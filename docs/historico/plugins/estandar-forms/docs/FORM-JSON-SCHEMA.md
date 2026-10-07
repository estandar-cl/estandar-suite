# Estandar Forms — Formato JSON de Formularios

## `fields_json` — Definición de campos

Array de objetos. Cada campo tiene esta estructura base:

```json
{
  "type": "text",
  "name": "nombre",
  "label": "Nombre completo",
  "required": true,
  "placeholder": "Ingresa tu nombre",
  "error_message": "El nombre es obligatorio.",
  "column_width": "2",
  "options": [],
  "conditional_logic": [],
  "default_value": ""
}
```

### Tipos de campo (15)

| type | Descripción | Props extra |
|---|---|---|
| `text` | Texto simple | — |
| `email` | Email con validación | — |
| `textarea` | Texto multilínea | `rows` |
| `number` | Numérico | `min`, `max`, `step` |
| `tel` | Teléfono (validación por país) | — |
| `date` | Fecha | — |
| `select` | Dropdown | `options: [{value, label}]` |
| `radio` | Radio buttons | `options: [{value, label}]` |
| `checkbox` | Checkbox (single o group) | `options`, `checkbox_mode` |
| `file` | Upload de archivo | `accept`, `max_size_mb` |
| `rut` | RUT chileno con validación módulo 11 | — |
| `project_select` | Selector de proyecto (Registry) | — |
| `dependent_select` | Select dependiente (ej: proyecto → calle) | `depends_on` |
| `hidden` | Campo oculto | `default_value` |
| `custom` | HTML/JS personalizado | `html` |

### `conditional_logic` — Lógica condicional

Array de reglas. Un campo se muestra si TODAS las reglas coinciden:

```json
{
  "conditional_logic": [
    {
      "field": "tipo_contacto",
      "operator": "equals",
      "value": "telefono"
    }
  ]
}
```

Operadores: `equals`, `not_equals`, `contains`, `not_contains`, `greater_than`, `less_than`.

### `column_width` — Ancho en grid

Valores: `"1"` (25%), `"2"` (50%), `"3"` (75%), `"4"` (100%).

---

## `context_schema` — Routing V2

Define cómo el formulario asigna proyecto al lead. Estructura:

```json
{
  "routing": {
    "mode": "fixed_project",
    "project_slug": "proyecto-ejemplo",
    "project_name": "Proyecto Ejemplo",
    "project_area": "Santiago",
    "intent": "cotizacion"
  }
}
```

### Modo `project_selector`:

```json
{
  "routing": {
    "mode": "project_selector",
    "projects": [
      {"slug": "proyecto-a"},
      {"slug": "proyecto-b"}
    ]
  }
}
```

Los labels se resuelven del Project Registry en runtime. El slug enviado por POST se valida contra la lista permitida almacenada en DB (nunca se confía en el POST).

---

## `actions_json` — Pipeline de acciones (Forms 4.0)

Usado por formularios tipo `incident`. Define qué hacer post-submit:

```json
{
  "actions": [
    {
      "type": "database",
      "config": {}
    },
    {
      "type": "email",
      "config": {
        "to": "{{owner.email}}",
        "subject": "Solicitud recibida",
        "body": "Hola {{owner.name}}, tu solicitud #{{request_ref}} fue recibida."
      }
    },
    {
      "type": "webhook",
      "config": {
        "url": "https://hooks.n8n.io/...",
        "method": "POST"
      }
    }
  ]
}
```

Tipos de acción: `database` (persistir), `email` (notificar), `webhook` (despachar externamente).

Variables disponibles en templates: `{{owner.*}}`, `{{request.*}}`, `{{request_ref}}`, `{{form_id}}`, `{{incident.*}}`.

---

## `incidents_config` — Configuración de repeater (Forms 4.0)

```json
{
  "min": 1,
  "max": 4,
  "conditional_field": "tiene_problemas",
  "conditional_value": "si"
}
```

- `min` / `max`: cantidad de incidentes permitidos
- `conditional_field` / `conditional_value`: campo que activa/desactiva el repeater

---

## `experience_config_json` — Experience Engine v1

Define el flujo de pre-calificación inmobiliaria:

```json
{
  "steps": [
    {
      "id": "step_1",
      "title": "¿Cuál es tu objetivo?",
      "fields": ["objetivo"],
      "rules": [
        {
          "field": "objetivo",
          "value": "subsidio",
          "next_step": "step_subsidio"
        }
      ]
    }
  ],
  "flow": "real_estate_prequalification_v1",
  "trigger": "on_form_view"
}
```

---

## `survey_config` — Survey V1 (primera experiencia del Conversation Engine)

> Shape **verificado contra el código real** (MS-0.5). Anteriormente este documento listaba
> `identification`/`steps[]`/`theme`; el contrato real usa `identification_mode` + `hero_*` +
> `completion_*`. Las preguntas viven en `fields_json`.

```json
{
  "identification_mode": "optional",
  "hero_title": "Cuéntanos tu experiencia",
  "hero_subtitle": "Tu opinión nos ayuda a mejorar",
  "hero_cta": "Comenzar",
  "hero_image_url": "https://...",
  "completion_title": "¡Gracias!",
  "completion_message": "Tu respuesta fue registrada exitosamente."
}
```

Modos de identificación: `anonymous` (sin datos personales), `identified` (requiere nombre/email al
inicio), `optional` (puede saltar). La config del form es la fuente autoritativa del modo (un payload
manipulado no puede cambiar el modo de la encuesta).

### Tipos de pregunta survey (10 tipos)

| type | Admin label | Props | Persistencia |
|---|---|---|---|
| `nps` | NPS (0-7) | — | Número 0-7 |
| `rating` | Rating (estrellas) | `max` (3,4,5,7,10) | Número 1-`max` |
| `matrix` | Evaluación múltiple | `scale` (3,5,7,10), `rows[]`, `label_min`, `label_max`, `column_labels[]` | JSON `{row_id: score}` |
| `emoji` | Emojis | `options[]` → `{value, label, emoji}` | Texto simple |
| `cards` | Cards de selección | `options[]` → `{value, label, description}` | Texto simple |
| `checkbox` | Checkbox múltiple | `options[]`, `allow_other` (bool) | JSON `{checked: [values], other_text?: string}` |
| `text` | Texto libre | `placeholder` | Texto simple |
| `select` | Select | `options[]` → `{value, label}` | Texto simple |
| `radio` | Radio | `options[]` → `{value, label}` | Texto simple |
| `textarea` | Texto multilínea | `placeholder`, `rows` | Texto simple |

### Checkbox — Selección múltiple con "Otro"

El tipo `checkbox` permite marcar una o varias alternativas, con opción de incluir "Otro":

```json
{
  "id": "q_chk",
  "type": "checkbox",
  "name": "preferencias",
  "label": "¿Qué servicios te interesan?",
  "required": true,
  "allow_other": true,
  "options": [
    {"value": "instalacion", "label": "Instalación"},
    {"value": "mantencion", "label": "Mantención"},
    {"value": "garantia", "label": "Garantía"}
  ]
}
```

**Comportamiento:**
- `allow_other: true` muestra un checkbox "Otro" con campo de texto asociado
- Al marcar "Otro", se muestra el campo de texto y recibe foco
- Al desmarcar, se oculta y se limpia su valor
- Si "Otro" está marcado, el campo de texto es requerido para avanzar
- Los valores seleccionados se persisten como JSON: `{"checked":["instalacion"],"other_text":"más servicios"}`

**Sanitización server-side:** Todo answer_value JSON de tipo checkbox es validado y sanitizado: se filtran tags HTML, se normalizan los valores, y `other_text` vacío se omite del payload persistido.

---

## `conversation_config` — Conversation Engine (contrato MS-2; capacidades sobre Survey MS-3)

> Contrato aprobado (ADR 0034). Normalización implementada (MS-2) y **capacidades activables sobre
> Survey desde MS-3** (branching opcional, scoring, completion_strategy=lead) vía extensiones en
> `survey_config`. El runtime completo (experiencias nuevas) se factoriza en MS-4.

```json
{
  "flow": {
    "mode": "branching",
    "start": "step_1",
    "steps": [
      { "id": "step_1", "widget": "cards", "fields": ["objetivo"], "next": "step_2" }
    ],
    "rules": [
      { "from": "step_1", "if_answer": "subsidio", "goto": "step_subsidio" },
      { "from": "step_1", "if_answer_in": ["credito", "otro"], "goto": "step_credito" }
    ]
  },
  "scoring": {
    "weights": { "objetivo": 10, "rango_ingresos": { "alta": 35, "media": 10 } },
    "result_rules": [
      { "min_score": 80, "segment": "hot", "tier": "A" }
    ],
    "default_result": { "segment": "cold", "tier": "C" }
  },
  "completion_strategy": {
    "type": "lead",
    "lead_pipeline": true,
    "notify_emails": true
  },
  "identification": "optional"
}
```

> `scoring.weights` admite **dos formas** (QA MS-5): peso numérico (`"objetivo": 10`, se multiplica por
> una respuesta numérica/booleana) o **peso por valor de elección** (`"rango_ingresos": {"alta": 35}` —
> se suma el peso del valor seleccionado en respuestas de elección tipo cards/radio/select).

Capacidades declaradas: flujo lineal o ramificado (server-driven), scoring por respuesta/resultado y
estrategia de finalización (email, lead, request, artefacto, reporte, calendario). Normalización
implementada (MS-2); ejecución en MS-3/MS-4. `completion_strategy.type` válidos: `email` (default),
`lead`, `request`, `artefacto`, `reporte`, `calendario`.

---

## Template System — `.estandar-template.json` (v1.1)

Envelope usado para export/import de formularios:

```json
{
  "version": "1.1",
  "exported_at": "2026-07-31T00:00:00Z",
  "source_plugin_version": "13.0.158",
  "form": {
    "name": "Formulario de Contacto",
    "slug": "formulario-de-contacto",
    "form_type": "lead",
    "form_context": "generic",
    "fields_json": "[...]",
    "success_message": "Gracias...",
    "submit_label": "Enviar",
    "context_schema": "{...}",
    "actions_json": "[...]",
    "incidents_config": "[...]",
    "experience_config_json": "[...]",
    "survey_config": "[...]",
    "conversation_config": "[...]",
    "visual_theme": "",
    "notify_emails": "",
    "form_country": "CL",
    "webhook_url": "__ESTANDAR_REDACTED__",
    "webhook_payload_mode": "estandar",
    "mail_subject": "",
    "mail_body": "",
    "mail_sender_name": "",
    "mail_reply_to_field": "email"
  }
}
```

**Import strategies:**
- **Safe**: crea un nuevo formulario (nuevo slug, nuevo ID)
- **Resolve**: busca conflicto por slug y aplica merge

**Secrets redaction:** `__ESTANDAR_REDACTED__` reemplaza webhook_url, tokens, contraseñas y cualquier clave sensible durante export.
