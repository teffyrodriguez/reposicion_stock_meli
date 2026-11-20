# Reposición Full — Mercado Libre (Productor + Consumidor Redis)

Paquete PHP con el **job productor** (SQL → Redis) y el **worker consumidor** (Redis → API ML → BD), rate limit, reintentos/DLQ, tests unitarios y documentación de arquitectura.

## Arranque rápido

```bash
composer install
copy .env.example .env
```

Completá `.env` (Redis, SQL Server y, para el consumidor, la ruta del GET). El access token se lee de `ParametrosIntegracion` por cada mensaje. Hacen falta las extensiones `phpredis`, `pdo_sqlsrv` y `curl`.

```bash
php bin/producer.php
php bin/consumer.php
php bin/consumer.php --once
```

El productor corre una pasada y termina. El consumidor, sin `--once`, queda en loop. `--help` en cada script lista las opciones.

Tests: `composer test`.

## Componentes principales

| Clase | Rol |
|-------|-----|
| `Producer\ProducerJob` | Busca UP Full pendientes y encola en Redis (dedup + backpressure) |
| `Consumer\ConsumerWorker` | Consume cola, respeta frescura/lunes ML, rate limit, GET ML, UPSERT, retry/DLQ |
| `Freshness\MlWeeklyWindow` | Calcula inicio de ventana (refresh semanal los lunes) |
| `RateLimit\*` / `Infrastructure\Redis\PhpRedisTokenBucket` | Token bucket (RPM configurable; ref. ML ~100 RPM) |
| `Queue\InMemoryQueue` / `Infrastructure\Redis\PhpRedisQueue` | Cola, processing, dedup, DLQ |

## Documentación

| Doc | Contenido |
|-----|-----------|
| [`docs/DESARROLLO-PRODUCTOR-CONSUMIDOR.md`](docs/DESARROLLO-PRODUCTOR-CONSUMIDOR.md) | Cómo funciona el código, rate limit, reintentos, integración |
| [`docs/FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md`](docs/FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md) | Flujo + query SQL + errores |
| [`docs/TAREAS-PRODUCTOR-CONSUMIDOR-REDIS.md`](docs/TAREAS-PRODUCTOR-CONSUMIDOR-REDIS.md) | División en tareas |
| [`docs/ANALISIS-ALTERNATIVAS-REPOSICION-ML-FULL.md`](docs/ANALISIS-ALTERNATIVAS-REPOSICION-ML-FULL.md) | Análisis de alternativas |
| [`docs/DISENO-DETALLADO-ALTERNATIVA-2.md`](docs/DISENO-DETALLADO-ALTERNATIVA-2.md) | Diseño Redis detallado |

## Rate limit

El límite de ML aplica principalmente por **Client ID**. Este proceso usa un **token bucket** compartido (default ejemplo **25 RPM** reservados). Ante **HTTP 429** se penaliza el bucket y el mensaje reintenta con backoff. Ajustá `maxTokens` / `refillPerSecond` a la cuota real del endpoint de planes.

## Explicación corta del proceso

1. **Productor** (lun/diario): query de Full no actualizados desde el lunes ML → encola en Redis.  
2. **Consumidor** (continuo): saca mensaje → si fresco/inactivo skip → rate limit → GET ML → UPSERT.  
3. Fallos recuperables → retry/backoff; agotados → **DLQ**.

## Integración Contabilium

Implementar las interfaces en `Contracts\` (SQL, cliente ML, UPSERT). En producción usar `PhpRedisQueue` + `PhpRedisTokenBucket` (extensión `phpredis`). Ver ejemplo SQL en `src/Infrastructure/Sql/SqlEligibleProductRepository.example.php`.
