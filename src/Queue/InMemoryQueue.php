<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Queue;

use Contabilium\ReposicionFull\Contracts\ClockInterface;
use Contabilium\ReposicionFull\Contracts\QueueInterface;
use Contabilium\ReposicionFull\Domain\ReposicionJob;

/**
 * Cola en memoria equivalente a:
 * queue (list), processing (zset), dedup:*, dlq.
 */
final class InMemoryQueue implements QueueInterface
{
    /** @var list<string> */
    private array $queue = [];

    /** @var array<string, int> payload json => visibleAt */
    private array $processing = [];

    /** @var array<string, int> dedupKey => expiresAt */
    private array $dedup = [];

    /** @var list<string> */
    private array $dlq = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $dedupTtlSeconds = 1800,
        private readonly int $visibilityTimeoutSeconds = 120,
    ) {
    }

    public function enqueue(ReposicionJob $job): bool
    {
        $this->purgeExpiredDedup();
        $key = $this->dedupKey($job);
        $now = $this->clock->now()->getTimestamp();

        if (isset($this->dedup[$key]) && $this->dedup[$key] > $now) {
            return false;
        }

        $this->dedup[$key] = $now + $this->dedupTtlSeconds;
        $this->queue[] = $job->toJson();

        return true;
    }

    public function depth(): int
    {
        return count($this->queue);
    }

    public function claim(?int $visibleAtUnix = null): ?ReposicionJob
    {
        if ($this->queue === []) {
            return null;
        }

        $payload = array_shift($this->queue);
        $job = ReposicionJob::fromArray(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        $visibleAt = $visibleAtUnix ?? ($this->clock->now()->getTimestamp() + $this->visibilityTimeoutSeconds);
        $stored = $job->toJson();
        $this->processing[$stored] = $visibleAt;

        return $job->withProcessingMember($stored);
    }

    public function ack(ReposicionJob $job): void
    {
        foreach ($job->ackPayloads() as $payload) {
            unset($this->processing[$payload]);
        }
        foreach (array_keys($this->processing) as $payload) {
            $data = json_decode($payload, true);
            if (is_array($data) && ($data['jobId'] ?? null) === $job->jobId) {
                unset($this->processing[$payload]);
            }
        }
    }

    public function scheduleRetry(ReposicionJob $job, int $visibleAtUnix): void
    {
        $this->ack($job);
        $this->processing[$job->toJson()] = $visibleAtUnix;
    }

    public function sendToDlq(ReposicionJob $job): void
    {
        $this->ack($job);
        $this->dlq[] = $job->toJson();
    }

    public function recoverStale(int $nowUnix): int
    {
        $recovered = 0;
        foreach ($this->processing as $payload => $visibleAt) {
            if ($visibleAt <= $nowUnix) {
                unset($this->processing[$payload]);
                $this->queue[] = $payload;
                $recovered++;
            }
        }

        return $recovered;
    }

    public function dlqDepth(): int
    {
        return count($this->dlq);
    }

    /** @return list<ReposicionJob> */
    public function dlqJobs(): array
    {
        return array_map(
            static fn (string $p) => ReposicionJob::fromArray(json_decode($p, true, 512, JSON_THROW_ON_ERROR)),
            $this->dlq,
        );
    }

    public function processingCount(): int
    {
        return count($this->processing);
    }

    private function dedupKey(ReposicionJob $job): string
    {
        return hash('sha256', $job->businessKey());
    }

    private function purgeExpiredDedup(): void
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->dedup as $key => $expires) {
            if ($expires <= $now) {
                unset($this->dedup[$key]);
            }
        }
    }
}
