<?php

namespace WoowUpConnectors\Stages\Orders;

/**
 * Arma los campos nativos `promotion` (raíz de la compra) y `collection` (por ítem de
 * `purchase_detail`) de la API v3 de Purchases, a partir de lo que ya trae la orden de VTEX.
 *
 * Hasta ahora esta información se guardaba como atributo extendido (`custom_attributes['promocion']`
 * en la venta, `Colecciones` en el producto), que no puede alimentar los filtros de segmentación
 * "Código de promoción" y "Colecciones de productos".
 *
 * Reglas que vienen del backend y explican las validaciones de acá:
 *
 * - `name` es requerido y `type: string` en el JSON Schema del endpoint. Una violación devuelve 400
 *   y **descarta la compra entera**, así que un elemento inválido se descarta acá y no se manda.
 * - El schema no limita el largo de `name`, pero las columnas sí, y `promotion` se procesa DENTRO de
 *   la transacción de la venta: un error de columna haría rollback de la compra. Por eso se descarta
 *   (y no se trunca) lo que no entra: truncar cambiaría la clave de dedup y crearía una fila nueva.
 * - La dedup de colección es por `external_id` y después por `name`; la de promoción es sólo por
 *   `name`. Un rename en el origen no se propaga a WoowUp.
 * - El orden de salida es determinístico a propósito. El payload completo de la venta se hashea para
 *   la caché de ventas, y VTEX no garantiza el orden ni de los clusters ni de las promociones: si
 *   emitiéramos en el orden recibido, el hash oscilaría y re-postearíamos cada venta en cada corrida.
 */
class PurchaseNativeFields
{
    /** Anchos de las columnas destino: `promo.name` y `promo.external_id`. */
    private const PROMOTION_NAME_MAX        = 255;
    private const PROMOTION_EXTERNAL_ID_MAX = 128;

    /** Anchos de las columnas destino: `collection.name` y `collection.external_id`. */
    private const COLLECTION_NAME_MAX        = 256;
    private const COLLECTION_EXTERNAL_ID_MAX = 64;

    /**
     * Promociones de la compra, desde `ratesAndBenefitsData.rateAndBenefitsIdentifiers`.
     *
     * @param  mixed $identifiers
     * @return array lista de `['name' => string, 'external_id' => string]`, ordenada por nombre
     */
    public static function promotions($identifiers): array
    {
        $promotions = [];
        $seen       = [];

        foreach (is_array($identifiers) ? $identifiers : [] as $identifier) {
            $promotion = self::entry(
                self::read($identifier, 'name'),
                self::read($identifier, 'id'),
                self::PROMOTION_NAME_MAX,
                self::PROMOTION_EXTERNAL_ID_MAX
            );

            if ($promotion === null) {
                continue;
            }

            // El backend colapsa las promociones homónimas en una sola fila: mandarlas repetidas
            // sólo agrega ruido al payload y al hash.
            $key = mb_strtolower($promotion['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $promotions[] = $promotion;
        }

        usort($promotions, function (array $a, array $b) {
            return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        });

        return $promotions;
    }

    /**
     * Colecciones de un ítem, desde `items[].additionalInfo.productClusterId` (CSV de ids).
     *
     * La orden trae los ids de cluster pero no sus nombres, así que hay que pasarle el mapa de
     * colecciones de la cuenta (`VTEXConnector::getCollections()`). Un id que no esté en el mapa se
     * descarta: sin nombre no se puede emitir, porque `name` es obligatorio.
     *
     * @param  mixed $productClusterId CSV de ids de cluster
     * @param  array $collectionNames  `clusterId => nombre` de toda la cuenta
     * @return array lista de `['name' => string, 'external_id' => string]`
     */
    public static function collections($productClusterId, array $collectionNames): array
    {
        $clusterIds = self::clusterIds($productClusterId);

        if (empty($clusterIds) || empty($collectionNames)) {
            return [];
        }

        $collections = [];

        // Se recorre el mapa de la cuenta y no los ids de la orden: el mapa viene ordenado, así que
        // fija el orden de salida. VTEX no garantiza el orden del CSV, y el payload de la venta se
        // hashea para la caché.
        foreach ($collectionNames as $clusterId => $name) {
            if (!isset($clusterIds[$clusterId])) {
                continue;
            }

            $collection = self::entry(
                $name,
                $clusterId,
                self::COLLECTION_NAME_MAX,
                self::COLLECTION_EXTERNAL_ID_MAX
            );

            if ($collection !== null) {
                $collections[] = $collection;
            }
        }

        return $collections;
    }

    /**
     * Valida y normaliza un elemento. Devuelve null si no es enviable.
     *
     * @return array|null `['name' => string]` más `external_id` si es válido
     */
    private static function entry($name, $externalId, int $nameMax, int $externalIdMax): ?array
    {
        if (!is_scalar($name)) {
            return null;
        }

        $name = trim((string) $name);

        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > $nameMax) {
            return null;
        }

        $entry = ['name' => $name];

        // Un external_id que no entra en la columna se omite y el elemento igual se manda: la
        // colección degrada a dedup por nombre, que es preferible a perderla.
        if (is_scalar($externalId)) {
            $externalId = trim((string) $externalId);
            if ($externalId !== '' && strlen($externalId) <= $externalIdMax) {
                $entry['external_id'] = $externalId;
            }
        }

        return $entry;
    }

    /**
     * Lee una clave indistintamente de un objeto o de un array.
     *
     * La orden llega decodificada como objeto, pero los stages de las cuentas la manipulan y algunos
     * la pasan como array. Acceder con `->` a un array es un E_NOTICE, y el error handler de los
     * comandos convierte todo notice en excepción: en el mapper eso aborta la corrida entera, no una
     * venta, porque `importOrders()` no envuelve cada orden en un try/catch.
     *
     * @return mixed|null
     */
    private static function read($source, string $key)
    {
        if (is_object($source)) {
            return $source->{$key} ?? null;
        }

        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return null;
    }

    /**
     * Parsea el CSV de `productClusterId` a un set de ids, para poder consultarlo con isset().
     *
     * @return array `[clusterId => true]`
     */
    private static function clusterIds($productClusterId): array
    {
        if (!is_scalar($productClusterId)) {
            return [];
        }

        $clusterIds = [];

        foreach (explode(',', (string) $productClusterId) as $clusterId) {
            $clusterId = trim($clusterId);
            if ($clusterId !== '') {
                $clusterIds[$clusterId] = true;
            }
        }

        return $clusterIds;
    }
}
