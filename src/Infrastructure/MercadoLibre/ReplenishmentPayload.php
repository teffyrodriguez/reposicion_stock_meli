<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

/** Mapea el JSON de replenishment a las columnas de RecomendacionReposicion. */
final class ReplenishmentPayload
{
    /**
     * @param array<mixed> $data
     */
    public static function fromApi(
        string $userProductId,
        array $data,
        ?string $estadoConsulta = null,
    ): ReplenishmentRecommendation
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        /** @var array<mixed> $data */
        $stock = self::section($data, 'stock');
        $recommendation = self::section($data, 'recommendation');
        $identifiers = self::section($data, 'identifiers');
        $empty = $data === [] || ($data['empty'] ?? false) === true;

        return new ReplenishmentRecommendation(
            userProductId: $userProductId,
            cantidadSugerida: self::suggestedQuantity($recommendation, $data),
            cantidadSugeridaMinima: self::suggestedBound($recommendation, $data, 'min'),
            cantidadSugeridaMaxima: self::suggestedBound($recommendation, $data, 'max'),
            inventoryId: self::stringOrNull($identifiers, 'inventory_id')
                ?? self::stringOrNull($data, 'inventory_id'),
            urgenciaDeEnvio: self::clip(
                self::stringOrNull($stock, 'shipping_urgency')
                    ?? self::stringOrNull($data, 'urgencia_de_envio', 'shipping_urgency'),
                50,
            ),
            plazoLimite: self::clip(
                self::stringOrNull($recommendation, 'replenishment_deadline')
                    ?? self::stringOrNull($data, 'plazo_limite', 'replenishment_deadline'),
                100,
            ),
            stockTotal: self::intOrNull($stock, 'total_stock')
                ?? self::intOrNull($data, 'stock_total', 'total_stock'),
            beneficio: self::beneficio($data),
            tags: self::tags($data),
            sku: self::stringOrNull($identifiers, 'seller_sku')
                ?? self::stringOrNull($data, 'seller_sku'),
            estadoConsulta: self::clip(
                $estadoConsulta
                    ?? self::stringOrNull($data, 'estado_consulta', 'estadoConsulta')
                    ?? ($empty ? 'sin_recomendacion' : 'ok'),
                100,
            ),
            empty: $empty,
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
    }

    /**
     * @param array<mixed> $recommendation
     * @param array<mixed> $data
     */
    private static function suggestedQuantity(array $recommendation, array $data): ?int
    {
        $suggested = self::suggestedObject($recommendation, $data);
        if ($suggested === null || self::suggestedType($suggested) !== 'exact') {
            return null;
        }
        if (!isset($suggested['value']) || !is_numeric($suggested['value'])) {
            return null;
        }

        return (int) $suggested['value'];
    }

    /**
     * @param array<mixed> $recommendation
     * @param array<mixed> $data
     */
    private static function suggestedBound(array $recommendation, array $data, string $key): ?int
    {
        $suggested = self::suggestedObject($recommendation, $data);
        if ($suggested === null || self::suggestedType($suggested) !== 'range') {
            return null;
        }
        if (!array_key_exists($key, $suggested) || !is_numeric($suggested[$key])) {
            return null;
        }

        return (int) $suggested[$key];
    }

    /**
     * @param array<mixed> $recommendation
     * @param array<mixed> $data
     * @return array<mixed>|null
     */
    private static function suggestedObject(array $recommendation, array $data): ?array
    {
        $suggested = $recommendation['suggested_quantity'] ?? $data['suggested_quantity'] ?? null;

        return is_array($suggested) ? $suggested : null;
    }

    /**
     * @param array<mixed> $suggested
     */
    private static function suggestedType(array $suggested): ?string
    {
        if (!isset($suggested['type']) || !is_scalar($suggested['type'])) {
            return null;
        }

        $type = strtolower(trim((string) $suggested['type']));

        return $type === '' ? null : $type;
    }

    /**
     * @param array<mixed> $data
     */
    private static function beneficio(array $data): ?string
    {
        $benefits = $data['eligibility_benefits'] ?? $data['beneficio'] ?? null;
        if (is_array($benefits)) {
            $parts = [];
            foreach ($benefits as $benefit) {
                if (is_scalar($benefit) && trim((string) $benefit) !== '') {
                    $parts[] = trim((string) $benefit);
                }
            }

            return $parts === [] ? null : self::clip(implode(',', $parts), 100);
        }

        return self::clip(is_string($benefits) ? $benefits : null, 100);
    }

    /**
     * @param array<mixed> $data
     */
    private static function tags(array $data): ?string
    {
        $product = self::section($data, 'product');
        $tags = $product['tags'] ?? $data['tags'] ?? null;
        if (is_string($tags)) {
            $tags = trim($tags);

            return $tags === '' ? null : $tags;
        }
        if (!is_array($tags) || $tags === []) {
            return null;
        }

        $encoded = json_encode(array_values($tags), JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringOrNull(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $value = trim((string) $data[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intOrNull(array $data, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (is_int($data[$key]) || is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
