# Productor (SQL → Redis) + Consumidor (Redis → MeLi → BD)

**U**n proceso busca los productos a consultar y los encola en Redis; otro toma de Redis, consulta Mercado Libre y persiste en BD.

Alineado a: Actualizacion semanal (ML actualiza los **lunes**) + `DISTINCT` por User Product.

---

## 1. Diagrama del flujo

```text
┌─────────────────────────────────────┐
│ 1) PRODUCTOR (job programado)       │
│    - Corre lun/mar post-refresh ML  │
│      y/o catch-up diario ?            │
│    - Query elegibles (faltan/stale) │
│    - DISTINCT + paginado + cursor   │
│    - Encola en Redis (con dedup)    │
└──────────────────┬──────────────────┘
                   │ LPUSH
                   ▼
         ┌──────────────────┐
         │ Redis            │
         │ queue            │
         │ processing       │
         │ dedup:*          │
         │ dlq              │
         │ rate limiter     │
         └────────┬─────────┘
                  │ BRPOP / claim
                  ▼
┌─────────────────────────────────────┐
│ 2) CONSUMIDOR (worker N instancias) │
│    - Saca mensaje                   │
│    - Revalida frescura en BD        │
│    - Rate limit → GET ML            │
│    - UPSERT tabla recomendaciones   │
│    - ACK / retry / DLQ              │
└─────────────────────────────────────┘
catch-up diario: proceso que se ejecuta todos los días y busca los productos sin recomendaciones o con recomendaciones no actualizadas o no esten en redis.
Ejemplo:
Lunes: el proceso funciona correctamente y encola en redis.
Martes: ingresan nuevas publicaciones con nuevos productos que no se encuentran en la cola de redis.
Miércoles: se ejecuta el catch-up diario y vuelve a buscar los productos que no se encuentren en la nueva tabla ni en redis para encolarlos en redis.
```

**Responsabilidades claras**


| Proceso    | Hace                        | No hace                       |
| ---------- | --------------------------- | ----------------------------- |
| Productor  | SELECT + encolar            | Llamar a MeLi ni UPSERT final |
| Consumidor | GET MeLi + UPSERT + retries | Armar el universo SQL         |


---



## 2. Query para buscar qué encolar



### 2.1 Criterios de negocio

Incluir un User Product si:

1. Está en `ConfiguracionPublicacionIntegracion` con `MeLiUserProductId` no nulo
2. `TipoShipping` = fulfillment (Full)
3. Usuario `Activo = 1`
4. Integración `FechaEliminacion IS NULL`
5. **Y** aún no está actualizado para la ventana MeLi vigente:
  - no existe fila en la tabla de recomendaciones, **o**
  - `FechaHoraActualizacion < @InicioVentanaMl`  
  (`@InicioVentanaMl` = último lunes de refresh MeLi)



### 2.2 Query (SQL Server — ajustar nombres reales)

```sql
-- Parámetros
-- @InicioVentanaMl : DATETIME2  -- ej. lunes 00:00 ART convertido a la TZ que usen en BD
-- @BatchSize       : INT        -- ej. 5000
-- @CursorUsuario   : INT        -- checkpoint (0 al inicio)
-- @CursorIntegracion INT
-- @CursorUserProduct NVARCHAR(64)

SELECT TOP (@BatchSize)
    c.IDUsuario,
    c.IDIntegracion,
    MIN(c.IDConfiguracionPublicacionIntegracion) AS IDConfiguracionPublicacionIntegracion,
    c.MeLiUserProductId
FROM ConfiguracionPublicacionIntegracion AS c
INNER JOIN Usuarios AS u
    ON u.IDUsuario = c.IDUsuario
   AND u.Activo = 1
INNER JOIN Integracion AS i
    ON i.IDIntegracion = c.IDIntegracion
   AND i.FechaEliminacion IS NULL
LEFT JOIN RecomendacionReposicionFull AS r  -- nombre según wiki Contabilium
    ON r.IDUsuario = c.IDUsuario
   AND r.IDIntegracion = c.IDIntegracion
   AND r.MeLiUserProductId = c.MeLiUserProductId
WHERE c.TipoShipping = N'fulfillment'         
  AND c.MeLiUserProductId IS NOT NULL
  AND LTRIM(RTRIM(c.MeLiUserProductId)) <> N''
  AND (
        r.MeLiUserProductId IS NULL
     OR r.FechaHoraActualizacion < @InicioVentanaMl
      )
  -- Paginación por cursor (clave de negocio)
  AND (
        c.IDUsuario > @CursorUsuario
     OR (c.IDUsuario = @CursorUsuario AND c.IDIntegracion > @CursorIntegracion)
     OR (c.IDUsuario = @CursorUsuario AND c.IDIntegracion = @CursorIntegracion
         AND c.MeLiUserProductId > @CursorUserProduct)
      )
GROUP BY
    c.IDUsuario,
    c.IDIntegracion,
    c.MeLiUserProductId
ORDER BY
    c.IDUsuario,
    c.IDIntegracion,
    c.MeLiUserProductId;
```



### 2.3 Conteo previo (capacidad / ETA)

```sql
SELECT COUNT(*) AS Pendientes
FROM (
    SELECT c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
    FROM ConfiguracionPublicacionIntegracion c
    INNER JOIN Usuarios u ON u.IDUsuario = c.IDUsuario AND u.Activo = 1
    INNER JOIN Integracion i ON i.IDIntegracion = c.IDIntegracion AND i.FechaEliminacion IS NULL
    LEFT JOIN RecomendacionReposicionFull r
      ON r.IDUsuario = c.IDUsuario
     AND r.IDIntegracion = c.IDIntegracion
     AND r.MeLiUserProductId = c.MeLiUserProductId
    WHERE c.TipoShipping = N'fulfillment'
      AND c.MeLiUserProductId IS NOT NULL
      AND (r.MeLiUserProductId IS NULL OR r.FechaHoraActualizacion < @InicioVentanaMl)
    GROUP BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
) x;
```

---



## 3. Proceso productor (SQL → Redis)



### 3.1 Cuándo correrlo


| Momento                                                | Objetivo                     |
| ------------------------------------------------------ | ---------------------------- |
| Lunes después de la hora de refresh MeLi (o martes AM) | Carga semanal principal      |
| Diario (opcional, lotes chicos)                        | Catch-up de los que faltaron |




### 3.2 Algoritmo

```text
InicioVentanaMl = calcularLunesRefresh(now)
Cursor = leerCheckpoint() o (0,0,'')
MaxColaPendiente = 20000   -- backpressure

loop:
  pendientesCola = LLEN(queue) + ZCARD(processing aproximado)
  SI pendientesCola >= MaxColaPendiente:
      sleep / salir y retomar después
      continue

  filas = ejecutarQuery(@InicioVentanaMl, BatchSize, Cursor)
  SI filas vacío:
      marcar pasada completa / guardar checkpoint fin
      break

  POR CADA fila:
      dedupKey = hash(IDUsuario|IDIntegracion|MeLiUserProductId)
      SI SET dedup NX EX 1800 OK:
          LPUSH queue {
            jobId, MeLiUserProductId, IDUsuario, IDIntegracion,
            IDConfiguracionPublicacionIntegracion,
            source: "reconcile", attempt: 0, enqueuedAt
          }
      SI NO: skip (ya en cola)

  Cursor = última fila
  guardarCheckpoint(Cursor)
```



### 3.3 Payload Redis (ejemplo)

```json
{
  "jobId": "…",
  "MeLiUserProductId": "MLAU12345678",
  "IDUsuario": 98765,
  "IDIntegracion": 4321,
  "IDConfiguracionPublicacionIntegracion": 555001,
  "source": "reconcile",
  "enqueuedAt": "2026-10-28T14:00:00Z",
  "attempt": 0,
  "lastError": null
}
```

Keys:


| Key                       | Uso                                                                   |
| ------------------------- | --------------------------------------------------------------------- |
| `reposicion:queue`        | Pendientes en cola                                                    |
| `reposicion:processing`   | En vuelo / delayed retry                                              |
| `reposicion:dedup:*`      | Anti-duplicado al encolar para no encolar el mismo producto dos veces |
| `reposicion:dlq`          | Fallidos definitivos                                                  |
| `reposicion:ratelimit:ml` | Token bucket GET ML                                                   |


---



## 4. Proceso consumidor (Redis → ML → BD)



### 4.1 Algoritmo por mensaje

```text
msg = BRPOP queue
ZADD processing visibleAt=now+120s

try:
  1. Validar payload
  2. Revalidar en BD: ¿FechaHoraActualizacion >= InicioVentanaMl?
       SÍ → ACK (noop)          // otro worker/productor ya lo actualizó
  3. Validar usuario/integración siguen activos
       NO → ACK (descartar)
  4. acquire rate limiter
  5. GET endpoint recomendación ML (token de la integración)
  6. UPSERT tabla + FechaHoraActualizacion = UtcNow
       (opcional: SemanaRecomendacion = fecha del lunes de la ventana)
  7. ZREM processing  // ACK OK

catch reintentable (429, 5xx, timeout, deadlock BD):
  attempt++
  SI attempt >= MaxAttempts (5):
      LPUSH dlq
      ZREM processing
  SI NO:
      delay = backoff(attempt) + jitter
      ZADD processing visibleAt=now+delay   // sweeper lo reencola después

catch permanente (auth irrecuperable, payload inválido):
  LPUSH dlq
  ZREM processing

catch 404 / sin recomendación de negocio:
  UPSERT vacío o marca "sin datos" + FechaHoraActualizacion
  ACK   // no reintentar en loop
```



### 4.2 UPSERT conceptual

```sql
MERGE RecomendacionReposicionFull AS t
USING (SELECT
         @IDUsuario AS IDUsuario,
         @IDIntegracion AS IDIntegracion,
         @MeLiUserProductId AS MeLiUserProductId
      ) AS s
ON  t.IDUsuario = s.IDUsuario
AND t.IDIntegracion = s.IDIntegracion
AND t.MeLiUserProductId = s.MeLiUserProductId
WHEN MATCHED THEN UPDATE SET
    /* campos de la respuesta ML según wiki */,
    FechaHoraActualizacion = SYSUTCDATETIME(),
    SemanaRecomendacion = @LunesVentana   -- opcional
WHEN NOT MATCHED THEN INSERT (
    IDUsuario, IDIntegracion, MeLiUserProductId,
    /* campos ML */, FechaHoraActualizacion, SemanaRecomendacion
) VALUES (
    @IDUsuario, @IDIntegracion, @MeLiUserProductId,
    /* campos ML */, SYSUTCDATETIME(), @LunesVentana
);
```



### 4.3 Sweeper (cada 30–60 s)

Mensajes en `processing` con `score <= now` → volver a `LPUSH queue` (worker caído o fin de backoff).

---



## 5. Cálculo de `@InicioVentanaMl` (lunes ML)

ML actualiza **una vez por semana los lunes**. Un registro está actualizado si se persistió **en o después** del último refresh.

```text
TZ = America/Argentina/Buenos_Aires
horaRefresh = 00:00   // o la hora real si la confirman (ej. 12:00)

lunesEstaSemana = lunes de la semana de "now" a horaRefresh
SI now < lunesEstaSemana:
    InicioVentanaMl = lunesEstaSemana - 7 días
SI NO:
    InicioVentanaMl = lunesEstaSemana
```

Productor y consumidor deben usar **la misma función**.

---



## 6. Manejo de errores (tabla rápida)


| Situación                       | Dónde      | Acción                                                           |
| ------------------------------- | ---------- | ---------------------------------------------------------------- |
| Query productor lenta / timeout | Productor  | Batches chicos, índices, no tumbar el job entero                 |
| Redis caído al encolar          | Productor  | Fallar el batch, no avanzar cursor (o avanzar solo encolados OK) |
| Dedup hit                       | Productor  | Skip OK                                                          |
| Cola llena (backpressure)       | Productor  | Pausar hasta que baje `LLEN`                                     |
| Worker sin mensaje              | Consumidor | Idle / sleep                                                     |
| Ya fresco en BD                 | Consumidor | ACK noop                                                         |
| HTTP 429                        | Consumidor | Penalizar rate limiter + retry backoff                           |
| HTTP 5xx / timeout ML           | Consumidor | Retry backoff                                                    |
| HTTP 401                        | Consumidor | Refresh token 1 vez; si falla → DLQ + marcar integración         |
| HTTP 403 / 404 negocio          | Consumidor | UPSERT vacío + fecha **o** DLQ tipificada; no loop               |
| Deadlock / timeout BD           | Consumidor | Retry; ideal staging del GET para no repetir ML                  |
| `attempt >= 5`                  | Consumidor | **DLQ**                                                          |
| Worker crash mid-flight         | Sweeper    | Reencola desde `processing`                                      |




### Qué es la DLQ

`reposicion:dlq` guarda mensajes que **ya no se reintentan solos**. No se pierde el trabajo: queda el JSON + `lastError` + `attempt` para alerta y reproceso manual/job (`attempt = 0` de nuevo a `queue`).

---



## 7. Secuencia ejemplo (semana)

```text
Lunes 14:00 (post-refresh ML)
  Productor corre → 800.000 pendientes DISTINCT → encola con backpressure
  Workers drenan cola (rate limit 25 RPM) durante horas/días

Martes–Domingo
  Productor catch-up: solo los que siguen con FechaHora < lunes
  Si alguien reencola duplicado → dedup / noop por frescura

Mensaje con 429 tres veces → backoff → al 5º → DLQ → alerta
Operador corrige / reencola DLQ cuando ML estabiliza
```

---



## 8. Checklist de implementación

- [ ] Tabla destino + UNIQUE `(IDUsuario, IDIntegracion, MeLiUserProductId)` + `FechaHoraActualizacion`  
- [ ] Confirmar valor `TipoShipping` y nombre real de tabla wiki  
- [ ] Confirmar hora del refresh del lunes MeLi  
- [ ] Job productor + checkpoint + backpressure  
- [ ] Workers consumidores + rate limiter global  
- [ ] Dedup, processing, sweeper, DLQ + alertas  
- [ ] Métricas: pendientes SQL, profundidad cola, 429/min, DLQ, % cobertura semanal  

---



## 9. Resumen

1. **Productor:** query de Full elegibles no actualizados desde el lunes MeLi → Redis.
2. **Consumidor:** Redis → (si no esta actualizado) GET MeLi → UPSERT.
3. **Errores:** retry con backoff; agotados o permanentes → **DLQ**; Actualizados por ventana del lunes evita reconsultar lo ya bajado esa semana.

