# Estandar KickOff — Guía de usuario

Plugin modular tipo "navaja suiza" para WordPress. Agrupa herramientas comunes en una sola interfaz con pestañas.

## Instalación

1. Sube la carpeta `estandar-kickoff` a `wp-content/plugins/`
2. Activa el plugin desde **Plugins**
3. Accede a **KickOff** en el menú lateral del admin

## Ajustes

En el tab **Ajustes** puedes:

- **Módulos**: Activar o desactivar cada módulo según tus necesidades

## Módulos disponibles

| Módulo | Descripción |
|--------|-------------|
| **Login Personalizer** | Personaliza el login: fondo, logo, textos, párrafo y shortcode [estandar_login] |
| **Entrega de imágenes** | Mejora atributos de carga en frontend sin modificar archivos ni contenido |
| **Child Theme** | Genera un child theme mínimo para el tema activo, con activación opcional |
| **Clean Head** | Limpia el `<head>`: quita versión WP, RSD, WLW, oEmbed, shortlink y emojis |
| **Maintenance Mode** | Página 503 para visitantes no logueados, con IPs permitidas |
| **Redirects** | Redirecciones 301/302 por path |
| **Security** | Desactivar XML-RPC, ocultar versión de WordPress |
| **Snippets** | Inyectar HTML, CSS o JS en head, body y footer |
| **SVG Upload** | Permitir subir SVG con sanitización anti-XSS |
| **Scripts defer** | Aplica defer solo a scripts no críticos permitidos, sin cambiar orden ni dependencias |
| **Upload Limits** | Aumentar límite de subida de archivos (1–512 MB) |

## Recomendaciones

- **Snippets**: Para scripts y estilos, el usuario debe tener capacidad `unfiltered_html` (admins por defecto)
- **Upload Limits**: Si el límite no cambia, puede ser necesario modificar `php.ini` o `.htaccess` en el servidor

## Soporte

Para desarrolladores: consulta `modules/README.md` para crear nuevos módulos.
