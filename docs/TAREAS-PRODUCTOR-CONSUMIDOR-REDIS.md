# División en tareas — Flujo Productor / Consumidor Redis

Basado en [`FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md`](FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md).  
Orden sugerido: **descubrimiento → persistencia → Redis base → consumidor mínimo → productor → resiliencia → operación**.

Convención de IDs: `EPIC` / `T-xx` (tarea). Cada tarea debería ser mergeable en 1–3 días de trabajo de un dev.

---

## Mapa de dependencias (resumen)

```text
T01–T04 (descubrimiento + BD)
    ↓
T05–T07 (Redis + frescura + cliente ML)
    ↓
T08–T11 (consumidor MVP)
    ↓
T12–T14 (productor)
    ↓
T15–T18 (retry, DLQ, sweeper, rate limit fino)
    ↓
T19–T22 (schedules, métricas, alertas, runbook)
```

---

## Épico 0 — Descubrimiento y contratos

### T01 — Confirmar contrato API ML
**Qué:** Validar endpoint real de planes/recomendación (URL, auth, request/response). Capturar JSON de ejemplo 200/404/403/429.  
**Done:** Documento o ticket con request, response y códigos de error acordados.  
**Depende de:** nada.

### T02 — Confirmar modelo Contabilium (wiki)
**Qué:** Nombre de tabla destino, columnas, UNIQUE, valor exacto de `TipoShipping`.  
**Done:** Spec de tabla alineada a wiki + campos ML mapeados.  
**Depende de:** T01 (ideal).

### T03 — Confirmar hora de refresh del lunes ML
**Qué:** Definir `InicioVentanaMl` (TZ + hora).  
**Done:** Función/regla documentada y compartida productor/consumidor.  
**Depende de:** nada (puede ir en paralelo).

### T04 — Medir volumen real
**Qué:** Correr `COUNT DISTINCT` de elegibles Full (query §2.3 del flujo). Estimar ETA con RPM reservado.  
**Done:** Número de UP únicos + estimación horas/días de drenado.  
**Depende de:** T02 (filtros correctos).

---

## Épico 1 — Base de datos

### T05 — Crear tabla de recomendaciones + índices
**Qué:** DDL: UNIQUE `(IDUsuario, IDIntegracion, MeLiUserProductId)`, `FechaHoraActualizacion`, campos ML, opcional `SemanaRecomendacion`. Índices para catch-up.  
**Done:** Migración aplicada en ambiente de prueba; script de rollback.  
**Depende de:** T02.

### T06 — Repositorio UPSERT + “¿está fresco?”
**Qué:** Métodos: `IsFresh(...)`, `Upsert(...)`, `UpsertEmpty(...)`, `IsUsuarioIntegracionActiva(...)`.  
**Done:** Unit/integration tests básicos con casos fresco / stale / insert.  
**Depende de:** T05, T03.

### T07 — Índices / performance de la query de elegibles
**Qué:** Revisar plan de ejecución del SELECT del productor; índices en `ConfiguracionPublicacionIntegracion` si hace falta.  
**Done:** Query de un lote (ej. TOP 5000) en tiempo aceptable documentado.  
**Depende de:** T05, T04.

---

## Épico 2 — Infra Redis y utilidades compartidas

### T08 — Keys Redis + helper de conexión
**Qué:** Constantes: `queue`, `processing`, `dedup:*`, `dlq`, `ratelimit:ml`. Config de connection string.  
**Done:** Lectura/escritura smoke test en Redis de QA.  
**Depende de:** nada (infra).

### T09 — Servicio de frescura `InicioVentanaMl`
**Qué:** Librería/util compartida (misma lógica en productor y consumidor).  
**Done:** Tests de bordes (domingo, lunes antes/después de hora refresh).  
**Depende de:** T03.

### T10 — Cliente HTTP ML (recomendación)
**Qué:** GET con token de integración; mapear DTO; excepciones tipadas (429, 5xx, 401, 403, 404).  
**Done:** Prueba contra sandbox/cuenta real de prueba; logs de requestId.  
**Depende de:** T01.

### T11 — Rate limiter (token bucket Redis)
**Qué:** `Acquire` + `Penalize` ante 429; RPM configurable.  
**Done:** Test: N workers no exceden RPM configurado.  
**Depende de:** T08.

---

## Épico 3 — Consumidor (prioridad: sin él la cola no sirve)

### T12 — Job/worker consumidor MVP (camino feliz)
**Qué:** `BRPOP` → `processing` → revalidar frescura → rate limit → GET ML → UPSERT → ACK. Procesar N mensajes por corrida o loop.  
**Done:** Encolar a mano 1 mensaje en Redis y ver fila en BD.  
**Depende de:** T06, T08, T09, T10, T11.

### T13 — Revalidaciones de negocio en consumidor
**Qué:** Usuario activo, integración no eliminada, config sigue Full / mismo UP; si no → ACK descartar.  
**Done:** Tests con usuario inactivo / integración borrada.  
**Depende de:** T12.

### T14 — Clasificación de errores ML/BD (sin DLQ aún)
**Qué:** 404 → UpsertEmpty + ACK; 429/5xx → log + dejar en processing con delay simple o re-LPUSH.  
**Done:** Tabla de errores implementada según §6 del flujo.  
**Depende de:** T12.

---

## Épico 4 — Productor

### T15 — Query paginada + checkpoint
**Qué:** Implementar SELECT con cursor; persistir checkpoint (tabla o key Redis).  
**Done:** Dos corridas consecutivas no repiten el mismo lote ya recorrido.  
**Depende de:** T07, T09.

### T16 — Encolado con dedup + backpressure
**Qué:** `SET dedup NX EX`; `LPUSH`; pausar si `LLEN(queue)` ≥ umbral. Payload JSON acordado.  
**Done:** Re-correr productor no duplica mensajes mientras dedup vive; respeta MaxColaPendiente.  
**Depende de:** T08, T15.

### T17 — Job productor programable
**Qué:** Schedule: lunes post-refresh (+ catch-up diario opcional). Parámetros BatchSize, MaxCola, TTL dedup.  
**Done:** Job corre en QA y deja mensajes en `queue`.  
**Depende de:** T16.

---

## Épico 5 — Resiliencia

### T18 — Retry con backoff + MaxAttempts → DLQ
**Qué:** `attempt++`, delay exponencial + jitter en `processing`; a MaxAttempts → `dlq`.  
**Done:** Simular 429 repetidos termina en DLQ con `lastError`.  
**Depende de:** T12, T14.

### T19 — Sweeper de `processing`
**Qué:** Job cada 30–60 s: score ≤ now → reencolar a `queue`.  
**Done:** Matar worker a mitad de proceso; mensaje reaparece y se procesa.  
**Depende de:** T12.

### T20 — Reproceso desde DLQ
**Qué:** Comando/job manual o endpoint interno: DLQ → queue con `attempt=0`.  
**Done:** Operador puede reencolar un mensaje o un lote.  
**Depende de:** T18.

---

## Épico 6 — Observabilidad y cierre

### T21 — Logs y métricas
**Qué:** Logs con `jobId`, UP, usuario, intento, resultado; métricas: profundidad cola, DLQ, 429/min, % cobertura semanal, pendientes SQL.  
**Done:** Dashboard o queries de monitoreo documentadas.  
**Depende de:** T12, T17.

### T22 — Alertas + runbook
**Qué:** Alertas DLQ >, cola no drena, 429 sostenidos; runbook (qué hacer lunes, cómo reencolar DLQ, cómo pausar productor).  
**Done:** Runbook en wiki Contabilium + alertas en staging.  
**Depende de:** T21.

### T23 — Prueba de carga / dry-run controlado
**Qué:** Correr productor+consumidor con subset de usuarios o RPM bajo; validar ETA vs T04.  
**Done:** Informe: cobertura, errores, ajuste de RPM/BatchSize.  
**Depende de:** T17, T18, T19.

---

## Cortes por sprint (sugerido)

### Sprint A — Fundación
T01, T02, T03, T04, T05, T06, T08, T09

### Sprint B — Consumidor usable
T07, T10, T11, T12, T13, T14

### Sprint C — Productor + circuito cerrado
T15, T16, T17, T18, T19

### Sprint D — Producción
T20, T21, T22, T23

---

## Qué puede ir en paralelo

| Track A (BD/API) | Track B (Redis/jobs) |
|------------------|----------------------|
| T01, T02, T05, T06, T10 | T03, T08, T09, T11 |
| Luego se juntan en T12 | |

Un dev en contrato ML/BD y otro en Redis/frescura acelera el Sprint A/B.

---

## Criterio de “MVP listo para piloto”

- [ ] T05–T06, T08–T13, T15–T17, T18–T19  
- Sin T20–T23 aún se puede pilotoear con subset; no salir a 100% del universo sin T21–T23.

---

## Fuera de alcance de estas tareas (backlog posterior)

- Cola `high` por notificaciones en vivo  
- Staging post-GET para no repetir ML en fallo de BD  
- UI de consulta de recomendaciones para el usuario Contabilium  
- Aumento formal de cuota RPM con ML  

---

## Tip de redacción en Jira/Azure DevOps

Título ejemplo:  
`[Reposicion Full] T12 — Worker consumidor MVP (Redis → ML → UPSERT)`

Descripción mínima: contexto 2 líneas + Done de arriba + link al flujo + dependencias.
