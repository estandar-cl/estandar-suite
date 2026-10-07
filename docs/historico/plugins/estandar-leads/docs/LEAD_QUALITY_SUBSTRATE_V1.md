# Lead Quality Substrate — Canonical Contract (v1 + v2)

**Estado:** activo — macro-sprint calidad/contactabilidad/entendimiento real del lead.
**Ámbito:** Estandar Suite (legacy) + Estandar Suite v2 (Brain). Un contrato, dos implementaciones alineadas.

---

## 1. Por qué existe

El lead tenía una única lectura (score + status + quality HOT/WARM/COLD/JUNK) que mezclaba semánticas
distintas: sintaxis de contacto, contactabilidad real, perfil comercial, comportamiento y resultado
comercial. El sprint separa esas dimensiones en ejes canónicos ortogonales, con precedencia de
evidencia determinística y provenance auditable.

## 2. Dónde vive cada responsabilidad

| Capacidad | Suite actual (v1) | Suite v2 | 
|---|---|---|
| Contactabilidad teléfono/email | `Estandar_Leads_Contactability` | `Estandar\Brain\Contactability` |
| Calidad provisional/confirmada + precedencia | `Estandar_Leads_Quality` | `Estandar\Brain\Quality` |
| Feedback de operador → evidencia | `Estandar_Leads_Operator_Feedback` | `Estandar\Brain\OperatorFeedback` |
| Consistency checks | `Estandar_Leads_Consistency` | `Estandar\Brain\Consistency` |
| Perfil comercial sobre dimensiones canónicas | `Estandar_Leads_Profile_Map` | `Estandar\Brain\ProfileMap` |
| Behavior gate (technical_fetch=0, no double-count) | `Estandar_Leads_Scoring::sanitize_behavior_events` | `Estandar\Brain\Scoring::sanitize_behavior_events` |
| Runtime: persistencia + recálculo determinístico | `Estandar_Leads_Quality_Runtime` | `Estandar\Brain\QualityRuntime` |
| Backfill seguro (dry-run) | `tools/quality-backfill.php` | `wp estandar-suite brain quality backfill` |
| Taxonomía fit (preexistente, se conserva) | `Estandar_Leads_Fit` | (v2 lo consume vía Rules) |

Todas las clases son **PURE** (sin WP/DB obligatorio) y testables standalone.

## 3. Vocabularios canónicos (cerrados)

### 3.1 Contactability states (phone y email)
`VALID` · `PLAUSIBLE` · `SUSPICIOUS` · `INVALID` · `UNKNOWN`

- `VALID`: forma estricta consistente (patrón) o verificada por evidencia.
- `PLAUSIBLE`: bien formada pero sin verificación independiente.
- `SUSPICIOUS`: probable typo / patrón sospechoso. **Nunca invalida por sí solo.**
- `INVALID`: malformada o no normalizable.
- `UNKNOWN`: sin dato / sin evidencia.

### 3.2 Email — dimensiones separadas
`syntax_valid` · `domain_status` (TRUSTED/SUSPICIOUS/UNKNOWN) · `deliverability_status`
(UNKNOWN/CONFIRMED/FAILED — nunca inferido de sintaxis) · `contactability_status` ·
`validation_reason` · `confidence`.

**Regla:** un email sintácticamente válido con dominio sospechoso reduce confianza/contactabilidad,
NO convierte el lead en malo, y el valor original NUNCA se corrige silenciosamente. La sugerencia
(`suggested`) es informativa y nunca se aplica.

### 3.3 Quality states
`UNKNOWN` · `LOW_PROVISIONAL` · `MEDIUM_PROVISIONAL` · `HIGH_PROVISIONAL` ·
`LOW_CONFIRMED` · `MEDIUM_CONFIRMED` · `HIGH_CONFIRMED`

- `*_PROVISIONAL`: inferencia automática (source=automatic).
- `*_CONFIRMED`: respaldada por evidencia de operador/outcome.
- Cada estado lleva `quality_source`, `quality_confidence`, `quality_reason`,
  `provisional`, `confirmed`, `provenance`.

### 3.4 Operator feedback codes (abstractos, sin semántica vertical)
`unreachable` · `invalid_phone` · `invalid_email` · `wrong_contact` · `no_response`

### 3.5 Dimensiones canónicas (perfil común)
`contact_data_quality` · `contactability` · `stated_need` · `business_or_person_context` ·
`current_provider_status` · `urgency` · `purchase_or_start_timeframe` · `location` ·
`budget_or_capacity` · `intent_strength` · `engagement_strength` · `operator_feedback` ·
`commercial_outcome`

## 4. Precedencia de evidencia (determinística)

```
operator confirmed outcome        5   (mayor)
operator structured feedback      4
delivery/contactability evidence  3
automated commercial profile      2
behavioral evidence               1
capture completeness              0   (menor)
```

Una evidencia de precedencia igual o menor que una ya decidida NO sobreescribe; solo acumula
provenance. La evidencia histórica nunca se borra.

## 5. Gates de consistencia

El motor detecta y reporta (severidad blocker/warning):

1. `technical_fetch`/`prefetch`/`scanner`/`probable_machine_open` aportando puntos → **0 puntos**.
2. raw open signal + classified open event puntuando doble → se elimina el raw (classified es autoritativo).
3. HIGH/otra quality sin flag provisional/confirmed.
4. contactability INVALID sin confirmación de operador/delivery.
5. admission confundida con qualification (`QUALIFIED` como "admitido").
6. quality sin reason estructurado.
7. score no recalculado tras evidencia relevante.
8. campos verticales usados directamente por el scoring común.
9. reglas cliente/industria dentro del core.

## 6. Perfil comercial genérico (sin hardcoding)

```
campo vertical → mapping configurable → dimensión canónica → motor común
```

`field_mappings()` (filtrable) traduce campos verticales a dimensiones canónicas.
`value_mapping()` define tokens canónicos. El core solo conoce dimensiones canónicas.
`profile_strength` es independiente de `engagement_strength` y de la prioridad:
un perfil alto con behavior=0 y qualification=pending NO se degrada.

## 7. Fixtures sintéticos (no se usan datos reales)

| Fixture | Escenario | Comportamiento esperado |
|---|---|---|
| A `INVALID_CONTACT_CONFIRMED` | form completo, contacto sintácticamente aceptado, operador confirma inválido | contactability INVALID, quality consistente LOW_CONFIRMED, reason estructurado, provenance |
| B `SUSPICIOUS_EMAIL_UNCONFIRMED` | email sintácticamente válido, dominio sospechoso, sin evidencia humana | contactability SUSPICIOUS, quality UNKNOWN/PROVISIONAL, sin invalidación automática |
| C `HIGH_PROFILE_TECHNICAL_OPEN` | perfil alto, única apertura = technical_fetch, sin qualification humana | technical_fetch=0, behavior sin falso engagement, profile alto, qualification pending |
| D `SERVICE_BUSINESS_PROFILE` | vertical servicios: necesidad, proveedor actual, intención de cambio, plazo, ubicación | mismo motor, mapping propio |
| E `PRODUCT_SALES_PROFILE` | vertical producto: interés, intención de compra, plazo, ubicación, capacidad | mismo motor, mapping propio |
| F `APPOINTMENT_SERVICE_PROFILE` | vertical agenda: servicio, disponibilidad, urgencia, ubicación, contacto | mismo motor, mapping propio |

## 8. Recálculo

Ante evidencia nueva relevante se recalculan las dimensiones afectadas; se preserva el historial
(provenance con `previous_value`/`resulting_value`/`reason`/`source`). No se mantienen
score/temperature obsoletos.

## 8b. Runtime + persistencia (QUALITY_RUNTIME_V1, cierre de deuda 2026-08-07)

**Owner canónico del estado:** `Estandar_Leads_Quality_Runtime` (v1) y
`Estandar\Brain\QualityRuntime` (v2). Misma semántica, adaptación de almacenamiento.

### Modelo de persistencia (persistido vs derivado)

| Dimensión | Owner | Persistido | Storage |
|---|---|---|---|
| phone contactability | Quality Runtime | sí | state JSON |
| email contactability | Quality Runtime | sí | state JSON |
| domain/deliverability | Quality Runtime | sí | state JSON |
| provisional/confirmed quality | Quality Runtime | sí | `quality_state` (es_leads / estandar_brain_lead_quality) |
| quality_source/confidence/reason | Quality Runtime | sí | state JSON |
| commercial_profile_strength | Quality Runtime | sí | state JSON |
| engagement_strength | Scoring | derivado | score JSON |
| structured operator evidence | Operator Feedback | sí | event ledger / signal ledger |
| provenance / recalc metadata | Quality Runtime | sí | state JSON |

- **CURRENT STATE** → `es_leads.quality_state_json` (v1) / `estandar_brain_lead_quality.state_json` (v2), una única fila por entidad.
- **EVIDENCE / CHANGE HISTORY** → ledger canónico (`es_lead_events` con `lead_quality_state_changed`, `estandar_brain_signals` con `lead_quality_state_changed`) SOLO cuando el valor calculado realmente cambió.
- **No se tocan** las columnas legacy `quality` (HOT/WARM/COLD/JUNK), `commercial_fit`, `score*`.
- Schema aditivo e idempotente: v1 columnas `quality_state`/`quality_updated_at`/`quality_state_json` en `es_leads` (dbDelta, `SCHEMA_VERSION 3.9.0`); v2 tabla `estandar_brain_lead_quality` (reemplazo upsert por `entity_id`).

### Triggers de recálculo (determinísticos, sin loops)

| Trigger | Dimensiones afectadas |
|---|---|
| lead/capture ingested | todas (inferencia inicial) |
| operator feedback (`operator_feedback_evidence`) | contactability → quality |
| `autoresponse_open_classified` | behavior (via Scoring) |
| `lead_recontacted` / `status_changed` | calidad/contexto |

- `recompute()` es función pura de (lead_id, snapshot): misma entrada + mismo estado previo ⇒ mismo resultado (`signature()`).
- El evento de cambio NO es trigger de sí mismo (`is_recalc_event` lo excluye) → sin loops ni eventos duplicados.
- `apply_operator_feedback()` escribe el evento estructurado preservando la nota original y recalcula.

### Backfill seguro

- v1: `tools/quality-backfill.php` (`--limit N`, `--commit` para persistir; dry-run por defecto, idempotente, resumible).
- v2: `wp estandar-suite brain quality backfill [--commit] [--limit N]`.
- Distinción honesta: `UNKNOWN` (sin evidencia) ≠ `NOT_YET_RECALCULATED` (no procesado). Nunca se inventa calidad histórica.

## 8c. Defectos encontrados en la validación real (gate V1, 2026-08-07)

La validación contra WordPress + DB reales (`~/.dragon-work/v1-gate`, SQLite desechable)
reveló y corrigió dos defectos de diseño del runtime (no visibles en tests standalone puros):

### D1 — La evidencia del operador no se re-aplicaba desde el ledger
**Síntoma:** una recaptura sin feedback degradaba `phone=INVALID` (confirmado por operador)
a `VALID` — la confirmación se perdía al recalcular. **Causa raíz:** `derive()` solo aplicaba
el feedback del trigger actual; la evidencia persistida en `es_lead_events`
(`operator_feedback_evidence`) no se releía. **Fix:** `snapshot()` re-lee las evidencias del
operador del ledger y `derive()` las re-aplica a contactability y calidad (precedencia intacta).
Aplicado en v1 (`Estandar_Leads_Quality_Runtime`) y v2 (`Estandar\Brain\QualityRuntime`).

### D2 — La firma de idempotencia incluía ruido temporal
**Síntoma:** `recompute()` generaba eventos de cambio espurios y reportaba no-idempotencia.
**Causa raíz:** `signature()` serializaba los `evidence[]` anidados en phone/email cuyo
`timestamp` cambia en cada derivación. **Fix:** `signature()` conserva solo claves semánticas
(status/reason/confidence) de phone/email y excluye `evidence`/`original` y `recalc`/`provenance`.
El gate E2/E4/E5 confirma: misma evidencia ⇒ misma firma ⇒ sin escrituras ni eventos redundantes.

## 8d. Alineación con la versión canónica v13.0.170 (resolución de DEPLOY_READY=NO)

La validación sobre la instalación canónica (`Estandar_plugins`, header 13.0.170 / constante
13.0.162) reveló que la arquitectura real ya evolucionó: **Quality vive en
`Estandar\Core\Quality\*`** (ContactabilityEngine, LeadQualityModel, OperatorFeedbackParser,
LeadQualityRecalculator, LeadQualityConsistency) y estandar-leads usa **adapters** hacia ese
motor. El runtime `QUALITY_RUNTIME_V1` (v13.0.162) **no se portó** — su contrato ya está cubierto.

### Clasificación de capacidades en v13.0.170

| Capacidad | Clasificación | Dónde |
|---|---|---|
| contactability | ALREADY_CANONICAL | core `ContactabilityEngine` + adapter |
| structured operator evidence | ALREADY_CANONICAL | core `OperatorFeedbackParser` |
| provenance | ALREADY_CANONICAL | core `LeadQualityRecalculator` |
| deterministic recalculation | ALREADY_CANONICAL | core `LeadQualityRecalculator` |
| consistency checks | ALREADY_CANONICAL | core `LeadQualityConsistency::audit` |
| persistence / evidence ledger | ALREADY_CANONICAL | `es_leads.quality` + `quality_recalculated` event |
| **D1** evidencia sobrevive recaptura | ALREADY_RESOLVED | ingestion no toca `quality`; valor persistido |
| **D2** idempotencia por valor | ALREADY_RESOLVED | recalculator persiste solo si `recalculated=true` |
| **behavior scoring gate** | **NEEDS_EXTENSION** | **delta portado al scoring canónico** |

### Único delta real portado
`Estandar_Leads_Scoring::sanitize_behavior_events()` + uso en `calculate()`/`read_behavior_events()`
en `estandar-leads/includes/class-estandar-leads-scoring.php` (canónico). Resuelve el **double-count
real** (raw 3 + classified 6 = 9 → ahora 6) y `technical_fetch/scanner/prefetch = 0`.
V2 (brain 13.0.170) no necesita el gate: su vocabulary mapea solo `autoresponse_email_open_signal`
→ `email_open` (nunca raw+classified doble).

### Release candidate
- Fuente: `Estandar_plugins/wp-content/plugins/estandar-leads` (canónico v13.0.170).
- Cambio: 1 archivo — `includes/class-estandar-leads-scoring.php`.
- **NO incluye**: runtime v1, QUALITY_RUNTIME_V1, profile-map, datos, config ni secrets.
- Validado: gate v170 21/21 + mutex 48 + journey-wordpress 44 + clasificación open real.

## 9. Tests

- Suite actual: `estandar-leads/tests/run-contactability.php` (A–F, 38), `run-profile-map.php` (D–H, 15),
  `run-quality-runtime.php` (A–G runtime/persistencia, 28).
- Suite v2: `estandar-brain/tests/run-quality.php` (A–H, 24), `run-quality-runtime.php` (A–G, 24).
- Regresión: `run-scoring.php`, `run-assessment-engine.php`, `run-fit-taxonomy.php`,
  `run-assessment-accuracy.php`, `run-commercial-profile.php` verdes.

## 10. Configuración (filtros)

- v1: `estandar_leads_contactability_config`, `estandar_leads_operator_feedback_codes`,
  `estandar_leads_profile_map_mappings`.
- v2: `estandar_suite_brain_contactability_config`, `estandar_suite_brain_profile_map_mappings`.
