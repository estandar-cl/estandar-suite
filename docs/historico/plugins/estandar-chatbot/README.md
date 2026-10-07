# Estandar Chatbot IA

Plugin de WordPress para chatbot con IA (OpenAI), captura de leads, logs y canal WhatsApp.

## Requisitos

- WordPress 6.0+
- PHP 8.0+
- Cuenta OpenAI con API Key
- (Opcional) Meta Cloud API para WhatsApp

## Instalación

1. Sube la carpeta `estandar-chatbot` a `wp-content/plugins/`
2. Activa el plugin desde el panel de WordPress
3. Ve a **Estandar Chatbot** en el menú admin
4. Configura la API Key de OpenAI en **Configuración → Integraciones**

## Configuración mínima

1. **Configuración** → Activar chatbot, nombre, idioma
2. **Configuración** → OpenAI API Key, modelo (p. ej. gpt-4o-mini), max tokens
3. **Contexto** → Subir archivo TXT con base de conocimiento
4. **Configuración** → Instrucciones para la IA (prompt de sistema) — opcional

## Shortcodes

```
[estandar_chatbot mode="inline" height="680"]
```

- `mode`: `inline` (página completa) o `widget` (burbuja flotante)
- `height`: altura en px para modo inline (420–1100)

## Pestañas del admin

| Pestaña | Descripción |
|---------|-------------|
| Configuración | General, tema, OpenAI, webhooks, WhatsApp |
| Botones | Botones iniciales del chat |
| Contexto | Archivo TXT de conocimiento |
| Leads | Registro y gestión de leads |
| Conversaciones | Historial por sesión |
| Analítica | Eventos y métricas |
| Logs | Preguntas/respuestas del chatbot |
| Herramientas | Purga, migración, export RGPD, borrar datos |

## Docker (desarrollo y pruebas)

```bash
docker compose up -d
```

WordPress en http://localhost:18080

```bash
# Instalar WordPress
docker compose run --rm wpcli core install \
  --url=http://localhost:18080 \
  --title="ECB Test" \
  --admin_user=admin \
  --admin_password=admin123 \
  --admin_email=admin@example.com \
  --skip-email

# Activar plugin
docker compose run --rm wpcli plugin activate estandar-chatbot
```

## Tests

```bash
composer install
docker compose up -d
docker compose exec wordpress bash -c \
  'cd /var/www/html/wp-content/plugins/estandar-chatbot && WP_LOAD_PATH=/var/www/html php vendor/bin/phpunit'
```

## RGPD

- **Exportar datos**: Herramientas → Exportar datos (ZIP con leads.csv y logs.csv)
- **Borrar datos**: Herramientas → Borrar todos los datos (vacía tablas del plugin)

## Licencia

Propietario — Estandar Studio
