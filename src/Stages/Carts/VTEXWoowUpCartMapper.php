<?php

namespace WoowUpConnectors\Stages\Carts;

use League\Pipeline\StageInterface;
use WoowUpConnectors\Stages\VTEXConfig;
use WoowUpV2\Models\AbandonedCartModel;

class VTEXWoowUpCartMapper implements StageInterface
{
    /**
     * Campo del payload de VTEX con la fecha real de la última sesión del carrito,
     * en ISO 8601 con offset (ej: "2026-07-02T20:09:17+00:00").
     */
    const LAST_SESSION_DATE_FIELD = 'rclastsessiondate';

    const COMMUNICATION_ENABLED = 'enabled';

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

        if (!$this->hasOptIn($cartdata)) {
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

        // Si la cuenta ignora el opt-in, el cliente se arma como siempre y no se escribe ningún canal.
        if ($this->ignoreOptIn) {
            return $customer;
        }

        // Acá ya sabemos que hay opt-in: hasOptIn() cortó el carrito si no lo había.
        $customer['mailing_enabled']  = self::COMMUNICATION_ENABLED;
        $customer['sms_enabled']      = self::COMMUNICATION_ENABLED;
        $customer['whatsapp_enabled'] = self::COMMUNICATION_ENABLED;

        return $customer;
    }

    /**
     * ¿Se le puede mandar un carrito abandonado a esta persona?
     *
     * Regla de negocio (Chris, 04-09-2026): *"no podemos mandar un carrito a alguien que no tiene
     * opt-in"*. Así que el carrito no se sube salvo que la tienda diga que SÍ.
     *
     * El opt-in **no viene en el mensaje del worker**: el `cartdata` trae los campos del carrito
     * (`rclastcart`, `rclastcartvalue`, `rclastsessiondate`) y los datos de contacto, nada más. Hay
     * que ir a buscarlo a Master Data.
     *
     * `null` —no hay perfil, o la consulta falló— cuenta como **no**: no saber que lo dio no es
     * tenerlo. Se loguea aparte del "no" explícito porque son casos distintos y el segundo es el que
     * puede tirar carritos que hoy sí se suben.
     */
    private function hasOptIn(array $cartdata): bool
    {
        if ($this->ignoreOptIn) {
            return true;
        }

        if (empty($cartdata['email'])) {
            $this->logger->info('[OptIn] Cart skipped: no email to resolve the opt-in with.');
            return false;
        }

        $optIn = $this->vtexConnector->getNewsletterOptInByEmail($cartdata['email']);

        if ($optIn === true) {
            return true;
        }

        $this->logger->info($optIn === false
            ? '[OptIn] Cart skipped: the customer has no newsletter opt-in.'
            : '[OptIn] Cart skipped: could not determine the opt-in (no Master Data profile).');

        return false;
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
