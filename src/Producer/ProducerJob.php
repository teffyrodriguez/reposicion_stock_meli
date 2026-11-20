<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Producer;

use Contabilium\ReposicionFull\Contracts\CheckpointStoreInterface;
use Contabilium\ReposicionFull\Contracts\ClockInterface;
use Contabilium\ReposicionFull\Contracts\EligibleProductRepositoryInterface;
use Contabilium\ReposicionFull\Contracts\QueueInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;
use Contabilium\ReposicionFull\Domain\ReposicionJob;
use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Job productor: SQL elegibles → Redis (sin llamar a ML).
 */
final class ProducerJob
{
    public function __construct(
        private readonly EligibleProductRepositoryInterface $eligibleRepository,
        private readonly QueueInterface $queue,
        private readonly CheckpointStoreInterface $checkpoint,
        private readonly MlWeeklyWindow $weeklyWindow,
        private readonly ClockInterface $clock,
        private readonly ProducerConfig $config = new ProducerConfig(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(): ProducerResult
    {
        $windowStart = $this->weeklyWindow->start($this->clock->now());
        $cursor = $this->checkpoint->get();
        var_dump($windowStart, $cursor);
        $fetched = 0;
        $enqueued = 0;
        $dedupSkipped = 0;
        $batches = 0;
        $pausedByBackpressure = false;
        $completedUniverse = false;

        $this->logger->info('reposicion.producer.start', [
            'windowStart' => $windowStart->format(\DateTimeInterface::ATOM),
            'queueDepth' => $this->queue->depth(),
        ]);

        while ($batches < $this->config->maxBatchesPerRun) {
            var_dump("contador: ".$batches);
            if ($this->queue->depth() >= $this->config->maxQueuePending) {
                $pausedByBackpressure = true;
                $this->logger->warning('reposicion.producer.backpressure', [
                    'queueDepth' => $this->queue->depth(),
                    'max' => $this->config->maxQueuePending,
                ]);
                var_dump("paso 1 - break");
                break;
            }
            var_dump("busca en BD");
            var_dump($windowStart,
                $this->config->batchSize,
                $cursor);
            $rows = $this->eligibleRepository->fetchPendingBatch(
                $windowStart,
                $this->config->batchSize,
                $cursor,
            );
            var_dump($rows);

            if ($rows === []) {
                $completedUniverse = true;
                $this->checkpoint->clear();
                var_dump("paso 2 - break");
                break;
            }

            $batches++;
            $last = null;

            foreach ($rows as $product) {
                $fetched++;
                $job = ReposicionJob::createNew(
                    meLiUserProductId: $product->meLiUserProductId,
                    idUsuario: $product->idUsuario,
                    idIntegracion: $product->idIntegracion,
                    idConfiguracionPublicacionIntegracion: $product->idConfiguracionPublicacionIntegracion,
                    source: $this->config->source,
                    now: $this->clock->now(),
                );

                if ($this->queue->enqueue($job)) {
                    $enqueued++;
                } else {
                    $dedupSkipped++;
                }

                $last = $product;
                var_dump("paso 3 - encolar");
            }

            if ($last instanceof EligibleProduct) {
                $this->checkpoint->save($last);
                $cursor = $last;
                var_dump("paso 4 - elegible product");
            }
        }

        $result = new ProducerResult(
            fetched: $fetched,
            enqueued: $enqueued,
            dedupSkipped: $dedupSkipped,
            batches: $batches,
            pausedByBackpressure: $pausedByBackpressure,
            completedUniverse: $completedUniverse,
        );
        var_dump("paso 5 - result");

        $this->logger->info('reposicion.producer.done', [
            'fetched' => $result->fetched,
            'enqueued' => $result->enqueued,
            'dedupSkipped' => $result->dedupSkipped,
            'batches' => $result->batches,
            'pausedByBackpressure' => $result->pausedByBackpressure,
            'completedUniverse' => $result->completedUniverse,
        ]);

        return $result;
    }
}
