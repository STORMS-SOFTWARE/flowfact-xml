# flowfact-xml
Shall serve an easy to use and intuitive way to work with FlowFact xml export files

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

//
//\storms\flowfact\FlowFact::getInstance()->clearFilters(); // !!! without this $predefined_filter__MIETE will also use the filter defined for $predefined_filter__KAUF
//

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

### TODO
  - documentation
