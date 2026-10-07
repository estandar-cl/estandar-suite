#!/usr/bin/env bash
# ESTANDAR_TEST.command
# Ejecuta la bateria de tests de todos los plugins estandar-* + php -l, y genera reporte.

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR" || exit 1

if [ -t 1 ]; then
  C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
  C_RED=""; C_GREEN=""; C_YELLOW=""; C_BOLD=""; C_RESET=""
fi

PHP_BIN="$(command -v php)"
if [ -z "$PHP_BIN" ]; then
  echo "${C_RED}ERROR: No se encontro PHP en el PATH. Instala PHP y vuelve a intentar.${C_RESET}"
  exit 1
fi
PHP_VERSION="$($PHP_BIN -v | head -n 1)"

mkdir -p "$DIR/reports"
TS="$(date +%Y-%m-%d_%H%M%S)"
REPORT_FILE="$DIR/reports/${TS}_test_report.txt"
: > "$REPORT_FILE"

log() {
  printf '%s\n' "$1"
  printf '%s\n' "$1" | sed -E 's/\x1B\[[0-9;]*[mK]//g' >> "$REPORT_FILE"
}

START_TIME=$(date +%s)

TOTAL_PLUGINS=0
TOTAL_TESTS=0
TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_SKIPPED=0
TOTAL_LINT_ERRORS=0

log "=================================================="
log "ESTANDAR SUITE TEST REPORT"
log "Fecha: $(date '+%Y-%m-%d %H:%M:%S')"
log "PHP: $PHP_VERSION"
log "=================================================="
log ""

PLUGIN_DIRS=()
# Canal legacy: excluye la Suite v2 (canal aislado, gates propios en
# estandar-suite-v2/tests/run-suite.sh). Nunca mezclar canales en el gate.
V2_EXCLUDED=(estandar-core estandar-capture estandar-brain estandar-flow estandar-insights)
while IFS= read -r -d '' d; do
  slug="$(basename "$d")"
  skip=0
  for v in "${V2_EXCLUDED[@]}"; do [ "$slug" = "$v" ] && skip=1; done
  [ "$skip" -eq 1 ] && continue
  PLUGIN_DIRS+=("$d")
done < <(find "$DIR" -mindepth 1 -maxdepth 1 -type d -name 'estandar-*' -print0 | sort -z)

if [ "${#PLUGIN_DIRS[@]}" -eq 0 ]; then
  echo "${C_RED}ERROR: No se encontraron plugins estandar-*. ¿Estás en el directorio correcto?${C_RESET}"
  exit 1
fi

for PLUGIN_PATH in "${PLUGIN_DIRS[@]}"; do
  PLUGIN_NAME="$(basename "$PLUGIN_PATH")"
  TOTAL_PLUGINS=$((TOTAL_PLUGINS + 1))

  log "------------------------------------------------"
  log "Plugin: $PLUGIN_NAME"
  log "------------------------------------------------"

  TESTS_DIR="$PLUGIN_PATH/tests"
  RAN_ANY=0

  if [ -d "$TESTS_DIR" ]; then
    TEST_FILES=()
    while IFS= read -r -d '' f; do
      TEST_FILES+=("$f")
    done < <(find "$TESTS_DIR" -maxdepth 1 -type f -name 'run-*.php' -print0 | sort -z)

    RUN_ALL="$TESTS_DIR/run-all.php"
    if [ -f "$RUN_ALL" ]; then
      TEST_FILES=("$RUN_ALL")
    fi

    if [ "${#TEST_FILES[@]}" -gt 0 ]; then
      for TEST_FILE in "${TEST_FILES[@]}"; do
        TEST_BASENAME="$(basename "$TEST_FILE")"
        RAN_ANY=1
        TOTAL_TESTS=$((TOTAL_TESTS + 1))

        OUTPUT="$("$PHP_BIN" "$TEST_FILE" 2>&1)"
        CODE=$?

        if [ $CODE -eq 0 ] && printf '%s' "$OUTPUT" | grep -qE '^(SKIP|SKIPPED):'; then
          TOTAL_SKIPPED=$((TOTAL_SKIPPED + 1))
          log "${C_YELLOW}SKIPPED${C_RESET} $TEST_BASENAME"
          while IFS= read -r LINE; do
            log "    $LINE"
          done <<< "$OUTPUT"
        elif [ $CODE -eq 0 ]; then
          TOTAL_PASS=$((TOTAL_PASS + 1))
          log "${C_GREEN}PASS${C_RESET} $TEST_BASENAME"
        else
          TOTAL_FAIL=$((TOTAL_FAIL + 1))
          log "${C_RED}FAIL${C_RESET} $TEST_BASENAME"
          log "  Plugin: $PLUGIN_NAME"
          log "  Archivo: $TEST_FILE"
          log "  Salida completa:"
          while IFS= read -r LINE; do
            log "    $LINE"
          done <<< "$OUTPUT"
        fi
      done
    fi
  fi

  if [ "$RAN_ANY" -eq 0 ]; then
    TOTAL_SKIPPED=$((TOTAL_SKIPPED + 1))
    log "${C_YELLOW}SKIPPED (sin tests)${C_RESET}"
  fi

  PHP_FILES=()
  while IFS= read -r -d '' f; do
    PHP_FILES+=("$f")
  done < <(find "$PLUGIN_PATH" -type f -name '*.php' -print0)

  LINT_ERRORS_HERE=0
  for PHP_FILE in "${PHP_FILES[@]}"; do
    LINT_OUT="$("$PHP_BIN" -l "$PHP_FILE" 2>&1)"
    LINT_CODE=$?
    if [ $LINT_CODE -ne 0 ]; then
      LINT_ERRORS_HERE=$((LINT_ERRORS_HERE + 1))
      TOTAL_LINT_ERRORS=$((TOTAL_LINT_ERRORS + 1))
      log "${C_RED}LINT ERROR${C_RESET} $PHP_FILE"
      while IFS= read -r LINE; do
        log "    $LINE"
      done <<< "$LINT_OUT"
    fi
  done

  if [ "$LINT_ERRORS_HERE" -eq 0 ]; then
    log "php -l: OK (${#PHP_FILES[@]} archivos)"
  fi

  log ""
done

# ── Production Data Safety Gate ──────────────────────────────────────────
log ""
log "=================================================="
log "PRODUCTION DATA SAFETY GATE"
log "=================================================="
log ""

SAFETY_GATE_SCRIPT="$DIR/tests/production-safety-gate.php"
if [ -f "$SAFETY_GATE_SCRIPT" ]; then
  SAFETY_OUTPUT="$("$PHP_BIN" "$SAFETY_GATE_SCRIPT" 2>&1)"
  SAFETY_CODE=$?
  while IFS= read -r LINE; do
    log "  $LINE"
  done <<< "$SAFETY_OUTPUT"
  if [ $SAFETY_CODE -ne 0 ]; then
    SAFETY_FAILED=1
    log "${C_RED}${C_BOLD}PRODUCTION DATA SAFETY GATE FAILED${C_RESET}"
  else
    log "${C_GREEN}${C_BOLD}Production Data Safety Gate PASS${C_RESET}"
  fi
else
  SAFETY_FAILED=1
  log "${C_RED}${C_BOLD}PRODUCTION DATA SAFETY GATE: script no encontrado en $SAFETY_GATE_SCRIPT${C_RESET}"
fi

END_TIME=$(date +%s)
ELAPSED=$((END_TIME - START_TIME))

log ""
log "=================================================="
log "ESTANDAR SUITE TEST REPORT"
log ""
log "Plugins revisados: $TOTAL_PLUGINS"
log "Tests ejecutados: $TOTAL_TESTS"
log "PASS: $TOTAL_PASS"
log "FAIL: $TOTAL_FAIL"
log "SKIPPED: $TOTAL_SKIPPED"
log "Lint Errors: $TOTAL_LINT_ERRORS"
log "Safety Gate: $([ ${SAFETY_FAILED:-0} -eq 1 ] && echo 'FAIL' || echo 'PASS')"
log "Tiempo total: ${ELAPSED} s"
log "=================================================="

if [ "$TOTAL_FAIL" -eq 0 ] && [ "$TOTAL_LINT_ERRORS" -eq 0 ] && [ "${SAFETY_FAILED:-0}" -eq 0 ]; then
  log "${C_GREEN}${C_BOLD}SAFE TO DEPLOY${C_RESET}"
  log "${C_GREEN}  Tests funcionales aprobados${C_RESET}"
  log "${C_GREEN}  PHP lint aprobado${C_RESET}"
  log "${C_GREEN}  Production Data Safety Gate aprobado${C_RESET}"
  if [ "$TOTAL_SKIPPED" -gt 0 ]; then
    log "${C_YELLOW}  ATENCION: $TOTAL_SKIPPED test(s) omitido(s) — verificar manualmente${C_RESET}"
  fi
  EXIT_CODE=0
else
  log "${C_RED}${C_BOLD}DEPLOY BLOCKED${C_RESET}"
  [ "$TOTAL_FAIL" -ne 0 ] && log "${C_RED}  - Tests funcionales fallidos${C_RESET}"
  [ "$TOTAL_LINT_ERRORS" -ne 0 ] && log "${C_RED}  - PHP lint errors${C_RESET}"
  [ "${SAFETY_FAILED:-0}" -ne 0 ] && log "${C_RED}  - Production Data Safety Gate FAILED${C_RESET}"
  EXIT_CODE=1
fi

log ""
log "Reporte guardado en: $REPORT_FILE"

exit $EXIT_CODE
