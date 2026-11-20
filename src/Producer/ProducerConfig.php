<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Producer;

final class ProducerConfig
{
    public function __construct(
        public readonly int $batchSize = 5000,
        public readonly int $maxQueuePending = 20000,
        public readonly string $source = 'reconcile',
        public readonly int $maxBatchesPerRun = 100,
    ) {
    }
}
