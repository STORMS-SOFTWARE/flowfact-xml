<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 *
 * This is a wrapper class around a SimpleXMLElement object.
 * It allows us to create custom quick access methods that return deeply nested data from the flowfact xml.
 * It uses the magic php methods in order to delegate xml access tries to the previously passed SimpleXMLElement object.
 *a
 * TODO mostly everything in this class runs everytime anew as the getter (for example getPreise) is called - this ain't good for performance. We should implement some cache.
 */

namespace storms\flowfact;

use STORMS\webframe\Core\Traits\UtilityMethods;
use STORMS\webframe\Core\WebFrame;

use storms\flowfact\traits\ValueFormatters;

class ImmoObject {

    use ValueFormatters;

    private ?\SimpleXMLElement $immoObjectSimpleXMLElement = null;

    private $formatters = [
        // >*< can be used as wildcard - position of the asterisk is not important in any way - as soon as the asterisk is found, the formatter is applied. So you can put it at the beginning, in the middle or at the end of the field name.
        // *NYI:* >!< = don't format elems which are keyed like this *:NYI*
        // note that the order of the definition defines the order of the formatters being applied
        [
            'fields' => ['*preis', '*kosten', '*miete', 'kaution_text'],
            'formatter' => 'formatPrice' /** @see ValueFormatters::formatPrice() */
        ],
        [
            'fields' => ['*flaeche', '*anzahl'],
            'formatter' => 'formatTrimmed' /** @see ValueFormatters::formatTrimmed() */
        ],
        [
            'fields' => ['*flaeche'],
            'formatter' => 'formatSqm' /** @see ValueFormatters::formatSqm() */
        ],
    ];

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

    public function getPreis() {
        if($this->isMiete())
            return $this->getKaltmiete();
        elseif($this->isKauf())
            return $this->getKaufpreis();
        return null;
    }
    public function getKaufpreis() : string|null {
        return $this->getPreise()['kaufpreis']['value'] ?? null;
    }
    public function getKaltmiete() : string|null {
        return $this->getPreise()['kaltmiete']['value'] ?? null;
    }
    public function getPreise($without = []) : array {
        //d($this->convertData($this->immoObjectSimpleXMLElement->preise->children()));
        return $this->convertData($this->immoObjectSimpleXMLElement->preise->children(), $without);
    }

    public function getAusstattung($without = []) : array {
        return $this->convertData($this->immoObjectSimpleXMLElement->ausstattung->children(), $without);
    }

    /**
     * General method for converting and formatting data-values by their type and/or defined formatters
     *
     * NOTE:
     *  - this method does NOT modify the passed data - it returns a processed copy of it
     *  - this method needs to be called specially for the (sub) data you want to convert (typically within the methods of this class) - this method does NOT automatically convert all data passed to the class for performance reasons
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

            // recursion!
            if(!is_array($data) && !empty($attribs = (array)$v->attributes())) { // for xml elements that don't have a direct value but only attributes: pull the attributes out of the xml and store them as simple key/value array
                $tmp[$k]['attributes'] = $this->convertData($attribs['@attributes']);
            }

            $val = (string)$v ?? null;
            if(in_array($val, [FlowFact::BOOL_TRUE, FlowFact::BOOL_FALSE])) { // TODO we could actually also create a formatter for this...
                // convert "true" "false" (strings) from the xml to real booleans & just use the plain string values if it's not a bool
                $tmp[$k]['value'] = ($val === FlowFact::BOOL_TRUE);
            }
            elseif($this->hasFormattersFor($k)) {
                $tmp[$k]['_value_orig'] = $val;
                foreach($this->findFormattersFor($k) as $formatterName)
                    $val = $this->$formatterName($val);
                $tmp[$k]['value'] = $val;
            }
            else
                $tmp[$k]['value'] = $val;
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
            foreach($tmp_inner as $k => $v) {
                $tmp[$cat_key]['detail'][] = [
                    'key' => $k,
                    'value' => $v
                ];
            }
            //$tmp[$cat_key]['detail']['foo'] = $this->convertData($tmp_inner); // same as the loop above???? TEST THIS!!
        }
        return $tmp;
    }

    public function getKategorienFormatted () {
        $tmp = [];
        foreach($this->getKategorien() as $cat) {
            $l1 = $cat['label'];
            $l2 = $cat['detail'][0]['value'];
            $tmp[] = implode(' / ', [$l1, $l2]);
        }
        return $tmp;
    }

    /*
     * TODO not sure if this can only be one or multiple...
     */
    public function getObjektarten() {
        $tmp = [];
        foreach($this->getKategorien() as $cat) {
            $key = array_keys($cat['detail'])[0];
            $tmp[] = $cat['detail'][$key];
        }
        return $tmp;
    }

    public function getTitelbild(?string $default = null) : string {
        return $this->getImages(grouped : true)['TITELBILD'][0]['path'] ?? $default;
    }
    public function hasTitelbild() : bool {
        return (bool)($this->getImages(grouped : true)['TITELBILD'][0] ?? null);
    }

    public function getImages ($grouped = true) : array {
        $tmp = [];
        foreach ($this->immoObjectSimpleXMLElement->anhaenge->children() as $anhang) {
            $aAnhang = (array)$anhang;
            $attributes = $aAnhang['@attributes'];
            $data = [
                'path' => sprintf(
                    '%s/%s/%s',
                    preg_replace('/(\/?\.\.?\/)/', '' , FlowFact::$EXTRACT_DIR), // remove all ./ ; ../ ; /../ ; /./ ; etc.
                    $this->getId(),
                    (string)$anhang->daten->pfad
                ),
                'title' => (string)$anhang->anhangtitel ?? null,
            ];
            if($grouped)
                $tmp[$attributes['gruppe']][] = $data;
            else
                $tmp[] = $data;
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

    public function getFlaeche(string $key, $default = null) {
        return $this->getFlaechen()[$key]['value'] ?? $default;
    }
    private $flaechen_cache = null;
    public function getFlaechen(/*$without = []*/) {
        //return $this->convertData($this->immoObjectSimpleXMLElement->flaechen->children(), $without);

        // TODO implement parameter >$without< if needed
        if($this->flaechen_cache === null)
            $this->flaechen_cache = $this->convertData($this->immoObjectSimpleXMLElement->flaechen->children());
        return $this->flaechen_cache;
    }

    public function getAnzahlBadezimmer () : int {
        return (int)$this->getFlaeche('anzahl_badezimmer');
    }
    public function getAnzahlSchlafzimmer () : int {
        return (int)$this->getFlaeche('anzahl_schlafzimmer');
    }
    public function getAnzahlZimmer () : int {
        return (int)$this->getFlaeche('anzahl_zimmer');
    }
    public function getAnzahlStellplaetze () : int {
        return (int)$this->getFlaeche('anzahl_stellplaetze');
    }
    public function getAnzahlTerrassen () : int {
        return (int)$this->getFlaeche('anzahl_terrassen');
    }

    public function getNextImmo() : ?ImmoObject {
        return FlowFact::getInstance()->getNextOf($this);
    }

    public function getPrevImmo() : ?ImmoObject {
        return FlowFact::getInstance()->getPrevOf($this);
    }

    private $unformatted_fields = []; // at the moment ONLY for debugging purposes
    private function hasFormattersFor(string $key) : bool {
        $hasFormatters = $this->findFormattersFor($key) !== null;
        if(!$hasFormatters && !in_array($key, $this->unformatted_fields))
            $this->unformatted_fields[] = $key;
        return $hasFormatters;
    }

    private $formatter_cache = []; // store for the findFormattersFor method to speed up finding formatters
    private function findFormattersFor(string $key, $max = -1) : null|array {
        $formatters = [];
        if(array_key_exists($key, $this->formatter_cache))
            return $this->formatter_cache[$key];

        foreach($this->formatters as $formatterData) {
            foreach($formatterData['fields'] as $field_info) {
                if(str_contains($field_info, '*')) { // is wildcard match?
                    if(str_contains($key, str_replace('*', '', $field_info))) {
                        $formatters[] = $formatter = $formatterData['formatter'];
                        $this->formatter_cache[$key][] = $formatter;
                    }
                }
                elseif(str_contains($field_info, '!') !== false) { // blacklist match (force field to be ignored for formatting)
                    // NYI
                }
                elseif($key === $field_info) { // precise match
                    $formatters[] = $formatter = $formatterData['formatter']; // TODO duplicate code ... refactor
                    $this->formatter_cache[$key][] = $formatter;
                }
                if(count($formatters) === $max)
                    return $formatters;
            }
        }
        return empty($formatters) ? null : $formatters;
    }

    /**
     * Method currently exists only for debugging reasons
     *
     * Allows us to get an information on which fields have been sent through any formatter
     * @return array
     */
    public function getFormatterCache() {
        return $this->formatter_cache;
    }

    /**
     * Like getFormatterCache() also for debugging purposes
     *
     * Allows us to get an information on which fields have NOT been formatted
     * @return array
     */
    public function getUnformattedFields() {
        return $this->unformatted_fields;
    }

}
