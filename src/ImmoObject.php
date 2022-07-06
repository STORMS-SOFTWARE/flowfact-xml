<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 *
 * This is a wrapper class around a SimpleXMLElement object.
 * It allows us to create custom quick access methods that return deeply nested data from the flowfact xml.
 * It uses the magic php methods in order to delegate xml access tries to the previously passed SimpleXMLElement object.
 *
 * TODO everything in this class runs everytime the method is called - this ain't good.
 */

namespace storms\flowfact;

use STORMS\webframe\Core\Traits\UtilityMethods;
use STORMS\webframe\Core\WebFrame;

class ImmoObject {

    private ?\SimpleXMLElement $immoObjectSimpleXMLElement = null;

    public function __construct(\SimpleXMLElement $simpleXMLElement) {
        $this->immoObjectSimpleXMLElement = $simpleXMLElement;
    }

    public function __get($name) {
        return $this->immoObjectSimpleXMLElement->$name;
    }

    public function __call($name, $arguments) {
        return $this->immoObjectSimpleXMLElement->$name(...$arguments);
    }

    /*
     * Example usage:
     * $instanceOfImmoObject->isVermarktunsart(ImmoObject::VERMARKTUNGSART__KAUF); // -> true / false
     */
    const VERMARKTUNGSART__KAUF = 'KAUF';
    const VERMARKTUNGSART__MIETE = 'MIETE_PACHT';
    public function isVermarktungsart (string $vermarkungsart) : bool {
        return (string)$this->immoObjectSimpleXMLElement->objektkategorie->vermarktungsart[$vermarkungsart] === FlowFact::BOOL_TRUE;
    }

    public function isKauf () : bool {
        return $this->isVermarktungsart(self::VERMARKTUNGSART__KAUF);
    }
    public function isMiete () : bool {
        return $this->isVermarktungsart(self::VERMARKTUNGSART__MIETE);
    }

    public function getVermarktungsart () : array {
        $va = array_keys(array_filter(((array)$this->immoObjectSimpleXMLElement->objektkategorie->vermarktungsart)['@attributes'], function ($value) {
            return $value === FlowFact::BOOL_TRUE;
        }))[0];
        return [
            'key' => $va,
            'label' => FlowFact::getInstance()->mapString($va)
        ];
    }

    const NUTZUNGSART__WOHNEN = 'WOHNEN';
    const NUTZUNGSART__GEWERBE = 'GEWERBE';
    public function isNutzungsart (string $nutzungsart) : bool {
        return (string)$this->immoObjectSimpleXMLElement->objektkategorie->nutzungsart[$nutzungsart] === FlowFact::BOOL_TRUE;
    }
    public function getNutzungsart () : array {
        $na = array_keys(array_filter(((array)$this->immoObjectSimpleXMLElement->objektkategorie->nutzungsart)['@attributes'], function ($value) {
            return $value === FlowFact::BOOL_TRUE;
        }))[0];
        return [
            'key' => $na,
            'label' => FlowFact::getInstance()->mapString($na)
        ];
    }

    public function getId(?bool $slugify = null) : string {
        $id = (string)$this->immoObjectSimpleXMLElement->verwaltung_techn->objektnr_extern;
        if($slugify || ($slugify === null && FlowFact::$SLUGIFY_IDENTIFIER_BY_DEFAULT))
            return strtolower(str_replace('#', '-', $id));
        else
            return $id;
    }

    /*
     * TODO if this is used more often: better also store the provider within the immo data/object
     */
    public function getProvider() : array {
        return FlowFact::getInstance()->getItem($this->getObjectId())->getProvider();
    }

    public function getLocation() : array {
        $loc = [
            'plz'               => (int)$this->immoObjectSimpleXMLElement->geo->plz,
            'ort'               => (string)$this->immoObjectSimpleXMLElement->geo->ort,
            'strasse'           => (string)$this->immoObjectSimpleXMLElement->geo->strasse,
            'hausnummer'        => (string)$this->immoObjectSimpleXMLElement->geo->hausnummer,
            'land'              => (string)$this->immoObjectSimpleXMLElement->geo->land['iso_land'], //WebFrame::inst()->translate(),
            'lat'               => (float)$this->immoObjectSimpleXMLElement->geo->geokoordinaten['breitengrad'],
            'lng'               => (float)$this->immoObjectSimpleXMLElement->geo->geokoordinaten['laengengrad']
        ];
        //$loc['id'] = implode('-', array_map('_slugify', $loc));
        return $loc;
    }

    public function getPreise($without = []) : array {
        return array_map(function($elem) { // TODO this won't process multi dim arrays (like for example @ preise->stp_garage->stellplatzmiete)
            if(is_numeric($elem['value'] ?? false))
                $elem['value'] = $elem['value'] . ' €';
            return $elem;
        }, $this->convertData($this->immoObjectSimpleXMLElement->preise->children(), $without));
    }

    public function getAusstattung($without = []) : array {
        return $this->convertData($this->immoObjectSimpleXMLElement->ausstattung->children(), $without);
    }

    /**
     * TODO refactor to a more speaking name ...
     * @param $without array with KEYS to exclude from the result
     */
    private function convertData($data, array $without = []) : array {

        if(is_null($data))
            return [];

        $tmp = [];
        foreach ($data as $k => $v) {

            if(in_array($k, $without))
                continue;

            $tmp[$k] = [
                'key' => $k,
                'label' => FlowFact::getInstance()->mapString($k),
            ];

            if(!is_array($data) && !empty($attribs = (array)$v->attributes())) { // for xml elements that don't have a direct value but only attributes: pull the attributes out of the xml and store them as simple key/value array
                $tmp[$k]['attributes'] = $this->convertData($attribs['@attributes']);
            }
            else {
                $tmp[$k]['value'] =
                    // convert "true" "false" (strings) from the xml to real booleans & just use the plain string values if it's not a bool
                    in_array((string)$v, [FlowFact::BOOL_TRUE, FlowFact::BOOL_FALSE])
                        ? (string)$v === FlowFact::BOOL_TRUE
                        : (string)$v;
            }
        }
        return $tmp;
    }

    /**
     * @return array
     */
    public function getKategorien () : array {
        $tmp = [];
        foreach ($this->immoObjectSimpleXMLElement->objektkategorie->objektart->children() as $cat_key => $cat_data) {
            $tmp_inner = ((array)$cat_data)['@attributes'];
            $tmp[$cat_key]['key'] = $cat_key;
            $tmp[$cat_key]['label'] = FlowFact::getInstance()->mapString($cat_key);
            array_walk($tmp_inner, function(&$elem) {
                $elem = FlowFact::getInstance()->mapString($elem);
            });
            $tmp[$cat_key]['detail'] = $tmp_inner;
        }
        return $tmp;
    }

    public function getTitelbild(?string $default = null) : string {
        return $this->getImagesGrouped()['TITELBILD'][0]['path'] ?? $default;
    }
    public function hasTitelbild() : bool {
        return (bool)($this->getImagesGrouped()['TITELBILD'][0] ?? null);
    }

    public function getImagesGrouped () : array {
        $tmp = [];
        foreach ($this->immoObjectSimpleXMLElement->anhaenge->children() as $anhang) {
            $aAnhang = (array)$anhang;
            $attributes = $aAnhang['@attributes'];
            $tmp[$attributes['gruppe']][] = [
                'path' => sprintf('%s/%s/%s', ltrim(FlowFact::$EXTRACT_DIR, '.'), $this->getId(), (string)$anhang->daten->pfad),
                'title' => (string)$anhang->anhangtitel ?? null,
            ];
        }
        return $tmp;
    }

    /*
     * get facts that should really be convincing the user that this is an awesome immo object
     */
    public function getConvincingFacts () {
        return array_values(array_filter([
            (string)$this->ausstattung->kamin === FlowFact::BOOL_TRUE ? 'Kamin' : null, // TODO this should use mapString (of the FlowFact class)
            (string)$this->ausstattung->sauna === FlowFact::BOOL_TRUE ? 'Sauna' : null,
            (string)$this->ausstattung->barrierefrei === FlowFact::BOOL_TRUE ? 'Barrierefrei' : null,
            (string)$this->ausstattung->swimmingpool === FlowFact::BOOL_TRUE ? 'Swimmingpool' : null,
            (string)$this->ausstattung->wintergarten === FlowFact::BOOL_TRUE ? 'Wintergarten' : null,
        ]));
    }

    public function getHeizungsart() { // guess this would also work with the convertData method
        return array_map([FlowFact::getInstance(), 'mapString'], array_keys(((array)$this->immoObjectSimpleXMLElement->ausstattung->heizungsart)['@attributes']??[]));
    }

    public function getNextImmo() : ?ImmoObject {
        return FlowFact::getInstance()->getNextOf($this);
    }

    public function getPrevImmo() : ?ImmoObject {
        return FlowFact::getInstance()->getPrevOf($this);
    }

}
