# ADS_EXPORT_CONTRACT_V2

## Purpose

Documents the current, implemented contract between the lead commercial-status
lifecycle and the two outbound conversion export adapters (Google Ads OCI and
Meta CAPI). Describes what is true today in code — not a design proposal.

## Single Source of Truth

- **Commercial Stages** (`Estandar_Commercial_Stages`) — the exclusive source of
  truth for which commercial statuses are exportable.
- **Commercial Outcomes Value Provider** (`Estandar_Commercial_Outcomes_Value_Provider`)
  — the exclusive source of truth for conversion value.
- **Commercial Money** (`Estandar_Commercial_Money`) — the exclusive carrier of
  amount + currency; both adapters read amount and currency from the same
  `Estandar_Commercial_Money` instance returned by the Value Provider.

## Providers

- **Google OCI** (`Estandar_Leads_Ads_OCI`) — consumes Commercial Stages for
  exportability, and Commercial Money (via the Value Provider) for conversion
  value and currency.
- **Meta CAPI** (`Estandar_Leads_Meta_Adapter`) — consumes Commercial Stages for
  exportability, and Commercial Money (via the Value Provider) for conversion
  value and currency.

Both providers resolve status, value, and currency through the same shared
classes, in the same order, with no per-provider duplication of this logic.

### Alias

- `WON` → `CONVERTED`. `WON` is not a distinct commercial stage; `Estandar_Commercial_Stages::is_exportable()` normalizes it to `CONVERTED` before evaluating exportability.

### Fallback

Each provider retains a local, standalone fallback (status whitelist / value
map / currency option) used **only** when the Commercial Outcomes module is
not present. When the module is present, it is the exclusive source — the
local fallback is not blended with it.

## Export Contract

| Concern | Source of truth | Fallback (module absent) |
|---|---|---|
| Exportable status | `Estandar_Commercial_Stages::is_exportable()` | Local const whitelist per provider |
| Conversion value | `Estandar_Commercial_Outcomes_Value_Provider::value_for()` → `Estandar_Commercial_Money::amount()` | Local value map per provider |
| Currency | Same `Estandar_Commercial_Money::currency()` instance as conversion value | Local currency option per provider |

## Platform Eligibility Reconciliation (2026-07-15)

The shared commercial gate remains unchanged: QA exclusion plus the canonical
`ads_feedback_policy`/qualification decision. After that gate, both adapters
consume `Estandar_Leads_Attribution::platform_eligibility()`, which in turn uses
the only channel resolver, `Estandar_Leads_Attribution::resolve_channel()`.

- Meta CAPI: commercial feedback allowed, canonical channel `meta_ads`, and a
  non-empty `fbclid` or `meta_fbc`. `_fbp` alone is insufficient.
- Google OCI: commercial feedback allowed, canonical channel `google_ads`, and
  a non-empty `gclid`, `gbraid`, or `wbraid`.
- Organic, direct, referral, unknown and form traffic is ineligible for both.

The gate is applied before enqueue and rechecked before worker/direct/backfill
transport. Existing queues, retries, deduplication, event IDs, timestamps,
payload hashes and idempotency are unchanged. Ads Feedback Observability uses
the same platform decision; historical execution evidence remains visible but
cannot render an ineligible acquisition as eligible or successful. Signal Core
remains an audit-only secondary ledger and is unchanged.

## Invariants

Given the same lead, project, and status, both providers produce:

- the same exportable/non-exportable determination
- the same conversion value
- the same currency
- the same exportability criterion (no provider-specific status list takes
  precedence while Commercial Outcomes is present)

## Non Goals

This contract does not cover, and does not change:

- payload structure
- transport
- cron
- retries
- logging
- telemetry
- event_id
- dedupe

## Historical Notes

- **D1** — Unified the exportable-status whitelist. Google OCI stopped
  maintaining an independent whitelist and now defers to
  `Estandar_Commercial_Stages`, mirroring the resolution order already used by
  the Meta adapter. Its local const remains only as the no-module fallback.
- **D3** — Unified conversion value. Google OCI stopped maintaining an
  independent value map as the primary source and now defers to
  `Estandar_Commercial_Outcomes_Value_Provider`, mirroring the Meta adapter.
  Its local value map remains only as the no-module fallback.
- **D4** — Unified currency. Google OCI stopped maintaining an independent
  currency configuration as the primary source and now reads currency from the
  same `Estandar_Commercial_Money` instance resolved for conversion value,
  mirroring the Meta adapter. Its local currency option remains only as the
  no-module fallback.
