#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "[1/4] Levantando Docker..."
docker compose up -d >/dev/null

echo "[2/4] Asegurando plugin activo..."
if ! docker compose run --rm -T wpcli plugin is-active estandar-chatbot >/dev/null 2>&1; then
  docker compose run --rm -T wpcli plugin activate estandar-chatbot >/dev/null
fi

echo "[3/4] Resolviendo URL auto-creada..."
TARGET_URL="$(docker compose run --rm -T wpcli eval '$id=(int)get_option("estandar_chatbot_inline_page_id",0); echo $id ? get_permalink($id) : "";' | tail -n 1 | tr -d '\r')"
if [[ -z "$TARGET_URL" ]]; then
  echo "No se pudo resolver la URL de la pagina auto-creada" >&2
  exit 1
fi
TARGET_URL="${TARGET_URL/localhost:18080/host.docker.internal:18080}"

echo "[4/4] Ejecutando validacion visual Playwright..."
docker compose run --rm -T \
  -e ECB_TARGET_URL="$TARGET_URL" \
  -e ECB_SCREENSHOT_PATH="/workspace/storage/playwright/chatbot-fullpage.png" \
  playwright "PLAYWRIGHT_NODE_DIR=/tmp/ecb-playwright-node; \
    if [ ! -d \"\$PLAYWRIGHT_NODE_DIR/node_modules/playwright\" ]; then \
      mkdir -p \"\$PLAYWRIGHT_NODE_DIR\" && npm install --prefix \"\$PLAYWRIGHT_NODE_DIR\" --no-save playwright@1.51.1 >/tmp/ecb-playwright-npm.out 2>/tmp/ecb-playwright-npm.err; \
    fi; \
    NODE_PATH=\"\$PLAYWRIGHT_NODE_DIR/node_modules\" node /workspace/tests/e2e/chatbot-visual-check.js" \
  >/tmp/ecb-playwright.out 2>/tmp/ecb-playwright.err \
  || { cat /tmp/ecb-playwright.err >&2; exit 1; }

cat /tmp/ecb-playwright.out
