# estandar-insights — Análisis

**Propósito:** eventos analíticos, tráfico web, **embudo** comercial y web y **auditoría SEO técnica** del
propio sitio. Además adopta (read-only) el analytics histórico de los plugins originales.

> **Limpieza 2026-09-27 (post-diagnóstico):** se retiraron `Observability` (estado decorativo, nunca
> cableado a evidencia real), los settings analíticos legacy inertes, `Funnel::channel_mix`,
> `Visual::funnel()` (barras), `Cli::retention` (datos falsos) y los controles fantasma
> (`enabled`/`retention_days`/`sync_enabled`). Ver `_retired/` y
> `050_Estandar_Wordpress_Plugins/documentos/diagnostico-insights-produccion-20260927.md`.

**Módulos (11):** `Events` (tipos cerrados + contract consumers + canal de señales) · `Funnel` (puro) ·
`WebFunnel` (puro) · `Visual` (embudo **SVG**, puro) ·
`Analytics` (espejos legacy + series del Panorama) · `ExternalDaily` (relleno de días sin dato) ·
`SeoAudit` (**auditoría SEO Tier 1**) · `SeoAdoption` (espejos SEO legacy) · `Adoption` · `Rest` · `Cli` ·
`Admin`.

**Tablas (12):** `estandar_insights_events` · `estandar_insights_analytics_{sessions,pageviews,events,conversions,aggregates}`
· `estandar_insights_external_daily` · `estandar_insights_seo_audit` + `..._seo_audit_runs` ·
`estandar_insights_seo_{landings,metrics,queries,runs}`.
**Options:** `estandar_suite_insights_{schema,analytics_schema,external_daily_schema,seo_schema,seo_audit_schema}_version`,
`..._visibility`, `..._seo_settings`.

**Contratos:**
- **Consume** `estandar_suite_capture_event` y `estandar_suite_signal_recorded` → registra eventos propios
  (**con canal**: click ids `gclid/wbraid/gbraid` → `google_ads`, `fbc/fbp` → `meta_ads`, o el `utm` de la captura).
- **Lee** el funnel comercial de Flow (`Pipeline::counts_by_stage()`, guard `method_exists`; UI, CLI y REST
  usan la MISMA fuente) y el tráfico vivo de Core (`core_sessions`/`core_pageviews`); leads de
  `estandar_capture_submissions`.
- **Canales** vía `Core\Channels`.

**REST** (`estandar-suite/insights/v1`): `GET /funnel`, `GET /events`, `GET /funnel/web`,
`GET /analytics/visits`, `GET /adoption`, `POST /adoption/import`, `POST /adoption/rollback`.

**WP-CLI:**
`wp estandar-suite insights funnel|funnel-web|visits [--from=] [--to=]`
`wp estandar-suite insights events backfill-channel [--limit=N]`
`wp estandar-suite insights adoption import [--commit]|rollback --confirm=yes|status`
`wp estandar-suite insights seo scan [--limit=N]|audit`
`wp estandar-suite insights seo adoption status|import|rollback`
`wp estandar-suite insights analytics summary|traffic|pages|trend|events`
`wp estandar-suite insights seo list|summary` · `external import|status|purge`

**UI:**
- **Estado:** Resumen · Datos (**embudo comercial en SVG** + eventos recientes) · Esquema.
- **Configuración → Analytics** (8 sub-tabs): **Panorama** (actividad por día con rangos), **Embudo web**
  (**SVG** sesiones→pageviews→leads), Resumen, Tráfico, Páginas, Eventos, Chatbot, Tendencia.
  *Panorama/Embudo* combinan histórico adoptado + datos vivos; *Resumen/Tráfico/Páginas/Eventos/Tendencia*
  muestran el **histórico congelado** (espejo legacy), etiquetado como tal.
- **Configuración → SEO:** **Resumen** (score medio, chequeos del sitio, incidencias frecuentes, tendencia y
  botón «Auditar ahora») · **Auditoría** (por página) · **Landings · Search Console** (Tier 2, opcional) · Settings.
- **Configuración → Configuración:** ajustes operativos (auditoría automática, páginas por auditoría) + reglas.
- **Datos → Adopción:** import/rollback del legacy (**se oculta si no hay nada que adoptar**).
- **Avanzado:** REST · WP-CLI · Inventario (**derivado por reflexión**, no hardcodeado).

**SEO (dos capas):**
- **Tier 1 — Auditoría técnica propia** (`SeoAudit`): sin credenciales y universal. Descarga el HTML
  renderizado de las páginas publicadas y puntúa 0-100 (title, meta description, canonical, `noindex`,
  H1/H2, `alt` de imágenes, largo del contenido, JSON-LD, `lang`) + chequeos de `sitemap.xml`/`robots.txt`.
  Guarda por página y por corrida (→ tendencia). CLI · botón · **cron semanal** opcional.
- **Tier 2 — Search Console** (opcional): datos reales de búsqueda a los espejos `..._seo_*` (hoy sin
  implementación de sync; el toggle se retiró por inerte).

**Adopción (datos legados):** espejos v2 (`origin=legacy`, `legacy_id` único) con dry-run/commit/rollback;
los eventos adoptados llevan `source='legacy'` (rollback por columna, no por texto); los fallos de inserción
se reportan (`failed`). **Seed externo por host configurable por filtro**
(`estandar_insights_external_seed_dir`); ya NO se embebe datos de ningún cliente en el plugin.

**Retirado:** *Scanner* (vista sin fuente) y *Observability* (estado decorativo) → `_retired/`.

**Tests:** `run-insights.php` (45) · `run-insights-admin.php` (44, guardarraíl de render de todas las ramas) ·
`run-insights-analytics.php` (32) · `run-seo-audit.php` (24) · `run-web-funnel.php` (21) · `run-seo-adoption.php` (13).
