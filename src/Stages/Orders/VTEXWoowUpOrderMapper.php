<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;

class VTEXWoowUpOrderMapper implements StageInterface
{
	protected $importing;
	protected $vtexConnector;

	public function __construct($vtexConnector, $importing = false, $logger)
	{
		$this->vtexConnector = $vtexConnector;
		$this->importing     = $importing;
        $this->logger        = $logger;
	}

	public function __invoke($payload)
	{
		if ($payload !== null) {
			return $this->buildOrder($payload);
		}

		return null;
	}

	/**
     * Maps a VTEX order to WoowUp's format
     * @param  object $vtexOrder   VTEX order
     * @return array               WoowUp order
     */
    protected function buildOrder($vtexOrder)
    {
        $createtime = date('c', strtotime($vtexOrder->creationDate));

        $order = [
            'invoice_number'  => $vtexOrder->orderId,
            'channel'         => 'web',
            'createtime'      => $createtime,
            'approvedtime'    => $this->importing ? $createtime : date('c'),
            'branch_name'     => $this->getOrderBranch($vtexOrder),
            'customer'        => $this->buildCustomerFromOrder($vtexOrder),
            'purchase_detail' => $this->buildOrderDetails($vtexOrder->items),
            'payment'         => $this->getOrderPayments($vtexOrder),
            'prices'          => $this->getOrderPrices($vtexOrder),
        ];

        if (($seller = $this->getOrderSeller($vtexOrder)) !== null) {
            $order['seller'] = $seller;
        }

        if (isset($order['customer']['document']) && ($order['customer']['document'] !== "")) {
            $order['document'] = $order['customer']['document'];
        }

        if (isset($order['customer']['email']) && ($order['customer']['email'] !== "")) {
            $order['email'] = $order['customer']['email'];
        }

        return $order;
    }

    /**
     * Builds a customer in WoowUp's format from VTEX's order
     * @param  [type] $vtexOrder [description]
     * @return [type]            [description]
     */
    protected function buildCustomerFromOrder($vtexOrder)
    {
        $customer = [
            'email'         => $this->vtexConnector->unmaskEmail($vtexOrder->clientProfileData->email),
            'first_name'    => ucwords(mb_strtolower($vtexOrder->clientProfileData->firstName)),
            'last_name'     => ucwords(mb_strtolower($vtexOrder->clientProfileData->lastName)),
            'document_type' => $vtexOrder->clientProfileData->documentType,
            'document'      => $vtexOrder->clientProfileData->document,
            'telephone'     => $vtexOrder->clientProfileData->phone,
        ];

        // Tomo datos de ubicación desde shippingData
        if (isset($vtexOrder->shippingData) && isset($vtexOrder->shippingData->address) && !empty($vtexOrder->shippingData->address)) {
            $address = $vtexOrder->shippingData->address;
            $customer += [
                'postcode' => $address->postalCode,
                'city'     => ucwords(mb_strtolower($address->city)),
                'state'    => ucwords(mb_strtolower($address->state)),
                'country'  => $address->country,
                'street'   => ucwords(mb_strtolower(trim(trim($address->street) . " " . trim($address->number)))),
            ];
        }

        return $customer;
    }

    /**
     * Builds WoowUp's purchase_detail from VTEX order's item list
     * @param  array $items VTEX order's item list
     * @return array        WoowUp's purchase_detail
     */
    protected function buildOrderDetails($items)
    {
        $purchaseDetail = [];
        foreach ($items as $item) {
            $sku = ($item->refId) ? $item->refId : $this->getProductRefId($item->productId);

            $product = [
                'sku'           => $sku,
                'product_name'  => $item->name,
                'quantity'      => (int) $item->quantity,
                'unit_price'    => (float) $item->price / 100,
                'url'           => rtrim($this->vtexConnector->getStoreUrl(), '/') . '/' . ltrim($item->detailUrl, '/'),
                'image_url'     => $item->imageUrl,
                'thumbnail_url' => $this->vtexConnector->normalizeResizedImageUrl($item->imageUrl),
            ];

            $vtexVariations = $this->vtexConnector->getVariations($item->productId);
            if ($vtexVariations) {
                $product['variations'] = $this->buildVariations($vtexVariations, $item->id);
            }

            $categoryPath = explode('/', trim($item->additionalInfo->categoriesIds, '/'));
            $categoryId   = array_pop($categoryPath);
            if (isset($this->_categories[$categoryId])) {
                $product['category'] = $this->_categories[$categoryId];
            }

            $purchaseDetail[] = $product;
        }

        return $purchaseDetail;
    }

    /**
     * Maps VTEX variations to WoowUp's format
     * @param  object $vtexVariations VTEX variations
     * @param  string $needleItemId   searched VTEX item id
     * @return array                  WoowUp's variations
     */
    protected function buildVariations($vtexVariations, $needleItemId)
    {
        $variations = [];

        if (!empty($vtexVariations) && isset($vtexVariations->dimensions) && !empty($vtexVariations->dimensions)) {
            $properties = [];

            foreach ($vtexVariations->dimensions as $dimension) {
                $properties[] = $dimension;
            }

            if (!empty($properties) && isset($vtexVariations->skus)) {
                foreach ($vtexVariations->skus as $vtexItem) {
                    if (isset($vtexItem->sku) && ($vtexItem->sku == $needleItemId) && (isset($vtexItem->dimensions))) {
                        foreach ($properties as $property) {
                            if (isset($vtexItem->dimensions->$property)) {
                                $variations[] = [
                                    'name'  => $property,
                                    'value' => $vtexItem->dimensions->$property,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $variations;
    }

    /**
     * Maps VTEX payments to WoowUp's API format
     * @param  object $vtexOrder VTEX order
     * @return array             WoowUp's payment
     */
    protected function getOrderPayments($vtexOrder)
    {
        $payment = [];
        if (isset($vtexOrder->paymentData) && isset($vtexOrder->paymentData->transactions)) {
            foreach ($vtexOrder->paymentData->transactions as $vtexTransaction) {
                if (isset($vtexTransaction->payments) && (count($vtexTransaction->payments) > 0)) {
                    foreach ($vtexTransaction->payments as $vtexPayment) {
                        $payment[] = $this->buildOrderPayment($vtexPayment);
                    }
                }
            }
        }

        return $payment;
    }

    /**
     * Maps one single VTEX payment to WoowUp's payment format
     * @param  [type] $vtexPayment [description]
     * @return [type]              [description]
     */
    protected function buildOrderPayment($vtexPayment)
    {
        $payment = [
            'type'  => $this->getPaymentType($vtexPayment->group),
            'total' => (float) $vtexPayment->value / 100,
        ];

        switch ($vtexPayment->paymentSystemName) {
            case $this->vtexConnector::PAYMENT_SERVICES['SERVICE_MP']:
                // TO-DO Implementar conector de mercado pago
                break;
            case $this->vtexConnector::PAYMENT_SERVICES['SERVICE_TP']:
                // TO-DO Implementar conector de todo pago
                break;
            case $this->vtexConnector::PAYMENT_SERVICES['SERVICE_CASH']:
            case $this->vtexConnector::PAYMENT_SERVICES['SERVICE_COUPON']:
            case $this->vtexConnector::PAYMENT_SERVICES['SERVICE_COMMERCE']:
                break;
            default:
                if (isset($vtexPayment->firstDigits) && (trim($vtexPayment->firstDigits) !== "")) {
                    $payment['first_digits'] = trim($vtexPayment->firstDigits);
                }
                break;
        }

        if (isset($vtexPayment->paymentSystemName) && (trim($vtexPayment->paymentSystemName) !== "")) {
            $payment['brand'] = trim($vtexPayment->paymentSystemName);
        }
        if (isset($vtexPayment->installments) && ($vtexPayment->installments > 0)) {
            $payment['installments'] = (int) $vtexPayment->installments;
        }

        return $payment;
    }

    /**
     * Maps VTEX payment's group to WoowUp's payment type
     * @param  [type] $type [description]
     * @return [type]       [description]
     */
    protected function getPaymentType($type)
    {
        // retorno el valor por default de la BD
        if (empty($type)) {
            return '';
        }

        switch (strtolower($type)) {
            case 'creditcard':
            case 'credit':
            case 'credit_card':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_CREDIT'];
            case 'debitcard':
            case 'debit':
            case 'debit_card':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_DEBIT'];
            case 'todopago':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_TP'];
            case 'mercadopago':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_MP'];
            case 'promissory':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_CASH'];
            case 'cash':
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_CASH'];
            default:
                return $this->vtexConnector::PAYMENT_TYPES['TYPE_OTHER'];
        }
    }

    /**
     * Calculates WoowUp's prices based on VTEX's prices
     * @param  [type] $vtexOrder [description]
     * @return [type]            [description]
     */
    protected function getOrderPrices($vtexOrder)
    {
        $prices = [
            'gross'    => 0,
            'tax'      => 0,
            'shipping' => 0,
            'discount' => 0,
            'total'    => 0,
        ];

        foreach ($vtexOrder->totals as $price) {
            switch ($price->id) {
                case 'Items':
                    $prices['gross'] = (float) $price->value / 100;
                    break;
                case 'Discounts':
                    $prices['discount'] = abs((float) $price->value / 100);
                    break;
                case 'Shipping':
                    $prices['shipping'] = (float) $price->value / 100;
                    break;
                case 'Tax':
                    $prices['tax'] = (float) $price->value / 100;
                    break;
            }
        }

        $prices['total'] = $prices['gross'] - $prices['discount'];

        return $prices;
    }

    /**
     * Calculates WoowUp's seller based on VTEX's callCenterOperatorData
     * @param  [type] $vtexOrder [description]
     * @return [type]            [description]
     */
    protected function getOrderSeller($vtexOrder)
    {
        $seller = null;

        if ($vtexOrder->callCenterOperatorData && isset($vtexOrder->callCenterOperatorData->email)) {
            $seller = [
                'email'    => $vtexOrder->callCenterOperatorData->email,
                'name'      => isset($vtexOrder->callCenterOperatorData->userName),
            ];
        }

        return $seller;
    }

    /**
     * Maps VTEX marketplace to WoowUp's API branch name
     * @param  object $vtexOrder VTEX order
     * @return string
     */
    public function getOrderBranch($vtexOrder)
    {
        $branchName = null;

        if (isset($vtexOrder->marketplace->name)) {
            $branchName = $vtexOrder->marketplace->name;
        } else {
            preg_match('/an=\w+$/', $vtexOrder->marketplaceServicesEndpoint, $marketplace);

            if (count($marketplace) > 0) {
                $marketplace = $marketplace[0];
                $branchName = str_replace('an=', '', $marketplace);
            } else {
                $branchName = $this->vtexConnector->getBranchName();
            }
        }

        return ($branchName == $this->vtexConnector->getAppName()) ? $this->vtexConnector->getBranchName() : $branchName;
    }

    /**
     * Gets referenceId/refId for a specific productId
     * @param  [type] $productId [description]
     * @return [type]            [description]
     */
    protected function getProductRefId($productId)
    {
        try {
            $this->logger->info("Searching RefId for productId $productId");
            $product = $this->vtexConnector->getProductByProductId($productId);
            return $product->RefId;
        } catch (\Exception $e) {
            $this->logger->error("Could not obtain refId for product with productId: " . $productId);
            return $productId;
        }
    }
}