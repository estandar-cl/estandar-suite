# Estandar Forms — Pipeline de Leads

## Diagrama de flujo completo

```
Usuario envía formulario
        │
        ▼
handle_submit()
        │
        ├── Validación (nonce, campos, spam)
        ├── build_lead_payload()  ← construye el array $payload
        │
        ▼
store_entry() → wp_estandar_form_entries
        │
        ▼
do_action('estandar_lead_captured', $payload)  ← PUNTO DE INTEGRACIÓN
        │
        ├─────────────────────────────────────────────┐
        │                                             │
        ▼ (priority 10)                               ▼ (priority 20)
Estandar_Leads_Ingestion::capture()           Estandar_Forms_Webhook::maybe_dispatch()
        │                                             │
        ├── Persiste lead (wp_es_leads)               ├── POST fire-and-forget
        ├── Persiste submission (wp_es_lead_submissions)  ├── Modo estandar (JSON v1.0)
        ├── Meta CAPI event (si configurado)           └── Modo cf7_compat
        ├── Asignación de operador
        ├── Scoring
        └── do_action('estandar_lead_ingested', ...)
                │
                └── Estandar_Forms_Handler::capture_recontact_context()
                    (solo si es recontacto: $is_new = false)
```

## Payload estandar_lead_captured v1.0

```php
// Schema completo del array que viaja en el hook
$payload = [
    // ── Identificación ──────────────────────────────
    'submitted_at' => '2026-01-01T00:00:00+00:00',   // ISO 8601 UTC
    'form_id'      => 1,                               // ID del formulario

    // ── Datos del formulario ────────────────────────
    'entries' => [
        'lead' => [
            'nombre' => 'Juan',                         // Campos del usuario
            'email'  => 'juan@example.com',             // (todos los fields normalizados)
            'telefono' => '+56912345678',
            // ... resto de campos
        ],
        'meta' => [
            // Campos de sistema que no son del usuario
            'project_slug' => '',
            'lead_uuid'    => '',
            // ...
        ],
    ],

    // ── Atribución de marketing ─────────────────────
    'attribution' => [
        'utm_source'        => '',
        'utm_medium'        => '',
        'utm_campaign'      => '',
        'utm_content'       => '',
        'utm_term'          => '',
        'fbclid'            => '',    // Facebook Click ID
        'gclid'             => '',    // Google Click ID
        'ttclid'            => '',    // TikTok Click ID
        'msclkid'           => '',    // Microsoft Click ID
        'creative_id'       => '',
        'keyword'           => '',
        'landing_url'       => '',
        'referrer'          => '',
        'first_touch_timestamp' => '',
        'last_touch_timestamp'  => '',
        'session_id'        => '',
    ],

    // ── Contexto de negocio ─────────────────────────
    'context' => [
        'project_slug'   => '',   // Slug del proyecto
        'project_name'   => '',   // Nombre del proyecto
        'project_area'   => '',
        'intent'         => '',   // Intención: cotizacion, contacto, etc.
        'client_slug'    => '',   // Cliente/empresa
        'client_name'    => '',
        'lead_status'    => '',   // Estado inicial del lead
        'source_page_url'=> '',   // URL donde estaba el formulario
        'qualification_summary' => '',  // Si se aplicó qualification
        'client_context' => [     // Snapshot del Client Context Record (Core)
            'company'          => '',
            'business'         => '',
            'services'         => [],
            'primary_service'  => '',
            'lead_goal'        => '',
            'rules'            => [],
            'projects'         => [],
            'handoff' => [
                'owner'      => '',
                'fallback'   => '',
                'conditions' => [],
            ],
        ],
    ],

    // ── Datos de request ────────────────────────────
    'request' => [
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0...',
    ],

    // ── Señales de spam ─────────────────────────────
    'spam' => [
        'honeypot_filled'  => false,
        'seconds_to_submit' => 5,
    ],

    // ── Metadata del envío ──────────────────────────
    'meta' => [
        'source_entry_id'      => 1,    // FK → wp_estandar_form_entries
        'lead_uuid'            => 'uuid-v4-generado-en-frontend',
        'sanitized_raw_fields' => [/* data sanitizada completa */],
        'qualification'        => null, // Resultado de qualification engine
        'reconciliation'       => false, // true si viene de reemit_entry()
    ],
];

// Formularios experience agregan:
$payload['experience'] = [
    'flow'     => 'real_estate_prequalification_v1',
    'steps'    => [/* snapshots de cada step */],
    'result'   => [
        'segment'  => 'subsidio_ds19',
        'score'    => 85,
        'label'    => 'DS19 - Sectores Medios',
    ],
];
```

## Puntos de integración

### Entrada (otros plugins envían leads a Forms)

Usar `do_action('estandar_lead_captured', $payload)` con el schema v1.0. El plugin `estandar-leads` lo captura automáticamente si está activo.

### Salida (Forms notifica a otros sistemas)

1. **estandar-leads** (priority 10): ingestión canónica de leads
2. **Webhook** (priority 20): POST al webhook configurado en el formulario
3. **Filtro `estandar_lead_ingested`**: notificación post-persistencia

### Webhook payload mode

- **`estandar`** (default): envía el payload completo del hook
- **`cf7_compat`**: formato compatible con Contact Form 7 DB, útil para integraciones n8n existentes

### Owner del webhook

Opción `estandar_leads_webhook_owner`:
- `legacy_forms` (default): Forms despacha el webhook
- `leads`: Leads despacha el webhook (después de su propio pipeline)

## Manejo de errores y observabilidad

### debug_ref

Cada respuesta de error incluye un código `debug_ref` (formato `EF-XXXXXXXXXX`) que se registra en telemetría. Permite correlacionar el error que ve el usuario con el log del servidor.

### FORMS_PIPELINE_OBSERVABILITY_V1

Etapas de fallo registradas:
- `form_id` — ID inválido
- `form_lookup` — formulario no encontrado
- `field_config` — fields_json inválido
- `incident_submission` — fallo al persistir incidentes
- `direct_email` — fallo al enviar email directo
- `lead_pipeline_exception` — excepción en listeners de `estandar_lead_captured`
- `lead_ingestion_not_confirmed` — ingesta canónica no confirmó persistencia

### Orphan Reconciliation

`Estandar_Forms_Handler::reemit_entry(int $entry_id)` — re-emite una entry que fue guardada pero cuyo lead pipeline falló. Idempotente: verifica que no exista submission previa vinculada.

Limitaciones:
- Atribución de marketing no recuperable (vivía en POST, no en DB)
- `project_selector`: elección de proyecto perdida (solo `fixed_project` recupera)

## Conversación → Lead (ADR 0034 — MS-3 opt-in)

Las experiencias conversacionales pueden terminar produciendo un lead mediante el **mismo punto
canónico** de ingesta: `estandar_lead_captured`. Desde MS-3, una encuesta con
`survey_config.completion_strategy.type = lead` emite el payload v1.0 al completar (opt-in; las
encuestas por defecto usan `email` y **no** generan lead — comportamiento intacto). El scoring, si
`survey_config.scoring` está definido, se persiste como `survey_result` y emite
`estandar_conversation_lead_qualified`, enriquecido en `payload['meta']['qualification']`.

- Survey (experiencia actual, sin config extra) mantiene `completion_type = email`: no emite
  `estandar_lead_captured` (comportamiento intacto).
- AltaCruz y experiencias futuras usarán el mismo `Capture` normalizado → `estandar_lead_captured`.
- Integraciones (Sheets, Excel, CRM, Email/Brevo, Webhooks) consumen **eventos de dominio del motor**
  vía adaptadores en Core (`dispatch_event`), nunca lógica específica de Survey.

## Dependencias opcionales| Plugin | Función de detección | Impacto si ausente |
|---|---|---|
| Estandar Core | `primer_is_active()` | Sin nav integrada, sin client context, sin sender config |
| Estandar Leads | `class_exists('Estandar_Leads_Ingestion')` | Sin persistencia canónica, sin Meta CAPI, sin scoring |
| Estandar Analytics | `function_exists('estandar_analytics_get_attribution_by_session')` | Sin enriquecimiento de atribución por session |
