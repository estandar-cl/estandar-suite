# ROLLBACK_PATH_V1 — Release 13.0.17 (2026-07-04)

## Artefactos de release anterior (verificados)
- `../todos al 30 de junio.zip` (9.7 MB, 2.041 entradas estandar-*) — snapshot completo previo al release 13.0.17. **Artefacto canónico de rollback.**
- `../todos al 31 de mayo.zip` (7.7 MB) — snapshot secundario, solo si el de junio estuviera corrupto.
- `ecosystem-manifest.json` — manifest del release actual (13.0.17, 16 plugins, notifications excluido).

## Procedimiento de rollback (sin cambios de código)
1. Desactivar los plugins estandar-* en el sitio afectado (wp-admin → Plugins, o renombrar el directorio del plugin problemático).
2. Extraer del zip `todos al 30 de junio.zip` los directorios `estandar-*` y reemplazar los del sitio (`wp-content/plugins/`). Reemplazo completo de flota — nunca parcial (regla de deploy: ecosistema completo, una sola versión).
3. Reactivar plugins. Las constantes `*_DB_VERSION` / `*_SCHEMA_VERSION` no cambiaron en 13.0.17, por lo que **no hay migraciones que revertir**: el rollback es puramente de archivos.
4. Verificar: Dashboard Primer carga, un lead de prueba entra (`?estandar_test_lead`), y versiones visibles en Sistema → Plugins coinciden con el snapshot.

## Condiciones que hacen este rollback seguro
- 13.0.17 es aditivo: sin cambios de schema, sin migraciones, sin borrado de opciones.
- `estandar-notifications` está excluido del bundle (inactivo); no participa del rollback.

## Regla
Rollback = flota completa desde el zip. Prohibido mezclar plugins 13.0.17 con versiones del snapshot.
