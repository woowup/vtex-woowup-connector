<?php

namespace WoowUpConnectors\Stages\Products;

use WoowUpConnectors\Stages\Products\VTEXWoowUpProductMapper;
use WoowUpConnectors\Stages\VTEXConfig;

class VTEXWoowUpProductWithoutChildrenMapper extends VTEXWoowUpProductMapper
{
    /**
     * Builds products from a base product and its first child
     * @param  [type] $vtexBaseProduct [description]
     * @return [type]                  [description]
     */

    protected function buildProducts($vtexBaseProduct)
    {
        if (!$this->hasSku($vtexBaseProduct)) {
            return null;
        }

        $firstItem = $vtexBaseProduct->items[0];
        $availableItem = $this->searchForAvailableProduct($vtexBaseProduct);
        if ($availableItem != $firstItem && isset($availableItem->referenceId[0]->Value)) {
            $this->vtexConnector->_logger->info("Base product with SKU: $vtexBaseProduct->productReference took price from item with SKU: {$availableItem->referenceId[0]->Value}");
        }

        $product = [
            'brand'             => $vtexBaseProduct->brand,
            'description'       => $this->stripHTML($vtexBaseProduct->description),
            'url'               => preg_replace('/https?:\/\/.*\.vtexcommercestable\.com\.br/si', $this->vtexConnector->getStoreUrl(), $vtexBaseProduct->link),
            'release_date'      => $vtexBaseProduct->releaseDate,
            'image_url'         => $this->getImageUrl($firstItem),
            'thumbnail_url'     => $this->getImageUrl($firstItem),
            'stock'             => $this->getStock($vtexBaseProduct),
            'available'         => true
        ];

        $product = $this->getItemInfo($availableItem, $product);

        if($this->skipEmptyPrices($product)) {
            $this->vtexConnector->_logger->info("Skipping product with empty prices: $sku");
            return null;
        }

        if ($this->onlyMapsParentProducts) {
            $product['name'] = $vtexBaseProduct->productName;
            $product['sku']  = $vtexBaseProduct->productReference;
        } else {
            $product['base_name'] = $vtexBaseProduct->productName;
            $product['name']      = $firstItem->name;
            $product['sku']       = $firstItem->referenceId[0]->Value;
        }

        $categories = $this->vtexConnector->getCategories();
        if ($this->hasCategory($categories, $vtexBaseProduct)) {
            $product['category'] = $categories[$vtexBaseProduct->categoryId];
        }

        if ($customAttributes = $this->getCustomAttributes($vtexBaseProduct)) {
            $product['custom_attributes'] = $customAttributes;
        }

        yield $product;
    }



    protected function hasSku($vtexBaseProduct)
    {
        if ($this->onlyMapsParentProducts) {
            return (isset($vtexBaseProduct->productReference) && !empty($vtexBaseProduct->productReference));
        }

        return (isset($vtexBaseProduct->items[0]) && isset($vtexBaseProduct->items[0]->referenceId) && !empty($vtexBaseProduct->items[0]->referenceId) && isset($vtexBaseProduct->items[0]->referenceId[0]->Value));
    }

    protected function getStock($vtexBaseProduct)
    {
        $stock = 0;
        foreach ($vtexBaseProduct->items as $vtexProduct) {
            $stock += $this->getItemStock($vtexProduct);
        }
        return $stock;
    }

    private function searchForAvailableProduct($vtexBaseProduct) {
        foreach ($vtexBaseProduct->items as $vtexItem) {
            $available = $vtexItem->sellers[0]->commertialOffer->IsAvailable ?? false;
            if ($available) {
                return $vtexItem;
            }
        }

        return $vtexBaseProduct->items[0];
    }

    protected function getItemInfo($vtexProduct, $baseProduct)
    {
        if ($this->stockAndPriceRealTime) {
            $info = $this->vtexConnector->getStockAndPriceFromSimulation($vtexProduct->itemId);

            $price = $info->items[0]->listPrice ?? null;
            $offer_price = $info->items[0]->sellingPrice ?? null;

            $baseProduct['price'] = $price / self::DIVIDE_FACTOR;
            $baseProduct['offer_price'] = $offer_price / self::DIVIDE_FACTOR;
        } else {
            $baseProduct['price']         = $this->getItemListPrice($vtexProduct);
            $baseProduct['offer_price']   = $this->getItemPrice($vtexProduct);
        }

        return $baseProduct;
    }



}