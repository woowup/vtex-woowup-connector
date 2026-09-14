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

    /**
     * The account asked us not to manage its opt-in: no channel is ever written.
     *
     * Read from the connector's account config, not from a constructor argument: subclasses are
     * built by the command with its own signature, so an argument would be lost there.
     *
     * @var bool
     */
    private $ignoreOptIn;

    public function __construct($vtexConnector, $logger)
    {
        $this->vtexConnector = $vtexConnector;
        $this->logger        = $logger;
        $this->ignoreOptIn   = !empty(($vtexConnector->getAccountConfig() ?? [])['ignoreOptIn']);
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

        $optIn = $this->resolveOptIn($cartdata);

        // Same attribute the customers mapper writes. Nothing has to drop it later here: the cart
        // uploader only creates, it never updates a customer that already exists.
        // Gated by `ignoreOptIn` like the channels are: the attribute is not commentary, it is
        // consent data — OptInFreshnessService::CONSENT_ATTRIBUTES drops it together with the
        // channels when preserving. If the account manages its opt-in elsewhere, the connector
        // writes none of it: no channels, no attribute.
        if ($optIn !== null && !$this->ignoreOptIn) {
            $customer['custom_attributes']['opt_in_vtex'] = $optIn ? 'True' : 'False';
        }

        return CommunicationOptIn::apply($customer, $optIn, $this->ignoreOptIn);
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
     * cart is the one creating it. `null` —no profile, or the lookup failed— leaves the opt-in
     * untouched: a failed request is not the customer saying no.
     *
     * Only called with a non-empty email: `buildCustomer()` returns before this otherwise.
     *
     * @param  array $cartdata
     * @return bool|null
     */
    private function resolveOptIn(array $cartdata): ?bool
    {
        if (array_key_exists('isNewsletterOptIn', $cartdata)) {
            return $this->normalizeOptIn($cartdata['isNewsletterOptIn']);
        }

        return $this->vtexConnector->getNewsletterOptInByEmail($cartdata['email']);
    }

    /**
     * Reads the opt-in the message carries, which is **a string, not a boolean**.
     *
     * Master Data sends the `CL` document with its booleans serialised: measured on the queue,
     * `isNewsletterOptIn` arrives as a string. A plain `(bool)` cast would turn `"false"` into
     * true and enable the three channels for someone who explicitly said no.
     *
     * Anything unrecognised returns null —do not touch— rather than guessing.
     *
     * @param  mixed $value
     * @return bool|null
     */
    private function normalizeOptIn($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
