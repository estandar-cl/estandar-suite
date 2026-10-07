=== Estandar WP Exporter (Plugins & Themes) ===
Contributors: estandarstudio
Tags: export, backup, plugins, themes, zip
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 13.0.17
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exporta plugins y themes instalados como archivos ZIP descargables desde el panel
de administración de WordPress, utilizando un proceso seguro por pasos (AJAX) y
archivos temporales para máxima compatibilidad con hostings restrictivos.

== Description ==

Estandar WP Exporter es una herramienta administrativa diseñada para permitir a
usuarios con permisos de administrador exportar plugins y themes instalados en
un sitio WordPress como archivos ZIP completos, conservando su estructura
original de carpetas y archivos.

Este plugin está orientado a:
- Entornos con hosting compartido o restrictivo
- Sitios sin acceso directo al filesystem
- Procesos de respaldo, migración o auditoría técnica
- Uso interno y administrativo (no frontend)

Principios de diseño:
- No modifica contenido ni base de datos
- No afecta el frontend del sitio
- No requiere librerías externas
- Usa directorios temporales seguros fuera del plugin y sin exponer URLs públicas
- Puede usar ZipArchive o PclZip según lo disponible en el servidor
- Ejecuta la exportación desde wp-admin sin tocar frontend, Astra ni Elementor

== Features ==

- Selección manual de plugins y/o themes instalados
- Exportación segura en formato ZIP
- Proceso iniciado por AJAX con descarga autenticada desde `admin-ajax.php`
- Control de tamaño máximo por archivo
- Compatible con hostings restrictivos y plugins/themes con `vendor/`
- Descarga directa desde wp-admin
- Limpieza automática de archivos temporales y jobs vencidos

== Installation ==

1. Sube la carpeta `estandar-wp-exporter` a `/wp-content/plugins/`
2. Activa el plugin desde el menú "Plugins" en WordPress
3. Accede a "Herramientas → Estandar WP Exporter"

== Usage ==

1. En el panel de administración, ve a:
   Herramientas → Estandar WP Exporter
2. Selecciona los plugins y/o themes que deseas exportar
3. Ajusta los límites de seguridad (opcional)
4. Haz clic en "Iniciar exportación"
5. Espera a que el proceso termine
6. Descarga el archivo ZIP generado

== Security ==

- Solo usuarios con capacidad `manage_options` pueden usar el plugin
- Todas las acciones se protegen con nonces
- No se exponen archivos exportados mediante URLs públicas en `uploads`
- Los archivos ZIP se descargan solo por usuarios autenticados con `manage_options`

== Technical Notes ==

- El plugin utiliza `ZipArchive` y cae a `PclZip` cuando el servidor no tiene la extensión ZIP
- Los jobs y ZIPs temporales se guardan en un runtime seguro fuera del directorio del plugin
- El proceso se ejecuta desde wp-admin y no carga nada en frontend
- No se almacenan datos persistentes en la base de datos

== Frequently Asked Questions ==

= ¿Este plugin reemplaza un sistema de backup? =
No. Está diseñado para exportaciones puntuales de plugins y themes, no para
copias completas del sitio.

= ¿Funciona en hosting compartido? =
Sí. Fue diseñado específicamente para entornos con restricciones de filesystem.

= ¿Afecta el rendimiento del sitio? =
No. Solo se ejecuta bajo demanda desde el panel de administración.

== Changelog ==

= 2.0.0 =
- Runtime temporal movido fuera del directorio del plugin
- Descarga protegida por `admin-ajax.php` sin URL pública en `uploads`
- Fallback a `PclZip` si `ZipArchive` no existe
- Inclusión de carpetas `vendor/` para exportaciones fieles
- Limpieza de artefactos vencidos y endurecimiento general de compatibilidad

== Upgrade Notice ==

= 2.0.0 =
Esta versión reemplaza completamente implementaciones anteriores.
Se recomienda eliminar versiones previas antes de instalar.
