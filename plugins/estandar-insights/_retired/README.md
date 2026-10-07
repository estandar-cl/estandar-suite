# _retired — retirados de Insights

## feature-scanner_20260916.php (módulo *Scanner*)
Retirado el 2026-09-16. **Motivo:** era un **shell sin fuente de datos** — leía `estandar_scans` /
`estandar_scan_queue`, tablas del plugin legacy que **no existen en ninguna instalación** (verificado en
producción: `SHOW TABLES LIKE '%estandar_scan%'` → vacío). La vista siempre mostraba «Sin escaneos legados».

Retiro **completo** (2026-09-16, segunda limpieza): se quitó la sub-tab (Configuración → Scanner) de
`visibility_structure()` y del render, **más** `scanner_ssubs()`, `feature_data('scanner')`,
`feature_fields()['scanner']` y los comandos CLI `insights scanner list|summary|settings get|set`.
Verificado que `estandar_scans` / `estandar_scan_queue` **no existen en ninguno de los 5 sitios**. 

**Alternativa futura:** un escaneo técnico REAL del propio sitio (SEO/rendimiento) vive ahora en
`SeoAudit` (SEO → Auditoría), que no depende de ninguna tabla legacy.

## Observability_retired_20260927.php (módulo *Observabilidad*)
Retirado el 2026-09-27. **Motivo:** era un **estado decorativo** — `Observability::resolve_state()` se
llamaba SIEMPRE con `array()` vacío (`Admin.php`, `Rest.php`), así que devolvía siempre
`NOT_CONFIGURED / basis: config` y no reflejaba ninguna evidencia real de exportación. No tenía fuente ni
usuario. Se quitó de Estado→Resumen/Datos, del REST (`GET /observability`), del inventario y de los tests.
Alternativa futura: cablearlo a la evidencia real de exportación (CAPI/OCI) — hoy vive en Core/Capture, no
en Insights.

## external-sessions/acacios.cl.json (seed de hosting de un cliente)
Retirado el 2026-09-27. **Motivo:** un **plugin genérico no debe embeber datos de hosting de un cliente**.
El auto-seed por host ahora es **configurable** vía el filtro `estandar_insights_external_seed_dir`
(por defecto deshabilitado) o por CLI `external import --file=…`.
