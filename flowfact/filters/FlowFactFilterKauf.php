<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 */

namespace storms\flowfact\filters;

use storms\flowfact\FlowFact;
use storms\flowfact\FlowFactFilter;
use storms\flowfact\ImmoObject;

/*
 * this filter allows to get only items that are for sale
 * <vermarktungsart KAUF="true" MIETE_PACHT="false"/>
 */

class FlowFactFilterKauf implements FlowFactFilter {

    public function filter(ImmoObject $item) : bool {
        return (string)$item->objektkategorie->vermarktungsart['KAUF'] === FlowFact::BOOL_TRUE;
    }

}
