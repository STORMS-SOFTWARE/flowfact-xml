# flowfact-xml
This singleton Class shall serve an easy to use and intuitive way to work with FlowFact xml export files (not the API!)

**Requirements:**
```json
"require": {
    "php": ">=8.0",
    "ext-json": "*",
    "ext-simplexml": "*"
},
```

**Third party deps:**
None

----

**somewhere before using the class:**
```php
FlowFact::$BASE_DIR = './data/flowfact'; // contains the openimmoXXXX.zip files
```

**prepare / update your data base the class is working with:**
You may put this into a file that is called by a cronjob (its not recommended to do this every time the script that outputs immo-data runs):
```php
// prepare-flowfact.php:
if(!FlowFact::getInstance()->isUpToDate())
    FlowFact::getInstance()->updateDataStorage();
```
This will make sure that all archives within the ```FlowFact::$BASE_DIR``` are extracted and deleted immo objects are removed from the data base. The FlowFact class can now work with the data using the ```getItems()``` (...) method.

----

**setting up textmappings for field labels as well as for field values:**
```php
// Example Mapping
FlowFact::getInstance()->setTextsMap([
    'DOPPELHAUSHAELFTE' => 'Doppelhaushälfte',
    'kaution_text'  => 'Kaution',
    'courtage_hinweis' => 'Courtage',
    'waehrung' => 'Währung',
    'FUSSBODEN' => 'Fußboden',
    'gaestewc'  => 'Gäste WC',
    'iso_waehrung' => 'Währung',
    'stp_freiplatz' => 'Freiplatz',
    'stp_garage' => 'Garage',
    'EBK' => 'EBK',
    'kueche' => 'Küche',
]);
```

**output the flowfact data:**
```php
<?php
/*
 * EXAMPLES
 */

// get immo objects using an inline filter
$inline_filter = \storms\flowfact\FlowFact::getInstance()->addFilter(new class implements \storms\flowfact\FlowFactFilter {
    public function filter(\storms\flowfact\ImmoObject $item) : bool {
        return $item->objektkategorie->vermarktungsart['KAUF'] == \storms\flowfact\FlowFact::BOOL_TRUE;
    }
})->getItems();

d($inline_filter);

// ----

// using one of the predefined example filters (of course you can define your own filter classes)
$predefined_filter__KAUF = \storms\flowfact\FlowFact::getInstance()
    ->addFilter(new \storms\flowfact\filters\FlowFactFilterKauf())
    ->getItems();
d($predefined_filter__KAUF);

$predefined_filter__MIETE = \storms\flowfact\FlowFact::getInstance()
    ->addFilter(new \storms\flowfact\filters\FlowFactFilterMiete())
    ->getItems();
d($predefined_filter__MIETE);

// ----

// not using any filter / fetching all immo objects
$all = \storms\flowfact\FlowFact::getInstance()->getItems();
d($all);
$firstOfAll = array_shift($all);
/* @var $firstOfAll \storms\flowfact\ImmoObject */
d( 
    $firstOfAll,
    $firstOfAll->getId(),
    $firstOfAll->isNutzungsart(\storms\flowfact\ImmoObject::NUTZUNGSART__GEWERBE),
    $firstOfAll->isNutzungsart(\storms\flowfact\ImmoObject::NUTZUNGSART__WOHNEN),
    $firstOfAll->getLocation(),
    $firstOfAll->getKategorien(),
);
?>
```

**for outputting a immo-object detail/single item:**
```php
$item = FlowFact::getInstance(false)->getItem($detail_id);
```
Note the ```getInstance(false)``` **(false)**: This will prevent the class from indexing all files in the dir and instead try to use the desired data file directly 

## "Config"
**Those "settings" can be set statically to the class before you actually use it in order to fetch data**

```$SLUGIFY_IDENTIFIER_BY_DEFAULT``` (FlowFact::$SLUGIFY_IDENTIFIER_BY_DEFAULT = [true|false]) <default:true>
true: the immo-id will be made URL compatible (so '#' is exchanged with '-'), This way you can use the immo ID for immo detail pages...

```$BASE_DIR``` **REQUIRED** (FlowFact::$BASE_DIR)
Needs to be set in order for the class to know where the immo archives are located. 

```$EXTRACT_DIR``` (FlowFact::$EXTRACT_DIR)
The dir where the class will extract the immo archives to. If not set the class will automatically use ```FlowFact::$BASE_DIR . '/extracted'```

```$AUTO_UPDATE``` (FlowFact::$AUTO_UPDATE) <default:false>
**Recommendation:** keep this to false (default) and trigger updates through a cronjob (see section "prepare / update your data base the class is working with")

Settings this to true causes the class to automatically update the data store (if needed) every time it is accessed. This will ensure that the data is always accurate and up to date, but will cause increased loading times for the person who first accesses the webpage after new flowfact data has been exported to the ```$BASE_DIR```. In order to prevent this behaviour you should use a cronjob to update data.

```$DEFAULT_TEXT_MAPPER``` (FlowFact::$DEFAULT_TEXT_MAPPER) <default:null>
Can be set with a closure that labels and values are processed by. 
Example:
```
FlowFact::$DEFAULT_TEXT_MAPPER = function($str) {
    return str_replace('_', ' ', ucwords(strtolower($str)));
};
```
This example mapper will by default modify labels and values without an extra definition through ```FlowFact::getInstance()->setTextsMap``` by replacing '_' with an space and transforming the case to a much more likely version.