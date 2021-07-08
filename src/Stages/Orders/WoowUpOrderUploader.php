<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Stages\Customers\WoowUpCustomerUploader;

class WoowUpOrderUploader implements StageInterface
{
	protected $woowupClient;
	protected $updateOrder;
	protected $logger;
	protected $customerUploader;
	protected $woowupStats;

	public function __construct($woowupClient, $updateOrder, $logger)
	{
		$this->woowupClient     = $woowupClient;
		$this->updateOrder      = $updateOrder;
		$this->logger           = $logger;
		$this->customerUploader = new WoowUpCustomerUploader($this->woowupClient, $this->logger);

		$this->resetWoowupStats();

		return $this;
	}

	public function __invoke($payload)
	{
		if (is_null($payload)) {
			return null;
		}
    
		$order = $payload;

		if (isset($order['customer'])) {
			($this->customerUploader)($order['customer']);
		}

        try {
            $this->woowupClient->purchases->create($order);
            $this->logger->info("[Purchase] {$order['invoice_number']} Created Successfully");
            $this->woowupStats['created']++;
            return true;
        } catch (\Exception $e) {
            if (method_exists($e, 'getResponse')) {
                $response = json_decode($e->getResponse()->getBody(), true);
                switch ($response['code']) {
                    case 'user_not_found':
                        $this->logger->info("[Purchase] {$order['invoice_number']} Error: customer not found");
                        $this->woowupStats['failed'][] = $order;
                        return false;
                        break;
                    case 'duplicated_purchase_number':
                        $this->logger->info("[Purchase] {$order['invoice_number']} Duplicated");
                        $this->woowupStats['duplicated']++;
                        if ($this->updateOrder) {
                            $this->woowupClient->purchases->update($order);
                            $this->logger->info("[Purchase] {$order['invoice_number']} Updated Successfully");
                            $this->woowupStats['updated']++;
                        }
                        return true;
                        break;
                    default:
                        $errorCode    = $response['code'];
                        $errorMessage = $response['payload']['errors'][0] ?? json_encode($response);
                        break;
                }
            } else {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
            }
            $this->logger->info("[Purchase] {$order['invoice_number']} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $order;

            return false;
        }
    }

    public function getWoowupStats()
    {
    	return [
    		'orders'    => $this->woowupStats,
    		'customers' => $this->customerUploader->getWoowupStats(),
    	];
    }

    public function resetWoowupStats()
    {
    	$this->woowupStats = [
	        'created'    => 0,
	        'updated'    => 0,
	        'duplicated' => 0,
	        'failed'     => [],
    	];

    	return $this;
    }
}