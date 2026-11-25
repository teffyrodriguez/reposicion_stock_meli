<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

interface RecommendationRepositoryInterface
{
    public function isFresh(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        \DateTimeImmutable $windowStart,
    ): bool;

    public function isUsuarioIntegracionActiva(int $idUsuario, int $idIntegracion): bool;

    /**
     * Actualiza FechaActualizacion y EstadoConsulta.
     * FechaSemanal se actualiza salvo que $actualizarFechaSemanal sea false.
     * Si la fila no existe, la inserta con FechaSemanal y el resto de columnas en NULL.
     */
    public function markEstadoConsulta(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        string $estadoConsulta,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $semanaRecomendacion,
        bool $actualizarFechaSemanal = true,
    ): void;

    public function upsert(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        ?int $idConfiguracionPublicacionIntegracion,
        ReplenishmentRecommendation $recommendation,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $semanaRecomendacion,
    ): void;
}
