# Estandar Quotes

Bounded context de cotizaciones comerciales auditables. El plugin más completo de la Suite (44 archivos, 17 tests, 9 tablas, hydration pipeline). Versión unificada: 13.0.173.

> Documentación técnica archivada: `docs/historico/plugins/estandar-quotes/` (ARCHITECTURE_V1, ACCEPTANCE_SUITE, ROADMAP_COMERCIAL, SPRINT_BACKLOG, adr/ADR-001-hydration-pipeline).

## Arquitectura (V1 — congelada)

`estandar-quotes` es un bounded context independiente. Leads sigue siendo la fuente de verdad de lead/submission; Forms es el productor de captura; Signal Core es un ledger comercial audit-only. Quotes es dueño de drafts, revisiones de documento, ciclo de issue, artefactos de entrega y perfiles verticales.

### Modelo canónico

- `es_quotes` — aggregate header mutable + snapshot inmutable de lead/submission al crear el draft.
- `es_quote_revisions` — snapshots canónicos append-only, únicos por `(quote_id, revision_no)`, con digest SHA-256.
- `es_quote_events` — historial append-only (`quote.draft.created`, `quote.revision.created`, `quote.status.{state}`, `quote.operator.rebound`).
- `es_quote_handoffs` — tokens de handoff de un solo uso (256-bit, hash SHA-256, single-use atómico).
- `es_quote_operator_sessions` — sesiones bearer de 4h, sin PII.
- `es_quote_artifacts` — registros inmutables de generación de artefactos (PDF con SHA-256).
- `es_quote_deliveries` — registros de entrega idempotentes (`pending → sending → sent|failed`, + `cancelled`).

**Ciclo de vida:** `draft → review → issued → superseded|expired`, con `review → draft` permitido. `issued` requiere ≥1 revisión. No hay update API; las revisiones solo se agregan.

### Pipeline de hidratación

```
Lead capture → Handoff token (256-bit) → Hydration Pipeline (QuoteDraft en memoria)
  LeadStage(10) → ContextStage(20) → UnitStage(25) → FormStage(30)
  → SettingsStage(40) → FinanceStage(50) → ValidationStage(60) → SnapshotStage(70)
→ Repository::initialize_draft() / save_draft()
```

Reglas: construcción solo sobre `QuoteDraft` en memoria; ningún Stage accede a la DB; `es_quotes` se escribe solo tras completar el pipeline.

## Estado en la Suite

- **Clasificación portfolio:** PRODUCTO PRINCIPAL — **CONTINUAR** + integrar con Core Communications y Document Engine (deuda: dompdf ×2, email propio).

## Licencia

Propietario — Estandar Studio
