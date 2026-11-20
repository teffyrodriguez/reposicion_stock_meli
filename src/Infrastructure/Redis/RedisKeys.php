<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Redis;

final class RedisKeys
{
    public const QUEUE = 'reposicion:queue';
    public const PROCESSING = 'reposicion:processing';
    public const DLQ = 'reposicion:dlq';
    public const RATE_LIMIT = 'reposicion:ratelimit:ml';
    public const DEDUP_PREFIX = 'reposicion:dedup:';
    public const CHECKPOINT = 'reposicion:producer:checkpoint';

    public static function dedup(string $businessKey): string
    {
        return self::DEDUP_PREFIX . hash('sha256', $businessKey);
    }
}
