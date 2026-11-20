# Desarrollo: Productor y Consumidor — Reposición Full ML

**Fecha:** 2 nov 2026 (America/Buenos_Aires)

Implementación PHP del flujo documentado en [`FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md`](FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md).

---

## Cómo correr

```bash
composer install
copy .env.example .env
php bin/producer.php
php bin/consumer.php
php bin/consumer.php --once
composer test
```

---

## Arquitectura

```text
ProducerJob                     ConsumerWorker
    │                                 │
    ├─ EligibleProductRepository      ├─ QueueInterface (claim/ack/retry/dlq)
    ├─ QueueInterface (enqueue+dedup) ├─ RecommendationRepository
    ├─ CheckpointStore                ├─ MercadoLibreClient
    └─ MlWeeklyWindow                 ├─ RateLimiter (token bucket)
                                      └─ MlWeeklyWindow
```

| Componente | Responsabilidad |
|------------|-----------------|
| `ProducerJob` | SQL pendientes → Redis; no llama ML |
| `ConsumerWorker` | Redis → validaciones → rate limit → GET ML → UPSERT |
| `MlWeeklyWindow` | Frescura por lunes de refresh ML |
| `InMemoryTokenBucket` / `PhpRedisTokenBucket` | Rate limit (RPM reservado al proceso) |
| `InMemoryQueue` / `PhpRedisQueue` | Cola, processing, dedup, DLQ |

---

## Rate limit

Según documentación ML, el control principal es por **Client ID** y endpoint; ante **HTTP 429** hay que aplicar backoff. Endpoints de stock relacionados documentan del orden de **~100 RPM** (confirmar el de planes).

En este código:

1. Token bucket compartido (`reposicion:ratelimit:ml` en Redis).
2. Default de ejemplo: **25 RPM** reservados a este proceso (no usar el 100% de la app).
3. Antes de cada GET: `tryAcquire()`.
4. Si ML responde **429**: `penalize(5)` + retry con backoff del mensaje.
5. Configurable vía constructor del bucket (`maxTokens`, `refillPerSecond`).

> Si tenés una imagen/tabla oficial de cuotas del endpoint de planes, ajustá esos dos números a ese valor y al % que quieran reservar.

---

## Reintentos

| Error | Acción |
|-------|--------|
| 429 / 5xx / timeout / rate limiter exhausted | `attempt++`, backoff exponencial + jitter, queda en `processing` hasta `visibleAt` |
| `attempt >= maxAttempts` (default 5) | **DLQ** |
| 401 permanente | **DLQ** |
| 404 / sin recomendación | UPSERT vacío + ACK (no loop) |
| Ya fresco / user inactivo | ACK noop / descartar |

Backoff: `min(maxBackoff, base * 2^(attempt-1)) + jitter`  
Default: base 30s, cap 1800s.

El **sweeper** (`recoverStale`) reencola processing vencido (fin de backoff o worker caído).

---

## Explicación del proceso (operación semanal)

1. **Lunes post-refresh ML** (o martes AM): corre el **productor**.
2. Calcula `InicioVentanaMl` (último lunes de refresh).
3. Pagina UP Full elegibles sin fila o con `FechaHoraActualizacion < ventana`.
4. Por cada uno: `SET dedup NX` → si OK, `LPUSH` a `reposicion:queue`.
5. Si la cola supera `maxQueuePending`, para (backpressure) y retoma después.
6. En paralelo/continuo, el **consumidor** hace claim de mensajes:
   - Si integración/usuario inactivos → descarta.
   - Si ya fresco esta semana → noop.
   - Si no → pide token de rate limit → GET ML → UPSERT + `FechaHoraActualizacion`.
7. Fallos recuperables reencolan con delay; definitivos van a DLQ.
8. Durante la semana, catch-up del productor solo encola lo que sigue stale.

---

## Integración en Contabilium

1. Implementar `EligibleProductRepositoryInterface` (ver `SqlEligibleProductRepository.example.php`).
2. Implementar `RecommendationRepositoryInterface` (UPSERT real).
3. Implementar `MercadoLibreClientInterface` (HTTP + tokens).
4. En producción usar `PhpRedisQueue` + `PhpRedisTokenBucket` (extensión phpredis).
5. Programar:
   - Job productor: lunes + diario catch-up.
   - Job consumidor: cada 30–60 s (o worker daemon).
   - Job sweeper: cada 30–60 s → `recoverStale()`.

---

## Tests

| Suite | Cobertura |
|-------|-----------|
| `MlWeeklyWindowTest` | Ventana lunes / hora refresh |
| `InMemoryTokenBucketTest` | Cap, refill, penalize 429 |
| `InMemoryQueueTest` | Dedup, claim, stale, DLQ |
| `ProducerJobTest` | Encolado, backpressure, checkpoint |
| `ConsumerWorkerTest` | Happy path, noop, 404, 429 retry, max→DLQ, 401, rate limit |

---

## Estructura de archivos

```text
src/
  Domain/           Job, recommendation, exceptions
  Contracts/        Interfaces
  Freshness/        MlWeeklyWindow
  RateLimit/        InMemoryTokenBucket
  Queue/            InMemoryQueue, CheckpointStore
  Producer/         ProducerJob
  Consumer/         ConsumerWorker
  Infrastructure/   PhpRedis*, SQL example
  Testing/          Fakes para tests
  Support/          Clock, ArrayLogger
tests/
docs/DESARROLLO-PRODUCTOR-CONSUMIDOR.md
```
