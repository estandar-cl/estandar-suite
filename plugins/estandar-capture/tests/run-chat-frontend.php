<?php
/**
 * ESTANDAR SUITE v2 — Capture ChatConversation + ChatbotFrontend config tests.
 *
 *   php tests/run-chat-frontend.php   → expect "0 failed"
 *
 * Covers: message persistence (append/get), conversation aggregation, stats,
 * session normalization, and the chatbotConfig contract shape.
 */

define('ABSPATH', __DIR__);
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_rows'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return (string) $e; } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($f = 'name') { return 'Sitio de Prueba'; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return trim((string) preg_replace('/<[^>]*>/', ' ', (string) $t)); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return (string) $u; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
$GLOBALS['__test_view'] = array( 'singular_id' => 0, 'front' => false, 'home' => false, 'shortcode' => false );
if (!function_exists('is_singular')) { function is_singular() { return (int) $GLOBALS['__test_view']['singular_id'] > 0; } }
if (!function_exists('get_queried_object_id')) { function get_queried_object_id() { return (int) $GLOBALS['__test_view']['singular_id']; } }
if (!function_exists('is_front_page')) { function is_front_page() { return ! empty( $GLOBALS['__test_view']['front'] ); } }
if (!function_exists('is_home')) { function is_home() { return ! empty( $GLOBALS['__test_view']['home'] ); } }
if (!function_exists('has_shortcode')) { function has_shortcode( $c, $t ) { return ! empty( $GLOBALS['__test_view']['shortcode'] ) && strpos( (string) $c, '[' . $t ) !== false; } }
if (!class_exists('WP_Post')) { class WP_Post { public $post_content = ''; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://site.test/wp-admin/' . ltrim($p, '/'); } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a = '') { return 'test-nonce'; } }
if (!function_exists('rest_url')) { function rest_url($p = '') { return 'https://site.test/wp-json/' . ltrim($p, '/'); } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://site.test/' . ltrim((string) $p, '/'); } }
if (!function_exists('add_query_arg')) { function add_query_arg($args = array(), $url = '') { return $url !== '' ? $url : 'https://site.test/'; } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url($f) { return 'https://site.test/wp-content/plugins/estandar-capture/'; } }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l) { return substr($s, $a, $l); } }
$GLOBALS['__wpdb'] = new class {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public function get_charset_collate() { return ''; }
    public function insert($t, $d, $f) {
        $GLOBALS['__test_rows'][$t][] = $d;
        $this->insert_id++;
        return true;
    }
    public function get_results($sql, $type = ARRAY_A) {
        // naive: return stored rows for table name in SQL
        if (strpos($sql, 'estandar_capture_chat_messages') !== false) {
            if (strpos($sql, 'GROUP BY') !== false) {
                return array_values($GLOBALS['__test_rows']['wp_estandar_capture_chat_messages'] ?? array());
            }
            return array_values($GLOBALS['__test_rows']['wp_estandar_capture_chat_messages'] ?? array());
        }
        return array();
    }
    public function get_var($sql) {
        if (strpos($sql, 'COUNT(*)') !== false) {
            return count($GLOBALS['__test_rows']['wp_estandar_capture_chat_messages'] ?? array());
        }
        if (strpos($sql, 'DISTINCT session_key') !== false) {
            $rows = $GLOBALS['__test_rows']['wp_estandar_capture_chat_messages'] ?? array();
            $keys = array();
            foreach ($rows as $r) { $keys[$r['session_key'] . '|' . $r['channel']] = 1; }
            return count($keys);
        }
        return 0;
    }
    public function prepare($sql, ...$args) {
        // naive interpolation for tests
        $s = $sql;
        if (isset($args[0]) && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $s = preg_replace('/%[sd]/', "'" . addslashes((string) $a) . "'", $s, 1);
        }
        return $s;
    }
    public function query($sql) { return 0; }
};

$GLOBALS['wpdb'] = $GLOBALS['__wpdb'];

require dirname(__DIR__) . '/includes/ChatConversation.php';
require dirname(__DIR__) . '/includes/ChatbotFrontend.php';
require dirname(__DIR__) . '/includes/AiChat.php';
require dirname(__DIR__) . '/includes/AiChatPrompt.php';

use Estandar\Capture\ChatConversation;
use Estandar\Capture\ChatbotFrontend;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── 1. Append + get ─────────────────────────────────────────────────────
$id = ChatConversation::append('web_abc_123', 'web', 'user', 'Hola, necesito información');
check('1a append devuelve id', $id > 0);
ChatConversation::append('web_abc_123', 'web', 'assistant', 'Claro, cuéntame más.');
$msgs = ChatConversation::get_messages('web_abc_123', 'web');
check('1b get devuelve 2 mensajes', count($msgs) === 2);
check('1c primer rol user', ($msgs[0]['role'] ?? '') === 'user');
check('1d segundo rol assistant', ($msgs[1]['role'] ?? '') === 'assistant');

// ── 2. Normalización de session (caracteres no permitidos) ──────────────
ChatConversation::append('bad!!session***', 'web', 'user', 'x');
$msgs2 = ChatConversation::get_messages('bad!!session***');
check('2a session sanitizada', count($msgs2) >= 0); // no fatal

// ── 3. stats ────────────────────────────────────────────────────────────
$stats = ChatConversation::stats();
check('3a stats messages', ($stats['messages'] ?? 0) >= 3);
check('3b stats conversations', ($stats['conversations'] ?? 0) >= 1);

// ── 4. Config contract (ChatbotFrontend::config) ────────────────────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'activar' => 1,
    'nombre' => 'Asistente Test',
    'mostrar_nombre' => 1,
    'idioma' => 'es',
    'lead_capture_enabled' => 1,
    'theme_accent' => '#ff0000',
    'bubble_size' => 60,
    'bubble_shape' => 'circle',
    'theme_position' => 'left',
    'business_industry' => 'inmobiliaria',
    'business_goal' => 'captar contactos',
    'business_primary_cta' => 'Quiero avanzar',
    'business_tone' => 'profesional',
    'business_sensitive_topics' => 'precio',
    'openai_model' => 'deepseek-chat',
    'openai_temperature' => '0.4',
    'openai_max_tokens' => '500',
    'system_prompt' => 'Sé directo.',
);
$cfg = ChatbotFrontend::config();
check('4a activo', !empty($cfg['activo']));
check('4b botName', ($cfg['botName'] ?? '') === 'Asistente Test');
check('4c idioma', ($cfg['idioma'] ?? '') === 'es');
check('4d theme accent', ($cfg['theme']['accent'] ?? '') === '#ff0000');
check('4e theme bubbleSize', ($cfg['theme']['bubbleSize'] ?? 0) === 60);
check('4f theme position', ($cfg['theme']['position'] ?? '') === 'left');
check('4g businessContext industry', ($cfg['businessContext']['industry'] ?? '') === 'inmobiliaria');
check('4h scriptUrls presente', !empty($cfg['scriptUrls']['chatbot']));
check('4i nonce presente', ($cfg['nonce'] ?? '') !== '');
check('4j ajaxUrl presente', ($cfg['ajaxUrl'] ?? '') !== '');

// ── 5. is_visible (desactivado → false) ─────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 0;
check('5a desactivado no visible', ChatbotFrontend::is_visible() === false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 1;
check('5b activado visible', ChatbotFrontend::is_visible() === true);

// ── 6. should_load_floating (filtro por páginas visibles) ────────────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 1;
unset( $GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['paginas_visibles'] );
unset( $GLOBALS['__test_options']['chatbot_paginas_visibles'] );
$GLOBALS['__test_view'] = array( 'singular_id' => 0, 'front' => false, 'home' => false, 'shortcode' => false );
$GLOBALS['post'] = new WP_Post();
$GLOBALS['post']->post_content = '';
check('6a sin restricción → todo el sitio', ChatbotFrontend::should_load_floating() === true);

$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['paginas_visibles'] = array( 12 );
$GLOBALS['__test_view']['singular_id'] = 12;
check('6b en página permitida → visible', ChatbotFrontend::should_load_floating() === true);

$GLOBALS['__test_view']['singular_id'] = 13;
check('6c en página no permitida → oculto', ChatbotFrontend::should_load_floating() === false);

$GLOBALS['__test_view']['singular_id'] = 13;
$GLOBALS['__test_view']['shortcode'] = true;
$GLOBALS['post']->post_content = '[estandar_chatbot mode="inline"]';
check('6d shortcode fuerza carga aunque no esté en la lista', ChatbotFrontend::should_load_floating() === true);
$GLOBALS['__test_view']['shortcode'] = false;
$GLOBALS['post']->post_content = '';

unset( $GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['paginas_visibles'] );
$GLOBALS['__test_options']['chatbot_paginas_visibles'] = array( 2 );
$GLOBALS['__test_options']['page_on_front'] = '2';
$GLOBALS['__test_view']['singular_id'] = 0;
$GLOBALS['__test_view']['front'] = true;
check('6e front page en lista → visible (fallback opción legacy)', ChatbotFrontend::should_load_floating() === true);

$GLOBALS['__test_options']['chatbot_paginas_visibles'] = array( 9 );
check('6f front page fuera de lista → oculto', ChatbotFrontend::should_load_floating() === false);

$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 0;
$GLOBALS['__test_view']['front'] = false;
$GLOBALS['__test_view']['shortcode'] = true;
$GLOBALS['post']->post_content = '[estandar_chatbot]';
check('6g desactivado nunca carga, ni con shortcode', ChatbotFrontend::should_load_floating() === false);

// ── 7. SAVE_LEAD_FLAT_V1: campos planos no se pierden ────────────────────
$lead_flat = ChatbotFrontend::normalize_lead_payload(array(
    'intent'               => 'comprar',
    'requirement'          => 'Quiero información del proyecto',
    'conversation_summary' => 'Resumen de la charla',
    'source_page_url'      => 'https://site.test/pagina',
    'message'              => 'hola',
    'session_id'           => 'web_flat_1',
    'lead'                 => array('name' => 'Ana', 'email' => 'ana@x.cl'),
));
check('7a requirement plano leído', ($lead_flat['requirement'] ?? '') === 'Quiero información del proyecto');
check('7b source_page_url plano leído', ($lead_flat['source_page_url'] ?? '') === 'https://site.test/pagina');
check('7c lead[] no se pisa', ($lead_flat['name'] ?? '') === 'Ana' && ($lead_flat['email'] ?? '') === 'ana@x.cl');
$ing_flat = ChatbotFrontend::build_lead_ingest($lead_flat);
check('7d content.message desde requirement', ($ing_flat['content']['message'] ?? '') === 'Quiero información del proyecto');
check('7e context.source_page_url no vacío', ($ing_flat['context']['source_page_url'] ?? '') === 'https://site.test/pagina');
check('7f attribution.landing_url no vacío', ($ing_flat['attribution']['landing_url'] ?? '') === 'https://site.test/pagina');
$ing_flat2 = ChatbotFrontend::build_lead_ingest(array('conversation_summary' => 'Solo resumen', 'source_page_url' => 'https://site.test/x'));
check('7g message cae a conversation_summary', ($ing_flat2['content']['message'] ?? '') === 'Solo resumen');

// ── 8. LEAD_CAPTURE_COPY_V1: copy configurable + defaults amables ────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['lead_capture_ask_email'] = '¿Cuál es tu correo?';
$det = ChatbotFrontend::deterministic_lead_reply('hola', array(), 'web_copy_1');
check('8a copy de captación configurable', strpos($det['content'], '¿Cuál es tu correo?') !== false);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['lead_capture_ask_email']);
$det_def = ChatbotFrontend::deterministic_lead_reply('hola', array(), 'web_copy_2');
check('8b default no es burocrático', strpos($det_def['content'], 'primero necesito tu correo') === false);
check('8c default pide correo con naturalidad', strpos($det_def['content'], 'correo') !== false);

// ── 9. CHATBOT_MSGS_V1: copy de escalado desde v2 (con fallback) ─────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['human_escalation_text'] = 'Te derivo con una persona ahora.';
$cfg9 = ChatbotFrontend::config();
check('9a humanEscalationText desde v2', ($cfg9['humanEscalationText'] ?? '') === 'Te derivo con una persona ahora.');
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['human_escalation_text']);
$cfg9b = ChatbotFrontend::config();
check('9b humanEscalationText default no vacío', ($cfg9b['humanEscalationText'] ?? '') !== '');
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['human_escalation_whatsapp_message'] = 'Hola equipo, vengo del chat.';
check('9c WhatsApp escalation desde v2', (ChatbotFrontend::config()['humanEscalationWhatsAppMessage'] ?? '') === 'Hola equipo, vengo del chat.');
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['human_escalation_whatsapp_message']);

// ── 10. NAME_EXTRACT_V2: nombre conservador (no guarda basura) ───────────
check('10a no extrae basura de frase', ChatbotFrontend::extract_name(array('necesio el correo de hernan aviles')) === '');
check('10b "me llamo Ana"', ChatbotFrontend::extract_name(array('hola, me llamo Ana')) === 'Ana');
check('10c "soy María José"', ChatbotFrontend::extract_name(array('soy María José')) === 'María José');
check('10d mensaje corto nombre', ChatbotFrontend::extract_name(array('Juan Pérez')) === 'Juan Pérez');
check('10e frase larga sin nombre', ChatbotFrontend::extract_name(array('quiero saber el precio de las plantas de uva de mesa')) === '');

// ── 11. CHAT_SESSION_KEY_V1: clave derivada del servidor (anti-spoof) ────
$k1 = \Estandar\Capture\AiChatPrompt::server_session_key('1.2.3.4', 'web', 'UA');
$k2 = \Estandar\Capture\AiChatPrompt::server_session_key('1.2.3.4', 'web', 'UA');
$k3 = \Estandar\Capture\AiChatPrompt::server_session_key('9.9.9.9', 'web', 'UA');
check('11a determinista por IP/UA/día', $k1 === $k2);
check('11b distinta por IP', $k1 !== $k3);
check('11c prefijo web_', strpos($k1, 'web_') === 0);
check('11d no usa session_id del cliente', strpos($k1, 'web_') === 0 && strlen($k1) === 36);

// ── 12. CHAT_WIDGET_GUARD_V1 + CHAT_RAG_V1 defaults ──────────────────────
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['ajax_guard_enabled']);
check('12a guard default ON', \Estandar\Capture\AiChatPrompt::widget_guard_enabled() === true);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['ajax_guard_enabled'] = 0;
check('12b guard opt-out', \Estandar\Capture\AiChatPrompt::widget_guard_enabled() === false);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['ajax_guard_enabled']);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['rag_enabled']);
check('12c rag default ON', \Estandar\Capture\AiChatPrompt::rag_enabled() === true);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['rag_enabled'] = 0;
check('12d rag opt-out', \Estandar\Capture\AiChatPrompt::rag_enabled() === false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['rag_enabled'] = 1;
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['rag_chunks'] = 99;
check('12e rag_chunks acotado a 6', \Estandar\Capture\AiChatPrompt::rag_chunks() === 6);
check('12f knowledge_context sin Brain = vacío', \Estandar\Capture\AiChatPrompt::knowledge_context('precio') === '');
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['rag_chunks']);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['sales_mode']);
check('12g sales_mode default vacío (no altera sitios no inmobiliarios)', \Estandar\Capture\AiChatPrompt::sales_mode() === '');
check('12h system() no inyecta modalidad por defecto', strpos(\Estandar\Capture\AiChatPrompt::system(), 'Modalidad de este negocio') === false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['sales_mode'] = 'venta';
check('12i system() inyecta modalidad configurada', strpos(\Estandar\Capture\AiChatPrompt::system(), 'VENTA') !== false);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['sales_mode']);

// ── 13. CHAT_CAPTURE_FIELDS_V1 + CHAT_RUT_V1 ─────────────────────────────
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['capture_fields']);
check('13a campos default', ChatbotFrontend::capture_fields() === array('name', 'email', 'phone'));
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['capture_fields'] = 'name,email,phone,rut';
check('13b rut habilitado', in_array('rut', ChatbotFrontend::capture_fields(), true));
check('13c RUT válido', ChatbotFrontend::rut_is_valid('11.111.111-1') === true);
check('13d RUT inválido', ChatbotFrontend::rut_is_valid('12.345.678-9') === false);
check('13e extrae RUT de texto', ChatbotFrontend::extract_rut(array('mi rut es 11.111.111-1')) === '11111111-1');
check('13f no extrae RUT inválido', ChatbotFrontend::extract_rut(array('rut 12.345.678-9')) === '');
// Con RUT habilitado y sin RUT en los mensajes, el flujo determinístico lo pide.
$det_rut = ChatbotFrontend::deterministic_lead_reply('Juan Pérez', array(
    array('role' => 'user', 'content' => 'juan@x.cl'),
    array('role' => 'user', 'content' => '+56912345678'),
    array('role' => 'user', 'content' => 'Juan Pérez'),
), 'web_rut_test');
check('13g pide RUT cuando falta', strpos($det_rut['content'], 'RUT') !== false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['capture_fields'] = 'name,email,phone';

// ── 14. CHATBOT_LOG_RETENTION_V1: default 90 + cron ──────────────────────
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['logs_retention_days']);
check('14a retención default 90', ChatbotFrontend::retention_days() === 90);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['logs_retention_days'] = '0';
check('14b retención 0 = indefinida', ChatbotFrontend::retention_days() === 0);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['logs_retention_days'] = '30';
ChatbotFrontend::ensure_prune_schedule(); // no fatal sin WP-Cron
ChatbotFrontend::prune_logs();            // no fatal (wpdb->query mock)
check('14c poda no fatal', true);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['logs_retention_days']);

// ── 15. CHAT_CONV_PAGING_V1: filtros + conteo ────────────────────────────
list($where, $args) = \Estandar\Capture\ChatConversation::filter_where(array('channel' => 'web', 'q' => 'abc'));
check('15a where con filtros', strpos($where, 'channel = %s') !== false && strpos($where, 'session_key LIKE') !== false);
check('15b args de filtros', count($args) === 3);
list($where_empty, $args_empty) = \Estandar\Capture\ChatConversation::filter_where(array());
check('15c sin filtros = vacío', $where_empty === '' && $args_empty === array());
check('15d count_conversaciones int', is_int(\Estandar\Capture\ChatConversation::count_conversations()));
$conv_paged = \Estandar\Capture\ChatConversation::conversations(10, array('channel' => 'web'), 0);
check('15e conversations paginadas no fatal', is_array($conv_paged));

// ── 16. CHAT_STREAM_V1: flag de streaming (default ON, opt-out) ──────────
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['stream_enabled']);
check('16a stream default ON', \Estandar\Capture\AiChatPrompt::stream_enabled() === true);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['stream_enabled'] = 0;
check('16b stream opt-out', \Estandar\Capture\AiChatPrompt::stream_enabled() === false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['stream_enabled'] = 1;
$cfg_stream = ChatbotFrontend::config();
check('16c config expone streamEnabled', array_key_exists('streamEnabled', $cfg_stream) && $cfg_stream['streamEnabled'] === true);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['stream_enabled']);

// ── 17. UX_CHATBOT_CONTEXT_V1 / CHIPS_V1 / DEBUG_V1 (P0/P1/P2) ───────────
$cfg_ux = ChatbotFrontend::config();
check('17a config expone currentProject', array_key_exists('currentProject', $cfg_ux) && is_array($cfg_ux['currentProject']));
check('17b currentProject trae slug+name', array_key_exists('slug', $cfg_ux['currentProject']) && array_key_exists('name', $cfg_ux['currentProject']));
check('17c config expone currentUrl', array_key_exists('currentUrl', $cfg_ux) && $cfg_ux['currentUrl'] !== '');
check('17d config expone defaultChips', array_key_exists('defaultChips', $cfg_ux) && is_array($cfg_ux['defaultChips']));
check('17e defaultChips default 4 opciones', count($cfg_ux['defaultChips']) === 4 && ($cfg_ux['defaultChips'][0]['label'] ?? '') === 'Comprar');
check('17f defaultChips slug determinístico', ($cfg_ux['defaultChips'][3]['id'] ?? '') === 'hablar-con-un-ejecutivo');
check('17g config expone debug', array_key_exists('debug', $cfg_ux) && $cfg_ux['debug'] === false);
$GLOBALS['__test_options']['chatbot_default_chips'] = 'Comprar, Vender';
$cfg_chips = ChatbotFrontend::config();
check('17h defaultChips string CSV', count($cfg_chips['defaultChips']) === 2 && ($cfg_chips['defaultChips'][1]['label'] ?? '') === 'Vender');
unset($GLOBALS['__test_options']['chatbot_default_chips']);
$GLOBALS['__test_options']['chatbot_default_chips'] = array();
check('17i defaultChips vacío = sin chips', ChatbotFrontend::config()['defaultChips'] === array());
unset($GLOBALS['__test_options']['chatbot_default_chips']);

// ── 18. CACHE_PURGE_V1: purga de caché al cambiar settings del chatbot ───
$GLOBALS['__cache_purged'] = 0;
$GLOBALS['__purge_hook'] = 0;
if (!function_exists('sg_cachepress_purge_everything')) {
    function sg_cachepress_purge_everything() { $GLOBALS['__cache_purged']++; return true; }
}
if (!function_exists('do_action')) {
    function do_action($tag = '', ...$args) { if ($tag === 'estandar_suite_chatbot_purge_caches') { $GLOBALS['__purge_hook']++; } }
}
ChatbotFrontend::purge_caches();
check('18a purga SiteGround', ($GLOBALS['__cache_purged'] ?? 0) === 1);
check('18b dispara hook genérico', ($GLOBALS['__purge_hook'] ?? 0) === 1);
check('18c purge_caches no fatal con args extra', (function () { ChatbotFrontend::purge_caches(array(), array(), 'estandar_suite_capture_chatbot_settings'); return true; })());

echo PHP_EOL . $failed . ' failed · ' . $passed . ' passed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
