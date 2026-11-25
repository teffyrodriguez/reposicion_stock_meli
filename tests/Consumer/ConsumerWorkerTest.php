<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Consumer;

use Contabilium\ReposicionFull\Consumer\ConsumerConfig;
use Contabilium\ReposicionFull\Consumer\ConsumerWorker;
use Contabilium\ReposicionFull\Consumer\ProcessingOutcome;
use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Domain\ReposicionJob;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;
use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use Contabilium\ReposicionFull\Queue\InMemoryQueue;
use Contabilium\ReposicionFull\RateLimit\InMemoryTokenBucket;
use Contabilium\ReposicionFull\Support\FixedClock;
use Contabilium\ReposicionFull\Testing\FakeMercadoLibreClient;
use Contabilium\ReposicionFull\Testing\FakeRecommendationRepository;
use PHPUnit\Framework\TestCase;

final class ConsumerWorkerTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryQueue $queue;
    private FakeRecommendationRepository $repo;
    private FakeMercadoLibreClient $meli;
    private InMemoryTokenBucket $limiter;
    private ConsumerWorker $worker;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(
            new \DateTimeImmutable('2026-10-28T15:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires'))
        );
        $this->queue = new InMemoryQueue($this->clock, visibilityTimeoutSeconds: 60);
        $this->repo = new FakeRecommendationRepository();
        $this->meli = new FakeMercadoLibreClient();
        $this->limiter = new InMemoryTokenBucket($this->clock, maxTokens: 100, refillPerSecond: 100);
        $this->worker = new ConsumerWorker(
            queue: $this->queue,
            recommendations: $this->repo,
            meliClient: $this->meli,
            rateLimiter: $this->limiter,
            weeklyWindow: new MlWeeklyWindow(),
            clock: $this->clock,
            config: new ConsumerConfig(
                maxMessagesPerRun: 10,
                maxAttempts: 3,
                baseBackoffSeconds: 30,
                jitterSeconds: 0,
                rateLimitWaitAttempts: 1,
                rateLimitWaitMicros: 0,
            ),
        );
    }

    public function testHappyPathUpsertsAndAcks(): void
    {
        $this->enqueue('UP-OK', 1, 10);
        $this->meli->willReturn('UP-OK', 1, 10, new ReplenishmentRecommendation(
            userProductId: 'UP-OK',
            cantidadSugerida: 7,
            estadoConsulta: 'ok',
        ));

        $result = $this->worker->run();

        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->outcomes[ProcessingOutcome::UpsertOk->value] ?? 0);
        self::assertCount(1, $this->repo->upserts);
        self::assertSame(1, $this->meli->calls);
        self::assertSame(0, $this->queue->depth());
        self::assertSame(0, $this->queue->processingCount());
    }

    public function testNoopWhenAlreadyFresh(): void
    {
        $this->enqueue('UP-FRESH', 1, 10);
        $this->repo->markFresh(1, 10, 'UP-FRESH', $this->clock->now());

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::NoopFresh->value] ?? 0);
        self::assertSame(0, $this->meli->calls);
        self::assertCount(0, $this->repo->upserts);
    }

    public function testDiscardWhenUsuarioInactive(): void
    {
        $this->enqueue('UP-X', 1, 10);
        $this->repo->markInactive(1, 10);

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::DiscardedInactive->value] ?? 0);
        self::assertSame(0, $this->meli->calls);
    }

    public function testNotFoundPersistsEmptyAndAcks(): void
    {
        $this->enqueue('UP-404', 1, 10);
        $this->meli->willFail('UP-404', 1, 10, FakeMercadoLibreClient::notFound());

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::UpsertEmpty->value] ?? 0);
        self::assertCount(0, $this->repo->upserts);
        self::assertCount(1, $this->repo->notFound);
        self::assertSame('not_found', $this->repo->notFound[0]['estadoConsulta']);
        self::assertTrue($this->repo->notFound[0]['actualizarFechaSemanal']);
        self::assertSame(0, $this->queue->dlqDepth());
    }

    public function testAccessDeniedUpdatesOnlyEstado(): void
    {
        $this->enqueue('UP-403', 1, 10);
        $this->meli->willFail('UP-403', 1, 10, new NotFoundRecommendationException('access_denied', 403, 'access_denied'));

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::UpsertEmpty->value] ?? 0);
        self::assertCount(0, $this->repo->upserts);
        self::assertSame('access_denied', $this->repo->notFound[0]['estadoConsulta']);
        self::assertFalse($this->repo->notFound[0]['actualizarFechaSemanal']);
        self::assertSame(0, $this->queue->dlqDepth());
    }

    public function testInvalidRequestUpdatesOnlyEstado(): void
    {
        $this->enqueue('UP-400', 1, 10);
        $this->meli->willFail('UP-400', 1, 10, new NotFoundRecommendationException('invalid_request', 400, 'invalid_request'));

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::UpsertEmpty->value] ?? 0);
        self::assertCount(0, $this->repo->upserts);
        self::assertSame('invalid_request', $this->repo->notFound[0]['estadoConsulta']);
        self::assertTrue($this->repo->notFound[0]['actualizarFechaSemanal']);
        self::assertSame(0, $this->queue->dlqDepth());
    }

    public function testRetryableSchedulesBackoff(): void
    {
        $this->enqueue('UP-429', 1, 10);
        $this->meli->willFail('UP-429', 1, 10, FakeMercadoLibreClient::rateLimited());

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::RetryScheduled->value] ?? 0);
        self::assertSame(1, $this->queue->processingCount());
        self::assertSame(0, $this->queue->dlqDepth());

        // Aún no visible
        self::assertNull($this->queue->claim());

        $this->clock->advanceSeconds(30);
        self::assertSame(1, $this->queue->recoverStale($this->clock->now()->getTimestamp()));
        self::assertSame(1, $this->queue->depth());
    }

    public function testMaxAttemptsGoesToDlq(): void
    {
        $job = ReposicionJob::createNew('UP-FAIL', 1, 10, null, 'reconcile', $this->clock->now());
        // attempt ya en 2; un fallo más (attempt=3) con maxAttempts=3 → DLQ
        $job = $job->withRetry('prev1')->withRetry('prev2');
        self::assertSame(2, $job->attempt);
        $this->queue->enqueue($job);
        // dedup puede bloquear — forzar claim path: clear by using fresh queue without dedup conflict
        // Re-crear: el enqueue con same business key might fail if first enqueue of createNew happened...
        // Actually we enqueued the retried job once. Good.

        $this->meli->willFail('UP-FAIL', 1, 10, FakeMercadoLibreClient::serverError());

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::SentToDlq->value] ?? 0);
        self::assertSame(1, $this->queue->dlqDepth());
        $dlq = $this->queue->dlqJobs()[0];
        self::assertSame(3, $dlq->attempt);
    }

    public function testUnauthorizedCountsAttemptsAndStopsAtMax(): void
    {
        $this->enqueue('UP-401', 1, 10);
        $this->meli->willFail('UP-401', 1, 10, new RetryableException('http_401', httpStatus: 401));

        $first = $this->worker->run();
        self::assertSame(1, $first->outcomes[ProcessingOutcome::RetryScheduled->value] ?? 0);
        self::assertSame(0, $this->queue->dlqDepth());
        self::assertSame(1, $this->queue->processingCount());

        $this->clock->advanceSeconds(30);
        self::assertSame(1, $this->queue->recoverStale($this->clock->now()->getTimestamp()));

        $second = $this->worker->run();
        self::assertSame(1, $second->outcomes[ProcessingOutcome::RetryScheduled->value] ?? 0);
        self::assertSame(0, $this->queue->dlqDepth());

        $this->clock->advanceSeconds(60);
        self::assertSame(1, $this->queue->recoverStale($this->clock->now()->getTimestamp()));

        $third = $this->worker->run();
        self::assertSame(1, $third->outcomes[ProcessingOutcome::SentToDlq->value] ?? 0);
        self::assertSame(1, $this->queue->dlqDepth());
        self::assertSame(3, $this->queue->dlqJobs()[0]->attempt);
        self::assertSame('http_401', $this->queue->dlqJobs()[0]->lastError);
        self::assertSame(0, $this->queue->processingCount());

        $this->clock->advanceSeconds(1800);
        self::assertSame(0, $this->queue->recoverStale($this->clock->now()->getTimestamp()));
        $again = $this->worker->run();
        self::assertSame(0, $again->processed);
        self::assertSame(1, $this->queue->dlqDepth());
    }

    public function testPermanentErrorGoesToDlqImmediately(): void
    {
        $this->enqueue('UP-401', 1, 10);
        $this->meli->willFail('UP-401', 1, 10, FakeMercadoLibreClient::authError());

        $result = $this->worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::SentToDlq->value] ?? 0);
        self::assertSame(1, $this->queue->dlqDepth());
    }

    public function testRateLimitExhaustedSchedulesRetry(): void
    {
        $this->enqueue('UP-RL', 1, 10);
        // vaciar tokens y sin refill
        $limiter = new InMemoryTokenBucket($this->clock, maxTokens: 0, refillPerSecond: 0);
        $worker = new ConsumerWorker(
            $this->queue,
            $this->repo,
            $this->meli,
            $limiter,
            new MlWeeklyWindow(),
            $this->clock,
            new ConsumerConfig(maxMessagesPerRun: 1, rateLimitWaitAttempts: 1, rateLimitWaitMicros: 0, jitterSeconds: 0),
        );

        $result = $worker->run();

        self::assertSame(1, $result->outcomes[ProcessingOutcome::RetryScheduled->value] ?? 0);
        self::assertSame(0, $this->meli->calls);
    }

    private function enqueue(string $up, int $user, int $integration): void
    {
        $job = ReposicionJob::createNew($up, $user, $integration, 55, 'reconcile', $this->clock->now());
        self::assertTrue($this->queue->enqueue($job));
    }
}
