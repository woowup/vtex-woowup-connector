<?php

namespace WoowUpConnectors\Stages\Products;

use League\Pipeline\StageInterface;

class WoowUpProductUploader implements StageInterface
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
        $processedProducts = [];
        foreach ($payload as $product) {
            $processedProducts[] = $product;

    		try {
                $this->woowupClient->products->update($product['sku'], $product);
                $this->logger->info("[Product] {$product['sku']} Updated Successfully");
                $this->woowupStats['updated']++;
            } catch (\Exception $e) {
                if (method_exists($e, 'getResponse')) {
                    $response = json_decode($e->getResponse()->getBody(), true);
                    if ($e->getResponse()->getStatusCode() == 404) {
                        // no existe el producto
                        try {
                            $this->woowupClient->products->create($product);
                            $this->logger->info("[Product] {$product['sku']} Created Successfully");
                            $this->woowupStats['created']++;
                            break;
                        } catch (\Exception $e) {
                            $errorCode    = $e->getCode();
                            $errorMessage = $e->getMessage();
                            $this->woowupStats['failed'][] = $product;
                        }
                    } else {
                        $errorCode    = $response['code'];
                        $errorMessage = $response['payload']['errors'][0] ?? json_encode($response);
                    }
                } else {
                    $errorCode    = $e->getCode();
                    $errorMessage = $e->getMessage();
                }
                $this->logger->info("[Product] {$product['sku']} Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
                $this->woowupStats['failed'][] = $product;
            }

        }

        return $processedProducts;
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

    public function setWoowUpStats($woowupStats)
    {
        $this->woowupStats = $woowupStats;
    }

    public function retryFailed()
    {
        $failedProducts              = $this->woowupStats['failed'];
        $this->woowupStats['failed'] = [];

        $this->logger->info("FAILED PRODUCTS " . count($failedProducts));

        $this->__invoke($failedProducts);

        return true;
    }

    public function updateUnavailable($updatedSkus)
    {
        $page = 0; $limit = 100;

        $woowUpProducts      = $this->woowupClient->products->search(['available' => true], $page, $limit);
        $unavailableProducts = [];

        while (is_array($woowUpProducts) && (count($woowUpProducts) > 0)) {
            foreach ($woowUpProducts as $wuProduct) {

                // Si el producto no está en VTEX lo deshabilito
                if (!in_array($wuProduct->sku, $updatedSkus)) {
                    $this->logger->info("Product " . $wuProduct->sku . " no longer available");
                    $unavailableProducts[] = [
                        'sku'       => $wuProduct->sku,
                        'name'      => $wuProduct->name,
                        'available' => false,
                        'stock'     => 0,
                    ];
                }
            }

            $this->__invoke($unavailableProducts);
            $unavailableProducts = [];

            $page++;
            $woowUpProducts = $this->woowupClient->products->search(['available' => true], $page, $limit);
        }
    }
}