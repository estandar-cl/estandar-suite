# Estandar Forms

Plugin de formularios para WordPress con constructor visual, shortcode, base de datos propia, exportación CSV, notificaciones, webhook y anti-spam multicapa. Versión unificada: 13.0.173.

> Documentación técnica completa archivada: `docs/historico/plugins/estandar-forms/` (ARCHITECTURE, DATABASE, FORM-JSON-SCHEMA, HOOKS, LEAD-PIPELINE, CHANGELOG).

## Formulario inicial

Al activar el plugin se crea automáticamente un formulario `Formulario Estandar` (Nombre, Apellido, Mail, botón Enviar) si no existe ninguno.

## Shortcodes

- `[estandar_form slug="contacto"]`
- `[estandar_form id="1"]`

## Protección anti-spam

Por defecto: honeypot oculto, validación de tiempo mínimo de envío, limitación de intentos por IP + formulario.

### reCAPTCHA v3 (opcional)

```php
define('ESTANDAR_FORMS_RECAPTCHA_SITE_KEY', 'tu_site_key');
define('ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY', 'tu_secret_key');
```

## SMTP (constantes)

```php
define('ESTANDAR_FORMS_SMTP_HOST', 'smtp.tu-dominio.com');
define('ESTANDAR_FORMS_SMTP_PORT', 587);
define('ESTANDAR_FORMS_SMTP_USER', 'usuario');
define('ESTANDAR_FORMS_SMTP_PASS', 'password');
define('ESTANDAR_FORMS_SMTP_SECURE', 'tls');
define('ESTANDAR_FORMS_SMTP_AUTH', true);
define('ESTANDAR_FORMS_SMTP_FROM', 'no-reply@tu-dominio.com');
define('ESTANDAR_FORMS_SMTP_FROM_NAME', 'Estandar Forms');
define('ESTANDAR_FORMS_SMTP_TIMEOUT', 10);
```

## Hooks principales

`estandar_forms_before_validate`, `estandar_forms_validation_errors`, `estandar_forms_pre_insert_data`, `estandar_forms_after_entry_saved`, `estandar_forms_mail_subject`, `estandar_forms_mail_body`, `estandar_forms_spam_blocked`, `estandar_forms_webhook_payload`, `estandar_forms_export_entries`, y más. Catálogo completo en `docs/historico/plugins/estandar-forms/HOOKS.md`.

## Contrato de datos

- **DB:** 8 tablas (`estandar_forms`, entries, logs, requests/incidents/attachments, tokens, survey_responses) — `docs/historico/plugins/estandar-forms/DATABASE.md`.
- **JSON:** schema de `fields_json`, `context_schema`, `actions_json`, `conversation_config` — `FORM-JSON-SCHEMA.md`.
- **Leads:** emite `estandar_lead_captured` → ingesta; payload v1.0 en `LEAD-PIPELINE.md`.

## QA rápida

```bash
bash tests/run-regression-checks.sh
```

## Estado en la Suite

- **Clasificación portfolio:** PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY — MANTENER POR COMPATIBILIDAD.
- **Equivalente v2:** `estandar-capture` Forms. No retirar hasta que Capture absorba Surveys completos.

## Licencia

Propietario — Estandar Studio
