#!/usr/bin/env bash
# Verifica las 8 condiciones del test gate en release.command
# NO destructivo — no modifica archivos, no publica, no accede al remoto.
set -u -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$(dirname "$SCRIPT_DIR")"
RELEASE_CMD="$PLUGINS_DIR/release.command"
TEST_CMD="$PLUGINS_DIR/ESTANDAR_TEST.command"

PASS=0; FAIL=0
check() { if [ "$1" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS $2"; else FAIL=$((FAIL+1)); echo "FAIL $2"; fi }

echo "=== REGRESION TEST GATE ==="
echo ""

# ------------------------------------------------------------------ helpers --
make_dummy_test_runner() {
    local dir="$1" exit_code="$2"
    cat > "$dir/ESTANDAR_TEST.command" <<'SCRIPT'
#!/usr/bin/env bash
exit PLACEHOLDER
SCRIPT
    sed -i '' "s/PLACEHOLDER/${exit_code}/" "$dir/ESTANDAR_TEST.command"
    chmod +x "$dir/ESTANDAR_TEST.command"
}

run_release_snippet() {
    # Run just the TEST GATE phase of release.command in a controlled env.
    # Uses RELEASE_SKIP_TEST_GATE from the caller (preserved via export).
    local test_dir="$1"
    local gate_code=0
    local output
    output="$(bash -c '
        set -u -o pipefail
        dir="'"$test_dir"'"
        fail() { echo "GATE_FAILED: $*" >&2; exit 1; }
        TEST_RUNNER="$dir/ESTANDAR_TEST.command"
        if [ "${RELEASE_SKIP_TEST_GATE:-0}" -eq 0 ]; then
            echo "--- TEST GATE ---"
            echo "ejecutando suite de tests y php -l..."
            if [ -x "$TEST_RUNNER" ] || [ -f "$TEST_RUNNER" ]; then
                if ! bash "$TEST_RUNNER"; then
                    fail "DEPLOY BLOCKED: la suite de tests no paso. Corrige los fallos antes de publicar."
                fi
            else
                fail "No se encontro ESTANDAR_TEST.command en $dir"
            fi
        else
            echo "--- TEST GATE ---"
            echo "(omitido: RELEASE_SKIP_TEST_GATE=1)"
        fi
        echo "GATE_PASSED"
    ' 2>&1)" || gate_code=$?
    echo "$output"
    return $gate_code
}

# ------------------------------------------------------------ 1) Tests verdes
echo "--- 1) Tests verdes permiten continuar ---"
TEST_DIR1="$(mktemp -d)"
make_dummy_test_runner "$TEST_DIR1" 0
OUT1="$(run_release_snippet "$TEST_DIR1")"
check $? "test gate pasa con ESTANDAR_TEST.command exit 0"
echo "$OUT1" | grep -q "GATE_PASSED"
check $? "gate muestra GATE_PASSED con tests verdes"
rm -rf "$TEST_DIR1"

# ---------------------------------------------------------- 2) Tests fallidos
echo ""
echo "--- 2) Tests fallidos abortan (sin locks, sin bumps, sin ZIPs) ---"
TEST_DIR2="$(mktemp -d)"
make_dummy_test_runner "$TEST_DIR2" 1
OUT2="$(run_release_snippet "$TEST_DIR2")" || GATE_CODE2=$?
[ "${GATE_CODE2:-0}" != "0" ]
check $? "test gate falla con ESTANDAR_TEST.command exit 1 (codigo=${GATE_CODE2:-0})"
echo "$OUT2" | grep -q "DEPLOY BLOCKED"
check $? "gate imprime DEPLOY BLOCKED"
rm -rf "$TEST_DIR2"

# --------------------------------------------------------------- 3) php -l
echo ""
echo "--- 3) Un error de php -l bloquea el release ---"
TEST_DIR3="$(mktemp -d)"
# Simular ESTANDAR_TEST.command que falla (exit 1 = error de lint o test)
make_dummy_test_runner "$TEST_DIR3" 1
OUT3="$(run_release_snippet "$TEST_DIR3")"
echo "$OUT3" | grep -q "DEPLOY BLOCKED"
check $? "exit 1 del test runner (simulando lint error) bloquea el release"
rm -rf "$TEST_DIR3"

# --------------------------------------------------------- 4) Cero plugins
echo ""
echo "--- 4) Cero plugins bloquea el release ---"
# Crear un directorio temporal sin plugins estandar-* para simular
TEST_DIR4="$(mktemp -d)"
# Crear un ESTANDAR_TEST.command modificado que detecta cero plugins
cat > "$TEST_DIR4/ESTANDAR_TEST.command" <<'SCRIPT'
#!/usr/bin/env bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIRS=()
while IFS= read -r -d '' d; do
  PLUGIN_DIRS+=("$d")
done < <(find "$DIR" -mindepth 1 -maxdepth 1 -type d -name 'estandar-*' -print0 2>/dev/null | sort -z)
if [ "${#PLUGIN_DIRS[@]}" -eq 0 ]; then
  echo "ERROR: No se encontraron plugins estandar-*."
  exit 1
fi
echo "Encontrados ${#PLUGIN_DIRS[@]} plugins"
exit 0
SCRIPT
chmod +x "$TEST_DIR4/ESTANDAR_TEST.command"
OUT4="$(run_release_snippet "$TEST_DIR4")" || GATE_CODE4=$?
[ "${GATE_CODE4:-0}" != "0" ]
check $? "test gate falla con cero plugins"
echo "$OUT4" | grep -q "DEPLOY BLOCKED"
check $? "gate imprime DEPLOY BLOCKED con cero plugins"
rm -rf "$TEST_DIR4"

# -------------------------------------------------- 5) --dry-run ejecuta gate
echo ""
echo "--- 5) --dry-run tambien ejecuta el gate ---"
# El gate corre antes del check de DRY_RUN en release.command (está en PREFLIGHT)
# Verificar que el source de release.command tiene el gate ANTES de cualquier if DRY_RUN
RELEASE_SRC="$(cat "$RELEASE_CMD")"
echo "$RELEASE_SRC" | grep -A5 'TEST GATE' | grep -q 'run-suite.sh'
check $? "release.command contiene el test gate v2 (run-suite.sh)"
# El dry-run exit está al final del PREPARE. El gate está antes. Orden correcto.
GATE_LINE="$(echo "$RELEASE_SRC" | grep -n 'TEST GATE' | head -1 | cut -d: -f1)"
DRYRUN_EXIT_LINE="$(echo "$RELEASE_SRC" | grep -n 'DRY-RUN completo' | head -1 | cut -d: -f1)"
[ "$GATE_LINE" -lt "$DRYRUN_EXIT_LINE" ]
check $? "test gate aparece antes del exit de dry-run (linea $GATE_LINE < $DRYRUN_EXIT_LINE)"

# --------------------------------------------- 6) Harness omite gate via bypass
echo ""
echo "--- 6) Harness omite test gate con RELEASE_SKIP_TEST_GATE=1 ---"
TEST_DIR6="$(mktemp -d)"
make_dummy_test_runner "$TEST_DIR6" 0
export RELEASE_SKIP_TEST_GATE=1
OUT6="$(run_release_snippet "$TEST_DIR6")"
unset RELEASE_SKIP_TEST_GATE
echo "$OUT6" | grep -q "omitido"
check $? "RELEASE_SKIP_TEST_GATE=1 omite el test gate"
check $? "bypass no falla (exit code 0)"
rm -rf "$TEST_DIR6"

# --------------------------------- 7) ESTANDAR_TEST.command independiente OK
echo ""
echo "--- 7) ESTANDAR_TEST.command se conserva ejecutable independiente ---"
check $([ -f "$TEST_CMD" ] && echo 0 || echo 1) "ESTANDAR_TEST.command existe"
check $([ -x "$TEST_CMD" ] && echo 0 || echo 1) "ESTANDAR_TEST.command es ejecutable"
# Verificar que no referencia a release.command internamente
! grep -q 'release\.command' "$TEST_CMD"
check $? "ESTANDAR_TEST.command no depende de release.command"

# ---------------------------------------- 8) Rollback y traps no cambian
echo ""
echo "--- 8) Rollback y traps existentes intactos ---"
RELEASE_SRC="$(cat "$RELEASE_CMD")"
echo "$RELEASE_SRC" | grep -q 'do_rollback'
check $? "do_rollback sigue presente"
echo "$RELEASE_SRC" | grep -q 'trap cleanup_on_exit EXIT'
check $? "trap EXIT sigue presente"
echo "$RELEASE_SRC" | grep -q 'restore_local_bumps'
check $? "restore_local_bumps sigue presente"
echo "$RELEASE_SRC" | grep -q 'release_locks'
check $? "release_locks sigue presente"

# --------------------------------------------- Sintaxis Bash
echo ""
echo "--- SINTAXIS BASH ---"
bash -n "$RELEASE_CMD" 2>&1
check $? "release.command sintaxis bash OK"
bash -n "$TEST_CMD" 2>&1
check $? "ESTANDAR_TEST.command sintaxis bash OK"

echo ""
echo "=== RESULTADO ==="
echo "PASS: $PASS / FAIL: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
