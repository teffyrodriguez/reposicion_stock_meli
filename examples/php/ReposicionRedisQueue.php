<?php
/**
 * Ejemplo didáctico — Alternativa 2: colas Redis para recomendaciones Full.
 *
 * Keys:
 *   reposicion:queue:high
 *   reposicion:queue:low
 *   reposicion:processing          (ZSET: member=JSON, score=visibleAt unix)
 *   reposicion:dedup:{hash}        (STRING NX + TTL)
 *   reposicion:dlq
 *   reposicion:ratelimit:ml        (token bucket simple)
 *
 * Requiere extensión phpredis (clase Redis).
 * Esto es un esqueleto de referencia, no un paquete de producción.
 */

declare(strict_types=1);

final class ReposicionRedisKeys
{
    public const QUEUE_HIGH   = 'reposicion:queue:high';
    public const QUEUE_LOW    = 'reposicion:queue:low';
    public const PROCESSING   = 'reposicion:processing';
    public const DLQ          = 'reposicion:dlq';
    public const RATE_LIMIT   = 'reposicion:ratelimit:ml';
    public const DEDUP_PREFIX = 'reposicion:dedup:';
}

final class ReposicionJob
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $meLiUserProductId,
        public readonly int $idUsuario,
        public readonly int $idIntegracion,
        public readonly ?int $idConfiguracionPublicacionIntegracion,
        public readonly string $source,      // notification|backfill|catchup|retry
        public readonly string $priority,    // high|low
        public readonly string $enqueuedAt,
        public int $attempt = 0,
        public ?string $lastError = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) $data['jobId'],
            meLiUserProductId: (string) $data['MeLiUserProductId'],
            idUsuario: (int) $data['IDUsuario'],
            idIntegracion: (int) $data['IDIntegracion'],
            idConfiguracionPublicacionIntegracion: isset($data['IDConfiguracionPublicacionIntegracion'])
                ? (int) $data['IDConfiguracionPublicacionIntegracion']
                : null,
            source: (string) $data['source'],
            priority: (string) $data['priority'],
            enqueuedAt: (string) $data['enqueuedAt'],
            attempt: (int) ($data['attempt'] ?? 0),
            lastError: $data['lastError'] ?? null,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'jobId' => $this->jobId,
            'MeLiUserProductId' => $this->meLiUserProductId,
            'IDUsuario' => $this->idUsuario,
            'IDIntegracion' => $this->idIntegracion,
            'IDConfiguracionPublicacionIntegracion' => $this->idConfiguracionPublicacionIntegracion,
            'source' => $this->source,
            'priority' => $this->priority,
            'enqueuedAt' => $this->enqueuedAt,
            'attempt' => $this->attempt,
            'lastError' => $this->lastError,
        ], JSON_THROW_ON_ERROR);
    }

    public function businessKey(): string
    {
        return $this->idUsuario . '|' . $this->idIntegracion . '|' . $this->meLiUserProductId;
    }

    public function dedupRedisKey(): string
    {
        return ReposicionRedisKeys::DEDUP_PREFIX . hash('sha256', $this->businessKey());
    }
}

/**
 * Productor: notificaciones (high) o reconciliación backfill/catch-up (low).
 */
final class ReposicionEnqueueService
{
    public function __construct(
        private readonly Redis $redis,
        private readonly int $dedupTtlSeconds = 1800, // 30 min
    ) {}

    /**
     * @return bool true si se encoló; false si dedup evitó el duplicado
     */
    public function enqueue(
        string $meLiUserProductId,
        int $idUsuario,
        int $idIntegracion,
        ?int $idConfiguracionPublicacionIntegracion,
        string $source,   // notification|backfill|catchup
        string $priority, // high|low
    ): bool {
        $job = new ReposicionJob(
            jobId: $this->uuidV4(),
            meLiUserProductId: $meLiUserProductId,
            idUsuario: $idUsuario,
            idIntegracion: $idIntegracion,
            idConfiguracionPublicacionIntegracion: $idConfiguracionPublicacionIntegracion,
            source: $source,
            priority: $priority,
            enqueuedAt: gmdate('c'),
            attempt: 0,
        );

        // SET NX EX: solo encola si no hay dedup activo para la misma clave de negocio
        $created = $this->redis->set(
            $job->dedupRedisKey(),
            '1',
            ['nx', 'ex' => $this->dedupTtlSeconds]
        );

        if (!$created) {
            return false; // ya estaba encolado / procesándose hace poco
        }

        $queue = $priority === 'high'
            ? ReposicionRedisKeys::QUEUE_HIGH
            : ReposicionRedisKeys::QUEUE_LOW;

        $this->redis->lPush($queue, $job->toJson());

        return true;
    }

    /** Ejemplo: desde el handler de notificaciones ML */
    public function enqueueFromNotification(
        string $meLiUserProductId,
        int $idUsuario,
        int $idIntegracion,
        int $idConfiguracionPublicacionIntegracion,
    ): bool {
        return $this->enqueue(
            $meLiUserProductId,
            $idUsuario,
            $idIntegracion,
            $idConfiguracionPublicacionIntegracion,
            source: 'notification',
            priority: 'high',
        );
    }

    /** Ejemplo: desde el job ReconciliarReposicion (backfill o catch-up) */
    public function enqueueFromReconcile(
        string $meLiUserProductId,
        int $idUsuario,
        int $idIntegracion,
        ?int $idConfiguracionPublicacionIntegracion,
        string $source = 'catchup', // backfill|catchup
    ): bool {
        return $this->enqueue(
            $meLiUserProductId,
            $idUsuario,
            $idIntegracion,
            $idConfiguracionPublicacionIntegracion,
            source: $source,
            priority: 'low',
        );
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

/**
 * Token bucket muy simple en Redis (1 key).
 * RPM efectivo = maxTokens si refillPerMinute ≈ maxTokens.
 */
final class MercadoLibreRateLimiter
{
    public function __construct(
        private readonly Redis $redis,
        private readonly int $maxTokens = 25,          // techo
        private readonly float $refillPerSecond = 25 / 60, // ~25 RPM
    ) {}

    /** Bloquea hasta obtener 1 token (con tope de espera opcional). */
    public function acquire(int $timeoutSeconds = 30): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->tryTakeOne()) {
                return true;
            }
            usleep(100_000); // 100ms
        }

        return false;
    }

    private function tryTakeOne(): bool
    {
        // Lua: rellena tokens según tiempo transcurrido y descuenta 1 si hay
        $script = <<<'LUA'
local key = KEYS[1]
local maxTokens = tonumber(ARGV[1])
local refillPerSecond = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'updatedAt')
local tokens = tonumber(data[1])
local updatedAt = tonumber(data[2])

if tokens == nil then
  tokens = maxTokens
  updatedAt = now
end

local elapsed = math.max(0, now - updatedAt)
tokens = math.min(maxTokens, tokens + (elapsed * refillPerSecond))
updatedAt = now

if tokens < 1 then
  redis.call('HMSET', key, 'tokens', tokens, 'updatedAt', updatedAt)
  redis.call('EXPIRE', key, 3600)
  return 0
end

tokens = tokens - 1
redis.call('HMSET', key, 'tokens', tokens, 'updatedAt', updatedAt)
redis.call('EXPIRE', key, 3600)
return 1
LUA;

        $ok = $this->redis->eval(
            $script,
            [
                ReposicionRedisKeys::RATE_LIMIT,
                (string) $this->maxTokens,
                (string) $this->refillPerSecond,
                (string) microtime(true),
            ],
            1
        );

        return (int) $ok === 1;
    }

    /** Penalización extra ante HTTP 429. */
    public function penalizeSeconds(int $seconds = 5): void
    {
        $this->redis->hMSet(ReposicionRedisKeys::RATE_LIMIT, [
            'tokens' => 0,
            'updatedAt' => (string) (microtime(true) + $seconds),
        ]);
        $this->redis->expire(ReposicionRedisKeys::RATE_LIMIT, 3600);
    }
}

/**
 * Worker: claim high→low, processing, UPSERT, retry/DLQ.
 */
final class ReposicionQueueWorker
{
    private const VISIBILITY_TIMEOUT = 120; // segundos
    private const MAX_ATTEMPTS = 5;
    private const BASE_BACKOFF_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 1800;

    public function __construct(
        private readonly Redis $redis,
        private readonly MercadoLibreRateLimiter $rateLimiter,
        // Inyectá tus servicios reales:
        private readonly object $meliClient,   // ->getReplenishmentRecommendation(...)
        private readonly object $repository,   // ->isFresh(...) / ->upsert(...)
        private readonly int $freshTtlSeconds = 7 * 24 * 3600,
    ) {}

    public function runOnce(): void
    {
        $payload = $this->claimNext();
        if ($payload === null) {
            return;
        }

        try {
            $job = ReposicionJob::fromArray(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
            $this->process($job);
            $this->ack($payload);
        } catch (ReposicionRetryableException $e) {
            $this->requeueWithBackoff($payload, $e->getMessage(), $e->penalizeRateLimit);
        } catch (ReposicionPermanentException $e) {
            $this->sendToDlq($payload, $e->getMessage());
        } catch (Throwable $e) {
            // Desconocido: tratar como reintentable
            $this->requeueWithBackoff($payload, $e->getMessage(), false);
        }
    }

    private function claimNext(): ?string
    {
        // Preferir high; si no hay, low (BRPOP bloquea hasta 1s en cada una)
        $result = $this->redis->brPop(
            [ReposicionRedisKeys::QUEUE_HIGH, ReposicionRedisKeys::QUEUE_LOW],
            1
        );

        if (!$result || count($result) < 2) {
            return null;
        }

        $payload = $result[1];
        $visibleAt = time() + self::VISIBILITY_TIMEOUT;
        $this->redis->zAdd(ReposicionRedisKeys::PROCESSING, $visibleAt, $payload);

        return $payload;
    }

    private function process(ReposicionJob $job): void
    {
        // 1) ¿Ya fresco en BD? → noop
        if ($this->repository->isFresh(
            $job->idUsuario,
            $job->idIntegracion,
            $job->meLiUserProductId,
            $this->freshTtlSeconds
        )) {
            return;
        }

        // 2) Rate limit antes de tocar ML
        if (!$this->rateLimiter->acquire(30)) {
            throw new ReposicionRetryableException('rate_limiter_timeout');
        }

        // 3) GET Mercado Libre
        try {
            $data = $this->meliClient->getReplenishmentRecommendation(
                $job->idUsuario,
                $job->idIntegracion,
                $job->meLiUserProductId
            );
        } catch (MeliRateLimitException $e) {
            $this->rateLimiter->penalizeSeconds(5);
            throw new ReposicionRetryableException('meli_429', true);
        } catch (MeliTransientException $e) {
            throw new ReposicionRetryableException('meli_transient: ' . $e->getMessage());
        } catch (MeliAuthException $e) {
            throw new ReposicionPermanentException('meli_auth: ' . $e->getMessage());
        } catch (MeliNotFoundException $e) {
            // Negocio: persistir "sin recomendación" y no reintentar en loop
            $this->repository->upsertEmpty(
                $job->idUsuario,
                $job->idIntegracion,
                $job->meLiUserProductId,
                $job->idConfiguracionPublicacionIntegracion
            );
            return;
        }

        // 4) UPSERT BD
        try {
            $this->repository->upsert(
                $job->idUsuario,
                $job->idIntegracion,
                $job->meLiUserProductId,
                $job->idConfiguracionPublicacionIntegracion,
                $data
            );
        } catch (DbTransientException $e) {
            throw new ReposicionRetryableException('db_transient: ' . $e->getMessage());
        }
    }

    private function ack(string $payload): void
    {
        $this->redis->zRem(ReposicionRedisKeys::PROCESSING, $payload);
    }

    private function requeueWithBackoff(string $payload, string $error, bool $penalizeRateLimit = false): void
    {
        if ($penalizeRateLimit) {
            $this->rateLimiter->penalizeSeconds(5);
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $job = ReposicionJob::fromArray($data);
        $job->attempt++;
        $job->lastError = mb_substr($error, 0, 500);

        // Sacar del processing actual
        $this->redis->zRem(ReposicionRedisKeys::PROCESSING, $payload);

        if ($job->attempt >= self::MAX_ATTEMPTS) {
            $this->pushDlq($job);
            return;
        }

        // Backoff exponencial + jitter → queda en processing hasta visibleAt
        $delay = min(
            self::MAX_BACKOFF_SECONDS,
            self::BASE_BACKOFF_SECONDS * (2 ** max(0, $job->attempt - 1))
        );
        $delay += random_int(0, 10);

        $newPayload = $job->toJson();
        // source conceptual: retry (queda reflejado si actualizás el campo en toJson; acá attempt/lastError bastan)
        $visibleAt = time() + $delay;
        $this->redis->zAdd(ReposicionRedisKeys::PROCESSING, $visibleAt, $newPayload);
    }

    private function sendToDlq(string $payload, string $error): void
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $job = ReposicionJob::fromArray($data);
        $job->lastError = mb_substr($error, 0, 500);

        $this->redis->zRem(ReposicionRedisKeys::PROCESSING, $payload);
        $this->pushDlq($job);
    }

    private function pushDlq(ReposicionJob $job): void
    {
        $this->redis->lPush(ReposicionRedisKeys::DLQ, $job->toJson());
        // TODO: métrica/alerta — LLEN dlq creciendo
    }

    /**
     * Sweeper: mensajes cuyo visibility timeout venció vuelven a la cola.
     * Correr cada 30s en un cron/comando aparte.
     */
    public function recoverStaleProcessing(): int
    {
        $now = time();
        $stale = $this->redis->zRangeByScore(ReposicionRedisKeys::PROCESSING, '-inf', (string) $now);

        $recovered = 0;
        foreach ($stale as $payload) {
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                $this->redis->zRem(ReposicionRedisKeys::PROCESSING, $payload);
                $this->redis->lPush(ReposicionRedisKeys::DLQ, $payload);
                continue;
            }

            $priority = $data['priority'] ?? 'low';
            $queue = $priority === 'high'
                ? ReposicionRedisKeys::QUEUE_HIGH
                : ReposicionRedisKeys::QUEUE_LOW;

            $this->redis->lPush($queue, $payload);
            $this->redis->zRem(ReposicionRedisKeys::PROCESSING, $payload);
            $recovered++;
        }

        return $recovered;
    }
}

/** Errores de dominio del worker */
class ReposicionRetryableException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $penalizeRateLimit = false)
    {
        parent::__construct($message);
    }
}

class ReposicionPermanentException extends RuntimeException {}

/** Stubs de excepciones ML/BD para el ejemplo */
class MeliRateLimitException extends RuntimeException {}
class MeliTransientException extends RuntimeException {}
class MeliAuthException extends RuntimeException {}
class MeliNotFoundException extends RuntimeException {}
class DbTransientException extends RuntimeException {}

// -----------------------------------------------------------------------------
// Ejemplo de uso (pseudo-bootstrap)
// -----------------------------------------------------------------------------

/*
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$enqueue = new ReposicionEnqueueService($redis);
$limiter = new MercadoLibreRateLimiter($redis, maxTokens: 25, refillPerSecond: 25/60);

// 1) Notificación Full → cola high
$enqueue->enqueueFromNotification(
    meLiUserProductId: 'MLAU12345678',
    idUsuario: 98765,
    idIntegracion: 4321,
    idConfiguracionPublicacionIntegracion: 555001,
);

// 2) Catch-up / backfill → cola low
$enqueue->enqueueFromReconcile(
    meLiUserProductId: 'MLAU99999999',
    idUsuario: 98765,
    idIntegracion: 4321,
    idConfiguracionPublicacionIntegracion: null,
    source: 'catchup',
);

// 3) Worker loop
$worker = new ReposicionQueueWorker($redis, $limiter, $meliClient, $repository);
while (true) {
    $worker->runOnce();
}

// 4) Cron cada 30s
$worker->recoverStaleProcessing();

// 5) Inspeccionar DLQ
// $redis->lRange('reposicion:dlq', 0, 49);
*/
