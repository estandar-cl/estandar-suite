#!/bin/zsh
set -euo pipefail
# Estandar Acceptance Framework — Runner
# Orchestrates scenario execution, evidence collection, and reporting.
# Usage: ./runner.sh [--audit TYPE] [--plugin PLUGIN] [--output DIR]

BASE="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")"/.. && pwd)"
FRAMEWORK="${BASE}/framework"
SCENARIOS="${BASE}/scenarios"
REPORTS="${BASE}/reports"
DATA="${BASE}/data"
LIB="${BASE}/lib"

AUDIT_TYPES=("functional" "journey" "operator" "ux" "commercial" "regression" "release")
AUDIT="journey"
PLUGIN=""
OUTPUT="${REPORTS}/$(date +%Y%m%d-%H%M%S)"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --audit) AUDIT="$2"; shift 2;;
        --plugin) PLUGIN="$2"; shift 2;;
        --output) OUTPUT="$2"; shift 2;;
        *) echo "Unknown: $1"; exit 1;;
    esac
done

mkdir -p "$OUTPUT"

# Source lib
source "${LIB}/helpers.sh" 2>/dev/null || true

PASS=0
FAIL=0
SKIP=0
BLOCKED=0
RESULTS=()

pass()   { PASS=$((PASS+1)); RESULTS+=("PASS:$1"); echo "  PASS $1"; }
fail()   { FAIL=$((FAIL+1)); RESULTS+=("FAIL:$1"); echo "  FAIL $1"; }
skip()   { SKIP=$((SKIP+1)); RESULTS+=("SKIP:$1"); echo "  SKIP $1"; }
blocked(){ BLOCKED=$((BLOCKED+1)); RESULTS+=("BLOCKED:$1"); echo "  BLOCKED $1"; }

echo "╔══════════════════════════════════════════════════════════╗"
echo "║  Estandar Acceptance Framework                         ║"
echo "║  Audit: ${AUDIT} | Output: ${OUTPUT}                ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Resolve scenarios to run
SCENARIO_DIR="${SCENARIOS}"
if [ -n "$PLUGIN" ]; then
    SCENARIO_DIR="${SCENARIOS}/${PLUGIN}"
    if [ ! -d "$SCENARIO_DIR" ]; then
        echo "No scenarios for plugin: $PLUGIN"
        exit 1
    fi
fi

# Execute scenarios by audit type
echo "── Scenarios (type: ${AUDIT}) ──"
echo ""

# Find and source all scenarios matching this audit type
for scenario in "${SCENARIO_DIR}"/*.sh; do
    [ -f "$scenario" ] || continue
    name=$(basename "$scenario" .sh)
    echo "  Loading: ${name}"
done

# Source the audit scenarios
AUDIT_FILE="${SCENARIO_DIR}/audit-${AUDIT}.sh"
if [ -f "$AUDIT_FILE" ]; then
    source "$AUDIT_FILE"
else
    echo "No audit-${AUDIT}.sh found in ${SCENARIO_DIR}"
    echo "Available: "
    ls "${SCENARIO_DIR}"/audit-*.sh 2>/dev/null | sed 's/.*\///' || echo "  (none)"
fi

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║  Results: ${PASS} PASS / ${FAIL} FAIL / ${SKIP} SKIP / ${BLOCKED} BLOCKED"
echo "╚══════════════════════════════════════════════════════════╝"

# Generate report
REPORT_FILE="${OUTPUT}/report.json"
python3 -c "
import json, sys
results = []
for r in sys.stdin.read().strip().split(chr(10)):
    if ':' in r:
        parts = r.split(':', 1)
        results.append({'status': parts[0], 'name': parts[1]})
report = {
    'framework': 'estandar-acceptance',
    'version': '1.0.0',
    'audit': '$AUDIT',
    'plugin': '$PLUGIN',
    'timestamp': '$(date -u +%Y-%m-%dT%H:%M:%SZ)',
    'results': results,
    'summary': {'pass': $PASS, 'fail': $FAIL, 'skip': $SKIP, 'blocked': $BLOCKED}
}
with open('$REPORT_FILE', 'w') as f:
    json.dump(report, f, indent=2)
print('Report: $REPORT_FILE')
" <<< "$(printf '%s\n' "${RESULTS[@]}")"

exit $FAIL
