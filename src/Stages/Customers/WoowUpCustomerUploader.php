<?php

namespace WoowUpConnectors\Stages\Customers;

use GuzzleHttp\Exception\RequestException;
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
		if (is_null($payload)) {
			return false;
		}

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
        } catch (RequestException $e) {
            $errorCode    = $e->getCode();
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorInformation = $this->getErrorInformation($response, $errorCode, $errorMessage);
                $errorCode = $errorInformation['code'];
                $errorMessage = $errorInformation['message'];
            }
            $this->logger->info("[Customer] " . implode(',', $customerIdentity) . " Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $customer;
            return false;
        } catch (\Exception $e) {
            $errorCode    = $e->getCode();
            $errorMessage = $e->getMessage();
            $this->logger->info("[Customer] " . implode(',', $customerIdentity) . " Error:  Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $customer;
            return false;
        }

        return true;
	}

    protected function getErrorInformation ($response, $errorCode, $errorMessage) {
        $errorInformation = [
            'code' => $errorCode,
            'message' => $errorMessage
        ];
        $body = json_decode((string) $response->getBody(),true);
        if  ($body) {
            if (isset($body['code']) && !empty($body['code'])) {
                $errorInformation['code'] = $body['code'];
                if ($body['code'] == 'internal_error') {
                    $errorInformation['message'] = $body['message'] ?? '';
                } else {
                    $errors = $body['payload']['errors'] ?? [];
                    $errorInformation['message'] = is_array($errors) ? implode(';',$errors) : $errors;
                }
            }
        }
        return $errorInformation;
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