# Estandar KickOff

Plugin modular tipo "navaja suiza" para WordPress. Agrupa herramientas comunes en una sola interfaz con pestañas. Versión unificada: 13.0.173.

## Instalación

1. Sube la carpeta `estandar-kickoff` a `wp-content/plugins/`
2. Activa el plugin desde **Plugins**
3. Accede a **KickOff** en el menú lateral del admin

## Ajustes

En el tab **Ajustes** puedes activar o desactivar cada módulo.

## Módulos disponibles

| Módulo | Descripción |
|--------|-------------|
| **Login Personalizer** | Personaliza el login: fondo, logo, textos y shortcode `[estandar_login]` |
| **Entrega de imágenes** | Mejora atributos de carga en frontend sin modificar archivos ni contenido |
| **Child Theme** | Genera un child theme mínimo para el tema activo |
| **Clean Head** | Limpia el `<head>`: versión WP, RSD, WLW, oEmbed, shortlink y emojis |
| **Maintenance Mode** | Página 503 para visitantes no logueados, con IPs permitidas |
| **Redirects** | Redirecciones 301/302 por path |
| **Security** | Desactivar XML-RPC, ocultar versión de WordPress |
| **Snippets** | Inyectar HTML, CSS o JS en head, body y footer |
| **SVG Upload** | Permitir subir SVG con sanitización anti-XSS |
| **Scripts defer** | Aplica defer solo a scripts no críticos permitidos |
| **Upload Limits** | Aumentar límite de subida de archivos (1–512 MB) |

## Desarrollo de módulos

El sistema detecta módulos automáticamente: cada módulo necesita un `config.php` obligatorio y un `module.php` con carga diferida (campo `order`). Guía: `docs/historico/plugins/estandar-kickoff/modules/README.md`.

## Recomendaciones

- **Snippets**: el usuario debe tener capacidad `unfiltered_html` (admins por defecto)
- **Upload Limits**: si no cambia el límite, modificar `php.ini` o `.htaccess`

## Estado en la Suite

- **Clasificación portfolio:** ACCESORIO (híbrido) — candidato a CONSOLIDACIÓN/DIVISIÓN (16 módulos legacy-style).
- **Absorciones previstas:** wp-exporter y firmas → Kickoff.

## Licencia

Propietario — Estandar Studio
