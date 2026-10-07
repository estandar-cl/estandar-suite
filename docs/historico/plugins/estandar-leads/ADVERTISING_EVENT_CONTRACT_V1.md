# AdvertisingEvent Contract V1 — Leads↔Signal decoupling (Phase 1)

## Ownership
- **estandar-leads** = único Source of Truth comercial. Decide qué hechos son *advertising-exportables* (política canónica `Estandar_Leads_QA_Policy::ads_feedback_blocked` = QA + `ads_feedback_policy`) y resuelve el nombre del evento (`event_for_status`, reubicado desde Signal).
- **estandar-signal-core** = infraestructura pura (ledger técnico, cola, retry, scheduler, exporters). Ya NO lee `es_leads.status`, no aplica whitelist ni interpreta el dominio en el flujo nuevo.

## Contrato — `do_action( 'estandar_advertising_event_ready', array $event )`
Emisor: `Estandar_Leads_Advertising_Event` (`class-estandar-leads-advertising-event.php`).

| Campo | Tipo | Nota |
|---|---|---|
| `event_id` | string | uuid de traza (único por emisión) |
| `event_name` | string | `lead_created\|lead_contacted\|lead_qualified\|meeting_booked\|sale_closed\|lead_rejected` |
| `occurred_at` | string | MySQL UTC |
| `project_slug` | string | |
| `lead_id` | int | |
| `submission_id` | int | 0 si no aplica |
| `transaction_id` | string | estable por (lead, event) — idempotencia de plataforma |
| `lifecycle_key` | string | base determinista de idempotencia (opaca para Signal) |
| `identifiers` | array | gclid, gbraid, wbraid, fbclid, meta_fbc, meta_fbp, ttclid, msclkid |
| `identity` | array | lead_uuid, session_id, email_normalized, phone_normalized (normalizado, **NO** hasheado) |
| `currency` | string | '' si no hay |
| `value` | float\|null | solo si hay valor real |
| `meta` | array | engine, quality (metadata técnica mínima) |

**Nunca** transporta: status crudo, sales_stage, whitelist, reglas de calificación ni el Lead completo.

## Clasificación de PII
`identity.email_normalized` y `identity.phone_normalized` son **PII interna** (constante `Estandar_Leads_Advertising_Event::PII_KEYS`). Van dentro del evento **a propósito**: son la identidad normalizada mínima para Enhanced Matching. El criterio de seguridad NO es "PII ausente del evento" sino **"PII ausente de la observabilidad"**:
- nunca en logs, telemetría, excepciones ni payloads de debug;
- el evento viaja solo en memoria del emisor al consumidor;
- se persiste únicamente como hash SHA-256 (`es_signal_events.email_hash`/`phone_hash`), nunca en claro (`identity_json`/`payload_json` no contienen email/phone);
- no se persiste PII adicional fuera del modelo `es_signal_*` ya existente.

## Política final de hashing (refleja la implementación existente)
- **Leads** entrega en el evento la identidad **normalizada, sin hashear** (in-memory).
- **Signal** nunca persiste email ni teléfono en claro: al persistir en `es_signal_events` deriva y guarda únicamente los hashes del modelo actual — `email_hash`/`phone_hash` = `advertising_hash()` = SHA-256(valor normalizado). `identity_json`/`payload_json` no contienen PII en claro.
- **Exporters** (Google OCI, Meta CAPI) siguen siendo los únicos responsables de cualquier transformación, normalización o hashing **adicional** que exija la plataforma (Google/Meta Enhanced Matching). El hashing NO se mueve entre componentes y el modelo de datos NO cambia.

## Flujo antes → después
- **Antes:** `estandar_lead_status_changed` → `Estandar_Signal_Core_Hooks::on_status_changed` (lee `es_leads`, aplica whitelist `event_for_status`, mapea `lead_qualified→qualification`) → `record_from_lead`.
- **Después:** `estandar_lead_status_changed`/`estandar_lead_ingested` → `Estandar_Leads_Advertising_Event` (decide + normaliza) → `estandar_advertising_event_ready` → `Estandar_Signal_Core_Advertising_Listener` → `Estandar_Signal_Core_Repository::record_from_event` (deriva solo campos técnicos: `advertising_hash`, `idempotency_key`, `lifecycle_key` fallback, `export_status`; encola con la infra existente).

## Capability del emisor (detección explícita)
Filtro estable: **`estandar_advertising_event_emitter_available`**. `Estandar_Leads_Advertising_Event::init()` registra el callback `declare_available` **al final** de init — solo después de que la clase se cargó, init terminó y ambos hooks de emisión (`estandar_lead_ingested`, `estandar_lead_status_changed`) quedaron registrados (flag interno `$ready`). Devuelve `true` únicamente en ese estado.

Signal Core consulta `apply_filters('estandar_advertising_event_emitter_available', false) === true` (nunca `class_exists`): una clase cargada-pero-no-inicializada mantiene el fallback activo. Sin dependencia dura entre plugins (solo un filtro; el default es `false`).

## Exporter Google OCI (Fase 2)
`Estandar_Signal_Core_Google_OCI::export($signal)` consume **exclusivamente** la fila técnica persistida de `es_signal_events`: `event_name`, `event_time`, `gclid/gbraid/wbraid`, `signal_uuid` (→ `orderId`, dedup), `value_decimal`, `currency`, `email_hash`, `phone_hash`.
- **Origen de `event_name`:** el valor persistido por el contrato AdvertisingEvent. El exporter NO lo reconstruye, NO consulta `es_leads`, NO inspecciona `status`/`sales_stage`, NO llama `event_for_status()` y NO aplica lógica de negocio.
- **QA/test:** ya no se gatea en el exporter (se eliminó `is_test_lead`). La exclusión es aguas arriba: un lead QA nunca produce AdvertisingEvent → nunca se persiste como señal → nunca llega al exporter.
- **Mapping localizado en el exporter** (`conversion_map`, wp_option `estandar_signal_core_google_conv_*`):

  | event_name | Google Conversion Action (resource) |
  |---|---|
  | lead_created | `..._conv_lead_created` |
  | lead_contacted | (configurable) |
  | lead_qualified | (configurable) |
  | meeting_booked | `..._conv_meeting_booked` |
  | sale_closed | `..._conv_sale_closed` |
  | lead_rejected | (configurable) |

  `event_name` sin conversion action configurada → skip (éxito, sin red). Señal sin click id (gclid/gbraid/wbraid) → skip.
- **Sin cambios** en retries, batching, scheduler, idempotencia (`orderId=signal_uuid`), observabilidad ni manejo de errores. Los hashes se **reutilizan** desde la fila (`email_hash`/`phone_hash`), no se recalculan; el hashing adicional de plataforma sigue siendo del exporter.

## Exporter Meta CAPI (Fase 3)
`Estandar_Signal_Core_Meta_Capi::export($signal)` consume **exclusivamente** la fila técnica persistida de `es_signal_events`: `event_name`, `event_time`, `email_hash`, `phone_hash`, `meta_fbc`, `meta_fbp`, `identity_json`→`lead_uuid` (→ `external_id`), `project_slug`, `value_decimal`, `currency`, `signal_uuid` (→ `event_id`).
- **Origen de `event_name`:** el valor persistido; el exporter NO lo reconstruye, NO consulta `es_leads`/`es_lead_submissions`, NO inspecciona `status`/`sales_stage`, NO llama `event_for_status()`, NO decide exportabilidad ni aplica lógica de negocio.
- **QA/test:** se eliminó `is_test_lead` del exporter (igual que Google OCI). La exclusión es aguas arriba: un lead QA nunca produce AdvertisingEvent → nunca se persiste → nunca llega al exporter.
- **Mapping localizado en el exporter** (`EVENT_MAP`): `lead_created→Lead`, `meeting_booked→Schedule`, `sale_closed→Purchase`. `event_name` sin mapping Meta → skip (éxito, sin red).
- **Sin cambios:** payload Meta, `event_id = signal_uuid` (dedup Browser/Server), retries, batching (1 evento por call), scheduler, observabilidad y manejo de errores (200→true · 5xx/red→retry · 4xx→Exception permanente). Los hashes (`em`/`ph`/`external_id`) se **reutilizan** desde la fila; no se recalcula identidad.

## Fallback legacy (temporal)
`Estandar_Signal_Core_Hooks` queda **@deprecated** como fallback: `legacy_superseded()` = capability `=== true` → si el emisor está disponible, cada handler legacy retorna sin persistir (sin doble escritura). Backstop adicional: idempotencia compartida (`idempotency_key` idéntico → guard de fila existente). Sin exporters/tablas/colas/scheduler modificados.

## Observabilidad del fallback (Fase 4)
`Estandar_Signal_Core_Hooks::observe_routing()` emite **una vez por request** (guard `$observed`, estado invariante en el request) por el canal canónico `do_action('estandar_telemetry_event', …)` — reutiliza el mecanismo existente del proyecto, sin logging nuevo ni telemetría externa. Pura observación: no cambia comportamiento y nunca lanza (envuelto en try/catch). Registra determinísticamente:
- `code`: `SIGNAL_LEGACY_FALLBACK_SUPERSEDED` (capability=true) | `SIGNAL_LEGACY_FALLBACK_ACTIVE` (capability=false)
- `context.capability_available` (bool) · `context.new_listener_registered` (bool, `class_exists(Advertising_Listener)`) · `context.legacy_active` (bool) · `context.reason` (`emitter_capability_true` | `emitter_capability_absent`)

## Platform eligibility reconciliation (2026-07-15)

For the new `estandar_advertising_event_ready` contract, Signal Core persists
`record_from_event()` rows as `audit_only` even when technical exporters are
configured. Canonical delivery belongs exclusively to the Leads Meta/Google
adapters, after `resolve_channel()` plus platform click-evidence eligibility.
This prevents Signal Core from becoming a parallel, policy-blind send path.
The deprecated `record_from_lead()` fallback is unchanged for old Leads
installations where the new emitter capability is absent.

## Matriz de compatibilidad

| Leads | Signal | Emite | Consume | Fallback | Comportamiento esperado |
|---|---|---|---|---|---|
| **Ausente** | nuevo | — (nadie emite `estandar_lead_*`… salvo captura) | Hooks legacy | ACTIVO | capability=false → legacy persiste (código `_ACTIVE`). Un lead ingested/status persiste vía `record_from_lead`. Sin doble escritura (no hay emisor nuevo). |
| **Antiguo** (sin emisor / clase sin `init()`) | nuevo | eventos de lead crudos | Hooks legacy | ACTIVO | capability=false (default del filtro) → legacy persiste. `_ACTIVE`. |
| **Nuevo** (capability=true) | nuevo | `estandar_advertising_event_ready` | `Advertising_Listener` → `record_from_event` | INACTIVO (stand-down) | capability=true → legacy retorna sin persistir (`_SUPERSEDED`). Solo el path nuevo persiste. Backstop idempotencia si ambos corrieran. |
| **Nuevo** (capability=true) | **antiguo** (sin `Advertising_Listener`) | `estandar_advertising_event_ready` | nadie escucha el hook nuevo; Hooks legacy también consume eventos de lead | ACTIVO por seguridad | Signal antiguo no tiene el listener nuevo → depende del legacy. capability=true apagaría el legacy y NADIE consumiría → **NO retirar el fallback hasta que Signal nuevo esté desplegado** (ver criterio). `new_listener_registered=false` es la señal observable de este caso. |
| **Ausente/Antiguo** | **antiguo** | eventos crudos | Hooks legacy | ACTIVO | comportamiento pre-Fase-1 intacto. |

## Criterio exacto de retiro (objetivo, sin tiempo ni supuestos)
Eliminar `Estandar_Signal_Core_Hooks` (listener legacy + `event_for_status`) es seguro **solo cuando ambas condiciones se cumplen de forma verificable**:
1. **Capability explícita:** `apply_filters('estandar_advertising_event_emitter_available', false) === true` en la instalación (estandar-leads emite el AdvertisingEvent).
2. **Compatibilidad mínima de versiones:** la instalación corre un **Signal nuevo** con `Estandar_Signal_Core_Advertising_Listener` presente (`context.new_listener_registered === true`) — de lo contrario, apagar el legacy dejaría el hecho sin consumidor.

Ambas son observables hoy en telemetría: retirar el fallback requiere que **todas** las instalaciones reporten `SIGNAL_LEGACY_FALLBACK_SUPERSEDED` con `new_listener_registered=true` (y ninguna `SIGNAL_LEGACY_FALLBACK_ACTIVE`). No depende de tiempo, despliegues manuales ni suposiciones.

## Seguridad
- Sin PII/secretos en logs; `identity_json`/`payload_json` solo guardan hashes (`email_hash`/`phone_hash` = SHA-256) y click ids; nunca email/phone en claro.
- Flujo server-side por hooks (sin form/nonce). Payload sanitizado en el consumidor (`absint`/`sanitize_key`/`sanitize_title`/`sanitize_text_field`).
- Sin cambios de esquema; contenido histórico de `es_signal_*` intacto; backward-compatible.
