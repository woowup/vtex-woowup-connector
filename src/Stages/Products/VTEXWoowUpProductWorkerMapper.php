<?php

namespace WoowUpConnectors\Stages\Products;
use Connectors\Support\SlackNotificator;
use WoowUpConnectors\Stages\HistoricalProducts\VTEXWoowUpHistoricalProductMapper;
use WoowUpConnectors\Stages\VTEXConfig;
use function GuzzleHttp\Psr7\parse_request;

class VTEXWoowUpProductWorkerMapper extends VTEXWoowUpHistoricalProductMapper
{

    const WEBHOOK_SLACK_CHANNEL = 'vtex-product-webhook-alerts';
    
    public function __construct($vtexConnector, $stockEqualsZero, $notifier = null)
    {
        parent::__construct($vtexConnector, $stockEqualsZero);
        $this->notifier = $notifier;
    }

    protected function getStockAndPrice(&$product, $vtexProduct)
    {
        $stock  = $this->setStock($product, $vtexProduct);
        $prices = $this->setPrice($product, $vtexProduct);
        
            if (!$this->hasStockOrPrice($stock, $prices)) {
            $this->vtexConnector->_logger->info("Skipping product: impossible to get price and stock.");
            $this->notifier->notify_once($this->getAccountMessage() . "\nMessage: Impossible to get price and stock. Example skipped product: $vtexProduct->Id", $this->vtexConnector->getAppId(), self::WEBHOOK_SLACK_CHANNEL);
            return false;
        }
        
        if ($stock === false || !is_object($prices)) {
            $this->vtexConnector->_logger->info("Could not get price or stock");
            $this->notifier->notify_once($this->getAccountMessage() . "\nMessage: Account cannot access stock or price. Example product: " . $vtexProduct->Id, $this->vtexConnector->getAppId(), self::WEBHOOK_SLACK_CHANNEL);
        }
        
        return true;
    }

    public function hasStockOrPrice($stock, $prices): bool
    {
        return ($stock !== false || is_object($prices));
    }

    public function setStock(array &$product, object $vtexProduct)
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

    public function setPrice(array &$product, object $vtexProduct)
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

}