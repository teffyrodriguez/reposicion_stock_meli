<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
