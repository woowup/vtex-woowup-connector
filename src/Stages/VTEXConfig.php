<?php

namespace WoowUpConnectors\Stages;

class VTEXConfig
{
    public static function mapsChildProducts($appId): bool
    {
        $accountsWithChildProducts = explode(',', env('VTEX_CHILDS'));
        return in_array(strval($appId), $accountsWithChildProducts);
    }

    public static function interruptBadCataloging($appId) : bool
    {
        $newAccounts = explode(',', env('STARTING_NEW_ACCOUNTS_APP_IDS'));
        return in_array(strval($appId), $newAccounts);
    }
}