# Estandar Forms (v3.0.0)

Plugin de formularios para WordPress con:

- Constructor visual de campos
- Shortcode por `slug` o `id`
- Guardado en base de datos propia
- Exportación CSV
- Notificaciones por correo
- Webhook
- Protección anti-spam multicapa (honeypot, time-trap, rate limit, reCAPTCHA v3 opcional)
- Creación automática de un formulario base al instalar

## Formulario inicial

Al activar el plugin se crea automáticamente un formulario llamado `Formulario Estandar` si todavía no existe ninguno.

Campos incluidos:

- `Nombre`
- `Apellido`
- `Mail`
- botón `Enviar`

## Shortcodes

- `[estandar_form slug="contacto"]`
- `[estandar_form id="1"]`

## Protección anti-spam

Incluye por defecto:

- Honeypot oculto
- Validación de tiempo mínimo de envío
- Limitación de intentos por IP + formulario

### reCAPTCHA v3 (opcional)

Define constantes en `wp-config.php`:

```php
define('ESTANDAR_FORMS_RECAPTCHA_SITE_KEY', 'tu_site_key');
define('ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY', 'tu_secret_key');
```

En este proyecto Docker también puedes definirlas en el archivo `.env` de la raíz del proyecto (no incluido en el release) y reiniciar el stack.

## SMTP

El plugin soporta SMTP nativo vía constantes:

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

En Docker puedes configurarlo desde el archivo `.env` de la raíz del proyecto y luego ejecutar:

```bash
docker compose up -d --force-recreate wordpress wpcli
```

## Hooks disponibles

- `estandar_forms_before_validate`
- `estandar_forms_validation_errors`
- `estandar_forms_pre_insert_data`
- `estandar_forms_after_entry_saved`
- `estandar_forms_mail_subject`
- `estandar_forms_mail_body`
- `estandar_forms_spam_blocked`
- `estandar_forms_recaptcha_action`
- `estandar_forms_recaptcha_min_score`
- `estandar_forms_require_recaptcha`
- `estandar_forms_min_submit_seconds`
- `estandar_forms_rate_limit_window`
- `estandar_forms_rate_limit_max_attempts`
- `estandar_forms_webhook_payload`
- `estandar_forms_webhook_args`
- `estandar_forms_webhook_sent`
- `estandar_forms_export_entries`
- `estandar_forms_export_filename`
- `estandar_forms_export_selected_entries`

## QA rápida

Ejecuta:

```bash
bash tests/run-regression-checks.sh
```

Verifica:

1. Sintaxis PHP (`php -l`) de todos los archivos.
2. Presencia de hooks críticos y archivos JS frontend.

## Flujo recomendado de validación manual

1. Crear formulario con campos requeridos (`email`, `rut`, `file`, `select`).
2. Enviar incompleto y confirmar que muestra errores en la misma página.
3. Enviar correcto y confirmar mensaje de éxito.
4. Validar que se guarda entrada, notifica correo y dispara webhook.
5. Probar exportación de seleccionados y cambio de estado en papelera.
