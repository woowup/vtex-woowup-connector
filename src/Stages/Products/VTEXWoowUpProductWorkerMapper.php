<?php

namespace WoowUpConnectors\Stages\Products;
use WoowUpConnectors\Stages\HistoricalProducts\VTEXWoowUpHistoricalProductMapper;

class VTEXWoowUpProductWorkerMapper extends VTEXWoowUpHistoricalProductMapper
{
    const WEBHOOK_SLACK_CHANNEL = 'vtex-product-webhook-alerts';
    
    public function __construct($vtexConnector, $stockEqualsZero, $notifier = null)
    {
        parent::__construct($vtexConnector, $stockEqualsZero);
        $this->notifier = $notifier;
    }

    protected function buildProduct($vtexProduct)
    {
        $product = parent::buildProduct($vtexProduct);
        if ($this->noPriceAndStock($product)) {
            return null;
        }

        return $product;
    }

    protected function getStockAndPrice(&$product, $vtexProduct)
    {
        $stock  = $this->setStock($product, $vtexProduct);
        $prices = $this->setPrice($product, $vtexProduct);
        
        if (!$this->hasStockOrPrice($stock, $prices)) {
            $this->vtexConnector->_logger->info("Skipping product: impossible to get price and stock.");
            $this->notifier->notifyOnceByFlag($this->getAccountMessage() . "\nMessage: Impossible to get price and stock. Example skipped product: $vtexProduct->Id", $this->vtexConnector->getAppId(), self::WEBHOOK_SLACK_CHANNEL);
            return false;
        }
        
        if ($stock === false || !is_object($prices)) {
            $this->vtexConnector->_logger->info("Could not get price or stock");
            $this->notifier->notifyOnceByFlag($this->getAccountMessage() . "\nMessage: Account cannot access stock or price. Example product: " . $vtexProduct->Id, $this->vtexConnector->getAppId(), self::WEBHOOK_SLACK_CHANNEL);
        }
        
        return true;
    }

    public function hasStockOrPrice($stock, $prices): bool
    {
        return ($stock !== false || is_object($prices));
    }

    public function setStock(&$product, object $vtexProduct)
    {
        $product['stock'] = 0;
        $stock = 0;
        if (!$this->stockEqualsZero) {
            $stock = $this->vtexConnector->searchItemStock($vtexProduct->Id);
            $product['stock'] = $stock;
        }
        
        if ($product['stock'] === false) {
            unset($product['stock']);
        }

        return $stock;
    }

    public function setPrice(&$product, $vtexProduct)
    {
        $prices = $this->vtexConnector->searchItemPrices($vtexProduct->Id);

        if (!(isset($prices) && !empty($prices))) {
            unset($product['price']);
            unset($product['offer_price']);
        } 
        
        if (isset($prices->listPrice)) {
            $product['price'] = (float)$prices->listPrice;
        }

        if (isset($prices->basePrice)) {
            $product['offer_price'] = (float)$prices->basePrice;
        }

        return $prices;
    }

    protected function getAvailable($vtexProduct)
    {
        return $vtexProduct->ProductIsVisible;
    }

    private function noPriceAndStock(?array $product)
    {
        return !(isset($product['stock']) || isset($product['price']) || isset($product['offer_price']));
    }

}