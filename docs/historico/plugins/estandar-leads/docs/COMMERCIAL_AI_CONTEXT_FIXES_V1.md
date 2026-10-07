# COMMERCIAL_AI_CONTEXT_FIXES_V1 — estandar-leads 13.0.83 (2026-07-16)

Fase aprobada: corrección de los 9 errores detectados en el Commercial AI Context Export
y la lógica asociada de Leads/Ads Feedback. Sin rediseño de arquitectura; reutiliza los
resolvers canónicos existentes (`Estandar_Leads_Attribution`, `Estandar_Leads_QA_Policy`,
`Estandar_Commercial_Qualification_Engine`, `Estandar_Leads_Ads_Feedback_Observability`).

## 1. Separación canónica de conceptos (Fix 5 · ADMISSION_POLICY_V1)

Tres conceptos que ANTES se confundían bajo la palabra "qualified", ahora separados:

```
Admission Policy      ALLOWED / DISALLOWED — decisión del engine de reglas
                      (sin regla de exclusión = ALLOWED, reason "no exclusion
                      rule matched"). NUNCA se rotula "qualified".
Commercial Profile    COMMERCIAL_PROFILE_V1 — perfil derivado de los campos
                      comerciales capturados (score + breakdown auditable).
Qualification         RESERVADO para la clasificación comercial real del
                      operador. Sin clasificación = PENDING, jamás inferida.
```

El export ya no imprime `DEFAULT (qualified — no rules matched)`. En su lugar:
`Qualification: PENDING (no operator qualification recorded)` + `Admission Policy: ALLOWED`
+ `Admission Reason: no exclusion rule matched`.

## 2. COMMERCIAL_PROFILE_V1 (Fix 1)

`includes/class-estandar-leads-commercial-profile.php` (NUEVO). Derivación pura y
determinística desde `form_fields_json`/`context_json` de la última submission.

- Dimensiones genéricas (diccionario filterable `estandar_leads_commercial_profile_dimensions`):
  `need` (necesidad_principal…) · `company_status` (situacion_empresa…) ·
  `current_provider` (contador_actual…) · `start_timeframe` (plazo_inicio…) ·
  `location` (ciudad…). Matching por keywords ES con niveles y puntos explícitos.
- Regla high-intent congelada: `company_status=operating AND current_provider IN
  (switching,none) AND start_timeframe=immediate`.
- Salida: `profile_id` + `score 0-100` + `signals[]` (dimensión, campo, nivel, puntos,
  reason) — cero heurística opaca. Sin campos comerciales → `null` (nunca fabricado).
- NUNCA marca QUALIFIED · NUNCA alimenta plataformas de ads · solo scoring/prioridad/render.

### Integración en Scoring (`Estandar_Leads_Scoring`)
- Nuevo componente `profile` con `W_PROFILE = 0.20`; participa SOLO cuando existe
  (reweight idéntico a capture/qualification — leads sin perfil no cambian).
- `score_components_json` (aditivo): `profile`, `profile_id`, `profile_detail`,
  `profile_high_intent`, `weights_used.profile`.

## 3. OPERATIONAL_PRIORITY_V2 (Fix 2)

`priority_for()` recibe dos señales nuevas, ambas con reason auditable:
- `deep_engagement` (click/engaged de autorespuesta dentro de la ventana de decay —
  un clic pesa más que una apertura: w=12 vs w=3 cap 6, ya vigente en el score):
  `TIBIO + sin gestión → ALTA (tibio_engagement_reciente_sin_gestion)` ·
  `CALIENTE + sin gestión → CRITICA` · `FRIO + sin gestión → MEDIA`.
- `profile_high_intent`: `TIBIO + sin gestión → ALTA (tibio_perfil_comercial_alto_sin_gestion)`.

El comportamiento NUNCA se convierte en calificación comercial ni en feedback QUALIFIED
a plataformas (la elegibilidad de ads no lee prioridad ni score).

## 4. META_GATE_V1 (Fix 3)

`Estandar_Leads_Meta_Adapter::dispatch_gate()` = único gate antes de encolar y despachar.
Compone (sin re-implementar) los SSOT existentes:
1. QA/test + `ads_feedback_policy` → `Estandar_Leads_QA_Policy::ads_feedback_blocked`.
2. Canal canónico Meta + evidencia válida → `Estandar_Leads_Attribution::platform_eligibility('meta')`
   (exige channel=meta_ads Y fbclid/fbc; **`_fbp` solo jamás habilita**; una adquisición
   Google no puede producir eventos Meta nuevos).

Rechazos persistidos (`record_skip`): evento `ads_dispatch_skipped` en `es_lead_events`
con `{platform, decision: not_eligible|skipped_by_policy, reason, channel, evidence}`.
QA/test leads se suprimen en silencio (sin traza — tráfico de prueba). En dispatch
(send), la inelegibilidad marca la fila `dead` con prefijo `skipped/not_eligible:` +
fila `status='skipped'` en `es_commercial_outcomes_log` — la observabilidad la renderiza
NOT_ELIGIBLE, nunca FAILED. Trazas históricas intactas; los eventos ya enviados no se
reescriben. Sin cambio de schema (enums de cola sin tocar).

## 5. GOOGLE_GATE_V1 + OCI_OBSERVABILITY_V1 (Fix 4)

Mismo gate espejo en `Estandar_Leads_Google_Adapter` (evaluado independiente de Meta).
Contrato de telemetría OCI — cada decisión/intento persiste en `es_commercial_outcomes_log`:

```
decision            eligible | not_eligible (skipped)
mode                validateOnly | send
identifier          gclid | gbraid | wbraid (evidencia usada)
conversion_action   conversion_action_id configurado
attempted_at        timestamp del intento
http_code / message / request_id / duration_ms   resultado API + error normalizado
```

El export NUNCA infiere éxito sin fila persistida: sin ejecución →
`PLATFORM_CONFIRMATION_UNKNOWN` (invariante de honestidad intacto); dry-run `validated`
sigue siendo UNKNOWN ("Google no registró conversión"). Esta fase NO activa envíos
reales ni toca conversion actions/configuración.

## 6. META_ATTRIBUTION_STATUS_V1 (Fix 6)

`Attribution Status` del bloque Origin ahora es consciente de plataforma:
- Canal Meta + fbclid/fbc → `explicit_meta_attribution (evidence: fbclid · provenance: explicit_capture)`.
- Canal Meta + UTM Meta/campaña resuelta → `captured (utm_source=… + campaign_resolved · provenance: utm_capture)`.
- Solo sin evidencia → `unavailable`. Los campos ValueTrack Google conservan su semántica.

## 7. EXPORT_TIMEZONE_V1 (Fix 7)

Convención declarada en Runtime Context (`Timestamp Convention`). Fechas clave
(First/Last Seen, fechas de submission, Assigned at) renderizan
`YYYY-MM-DD HH:MM:SS UTC (YYYY-MM-DD HH:MM America/Santiago)`; el Timeline declara UTC.
Los timestamps almacenados NUNCA se alteran (display-only, DST manejado por tzdata).

## 8. SESSION_LANDING_FALLBACK_V1 (Fix 8)

Cuando la sesión no existe o está incompleta (sin landing), el bloque Session resuelve
la landing de forma no destructiva con procedencia explícita:
`session → submission (context_json.source_page_url → landing_url) → lead.first_landing_url`,
renderizando `(fallback source: … — session landing unavailable)`. El WARNING de
Analytics Quality por sesión incompleta se conserva (el fallback no lo limpia).
`Estandar_Leads_Attribution::canonical_landing()` (LANDING_PRECEDENCE_V1) queda intacto
para sus consumidores actuales.

## 9. COMPLIANCE_STATE_SSOT (Fix 9)

`compliance_engine_state()` = única definición del estado del Compliance Engine en el
export. Engine Pipeline y AI Summary consumen la MISMA fuente: compliance activo
(`feedback_status=pending`) o completo = PASS → el summary jamás lo lista "Pending".
Solo WARNING (overdue/escalated) o ausencia de estado aparecen como pendiente.

## 10. COMMERCIAL_SNAPSHOT_V5_1 (editorial)

El export añade `Commercial Snapshot` inmediatamente después de `Runtime Context`.
Es un índice compacto, de sólo lectura, para Lead, estado, proyecto, operador, canal,
temperatura, prioridad, siguiente acción, espera de contacto y SLA. No calcula score,
riesgo, elegibilidad ni estado comercial nuevo: reutiliza columnas del lead, el resolver
canónico de canal, la acción determinística ya existente y el SLA ya renderizado. Riesgo
operativo se omite porque no existe un campo/estado canónico único que permita mostrarlo
sin introducir una inferencia nueva.

Para quitar repetición se eliminan del render `Commercial Readiness`, `Action`, `Machine
Context`, `AI Summary`, `Lead Intelligence` y `Missing Context`. La evidencia que los
fundamenta permanece en `Engine Pipeline`, `Engine Decisions`, `Scoring`, `Ads Feedback
Observability`, `Timeline`, `Recommended Operational Action` y `Raw Sources`; los
hallazgos, checks de consistencia, procedencia y diagnósticos v5.1 no se eliminan.

`Raw Sources` continúa al final y en bloques `<details>` colapsados. `Runtime Metrics`
se limita a tiempo, consultas, memoria y eventos; los contadores de estados de engine se
retiran porque ya están representados por `Engine Pipeline`. El formato editorial cambia de
Export Version 5.0 a 5.1; los datos y resolvers son compatibles semánticamente, pero los
consumidores que dependan de índices o encabezados numéricos deben aceptar el nuevo orden.

## 11. ROUTING_NOTIFICATION_EXPLAINABILITY_V1 (2026-07-17, Export Version 5.2 → 5.3)

Separa semánticamente "por qué se enrutó así" de "cuándo/cómo se entregó la notificación" dentro de
`Diagnostics → build_v51_diagnostics()`. Presentación/lectura pura sobre `es_lead_events` — cero eventos
nuevos, cero cambios de scoring/Commercial Profile/Qualification/Admission Policy (siguen orthogonal y
CLOSED per canon).

**Bug encontrado**: `diag_v51_routing_decision()` mezclaba `operator_assigned` y `operator_notified` y
tomaba "el último evento" como `Latest routing_mode`. El notifier (`class-estandar-leads-notifier.php`)
escribe `routing_mode` con granularidad distinta en cada evento — `operator_assigned` guarda el valor
específico del resolver (`project_match`/`allowed_project_match`/`fallback`, línea ~150), pero
`operator_notified` siempre escribe el literal genérico `'project'`/`'fallback'` (línea ~455, sin pasar
por el resolver). Como `operator_notified` ocurre después, su valor más pobre pisaba al de
`operator_assigned` en el render — el diagnóstico mostraba menos información de la que el sistema
realmente tenía.

**Fix**: `diag_v51_routing_decision()` ahora filtra exclusivamente `operator_assigned` — única fuente
canónica de la decisión de enrutamiento (LEAD-INVARIANT-001 del propio notifier: la asignación ocurre
antes del gate de horario operacional, nunca depende de si la notificación se envía). `operator_notified`
deja de participar en absoluto en este bloque.

**Bloques nuevos** (`Diagnostics → v5.1`, ambos read-only sobre `es_lead_events`, agrupados por
`submission_id`):

- **`Notification Delivery (v1)`** — separa la entrega de la decisión de ruteo. Por submission:
  `status` (delivered / queued-pending / consolidated / skipped by policy / not attempted),
  `delivery_source` (`immediate` | `business_hours_queue` | `business_hours_queue (requeued Nx)`),
  `queued_at`/`delivered_at`/`delay` cuando aplica, `reason`, operador receptor si está en metadata.
  Un delay causado por `operator_notification_deferred` (`reason=outside_operational_hours`, el gate
  `Estandar_Operational_Hours` del notifier) se etiqueta explícitamente como **POLICY, no problema**
  ("this is delivery POLICY (business hours), not a problem"). Evidencia real de re-encolado (≥2 eventos
  `operator_notification_deferred` para la misma submission antes de la entrega — posible por
  reentrada del cron/sweep) se muestra como "requeued Nx" con nota explícita de que es evidencia real,
  no un contador de retry fabricado — el notifier **no emite** un tipo de evento `retry` distinguible
  para notificaciones de operador (eso existe solo en los adapters Meta/Google/OCI, pipeline distinto);
  documentado honestamente en vez de inventar el enum.
- **`Behavior Evidence (v1)`** — agrega telemetría de autoresponse: sent/failed/skipped, opens
  (explícitamente "referencial — nunca KPI, puede ser un auto-scanner de cliente de correo"), total de
  clicks, y **tipos de recurso distintos** (`pdf`/`landing`/`whatsapp`) clickeados. `autoresponse_engaged`
  se explica citando su evidencia real: el `channel` del evento que lo disparó, consistente con
  `record_engagement_if_new()` en `class-estandar-leads-autoresponse.php` (dispara una sola vez por
  lead+submission, en el primer click de pdf/landing/whatsapp). **"Unique links" no se calcula**: el
  evento solo guarda `resource_type`, no un identificador por URL — se reporta "distinct resource types"
  con una nota explícita de por qué no se puede ir más fino, en vez de inventar unicidad que el dato no
  soporta (regla de oro del proyecto: ausencia de evidencia se muestra, no se rellena).

**Commercial Snapshot (§2)**: fila nueva `Notification Status` — un puntero de una línea al veredicto
más reciente de la familia de eventos de notificación (`delivered` / `queued (business hours policy)` /
`consolidated` / `skipped by policy` / `blocked before delivery` / ausencia total), con referencia
explícita a `Notification Delivery (§9)` para el detalle completo. Una sola query barata
(`notification_status_summary()`, mismo patrón que `has_event()`), sin duplicar el contenido del bloque
nuevo — es un índice editorial, no una segunda fuente de verdad.

**Compatibilidad**: aditivo. Ningún encabezado ni campo existente se elimina o renombra; `Routing
Decision (v5.1)` conserva su formato de líneas (`routing_mode: **{mode}**`) y su nota final
`**Latest routing_mode:**`, solo cambia qué eventos alimentan ese valor. Exports históricos (leads sin
eventos `operator_notification_deferred`/`autoresponse_*`) renderizan "No notification delivery events
recorded for this lead." / "No autoresponse/behavior telemetry recorded for this lead." — nunca fabrican
un estado. **Export Version 5.2 → 5.3** (bump documentado en `Compatibility` de `Runtime Context`, no
solo en el campo `Export Version`) porque el valor semántico de `Latest routing_mode` cambia para leads
con eventos `operator_notified` cuyo `routing_mode` difiere del de `operator_assigned` — un consumidor
que dependiera del comportamiento anterior (valor "más reciente" independientemente de la fuente) vería
un valor distinto, aunque la clave/formato de la línea es idéntica.

**Archivo tocado**: `includes/class-estandar-leads-ai-context.php` (`build_v51_diagnostics()`,
`diag_v51_routing_decision()` reescrito, `diag_notification_delivery()` NUEVO, `human_delay()` NUEVO,
`diag_behavior_evidence()` NUEVO, `build_runtime_context()` bump 5.2→5.3 + nota de compatibilidad).
No se tocó `class-estandar-leads-notifier.php` ni `class-estandar-leads-autoresponse.php` — solo lectura.

**Tests**: NUEVO `tests/run-ai-context-notification-explainability.php` — 22/22 PASS. Cubre: asignación
+ notificación inmediata · notificación demorada por horario operacional (delay explicado como policy)
· evidencia real de re-encolado (2 eventos deferred antes de entrega) · ausencia total de telemetría de
notificación/autoresponse · `operator_notified` NO sobrescribe el `routing_mode` de `operator_assigned`
· `autoresponse_engaged` citando su evidencia (`channel`) · clicks repetidos vs. tipos de recurso
distintos (sin fabricar unicidad por URL) · export histórico sin estos campos no rompe (sin fatal, sin
warnings) · los 2 bloques nuevos aparecen exactamente una vez y no duplican Timeline/Raw Sources.
Sin regresión: `tests/run-ai-context-export.php` **33/33 PASS** (sin cambios en el archivo de test).

**GATE deploy**: `php -l` en el archivo tocado + ambas suites en verde en la máquina de Rodrigo antes de
subir a producción.

## 12. NOTIFICATION_POLICY_VS_DELIVERY_V1 (2026-07-17, hardening pass sobre §11, Export Version 5.3 → 5.4)

Endurecimiento de contrato semántico sobre §11 — **sin arquitectura ni comportamiento funcional nuevo**:
mismo pipeline de lectura de `es_lead_events`, cero eventos nuevos, cero cambios a
`class-estandar-leads-notifier.php` ni `class-estandar-leads-autoresponse.php`.

**Tres distinciones canónicas** (vinculante para cualquier extensión futura de Diagnostics):

- **Routing ≠ Notification.** Ya vigente desde §11: `Routing Decision (v5.1)` responde "qué proyecto/
  operador se resolvió" (fuente única: `operator_assigned`); nunca menciona si/cuándo se notificó.
- **Notification Policy ≠ Notification Delivery.** Nuevo en este pass. Antes de §12, el bloque
  `Notification Delivery` mezclaba la REGLA aplicada (razón de horario laboral, política de
  calificación comercial, debounce) con el RESULTADO observado (se entregó o no, cuándo) — el mismo
  campo servía para dos preguntas distintas, violando la regla "cada concepto en su propio campo".
  Ahora son dos bloques separados en `Diagnostics → v5.1`:
  - **`Notification Policy (v1)`** (nuevo) — responde "qué gate se activó y por qué": Business Hours
    Gate (`operator_notification_deferred`, razón + `scheduled_for`), Debounce Consolidation Policy
    (`operator_notification_consolidated`), Commercial Qualification — Notification Policy
    (`notification_skipped_by_policy`), Commercial Qualification — Routing Policy
    (`routing_unresolved`/`routing_skipped_by_policy`). Toda razón/reason vive AQUÍ y solo aquí.
  - **`Notification Delivery (v1)`** (existente, reescrito) — responde solo "¿llegó, cuándo, con qué
    demora?". Ya no imprime la razón de política inline (la línea `**Reason:**` se ELIMINÓ de este
    bloque); en su lugar cada status apunta de vuelta con "see Notification Policy above". El campo
    `**Delay:**` sigue mostrando el valor y la advertencia "not a problem", pero la razón puntual del
    gate ya no se repite ahí.
- **Behavior Evidence ≠ Commercial Interpretation.** Ya vigente desde §11, ratificado explícitamente
  aquí: el bloque solo agrega evidencia observable (opens/clicks/tipos de recurso/`autoresponse_engaged`
  con su evento disparador) — no infiere intención, temperatura, ni prioridad comercial. Ese trabajo
  vive en Scoring/Commercial Profile, secciones distintas y ortogonales, sin tocar.

**Vocabulario canónico de `Notification Delivery.Status`** (5 nombres fijos solicitados; documentado en
código como comentario sobre `diag_notification_delivery()`, no solo en este doc):

| Status | Evidencia real hoy | Nota |
|---|---|---|
| `delivered` | SÍ — evento `operator_notified` | |
| `deferred_by_policy` | SÍ — evento `operator_notification_deferred` sin `operator_notified` posterior | reemplaza el string informal "queued" de §11 |
| `scheduled` | **NO** — no es un evento distinto | `scheduled_for` es metadata informativa de `deferred_by_policy`, no un estado propio; el notifier nunca emite un evento "scheduled" separado |
| `queued` | **NO** — no existe cola genérica | el único estado "aún no entregado" que el notifier produce es `deferred_by_policy`; no hay evidencia de una cola distinta |
| `failed` | **NO** — `LEADS_NOTIFIER_WP_MAIL_FAILED` solo escribe a `error_log()`/telemetría | ninguna fila en `es_lead_events` se inserta cuando `wp_mail()` falla hoy — si eso cambia, este status pasa a tener evidencia sin rediseño |

Estados FUERA de este set de 5 (responden "¿se intentó siquiera?", pregunta distinta): `not_attempted`
(bloqueo de routing), `skipped_by_policy` (política comercial), `consolidated` (fold por debounce) — se
mantienen como etiquetas propias, no forzadas dentro de los 5 nombres.

**Behavior Evidence — verificación de label inequívoco**: se confirmó que el único campo renderizado es
`**Distinct resource types clicked:**` (nunca "unique links" como nombre de campo); la frase "unique
links" solo aparece en un comentario/nota explicando qué NO se puede calcular y por qué, nunca como
label de un valor.

**Compatibilidad**: NO aditivo puro — cambio de contrato real dentro de `Notification Delivery`, por
eso el bump de versión (a diferencia de §11, que fue aditivo). Un consumidor de v5.3 que dependiera de:
(a) la línea `**Reason:**` dentro de `Notification Delivery` — REMOVIDA, ahora solo en `Notification
Policy`; (b) el string `queued — still pending delivery` — renombrado a `deferred_by_policy`; (c) el
string `not attempted` (con espacio) — renombrado a `not_attempted`; (d) el string `skipped by
commercial policy` — renombrado a `skipped_by_policy`; se rompería. Encabezados existentes
(`### Routing Decision (v5.1)`, `### Notification Delivery (v1)`, `### Behavior Evidence (v1)`) NO se
renombran ni eliminan — solo se agrega el encabezado nuevo `### Notification Policy (v1)` entre ellos.
**Export Version 5.3 → 5.4**, documentado en el campo `Compatibility` de `Runtime Context` con el detalle
exacto de qué string cambia.

**Archivo tocado**: `includes/class-estandar-leads-ai-context.php` (`build_v51_diagnostics()` — inserta
la llamada a `diag_notification_policy()`; `diag_notification_policy()` NUEVO; `diag_notification_delivery()`
reescrito — quita `Reason:`, renombra statuses, agrega comentario de vocabulario canónico;
`notification_status_summary()` — statuses del Commercial Snapshot alineados al mismo vocabulario;
`build_runtime_context()` bump 5.3→5.4 + nota de compatibilidad detallada). Ningún otro archivo de
producción tocado.

**Tests**: extendido `tests/run-ai-context-notification-explainability.php` — **38/38 PASS** (22
preexistentes de §11 sin modificar + 16 nuevos: routing inmediato + notificación diferida con separación
Policy/Delivery verificada · routing inmediato + entrega inmediata sin gate · múltiples defers con
Policy mostrando ambas aplicaciones del gate y Delivery mostrando el requeue count · ausencia total de
telemetría de notificación con Routing renderizando igual de forma independiente · compatibilidad
histórica confirmando que `queued`/`scheduled`/`failed` nunca se fabrican). Sin regresión:
`tests/run-ai-context-export.php` **33/33 PASS** (sin cambios en ese archivo de test).

**GATE deploy**: `php -l` en el archivo tocado + ambas suites (71 checks totales) en verde en la máquina
de Rodrigo antes de subir a producción.

## Archivos modificados

```
NUEVO   includes/class-estandar-leads-commercial-profile.php
        includes/class-estandar-leads-scoring.php
        includes/class-estandar-leads-meta-adapter.php
        includes/class-estandar-leads-google-adapter.php
        includes/class-estandar-leads-ads-feedback-observability.php
        includes/class-estandar-leads-ai-context.php
        estandar-leads.php                              (include + bump 13.0.83)
NUEVO   tests/run-commercial-profile.php                (aceptación A + D, 20 checks)
NUEVO   tests/run-meta-gate-skip.php                    (aceptación C, 11 checks)
NUEVO   tests/run-google-oci-observability.php          (Fix 4, 9 checks)
        tests/run-ai-context-export.php                 (aceptación B + E, +7 checks)
        tests/run-manual-override.php                   (contrato Admission Policy)
NUEVO   tests/run-ai-context-notification-explainability.php  (11. ROUTING_NOTIFICATION_EXPLAINABILITY_V1, 22 checks)
```

## Validación (2026-07-16, PHP local)

- `php -l` PASS en los 12 archivos tocados.
- Suites estandar-leads: **55/55 en verde** (incluye las 3 nuevas). Sin regresión en
  run-meta-adapter 17/17 · run-platform-eligibility 20/20 ·
  run-ads-feedback-observability 65/65 · run-scoring 20/20 · run-qualification-* ·
  run-semantic-status · run-landing-precedence.

**GATE deploy**: correr la suite en la máquina de Rodrigo + revisar un export real en
SALADEPRUEBAS (lead AK con campos comerciales y lead Meta) antes de subir a producción.
