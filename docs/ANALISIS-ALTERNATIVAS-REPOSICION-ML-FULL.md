# Análisis de alternativas: Recomendaciones de reposición Mercado Libre Full

**Fecha:** 26 oct 2026 (America/Buenos_Aires)  
**Objetivo:** Decidir cómo obtener y persistir recomendaciones de reposición de productos Full desde la API de Mercado Libre, a partir de las publicaciones Contabilium elegibles.

**Fuentes:**

- [Planes de reposición para Fulfillment (ML Developers)](https://developers.mercadolibre.com.ar/es_ar/planes-de-reposicion-para-fullment) — al momento de este análisis la URL pública devolvió 404; validar el slug vigente en el portal de desarrolladores.
- [Wiki Contabilium — Integra Recomendaciones de Reposición](https://contabilium.atlassian.net/wiki/spaces/a415a9b1c3a144e98f56ce046f606fb7/pages/1612382209/Integra+Recomendaciones+de+Reposici+n+en+Mercado+Libre+Full) — requiere autenticación; el modelo de columnas se toma como especificado por el equipo.
- [Rate limit / 429 (ML)](https://developers.mercadolibre.com.ar/rate-limit-error-429): límite principal por **Client ID** y endpoint; backoff + jitter; stock documentado en ~**100 RPM** en recursos relacionados.

---

## 1. Resumen ejecutivo

Con el volumen teórico (~2.500 usuarios × ~25.000 publicaciones ≈ **62,5 M** filas) y una cuota típica compartida por aplicación (~100 RPM en endpoints de stock), **un cron semanal que intente “barrer todo” en una sola corrida no es viable** si se consume el universo completo sin deduplicar ni acotar Full real.

**Recomendación:** implementar un **modelo híbrido** basado en la **Alternativa 2** (cola Redis desde notificaciones) + **backfill inicial controlado** + **proceso catch-up semanal** para productos sin notificación o stale. La Alternativa 1 sola solo sirve como referencia de peor caso o como motor del catch-up/backfill, nunca como único mecanismo de frescura.

---

## 2. Alcance funcional y modelo de datos

### 2.1 Selección de productos a consultar

```sql
-- Esqueleto ilustrativo (ajustar nombres reales de columnas/valores)
SELECT DISTINCT
    c.IDUsuario,
    c.IDIntegracion,
    c.IDConfiguracionPublicacionIntegracion, -- si se requiere trazabilidad
    c.MeLiUserProductId
FROM ConfiguracionPublicacionIntegracion c
INNER JOIN Usuarios u
    ON u.IDUsuario = c.IDUsuario
   AND u.Activo = 1
INNER JOIN Integracion i
    ON i.IDIntegracion = c.IDIntegracion
   AND i.FechaEliminacion IS NULL
WHERE c.TipoShipping = 'fulfillment'   -- valor exacto a confirmar
  AND c.MeLiUserProductId IS NOT NULL
  AND LTRIM(RTRIM(c.MeLiUserProductId)) <> '';
```

**Regla crítica:** el mismo `MeLiUserProductId` puede repetirse en varias publicaciones. La unidad de consulta a ML debe ser el **User Product** (idealmente por `(IDUsuario, IDIntegracion, MeLiUserProductId)`), no cada fila de publicación.

### 2.2 Persistencia

Nueva tabla (nombre según wiki), con al menos:

- Identificadores Contabilium: `IDUsuario`, `IDIntegracion`, opcionalmente `IDConfiguracionPublicacionIntegracion`
- `MeLiUserProductId`
- Campos de la respuesta del endpoint de planes/recomendación (según wiki)
- `FechaHoraActualizacion` — momento del UPSERT local

**Diseño obligatorio:** UPSERT idempotente + índice único sobre la clave de negocio + índice por `FechaHoraActualizacion` para el catch-up.

---

## 3. Cálculo de volumen y capacidad (rate limit)

### 3.1 Supuestos

| Parámetro | Valor | Notas |
|-----------|-------|-------|
| Usuarios activos | 2.500 | Dato de negocio |
| Publicaciones / usuario | 25.000 | Promedio; incluye no-Full |
| Filas brutas | 62.500.000 | Sin filtro Full efectivo medido |
| % publicaciones Full | **desconocido** | Debe medirse; escenarios abajo |
| Factor dedup UP / publicaciones Full | 2–5× | Mismo UP en varias pubs |
| RPM endpoint (estimado) | 100 | Referencia stock ML; **confirmar** para planes |
| % cuota reservada a este proceso | 25% | El Client ID es compartido |
| RPM efectivos | **25** | 100 × 0,25 |
| Ventana semanal útil | 7 × 24 h | Ideal; descontar mantenimiento |

### 3.2 Capacidad semanal

\[
\text{llamadas/semana} \approx 25\ \text{RPM} \times 60 \times 24 \times 7 = 252.000
\]

Con 50% de cuota (50 RPM): ~504.000 llamadas/semana.  
Con 100% de cuota (irreal): ~1.008.000 llamadas/semana.

### 3.3 Escenarios de demanda (tras DISTINCT)

| Escenario | % Full | Dedup | User Products únicos | Semanas a 25 RPM | ¿Cabe en 1 semana? |
|-----------|--------|-------|----------------------|------------------|--------------------|
| A — pesimista | 100% | 2× | 31,25 M | ~124 | No |
| B — alto | 40% | 3× | ≈ 8,3 M | ~33 | No |
| C — medio | 15% | 3× | ≈ 3,1 M | ~12 | No |
| D — bajo | 5% | 4× | ≈ 0,78 M | ~3,1 | No (casi) |
| E — acotado | 2% | 4× | ≈ 0,31 M | ~1,2 | Límite / no estable |

**Conclusión de capacidad:** incluso en escenarios moderados, un barrido completo semanal **excede** la cuota razonable. El diseño debe **reducir llamadas** (dedup + frescura + event-driven) y/o **pedir aumento de RPM** a ML con evidencia de uso.

### 3.4 Query de medición obligatoria (antes de estimar en firme)

```sql
-- Volumen real a llamar (unidad de API)
SELECT COUNT(*) AS DistinctUserProducts
FROM (
    SELECT c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
    FROM ConfiguracionPublicacionIntegracion c
    INNER JOIN Usuarios u ON u.IDUsuario = c.IDUsuario AND u.Activo = 1
    INNER JOIN Integracion i ON i.IDIntegracion = c.IDIntegracion AND i.FechaEliminacion IS NULL
    WHERE c.TipoShipping = 'fulfillment'
      AND c.MeLiUserProductId IS NOT NULL
    GROUP BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
) x;
```

---

## 4. Alternativa 1 — Cron semanal batch

### 4.1 Flujo

1. Job semanal (cron / Hangfire / Windows Service) obtiene el universo elegible con `DISTINCT`.
2. Pagina por cursor (`IDUsuario`, `MeLiUserProductId`) — **nunca** cargar 62 M en memoria.
3. Por cada unidad: GET al endpoint de planes ML con token del vendedor/integración.
4. UPSERT en la tabla nueva + `FechaHoraActualizacion = GETUTCDATE()` (o hora local acordada).
5. Checkpoint del último cursor procesado; al reiniciar continúa.

### 4.2 Ventajas / pros

- Modelo simple de razonar: un proceso, un schedule.
- Cobertura teórica del 100% del universo en cada ciclo (si el ciclo termina).
- No depende de la calidad/completitud de notificaciones ML.
- Fácil de auditar: “última corrida semanal X”.

### 4.3 Desventajas / contras

- **No escala** con el volumen Contabilium bajo cuota compartida (ver §3).
- Pico de carga semanal: riesgo de 429, degradación de otros syncs Contabilium, presión en BD.
- Datos potencialmente **stale** casi toda la semana (hasta el próximo cron).
- Una falla a mitad de corrida deja cobertura parcial hasta el reintento.
- Tokens expirados / usuarios con muchas pubs concentran tiempo de wall-clock.

### 4.4 Tiempo de ejecución (estimaciones)

Fórmula:

\[
T_{\text{horas}} = \frac{N_{\text{UP}}}{\text{RPM efectivos} \times 60}
\]

| N (UP únicos) | 25 RPM | 50 RPM | 100 RPM |
|---------------|--------|--------|---------|
| 100.000 | 67 h (~2,8 d) | 33 h | 17 h |
| 500.000 | 333 h (~14 d) | 167 h | 83 h |
| 1.000.000 | 667 h (~28 d) | 333 h | 167 h |
| 5.000.000 | 3.333 h (~139 d) | 1.667 h | 833 h |

**“Una vez por semana” solo es realista si N ≲ capacidad semanal** (§3.2) tras medir.

### 4.5 Errores de procesamiento esperados

| Error | Causa típica | Mitigación |
|-------|--------------|------------|
| HTTP 429 | Cuota Client ID / endpoint | Token bucket global, backoff exponencial + jitter, reencolar |
| HTTP 401/403 | Token inválido / sin permiso Full | Refresh token; marcar integración; no reintentar en loop infinito |
| HTTP 404 / vacío | UP sin plan / no Full | Guardar estado “sin recomendación” + fecha (evitar reconsultar agresivo) |
| HTTP 5xx / timeout | Inestabilidad ML | Retry limitado (ej. 3–5) luego DLQ |
| Timeout BD | Lotes grandes / locks | Batch menor, UPSERT set-based, índices |
| Duplicados lógicos | Mismo UP en N pubs | DISTINCT antes de llamar |
| Job matado | Deploy / OOM | Checkpoint por cursor + idempotencia |

### 4.6 Persistencia: individual vs lote

| Estrategia | Pros | Contras | Recomendación |
|------------|------|---------|---------------|
| 1 fila / request | Simple; falla acotada | Muchos round-trips BD | Solo si volumen bajo |
| Batch UPSERT (50–200) | Throughput BD alto | Rollback de lote entero si mal diseñado | **Preferida** |
| Bulk insert + merge staging | Máximo throughput | Más complejo; 2 pasos | Backfill masivo |

**Recomendado:**

1. Acumular en memoria/buffer N respuestas OK (ej. 100).
2. `MERGE` / upsert por lote en transacción corta.
3. Si el lote falla: reintentar el lote; si vuelve a fallar, degradar a **fila a fila** y mover fallidas a tabla `RecomendacionReposicion_Error` / DLQ.
4. Confirmar checkpoint **después** del commit exitoso (no antes).

Patrón **outbox / staging**:

```text
API OK → staging (pendiente) → MERGE destino → marcar staging OK
Si MERGE falla → staging queda pendiente → job de reproceso
```

### 4.7 Fallo al guardar y reprocesamiento

1. **Checkpoint + cursor:** no perder progreso de API ya hecha.
2. **Staging de respuestas:** no descartar JSON/campos ya obtenidos si falla el UPSERT destino.
3. **DLQ** con motivo, `MeLiUserProductId`, timestamps, intentos.
4. Job de reproceso cada X minutos solo sobre staging/DLQ (sin reconsultar ML si el payload ya está).
5. Si el fallo fue de API (no de BD): reencolar con backoff; respetar rate limit.

### 4.8 Veredicto Alternativa 1

Útil como **motor de backfill/catch-up**, no como única estrategia semanal de cobertura total salvo que el volumen real medido sea bajo (escenario E o menor) **y** se reserve cuota sin afectar otros procesos.

---

## 5. Alternativa 2 — Notificaciones → Redis → worker

### 5.1 Flujo

```text
Notificación ML (cambio publicación)
        ↓
Proceso existente Contabilium
        ↓
¿TipoShipping Full / logistic fulfillment?
        ↓ sí
Enqueue Redis {
  user_product_id,
  IDUsuario,
  IDIntegracion,
  IDConfiguracionPublicacionIntegracion,
  enqueued_at
}
        ↓
Worker(s)
  → ¿existe en tabla y FechaHoraActualizacion >= ahora - TTL?
       sí → ACK / noop
       no → GET ML → UPSERT → ACK
  → on fail → retry / DLQ
```

### 5.2 Ventajas / pros

- Distribuye la carga en el tiempo (mejor para rate limit).
- Actualiza cuando hay actividad real en la publicación (mejor frescura percibida).
- Reutiliza infraestructura ya existente de notificaciones.
- Dedup natural en cola (ver §5.6) reduce llamadas.
- Escala horizontalmente con workers + token bucket.

### 5.3 Desventajas / contras

- **Cobertura incompleta** si no hay notificación (producto quieto, fallo de webhook, topic no cubierto).
- Acopla lógica nueva al pipeline de notificaciones (riesgo de regresiones).
- Requiere Redis confiable (persistencia AOF/RDB, TTL de mensajes, monitoreo de lag).
- Cold start: tabla vacía hasta que “pasen” eventos → **necesita backfill**.
- Definir bien “actualizada” (TTL); muy corto = más RPM; muy largo = datos viejos.

### 5.4 Tiempo de ejecución / latencia

- **Latencia típica por evento:** segundos a minutos (cola + worker + 1 GET ML).
- **Carga sostenida:** proporcional a tasa de notificaciones Full, no al catálogo completo.
- Picos (eventos masivos ML, republicaciones) → la cola absorbe; el token bucket limita RPM.

Estimación ilustrativa: si hay 5 notificaciones Full/seg promedio → 300 RPM potenciales, pero el limiter las baja a 25–50 RPM y la cola crece (lag medible).

### 5.5 Errores y rate limit

Además de los de §4.5:

| Riesgo | Mitigación |
|--------|------------|
| Burst de notificaciones | Cola + rate limiter global por Client ID |
| Mensajes duplicados | Clave dedup Redis `SET key NX EX ttl` por `(IDUsuario, IDIntegracion, UP)` |
| Mensaje perdido (Redis) | Persistencia Redis + catch-up BD |
| Worker caído | Consumer group / visibility timeout / requeue |
| Notificación no-Full encolada | Filtrar antes de enqueue |

### 5.6 Persistencia y reproceso

Igual que Alternativa 1 para el UPSERT (lotes pequeños desde el worker o buffer corto).

Para la cola:

1. Al fallar UPSERT: **no ACK**; reintento con límite; luego DLQ Redis/lista o tabla SQL.
2. Preferible persistir staging SQL también en Alternativa 2 (fuente de verdad de fallos auditable).
3. Reproceso: worker dedicado lee DLQ/staging sin volver a ML si ya hay payload.

### 5.7 ¿Conviene un proceso inicial (backfill)?

**Sí, es necesario.** Sin backfill:

- Solo se poblarán productos que reciban notificaciones nuevas.
- Reportes/UI de reposición quedarán vacíos o incompletos por semanas/meses.

**Diseño del backfill:**

- Es la Alternativa 1, pero **una vez** (o por oleadas), con checkpoint, prioridad a usuarios activos con más ventas Full si se puede priorizar.
- Correr en ventana off-peak con RPM conservador (ej. 15–25).
- Objetivo: cobertura inicial ≥ 95% del universo DISTINCT; el resto lo termina el catch-up.

### 5.8 Hueco: notificaciones faltantes

Si no llega notificación:

- No se encola el UP.
- No se refresca la recomendación.
- El dato queda ausente o stale.

**Sí es recomendable un proceso catch-up** (ver §6). Sin él, la Alternativa 2 **no garantiza** integridad del universo Full.

### 5.9 Veredicto Alternativa 2

Es la **base correcta** para operación continua, condicionada a: backfill inicial + catch-up + dedup en cola + rate limiter global.

---

## 6. Proceso catch-up / reconciliación (recomendado)

### 6.1 Objetivo

Detectar productos elegibles **sin fila** en la tabla nueva o con `FechaHoraActualizacion` anterior a un umbral (ej. 7 días) y encolarlos/procesarlos.

### 6.2 Diseño propuesto

**Frecuencia:** diario (preferible) o semanal en ventana nocturna.  
**Prioridad:** menor que el worker de notificaciones en vivo (no robar toda la cuota).

```sql
-- Pseudocódigo: candidatos stale / faltantes
SELECT TOP (@BatchSize)
    c.IDUsuario,
    c.IDIntegracion,
    MIN(c.IDConfiguracionPublicacionIntegracion) AS IDConfiguracionPublicacionIntegracion,
    c.MeLiUserProductId
FROM ConfiguracionPublicacionIntegracion c
INNER JOIN Usuarios u ON u.IDUsuario = c.IDUsuario AND u.Activo = 1
INNER JOIN Integracion i ON i.IDIntegracion = c.IDIntegracion AND i.FechaEliminacion IS NULL
LEFT JOIN RecomendacionReposicionFull r  -- nombre según wiki
    ON r.IDUsuario = c.IDUsuario
   AND r.IDIntegracion = c.IDIntegracion
   AND r.MeLiUserProductId = c.MeLiUserProductId
WHERE c.TipoShipping = 'fulfillment'
  AND c.MeLiUserProductId IS NOT NULL
  AND (
        r.MeLiUserProductId IS NULL
     OR r.FechaHoraActualizacion < DATEADD(day, -7, SYSUTCDATETIME())
  )
GROUP BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
ORDER BY r.FechaHoraActualizacion ASC;  -- NULLS FIRST según motor
```

Flujo:

1. Seleccionar lote (ej. 5.000–20.000 claves).
2. Encolar en Redis con prioridad baja **o** procesar inline con el mismo worker y rate limiter.
3. Dedup contra claves ya en cola caliente.
4. Métricas: `missing_count`, `stale_count`, `catchup_lag_days`, `catchup_rpm_used`.

### 6.3 Pros

- Cierra el hueco de notificaciones perdidas/ausentes.
- Garantiza SLA de frescura (ej. “ningún Full elegible con dato > 7 días”).
- Reutiliza el mismo worker/UPSERT/DLQ.
- Permite operación segura aunque ML deje de notificar un subset.

### 6.4 Contras

- Consume cuota adicional (planificar presupuesto RPM: ej. 70% live / 30% catch-up).
- Query anti-join puede ser costosa → índices + lotes + horarios off-peak.
- Si el umbral es muy agresivo, se parece a la Alternativa 1 y reaparece el problema de capacidad.

### 6.5 Tiempo y errores

- Tiempo = función de `stale+missing` / RPM catch-up.
- Mismos errores API/BD; el catch-up debe ser **interruptible** (checkpoint del último batch).
- Si el catch-up no termina en la ventana: continúa al día siguiente (cursor), no reinicia de cero.

### 6.6 ¿Es recomendable?

**Sí.** Para Contabilium, con alto volumen y dependencia de notificaciones, el catch-up es **parte del diseño mínimo viable**, no un opcional.

---

## 7. Comparativa lado a lado

| Criterio | Alt. 1 Cron semanal | Alt. 2 Notif + Redis | Catch-up |
|----------|---------------------|----------------------|----------|
| Cobertura universo | Alta si termina | Baja/media sin backfill | Alta progresiva |
| Frescura | Baja (hasta 7 d) | Alta en productos activos | Media (según TTL) |
| Rate limit | Peor (ráfagas) | Mejor (distribuido) | Controlable |
| Dependencia notificaciones | No | Sí | Compensa la dependencia |
| Complejidad | Media | Media-alta | Media |
| Cold start | Resuelve solo | Necesita backfill | Ayuda a completar |
| Fallo parcial | Checkpoint + staging | Cola + DLQ + staging | Lotes + cursor |
| Escala 2×–5× | Muy frágil | Viable con más workers + cuota | Debe alargar TTL o pedir RPM |

---

## 8. Recomendación final (híbrido)

### Fase 0 — Medición (1–2 días de trabajo técnico)

1. Correr query DISTINCT real (§3.4).
2. Confirmar RPM del endpoint de planes (prueba controlada + headers 429).
3. Confirmar schema destino según wiki Contabilium.
4. Definir TTL de frescura de negocio (sugerido: **7 días**, alineado a “semanal”).

### Fase 1 — Fundación

1. Tabla destino + índices únicos + `FechaHoraActualizacion`.
2. Tabla/cola de errores (DLQ) + staging opcional.
3. Cliente ML con token bucket **global** (singleton / Redis) compartido por todos los workers.
4. Worker de consumo Redis con UPSERT idempotente.

### Fase 2 — Event-driven (Alternativa 2)

1. Hook en el procesador de notificaciones: filtro Full → enqueue.
2. Dedup de cola por clave de negocio + TTL corto (ej. 15–60 min) para colapsar ráfagas.
3. Métricas de lag y tasa de 429.

### Fase 3 — Backfill inicial

1. Job paginado tipo Alternativa 1, RPM bajo, off-peak.
2. No bloquear el go-live de la Fase 2: pueden correr en paralelo con presupuesto de cuota.

### Fase 4 — Catch-up continuo

1. Job diario de stale/missing → misma cola (prioridad baja).
2. Alertas si `stale_count` crece o el catch-up no converge.

### Política de guardado unificada

- **Producción steady-state:** buffer 50–100 → batch UPSERT.
- **Fallo de lote:** retry → fila a fila → DLQ.
- **No reconsultar ML** si el payload ya está en staging y solo falló la BD.
- **Sí reconsultar** si el error fue de API o el TTL de frescura venció.

---

## 9. Riesgos abiertos / datos a confirmar

1. URL/documentación exacta del endpoint de planes (404 en la URL provista).
2. Campos exactos de la tabla nueva (wiki privada).
3. Valor canónico de `TipoShipping` para Full (`fulfillment` vs otros).
4. RPM real del endpoint y si el límite es solo por Client ID o también por seller.
5. Volumen DISTINCT real post-filtros.
6. ¿Las notificaciones actuales cubren cambios de logística / alta Full / cambios de UP?
7. Capacidad Redis y patrón de colas ya usado en Contabilium.
8. Impacto en otros jobs que ya consumen la misma app ML.

---

## 10. Prompt reutilizable

Para iterar este análisis con datos reales medidos, usar:

[`prompts/analisis-alternativas-reposicion-ml-full.md`](../prompts/analisis-alternativas-reposicion-ml-full.md)
