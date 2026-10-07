#!/usr/bin/env bash
# ESTANDAR SUITE v2 — Capture · Tests funcionales del editor por subtabs (maquetador).
#
# Requiere un WordPress local con la suite instalada (estandar-core, estandar-capture,
# estandar-flow activos) y WP-CLI. Rutas configurables por variables de entorno:
#   ESTANDAR_TEST_WP   ruta del WP (default /tmp/estandar-subtabs)
#   ESTANDAR_TEST_ADMIN_ID  user id admin (default 1)
#
# Cubre (requisito 13): render de cada subtab, subtab por defecto, parámetro
# inválido, persistencia al guardar, permisos y nonces, redirects y compatibilidad
# con formularios existentes. Lo puro (registro/resolución) está en run-capture.php.
set -uo pipefail

WP="${ESTANDAR_TEST_WP:-/tmp/estandar-subtabs}"
ADMIN_ID="${ESTANDAR_TEST_ADMIN_ID:-1}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS="$HERE/functional/save-form-harness.php"
PASS=0; FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "FAIL  $1"; FAIL=$((FAIL+1)); }

wpq() { php -d memory_limit=1024M /opt/homebrew/bin/wp "$@" --path="$WP" 2>/dev/null; }

[ -f "$WP/wp-load.php" ] || { echo "SKIP  (no WP en $WP — define ESTANDAR_TEST_WP)"; exit 0; }

# ── setup: un formulario conocido + capabilities lead ─────────────────────
FORM_ID=$(wpq eval 'echo \Estandar\Capture\Forms::create(array("name"=>"Subtab Test","form_type"=>"lead","form_context"=>"generic","status"=>"active","config_json"=>json_encode(array("sections"=>array(array("title"=>"Contenido","fields"=>array(array("name"=>"nombre","label"=>"Nombre","type"=>"text","required"=>1))))))));' | tail -1)
[ -n "$FORM_ID" ] && [ "$FORM_ID" -gt 0 ] 2>/dev/null || { echo "SKIP  (no se pudo crear form de prueba)"; exit 0; }
wpq eval '\Estandar\Capture\Forms::set_capability("lead","webhook",true);\Estandar\Capture\Forms::set_capability("lead","notify_email",true);\Estandar\Capture\Forms::set_capability("lead","recaptcha",true);' >/dev/null

render_maq() { # st → html
  php "$HERE/functional/render-maq-harness.php" "$WP" "$ADMIN_ID" estandar-suite-capture config forms formularios "$FORM_ID" "$1" 2>/dev/null
}

# ── 1) render por subtab: solo el panel activo visible ─────────────────────
visible_panels() { # html → lista de paneles visibles
  python3 -c "
import re,sys
h=sys.stdin.read()
print(','.join(m.group(1) for m in re.finditer(r'<div class=\"es-st-panel\" data-panel=\"([a-z]+)\"([^>]*)>', h) if 'display:none' not in m.group(2)))
"
}
for st in diseno configuracion correo entradas; do
  h=$(render_maq "$st")
  v=$(printf '%s' "$h" | visible_panels)
  [ "$v" = "$st" ] && pass "render subtab $st → solo panel $st visible" || fail "render subtab $st → visible=$v"
done

# ── 2) subtab por defecto (sin st) ─────────────────────────────────────────
v=$(render_maq "" | visible_panels)
[ "$v" = "diseno" ] && pass "subtab por defecto (sin st) → diseno" || fail "subtab por defecto → visible=$v"

# ── 3) parámetro inválido → fallback a diseno ──────────────────────────────
v=$(render_maq "zzz" | visible_panels)
[ "$v" = "diseno" ] && pass "st inválido → fallback a diseno" || fail "st inválido → visible=$v"

# ── 3b) deep-links antiguos → alias a configuracion ────────────────────────
for old in general repeticion; do
  v=$(render_maq "$old" | visible_panels)
  [ "$v" = "configuracion" ] && pass "deep-link st=$old → alias configuracion" || fail "deep-link st=$old → visible=$v"
done

# ── 3c) breadcrumb ← Volver a Formularios oculto ───────────────────────────
h=$(render_maq "diseno")
printf '%s' "$h" | grep -q "Volver a Formularios" && fail "breadcrumb visible" || pass "breadcrumb ← Volver a Formularios oculto"

# ── 3d) Configuración contiene Identidad/Comportamiento/Información técnica ─
h=$(render_maq "configuracion")
printf '%s' "$h" | grep -q "Identidad" && printf '%s' "$h" | grep -q "Comportamiento" && printf '%s' "$h" | grep -q "Información técnica" \
  && pass "Configuración: Identidad + Comportamiento + Información técnica" || fail "Configuración incompleta"

# ── 3e) selector de tipo canónico presente ─────────────────────────────────
h=$(render_maq "configuracion")
printf '%s' "$h" | grep -q 'name="form_type"' && printf '%s' "$h" | grep -q 'value="survey"' && printf '%s' "$h" | grep -q 'value="incident"' \
  && pass "selector de tipo (lead/survey/incident)" || fail "selector de tipo ausente"

# ── 3f) conversión de tipo no destructiva + rollback ───────────────────────
TC=$(wpq eval '
$f=\Estandar\Capture\Forms::get('"$FORM_ID"');
$cfg=json_decode($f["config_json"],true);
$cfg["sections"]=array(array("title"=>"A","fields"=>array(array("name"=>"x","label"=>"X","type"=>"text"))));
$raw=array("name"=>$f["name"],"form_type"=>"survey","form_context"=>"generic","status"=>"active","config_json"=>json_encode($cfg));
$r=\Estandar\Capture\Forms::convert_type('"$FORM_ID"',"survey",$raw);
$c=json_decode(\Estandar\Capture\Forms::get('"$FORM_ID"')["config_json"],true);
$r2=array("ok"=>$r["ok"],"type"=>\Estandar\Capture\Forms::get('"$FORM_ID"')["form_type"],"sections"=>count($c["sections"]??[]));
echo json_encode($r2);
' | tail -1)
echo "$TC" | grep -q '"ok":true' && echo "$TC" | grep -q '"type":"survey"' && echo "$TC" | grep -q '"sections":1' \
  && pass "conversión lead→survey: tipo + secciones preservadas" || fail "conversión: $TC"
TC2=$(wpq eval '
$f=\Estandar\Capture\Forms::get('"$FORM_ID"');
$raw=array("name"=>$f["name"],"form_type"=>"lead","form_context"=>"generic","status"=>"active","config_json"=>$f["config_json"]);
$r=\Estandar\Capture\Forms::convert_type('"$FORM_ID"',"lead",$raw);
echo json_encode(array("ok"=>$r["ok"],"type"=>\Estandar\Capture\Forms::get('"$FORM_ID"')["form_type"]));
' | tail -1)
echo "$TC2" | grep -q '"ok":true' && echo "$TC2" | grep -q '"type":"lead"' \
  && pass "rollback survey→lead OK" || fail "rollback: $TC2"

# ── 4) nav con subtab activa correcta ──────────────────────────────────────
h=$(render_maq "correo")
printf '%s' "$h" | grep -qE 'class="nav-tab nav-tab-active"[^>]*data-st="correo"' \
  && pass "nav activa = correo" || fail "nav activa incorrecta"

# ── 5) persistencia al guardar (nonce válido, desde subtab correo) ─────────
R=$(wpq eval-file "$HARNESS" -- persist "$FORM_ID" "Subtab Test v2" "{\"sections\":[{\"title\":\"Nuevo\",\"fields\":[]}]}" | grep RESULT | tail -1)
echo "$R" | grep -q '"ok":true' && pass "persistencia: guardado con nonce válido" || fail "persistencia: $R"
echo "$R" | grep -q '"redirect".*form_saved=1' && pass "redirect post-guardado → form_saved=1 (compat)" || fail "redirect: $R"

# ── 6) nonce inválido → rechazado, sin cambios ─────────────────────────────
BEFORE=$(wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["name"];')
R=$(wpq eval-file "$HARNESS" -- nonce "$FORM_ID" "NoDebeGuardar" "{}" | grep RESULT | tail -1)
echo "$R" | grep -q '"die_msg"' && echo "$R" | grep -qv '"ok":true' && pass "nonce inválido rechazado (sin save)" || fail "nonce: $R"
AFTER=$(wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["name"];')
[ "$BEFORE" = "$AFTER" ] && pass "nonce inválido no alteró datos" || fail "nonce alteró datos: $BEFORE → $AFTER"

# ── 7) sin permisos (subscriber) → rechazado ───────────────────────────────
BEFORE=$(wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["name"];')
R=$(wpq eval-file "$HARNESS" -- persist "$FORM_ID" "NoPermiso" "{}" subscriber | grep RESULT | tail -1)
echo "$R" | grep -q '"die_msg":"Sin permisos.' && pass "sin permisos → 'Sin permisos.'" || fail "caps: $R"
AFTER=$(wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["name"];')
[ "$BEFORE" = "$AFTER" ] && pass "sin permisos no alteró datos" || fail "caps alteró datos"

# ── 8) merge no destructivo: guardado con config_json vacío NO borra ────────
wpq eval '\Estandar\Capture\Forms::update('"$FORM_ID"', array("name"=>"Subtab Test","form_type"=>"lead","form_context"=>"generic","status"=>"active","config_json"=>json_encode(array("sections"=>array(array("title"=>"Contenido","fields"=>array(array("name"=>"nombre","label"=>"Nombre","type"=>"text","required"=>1)))),"mail_body"=>"Cuerpo {{fields}}"))));' >/dev/null
wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["config_json"];' > /tmp/es_cfg_before.json 2>/dev/null
R=$(wpq eval-file "$HARNESS" -- persist "$FORM_ID" "Subtab Test" "{}" | grep RESULT | tail -1)
wpq eval 'echo \Estandar\Capture\Forms::get('"$FORM_ID"')["config_json"];' > /tmp/es_cfg_after.json 2>/dev/null
BEFORE_LEN=$(wc -c < /tmp/es_cfg_before.json | tr -d ' ')
AFTER_LEN=$(wc -c < /tmp/es_cfg_after.json | tr -d ' ')
[ "$AFTER_LEN" -ge "$BEFORE_LEN" ] && pass "merge: guardado con {} no destruye config (${BEFORE_LEN}->${AFTER_LEN})" || fail "merge: config destruida (${BEFORE_LEN}->${AFTER_LEN})"

# ── 9) política repeat_visibility por formulario (default always_visible) ──
RV=$(wpq eval '$f=\Estandar\Capture\Forms::get('"$FORM_ID"'); $c=json_decode($f["config_json"],true); echo \Estandar\Capture\Forms::repeat_visibility($c)["mode"];' | tail -1)
[ "$RV" = "always_visible" ] && pass "default always_visible" || fail "default=$RV"

# ── cleanup del form de prueba ─────────────────────────────────────────────
wpq eval '\Estandar\Capture\Forms::delete('"$FORM_ID"');' >/dev/null 2>&1

echo ""
echo "SUBTABS FUNCTIONAL: $PASS pass, $FAIL fail"
[ "$FAIL" -eq 0 ] || exit 1
