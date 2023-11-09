<?php

namespace WoowUpConnectors\Stages\Orders;

use Exception;
use GuzzleHttp\Exception\RequestException;
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
            $this->logger->info("[Purchase] {$order['invoice_number']} Size: " . strlen(json_encode($order)) . " bytes");
            $this->logger->info("[Purchase] {$order['invoice_number']} Created Successfully");
            $this->woowupStats['created']++;
            return true;
        } catch (RequestException $e ) {
            $errorCode    = $e->getCode();
            $errorMessage = $e->getMessage();
            $invoiceNumber = $order['invoice_number'] ?? 'Order without invoice number';
            if ($e->hasResponse()) {
                $body  = json_decode( (string ) $e->getResponse()->getBody(), true);
                if ($body) {
                    if (isset($body['code']) && !empty($body['code'])) {
                        $errorCode = $body['code'];
                        switch ($errorCode) {
                            case 'user_not_found':
                                $this->logger->info("[Purchase] {$invoiceNumber} Error: customer not found");
                                $this->woowupStats['failed'][] = $order;
                                return false;
                            case 'duplicated_purchase_number':
                                $this->logger->info("[Purchase] {$invoiceNumber} Duplicated");
                                $this->woowupStats['duplicated']++;
                                return $this->updateOrder($order, $invoiceNumber);
                            case 'internal_error':
                                $errorMessage = $body['message'] ?? '';
                                break;
                            default:
                                $errors = $body['payload']['errors'] ?? [];
                                $errorMessage = is_array($errors) ? implode(';',$errors) : $errors;
                                break;
                        }
                    }
                }
            }
            $this->logger->info("[Purchase] {$invoiceNumber} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $order;
            return false;
        } catch (\Exception $e) {
            $errorCode    = $e->getCode();
            $errorMessage = $e->getMessage();
            $invoiceNumber = $order['invoice_number'] ?? 'Order without invoice number';
            $this->logger->info("[Purchase] {$invoiceNumber} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
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

    private function updateOrder($order, string $invoiceNumber)
    {
        try {
            if ($this->updateOrder) {
                $this->woowupClient->purchases->update($order);
                $this->logger->info("[Purchase] $invoiceNumber Updated Successfully");
                $this->woowupStats['updated']++;
            }
        } catch (Exception $e) {
            if (!method_exists($e, 'getResponse')) {
                return false;
            }

            $response = json_decode($e->getResponse()->getBody(), true);

            if ($response['code'] === 'user_not_found') {
                $this->logger->info("[Purchase] $invoiceNumber is already associated with another Customer. Skipping Order...");
                return true;
            }

            $this->logger->info("[Purchase] $invoiceNumber Error: " . $response['message']);
            return false;
        }
        return true;
    }
}