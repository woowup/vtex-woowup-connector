<?php

namespace WoowUpConnectors\Stages\HistoricalProducts;

use League\Pipeline\StageInterface;

abstract class VTEXWoowUpHistoricalProductMapper implements StageInterface
{
    protected $vtexConnector;
    protected $stockEqualsZero;
    protected $onlyMapsParentProducts;

    public function __construct($vtexConnector, $stockEqualsZero)
    {
        $this->vtexConnector = $vtexConnector;
        $this->stockEqualsZero = $stockEqualsZero;
        $this->onlyMapsParentProducts = false;

        return $this;
    }

    public function __invoke($payload)
    {
        if (!is_null($payload)) {
            return $this->buildProduct($payload);
        }

        return null;
    }

    /**
     * Maps a VTEX product to WoowUp's format
     * @param  object $vtexProduct   VTEX product
     * @return array                 WoowUp product
     */
    protected function buildProduct($vtexCustomer)
    {

    }
}