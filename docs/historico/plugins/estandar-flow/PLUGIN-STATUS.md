# PLUGIN-STATUS — estandar-flow

**Última sesión:** 2026-08-03 · **Estado:** estable · **Tests:** 21/21 PASS

## Lo que se hizo en esta sesión
- `includes/Admin.php`: `quotes_settings_groups()` — 5 grupos que rescatan la
  configuración de `estandar-quotes` (documento/finanzas/sala_ventas/autoridad/email)
  guardada en `estandar_suite_flow_quotes_settings`. Handler
  `handle_save_quotes_settings()` (merge por grupo). `quotes_settings_fields()`.
- `includes/templates/feature-quotes.php`: 8 sub-sub-tabs — resumen · cotizaciones ·
  **documento · finanzas · sala_ventas · autoridad · email** · settings.
- `includes/Cli.php`: `quotes settings get|set` usan `quotes_settings_fields()`
  (catálogo completo, no solo `feature_fields()['quotes']`).

## Estado actual
- Cotizaciones legadas (es_quotes, read-only) + settings de cotización v2 operativos.

## Puntos de entrada
- `includes/Admin.php` (settings groups + handlers) · `includes/templates/feature-quotes.php`.
- Pipeline/adopción: ver tab Estado (es_pipeline_*).

## Notas
- Patrón de settings por grupo con `render_feature_settings(..., $extra=['group'=>$g])`.
