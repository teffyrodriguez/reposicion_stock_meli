<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Support;

use Contabilium\ReposicionFull\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
