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
            'base_name'         => $vtexBaseProduct->productName,
            'release_date'      => $vtexBaseProduct->releaseDate,
            'sku'               => $baseProduct->referenceId[0]->Value,
            'image_url'         => $this->getImageUrl($baseProduct),
            'thumbnail_url'     => $this->getImageUrl($baseProduct),
            'name'              => $baseProduct->name,
            'price'             => $this->getItemListPrice($baseProduct),
            'offer_price'       => $this->getItemPrice($baseProduct),
            'nombre_complementario' => $this->getItemComplementName($baseProduct),
            'stock'             => $this->getStock($vtexBaseProduct),
            'available'         => true
        ];

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