<?php
namespace WoowUpConnectors\Stages\Subscriptions;

use League\Pipeline\StageInterface;

class VTEXSubscriptionDownloader implements StageInterface
{
    private $vtexConnector;
    public function __construct($vtexConnector)
    {
        $this->vtexConnector = $vtexConnector;
        return $this;
    }

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }
        if (isset($payload->customerId) && !empty($payload->customerId)) {
            $customerInfo = $this->vtexConnector->getCustomerFromId($payload->customerId);
            if ($customerInfo) {
                $payload->customerInfo = $customerInfo;
            }
            return $payload;
        }
        return null;
    }
}