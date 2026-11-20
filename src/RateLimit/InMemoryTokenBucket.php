<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\RateLimit;

use Contabilium\ReposicionFull\Contracts\ClockInterface;
use Contabilium\ReposicionFull\Contracts\RateLimiterInterface;

/**
 * Token bucket en memoria (tests / proceso single-process).
 *
 * Referencia ML: límites por Client ID; endpoints de stock documentan ~100 RPM.
 * Configurar maxTokens/refill según cuota reservada al proceso (ej. 25 RPM).
 */
final class InMemoryTokenBucket implements RateLimiterInterface
{
    private float $tokens;
    private float $updatedAt;
    private float $penalizedUntil = 0.0;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly float $maxTokens = 25.0,
        private readonly float $refillPerSecond = 25.0 / 60.0,
    ) {
        $this->tokens = $maxTokens;
        $this->updatedAt = (float) $this->clock->now()->format('U.u');
    }

    public function tryAcquire(): bool
    {
        $now = (float) $this->clock->now()->format('U.u');

        if ($now < $this->penalizedUntil) {
            return false;
        }

        $elapsed = max(0.0, $now - $this->updatedAt);
        $this->tokens = min($this->maxTokens, $this->tokens + ($elapsed * $this->refillPerSecond));
        $this->updatedAt = $now;

        if ($this->tokens < 1.0) {
            return false;
        }

        $this->tokens -= 1.0;

        return true;
    }

    public function penalize(int $seconds = 5): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        $this->tokens = 0.0;
        $this->updatedAt = $now;
        $this->penalizedUntil = $now + $seconds;
    }

    public function availableTokens(): float
    {
        return $this->tokens;
    }
}
