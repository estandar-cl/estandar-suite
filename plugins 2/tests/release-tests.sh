#!/usr/bin/env bash
# tests/release-tests.sh — Pruebas automatizadas del sistema de release.
# Cubre: configuración de canales, aislamiento legacy↔v2, gates v2, --dry-run
# sin escritura remota, publicación TEST_MODE simulada y rollback.
# No accede a redes ni al remoto real (solo RELEASE_TEST_REMOTE_DIR local).
set -u -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$(dirname "$SCRIPT_DIR")"
RELEASE_CMD="$PLUGINS_DIR/release.command"
LIB="$PLUGINS_DIR/release-lib.sh"
CONFIG="$PLUGINS_DIR/release-channels.json"
SUITE_DIR="$HOME/Applications/Dragon/projects/estandar-suite-v2"

PASS=0; FAIL=0
check() { if [ "$1" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS $2"; else FAIL=$((FAIL+1)); echo "FAIL $2"; fi }

# Versión actual de la fuente v2 (mirror) y siguiente target lockstep.
v2_current_version() {
    grep -m1 "Version:" "$SUITE_DIR/plugins/estandar-core/estandar-core.php" \
        | sed -E 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*//; s/[[:space:]]*$//'
}
v2_next_version() {
    python3 -c 'v="'"$(v2_current_version)"'"; p=[int(x) for x in v.split(".")]; print(".".join(str(x) for x in p[:-1]+[p[-1]+1]))'
}

cd "$PLUGINS_DIR" || exit 1

echo "=== RELEASE SYSTEM TESTS ==="
echo ""

# ------------------------------------------------- 1) Configuración de canales
echo "--- 1) Configuración declarativa ---"
. "$LIB" >/dev/null 2>&1
eval "$(load_channel_config legacy)" >/dev/null
[ "${CHANNEL_LABEL}" = "legacy/production" ]
check $? "canal legacy se carga (label legacy/production)"
[ "${CHANNEL_REMOTE_DIR}" = "." ]
check $? "canal legacy usa remote_dir '.' (raíz del feed)"
eval "$(load_channel_config v2)" >/dev/null
[ "${CHANNEL_LABEL}" = "v2/staging" ]
check $? "canal v2 se carga (label v2/staging)"
[ "${CHANNEL_REMOTE_DIR}" = "staging/v2" ]
check $? "canal v2 usa remote_dir aislado 'staging/v2'"
[ "${REMOTE_PATH}" != "${PLUGINS_DIR}" ]
check $? "REMOTE_PATH v2 es distinto de legacy"
V2_EXCL=$(python3 -c "import json; print(json.dumps(json.load(open('$CONFIG'))['channels']['legacy']['source']['exclude']))")
echo "$V2_EXCL" | grep -q "estandar-core" && echo "$V2_EXCL" | grep -q "estandar-insights"
check $? "canal legacy excluye los 5 slugs v2"
N_SLUGS=$(python3 -c "import json; print(len(json.load(open('$CONFIG'))['channels']['v2']['source']['slugs']))")
[ "$N_SLUGS" = "5" ]
check $? "canal v2 declara 5 slugs"
[ "$(python3 -c "import json; print(json.load(open('$CONFIG'))['channels']['legacy'].get('frozen', False))")" = "True" ]
check $? "canal legacy marcado frozen"
[ "$(python3 -c "import json; print(json.load(open('$CONFIG'))['channels']['v2'].get('default', False))")" = "True" ]
check $? "canal v2 es el default"

# ------------------------------------------------- 2) Canal por defecto = v2 + frozen
echo ""
echo "--- 2) Sin argumentos → v2; --legacy → LEGACY_RELEASES_FROZEN ---"
RELEASE_SRC="$(cat "$RELEASE_CMD")"
echo "$RELEASE_SRC" | grep -q 'CHANNEL_ARG="v2"'
check $? "default explícito a v2 en release.command"
echo "$RELEASE_SRC" | grep -q "LEGACY_RELEASES_FROZEN"
check $? "release.command contiene el bloqueo LEGACY_RELEASES_FROZEN"
TMP="$(mktemp -d)"
OUT="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$TMP" bash "$RELEASE_CMD" --legacy --dry-run 2>&1)"
RC=$?
[ "$RC" = "3" ]
check $? "--legacy termina con código de error (rc=3)"
echo "$OUT" | grep -q "LEGACY_RELEASES_FROZEN"
check $? "--legacy imprime LEGACY_RELEASES_FROZEN"
[ -z "$(find "$TMP" -type f 2>/dev/null)" ]
check $? "--legacy sin escrituras (remoto simulado intacto)"
rm -rf "$TMP"

# ----------------------------------------------- 3) v2 dry-run (sin remoto)
echo ""
echo "--- 3) v2 --dry-run: gates + build local sin escritura remota ---"
TMP="$(mktemp -d)"
OUT="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$TMP" bash "$RELEASE_CMD" --v2 --dry-run 2>&1)"
RC=$?
echo "$OUT" | grep -q "DRY-RUN completo"
check $RC "v2 dry-run termina en éxito (rc=$RC)"
NEXT_V="$(v2_next_version)"
echo "$OUT" | grep -q "Versión de release (canal v2/staging): ${NEXT_V}"
check $? "v2 dry-run calcula versión lockstep ${NEXT_V} (actual $(v2_current_version))"
echo "$OUT" | grep -q "✔ reproducible"
check $? "gate ZIP reproducible (doble build) corre en dry-run"
echo "$OUT" | grep -q "metadata OK"
check $? "gate metadata/URL corre en dry-run"
echo "$OUT" | grep -q "paridad OK"
check $? "paridad ZIP↔árbol OK"
FOUND_REMOTE="$(find "$TMP" -type f | wc -l | tr -d ' ')"
[ "$FOUND_REMOTE" = "0" ]
check $? "dry-run no escribió nada en el remoto simulado"
rm -rf "$TMP"

# --------------------------------------- 4) legacy bloqueado (FROZEN)
echo ""
echo "--- 4) legacy bloqueado y recuperación excepcional ---"
TMP="$(mktemp -d)"
OUT="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$TMP" bash "$RELEASE_CMD" --channel=legacy 2>&1)"
RC=$?
[ "$RC" = "3" ]
check $? "--channel=legacy aborta (rc=3)"
echo "$OUT" | grep -q "LEGACY_RELEASES_FROZEN"
check $? "imprime LEGACY_RELEASES_FROZEN"
[ -z "$(find "$TMP" -type f 2>/dev/null)" ]
check $? "sin efectos en el remoto simulado"
[ ! -d .release.lock ]
check $? "sin lock local"
rm -rf "$TMP"

# Recuperación excepcional: bloqueada sin override
OUT2="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$(mktemp -d)" bash "$PLUGINS_DIR/release-legacy-frozen.command" 2>&1)"
RC2=$?
[ "$RC2" = "3" ]
check $? "release-legacy-frozen.command sin override → rc=3"
echo "$OUT2" | grep -q "LEGACY_RELEASES_FROZEN"
check $? "release-legacy-frozen.command imprime LEGACY_RELEASES_FROZEN"

# Recuperación excepcional con override + --dry-run: sin efectos (regresión)
TOKEN="$(python3 -c "import json; print(json.load(open('$CONFIG'))['channels']['legacy'].get('unfreeze_token',''))")"
SNAP_V="$(grep -m1 'Version:' "$PLUGINS_DIR/estandar-primer/estandar-core.php")"
SNAP_H="$(wc -l < "$PLUGINS_DIR/.release-history.log")"
TMP="$(mktemp -d)"
OUT3="$(LEGACY_FROZEN_OVERRIDE="$TOKEN" RELEASE_TEST_REMOTE_DIR="$TMP" RELEASE_SKIP_TEST_GATE=1 bash "$PLUGINS_DIR/release-legacy-frozen.command" --confirm-emergency --dry-run 2>&1)"
RC3=$?
check $RC3 "recovery con override + --dry-run termina en éxito (rc=$RC3)"
[ "$(grep -m1 'Version:' "$PLUGINS_DIR/estandar-primer/estandar-core.php")" = "$SNAP_V" ]
check $? "recovery dry-run NO deja bumps de versión en legacy"
[ "$(wc -l < "$PLUGINS_DIR/.release-history.log")" = "$SNAP_H" ]
check $? "recovery dry-run NO escribe en .release-history.log"
[ -z "$(find "$TMP" -type f 2>/dev/null)" ]
check $? "recovery dry-run sin escrituras en remoto simulado"
rm -rf "$TMP"

# ------------------------------------------------- 5) Colisión de slugs v2
echo ""
echo "--- 5) gate colisión de slugs (v2 vs feed legacy) ---"
. "$LIB" >/dev/null 2>&1
LEGACY_FIX="$(mktemp)"
printf '{"estandar-primer":"13.0.158","estandar-leads":"13.0.158"}\n' > "$LEGACY_FIX"
check_slug_collision "estandar-core estandar-capture estandar-brain estandar-flow estandar-insights" "$LEGACY_FIX" >/dev/null
check $? "slugs v2 no colisionan con un feed legacy sin ellos"
printf '{"estandar-core":"1.0.1"}\n' > "$LEGACY_FIX"
if check_slug_collision "estandar-core" "$LEGACY_FIX" >/dev/null 2>&1; then
    check 1 "slugs v2 COLISIONAN con feed legacy (debe fallar)"
else
    check 0 "slugs v2 COLISIONAN con feed legacy (debe fallar)"
fi
rm -f "$LEGACY_FIX"

# ------------------------------------------------- 6) Scan de archivos prohibidos
echo ""
echo "--- 6) gate forbidden files (temporales/secretos/bases) ---"
TMPCLEAN="$(mktemp -d)"
mkdir -p "$TMPCLEAN/p"
printf '<?php\n' > "$TMPCLEAN/p/p.php"
scan_forbidden_files "$TMPCLEAN/p" >/dev/null
check $? "directorio limpio pasa el scan"
printf 'SECRET=abc123\n' > "$TMPCLEAN/p/.env"
if scan_forbidden_files "$TMPCLEAN/p" >/dev/null 2>&1; then
    check 1 "archivo .env debe ser detectado"
else
    check 0 "archivo .env debe ser detectado"
fi
printf 'x\n' > "$TMPCLEAN/p/db.sqlite"
if scan_forbidden_files "$TMPCLEAN/p" >/dev/null 2>&1; then
    check 1 "base sqlite debe ser detectada"
else
    check 0 "base sqlite debe ser detectada"
fi
rm -rf "$TMPCLEAN"

# --------------------------------- 7) Publicación v2 TEST_MODE (remoto local)
echo ""
echo "--- 7) publicación v2 completa en remoto simulado ---"
SNAP="$(mktemp -d)"
for d in estandar-core estandar-capture estandar-brain estandar-flow estandar-insights; do
    cp "$SUITE_DIR/plugins/$d/$d.php" "$SNAP/$d.php"
done
TMP="$(mktemp -d)"
OUT="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$TMP" bash "$RELEASE_CMD" --v2 2>&1)"
RC=$?
echo "$OUT" | grep -q "RELEASE SUCCESS"
check $RC "publicación v2 TEST_MODE termina en éxito (rc=$RC)"
echo "$OUT" | grep -q "Verificación remota v2 OK"
check $? "verificación post-publicación OK (checksum/estructura/metadata)"
[ -f "$TMP/staging/v2/versions.json" ]
check $? "remoto simulado tiene staging/v2/versions.json"
[ -f "$TMP/staging/v2/estandar-core.zip" ] && [ -f "$TMP/staging/v2/estandar-insights.zip" ]
check $? "remoto simulado tiene los 5 zips"
[ -d "$TMP/staging/v2/releases/" ]
check $? "remoto simulado archiva releases/<id>"
python3 -c "import json; d=json.load(open('$TMP/staging/v2/versions.json')); assert len(d)==5 and all(d[k]=='$NEXT_V' for k in d), d"
check $? "versions.json v2 remoto: 5 plugins en ${NEXT_V}"
python3 -c "import json; m=json.load(open('$TMP/staging/v2/manifest.json')); assert all(p['url'].startswith('https://updates.estandar.cl/staging/v2/') for p in m['plugins'].values()), m"
check $? "manifest v2 remoto: urls apuntan al canal staging"
# restaurar la fuente del mirror desde el CANONICAL (bump quedó aplicado en la
# publicación simulada; el mirror debe volver al estado canónico, no a un SNAP)
for d in estandar-core estandar-capture estandar-brain estandar-flow estandar-insights; do
    rsync -a --delete "$PLUGINS_DIR/$d/" "$SUITE_DIR/plugins/$d/"
done
rm -rf "$SNAP" "$TMP"

# ------------------------------------------- 8) Rollback (fail point mid_switch)
echo ""
echo "--- 8) rollback ante fallo en el switch (v2) ---"
SNAP="$(mktemp -d)"
for d in estandar-core estandar-capture estandar-brain estandar-flow estandar-insights; do
    cp "$SUITE_DIR/plugins/$d/$d.php" "$SNAP/$d.php"
done
TMP="$(mktemp -d)"
OUT="$(RELEASE_SKIP_TEST_GATE=1 RELEASE_TEST_REMOTE_DIR="$TMP" RELEASE_TEST_FAIL_POINT=mid_switch bash "$RELEASE_CMD" --v2 2>&1)"
RC=$?
echo "$OUT" | grep -q "RELEASE FAILED"
check $? "release falla con fail point mid_switch"
[ ! -f "$TMP/staging/v2/versions.json" ]
check $? "tras rollback no quedan archivos publicados en staging/v2"
[ ! -f "$TMP/staging/v2/estandar-core.zip" ]
check $? "tras rollback no quedan zips en staging/v2"
[ -z "$(find "$TMP" -name '.release-lock' -maxdepth 3 2>/dev/null)" ]
check $? "locks liberados tras rollback"
for d in estandar-core estandar-capture estandar-brain estandar-flow estandar-insights; do
    rsync -a --delete "$PLUGINS_DIR/$d/" "$SUITE_DIR/plugins/$d/"
done
grep -q "Version: $(v2_current_version)" "$SUITE_DIR/plugins/estandar-core/estandar-core.php"
check $? "bump local revertido (mirror vuelve a $(v2_current_version))"
rm -rf "$SNAP" "$TMP"

echo ""
echo "=== RESULTADO ==="
echo "PASS: $PASS / FAIL: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
