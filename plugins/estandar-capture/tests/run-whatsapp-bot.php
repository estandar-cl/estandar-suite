<?php
/**
 * ESTANDAR SUITE v2 — Capture WhatsAppBot tests.
 *
 *   php tests/run-whatsapp-bot.php   → expect "0 failed"
 *
 * Covers: settings + legacy fallback, save (secret keep-on-blank), session key,
 * text splitting, webhook verification, signature, payload parsing + dedupe,
 * reply flow (AI + deterministic lead fallback), lead capture, Graph sender,
 * rate limit and health.
 */

namespace Estandar\Capture {
    class ChatConversation {
        public static $store = array();
        public static $seq = 0;
        public static function reset() { self::$store = array(); self::$seq = 0; }
        public static function append($sk, $ch, $role, $content) {
            self::$seq++;
            self::$store[$sk][$ch][] = array('id' => self::$seq, 'session_key' => $sk, 'channel' => $ch, 'role' => $role, 'content' => $content, 'created_at' => '2026-01-01 00:00:00');
            return self::$seq;
        }
        public static function get_messages($sk, $ch = 'web', $limit = 50) {
            $rows = self::$store[$sk][$ch] ?? array();
            return array_slice($rows, -$limit);
        }
        public static function conversations($limit = 100) {
            $out = array();
            foreach (self::$store as $sk => $chs) {
                foreach ($chs as $ch => $rows) {
                    $out[] = array('session_key' => $sk, 'channel' => $ch, 'message_count' => count($rows), 'last_message_at' => '2026-01-01 00:00:00');
                }
            }
            return array_slice($out, 0, $limit);
        }
        public static function stats() { return array('messages' => self::$seq, 'conversations' => count(self::$store)); }
        public static function activate_schema() {}
        public static function maybe_migrate_schema() {}
    }
    class ChatLog {
        public static $logs = array();
        public static function reset() { self::$logs = array(); }
        public static function log($sk, $ch, $u, $a) { self::$logs[] = array('session_key' => $sk, 'channel' => $ch, 'user' => $u, 'answer' => $a); return count(self::$logs); }
        public static function classify($a) { return 'ok'; }
        public static function recent($l = 200) { return self::$logs; }
        public static function friction($d = 7) { return array('total' => count(self::$logs), 'empty' => 0, 'fallbacks' => 0, 'errors' => 0, 'repeated' => array(), 'days' => $d); }
        public static function activate_schema() {}
        public static function maybe_migrate_schema() {}
    }
    class Ingestion {
        public static function ingest($raw) {
            $GLOBALS['__test_ingestions'][] = $raw;
            return array('id' => 900 + count($GLOBALS['__test_ingestions']), 'capture' => array('id' => 500 + count($GLOBALS['__test_ingestions']), 'capture_uuid' => 'u-' . count($GLOBALS['__test_ingestions'])));
        }
        public static function activate_schema() {}
        public static function maybe_migrate_schema() {}
    }
}

namespace {
    define('ABSPATH', __DIR__);
    define('HOUR_IN_SECONDS', 3600);
    define('MINUTE_IN_SECONDS', 60);
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__test_http'] = array();
    $GLOBALS['__test_http_error'] = '';
    $GLOBALS['__test_ingestions'] = array();

    if (!function_exists('sanitize_key')) {
        function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
    }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
    if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
    if (!function_exists('sanitize_email')) { function sanitize_email($t) { return strtolower(trim((string) $t)); } }
    if (!function_exists('is_email')) { function is_email($t) { return filter_var((string) $t, FILTER_VALIDATE_EMAIL) !== false; } }
    if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
    if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
    if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__test_options']['_t_' . $k] ?? false; } }
    if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['__test_options']['_t_' . $k] = $v; return true; } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
    if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return '11111111-2222-3333-4444-555555555555'; } }
    if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof \WP_Error; } }
    if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }
    if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 200); } }
    if (!function_exists('rest_url')) { function rest_url($p = '') { return 'https://example.test/wp-json/' . ltrim($p, '/'); } }
    if (!function_exists('error_log')) { function error_log($m) {} }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $code; public $message; public $data;
            public function __construct($c = '', $m = '', $d = array()) { $this->code = $c; $this->message = $m; $this->data = $d; }
            public function get_error_message() { return $this->message; }
        }
    }
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post($url, $args) {
            $GLOBALS['__test_http'][] = array('url' => $url, 'args' => $args);
            if ($GLOBALS['__test_http_error'] !== '') {
                return new WP_Error('err', $GLOBALS['__test_http_error']);
            }
            if (strpos($url, 'graph.facebook.com') !== false) {
                return array(
                    'body' => json_encode(array('messages' => array(array('id' => 'wamid.TEST123')))),
                    'response' => array('code' => 200),
                );
            }
            return array(
                'body' => json_encode(array(
                    'choices' => array(array('message' => array('content' => 'Hola, ¿en qué te ayudo?'), 'finish_reason' => 'stop')),
                    'usage' => array('prompt_tokens' => 5, 'completion_tokens' => 3),
                    'model' => 'deepseek-chat',
                )),
                'response' => array('code' => 200),
            );
        }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = array()) {
            $GLOBALS['__test_http'][] = array('url' => $url, 'args' => $args);
            if (strpos($url, 'graph.facebook.com') !== false) {
                return array(
                    'body' => json_encode(array('id' => '123456', 'display_phone_number' => '+56 9 1234 5678', 'verified_name' => 'Empresa Demo', 'quality_rating' => 'GREEN')),
                    'response' => array('code' => 200),
                );
            }
            return array('body' => '', 'response' => array('code' => 200));
        }
    }

    require dirname(__DIR__) . '/../estandar-core/includes/AiKeys.php';
    require dirname(__DIR__) . '/includes/AiChat.php';
    require dirname(__DIR__) . '/includes/AiChatPrompt.php';
    require dirname(__DIR__) . '/includes/WhatsAppBot.php';

    use Estandar\Capture\WhatsAppBot;

    $passed = 0; $failed = 0;
    function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

    // ── 1. Defaults + legacy fallback ─────────────────────────────────────
    unset($GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']);
    $GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
        'whatsapp_enabled' => 1,
        'whatsapp_phone_number_id' => '999999',
        'whatsapp_access_token' => 'sk-legacy',
        'whatsapp_verify_token' => 'vt-legacy',
    );
    check('1a legacy enabled', WhatsAppBot::enabled() === true);
    check('1b legacy phone id', WhatsAppBot::phone_number_id() === '999999');
    check('1c legacy token', WhatsAppBot::access_token() === 'sk-legacy');
    check('1d defaults aplicados', (int) WhatsAppBot::get('conversation_history') === 12);

    // ── 2. Save: dedicated overrides + secret keep-on-blank ──────────────
    $saved = WhatsAppBot::save_settings(array(
        'enabled' => 0,
        'phone_number_id' => '555000111',
        'access_token' => '',
        'verify_token' => '',
        'app_secret' => 'appsec',
        'conversation_history' => 5,
        'lead_capture_enabled' => 1,
    ));
    check('2a access_token conservado (blank)', WhatsAppBot::access_token() === 'sk-legacy');
    check('2b verify_token conservado (blank)', WhatsAppBot::verify_token() === 'vt-legacy');
    check('2c app_secret guardado', WhatsAppBot::app_secret() === 'appsec');
    check('2d phone overridden', WhatsAppBot::phone_number_id() === '555000111');
    check('2e enabled override', WhatsAppBot::enabled() === false);
    check('2f history clamp', (int) WhatsAppBot::get('conversation_history') === 5);

    // ── 3. Session key ────────────────────────────────────────────────────
    check('3a sesión wa_digits', WhatsAppBot::session_key('56912345678') === 'wa_56912345678');
    check('3b sesión limpia símbolos', WhatsAppBot::session_key('+56 9 1234 5678') === 'wa_56912345678');

    // ── 4. Split text ─────────────────────────────────────────────────────
    $short = WhatsAppBot::split_text('hola corto');
    check('4a corto no se parte', $short === array('hola corto'));
    $long = str_repeat('a', 9000);
    $parts = WhatsAppBot::split_text($long);
    check('4b largo se parte en 3', count($parts) === 3);
    $maxlen = 0;
    foreach ($parts as $p) { $l = function_exists('mb_strlen') ? mb_strlen($p) : strlen($p); $maxlen = max($maxlen, $l); }
    check('4c ninguna parte > 4096', $maxlen <= 4096);

    // ── 5. Firma del webhook ──────────────────────────────────────────────
    $secret = 'myappsecret';
    WhatsAppBot::save_settings(array('app_secret' => $secret));
    $raw = '{"entry":[]}';
    $good = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    check('5a firma válida', WhatsAppBot::signature_valid($good, $raw) === true);
    check('5b firma inválida', WhatsAppBot::signature_valid('sha256=badbadbad', $raw) === false);
    $GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']['app_secret'] = '';
    check('5c sin app_secret pasa', WhatsAppBot::signature_valid('', 'x') === true);

    // ── 6. Verificación del webhook ───────────────────────────────────────
    WhatsAppBot::save_settings(array('verify_token' => 'mivtoken'));
    check('6a challenge ok', WhatsAppBot::verify_webhook(array('hub_mode' => 'subscribe', 'hub_verify_token' => 'mivtoken', 'hub_challenge' => '123456')) === '123456');
    check('6b token malo', WhatsAppBot::verify_webhook(array('hub_mode' => 'subscribe', 'hub_verify_token' => 'nope', 'hub_challenge' => '7')) === null);
    check('6c sin modo', WhatsAppBot::verify_webhook(array('hub_verify_token' => 'mivtoken', 'hub_challenge' => '8')) === null);

    // ── 7. Parse del payload ──────────────────────────────────────────────
    $payload = array(
        'entry' => array(
            array(
                'changes' => array(
                    array('field' => 'messages', 'value' => array(
                        'messages' => array(
                            array('id' => 'w1', 'from' => '56912345678', 'type' => 'text', 'text' => array('body' => 'Hola'), 'timestamp' => '1700000000'),
                            array('id' => 'w2', 'from' => '56912345678', 'type' => 'status', 'status' => 'delivered'),
                            array('id' => 'w3', 'from' => '56912345678', 'type' => 'interactive', 'interactive' => array('button_reply' => array('title' => 'Ver precios'))),
                        ),
                    )),
                    array('field' => 'other', 'value' => array()),
                ),
            ),
        ),
    );
    $msgs = WhatsAppBot::parse_payload($payload);
    check('7a extrae 2 mensajes', count($msgs) === 2);
    check('7b primer texto', $msgs[0]['text'] === 'Hola');
    check('7c botón interactivo', $msgs[1]['text'] === 'Ver precios');
    check('7d from normalizado', $msgs[0]['from'] === '56912345678');

    // ── 8. Flujo: mensaje entrante con IA → reply + send + persist ───────
    \Estandar\Capture\ChatConversation::reset();
    \Estandar\Capture\ChatLog::reset();
    $GLOBALS['__test_options'] = array(
        'estandar_suite_capture_whatsapp_settings' => array(
            'enabled' => 1,
            'phone_number_id' => '555000111',
            'access_token' => 'tok-ok',
            'verify_token' => 'vt',
            'app_secret' => '',
            'conversation_history' => 12,
            'lead_capture_enabled' => 0,
            'welcome_message' => '',
            'fallback_reply' => 'fallback',
            'rate_limit_per_hour' => 200,
            'debug_log' => 0,
        ),
        'estandar_suite_core_ai_settings' => array('deepseek' => 'sk-deep', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat'),
    );
    $GLOBALS['__test_http'] = array();
    $GLOBALS['__test_http_error'] = '';
    $r = WhatsAppBot::process_message(array('id' => 'm1', 'from' => '56912345678', 'text' => 'Hola', 'ts' => '1700000000'));
    check('8a replied ok', ! empty($r['replied']));
    check('8b sin error', empty($r['error']));
    check('8c session_key', ($r['session_key'] ?? '') === 'wa_56912345678');
    check('8d mensajes persistidos', count(\Estandar\Capture\ChatConversation::$store['wa_56912345678']['whatsapp'] ?? array()) === 2);
    check('8e log registrado', count(\Estandar\Capture\ChatLog::$logs) === 1);
    $last_http = end($GLOBALS['__test_http']);
    check('8f envío a Graph /messages', strpos($last_http['url'], '555000111/messages') !== false);
    $body = json_decode($last_http['args']['body'], true);
    check('8g body messaging_product', ($body['messaging_product'] ?? '') === 'whatsapp');
    check('8h to del mensaje', ($body['to'] ?? '') === '56912345678');

    // ── 9. Dedupe por message id ──────────────────────────────────────────
    $GLOBALS['__test_options']['_t_estandar_suite_wa_msg_m1'] = 1;
    $payload = array('entry' => array(array('changes' => array(array('field' => 'messages', 'value' => array('messages' => array(array('id' => 'm1', 'from' => '56912345678', 'type' => 'text', 'text' => array('body' => 'Hola de nuevo')))))))));
    $summary = WhatsAppBot::handle_payload($payload);
    check('9a dedupe skip', ($summary['skipped'] ?? 0) === 1);
    check('9b processed contado', ($summary['processed'] ?? 0) === 1);

    // ── 10. Fallback determinístico + captura de lead ────────────────────
    $GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']['lead_capture_enabled'] = 1;
    unset($GLOBALS['__test_options']['estandar_suite_wa_ctx_wa_56987654321']);
    unset($GLOBALS['__test_options']['estandar_suite_wa_lead_wa_56987654321']);
    $GLOBALS['__test_http_error'] = 'AI_FAIL';
    $GLOBALS['__test_ingestions'] = array();
    $sk = WhatsAppBot::session_key('56987654321');
    $det = WhatsAppBot::deterministic_lead_reply('Hola necesito info', $sk);
    check('10a pide email', strpos($det['content'], 'correo electrónico') !== false);
    $det = WhatsAppBot::deterministic_lead_reply('mi correo es juan@cliente1.example.com', $sk);
    check('10b pide teléfono', strpos($det['content'], 'teléfono') !== false);
    $det = WhatsAppBot::deterministic_lead_reply('soy Juan Perez, 9 1234 5678', $sk);
    check('10c captura completa', ! empty($det['lead_id']));
    check('10d ingesta source whatsapp', ($GLOBALS['__test_ingestions'][0]['source'] ?? '') === 'whatsapp');
    check('10e contacto completo', ($GLOBALS['__test_ingestions'][0]['contact']['email'] ?? '') === 'juan@cliente1.example.com');
    $det2 = WhatsAppBot::deterministic_lead_reply('otra cosa', $sk);
    check('10f no recaptura', empty($det2['lead_id']));

    // ── 11. Envío sin configuración → error ──────────────────────────────
    $GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']['access_token'] = '';
    $bad = WhatsAppBot::send_text('56912345678', 'hola');
    check('11a error sin token', ! empty($bad['error']));

    // ── 12. Test connection (GET Graph) ──────────────────────────────────
    $GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']['access_token'] = 'tok-ok';
    $GLOBALS['__test_http'] = array();
    $conn = WhatsAppBot::test_connection();
    check('12a conexión ok', ! empty($conn['ok']));
    check('12b verified_name', ($conn['data']['verified_name'] ?? '') === 'Empresa Demo');

    // ── 13. Rate limit ───────────────────────────────────────────────────
    $GLOBALS['__test_options']['estandar_suite_capture_whatsapp_settings']['rate_limit_per_hour'] = 2;
    $GLOBALS['__test_options']['_t_estandar_suite_wa_rl_56912345678'] = 2;
    check('13a rate limit excedido', WhatsAppBot::rate_ok('56912345678') === false);
    $GLOBALS['__test_options']['_t_estandar_suite_wa_rl_56912345678'] = 1;
    check('13b dentro del límite', WhatsAppBot::rate_ok('56912345678') === true);
    WhatsAppBot::save_settings(array('rate_limit_per_hour' => 0));
    check('13c sin tope', WhatsAppBot::rate_ok('56912345678') === true);

    // ── 14. Health ────────────────────────────────────────────────────────
    $health = WhatsAppBot::health();
    check('14a health checks', isset($health['checks']['ai'], $health['checks']['webhook']));
    check('14b diag presente', is_array($health['diag']));

    echo "\nRESULTADO: {$passed} passed / {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}
