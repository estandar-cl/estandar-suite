# estandar-flow — Operación comercial

**Propósito:** pipeline/CRM con etapas canónicas, transiciones auditadas, clientes (fingerprint UNIQUE), cotizaciones y adopción de estado comercial histórico de `es_leads`.

**Módulos (10):** `Pipeline` (etapas legacy + **journey canónico de 17 estados**, transiciones auditadas,
invariantes, `advance_to`, bridge de señales, impl. CommercialStageMap) · `Customers` · `Quotes` ·
`QuotesAdoption` · `Adoption` (import_pipeline/rollback) · `Rest` (incl. `/journey/transition`) · `Cli` · `Admin` ·
`Journey/StateMachine` (grafo + invariantes + mapeos legacy).

**Contratos:** consume `estandar_suite_capture_event` (crea el registro y lo lleva a `CAPTURED`) y
`estandar_suite_signal_recorded` (mueve el journey: `lead_qualified→QUALIFIED`, `meeting_booked→MEETING`,
`sale_closed→CLOSED_WON`, `lead_rejected→INVALID`); emite `estandar_suite_journey_transition`
(lo consume `Core\AdsIntelligence`) y `estandar_suite_quote_issued`.

**Dos vocabularios, uno derivado:** `journey_state` es el canónico; `stage` es el **espejo legacy**
(`journey_to_legacy`/`legacy_to_journey`) y se mantiene en sincronía en cada transición.

**Tablas:** `estandar_flow_pipeline`, `estandar_flow_stage_events`, `estandar_flow_customers`, `estandar_flow_quotes`, `estandar_flow_tasks`. **Options:** `estandar_suite_flow_schema_version`.
**REST** (`estandar-suite/flow/v1`): `GET /pipeline`, `POST /pipeline/{id}/transition`, `GET /customers`, `GET /quotes`.
**WP-CLI:** `wp estandar-suite flow pipeline|transition|adoption import [--commit]|adoption rollback --confirm=yes`.
**Adopción:** dry-run; `origin_lead_id` en pipeline; clientes por fingerprint (sin duplicados); rollback solo adoptados.
**Tests:** `php tests/run-flow.php` (**103 assertions**).
