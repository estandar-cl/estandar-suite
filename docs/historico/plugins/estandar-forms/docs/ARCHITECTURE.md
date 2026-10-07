# Estandar Forms — Arquitectura

Versión: 13.0.158
Última actualización: 2026-08-04 (canonicalización arquitectónica MS-0.5)

> **Fuente de verdad arquitectónica:** este documento, junto con
> `ADR 0034 — Conversation Engine` (`~/Applications/Dragon/docs/adr/0034-conversation-engine.md`).
> La sección "Conversation Engine (arquitectura objetivo)" describe el diseño **aprobado y
> pendiente de implementación**; las secciones de flujo y clases describen el estado **actual**.

## Conversation Engine (ADR 0034 — implementado en MS-1..MS-4d)

> Estado de implementación: contrato (MS-2) + capacidades sobre Survey (MS-3) + catálogo único
> (MS-4a) + factorización del runtime (MS-4b: estado de sesión y validación) + captura única
> (MS-4c) + desacoplamiento nominal de Survey (MS-4d: `flow_map`, `capture_*`, `validate_answer_value`).
> + **experiencias por configuración (MS-5/MS-6):** render conversacional por PERFIL (`config_for_form`),
> handler y dispatch generalizados (`is_conversational`), builder universal (selector de tipo de
> experiencia + `conversation_config`), matrix validada en `1..scale`. Survey = perfil existente;
> Lead Qualification (AltaCruz) = primera experiencia por configuración.

**El Conversation Engine es la abstracción arquitectónica principal para todas las experiencias
conversacionales de Estandar Forms.** Survey deja de entenderse como una arquitectura propia y pasa a
ser la **primera experiencia implementada** sobre dicho motor.

### Modelo en capas

```
motor (Conversation Engine)  →  experiencias (config)  →  integraciones (adaptadores)  →  acciones finales
```

| Capa | Contenido | Estado |
|---|---|---|
| **Motor** | Sesión (`capture_session_*`), validación/sanitización por tipo (`validate_answer_value`), branching (`flow_map`), scoring (`evaluate_scoring`), persistencia de respuestas (`capture_response_*`), estado de sesión (`SESSION_STATUS_*`), eventos de dominio, catálogo único de widgets. | **Implementado** (MS-2..MS-4d) |
| **Experiencias** | Config de flujo + scoring + completion strategy + identificación + tema. `form_type` es etiqueta de experiencia: `survey`, `lead_qualification`, `postventa`, `cotizacion`, `wizard`, `assessment`, `quiz`, `booking`, … | Survey + Lead Qualification (AltaCruz) por config (MS-5). Resto: catálogo declarado (MS-6) |
| **Integraciones** | Adaptadores desacoplados (Google Sheets, Excel Online, CRM, Email/Brevo, Webhooks, Calendar, automatizaciones) que **consumen eventos del motor**. El runtime no conoce ninguna integración (Google es solo una integración). | Diseñado (eventos emitidos MS-3) |
| **Acciones finales** | Estrategia de finalización configurable: email, lead (`estandar_lead_captured`), request/incident, artefacto/cotización, reporte, calendario. | email + lead opt-in (MS-3/MS-5) |
| **Builder** | Editor de definiciones: catálogo de widgets, flujo/branching, scoring, completion, JSON lifecycle (compartido). | Universal (selector de tipo + `conversation_config`, MS-6) |
| **Capture** | Ingesta normalizada única (AJAX conversacional → `capture_*`; submit clásico intacto), adopción legacy (`estandar-capture`). | **Convergido para conversacional** (MS-4c); clásico intacto (C4 futuro) |
| **Core** | Destinos evento→destino (`dispatch_event`), tokens seguros, telemetría, comunicaciones, OAuth2. | Implementado en estandar-core |

### Principios permanentes (Conocimiento Transversal Aprobado)

1. **Adopción:** descubrir → interpretar → reutilizar → absorber → adaptar. Nunca reemplazar primero.
2. **Cero pérdida de configuración:** toda evolución preserva conexiones, mappings, credenciales,
   API Keys, webhooks, analytics, GTM/GA4, Google Sheets, Excel, Brevo/SMTP, pipelines,
   destinatarios, autorespuestas e integraciones existentes. Nunca sobrescribir por defaults.
3. **Runtime agnóstico de integraciones:** el motor solo publica eventos; los adaptadores reaccionan.
4. **Motor único de captura:** la diferencia entre experiencias es configuración, no motores.
5. **Contratos estables:** compatibilidad Legacy ↔ Suite v2 preservando `form_type`, `survey_config`,
   REST, AJAX, Builder, Hooks, WP-CLI y JSON Schema.

### Modelo de eventos de dominio (diseño)

El motor publica eventos genéricos; las experiencias emiten especializaciones. Todos son **aditivos**
y **no reemplazan** `ea_events` (funnel) ni `estandar_lead_captured` (ingesta canónica de leads):

- Motor: `estandar_conversation_started` · `estandar_conversation_step_presented` ·
  `estandar_answer_validated` · `estandar_answer_saved` · `estandar_conversation_completed`.
- Experiencia: `estandar_survey_completed` · `estandar_conversation_lead_qualified` · `estandar_quote_generated` ·
  `estandar_booking_requested` · `estandar_post_sale_completed` · `estandar_assessment_completed`.

Detalle completo (propósito, payload, momento, consumidores) en el ADR 0034 y en el informe MS-0.

### Compatibilidad garantizada

`form_type=survey`, `survey_config`, `estandar_survey_responses`, AJAX `estandar_survey_*`, eventos
`survey_*` en `ea_events`, módulos de token `survey`/`survey_results`, shortcode `[estandar_form]`,
JSON Schema/envelope y consumers externos (`estandar-capture`, `estandar-brain`) permanecen intactos.

---

## Diagrama de flujo

```
[estandar_form] shortcode
        │
        ▼
  form-render.php ──────── conditional asset loading ──────── JS/CSS enqueued
        │                                                     (only when form present)
        ▼
  POST → admin_post_estandar_form_submit
        │
        ▼
  Estandar_Forms_Handler::handle_submit()
        │
        ├── 1. Validate (nonce, form lookup, fields)
        ├── 2. Spam check (honeypot, speed, rate limit, content, recaptcha, token)
        ├── 3. Validate fields (type-specific, RUT, file, conditional)
        ├── 4. Qualify (Estandar_Forms_Qualification_Engine)
        │
        ├── [Incident forms] ─────────────────────────────────┐
        │   ├── prepare_incident_submission                    │
        │   ├── handle_incident_submission                     │
        │   │   └── Incident_Engine::store_all()  ──┬── requests
        │   │                                       ├── incidents
        │   │                                       └── attachments
        │   └── Action_Engine::run()               ──┬── Action_Database
        │                                             ├── Action_Email
        │                                             └── Action_Webhook
        │
        ├── [email_direct forms]
        │   └── send_direct_email()
        │
        ├── [lead/lead_capture/experience forms]
        │   └── build_lead_payload()
        │       │
        │       ▼
        │   do_action('estandar_lead_captured', $payload)
        │       │
        │       ├── Estandar_Leads_Ingestion::capture()  (priority 10)
        │       │   └── Persiste lead + submission + Meta CAPI
        │       │
        │       └── Estandar_Forms_Webhook::maybe_dispatch()  (priority 20)
        │           └── POST fire-and-forget → n8n / webhook URL
        │
        └── redirect_with_feedback() → transient → frontend JS
```

## Clases y responsabilidades

| Clase | Archivo | Rol |
|---|---|---|
| `Estandar_Forms_DB` | `class-db.php` | Schema (7 tablas), migraciones, índices, starter form |
| `Estandar_Forms_Handler` | `class-forms.php` | Orquestador de envío: validación, spam, routing, pipeline leads, incidentes |
| `Estandar_Forms_Shortcode` | `class-shortcode.php` | `[estandar_form]` render + asset loading condicional |
| `Estandar_Forms_Webhook` | `class-webhook.php` | Dispatch fire-and-forget, modos estandar/cf7_compat |
| `Estandar_Forms_Template` | `class-template.php` | Export/import/duplicate (.estandar-template.json v1.1) + editor JSON canónico |
| `Estandar_Incident_Engine` | `class-incident-engine.php` | Persistencia transaccional Request → Incidents → Attachments |
| `Estandar_Forms_Repeater` | `class-repeater.php` | Parser del repeater frontend (incidents[] + files[]) |
| `Estandar_Forms_Action_Engine` | `class-action-engine.php` | Pipeline de acciones post-submit (db, email, webhook) |
| `Estandar_Forms_Action_Database` | `class-action-database.php` | Acción: persistir request |
| `Estandar_Forms_Action_Email` | `class-action-email.php` | Acción: notificar por email |
| `Estandar_Forms_Action_Webhook` | `class-action-webhook.php` | Acción: webhook externo |
| `Estandar_Survey_Handler` | `class-survey-handler.php` | API AJAX de surveys (start, event, answer, complete) |
| `Estandar_Survey_DB` | `class-survey-db.php` | Schema + migraciones de survey |
| `Estandar_Survey_Analytics` | `class-survey-analytics.php` | Agregación de analytics de survey |
| `Estandar_Experience_Engine` | `class-experience-engine.php` | Pre-calificación inmobiliaria (subsidio, DS19, DS49) |
| `Estandar_Forms_Qualification_Engine` | `class-qualification-engine.php` | Scoring de leads por perfil |
| `Estandar_Forms_Dependent_Select` | `class-dependent-select.php` | Selects dinámicos proyecto → calle |
| `Estandar_Secure_Token` | `class-secure-token.php` | Enlaces públicos seguros con expiración |

## Tipos de formulario (form_type)

> `form_type` es la **etiqueta de experiencia**. Los tipos existentes se mantienen intactos. En la
> arquitectura objetivo (ADR 0034), toda experiencia conversacional (survey y futuras) corre sobre el
> **Conversation Engine** mediante configuración; ningún caso nuevo creará un motor paralelo.

| form_type | Motor | Pipeline | Uso |
|---|---|---|---|
| `lead` / `lead_capture` | Handler estándar | `estandar_lead_captured` → Leads | Captura de leads (default) |
| `experience` | Handler + Experience Engine | `estandar_lead_captured` + `experience` en payload | Pre-calificación inmobiliaria |
| `email_direct` | Handler con envío directo | wp_mail() directo | Formularios de contacto simple |
| `incident` | Handler + Incident Engine | Incident_Engine::store_all() + Action_Engine::run() | Post-venta, solicitudes |
| `survey` | **Primera experiencia del Conversation Engine** (runtime actual: `Survey_Handler` AJAX) | AJAX step-by-step, sin pipeline leads | Encuestas Typeform-like |

Tipos adicionales aceptados por el linter JSON (etiquetas declaradas, sin motor dedicado; en la
arquitectura objetivo son experiencias del motor): `general`, `feedback`, `postventa`, `soporte`.
Experiencias futuras previstas (MS-5+): `lead_qualification` (AltaCruz), `cotizacion`, `wizard`,
`assessment`, `quiz`, `booking`.

## Routing V2 (context_schema)

Define cómo un formulario asigna un proyecto al lead.

| Modo | Descripción |
|---|---|
| `none` | Sin routing |
| `fixed_project` | Proyecto fijo definido en backend (`project_slug`, `project_name`) |
| `project_selector` | Selector de N proyectos (registry). Seguridad: el slug enviado se valida contra la lista permitida almacenada en DB, nunca contra POST |

## Anti-spam (6 capas)

1. **Honeypot** — campo oculto `estandar_website`
2. **Tiempo mínimo** — default 3s (filtrable: `estandar_forms_min_submit_seconds`)
3. **Rate limit** — 5 intentos por IP+form en 10 min (transient)
4. **Contenido** — max URLs, repetición de caracteres, keywords bloqueables
5. **reCAPTCHA v3** — opcional, umbral configurable por filtro
6. **Token de envío** — deduplicación de doble submit (transient 1 min)

## Convenciones de código

- Todas las clases usan métodos estáticos (sin DI, sin instanciación)
- Dependencias externas con `function_exists()` / `class_exists()` — degradación con gracia
- Sanitización estricta en todas las entradas
- Escape en todas las salidas
- Telemetría en cada error: `estandar_forms_telemetry_event()`
- `debug_ref` en respuestas de error para correlación con logs
