<?php

namespace WoowUpConnectors\Stages\HistoricalProducts;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Stages\VTEXConfig;

class VTEXWoowUpHistoricalProductMapper implements StageInterface
{
    protected $vtexConnector;
    protected $stockEqualsZero;
    protected $onlyMapsParentProducts;

    public function __construct($vtexConnector, $stockEqualsZero)
    {
        $this->vtexConnector = $vtexConnector;
        $this->stockEqualsZero = $stockEqualsZero;
        $this->onlyMapsParentProducts = !VTEXConfig::mapsChildProducts($this->vtexConnector->getAppId());

        $productsLog = "Mapping " . ($this->onlyMapsParentProducts ? "Parent" : "Child") . "Products";
        $this->vtexConnector->_logger->info($productsLog);

        return $this;
    }

    public function __invoke($payload)
    {
        if (!is_null($payload)) {
            return $this->buildProduct($payload);
        }

        return null;
    }

    /**
     * Maps a VTEX product to WoowUp's format
     * @param  object $vtexProduct   VTEX product
     * @return array                 WoowUp product
     */
    protected function buildProduct($vtexProduct)
    {
        if (!$this->hasSku($vtexProduct)) {
            return null;
        }

        $product = [
            'brand'             => $vtexProduct->BrandName,
            'description'       => $vtexProduct->ProductDescription,
            'url'               => $this->vtexConnector->getStoreUrl() . $vtexProduct->DetailUrl,
            'release_date'      => $vtexProduct->ReleaseDate,
            'available'         => $this->getAvailable($vtexProduct)
        ];

        if (isset($vtexProduct->Images[0]->ImageUrl)) {
            $product['image_url'] = $vtexProduct->Images[0]->ImageUrl;
            $product['thumbnail_url'] = $vtexProduct->Images[0]->ImageUrl;
        }

        if ($this->onlyMapsParentProducts) {
            $product['name'] = $vtexProduct->ProductName;
            $product['sku']  = $vtexProduct->ProductRefId;
        } else {
            $product['base_name'] = $vtexProduct->ProductName;
            $product['name']      = $vtexProduct->NameComplete;
            $product['sku']       = $vtexProduct->AlternateIds->RefId;
        }

        if (!$this->getStockAndPrice($product, $vtexProduct)) {
            return null;
        }

        if (
            $this->vtexConnector->getSyncCategories() &&
            isset($vtexProduct->ProductCategoryIds)   &&
            !empty($vtexProduct->ProductCategoryIds)  &&
            isset($vtexProduct->ProductCategories)    &&
            !empty($vtexProduct->ProductCategories)
            )
        {
            $product['category'] = $this->getCategories($vtexProduct->ProductCategoryIds, $vtexProduct->ProductCategories);
        }

        if ($customAttributes = $this->getCustomAttributes($vtexProduct)) {
            $product['custom_attributes'] = $customAttributes;
        }

        return $product;
    }

    protected function hasSku($vtexProduct)
    {
        if ($this->onlyMapsParentProducts) {
            return (isset($vtexProduct->ProductRefId) && !empty($vtexProduct->ProductRefId));
        }

        return (isset($vtexProduct->AlternateIds) && isset($vtexProduct->AlternateIds->RefId) && !empty($vtexProduct->AlternateIds->RefId));
    }

    protected function getCategories($productCategoryIds, $productCategories): array
    {
        //cambio $productCategoryIds para poder recorrer cada clave
        $productCategoryIds = explode('/', $productCategoryIds);

        //recorro $productCategories y lo guardo en $categoriesById para poder utilizar funciones de arrays
        $categoriesById = [];
        foreach ($productCategories as $key => $value) {
            $categoriesById[$key] = $value;
        }

        $categories = [];
        foreach ($productCategoryIds as $key) {
            //las funciones in_array() y array_keys() no funcionan con objetos iterables
            if (in_array($key, array_keys($categoriesById))) {
                $categories[$key] = $categoriesById[$key];
            }
        }

        $categories = $this->mapCategories($categories);
        //mapCategories() devuelve un array indexado por claves de categoria
        $categories = $this->fixCategories($categories);
        //fixCategories() devuelve un array ordenado indexado en cero

        return $categories;
    }

    protected function mapCategories($categoriesById)
    {
        $categories = [];
        foreach ($categoriesById as $key => $value) {
            $categories[$key] = [
                'id'   => (string) $key,
                'name' => (string) $value
            ];
        }
        return $categories;
    }

    protected function fixCategories($categories)
    {
        $cleanCategories = [];

        foreach ($categories as $key => $value) {
            $cleanCategories[] = $value;
        }

        return $cleanCategories;
    }

    protected function getCustomAttributes($vtexProduct)
    {
        $customAttributes = [];
        if (isset($vtexProduct->ProductSpecifications) && !empty($vtexProduct->ProductSpecifications)) {
            foreach ($vtexProduct->ProductSpecifications as $specification) {
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', $specification->FieldName);
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', utf8_encode($specName));
                $specName = str_replace(' ', '_', $specName);
                $customAttributes[$specName] = strip_tags($specification->FieldValues[0]);
            }
        }

        if (isset($vtexProduct->ComplementName) && !empty($vtexProduct->ComplementName)) {
            $customAttributes['nombre_complementario'] = $vtexProduct->ComplementName;
        }

        if (empty($customAttributes)) {
            return null;
        }

        return $customAttributes;
    }


    protected function getStockAndPrice(&$product, $vtexProduct) {
        $product['stock'] = 0;
        if (!$this->stockEqualsZero) {
            $product['stock'] = $this->vtexConnector->searchItemStock($vtexProduct->Id);
        }

        if ($product['stock'] == 0) {
            $product['available'] = false;
        }

        $prices = $this->vtexConnector->searchItemPrices($vtexProduct->Id);
        if (isset($prices) && !empty($prices)) {
            $product['price'] = (float) $prices->listPrice;
            $product['offer_price'] = (float) $prices->basePrice;
        } else {
            $product['price'] = 0;
            $product['offer_price'] = 0;
        }
    }

    protected function getAvailable($vtexProduct) {
        return true;
    }

    protected function getAccountMessage(): string
    {
        return "Name: {$this->vtexConnector->getAppName()}\nAccount: {$this->vtexConnector->getAppId()}\n";
    }
}