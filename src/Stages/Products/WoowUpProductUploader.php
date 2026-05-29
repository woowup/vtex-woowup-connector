<?php

namespace WoowUpConnectors\Stages\Products;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;

class WoowUpProductUploader implements StageInterface
{
    private const MAX_ATTR_STRIP_RETRIES = 5;

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
            $sku = $product['sku'] ?? 'Product without sku';
            $errorCode = $errorMessage = null;

            for ($attempt = 0; $attempt <= self::MAX_ATTR_STRIP_RETRIES; $attempt++) {
                try {
                    $this->woowupClient->products->update($product['sku'], $product);
                    $this->logger->info("[Product] {$product['sku']} Updated Successfully");
                    $this->logProductData($product);
                    $this->woowupStats['updated']++;
                    $errorCode = null;
                    break;
                } catch (RequestException $e) {
                    $errorCode    = $e->getCode();
                    $errorMessage = $e->getMessage();
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        if ($response->getStatusCode() == 404) {
                            try {
                                $this->woowupClient->products->create($product);
                                $this->logger->info("[Product] $sku Created Successfully");
                                $this->logProductData($product);
                                $this->woowupStats['created']++;
                                continue 2;
                            } catch (\Exception $createEx) {
                                $this->logger->info("[Product] $sku Failed Creation");
                            }
                            break;
                        }
                        $body = json_decode((string) $response->getBody(), true);
                        if ($body && isset($body['code']) && !empty($body['code'])) {
                            $errorCode = $body['code'];
                            if ($errorCode === 'internal_error') {
                                $errorMessage = $body['message'] ?? '';
                            } else {
                                $errors = $body['payload']['errors'] ?? [];
                                $errorMessage = is_array($errors) ? implode(';', $errors) : $errors;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $errorCode    = $e->getCode();
                    $errorMessage = $e->getMessage();
                    break;
                }

                $bloatingAttr = $this->extractBloatingAttribute((string) $errorMessage, array_keys($product['custom_attributes'] ?? []));
                if ($bloatingAttr === null) {
                    break;
                }
                $this->logger->info("[Product] {$sku} Schema limit — retrying without '{$bloatingAttr}'");
                unset($product['custom_attributes'][$bloatingAttr]);
            }

            if ($errorCode !== null) {
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

    private function extractBloatingAttribute(string $errorMessage, array $existingAttrNames = []): ?string
    {
        if (strpos($errorMessage, 'extended attribute definition very long') === false) {
            return null;
        }
        $separatorPos = strpos($errorMessage, ' :: ');
        if ($separatorPos === false) {
            return null;
        }
        $data = json_decode(substr($errorMessage, $separatorPos + 4), true);
        foreach ($data['checkbox_breakdown'] ?? [] as $entry) {
            $name = $entry['name'] ?? null;
            if ($name === null) {
                continue;
            }
            if (empty($existingAttrNames) || in_array($name, $existingAttrNames, true)) {
                return $name;
            }
        }
        return null;
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