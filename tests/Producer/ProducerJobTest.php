<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Producer;

use Contabilium\ReposicionFull\Contracts\EligibleProductRepositoryInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;
use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use Contabilium\ReposicionFull\Producer\ProducerConfig;
use Contabilium\ReposicionFull\Producer\ProducerJob;
use Contabilium\ReposicionFull\Queue\InMemoryCheckpointStore;
use Contabilium\ReposicionFull\Queue\InMemoryQueue;
use Contabilium\ReposicionFull\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ProducerJobTest extends TestCase
{
    public function testEnqueuesPendingProductsAndRespectsDedup(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T15:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires')));
        $queue = new InMemoryQueue($clock);
        $checkpoint = new InMemoryCheckpointStore();

        $repo = new class implements EligibleProductRepositoryInterface {
            private int $calls = 0;

            public function fetchPendingBatch(
                \DateTimeImmutable $windowStart,
                int $batchSize,
                ?EligibleProduct $afterCursor,
            ): array {
                $this->calls++;
                if ($this->calls > 1) {
                    return [];
                }

                return [
                    new EligibleProduct(1, 10, 'UP-A', 100),
                    new EligibleProduct(1, 10, 'UP-B', 101),
                ];
            }
        };

        $producer = new ProducerJob(
            eligibleRepository: $repo,
            queue: $queue,
            checkpoint: $checkpoint,
            weeklyWindow: new MlWeeklyWindow(),
            clock: $clock,
            config: new ProducerConfig(batchSize: 10, maxQueuePending: 100),
        );

        $result = $producer->run();

        self::assertSame(2, $result->fetched);
        self::assertSame(2, $result->enqueued);
        self::assertSame(0, $result->dedupSkipped);
        self::assertTrue($result->completedUniverse);
        self::assertSame(2, $queue->depth());
    }

    public function testBackpressureStopsWhenQueueFull(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T15:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires')));
        $queue = new InMemoryQueue($clock);
        $checkpoint = new InMemoryCheckpointStore();

        // Prellenar cola
        for ($i = 0; $i < 3; $i++) {
            $queue->enqueue(
                \Contabilium\ReposicionFull\Domain\ReposicionJob::createNew('PRE' . $i, 9, 9, null, 'seed', $clock->now())
            );
        }

        $repo = new class implements EligibleProductRepositoryInterface {
            public function fetchPendingBatch(
                \DateTimeImmutable $windowStart,
                int $batchSize,
                ?EligibleProduct $afterCursor,
            ): array {
                return [new EligibleProduct(1, 1, 'SHOULD-NOT', null)];
            }
        };

        $producer = new ProducerJob(
            $repo,
            $queue,
            $checkpoint,
            new MlWeeklyWindow(),
            $clock,
            new ProducerConfig(maxQueuePending: 3),
        );

        $result = $producer->run();

        self::assertTrue($result->pausedByBackpressure);
        self::assertSame(0, $result->enqueued);
        self::assertSame(3, $queue->depth());
    }

    public function testCheckpointAdvancesAcrossRuns(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-28T15:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires')));
        $queue = new InMemoryQueue($clock);
        $checkpoint = new InMemoryCheckpointStore();

        $repo = new class implements EligibleProductRepositoryInterface {
            public function fetchPendingBatch(
                \DateTimeImmutable $windowStart,
                int $batchSize,
                ?EligibleProduct $afterCursor,
            ): array {
                if ($afterCursor === null) {
                    return [new EligibleProduct(1, 1, 'UP-1', null)];
                }
                if ($afterCursor->meLiUserProductId === 'UP-1') {
                    return [new EligibleProduct(1, 1, 'UP-2', null)];
                }

                return [];
            }
        };

        $config = new ProducerConfig(batchSize: 1, maxBatchesPerRun: 1, maxQueuePending: 100);
        $producer = new ProducerJob($repo, $queue, $checkpoint, new MlWeeklyWindow(), $clock, $config);

        $r1 = $producer->run();
        self::assertSame(1, $r1->enqueued);
        self::assertFalse($r1->completedUniverse);
        self::assertNotNull($checkpoint->get());
        self::assertSame('UP-1', $checkpoint->get()->meLiUserProductId);

        $r2 = $producer->run();
        self::assertSame(1, $r2->enqueued);
        self::assertSame(2, $queue->depth());
    }
}
