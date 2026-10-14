<?php

// Wasmer : le PHP WebAssembly n'a pas mb_split(), utilisee par Laravel.
if (! function_exists('mb_split')) {
    function mb_split(string $pattern, string $string, int $limit = -1): array|false
    {
        return preg_split('/'.str_replace('/', '\/', $pattern).'/u', $string, $limit);
    }
}