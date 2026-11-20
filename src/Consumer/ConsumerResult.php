<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Consumer;

final class ConsumerResult
{
    /**
     * @param array<string, int> $outcomes count by ProcessingOutcome value
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly array $outcomes = [],
    ) {
    }
}
