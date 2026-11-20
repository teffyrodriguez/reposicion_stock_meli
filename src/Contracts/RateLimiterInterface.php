<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

interface RateLimiterInterface
{
    /** Intenta obtener 1 token. false si no hay cuota disponible ahora. */
    public function tryAcquire(): bool;

    /** Penaliza el bucket (p.ej. ante HTTP 429). */
    public function penalize(int $seconds = 5): void;
}
