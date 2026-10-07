# Estandar Suite — Documentación del Proyecto

**Fuente de verdad única:** `Estandar_Suite`
**Versión unificada:** 13.0.173
**Última revisión:** 2026-08-12

---

## 1. Qué es Estandar Suite

Plataforma de plugins WordPress para Estandar Studios: captura de leads, inteligencia comercial, pipeline de ventas, analítica, cotizaciones y feedback publicitario. Conviven dos generaciones:

- **Sistema v1 (Legacy, 18 plugins):** columna vertebral operativa con datos y producción real (leads, chatbot, analytics, forms, ads).
- **Sistema v2 (5 plugins `Estandar\` + 4 híbridos):** nueva arquitectura con Core como infraestructura compartida y bounded contexts.
- **Capa compartida (`estandar-primer`):** monolito transversal (AI gateway, diagnostics, campaign resolver, updates).

> La documentación original histórica (auditorías, specs, ADRs, changelogs, docs por plugin) vive en `docs/historico/`. Este documento consolida la información vigente y de referencia.

---

## 2. Arquitectura

### 2.1 Visión objetivo

```
Core (estandar-core) — infraestructura compartida
  ├── Capture (captura) · Brain (inteligencia) · Flow (comercial) · Insights (analítica)
  └── Quote Engine (cotizaciones, independiente)
```

### 2.2 Motores transversales (Core)

| Motor | Estado | Notas |
|---|---|---|
| Communications Engine | 🟢 TESTEADO | TransportRegistry + 3 adapters (WpMail/SMTP/Brevo), 22 tests |
| Integration Core | 🟡 IMPLEMENTADO | OAuth2, Adapters, FieldMap, Sandbox, 216 tests |
| Interest Evolution Engine (IEE) | 🟢 TESTEADO | Detección de señales de intención, 88 tests |
| Quality Engine | 🟡 IMPLEMENTADO | Contactabilidad + Lead Quality (consolidar con Brain) |
| Document Engine | 🟢 MOTOR ÚNICO (S16) | PDF real vía `Core\Pdf` con dompdf embebido una sola vez en estandar-core (S16) |
| Import/Adapters | 🟠 PARCIAL | GoogleSheets/ExcelOnline, sin E2E real |
| Attribution Engine | 🟠 PARCIAL | Fragmentado v1+v2, consolidar |
| Campaign Resolver | 🟡 IMPLEMENTADO (v1 primer) | Migrar a Brain |

### 2.3 Contratos compartidos

Hooks cross-plugin: `estandar_suite_capture_event`, `estandar_suite_form_entry_created`, `estandar_suite_signal_recorded`, `estandar_suite_journey_transition`.

Adopción legacy: `AdoptionProbe`, `FormsAdoption` (598 entries preservadas), `LegacyMap`, Data-Settings Matrix.

---

## 3. Decisión de portafolio (2026-08-09) — RECTORA

Clasificación y decisión por elemento (ver `docs/historico/auditorias/` para el detalle completo):

| Decisión | Elementos |
|---|---|
| **CONTINUAR** | core, capture, brain, flow, insights, quotes, Communications, Integration Core, IEE, Quality, Document, ConversationEngine/FormBridge, acceptance |
| **MANTENER COMPATIBILIDAD** (hasta paridad) | leads, forms, chatbot, analytics, experience-blocks, pop, signal-core, seo-intelligence, website-scanner |
| **CONSOLIDAR** (absorber) | primer (→Core/Brain), destinations (→Core\Destinations), secure-share (→Core\Secrets), wp-exporter (→Kickoff), firmas, Attribution Engine, Campaign Resolver |
| **PAUSAR** | cpt-manager, delivery-locator-lite |
| **ABANDONAR** | notifications (stub superado), duplicate-post (WP nativo), firmas (tras decisión) |
| **INVESTIGAR ANTES DE DECIDIR** | Contact Strategy, Postventa, Commercial Knowledge, Feedback reutilizable, SEO auto, Dragon API, uso real de cpt-manager/scanner/delivery |

### Prioridad de consolidación

1. Unificar versionado (release.command — causa raíz inconsistencia 171/172)
2. Consolidar Google OCI / Meta CAPI (leads + signal-core + destinations → un motor)
3. ~~Unificar Document/PDF~~ → **HECHO (S16, 07/09): motor único `Core\Pdf` con dompdf embebido una sola vez en estandar-core** (el legacy estandar-quotes con su copia fue purgado en la limpieza v2-only)
4. Unificar Attribution (v1 analytics + primer + v2 Capture)
5. Absorber primer en Core/Brain
6. Integrar Quotes con Communications Engine
7. Migrar Surveys v1 → Capture v2
8. Paridad de Flow vs leads e Insights vs analytics antes de retirar v1

### Núcleo real

- **Núcleo operativo:** leads + chatbot + analytics + primer (datos y producción real).
- **Núcleo arquitectónico:** core + capture (motores).

### Condiciones para retirar Legacy

Retirar solo cuando **todos** tengan equivalente v2 validado: leads→Flow, analytics→Insights, chatbot→Brain, surveys→Capture, ads→motor unificado, autorespuesta, campaign resolver, experience. Retiro definitivo tras 6–12 meses de operación v2 sin dependencias de v1.

---

## 4. Auditorías (2026-08-07/09)

| Auditoría | Conclusión clave |
|---|---|
| **Estado Real** | ~62% cobertura funcional, ~28% testeado, 0 READY FOR RELEASE, 1 producción (Capture Forms acacios.cl) |
| **Segunda Capa** | Deuda principal: consolidación y testing (14/27 plugins con 0–1 tests). Flow = Catch. Archipiélago, no plataforma |
| **Decisión de Portafolio** | Síntesis rectora (sección 3) |

Detalles completos en `docs/historico/auditorias/`.

---

## 5. Decisiones de arquitectura (ADRs)

| ADR | Decisión |
|---|---|
| **ADR-001 (Quotes)** | Pipeline de hidratación declarativo como único mecanismo de construcción de cotizaciones |
| **ADR-003** | Project Registry V2 congelado en SCHEMA_VERSION 2.1; Regla de Oro (ningún consumer depende solo de campos V2) |
| **ADR-004 (Leads)** | Mantener 3 sistemas de prioridad independientes (Scoring, Notifier, Workspace) |
| Serie ADR 0024–0034 (v2) | Contracts de calidad, conversación (0034), seguridad de release (0032), integraciones |

Documentos completos en `docs/historico/`.

---

## 6. Especificaciones vigentes

| Spec | Estado | Referencia |
|---|---|---|
| `TICKET_CONTACT_ATTEMPT_TRACKING_V1` | SPEC — contrato abierto | `docs/historico/especificaciones/` |
| `ESTANDAR_CAMPAIGN_MAPPINGS_V1` | Implementada (resolver SSOT vigente) | `docs/historico/especificaciones/` |
| `ESTANDAR_META_IMPORT_V1` | SPEC_FROZEN (analytics ads_daily) | `docs/historico/especificaciones/` |
| `ESTANDAR_CLEANUP_V1` | SPEC_DRAFT (plugin no construido) | `docs/historico/especificaciones/` |

---

## 7. Contrato de eventos y webhooks (estable)

### 7.1 Contrato público del evento

Solo el contrato público es estable, versionado por `schema_version`. Los hooks internos, adapters y colas pueden cambiar.

```
{dominio}.{entidad}.{accion_pasado}   →   lead.created · lead.status.changed · sale.closed
```

**Envelope público v1:**

| Campo | Requisito | Descripción |
|---|---|---|
| `schema_version` | Siempre | Semver major.minor del contrato |
| `event` | Siempre | Nombre canónico dot-notation |
| `event_id` | Siempre | `ev_`+20 alfanuméricos; idempotencia; nunca cambia para el mismo hecho |
| `occurred_at` | Siempre | UTC ISO-8601 |
| `producer` | Siempre | Plugin o sistema origen |
| `project` | Opcional | Slug multi-tenant |
| `source` | Opcional | Canal de captura |
| `correlation_id` | Opcional | `c_`+32 hex; estable en todo el flujo |
| `entity` | Opcional | `{ type, id }` |
| `data` | Opcional | Payload específico, sin credenciales |

**Catálogo de eventos estables:** `lead.created`, `lead.recontacted`, `lead.status.changed`, `lead.quality.changed`, `lead.rejected`, `sale.closed`, `classification.automatic.recorded`, `classification.human.recorded`, `contact.attempted`, `meeting.booked`, `journey.stage.changed`, `destination.delivery.requested/succeeded/failed`. Planificados: `submission.created`, `quote.issued`, `survey.completed`.

Reglas: catálogo add-only; eventos obsoletos se marcan `DEPRECATED`, nunca se reutiliza el nombre; cambios incompatibles incrementan major.

### 7.2 Webhooks

- POST JSON inmediato tras captura; sin polling.
- **Headers:** `Content-Type`, `X-Estandar-Event`, `X-Estandar-Event-ID`, `X-Estandar-Schema-Version`, `X-Estandar-Timestamp`, `X-Estandar-Signature` (`sha256=<hex>`), `User-Agent: Estandar-Webhook/1.0`.
- **Firma HMAC-SHA256:** `HMAC(secreto, timestamp + "." + event_id + "." + body)`. Secreto por proyecto.
- **Replay:** ventana ±5 min; idempotencia por `event_id`.
- **Reintentos:** 200=ok, 400/404=dead, 401/429/500/timeout=reintenta (backoff 1→5→15→30→60 min, máx 5).
- Timeout del emisor: 15 s.

Docs completos en `docs/historico/arquitectura/` y `docs/historico/integraciones/`.

---

## 8. Journey comercial canónico

```
captura → lead canónico → clasificación automática + humana → eventos Journey → Signal Core → Destinations → Meta/Google Ads
```

- **Journey** = ledger canónico append-only, auditable, versionado. El estado se deriva de sus eventos.
- Clasificación automática y humana son eventos separados; la proyección aplica precedencia humana sin borrar evidencia.
- **Signal Core** valida contrato, decide rutas, registra intentos; no guarda credenciales ni construye payloads.
- **Destinations** aloja providers desacoplados (traducción, cola, retry, auditoría).
- Meta y Google Ads consumen el mismo evento canónico con traducciones independientes.
- **Duplicaciones actuales:** calificación en Forms/Chatbot/Leads; Google Ads en 3 caminos (Leads, Signal Core, Destinations); selección de estados exportables duplicada.

---

## 9. Plugins

| Plugin | Rol | Docs |
|---|---|---|
| estandar-core | Infraestructura compartida | README.md |
| estandar-brain | Inteligencia (Rules, Scoring, Agent, PublicChat) | README.md |
| estandar-capture | Captura (Forms, Ingestion, ConversationEngine) | README.md |
| estandar-flow | Pipeline comercial (= "Catch") | README.md |
| estandar-insights | Analítica/Observabilidad | README.md |
| estandar-quotes | Cotizaciones (bounded context) | README.md |
| estandar-forms | Forms + Surveys (v1) | README.md |
| estandar-leads | Lead management (v1) | README.md |
| estandar-chatbot | Chatbot IA (v1) | README.md |
| estandar-analytics | Analytics (v1) | README.md |
| estandar-experience-blocks | 13 bloques Gutenberg UX | README.md |
| estandar-pop | Popups Gutenberg | README.md |
| estandar-primer | Runtime compartido | README.md |
| estandar-kickoff | 16 módulos de utilidades | README.md |
| estandar-delivery-locator-lite | Localizador de delivery | README.md |
| +13 plugins legacy | Ver decisión de portafolio | README.md cada uno |

Cada plugin tiene su `README.md` consolidado dentro de `plugins/<plugin>/`. Documentación histórica por plugin en `docs/historico/plugins/`.

---

## 10. Release (SiteGround)

- **Pipeline:** `release.command` — gates (versión unificada 26/26, coherencia header↔constante interna, tests de calidad, scan de secretos) → bump de versión → php -l → build ZIPs reproducibles → metadatos (versions.json + manifest.json + sha256) → SFTP a `ssh.estandar.cl:18765` → atomic switch → verificación.
- **Canales:** legacy (frozen, `updates.estandar.cl`) + v2 (`updates.estandar.cl/staging/v2`).
- **Config:** `docs/release-channels.json`, `docs/estandar-updates/versions.json`, `.release-history.log`, `docs/releases/` (ZIPs históricos).
- **Regla de rollback:** flota completa, nunca mezclar versiones.
- **Issue conocido:** constantes internas quedan desincronizadas tras el bump (triple esquema de versionado) — prioridad #1 de consolidación.

---

## 11. Estructura de la fuente de verdad

```
Estandar_Suite/
├── docs/                  ← documentación activa
│   ├── ESTANDAR_SUITE.md  ← este documento
│   ├── INDEX.md           ← índice de navegación
│   └── historico/         ← documentación anterior (auditorías, specs, ADRs, changelogs, docs por plugin)
├── plugins/               ← 26 plugins, cada uno con su README.md
├── release.command        ← pipeline de release SiteGround
├── docs/ (release-channels.json · estandar-updates/ · releases/ · ecosystem-manifest.json)
└── .md de la raíz         ← (archivados en docs/historico/raiz/)
```
