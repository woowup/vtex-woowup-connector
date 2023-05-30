<?php

namespace WoowUpConnectors\Stages\Products;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Stages\VTEXConfig;

abstract class VTEXWoowUpProductMapper implements StageInterface
{
    protected $vtexConnector;
    protected $onlyMapsParentProducts;
    protected const PRODUCT_WITHOUT_LIST_PRICE = 0;

    public function __construct($vtexConnector)
    {
        $this->vtexConnector = $vtexConnector;
        $this->onlyMapsParentProducts = !VTEXConfig::mapsChildProducts($this->vtexConnector->getAppId());

        $productsLog = "Mapping " . ($this->onlyMapsParentProducts ? "Parent" : "Child") . "Products";
        $this->vtexConnector->_logger->info($productsLog);

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

    protected function hasItemComplementName($vtexItem)
    {
        return (isset($vtexItem->complementName) && !empty($vtexItem->complementName));
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
        $customAttributes   = [];
        if ($this->hasSpecifications($vtexBaseProduct)) {
            $specificationNames = $vtexBaseProduct->allSpecifications;
            foreach ($specificationNames as $specification) {
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', $specification);
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', utf8_encode($specName));
                $specName = str_replace(' ', '_', $specName);
                $customAttributes[$specName] = strip_tags($vtexBaseProduct->{$specification}[0]);
            }
        }
        foreach ($vtexBaseProduct->items as $item) {
            if ($this->hasItemComplementName($item)) {
                $customAttributes['nombre_complementario'] = $item->complementName;
            }
        }

        $colecciones = [];
        foreach ($vtexBaseProduct->productClusters as $coleccion){
            $colecciones[] = $coleccion;
        }
        $customAttributes['Colecciones'] = $colecciones;


        if (!empty($customAttributes)) {
            return $customAttributes;
        } else {
            return null;
        }
    }

}