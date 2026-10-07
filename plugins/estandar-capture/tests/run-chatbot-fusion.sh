#!/usr/bin/env bash
#
# ESTANDAR SUITE v2 — Capture · RUNNER FUSIÓN CHATBOT → CAPTURE
#
#   ./tests/run-chatbot-fusion.sh [wp-path]
#
# Batería local de la fusión chatbot↔capture (roadmap sesión 007):
#   1) Oráculo de paridad run-parity-chatbot.php (--gate = condición de retiro).
#   2) Harness funcional ChatbotBridge (coexistencia + dedupe + anti-bucle).
#   3) Harness funcional LeadsBridge (puente opt-in → estandar-leads).
#   4) Harness funcional ChatbotAdoption (ecb_leads read-only → submissions).
#   (run-capture.php ya incluye la sección 26 pure; se corre por separado.)
set -euo pipefail
cd "$(dirname "$0")/.."
WP="${1:-/Volumes/ESTANDAR_2026/02_EstandarIA/Estandar_Suite/Playground}"
echo "== 1/4 ORÁCULO DE PARIDAD (chatbot) =="
php tests/parity/run-parity-chatbot.php
echo "== 1b GATE (retiro) =="
php tests/parity/run-parity-chatbot.php --gate
echo "== 2/4 CHATBOT BRIDGE =="
php tests/functional/chatbot-bridge-harness.php "$WP"
echo "== 3/4 LEADS BRIDGE =="
php tests/functional/leads-bridge-harness.php "$WP"
echo "== 4/4 CHATBOT ADOPTION =="
php tests/functional/chatbot-adoption-harness.php "$WP"
echo "== FUSIÓN CHATBOT: ALL GREEN =="
