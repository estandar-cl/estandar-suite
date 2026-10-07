# PLUGIN-STATUS — estandar-core

**Última sesión:** 2026-08-03 · **Estado:** estable · **Tests:** 49/49 PASS

## Lo que se hizo en esta sesión
- `includes/LegacyMap.php`: nuevo `strip_legacy_menus()` (hook `admin_menu`, prioridad
  999) que elimina los menús legados del sidebar (`estandar-*` / `estandar_*`), dejando
  solo la suite (`estandar-suite*`). Las páginas legadas siguen accesibles por URL.
- Helpers compartidos usados por toda la suite (NO tocar): `help_html()`,
  `render_ssubtabs()`, `resolve_sub()`/`resolve_ssub()`, `legacy_rows()`/`legacy_group()`/
  `legacy_count()`, `render_feature_settings()` (acepta `$extra` con `group`),
  `feature_settings_show()`/`feature_settings_set()` (Core\Cli).

## Estado actual
- Sidebar del admin limpio: solo "Estandar Suite" (Core/Capture/Brain/Flow/Insights).
- Los 21 plugins legados están ACTIVOS en la sala de pruebas; conviven sin menús.

## Puntos de entrada
- `includes/Admin.php` — sub-tabs, visibilidad, helpers de render.
- `includes/Cli.php` — comandos de features + `feature_settings_*`.
- `includes/LegacyMap.php` — puente de migración con el legado.

## Notas
- `resolve_sub()` espera un ARRAY ASOCIATIVO de subs (no `array_keys()`).
- Los menús legados se quitan por slug; no tocar la excepción `estandar-suite*`.
