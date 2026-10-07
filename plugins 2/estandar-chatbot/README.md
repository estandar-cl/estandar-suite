# Estandar Chatbot IA

Plugin de WordPress para chatbot con IA (OpenAI), captura de leads, logs y canal WhatsApp. Versión unificada: 13.0.173.

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

## Shortcode

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

## RGPD

- **Exportar datos**: Herramientas → Exportar datos (ZIP con leads.csv y logs.csv)
- **Borrar datos**: Herramientas → Borrar todos los datos (vacía tablas del plugin)

## Estado en la Suite

- **Clasificación portfolio:** PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY — MANTENER POR COMPATIBILIDAD.
- **Equivalente v2:** `Brain\PublicChat` + `Agent` (básico, sin WhatsApp/frontend JS). No retirar hasta paridad.
- **Nota:** en la arquitectura v2, los tabs Leads/Analítica fueron delegados a Insights/Capture.

## Licencia

Propietario — Estandar Studio
