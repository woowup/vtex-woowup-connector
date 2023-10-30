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

            $baseProduct = $baseProduct + [
                    'image_url'     => $this->getImageUrl($vtexProduct),
                    'thumbnail_url' => $this->getImageUrl($vtexProduct),
                    'sku'           => $sku,
                    'name'          => $vtexProduct->name,
                    'available'     => true,
                ];

            $baseProduct = $this->getItemInfo($vtexProduct, $baseProduct);

            yield $baseProduct;
        }
    }

    protected function hasSku($vtexProduct)
    {
        return (isset($vtexProduct->referenceId) && !empty($vtexProduct->referenceId) && isset($vtexProduct->referenceId[0]->Value));
    }
}