# estandar-flow — Operación comercial

**Propósito:** pipeline/CRM con etapas canónicas, transiciones auditadas, clientes (fingerprint UNIQUE), cotizaciones y adopción de estado comercial histórico de `es_leads`.

**Módulos:** `Pipeline` (stages, transition, stage_events, impl. CommercialStageMap) · `Customers` · `Quotes` · `Adoption` (import_pipeline/rollback) · `Rest` · `Cli`.

**Tablas:** `estandar_flow_pipeline`, `estandar_flow_stage_events`, `estandar_flow_customers`, `estandar_flow_quotes`, `estandar_flow_tasks`. **Options:** `estandar_suite_flow_schema_version`.
**REST** (`estandar-suite/flow/v1`): `GET /pipeline`, `POST /pipeline/{id}/transition`, `GET /customers`, `GET /quotes`.
**WP-CLI:** `wp estandar-suite flow pipeline|transition|adoption import [--commit]|adoption rollback --confirm=yes`.
**Adopción:** dry-run; `origin_lead_id` en pipeline; clientes por fingerprint (sin duplicados); rollback solo adoptados.
**Tests:** `php tests/run-flow.php` (21 assertions).
