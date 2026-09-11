<?php

namespace WoowUpConnectors\Stages\Carts;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Support\CommunicationOptIn;
use WoowUpConnectors\Stages\VTEXConfig;
use WoowUpV2\Models\AbandonedCartModel;

class VTEXWoowUpCartMapper implements StageInterface
{
    /**
     * Campo del payload de VTEX con la fecha real de la última sesión del carrito,
     * en ISO 8601 con offset (ej: "2026-07-02T20:09:17+00:00").
     */
    const LAST_SESSION_DATE_FIELD = 'rclastsessiondate';

    private $vtexConnector;
    private $logger;
    private $ignoreOptIn;

    public function __construct($vtexConnector, $logger, $ignoreOptIn = false)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger        = $logger;
        $this->ignoreOptIn   = $ignoreOptIn;
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
        $cart->setCreatetime($this->resolveCreatetime($cartdata));
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

    /**
     * Usa la fecha real de la última sesión del carrito (rclastsessiondate) como createtime,
     * para reflejar el momento del abandono y no el momento en que el worker procesa el mensaje.
     * El valor ya viene en ISO 8601 con offset, mismo formato que espera setCreatetime.
     * Si el payload no lo trae, cae a la hora actual.
     */
    private function resolveCreatetime(array $cartdata): string
    {
        return !empty($cartdata[self::LAST_SESSION_DATE_FIELD])
            ? $cartdata[self::LAST_SESSION_DATE_FIELD]
            : date('c');
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

        return CommunicationOptIn::apply($customer, $this->resolveOptIn($cartdata), $this->ignoreOptIn);
    }

    /**
     * Prefers the opt-in that already travels in the message, and only falls back to Master Data.
     *
     * The message is the `CL` document: every other field this mapper reads —including `carttag`—
     * is a `CL` field with its exact name, and `isNewsletterOptIn` lives in that same document.
     * When it is there, no extra request is needed at all.
     *
     * The fallback matters because Master Data answers 429 on concurrent operations, so a lookup
     * per cart competes with the customers scroll of the same account.
     *
     * The cart is always uploaded; this only decides how the customer is created, and only when the
     * cart is the one creating it. `null` —field absent and no profile, or the lookup failed—
     * leaves the opt-in untouched: a failed request is not the customer saying no.
     *
     * @param  array $cartdata
     * @return bool|null
     */
    private function resolveOptIn(array $cartdata): ?bool
    {
        if (array_key_exists('isNewsletterOptIn', $cartdata)) {
            $optIn = $cartdata['isNewsletterOptIn'];

            return $optIn === null ? null : (bool) $optIn;
        }

        if (empty($cartdata['email'])) {
            return null;
        }

        return $this->vtexConnector->getNewsletterOptInByEmail($cartdata['email']);
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
        $storeUrl = $this->vtexConnector->getStoreUrl();
        if ($storeUrl) {
            return rtrim($storeUrl, '/') . '/checkout/cart/' . $url;
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
