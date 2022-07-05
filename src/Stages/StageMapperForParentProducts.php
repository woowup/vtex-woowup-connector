<?php

namespace WoowUpConnectors\Stages;

class StageMapperForParentProducts implements StageInterface
{
    protected function mapsParentProducts($appId, $features)
    {
        $mapsParentProducts = false;
        if(in_array('vtex-parents', $features)){
            $parentAccounts = explode(',', env('VTEX_PARENTS'));
            $mapsParentProducts = in_array(strval($appId), $parentAccounts);
        }
        return $mapsParentProducts;
    }
}