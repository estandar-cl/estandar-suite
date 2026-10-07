# ESTANDAR Delivery Locator Lite

Localizador de entregas ligero: mapa Leaflet, zona de despacho y contacto WhatsApp/correo. Versión unificada: 13.0.173.

## Instalación rápida

1. WordPress → Plugins → Añadir nuevo → Subir plugin → elegir el ZIP.
2. Activar el plugin.
3. Ajustes → Delivery Locator: número WhatsApp, email receptor, radio y centro (opcional webhook n8n).
4. Crear o editar una página y pegar: `[estandar_delivery_locator]`

## Notas

- No incluye Composer ni tests: solo PHP del plugin y assets (CSS/JS).
- Requisitos: WordPress 5.8+, PHP 7.4+, MySQL, curl recomendado para webhooks.
- Seguridad: no ejecuta código arbitrario ni shell; las peticiones externas son opcionales (OSM, geocodificación en navegador, webhook n8n con `wp_remote_post`).

## Estado en la Suite

- **Clasificación portfolio:** ACCESORIO — nicho, **PAUSAR** hasta tener evidencia de uso.

## Licencia

Propietario — Estandar Studio
