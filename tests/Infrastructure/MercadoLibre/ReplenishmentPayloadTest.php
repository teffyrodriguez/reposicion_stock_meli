<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\ReplenishmentPayload;
use PHPUnit\Framework\TestCase;

final class ReplenishmentPayloadTest extends TestCase
{
    public function testMapsReplenishmentResponse(): void
    {
        $recommendation = ReplenishmentPayload::fromApi('UP-1', [
            'identifiers' => ['seller_sku' => 'SKU-1'],
            'product' => ['tags' => ['master_case', 'star_product']],
            'stock' => [
                'total_stock' => 0,
                'shipping_urgency' => 'THIS_WEEK',
            ],
            'recommendation' => [
                'suggested_quantity' => ['type' => 'exact', 'value' => 510, 'min' => 1, 'max' => 2],
                'replenishment_deadline' => 'THIS_WEEK',
            ],
            'eligibility_benefits' => ['AGING'],
        ]);

        self::assertSame('UP-1', $recommendation->userProductId);
        self::assertSame(510, $recommendation->cantidadSugerida);
        self::assertNull($recommendation->cantidadSugeridaMinima);
        self::assertNull($recommendation->cantidadSugeridaMaxima);
        self::assertNull($recommendation->inventoryId);
        self::assertSame('THIS_WEEK', $recommendation->urgenciaDeEnvio);
        self::assertSame('THIS_WEEK', $recommendation->plazoLimite);
        self::assertSame(0, $recommendation->stockTotal);
        self::assertSame('AGING', $recommendation->beneficio);
        self::assertSame('["master_case","star_product"]', $recommendation->tags);
        self::assertSame('SKU-1', $recommendation->sku);
        self::assertSame('ok', $recommendation->estadoConsulta);
        self::assertFalse($recommendation->empty);
    }

    public function testMapsInventoryAndSuggestedRange(): void
    {
        $recommendation = ReplenishmentPayload::fromApi('UP-3', [
            'identifiers' => ['inventory_id' => 'FFOC95076'],
            'recommendation' => [
                'suggested_quantity' => ['type' => 'range', 'value' => 99, 'min' => 10, 'max' => 40],
            ],
        ]);

        self::assertSame('FFOC95076', $recommendation->inventoryId);
        self::assertNull($recommendation->cantidadSugerida);
        self::assertSame(10, $recommendation->cantidadSugeridaMinima);
        self::assertSame(40, $recommendation->cantidadSugeridaMaxima);
    }

    public function testEmptyFlag(): void
    {
        $recommendation = ReplenishmentPayload::fromApi('UP-2', ['empty' => true]);

        self::assertTrue($recommendation->empty);
        self::assertNull($recommendation->cantidadSugerida);
        self::assertNull($recommendation->cantidadSugeridaMinima);
        self::assertNull($recommendation->cantidadSugeridaMaxima);
        self::assertNull($recommendation->inventoryId);
        self::assertNull($recommendation->sku);
        self::assertSame('sin_recomendacion', $recommendation->estadoConsulta);
    }
}
