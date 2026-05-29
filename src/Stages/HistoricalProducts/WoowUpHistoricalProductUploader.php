<?php

namespace WoowUpConnectors\Stages\HistoricalProducts;

use GuzzleHttp\Exception\RequestException;
use League\Pipeline\StageInterface;

class WoowUpHistoricalProductUploader implements StageInterface
{
    private const MAX_ATTR_STRIP_RETRIES = 5;

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

        $sku = $product['sku'] ?? 'Product without sku';
        $errorCode = $errorMessage = null;

        for ($attempt = 0; $attempt <= self::MAX_ATTR_STRIP_RETRIES; $attempt++) {
            try {
                $this->woowupClient->products->update($product['sku'], $product);
                $this->logger->info("[Product] {$product['sku']} Updated Successfully");
                $this->woowupStats['updated']++;
                return true;
            } catch (RequestException $e) {
                $errorCode    = $e->getCode();
                $errorMessage = $e->getMessage();
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    if ($response->getStatusCode() == 404) {
                        try {
                            $this->woowupClient->products->create($product);
                            $this->logger->info("[Product] $sku Created Successfully");
                            $this->woowupStats['created']++;
                            return true;
                        } catch (\Exception $createEx) {
                            $this->logger->info("[Product] $sku Failed Creation");
                            return false;
                        }
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

        $this->logger->info("[Product] $sku Error: Code '" . $errorCode . "', Message '" . $errorMessage . "'");
        $this->woowupStats['failed'][] = $product;
        return false;
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