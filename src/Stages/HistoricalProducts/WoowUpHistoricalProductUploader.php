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
        //[Product] 8N010130000-S Error: Code '0', Message 'cURL error 28: Failed to connect to api-internal.woowup.com port 80: Expiró el tiempo de conexión (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)'

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