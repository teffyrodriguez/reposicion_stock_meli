#!/usr/bin/env php
<?php

declare(strict_types=1);

use Contabilium\ReposicionFull\Console\App;
use Contabilium\ReposicionFull\Producer\ProducerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['batch-size:', 'max-queue:', 'max-batches:', 'source:', 'help']) ?: [];
if (isset($opts['help'])) {
    fwrite(STDOUT, <<<'TXT'
Uso: php bin/producer.php [--batch-size=N] [--max-queue=N] [--max-batches=N] [--source=reconcile]

Lee .env, busca UP Full pendientes y los encola en Redis. Termina al acabar la pasada o al llenar la cola.

TXT);
    exit(0);
}

try {
    $app = App::boot(dirname(__DIR__));
    $sourceOpt = $opts['source'] ?? null;
    if (is_array($sourceOpt)) {
        $sourceOpt = $sourceOpt[array_key_last($sourceOpt)] ?? null;
    }
    $source = is_string($sourceOpt) ? $sourceOpt : $app->env('PRODUCER_SOURCE', 'reconcile');
    if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $source) !== 1) {
        throw new InvalidArgumentException('El source tiene que ser alfanumérico (hasta 32 caracteres).');
    }

    $result = $app->producer(new ProducerConfig(
        batchSize: App::optionInt($opts, 'batch-size', $app->intEnv('PRODUCER_BATCH_SIZE', 5000)),
        maxQueuePending: App::optionInt($opts, 'max-queue', $app->intEnv('PRODUCER_MAX_QUEUE', 20000)),
        source: $source,
        maxBatchesPerRun: App::optionInt($opts, 'max-batches', $app->intEnv('PRODUCER_MAX_BATCHES', 100)),
    ))->run();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf(
    "productor ok fetched=%d enqueued=%d dedupSkipped=%d batches=%d backpressure=%d completed=%d\n",
    $result->fetched,
    $result->enqueued,
    $result->dedupSkipped,
    $result->batches,
    $result->pausedByBackpressure ? 1 : 0,
    $result->completedUniverse ? 1 : 0,
));
