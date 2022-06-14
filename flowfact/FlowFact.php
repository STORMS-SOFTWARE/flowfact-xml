<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 * This is a FlowFact XML export processor & helper. It was NOT created to work with any external API or something..
 */

/*
 * item <=> immo object
 */

namespace storms\flowfact;

use STORMS\webframe\Core\Traits\UtilityMethods;

class FlowFact {

    public static $instance = null;

    private $items = null;
    private $provider = null;
    private $indexed = null;
    private $cat_map = [];
    private $filters = [];

    const BOOL_TRUE = 'true'; // just to make stuff more clear (for we are working with xml 'true' !== true)
    const BOOL_FALSE = 'false';

    /**
     * @param bool $index should all item files in the data dir be indexed? (This can only happen once). Preventing indexing may be likely in case for only getting an single item directly from the data file.
     * @return FlowFact|null
     */
    public static function getInstance(bool $index = true) {
        if (!isset(self::$instance) || ($index && self::$instance->items === null))
            self::$instance = new self($index);
        return self::$instance;
    }

    /**
     * @return FlowFact
     * @param $index
     */
    public function __construct(bool $index = true) {

        if($this->items !== null || $index === false)
            return self::$instance;

        $this->indexed = true;

        /*
         * index all immo items and store them temporarily
         */
        $base = FLOW_FACT_BASE_DIR;
        $objectId2xmlDatafileUri_map = [];
        foreach(glob("$base/*.zip") as $file) {
            $cache_xml_filename = basename($file, '.zip'); // how would the cached xml file (that has been extracted from the zip before) be named?

            // use the extracted xml file if it exists, otherwise read the xml from the zip and save its content as some kind of cache file to the filesystem
            if($xml_already_exists = is_file($xml_file_path = "$base/$cache_xml_filename.xml"))
                $immo_xml = file_get_contents($xml_file_path);
            else {
                $immo_xml = file_get_contents("zip://$file#openimmo.xml");
                file_put_contents($xml_file_path, $immo_xml);
            }

            $xml = simplexml_load_string($immo_xml);
            //$xml = new ImmoObject($xml);

            $xml->addChild('from_zip', $xml_already_exists ? 'false' : 'true');

            //$this->items[] = collect($xml);
            //$this->items[] = json_decode(json_encode($xml), true);
            //dd($xml);
            $object_id = _slugify($xml->anbieter->immobilie->verwaltung_techn->objektnr_extern);
            //$object_id = $xml->getObjectId();
            $this->items[$object_id] = new ImmoObject($xml->anbieter->immobilie);
            $this->provider = [ // Note: this is overwritten every iteration but that's okay because I guess we always have only one provider at all for all the items
                'anbieternr' => $xml->anbieternr,
                'firma' => $xml->firma,
                'openimmo_anid' => $xml->openimmo_anid
            ];
            $objectId2xmlDatafileUri_map[$object_id] = "$cache_xml_filename.xml";
        }
        file_put_contents("$base/objectId2xmlDatafileUri.json", json_encode($objectId2xmlDatafileUri_map));
        //$this->items = collect($this->items);
        //dd($this->items);
    }

    /**
     * TODO add caching (but if we add caching we could get into trouble when trying to fetch one single item with getItem if it was previously filtered)
     * @return array with instances of ImmoObject
     */
    public function getItems($resetFilters = true) {
        $tmp = [];
        foreach($this->items as /* @var $object_id string */ $object_id => /* @var $item ImmoObject */ $item) {
            $include = true;
            foreach ($this->filters as /* @var $filter \storms\flowfact\FlowFactFilter */ $filter) {
                if(!$filter->filter($item)) {
                    $include = false;
                    break;
                }
            }
            if($include)
                $tmp[$object_id] = $item;

        }

        if($resetFilters)
            $this->filters = [];

        return $tmp;
    }

    /**
     * Allows to get a single item (or false if not found) from the previously indexed items or try to load a single detail file
     * @param string $object_id
     * @return false|mixed|ImmoObject
     */
    public function getItem(string $object_id) {
        $base = FLOW_FACT_BASE_DIR;
        if($this->items === null) { // if items are not (yet) indexed: use the map file in order to just load the item from the single xml file
            $xml_file = json_decode(file_get_contents("$base/objectId2xmlDatafileUri.json"), true)[$object_id] ?? false;
            return $xml_file ? new ImmoObject(simplexml_load_file("$base/$xml_file")->anbieter->immobilie) : false;
        }
        else // otherwise just return the item from the index
            return $this->items[$object_id] ?? false;
    }

    private function getProvider() {
        return $this->provider;
    }

    /**
     * TODO add caching + this ignores the filters
     * @return array
     */
    public function getCategories() {
        $categories = [];
        foreach($this->items as $object_id => $item) {
            foreach($item->objektkategorie->objektart->children() as $cat_key => $category_sub) {
                //dd($cat_key, json_decode(json_encode($category), true), array_keys(json_decode(json_encode($category), true)), $category);
                $categories[$cat_key] = $this->cat_map[$cat_key] ?? ucwords(strtolower($cat_key));
                /*foreach($category as $foo => $bar) {
                    d($foo, $bar);
                }*/
            }
        }
        return $categories;
    }

    /**
     * @return array
     */
    public function getCatMap() : array {
        return $this->cat_map;
    }

    /**
     * @param array $cat_map
     */
    public function setCatMap(array $cat_map) : self {
        $this->cat_map = $cat_map;
        return $this;
    }

    /**
     * @return array
     */
    public function getFilters() : array {
        return $this->filters;
    }

    /**
     * @param array $filters
     * @return $this
     */
    public function setFilters(array $filters) : self {
        $this->filters = $filters;
        return $this;
    }

    /**
     * if you run the line >>storms\flowfact\FlowFact::getInstance()->addFilter(...)->getItems(false)<< multiple times in one and the same script execution the new filter will always be applied on top of previously added filters.
     * So if you want to start over all new you have to reset the filters before you call the ->getItems(false) method again. As an alternative you can also call ->getItems(<true:default>) which will reset the filters.
     * @return $this
     */
    public function clearFilters() : self {
        $this->filters = [];
        return $this;
    }

    /**
     * Note: Filter apply on top of each other - so they are ANDed
     * So for example if there is one item for rent and one for sale and you apply both filter classes (for rent & sale) you will not get any results
     * @return $this
     */
    public function addFilter(FlowFactFilter $filter) : self {
        $this->filters[] = $filter;
        return $this;
    }

    public function getNextOf(string $object_id) {
        // TODO NYI
    }
    public function getPrevOf(string $object_id) {
        // TODO NYI
    }


}
