# Prompt: Análisis de alternativas — Recomendaciones de reposición Mercado Libre Full

Usá este prompt con un modelo de análisis técnico (arquitectura / backend) para obtener o iterar el estudio de alternativas. Completá o ajustá las secciones marcadas con `[COMPLETAR]` según datos reales de Contabilium.

---

## Rol

Sos un arquitecto de software senior especializado en integraciones de alto volumen con marketplaces (Mercado Libre), colas, jobs batch y rate limiting. Debés producir un análisis accionable, cuantitativo y con recomendación explícita — no un resumen genérico.

## Contexto del negocio

Contabilium necesita integrar las **recomendaciones de reposición** de productos almacenados en depósitos **Full (Fulfillment)** de Mercado Libre.

### Fuentes de documentación

1. API Mercado Libre — Planes de reposición para Fulfillment:  
   https://developers.mercadolibre.com.ar/es_ar/planes-de-reposicion-para-fullment  
   (validar URL vigente; puede variar el slug `fulfillment` / `fullment`)
2. Modelo de datos / persistencia Contabilium:  
   https://contabilium.atlassian.net/wiki/spaces/a415a9b1c3a144e98f56ce046f606fb7/pages/1612382209/Integra+Recomendaciones+de+Reposici+n+en+Mercado+Libre+Full
3. Rate limit ML (referencia): control principal por **Client ID (aplicación)** y por endpoint; HTTP 429 con backoff + jitter; endpoints de stock suelen documentar ~100 RPM. Solicitar aumento de cuota al equipo comercial de ML si el volumen lo justifica.

### Criterio de selección de registros a consultar

Obtener los `MeLiUserProductId` a consultar desde:

- Tabla `ConfiguracionPublicacionIntegracion`
  - `MeLiUserProductId` no nulo
  - `TipoShipping = fulfillment` (o el valor exacto usado en Contabilium para Full)
- Relación con `Usuarios` por `IDUsuario`
  - `Usuarios.Activo = 1`
- Relación con `Integracion`
  - `Integracion.FechaEliminacion IS NULL`

### Volumen estimado

| Dimensión | Valor |
|-----------|-------|
| Usuarios activos (promedio) | ~2.500 |
| Publicaciones por usuario (promedio) | ~25.000 |
| Registros brutos teóricos | ~62.500.000 |
| Duplicación | El mismo `MeLiUserProductId` puede estar en varias publicaciones |

**Importante:** no todas las publicaciones son Full. Medí el volumen real con un query de conteo `DISTINCT` por `(IDUsuario, MeLiUserProductId)` filtrando Full + usuario activo + integración no eliminada. Usá ese número en los cálculos de tiempo.

### Persistencia objetivo

Por cada producto consultado, guardar en una **tabla nueva** la respuesta del endpoint de ML (según la wiki Contabilium) **más** `FechaHoraActualizacion` (fecha/hora en que se persistió/actualizó el registro en Contabilium).

Clave de unicidad recomendada a validar:  
`(IDUsuario, IDIntegracion, MeLiUserProductId)` o la que defina la wiki. El diseño debe ser **idempotente** (UPSERT).

### Rate limit y cuota compartida

Asumí que Contabilium usa **un Client ID** de aplicación Mercado Libre compartido por muchos procesos. El job de reposición **compite** con notificaciones, sync de publicaciones, órdenes, etc. No asignar el 100% de la cuota a este proceso.

Parámetros a usar si no hay dato oficial del endpoint de planes:

- Cuota estimada del endpoint: `[COMPLETAR: ej. 100 RPM]`
- % de cuota reservada a este proceso: `[COMPLETAR: ej. 20–40%]`
- Workers concurrentes máximos: `[COMPLETAR]`

## Alternativas a analizar

### Alternativa 1 — Cron semanal batch

Armar un script/cron que **una vez por semana**:

1. Busque todos los registros elegibles (query con filtros arriba).
2. Deduplique por `MeLiUserProductId` (+ usuario/integración).
3. Consulte el endpoint de ML por cada unidad deduplicada.
4. Guarde el resultado en la tabla nueva.

**Debés analizar explícitamente:**

- Ventajas / desventajas / pros / contras
- Tiempo de ejecución estimado (fórmulas + escenarios: optimista / realista / pesimista)
- Posibles errores de procesamiento (API, token, timeouts, 429, 5xx, datos inconsistentes)
- Rate limit y cómo no tumbar otros procesos Contabilium
- **Mejor forma de guardar en BD:** individual vs lote (batch size, transacciones, UPSERT, índices)
- Qué pasa si falla el guardado a mitad de corrida
- Estrategia de **reprocesamiento** de lo no guardado (tabla de staging / outbox / dead-letter / checkpoint por cursor)
- Si “una vez por semana” es físicamente viable con el volumen y la cuota

### Alternativa 2 — Cola Redis alimentada por notificaciones existentes

Ya existe un proceso que consume **notificaciones de cambios en publicaciones** de Mercado Libre. En ese mismo flujo:

1. Identificar si la publicación tiene envío Full.
2. Encolar en Redis: `user_product_id` + `IDUsuario` + `IDIntegracion` + `IDConfiguracionPublicacionIntegracion`.
3. Un worker consume la cola:
   - Si ya existe en la tabla nueva y está **actualizada** (definir TTL / ventana de frescura, ej. 24h–7d) → no hacer nada.
   - Si no existe o está stale → consultar endpoint ML y UPSERT.

**Debés analizar explícitamente:**

- Ventajas / desventajas / pros / contras
- Tiempo de ejecución / latencia típica vs carga sostenida
- Errores de procesamiento y rate limit
- Mejor forma de guardar en BD + fallos de persistencia + reprocesamiento
- Si conviene un **proceso inicial (backfill)** que cargue el universo histórico una sola vez
- Qué pasa si **no llega una notificación** → el producto nunca entra a Redis → la recomendación no se actualiza
- En ese caso: ¿es recomendable un **proceso catch-up / reconciliación** que detecte productos no actualizados y los procese?
  - Diseñá ese proceso (query, cola, prioridad, frecuencia)
  - Ventajas / desventajas / pros / contras
  - Tiempo de ejecución, errores, rate limit

## Criterios de evaluación obligatorios

Para cada alternativa (y para el catch-up), cubrir:

1. Completitud de datos (cobertura del universo Full)
2. Frescura de datos (SLA de actualización)
3. Uso de rate limit ML
4. Carga sobre SQL Server / BD Contabilium
5. Complejidad operativa (monitoreo, alertas, reintentos)
6. Idempotencia y consistencia
7. Costo de fallo parcial
8. Escalabilidad si crece el volumen 2×–5×

## Formato de salida requerido

1. **Resumen ejecutivo** (máx. 10 líneas) con recomendación clara.
2. **Cálculo de volumen y capacidad** (tabla con supuestos, dedup, RPM, horas/días).
3. **Alternativa 1** — pros/contras, tiempos, errores, persistencia, reproceso.
4. **Alternativa 2** — idem + backfill inicial.
5. **Proceso catch-up** — diseño propuesto + pros/contras.
6. **Comparativa lado a lado** (tabla).
7. **Recomendación final** (híbrida si aplica) con plan de implementación por fases.
8. **Riesgos abiertos** y datos que Contabilium debe medir antes de estimar en firme.

## Restricciones

- No inventar endpoints ni campos de la wiki si no están documentados; marcar supuestos.
- Cuantificar siempre que sea posible; si falta un dato, indicar cómo medirlo.
- Priorizar diseño resiliente: checkpoint, idempotencia, DLQ, backoff.
- Responder en **español**.

## Datos opcionales a inyectar antes de correr el prompt

```text
RPM_CONFIRMADO_ENDPOINT = [COMPLETAR]
VOLUMEN_DISTINCT_USER_PRODUCT_FULL = [COMPLETAR query]
PORCENTAJE_PUBLICACIONES_FULL = [COMPLETAR]
TTL_FRESURA_RECOMENDACION = [ej. 7 días]
TECNOLOGIA_WORKERS = [ej. .NET / Hangfire / Azure Function / Windows Service]
REDIS_YA_EXISTE = sí/no
MOTOR_BD = [ej. SQL Server]
```
