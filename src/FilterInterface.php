<?php

namespace WoowUpConnectors;

interface FilterInterface
{
    public function filterSku($sku);
    public function getPurchasePoints($order);
}
