# Estandar Forms — Schema de Base de Datos

Versión del schema: 2.4
Última actualización: 2026-07-31

## Tablas

### `wp_estandar_forms` — Definición de formularios

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `name` | VARCHAR(200) | NOT NULL | Nombre del formulario |
| `slug` | VARCHAR(100) | NULL, UNIQUE | Slug (auto-generado) |
| `form_type` | VARCHAR(32) | `lead` | Motor: `lead`, `lead_capture`, `experience`, `email_direct`, `incident`, `survey` |
| `form_context` | VARCHAR(64) | `generic` | Contexto de negocio: `generic`, `inmobiliaria` |
| `request_type` | VARCHAR(64) | `''` | Tipo de solicitud para formularios incident |
| `context_schema` | LONGTEXT | NULL | JSON — Schema de routing (Routing V2) |
| `fields_json` | LONGTEXT | NOT NULL | JSON — Definición de campos del formulario |
| `actions_json` | LONGTEXT | NULL | JSON — Pipeline de acciones post-submit (Forms 4.0) |
| `incidents_config` | LONGTEXT | NULL | JSON — Configuración del repeater de incidentes |
| `experience_config_json` | LONGTEXT | NULL | JSON — Definición steps/flow del Experience Engine v1 |
| `survey_config` | LONGTEXT | NULL | JSON — Configuración de survey |
| `success_message` | LONGTEXT | NULL | Mensaje post-envío |
| `submit_label` | VARCHAR(100) | `Enviar` | Texto del botón |
| `after_submit_text` | TEXT | NULL | Texto adicional post-envío |
| `visual_theme` | VARCHAR(64) | `''` | Tema visual |
| `notify_emails` | TEXT | NULL | Emails de notificación (separados por coma/salto) |
| `form_country` | VARCHAR(2) | `CL` | País (afecta validación de teléfono) |
| `store_entries` | TINYINT(1) | 1 | Guardar entries en BD |
| `send_email` | TINYINT(1) | 1 | Enviar email de notificación |
| `send_webhook` | TINYINT(1) | 0 | Despachar webhook |
| `require_recaptcha` | TINYINT(1) | 1 | Requerir reCAPTCHA v3 |
| `webhook_url` | TEXT | NULL | URL del webhook |
| `webhook_payload_mode` | VARCHAR(32) | `estandar` | Modo: `estandar` o `cf7_compat` |
| `mail_subject` | TEXT | NULL | Asunto del email (soporta `{{form_name}}`) |
| `mail_body` | LONGTEXT | NULL | Cuerpo del email |
| `mail_sender_name` | VARCHAR(200) | NULL | Nombre del remitente |
| `mail_reply_to_field` | VARCHAR(100) | NULL | Campo usado como Reply-To |
| `created_at` | DATETIME | CURRENT_TIMESTAMP | Fecha de creación |

Índices: PK `id`, UNIQUE `slug`.

---

### `wp_estandar_form_entries` — Entradas de formulario

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `form_id` | BIGINT UNSIGNED | NOT NULL | FK → `estandar_forms.id` |
| `data_json` | LONGTEXT | NOT NULL | JSON con todos los valores del envío |
| `status` | VARCHAR(20) | `active` | Estado: `active`, `trash` |
| `created_at` | DATETIME | CURRENT_TIMESTAMP | Fecha de envío |

Índices: PK `id`, KEY `form_id`, KEY `status`, KEY `status_form_created` (status, form_id, created_at).

---

### `wp_estandar_form_logs` — Log de telemetría

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `form_id` | BIGINT UNSIGNED | NULL | FK → `estandar_forms.id` |
| `event_type` | VARCHAR(50) | NOT NULL | Tipo de evento |
| `level` | VARCHAR(20) | `info` | Nivel: `info`, `warning`, `error` |
| `message` | TEXT | NULL | Mensaje del evento |
| `context_json` | LONGTEXT | NULL | JSON con contexto adicional |
| `created_at` | DATETIME | CURRENT_TIMESTAMP | Timestamp |

Índices: PK `id`, KEY `form_id`, KEY `level_created` (level, created_at), KEY `event_created` (event_type, created_at).

---

### `wp_estandar_requests` — Solicitudes (Forms 4.0 — Incident System)

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `request_ref` | VARCHAR(24) | `''`, UNIQUE | Referencia única de solicitud |
| `form_id` | BIGINT UNSIGNED | NOT NULL | FK → `estandar_forms.id` |
| `form_type` | VARCHAR(32) | `incident` | Tipo de formulario |
| `form_context` | VARCHAR(64) | `generic` | Contexto de negocio |
| `request_type` | VARCHAR(64) | `''` | Tipo de solicitud |
| `status` | VARCHAR(32) | `open` | Estado: `open`, `in_progress`, `resolved`, `closed` |
| `owner_json` | LONGTEXT | NULL | JSON — Datos del solicitante |
| `context_json` | LONGTEXT | NULL | JSON — Contexto (proyecto, calle, etc.) |
| `meta_json` | LONGTEXT | NULL | JSON — Metadata adicional |
| `source_ip` | VARCHAR(45) | `''` | IP del solicitante |
| `source_ua` | TEXT | NULL | User agent |
| `created_at` | DATETIME | NOT NULL | Fecha de creación |
| `updated_at` | DATETIME | NOT NULL | Última actualización |

Índices: PK `id`, UNIQUE `request_ref`, KEY `form_id`, `status`, `form_type`, `request_type`, `created_at`.

---

### `wp_estandar_incidents` — Incidentes individuales

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `request_id` | BIGINT UNSIGNED | NOT NULL | FK → `estandar_requests.id` |
| `sort_order` | TINYINT UNSIGNED | 0 | Orden |
| `location` | VARCHAR(190) | `''` | Ubicación del incidente |
| `title` | VARCHAR(190) | `''` | Título |
| `description` | TEXT | NULL | Descripción |
| `status` | VARCHAR(32) | `open` | Estado |
| `created_at` | DATETIME | NOT NULL | Fecha |

Índices: PK `id`, KEY `request_id`, `location`, `status`.

---

### `wp_estandar_request_attachments` — Archivos adjuntos

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `request_id` | BIGINT UNSIGNED | NOT NULL | FK → `estandar_requests.id` |
| `incident_id` | BIGINT UNSIGNED | NULL | FK → `estandar_incidents.id` |
| `file_path` | VARCHAR(500) | `''` | Ruta relativa en uploads |
| `file_name` | VARCHAR(190) | `''` | Nombre original |
| `file_size` | INT UNSIGNED | 0 | Tamaño en bytes |
| `mime_type` | VARCHAR(100) | `''` | MIME type |
| `created_at` | DATETIME | NOT NULL | Fecha |

Índices: PK `id`, KEY `request_id`, `incident_id`.

---

### `wp_estandar_secure_tokens` — Tokens seguros (multimódulo)

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `module` | VARCHAR(32) | NOT NULL | Módulo: `survey`, `quote`, `form`, `report` |
| `reference_id` | BIGINT UNSIGNED | NOT NULL | ID de referencia en el módulo |
| `token` | VARCHAR(128) | NOT NULL, UNIQUE | Token seguro |
| `created_at` | DATETIME | NOT NULL | Fecha de creación |
| `expires_at` | DATETIME | NULL | Expiración |
| `revoked_at` | DATETIME | NULL | Fecha de revocación |
| `meta_json` | LONGTEXT | NULL | Metadata adicional |

Índices: PK `id`, UNIQUE `token`, KEY `module_ref` (module, reference_id), KEY `expires_at`.

---

### `wp_estandar_survey_responses` — Respuestas por paso de experiencia conversacional

> Schema **verificado contra la instalación real** (MS-0.5). Anteriormente este documento listaba
> columnas inexistentes (`question_id`, `question_key`, `field_type`); se corrigieron.

| Columna | Tipo | Default | Descripción |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | AUTO_INCREMENT | PK |
| `session_token` | VARCHAR(64) | NOT NULL | Token de sesión (generado por el cliente, UUID v4) |
| `form_id` | BIGINT UNSIGNED | NOT NULL | FK → survey form |
| `entry_id` | BIGINT UNSIGNED | NULL | FK → `estandar_form_entries.id` (se enlaza al completar) |
| `question_index` | TINYINT UNSIGNED | NOT NULL | Índice del paso/pregunta |
| `question_label` | VARCHAR(500) | NULL | Etiqueta del paso |
| `question_type` | VARCHAR(32) | `text` | Tipo de pregunta (`nps`, `rating`, `matrix`, `emoji`, `cards`, `checkbox`, `text`, `select`, `radio`, `textarea`) |
| `answer_value` | TEXT | NULL | Valor de la respuesta (JSON para `matrix` y `checkbox`) |
| `answer_label` | TEXT | NULL | Etiqueta legible de la respuesta |
| `answered_at` | DATETIME | NOT NULL | Timestamp |
| `time_spent_seconds` | SMALLINT UNSIGNED | NOT NULL | Tiempo en el paso |

Índices: PK `id`, KEY `session_token`, KEY `form_id`, KEY `entry_id`, KEY `question_type`.

> **Nota `form_structure` (resuelto en MS-1):** nunca existió una columna `form_structure` en
> `wp_estandar_forms` (el schema real usa `fields_json`). Las 8 queries que la referenciaban
> (handler, analytics, results) fueron corregidas en MS-1 para leer `fields_json` (ADR 0034, R1).

### Sesiones de experiencia en `wp_estandar_form_entries`

Las sesiones conversacionales se persisten como filas de `estandar_form_entries` con
`data_json.survey_session = true` y estados de sesión en `status`:
`active` (iniciada) · `survey_completed` (completada) · `survey_abandoned` (abandonada).
En la arquitectura objetivo (ADR 0034) estos estados se generalizan a
`started`/`in_progress`/`completed`/`abandoned` por el Conversation Engine, manteniendo los valores
actuales como contrato.

---

## Migraciones

El schema se gestiona en `Estandar_Forms_DB::install()` con versión `2.4`:

- **Activación**: `register_activation_hook` → `bootstrap()` → `install()`
- **admin_init**: Compara `estandar_forms_db_version` con `DB_VERSION`. Si hay mismatch o falta alguna columna crítica, re-ejecuta `bootstrap()`.
- **Columnas legacy (1.x–1.7)**: `ensure_column()` idempotente para 13 columnas
- **Columns Forms 4.0**: `form_type`, `form_context`, `request_type`, `context_schema`, `actions_json`, `incidents_config`
- **SURVEYS_V1**: `survey_config`
- **EXPERIENCE_ENGINE_V1**: `experience_config_json`
- **Survey DB**: `Estandar_Survey_DB` maneja migración v1→v1.1 con retiro no destructivo (`_legacy`)

### Columna `conversation_config` (MS-2, aditiva — contrato del motor)

`conversation_config LONGTEXT NULL` — configuración de flujo/branching/scoring/completion del
Conversation Engine para **experiencias nuevas** (ADR 0034). Añadida de forma aditiva en MS-2
(`ensure_column` + `assert_schema`). `survey_config` sigue siendo la fuente de config para encuestas.
Normalización del contrato: `Estandar_Conversation_Engine::normalize_config()`.
