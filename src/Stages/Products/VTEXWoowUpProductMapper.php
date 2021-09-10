<?php

namespace WoowUpConnectors\Stages\Products;

use League\Pipeline\StageInterface;

abstract class VTEXWoowUpProductMapper implements StageInterface
{
    protected $vtexConnector;
    protected const PRODUCT_WITHOUT_LIST_PRICE = 0;

    public function __construct($vtexConnector)
    {
        $this->vtexConnector = $vtexConnector;

        return $this;
    }

    public function __invoke($payload)
    {
        foreach ($this->buildProducts($payload) as $product) {
            yield $product;
        }
    }

    abstract protected function hasSku($vtexBaseProduct);

    abstract protected function buildProducts($vtexBaseProduct);

    /**
     * Gets stock for an item
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    protected function getItemStock($vtexItem)
    {
        if (isset($vtexItem->sellers) && isset($vtexItem->sellers[0]) && isset($vtexItem->sellers[0]->commertialOffer) && isset($vtexItem->sellers[0]->commertialOffer->AvailableQuantity)) {
            return $vtexItem->sellers[0]->commertialOffer->AvailableQuantity;
        } else {
            return null;
        }
    }

    /**
     * Gets price from vtex item, if not set finds it in API
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    protected function getItemPrice($vtexItem)
    {
        if (isset($vtexItem->sellers) && isset($vtexItem->sellers[0]) && isset($vtexItem->sellers[0]->commertialOffer) && isset($vtexItem->sellers[0]->commertialOffer->Price)) {
            return $vtexItem->sellers[0]->commertialOffer->Price;
        } else {
            $prices = $this->vtexConnector->searchItemPrices($vtexItem->itemId);
            return $prices->basePrice;
        }
    }

    /**
     * Gets list price from vtex item, if not set finds it in API
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    protected function getItemListPrice($vtexItem)
    {
        if (isset($vtexItem->sellers) && isset($vtexItem->sellers[0]) && isset($vtexItem->sellers[0]->commertialOffer) && isset($vtexItem->sellers[0]->commertialOffer->ListPrice)) {
            return $vtexItem->sellers[0]->commertialOffer->ListPrice;
        } else {
            $prices = $this->vtexConnector->searchItemPrices($vtexItem->itemId);
            if (isset($prices->listPrice)) {
                return $prices->listPrice;
            }
            return self::PRODUCT_WITHOUT_LIST_PRICE;
        }
    }

    public function hasImageUrl($baseProduct)
    {
        return isset($baseProduct->images[0]) && isset($baseProduct->images[0]->imageUrl);
    }

    public function hasCategory($categories, $vtexBaseProduct)
    {
        return isset($categories[$vtexBaseProduct->categoryId]);
    }

    public function hasSpecifications($vtexBaseProduct)
    {
        return isset($vtexBaseProduct->allSpecifications) && !empty($vtexBaseProduct->allSpecifications);
    }

    public function getImageUrl($baseProduct)
    {
        $imageUrl = null;
        if ($this->hasImageUrl($baseProduct)) {
            $imageUrl = $this->vtexConnector->normalizeResizedImageUrl($baseProduct->images[0]->imageUrl);
        }
        return $imageUrl;
    }

    public function getCustomAttributes($vtexBaseProduct)
    {
        if ($this->hasSpecifications($vtexBaseProduct)) {
            $customAttributes   = [];
            $specificationNames = $vtexBaseProduct->allSpecifications;
            foreach ($specificationNames as $specification) {
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', $specification);
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', utf8_encode($specName));
                $specName = str_replace(' ', '_', $specName);
                $customAttributes[$specName] = strip_tags($vtexBaseProduct->{$specification}[0]);
            }
            return $customAttributes;
        }
        return null;
    }
}