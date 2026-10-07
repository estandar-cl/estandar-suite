# Estandar Forms — Changelog

Versión actual: 13.0.158

> **Release 13.0.158 (DEPLOYED, 2026-08-04):** desplegado el paquete certificado `estandar-forms.zip`
> (SHA-256 `63616b19…be5`, manifest verificado). Ciclo completo: Release Engineering → Staging
> Acceptance (capturó y corrigió `datasets/provider.php` requerido y build determinista) → Production
> Deployment. Gates P-1..P-5 verdes: backups, despliegue solo-paquete (instalado ≡ paquete), smoke
> 309 tests/16 suites, flujos reales (clásico/survey/AltaCruz/AK/Postventa), validación comercial con
> lead + scoring hot/A, cero errores post-despliegue, cero pérdida de datos. Estado:
> **DEPLOYED SUCCESSFULLY · VALIDATED**.

> Nota: el plugin no tiene repositorio git independiente con historial. Este changelog
> se reconstruye a partir de comentarios en el código, constantes de versión en DB,
> y la estructura de migraciones. Las fechas son aproximadas.

---

## v13.0.165 — QA integral · Correcciones de la validación visual de AltaCruz (en desarrollo)

> Correcciones objetivas detectadas por el QA funcional real (HTTP, usuario anónimo) de la primera
> Experience. Sin cambios de contratos públicos.

- **Colisión de evento corregida:** el evento de scoring del motor se renombra de `estandar_lead_qualified`
  a **`estandar_conversation_lead_qualified`** — `estandar_lead_qualified` es un evento existente de
  `estandar-leads` con firma `(lead_id, old, new, changed_at)` (Google Ads export); emitirlo con 1
  argumento causaba `ArgumentCountError` en `Estandar_Leads_Google_Adapter`. Contrato de estandar-leads
  preservado.
- **Payload de lead CANÓNICO:** `build_lead_payload_from_entry` ahora emite el shape de ingesta de
  estandar-leads (`schema_version=1.0`, `source_plugin=estandar-forms`, `contact`/`content`/…); el
  `Estandar_Leads_Payload_Validator` rechazaba el shape anterior → el lead no se creaba.
- **Scoring por valor de elección:** `evaluate_scoring`/`normalize_config` soportan
  `weights[field] = { "valor": peso }` para respuestas de elección (string). Antes solo numérico/booleano
  → respuestas de elección puntuaban 0.
- **Validación funcional completa (localhost:10000):** flujo real start→answer×3→complete sobre la
  Experience "AltaCruz — Lead Qualification" (id 152): sesión `survey_completed`, scoring `hot/A`
  (35 pts), **lead creado** (`es_leads` 1769) + **submission** vinculada, funnel `ea_events` correcto.
  Compatibilidad: clásicos (22/126), incident (28) y survey (42) intactos; sin errores PHP tras el fix.

---

## v13.0.164 — MS-5/MS-6 · Primeras experiencias por configuración + Builder universal (en desarrollo)

> Tercera ola del Macro-Sprint. **Hipótesis validada:** una Experience se define únicamente por
> configuración (conversation_config + fields_json/widgets + identification + completion), reutilizando
> el motor, el render y el AJAX — sin runtime nuevo. Sin cambios de contratos públicos.

- **MS-5 · Lead Qualification (AltaCruz):** primera experiencia completa por configuración.
  - **Render conversacional por PERFIL:** `public/survey-render.php` ahora lee la config por
    `Estandar_Conversation_Engine::config_for_form()` — Survey → `survey_config`
    (identification_mode/hero/completion); otras experiencias → `conversation_config`
    (identification/flow/scoring/completion_strategy). Survey renderiza idéntico (regresión 42 intacta).
  - **Handler generalizado:** `get_identification_mode`, carga de campo rating y lookup de `complete`
    ya no filtran por `form_type='survey'`; leen la config del perfil. `maybe_run_engine_completion`
    usa `config_for_form` (scoring + completion lead desde `conversation_config`).
  - **Dispatch conversacional:** `class-shortcode.php` enruta cualquier
    `is_conversational(form_type)` al render compartido (antes solo `survey`).
  - Validado en vivo: form `lead_qualification` con `conversation_config` renderiza pantallas,
    widgets y `data-id-mode` correctos; Survey 42 intacto.
- **MS-6 · Catálogo de experiencias + Builder universal:**
  - **Evaluación múltiple (matrix) endurecida:** `validate_answer_value` valida cada score en `1..scale`
    (antes pasaba sin validar; R1 ya corregido en MS-1).
  - **Builder universal:** `admin/screens/survey-edit.php` añade selector **Tipo de experiencia**
    (Survey / Lead Qualification) y editor JSON `conversation_config`; persiste `form_type` +
    `conversation_config` para experiencias no-survey. Formatos de escritura dinámicos.
  - Catálogo: 8 experiencias (`survey`, `lead_qualification`, `postventa`, `cotizacion`, `wizard`,
    `assessment`, `quiz`, `booking`) — todas conversacionales, mismo runtime.
- **Tests nuevos:** `run-ms5-alta-cruz.php` (16: definición AltaCruz por config, config por perfil,
  generalización estructural) · `run-ms6-experiences.php` (11: matrix, catálogo, builder).
- **Regresión:** 16 suites verdes (verde completa; survey 91 intacta).

---

## v13.0.163 — MS-4b/c/d · Factorización del runtime + captura + desacoplamiento (en desarrollo)

> Segunda ola del Macro-Sprint. El runtime conversacional pasa a ser el motor; Survey queda como
> experiencia consumidora. Sin cambios de contratos públicos; encuestas intactas (regresión 14 suites).

- **MS-4b · Factorización del runtime:** estado de sesión centralizado en el motor
  (`SESSION_STATUS_*` + `session_status_label()`; el handler los usa vía aliases BC) y
  **validación/sanitización por tipo en el motor** (`validate_answer_value`: checkbox JSON +
  other_text, NPS 0-7, rating max, fallback a text). El handler ya no sanitiza inline ni consulta
  la definición por respuesta salvo para rating.
- **MS-4c · Convergencia de captura (capa única):** primitivas de persistencia en el motor
  (`capture_session_create/update_data/set_status/complete`, `capture_response_save`,
  `capture_response_link_entry`). El handler de Survey **delega todas las escrituras** a
  `estandar_form_entries`/`estandar_survey_responses`; ninguna queda inline. El pipeline clásico de
  Forms (`handle_submit`) no se toca (C4 futuro opcional).
- **MS-4d · Desacoplamiento del concepto Survey:** `flow_map()` como método canónico genérico
  (`survey_flow_map()` queda como alias BC); el motor no tiene métodos de runtime survey-named
  salvo el alias; `EVENT_SURVEY_COMPLETED` se conserva como evento de experiencia (contrato).
- **Tests nuevos:** `run-ms4b-runtime-extraction.php` (22) · `run-ms4c-capture-convergence.php` (15,
  con `$wpdb` simulado) · `run-ms4d-experience-decoupling.php` (7).
- **Validación en vivo:** ciclo completo start→answer→complete→link contra la DB real, con cleanup.

---

## v13.0.162 — MS-4 · Catálogo único de tipos de pregunta (en desarrollo)

> Cuarto micro-sprint del Macro-Sprint. Consolidación de catálogo (T2) — fuente única del set de
> tipos de pregunta. Sin cambios de contratos públicos.

- **`Estandar_Conversation_Engine::question_type_catalog()` / `is_supported_question_type()`** —
  fuente única de los 10 tipos (`nps, rating, matrix, emoji, cards, checkbox, text, select, radio,
  textarea`).
- **Handler** (`class-survey-handler.php`): `$allowed_types` delega en el catálogo del motor.
- **Builder PHP** (`survey-edit.php`): botones de tipos iteran el catálogo del motor (se incorpora
  `textarea`, antes ausente del builder).
- **Linter** (`class-template.php::survey_question_type_catalog()`): base en el catálogo del motor,
  mantiene el filtro `estandar_forms_survey_question_types`; incorpora `checkbox` (antes ausente).
- **Tests:** `tests/run-ms4-widget-catalog.php` — 10 tests. Regresión completa verde.

---

## v13.0.161 — MS-3 · Capacidades del motor sobre Survey (en desarrollo)

> Tercer micro-sprint del Macro-Sprint. Capacidades opt-in + eventos de dominio reales. Sin cambios
> de contratos públicos; encuestas por defecto intactas.

- **Eventos de dominio emitidos** desde `Estandar_Survey_Handler` (aditivos):
  `estandar_conversation_started` (start) · `estandar_answer_saved` (answer) ·
  `estandar_conversation_completed` + `estandar_survey_completed` (complete). Sin listeners → no-op.
- **Branching opcional sobre Survey:** `survey_config.flow.rules` → `survey_flow_map()` server-driven
  localizado en `EstandarSurvey.nextMap`; el JS salta a la pregunta destino (o fin) según la respuesta.
  Sin reglas → navegación lineal intacta. Reglas irresolubles ignoradas (fallback lineal).
- **Scoring + completion opt-in** en `handle_complete` (`maybe_run_engine_completion`):
  - `survey_config.scoring` → persiste `survey_result` en la sesión y emite `estandar_conversation_lead_qualified`.
  - `survey_config.completion_strategy.type = lead` → emite `estandar_lead_captured` (payload v1.0,
    ingesta canónica). Default `email` → comportamiento intacto.
- **Capacidades puras nuevas** en el motor: `survey_flow_map()`, `build_answers_from_responses()`,
  `normalize_answer_value()`, `completion_result()`.
- **Tests:** `tests/run-ms3-engine-capabilities.php` — 16 tests. Regresión completa verde
  (survey 91, form-engine 45, json-lifecycle, ms1 13, ms2 33).

---

## v13.0.160 — MS-2 · Contrato del Conversation Engine (en desarrollo)

> Segundo micro-sprint del Macro-Sprint. Contrato + columna aditiva; sin cambios de contratos públicos.

- **`Estandar_Conversation_Engine`** (`includes/class-conversation-engine.php`) — contrato del motor:
  constantes de **eventos de dominio** (11: started/step_presented/answer_validated/answer_saved/
  completed + survey_completed/lead_qualified/quote_generated/booking_requested/post_sale_completed/
  assessment_completed), **taxonomía de experiencias** (`is_conversational`), **fuente de config por
  tipo** (`config_for_form`: survey→`survey_config`, nuevas→`conversation_config`),
  **`normalize_config()`** (flow/scoring/completion/identification) y contrato de runtime
  (`resolve_next_step`, `evaluate_scoring`).
- **Columna aditiva `conversation_config`** en `wp_estandar_forms` (schema 2.4, `ensure_column` +
  `assert_schema`). Sin migración de datos.
- Cargado en bootstrap (`estandar-forms.php`). Sin hooks activos en MS-2 (contrato).
- **Tests:** `tests/run-ms2-engine-contract.php` — 33 tests. Regresión completa verde.

---

## v13.0.159 — MS-1 · Higiene de captura y configuración segura (en desarrollo)

> Macro-Sprint Conversation Engine — primer micro-sprint de código. Sin cambios de contratos públicos.

- **R1 (ADR 0034) — Columna fantasma `form_structure`:** las 8 queries que consultaban una columna
  inexistente ahora leen `fields_json` (schema real). Esto restaura la validación de rango de `rating`
  y los labels de filas `matrix` en email, visor seguro, resultados, CSV y analytics.
  Archivos: `class-survey-handler.php`, `class-survey-analytics.php`, `survey-results.php`.
- **R3 (ADR 0034) — Guardia de `form_type`:** `Estandar_Forms_Handler::handle_submit` rechaza
  explícitamente (telemetría `FORMS_UNHANDLED_FORM_TYPE` + error con redirect) cualquier `form_type`
  sin pipeline síncrono (survey y experiencias conversacionales usan AJAX), **antes** de perseguir
  spam/rate-limit y de persistir una entry. `general` (starter/legacy) pasa a ser sinónimo de `lead`
  en el pipeline (restaura la creación de leads del starter form). Se elimina el "éxito silencioso".
  Archivo: `class-forms.php`.
- **R9 (ADR 0034) — Guardado no destructivo de `survey_config`:** el editor de encuestas ahora fusiona
  determinísticamente (`estandar_forms_merge_survey_config`) preservando claves desconocidas, en lugar
  de reconstruir la config desde un set fijo de claves (pérdida silenciosa). Patrón `merge_config_json`
  (ADR 0032) aplicado a surveys. Archivo: `admin/screens/survey-edit.php`, `includes/helpers.php`.
- **Helpers puros nuevos:** `estandar_forms_is_standard_submit_type()` y
  `estandar_forms_merge_survey_config()`.
- **Tests:** `tests/run-ms1-hygiene.php` — 13 tests (R9 merge ×7, R3 guard ×4, R1 regresión ×2).
  Regresión completa verde: survey-checkbox 91, form-engine 45, json-lifecycle, pipeline-observability 23.

---

## v13.0.158 — Canonicalización arquitectónica (MS-0.5, documentación)

> Cambio **exclusivamente documental y de ADR** (MS-0.5). Sin código, sin contratos, sin despliegue.

- **Conversation Engine como abstracción principal (ADR 0034):** la arquitectura canónica declara
  que todas las experiencias conversacionales corren sobre un motor único (runtime, flujo,
  persistencia, navegación, validación y eventos pertenecen al motor, no a la experiencia).
- **Survey = primera experiencia** implementada sobre el motor, con compatibilidad completa
  (`form_type=survey`, `survey_config`, AJAX, Builder, Hooks, WP-CLI, JSON Schema, instalaciones).
- **Integraciones desacopladas:** Google Sheets, Excel Online, CRM, Email/Brevo, Calendar, Webhooks
  y futuras consumirán **eventos de dominio** del motor mediante adaptadores; el runtime no conoce
  ninguna integración.
- **Conocimiento transversal aprobado (AK Contadores)** incorporado como principios permanentes:
  adopción (descubrir→interpretar→reutilizar→absorber→adaptar), cero pérdida de configuración,
  contratos estables, compatibilidad Legacy ↔ Suite v2.
- **Consistencia documental:** corregidas contradicciones en DATABASE.md (schema de
  `estandar_survey_responses`), FORM-JSON-SCHEMA.md (shape de `survey_config`) y versiones
  (13.0.155 → 13.0.158). Detalle en el informe MS-0.5.
- **No implementado todavía:** Conversation Engine, eventos de dominio, checkboxes múltiples,
  evaluación múltiple, refactors, migraciones ni correcciones funcionales.

---

## v13.0.156–157 (no documentados)

Ventana de versión sin entradas en este changelog (deriva documental corregida en 13.0.158).
El código actual (13.0.158) incluye el GF Import V1 y el Survey Checkbox V1 descritos a
continuación.

## v13.0.156 (próximo)

**GF Import V1 — Gravity Forms → Estandar Forms importer reutilizable**
- Nueva clase `Estandar_Forms_GF_Importer` (`includes/class-gf-importer.php`)
- Pipeline modular: detect → parse → normalize → map → report
- Soporta 20+ tipos de campo GF → EF incluyendo lógica condicional
- Campos compuestos: `name` (advanced) → nombre + apellido
- Reporte de compatibilidad con porcentaje y detalle de pérdidas
- Admin UI: subir JSON, previsualizar compatibilidad, elegir form_type, importar
- Slug único automático al importar
- Testeado con GF Form ID 14 (36 campos, 91.7% compatibilidad)

**Survey Checkbox V1 — Selección múltiple con "Otro"**
- Nuevo tipo de pregunta `checkbox` para surveys
- Soporta selección de una o múltiples alternativas
- Opción `allow_other: true` incluye checkbox "Otro" con campo de texto
- Campo de texto se muestra/oculta/limpia al marcar/desmarcar "Otro"
- Validación: si "Otro" está marcado, requiere texto para avanzar
- Persistencia en JSON: `{"checked":[...values],"other_text":"..."}`
- Sanitización estricta server-side con `sanitize_text_field` + validación de estructura
- UI consistente con radio (bordes coloreados, hover, estado checked)
- CSS mobile-responsive (20px checkboxes en mobile)

**Archivos modificados:**
- `includes/class-survey-handler.php` — `checkbox` en `$allowed_types`, sanitización JSON, secure viewer
- `public/survey-render.php` — render HTML con opciones + "Otro" condicional
- `assets/js/estandar-survey.js` — extracción de respuesta, toggle "Otro", validación
- `assets/js/estandar-survey-admin.js` — `checkbox` en QUESTION_TYPES, `allow_other` toggle, sync
- `assets/css/estandar-survey.css` — estilos `.esvy-checkbox-group` + `.esvy-other-text`
- `admin/screens/survey-edit.php` — botón "Checkbox múltiple" en builder
- `tests/run-survey-checkbox.php` — 91 tests nuevos (estructura, encoding, sanitización, render, matrix regresión, roundtrip, viewer)

**Tests:** 91/91 PASS. Regresión de tipos existentes verificada (matrix "Evaluación múltiple" incluido).

---

## v13.0 (actual)

**FORMS_PIPELINE_OBSERVABILITY_V1**
- `debug_ref` en todas las respuestas de error para correlación con logs
- Etapas de fallo (`mark_failure_stage()`) con contexto estructurado
- `reemit_entry()` para reconciliación de entries huérfanas
- Detección de ingesta no confirmada (entry guardada sin lead)

**Template System v4.1**
- `.estandar-template.json` envelope v1.1
- Import con estrategias safe/resolve
- Editor JSON canónico (validate, diff, apply, history)
- Redaction de secrets (`__ESTANDAR_REDACTED__`)
- Duplicate form

**Surveys V1**
- API AJAX (start, event, answer, complete)
- Typeform-like step UI
- Funnel analytics (view → start → q_view → answered → completed → abandoned)
- Secure public response links via tokens
- Identificación: anonymous/identified/optional
- Schema v1.1 (migración no destructiva)

**Forms 4.0 — Incident/Request System**
- Tablas: `estandar_requests`, `estandar_incidents`, `estandar_request_attachments`
- Repeater UI en frontend (incidents[] + incident_files[])
- Pipeline de acciones: database, email, webhook
- Persistencia transaccional (START TRANSACTION → COMMIT/ROLLBACK)
- `form_type=incident`
- `incidents_config` — min/max, conditional field
- `actions_json` — pipeline post-submit

**Forms 4.0 — Dependent Select**
- Campo `dependent_select`: proyecto → calle dinámico
- AJAX endpoint `ef_get_streets`

**Experience Engine V1**
- Pre-calificación inmobiliaria (`real_estate_prequalification_v1`)
- Rule-based step flow
- Segmentación: subsidio_ds19, ds49
- `experience_config_json` en forms

**Qualification Engine**
- Lead scoring por perfiles configurables
- Score → tier → result → recommended project → routing hint → public message

**Secure Token System**
- Tabla `estandar_secure_tokens`
- Generación, validación, revocación con expiración
- Reutilizable entre módulos (survey, quote, form, report)

**Routing V2**
- `context_schema` con modos `none`, `fixed_project`, `project_selector`
- Migración automática `user_selector` → `project_selector`
- Validación server-side del slug en `project_selector`

**Anti-spam mejorado**
- 6 capas: honeypot, tiempo, rate limit, contenido, reCAPTCHA v3, token deduplication
- Content analysis: URL count + character repetition + keyword blocklist

**Feedback System**
- Transient-based feedback con `debug_ref`
- Soporte para `recontact` status (lead ya existente)
- AJAX + redirect dual-mode

**Meta Lead (Facebook CAPI)**
- `event_id` server-side derivado del lead persistido
- Anti-colisión: regeneración de UUID en recontacto
- Sin CAPI: fallback uuid v4 del frontend

**Email**
- `email_direct` form_type — email sin pipeline leads
- SMTP nativo via constantes PHP
- Template variables `{{form_name}}`

**Atribución**
- UTM + click IDs (Google, Facebook, TikTok, Microsoft)
- Session tracking con `ea_sid`
- First/last touch timestamps
- Attribution enrichment via estandar-analytics

---

## v1.x–1.7 (legacy)

**DB Schema v2.4**
- Columnas legacy: `notify_emails`, `form_country`, `store_entries`, `send_email`, `send_webhook`, `require_recaptcha`, `mail_subject`, `mail_body`, `mail_sender_name`, `mail_reply_to_field`, `submit_label`, `after_submit_text`, `visual_theme`

**Features base**
- Form builder visual (jQuery UI sortable)
- 15 tipos de campo
- Shortcode `[estandar_form slug="..."]`
- CSV export de entries
- RUT chileno (módulo 11)
- Phone specs por país (CL, AR, PE, MX, ES, US)
- Webhook fire-and-forget
- Conditional logic client + server
- reCAPTCHA v3 integración
- Admin menu 2-tier (Primer + standalone)
- Graceful degradation (todos los plugins externos opcionales)

---

## v1.0 (inicial)

- Plugin bootstrap
- Tablas: `estandar_forms`, `estandar_form_entries`, `estandar_form_logs`
- `[estandar_form]` shortcode
- Admin dashboard básico
- Submit handler con nonce + validación
- Starter form automático en activación
