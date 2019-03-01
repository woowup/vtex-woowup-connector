<?php

namespace WoowUpConnectors;

use Psr;

class VTEX
{
    private $_host;
    private $_appName;
    private $_appKey;
    private $_appToken;
    private $_status;
    private $_branchName;
    private $_storeUrl;

    private $_categories;
    private $_filters;

    private $_httpClient;
    private $_woowupClient;
    private $_logger;

    private $_woowupStats = [
        'customers' => [
            'created' => 0,
            'updated' => 0,
            'failed'  => [],
        ],
        'orders'    => [
            'created'    => 0,
            'updated'    => 0,
            'duplicated' => 0,
            'failed'     => [],
        ],
        'products'  => [
            'created' => 0,
            'updated' => 0,
            'failed'  => [],
        ],
    ];

    const STATUS_INVOICED = 'invoiced';
    const CUSTOMER_TAG    = 'VTEX';

    const HOST_UNMASK          = 'http://conversationtracker.vtex.com.br/api/pvt/emailMapping';
    const PERSONALDATA_URL     = "/api/profile-system/pvt/profiles/{email}/personalData";
    const GIFTCARD_URL         = '/api/gift-card-system/pvt/giftCards';
    const GIFTCARDCREDIT_URL   = '/api/gift-card-system/pvt/giftCards/{cardId}/credit';
    const CHECK_CONNECTION_URL = '/api/oms/pvt/orders';

    const PRODUCTS_SEARCH_OFFSET = 0;
    const PRODUCTS_SEARCH_LIMIT  = 25;

    const BRANCH_NAME = 'VTEX';

    const EMAIL_CONVERSATION_TRACKER_HOST = "http://conversationtracker.vtex.com.br";

    const PAYMENT_TYPES = [
        'TYPE_CREDIT' => 'credit',
        'TYPE_DEBIT'  => 'debit',
        'TYPE_OTHER'  => 'other',
        'TYPE_MP'     => 'mercadopago',
        'TYPE_TP'     => 'todopago',
        'TYPE_CASH'   => 'cash',
    ];

    const PAYMENT_SERVICES = [
        'SERVICE_MP'       => 'mercadopago',
        'SERVICE_TP'       => 'todopago',
        'SERVICE_VTEX'     => 'vtex',
        'SERVICE_CASH'     => 'Pago contra entrega',
        'SERVICE_COMMERCE' => 'Pago en Punto de Venta',
        'SERVICE_COUPON'   => 'Vale',
    ];

    const PAYMENT_BRANDS = [
        'CARD_MASTERCARD' => 'mastercard',
        'CARD_VISA'       => 'visa',
        'CARD_CABAL'      => 'cabal',
    ];

    const VTEX_CONFIG_REQUIRED = ['appName', 'appKey', 'appToken', 'storeUrl'];

    public function __construct($vtexConfig, \GuzzleHttp\ClientInterface $httpClient, Psr\Log\LoggerInterface $logger, $woowupClient)
    {
        try {
            $this->_logger = $logger;
            $this->checkVtexConfig($vtexConfig);

            $this->_host         = 'http://' . $vtexConfig['appName'] . '.vtexcommercestable.com.br';
            $this->_appKey       = $vtexConfig['appKey'];
            $this->_appToken     = $vtexConfig['appToken'];
            $this->_appName      = $vtexConfig['appName'];
            $this->_status       = (isset($vtexConfig['status']) && $vtexConfig['status']) ? $status : [self::STATUS_INVOICED];
            $this->_storeUrl     = $vtexConfig['storeUrl'];
            $this->_httpClient   = $httpClient;
            $this->_woowupClient = $woowupClient;
        } catch (\Exception $e) {
            $this->_logger->error("VTEX Service Error: " . $e->getMessage());
            return null;
        }
    }

    // TO-DO preguntar a diego cuales son los parametros
    public function importOrders($updateOrders = false, $from_date = null, $page = 1, $order = null, $per_page = 200, $salesChannel = null, $branchName = null, $allowedSellers = null, $getCategories = false)
    {
        // TO-DO Borrar este counter
        $counter = 5;
        $this->_logger->info("Importing orders for " . $this->_appName);
        foreach ($this->getOrders($from_date, $page, $order, $per_page, $salesChannel, $branchName, $allowedSellers, $getCategories) as $order) {
            $this->upsertCustomer($order['customer']);
            $this->upsertOrder($order, $updateOrders);
            if (--$counter === 0) {
                break;
            }
        }

        if (count($this->_woowupStats['orders']['failed']) > 0) {
            $this->_logger->info("Retrying failed orders");
            $failedOrders                           = $this->_woowupStats['orders']['failed'];
            $this->_woowupStats['orders']['failed'] = [];
            foreach ($failedOrders as $order) {
                $this->upsertCustomer($order['customer']);
                $this->upsertOrder($order, $updateOrders);
            }
        }

        $this->_logger->info("Finished. Stats:");
        $this->_logger->info("Created orders: " . $this->_woowupStats['orders']['created']);
        $this->_logger->info("Duplicated orders: " . $this->_woowupStats['orders']['duplicated']);
        $this->_logger->info("Updated orders: " . $this->_woowupStats['orders']['updated']);
        $this->_logger->info("Failed orders: " . count($this->_woowupStats['orders']['failed']));
    }

    public function upsertOrder($order, $update)
    {
        try {
            $this->_woowupClient->purchases->create($order);
            $this->_logger->info("[Purchase] {$order['invoice_number']} Created Successfully");
            $this->_woowupStats['orders']['created']++;
            return true;
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response = json_decode($e->getResponse()->getBody(), true);
                switch ($response['code']) {
                    case 'user_not_found':
                        $this->_logger->info("[Purchase] {$order['invoice_number']} Error: customer not found");
                        $this->_woowupStats['orders']['failed'][] = $order;
                        return false;
                        break;
                    case 'duplicated_purchase_number':
                        $this->_logger->info("[Purchase] {$order['invoice_number']} Duplicated");
                        $this->_woowupStats['orders']['duplicated']++;
                        if ($update) {
                            $this->_woowupClient->purchases->update($order);
                            $this->_logger->info("[Purchase] {$order['invoice_number']} Updated Successfully");
                            $this->_woowupStats['orders']['updated']++;
                        }
                        return true;
                        break;
                    default:
                        $errorCode    = $response['code'];
                        $errorMessage = $response['payload']['errors'][0];
                        break;
                }
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->_logger->info("[Purchase] {$order['invoice_number']} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->_woowupStats['orders']['failed'][] = $order;

            return false;
        }
    }

    public function upsertCustomer($customer)
    {
        $customerIdentity = [
            'email'    => isset($customer['email']) ? $customer['email'] : '',
            'document' => isset($customer['document']) ? $customer['document'] : '',
        ];
        try {
            if (!$this->_woowupClient->multiusers->exist($customerIdentity)) {
                $this->_woowupClient->users->create($customer);
                $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Created Successfully");
                $this->_woowupStats['customers']['created']++;
            } else {
                $this->_woowupClient->multiusers->update($customer);
                $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Updated Successfully");
                $this->_woowupStats['customers']['updated']++;
            }
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response     = json_decode($e->getResponse()->getBody(), true);
                $errorCode    = $response['code'];
                $errorMessage = $response['payload']['errors'][0];
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->_logger->info("[Customer] " . implode(',', $customerIdentity) . " Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->_woowupStats['customers']['failed'][] = $customer;

            return false;
        }

        return true;
    }

    /**
     * Checks minimum parameters to get connector running
     * @param  [type] $vtexConfig [description]
     * @return [type]             [description]
     */
    public function checkVtexConfig($vtexConfig)
    {
        foreach (self::VTEX_CONFIG_REQUIRED as $parameter) {
            if (!isset($vtexConfig[$parameter])) {
                throw new \Exception("$parameter is missing", 1);
            }
        }

        return true;
    }

    /**
     * Sets HTTP Client that fits ClientInterface
     * @param ClientInterface $httpClient [description]
     */
    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->_httpClient = $httpClient;
    }

    /**
     * Sets Logger that fits LoggerInterface
     * @param LoggerInterface $logger [description]
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }

    /**
     * Gets products from VTEX's API and maps them to WoowUp's API format
     * @param  boolean $getCategories sync categories
     * @return array   $products      products in WoowUp's API format
     */
    public function getProducts($getCategories = false)
    {
        $offset = self::PRODUCTS_SEARCH_OFFSET;
        $limit  = self::PRODUCTS_SEARCH_LIMIT;

        if ($getCategories === true) {
            $this->_logger->info("Getting categories... ");
            $this->_categories = $this->getCategories();
            $this->_logger->info("Success!");
        } else {
            $this->_categories = [];
        }

        $totalProductsRetrieved = 0;

        do {
            $this->_logger->info("Getting products from $offset to " . ($offset + $limit - 1) . "... ");
            // TO-DO traer por categorías hojas
            $response = $this->_get('/api/catalog_system/pub/products/search', ['_from' => $offset, '_to' => $offset + $limit - 1]);

            if (($response->getStatusCode() !== 200) && ($response->getStatusCode() !== 206)) {
                throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
            }

            $this->_logger->info("Success!");

            $total = explode('/', $response->getHeader('resources')[0])[1];

            $vtexProducts = json_decode($response->getBody());

            foreach ($vtexProducts as $vtexBaseProduct) {
                foreach ($this->buildProducts($vtexBaseProduct) as $product) {
                    $totalProductsRetrieved++;
                    yield $product;
                }
            }

            $offset += $limit;
        } while (($limit + $offset) < $total);

        $this->_logger->info("Done! Total products retrieved " . $totalProductsRetrieved);
    }

    /**
     * Gets orders from VTEX's API and maps them to WoowUp's API format
     * @param  string  $from_date      oldest order date format [TO-DO poner formato válido]
     * @param  integer $page           first page
     * @param  string  $order          parameter to sort orders by
     * @param  integer $per_page       amount of orders per page
     * @param  string  $salesChannel   sales channel
     * @param  string  $branchName     branch name to set to orders
     * @param  array   $allowedSellers array of strings containing allowed sellers
     * @param  boolean $getCategories  sync categories
     * @return array   $orders         orders in WoowUp's API format
     */
    public function getOrders($from_date = null, $page = 1, $order = null, $per_page = 200, $salesChannel = null, $branchName = null, $allowedSellers = null, $getCategories = false)
    {
        $this->_branchName = $branchName ? $branchName : self::BRANCH_NAME;

        $params = array(
            'f_status' => join(',', $this->_status),
            'page'     => $page,
            'per_page' => $per_page,
        );

        if ($order) {
            $params['orderBy'] = $order;
        }

        if ($from_date != null) {
            $params += array(
                'f_creationDate' => $from_date,
            );
        }

        if ($salesChannel) {
            $params += ['f_salesChannel' => $salesChannel];
        }

        if ($getCategories === true) {
            $this->_logger->info("Getting categories...");
            $this->_categories = $this->getCategories();
            $this->_logger->info("Success!");
        } else {
            $this->_categories = [];
        }

        do {
            $response = $this->_get('/api/oms/pvt/orders/', $params);

            if ($response->getStatusCode() === 200) {
                $response    = json_decode($response->getBody());
                $totalOrders = $response->paging->total;
                foreach ($response->list as $vtexOrder) {
                    if (!$this->isAllowedSeller($vtexOrder, $allowedSellers)) {
                        continue;
                    }
                    $order = $this->buildOrder($vtexOrder->orderId);
                    foreach ($this->_filters as $filter) {
                        if (method_exists($filter, 'getPurchasePoints') && (($points = $filter->getPurchasePoints($order)) != 0)) {
                            $order['points'] = $points;
                        }
                    }
                    yield $order;
                }
            } else {
                throw new Exception($response->getReasonPhrase(), $response->getStatusCode());
            }
        } while (($params['page'] * $params['per_page']) < $totalOrders);
    }

    /**
     * Checks if seller is allowed
     * @param  object  $vtexOrder       VTEX order object
     * @param  array   $allowedSellers  array of allowed sellers
     * @return boolean $isAllowedSeller isAllowedSeller
     */
    public function isAllowedSeller($vtexOrder, $allowedSellers)
    {
        if (!is_null($allowedSellers) && isset($vtexOrder->sellers) && (count($vtexOrder->sellers) > 0)) {
            foreach ($vtexOrder->sellers as $seller) {
                if (!in_array(strtolower($seller->name), $allowedSellers)) {
                    $this->_logger->info($seller->name . " is not a valid seller");
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Maps a VTEX order to WoowUp's format
     * @param  object $vtexOrderId VTEX order
     * @return array               WoowUp order
     */
    public function buildOrder($vtexOrderId)
    {
        $vtexOrder = $this->getOrder($vtexOrderId);

        $order = [
            'invoice_number'  => $vtexOrderId,
            'channel'         => 'web',
            'createtime'      => date('c', strtotime($vtexOrder->creationDate)),
            'approvedtime'    => date('c'),
            'branch_name'     => $this->_branchName,
            'customer'        => $this->buildCustomerFromOrder($vtexOrder),
            'purchase_detail' => $this->buildOrderDetails($vtexOrder->items),
            'payment'         => $this->getOrderPayments($vtexOrder),
            'prices'          => $this->getOrderPrices($vtexOrder),
        ];

        if (isset($order['customer']['document']) && ($order['customer']['document'] !== "")) {
            $order['document'] = $order['customer']['document'];
        }

        if (isset($order['customer']['email']) && ($order['customer']['email'] !== "")) {
            $order['email'] = $order['customer']['email'];
        }

        return $order;
    }

    /**
     * Builds WoowUp's purchase_detail from VTEX order's item list
     * @param  array $items VTEX order's item list
     * @return array        WoowUp's purchase_detail
     */
    public function buildOrderDetails($items)
    {
        $purchaseDetail = [];
        foreach ($items as $item) {
            $sku = ($item->refId) ? $item->refId : $this->getProductRefId($item->productId);
            foreach ($this->_filters as $filter) {
                if (method_exists($filter, 'filterSku')) {
                    $auxSku = $filter->filterSku($sku);
                    $this->_logger->info("Filtered $sku into " . trim($auxSku));
                    $sku = $auxSku ? $auxSku : $sku;
                }
            }

            $product = [
                'sku'           => $sku,
                'product_name'  => $item->name,
                'quantity'      => (int) $item->quantity,
                'unit_price'    => (float) $item->price / 100,
                'variations'    => $this->getVariations($item),
                'url'           => rtrim($this->_storeUrl, '/') . '/' . ltrim($item->detailUrl, '/'),
                'image_url'     => $item->imageUrl,
                'thumbnail_url' => $this->normalizeResizedImageUrl($item->imageUrl),
            ];

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
     * Normalizes an imageUrl
     * @param  [type] $imageUrl [description]
     * @return [type]           [description]
     */
    public function normalizeResizedImageUrl($imageUrl)
    {
        // Le saco los parametros de resize de imagen que son los digitos despues del id.
        $regex = '/([\S]+vteximg\.com\.br\/arquivos\/ids\/\d+)\-\d+\-\d+(\/[\S]+)/';
        return preg_replace($regex, '$1$2', $imageUrl);
    }

    /**
     * Get's VTEX variations for an item and returns them in WoowUp's format
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    public function getVariations($vtexItem)
    {
        try {
            $response = $this->_get('/api/catalog_system/pub/products/variations/' . $vtexItem->productId, []);

            if ($response->getStatusCode() === 200) {
                $response = json_decode($response->getBody());
                return $this->buildVariations($response, $vtexItem->id);
            }
        } catch (\Exception $e) {
            $this->_logger->error("Error getting variations: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Maps VTEX variations to WoowUp's format
     * @param  object $vtexVariations VTEX variations
     * @param  string $needleItemId   searched VTEX item id
     * @return array                  WoowUp's variations
     */
    public function buildVariations($vtexVariations, $needleItemId)
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
    public function getOrderPayments($vtexOrder)
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
    public function buildOrderPayment($vtexPayment)
    {
        $payment = [
            'type'  => $this->getPaymentType($vtexPayment->group),
            'total' => (float) $vtexPayment->value / 100,
        ];

        switch ($vtexPayment->paymentSystemName) {
            case self::PAYMENT_SERVICES['SERVICE_MP']:
                // TO-DO Implementar conector de mercado pago
                break;
            case self::PAYMENT_SERVICES['SERVICE_TP']:
                // TO-DO Implementar conector de todo pago
                break;
            case self::PAYMENT_SERVICES['SERVICE_CASH']:
            case self::PAYMENT_SERVICES['SERVICE_COUPON']:
            case self::PAYMENT_SERVICES['SERVICE_COMMERCE']:
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
     * Calculates WoowUp's prices based on VTEX's prices
     * @param  [type] $vtexOrder [description]
     * @return [type]            [description]
     */
    public function getOrderPrices($vtexOrder)
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
     * Maps VTEX payment's group to WoowUp's payment type
     * @param  [type] $type [description]
     * @return [type]       [description]
     */
    public function getPaymentType($type)
    {
        // retorno el valor por default de la BD
        if (empty($type)) {
            return '';
        }

        switch (strtolower($type)) {
            case 'creditcard':
            case 'credit':
            case 'credit_card':
                return self::PAYMENT_TYPES['TYPE_CREDIT'];
            case 'debitcard':
            case 'debit':
            case 'debit_card':
                return self::PAYMENT_TYPES['TYPE_DEBIT'];
            case 'todopago':
                return self::PAYMENT_TYPES['TYPE_TP'];
            case 'mercadopago':
                return self::PAYMENT_TYPES['TYPE_MP'];
            case 'promissory':
                return self::PAYMENT_TYPES['TYPE_CASH'];
            case 'cash':
                return self::PAYMENT_TYPES['TYPE_CASH'];
            default:
                return self::PAYMENT_TYPES['TYPE_OTHER'];
        }
    }

    /**
     * Gets referenceId/refId for a specific productId
     * @param  [type] $productId [description]
     * @return [type]            [description]
     */
    public function getProductRefId($productId)
    {
        try {
            $product = $this->getProductByProductId($productId);
            return $product->RefId;
        } catch (\Exception $e) {
            $this->_logger->error("Could not obtain refId for product with productId: " . $productId);
            return $productId;
        }
    }

    /**
     * Finds a product in VTEX's API by product Id
     * @param  [type] $productId [description]
     * @return [type]            [description]
     */
    public function getProductByProductId($productId)
    {
        $response = $this->_get('/api/catalog_system/pvt/products/ProductGet/' . $productId);

        if ($response->getStatusCode() === 200) {
            return $response->getBody();
        } else {
            throw new Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
    }

    /**
     * Builds a customer in WoowUp's format from VTEX's order
     * @param  [type] $vtexOrder [description]
     * @return [type]            [description]
     */
    public function buildCustomerFromOrder($vtexOrder)
    {
        $customer = [
            'email'         => $this->unmaskEmail($vtexOrder->clientProfileData->email),
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
     * Unmasks an email from a VTEX order's alias
     * @param  [type] $alias [description]
     * @return [type]        [description]
     */
    public function unmaskEmail($alias)
    {
        try {
            $this->_logger->info("Converting $alias...");

            $this->_host = 'http://conversationtracker.vtex.com.br';

            $params = [
                'an'    => $this->_appName,
                'alias' => $alias,
            ];

            $response = $this->_get('/api/pvt/emailMapping', $params);

            $this->_host = 'http://' . $this->_appName . '.vtexcommercestable.com.br';

            $response = json_decode($response->getBody(), true);
            if (isset($response['email'])) {
                $response = $response['email'];
                $this->_logger->info("Success! Got " . $response);
            } else {
                $this->_logger->info("Could not convert alias :(");
                $response = $alias;
            }

            return $response;
        } catch (\Exception $e) {
            $this->_host = 'http://' . $this->_appName . '.vtexcommercestable.com.br';
            $this->_logger->error("Error at request attempt " . $e->getMessage());
            return null;
        }
    }

    /**
     * Finds an order in VTEX's API by order id
     * @param  [type] $vtexOrderId [description]
     * @return [type]              [description]
     */
    public function getOrder($vtexOrderId)
    {
        $this->_logger->info("Getting order " . $vtexOrderId . "... ");
        $response = $this->_get('/api/oms/pvt/orders/' . $vtexOrderId);

        if ($response->getStatusCode() === 200) {
            $this->_logger->info("Success!");
            return json_decode($response->getBody());
        } else {
            throw new Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
    }

    /**
     * Builds different products from a base product
     * @param  [type] $vtexBaseProduct [description]
     * @return [type]                  [description]
     */
    public function buildProducts($vtexBaseProduct)
    {
        $baseProduct = [
            'brand'       => $vtexBaseProduct->brand,
            'description' => $vtexBaseProduct->description,
            'url'         => preg_replace('/https?:\/\/.*\.vtexcommercestable\.com\.br/si', $this->_storeUrl, $vtexBaseProduct->link),
        ];

        if (isset($this->_categories[$vtexBaseProduct->categoryId])) {
            $baseProduct['category'] = $this->_categories[$vtexBaseProduct->categoryId];
        }

        foreach ($vtexBaseProduct->items as $vtexProduct) {
            $sku = $vtexProduct->referenceId[0]->Value;
            foreach ($this->_filters as $filter) {
                if (method_exists($filter, 'filterSku')) {
                    $auxSku = $filter->filterSku($sku);
                    $this->_logger->info("Filtered $sku into " . trim($auxSku));
                    $sku = $auxSku ? $auxSku : $sku;
                }
            }
            yield $baseProduct + [
                'image_url'     => $vtexProduct->images[0]->imageUrl,
                'thumbnail_url' => $vtexProduct->images[0]->imageUrl,
                'sku'           => $sku,
                'name'          => $vtexProduct->name,
                'base_name'     => $vtexProduct->nameComplete,
                'price'         => $this->getItemPrice($vtexProduct),
                'stock'         => $this->getItemStock($vtexProduct),
            ];
        }
    }

    /**
     * Gets VTEX category tree and converts it to WoowUp's API category format
     * @return [type] [description]
     */
    public function getCategories()
    {
        $response = $this->_get('/api/catalog_system/pub/category/tree/10', []);

        if ($response->getStatusCode() === 200) {
            $categoryTree = [];

            $vtexCategories = json_decode($response->getBody());

            foreach ($vtexCategories as $vtexCategory) {
                $categoryTree[(string) $vtexCategory->id] = $this->getCategoryInfo($vtexCategory);
            }

            $categories = $this->flatternCategoryTree($categoryTree);

            return $categories;
        } else {
            throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
    }

    /**
     * Flatterns a category tree
     * @param  [type] $categoryTree [description]
     * @param  array  $parentPath   [description]
     * @return [type]               [description]
     */
    public function flatternCategoryTree($categoryTree, $parentPath = [])
    {
        $categories = [];

        foreach ($categoryTree as $categoryId => $category) {
            $categoryInfo = $category;

            unset($categoryInfo['children']);

            $categories[$categoryId] = array_merge($parentPath, array($categoryInfo));

            $childrenFlatCategories = $this->flatternCategoryTree($category['children'], $categories[$categoryId]);

            $categories = $categories + $childrenFlatCategories;
        }

        return $categories;
    }

    /**
     * Gets category info: id, name, url and children (recursive strategy)
     * @param  [type] $vtexCategory [description]
     * @return [type]               [description]
     */
    public function getCategoryInfo($vtexCategory)
    {
        $categoryInfo = [
            'id'       => (string) $vtexCategory->id,
            'name'     => $vtexCategory->name,
            'url'      => $vtexCategory->url,
            'children' => [],
        ];

        if (isset($vtexCategory->children) && !empty($vtexCategory->children)) {
            foreach ($vtexCategory->children as $childVtexCategory) {
                $categoryInfo['children'][(string) $childVtexCategory->id] = $this->getCategoryInfo($childVtexCategory);
            }
        }

        return $categoryInfo;
    }

    /**
     * Gets stock for an item
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    public function getItemStock($vtexItem)
    {
        if (isset($vtexItem->sellers) && isset($vtexItem->sellers[0]) && isset($vtexItem->sellers[0]->commertialOffer) && isset($vtexItem->sellers[0]->commertialOffer->AvailableQuantity)) {
            return $vtexItem->sellers[0]->commertialOffer->AvailableQuantity;
        } else {
            return 0;
        }
    }

    /**
     * Gets price from vtex item, if not set finds it in API
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    public function getItemPrice($vtexItem)
    {
        if (isset($vtexItem->sellers) && isset($vtexItem->sellers[0]) && isset($vtexItem->sellers[0]->commertialOffer) && isset($vtexItem->sellers[0]->commertialOffer->Price)) {
            return $vtexItem->sellers[0]->commertialOffer->Price;
        } else {
            $vtexItemId = $vtexItem->itemId;
            $this->_logger->info("Getting price for item Id " . $vtexItemId . "... ");
            $response = $this->_get('/api/pricing/prices/' . $vtexItemId);

            if ($response->getStatusCode() !== 200) {
                $this->_logger->info("Not found :(");
                return 0;
            } else {
                $body = json_decode($response->getBody());
                $this->_logger->info("Sucess!");
                return $body->listPrice;
            }
        }
    }

    /**
     * Basic VTEX API request
     * @param  [type] $method      [description]
     * @param  [type] $endpoint    [description]
     * @param  array  $queryParams [description]
     * @return [type]              [description]
     */
    protected function _request($method, $endpoint, $queryParams = [])
    {
        try {
            $response = $this->_httpClient->request($method, $this->_host . $endpoint, [
                'headers' => [
                    'Content-Type'        => 'application/json',
                    'Accept'              => 'application/vnd.vtex.ds.v10+json',
                    'X-VTEX-API-AppKey'   => $this->_appKey,
                    'X-VTEX-API-AppToken' => $this->_appToken,
                ],
                'query'   => $queryParams,
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->_logger->error("Error at request attempt " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a GET request to VTEX API
     * @param  [type] $endpoint    [description]
     * @param  array  $queryParams [description]
     * @return [type]              [description]
     */
    protected function _get($endpoint, $queryParams = [])
    {
        return $this->_request('GET', $endpoint, $queryParams);
    }

    /**
     * Adds a filter to connector
     * @param FilterInterface $filter [description]
     */
    public function addFilter(FilterInterface $filter)
    {
        $this->_filters[] = $filter;
        return true;
    }
}
