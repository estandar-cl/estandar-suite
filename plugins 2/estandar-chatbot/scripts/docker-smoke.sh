#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "[1/8] Levantando Docker..."
docker compose up -d >/dev/null

echo "[2/8] Verificando WordPress..."
if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  docker compose run --rm wpcli core install \
    --url=http://localhost:18080 \
    --title="ECB Test" \
    --admin_user=admin \
    --admin_password=admin123 \
    --admin_email=admin@example.com \
    --skip-email >/dev/null
fi

echo "[3/8] Activando plugin..."
if ! docker compose run --rm wpcli plugin is-active estandar-chatbot >/dev/null 2>&1; then
  docker compose run --rm wpcli plugin activate estandar-chatbot >/dev/null
fi

echo "[4/8] Validando pagina auto-creada..."
PAGE_OUT="$(docker compose run --rm -T wpcli eval '
$id = (int) get_option("estandar_chatbot_inline_page_id", 0);
echo "PAGE_ID=" . $id . "\n";
if ( $id > 0 ) {
  echo "PAGE_STATUS=" . get_post_status( $id ) . "\n";
  echo "PAGE_HAS_SHORTCODE=" . ( strpos( (string) get_post_field( "post_content", $id ), "[estandar_chatbot" ) !== false ? "1" : "0" ) . "\n";
}
')"

echo "$PAGE_OUT" | grep -Eq 'PAGE_ID=[1-9][0-9]*'
echo "$PAGE_OUT" | grep -q 'PAGE_STATUS=publish'
echo "$PAGE_OUT" | grep -q 'PAGE_HAS_SHORTCODE=1'

echo "[5/8] Probando endpoint save_lead..."
NONCE="$(docker compose run --rm -T wpcli eval "echo wp_create_nonce('estandar_chatbot_nonce');" | tail -n 1 | tr -d '\r')"
RESP="$(docker compose exec -T wordpress curl -s -X POST 'http://localhost/wp-admin/admin-ajax.php' \
  --data-urlencode 'action=estandar_chatbot_save_lead' \
  --data-urlencode "nonce=${NONCE}" \
  --data-urlencode 'session_id=smoke_session_1' \
  --data-urlencode 'channel=web' \
  --data-urlencode 'lead[email]=smoke@example.com' \
  --data-urlencode 'lead[phone]=+56 9 4444 3333' \
  --data-urlencode 'lead[name]=Smoke Lead')"

echo "$RESP" | grep -q '"success":true'

echo "[6/8] Probando persistencia de conversacion y eventos..."
CONV_OUT="$(docker compose run --rm -T wpcli eval '
estandar_chatbot_append_conversation_message("smoke_session_1", "web", "user", "Hola");
estandar_chatbot_append_conversation_message("smoke_session_1", "web", "assistant", "Hola, en que te ayudo");
estandar_chatbot_log_event([
  "event_name" => "smoke.event",
  "channel" => "web",
  "session_key" => "smoke_session_1",
  "meta" => [ "ok" => 1 ]
]);
$messages = estandar_chatbot_get_conversation_messages("smoke_session_1", "web", 10);
echo "CONV_COUNT=" . count($messages) . "\n";
echo "EVENTS_COUNT=" . (int) estandar_chatbot_count_events() . "\n";
')"

CONV_COUNT="$(printf '%s\n' "$CONV_OUT" | awk -F= '/^CONV_COUNT=/{print $2}' | tail -n 1)"
test "${CONV_COUNT:-0}" -ge 2
echo "$CONV_OUT" | grep -Eq 'EVENTS_COUNT=[1-9][0-9]*'

echo "[7/8] Probando paginación logs..."
PAGE_OUT="$(docker compose run --rm -T wpcli eval '
for($i=0;$i<6;$i++){
  estandar_chatbot_log_interaction([
    "question"=>"PAGE_TEST_".$i,
    "answer"=>"ok",
    "ip"=>"127.0.0.1",
    "ua"=>"smoke",
    "url"=>"http://localhost:18080/"
  ]);
}
$a = estandar_chatbot_get_logs(2,0);
$b = estandar_chatbot_get_logs(2,2);
echo "PAGE_A=" . count($a) . "\n";
echo "PAGE_B=" . count($b) . "\n";
echo "DIFF=" . (((string)($a[0]["question"] ?? "")) !== ((string)($b[0]["question"] ?? "")) ? "1" : "0") . "\n";
')"

echo "$PAGE_OUT" | grep -q 'PAGE_A=2'
echo "$PAGE_OUT" | grep -q 'PAGE_B=2'
echo "$PAGE_OUT" | grep -q 'DIFF=1'

echo "[8/8] Probando retención de logs..."
RET_OUT="$(docker compose run --rm -T wpcli eval '
update_option("chatbot_logs_retention_days", 1);
global $wpdb;
$table = $wpdb->prefix . "ecb_logs";
$wpdb->insert($table, [
  "datetime"   => gmdate("Y-m-d H:i:s", time() - 20 * DAY_IN_SECONDS),
  "question"   => "OLD_LOG_TEST",
  "answer"     => "old",
  "ip"         => "127.0.0.1",
  "user_agent" => "smoke",
  "url"        => "http://localhost:18080/"
], ["%s","%s","%s","%s","%s","%s"]);
$deleted = estandar_chatbot_purge_old_logs();
echo "DELETED=" . (int)$deleted . "\n";
')"

echo "$RET_OUT" | grep -Eq 'DELETED=[1-9][0-9]*'

echo "SMOKE_OK"
