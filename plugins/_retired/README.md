# _retired — plugins retirados

## estandar-suite_20260916 (hub de la Suite)
Retirado el 2026-09-16. **Motivo:** duplicaba el menú top-level `Estandar Suite`
que ya provee `estandar-core/includes/Admin.php` (mismo slug `estandar-suite`,
hook `admin_menu` prioridad 9). Los módulos (Brain/Capture/Flow/Insights) cuelgan
de `estandar-core`, no del hub; el hub sólo tenía un huésped (`estandar-inicio`).

- Se quitó de `SUITE_PLUGINS` y de `VERSION_CONSTANTS` en `release-suite.command`.
- Se desactivó y **eliminó** de las 5 instalaciones (acacios main + inmobiliaria,
  estandar, guillaume, rodrigovaldes) el 2026-09-16.
- Lo rescatable de su diseño (tabs declarativos, ARIA/teclado, capability por tab)
  está analizado en `050/documentos/rescate-navegacion-tabs.md`; la accesibilidad
  ya se portó a `estandar-core` (release 2.9.4).
- **No reactivar.** El hub real es `estandar-core`.

## estandar-inicio_20260916
Retirado el 2026-09-16. **Motivo:** era un plugin con 2 capacidades de admin y **cero tests**, sin
dependencias; se **plegó a `estandar-core`** como el módulo *Herramientas* (`includes/Tools.php`,
tab **Core → Avanzado → Herramientas**), **corregido**:

- Sanitizador de SVG reescrito como **allowlist DOM** (antes: blocklist por regex, bypasseable).
  Rechaza `<!DOCTYPE`/`<!ENTITY` (anti-XXE), elimina tags/atributos fuera de la lista, bloquea
  `on*`, `href`/`xlink:href` externos, `url()` externos, `javascript:` y `<style>`.
- Ambas capacidades ahora son **configurables por sitio** (opción `estandar_suite_tools`):
  `svg` (default **OFF**) y `child_theme` (default ON).
- Límite de 2 MB para el screenshot y chequeo de `DISALLOW_FILE_MODS` al escribir.
- Tests: `estandar-core/tests/run-tools.php` (28 casos, incluye vectores adversarios).

Se quitó de `SUITE_PLUGINS` y `VERSION_CONSTANTS`; se desactivó y **eliminó** de las 5 instalaciones.
En el subsitio `acacios.cl/inmobiliaria` (donde estaba activo y se usaba) se **activó el switch SVG**
para no perder la capacidad. **No reactivar.**

## flow-journey-20260916 (Outcomes · ReEngagement · Recovery)
Retirado el 2026-09-16. **Motivo:** eran **332 líneas huérfanas** — NO estaban en ningún `require_once`
de `estandar-flow.php`, no se referenciaban en ninguna parte del repo y no los cubría ningún test.
Sus tablas/opciones (`estandar_flow_outcomes`, `estandar_suite_reengagement_config`,
`estandar_suite_recovery_config`) nunca existieron en producción.

Capacidades que implementaban (por si se retoman):
- **Outcomes**: ingresos/atribución (`record_deal`), `total_revenue`, `conversion_rate`, `stage_duration_avg`.
- **ReEngagement**: detección/disparo de re-enganche por señales de comportamiento (visited_pricing,
  submitted_form, chatbot_interaction…).
- **Recovery**: recuperación de leads perdidos (`schedule_from_lost`, `initiate`, `attempt`, `mark_reengaged`, `fail`).

**Por qué retirarlas y no conectarlas ahora:** Flow está **inerte en producción** (566 registros, todos en
`NEW`, **0 transiciones**, 0 clientes, 0 tareas) y estas capacidades dependen de que el journey se mueva.
Primero se activa el uso real (bridge señales→journey, hecho en la misma fecha); luego se decide si se
reintegran como módulos cargados y testeados. **No reactivar sin tests y sin wire real.**
