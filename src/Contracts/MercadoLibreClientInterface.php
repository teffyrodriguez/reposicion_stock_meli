<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

interface MercadoLibreClientInterface
{
    /**
     * @throws \Contabilium\ReposicionFull\Domain\Exceptions\RetryableException
     * @throws \Contabilium\ReposicionFull\Domain\Exceptions\PermanentException
     * @throws \Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException
     */
    public function getReplenishmentRecommendation(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
    ): ReplenishmentRecommendation;
}
