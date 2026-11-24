<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Infrastructure\MercadoLibre;

use Closure;
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

        $assign = Closure::bind(
            function (string $baseUrl, string $pathTemplate, string $country): void {
                $this->baseUrl = $baseUrl;
                $this->pathTemplate = $pathTemplate;
                $this->country = $country;
            },
            $client,
            HttpMercadoLibreClient::class,
        );
        $assign('https://api.mercadolibre.com', $path, $country);

        $build = Closure::bind(
            function (int $idUsuario, int $idIntegracion, string $meLiUserProductId): string {
                return $this->url($idUsuario, $idIntegracion, $meLiUserProductId);
            },
            $client,
            HttpMercadoLibreClient::class,
        );

        return $build(1, 10, $userProductId);
    }
}
