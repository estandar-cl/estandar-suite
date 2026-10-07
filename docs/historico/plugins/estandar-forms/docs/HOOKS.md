# Estandar Forms — Catálogo de Hooks y Filtros

## Acciones (actions) — emitidas por el plugin

### `estandar_lead_captured`

**Punto principal de integración.** Se dispara después de construir el payload del lead.

```php
do_action('estandar_lead_captured', array $payload);
```

**Payload schema v1.0:**

```php
[
    'submitted_at'   => '2026-01-01T00:00:00+00:00',
    'form_id'        => 1,
    'entries'        => [
        'lead' => [/* fields y valores del formulario */],
        'meta' => [/* campos ocultos + sistema */],
    ],
    'attribution'    => [
        'utm_source'   => '',
        'utm_medium'   => '',
        'fbclid'       => '',
        'gclid'        => '',
        // ... todos los UTM + click IDs + creative/campaign
        'landing_url'  => '',
        'referrer'     => '',
        'session_id'   => '',
    ],
    'context'        => [
        'project_slug' => '',
        'project_name' => '',
        'intent'       => '',
        'client_slug'  => '',
        // ...
    ],
    'request'        => ['ip_address' => '', 'user_agent' => ''],
    'spam'           => ['honeypot_filled' => false, 'seconds_to_submit' => 3],
    'meta'           => [
        'source_entry_id' => 0,
        'lead_uuid'       => '',
        'qualification'   => null,
    ],
]
```

**Listeners registrados:**
- `Estandar_Leads_Ingestion::capture()` — priority 10 (persiste lead + submission)
- `Estandar_Forms_Webhook::maybe_dispatch()` — priority 20 (webhook fire-and-forget)

### `estandar_lead_ingested`

Se dispara después de que el lead fue persistido por `estandar-leads`.

```php
do_action('estandar_lead_ingested', int $lead_id, int $submission_id, bool $is_new, array $payload);
```

### `estandar_forms_webhook_sent`

Se dispara después de intentar enviar un webhook.

```php
do_action('estandar_forms_webhook_sent', string $url, array $payload, int $form_id);
```

### `estandar_forms_spam_blocked`

Se dispara cuando se detecta spam.

```php
do_action('estandar_forms_spam_blocked', string $reason, int $form_id, array $post_data);
```

### `estandar_forms_telemetry_event`

Emitido internamente para logging. Delegado a `estandar_telemetry_event`.

```php
do_action('estandar_telemetry_event', [
    'source_plugin' => 'estandar-forms',
    'type'          => 'business_event',
    'severity'      => 'error|warning|info',
    'code'          => 'FORMS_SUBMISSION_ERROR_RESPONSE',
    'message'       => '...',
    'context'       => ['form_id' => 1, 'debug_ref' => 'EF-...'],
]);
```

---

### Eventos de dominio del Conversation Engine (ADR 0034 — implementados en MS-3, aditivos)

El motor conversacional publica eventos genéricos que las integraciones consumen mediante adaptadores
desacoplados (Google Sheets, Excel Online, CRM, Email/Brevo, Calendar, Webhooks y futuras).
**Aditivos** — no reemplazan `ea_events` (funnel/telemetría) ni `estandar_lead_captured` (ingesta
canónica de leads). Emisión actual: `Estandar_Survey_Handler` (start/answer/complete) — MS-3.

| Evento | Propósito | Momento |
|---|---|---|
| `estandar_conversation_started` | Sesión iniciada (identificación/proyecto/token) | handler `start` |
| `estandar_conversation_step_presented` | El usuario vio un paso/pregunta | al servir/activar el paso |
| `estandar_answer_validated` | Respuesta pasó validación server-side | tras validar |
| `estandar_answer_saved` | Respuesta persistida | tras INSERT/UPDATE en `estandar_survey_responses` |
| `estandar_conversation_completed` | Sesión terminada correctamente | al completar, antes de acciones finales |
| `estandar_survey_completed` | Especialización para encuestas (compatibilidad) | idem `conversation_completed` |
| `estandar_conversation_lead_qualified` | Resultado de scoring (segmento/puntaje/tier) | al completar si la experiencia define scoring |
| `estandar_quote_generated` | Cotización calculada | al completar experiencia de cotización |
| `estandar_booking_requested` | Reserva de reunión/espacio | al completar experiencia de reserva |
| `estandar_post_sale_completed` | Postventa finalizada (incidentes/solicitudes) | al completar |
| `estandar_assessment_completed` | Evaluación terminada (reporte por dimensiones) | al completar |

Payload canónico de cada evento, especializaciones y reglas de emisión: ver ADR 0034 y el informe
MS-0. Los eventos de funnel actuales `survey_*` en `ea_events` se mantienen intactos.

---

## Filtros (filters) — disponibles para extensión

### Validación

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_validation_errors` | `[]` | Modificar errores de validación antes del redirect |
| `estandar_forms_min_submit_seconds` | `3` | Segundos mínimos entre carga y submit |
| `estandar_forms_rate_limit_window` | `600` | Ventana de rate limit (segundos) |
| `estandar_forms_rate_limit_max_attempts` | `5` | Máximos intentos por IP+form en la ventana |

### Anti-spam / contenido

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_spam_max_urls` | `2` | Max URLs antes de marcar spam |
| `estandar_forms_spam_keywords` | `[]` | Array de keywords bloqueadas |

### reCAPTCHA

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_require_recaptcha` | valor del form | Forzar/no forzar reCAPTCHA |
| `estandar_forms_recaptcha_action` | `estandar_form_submit` | Action name reCAPTCHA |
| `estandar_forms_recaptcha_min_score` | `0.5` | Umbral mínimo de score |

### Email

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_mail_subject` | template del form | Modificar asunto del email |
| `estandar_forms_mail_body` | template del form | Modificar cuerpo del email |
| `estandar_forms_brand_color` | `#0069ff` | Color de marca para templates de email |

### Datos pre-insert

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_pre_insert_data` | `$data` | Modificar datos antes de persistir |

### Webhook

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_webhook_payload` | `$payload` | Modificar payload enviado al webhook |
| `estandar_forms_webhook_args` | `$args` | Modificar argumentos de `wp_remote_post` |
| `estandar_forms_webhook_cf7_payload` | `$payload` | Modificar payload en modo cf7_compat |

### Export

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_export_entries` | `$entries` | Modificar entries antes de exportar CSV |
| `estandar_forms_export_filename` | `estandar-forms-{date}.csv` | Nombre del archivo de exportación |
| `estandar_forms_export_selected_entries` | `$entries` | Modificar entries seleccionadas |

### UI (Frontend)

| Filtro | Default | Descripción |
|---|---|---|
| `estandar_forms_project_selector_label` | `Empresa` | Label del selector de proyecto |
| `estandar_forms_project_selector_placeholder` | `— Seleccionar —` | Placeholder del selector |

---

## Endpoints registrados

### admin-post.php

| Action | Handler | Auth |
|---|---|---|
| `estandar_form_submit` | `Estandar_Forms_Handler::handle_submit` | Público |
| `estandar_forms_export` | `Estandar_Forms_Template::handle_export` | Admin |
| `estandar_forms_import` | `Estandar_Forms_Template::handle_import` | Admin |
| `estandar_forms_import_preflight` | `Estandar_Forms_Template::handle_import_preflight` | Admin |
| `estandar_forms_import_confirmed` | `Estandar_Forms_Template::handle_import_confirmed` | Admin |
| `estandar_forms_import_resolve` | `Estandar_Forms_Template::handle_import_resolve` | Admin |
| `estandar_forms_duplicate` | `Estandar_Forms_Template::handle_duplicate` | Admin |

### admin-ajax.php (AJAX)

| Action | Handler | Auth |
|---|---|---|
| `estandar_forms_json_export` | `Estandar_Forms_Template::handle_ajax_export` | Admin |
| `estandar_forms_json_validate` | `Estandar_Forms_Template::handle_ajax_validate` | Admin |
| `estandar_forms_json_diff` | `Estandar_Forms_Template::handle_ajax_diff` | Admin |
| `estandar_forms_json_apply` | `Estandar_Forms_Template::handle_ajax_apply` | Admin |
| `estandar_forms_json_history` | `Estandar_Forms_Template::handle_ajax_history` | Admin |
| `estandar_survey_start` | `Estandar_Survey_Handler::handle_ajax_start` | Público |
| `estandar_survey_event` | `Estandar_Survey_Handler::handle_ajax_event` | Público |
| `estandar_survey_answer` | `Estandar_Survey_Handler::handle_ajax_answer` | Público |
| `estandar_survey_complete` | `Estandar_Survey_Handler::handle_ajax_complete` | Público |
| `ef_get_streets` | `Estandar_Forms_Dependent_Select::handle_ajax` | Público |

### template_redirect

| Ruta | Handler |
|---|---|
| `estandar_survey_response_route` | `Estandar_Survey_Handler::handle_secure_response_route` |

---

## Opciones de WordPress

| Opción | Tipo | Descripción |
|---|---|---|
| `estandar_forms_db_version` | string | Versión del schema (actual: `2.4`) |
| `estandar_surveys_db_version` | string | Versión del schema de surveys (actual: `1.1`) |
| `estandar_leads_webhook_owner` | string | `legacy_forms` o `leads` — controla quién despacha webhooks |
