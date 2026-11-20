<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Domain;

final class EligibleProduct
{
    public function __construct(
        public readonly int $idUsuario,
        public readonly int $idIntegracion,
        public readonly string $meLiUserProductId,
        public readonly ?int $idConfiguracionPublicacionIntegracion = null,
    ) {
    }
}
