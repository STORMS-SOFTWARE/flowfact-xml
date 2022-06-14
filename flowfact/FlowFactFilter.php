<?php

/**
 * @author Serjoscha Bassauer <bassauer@storms-media.de>
 */

namespace storms\flowfact;

interface FlowFactFilter {

    public function filter(ImmoObject $item) : bool;

}
