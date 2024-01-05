<?php

namespace WoowUpConnectors\Stages\Products;

use League\Pipeline\StageInterface;

class VTEXWoowUpProductWithChildrenMapper extends VTEXWoowUpProductMapper
{
    /**
     * Builds different products from a base product
     * @param  [type] $vtexBaseProduct [description]
     * @return [type]                  [description]
     */
    protected function buildProducts($vtexBaseProduct)
    {
        $baseProduct = [
            'brand'             => $vtexBaseProduct->brand,
            'description'       => $this->stripHTML($vtexBaseProduct->description),
            'url'               => preg_replace('/https?:\/\/.*\.vtexcommercestable\.com\.br/si', $this->vtexConnector->getStoreUrl(), $vtexBaseProduct->link),
            'base_name'         => $vtexBaseProduct->productName,
            'release_date'      => $vtexBaseProduct->releaseDate
        ];

        $categories = $this->vtexConnector->getCategories();
        if ($this->hasCategory($categories, $vtexBaseProduct)) {
            $baseProduct['category'] = $categories[$vtexBaseProduct->categoryId];
        }

        if ($customAttributes = $this->getCustomAttributes($vtexBaseProduct)) {
            $baseProduct['custom_attributes'] = $customAttributes;
        }

        foreach ($vtexBaseProduct->items as $vtexProduct) {
            if (!$this->hasSku($vtexProduct)) {
                continue;
            }
            $sku = $vtexProduct->referenceId[0]->Value;

            $baseProduct['image_url'] = $this->getImageUrl($vtexProduct);
            $baseProduct['thumbnail_url'] = $this->getImageUrl($vtexProduct);
            $baseProduct['name'] = $vtexProduct->name;
            $baseProduct['sku'] = $sku;
            $baseProduct['available'] = true;

            $baseProduct = $this->getItemInfo($vtexProduct, $baseProduct);

            if($this->isEmptyPrice($baseProduct)) {
                $this->vtexConnector->_logger->info("Skipping product with empty prices: $sku");
                continue;
            }

            yield $baseProduct;
        }
    }

    protected function hasSku($vtexProduct)
    {
        return (isset($vtexProduct->referenceId) && !empty($vtexProduct->referenceId) && isset($vtexProduct->referenceId[0]->Value));
    }

    protected function getItemInfo($vtexProduct, $baseProduct) {
        if ($this->stockAndPriceRealTime) {
            $info = $this->vtexConnector->getStockAndPriceFromSimulation($vtexProduct->itemId);

            $stock = $info->logisticsInfo[0]->stockBalance ?? null;
            $price = $info->items[0]->listPrice ?? null;
            $offer_price = $info->items[0]->sellingPrice ?? null;

            $baseProduct['stock'] = $stock;
            $baseProduct['price'] = $price / self::DIVIDE_FACTOR;
            $baseProduct['offer_price'] = $offer_price / self::DIVIDE_FACTOR;
        } else {
            $baseProduct['price']         = $this->getItemListPrice($vtexProduct);
            $baseProduct['offer_price']   = $this->getItemPrice($vtexProduct);
            $baseProduct['stock']         = $this->getItemStock($vtexProduct);
        }

        return $baseProduct;
    }
}