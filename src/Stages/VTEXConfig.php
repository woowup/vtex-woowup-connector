<?php

namespace WoowUpConnectors\Stages;

class VTEXConfig
{
    public static function mapsChildProducts($appId): bool
    {
        $accountsWithChildProducts = explode(',', env('VTEX_CHILDS'));
        return in_array(strval($appId), $accountsWithChildProducts);
    }

    public static function getStartingIdNewAccounts()
    {
        return env('STARTING_NEW_ACCOUNTS_APP_ID');
    }

    public static function getTestFeaturesAccounts()
    {
        return explode(',', env('TEST_FEATURE_APP_IDS'));
    }

    public static function downloadInactiveProducts($appId): bool
    {
        $accounts = explode(',', env('DOWNLOAD_INACTIVE_PRODUCTS'));
        return in_array(strval($appId), $accounts);
    }
}