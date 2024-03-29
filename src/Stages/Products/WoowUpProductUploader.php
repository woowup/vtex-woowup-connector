<?php

namespace WoowUpConnectors\Stages\Products;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;

class WoowUpProductUploader implements StageInterface
{
	protected $woowupClient;
	protected $logger;
	protected $woowupStats;
    protected $cleanser;

	public function __construct($woowupClient, $logger, $cleanser)
	{
		$this->woowupClient = $woowupClient;
		$this->logger       = $logger;
        $this->cleanser     = $cleanser;

		$this->resetWoowupStats();

		return $this;
	}

	public function __invoke($payload)
	{
        $begin = microtime(true);
        $processedProducts = [];
        foreach ($payload as $product) {
            $processedProducts[] = $product;
    		try {
                $this->woowupClient->products->update($product['sku'], $product);
                $this->logger->info("[Product] {$product['sku']} Updated Successfully");
                $this->logProductData($product);
                $this->woowupStats['updated']++;
            } catch (RequestException $e) {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
                $sku = $product['sku'] ?? 'Product without sku';
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $responseStatusCode = $response->getStatusCode();
                    if ($responseStatusCode == 404) {
                        try {
                            $this->woowupClient->products->create($product);
                            $this->logger->info("[Product] $sku Created Successfully");
                            $this->logProductData($product);
                            $this->woowupStats['created']++;
                            continue;
                        } catch (\Exception $e) {
                            $this->logger->info("[Product] $sku Failed Creation");
                        }
                    }
                    $body = json_decode((string) $response->getBody(),true);
                    if ($body) {
                        if (isset($body['code']) && !empty($body['code'])) {
                            $errorCode = $body['code'];
                            switch ($errorCode) {
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
                $this->logger->info("[Product] $sku Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
                $this->woowupStats['failed'][] = $product;

            } catch (\Exception $e) {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
                $sku = $product['sku'] ?? 'Product without sku';
                $this->logger->info("[Product] $sku Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
                $this->woowupStats['failed'][] = $product;
            }
            $this->logger->info("Product processed in " . (microtime(true) - $begin) . " seconds");
        }

        return $processedProducts;
	}

    public function logProductData($product)
    {
        $sku = 'Product without sku'; $name = 'Product without name'; $base_name= 'Product without base_name'; $price = 'Product without price'; $offer_price = 'Product without offer_price'; $stock = 'Product without stock'; $release_date = 'Product without release_date';
        extract($product,EXTR_OVERWRITE);
        $productData=array('sku' => $sku, 'name' => $name, 'base_name' => $base_name,
            'price' => $price, 'offer_price' => $offer_price , 'stock' => $stock,'release_date' => $release_date);
        $productData['name'] = $this->cleanser::deleteAccents($productData['name'],true);
        $productData['base_name'] = $this->cleanser::deleteAccents($productData['base_name'],true);
        $productData['sku_encoded'] = ($sku != 'Product without sku') ? base64_encode($sku) : 'Product without sku';
        $productData['last_category_id'] = (array_key_exists('category',$product)) ? $product['category'][count($product['category'])-1]['id'] : 'Product without category';
        $this->logger->info(json_encode($productData,JSON_PRETTY_PRINT));
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