<?php

namespace WoowUpConnectors\Stages;
use League\Pipeline\StageInterface;

abstract class StageMapperForParentProducts implements StageInterface
{
    protected function mapsParentProducts($appId)
    {
        $parentAccounts = explode(',', env('VTEX_PARENTS'));
        return in_array(strval($appId), $parentAccounts);
    }
}