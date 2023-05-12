<?php

namespace WoowUpConnectors;

use League\Pipeline\Pipeline;
use WoowUpConnectors\Exceptions\VTEXException;
use WoowUpConnectors\Exceptions\VTEXRequestException;
use Psr;

class VTEXConnector
{
    const STATUS_INVOICED = 'invoiced';
    const CUSTOMER_TAG    = 'VTEX';

    const HOST_UNMASK          = 'http://conversationtracker.vtex.com.br/api/pvt/emailMapping';
    const PERSONALDATA_URL     = "/api/profile-system/pvt/profiles/{email}/personalData";
    const GIFTCARD_URL         = '/api/gift-card-system/pvt/giftCards';
    const GIFTCARDCREDIT_URL   = '/api/gift-card-system/pvt/giftCards/{cardId}/credit';
    const CHECK_CONNECTION_URL = '/api/oms/pvt/orders';

    const PRODUCTS_SEARCH_OFFSET = 0;
    const PRODUCTS_SEARCH_LIMIT  = 25;
    const HISTORICAL_PRODUCTS_SEARCH_LIMIT =  500;
    const PRODUCTS_MAX_VALUE_FROM_PARAMETER = 2500;

    const DEFAULT_BRANCH_NAME = 'VTEX';

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

    const ORDERS_QUERY_PARAMS = [
        'page'     => 0,
        'orderBy'  => 'creationDate,asc',
        'per_page' => 100,
    ];

    const MAX_REQUEST_ATTEMPTS = 3;

    const DEFAULT_SLEEP_SEC = 2;
    const TOO_MANY_REQUESTS_SLEEP_SEC = 30;

    const DEFAULT_SALES_WINDOW = 3;

    private $_host;
    private $_appName;
    private $_appId;
    private $_appKey;
    private $_appToken;
    private $_status;
    private $_branchName;
    private $_storeUrl;
    private $_allowedSellers;
    private $_syncCategories;
    private $_salesChannel;

    private $_categories;

    private $_httpClient;
    public $_logger;
    private $feature;
    private $features;


    public function __construct($vtexConfig, \GuzzleHttp\ClientInterface $httpClient, Psr\Log\LoggerInterface $logger, $features = null)
    {
        try {
            $this->_logger = $logger;
            $this->checkVtexConfig($vtexConfig);

            $this->_host           = 'http://' . $vtexConfig['appName'] . '.vtexcommercestable.com.br';
            $this->_appKey         = $vtexConfig['appKey'];
            $this->_appId          = $vtexConfig['appId'];
            $this->_appToken       = $vtexConfig['appToken'];
            $this->_appName        = $vtexConfig['appName'];
            $this->_status         = isset($vtexConfig['status']) && $vtexConfig['status'] ? $vtexConfig['status'] : [self::STATUS_INVOICED];
            $this->_storeUrl       = $vtexConfig['storeUrl'];
            $this->_salesChannel   = isset($vtexConfig['salesChannel']) ? $vtexConfig['salesChannel'] : null;
            $this->_branchName     = isset($vtexConfig['branchName']) ? $vtexConfig['branchName'] : self::DEFAULT_BRANCH_NAME;
            $this->_allowedSellers = isset($vtexConfig['allowedSellers']) ? $vtexConfig['allowedSellers'] : null;
            $this->_syncCategories = isset($vtexConfig['syncCategories']) ? $vtexConfig['syncCategories'] : null;
            $this->_httpClient     = $httpClient;
            $this->features        = $features;
        } catch (\Exception $e) {
            $this->_logger->error("VTEX Service Error: " . $e->getMessage());
            return null;
        }
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

    public function getAppName()
    {
        return $this->_appName;
    }

    public function getAppId()
    {
        return $this->_appId;
    }

    public function getBranchName()
    {
        return $this->_branchName;
    }

    public function getStoreUrl()
    {
        return $this->_storeUrl;
    }

    public function getSyncCategories()
    {
        return $this->_syncCategories;
    }

    public function getCategories()
    {
        return $this->_categories;
    }

    public function setFeature($feature)
    {
        $this->feature = (bool) $feature;
    }

    public function getFeature()
    {
        return $this->feature;
    }

    public function getFeatures()
    {
        return $this->features;
    }

    /**
     * Gets orders from VTEX's API and maps them to WoowUp's API format
     * @param  string  $fromDate      oldest order date format [TO-DO poner formato válido]
     * @return array   $orders         orders in WoowUp's API format
     */
    public function getOrders($fromDate = null, $toDate = null, $importing = false, $hours = null)
    {
        $params = array(
            'f_status' => join(',', $this->_status),
            'page'     => self::ORDERS_QUERY_PARAMS['page'],
            'per_page' => self::ORDERS_QUERY_PARAMS['per_page'],
            'orderBy'  => self::ORDERS_QUERY_PARAMS['orderBy'],
        );

        if ($fromDate === null) {
            $fromDate = date('Y-m-d', strtotime('-5 days'));
        }

        if ($toDate === null) {
            $toDate = date('Y-m-d', strtotime('+1 day'));
        }

        $salesWindow = $hours ?? self::DEFAULT_SALES_WINDOW;

        $toDate      = date('c', strtotime($toDate));
        $fromDate    = date('c', strtotime($fromDate));
        $intervalSec = 3600 * $salesWindow;

        if ($this->_salesChannel) {
            $params += ['f_salesChannel' => $this->_salesChannel];
        }

        if ($this->_syncCategories === true) {
            $this->_logger->info("Getting categories...");
            $categoryTree      = $this->getCategoryTree();
            $this->_categories = $this->flatternCategoryTree($categoryTree);
            $this->_logger->info("Success!");
        } else {
            $this->_categories = [];
        }

        while ($fromDate <= $toDate) {
            $timeStamp = strtotime($fromDate);

            $dateFilter = "creationDate:[";
            $dateFilter .= date('Y-m-d\TH:i:s.B', $timeStamp);
            $dateFilter .= "Z TO ";
            $dateFilter .= date('Y-m-d\TH:i:s.B', $timeStamp + $intervalSec);
            $dateFilter .= "Z]";

            $this->_logger->info("Dates " . $dateFilter);

            $params['f_creationDate'] = $dateFilter;
            $params['page']           = self::ORDERS_QUERY_PARAMS['page'];

            do {
                $params['page']++;
                $this->_logger->info("Page " . $params['page']);

                $response = $this->_get('/api/oms/pvt/orders/', $params);

                if ($response->getStatusCode() === 200) {
                    $response    = json_decode($response->getBody());
                    $totalOrders = $response->paging->total;

                    foreach ($response->list as $vtexOrder) {
                        yield $vtexOrder->orderId;
                    }
                } else {
                    throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
                }
            } while (($params['page'] * $params['per_page']) < $totalOrders);

            $fromDate = date('c', $timeStamp + $intervalSec);
        }
    }

    /**
     * Gets products from VTEX's API and maps them to WoowUp's API format
     * @return array   $products      products in WoowUp's API format
     */
    public function getProducts()
    {
        $offset = self::PRODUCTS_SEARCH_OFFSET;
        $limit  = self::PRODUCTS_SEARCH_LIMIT;

        $categoryTree = $this->getCategoryTree();

        if ($this->_syncCategories) {
            $this->_logger->info("Getting categories... ");
            $this->_categories = $this->flatternCategoryTree($categoryTree);
            $this->_logger->info("Success!");
        } else {
            $this->_categories = [];
        }

        $totalProductsRetrieved = 0;

        $this->_logger->info("Getting category leaves");
        $categoryLeaves = $this->getCategoryLeaves(['children' => $categoryTree, 'id' => '']);

        foreach ($categoryLeaves as $leaf) {
            do {
                $this->_logger->info("Getting products from $offset to " . ($offset + $limit - 1) . "... with category " . $leaf['name'] . " and path " . $leaf['path']);

                $response = $this->_get('/api/catalog_system/pub/products/search', ['_from' => $offset, '_to' => $offset + $limit - 1, 'fq' => 'C:' . $leaf['path']]);

                if (($response->getStatusCode() !== 200) && ($response->getStatusCode() !== 206)) {
                    throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
                }

                $this->_logger->info("Success!");

                $total = explode('/', $response->getHeader('resources')[0])[1];
                $vtexProducts = json_decode($response->getBody());

                foreach ($vtexProducts as $vtexBaseProduct) {
                    yield $vtexBaseProduct;
                }

                $offset += $limit;
            } while ((($limit + $offset) < $total) && ($offset < self::PRODUCTS_MAX_VALUE_FROM_PARAMETER));
            $this->_logger->info("Getting products from $offset to " . ($offset + $limit - 1) . "... with category " . $leaf['name'] . " and path " . $leaf['path']);

            $response = $this->_get('/api/catalog_system/pub/products/search', ['_from' => $offset, '_to' => $offset + $limit-1, 'fq' => 'C:' . $leaf['path']]);

            if (($response->getStatusCode() !== 200) && ($response->getStatusCode() !== 206)) {
                throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
            }

            $vtexProducts = json_decode($response->getBody());

            foreach ($vtexProducts as $vtexBaseProduct) {
                yield $vtexBaseProduct;
            }

            $offset = self::PRODUCTS_SEARCH_OFFSET;
        }

        $this->_logger->info("Done! Total products retrieved " . $totalProductsRetrieved);
    }

    /**
     * Gets historical products from VTEX's API and maps them to WoowUp's API format
     * This method includes VTEX's hidden products and VTEX's inactive products
     * @return array   $products      products in WoowUp's API format
     */
    public function getHistoricalProducts()
    {
        $skuIdList = $this->getSkuIdList();

        foreach ($skuIdList as $skuId) {
            $this->_logger->info("Getting data from product with SkuId: " . strval($skuId));

            $response = $this->_get('/api/catalog_system/pvt/sku/stockkeepingunitbyid/' . strval($skuId));
            if ($response->getStatusCode() !== 200) {
                throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
            }

            $vtexProduct = json_decode($response->getBody());

            yield $vtexProduct;
        }
    }

    protected function getSkuIdList()
    {
        $skuIdList = [];

        $params = [
            'page' => self::PRODUCTS_SEARCH_OFFSET,
            'pagesize' => self::HISTORICAL_PRODUCTS_SEARCH_LIMIT
        ];

        do {
            $params['page']++;

            $response = $this->_get('/api/catalog_system/pvt/sku/stockkeepingunitids', $params);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
            }

            $vtexSkuIds = json_decode($response->getBody());

            $skuIdList = array_merge($skuIdList, $vtexSkuIds);

        } while (!empty(json_decode($response->getBody())));

        return $skuIdList;
    }

    public function searchItemStock($vtexItemId)
    {
        $this->_logger->info("Searching stock for item Id " . $vtexItemId . "... ");
        try {
            $response = $this->_get('/api/logistics/pvt/inventory/skus/' . $vtexItemId);
            $this->_logger->info("Success!");
            $stockBalance = json_decode($response->getBody());
            $stock = 0;
            foreach ($stockBalance->balance as $warehouse) {
                $stock += $warehouse->totalQuantity;
            }
            return $stock;
        } catch (\Exception $e) {
            $this->_logger->info("Not found stock for item Id $vtexItemId - Message: {$e->getMessage()}");
            return 0;
        }
    }

    public function getCustomerFromId($id)
    {
        $params = [
            '_fields' => '_all',
            'userId' => $id
        ];
        try {
            $response = $this->_get('/api/dataentities/CL/search', $params);
        }catch (\Exception $error){
            $this->_logger->info("Error to getting client info!");
            return null;
        }
        $this->_logger->info("Success to getting client info!");
        $customer = json_decode($response->getBody());
        if (empty($customer)) {
            $this->_logger->info("Client with empty info!");
            return null;
        }
        return $customer[0];
    }

    public function getSubscription($fromDate)
    {
        $page = 0;
        $params = [
            'size' => 100,
        ];
        if ($fromDate) {
            $this->_logger->info("Getting subscriptions from $fromDate");
            $params['nextPurchaseDate'] = $fromDate;
        }
        do {
            $page++;
            $this->_logger->info("Subscriptions page: " . $page);
            $params['page'] = $page;
            $response = $this->_get('/api/rns/pub/subscriptions', $params);
            $this->_logger->info("Success!");
            foreach (json_decode($response->getBody()) as $subscription) {
                yield $subscription;
            }
            $totalCustomers = $response->getHeader('X-Total-Count')[0];
        } while (((100 * $page) < $totalCustomers) && !empty(json_decode($response->getBody())));
    }

    public function getCustomers($fromDate = null, $toDate = null, $dataEntity = "CL")
    {
        if($toDate === null){
            $toDate = date('Y-m-d', strtotime('+1 days'));
        }

        $this->_logger->info("Getting updated and created customers from date " . $fromDate. " to " . $toDate . " and dataEntity $dataEntity");
        $params = [
            '_fields' => 'id',
            '_where' => "((updatedIn<$toDate) AND (updatedIn>$fromDate)) OR ((updatedIn is null) AND (createdIn<$toDate) AND (createdIn>$fromDate))",
        ];
        $offset = 0;
        $limit  = 100;
        $page   = 0;
        do {
            $page++;
            $this->_logger->info("Offset: " . $offset . ", Limit: " . $limit . ", Page: " . $page);

            $requestHeaders = [
                'REST-Range' => 'resources=' . $offset . '-' . ($offset + $limit),
            ];

            $response = $this->_get('/api/dataentities/' . $dataEntity . '/scroll', $params, $requestHeaders);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
            }

            $this->_logger->info("Success!");
            $totalCustomers   = $response->getHeader('REST-Content-Total')[0];
            $params['_token'] = $response->getHeader('X-VTEX-MD-TOKEN')[0];
            yield json_decode($response->getBody());
        } while ((($limit * $page) < $totalCustomers) && !empty(json_decode($response->getBody())));
    }

    public function getAddress($userId)
    {
        $this->_logger->info("Getting address for user: $userId");
        $params = [
            'userId' => $userId,
            '_fields' => '_all',
        ];

        $response = $this->_get('/api/dataentities/AD/search', $params);
        if ($response->getStatusCode() !== 200) {
            throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
        }

        $this->_logger->info("Success!");
        $address = json_decode($response->getBody());

        if (is_array($address) && !empty($address)) {
            return array_pop($address);
        } else {
            throw new VTEXException("No address found");
        }
    }

    /**
     * Finds an order in VTEX's API by order id
     * @param  [type] $vtexOrderId [description]
     * @return [type]              [description]
     */
    public function downloadOrder($vtexOrderId)
    {
        $this->_logger->info("Getting order " . $vtexOrderId . "... ");
        $response = $this->_get('/api/oms/pvt/orders/' . $vtexOrderId);

        if ($response->getStatusCode() === 200) {
            $this->_logger->info("Success!");
            return json_decode($response->getBody());
        } else {
            throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
    }

    public function downloadCustomer($vtexCustomerId, $dataEntity)
    {
        $this->_logger->info("Getting customer " . $vtexCustomerId . "... ");

        $params = [
            '_fields' => '_all',
        ];

        $response = $this->_get('/api/dataentities/'. $dataEntity . '/documents/' . $vtexCustomerId, $params);

        if ($response->getStatusCode() === 200) {
            $this->_logger->info("Success!");
            return json_decode($response->getBody());
        } else {
            throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
    }

    /**
     * Get's VTEX variations for an item and returns them in WoowUp's format
     * @param  [type] $vtexItem [description]
     * @return [type]           [description]
     */
    public function getVariations($vtexProductId)
    {
        $this->_logger->info("Getting variations");
        try {
            $response = $this->_get('/api/catalog_system/pub/products/variations/' . $vtexProductId, []);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody());
            }
        } catch (\Exception $e) {
            $this->_logger->error("Error getting variations: " . $e->getMessage());
        }

        return [];
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
     * Finds a product in VTEX's API by product Id
     * @param  [type] $productId [description]
     * @return [type]            [description]
     */
    public function getProductByProductId($productId)
    {
        $response = $this->_get('/api/catalog_system/pvt/products/ProductGet/' . $productId);

        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody());
        } else {
            throw new \Exception($response->getReasonPhrase(), $response->getStatusCode());
        }
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

    public function searchItemPrices($vtexItemId)
    {
        $this->_logger->info("Searching price for item Id " . $vtexItemId . "... ");
        try {
            $response = $this->_get('/api/pricing/prices/' . $vtexItemId);
            $this->_logger->info("Success!");
            return json_decode($response->getBody());
        } catch (\Exception $e) {
            $this->_logger->info("Not found price for item Id $vtexItemId - Message: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Checks minimum parameters to get connector running
     * @param  [type] $vtexConfig [description]
     * @return [type]             [description]
     */
    protected function checkVtexConfig($vtexConfig)
    {
        foreach (self::VTEX_CONFIG_REQUIRED as $parameter) {
            if (!isset($vtexConfig[$parameter])) {
                throw new \Exception("$parameter is missing", 1);
            }
        }

        return true;
    }

    protected function getCategoryLeaves($categoryTree, $parentPath = "")
    {
        $leaves = [];
        $parentPath .= $categoryTree['id'] . '/';

        if (isset($categoryTree['name'])) {
            $leaves[$categoryTree['id']] = ['id' => $categoryTree['id'], 'name' => $categoryTree['name'], 'path' => $parentPath];
        }

        if (isset($categoryTree['children']) && !empty($categoryTree['children'])) {
            // No es hoja
            foreach ($categoryTree['children'] as $childCategory) {
                $leaves += $this->getCategoryLeaves($childCategory, $parentPath);
            }
        } else {
            $leaves[$categoryTree['id']] = ['id' => $categoryTree['id'], 'name' => $categoryTree['name'], 'path' => $parentPath];
        }

        return $leaves;
    }

    /**
     * Checks if seller is allowed
     * @param  object  $vtexOrder       VTEX order object
     * @param  array   $allowedSellers  array of allowed sellers
     * @return boolean $isAllowedSeller isAllowedSeller
     */
    protected function isAllowedSeller($vtexOrder, $allowedSellers)
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
     * Gets VTEX category tree and converts it to WoowUp's API category format
     * @return [type] [description]
     */
    protected function getCategoryTree()
    {
        $response = $this->_get('/api/catalog_system/pub/category/tree/10', []);

        if ($response->getStatusCode() === 200) {
            $categoryTree = [];

            $vtexCategories = json_decode($response->getBody());

            foreach ($vtexCategories as $vtexCategory) {
                $categoryTree[(string) $vtexCategory->id] = $this->getCategoryInfo($vtexCategory);
            }

            return $categoryTree;
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
    protected function flatternCategoryTree($categoryTree, $parentPath = [])
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
    protected function getCategoryInfo($vtexCategory)
    {
        $categoryInfo = [
            'id'       => (string) $vtexCategory->id,
            'name'     => $vtexCategory->name,
            'url'      => str_replace('http://'.$this->getAppName().'.vtexcommercestable.com.br/', $this->getStoreUrl(), $vtexCategory->url),
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
     * Basic VTEX API request
     * @param  [type] $method      [description]
     * @param  [type] $endpoint    [description]
     * @param  array  $queryParams [description]
     * @return [type]              [description]
     */
    protected function _request($method, $endpoint, $queryParams = [], $headers = [])
    {
        $attempts = 0;
        while ($attempts < self::MAX_REQUEST_ATTEMPTS) {
            try {
                $response = $this->_httpClient->request($method, $this->_host . $endpoint, [
                    'headers' => [
                        'Content-Type'        => 'application/json',
                        'Accept'              => 'application/vnd.vtex.ds.v10+json',
                        'X-VTEX-API-AppKey'   => $this->_appKey,
                        'X-VTEX-API-AppToken' => $this->_appToken,
                    ] + $headers,
                    'query' => $queryParams,
                ]);

                if (in_array($response->getStatusCode(), [200, 206])) {
                    return $response;
                }
            } catch (\Exception $e) {
                if (method_exists($e, 'getResponse') &&
                    method_exists($e->getResponse(), 'getStatusCode') &&
                    method_exists($e->getResponse(), 'getBody')) {
                    $response = $e->getResponse();
                    $code = $response->getStatusCode();
                    $body = (string)$e->getResponse()->getBody();
                    $body = json_decode($body);
                    $message = $body->Message ?? $body->error->message ?? $code;
                    $this->_logger->error("Error [" . $code . "] " . $message);
                    if ($response->getStatusCode() == 429) {
                        $this->_logger->info("Too many request");
                        sleep(self::TOO_MANY_REQUESTS_SLEEP_SEC);
                    } elseif ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                        throw new VTEXRequestException($message, $code, $endpoint, $queryParams);
                    } elseif (in_array($response->getStatusCode(), [500, 503, 504])) {
                        $isVTEXServerError = true;
                        $this->_logger->info("VTEX Server error, endpoint: " . $endpoint . " queryParams: " . json_encode($queryParams));
                    } else {
                        throw new VTEXRequestException($message, $code, $endpoint, $queryParams, true);
                    }
                } else {
                    $this->_logger->error("Error at request attempt " . $e->getMessage());
                }
            }
            $attempts++;
            sleep(pow(self::DEFAULT_SLEEP_SEC, $attempts));
        }
        $this->_logger->info("Max request attempts reached");

        if (isset($isVTEXServerError) && $isVTEXServerError) {
            throw new VTEXRequestException($message . " (max attempts reached)", $code, $endpoint, $queryParams);
        }
        throw new VTEXException($endpoint);
    }

    /**
     * Sends a GET request to VTEX API
     * @param  [type] $endpoint    [description]
     * @param  array  $queryParams [description]
     * @return [type]              [description]
     */
    protected function _get($endpoint, $queryParams = [], $headers = [])
    {
        return $this->_request('GET', $endpoint, $queryParams, $headers);
    }
}
