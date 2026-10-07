# Changelog

## 10.3.7 - 2026-06-14

- PROJECT_DISPLAY_RESOLVER_V1:
  - Normalizacion read-side de nombres de proyecto.
  - Precedencia: registry name, project area, project slug.
  - Sin cambios de escritura, agrupacion o persistencia.
- OPERATIONAL_STATUS_UX_V2:
  - Warnings operativos con impacto, correccion y CTA.
  - Estado visible del Compliance Engine.
- COMPLIANCE_SHADOW_COPY_V1:
  - BCC temporal y opt-in para seis tipos de correo Compliance.
  - Expiracion automatica y preservacion de BCC existente.
- TELEMETRY_UX_FIX:
  - Separacion entre incidencias abiertas, eventos operativos y resueltos.
  - Eventos informativos dejan de mostrarse como incidencias accionables.

### Paquete compatible

- `estandar-core-mu`: `10.3.7`
- `estandar-leads`: `10.3.6`

### Fuera de alcance

- COMPLIANCE_ROUTING_CONTEXT_AUDIT.
- Correcciones multi-project.
- Cambios de schema.
- Migraciones.

## Sprint 1 - Platform Consolidation

- Fusion visual de Sistema:
  - Estado: mantiene `admin.php?page=estandar_core_diagnostics`.
  - Logs: mantiene `admin.php?page=estandar_telemetry`.
- Configuracion global email en Core:
  - `ESTANDAR_SENDER_NAME`
  - `ESTANDAR_SENDER_EMAIL`
- Lectura Core-first con fallback local en plugins consumidores.
- Helper comun `estandar_core_get_ea_sid()` sin dependencia dura de Analytics.
- Consolidacion de CSS admin compartido: una sola fuente en Core.

### Dependencia nueva

Los plugins Forms, Delivery Locator, Duplicate Post, KickOff y CPT Manager requieren Core para renderizar correctamente la interfaz administrativa.

Si Core esta inactivo:

- Funcionalidad de negocio intacta.
- UI administrativa no garantizada.

Las opciones locales existentes no fueron eliminadas.
