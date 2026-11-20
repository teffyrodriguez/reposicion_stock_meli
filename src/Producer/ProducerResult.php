<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Producer;

final class ProducerResult
{
    public function __construct(
        public readonly int $fetched = 0,
        public readonly int $enqueued = 0,
        public readonly int $dedupSkipped = 0,
        public readonly int $batches = 0,
        public readonly bool $pausedByBackpressure = false,
        public readonly bool $completedUniverse = false,
    ) {
    }
}
