<?php

namespace WoowUpConnectors\Stages\Products;

use WoowUpConnectors\Stages\Products\VTEXWoowUpProductMapper;

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

        $baseProduct = $vtexBaseProduct->items[0];

        $product = [
            'brand'             => $vtexBaseProduct->brand,
            'description'       => $vtexBaseProduct->description,
            'url'               => preg_replace('/https?:\/\/.*\.vtexcommercestable\.com\.br/si', $this->vtexConnector->getStoreUrl(), $vtexBaseProduct->link),
            'release_date'      => $vtexBaseProduct->releaseDate,
            'image_url'         => $this->getImageUrl($baseProduct),
            'thumbnail_url'     => $this->getImageUrl($baseProduct),
            'price'             => $this->getItemListPrice($baseProduct),
            'offer_price'       => $this->getItemPrice($baseProduct),
            'stock'             => $this->getStock($vtexBaseProduct),
            'available'         => true
        ];

        if ($this->onlyMapsParentProducts) {
            $product['name'] = $vtexBaseProduct->productName;
            $product['sku']  = $vtexBaseProduct->productReference;
        } else {
            $product['base_name'] = $vtexBaseProduct->productName;
            $product['name']      = $baseProduct->name;
            $product['sku']       = $baseProduct->referenceId[0]->Value;
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
}