# Diseño detallado — Alternativa 2 (Redis + backfill/catch-up + worker)

**Fecha:** 26 oct 2026 (America/Buenos_Aires)  
**Alcance:** cómo se encola en Redis, cómo se procesa, cómo se reintenta ante fallos, y cómo conviven backfill inicial y catch-up.

---

## 1. Vista general de componentes

```text
┌─────────────────────┐     ┌──────────────────────┐
│ Notificaciones ML   │     │ ReconciliarReposicion │
│ (proceso existente) │     │ (backfill + catch-up) │
└──────────┬──────────┘     └──────────┬───────────┘
           │ prioridad ALTA            │ prioridad BAJA
           │ drip-feed + checkpoint    │
           └────────────┬──────────────┘
                        ▼
              ┌───────────────────┐
              │ Redis             │
              │ - cola alta       │
              │ - cola baja       │
              │ - dedup keys      │
              │ - processing / DLQ│
              └─────────┬─────────┘
                        ▼
              ┌───────────────────┐
              │ Worker(s)         │
              │ 1) claim mensaje  │
              │ 2) ¿fresco? skip  │
              │ 3) GET ML         │
              │ 4) UPSERT BD      │
              │ 5) ACK / retry/DLQ│
              └─────────┬─────────┘
                        ▼
              ┌───────────────────┐
              │ Tabla recomendaciones│
              │ + FechaHoraActualizacion
              │ (+ staging / errores)│
              └───────────────────┘
```

**Regla de oro:** un solo processor (`ProcesarReposicion`). Notificaciones y reconciliación solo **producen**; los workers **consumen**.

---

## 2. Payload en Redis (qué se guarda en la cola)

### 2.1 Mensaje de trabajo (JSON)

No se guarda la respuesta de Mercado Libre en la cola (salvo staging post-API; ver §6). En la cola solo va la **clave de negocio** para saber qué consultar:

```json
{
  "schemaVersion": 1,
  "jobId": "b7c2e0a1-4f3d-4a9e-9c1a-2d8f6e0a1b2c",
  "MeLiUserProductId": "MLAU12345678",
  "IDUsuario": 98765,
  "IDIntegracion": 4321,
  "IDConfiguracionPublicacionIntegracion": 555001,
  "source": "notification",
  "priority": "high",
  "enqueuedAt": "2026-10-26T18:30:00.000Z",
  "attempt": 0,
  "lastError": null
}
```

| Campo | Uso |
|-------|-----|
| `jobId` | Idempotencia / trazabilidad de logs |
| `MeLiUserProductId` | Qué consultar en ML |
| `IDUsuario` / `IDIntegracion` | Scope Contabilium + token de la integración |
| `IDConfiguracionPublicacionIntegracion` | Trazabilidad; no es la clave de UPSERT si el mismo UP está en varias pubs |
| `source` | `notification` \| `backfill` \| `catchup` \| `retry` |
| `priority` | `high` \| `low` |
| `attempt` | Intentos ya realizados (el worker lo incrementa al reencolar) |
| `enqueuedAt` | Métricas de lag |

**Clave de negocio (dedup / tabla destino):**  
`(IDUsuario, IDIntegracion, MeLiUserProductId)`

### 2.2 Estructuras Redis recomendadas

Usar **listas + claim con visibility timeout** (patrón tipo reliable queue), o Streams (`XADD` / `XREADGROUP`) si ya es el estándar Contabilium. Ejemplo con listas (simple y explícito):

| Key | Tipo | Contenido |
|-----|------|-----------|
| `reposicion:queue:high` | LIST | mensajes JSON (LPUSH / BRPOP) |
| `reposicion:queue:low` | LIST | mensajes JSON backfill/catch-up |
| `reposicion:processing` | ZSET | `member=JSON`, `score=visible_at_unix` (en vuelo) |
| `reposicion:dedup:{hash}` | STRING | `1` con TTL (ej. 15–60 min) — evita encolar duplicados |
| `reposicion:dlq` | LIST | agotaron reintentos |
| `reposicion:ratelimit:ml` | STRING / token bucket | cuota global del proceso |

`hash` del dedup = hash de `(IDUsuario, IDIntegracion, MeLiUserProductId)`.

### 2.3 Encolar (algoritmo del productor)

```text
clave = (IDUsuario, IDIntegracion, MeLiUserProductId)
dedupKey = reposicion:dedup:{hash(clave)}

SI SET dedupKey NX EX 1800  → OK (no había duplicado reciente)
  armar mensaje (attempt=0)
  SI source == notification → LPUSH reposicion:queue:high
  SI NO                   → LPUSH reposicion:queue:low
SI NO (ya existe dedup)
  no encolar (otro job equivalente ya está pendiente o se encoló hace poco)
```

**Notificaciones:** encolar siempre que sea Full (el worker decide si está fresco).  
**Reconciliación:** solo encolar si falta fila o `FechaHoraActualizacion` &lt; umbral (evita ruido).

---

## 3. Productores

### 3.1 Notificaciones (prioridad alta)

En el proceso existente de notificaciones de publicaciones:

1. Resolver publicación → `ConfiguracionPublicacionIntegracion`.
2. Si `TipoShipping` es Full y hay `MeLiUserProductId`, y usuario activo / integración no eliminada:
3. Encolar payload (§2.3) en `queue:high`.

No llamar a ML acá: solo encolar.

### 3.2 ReconciliarReposicion (backfill + catch-up = **mismo job**)

Un solo proceso parametrizado:

| Parámetro | Backfill inicial | Catch-up diario |
|-----------|------------------|-----------------|
| Frecuencia | Continuo hasta cobertura | Cron diario / cada N horas |
| Filtro | Sin fila **o** cualquier edad | Sin fila **o** más viejo que TTL (ej. 7 días) |
| Cola | `queue:low` | `queue:low` |
| `MaxPendientesLow` | ej. 20.000 | ej. 5.000–10.000 |
| Checkpoint | Cursor `(IDUsuario, IDIntegracion, MeLiUserProductId)` | Mismo |

Flujo del feeder:

```text
loop:
  pendientes = LLEN(queue:low) + count(processing low)
  SI pendientes >= MaxPendientesLow → sleep / WAIT
  SELECT TOP BatchSize candidatos DISTINCT ... LEFT JOIN recomendaciones
    WHERE missing OR FechaHoraActualizacion < @Umbral
    AND clave > @Cursor
    ORDER BY clave
  por cada candidato → intentar enqueue (§2.3) source=backfill|catchup
  guardar Cursor
  SI no hay más filas → fin de pasada (backfill puede marcar “completado”)
```

Así el backfill **no escribe BD** ni llama ML: solo alimenta Redis con backpressure.

---

## 4. Worker: cómo se procesa un registro

Workers N (escalables). Todos comparten el rate limiter.

### 4.1 Claim del mensaje

```text
loop:
  msg = BRPOP queue:high timeout=1s
  SI null → msg = BRPOP queue:low timeout=1s
  SI null → continue

  visibleAt = now + VisibilityTimeout (ej. 120s)
  ZADD processing score=visibleAt member=msg

  try Procesar(msg)
  SI éxito → ZREM processing msg
  SI fallo reintentable → ReencolarConBackoff(msg) + ZREM processing
  SI fallo definitivo / max attempts → LPUSH dlq + ZREM processing
```

**Recovery de mensajes trabados:** job cada 30s:

```text
para cada member en processing con score <= now:
  volver a LPUSH de su cola (según priority)  // o a retry
  ZREM processing
```

(Equivale al “visibility timeout” de SQS.)

### 4.2 `Procesar(msg)` — pasos

```text
1. Validar payload / schemaVersion
2. Cargar token de la integración (IDUsuario + IDIntegracion)
   - si integración eliminada o usuario inactivo → ACK sin ML (descartar)
3. Leer fila destino por clave de negocio
4. SI existe Y FechaHoraActualizacion >= now - TTL
     → ACK (noop)   // ya fresco; típico con dedup imperfecto o catch-up solapado
5. Adquirir permiso rate limiter global (bloquear/esperar si no hay token)
6. GET endpoint planes/recomendación ML con MeLiUserProductId
7. Mapear respuesta → modelo tabla Contabilium (wiki)
8. UPSERT en BD + FechaHoraActualizacion = UtcNow
9. ACK
```

### 4.3 UPSERT en base

- Preferible **1 UPSERT por mensaje** en el worker (simplicidad + ACK claro).
- Opcional: buffer de 20–50 UPSERTs en el mismo worker si el cuello es BD (solo ACK tras commit del lote; más cuidado en fallos).
- Índice único en `(IDUsuario, IDIntegracion, MeLiUserProductId)`.
- Transacción corta solo del MERGE.

Ejemplo conceptual:

```sql
MERGE RecomendacionReposicionFull AS t
USING (SELECT @IDUsuario AS IDUsuario, @IDIntegracion AS IDIntegracion,
              @MeLiUserProductId AS MeLiUserProductId) AS s
ON t.IDUsuario = s.IDUsuario
AND t.IDIntegracion = s.IDIntegracion
AND t.MeLiUserProductId = s.MeLiUserProductId
WHEN MATCHED THEN UPDATE SET
  /* campos ML */, FechaHoraActualizacion = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT (..., FechaHoraActualizacion)
VALUES (..., SYSUTCDATETIME());
```

---

## 5. Fallos y reintentos (detalle)

### 5.1 Clasificación de errores

| Tipo | Ejemplos | Acción |
|------|----------|--------|
| **Transitorio API** | 429, 5xx, timeout, reset de red | Reintento con backoff + jitter |
| **Transitorio BD** | deadlock, timeout conexión | Reintento; si ya hay payload en staging, no rellamar ML |
| **Rate limit** | 429 | Esperar según limiter + `Retry-After` si existe; reencolar |
| **Auth** | 401 | Intentar refresh token 1 vez; si falla → DLQ / marcar integración |
| **Negocio no reintentable** | 403 sin Full, 404 UP inexistente | UPSERT estado vacío/“sin recomendación” + fecha **o** DLQ tipificada; **no** loop infinito |
| **Payload inválido** | JSON roto, IDs null | DLQ inmediata |

### 5.2 Política de reintentos

Parámetros sugeridos:

- `MaxAttempts` = 5 (attempt 0..4 en cola; el 5º fallo → DLQ)
- Backoff exponencial con jitter:

\[
delay = min(Cap, Base \times 2^{attempt}) + random(0, Jitter)
\]

Ejemplo: Base 30s, Cap 30 min, Jitter 10s → ~30s, 60s, 120s, 240s, 480s…

### 5.3 Cómo se reencola

Al fallar de forma reintentable:

```text
msg.attempt = msg.attempt + 1
msg.lastError = "{code}:{message}"
msg.source = "retry"

SI msg.attempt >= MaxAttempts:
  LPUSH reposicion:dlq  msg
  log + métrica
  return

delay = backoff(msg.attempt)
visibleAt = now + delay
ZADD processing score=visibleAt member=msg
// el sweeper de processing, cuando score <= now,
// re-LPUSH a queue:high o queue:low según msg.priority
```

Alternativa equivalente: cola delayed `reposicion:delayed` (ZSET) y un mover periódico a `queue:*`.

**Importante:** al reencolar por retry, **borrar o no** la dedup key:

- Si mantenés dedup durante el retry, otro productor no duplica (bien).
- Cuando el mensaje va a DLQ o ACK OK, podés dejar expirar el TTL del dedup solo.

### 5.4 Qué pasa si falla **después** del GET a ML pero **antes/durante** el UPSERT

Para no gastar cuota dos veces:

1. Tras GET OK, guardar respuesta en **staging SQL** (o Redis `reposicion:payload:{jobId}` con TTL 24h):
   - clave de negocio + JSON/campos + `jobId` + `fetchedAt`
2. UPSERT destino desde staging.
3. Si UPSERT falla:
   - reintento **solo de persistencia** (sin ML) mientras exista staging fresco.
4. Si staging expiró → reintento completo (vuelve a ML).

Mínimo viable sin staging: reintentar todo el `Procesar` (vuelve a llamar ML). Es más simple pero gasta RPM.

### 5.5 Reproceso desde DLQ

- Lista/tabla `reposicion:dlq` + pantalla o job manual/automático.
- Operador puede `requeue` (attempt=0) tras corregir causa (token, schema, outage).
- Alertar si `LLEN(dlq)` crece o hay 429 sostenidos.

---

## 6. Rate limiting compartido

Todos los workers (y cualquier llamada ML del processor) pasan por un **token bucket en Redis**:

- Ej.: 25 tokens/min para este dominio (ajustable).
- `source=notification` y `source=catchup` compiten; el orden de consumo (high antes que low) prioriza lo vivo.
- Ante 429: vaciar/penalizar el bucket un período corto además del backoff del mensaje.

Presupuesto orientativo:

- 70% efectivo para cola high (notificaciones)
- 30% residual para low (backfill/catch-up) — emerge naturalmente si siempre se drena high primero

---

## 7. Idempotencia y concurrencia

1. Dos mensajes del mismo UP pueden existir si el dedup expiró: el check de **TTL en BD** evita doble escritura inútil.
2. Dos workers no deben procesar el mismo mensaje: claim vía `processing` ZSET.
3. UPSERT hace segura la doble escritura concurrente del mismo UP.
4. `FechaHoraActualizacion` siempre refleja el último UPSERT exitoso local (no la fecha de ML, salvo que la wiki pida ambas).

---

## 8. Secuencia ejemplo (feliz + fallo)

### Feliz (notificación)

```text
Notif Full → enqueue high (dedup OK)
Worker BRPOP high → processing
BD stale → GET ML (rate OK) → UPSERT → ZREM processing
```

### Fallo 429 y reintento

```text
Worker → GET ML → 429
attempt 0→1, lastError=429
ZADD processing visibleAt=now+35s
… 35s después sweeper → LPUSH high
Worker otra vez → GET OK → UPSERT → ACK
```

### Fallo BD tras GET (con staging)

```text
GET OK → save staging → UPSERT FAIL
reintento attempt+1 sin ML → UPSERT OK → borrar staging → ACK
```

### Agota intentos

```text
attempt llega a MaxAttempts → LPUSH dlq
alerta; no se pierde el mensaje; se reprocesa a mano/job
```

---

## 9. Checklist de implementación

- [ ] Keys Redis: `queue:high`, `queue:low`, `processing`, `dedup:*`, `dlq`, rate limiter  
- [ ] Productor en notificaciones Full  
- [ ] Job único `ReconciliarReposicion` (backfill/catch-up) con cursor + backpressure  
- [ ] Workers con preferencia high → low  
- [ ] `Procesar` con skip por TTL, GET ML, UPSERT, ACK  
- [ ] Clasificación errores + backoff + DLQ  
- [ ] (Recomendado) staging post-GET para no repetir ML en fallo de BD  
- [ ] Métricas: lag cola, profundidad, 429/min, DLQ, stale_count, cobertura %  
- [ ] Alertas: DLQ >, lag high >, tasa 429 >, reconciliación sin avanzar cursor  

---

## 10. Resumen en una frase

**Notificaciones y reconciliación solo encolan claves en Redis; los workers hacen GET+UPSERT con rate limit único; los fallos reencolan con backoff hasta un máximo y luego van a DLQ; el backfill inicial es el mismo reconciliador que el catch-up, solo que corre más agresivo al principio.**
