#!/usr/bin/env bash
# ESTANDAR SUITE v2 — Capture · Regresión Forms (macro-sprint diagnóstico).
#
# Reproduce el flujo completo del caso form 2:
#   legacy (estandar_forms) → adopción v2 → render frontend+backend → guardado
#   parcial que escribe {} → frontend solo botón / backend Diseño vacío →
#   diagnóstico (inspect/validate) → recuperación no destructiva (restore) →
#   render completo de nuevo.
#
# Requiere WP local (ESTANDAR_TEST_WP) con estandar-core/capture/flow activos.
set -uo pipefail

WP="${ESTANDAR_TEST_WP:-/tmp/estandar-subtabs}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS="$HERE/functional/save-form-harness.php"
PASS=0; FAIL=0
pass() { echo "PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "FAIL  $1"; FAIL=$((FAIL+1)); }
wpq() { php -d memory_limit=1024M /opt/homebrew/bin/wp "$@" --path="$WP" 2>/dev/null; }

[ -f "$WP/wp-load.php" ] || { echo "SKIP  (no WP en $WP)"; exit 0; }

# ── setup: form legacy id 90 + migración → v2 id 90 ───────────────────────
wpq eval '
global $wpdb;
$t = $wpdb->prefix."estandar_forms";
$wpdb->query("DROP TABLE IF EXISTS $t");
$wpdb->query("CREATE TABLE $t (id BIGINT(20) NOT NULL AUTO_INCREMENT, name varchar(200), slug varchar(200), success_message text, submit_label varchar(200), after_submit_text text, notify_emails varchar(400), form_country varchar(40), store_entries tinyint(1) DEFAULT 1, send_email tinyint(1) DEFAULT 0, send_webhook tinyint(1) DEFAULT 0, require_recaptcha tinyint(1) DEFAULT 0, mail_subject varchar(300), mail_body text, mail_reply_to_field varchar(120), fields_json longtext, webhook_url varchar(400), created_at datetime, form_type varchar(60), form_context varchar(60), request_type varchar(60), context_schema longtext, actions_json longtext, incidents_config longtext, visual_theme varchar(60), webhook_payload_mode varchar(40), mail_sender_name varchar(200), survey_config longtext, experience_config_json longtext, PRIMARY KEY (id))");
$fields = json_encode(array(array("type"=>"text","name"=>"nombre","label"=>"Nombre Apellido","required"=>true),array("type"=>"email","name"=>"mail","label"=>"Mail","required"=>true),array("type"=>"textarea","name"=>"mensaje","label"=>"Mensaje","required"=>false)), JSON_UNESCAPED_UNICODE);
$wpdb->insert($t, array("id"=>90,"name"=>"Form Legado","fields_json"=>$fields,"form_type"=>"general","form_context"=>"generic","created_at"=>gmdate("Y-m-d H:i:s")));
echo "LEGACY_90\n";
' >/dev/null
wpq eval '\Estandar\Capture\Forms::migrate_legacy(false, 90); echo "MIG\n";' >/dev/null

# ── 1) render inicial: frontend con campos + backend Diseño con secciones ──
H=$(wpq eval 'echo do_shortcode("[estandar_form_v2 id=\"90\"]");')
echo "$H" | grep -q "Nombre Apellido" && echo "$H" | grep -q "estandar-form-v2-submit" \
  && pass "render inicial: campos + botón" || fail "render inicial sin campos"
HB=$(php "$HERE/functional/render-maq-harness.php" "$WP" 1 estandar-suite-capture config forms formularios 90 diseno 2>/dev/null)
echo "$HB" | grep -q "Nombre Apellido" && pass "backend Diseño inicial: secciones visibles" || fail "backend Diseño inicial vacío"

# ── 2) PROTOCOLO DE BLOQUEO: update sin allow_reset NO puede degradar a {} ──
H=$(wpq eval '$ok=\Estandar\Capture\Forms::update(90, array("name"=>"Form Legado","form_type"=>"lead","form_context"=>"generic","status"=>"active","config_json"=>"")); echo ($ok?"ESCRIBIO":"BLOQUEADO");')
echo "$H" | grep -q "BLOQUEADO" && pass "guard: update sin allow_reset bloqueado (no degrada a {})" || fail "guard no bloqueó: $H"
H=$(wpq eval '$f=\Estandar\Capture\Forms::get(90); echo (strpos($f["config_json"],"Nombre Apellido")!==false?"conservado":"perdido");')
echo "$H" | grep -q "conservado" && pass "guard: config conservada tras intento bloqueado" || fail "guard perdió config"

# ── 3) reproducción histórica del daño (allow_reset explícito) ─────────────
wpq eval '\Estandar\Capture\Forms::update(90, array("name"=>"Form Legado","form_type"=>"lead","form_context"=>"generic","status"=>"active","config_json"=>""), true); echo "WIPE\n";' >/dev/null
H=$(wpq eval '$h=do_shortcode("[estandar_form_v2 id=\"90\"]"); echo (strpos($h,"Nombre Apellido")!==false?"campos":"solo_boton")."|".(strpos($h,"estandar-form-v2-submit")!==false?"boton":"nobot");')
echo "$H" | grep -q "^solo_boton|boton" && pass "regresión histórica reproducida (frontend solo botón)" || fail "regresión no reproducida: $H"

# ── 4) diagnóstico CLI: inspect detecta config vacía; validate falla ───────
I=$(wpq estandar-suite capture form inspect 90 2>/dev/null | grep "has_fields=" )
echo "$I" | grep -q "has_fields=no" && pass "inspect detecta config vacía" || fail "inspect: $I"
wpq estandar-suite capture form validate 90 >/dev/null 2>&1 && fail "validate debió fallar" || pass "validate reporta documento inválido"

# ── 5) recuperación no destructiva (restore) ───────────────────────────────
wpq estandar-suite capture form restore 90 --confirm=yes >/dev/null 2>&1
V=$(wpq estandar-suite capture form validate 90 2>/dev/null; echo "rc=$?")
echo "$V" | grep -q "rc=0" && pass "restore: documento válido de nuevo" || fail "restore no recuperó: $V"
H=$(wpq eval 'echo do_shortcode("[estandar_form_v2 id=\"90\"]");')
echo "$H" | grep -q "Nombre Apellido" && pass "render post-restore: campos de nuevo" || fail "render post-restore sin campos"

# ── cleanup ────────────────────────────────────────────────────────────────
wpq eval '\Estandar\Capture\Forms::delete(90);' >/dev/null 2>&1

echo ""
echo "REGRESSION FORMS: $PASS pass, $FAIL fail"
[ "$FAIL" -eq 0 ] || exit 1
