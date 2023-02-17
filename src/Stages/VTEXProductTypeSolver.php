<?php

namespace WoowUpConnectors\Stages;

class VTEXProductTypeSolver
{
    public static function mapsChildProducts($appId): bool
    {
        $accountsWhithChildProducts = explode(',', env('VTEX_CHILDS'));
        return in_array(strval($appId), $accountsWhithChildProducts);
    }
}