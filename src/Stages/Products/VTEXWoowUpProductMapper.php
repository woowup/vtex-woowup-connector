<?php

namespace WoowUpConnectors\Stages\Products;

use League\Pipeline\StageInterface;

class VTEXWoowUpProductMapper implements StageInterface
{
	protected $vtexConnector;

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

	/**
     * Builds different products from a base product
     * @param  [type] $vtexBaseProduct [description]
     * @return [type]                  [description]
     */
    protected function buildProducts($vtexBaseProduct)
    {
        $baseProduct = [
            'brand'       => $vtexBaseProduct->brand,
            'description' => $vtexBaseProduct->description,
            'url'         => preg_replace('/https?:\/\/.*\.vtexcommercestable\.com\.br/si', $this->vtexConnector->getStoreUrl(), $vtexBaseProduct->link),
            'base_name'   => $vtexBaseProduct->productName,
            'release_date'=> $vtexBaseProduct->releaseDate,
        ];

        $categories = $this->vtexConnector->getCategories();
        if (isset($categories[$vtexBaseProduct->categoryId])) {
            $baseProduct['category'] = $categories[$vtexBaseProduct->categoryId];
        }

        if (isset($vtexBaseProduct->allSpecifications) && !empty($vtexBaseProduct->allSpecifications)) {
            $customAttributes = [];
            $specificationNames = $vtexBaseProduct->allSpecifications;
            foreach ($specificationNames as $specification) {
                $specName = preg_replace("/[^a-zA-Z áéíóúÁÉÍÓÚñÑ]/i", '', utf8_encode($specification));
                $specName = str_replace(' ', '_', $specName);
                $customAttributes[$specName] = strip_tags($vtexBaseProduct->{$specification}[0]);
            }
            $baseProduct['custom_attributes'] = $customAttributes;
        }

        foreach ($vtexBaseProduct->items as $vtexProduct) {
            if (!isset($vtexProduct->referenceId) || empty($vtexProduct->referenceId) || !isset($vtexProduct->referenceId[0]->Value)) {
                continue;
            }
            $sku = $vtexProduct->referenceId[0]->Value;

            $imageUrl = null;
            if (isset($vtexProduct->images[0]) && isset($vtexProduct->images[0]->imageUrl)) {
                $imageUrl = $this->vtexConnector->normalizeResizedImageUrl($vtexProduct->images[0]->imageUrl);
            }

            yield $baseProduct + [
                'image_url'     => $imageUrl,
                'thumbnail_url' => $imageUrl,
                'sku'           => $sku,
                'name'          => $vtexProduct->name,
                'price'         => $this->getItemListPrice($vtexProduct),
                'offer_price'   => $this->getItemPrice($vtexProduct),
                'stock'         => $this->getItemStock($vtexProduct),
                'available'     => true,
            ];
        }
    }

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
            $vtexItemId = $vtexItem->itemId;
            $prices = $this->vtexConnector->searchItemPrices($vtexItem->itemId);
			return $prices->listPrice;
        }
    }
}