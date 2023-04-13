<?php

namespace storms\flowfact\traits;

trait ValueFormatters {

    private function formatPrice(string $val) {
        return number_format($val, 2, ',', '.') . ' €';
    }

    private function formatTrimmed(string $val) {
        $foo = str_replace(['.', ','], '#', $val);
        $bar = strpos($foo, '#');
        $foo = $bar === false ? $val : substr($foo, 0, strpos($foo, '#'));
        return $foo;
    }

    private function formatSqm(string $val) {
        return trim($val) !== '' ? $val .' m²' : null;
    }

}
