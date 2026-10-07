# estandar-insights — Análisis y observabilidad

**Propósito:** eventos analíticos, funnel comercial (orden canónico), retención, observabilidad honesta (ausencia → UNKNOWN) y adopción de datos históricos de `ea_events`/`ea_sessions`.

**Módulos:** `Events` (tipos cerrados + contract consumers) · `Funnel` (pure) · `Observability` (resolve_state honesto) · `Adoption` (project/import/rollback) · `Rest` · `Cli`.

**Tabla:** `estandar_insights_events`. **Options:** `estandar_suite_insights_schema_version`.
**REST** (`estandar-suite/insights/v1`): `GET /funnel`, `GET /events`, `GET /observability`.
**WP-CLI:** `wp estandar-suite insights funnel|retention|adoption import [--commit]|adoption rollback --confirm=yes`.
**Dependencias:** usa `Core\Channels` para canales (sin dependencia dura con Capture). Funnel alimentado por Flow cuando está activo (guard class_exists).
**Tests:** `php tests/run-insights.php` (23 assertions).
