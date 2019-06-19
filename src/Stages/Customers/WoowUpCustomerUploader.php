<?php

namespace WoowUpConnectors\Stages\Customers;

use League\Pipeline\StageInterface;

class WoowUpCustomerUploader implements StageInterface
{
	protected $woowupClient;
	protected $logger;
	protected $woowupStats;

	public function __construct($woowupClient, $logger)
	{
		$this->woowupClient = $woowupClient;
		$this->logger       = $logger;

		$this->resetWoowupStats();

		return $this;
	}

	public function __invoke($payload)
	{
		$customer = $payload;

		$customerIdentity = [
            'email'    => isset($customer['email']) ? $customer['email'] : '',
            'document' => isset($customer['document']) ? $customer['document'] : '',
        ];
        try {
            if (!$this->woowupClient->multiusers->exist($customerIdentity)) {
                $this->woowupClient->users->create($customer);
                $this->logger->info("[Customer] " . implode(',', $customerIdentity) . " Created Successfully");
                $this->woowupStats['created']++;
            } else {
                $this->woowupClient->multiusers->update($customer);
                $this->logger->info("[Customer] " . implode(',', $customerIdentity) . " Updated Successfully");
                $this->woowupStats['updated']++;
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
            $this->logger->info("[Customer] " . implode(',', $customerIdentity) . " Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $customer;

            return false;
        }

        return true;
	}

	public function getWoowupStats()
	{
		return $this->woowupStats;
	}

	public function resetWoowupStats()
	{
		$this->woowupStats = [
			'created' => 0,
	        'updated' => 0,
	        'failed'  => [],
		];

		return $this;
	}
}