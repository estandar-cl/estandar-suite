# Estandar Quotes — Architecture V1

> **Documento canónico de arquitectura.**  
> Las decisiones arquitectónicas formales están documentadas en `docs/adr/ADR-001-hydration-pipeline.md`.  
> **Estado:** V1 — Congelada. Modificaciones estructurales requieren nuevo ADR.

## Decision

`estandar-quotes` is an independent bounded context. Leads remains the source of lead and submission truth; Forms remains the capture producer; Signal Core remains an audit-only commercial-signal ledger. Quotes owns drafts, document revisions, issue lifecycle, delivery artifacts and vertical profiles.

Extending Leads was rejected: quotation revisions and structured delivery are a separate aggregate with different immutability, query and retention needs. A CPT-only model was rejected: it does not provide a reliable relational boundary for revisions, history and delivery queries.

## Canonical model

- `es_quotes`: mutable aggregate header and immutable lead/submission snapshot at draft creation.
- `es_quote_revisions`: append-only canonical document snapshots, unique per `(quote_id, revision_no)`, with SHA-256 digest.
- `es_quote_events`: append-only domain history. Event names: `quote.draft.created`, `quote.revision.created`, `quote.status.{state}`, `quote.operator.rebound`.
- `es_quote_handoffs`: durable single-use handoff tokens. Schema `0.1.7`. Each row binds an opaque 256-bit token (stored as SHA-256 hash) to a submission and operator. `consumed_at` gates single-use with atomic SQL UPDATE. No TTL — handoffs persist until consumed.
- `es_quote_operator_sessions`: 4h bearer sessions scoped to a single quote/operator pair. No PII.
- `es_quote_artifacts`: immutable artifact generation records (PDF renderings with SHA-256 integrity, source hash, renderer/version, storage key).
- `es_quote_deliveries`: idempotent delivery records with lifecycle (`pending → sending → sent|failed`, + `cancelled`).

The allowed lifecycle is `draft → review → issued → superseded|expired`, with `review → draft` allowed. `issued` requires at least one revision. Revisions can only be appended in `draft` or `review`; there is no update API.

### Construction lifecycle (hydration)

All quote construction follows this pipeline:

```
Lead capture (form/chatbot)
  ↓
Handoff token (256-bit)
  ↓
Hydration Pipeline (QuoteDraft en memoria)
  ├── LeadStage(10)      — Profile defaults + lead contact data
  ├── ContextStage(20)   — submission.context_json → project/issuer
  ├── UnitStage(25)      — Unit catalog (placeholder)
  ├── FormStage(30)      — Declarative form field mappings → property/pricing
  ├── SettingsStage(40)  — Global configuration overlay
  ├── FinanceStage(50)   — Expiry date computation
  ├── ValidationStage(60) — Warnings/errors (read-only)
  └── SnapshotStage(70)  — Canonical normalization + SHA-256 hash
  ↓
Repository::initialize_draft() / save_draft()  — Persistencia solo aquí
```

**Reglas:**
- Toda la construcción ocurre sobre `QuoteDraft` en memoria.
- Ningún Stage puede acceder a la base de datos.
- La entidad `es_quotes` solo se escribe después de que el pipeline completa su ejecución.
- El orden se determina por prioridad declarativa, no por orden de registro.

## Profiles (tenant-agnostic V1)

Profiles are versioned document contracts registered through the `estandar_quotes_profiles` filter. Each declares `version`, required document fields, a normalizer, and (optionally) a defaults factory.

### Active profiles

| Key | Label | Status |
|-----|-------|--------|
| `base@1` | Base | Minimal; core schema only |
| `real_estate@1` | Real Estate (genérico) | **Canonical default** — tenant-agnostic, no client-specific branding |
| `acacios_real_estate@1` | Acacios Real Estate | Named profile with Acacios-specific asset references |

### Profile selection

The active profile is configured in Settings → Ajustes de Cotizaciones → "Perfil activo". Defaults to `real_estate@1`. Both Gateway (`Estandar_Quotes_Operator_Gateway::active_profile_key()`) and Admin (`Estandar_Quotes_Admin::active_profile_key()`) read the configured profile key from `estandar_quotes_defaults[active_profile]`.

New drafts always use the configured active profile. Existing drafts retain the profile they were created with — changing the active profile setting does not retroactively change existing drafts.

### real_estate@1 document contract

Same structure as `acacios_real_estate@1`: `client`, `project`, `property`, `pricing` (UF), `uf_rate` (CLP), `financing`, `validity`, `issuer`, `conditions`, `editorial_blocks`, `capture_context`. All branding, company info, signature, sales room data, and conditions come from Settings — not hardcoded.

### Document definitions

`Estandar_Quotes_Document_Definitions::get()` returns a definition for any supported profile key. Both `real_estate@1` and `acacios_real_estate@1` use the same document structure (`estandar_quote_document@1`) with 7 blocks: `client`, `property`, `investment`, `conditions`, `sales_room`, `financing`, `images`.

Asset references are declared by each profile class via `document_assets()` rather than hardcoded in `Document_Definitions`. `Document_Definitions::discover_assets()` resolves assets from the profile class. This allows new profiles (including third-party ones injected via the `estandar_quotes_profiles` filter) to declare their own assets without modifying core code.

Generic assets: `reference_plan_ground`/`reference_plan_upper`. Acacios assets (backward-compatible): `acacios_reference_plan_ground`/`acacios_reference_plan_upper`.

Validation accepts both `estandar_quote_document@1` (current) and `acacios_quote_document@1` (legacy, backward compatibility).

### ViewModel

`Estandar_Quotes_View_Model::from_document()` supports both profiles. It dispatches to the correct normalizer based on the profile key and produces a display-only `estandar_quote_view_model@1` contract used by the Document Engine.

## Lead integration and security

Leads contributes only an optional filtered presentation URL (`estandar_leads_quote_create_url`) in its email and workspace. Quotes creates an opaque 256-bit durable handoff persisted in `es_quote_handoffs`. The URL contains no lead ID, submission ID or personal data. Handoffs have no TTL — they remain valid until explicitly consumed (single-use, atomic `consumed_at` column).

Admin `handle_handoff()` peeks handoffs with `operator_id`: if present, redirects to the frontend Gateway (`/cotizacion/{token}`). Legacy handoffs without `operator_id` are consumed inside `wp-admin`.

## Settings V2

Settings live in `wp_option[estandar_quotes_defaults]` with full sanitization and validation. Categories:

### Template
- `active_profile`: `real_estate@1` (default) | `acacios_real_estate@1`
- `document_title`: Document heading
- `document_subtitle`: Optional subtitle

### Branding
- `company_name`, `company_logo_id`, `company_website`, `company_phone`, `company_email`, `company_address`
- `primary_color` (#hex), `accent_color` (#hex)
- `font_family` (CSS value)

### Sales room
- `sales_room_name`, `_address`, `_phone`, `_email`, `_hours`

### Issuer / Signature
- `authority_name`, `authority_role`, `authority_id`

### Quote defaults
- `validity_days`, `uf_source`, `uf_value_clp`, `annual_rate_percent`, `credit_percent`, `income_multiple`

### Conditions & footer
- `conditions` (text, one per line)
- `footer_legal`, `footer_company`

### Email
- `sender_name`, `sender_email`, `email_subject_template`
- `operator_copy` (email), `email_cc`, `email_bcc`

### Renderer
- `renderer`: `dompdf` (default)

Settings apply only during draft initialization. Changing settings does not alter already-emitted revisions.

### Import/Export

Settings can be exported as JSON (download) and imported from a JSON file (merge, not replace). Both actions require `estandar_quotes_manage_settings` capability and a nonce. Export excludes the `company_logo_id` (installation-specific). Admin hooks: `admin_post_estandar_quotes_export_settings`, `admin_post_estandar_quotes_import_settings`.

## Signal Core contract

Quotes emits `estandar_quote_event` for local subscribers. No advertising/export path in this phase.

## Delivery V1

Delivery is a Quotes subdomain, not an extension of Leads or a mailer. It accepts only an immutable revision and a `ready` artifact.

Transitions: `pending → sending → sent|failed`; `pending|failed → cancelled`. `sending` claim is conditional SQL (atomic). No automatic retries. `resend()` explicit; `create_new()` creates a separate delivery (idempotent over source, recipients, subject, template, language, salt).

`Estandar_Quotes_Mail_Transport` is the sole transport boundary. `Estandar_Quotes_WP_Mail_Transport` delegates to `wp_mail`, renders HTML, attaches PDF from opaque storage key. Operator confirmation is best-effort.

Events: `QUOTE_DELIVERY_CREATED`, `_SENT`, `_FAILED`, `_CANCELLED`.

## Operational workspace V1

Path: `Lead handoff → mutable draft → explicit save → immutable revision/issued state → artifact generation → Delivery`.

`Estandar_Quotes_Admin` is server-rendered, reuses Profile normalizer, Calculator, Preview Service, Artifacts, and Delivery Service. Incomplete defaults may initialize a draft; full validation happens on explicit save/issue.

Menu: `Cotizaciones` tab in Estandar shell; Workspace and Ajustes as local tabs. Preview hidden from menu (reached only from authorized quote action).

Capabilities (6): `estandar_quotes_view`, `_edit_drafts`, `_issue`, `_generate_pdf`, `_send`, `_manage_settings`. Granted to Administrators on activation.

## Document engine V1

Pipeline: `Quote Snapshot → ViewModel → Document Definition → Document Engine → HTML preview|print|future PDF`. Read-only: receives normalized immutable snapshot.

`render_preview()` and `render_print()` return escaped HTML. Print CSS included. Image assets not resolved yet (future PDF adapter).

## Preview Service V1

Strict source selection: `source=revision` requires exact `revision_uuid`; `source=draft` reads `draft_document_json`. Validates quote state, profile, definition, blocks, and asset references. Assets must be named references; URLs/paths rejected. Errors are generic.

Each artifact returns internal reproducibility hashes (source, view model, definition, preview HTML, print HTML). Never displayed in UI.

## Artifacts V1

`Estandar_Quotes_Artifacts::generate()` consumes validated `print_html` from Preview Service. Idempotent: same source hash + renderer + template version returns existing artifact. Explicit regeneration creates new generation. Files stored under `wp-content/uploads/estandar-quotes/` with UUID filenames.

`Estandar_Quotes_PDF_Fake_Adapter`: test-only, deterministic.

## PDF Adapter V1

`Estandar_Quotes_PDF_Adapter` interface: `render(print_html, options) → PDF bytes`. DOMPDF adapter uses runtime instance; fails explicitly if absent. Remote fetch disabled. Format: letter, portrait.

### Canonical Renderer Resolver V1 (2026-07-23)

`Estandar_Quotes_PDF_Dompdf_Adapter::resolve()` is the canonical factory for the PDF renderer. Both `Estandar_Quotes_Admin` (wp-admin) and `Estandar_Quotes_Operator_Gateway` (public workspace) use this single method — no path duplicates the loading/check/instantiation logic.

```php
// Canonical, shared by both entry points
$renderer = Estandar_Quotes_PDF_Dompdf_Adapter::resolve();
```

The method:
1. Requires `dompdf-loader.php` (guarded by `ABSPATH`)
2. Calls `estandar_quotes_load_dompdf()` which tries 3 sources (bundled `lib/dompdf/vendor/`, `estandar-website-scanner/vendor/`, plugin's own `vendor/`)
3. Returns a new adapter instance on success
4. Throws `RuntimeException('PDF renderer unavailable.')` if DOMPDF cannot be loaded

History: The original code in `operator-gateway.php` used `class_exists('\Dompdf\Dompdf')` before loading DOMPDF, which always returned false because the bundled autoloader had not been invoked. The admin path used direct instantiation without a check, relying on the adapter's `render()` to load DOMPDF. The fix aligns both paths to the same `resolve()` factory, ensuring the public workspace PDF generation no longer fails at the gate check.

## Operator Gateway V1

### Identity: Leads operator, not WordPress user

Leads operators are `es_operators` rows — no WP capability, no WP session, no `wp-admin` login. Gateway serves `/cotizacion/{token}` via `template_redirect`. No `current_user_can()` anywhere. Authorization against Leads operator/submission model.

### Token spaces (resolved in order)

1. **Handoff token** — single-use, minted by Leads integration. Peeked (read-only) to learn `operator_id`/`submission_id`, live Leads authorization checked, then atomically consumed.
2. **Session token** — 4h bearer token minted after handoff consumption. Scoped to single `(quote_id, operator_id)`.

### Authorization: re-checked per-request

Every request through an active session:
1. Session supplies `(quote_id, operator_id)`.
2. Quote's `operator_id` must match.
3. `operator_authorized_for_submission()` checked live against Leads.
4. If Leads lapsed, session revoked immediately.

### Ownership and rebind

Default: current owner kept. Different operator refused while current owner still Leads-authorized. Rebind only when prior owner no longer authorized (Leads reassignment already made).

### CSRF

HMAC-SHA256 of `action|quote_uuid` keyed by session token + wp_salt('auth'). Every mutation verifies `esq_csrf` via `verify_action_token()` (constant-time `hash_equals()`) and `quote_uuid` match.

### Gateway workspace

Minimal self-contained HTML: save, issue, preview, PDF, send, logout. Uses `Estandar_Quotes_Document_Form` (shared with wp-admin). Errors generic — never leak identifiers.

## Integrity hardening

- Revision JSON recursively canonicalized before hashing (maps sort by key, lists preserve order).
- Revision insert + header update in a DB transaction with expected revision number check.
- State updates compare prior status in SQL.
- Handoff: `consumed_at` atomic SQL gate.

## Limits and residual risks

- **No multi-operator collaboration.** One owning operator per quote.
- **Session TTL fixed at 4h** with no idle-timeout distinction.
- **TOCTOU on ownership**: Leads reassignment observed on next request, not instantaneously.
- **No rate limiting** on token guessing beyond 256-bit entropy.
- ~~**DOMPDF rendering + real end-to-end smoke test**: validated only via test doubles, not against live DOMPDF/mail transport.~~ **RESUELTO 2026-07-23**: Validado con DOMPDF v2.0.8 standalone (PHP 8.5 genera PDF válido de 4KB, 1 página). Persisten warnings de compatibilidad PHP 8.5 (nullable params implícitos) — no bloqueantes, DOMPDF funciona correctamente.
- **Admin UI**: tested primarily via source-level assertions; limited runtime coverage.

## Audit fixes (2026-07-17)

| Bug | Severity | Description | Fix |
|-----|----------|-------------|-----|
| B1 | HIGH | `consume_handoff()` rejected `operator_id=0`, making legacy/admin handoffs impossible | Removed guard; admin path uses peeked operator_id |
| B2 | HIGH | `Document_Engine` crashed on PHP 8.x when conditions.items are flat strings | Handle both `is_array($item) ? $item['text'] : $item` |
| B3 | HIGH | GET-based logout lacked CSRF, allowing cross-site force-logout | Added `esq_csrf` HMAC token to logout link and verification |
| B4 | MEDIUM | `import_settings()` bypassed `sanitize_defaults()` + no file size limit | Route through sanitizer; 512KB limit |
| B5 | MEDIUM | "Defaults Acacios" hardcoded in settings section title | Changed to "Valores predeterminados" |
| B6 | MEDIUM | `render_error()` calls without defensive `return` | Added `return` after all `render_error` in Gateway |
| — | MEDIUM | Gateway `send` didn't pass `operator_email` for operator copy | Now reads `operator_copy` from settings |
| — | — | `Document_Definitions` hardcoded profile list and asset names | Added `document_assets()` to each profile; `discover_assets()` in DD |

## Fixes 2026-07-23

| FAIL | Severity | Description | Fix |
|------|----------|-------------|-----|
| F1 | HIGH | PDF gate in operator-gateway.php:202 checked `class_exists('\Dompdf\Dompdf')` before loading DOMPDF → always returned false → "El generador de PDF no está disponible en este momento". Admin path used direct instantiation without gate check, so DOMPDF worked from wp-admin but not from the public workspace. | Added `Estandar_Quotes_PDF_Dompdf_Adapter::resolve()` canonical factory. Both `admin.php` and `operator-gateway.php` now call `Estandar_Quotes_PDF_Dompdf_Adapter::resolve()` — same loading, check, and instantiation logic. Removed duplicate `class_exists` gate and direct `new` call. |
| F1b | LOW | Subject placeholder `{project}` en admin.php history form no se interpolaba — el correo llegaba con asunto literal "Cotización - {project}" en vez del nombre del proyecto | Resuelto interpolando `self::draft($quote)['project']['name']` en el value del input subject. Si no hay documento, cae a `$quote['project_slug']` o "Cotización". |
| F2 | HIGH | After handoff consumption and quote issuance, clicking the email link again failed because `get_by_submission()` filtered `status IN ('draft','review')` — issued quotes were invisible → "Enlace no disponible" | Added `issued` to status filter in `get_by_submission()` + new `get_by_submission_any_status()` (no filter) + fallback in recovery path |
| F3 | MEDIUM | `project_slug` del handoff se perdía (no llegaba al pipeline de hydratación); form_fields no mapeados eran descartados sin registro | `snapshot_for_submission()` incluye `project_slug` dentro de `lead`; ambos perfiles (`real_estate@1`, `acacios_real_estate@1`) exponen `capture_context.project_slug` + `capture_context.raw_fields` (todos los form_fields originales) |

## Fixtures

| File | Purpose |
|------|---------|
| `fixtures/acacios-real-estate-v1.json` | Synthetic Acacios profile fixture (test data, not production defaults) |
| `fixtures/real-estate-v1.json` | Generic real estate fixture with neutral data |
| `docs/reference/acacios/Cotizacion_11035737.pdf` | Visual reference PDF (not a production fixture) |

## Files (28 PHP, 2 JSON fixtures, 1 reference PDF)

**Bootstrap**: `estandar-quotes.php`, `uninstall.php`

**Classes (24)**: `Schema`, `State_Machine`, `Profiles`, `Real_Estate_Profile`, `Acacios_Profile`, `Canonical_Json`, `Calculator`, `View_Model`, `Document_Definitions`, `Document_Engine`, `Preview_Service`, `Preview_Admin`, `Artifacts`, `Delivery_Service`, `Delivery_Email`, `Lead_Integration`, `Document_Form`, `Admin`, `Operator_Session`, `Operator_Gateway`, `PDF_Dompdf_Adapter`, `PDF_Fake_Adapter`, `Fake_Mail_Transport`, `WP_Mail_Transport`

**Interfaces (2)**: `PDF_Adapter`, `Mail_Transport`

**Tests (17)**: `run-foundation`, `run-schema`, `run-acacios-profile`, `run-generic-profile`, `run-document-engine`, `run-preview-service`, `run-artifacts-generate`, `run-delivery-service`, `run-operator-gateway`, `run-admin-regression`, `run-email-to-gateway`, `run-pdf-fake-adapter`, `run-audit-fixes-regression`, `run-architecture-tests`, `run-hydration-pipeline`, `run-field-mapping`, `run-admin-actions`

## Test status

17/17 suites green. No regressions. All existing profiles and fixtures pass.
13/13 acceptance tests green. Validated via standalone PHP scripts: PDF generated (DOMPDF v2.0.8, 4KB, 1 page), project_slug/raw_fields populated in hydration output.

## CLIENT_ISOLATION_V1 compliance

- No client-specific hardcodes in production code. Profile keys are configurable.
- Generic `real_estate@1` profile is the default for new installations.
- Named `acacios_real_estate@1` profile remains as a selectable, versioned document contract — it does not leak into other installations.
- Test fixtures use synthetic data; the Acacios fixture is explicit about being test-only.
- Reference PDF in `docs/reference/` is visual reference only, not a production fixture.

## Pending / out of scope

- DOMPDF real end-to-end smoke test (known gap)
- Email template customization beyond subject/sender/copies
- Image asset resolution in PDF
- Multi-operator collaboration
- Session management dashboard (list/revoke)
- Template builder UI (visual drag-drop)
- Marketplace of templates
- Electronic signatures
- Client acceptance workflow
- Payment integration
- Opening/tracking pixels
- Automatic retry queue for deliveries

## Continuation instructions

1. Run `php -l` on all modified files.
2. Run `php tests/run-*.php` — all must exit 0.
3. Deploy to SALADEPRUEBAS first.
4. Configure "Ajustes de Cotizaciones" with real branding/settings.
5. Smoke test: create a lead → notification email → click "Crear cotización" → Gateway workspace → edit → save → issue → preview → PDF → send.
6. Deploy to Acacios after validation.
7. Close DOMPDF smoke gap before declaring production-ready.

## Hydration Pipeline V2

Quote construction follows a declarative pipeline of registered stages. Each stage implements `Estandar_Quotes_Hydration_Stage` and is responsible for a single data source.

### Pipeline order

| Priority | Stage | Source | Policy | Fields |
|----------|-------|--------|--------|--------|
| 10 | LeadStage | Profile defaults + lead snapshot (name, email, phone) | merge_recursive | client, project, property, pricing, uf_rate, financing, validity, issuer, conditions, editorial_blocks, capture_context |
| 20 | ContextStage | submission.context_json (project_name, project_area, handoff.owner) | merge_if_empty | project.name, project.stage, issuer.authority_name |
| 25 | UnitStage | Unit catalog (future) | overwrite | property.*, pricing.total_uf.amount |
| 30 | FormStage | submission.form_fields_json (all form field values) | overwrite | client.tax_id, project.name, project.stage, property.*, pricing.total_uf, uf_rate.value_clp, uf_rate.effective_date |
| 40 | SettingsStage | wp_option estandar_quotes_defaults | overwrite | validity.days, uf_rate.*, financing.*, project.sales_room.*, issuer.*, conditions.items |
| 50 | FinanceStage | Computed values | overwrite | validity.expires_at, validity.issued_at |
| 60 | ValidationStage | All fields | read_only | (warnings/errors) |
| 70 | SnapshotStage | Full document | overwrite | computed, visibility, snapshot_hash |

### Construction model

All construction happens on `Estandar_Quotes_Quote_Draft` (a mutable temporary model). The persistent entity (`es_quotes` row) is only written after hydration and validation complete successfully, via `Repository::initialize_draft()` or `Repository::save_draft()`.

### Key classes

| Class | File | Responsibility |
|-------|------|---------------|
| `Estandar_Quotes_Hydration_Stage` (interface) | `interface-estandar-quotes-hydration-stage.php` | Contract: priority(), apply(), fields(), overwrite_policy(), supported_profiles() |
| `Estandar_Quotes_Quote_Draft` | `class-estandar-quotes-quote-draft.php` | Temporary document model with dot-path get/set, merge policies, error/warning collection, clone, meta storage |
| `Estandar_Quotes_Hydration_Pipeline` | `class-estandar-quotes-hydration-pipeline.php` | Stage registry and orchestrator. Sorts by priority, executes in order, skips unsupported profiles |
| `Estandar_Quotes_Lead_Stage` | `class-estandar-quotes-lead-stage.php` | Profile defaults + lead contact data |
| `Estandar_Quotes_Context_Stage` | `class-estandar-quotes-context-stage.php` | Context metadata to project name, stage, issuer |
| `Estandar_Quotes_Form_Stage` | `class-estandar-quotes-form-stage.php` | Form field mapping with Chilean number/rut format handling |
| `Estandar_Quotes_Unit_Stage` | `class-estandar-quotes-unit-stage.php` | Unit catalog (placeholder for future implementation) |
| `Estandar_Quotes_Settings_Stage` | `class-estandar-quotes-settings-stage.php` | Global configuration overlay |
| `Estandar_Quotes_Finance_Stage` | `class-estandar-quotes-finance-stage.php` | Expiry date computation |
| `Estandar_Quotes_Validation_Stage` | `class-estandar-quotes-validation-stage.php` | Warning/error collection for incomplete documents |
| `Estandar_Quotes_Snapshot_Stage` | `class-estandar-quotes-snapshot-stage.php` | Profile normalization + canonical hash generation |

### Registration

Stages register on `plugins_loaded` in `estandar-quotes.php`. New profiles can inject stages via `Estandar_Quotes_Hydration_Pipeline::register()` or the `estandar_quotes_hydrator_stages` action.

### Architecture tests

The ADR decisions are codified as automated checks in `tests/run-architecture-tests.php` (133 tests). These verify:

- Pipeline composition by registration, not hardcoded lists
- Priority-based execution order
- No stage references other stages
- No stage performs persistence, PDF, mail, or delivery
- No stage contains profile-specific business logic (except Lead/Snapshot)
- QuoteDraft has no infrastructure dependencies
- FormStage is a pure interpreter of declarative mappings
- ValidationStage is read-only
- Both Admin and Gateway use HydrationPipeline
- Bootstrap registers all 8 stages and the hook for extensibility

These tests run as part of the normal test suite. Any change that breaks these rules will fail CI and must be justified with a new ADR.

## Form Field Mapping System

A declarative mapping system configurable per profile. The `FormStage` (priority 30) interprets mapping definitions and applies them to the `QuoteDraft`.

### Mapping definition structure

Each mapping entry is an associative array:

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `target` | string | yes | — | QuoteDraft dot-path destination |
| `sources` | string[] | yes | — | Ordered source field names from `form_fields`. First available wins |
| `transform` | string | no | `text` | Transformer: `text`, `chilean_float`, `chilean_bool`, `int`, `rut`, `date` |
| `default` | mixed | no | null | Fallback value if no source found |
| `condition` | array | no | null | Condition `{field, operator, value}`. Operators: `not_empty`, `empty`, `equals`, `not_equals`, `in`, `matches` |
| `overwrite` | string | no | `always` | Policy: `always`, `if_empty`, `if_null`, `never` |
| `validate` | string | no | null | Validator: `rut`, `positive`, `date`, `not_empty` |
| `profiles` | string[] | no | `[]` | Restrict to specific profiles (empty = all) |

### Registration

Profiles register mappings on `plugins_loaded`:

```php
Estandar_Quotes_Field_Mapping_Registry::register( 'real_estate@1', $definitions );
Estandar_Quotes_Field_Mapping_Registry::register( 'acacios_real_estate@1', $definitions );
```

Third-party profiles can modify mappings via the `estandar_quotes_field_mappings` filter.

### Transformers

| Name | Input | Output | Description |
|------|-------|--------|-------------|
| `text` | string | string | `sanitize_text_field()` |
| `chilean_float` | string | float|null | Parses `12.345,67` and `65,5` formats |
| `chilean_bool` | string | bool | `Si`/`1`/`true`/`yes` → true |
| `int` | string | int|null | Numeric cast |
| `rut` | string | string|null | Strips non-RUT chars |
| `date` | string | string|null | Parses YYYY-MM-DD or strtotime |

### Validators

| Name | Validates |
|------|-----------|
| `rut` | Chilean RUT (modulo 11 algorithm) |
| `positive` | Numeric value > 0 |
| `date` | ISO date format YYYY-MM-DD |
| `not_empty` | Not null, empty string, or false |

### Key classes

| Class | File | Responsibility |
|-------|------|---------------|
| `Estandar_Quotes_Field_Mapping_Registry` | `class-estandar-quotes-field-mapping-registry.php` | Stores per-profile mappings, filter hook |
| `Estandar_Quotes_Field_Transformer` | `class-estandar-quotes-field-transformer.php` | Pure transformers and validators, condition evaluator |
| `Estandar_Quotes_Form_Stage` | `class-estandar-quotes-form-stage.php` | Interprets mappings, applies to QuoteDraft |

### Fields autocompleted by the mapping system

| QuoteDraft field | Source(s) | Transformer | Validation |
|---|---|---|---|
| `client.tax_id` | rut, tax_id, run, identificacion | `rut` | rut |
| `project.name` | proyecto, project, nombre_proyecto | `text` | — |
| `project.stage` | etapa, stage | `text` | — |
| `property.unit_code` | unidad, unit_code, codigo, numero_unidad | `text` | — |
| `property.model` | modelo, model, tipo_propiedad, tipo | `text` | — |
| `property.orientation` | orientacion, orientation | `text` | — |
| `property.area_total_m2` | superficie_total, area_total, superficie, metros_totales | `chilean_float` | — |
| `property.area_usable_m2` | superficie_util, area_util, metros_utiles | `chilean_float` | — |
| `property.floors` | pisos, floors | `int` | — |
| `property.bedrooms` | dormitorios, bedrooms, habitaciones | `int` | — |
| `property.bathrooms` | banos, bathrooms, baños | `int` | — |
| `property.storage` | bodega, storage | `chilean_bool` | — |
| `property.parking` | estacionamiento, parking | `chilean_bool` | — |
| `pricing.total_uf.amount` | presupuesto, valor, precio, budget, valor_propiedad, monto | `chilean_float` | positive |
| `uf_rate.value_clp` | valor_uf, uf_value, uf_clp | `chilean_float` | positive |
| `uf_rate.effective_date` | fecha_uf, uf_date | `date` | date |

### Extending for new projects

1. Register mappings for the new profile key:
```php
Estandar_Quotes_Field_Mapping_Registry::register( 'agricola@1', array(
    array(
        'target'  => 'property.crop_type',
        'sources' => array( 'tipo_cultivo', 'crop' ),
        'transform' => 'text',
    ),
    // ...
) );
```

2. If a new transformer is needed, add it to `Field_Transformer::transform()`.
3. If a new validator is needed, add it to `Field_Transformer::validate()`.

---

## Estabilidad del contrato

### QuoteDraft — contrato canónico

El contrato `Estandar_Quotes_Quote_Draft` se considera **estable** a partir de la publicación de este documento. Cualquier modificación estructural (agregar/renombrar/eliminar secciones, cambiar tipos) requerirá:

1. Un nuevo ADR que documente la necesidad funcional.
2. Compatibilidad hacia atrás con revisiones existentes.
3. Migración de datos si aplica.

Se permite agregar nuevas propiedades opcionales dentro de secciones existentes sin ADR, siempre que no afecten la lectura de revisiones existentes.

### Bounded contexts del modelo de dominio

| Contexto | Secciones en QuoteDraft | Estabilidad |
|----------|------------------------|-------------|
| **Customer** | `client` | Estable |
| **Commercial** | `project`, `property`, `pricing`, `conditions`, `editorial_blocks`, `capture_context` | Estable |
| **Financial** | `uf_rate`, `financing`, `computed` | Estable |
| **Validity** | `validity` | Estable |
| **Identity** | `issuer`, `document_version` | Estable |
| **Rendering** | `visibility` | Estable |

### Pipeline

El `HydrationPipeline` y sus Stages se consideran estables. Las únicas modificaciones permitidas son:

- Agregar nuevos Stages vía `register()`.
- Agregar nuevos transformadores/validadores a `Field_Transformer`.
- Registrar nuevos mappings por perfil en `Field_Mapping_Registry`.

### ADR

Las decisiones arquitectónicas formales están documentadas en:
- `docs/adr/ADR-001-hydration-pipeline.md`

### Continuation instructions
