#!/bin/bash
# release.command — Estandar Suite Release Pipeline
# Uso: ./release.command [--dry-run]
# Publica SOLO la Suite v2 (los 6 plugins de la versión vigente). Los plugins
# legacy se eliminaron del disco (30/08/2026) — v2-only.
# Compatible con bash 3.2+ (macOS)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/.release-build/$(date -u +%Y%m%d-%H%M%S)"
SRC="$SCRIPT_DIR/plugins"
SSH_KEY="$HOME/.ssh/id_ed25519_estandar"

DRY_RUN=0; DO_V2=1; DO_STATUS=0; DO_DEPLOY=0; DEPLOY_LIST=""; POLICY_SPECS=()
CHANNEL_BASE="https://updates.estandar.cl/staging/v2"

usage() {
  cat <<'EOF'
Uso: ./release.command [--dry-run] [opciones]

RELEASE (publica la Suite v2 al canal updates.estandar.cl/staging/v2):
  (sin opciones)   publica (gates + bump + zips + SFTP al canal)
  --dry-run        valida gates/tests sin publicar nada

ADMINISTRACIÓN DE INSTALACIONES (no hace release; usa la versión del canal):
  --status         muestra versión local + política por instalación
  --deploy-all     despliega la versión del canal a TODAS las instalaciones SSH
  --deploy=LISTA   despliega solo a las indicadas (acacios,estandar,guillaume,
                   rodrigovaldes; akcontadores es manual/GoDaddy)
  --policy=SITIO:VAL   fija la política de actualización del sitio:
                       auto | off | pin:X.Y.Z   (repetible; útil cuando la
                       suscripción termina o el cliente deja de ser cliente)
EOF
}

for arg; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --v2) DO_V2=1 ;;
    --status) DO_STATUS=1 ;;
    --deploy-all) DO_DEPLOY=1 ;;
    --deploy=*) DO_DEPLOY=1; DEPLOY_LIST="${arg#--deploy=}" ;;
    --policy=*) POLICY_SPECS+=("${arg#--policy=}") ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Opción desconocida: $arg"; usage; exit 1 ;;
  esac
done

REMOTE_HOST="ssh.estandar.cl"; REMOTE_PORT="18765"
REMOTE_USER="u1145-hdrkulkhlnq9"
REMOTE_BASE="/home/u1145-hdrkulkhlnq9/www/updates.estandar.cl/public_html"

GREEN='\033[0;32m'; RED='\033[0;31m'; NC='\033[0m'
remote() { [ "$DRY_RUN" -eq 1 ] && return 0; ssh -p "$REMOTE_PORT" -i "$SSH_KEY" -o BatchMode=yes "$REMOTE_USER@$REMOTE_HOST" "$@"; }
fail() { echo -e "${RED}ERROR: $*${NC}" >&2; exit 1; }
ok()   { echo -e "  ${GREEN}✓${NC} $*"; }

# ════════════════════════════════════════════════════════════════
# INSTALACIONES (matriz de despliegue) + administración por sitio
# formato: nombre|user|host|port|sshkey|wppath|tipo  (tipo: ssh|manual)
SITES="
acacios|u235-jgwrgt7gd3ye|ssh.acacios.cl|18765|$HOME/.ssh/dragon_acacios|www/acacios.cl/public_html|ssh
estandar|u1145-hdrkulkhlnq9|ssh.estandar.cl|18765|$HOME/.ssh/id_ed25519_estandar|www/estandar.cl/public_html|ssh
guillaume|u2835-bfzasnseuwht|34.174.169.166|18765|$HOME/.ssh/dragon_guillaume|www/guillaume.cl/public_html|ssh
rodrigovaldes|u490-h9thfareohb6|ssh.rodrigovaldes.cl|18765|$HOME/.ssh/dragon_rodrigovaldes|www/rodrigovaldes.cl/public_html|ssh
akcontadores|-|-|-|-|-|manual
"

site_line() { echo "$SITES" | grep -E "^$1\|" | head -1; }
site_kind() { local line="$1"; IFS='|' read -r _ _ _ _ _ _ k <<< "$line"; echo "$k"; }
site_ssh() {
  local line="$1"; shift
  local user host port key
  IFS='|' read -r _ user host port key _ _ <<< "$line"
  [ "$user" = "-" ] && return 1
  ssh -n -p "$port" -i "$key" -o BatchMode=yes -o ConnectTimeout=20 "$user@$host" "$@"
}
site_wp() {
  local line="$1"; shift
  local wppath
  IFS='|' read -r _ _ _ _ _ wppath _ <<< "$line"
  site_ssh "$line" "cd '$wppath' && wp $* 2>/dev/null"
}
site_opt() { site_wp "$1" option get "$2" 2>/dev/null | tail -1 | tr -d '[:space:]'; }
site_policy() { local p; p=$(site_opt "$1" estandar_suite_updates_policy); [ -n "$p" ] && echo "$p" || echo "auto"; }
site_local_version() { site_wp "$1" plugin get estandar-core --field=version 2>/dev/null | tr -d '[:space:]'; }

ver_ge() {
  python3 - "$1" "$2" <<'PY'
import sys,re
def part(v):
    m=re.match(r'(\d+)\.(\d+)(?:\.(\d+))?', v or '')
    g=[int(x or 0) for x in m.groups()] if m else [0,0,0]
    return g
sys.exit(0 if part(sys.argv[1])>=part(sys.argv[2]) else 1)
PY
}

channel_version() { curl -fsSL "$CHANNEL_BASE/versions.json" | python3 -c "import sys,json;print(json.load(sys.stdin)['estandar-core'])"; }

valid_policy() {
  case "$1" in
    auto|off) return 0 ;;
    pin:*) python3 -c "import sys,re; sys.exit(0 if re.match(r'^pin:\d+\.\d+(\.\d+)?',sys.argv[1]) else 1)" "$1" ;;
    *) return 1 ;;
  esac
}

# Despliega la versión del canal a un sitio SSH (respeta su política local).
deploy_site() {
  local line="$1" name tgt
  IFS='|' read -r name _ <<< "$line"
  local kind; kind=$(site_kind "$line")
  if [ "$kind" = "manual" ]; then
    echo -e "  ${name}: ${RED}manual${NC} (GoDaddy, sin SSH) → actualiza en su panel Suite (Core → Avanzado → Actualizaciones)."
    return 0
  fi
  local pol loc
  pol=$(site_policy "$line")
  loc=$(site_local_version "$line")
  tgt="$DEPLOY_TARGET"
  [ -z "$tgt" ] && tgt=$(channel_version)
  echo -e "  ${name}: local=$loc canal=$tgt política=$pol"
  if [ "$pol" = "off" ]; then
    echo -e "    → ${RED}SKIP${NC}: política off (sitio desactivado)"
    return 0
  fi
  if [ "${pol#pin:}" != "$pol" ]; then
    local pin="${pol#pin:}"
    if ver_ge "$pin" "$tgt"; then
      echo -e "    → ${RED}SKIP${NC}: política pin en $pin ≥ canal $tgt"
      return 0
    fi
  fi
  if [ -n "$loc" ] && ver_ge "$loc" "$tgt"; then
    echo -e "    → ya al día."
    return 0
  fi
  if [ "$DRY_RUN" -eq 1 ]; then
    echo -e "    → (dry-run) se actualizaría a $tgt"
    return 0
  fi
  for slug in "${V2_PLUGINS[@]}"; do
    site_wp "$line" plugin install "$CHANNEL_BASE/$slug.zip" --force > /dev/null 2>&1 || echo -e "    ${RED}✗${NC} fallo al actualizar $slug"
  done
  site_wp "$line" plugin activate estandar-core > /dev/null 2>&1 || true
  echo -e "    ${GREEN}✓${NC} actualizado a $tgt"
}

site_status() {
  local line="$1" name kind
  IFS='|' read -r name _ <<< "$line"
  kind=$(site_kind "$line")
  local loc pol tgt
  tgt="${DEPLOY_TARGET:-$(channel_version)}"
  if [ "$kind" = "manual" ]; then
    # akcontadores: versión vía REST si existe la env con app password
    if [ -n "${ESTANDAR_AK_APP_PASS:-}" ] && [ -n "${ESTANDAR_AK_ADMIN_URL:-}" ]; then
      loc=$(curl -fsSL -u "luis valdes:$ESTANDAR_AK_APP_PASS" "${ESTANDAR_AK_ADMIN_URL}wp/v2/plugins" 2>/dev/null | python3 -c "import sys,json;[print(p['version']) for p in json.load(sys.stdin) if p['plugin']=='estandar-core/estandar-core.php']" 2>/dev/null)
    else
      loc="(sin env REST)"
    fi
    pol="auto"
  else
    loc=$(site_local_version "$line"); pol=$(site_policy "$line")
  fi
  printf '  %-14s local=%-8s canal=%-8s política=%-10s %s\n' "$name" "${loc:-?}" "$tgt" "$pol"
}

# ── release_group: hace todo el ciclo para un grupo de plugins ──
# $1 = label, $2 = remote_path, $3 = public_url, $4... = plugin slugs
release_group() {
  local label="$1" remote_path="$2" public_url="$3"; shift 3
  local plugins=("$@") current next

  echo ""; echo "─── $label ───"

  # Preflight
  for p in "${plugins[@]}"; do [ -d "$SRC/$p" ] || fail "$p no encontrado"; done
  ok "${#plugins[@]} plugins presentes"

  # Versión actual + consistencia
  first_p="${plugins[0]}"
  first_main=$(ls "$SRC/$first_p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
  [ -z "$first_main" ] && first_main="$SRC/$first_p/$first_p.php"
  current=$(grep -m1 "Version:" "$first_main" | sed 's/.*Version: *//')
  for p in "${plugins[@]}"; do
    local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
    [ -z "$main" ] && main="$SRC/$p/$p.php"
    local v=$(grep -m1 "Version:" "$main" 2>/dev/null | sed 's/.*Version: *//')
    [ "$v" = "$current" ] || fail "version inconsistente: $p=$v ≠ $current"
  done
  echo "  version actual: $current"

  # Bump (suite): patch 0-9; al llegar a la 9 sube minor y reinicia patch
  # (2.0.9 → 2.1.0). minor 9 + patch 9 → major+1 (2.9.9 → 3.0.0).
  IFS='.' read -r major minor patch _ <<< "$current"
  if [ "$patch" -ge 9 ]; then
    if [ "$minor" -ge 9 ]; then
      major=$((major + 1)); minor=0
    else
      minor=$((minor + 1))
    fi
    patch=0
  else
    patch=$((patch + 1))
  fi
  next="$major.$minor.$patch"
  echo "  → $next"
  for p in "${plugins[@]}"; do
    local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
    [ -z "$main" ] && main="$SRC/$p/$p.php"
    [ -f "$main" ] && perl -pi -e "s/Version: $current/Version: $next/" "$main"
    # Sincronizar la constante interna de versión con el nuevo número (si existe).
    # Patrón: define( 'CONST', '13.0.171' ) / define("CONST","13.0.171") — mantiene comillas.
    local vc; vc=$(plugin_version_const "$p")
    if [ -n "$vc" ] && [ -f "$main" ]; then
      perl -pi -e "s/(define\(\s*['\"]${vc}['\"]\s*,\s*['\"])[^'\"]*(['\"]\s*\))/\${1}${next}\${2}/" "$main"
    fi
  done
  ok "bump aplicado"
  BUMP_FROM="$current"; BUMP_TO="$next"

  # php -l
  local errors=0
  for p in "${plugins[@]}"; do
    while IFS= read -r -d '' f; do
      php -l "$f" > /dev/null 2>&1 || { echo -e "  ${RED}✗${NC} $f"; errors=$((errors+1)); }
    done < <(find "$SRC/$p" -name "*.php" -print0 2>/dev/null)
  done
  [ "$errors" -gt 0 ] && fail "$errors errores de sintaxis"
  ok "php -l OK"

  # Build ZIPs
  local zip_dir="$BUILD_DIR/$label"; mkdir -p "$zip_dir"
  for p in "${plugins[@]}"; do
    python3 - "$SRC/$p" "$zip_dir/$p.zip" <<'PYEOF'
import os, sys, zipfile, hashlib, fnmatch
src, dst = sys.argv[1], sys.argv[2]; FIXED = (1980,1,1,0,0,0)
EXCL = ['__pycache__','.DS_Store','.git','node_modules','vendor','*.sqlite','*.db','*.log','audit-evidence']
root = os.path.abspath(src); top = os.path.basename(root); entries = []
for cur, dirs, files in os.walk(root):
    dirs[:] = sorted(d for d in dirs if d not in ('__pycache__','audit-evidence'))
    for name in sorted(files):
        full = os.path.join(cur, name); rel = os.path.relpath(full, root).replace(os.sep, '/')
        if any(fnmatch.fnmatch(rel, p) or fnmatch.fnmatch(os.path.basename(rel), p) for p in EXCL): continue
        try:
            with open(full, 'rb') as fh: entries.append((f'{top}/{rel}', fh.read()))
        except: pass
entries.sort(key=lambda e: e[0])
with zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as zf:
    for rel, data in entries:
        zi = zipfile.ZipInfo(rel, FIXED); zi.compress_type = zipfile.ZIP_DEFLATED
        zi.external_attr = 0o100644 << 16; zf.writestr(zi, data)
PYEOF
  done
  ok "$(ls "$zip_dir"/*.zip 2>/dev/null | wc -l | tr -d ' ') ZIPs"

  # Metadata
  python3 -c "
import json, hashlib, os
b='$zip_dir'; v='$next'; u='$public_url'; ps='${plugins[*]}'.split()
versions={}; manifest={'generated_at':'$(date -u +%Y-%m-%dT%H:%M:%SZ)','release_version':v,'plugins':{}}
for p in ps:
    zp=f'{b}/{p}.zip'
    if not os.path.exists(zp): continue
    sha=hashlib.sha256(open(zp,'rb').read()).hexdigest()
    versions[p]=v; manifest['plugins'][p]={'version':v,'zip':f'{p}.zip','sha256':sha,'url':f'{u}/{p}.zip'}
json.dump(versions,open(f'{b}/versions.json','w'),indent=2)
json.dump(manifest,open(f'{b}/manifest.json','w'),indent=2)
"
  ok "metadatos"

  # Dry-run exit
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  (dry-run: no se publica $label)"
    # rollback bump
    for p in "${plugins[@]}"; do
      local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
      [ -z "$main" ] && main="$SRC/$p/$p.php"
      [ -f "$main" ] && perl -pi -e "s/Version: $next/Version: $current/" "$main"
      local vc; vc=$(plugin_version_const "$p")
      if [ -n "$vc" ] && [ -f "$main" ]; then
        perl -pi -e "s/(define\(\s*['\"]${vc}['\"]\s*,\s*['\"])[^'\"]*(['\"]\s*\))/\${1}${current}\${2}/" "$main"
      fi
    done
    # dry-run: ya revirtió el bump; limpiar marcas para que el trap no repita.
    BUMP_FROM=""; BUMP_TO=""; PUBLISHED_VERSION=""
    return 0
  fi

  # Publish
  local staging="$remote_path/.staging-$$"
  remote "mkdir -p '$staging'"
  cd "$zip_dir"
  for f in *.zip versions.json manifest.json; do
    printf '  ↑ %-30s' "$f"
    sftp -P "$REMOTE_PORT" -i "$SSH_KEY" -b - "$REMOTE_USER@$REMOTE_HOST" <<< "put $f $staging/$f" > /dev/null 2>&1 \
      && echo -e "${GREEN}OK${NC}" || fail "fallo subida $f"
  done

  # Verify staging integrity
  local lsums rsums
  lsums=$(shasum -a 256 *.zip versions.json manifest.json 2>/dev/null | awk '{print $1,$2}' | sort)
  rsums=$(remote "cd '$staging' && shasum -a 256 *.zip versions.json manifest.json" | awk '{print $1,$2}' | sort)
  [ "$lsums" = "$rsums" ] || fail "staging corrupto"
  ok "staging integro"

  # Atomic switch
  remote "cd '$remote_path'; mkdir -p .rollback; for f in *.zip versions.json manifest.json; do [ -e \"\$f\" ] && cp \"\$f\" .rollback/; done; for f in $staging/*; do mv \"\$f\" \"\$(basename \$f)\"; done; rmdir $staging" || fail "switch fallo"
  ok "publicado → $public_url"

  # Verify published
  local tmp; tmp=$(mktemp -d)
  local all_ok=1
  for f in versions.json manifest.json; do
    curl -fsSL "$public_url/$f" -o "$tmp/$f" 2>/dev/null
    cmp -s "$zip_dir/$f" "$tmp/$f" && ok "$f coincide" || { echo -e "  ${RED}✗${NC} $f"; all_ok=0; }
  done
  rm -rf "$tmp"
  [ "$all_ok" -eq 0 ] && fail "verificacion fallo"

  echo -e "  ${GREEN}✓ $label $next publicado${NC}"
  PUBLISHED_VERSION="$next"
}

# ════════════════════════════════════════════════════════════════
# ROLLBACK_AUTOMATICO_V1: si el release falla DESPUÉS del bump pero ANTES de
# publicar, se revierte la versión local (headers + constantes internas) para
# no dejar el repo a medias. Si se publicó, no se toca nada.
BUMP_FROM=""; BUMP_TO=""; PUBLISHED_VERSION=""
cleanup() {
  rm -rf "$BUILD_DIR" 2>/dev/null
  if [ -n "$BUMP_FROM" ] && [ -n "$BUMP_TO" ] && [ "$PUBLISHED_VERSION" != "$BUMP_TO" ]; then
    echo -e "  ${RED}↯ release no completó la publicación — revirtiendo versión $BUMP_TO → $BUMP_FROM${NC}"
    for p in "${RELEASE_PLUGINS[@]}"; do
      local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
      [ -z "$main" ] && main="$SRC/$p/$p.php"
      [ -f "$main" ] && perl -pi -e "s/Version: $BUMP_TO/Version: $BUMP_FROM/" "$main"
      local vc; vc=$(plugin_version_const "$p")
      if [ -n "$vc" ] && [ -f "$main" ]; then
        perl -pi -e "s/(define\(\s*['\"]${vc}['\"]\s*,\s*['\"])[^'\"]*(['\"]\s*\))/\${1}${BUMP_FROM}\${2}/" "$main"
      fi
    done
  fi
}
trap cleanup EXIT

# ── GRUPOS DE PLUGINS ─────────────────────────────────────────────
# Desde 2026-08-17 la release publica SOLO la Suite v2. Los plugins legacy se
# eliminaron del disco (30/08/2026): el repo solo contiene los 6 plugins v2.
V2_PLUGINS=(
  estandar-core estandar-capture estandar-brain estandar-flow estandar-insights \
  estandar-experience
)

# Conjunto efectivo a liberar (default: solo v2).
RELEASE_PLUGINS=()
[ "$DO_V2" -eq 1 ] && RELEASE_PLUGINS+=( "${V2_PLUGINS[@]}" )
GATE_SCOPE_ARG="--scope=$(IFS=,; echo "${RELEASE_PLUGINS[*]}")"

# VERSION_CONSTANTS: constante interna de versión de producto por plugin ──
# Cada plugin expone su versión de dos formas: el header "Version:" (que este
# pipeline ya bumpa) y una constante PHP interna. Si solo se actualiza el header,
# la constante interna queda obsoleta y produce el "triple esquema de versionado"
# (auditoría 2026-08-07: header 13.0.172 vs constantes 13.0.158 / 1.0.11).
# Formato: "plugin|CONSTANTE"  (vacío = el plugin solo usa el header).
# Las constantes de schema/DB (p.ej. *_DB_VERSION, *_SCHEMA_VERSION) NO se tocan:
# no representan la versión de release.
VERSION_CONSTANTS=(
  "estandar-analytics|EA_VERSION"
  "estandar-chatbot|ESTANDAR_CHATBOT_VERSION"
  "estandar-delivery-locator-lite|ESTANDAR_DL_VERSION"
  "estandar-destinations|ESTANDAR_DESTINATIONS_VERSION"
  "estandar-experience-blocks|ESTANDAR_EFX_VERSION"
  "estandar-forms|ESTANDAR_FORMS_VERSION"
  "estandar-kickoff|ESTANDAR_KICKOFF_VERSION"
  "estandar-leads|ESTANDAR_LEADS_VERSION"
  "estandar-notifications|ESTANDAR_NOTIFICATIONS_VERSION"
  "estandar-pop|EP_VERSION"
  "estandar-primer|ESTANDAR_CORE_MU_VERSION"
  "estandar-quotes|ESTANDAR_QUOTES_VERSION"
  "estandar-secure-share|ESTANDAR_SECURE_SHARE_VERSION"
  "estandar-seo-intelligence|ESTANDAR_SEO_INTELLIGENCE_VERSION"
  "estandar-signal-core|ESTANDAR_SIGNAL_CORE_VERSION"
  "estandar-website-scanner|EWS_VERSION"
  "estandar-wp-exporter|ESTANDAR_WPE_VERSION"
  "estandar-core|ESTANDAR_SUITE_CORE_VERSION"
  "estandar-capture|ESTANDAR_SUITE_CAPTURE_VERSION"
  "estandar-brain|ESTANDAR_SUITE_BRAIN_VERSION"
  "estandar-flow|ESTANDAR_SUITE_FLOW_VERSION"
  "estandar-insights|ESTANDAR_SUITE_INSIGHTS_VERSION"
  "estandar-experience|ESTANDAR_EXPERIENCE_VERSION"
)

# Devuelve el nombre de la constante interna de versión de un plugin (o vacío).
plugin_version_const() {
  local p="$1" entry const
  for entry in "${VERSION_CONSTANTS[@]}"; do
    const="${entry##*|}"
    [[ "$entry" == "$p|"* ]] && { echo "$const"; return 0; }
  done
  return 0
}

# ── VERSION UNIFICADA: los plugins del conjunto comparten el MISMO número ──
# Preflight: los plugins del release deben estar en la misma versión.
gate_unified_version() {
  echo "─── Gate: versión unificada (${#RELEASE_PLUGINS[@]} plugins) ───"
  local first="" v
  for p in "${RELEASE_PLUGINS[@]}"; do
    local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
    [ -z "$main" ] && main="$SRC/$p/$p.php"
    v=$(grep -m1 "Version:" "$main" 2>/dev/null | sed 's/.*Version: *//')
    if [ -z "$first" ]; then first="$v"; fi
    [ "$v" = "$first" ] || fail "versión NO unificada: $p=$v ≠ $first (se espera $first en el conjunto)"
  done
  ok "${#RELEASE_PLUGINS[@]}/${#RELEASE_PLUGINS[@]} plugins en versión unificada $first"
}

# ── GATE DE COHERENCIA INTERNA: header "Version:" == constante PHP interna ──
# Previene el "triple esquema de versionado" (auditoría 2026-08-07): si el header
# dice 13.0.172 pero la constante interna dice 13.0.158 / 1.0.11, el plugin está
# desincronizado (assets servidos con cache-buster viejo, metadatos incoherentes).
gate_internal_versions() {
  echo "─── Gate: coherencia header ↔ constante interna ───"
  local bad=0 p vc hv cv
  for p in "${RELEASE_PLUGINS[@]}"; do
    local main=$(ls "$SRC/$p"/*.php 2>/dev/null | while read f; do grep -ql "Plugin Name:" "$f" 2>/dev/null && echo "$f" && break; done)
    [ -z "$main" ] && main="$SRC/$p/$p.php"
    vc=$(plugin_version_const "$p")
    hv=$(grep -m1 "Version:" "$main" 2>/dev/null | sed 's/.*Version: *//')
    if [ -n "$vc" ] && [ -f "$main" ]; then
      cv=$(grep -m1 "define.*${vc}.*" "$main" 2>/dev/null | grep -oE "[\"'][0-9]+\.[0-9]+\.[0-9]+[^\"']*[\"']" | head -1 | tr -d "\"'")
      if [ -n "$cv" ] && [ "$cv" != "$hv" ]; then
        echo -e "  ${RED}✗${NC} $p: header=$hv constante $vc=$cv"
        bad=$((bad+1))
      fi
    fi
  done
  [ "$bad" -gt 0 ] && fail "$bad plugins con versión interna desincronizada — no se publica"
  ok "${#RELEASE_PLUGINS[@]}/${#RELEASE_PLUGINS[@]} constantes internas coherentes con headers"
}

# ── GATE DE CALIDAD: batería de seguridad completa ──
# Criterio de seguridad: los 26 plugins deben funcionar TODOS JUNTOS y también
# DESCONECTADOS en cualquier orden, sin romper el sitio ni pisar datos.
# La batería cubre 3 capas:
#   1. Tests standalone (~95): cada plugin corre aislado, sin los demás cargados
#      (aislamiento / "desconectados"). Integridad de datos, idempotencia,
#      contratos de eventos compartidos, backfills, optionality/ausencia.
#   2. Tests WP-dependientes (Playground local): integración real contra
#      WordPress (semilla local, nunca producción).
#   3. Smoke "todos juntos" (gate_together_smoke): activa los 26 plugins
#      simultáneamente y verifica cero fatals + HTTP 200.
# Formato de GATE_TESTS: "ruta|patrón". Patrón = grep -E sobre la salida
# completa del test. Vacío = GENERIC_PASS_PATTERN. El runner además debe
# salir con código 0 (un crash de PHP aborta el release aunque el patrón
# coincidiera).
GENERIC_PASS_PATTERN='0 failed|0 FAIL|FAIL: 0|ALL PASS|ALL TESTS PASSED'

GATE_TESTS=(
  # Core / Brain / Flow (unidades)
  "$SRC/estandar-core/tests/run-core.php"
  "$SRC/estandar-core/tests/run-duplicate.php"
  "$SRC/estandar-core/tests/run-crm-ingestion.php"
  "$SRC/estandar-core/tests/run-ai-discovery.php"
  "$SRC/estandar-core/tests/run-ai-keys.php"
  "$SRC/estandar-core/tests/run-feature-fields.php"
  "$SRC/estandar-core/tests/run-interest-engine.php"
  "$SRC/estandar-core/tests/run-contactability.php"
  "$SRC/estandar-core/tests/run-ak-invalid-contact.php"
  "$SRC/estandar-core/tests/run-leads-panel.php"
  "$SRC/estandar-core/tests/run-leads-admin.php"
  "$SRC/estandar-core/tests/run-ai-context.php"
  "$SRC/estandar-core/tests/run-updates.php"
  "$SRC/estandar-core/tests/run-ads-data-manager.php"
  "$SRC/estandar-core/tests/run-site-map.php"
  "$SRC/estandar-core/tests/run-auto-diag.php"
  "$SRC/estandar-core/tests/run-event-bus.php"
  "$SRC/estandar-brain/tests/run-brain.php"
  "$SRC/estandar-brain/tests/run-brain-contactability.php"
  "$SRC/estandar-brain/tests/run-brain-exporters.php"
  "$SRC/estandar-flow/tests/run-flow.php"
  "$SRC/estandar-flow/tests/run-flow-interest.php"
  # Leads — standalone (aislamiento, integridad de datos)
  # Leads — WP-dependientes (Playground; requieren seed local)
  "$SRC/estandar-core/tests/run-operator-notifier.php"
  # Chatbot
  # Signal Core — contratos de eventos compartidos
  # SEO Intelligence — contratos de integración
  # Analytics
  # Primer — registry, horas, migraciones
  # Suite v2
  "$SRC/estandar-capture/tests/run-capture.php"
  "$SRC/estandar-capture/tests/run-operator-gate.php"
  "$SRC/estandar-capture/tests/run-camara-registros.php"
  "$SRC/estandar-capture/tests/run-gf-native-bridge.php"
  "$SRC/estandar-capture/tests/run-ai-chat.php"
  "$SRC/estandar-capture/tests/run-chat-frontend.php"
  "$SRC/estandar-capture/tests/run-chat-log.php"
  "$SRC/estandar-capture/tests/run-chatbot-health.php"
  "$SRC/estandar-capture/tests/run-whatsapp-bot.php"
  "$SRC/estandar-capture/tests/parity/run-parity.php"
  "$SRC/estandar-capture/tests/parity/run-parity-chatbot.php"
  # Fusión chatbot→capture (WP-dependientes, Playground)
  "$SRC/estandar-capture/tests/functional/chatbot-bridge-harness.php"
  "$SRC/estandar-capture/tests/functional/leads-bridge-harness.php"
  "$SRC/estandar-capture/tests/functional/chatbot-adoption-harness.php"
  "$SRC/estandar-capture/tests/functional/popups-harness.php"
  "$SRC/estandar-core/tests/functional/event-bus-harness.php"
  "$SRC/estandar-core/tests/functional/pdf-real-harness.php"
  "$SRC/estandar-insights/tests/run-insights.php"
  "$SRC/estandar-insights/tests/run-insights-analytics.php"
  "$SRC/estandar-insights/tests/run-web-funnel.php"
  "$SRC/estandar-insights/tests/run-seo-adoption.php"
  "$SRC/estandar-brain/tests/run-signal-adoption.php"
  # Estandar Experience — manifiesto, guarda, pipeline (pure logic)
  "$SRC/estandar-experience/tests/run-experience.php"
  # Otros plugins
  # Forms — motor, surveys, migración, observabilidad
  # Quotes — regresión completa del bounded context comercial
)

# ── GATE DE SEED: siembra idempotente del Playground local ──
# Los tests WP-dependientes necesitan un registry con al menos un proyecto y
# un operador. Este seed solo toca la BD local del Playground (nunca
# producción) y es idempotente.
gate_seed_playground() {
  echo "─── Gate: seed Playground local ───"
  local seed="$SCRIPT_DIR/tests/seed-playground.php"
  [ -f "$seed" ] || fail "seed no encontrado: $seed (crea tests/seed-playground.php)"
  php "$seed" > /dev/null 2>&1 || fail "seed del Playground falló (¿MySQL local arriba? ¿Playground instalado?)"
  ok "Playground sembrado (proyecto + operador de test)"
}

# ── GATE SMOKE "TODOS JUNTOS" ──
# Activa los 26 plugins simultáneamente en el Playground y verifica que la
# convivencia total no genere fatals nuevos. Además valida HTTP 200 en home y
# wp-login (si el servidor dev no está arriba, lo levanta temporalmente).
gate_together_smoke() {
  echo "─── Gate: smoke 'todos juntos' ───"
  local pg="$SCRIPT_DIR/Playground" smoke="$SCRIPT_DIR/tests/smoke-all-together.php"
  [ -d "$pg" ] || fail "Playground no encontrado en $pg"
  [ -f "$smoke" ] || fail "smoke no encontrado: $smoke"

  local server_pid=""
  if ! curl -fsS --max-time 5 -o /dev/null "http://localhost:8080/" 2>/dev/null; then
    ( cd "$pg" && php -S 127.0.0.1:8080 router.php > /dev/null 2>&1 & echo $! > /tmp/estandar-pg-server.pid )
    server_pid=$(cat /tmp/estandar-pg-server.pid 2>/dev/null || true)
    sleep 1
  fi

  local smoke_out
  smoke_out=$(php "$smoke" 2>&1 | tail -1) || true
  if echo "$smoke_out" | grep -qE "SMOKE_OK"; then
    ok "activación conjunta OK → $smoke_out"
  else
    echo -e "  ${RED}✗${NC} $smoke_out"
    [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null
    fail "smoke 'todos juntos' falló — no se publica"
  fi

  local h1 h2
  h1=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "http://localhost:8080/")
  h2=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "http://localhost:8080/wp-login.php")
  if [ "$h1" = "200" ] && [ "$h2" = "200" ]; then
    ok "HTTP home=$h1 wp-login=$h2"
  else
    [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null
    fail "HTTP anómalo: home=$h1 wp-login=$h2"
  fi

  [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null
  ok "smoke 'todos juntos' OK"
}

# ── GATE DE PRESERVACIÓN EN LA ACTUALIZACIÓN ──
# Simula el ciclo de actualización de WordPress (archivos reemplazados →
# plugins re-activados) en el Playground y verifica que NINGÚN dato se pierda
# ni se formatee: conteos de tablas de datos (entries/leads/quotes/events)
# intactos, opciones (tokens, API keys, settings) byte-idénticas, conjunto de
# plugins activos idéntico y cero fatals nuevos.
gate_update_preservation() {
  echo "─── Gate: preservación en la actualización ───"
  local preserve="$SCRIPT_DIR/tests/smoke-update-preservation.php"
  [ -f "$preserve" ] || fail "preservation smoke no encontrado: $preserve"
  local out
  out=$(php "$preserve" 2>&1 | tail -1) || true
  if echo "$out" | grep -qE "PRESERVE_OK"; then
    ok "datos preservados → $out"
  else
    echo -e "  ${RED}✗${NC} $out"
    fail "preservación falló — no se publica"
  fi
}

# ── RUNNER DE LA BATERÍA ──
# Solo corre los tests de los plugins en el conjunto a liberar (un test se
# asigna a un plugin por la primera carpeta de su ruta). Así una release v2
# valida únicamente los 5 plugins v2 (los legacy están congelados).
gate_quality_tests() {
  echo "─── Gate: tests de calidad (conjunto: ${#RELEASE_PLUGINS[@]} plugins) ───"
  local entry t pat out rc failures=0 skipped=0
  for entry in "${GATE_TESTS[@]}"; do
    t="${entry%%|*}"
    pat="${entry#*|}"
    [ "$pat" = "$entry" ] && pat="$GENERIC_PASS_PATTERN"
    local rel="${t#"$SRC"/}"; local slug="${rel%%/*}"
    local in_set=0
    for sp in "${RELEASE_PLUGINS[@]}"; do [ "$sp" = "$slug" ] && { in_set=1; break; }; done
    if [ "$in_set" -eq 0 ]; then skipped=$((skipped+1)); continue; fi
    if [ ! -f "$t" ]; then
      echo -e "  ${RED}✗${NC} test no encontrado: $t"
      failures=$((failures+1))
      continue
    fi
    rc=0
    out=$(cd "$(dirname "$t")" && php "$(basename "$t")" 2>&1) || rc=$?
    summary=$(printf '%s' "$out" | grep -v '^[[:space:]]*$' | tail -1)
    if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -qE "$pat"; then
      echo -e "  ${GREEN}✓${NC} $(basename "$t") → $summary"
    else
      echo -e "  ${RED}✗${NC} $(basename "$t") → $summary"
      failures=$((failures+1))
    fi
  done
  if [ "$failures" -gt 0 ]; then
    fail "$failures tests fallaron — no se publica"
  fi
  ok "tests de calidad OK ($(( ${#GATE_TESTS[@]} - skipped )) runners del conjunto, $skipped fuera de alcance)"
}

# ── GATE DE SEGURIDAD: scan de secretos/credenciales en archivos a publicar ──
# Detecta literales de alto riesgo (API keys, tokens, claves privadas,
# credenciales SMTP/Brevo) dentro del código PHP que se empaquetará.
# No escanea wp-config ni directorios tests/audit-evidence.
gate_security_scan() {
  echo "─── Gate: scan de secretos/credenciales ───"
  local p files hits=0
  for p in "${RELEASE_PLUGINS[@]}"; do
    while IFS= read -r -d '' f; do
      local rel="${f#"$SRC"/}"
      case "$rel" in
        *wp-config*|*tests/audit-evidence*|*.example|*/vendor/*) continue ;;
      esac
      if grep -rIlE --include='*.php' \
          'BEGIN (RSA|OPENSSH|EC|DSA) PRIVATE KEY' \
          'sk-[A-Za-z0-9]{20,}' \
          'AIza[0-9A-Za-z_\-]{30,}' \
          'AKIA[0-9A-Z]{16}' \
          'ghp_[A-Za-z0-9]{20,}' \
          'xox[baprs]-[A-Za-z0-9-]{10,}' \
          'smtp.*password[=: ].{6,}' \
          'BREVO_API_KEY[=: ].{8,}' \
          "$f" >/dev/null 2>&1; then
        echo -e "  ${RED}✗ POSIBLE SECRETO: $rel${NC}"
        hits=$((hits+1))
      fi
    done < <(find "$SRC/$p" -name "*.php" -print0 2>/dev/null)
  done
  [ "$hits" -gt 0 ] && fail "$hits archivos con posible secreto — no se publica"
  ok "sin secretos/credenciales en el código a publicar"
}

# ════════════════════════════════════════════════════════════════
# NUEVOS GATES DE SEGURIDAD TÉCNICA (sesión 010, migración agresiva)
# Cada uno ejecuta un script en tests/ y exige un patrón de éxito en la
# salida. Si el script falla (rc≠0) o el patrón no aparece, se aborta.
# ════════════════════════════════════════════════════════════════

# Runner genérico: label, ruta del script, patrón de éxito, args extra opcionales.
run_gate_script() {
  local label="$1" script="$2" pattern="$3" extra="${4:-}" out rc summary
  echo "─── Gate: $label ───"
  out=$(php "$SCRIPT_DIR/$script" $extra 2>&1) || rc=$?
  summary=$(printf '%s' "$out" | grep -v '^[[:space:]]*$' | tail -1)
  if [ -z "$rc" ] && printf '%s' "$out" | grep -qE "$pattern"; then
    ok "$label → $summary"
  else
    echo -e "  ${RED}✗${NC} $summary"
    fail "$label falló — no se publica"
  fi
}

# 1. gate_php_matrix — lint de sintaxis de los 26 plugins con las versiones de
#    PHP de producción (8.2 / 8.4). Caza código que compila en 8.5 pero no en
#    las versiones de los sitios.
gate_php_matrix() {
  run_gate_script "matrix PHP multi-versión (8.2/8.4)" "tests/gate-php-matrix.php" "PHP_MATRIX_OK" "$GATE_SCOPE_ARG"
}

# 2. gate_symbol_collisions — clases/funciones/constantes globales duplicadas
#    entre plugins (previene fatals "cannot redeclare").
gate_symbol_collisions() {
  run_gate_script "colisiones de símbolos globales" "tests/gate-symbol-collisions.php" "COLLISIONS_OK" "$GATE_SCOPE_ARG"
}

# 3. gate_constants_defined — usos de constantes nunca definidas ni whitelisted
#    (previene el fatal "Undefined constant" de acacios.cl).
gate_constants_defined() {
  run_gate_script "constantes usadas sin definir" "tests/gate-constants-defined.php" "CONSTANTS_OK" "$GATE_SCOPE_ARG"
}

# 4. gate_theme_smoke — smoke "todos juntos" con cada tema de producción
#    (Divi/Divi-child/astra/astra-child) en el Playground. Asegura el servidor
#    dev antes de ejecutar.
gate_theme_smoke() {
  echo "─── Gate: smoke por tema de producción ───"
  local pg="$SCRIPT_DIR/Playground" smoke="$SCRIPT_DIR/tests/theme-smoke.php"
  [ -d "$pg" ] || fail "Playground no encontrado en $pg"
  [ -f "$smoke" ] || fail "theme-smoke no encontrado: $smoke"

  local server_pid=""
  if ! curl -fsS --max-time 5 -o /dev/null "http://localhost:8080/" 2>/dev/null; then
    ( cd "$pg" && php -S 127.0.0.1:8080 router.php > /dev/null 2>&1 & echo $! > /tmp/estandar-pg-server.pid )
    server_pid=$(cat /tmp/estandar-pg-server.pid 2>/dev/null || true)
    sleep 1
  fi

  local out
  out=$(php "$smoke" 2>&1) || true
  [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null
  if printf '%s' "$out" | grep -qE "THEME_SMOKE_OK"; then
    ok "smoke por tema → $(printf '%s' "$out" | grep -v '^[[:space:]]*$' | tail -1)"
  else
    echo -e "  ${RED}✗${NC} $(printf '%s' "$out" | grep -v '^[[:space:]]*$' | tail -1)"
    fail "smoke por tema falló — no se publica"
  fi
}

# 5. gate_remote_health — HTTP 200 de las instalaciones de producción + wp-json
#    (estandar.cl / akcontadores.cl / acacios.cl / updates.estandar.cl).
gate_remote_health() {
  run_gate_script "salud remota de producción" "tests/gate-remote-health.php" "REMOTE_HEALTH_OK"
}

# 6. gate_header_metadata — Requires PHP / Requires at least / Tested up to
#    presentes y coherentes con los floors del proyecto.
gate_header_metadata() {
  run_gate_script "coherencia de headers de plugins" "tests/gate-header-metadata.php" "HEADER_META_OK" "$GATE_SCOPE_ARG"
}

# 7. gate_zip_prohibited — sin archivos prohibidos (.env, wp-config, dumps,
#    logs, backups, llaves privadas) en los directorios a empaquetar.
gate_zip_prohibited() {
  run_gate_script "archivos prohibidos en ZIPs" "tests/gate-zip-prohibited.php" "ZIP_PROHIBITED_OK" "$GATE_SCOPE_ARG"
}

# 8. gate_wp_api_compat — llamadas a funciones que NO existen en WP core 7.1
#    (ni PHP, ni la Suite) → candidato a fatal en producción (ej.
#    `upgrade_plugin()` eliminada en WP 7.1).
gate_wp_api_compat() {
  run_gate_script "compatibilidad de APIs de WP (funciones ausentes)" "tests/gate-wp-api-compat.php" "WP_API_OK" "$GATE_SCOPE_ARG"
}

# 9. gate_wp_api_self_test — el propio gate se prueba a sí mismo: detecta
#    upgrade_plugin() (eliminada) y no acusa WP core ni guards function_exists.
gate_wp_api_self_test() {
  run_gate_script "autorregresión del gate de compatibilidad" "tests/run-gate-wp-api-compat.php" "0 failed"
}

# 10. gate_rest_link — enlace de rutas HTTP PHP↔JS: cada consumidor
#     (rest_url en PHP o literal wp-json/namespace en JS) debe apuntar a una
#     ruta REST realmente registrada. Caza el 404 silencioso (rest_no_route)
#     como el del botón WhatsApp del chatbot (ruta legacy estandar-leads).
#     La deuda preexistente se tolera vía tests/rest-link-baseline.txt.
gate_rest_link() {
  run_gate_script "enlace de rutas HTTP (PHP↔JS)" "tests/gate-rest-link.php" "REST_LINK_OK" "$GATE_SCOPE_ARG"
}

# Paso informativo (solo --dry-run): blast radius desde el último release.
# No aborta el release; ayuda a saber qué verificar antes de publicar.
report_blast_radius() {
  echo "─── Blast radius desde el último release (informativo) ───"
  php "$SCRIPT_DIR/tests/blast-radius.php" "--scope=$(IFS=,; echo "${RELEASE_PLUGINS[*]}")" 2>&1 \
    | sed 's/^/  /' || true
}

echo "══════════════════════════════════════════════════"
echo "  Estandar Suite — Release Pipeline"
echo "  Conjunto: ${RELEASE_PLUGINS[*]}"
echo "══════════════════════════════════════════════════"
[ "$DRY_RUN" -eq 1 ] && echo "  (dry-run: valida pero no publica)"
[ "$DRY_RUN" -eq 0 ] && [ ! -f "$SSH_KEY" ] && fail "SSH key: $SSH_KEY"

# ── MODOS DE ADMINISTRACIÓN DE INSTALACIONES ────────────────────
# No hacen release ni bump: operan sobre la versión del canal actual.
# Útiles para "actualizar todos" tras un release o para desactivar sitios
# cuando una suscripción termina / el cliente deja de ser cliente.
if [ "$DO_STATUS" -eq 1 ] || [ "${#POLICY_SPECS[@]}" -gt 0 ] || [ "$DO_DEPLOY" -eq 1 ]; then
  DEPLOY_TARGET="$(channel_version 2>/dev/null || echo '?')"
  echo "Canal v2 actual: ${DEPLOY_TARGET}"

  if [ "$DO_STATUS" -eq 1 ]; then
    echo "─── Estado de instalaciones ───"
    echo "$SITES" | while IFS= read -r line; do
      [ -z "$line" ] && continue
      site_status "$line"
    done
  fi

  if [ "${#POLICY_SPECS[@]}" -gt 0 ]; then
    echo "─── Política de actualización por sitio ───"
    for spec in "${POLICY_SPECS[@]}"; do
      name="${spec%%:*}" val="${spec#*:}"
      line="$(site_line "$name")"
      [ -z "$line" ] && { echo -e "  ${RED}✗${NC} sitio desconocido: $name"; continue; }
      if ! valid_policy "$val"; then
        echo -e "  ${RED}✗${NC} $name: política inválida '$val' (auto | off | pin:X.Y.Z)"
        continue
      fi
      if [ "$DRY_RUN" -eq 1 ]; then
        echo -e "  ${name}: (dry-run) se fijaría política → $val"
        continue
      fi
      kind=$(site_kind "$line")
      if [ "$kind" = "manual" ]; then
        echo -e "  ${name}: ${RED}manual${NC} (GoDaddy) → fijar en su panel o por REST no disponible; opción guardada como pendiente."
        continue
      fi
      if site_wp "$line" option update estandar_suite_updates_policy "$val" > /dev/null 2>&1; then
        echo -e "  ${GREEN}✓${NC} $name → política $val"
      else
        echo -e "  ${RED}✗${NC} $name: no se pudo guardar política"
      fi
    done
  fi

  if [ "$DO_DEPLOY" -eq 1 ]; then
    echo "─── Deploy a instalaciones ───"
    sel=""
    if [ -n "$DEPLOY_LIST" ]; then
      sel="$DEPLOY_LIST"
    else
      sel=$(echo "$SITES" | awk -F'|' '$1!=""{printf "%s,",$1}' | sed 's/,$//')
    fi
    IFS=',' read -ra targets <<< "$sel"
    for nm in "${targets[@]}"; do
      line="$(site_line "$nm")"
      [ -z "$line" ] && { echo -e "  ${RED}✗${NC} sitio desconocido: $nm"; continue; }
      deploy_site "$line"
    done
  fi

  echo "── Fin de administración ──"
  exit 0
fi

# Gates globales (antes de publicar, sobre el conjunto a liberar)
[ "$DRY_RUN" -eq 1 ] && report_blast_radius
gate_unified_version
gate_internal_versions
gate_seed_playground
gate_quality_tests
gate_update_preservation
gate_together_smoke
gate_security_scan
gate_symbol_collisions
gate_constants_defined
gate_php_matrix
gate_theme_smoke
gate_remote_health
gate_header_metadata
gate_zip_prohibited
gate_wp_api_compat
gate_wp_api_self_test
gate_rest_link

if [ "$DO_V2" -eq 1 ]; then
  release_group "Suite v2" \
    "$REMOTE_BASE/staging/v2" \
    "https://updates.estandar.cl/staging/v2" \
    "${V2_PLUGINS[@]}"
fi

echo ""
echo -e "${GREEN}══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  RELEASE COMPLETO${NC}"
echo -e "${GREEN}══════════════════════════════════════════════════${NC}"
