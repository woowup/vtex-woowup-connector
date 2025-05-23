<?php

namespace WoowUpConnectors;

use WoowUpConnectors\Stages\VTEXConfig;
use WoowUpConnectors\Stages\Subscriptions\VTEXSubscriptionDownloader;
use WoowUpConnectors\Stages\Subscriptions\VTEXSubscriptionMapper;
use WoowUpConnectors\WoowUpHandler;
use WoowUpConnectors\Stages\DebugUploadStage;
use WoowUpConnectors\Stages\Customers\VTEXCustomerDownloader;
use WoowUpConnectors\Stages\Customers\VTEXWoowUpCustomerMapper;
use WoowUpConnectors\Stages\Customers\WoowUpCustomerUploader;
use WoowUpConnectors\Stages\Orders\VTEXOrderDownloader;
use WoowUpConnectors\Stages\Orders\VTEXWoowUpOrderMapper;
use WoowUpConnectors\Stages\Orders\WoowUpCCInfoStage;
use WoowUpConnectors\Stages\Orders\WoowUpOrderUploader;
use WoowUpConnectors\Stages\Products\VTEXWoowUpProductWithoutChildrenMapper;
use WoowUpConnectors\Stages\Products\VTEXWoowUpProductWithChildrenMapper;
use WoowUpConnectors\Stages\Products\WoowUpProductDebugger;
use WoowUpConnectors\Stages\Products\WoowUpProductUploader;
use WoowUpConnectors\Stages\HistoricalProducts\VTEXWoowUpHistoricalProductMapper;
use WoowUpConnectors\Stages\HistoricalProducts\WoowUpHistoricalProductUploader;
use League\Pipeline\Pipeline;

class VTEXWoowUp
{
    protected $downloadStage;
    protected $preMapStages = [];
    protected $mapStage;
    protected $postMapStages = [];
    protected $ccInfoStage;
    protected $uploadStage;
    protected $vtexConnector;
    protected $logger;
    protected $woowupClient;
    protected $pipeline;
    protected $errorHandler;
    private $apiKey;
    protected $notifier;

    protected $ignoreOptIn;

    public function __construct($vtexConfig, $httpClient, $logger, $woowupClient, $errorHandler, $features = null, $notifier = null, $ignoreOptIn = false)
    {
        $this->vtexConnector = new VTEXConnector($vtexConfig, $httpClient, $logger, $features);
        $this->logger        = $logger;
        $this->woowupClient  = $woowupClient;
        $this->errorHandler  = $errorHandler;
        $this->apiKey        = $vtexConfig['accountApiKey'];
        $this->notifier = $notifier;
        $this->ignoreOptIn = $ignoreOptIn;
    }

    public function addPreMapStage($stage)
    {
        $this->preMapStages[] = $stage;
    }

    public function addPostMapStage($stage)
    {
        $this->postMapStages[] = $stage;
    }

    public function setDownloadStage($stage)
    {
        $this->downloadStage = $stage;
    }

    public function setMapStage($stage)
    {
        $this->mapStage = $stage;
    }

    public function setCCInfoStage($stage)
    {
        $this->ccInfoStage = $stage;
    }

    public function setUploadStage($stage)
    {
        $this->uploadStage = $stage;
    }

    public function resetStages()
    {
        $this->downloadStage = null;
        $this->preMapStages  = [];
        $this->mapStage      = null;
        $this->postMapStages = [];
        $this->uploadStage   = null;

        return $this;
    }

    public function run($param)
    {
        return $this->pipeline->process($param);
    }

    protected function preparePipeline()
    {
        $pipeline = new Pipeline;

        if ($this->downloadStage) {
            $pipeline = $pipeline->pipe($this->downloadStage);
        }

        foreach ($this->preMapStages as $stage) {
            $stage->setConnector($this->vtexConnector);
            $pipeline = $pipeline->pipe($stage);
        }

        if ($this->mapStage) {
            $pipeline = $pipeline->pipe($this->mapStage);
        }

        if ($this->ccInfoStage) {
            $pipeline = $pipeline->pipe($this->ccInfoStage);
        }

        foreach ($this->postMapStages as $stage) {
            $stage->setConnector($this->vtexConnector);
            $pipeline = $pipeline->pipe($stage);
        }

        if ($this->uploadStage) {
            $pipeline = $pipeline->pipe($this->uploadStage);
        }

        $this->pipeline = $pipeline;
    }

    /**
     * Searches orders since a date in VTEX and imports them into WoowUp
     * @param  [type]  $fromDate  FORMAT ['Y-m-d'] (Example '2018-12-31')
     * @param  boolean $updating  update duplicated orders
     * @param  boolean $importing approve orders at execution time (for time-triggered campaigns)
     * @return [type]             [description]
     */
    public function importOrders($fromDate = null, $toDate = null, $updating = false, $importing = false, $debug = false, $hours = null, $daysFrom = null)
    {
        $this->logger->info("Importing orders");

        $this->logger->info("Debug mode? " . ($debug ? "Yes" : "No"));
        $this->logger->info("Updating duplicated orders? " . ($updating ? "Yes" : "No"));
        $this->logger->info("Approving orders at excecution time? " . ($importing ? "No" : "Yes"));

        $countOrders = $this->vtexConnector->countOrders($fromDate, $toDate, $daysFrom);
        $this->logger->info("Found " . $countOrders . " orders to import");

        // Pipeline = Download(VTEX) + ... + Map (VTEX->WoowUp) + ... + Upload(WoowUp)
        if (!$this->downloadStage) {
            $this->setDownloadStage(new VTEXOrderDownloader($this->vtexConnector));
        }

        if (!$this->mapStage) {
            $this->setMapStage(new VTEXWoowUpOrderMapper($this->vtexConnector, $importing, $this->logger, $this->notifier, $countOrders));
        }

        if (!$this->ccInfoStage) {
            $this->setCCInfoStage(new WoowUpCCInfoStage($this->woowupClient, $this->logger, $this->errorHandler));
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(($debug) ?
                                      new DebugUploadStage() :
                                      new WoowUpOrderUploader($this->woowupClient, $updating, $this->logger)
            );
        }

        $this->preparePipeline();
        foreach ($this->vtexConnector->getOrders($fromDate, $toDate, $importing, $hours, $daysFrom) as $orderId) {
            $this->logger->info("Processing order $orderId");
            $this->run($orderId);
        }

        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created orders: " . $woowupStats['orders']['created']);
        $this->logger->info("Duplicated orders: " . $woowupStats['orders']['duplicated']);
        $this->logger->info("Updated orders: " . $woowupStats['orders']['updated']);
        $this->logger->info("Failed orders: " . count($woowupStats['orders']['failed']));
        $this->logger->info("Created customers: " . $woowupStats['customers']['created']);
        $this->logger->info("Updated customers: " . $woowupStats['customers']['updated']);
        $this->logger->info("Failed customers: " . count($woowupStats['customers']['failed']));
        $this->uploadStage->resetWoowupStats();

        $this->resetStages();

        return true;
    }

    public function importSubscriptions($fromDate = null, $debug = false){
        $this->logger->info("Importing subscriptions");
        if (!$this->downloadStage) {
            $this->setDownloadStage(new VTEXSubscriptionDownloader());
        }
        if (!$this->mapStage) {
            $this->setMapStage(new VTEXSubscriptionMapper($this->vtexConnector));
        }
        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new DebugUploadStage() :
                    new WoowUpCustomerUploader($this->woowupClient, $this->logger)
            );
        }
        $this->preparePipeline();
        foreach ($this->vtexConnector->getSubscription($fromDate) as $subscription) {
            $subscriptionId = $subscription->id ?? 'emptyId';
            $this->logger->info("Processing subscription: " . $subscriptionId);
            $this->run($subscription);
        }

        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $woowupStats['created']);
        $this->logger->info("Updated customers: " . $woowupStats['updated']);
        $this->logger->info("Failed customers: " . count($woowupStats['failed']));
        $this->uploadStage->resetWoowupStats();
        $this->resetStages();
        return true;
    }

    public function importCustomers($fromDate = null, $toDate = null, $days= null, $debug = false, $dataEntity = "CL")
    {
        if (!$fromDate) {
            $fromDate = ($days) ? date('Y-m-d', strtotime("-$days days")) : date('Y-m-d', strtotime("-3 days"));
        }

        $this->logger->info("Importing customers from $fromDate and entity $dataEntity");
        $this->logger->info("Ignore OptIn: $this->ignoreOptIn");

        if (!$this->downloadStage) {
            $this->setDownloadStage(new VTEXCustomerDownloader($this->vtexConnector, $dataEntity));
        }

        if (!$this->mapStage) {
            $this->setMapStage(new VTEXWoowUpCustomerMapper($this->vtexConnector, $this->logger,$this->apiKey, $this->ignoreOptIn));
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new DebugUploadStage() :
                    new WoowUpCustomerUploader($this->woowupClient, $this->logger)
            );
        }

        $this->preparePipeline();
        foreach ($this->vtexConnector->getCustomers($fromDate, $toDate, $dataEntity) as $vtexCustomers) {
            foreach ($vtexCustomers as $vtexCustomer) {
                $vtexCustomerId = $vtexCustomer->id;
                if (!$vtexCustomerId) {
                    continue;
                }
                $this->logger->info("Processing customer " . $vtexCustomerId);
                $this->run($vtexCustomerId);
            }

        }

        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $woowupStats['created']);
        $this->logger->info("Updated customers: " . $woowupStats['updated']);
        $this->logger->info("Failed customers: " . count($woowupStats['failed']));
        $this->uploadStage->resetWoowupStats();

        $this->resetStages();

        return true;
    }

    public function importCustomersWithYield($fromDate = null, $toDate = null, $days= null, $debug = false, $dataEntity = "CL", $toFile = false)
    {
        if (!$fromDate) {
            $fromDate = ($days) ? date('Y-m-d', strtotime("-$days days")) : date('Y-m-d', strtotime("-3 days"));
        }

        $this->logger->info("Importing customers from $fromDate and entity $dataEntity");
        $this->initProcessCustomers($dataEntity,$debug);
        foreach ($this->vtexConnector->getCustomers($fromDate, $toDate, $dataEntity) as $vtexCustomers) {
            yield $vtexCustomers;
        }
    }

    public function importCustomersByIDs($dataEntity = "CL", $debug = false, $customers){
        $this->initProcessCustomers($dataEntity, $debug);
        $this->processCustomers($customers);
        $this->postProcessCustomer();
    }

    public function initProcessCustomers($dataEntity, $debug)
    {
        if (!$this->downloadStage) {
            $this->setDownloadStage(new VTEXCustomerDownloader($this->vtexConnector, $dataEntity));
        }

        if (!$this->mapStage) {
            $this->setMapStage(new VTEXWoowUpCustomerMapper($this->vtexConnector, $this->logger, $this->apiKey));
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new DebugUploadStage() :
                    new WoowUpCustomerUploader($this->woowupClient, $this->logger)
            );
        }

        $this->preparePipeline();
    }


    public function postProcessCustomer()
    {
        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created customers: " . $woowupStats['created']);
        $this->logger->info("Updated customers: " . $woowupStats['updated']);
        $this->logger->info("Failed customers: " . count($woowupStats['failed']));
        $this->uploadStage->resetWoowupStats();

        $this->resetStages();
    }

    public function processCustomers($vtexCustomers)
    {
        foreach ($vtexCustomers as $vtexCustomer) {
            $this->logger->info("Processing customer" . $vtexCustomer->id);
            $this->run($vtexCustomer->id);
        }
    }

    public function importProducts($debug = false, $feature = false, $cleanser)
    {
        $updatedSkus = [];
        $this->logger->info("Importing products");

        if (!$this->mapStage) {
            if (VTEXConfig::mapsChildProducts($this->vtexConnector->getAppId())){
                $this->setMapStage(new VTEXWoowUpProductWithChildrenMapper($this->vtexConnector));
            } else {
                $this->setMapStage(new VTEXWoowUpProductWithoutChildrenMapper($this->vtexConnector));
            }
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new WoowUpProductDebugger() :
                    new WoowUpProductUploader($this->woowupClient, $this->logger, $cleanser)
            );
        }

        $this->vtexConnector->setFeature($feature);

        $this->preparePipeline();
        foreach ($this->vtexConnector->getProducts() as $vtexBaseProduct) {
            $products = $this->run($vtexBaseProduct);
            foreach ($products as $product) {
                $updatedSkus[] = $product['sku'];
            }
        }

        $woowupStats = $this->uploadStage->getWoowupStats();
        if (count($woowupStats['failed']) > 0) {
            $this->logger->info("Retrying failed products");
            // Los productos ya están procesados hasta el uploadStage
            $this->uploadStage->retryFailed();
        }

        // Actualizo los que no están más disponibles
        //$this->uploadStage->updateUnavailable($updatedSkus);

        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created products: " . $woowupStats['created']);
        $this->logger->info("Updated products: " . $woowupStats['updated']);
        $this->logger->info("Failed products: " . count($woowupStats['failed']));
        $this->uploadStage->resetWoowupStats();

        $this->resetStages();

        return true;
    }


    public function importSingleProduct($skuId, $productId, $cleanser, $debug = false)
    {
        $this->logger->info("importing single product with sku $skuId");

        if (!$this->mapStage) {
            if (VTEXConfig::mapsChildProducts($this->vtexConnector->getAppId())){
                $this->setMapStage(new VTEXWoowUpProductWithChildrenMapper($this->vtexConnector, true));
            } else {
                $this->setMapStage(new VTEXWoowUpProductWithoutChildrenMapper($this->vtexConnector, true));
            }
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new WoowUpProductDebugger() :
                    new WoowUpProductUploader($this->woowupClient, $this->logger, $cleanser)
            );
        }

        $this->preparePipeline();

        foreach ($this->vtexConnector->getSingleProduct($skuId, $productId) as $vtexBaseProduct) {
            $this->run($vtexBaseProduct);
        }
        return true;
    }

    public function importHistoricalProducts($stockEqualsZero = false, $debug = false)
    {
        $updatedSkus = [];
        $this->logger->info("Importing historical products");

        if (!$this->mapStage) {
            $this->setMapStage(new VTEXWoowUpHistoricalProductMapper($this->vtexConnector, $stockEqualsZero));
        }

        if (!$this->uploadStage) {
            $this->setUploadStage(
                ($debug) ?
                    new DebugUploadStage() :
                    new WoowUpHistoricalProductUploader($this->woowupClient, $this->logger)
            );
        }

        $this->preparePipeline();
        foreach ($this->vtexConnector->getHistoricalProducts() as $vtexBaseProduct) {
            $this->run($vtexBaseProduct);
        }

        $woowupStats = $this->uploadStage->getWoowupStats();
        $this->logger->info("Finished. Stats:");
        $this->logger->info("Created products: " . $woowupStats['created']);
        $this->logger->info("Updated products: " . $woowupStats['updated']);
        $this->logger->info("Failed products: " . count($woowupStats['failed']));
        $this->uploadStage->resetWoowupStats();

        $this->resetStages();

        return true;
    }

    public function getConnector()
    {
        return $this->vtexConnector;
    }
}