<?php
namespace WoowUpConnectors\Stages\Subscriptions;

use League\Pipeline\StageInterface;

class VTEXSubscriptionDownloader implements StageInterface
{
    private $vtexConnector;
    private $logger;
    public function __construct($vtexConnector, $logger)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger = $logger;
        return $this;
    }

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }
        if (isset($payload->customerId) && !empty($payload->customerId)) {
            $customerInfo = $this->searchCustomerById($payload->customerId);
            if ($customerInfo) {
                $payload->customerInfo = $customerInfo;
            }
            return $payload;
        }
        return null;
    }


    protected function searchCustomerById ($id) {
        $params = [
            '_fields' => '_all',
            'userId' => $id
        ];
        try {
            $response = $this->vtexConnector->_get('/api/dataentities/CL/search', $params);
        }catch (\Exception $error){
            $this->logger->info("Error to getting client info!");
            return null;
        }
        $this->logger->info("Success to getting client info!");
        return json_decode($response->getBody())[0];
    }
}