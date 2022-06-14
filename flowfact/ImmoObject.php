<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 *
 * This is a wrapper class around a SimpleXMLElement object.
 * It allows us to create custom quick access methods that return deeply nested data from the flowfact xml.
 * It uses the magic php methods in order to delegate xml access tries to the previously passed SimpleXMLElement object.
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
    public function isVermarkungsart (string $vermarkungsart) : bool {
        return (string)$this->immoObjectSimpleXMLElement->objektkategorie->vermarktungsart[$vermarkungsart] === FlowFact::BOOL_TRUE;
    }

    public function isKauf () : bool {
        return $this->isVermarkungsart(self::VERMARKTUNGSART__KAUF);
    }
    public function isMiete () : bool {
        return $this->isVermarkungsart(self::VERMARKTUNGSART__MIETE);
    }

    const NUTZUNGSART__WOHNEN = 'WOHNEN';
    const NUTZUNGSART__GEWERBE = 'GEWERBE';
    public function isNutzungsart (string $nutzungsart) : bool {
        return (string)$this->immoObjectSimpleXMLElement->objektkategorie->nutzungsart[$nutzungsart] === FlowFact::BOOL_TRUE;
    }

    public function getId() : string {
        return _slugify($this->immoObjectSimpleXMLElement->verwaltung_techn->objektnr_extern);
    }

    /*
     * TODO if this is used more often: better also store the provider within the immo data/object
     */
    public function getProvider() : array {
        return FlowFact::getInstance()->getItem($this->getObjectId())->getProvider();
    }

    public function getLocation() : array {
        return [
            'plz'               => (int)$this->immoObjectSimpleXMLElement->geo->plz,
            'ort'               => (string)$this->immoObjectSimpleXMLElement->geo->ort,
            'strasse'           => (string)$this->immoObjectSimpleXMLElement->geo->strasse,
            'hausnummer'        => (string)$this->immoObjectSimpleXMLElement->geo->hausnummer,
            'land'              => (string)$this->immoObjectSimpleXMLElement->geo->land['iso_land'], //WebFrame::inst()->translate(),
            'lat'               => (float)$this->immoObjectSimpleXMLElement->geo->geokoordinaten['breitengrad'],
            'lng'               => (float)$this->immoObjectSimpleXMLElement->geo->geokoordinaten['laengengrad'],
        ];
    }

    public function getKategorien () : array {
        $tmp = [];
        foreach ($this->immoObjectSimpleXMLElement->objektkategorie->objektart->children() as $cat_key => $cat_data) {
            $tmp[$cat_key] = ((array)$cat_data)['@attributes'];
        }
        return $tmp;
    }

}
