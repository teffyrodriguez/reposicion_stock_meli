<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\RateLimit;

use Contabilium\ReposicionFull\RateLimit\InMemoryTokenBucket;
use Contabilium\ReposicionFull\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class InMemoryTokenBucketTest extends TestCase
{
    public function testRespectsMaxTokens(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        $bucket = new InMemoryTokenBucket($clock, maxTokens: 2, refillPerSecond: 0);

        self::assertTrue($bucket->tryAcquire());
        self::assertTrue($bucket->tryAcquire());
        self::assertFalse($bucket->tryAcquire());
    }

    public function testRefillsOverTime(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        // 1 token/sec
        $bucket = new InMemoryTokenBucket($clock, maxTokens: 1, refillPerSecond: 1.0);

        self::assertTrue($bucket->tryAcquire());
        self::assertFalse($bucket->tryAcquire());

        $clock->advanceSeconds(1);
        self::assertTrue($bucket->tryAcquire());
    }

    public function testPenalizeBlocksAcquire(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T12:00:00Z'));
        $bucket = new InMemoryTokenBucket($clock, maxTokens: 10, refillPerSecond: 10);

        $bucket->penalize(5);
        self::assertFalse($bucket->tryAcquire());

        $clock->advanceSeconds(5);
        self::assertTrue($bucket->tryAcquire());
    }
}
