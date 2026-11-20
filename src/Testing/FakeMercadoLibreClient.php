<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Testing;

use Contabilium\ReposicionFull\Contracts\MercadoLibreClientInterface;
use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\PermanentException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

final class FakeMercadoLibreClient implements MercadoLibreClientInterface
{
    /** @var array<string, ReplenishmentRecommendation|\Throwable> */
    public array $responses = [];

    public int $calls = 0;

    public function getReplenishmentRecommendation(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
    ): ReplenishmentRecommendation {
        $this->calls++;
        $key = $idUsuario . '|' . $idIntegracion . '|' . $meLiUserProductId;
        $response = $this->responses[$key] ?? new ReplenishmentRecommendation(
            userProductId: $meLiUserProductId,
            cantidadSugerida: 10,
            estadoConsulta: 'ok',
        );

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    public function willReturn(string $up, int $user, int $integration, ReplenishmentRecommendation $rec): void
    {
        $this->responses[$user . '|' . $integration . '|' . $up] = $rec;
    }

    public function willFail(string $up, int $user, int $integration, \Throwable $e): void
    {
        $this->responses[$user . '|' . $integration . '|' . $up] = $e;
    }

    public static function rateLimited(): RetryableException
    {
        return new RetryableException('meli_429', penalizeRateLimit: true, httpStatus: 429);
    }

    public static function serverError(): RetryableException
    {
        return new RetryableException('meli_500', penalizeRateLimit: false, httpStatus: 500);
    }

    public static function authError(): PermanentException
    {
        return new PermanentException('meli_401');
    }

    public static function notFound(): NotFoundRecommendationException
    {
        return new NotFoundRecommendationException('meli_404');
    }
}
