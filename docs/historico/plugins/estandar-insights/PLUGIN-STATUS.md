# PLUGIN-STATUS — estandar-insights

**Última sesión:** 2026-08-03 · **Estado:** estable · **Tests:** 23/23 PASS

## Lo que se hizo en esta sesión
- `includes/Admin.php`: `analytics_settings_groups()` — 3 grupos que rescatan
  `ea_settings` (tracking/dashboard/settings) guardados en
  `estandar_suite_insights_analytics_settings`. Handler
  `handle_save_analytics_settings()` (merge por grupo). `analytics_settings_fields()`.
- `includes/templates/feature-analytics.php`: sub-sub-tab **configuracion** (selector
  de grupo vía `?fd=`) y sub-sub-tab **chatbot** (eventos del chatbot, ecb_* — el
  área de analítica absorbe la analítica del chatbot). 8 sub-sub-tabs.
- `includes/Cli.php`: `analytics settings get|set` usan el catálogo completo.

## Estado actual
- Analytics legado (ea_*) read-only + configuración v2 operativa.
- Insights está "en espera" (0 eventos, funnel vacío): cobrará sentido con actividad
  real (capturas/señales/pipeline).

## Puntos de entrada
- `includes/Admin.php` (settings groups + handlers + feature_data) ·
  `includes/templates/feature-analytics.php`.

## Notas
- Fix de bug: `resolve_sub()` debe recibir el ARRAY ASOCIATIVO de grupos, no
  `array_keys()` (devolvía '0' y rompía el form).
