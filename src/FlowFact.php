<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 * This is a (Singleton) FlowFact XML export processor & helper. It was NOT created to work with any external API or something...
 *
 * Also this class is designed to work with only ONE single object vendor. If you got immo objects from multiple vendors, you perhaps need to modify this class.
 * This class works directly on the XML files - therefor it will perhaps be very slow when having hundreds of objects. If you occur massive performance issues, this class may not be the best choice or needs to be rewritten to not work with the xml files directly anymore.
 */

/*
 * item <=> immo object
 */

namespace storms\flowfact;

use STORMS\webframe\Core\Traits\UtilityMethods;

class FlowFact {

    public static ?FlowFact $instance = null;

    private ?array $items = null;
    private ?array $provider = null;
    private array $texts_map = []; // may contain a generic map of all text (no matter if key or value) that will be used for translation various strings from the xml
    private array $filters = [];

    public static bool $SLUGIFY_IDENTIFIER_BY_DEFAULT = true; // shall the immo id be slugified by default? (if not overridden by the parameter @ getId(>false<))

    public static ?string $BASE_DIR = null; // THIS VAR NEEDS TO BE SET (from the outside) BEFORE USING THE CLASS
    public static ?string $EXTRACT_DIR = null; // ... this var MAY be set from the outside but is not required at all

    public static bool $AUTO_UPDATE = false; // may be set from the outside; this causes the class to automatically update the data store (if needed) every time the singleton is accessed

    public static ?\Closure $DEFAULT_TEXT_MAPPER = null;

    const BOOL_TRUE = 'true'; // just to make stuff more clear (for we are working with xml 'true' !== true)
    const BOOL_FALSE = 'false';

    /**
     * @param bool $importImmosFromFilesystem should all item files in the data dir be indexed? (This can only happen once). Preventing indexing may be likely in case for only getting an single item directly from the data file.
     * @return FlowFact|null
     */
    public static function getInstance($importImmosFromFilesystem = true) {
        if (!isset(self::$instance))
            self::$instance = new self($importImmosFromFilesystem);
        return self::$instance;
    }

    public function __construct($importImmosFromFilesystem = true) {
        if(self::$BASE_DIR === null)
            throw new \Exception('Please set the FlowFact base dir somewhere before using this class. Do it like so FlowFact::$BASE_DIR = "<path>";');
        if(self::$EXTRACT_DIR === null)
            self::$EXTRACT_DIR = self::$BASE_DIR . "/extracted";

        if(self::$AUTO_UPDATE)
            $this->updateDataStorage();

        if($importImmosFromFilesystem)
            $this->importImmosFromFilesystem(); // propagates the data from the filesystem to the memory
    }

    /**
     * extract all zips, name them by object ID (while deleting old dir with the same id) and delete the source zip file
     * !! If possible this should be invoked through a cronjob - NOT every time immo data is needed !! (create a new php file that just does >FlowFact::getInstance()->updateDataStorage();< and call this script though a cron)
     */
    public function updateDataStorage() {

        if(!is_dir(self::$EXTRACT_DIR))
            mkdir(self::$EXTRACT_DIR);

        $zip_files = glob(self::$BASE_DIR . '/*.zip');

        // sort zip files by timestamp (taken from the filename, nor the physical file stats) so that the newest zip file (with the highest timestamp value) is processed last (otherwise we would extract the newest data and then overwrite it with older/previous data)
        usort($zip_files, function($a, $b) {
            $ts_from_filename_a = substr(basename($a), strlen('openimmo'), -strlen('.zip'));
            $ts_from_filename_b = substr(basename($b), strlen('openimmo'), -strlen('.zip'));
            return $ts_from_filename_a > $ts_from_filename_b;
        });

        $existing_ids = [];

        foreach($zip_files as $file) {

            $extr_tar_dir = basename($file, '.zip');

            $zip = new \ZipArchive;
            if ($zip->open($file)) {
                if(is_dir(self::$EXTRACT_DIR . "/$extr_tar_dir")) // skip extraction if tar dir already exists
                    continue;
                $zip->extractTo(self::$EXTRACT_DIR . "/$extr_tar_dir");
                $zip->close();

                if(!_isDev()) // because in dev envs we are normally testing -> keep the zip archives here instead of deleting them
                    unlink($file);
            }

            // skip archives that do not have the openimmo.xml file
            if(!is_file(self::$EXTRACT_DIR . "/$extr_tar_dir/openimmo.xml")) {
                rename(self::$EXTRACT_DIR . "/$extr_tar_dir", self::$EXTRACT_DIR . "/_BROKEN__$extr_tar_dir");
                continue;
            }

            $xml = simplexml_load_file(self::$EXTRACT_DIR . "/$extr_tar_dir/openimmo.xml");
            $immo_object = new ImmoObject($xml->anbieter->immobilie);

            // 1. delete previous object dirs that are named by the same id as the one we are currently trying to copy over (we need this for update & delete action)
            if(is_dir(self::$EXTRACT_DIR . "/{$immo_object->getId()}")) {
                if (PHP_OS === 'Windows')
                    exec(sprintf("rd /s /q %s", escapeshellarg(self::$EXTRACT_DIR . "/{$immo_object->getId()}"))); // 'exec' because rmdir can only delete empty dirs
                else
                    exec(sprintf("rm -rf %s", escapeshellarg(self::$EXTRACT_DIR . "/{$immo_object->getId()}")));
            }

            $is_delete_action = ((string)$immo_object->verwaltung_techn->aktion->aktionart) === 'DELETE';

            if($is_delete_action) {
                // at this point a (possibly) previously created immo-object dir is already removed - now lets just remove the fresh extracted immo-object dir (before it is even renamed) (because we don't need it because this is a delete action)
                if (PHP_OS === 'Windows')
                    exec(sprintf("rd /s /q %s", escapeshellarg(self::$EXTRACT_DIR . "/$extr_tar_dir"))); // 'exec' because rmdir can only delete empty dirs
                else
                    exec(sprintf("rm -rf %s", escapeshellarg(self::$EXTRACT_DIR . "/$extr_tar_dir")));
                continue; // skip renaming the dir to the immo-object id (because we won't even have the dir at this point for this is a delete action)
            }

            $existing_ids[] = $immo_object->getId();

            // 2. rename the fresh extraction/extracted dir to be named by the id of the immo-object
            rename(self::$EXTRACT_DIR . "/$extr_tar_dir", self::$EXTRACT_DIR . "/{$immo_object->getId()}");
        }

        file_put_contents(self::$BASE_DIR . "/objectId2xmlDatafileUri.json", json_encode($existing_ids));

    }

    /*
     * NOTE: through the fact that zip archives are not deleted on dev, this will be ALWAYS return false in dev envs as long as zip archives are not deleted manually (or the code is changed)
     */
    public function isUpToDate () {
        return count(glob(self::$BASE_DIR . "/*.zip")) === 0;
    }

    /*
     * TODO if this is too slow: make the updateDataStorage method do more magic like creating a sqlite db to store the data in and then query that sqlite db here or something like this
     * - https://github.com/rakibtg/SleekDB
     * - https://github.com/STORMS-SOFTWARE/reimann-wolff-immo-webseite/blob/fec36ee9270a3f2e295f88cafa86d6e98df65a44/opim/class.flowfact.php#L383
     */
    public function importImmosFromFilesystem () {
        $this->items = [];
        foreach(glob(self::$EXTRACT_DIR . '/*/openimmo.xml') as $immo_file) {
            $xml = simplexml_load_file($immo_file);
            $immo_object = new ImmoObject($xml->anbieter->immobilie);
            $this->items[$immo_object->getId()] = $immo_object;
        }
    }

    /**
     * TODO add caching (but if we add caching we could get into trouble when trying to fetch one single item with getItem if it was previously filtered)
     * TODO perhaps remove addFilter logic and instead just allow passing the filters as array directly to this method... perhaps...
     * @return array with instances of ImmoObject
     */
    public function getItems($resetFilters = true, $importIfNeeded = true) {
        $tmp = [];
        if($this->items === null && $importIfNeeded)
            $this->importImmosFromFilesystem();
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
     * Allows to get a single item (or false if not found) from the previously extracted items or try to load a single detail file
     * @param string $object_id
     * @return false|mixed|ImmoObject
     */
    public function getItem(string $object_id) : ?ImmoObject {
        if($this->items === null) { // if items are not (yet) indexed: use the map file in order to just load the item from the single xml file
            $xml_file = is_file(self::$EXTRACT_DIR . "/$object_id/openimmo.xml") ? self::$EXTRACT_DIR . "/$object_id/openimmo.xml" : false;
            return $xml_file ? new ImmoObject(simplexml_load_file($xml_file)->anbieter->immobilie) : false;
        }
        else // otherwise just return the item from the index
            return $this->items[$object_id] ?? null;
    }

    private function getProvider() {
        return $this->provider;
    }

    /**
     * @return array
     */
    public function getUniqueCategories() {
        $categories = [];
        foreach($this->items as $object_id => /* @var $item ImmoObject */ $item) {
            /*foreach($item->objektkategorie->objektart->children() as $cat_key => $category_sub) { // TODO use getCategories() of the immo object
                $categories[$cat_key] = $this->cat_map[$cat_key] ?? ucwords(strtolower($cat_key));
            }*/
            foreach($item->getKategorien() as $category_data) {
                $categories[$category_data['key']] = $category_data;
            }
        }
        return $categories;
    }

    public function getLocations($unique_by = null) {
        $locations = [];
        foreach($this->items as $object_id => /* @var $item ImmoObject */ $item) {
            /*foreach($item->geo->ort as $loc) {
                $locations[] = ucwords(strtolower($loc));
            }*/
            $loc = $item->getLocation();
            if($unique_by)
                $locations[$loc[$unique_by]] = $loc;
            else
                $locations[] = $loc;
        }
        return $locations;
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

    /*
     * Note that getNextOf and getPrevOf can also be called directly on the ImmoObject instance
     */
    public function getNextOf($id_or_immoobject) : ?ImmoObject {
        if(gettype($id_or_immoobject) === 'string') {
            $index = array_search($id_or_immoobject, array_keys($this->items));
            return array_values($this->items)[$index + 1] ?? null;
        }
        elseif(gettype($id_or_immoobject) === 'object' && get_class($id_or_immoobject) === ImmoObject::class)
            return $this->getNextOf($id_or_immoobject->getId());

        return null;
    }
    public function getPrevOf($id_or_immoobject) : ?ImmoObject {
        if(gettype($id_or_immoobject) === 'string') {
            $index = array_search($id_or_immoobject, array_keys($this->items));
            return array_values($this->items)[$index - 1] ?? null;
        }
        elseif(gettype($id_or_immoobject) === 'object' && get_class($id_or_immoobject) === ImmoObject::class)
            return $this->getPrevOf($id_or_immoobject->getId());

        return null;
    }

    /**
     * @return array
     */
    public function getTextsMap() : array {
        return $this->texts_map;
    }

    /**
     * @param array $map
     */
    public function setTextsMap(array $map) : self {
        $this->texts_map = $map;
        return $this;
    }

    public function mapString (string $str, string|callable|null $default = null) {
        if($this->texts_map[$str] ?? false)
            return $this->texts_map[$str];
        else {
            if(is_callable($default))
                return $default($str);
            else {
                if($default)
                    return $default;
                else {
                    if(is_callable(self::$DEFAULT_TEXT_MAPPER))
                        return call_user_func(self::$DEFAULT_TEXT_MAPPER, $str);
                    else
                        return $str;
                }

            }
        }
    }

}
