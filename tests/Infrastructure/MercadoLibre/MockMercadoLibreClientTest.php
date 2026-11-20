<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\MockMercadoLibreClient;
use Contabilium\ReposicionFull\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class MockMercadoLibreClientTest extends TestCase
{
    public function testReturnsFixtureBodyForTheRequestedUserProduct(): void
    {
        $client = new MockMercadoLibreClient($this->write([
            'status' => 200,
            'body' => [
                'stock' => ['total_stock' => 4, 'shipping_urgency' => 'THIS_WEEK'],
                'recommendation' => [
                    'suggested_quantity' => ['value' => 12],
                    'replenishment_deadline' => 'THIS_WEEK',
                ],
            ],
        ]), new ArrayLogger());

        $recommendation = $client->getReplenishmentRecommendation(9, 3, 'UP-REAL');

        self::assertSame('UP-REAL', $recommendation->userProductId);
        self::assertSame(12, $recommendation->cantidadSugerida);
        self::assertSame(4, $recommendation->stockTotal);
        self::assertSame('ok', $recommendation->estadoConsulta);
        self::assertFalse($recommendation->empty);
    }

    public function testStatus206KeepsTheBodyAndMarksPartialContent(): void
    {
        $client = new MockMercadoLibreClient($this->write([
            'status' => 206,
            'body' => [
                'stock' => ['total_stock' => 0],
                'recommendation' => ['suggested_quantity' => ['value' => 8]],
            ],
        ]), new ArrayLogger());

        $recommendation = $client->getReplenishmentRecommendation(9, 3, 'UP-206');

        self::assertSame('partial-content', $recommendation->estadoConsulta);
        self::assertSame(8, $recommendation->cantidadSugerida);
        self::assertSame(0, $recommendation->stockTotal);
        self::assertFalse($recommendation->empty);
    }

    public function testAccessDeniedCarriesEstado(): void
    {
        $client = new MockMercadoLibreClient($this->write([
            'status' => 403,
            'error' => 'access_denied',
            'message' => 'You are not authorized to access this resource.',
        ]), new ArrayLogger());

        try {
            $client->getReplenishmentRecommendation(1, 1, 'UP-403');
            self::fail('Se esperaba access_denied');
        } catch (NotFoundRecommendationException $e) {
            self::assertSame('access_denied', $e->estadoConsulta);
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testInvalidRequestCarriesEstado(): void
    {
        $client = new MockMercadoLibreClient($this->write([
            'status' => 400,
            'error' => 'invalid_request',
            'message' => 'The request is invalid.',
        ]), new ArrayLogger());

        try {
            $client->getReplenishmentRecommendation(1, 1, 'UP-400');
            self::fail('Se esperaba invalid_request');
        } catch (NotFoundRecommendationException $e) {
            self::assertSame('invalid_request', $e->estadoConsulta);
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testStatus404IsABusinessMiss(): void
    {
        $client = new MockMercadoLibreClient($this->write(['status' => 404, 'body' => []]), new ArrayLogger());

        $this->expectException(NotFoundRecommendationException::class);
        $client->getReplenishmentRecommendation(1, 1, 'UP-404');
    }

    public function testStatus429IsRetryable(): void
    {
        $client = new MockMercadoLibreClient($this->write(['status' => 429, 'body' => []]), new ArrayLogger());

        $this->expectException(RetryableException::class);
        $client->getReplenishmentRecommendation(1, 1, 'UP-429');
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meli-mock');
        self::assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
