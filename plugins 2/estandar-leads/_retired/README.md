# _retired — código retirado (no se carga)

## `class-estandar-leads-scoring.php` (retirado 2026-09-17)

**Qué hacía:** `Estandar_Leads_Scoring` (BEHAVIORAL_SCORE_V1) — el scorer del
plugin legado `estandar-leads`. En `estandar_lead_ingested` (prioridad 20),
`estandar_lead_event_recorded` y un cron diario de decaimiento, calculaba el
score del lead y escribía en `es_leads`:
`behavioral_score`, `temperature`, `priority`, `score_updated_at`,
`score_components_json` (contrato: total/capture/behavior/priority_reason/
behavior_detail) y, si existían, `capture_score`/`capture_profile`.

**Por qué se retira:** el scorer canónico pasó a ser **Brain**
(`plugins/estandar-brain/includes/Scoring.php` + `LeadScorer.php`,
`LEAD_SCORE_CANONICAL_V1`). `LeadScorer` recalcula con `Scoring::calculate()`
en `estandar_lead_ingested` (prioridad 20, antes del notificador que lo lee en
25) y escribe las MISMAS columnas de `es_leads` con el MISMO contrato, tomando
el comportamiento read-only de `es_lead_events` (`Adoption::behavior_signals`)
y la intención de `IntentMap`. Así el email al operador y el panel siguen
funcionando, pero con una única fuente de verdad configurable por sitio
(`estandar_suite_brain_scoring_settings`).

**Cómo se desconectó:** se quitó de `estandar-leads.php` el `require_once` y la
llamada `Estandar_Leads_Scoring::init()`. El resto de referencias del plugin
legado ya estaban guardadas (`class_exists`/`defined`), por lo que no se rompe.

**Estado en producción:** el plugin legado `estandar-leads` NO está activo en
ninguna instalación desplegada (verificado 2026-09-17 en acacios + subsitio,
estandar, guillaume, rodrigovaldes). Este retiro es limpieza de repositorio.

**Para volver atrás:** reponer el `require_once` + `::init()` y mover el
archivo de vuelta a `includes/`.
