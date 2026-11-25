<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Redis;

use Contabilium\ReposicionFull\Contracts\QueueInterface;
use Contabilium\ReposicionFull\Domain\ReposicionJob;
use Redis;

/**
 * Adaptador Redis (extensión phpredis) para producción.
 */
final class PhpRedisQueue implements QueueInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly int $dedupTtlSeconds = 1800,
        private readonly int $visibilityTimeoutSeconds = 120,
    ) {
    }

    public function enqueue(ReposicionJob $job): bool
    {
        $created = $this->redis->set(
            RedisKeys::dedup($job->businessKey()),
            '1',
            ['nx', 'ex' => $this->dedupTtlSeconds]
        );

        if (!$created) {
            var_dump('dedup no created');
            return false;
        }
        var_dump('dedup created');

        $this->redis->lPush(RedisKeys::QUEUE, $job->toJson());
        var_dump('queue created');

        return true;
    }

    public function depth(): int
    {
        return (int) $this->redis->lLen(RedisKeys::QUEUE);
    }

    public function claim(?int $visibleAtUnix = null): ?ReposicionJob
    {
        $payload = $this->redis->rPop(RedisKeys::QUEUE);
        if ($payload === false || $payload === null || $payload === '') {
            return null;
        }

        $job = ReposicionJob::fromArray(json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR));
        $visibleAt = $visibleAtUnix ?? (time() + $this->visibilityTimeoutSeconds);
        $stored = $job->toJson();
        $this->redis->zAdd(RedisKeys::PROCESSING, $visibleAt, $stored);

        return $job->withProcessingMember($stored);
    }

    public function ack(ReposicionJob $job): void
    {
        foreach ($job->ackPayloads() as $payload) {
            $this->redis->zRem(RedisKeys::PROCESSING, $payload);
        }
    }

    public function scheduleRetry(ReposicionJob $job, int $visibleAtUnix): void
    {
        $this->ack($job);
        $this->redis->zAdd(RedisKeys::PROCESSING, $visibleAtUnix, $job->toJson());
    }

    public function sendToDlq(ReposicionJob $job): void
    {
        $this->ack($job);
        $this->redis->lPush(RedisKeys::DLQ, $job->toJson());
    }

    public function recoverStale(int $nowUnix): int
    {
        $stale = $this->redis->zRangeByScore(RedisKeys::PROCESSING, '-inf', (string) $nowUnix);
        if (!is_array($stale) || $stale === []) {
            return 0;
        }

        $n = 0;
        foreach ($stale as $payload) {
            $this->redis->lPush(RedisKeys::QUEUE, $payload);
            $this->redis->zRem(RedisKeys::PROCESSING, $payload);
            $n++;
        }

        return $n;
    }

    public function dlqDepth(): int
    {
        return (int) $this->redis->lLen(RedisKeys::DLQ);
    }
}
