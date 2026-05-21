<?php

namespace WoowUpConnectors\Stages\Carts;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Stages\VTEXConfig;
use WoowUpV2\Models\AbandonedCartModel;

class VTEXWoowUpCartMapper implements StageInterface
{
    private $vtexConnector;
    private $logger;

    public function __construct($vtexConnector, $logger)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger        = $logger;
    }

    public function __invoke($cartdata)
    {
        if (empty($cartdata)) {
            return null;
        }

        $quantities = $this->parseQuantities($cartdata['rclastcart'] ?? '');
        if (empty($quantities)) {
            $this->logger->info('No parseable SKU quantities in rclastcart.');
            return null;
        }

        $appId = $this->vtexConnector->getAppId();
        $cart  = new AbandonedCartModel();
        $cart->setSource('vtex');
        $cart->setEmail($cartdata['email']);
        $cart->setExternalId(substr(md5($cartdata['email'] . ($cartdata['rclastcart'] ?? '')), 0, 20));
        $cart->setTotalPrice((float) ($cartdata['rclastcartvalue'] ?? 0));
        $cart->setCreatetime(date('c'));
        $recoverUrl = $this->buildRecoverUrl($cartdata);
        if ($recoverUrl) {
            $cart->setRecoverUrl($recoverUrl);
        }

        if (!empty($cartdata['document'])) {
            $cart->setDocument($cartdata['document']);
        }

        $productsAdded = 0;
        foreach ($cartdata['carttag']['Scores'] as $numericSkuId => $scores) {
            $price = $scores[0]['Point'] ?? null;
            if ($price === null || !isset($quantities[$numericSkuId])) {
                $this->logger->info("Missing price or quantity for SKU $numericSkuId. Skipping.");
                continue;
            }

            $refId = $this->resolveSkuRefId($numericSkuId, $appId);
            if (!$refId) {
                $this->logger->info("Could not resolve RefId for SKU $numericSkuId. Skipping.");
                continue;
            }

            $cart->addProduct([
                'sku'        => $refId,
                'quantity'   => (int) $quantities[$numericSkuId],
                'unit_price' => (float) $price,
            ]);
            $productsAdded++;
        }

        if ($productsAdded === 0) {
            $this->logger->info('Cart has no valid products after SKU mapping.');
            return null;
        }

        return [
            'cart'     => $cart,
            'customer' => $this->buildCustomer($cartdata),
        ];
    }

    private function resolveSkuRefId($numericSkuId, int $appId): ?string
    {
        try {
            $skuData = $this->vtexConnector->getHistoricalSingleProduct($numericSkuId);
            if (!$skuData) {
                return null;
            }
            return VTEXConfig::mapsChildProducts($appId)
                ? ($skuData->AlternateIds->RefId ?? null)
                : ($skuData->ProductRefId ?? null);
        } catch (\Exception $e) {
            $this->logger->info("Error resolving RefId for SKU $numericSkuId: " . $e->getMessage());
            return null;
        }
    }

    private function buildCustomer(array $cartdata): ?array
    {
        if (empty($cartdata['email'])) {
            return null;
        }

        $customer = ['email' => $cartdata['email']];
        if (!empty($cartdata['firstName'])) {
            $customer['first_name'] = $cartdata['firstName'];
        }
        if (!empty($cartdata['lastName'])) {
            $customer['last_name'] = $cartdata['lastName'];
        }
        if (!empty($cartdata['document'])) {
            $customer['document'] = $cartdata['document'];
        }

        return $customer;
    }

    private function buildRecoverUrl(array $cartdata): ?string
    {
        $url = $cartdata['rclastcart'] ?? null;
        if (!$url) {
            return null;
        }
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        $accountName = $cartdata['accountName'] ?? null;
        if (!$accountName) {
            return $url;
        }
        return "https://{$accountName}.vtexcommercestable.com.br/checkout/cart/" . $url;
    }

    private function parseQuantities(string $url): array
    {
        $quantities = [];
        preg_match_all('/sku=(\w+)&qty=(\d+)/', $url, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $quantities[$match[1]] = (int) $match[2];
        }
        return $quantities;
    }
}
