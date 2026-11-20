<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Domain;

/** Campos que se persisten en RecomendacionReposicion. */
final class ReplenishmentRecommendation
{
    public function __construct(
        public readonly string $userProductId,
        public readonly ?int $cantidadSugerida = null,
        public readonly ?int $cantidadSugeridaMinima = null,
        public readonly ?int $cantidadSugeridaMaxima = null,
        public readonly ?string $inventoryId = null,
        public readonly ?string $urgenciaDeEnvio = null,
        public readonly ?string $plazoLimite = null,
        public readonly ?int $stockTotal = null,
        public readonly ?string $beneficio = null,
        public readonly ?string $tags = null,
        public readonly ?string $sku = null,
        public readonly ?string $estadoConsulta = null,
        public readonly bool $empty = false,
    ) {
    }

    public static function empty(string $userProductId): self
    {
        return new self(
            userProductId: $userProductId,
            estadoConsulta: 'sin_recomendacion',
            empty: true,
        );
    }
}
