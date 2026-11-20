<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Support;

use Contabilium\ReposicionFull\Contracts\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}
