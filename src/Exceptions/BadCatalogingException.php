<?php

namespace WoowUpConnectors\Exceptions;

use Exception;

class BadCatalogingException extends Exception
{
    private $productIds;

    public function __construct($message, $productIds)
    {
        parent::__construct("$message " . implode(',', $productIds));
        $this->productIds = $productIds;
    }

    public function getProductIds()
    {
        return $this->productIds;
    }

}