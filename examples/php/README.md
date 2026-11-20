# Ejemplo PHP — colas Redis (Alternativa 2)

Archivo: [`ReposicionRedisQueue.php`](ReposicionRedisQueue.php)

Ilustra el uso de:

| Key | Rol |
|-----|-----|
| `reposicion:queue:high` | Notificaciones en vivo |
| `reposicion:queue:low` | Backfill / catch-up |
| `reposicion:processing` | Mensajes en vuelo + delayed retry |
| `reposicion:dedup:*` | Anti-duplicado al encolar |
| `reposicion:dlq` | Fallidos definitivos |
| `reposicion:ratelimit:ml` | Token bucket hacia ML |

## Requisitos

- PHP 8.1+
- Extensión [phpredis](https://github.com/phpredis/phpredis)

## Flujo rápido

1. `ReposicionEnqueueService` encola con `SET NX` en `dedup:*`.
2. `ReposicionQueueWorker::runOnce()` hace `BRPOP` high→low, mueve a `processing`.
3. Si OK → saca de `processing`.
4. Si error reintentable → `attempt++`, backoff en `processing` hasta `visibleAt`.
5. Si `attempt >= 5` o error permanente → `LPUSH` a `dlq`.
6. `recoverStaleProcessing()` reencola lo que venció en `processing` (worker caído).

`meliClient` y `repository` son stubs a reemplazar por tus servicios Contabilium.

Diseño conceptual: [`docs/DISENO-DETALLADO-ALTERNATIVA-2.md`](../../docs/DISENO-DETALLADO-ALTERNATIVA-2.md)
