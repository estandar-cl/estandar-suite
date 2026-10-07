# Estandar Leads

Ciclo mínimo de verdad comercial para calidad de leads. Capacidad comercial central de la Suite v1 (15 tablas, 78 tests). Versión unificada: 13.0.173.

> Documentación técnica archivada: `docs/historico/plugins/estandar-leads/` (11 documentos: contratos, modelos y ADRs).

## Contratos clave

### AdvertisingEvent (Leads ↔ Signal, Fase 1)

- **estandar-leads** = único Source of Truth comercial. Decide qué hechos son advertising-exportables y resuelve el nombre del evento.
- **estandar-signal-core** = infraestructura pura (ledger, cola, retry, exporters); ya no lee `es_leads.status` ni aplica whitelist.
- **Contrato:** `do_action('estandar_advertising_event_ready', $event)` — campos: `event_id`, `event_name` (`lead_created|lead_contacted|lead_qualified|meeting_booked|sale_closed|lead_rejected`), `occurred_at`, `project_slug`, `lead_id`, `submission_id`, `transaction_id` (idempotencia de plataforma), `lifecycle_key`, `identifiers` (gclid/gbraid/wbraid/fbclid/meta_fbc/meta_fbp/ttclid/msclkid), `identity` (normalizada, NO hasheada), `currency`, `value`, `meta`.
- Nunca transporta: status crudo, sales_stage, whitelist, reglas de calificación ni el Lead completo.
- PII: `identity.email_normalized` / `phone_normalized` van en el evento (Enhanced Matching) pero **nunca** en logs/telemetría/payloads de debug.

### Atribución

- **Lead** = persona canónica; `first_*` son first-touch inmutables; `latest_touch` se deriva de la Submission más reciente.
- **Submission** = un ingreso concreto; sus columnas de atribución son el snapshot `current_touch`.
- **Resolver único:** `Estandar_Leads_Attribution::resolve_channel()` para Analytics, Commercial Intelligence, OCI, exports y notificaciones.
- Precedencia Google: `gclid`/`gbraid`/`wbraid` → Google paid `utm_medium` → `organic` → referrer Google → fallback. `utm_source=google` solo nunca resuelve a Google Ads.
- Meta Ads requiere evidencia de adquisición (`fbclid`, `meta_fbc`, fuente explícita). `meta_fbp` nunca cambia un canal orgánico/directo/referral a Meta Ads.
- Sin evidencia, nunca se fabrica `Organic`; la ausencia de datos degrada a empty/unknown (nunca backfill de otro ingreso).

## Estado en la Suite

- **Clasificación portfolio:** PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY — **MANTENER POR COMPATIBILIDAD** y migrar a Flow. NO retirar hasta paridad de Flow.
- Prioridad de consolidación #2: unificar Google OCI / Meta CAPI (leads + signal-core + destinations → un motor).

## Licencia

Propietario — Estandar Studio
