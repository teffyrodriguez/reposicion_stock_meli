<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Queue;

use Contabilium\ReposicionFull\Domain\ReposicionJob;
use Contabilium\ReposicionFull\Queue\InMemoryQueue;
use Contabilium\ReposicionFull\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class InMemoryQueueTest extends TestCase
{
    public function testDedupPreventsSecondEnqueue(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        $queue = new InMemoryQueue($clock, dedupTtlSeconds: 60);

        $job1 = ReposicionJob::createNew('UP1', 1, 10, 100, 'reconcile', $clock->now());
        $job2 = ReposicionJob::createNew('UP1', 1, 10, 100, 'reconcile', $clock->now());

        self::assertTrue($queue->enqueue($job1));
        self::assertFalse($queue->enqueue($job2));
        self::assertSame(1, $queue->depth());
    }

    public function testClaimAckAndRecoverStale(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        $queue = new InMemoryQueue($clock, visibilityTimeoutSeconds: 30);

        $job = ReposicionJob::createNew('UP2', 2, 20, null, 'reconcile', $clock->now());
        $queue->enqueue($job);

        $claimed = $queue->claim($clock->now()->getTimestamp() + 30);
        self::assertNotNull($claimed);
        self::assertSame(0, $queue->depth());
        self::assertSame(1, $queue->processingCount());

        // simula worker caído: visibility vencida
        $clock->advanceSeconds(31);
        self::assertSame(1, $queue->recoverStale($clock->now()->getTimestamp()));
        self::assertSame(1, $queue->depth());
    }

    public function testSendToDlq(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        $queue = new InMemoryQueue($clock);

        $job = ReposicionJob::createNew('UP3', 3, 30, null, 'reconcile', $clock->now());
        $queue->enqueue($job);
        $claimed = $queue->claim();
        self::assertNotNull($claimed);

        $failed = $claimed->withRetry('boom');
        $queue->sendToDlq($failed);

        self::assertSame(1, $failed->attempt);
        self::assertContains($claimed->processingMember, $failed->ackPayloads());
        self::assertNotSame($claimed->toJson(), $failed->toJson());
        self::assertSame(1, $queue->dlqDepth());
        self::assertSame(0, $queue->processingCount());
    }
}
