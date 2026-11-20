<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\HttpMercadoLibreClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HttpMercadoLibreClientUrlTest extends TestCase
{
    public function testAppendsCountryFromEnvValue(): void
    {
        $url = $this->url(
            '/marketplace/fbm/user-products/{meLiUserProductId}/replenishment',
            'MX',
            'MLBU763575079',
        );

        self::assertSame(
            'https://api.mercadolibre.com/marketplace/fbm/user-products/MLBU763575079/replenishment?country=MX',
            $url,
        );
    }

    public function testAppendsCountryAfterExistingQuery(): void
    {
        $url = $this->url(
            'https://api.mercadolibre.com/items/{meLiUserProductId}?foo=1',
            'AR',
            'MLA 1',
        );

        self::assertSame(
            'https://api.mercadolibre.com/items/MLA%201?foo=1&country=AR',
            $url,
        );
    }

    /**
     * @param non-empty-string $path
     */
    private function url(string $path, string $country, string $userProductId): string
    {
        $class = new ReflectionClass(HttpMercadoLibreClient::class);
        $client = $class->newInstanceWithoutConstructor();
        $class->getProperty('baseUrl')->setValue($client, 'https://api.mercadolibre.com');
        $class->getProperty('pathTemplate')->setValue($client, $path);
        $class->getProperty('country')->setValue($client, $country);

        return $class->getMethod('url')->invoke($client, 1, 10, $userProductId);
    }
}
