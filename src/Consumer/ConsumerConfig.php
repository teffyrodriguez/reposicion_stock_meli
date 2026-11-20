<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Consumer;

final class ConsumerConfig
{
    public function __construct(
        public readonly int $maxMessagesPerRun = 100,
        public readonly int $maxAttempts = 5,
        public readonly int $baseBackoffSeconds = 30,
        public readonly int $maxBackoffSeconds = 1800,
        public readonly int $jitterSeconds = 10,
        public readonly int $rateLimitWaitAttempts = 30,
        public readonly int $rateLimitWaitMicros = 100_000,
        public readonly int $visibilityTimeoutSeconds = 120,
    ) {
    }
}
