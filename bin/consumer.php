#!/usr/bin/env php
<?php

declare(strict_types=1);

use Contabilium\ReposicionFull\Console\App;
use Contabilium\ReposicionFull\Consumer\ConsumerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['once', 'max-messages:', 'sleep:', 'help']) ?: [];
if (isset($opts['help'])) {
    fwrite(STDOUT, <<<'TXT'
Uso: php bin/consumer.php [--once] [--max-messages=N] [--sleep=N]

Lee .env, saca mensajes de Redis, consulta Mercado Libre y hace el UPSERT.
Con ML_MOCK=1 no llama a la API: usa el JSON de ML_MOCK_FILE.
Sin --once queda en loop: si la cola está vacía espera --sleep segundos (default 30).
En cada vuelta también reencola lo vencido en processing.

TXT);
    exit(0);
}

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
}

try {
    $app = App::boot(dirname(__DIR__));
    $once = isset($opts['once']);
    //lee cuánto debe esperar, cuántos mensajes tomar y cuántas veces reintentar, y con eso arma el worker.
    $idleSeconds = App::optionInt($opts, 'sleep', $app->intEnv('CONSUMER_IDLE_SECONDS', 30, 0), 0);
    $worker = $app->consumer(new ConsumerConfig(
        maxMessagesPerRun: App::optionInt($opts, 'max-messages', $app->intEnv('CONSUMER_MAX_MESSAGES', 100)),
        maxAttempts: $app->intEnv('CONSUMER_MAX_ATTEMPTS', 5),
        visibilityTimeoutSeconds: $app->intEnv('CONSUMER_VISIBILITY_TIMEOUT', 120),
    ));

    do {
        $processed = 0;
        try {
            $stale = $worker->recoverStale();
            $result = $worker->run();
            $processed = $result->processed;
            $parts = ["processed={$processed}", "staleRecovered={$stale}"];
            foreach ($result->outcomes as $name => $count) {
                $parts[] = $name . '=' . $count;
            }
            fwrite(STDOUT, 'consumidor ' . implode(' ', $parts) . PHP_EOL);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            if ($once) {
                exit(1);
            }
        }

        if ($once || $stop) {
            break;
        }
        if ($processed === 0) {
            sleep($idleSeconds);
        }
    } while (!$stop);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
