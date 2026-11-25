<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Consumer;

use Contabilium\ReposicionFull\Contracts\ClockInterface;
use Contabilium\ReposicionFull\Contracts\MercadoLibreClientInterface;
use Contabilium\ReposicionFull\Contracts\QueueInterface;
use Contabilium\ReposicionFull\Contracts\RateLimiterInterface;
use Contabilium\ReposicionFull\Contracts\RecommendationRepositoryInterface;
use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\PermanentException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;
use Contabilium\ReposicionFull\Domain\ReposicionJob;
use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Worker consumidor: Redis → (frescura/activo) → rate limit → GET ML → UPSERT.
 * Reintentos con backoff; MaxAttempts → DLQ.
 */
final class ConsumerWorker
{
    public function __construct(
        private readonly QueueInterface $queue,
        private readonly RecommendationRepositoryInterface $recommendations,
        private readonly MercadoLibreClientInterface $meliClient,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly MlWeeklyWindow $weeklyWindow,
        private readonly ClockInterface $clock,
        private readonly ConsumerConfig $config = new ConsumerConfig(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(): ConsumerResult
    {
        $outcomes = [];
        $processed = 0;
        $this->logger->info('maximo de mensajes por corrida: ', [$this->config->maxMessagesPerRun]);
        for ($i = 0; $i < $this->config->maxMessagesPerRun; $i++) {
            $job = $this->queue->claim(
                $this->clock->now()->getTimestamp() + $this->config->visibilityTimeoutSeconds
            );
            $this->logger->info("procesar: ", (array)$job);
            if ($job === null) {
                break;
            }

            $processed++;
            $outcome = $this->processOne($job);
            var_dump("outcome",$outcome);
            $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;
        }

        return new ConsumerResult(processed: $processed, outcomes: $outcomes);
    }

    public function recoverStale(): int
    {
        $n = $this->queue->recoverStale($this->clock->now()->getTimestamp());
        if ($n > 0) {
            $this->logger->warning('reposicion.consumer.recover_stale', ['count' => $n]);
        }

        return $n;
    }

    private function processOne(ReposicionJob $job): ProcessingOutcome
    {
        //lunes semanal
        $windowStart = $this->weeklyWindow->start($this->clock->now());

        try {
            if (!$this->recommendations->isUsuarioIntegracionActiva($job->idUsuario, $job->idIntegracion)) {
                var_dump("USUARIO O INTEGRACION INACTIVO");
                $this->queue->ack($job);
                $this->log($job, ProcessingOutcome::DiscardedInactive);

                return ProcessingOutcome::DiscardedInactive;
            }

            if ($this->recommendations->isFresh(
                $job->idUsuario,
                $job->idIntegracion,
                $job->meLiUserProductId,
                $windowStart,
            )) {
                $this->queue->ack($job);
                $this->log($job, ProcessingOutcome::NoopFresh);

                return ProcessingOutcome::NoopFresh;
            }

            $this->acquireRateLimitOrFail();

            try {
                $recommendation = $this->meliClient->getReplenishmentRecommendation(
                    $job->idUsuario,
                    $job->idIntegracion,
                    $job->meLiUserProductId,
                );
                $this->logger->warning('recommendation', [$recommendation]);
            } catch (NotFoundRecommendationException $e) {
                $estado = $e->estadoConsulta ?? match ($e->httpStatus) {
                    404 => 'not_found',
                    403 => 'access_denied',
                    default => null,
                };
                if ($estado !== null) {
                    $this->recommendations->markEstadoConsulta(
                        $job->idUsuario,
                        $job->idIntegracion,
                        $job->meLiUserProductId,
                        $estado,
                        $this->clock->now(),
                        $windowStart,
                        actualizarFechaSemanal: $e->httpStatus !== 403,
                    );
                } else {
                    $this->recommendations->upsert(
                        $job->idUsuario,
                        $job->idIntegracion,
                        $job->meLiUserProductId,
                        $job->idConfiguracionPublicacionIntegracion,
                        ReplenishmentRecommendation::empty($job->meLiUserProductId),
                        $this->clock->now(),
                        $windowStart,
                    );
                }
                $this->queue->ack($job);
                $this->log($job, ProcessingOutcome::UpsertEmpty);

                return ProcessingOutcome::UpsertEmpty;
            }

            //GUARDA EN BD
            $this->recommendations->upsert(
                $job->idUsuario,
                $job->idIntegracion,
                $job->meLiUserProductId,
                $job->idConfiguracionPublicacionIntegracion,
                $recommendation,
                $this->clock->now(),
                $windowStart,
            );
            $this->queue->ack($job);
            $this->log($job, ProcessingOutcome::UpsertOk);

            return ProcessingOutcome::UpsertOk;
        } catch (RetryableException $e) {
            if ($e->penalizeRateLimit) {
                $this->rateLimiter->penalize(5);
            }

            return $this->handleRetry($job, $e->getMessage());
        } catch (PermanentException $e) {
            $failed = $job->withRetry($e->getMessage());
            $this->queue->sendToDlq($failed);
            $this->log($failed, ProcessingOutcome::SentToDlq);

            return ProcessingOutcome::SentToDlq;
        } catch (\Throwable $e) {
            return $this->handleRetry($job, $e->getMessage());
        }
    }

    private function acquireRateLimitOrFail(): void
    {
        for ($i = 0; $i < $this->config->rateLimitWaitAttempts; $i++) {
            if ($this->rateLimiter->tryAcquire()) {
                return;
            }
            usleep($this->config->rateLimitWaitMicros);
        }

        throw new RetryableException('rate_limiter_exhausted', penalizeRateLimit: false);
    }

    private function handleRetry(ReposicionJob $job, string $error): ProcessingOutcome
    {
        $retried = $job->withRetry($error);

        if ($retried->attempt >= $this->config->maxAttempts) {
            $this->queue->sendToDlq($retried);
            $this->log($retried, ProcessingOutcome::SentToDlq);

            return ProcessingOutcome::SentToDlq;
        }

        $delay = $this->backoffSeconds($retried->attempt);
        $visibleAt = $this->clock->now()->getTimestamp() + $delay;
        $this->queue->scheduleRetry($retried, $visibleAt);
        $this->log($retried, ProcessingOutcome::RetryScheduled, ['delaySeconds' => $delay]);

        return ProcessingOutcome::RetryScheduled;
    }

    private function backoffSeconds(int $attempt): int
    {
        $exp = $this->config->baseBackoffSeconds * (2 ** max(0, $attempt - 1));
        $capped = min($this->config->maxBackoffSeconds, $exp);
        $jitter = $this->config->jitterSeconds > 0
            ? random_int(0, $this->config->jitterSeconds)
            : 0;

        return $capped + $jitter;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function log(ReposicionJob $job, ProcessingOutcome $outcome, array $extra = []): void
    {
        $this->logger->info('reposicion.consumer.message', array_merge([
            'jobId' => $job->jobId,
            'IDUsuario' => $job->idUsuario,
            'IDIntegracion' => $job->idIntegracion,
            'MeLiUserProductId' => $job->meLiUserProductId,
            'attempt' => $job->attempt,
            'outcome' => $outcome->value,
            'lastError' => $job->lastError,
        ], $extra));
    }
}
