# flowfact-xml
This singleton Class shall serve an easy to use and intuitive way to work with FlowFact xml export files (not the API!)

----

**somewhere:**
```php
define('FLOW_FACT_BASE_DIR', './data/flowfact'); // contains the openimmoXXXX.zip files
```

**where ever you need the flowfact data:**
```php
<?php
/*
 * EXAMPLES
 */

$inline_filter = \storms\flowfact\FlowFact::getInstance()->addFilter(new class implements \storms\flowfact\FlowFactFilter {
    public function filter(\storms\flowfact\ImmoObject $item) : bool {
        return $item->objektkategorie->vermarktungsart['KAUF'] == \storms\flowfact\FlowFact::BOOL_TRUE;
    }
})->getItems();

d($inline_filter);

// ----

$predefined_filter__KAUF = \storms\flowfact\FlowFact::getInstance()
    ->addFilter(new \storms\flowfact\filters\FlowFactFilterKauf())
    ->getItems();
d($predefined_filter__KAUF);

$predefined_filter__MIETE = \storms\flowfact\FlowFact::getInstance()
    ->addFilter(new \storms\flowfact\filters\FlowFactFilterMiete())
    ->getItems();
d($predefined_filter__MIETE);

// ----

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

**for a detail:**
```php
$item = FlowFact::getInstance(false)->getItem($detail_id);
```
Note the ```getInstance(false)``` **(false)**: This will prevent the class from indexing all files in the dir and instead try to use the desired data file directly 

### TODO
  - documentation
