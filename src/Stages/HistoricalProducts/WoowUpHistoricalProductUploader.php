<?php

namespace WoowUpConnectors\Stages\HistoricalProducts;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;

class WoowUpHistoricalProductUploader implements StageInterface
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

        $product = $payload;
        $encode = base64_encode($product['sku']);
        $lastCategory = !empty($product['category']) ? end($product['category']) : null;
        $lastCategoryId = $lastCategory ? ($lastCategory['id'] ?? null) : 'no_category';

        $this->logger->info("[Product] Sku: {$product['sku']} , EncodedSku : {$encode}");

        $price = $product['price'] ?? 'no_price';
        $offer_price = $product['offer_price'] ?? 'no_offer_price';
        $stock = $product['stock'] ?? 'stock';
        $this->logger->info("[Product] Price: {$price} , Offer_Price : {$offer_price}");
        $this->logger->info("[Product] Stock: {$stock}");
        $this->logger->info("[Product] Release_Date: {$product['release_date']}");
        $this->logger->info("[Product] LastCategoryId: {$lastCategoryId}");
        $this->logger->info("---------");

        try {
            $this->woowupClient->products->update($product['sku'], $product);
            $this->logger->info("[Product] {$product['sku']} Updated Successfully");
            $this->woowupStats['updated']++;
            return true;
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
                        $this->woowupStats['created']++;
                        return true;
                    } catch (\Exception $e) {
                        $this->logger->info("[Product] $sku Failed Creation");
                        return false;
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
            return false;
        } catch (\Exception $e) {
            $errorCode    = $e->getCode();
            $errorMessage = $e->getMessage();
            $sku = $product['sku'] ?? 'Product without sku';
            $this->logger->info("[Product] $sku Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
            $this->woowupStats['failed'][] = $product;
            return false;
        }
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