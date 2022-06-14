<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 */

namespace storms\flowfact\filters;

use storms\flowfact\FlowFact;
use storms\flowfact\FlowFactFilter;
use storms\flowfact\ImmoObject;

/*
 * this filter allows to get only items that are for rent
 * <vermarktungsart KAUF="false" MIETE_PACHT="true"/>
 */

class FlowFactFilterMiete implements FlowFactFilter {

    public function filter(ImmoObject $item) : bool {
        return (string)$item->objektkategorie->vermarktungsart['MIETE_PACHT'] === FlowFact::BOOL_TRUE;
    }

}
