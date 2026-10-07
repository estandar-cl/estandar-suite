#!/bin/bash
# release-noticias.command — build + deploy del plugin standalone `estandar-noticias`.
# NO es un plugin de la suite: no entra en SUITE_PLUGINS ni en --deploy-all.
# Uso:
#   ./release-noticias.command                 # solo construye el zip
#   ./release-noticias.command --deploy=rodrigovaldes
#   ./release-noticias.command --deploy=estandar
#   ./release-noticias.command --deploy=rodrigovaldes,estandar
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SLUG="estandar-noticias"
SRC="$SCRIPT_DIR/plugins/$SLUG"
BUILD_DIR="$SCRIPT_DIR/.release-build/noticias-$(date -u +%Y%m%d-%H%M%S)"

GREEN='\033[0;32m'; RED='\033[0;31m'; NC='\033[0m'
ok()   { echo -e "  ${GREEN}✓${NC} $*"; }
fail() { echo -e "${RED}ERROR: ${NC}$*" >&2; exit 1; }

DEPLOY=""
for arg in "$@"; do
  case "$arg" in
    --deploy=*) DEPLOY="${arg#--deploy=}" ;;
    -h|--help) echo "uso: $0 [--deploy=rodrigovaldes,estandar]"; exit 0 ;;
    *) fail "opción desconocida: $arg" ;;
  esac
done

[ -f "$SRC/$SLUG.php" ] || fail "no existe $SRC/$SLUG.php"
VER="$(grep -m1 'Version:' "$SRC/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')"
[ -n "$VER" ] || fail "no se pudo leer la versión"

echo "== $SLUG $VER =="
for f in "$SRC"/*.php "$SRC"/includes/*.php; do php -l "$f" >/dev/null || fail "php -l falló en $f"; done
if [ -f "$SRC/tests/run-noticias.php" ]; then
  php "$SRC/tests/run-noticias.php" >/dev/null || fail "tests fallaron (corre: php $SRC/tests/run-noticias.php)"
  ok "php -l + tests"
fi

mkdir -p "$BUILD_DIR"
ZIP="$BUILD_DIR/$SLUG-$VER.zip"
( cd "$SCRIPT_DIR/plugins" && zip -rq "$ZIP" "$SLUG" -x "$SLUG/tests/*" -x "*.DS_Store" )
ok "zip: $ZIP"

[ -z "$DEPLOY" ] && { echo "Listo (sin deploy)."; exit 0; }

wp_path_for() {
  case "$1" in
    rodrigovaldes) echo '~/www/rodrigovaldes.cl/public_html' ;;
    estandar)      echo '~/www/estandar.cl/public_html' ;;
    acacios)       echo '~/www/acacios.cl/public_html' ;;
    *)             echo '' ;;
  esac
}

IFS=',' read -r -a SITES <<< "$DEPLOY"
for site in "${SITES[@]}"; do
  path="$(wp_path_for "$site")"
  [ -n "$path" ] || fail "sitio desconocido: $site"
  echo "== deploy → $site =="
  scp -o BatchMode=yes -o ConnectTimeout=20 "$ZIP" "$site:/tmp/" >/dev/null || fail "scp a $site"
  ssh -o BatchMode=yes -o ConnectTimeout=30 "$site" "cd $path && wp plugin install /tmp/$SLUG-$VER.zip --force && wp plugin activate $SLUG" \
    || fail "wp plugin install en $site"
  ok "$site → $(ssh -o BatchMode=yes "$site" "cd $path && wp plugin get $SLUG --field=version")"
done
echo "Deploy completo."
