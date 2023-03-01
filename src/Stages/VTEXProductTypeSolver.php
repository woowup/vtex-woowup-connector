<?php

namespace WoowUpConnectors\Stages;

class VTEXProductTypeSolver
{
    public static function mapsChildProducts($appId): bool
    {
        $accountsWithChildProducts = explode(',', env('VTEX_CHILDS'));
        return in_array(strval($appId), $accountsWithChildProducts);
    }
}