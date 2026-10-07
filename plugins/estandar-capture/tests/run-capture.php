<?php
/**
 * ESTANDAR SUITE v2 — Capture tests (pure logic + guarded DB paths).
 *
 *   php tests/run-capture.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$_SERVER['HTTP_REFERER'] = '';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('remove_accents')) { function remove_accents($s) { return (string) $s; } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', strtolower(trim((string) $t)))); } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) strip_tags((string) $t)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return (string) $u; } }
if (!function_exists('rest_url')) { function rest_url($p = '') { return 'https://example.test/wp-json/' . ltrim((string) $p, '/'); } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_textarea')) { function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($t) { return (string) $t; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_hex_color')) { function sanitize_hex_color($c) { return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $c) ? (string) $c : null; } }
if (!function_exists('selected')) { function selected($a, $b, $echo = true) { $s = ((string) $a === (string) $b) ? ' selected="selected"' : ''; if ($echo) { echo $s; return ''; } return $s; } }
if (!function_exists('checked')) { function checked($a, $b, $echo = true) { $s = ((string) $a === (string) $b) ? ' checked="checked"' : ''; if ($echo) { echo $s; return ''; } return $s; } }
if (!function_exists('disabled')) { function disabled($a, $b, $echo = true) { $s = ((string) $a === (string) $b) ? ' disabled="disabled"' : ''; if ($echo) { echo $s; return ''; } return $s; } }
if (!function_exists('esc_js')) { function esc_js($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('is_email')) { function is_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) !== false; } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { $e = trim((string) $e); return is_email($e) ? strtolower($e) : ''; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $t))); } }
if (!function_exists('absint')) { function absint($n) { return abs((int) $n); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff), mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return array_key_exists($tag, $GLOBALS['__test_filters'] ?? array()) ? $GLOBALS['__test_filters'][$tag] : $value; } }
if (!function_exists('get_queried_object')) { function get_queried_object() { return $GLOBALS['__test_queried_object'] ?? null; } }
if (!function_exists('do_action')) { function do_action() {} }
if (!function_exists('add_shortcode')) { function add_shortcode() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
// Stubs de cron/transients para AUTORESPONSE_DELAYED_V1.
if (!function_exists('wp_schedule_single_event')) {
	function wp_schedule_single_event($when, $hook, $args = array()) { $GLOBALS['__test_cron'][] = array('when' => $when, 'hook' => $hook, 'args' => $args); return true; }
}
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook($h, $a = array()) { $GLOBALS['__test_cron'] = array(); } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__test_transients'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['__test_transients'][$k] = $v; $GLOBALS['__test_transients_exp'][$k] = $e; return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { unset($GLOBALS['__test_transients'][$k]); return true; } }

require dirname(__DIR__) . '/includes/Ingestion.php';
require dirname(__DIR__) . '/includes/ConversationEngine.php';
require dirname(__DIR__) . '/includes/Attribution.php';
require dirname(__DIR__) . '/includes/Forms.php';
require dirname(__DIR__) . '/includes/AiChatPrompt.php';
require dirname(__DIR__) . '/includes/FormBridge.php';
require dirname(__DIR__) . '/includes/MetaLeadAds.php';
require dirname(__DIR__) . '/includes/Adoption.php';
require dirname(__DIR__) . '/includes/FormsAdoption.php';
require_once dirname(__DIR__) . '/includes/Admin.php';

// Stub de Core\Mail para ejercitar el envío (autorespuesta / notify / dedupe).
// Este test es puro (sin WordPress): se hace class_alias para que Forms use el
// motor como en producción sin tocar el SMTP real.
if ( ! class_exists( 'EstandarTest_Mail_Stub' ) ) {
	class EstandarTest_Mail_Stub {
		public static function identity( array $overrides = array(), string $project_slug = '' ): array {
			return array(
				'site_name' => '', 'sender_name' => '', 'sender_email' => '',
				'brand_color' => '#1a73e8', 'logo_url' => '', 'header_text' => '',
				'footer_text' => '', 'preheader' => '', 'project_name' => $project_slug,
			);
		}
		public static function header_html( array $identity = array() ): string { return '<div>HDR</div>'; }
		public static function footer_html( array $identity = array() ): string { return '<div>FTR</div>'; }
		public static function validate_address( string $address ): bool { return filter_var( $address, FILTER_VALIDATE_EMAIL ) !== false; }
		public static function normalize_addresses( $v ): array {
			if ( is_array( $v ) ) { $parts = $v; } else { $parts = preg_split( '/[\s,;]+/', (string) $v ); }
			return array_values( array_filter( array_map( 'sanitize_email', (array) $parts ) ) );
		}
		public static function send( array $args = array() ): array { $GLOBALS['__test_mail_last'] = $args; return array( 'ok' => true, 'id' => 'test-mail' ); }
		// MAIL_TEMPLATES_V1: stub de registro/resolución/envoltura (ver run-core
		// para la implementación real). Se controla vía __test_templates_*.
		public static function mail_templates(): array { return array( 'base' => array( 'name' => 'B', 'header_html' => '<h1>{{site_name}}</h1>', 'content_style' => 'padding:8px;', 'footer_html' => '<footer>{{footer_text}}</footer>' ) ); }
		public static function mail_template( string $slug ): ?array { return isset( self::mail_templates()[ $slug ] ) ? self::mail_templates()[ $slug ] : null; }
		public static function save_mail_templates( array $all ): array { return $all; }
		public static function wrap_template( array $template, string $content ): string {
			$html = '<div style="' . esc_attr( (string) ( $template['content_style'] ?? '' ) ) . '">'
				. (string) ( $template['header_html'] ?? '' ) . '{{content}}' . (string) ( $template['footer_html'] ?? '' ) . '</div>';
			return strtr( $html, array( '{{content}}' => $content ) );
		}
		public static function resolve_template( array $form_config = array(), string $project_slug = '' ): ?array {
			$c = (string) ( $form_config['mail_template'] ?? '' );
			if ( $c === 'none' ) { return null; }
			if ( $c === '' ) { $c = (string) ( $GLOBALS['__test_templates_default'] ?? '' ); }
			return self::mail_template( $c );
		}
	}
}
if ( ! class_exists( 'Estandar\\Core\\Mail', false ) ) {
	class_alias( 'EstandarTest_Mail_Stub', 'Estandar\\Core\\Mail' );
}

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Capture as C;

// ── 1. normalize: identidad + atribución + spam ───────────────────────────
$n = C\Ingestion::normalize(array(
    'source' => 'form',
    'contact' => array('name' => '  Sandra Melisa ', 'email' => ' SANDRA@X.CL ', 'phone' => '+56912345678'),
    'content' => array('message' => 'Necesito ayuda para comprar materiales', 'source_form_id' => 'contacto'),
    'attribution' => array('utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'ABC123', 'landing_url' => 'https://x.cl/contacto'),
    'spam' => array('honeypot_filled' => true),
));
check('1a email normalizado lowercase', $n['email_normalized'] === 'sandra@x.cl');
check('1a2 nombre normalizado (iniciales mayúsculas)', $n['name'] === 'Sandra Melisa');
check('1a3 nombre: solo iniciales mayúsculas', C\Ingestion::normalize_name('MARÍA JOSÉ DE LA CRUZ') === 'María José De La Cruz');
check('1a4 nombre: espacios múltiples', C\Ingestion::normalize_name('  juan   pedro  ') === 'Juan Pedro');
check('1a5 nombre vacío', C\Ingestion::normalize_name('   ') === '');
check('1a6 phone incompleto rechazado', C\Ingestion::normalize(array('contact' => array('phone' => '123')))['phone_normalized'] === '');
check('1a7 phone 9 dígitos → 56 prefijo', C\Ingestion::normalize(array('contact' => array('phone' => '912345678')))['phone_normalized'] === '56912345678');
check('1a8 nombre unicode fancy transliterado (NFKC)', C\Ingestion::normalize_name('𝑪𝒂𝒕𝒉𝒆𝒓𝒊𝒏𝒆 𝑩𝒂𝒓𝒏𝒂𝒄𝒉𝒆𝒂') === 'Catherine Barnachea');
check('1a8b nombre adornado (emoji + deletreado) → palabra limpia', C\Ingestion::normalize_name('꧁ ✷ 𝚁 𝚘 𝚜 𝚊 𝚛 𝚒 𝚘 ✷ ꧂') === 'Rosario');
check('1a8c adorno sin letras → vacío', C\Ingestion::normalize_name('✷ ★ ꧁ ꧂') === '');
check('1a8d nombre normal con guion/apóstrofo intacto', C\Ingestion::normalize_name("JOSÉ-MARÍA D'ÁNGELO") === "José-María D'ángelo");
check('1a8e dos iniciales no se fusionan', C\Ingestion::normalize_name('J P') === 'J P');
check('1a9 rut canónico', C\Ingestion::normalize_rut('17.716.480-1') === '17.716.480-1');
check('1a10 rut sin separadores', C\Ingestion::normalize_rut('177164801') === '17.716.480-1');
check('1a11 rut concat dv', C\Ingestion::normalize_rut('151206360') === '15.120.636-0');
check('1a12 rut inválido rechazado', C\Ingestion::normalize_rut('123') === '');
check('1a13 rut dv K mayúscula', C\Ingestion::normalize_rut('12874376k') === '12.874.376-K');
check('1b phone normalizado', $n['phone_normalized'] === '56912345678');
check('1c gclid capturado', strpos($n['click_ids_json'], 'ABC123') !== false);
check('1d honeypot → spam flag', $n['spam_honeypot'] === 1);
check('1e uuid presente', strlen($n['capture_uuid']) === 36);
check('1f source_form_id', $n['source_form_id'] === 'contacto');

// META_ATTR_V1: los ids de anuncio de Meta Instant Form se persisten en
// click_ids_json (sin click-id, el ad_id es la única traza de atribución).
$na = C\Ingestion::normalize(array(
    'source'      => 'facebook',
    'contact'     => array('name' => 'Lead Meta', 'phone' => '+56912345678'),
    'attribution' => array('meta_ad_id' => 'AD9', 'meta_campaign_id' => 'CMP8', 'meta_adset_id' => 'ADST7', 'meta_leadgen_id' => 'LG6'),
));
check('1g meta_ad_id persistido', strpos($na['click_ids_json'], '"meta_ad_id";s:2:"AD9"') !== false || strpos($na['click_ids_json'], 'AD9') !== false);
check('1h meta_campaign_id persistido', strpos($na['click_ids_json'], 'CMP8') !== false);
check('1i meta_leadgen_id persistido', strpos($na['click_ids_json'], 'LG6') !== false);

// ── 2. normalize_phone edge cases ─────────────────────────────────────────
check('2a vacío', C\Ingestion::normalize_phone('') === '');
check('2b 9 dígitos → prefijo 56', C\Ingestion::normalize_phone('9 1234 5678') === '56912345678');
check('2c 8 dígitos → prefijo 56', C\Ingestion::normalize_phone('12345678') === '5612345678');
check('2d 0056 → quitar 00', C\Ingestion::normalize_phone('005612345678') === '5612345678');
check('2e ya con 56 se mantiene', C\Ingestion::normalize_phone('5612345678') === '5612345678');
check('2f no dígitos → vacío', C\Ingestion::normalize_phone('abc') === '');

// ── 3. Attribution: canal y evidencia de click ────────────────────────────
check('3a google cpc → google_ads', C\Attribution::channel('google', 'cpc') === 'google_ads');
check('3b fb paid → meta_ads', C\Attribution::channel('fb', 'paid') === 'meta_ads');
check('3c organic → google_organic', C\Attribution::channel('google', 'organic') === 'google_organic');
check('3d direct', C\Attribution::channel('', '') === 'direct');
check('3e gclid es evidencia', C\Attribution::has_click_evidence(array('gclid' => 'x'), 'google_ads') === true);
check('3f fbp SOLO no es evidencia (paridad contrato)', C\Attribution::has_click_evidence(array('meta_fbp' => 'x'), 'meta_ads') === false);
check('3g fbc sí es evidencia', C\Attribution::has_click_evidence(array('meta_fbc' => 'x'), 'meta_ads') === true);

// ── 4. Adoption: proyección de submission histórica (read-only) ───────────
$p = C\Adoption::project_submission(array(
    'lead_id' => 42, 'lead_uuid' => 'uuid-42', 'source_plugin' => 'estandar-chatbot',
    'submitted_at' => '2026-01-02 03:04:05', 'name' => 'Juan', 'email_normalized' => 'j@x.cl',
    'phone_normalized' => '56911112222', 'message' => 'hola', 'gclid' => 'G1', 'suspect' => 1,
));
check('4a origin_lead_id preservado', $p['origin_lead_id'] === 42);
check('4b uuid preservado', $p['capture_uuid'] === 'uuid-42');
check('4c timestamp preservado', $p['created_at'] === '2026-01-02 03:04:05');
check('4d click id del histórico', strpos($p['click_ids_json'], 'G1') !== false);
check('4e suspect → spam flag', $p['spam_honeypot'] === 1);
check('4f import no-op sin DB (guard)', C\Adoption::import(true, 5)['dry_run'] === true);
check('4g historical vacío sin DB', C\Adoption::historical(5) === array());
check('4h rollback no-op sin DB', C\Adoption::rollback_import() === 0);

// ── 5. Safety gate: proyección nunca toca tablas legadas ──────────────────
check('5a tabla de captura propia (no es_*)', strpos(C\Ingestion::TABLE, 'es_') !== 0);

// ── 6. Persist row convergencia (bug adopción: 20 cols vs 19 formats) ─────
$row = C\Ingestion::row_for_insert(C\Ingestion::normalize(array('contact' => array('name' => 'A'))));
check('6a data tiene 27 columnas', count($row['data']) === 29);
check('6b formats tiene 27', count($row['formats']) === 29);
check('6c origin_lead_id default 0', $row['data']['origin_lead_id'] === 0);
check('6d spam_honeypot default 0', $row['data']['spam_honeypot'] === 0);
$proj = C\Adoption::project_submission(array('lead_id' => 99, 'submitted_at' => '2026-01-01 00:00:00'));
$prow = C\Ingestion::row_for_insert($proj);
check('6e proyección adopción → 27 columnas y 27 formats', count($prow['data']) === 29 && count($prow['formats']) === 29);
check('6f origin preservado en proyección', $prow['data']['origin_lead_id'] === 99);
check('6g formats alineados con columnas', implode(',', array_keys($row['data'])) === implode(',', array_keys(C\Ingestion::columns())));

// ── 7. FormBridge: hooks guardados + ingesta + descubrimiento ─────────────
$k1 = C\FormBridge::webhook_key();
check('7a webhook key generada', strlen($k1) === 48);
check('7b webhook key estable', C\FormBridge::webhook_key() === $k1);
check('7c forms cf7 sin plugin → vacío', C\FormBridge::forms('cf7') === array());
check('7d forms gravity sin plugin → vacío', C\FormBridge::forms('gravity') === array());
check('7e fields v2 sin DB → vacío', C\FormBridge::fields('v2', 0) === array());
$r = C\FormBridge::ingest_form('cf7', 5, array('your-name' => 'Ana', 'your-email' => 'a@b.cl'));
check('7f ingest sin DB → id 0 (guard)', is_array($r) && (int) $r['id'] === 0);
check('7g fuentes válidas del bridge', is_array(C\FormBridge::forms('jotform')));

// ── 8. FormsAdoption: proyecciones puras (entries + survey responses) ─────
$entry = C\FormsAdoption::project_entry(array('id' => 41, 'form_id' => 3, 'data_json' => '{"name":"Ana Pérez","email":"ana@x.cl","mensaje":"Hola"}', 'status' => 'active', 'created_at' => '2026-01-02 03:04:05'));
check('8a entry source=form + origen form_id', $entry['source'] === 'form' && $entry['source_form_id'] === '3');
check('8b entry origin_lead_id = id legacy', (int) $entry['origin_lead_id'] === 41);
check('8c entry name/email extraídos de data_json', $entry['name'] === 'Ana Pérez' && $entry['email_normalized'] === 'ana@x.cl');
check('8d entry fields_json preserva data', json_decode($entry['fields_json'], true)['mensaje'] === 'Hola');
check('8e entry created_at heredado', $entry['created_at'] === '2026-01-02 03:04:05');
$resp = C\FormsAdoption::project_response(array('id' => 9, 'form_id' => 3, 'question_index' => 1, 'question_label' => 'Edad', 'question_type' => 'text', 'answer_value' => '34', 'answered_at' => '2026-02-02 03:04:05'));
check('8f response source=survey', $resp['source'] === 'survey');
check('8g response origin = id legacy', (int) $resp['origin_lead_id'] === 9);
check('8h response fields_json lleva pregunta+respuesta', json_decode($resp['fields_json'], true)['question_label'] === 'Edad' && json_decode($resp['fields_json'], true)['answer_value'] === '34');
check('8i import sin DB → imported 0 (guard)', C\FormsAdoption::import_entries(false)['imported'] === 0);
check('8j rollback sin DB → 0 (guard)', C\FormsAdoption::rollback_entries() === 0 && C\FormsAdoption::rollback_responses() === 0);

// ── 9. Migración controlada Forms legacy → nativos (pure logic) ───────────
check('9a legacy_form_type_map general → lead', C\Forms::legacy_form_type_map('general') === 'lead');
check('9b legacy_form_type_map lead_capture → lead', C\Forms::legacy_form_type_map('lead_capture') === 'lead');
check('9c legacy_form_type_map survey → survey (F2)', C\Forms::legacy_form_type_map('survey') === 'survey');
$lf_sample = array(
    'id' => 3, 'name' => 'demo', 'slug' => 'demo-demo', 'form_type' => 'lead_capture',
    'form_context' => 'generic', 'webhook_url' => 'https://x.cl/h', 'notify_emails' => 'a@b.cl',
    'fields_json' => '[{"type":"text","name":"nombre"}]', 'created_at' => '2026-01-01 00:00:00',
);
$native = C\Forms::legacy_to_native($lf_sample, 'lead');
check('9d legacy_to_native preserva id', (int) $native['id'] === 3);
check('9e legacy_to_native mapea tipo a lead', $native['form_type'] === 'lead');
$nc = json_decode($native['config_json'], true);
check('9f marcador _migrated_legacy presente', isset($nc[C\Forms::MIG_MARKER]) && (int) $nc[C\Forms::MIG_MARKER]['id'] === 3);
check('9g webhook + notify conservados', ($nc['webhook_url'] ?? '') === 'https://x.cl/h' && ($nc['notify_emails'] ?? '') === 'a@b.cl');
check('9h fields_json conservado', ($nc['fields_json'] ?? '') === '[{"type":"text","name":"nombre"}]');
$parity = C\Forms::parity_diff($lf_sample, $native);
check('9i parity_diff OK cuando coincide', ! empty($parity['parity']));
$parity_bad = C\Forms::parity_diff(array('id' => 3, 'webhook_url' => 'https://old.cl'), $native);
check('9j parity_diff detecta diffs (webhook distinto)', empty($parity_bad['parity']));

// ── 10. Forms: correo por Form (mail_settings + guards) ───────────────────
$ms = C\Forms::mail_settings(array('send_email' => 1, 'notify_emails' => 'a@b.cl', 'mail_subject' => 'S {{form_name}}'));
check('10a notify enabled + destinatarios', ! empty($ms['notify']['enabled']) && $ms['notify']['to'] === 'a@b.cl');
$ms2 = C\Forms::mail_settings(array('autoresponse' => array('enabled' => 1, 'email_field' => 'mail', 'subject' => 'Gracias')));
check('10b autoresponse config', ! empty($ms2['autoresponse']['enabled']) && $ms2['autoresponse']['email_field'] === 'mail');
$no = C\Forms::notify_submission(2, array(), array());
check('10c notify sin destinatario → sin_destinatarios (nunca disabled) [NOTIFY_ALWAYS_V1]', ($no['error'] ?? '') === 'sin_destinatarios');
// NOTIFY_GUARANTEE_V1: un form necesita destinatario (notify_emails / routing / operadores).
check('10n destinatario por notify_emails', C\Forms::form_has_designated_recipient(array('notify_emails' => 'a@b.cl')));
check('10o destinatario por campo routing', C\Forms::form_has_designated_recipient(array('sections' => array(array('fields' => array(array('type' => 'routing', 'name' => 'r')))))));
check('10p sin destinatario → false', ! C\Forms::form_has_designated_recipient(array('sections' => array(array('fields' => array(array('type' => 'text', 'name' => 'x')))))));
// AUTORESPONSE_DEFAULT_V1: la confirmación al remitente es DEFAULT ON.
$ms3 = C\Forms::mail_settings(array());
check('10d1 autoresponse default ON (sin config)', ! empty($ms3['autoresponse']['enabled']));
$ms4 = C\Forms::mail_settings(array('autoresponse' => array('enabled' => '0')));
check('10d2 autoresponse desactivada con enabled=0 explícito', empty($ms4['autoresponse']['enabled']));
$ms5 = C\Forms::mail_settings(array('autoresponse' => array('enabled' => '1', 'subject' => '')));
check('10d3 autoresponse subject vacío → default', $ms5['autoresponse']['subject'] === 'Gracias por contactarnos');

// CONFIG_GUARD_V1 (07/09, acacios form 17): el saneo al guardar impide que
// vuelvan a incrustarse marcadores TinyMCE o páginas HTML completas en el config.
$clean_txt = C\Forms::sanitize_config_text('<p>Hola<!--mce:protected r-->otected r--&gt;acias por escribirnos</p>');
check('10e1 quita marcadores mce', strpos($clean_txt, 'mce:') === false);
$clean_doc = C\Forms::sanitize_config_text("Bienvenido. Hemos recibido tus datos.\n\n<!DOCTYPE html><html lang='es'><body>Ha habido un error crítico</body></html>");
check('10e2 corta documento HTML completo pegado', strpos($clean_doc, '<!DOCTYPE') === false && strpos($clean_doc, 'error crítico') === false && strpos($clean_doc, 'Bienvenido') === 0);
$tree = C\Forms::sanitize_config_tree(array(
	'autoresponse' => array('enabled' => '1', 'body' => 'Hola G<!--mce:protected-->racias' . "\n<!DOCTYPE html><html></html>"),
	'mail_subject' => 'OK',
));
check('10e3 tree sanea solo cadenas marcadas y conserva el resto', strpos($tree['autoresponse']['body'], 'mce:') === false && strpos($tree['autoresponse']['body'], '<!DOCTYPE') === false && $tree['mail_subject'] === 'OK');
$raw_save = array('name' => 'Guard Form', 'form_type' => 'lead', 'status' => 'active',
	'config_json' => wp_json_encode(array('autoresponse' => array('body' => "Hola\nG<!--mce:p<!--mce:protected r-->otected r--&gt;acias por contactar" . "\n<!DOCTYPE html><html><body>error</body></html>"), 'mail_subject' => 'T')));
$san = C\Forms::sanitize($raw_save);
$cfg_san = json_decode($san['config_json'], true);
check('10e4 sanitize() limpia config_json al guardar (válido, sin mce/doctype, subject intacto)', is_array($cfg_san) && strpos($san['config_json'], 'mce:') === false && strpos($san['config_json'], '<!DOCTYPE') === false && ($cfg_san['mail_subject'] ?? '') === 'T');
// DUPLICATE_V1: clonar forms/surveys/postventa.
check('10f1 duplicate sin DB → 0', C\Forms::duplicate( 3 ) === 0);
$src_f = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Forms.php' );
check('10f2 duplicate() clona con slug único', strpos( $src_f, 'function duplicate( int $id ): int' ) !== false && strpos( $src_f, 'slug_exists' ) !== false);

// ── AUTORESPONSE_DELAYED_V1: envío diferido (config + agendamiento) ─────────
$GLOBALS['__test_cron'] = array();
$GLOBALS['__test_transients'] = array();
$cfg_delay = array('autoresponse_delayed' => array('enabled' => '1', 'delay_minutes' => 7, 'subject' => 'Más info'));
$dd1 = C\Forms::delayed_autoresponse_config($cfg_delay, 'altojaramillo');
check('10f1 delayed config: form block enabled + minutos', ! empty($dd1['enabled']) && (int) $dd1['delay_minutes'] === 7 && ! empty($dd1['from_form']));
$dd2 = C\Forms::delayed_autoresponse_config(array('autoresponse_delayed' => array('enabled' => '0', 'delay_minutes' => 3)), 'altojaramillo');
check('10f2 delayed config: enabled=0 → off', empty($dd2['enabled']));
$GLOBALS['__test_options'][C\Forms::OPTION_DELAY] = array('parronales' => array('enabled' => '1', 'delay_minutes' => 15));
$dd3 = C\Forms::delayed_autoresponse_config(array(), 'parronales');
check('10f3 delayed config: option por proyecto', ! empty($dd3['enabled']) && (int) $dd3['delay_minutes'] === 15 && ! empty($dd3['from_project']));
unset($GLOBALS['__test_options'][C\Forms::OPTION_DELAY]);
$cap_delay = array('fields' => array('project_slug' => 'altojaramillo'), 'id' => 42);
$sched1 = C\Forms::schedule_delayed_autoresponse(5, $cfg_delay, $cap_delay);
check('10f4 agendamiento con delay activo', ! empty($sched1['ok']) && (int) $sched1['minutes'] === 7 && (int) $sched1['capture_id'] === 42);
check('10f5 cron registrado con hook/args', isset($GLOBALS['__test_cron'][0]) && $GLOBALS['__test_cron'][0]['hook'] === C\Forms::CRON_DELAYED && $GLOBALS['__test_cron'][0]['args'] === array(42, 5, 'altojaramillo'));
$sched2 = C\Forms::schedule_delayed_autoresponse(5, array(), array('fields' => array('project_slug' => 'altojaramillo'), 'id' => 43));
check('10f6 sin config de delay → disabled (no agenda)', ! empty($sched2['error']) && $sched2['error'] === 'disabled' && empty($GLOBALS['__test_cron'][1]));
$rebuilt = C\Forms::capture_from_submission(array(
	'id' => 9, 'source' => 'form', 'source_form_id' => '3', 'message' => 'hola',
	'name' => 'Ana', 'email_normalized' => 'ana@x.cl', 'phone_normalized' => '+56',
	'fields_json' => wp_json_encode(array('nombre' => 'Ana', 'email' => 'ana@x.cl', 'project_slug' => 'parronales')),
));
check('10f7 capture_from_submission reconstruye fields/contact', (string) $rebuilt['fields']['project_slug'] === 'parronales' && (string) $rebuilt['contact']['name'] === 'Ana' && (int) $rebuilt['id'] === 9);

// ── CHAT_HARDEN_V1: filtro de salida + presupuesto diario ────────────────────
check('10g1 reply limpia pasa el filtro', C\AiChatPrompt::reply_is_clean( 'Gracias por tu interés, un ejecutivo te contactará.' ) === true );
check('10g2 reply que revela prompt se bloquea', C\AiChatPrompt::reply_is_clean( 'Mi prompt de sistema es confidencial.' ) === false );
check('10g3 reply con script se bloquea', C\AiChatPrompt::reply_is_clean( 'Respuesta <script>alert(1)</script>' ) === false );
check('10g4 reply con javascript: se bloquea', C\AiChatPrompt::reply_is_clean( 'Haz clic en javascript:alert(1)' ) === false );
$budget_default = C\AiChatPrompt::daily_budget();
check('10g5 presupuesto diario default >= 0', $budget_default >= 0 );
$ar = C\Forms::autoresponse_submission(2, array(), array());
check('10d autoresponse sin correo receptor → sin_motor/receptor_invalido', ! empty($ar['error']) && in_array($ar['error'], array('sin_motor', 'email_receptor_invalido'), true));
check('10e submissions sin DB → vacío (guard)', C\Forms::submissions(2) === array());
check('10f pipeline_for_capture sin DB → null (guard)', C\Forms::pipeline_for_capture(1) === null);

// ── EMAIL_DEDUPE_V1: autorespuesta / notify / diferida (resubmit) ──────────
$GLOBALS['__test_transients'] = array();
$cfg_ar = array('name' => 'Form AR', 'autoresponse' => array('enabled' => '1', 'body' => 'Hola {{nombre}}, gracias', 'email_field' => 'mail'));
$cap_ar = array(
	'id' => 100, 'fields' => array('mail' => 'ana@x.cl', 'nombre' => 'Ana', 'project_slug' => 'parronales'),
	'contact' => array('email' => 'ana@x.cl', 'name' => 'Ana'),
	'content' => array('source_form_id' => '9', 'message' => 'hola'), 'email_normalized' => 'ana@x.cl',
);
$ar1 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_ar);
check('11aa autorespuesta 1er envío OK', ! empty($ar1['ok']));
$ar2 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_ar);
check('11ab autorespuesta repetida → dedupe_window', ($ar2['error'] ?? '') === 'dedupe_window');
$cap_ar2 = array_merge($cap_ar, array(
	'fields' => array('mail' => 'pedro@x.cl', 'nombre' => 'Pedro', 'project_slug' => 'parronales'),
	'contact' => array('email' => 'pedro@x.cl', 'name' => 'Pedro'), 'email_normalized' => 'pedro@x.cl',
));
$ar3 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_ar2);
check('11ac autorespuesta destinatario distinto → se envía', ! empty($ar3['ok']));
$GLOBALS['__test_transients'] = array();

$cap_dly = array(
	'id' => 101, 'fields' => array('mail' => 'ana@x.cl', 'nombre' => 'Ana', 'project_slug' => 'parronales'),
	'contact' => array('email' => 'ana@x.cl', 'name' => 'Ana'),
	'content' => array('source_form_id' => '10', 'message' => 'hola'), 'email_normalized' => 'ana@x.cl',
);
$ar_d1 = C\Forms::send_delayed_autoresponse(array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'body' => 'ARX', 'email_field' => 'mail')), $cap_dly, 'parronales');
check('11ad diferida 1er envío OK', ! empty($ar_d1['ok']));
$ar_d2 = C\Forms::send_delayed_autoresponse(array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'body' => 'ARX', 'email_field' => 'mail')), $cap_dly, 'parronales');
check('11ae diferida repetida → dedupe_window', ($ar_d2['error'] ?? '') === 'dedupe_window');
$GLOBALS['__test_transients'] = array();

$cfg_n = array('name' => 'Form N', 'send_email' => 1, 'notify_emails' => 'team@x.cl');
$cap_n = array(
	'fields' => array('mail' => 'ana@x.cl', 'nombre' => 'Ana'),
	'contact' => array('email' => 'ana@x.cl', 'name' => 'Ana'),
	'content' => array('source_form_id' => '11', 'message' => 'hola'), 'email_normalized' => 'ana@x.cl',
);
$n1 = C\Forms::notify_submission(11, $cfg_n, $cap_n);
check('11af notify 1er envío OK', ! empty($n1['ok']));
$n2 = C\Forms::notify_submission(11, $cfg_n, $cap_n);
check('11ag notify repetido (mismo lead) → dedupe_window', ($n2['error'] ?? '') === 'dedupe_window');
$cap_n2 = array_merge($cap_n, array(
	'fields' => array('mail' => 'pedro@x.cl', 'nombre' => 'Pedro'),
	'contact' => array('email' => 'pedro@x.cl', 'name' => 'Pedro'), 'email_normalized' => 'pedro@x.cl',
));
$n3 = C\Forms::notify_submission(11, $cfg_n, $cap_n2);
check('11ah notify lead distinto → se envía', ! empty($n3['ok']));
$GLOBALS['__test_transients'] = array();
// Ventana deshabilitada por filtro → nunca dedupe.
$GLOBALS['__test_filters']['estandar_suite_capture_email_dedupe_seconds'] = 0;
$ar_f1 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_ar);
$ar_f2 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_ar);
check('11ai ventana=0 → se envía siempre', ! empty($ar_f1['ok']) && ! empty($ar_f2['ok']));
unset($GLOBALS['__test_filters']['estandar_suite_capture_email_dedupe_seconds']);
$GLOBALS['__test_transients'] = array();
// EMAIL_DEDUPE_EXEMPT_V1: remitente exento (pruebas del dueño) salta la ventana.
check('11aj email_dedupe_exempt default incluye dueño', C\Forms::email_dedupe_exempt('rodrigovaldes@me.com') === true);
check('11ak email_dedupe_exempt no exime a otros', C\Forms::email_dedupe_exempt('ana@x.cl') === false);
$cap_owner = array_merge($cap_ar, array(
	'fields' => array('mail' => 'rodrigovaldes@me.com', 'nombre' => 'Rodrigo', 'project_slug' => 'acacios'),
	'contact' => array('email' => 'rodrigovaldes@me.com', 'name' => 'Rodrigo'), 'email_normalized' => 'rodrigovaldes@me.com',
));
$ar_o1 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_owner);
$ar_o2 = C\Forms::autoresponse_submission(9, $cfg_ar, $cap_owner);
check('11al autorespuesta repetida del dueño → se envía (exento)', ! empty($ar_o1['ok']) && ! empty($ar_o2['ok']));
$n_o1 = C\Forms::notify_submission(11, $cfg_n, array_merge($cap_n, array('fields' => array('mail' => 'rodrigovaldes@me.com'), 'contact' => array('email' => 'rodrigovaldes@me.com', 'name' => 'R'), 'email_normalized' => 'rodrigovaldes@me.com')));
$n_o2 = C\Forms::notify_submission(11, $cfg_n, array_merge($cap_n, array('fields' => array('mail' => 'rodrigovaldes@me.com'), 'contact' => array('email' => 'rodrigovaldes@me.com', 'name' => 'R'), 'email_normalized' => 'rodrigovaldes@me.com')));
check('11am notify repetido del dueño → se envía (exento)', ! empty($n_o1['ok']) && ! empty($n_o2['ok']));
$GLOBALS['__test_transients'] = array();

// ── 11aj-11an. NOTIFY_FIELDS_V1: {{fields}} con campos reales + labels ─────
$cfg_f = array('sections' => array(array('fields' => array(
	array('name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'),
	array('name' => 'apellido', 'label' => 'Apellido', 'type' => 'text'),
	array('name' => 'comuna', 'label' => 'Comuna', 'type' => 'select', 'options' => array(array('value' => 'san_fernando', 'label' => 'San Fernando'))),
	array('name' => 'interes', 'label' => 'Interés', 'type' => 'routing', 'options' => array(array('value' => 'chimbarongo', 'label' => 'Chimbarongo'))),
	array('name' => '_routing_emails', 'label' => '_routing_emails', 'type' => 'hidden'),
	array('name' => 'utm_source', 'label' => 'utm_source', 'type' => 'text'),
))));
$cap_f = array('fields' => array(
	'nombre' => 'Luis', 'apellido' => 'Valdés', 'comuna' => 'san_fernando', 'interes' => 'chimbarongo',
	'_routing_emails' => 'cm@x.cl', 'utm_source' => 'google',
));
$sv = C\Forms::submission_vars(0, $cfg_f, $cap_f);
check('11aj fields incluye interés con label', strpos( $sv['fields'], 'Chimbarongo' ) !== false && strpos( $sv['fields'], 'Interés' ) !== false);
check('11ak fields incluye apellido y comuna con label', strpos( $sv['fields'], 'Valdés' ) !== false && strpos( $sv['fields'], 'San Fernando' ) !== false);
check('11al fields NO incluye hidden ni sistema', strpos( $sv['fields'], '_routing_emails' ) === false && strpos( $sv['fields'], 'utm_source' ) === false && strpos( $sv['fields'], 'google' ) === false && strpos( $sv['fields'], 'cm@x.cl' ) === false);
check('11am fields_text plano', strpos( $sv['fields_text'], 'Interés: Chimbarongo' ) !== false && strpos( $sv['fields_text'], '_routing_emails' ) === false);
check('11an is_hidden_field y option_label', C\Forms::is_hidden_field( '_x', 'text' ) === true && C\Forms::is_hidden_field( 'utm_source', 'text' ) === true && C\Forms::is_hidden_field( 'nombre', 'text' ) === false && C\Forms::field_option_label( array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ), 'a' ) === 'A');
// 11ao-11aq. NOTIFY_BODY_MIGRATION_V1: detecta el mail_body legacy duplicado.
$old_legacy = '<table><tr><td><strong>Nombre</strong></td><td>{{name}}</td></tr><tr><td><strong>Email</strong></td><td>{{email}}</td></tr></table><div><strong>Detalle completo</strong><br>{{fields}}</div>';
check('11ao legacy body detectado', C\Forms::is_legacy_notify_body( $old_legacy ) === true);
check('11ap nuevo default NO es legacy', C\Forms::is_legacy_notify_body( C\Forms::notification_body_default() ) === false);
check('11aq custom sin markers → NO', C\Forms::is_legacy_notify_body( '<p>Hola {{name}}, gracias.</p>' ) === false);

// RECONTACT_AR_V1: variante de autorespuesta para recontactos (config por
// form `autoresponse.body_recontact` o por proyecto legacy).
if ( ! class_exists( 'EstandarTest_Wpdb' ) ) {
	class EstandarTest_Wpdb {
		public $prefix = '';
		public $count = 0;
		public function prepare( $q ) { return $q; }
		public function get_var( $q ) { return $this->count; }
	}
}
$GLOBALS['wpdb'] = new EstandarTest_Wpdb();
$GLOBALS['wpdb']->count = 1;
check('11aj recontacto: solo la captura actual → false', C\Forms::capture_is_recontact(array('email_normalized' => 'ana@x.cl')) === false);
$GLOBALS['wpdb']->count = 2;
check('11ak recontacto: 2 submissions → true', C\Forms::capture_is_recontact(array('email_normalized' => 'ana@x.cl')) === true);
check('11al recontacto: sin identidad → false', C\Forms::capture_is_recontact(array('fields' => array('nombre' => 'X'))) === false);

$cap_rc = array(
	'id' => 200, 'email_normalized' => 'ana@x.cl',
	'fields' => array('mail' => 'ana@x.cl', 'nombre' => 'Ana', 'project_slug' => 'parronales'),
	'contact' => array('email' => 'ana@x.cl', 'name' => 'Ana'),
	'content' => array('source_form_id' => '12', 'message' => 'hola'),
);
$cfg_rc = array('name' => 'Form RC', 'autoresponse' => array('enabled' => '1', 'email_field' => 'mail', 'body' => 'BODY_NORMAL', 'body_recontact' => 'BODY_RECONTACT', 'subject_recontact' => 'Re: vuelves a contactar'));
$GLOBALS['__test_transients'] = array();
$ar_rc = C\Forms::autoresponse_submission(12, $cfg_rc, $cap_rc);
check('11am recontacto usa body_recontact', ! empty($ar_rc['ok']) && ($GLOBALS['__test_mail_last']['html'] ?? '') === 'BODY_RECONTACT');
check('11an recontacto usa subject_recontact', ($GLOBALS['__test_mail_last']['subject'] ?? '') === 'Re: vuelves a contactar');
$cfg_nrc = array('name' => 'Form NRC', 'autoresponse' => array('enabled' => '1', 'email_field' => 'mail', 'body' => 'BODY_NORMAL'));
$GLOBALS['__test_transients'] = array();
$ar_nrc = C\Forms::autoresponse_submission(12, $cfg_nrc, $cap_rc);
check('11ao sin body_recontact → body normal (sin cambio)', ($GLOBALS['__test_mail_last']['html'] ?? '') === 'BODY_NORMAL');
$GLOBALS['__test_options']['estandar_leads_autoresponse_settings'] = array(
	'uuid-p' => array('enabled' => 1, 'project_slug' => 'parronales', 'body' => 'LEGACY_BODY', 'body_recontact' => 'LEGACY_RECONTACT'),
);
$cfg_leg = array('name' => 'Form L', 'autoresponse' => array('enabled' => '1', 'email_field' => 'mail'));
$GLOBALS['__test_transients'] = array();
$ar_leg = C\Forms::autoresponse_submission(12, $cfg_leg, $cap_rc);
check('11ap recontacto usa body_recontact legacy (envuelto)', ! empty($ar_leg['ok']) && strpos((string) ($GLOBALS['__test_mail_last']['html'] ?? ''), 'LEGACY_RECONTACT') !== false);
unset($GLOBALS['__test_options']['estandar_leads_autoresponse_settings']);
unset($GLOBALS['wpdb']);

// NOTIFY_ROUTING_V1: bifurcación de destinatarios por valor de respuesta
// (misma lógica que el form "Contacto General" de la home → resolver desde
// cualquier camino: form nativo y cierre de survey por etapas).
$r_cfg = array(
	'send_email'          => 1,
	'notify_emails'       => 'default@x.cl',
	'notify_route_field'  => 'interesadoen',
	'notify_route_map_text' => "Inmobiliaria=inmobiliaria@x.cl, gestion@x.cl\nConstructora=cm@x.cl",
);
check('10g routing sin valor → fallback notify_emails', C\Forms::resolve_notify_to($r_cfg, array('fields' => array())) === 'default@x.cl');
check('10h routing matchea valor → correos de la regla', C\Forms::resolve_notify_to($r_cfg, array('fields' => array('interesadoen' => 'Inmobiliaria'))) === 'inmobiliaria@x.cl, gestion@x.cl');
check('10i routing case-insensitive (CONSTRUCTORA → cm@)', C\Forms::resolve_notify_to($r_cfg, array('fields' => array('interesadoen' => 'CONSTRUCTORA'))) === 'cm@x.cl');
check('10j routing valor sin regla → fallback', C\Forms::resolve_notify_to($r_cfg, array('fields' => array('interesadoen' => 'Otra cosa'))) === 'default@x.cl');
check('10k sin routing config → notify_emails', C\Forms::resolve_notify_to(array('send_email' => 1, 'notify_emails' => 'a@b.cl'), array('fields' => array('x' => 'y'))) === 'a@b.cl');
check('10l routing con regla vacía → fallback', C\Forms::resolve_notify_to(array('send_email' => 1, 'notify_emails' => 'd@x.cl', 'notify_route_field' => 'a', 'notify_route_map_text' => 'X='), array('fields' => array('a' => 'X'))) === 'd@x.cl');

// ROUTING_URL_V1: cada opción de un campo 'routing' resuelve proyecto + correos
// (mail) + URL de redirección (no excluyente). routing_option() es la lógica
// pura que FormRenderer aplica en el submit (ROUTING_FIELD_V1).
$ro_field = array( 'name' => 'a_quien_te_diriges', 'type' => 'routing', 'options' => array(
	array( 'value' => 'inmobiliaria', 'label' => 'Inmobiliaria', 'project' => 'altojaramillo', 'emails' => 'cm@x.cl', 'url' => '', 'pipeline' => true ),
	array( 'value' => 'constructora', 'label' => 'Constructora', 'project' => 'parronales', 'emails' => '', 'url' => 'https://constructora.cl/gracias', 'pipeline' => false ),
	array( 'value' => 'gestion', 'label' => 'Gestión', 'project' => '', 'emails' => 'gestion@x.cl', 'url' => 'https://x.cl/gracias', 'pipeline' => true ),
) );
$ro_a = C\Forms::routing_option( $ro_field, 'inmobiliaria' );
check('28a routing_option mail: proyecto resuelto', $ro_a['project_slug'] === 'altojaramillo' && $ro_a['project_area'] === 'altojaramillo');
check('28b routing_option mail: correos + sin url + pipeline', $ro_a['emails'] === 'cm@x.cl' && $ro_a['redirect'] === '' && $ro_a['pipeline'] === true);
$ro_b = C\Forms::routing_option( $ro_field, 'constructora' );
check('28c routing_option url: redirect + sin correos + simple', $ro_b['redirect'] === 'https://constructora.cl/gracias' && $ro_b['emails'] === '' && $ro_b['pipeline'] === false);
$ro_c = C\Forms::routing_option( $ro_field, 'gestion' );
check('28d routing_option ambos: correos + redirect juntos', $ro_c['emails'] === 'gestion@x.cl' && $ro_c['redirect'] === 'https://x.cl/gracias');
check('28e routing_option sin match → array vacío', C\Forms::routing_option( $ro_field, 'otra' ) === array());
check('28f routing_option sin opciones → array vacío', C\Forms::routing_option( array( 'name' => 'r', 'type' => 'routing' ), 'x' ) === array());

// EMAIL_IDENTITY_V1: identidad por Form (override vs default compartido).
$mi = C\Forms::mail_identity(array('mail_brand_color' => '#ff0000', 'mail_logo_url' => 'https://x.cl/logo.png', 'mail_header_text' => 'Empresa Demo', 'mail_footer_text' => 'Footer A'));
check('10m override brand_color por form', $mi['brand_color'] === '#ff0000');
check('10n override logo por form', $mi['logo_url'] === 'https://x.cl/logo.png');
check('10o override header_text por form', $mi['header_text'] === 'Empresa Demo');
check('10p override footer por form', $mi['footer_text'] === 'Footer A');
check('10q sin override → default brand_color', C\Forms::mail_identity(array())['brand_color'] === '#1a73e8');
$m0 = C\Forms::mail_identity(array('mail_brand_color' => '', 'mail_logo_url' => 'https://x.cl/l.png'));
check('10r override parcial (logo sí, color default)', $m0['logo_url'] === 'https://x.cl/l.png' && $m0['brand_color'] === '#1a73e8');

// REMITENTE_V1: mail_settings resuelve defaults (admin/sitio) y override por form.
$GLOBALS['__test_options']['admin_email'] = 'admin@x.cl';
$ms = C\Forms::mail_settings(array());
check('10s notify sender default = nombre sitio', $ms['notify']['sender'] === '');
check('10t notify from_email default = admin_email', $ms['notify']['from_email'] === 'admin@x.cl');
check('10u autoresponse from_email default = admin_email', $ms['autoresponse']['from_email'] === 'admin@x.cl');
$ms2 = C\Forms::mail_settings(array('mail_sender_name' => 'Empresa Demo', 'mail_from_email' => 'no-reply@cliente1.example.com', 'autoresponse' => array('sender' => 'Equipo', 'from_email' => 'hola@cliente1.example.com')));
check('10v override notify sender/from', $ms2['notify']['sender'] === 'Empresa Demo' && $ms2['notify']['from_email'] === 'no-reply@cliente1.example.com');
check('10w override autoresponse sender/from', $ms2['autoresponse']['sender'] === 'Equipo' && $ms2['autoresponse']['from_email'] === 'hola@cliente1.example.com');

// NOTIFY_CC_V1: copias SIEMPRE presentes aunque el routing por campo cambie
// el To (form nativo y survey por etapas comparten la semántica, pure).
check('cc_always: mail_settings lo expone', C\Forms::mail_settings(array('notify_cc_always' => 'siempre@x.cl'))['notify']['cc_always'] === 'siempre@x.cl');
$GLOBALS['__test_options']['admin_email'] = 'admin@x.cl';
$cc = C\Forms::notify_cc( array( 'cc_admin' => 1, 'cc_always' => 'siempre@x.cl, destino@x.cl' ), 'destino@x.cl' );
check('cc_always: admin en CC', in_array( 'admin@x.cl', $cc, true ) );
check('cc_always: siempre@ en CC', in_array( 'siempre@x.cl', $cc, true ) );
check('cc_always: dedup contra To (destino@ no en CC)', ! in_array( 'destino@x.cl', $cc, true ) );
$cc2 = C\Forms::notify_cc( array( 'cc_admin' => 0, 'cc_always' => 'siempre@x.cl' ), 'x@y.cl' );
check('cc_always: admin desactivado → ausente', ! in_array( 'admin@x.cl', $cc2, true ) && in_array( 'siempre@x.cl', $cc2, true ) );

// FUNNEL_V1 (reactivado 2026-09-22): el modo del form manda. El aviso simple se
// suprime SOLO en 'funnel' (gate en Actions::run); notify_submission queda
// incondicional para no romper el reenvío manual por CLI.
check('10x funnel_mode() vacío', C\Forms::funnel_mode(array()) === '');
check('10y funnel_mode() funnel', C\Forms::funnel_mode(array('form_funnel_mode' => 'funnel')) === 'funnel');
check('10z funnel_mode() simple', C\Forms::funnel_mode(array('form_funnel_mode' => 'simple')) === 'simple');
$n_funnel = C\Forms::notify_submission(99, array('send_email' => 1, 'notify_emails' => 'x@y.cl', 'form_funnel_mode' => 'funnel'), array());
check('11a notify en modo funnel → ya NO se suprime', ! empty( $n_funnel['ok'] ) && empty( $n_funnel['error'] ));
$n_simple = C\Forms::notify_submission(99, array('send_email' => 1, 'form_funnel_mode' => 'simple'), array());
check('11b notify en modo simple → no suprimido por funnel', ( $n_simple['error'] ?? '' ) !== 'funnel_mode');

// ROUTING_INDEPENDENT_V1: la notificación por campo 'routing' no depende del
// switch genérico send_email (apagarlo mataba también el routing).
$n_route = C\Forms::notify_submission(199, array('send_email' => 0, 'notify_emails' => 'x@y.cl'), array('fields' => array('_routing_emails' => 'r@z.cl')), array('skip_dedupe' => true));
check('11r1 ROUTING_INDEPENDENT_V1: send_email off + routing → no se corta', ($n_route['error'] ?? '') !== 'disabled' && !empty($n_route['ok']));
check('11r2 ROUTING_INDEPENDENT_V1: To = routing', ($GLOBALS['__test_mail_last']['to'] ?? '') === 'r@z.cl');
$n_noroute = C\Forms::notify_submission(198, array('send_email' => 0, 'notify_emails' => 'x@y.cl'), array('fields' => array()), array('skip_dedupe' => true));
check('11r3 NOTIFY_ALWAYS_V1: send_email off + notify_emails → no se corta (envía)', ($n_noroute['error'] ?? '') !== 'disabled' && !empty($n_noroute['ok']));

// ROUTING_FORM_AUTOCONFIG_V1: form que DECLARA campo 'routing' → aviso genérico
// apagado (solo routing), diferido apagado, y el correo usa el template default.
$cfg_rf = array(
    'send_email'    => 1,
    'notify_emails' => 'gen@x.cl',
    'sections'      => array( array( 'fields' => array( array( 'type' => 'routing', 'name' => 'area' ) ) ) ),
);
check('11s1 has_routing_field detecta campo routing', C\Forms::has_routing_field($cfg_rf) === true);
check('11s2 has_routing_field false sin campo routing', C\Forms::has_routing_field(array('sections' => array(array('fields' => array(array('type' => 'text', 'name' => 'n')))))) === false);
$GLOBALS['__test_templates_default'] = 'base';
$n_rf = C\Forms::notify_submission(197, $cfg_rf, array('fields' => array('_routing_emails' => 'r@z.cl')), array('skip_dedupe' => true));
check('11s3 routing form: solo al destino del routing (no notify_emails)', ($GLOBALS['__test_mail_last']['to'] ?? '') === 'r@z.cl' && !empty($n_rf['ok']));
check('11s4 routing form: usa el template default', strpos((string) ($GLOBALS['__test_mail_last']['html'] ?? ''), '<footer>') !== false);
unset($GLOBALS['__test_templates_default']);
$n_rf2 = C\Forms::notify_submission(196, $cfg_rf, array('fields' => array()), array('skip_dedupe' => true));
check('11s5 routing form sin selección → routing_sin_destino', ($n_rf2['error'] ?? '') === 'routing_sin_destino');
$d_rf = C\Forms::schedule_delayed_autoresponse(195, array_merge($cfg_rf, array('autoresponse_delayed' => array('enabled' => 1, 'delay_minutes' => 10))), array('id' => 1, 'fields' => array()));
check('11s6 routing form: diferido apagado', ($d_rf['error'] ?? '') === 'routing_form');

// ROUTING_FIELD_V1: el payload de LeadsBridge propaga modo + correos extra.
require_once dirname(__DIR__) . '/includes/LeadsBridge.php';
$rp = C\LeadsBridge::build_payload(array(
    'name' => 'Luis', 'email_normalized' => 'a@b.cl', 'message' => 'Hola', 'source_form_id' => '7',
    'fields_json' => json_encode(array('a_quien_te_diriges' => 'inmobiliaria', 'project_slug' => 'altojaramillo', '_form_funnel_mode' => 'funnel', '_routing_emails' => 'cm@x.cl, extra@y.cl')),
));
check('11c payload funnel_mode = funnel', ($rp['context']['funnel_mode'] ?? '') === 'funnel');
check('11d payload notify_extra presente', ($rp['context']['notify_extra'] ?? '') === 'cm@x.cl, extra@y.cl');
check('11e payload project_slug', ($rp['context']['project_slug'] ?? '') === 'altojaramillo');
check('11f notify con routing → no funnel_mode si el modo capturado es simple', (C\Forms::notify_submission(99, array('send_email' => 1, 'form_funnel_mode' => 'funnel'), array('fields' => array('_form_funnel_mode' => 'simple')))['error'] ?? '') !== 'funnel_mode');

// NAME_JOIN_V1: build_payload une nombre + apellido y propaga form_fields.
$rp_name = C\LeadsBridge::build_payload(array(
    'name' => 'Caroline', 'email_normalized' => 'caroline@x.cl',
    'fields_json' => json_encode(array('nombre' => 'Caroline', 'apellido' => 'Orellana', 'email' => 'caroline@x.cl', 'telefono' => '56911112222', 'project_slug' => 'parronales')),
));
check('11f2 NAME_JOIN: contact.name = nombre + apellido', ($rp_name['contact']['name'] ?? '') === 'Caroline Orellana');
check('11f3 NAME_JOIN: form_fields propagado', ($rp_name['form_fields']['apellido'] ?? '') === 'Orellana');
check('11f4 NAME_JOIN: meta.sanitized_raw_fields para el notifier', ($rp_name['meta']['sanitized_raw_fields']['nombre'] ?? '') === 'Caroline');
$rp_name2 = C\LeadsBridge::build_payload(array('name' => 'Diego', 'fields_json' => json_encode(array('nombre' => 'Diego'))));
check('11f5 NAME_JOIN: sin apellido → nombre tal cual', ($rp_name2['contact']['name'] ?? '') === 'Diego');
check('11f6 NAME_JOIN: sin campos → fallback capture.name', C\LeadsBridge::build_payload(array('name' => 'Ana', 'fields_json' => '[]'))['contact']['name'] === 'Ana');

// ── 11. Editor por subtabs: registro canónico + resolución + nav ────────────
require_once dirname(__DIR__) . '/../estandar-core/includes/Admin.php';
 $st_subtabs = C\Admin::editor_subtabs();
 check('11a registro de subtabs (4 módulos: configuracion/diseno/correos/entradas)', array_keys($st_subtabs) === array('configuracion', 'diseno', 'correos', 'entradas'));
 check('11b subtab por defecto = configuracion (primera clave)', array_key_first($st_subtabs) === 'configuracion');
 check('11b2 general y repeticion ya no son subtabs', !isset($st_subtabs['general']) && !isset($st_subtabs['repeticion']));
 unset($_GET['st']);
 check('11c sin st → configuracion (default)', C\Admin::resolve_editor_subtab() === 'configuracion');
 $_GET['st'] = 'configuracion';
 check('11d st válido → configuracion', C\Admin::resolve_editor_subtab() === 'configuracion');
 $_GET['st'] = 'zzz';
 check('11e st inválido → configuracion (fallback seguro)', C\Admin::resolve_editor_subtab() === 'configuracion');
$_GET['st'] = 'CORREOS';
check('11f st mayúsculas → normalizado a correos', C\Admin::resolve_editor_subtab() === 'correos');
$_GET['st'] = 'general';
check('11g deep-link st=general → alias configuracion', C\Admin::resolve_editor_subtab() === 'configuracion');
$_GET['st'] = 'repeticion';
check('11h deep-link st=repeticion → alias correos', C\Admin::resolve_editor_subtab() === 'correos');
$_GET['st'] = 'confirmacion';
check('11h2 deep-link st=confirmacion → alias correos', C\Admin::resolve_editor_subtab() === 'correos');
$_GET['st'] = 'correo';
check('11h3 deep-link st=correo → alias correos', C\Admin::resolve_editor_subtab() === 'correos');
$_GET['st'] = 'entradas';
check('11i st=entradas válido', C\Admin::resolve_editor_subtab() === 'entradas');
// CORREOS_V1: sub-sub-tabs internas del panel «Correos y confirmación» + deep-links.
$st_sst = C\Admin::editor_sstabs();
check('11j sstabs (5 bloques del pipeline)', array_keys($st_sst) === array('pantalla', 'autoresponse', 'diferido', 'notify', 'identidad'));
unset($_GET['sst']); $_GET['st'] = '';
check('11k sin sst/st → pantalla (default)', C\Admin::resolve_editor_sst() === 'pantalla');
check('11k2 st=confirmacion → sst pantalla', C\Admin::resolve_editor_sst('confirmacion') === 'pantalla');
check('11k3 st=correo → sst notify', C\Admin::resolve_editor_sst('correo') === 'notify');
$_GET['sst'] = 'diferido';
check('11l sst válido ?sst=diferido → diferido', C\Admin::resolve_editor_sst('correo') === 'diferido');
unset($_GET['sst']);
$nav = \Estandar\Core\Admin::subtab_nav_html(array('general' => array('label' => 'General', 'url' => 'http://x/?st=general'), 'correos' => array('label' => 'Correos', 'url' => 'http://x/?st=correos')), 'correos');
check('11m nav marca la subtab activa', strpos($nav, 'nav-tab-active') !== false && strpos($nav, 'data-st="correos"') !== false);
check('11h nav escapa etiquetas', strpos(\Estandar\Core\Admin::subtab_nav_html(array('x' => array('label' => '<b>X</b>', 'url' => '#')), 'x'), '&lt;b&gt;X&lt;/b&gt;') !== false);
$_GET['st'] = '';

// ── 12. Regresión: merge, renderable_fields, repeat_visibility, reason ──────
// renderable_fields: sections y fields_json
$rf = C\Forms::renderable_fields(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text'), array('name' => 'y', 'label' => 'Y', 'type' => 'email'))), array('title' => 'B', 'fields' => array(array('name' => 'z', 'label' => 'Z', 'type' => 'tel'))))));
check('12a renderable_fields sections → plano', count($rf) === 3 && $rf[0]['name'] === 'x' && $rf[2]['name'] === 'z');
check('12b renderable_fields fields_json → plano', count(C\Forms::renderable_fields(array('fields_json' => array(array('name' => 'a'), array('name' => 'b'))))) === 2);
check('12c renderable_fields vacío → []', C\Forms::renderable_fields(array()) === array());
// merge_config_json: no destructivo + parcial
$cur = json_encode(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text')))), 'repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 30, 'unit' => 'minutes', 'behavior' => 'show_again', 'message' => 'Vuelve'), 'mail_body' => 'Cuerpo {{fields}}'));
$m_empty = C\Forms::merge_config_json($cur, '{}');
check('12d merge con post vacío conserva documento', strlen($m_empty) > 100 && isset(json_decode($m_empty, true)['mail_body']));
$m_partial = C\Forms::merge_config_json($cur, json_encode(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X2', 'type' => 'text')))))));
$mp = json_decode($m_partial, true);
check('12e merge parcial: sección actualizada', $mp['sections'][0]['fields'][0]['label'] === 'X2');
check('12f merge parcial: claves ausentes preservadas', isset($mp['repeat_visibility']) && isset($mp['mail_body']));
check('12g merge no vacía secciones por POST parcial', count($mp['sections']) === 1);
check('12h merge post inválido → conserva actual', C\Forms::merge_config_json($cur, 'no-json') === json_encode(json_decode($cur, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// repeat_visibility: fallback always_visible
$rv = C\Forms::repeat_visibility(array());
check('12i default always_visible', $rv['mode'] === 'always_visible' && $rv['enabled'] === false);
$rv2 = C\Forms::repeat_visibility(array('repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 30, 'unit' => 'days')));
check('12j hide_temporarily válido', $rv2['mode'] === 'hide_temporarily' && $rv2['duration'] === 30 && $rv2['unit'] === 'days' && $rv2['enabled'] === true);
$rv3 = C\Forms::repeat_visibility(array('repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 0, 'unit' => 'semanas')));
check('12k duración 0/unidad inválida → no oculta (fallback seguro)', $rv3['enabled'] === false && $rv3['unit'] === 'minutes');
check('12l repeat_visibility_seconds días', C\Forms::repeat_visibility_seconds(array('duration' => 2, 'unit' => 'days')) === 172800);
// repeat_reasons + repeat_priority determinística
check('12m razones canónicas', array_keys(C\Forms::repeat_reasons()) === array('not_contacted_yet', 'add_info', 'request_changed', 'urgent', 'other'));
$p1 = C\Forms::repeat_priority('not_contacted_yet', 2, false);
check('12n not_contacted_yet sin contacto → escalación', $p1['score_delta'] === 30 && $p1['escalation'] === true);
$p2 = C\Forms::repeat_priority('not_contacted_yet', 2, true);
check('12o not_contacted_yet con contacto → prioridad, sin escalación', $p2['score_delta'] === 30 && $p2['escalation'] === false);
check('12p urgent → escalación', C\Forms::repeat_priority('urgent', 1, true)['escalation'] === true && C\Forms::repeat_priority('urgent', 1, true)['score_delta'] === 50);
check('12q request_changed/add_info/other', C\Forms::repeat_priority('request_changed', 1, true)['score_delta'] === 20 && C\Forms::repeat_priority('add_info', 1, true)['score_delta'] === 10 && C\Forms::repeat_priority('invalido', 1, true)['score_delta'] === 5);
// guards (sin DB / formularios inexistentes)
check('12r record_repeat_reason sin DB → no_db', !empty(C\Forms::record_repeat_reason(1, 'urgent')['error']));
check('12s validate sin DB → inexistente', C\Forms::validate(1)['valid'] === false);
check('12t restore_legacy_config sin DB → no_db', in_array('no_db', C\Forms::restore_legacy_config(2)['reasons'], true));
check('12u inspect sin DB → inexistente', C\Forms::inspect(1)['ok'] === false);
check('12v snapshot_config sin DB → null', C\Forms::snapshot_config(1) === null);
// config_loss_reason (protección permanente contra degradación a {})
$cur_full = array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text')))), 'fields_json' => array(array('name' => 'y')));
check('12w pérdida no solicitada de contenido → motivo', C\Forms::config_loss_reason($cur_full, array()) === 'perdida_de_contenido_no_solicitada');
check('12x config actual vacía → sin bloqueo', C\Forms::config_loss_reason(array(), array()) === '');
check('12y config nueva inválida → motivo', C\Forms::config_loss_reason($cur_full, null) === 'config_invalida');
 check('12z secciones presentes en nuevo → sin bloqueo', C\Forms::config_loss_reason($cur_full, array('sections' => array(array('title' => 'B', 'fields' => array())))) === '');

// ── 12A. Robustez de LAYOUT (merge profundo + protección de column_width) ──
// El motor es único (lead/survey/incident usan sections[].fields[]): el merge
// debe preservar column_width de cada campo ante guardados no relacionados.
$cur_cw = json_encode(array(
	'sections' => array(array(
		'title' => 'Datos',
		'fields' => array(
			array('name' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'column_width' => 2),
			array('name' => 'email',  'label' => 'Email',  'type' => 'email', 'column_width' => 2),
			array('name' => 'mensaje', 'label' => 'Mensaje', 'type' => 'textarea', 'column_width' => 4),
		),
	)),
));
// Renombrar el form reenvía los campos SIN column_width (pérdida típica).
$posted_cw = json_encode(array(
	'sections' => array(array(
		'title' => 'Datos',
		'fields' => array(
			array('name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'),
			array('name' => 'email',  'label' => 'Email',  'type' => 'email'),
			array('name' => 'mensaje', 'label' => 'Mensaje', 'type' => 'textarea'),
		),
	)),
));
$merged_cw = json_decode( C\Forms::merge_config_json( $cur_cw, $posted_cw ), true );
check('12A1 merge profundo conserva column_width sin payload', $merged_cw['sections'][0]['fields'][0]['column_width'] === 2 && $merged_cw['sections'][0]['fields'][2]['column_width'] === 4);
// Si el payload SÍ trae column_width explícito, prevalece (edición real).
$posted_cw2 = json_encode(array('sections' => array(array('title' => 'Datos', 'fields' => array(array('name' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'column_width' => 3))))));
$merged_cw2 = json_decode( C\Forms::merge_config_json( $cur_cw, $posted_cw2 ), true );
check('12A2 column_width explícito del payload prevalece', $merged_cw2['sections'][0]['fields'][0]['column_width'] === 3);
// Protección: pérdida de anchos → bloqueo con motivo específico.
check('12A3 pérdida de column_width → motivo', C\Forms::config_loss_reason(
	json_decode( $cur_cw, true ),
	json_decode( $posted_cw, true )
) === 'perdida_de_anchos_de_campos_no_solicitada');
// Survey: campos sin column_width (uno a uno) NO se bloquean.
$cur_survey = json_encode(array('sections' => array(array('title' => 'P', 'fields' => array(array('name' => 'q1', 'label' => 'Q1', 'type' => 'text')))), 'survey_config' => array('step_by_step' => 1)));
$new_survey = json_encode(array('sections' => array(array('title' => 'P', 'fields' => array(array('name' => 'q1', 'label' => 'Q1x', 'type' => 'text')))), 'survey_config' => array('step_by_step' => 1)));
check('12A4 survey sin column_width → sin bloqueo', C\Forms::config_loss_reason( json_decode( $cur_survey, true ), json_decode( $new_survey, true ) ) === '');
check('12A5 merge survey conserva survey_config', ( C\Forms::merge_config_json( $cur_survey, $new_survey ) !== '' ));


// ── 13. Tipo canónico intercambiable (mismo motor) + conversión ────────────
check('13a types canónicos lead/survey (incident retirado)', array_keys(C\Forms::types()) === array('lead', 'survey'));
check('13a2 incident ya no se ofrece como tipo', ! isset(C\Forms::types()['incident']));
check('13a3 guardar un form con type incident lo pasa a lead', C\Forms::sanitize(array('name'=>'X','form_type'=>'incident'))['form_type'] === 'lead');
check('13b type_exclusive_keys survey incluye survey_config', in_array('survey_config', C\Forms::type_exclusive_keys('survey'), true));
check('13c type_exclusive_keys incident incluye incidents_config', in_array('incidents_config', C\Forms::type_exclusive_keys('incident'), true));
check('13d type_exclusive_keys lead vacío', C\Forms::type_exclusive_keys('lead') === array());
check('13e convert_type sin DB → inexistente', C\Forms::convert_type(1, 'survey', array())['error'] === 'inexistente');
check('13f type_conversion_info sin DB → inexistente', C\Forms::type_conversion_info(1, 'survey')['error'] === 'inexistente');
$info_lead_survey = C\Forms::type_conversion_info(0, 'survey');
check('13g type_conversion_info documento vacío → warning frontend lead-only', !empty($info_lead_survey['warnings']) || $info_lead_survey['error'] === 'inexistente');

// ── 14. MS-2: Conversation Engine ──────────────────────────────────────────
use Estandar\Capture\ConversationEngine as CE;
// 14z REGRESIÓN (09/09 acacios, conversión form 20 postventa→lead): el hook
// estandar_suite_capture_form_type_changed se dispara con UN payload array
// (Forms::convert_type → do_action(..., $payload)) pero on_type_changed exigía
// (int $form_id, string $from, string $to) → TypeError fatal (WP hook le pasa
// el array como $form_id). Ahora acepta el payload array y extrae campos.
check('14z1 firma on_type_changed acepta array (payload)', (bool) ( new ReflectionMethod( CE::class, 'on_type_changed' )->getNumberOfParameters() ) >= 1 && strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/ConversationEngine.php' ), 'public static function on_type_changed( array $payload ): void' ) !== false );
$GLOBALS['__test_options']['estandar_suite_conversation_config'] = array();
// payload canónico (lo que emite Forms::convert_type)
CE::on_type_changed( array( 'form_id' => 999, 'old_type' => 'incident', 'new_type' => 'lead' ) );
$conv = $GLOBALS['__test_options']['estandar_suite_conversation_config'];
check( '14z2 payload array → guarda experience_type', isset( $conv['form_999']['experience_type'] ) && $conv['form_999']['experience_type'] === 'lead' );
// payload con form_id 0 → se ignora sin fatal (guard)
CE::on_type_changed( array( 'form_id' => 0 ) );
check( '14z3 form_id 0 se ignora sin fatal', true );
CE::on_type_changed( array() );
check( '14z4 payload vacío se ignora sin fatal', true );
unset( $GLOBALS['__test_options']['estandar_suite_conversation_config'] );

check('14a 9 experiencias (incident/postventa retirado)', count(CE::EXPERIENCES) === 9 && !CE::is_conversational('incident'));
check('14b lead en experiencias', CE::is_conversational('lead'));
check('14c survey en experiencias', CE::is_conversational('survey'));
check('14d quote en experiencias', CE::is_conversational('quote'));
check('14e diagnostico en experiencias', CE::is_conversational('diagnostico'));
check('14f recovery en experiencias', CE::is_conversational('recovery'));
check('14g assessment en experiencias', CE::is_conversational('assessment'));
check('14h no conversacional → false', !CE::is_conversational('blog_post'));
check('14i experience label lead', CE::experience_label('lead') === 'Contacto');
check('14j experience label survey', CE::experience_label('survey') === 'Encuesta');
check('14k desconocida → raw', CE::experience_label('bogus') === 'bogus');
check('14l widgets catálogo', in_array('text', CE::widgets(), true) && in_array('select', CE::widgets(), true));
check('14m completion strategies', in_array('create_lead', CE::completion_strategies(), true));

// ── 15. MS-2: Session + Steps ──────────────────────────────────────────────
$session = CE::start_session(1, array('source' => 'landing'));
check('15a start session', isset($session['started_at']) && $session['form_id'] === 1);
check('15b session has context', $session['context']['source'] === 'landing');
check('15c session initial score', $session['score'] === 0);
check('15d session initial step', $session['current_step'] === 0);

$steps = array(
	array('id' => 'name', 'widget' => 'text', 'scoring' => array('rules' => array())),
	array('id' => 'need', 'widget' => 'select', 'scoring' => array('rules' => array(
		array('field' => 'need', 'operator' => 'eq', 'value' => 'urgente', 'score' => 30),
	)), 'branching' => array(
		array('condition' => 'need:low', 'goto' => 'final'),
	)),
	array('id' => 'budget', 'widget' => 'number'),
	array('id' => 'final', 'widget' => 'info'),
);

$r1 = CE::process_step($session, $steps, 0, array('name' => 'Josias', 'value' => 'Josias'));
check('15e step 0 completed', !$r1['completed'] && $r1['next_step']['id'] === 'need');

$r2 = CE::process_step($session, $steps, 1, array('need' => 'urgente', 'value' => 'urgente'));
check('15f step 1 scored', $session['score'] > 0);

$completion = CE::complete_session($session, array('strategy' => 'create_lead'));
check('15g complete session', $completion['strategy'] === 'create_lead');

// ── 16. Compat shortcode [estandar_form] (F0) ──────────────────────────────
require_once dirname(__DIR__) . '/includes/FormRenderer.php';
require_once dirname(__DIR__) . '/includes/LegacyFormShortcode.php';
check('16a compat no pisa legacy activo (clase existe)', class_exists('Estandar\Capture\LegacyFormShortcode'));
// Guardia: init() es no-op si estandar-forms está activo (no lo pisa).
check('16b guardia silenciosa sin legacy activo', C\LegacyFormShortcode::init() === null);
// Resolución: nativo por id primero.
$native_row = C\Forms::legacy_to_native(array('id' => 90, 'name' => 'Compat', 'slug' => 'compat-slug', 'form_type' => 'lead', 'form_context' => 'generic', 'fields_json' => '[{"type":"text","name":"nombre","label":"Nombre"}]', 'created_at' => '2026-01-01 00:00:00'), 'lead');
check('16c legacy_to_native es el payload de compat', (int) $native_row['id'] === 90 && $native_row['form_type'] === 'lead');
$cfg90 = json_decode($native_row['config_json'], true);
check('16d fields_json conservado en payload compat', isset($cfg90['fields_json']));
check('16e marcador _migrated_legacy presente', isset($cfg90[C\Forms::MIG_MARKER]) && (int) $cfg90[C\Forms::MIG_MARKER]['id'] === 90);
// Tipos fuera de alcance (survey) → no migrables por compat (F2 pendiente).
check('16f survey SÍ resuelve por legacy_form_type_map (F2)', C\Forms::legacy_form_type_map('survey') === 'survey');
check('16g incident SÍ resuelve por legacy_form_type_map (F3)', C\Forms::legacy_form_type_map('incident') === 'incident');

// ── 16h-16m. UNIQUE_PROJECT_V1 · respuesta única por proyecto ─────────────
check('16h postventa (incident) siempre exenta', C\FormRenderer::unique_project_scope(array('unique_per_project' => '1', 'form_project' => 'altojaramillo'), 'incident') === '');
check('16i opción off → sin alcance', C\FormRenderer::unique_project_scope(array('unique_per_project' => '0', 'form_project' => 'altojaramillo'), 'lead') === '');
check('16j opción on + proyecto → alcance', C\FormRenderer::unique_project_scope(array('unique_per_project' => '1', 'form_project' => 'altojaramillo'), 'lead') === 'altojaramillo');
check('16k opción on sin proyecto → sin alcance', C\FormRenderer::unique_project_scope(array('unique_per_project' => '1'), 'lead') === '');
// 16n-16p: proyecto por routing v2 (context_schema.fixed_project) — la forma
// habitual en los forms migrados de demo (form_project vacío).
$ctx = array('unique_per_project' => '1', 'form_project' => '', 'context_schema' => '{"routing":{"mode":"fixed_project","project_slug":"terrenos","project_name":"Terrenos en Venta"}}');
check('16n proyecto desde context_schema.fixed_project', C\FormRenderer::unique_project_scope($ctx, 'lead') === 'terrenos');
check('16o postventa exenta aunque venga de context_schema', C\FormRenderer::unique_project_scope($ctx, 'incident') === '');
$ctx_sel = array('unique_per_project' => '1', 'context_schema' => '{"routing":{"mode":"project_selector"}}');
check('16p project_selector (dinámico) → sin alcance', C\FormRenderer::unique_project_scope($ctx_sel, 'lead') === '');
// 16q-16r: ALWAYS_REFILLABLE_V1 — un form always-refillable nunca participa del
// bloqueo por proyecto, aunque tenga unique_per_project + proyecto.
$always_cfg = array('unique_per_project' => '1', 'always_refillable' => '1', 'form_project' => 'terrenos');
check('16q always_refillable exime del bloqueo por proyecto', C\FormRenderer::unique_project_scope($always_cfg, 'lead') === '');
$always_ctx = array('unique_per_project' => '1', 'always_refillable' => '1', 'context_schema' => '{"routing":{"mode":"fixed_project","project_slug":"terrenos"}}');
check('16r always_refillable exime también vía context_schema', C\FormRenderer::unique_project_scope($always_ctx, 'lead') === '');
check('16l mensaje por defecto', C\FormRenderer::unique_project_message(array()) === C\FormRenderer::UNIQUE_PROJECT_DEFAULT_MSG);
check('16m mensaje configurable', C\FormRenderer::unique_project_message(array('unique_project_message' => 'Ya escribiste aquí.')) === 'Ya escribiste aquí.');

// ── 17. F1: Fields (render nativo + validación) ─────────────────────────────
require_once dirname(__DIR__) . '/includes/Fields.php';
check('17a rut válido', C\Fields::validate_rut('12.345.678-5') === true);
check('17b rut inválido', C\Fields::validate_rut('1-9') === false);
check('17b2 rut 12874376-6 válido', C\Fields::validate_rut('12874376-6') === true);
check('17b3 rut 12874376-7 inválido (DV malo)', C\Fields::validate_rut('12874376-7') === false);
check('17b4 rut 18516316-4 válido', C\Fields::validate_rut('18516316-4') === true);
check('17c tel CL válido', C\Fields::validate_phone('+56912345678', 'CL') === true);
check('17d tel CL inválido', C\Fields::validate_phone('123', 'CL') === false);
$file_html = C\Fields::render(array('type' => 'file', 'name' => 'archivo', 'label' => 'Archivo'));
check('17e file render nativo (no degrada)', strpos($file_html, 'type="file"') !== false && strpos($file_html, 'render básico') === false);
$mat_html = C\Fields::render(array('type' => 'matrix', 'name' => 'm', 'rows' => array(array('id' => 'r1', 'label' => 'Fila'))));
check('17f matrix render nativo', strpos($mat_html, 'estandar-form-v2-matrix') !== false);
$rut_html = C\Fields::render(array('type' => 'rut', 'name' => 'rut', 'label' => 'RUT'));
check('17g rut render nativo + clase JS', strpos($rut_html, 'estandar-rut-field') !== false);
$dep_html = C\Fields::render(array('type' => 'dependent_select', 'name' => 'calle', 'label' => 'Calle', 'depends_on' => 'proyecto'));
check('17h dependent_select render + depends_on', strpos($dep_html, 'data-depends-on="proyecto"') !== false);
// GENERIC_DEPENDENT_MAP_V1: options_map genérico (p.ej. lotes por cámara).
$depg_html = C\Fields::render(array('type' => 'dependent_select', 'name' => 'lote', 'label' => 'Lote', 'depends_on' => 'camara', 'options_map' => array('camara1' => array('F1', 'F2'))));
check('17h2 dependent_select options_map → data-options-map', strpos($depg_html, 'data-options-map=') !== false && strpos($depg_html, 'F1') !== false);
check('17i validate email inválido', C\Fields::validate(array('name' => 'mail', 'type' => 'email'), 'no-es-mail') !== array());
check('17j validate email válido', C\Fields::validate(array('name' => 'mail', 'type' => 'email'), 'a@b.cl') === array());
 check('17k validate required vacío', C\Fields::validate(array('name' => 'x', 'type' => 'text', 'required' => 1), '') !== array());
 check('17l validate nps fuera de rango', C\Fields::validate(array('name' => 'n', 'type' => 'nps'), '99') !== array());
 // TIME_FIELD_V1: tipo "time" (Hora) nativo, validación HH:MM y formato legible.
 $time_html = C\Fields::render(array('type' => 'time', 'name' => 'hora', 'label' => 'Hora'));
 check('17l2 time render nativo', strpos($time_html, 'type="time"') !== false && C\Fields::is_native('time') === true);
 check('17l3 validate time válido HH:MM', C\Fields::validate(array('name' => 'hora', 'type' => 'time'), '09:30') === array());
 check('17l4 validate time válido H:MM', C\Fields::validate(array('name' => 'hora', 'type' => 'time'), '9:05') === array());
 check('17l5 validate time inválido', C\Fields::validate(array('name' => 'hora', 'type' => 'time'), '25:99') !== array());
 check('17l6 validate time vacío no exige', C\Fields::validate(array('name' => 'hora', 'type' => 'time'), '') === array());
  check('17l7 time_display normaliza', C\Forms::time_display('9:5') === '9:5' && C\Forms::time_display('09:5') === '09:5' && C\Forms::time_display('07:05') === '07:05' && C\Forms::time_display('7:05') === '07:05');
  // DEFAULT_TODAY_V1 / READONLY_V1 (2026-09-22): campo date con fecha de hoy
  // precargada y no editable; el value SÍ se envía (readonly, no disabled).
  $today = current_time( 'Y-m-d' );
  $dt_html = C\Fields::render( array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha', 'default_today' => true, 'readonly' => true ) );
  check('17l8 default_today precarga hoy', strpos( $dt_html, 'value="' . $today . '"' ) !== false);
  check('17l9 readonly emite atributo', strpos( $dt_html, 'readonly' ) !== false && strpos( $dt_html, 'aria-readonly="true"' ) !== false);
  check('17l10 readonly conserva name (se envía)', strpos( $dt_html, 'name="fecha"' ) !== false);
  $dt_plain = C\Fields::render( array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha' ) );
  check('17l11 sin default_today ni readonly', strpos( $dt_plain, 'readonly' ) === false && strpos( $dt_plain, 'value="' . $today . '"' ) === false);
  // COLUMN_WIDTH_V2: ancho por porcentaje (full/2-3/1-2/1-3) y legacy 1-4.
  $w_render = function ( $w ) { return C\Fields::render( array( 'name' => 'x', 'label' => 'X', 'type' => 'text', 'column_width' => $w ) ); };
 check('17m ancho 33.3%', strpos( $w_render( '33.3' ), 'width:33.3%' ) !== false);
 check('17n ancho 50%', strpos( $w_render( '50' ), 'width:50%' ) !== false);
 check('17o ancho 100%', strpos( $w_render( '100' ), 'width:100%' ) !== false);
 check('17p legacy 2 → 50%', strpos( $w_render( 2 ), 'width:50%' ) !== false);
 check('17q sin ancho → default 100% (DEFAULT_ANCHO_V1)', strpos( $w_render( null ), 'width:100%' ) !== false);

// ── 17r-17y. NAME_DETECT_V1: is_name_field reconoce "Como te llamas" ──────
check('17r como_te_llamas → nombre', C\FormRenderer::is_name_field('como_te_llamas', array('label' => 'Como te llamas', 'type' => 'text')) === true);
check('17s cual_es_tu_nombre → nombre', C\FormRenderer::is_name_field('cual_es_tu_nombre', array('label' => 'Cuál es tu nombre', 'type' => 'text')) === true);
check('17t tu_nombre → nombre', C\FormRenderer::is_name_field('tu_nombre', array('label' => 'Tu nombre', 'type' => 'text')) === true);
check('17u nombre_empresa → NO (empresa)', C\FormRenderer::is_name_field('nombre_empresa', array('label' => 'Nombre de la empresa', 'type' => 'text')) === false);
check('17v nombre_proyecto → NO (proyecto)', C\FormRenderer::is_name_field('nombre_proyecto', array('label' => 'Nombre del proyecto', 'type' => 'select')) === false);
check('17w razon_social → NO', C\FormRenderer::is_name_field('razon_social', array('label' => 'Razón social', 'type' => 'text')) === false);
check('17x nombre exacto → sí', C\FormRenderer::is_name_field('nombre', array('type' => 'text')) === true);
check('17y apellido → NO', C\FormRenderer::is_name_field('apellido', array('label' => 'Apellido', 'type' => 'text')) === false);

// ── 18. F1: Spam (rate-limit + duplicado + min-time + recaptcha guard) ─────
require_once dirname(__DIR__) . '/includes/Spam.php';
check('18a min-time ok tras 10s', C\Spam::submitted_too_fast(array('estandar_form_v2_ts' => time() - 10)) === false);
check('18b min-time bloquea envío inmediato', C\Spam::submitted_too_fast(array('estandar_form_v2_ts' => time())) === true);
check('18c sin ts → no bloquea (legacy)', C\Spam::submitted_too_fast(array()) === false);
check('18d rate-limit ok sin transients (guard)', C\Spam::rate_limit_ok(1, '127.0.0.1') === true);
check('18e duplicado ok sin transients (guard)', C\Spam::duplicate_submit(1, array()) === false);
check('18f recaptcha sin require → ok', C\Spam::recaptcha_verify(array(), array()) === array('ok' => true, 'reason' => ''));
check('18g recaptcha require sin constantes → ok (guard)', C\Spam::recaptcha_verify(array('require_recaptcha' => 1), array()) === array('ok' => true, 'reason' => 'recaptcha_no_config'));
// 18h-18j: ALWAYS_REFILLABLE_V1 skip del rate-limit + SPAM_MESSAGE_V1 visible.
$rl_key = 'estandar_form_rl_' . md5('1|127.0.0.1');
$GLOBALS['__test_transients'][$rl_key] = C\Spam::RATE_MAX; // ya en el tope
check('18h rate-limit bloquea al tope', C\Spam::check(1, '127.0.0.1', array())['reason'] === 'rate_limited');
check('18i skip_rate_limit permite', C\Spam::check(1, '127.0.0.1', array(), true)['ok'] === true);
unset($GLOBALS['__test_transients'][$rl_key]);
check('18j mensaje rate_limited amigable', C\FormRenderer::spam_message('rate_limited') === 'Has hecho demasiados envíos en poco tiempo. Espera unos minutos e inténtalo de nuevo.');
check('18k mensaje submit_speed amigable', strpos( C\FormRenderer::spam_message('submit_speed'), 'demasiado rápido' ) !== false);
check('18l mensaje default', C\FormRenderer::spam_message('x') === 'No se pudo enviar tu mensaje. Inténtalo de nuevo.');
// 18m-18r: RATE_LIMIT_CONFIG_V1 — tope/ventana configurables por form.
$GLOBALS['__test_transients'][$rl_key] = 5;
check('18m fábrica (max=0) bloquea en 5', C\Spam::rate_limit_ok(1, '127.0.0.1', 0, 0) === false);
check('18n custom max=10 permite en 5', C\Spam::rate_limit_ok(1, '127.0.0.1', 10, 0) === true);
unset($GLOBALS['__test_transients'][$rl_key], $GLOBALS['__test_transients_exp'][$rl_key]);
C\Spam::rate_limit_ok(1, '127.0.0.1', 20, 1234);
check('18o custom max aplicado al contador', $GLOBALS['__test_transients'][$rl_key] === 1);
check('18p custom window aplicado al transient', ($GLOBALS['__test_transients_exp'][$rl_key] ?? 0) === 1234);
$GLOBALS['__test_transients'][$rl_key] = 9;
check('18q check con max=20 permite en 9', C\Spam::check(1, '127.0.0.1', array(), false, 20, 0)['ok'] === true);
check('18r check con max=5 y skip=false bloquea en 9', C\Spam::check(1, '127.0.0.1', array(), false, 5, 0)['reason'] === 'rate_limited');
unset($GLOBALS['__test_transients'][$rl_key], $GLOBALS['__test_transients_exp'][$rl_key]);
// Preset "Solo una vez" (spam_rate_max=1): permite el 1º y bloquea el 2º.
unset($GLOBALS['__test_transients'][$rl_key], $GLOBALS['__test_transients_exp'][$rl_key]);
C\Spam::rate_limit_ok(1, '127.0.0.1', 1, 0);
check('18s max=1 permite el 1º', $GLOBALS['__test_transients'][$rl_key] === 1);
check('18t max=1 bloquea el 2º', C\Spam::rate_limit_ok(1, '127.0.0.1', 1, 0) === false);
unset($GLOBALS['__test_transients'][$rl_key], $GLOBALS['__test_transients_exp'][$rl_key]);

// ── 19. F1: Webhook de salida (payloads estandar/cf7_compat) ───────────────
require_once dirname(__DIR__) . '/includes/Webhook.php';
require_once dirname(__DIR__) . '/includes/Actions.php';
$cap_w = array('name' => 'Ana', 'email_normalized' => 'a@b.cl', 'phone_normalized' => '56911112222', 'fields_json' => wp_json_encode(array('rut' => '1-9', 'your-email' => 'x@y.cl')), 'source_form_id' => '5', 'message' => 'Hola');
$cf7 = C\Webhook::build_cf7_payload($cap_w);
check('19a cf7 your-name desde contact', $cf7['your-name'] === 'Ana');
check('19b cf7 your-email desde fields', $cf7['your-email'] === 'x@y.cl');
check('19c cf7 tu-rut', $cf7['tu-rut'] === '1-9');
$est = C\Webhook::build_estandar_payload($cap_w);
check('19d estandar content source_form_id', $est['content']['source_form_id'] === '5');
check('19e estandar contact email', $est['contact']['email'] === 'a@b.cl');
check('19f dispatch sin config → disabled', C\Webhook::dispatch(array(), array()) === array('ok' => false, 'error' => 'disabled'));
// Payload desde capture con `fields` ARRAY (form nativo / survey, sin fields_json).
$cap_arr = array('name' => 'Luis', 'email_normalized' => 'l@m.cl', 'phone_normalized' => '56912345678', 'fields' => array('your-email' => 'c@d.cl', 'tu-rut' => '12874376-6', 'nombre_propietario' => 'Luis Valdes'), 'source_form_id' => '16', 'message' => 'Problema');
$cf7a = C\Webhook::build_cf7_payload($cap_arr);
check('19g cf7 desde fields array (your-name)', $cf7a['your-name'] === 'Luis Valdes');
check('19h cf7 desde fields array (your-email)', $cf7a['your-email'] === 'c@d.cl');
check('19i cf7 desde fields array (tu-rut)', $cf7a['tu-rut'] === '12874376-6');
$esta = C\Webhook::build_estandar_payload($cap_arr);
check('19j estandar fields array en payload', ($esta['fields']['tu-rut'] ?? '') === '12874376-6' && ($esta['fields']['nombre_propietario'] ?? '') === 'Luis Valdes');

// LEAD_FLAT_PHOTOS_V1 (09/09 acacios): payload gf_compat debe incluir las fotos
// de campos planos (type=file) del form LEAD en las keys GF 31/32/34/36 ("Foto 1..4").
$cap_flat = array(
	'name' => 'Luis', 'email_normalized' => 'l@m.cl', 'phone_normalized' => '56912345678',
	'fields' => array(
		'nombre_propietario' => 'Luis Valdes', 'rut' => '12874376-6', 'proyecto' => 'terracruz',
		'numero' => '2', 'tipo_postventa' => 'servico_tecnico_de_post_venta', 'calle' => 'HECTOR ARAVENA JIMENEZ',
		'lugar_problema_1' => 'exterior', 'problema_1' => 'TEST EXTERIOR',
		'adjunta_o_toma_una_imagen_con_tu_telefono' => 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/gz_1-1.jpg',
		'algo_mas_1' => 'si', 'lugar_problema_2' => 'acceso', 'problema_2' => 'TEST ACCESO',
		'adjunta_o_toma_una_imagen_con_tu_telefono_2' => 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/gz_2-1.jpg',
	),
	'source_form_id' => '21', 'message' => 'TEST EXTERIOR',
);
$gfp = C\Webhook::build_gf_payload( $cap_flat, 21 );
check('19q gf payload incluye lugar/problema plano', ($gfp['lead']['47'] ?? '') === 'exterior' && ($gfp['lead']['27'] ?? '') === 'TEST EXTERIOR' && ($gfp['lead']['51'] ?? '') === 'acceso' && ($gfp['lead']['29'] ?? '') === 'TEST ACCESO');
check('19r foto bloque 1 en key 31', strpos( (string) ( $gfp['lead']['31'] ?? '' ), 'gz_1-1.jpg' ) !== false );
check('19s foto bloque 2 en key 32', strpos( (string) ( $gfp['lead']['32'] ?? '' ), 'gz_2-1.jpg' ) !== false );
check('19t fotos de incidents[] intactas (si vienen del repeater)', true );
// payload con incidents (survey/repeater) sigue igual
$cap_inc = array( 'name' => 'L', 'fields' => array( 'lugar_problema_1' => 'COCINA', 'problema_1' => 'Chapa', 'incidents' => wp_json_encode( array( array( 'location' => 'COCINA', 'description' => 'Chapa', 'fotos' => array( 'https://x/survey/f1.jpg' ) ) ) ) ), 'source_form_id' => '16' );
$gfi = C\Webhook::build_gf_payload( $cap_inc, 16 );
check('19u incidents[] sigue mapeando fotos', strpos( (string) ( $gfi['lead']['31'] ?? '' ), 'f1.jpg' ) !== false );
check('19k dispatch con send_webhook+url → transport ok/dry', is_array(C\Webhook::dispatch(array('send_webhook' => 1, 'webhook_url' => 'https://x.cl/h'), $cap_arr)));
// Payload GF compat (contrato Gravity Forms → n8n): lead con meta keys GF.
$cap_gf = array(
    'name' => 'Luis Valdes', 'email_normalized' => 'l@m.cl', 'phone_normalized' => '56912345678',
    'source_form_id' => '16', 'id' => '99', 'session_id' => 'sess1', 'created_at' => '2026-08-18 12:00:00',
    'fields' => array(
        'nombre_propietario' => 'Luis Valdes', 'rut' => '12874376-6', 'email' => 'l@m.cl',
        'telefono' => '56 9 1234 5678', 'proyecto' => 'ALTACRUZ', 'calle' => 'CALLE VIÑAS DE COLCHAGUA',
        'numero' => '123', 'tipo_postventa' => 'SERVICO TECNICO DE POST VENTA',
        'requerimiento' => 'GARANTIA CALEFONT', 'solicitud' => 'Solicitud',
        'incidents' => wp_json_encode(array(array('location' => 'DORMITORIO 1', 'description' => 'Humedad', 'fotos' => array('https://x.cl/f.jpg')))),
    ),
);
$gf = C\Webhook::build_gf_payload($cap_gf, 16, array('name' => 'Postventa'));
check('19l gf_compat lead.1.3 nombre', ($gf['lead']['1.3'] ?? '') === 'Luis Valdes');
check('19m gf_compat lead.16 email', ($gf['lead']['16'] ?? '') === 'l@m.cl');
check('19n gf_compat lead.8 rut + 56 dv', ($gf['lead']['8'] ?? '') === '12874376' && ($gf['lead']['56'] ?? '') === '-6');
check('19o gf_compat lead.62 calle demo', ($gf['lead']['62'] ?? '') === 'CALLE VIÑAS DE COLCHAGUA');
check('19p gf_compat lead.47/27 lugar+desc + 31 fotos', ($gf['lead']['47'] ?? '') === 'DORMITORIO 1' && ($gf['lead']['27'] ?? '') === 'Humedad' && strpos((string)($gf['lead']['31'] ?? ''), 'f.jpg') !== false);
check('19q gf_compat form_id', ($gf['form_id'] ?? 0) === 16 && ($gf['form']['title'] ?? '') === 'Postventa');

// GF_CALLE_SLUG_V1 (2026-10-02): el select v2 postea un SLUG; debe mapear al
// mismo field_id de calle que los labels de GF (antes solo calzaban altacruz/
// terracruz/parronales/esfuerzo → calle vacía en la hoja para el resto).
foreach ( array(
	'altacruz'                => '62',
	'terracruz'               => '43',
	'villa_el_estero_casas'   => '44',
	'villa_el_estero_edificios' => '57',
	'los_vinedos_nancagua'    => '46',
	'los_parronales_nancagua' => '64',
	'casas_de_quinta'         => '58',
	'el_esfuerzo_licanten'    => '63',
) as $slug => $fid ) {
	$cap_slug = array( 'name' => 'X', 'fields' => array( 'proyecto' => $slug, 'calle' => 'CALLE X' ), 'source_form_id' => '21' );
	$gf_slug  = C\Webhook::build_gf_payload( $cap_slug, 21 );
	check( "19q2 gf calle slug $slug → $fid", ( $gf_slug['lead'][ $fid ] ?? '' ) === 'CALLE X' );
}

// ── 20. F1: Action Engine v2 (normalize + mapping) ─────────────────────────
check('20a normalize lista', C\Actions::normalize(array('database', 'email', 'webhook')) === array('database', 'email', 'webhook'));
check('20b normalize on_submit', C\Actions::normalize(array('on_submit' => array('webhook'))) === array('webhook'));
check('20c normalize vacío → database default', C\Actions::normalize(array()) === array('database'));
check('20d normalize inválido → database default', C\Actions::normalize('no-json') === array('database'));
check('20e normalize ignora tipos no canónicos', C\Actions::normalize(array('database', 'telegram', 'sms')) === array('database'));
check('20f run sin DB → database no-op + email disabled', in_array('database', C\Actions::run(1, array(), array())['executed'], true));
// FUNNEL_V1: el gate del modo vive en la orquestación (Actions), no en notify_submission.
check('20g FUNNEL_V1 run en modo funnel suprime el aviso simple', (C\Actions::run(1, array('form_funnel_mode' => 'funnel'), array())['results']['email']['notify']['skipped'] ?? '') === 'funnel_mode');
check('20h FUNNEL_V1 run en modo simple NO suprime el aviso simple', (C\Actions::run(1, array('form_funnel_mode' => 'simple'), array())['results']['email']['notify']['skipped'] ?? '') !== 'funnel_mode');

// ── 21. F2: ConversationEngine runtime de surveys ──────────────────────────
check('21a catálogo de 10 tipos de pregunta', count(CE::question_type_catalog()) === 10);
check('21b is_supported nps', CE::is_supported_question_type('nps') === true);
check('21c is_supported inválido', CE::is_supported_question_type('bogus') === false);
$v = CE::validate_answer_value('nps', '9');
check('21d nps fuera de rango → inválido', $v['valid'] === false);
check('21e nps 7 → válido', CE::validate_answer_value('nps', '7')['valid'] === true);
check('21f rating 6 con max 5 → inválido', CE::validate_answer_value('rating', '6', array('max' => 5))['valid'] === false);
check('21g checkbox JSON → sanitizado', CE::validate_answer_value('checkbox', '{"a":"1","_other_text":"x"}')['valid'] === true);
check('21h matrix 8 con scale 7 → inválido', CE::validate_answer_value('matrix', '{"r1":8}', array('scale' => 7))['valid'] === false);
check('21i tipo desconocido → text', CE::validate_answer_value('zzz', 'hola')['value'] === 'hola');
// normalize_config
$norm = CE::normalize_config(array('flow' => array('mode' => 'branching', 'rules' => array(array('from' => 'q1', 'if_answer' => 'si', 'goto' => 'q3'))), 'scoring' => array('weights' => array('a' => 1.5)), 'completion_strategy' => array('type' => 'lead'), 'identification' => 'optional'));
check('21j normalize flow mode', $norm['flow']['mode'] === 'branching');
check('21k normalize rule from/goto', $norm['flow']['rules'][0]['from'] === 'q1' && $norm['flow']['rules'][0]['goto'] === 'q3');
check('21l normalize weights', $norm['scoring']['weights']['a'] === 1.5);
check('21m normalize completion lead', $norm['completion_strategy']['type'] === 'lead');
check('21n normalize identification', $norm['identification'] === 'optional');
check('21o normalize string JSON', CE::normalize_config('{"identification":"identified"}')['identification'] === 'identified');
check('21p normalize inválido → []', CE::normalize_config('no-json') === array());
// flow_map
$fm = CE::flow_map($norm, array(array('name' => 'q1'), array('name' => 'q2'), array('name' => 'q3')));
check('21q flow_map regla si → índice 2', $fm[0]['rules'][0]['answers'] === array('si') && $fm[0]['rules'][0]['next'] === 2);
check('21r flow_map sin reglas → []', CE::flow_map(array(), array()) === array());
// FLOW_VALUE_LABEL_V1: if_answer por label canónico debe expandirse al value
// de la opción (value degradado a slug, caso acacios form 16) y viceversa.
$fields_slug = array(
	array('name' => 'tipo', 'type' => 'select', 'options' => array(
		array('value' => 'servico_tecnico_de_post_venta', 'label' => 'SERVICO TECNICO DE POST VENTA'),
		array('value' => 'documentos_y_escrituracion', 'label' => 'DOCUMENTOS Y ESCRITURACION'),
	)),
	array('name' => 'lugar_problema', 'type' => 'text'),
	array('name' => 'requerimiento', 'type' => 'text'),
);
$flow_slug = array('flow' => array(
	'mode' => 'branching',
	'rules' => array(
		array('from' => 'tipo', 'if_answer' => 'SERVICO TECNICO DE POST VENTA', 'goto' => 'lugar_problema'),
		array('from' => 'tipo', 'if_answer' => 'DOCUMENTOS Y ESCRITURACION', 'goto' => 'requerimiento'),
	),
));
$fm_slug = CE::flow_map(CE::normalize_config($flow_slug), $fields_slug);
$ans_serv = $fm_slug[0]['rules'][0]['answers'];
check('21v label canónico expande al value slug', in_array('servico_tecnico_de_post_venta', $ans_serv, true) && in_array('SERVICO TECNICO DE POST VENTA', $ans_serv, true));
$ans_doc = $fm_slug[0]['rules'][1]['answers'];
check('21w segundo label expande a su value', in_array('documentos_y_escrituracion', $ans_doc, true) && in_array('DOCUMENTOS Y ESCRITURACION', $ans_doc, true));
$fm_slug_v = CE::flow_map(CE::normalize_config(array('flow' => array('mode' => 'branching', 'rules' => array(array('from' => 'tipo', 'if_answer' => 'servico_tecnico_de_post_venta', 'goto' => 'lugar_problema'))))), $fields_slug);
check('21x value slug también matchea (no rompe esquema previo)', in_array('SERVICO TECNICO DE POST VENTA', $fm_slug_v[0]['rules'][0]['answers'], true));
// scoring
$scoring = CE::evaluate_scoring(array('scoring' => array('weights' => array('nps' => 1.0), 'result_rules' => array(array('min_score' => 5, 'segment' => 'promotor')))), array('nps' => 7));
check('21s scoring 7 → promotor', $scoring['score'] === 7.0 && in_array('promotor', $scoring['segments'], true));
// completion_result
$cr = CE::completion_result(array('completion_strategy' => array('type' => 'lead'), 'scoring' => array('weights' => array('nps' => 1))), array('nps' => 5));
check('21t completion lead + scoring', $cr['completion_type'] === 'lead' && is_array($cr['scoring']));
check('21u completion default email sin config', CE::completion_result(array(), array())['completion_type'] === 'email');

// ── 22. F2: SurveyDb guards (sin DB → no-op seguro) ────────────────────────
require_once dirname(__DIR__) . '/includes/SurveyDb.php';
require_once dirname(__DIR__) . '/includes/SurveyHandler.php';
require_once dirname(__DIR__) . '/includes/SurveyRenderer.php';
check('22a session_create sin DB → 0', C\SurveyDb::session_create('t', 1, array()) === 0);
check('22b session_get sin DB → null', C\SurveyDb::session_get('t') === null);
check('22c session_set_status sin DB → false', C\SurveyDb::session_set_status(1, 'completed') === false);
check('22d response_save sin DB → false', C\SurveyDb::response_save(array('session_token' => 't', 'question_index' => 0)) === false);
check('22e response_link_session sin DB → false', C\SurveyDb::response_link_session('t', 1, 1) === false);
check('22f handler init registra acciones nativas (clase + métodos)', method_exists('Estandar\Capture\SurveyHandler', 'handle_start') && method_exists('Estandar\Capture\SurveyHandler', 'handle_complete'));
check('22g renderer render_form existe', method_exists('Estandar\Capture\SurveyRenderer', 'render_form'));
check('22h FormRenderer::render despacha survey', true); // verificado en harness funcional WP

// ── 22.1 Encuesta: formateo de respuestas para el correo (SURVEY_MAIL_V2) ──
check('22i1 checkbox JSON → etiquetas legibles', C\SurveyHandler::format_answer_display( array(
	'question_type' => 'checkbox', 'answer_value' => '{"checked":["opcion_8"]}',
	'answer_label' => 'Página web, Instagram',
) ) === 'Página web, Instagram');
check('22i2 texto sin etiqueta distinta → valor literal', C\SurveyHandler::format_answer_display( array(
	'question_type' => 'text', 'answer_value' => 'Hola', 'answer_label' => 'Hola',
) ) === 'Hola');
check('22i3 matrix JSON → pares fila:puntaje', C\SurveyHandler::format_answer_display( array(
	'question_type' => 'matrix', 'answer_value' => '{"f1":4,"f2":5}', 'answer_label' => 'Matriz (2 filas)',
) ) === 'f1: 4, f2: 5');
check('22i4 JSON sin etiqueta → aplanado defensivo', C\SurveyHandler::format_answer_display( array(
	'question_type' => 'multi', 'answer_value' => '{"a":"X","b":"Y"}', 'answer_label' => '',
) ) === 'X, Y');
check('22i5 vacío → vacío', C\SurveyHandler::format_answer_display( array(
	'question_type' => 'radio', 'answer_value' => '', 'answer_label' => '',
) ) === '');
// 22j REGRESIÓN (07/09 acacios): survey_rate_limit() devuelve claves 'max'/
// 'window'; un list() numérico leía índices inexistentes → null → TypeError en
// Spam::rate_limit_ok() → la completación de encuestas moría sin guardar.
$sh_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/SurveyHandler.php' );
check( '22j rate usa claves max/window (no list() de array asociativo)', strpos( $sh_src, 'list( $rl_max, $rl_win )' ) === false && strpos( $sh_src, "\$rl_limit['max']" ) !== false && strpos( $sh_src, "\$rl_limit['window']" ) !== false );

// ── 22k. POSTVENTA_PHOTO_ATTACH_V1: resolución de adjuntos (fotos) al correo
// del operador. PURE: URL → ruta local candidata bajo uploads. ──────────────
$burl = 'https://acacios.cl/wp-content/uploads';
$bdir = '/home/customer/www/acacios.cl/public_html/wp-content/uploads';
$pa1 = C\SurveyHandler::resolve_attachment_paths(
	array( 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/foto1.jpg', 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/foto2.jpg' ),
	$burl, $bdir
);
check( '22k1 dos fotos → dos rutas locales', $pa1 === array( $bdir . '/estandar-capture/survey/foto1.jpg', $bdir . '/estandar-capture/survey/foto2.jpg' ) );
check( '22k2 URL fuera de uploads → ignorada', C\SurveyHandler::resolve_attachment_paths( array( 'https://otro.cl/x.jpg' ), $burl, $bdir ) === array() );
check( '22k3 URL con base distinta (http) → ignorada', C\SurveyHandler::resolve_attachment_paths( array( 'http://acacios.cl/wp-content/uploads/a.jpg' ), $burl, $bdir ) === array() );
check( '22k4 sin urls → vacío', C\SurveyHandler::resolve_attachment_paths( array(), $burl, $bdir ) === array() );
check( '22k5 traversal bloqueada (../)', C\SurveyHandler::resolve_attachment_paths( array( $burl . '/estandar-capture/../wp-config.php' ), $burl, $bdir ) !== array( $bdir . '/wp-config.php' ) );
check( '22k6 base vacía → vacío', C\SurveyHandler::resolve_attachment_paths( array( 'x' ), '', $bdir ) === array() );
check( '22k7 dupes → únicas', count( C\SurveyHandler::resolve_attachment_paths( array( $burl . '/estandar-capture/survey/f1.jpg', $burl . '/estandar-capture/survey/f1.jpg' ), $burl, $bdir ) ) === 1 );
$sh_src2 = (string) file_get_contents( dirname( __DIR__ ) . '/includes/SurveyHandler.php' );
check( '22k8 send_completion_email pasa attachments al motor', strpos( $sh_src2, 'self::response_attachments' ) !== false && strpos( $sh_src2, "'attachments' =>" ) !== false );

// ── 22m. MAIL_TEMPLATES_V1 · Fase 2: decorate_with_template (PURE) ────────
$GLOBALS['__test_templates_default'] = '';
// OFF: sin plantilla en form/proyecto/default → body intacto.
$d_off = C\Forms::decorate_with_template( '<p>Hola {{name}}</p>', array(), '', array() );
check( '22m1 sin plantilla → body intacto', $d_off === '<p>Hola {{name}}</p>' );
// Form elige 'none' → OFF (apaga default global si lo hubiera).
$GLOBALS['__test_templates_default'] = 'base';
$d_none = C\Forms::decorate_with_template( '<p>x</p>', array( 'mail_template' => 'none' ), '' );
check( '22m2 form none → body intacto (apaga default)', $d_none === '<p>x</p>' );
// ON por default global → envuelto con la plantilla base y tokens resueltos.
$d_on = C\Forms::decorate_with_template( '<p>CONTENIDO {{logo}} {{footer_estandar}}</p>', array(), '' );
check( '22m3 default base → envuelto', strpos( $d_on, '<p>CONTENIDO' ) !== false && strpos( $d_on, '{{logo}}' ) === false && strpos( $d_on, '{{footer_estandar}}' ) === false );
check( '22m4 wrap pone header antes del contenido', strpos( $d_on, '<h1>' ) !== false && strpos( $d_on, '<h1>' ) < strpos( $d_on, 'CONTENIDO' ) );
check( '22m5 slug en form gana al default', strpos( C\Forms::decorate_with_template( '<b>Y</b>', array( 'mail_template' => 'base' ), '' ), '<b>Y</b>' ) !== false );
// Cuerpo que ya es doc completo (<html>) → se conserva intacto (contrato GF).
$d_html = C\Forms::decorate_with_template( '<html><body><p>contrato</p></body></html>', array( 'mail_template' => 'base' ), '' );
check( '22m6 doc completo <html> no se toca (contrato)', $d_html === '<html><body><p>contrato</p></body></html>' );
// El código real llama decorate en las 3 rutas de marca (y no en contrato).
$f_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Forms.php' );
check( '22m7 notify_submission decora (solo no-contrato)', substr_count( $f_src, 'decorate_with_template' ) >= 2 && strpos( $f_src, "if ( \$contract !== 'gf_postventa' )" ) !== false );
check( '22m8 autoresponse decora', strpos( $f_src, "// MAIL_TEMPLATES_V1 · Fase 2: si el form/proyecto eligió plantilla,\n\t\t// inyectar el body de la autorespuesta" ) !== false );
$sh_src3 = (string) file_get_contents( dirname( __DIR__ ) . '/includes/SurveyHandler.php' );
check( '22m9 survey send_completion_email decora', substr_count( $sh_src3, 'decorate_with_template' ) >= 1 );
unset( $GLOBALS['__test_templates_default'] );


// ── 23. F3: Repeater + IncidentDb + IncidentEngine (puros/guards) ──────────
require_once dirname(__DIR__) . '/includes/IncidentDb.php';
require_once dirname(__DIR__) . '/includes/Repeater.php';
require_once dirname(__DIR__) . '/includes/IncidentEngine.php';
require_once dirname(__DIR__) . '/includes/IncidentRenderer.php';
$parsed23 = C\Repeater::parse(array(
	'incidents' => array(
		0 => array( 'location' => 'BAÑO', 'description' => 'Fuga' ),
		1 => array( 'location' => '', 'description' => '' ),
	),
));
check('23a parse descarta incidentes vacíos', count( $parsed23['incidents'] ) === 1 );
check('23b parse título autogenerado', $parsed23['incidents'][0]['title'] === 'Fuga' );
check('23c validate ubicación obligatoria', C\Repeater::validate( array( 'location' => '', 'description' => 'x' ) ) !== array() );
check('23d validate descripción obligatoria', C\Repeater::validate( array( 'location' => 'A', 'description' => '' ) ) !== array() );
check('23e get_files_for_incident sin archivos → []', C\Repeater::get_files_for_incident( array(), 0 ) === array() );
check('23f request_create sin DB → 0', C\IncidentDb::request_create( array( 'form_id' => 1 ) ) === 0 );
check('23g incident_create sin DB → 0', C\IncidentDb::incident_create( 1, 0, array() ) === 0 );
check('23h request_ref_from_id formato', C\IncidentDb::request_ref_from_id( 42 ) === 'REQ-000042' );
check('23i store_all sin DB → no_db', C\IncidentEngine::store_all( array( 'form_id' => 1 ), array() ) === 'no_db' );
check('23j IncidentRenderer render_repeater existe', method_exists( 'Estandar\Capture\IncidentRenderer', 'render_repeater' ) );
check('23k adoption import_incidents sin DB → 0 imports', C\FormsAdoption::import_incidents( false )['imported_requests'] === 0 );
check('23l adoption rollback_incidents sin DB → 0', C\FormsAdoption::rollback_incidents() === 0 );

// ── 24. F4: GF importer (puro) ─────────────────────────────────────────────
require_once dirname(__DIR__) . '/includes/GfImporter.php';
$gf_json = array(
	'version' => '2.8',
	'id' => 12,
	'title' => 'Contacto GF',
	'button' => array( 'text' => 'Enviar' ),
	'fields' => array(
		array( 'id' => 1, 'type' => 'text', 'label' => 'Nombre', 'isRequired' => true ),
		array( 'id' => 2, 'type' => 'email', 'label' => 'Correo' ),
		array( 'id' => 3, 'type' => 'select', 'label' => 'Interés', 'choices' => array( array( 'value' => 'a', 'text' => 'A' ), array( 'value' => 'b', 'text' => 'B' ) ) ),
		array( 'id' => 4, 'type' => 'captcha' ),
	),
);
$gf = new C\GfImporter( $gf_json );
check('24a GF detect válido', $gf->is_gravity_forms() === true );
check('24b GF no-detect JSON inválido', ( new C\GfImporter( 'no-json' ) )->is_gravity_forms() === false );
$gf_report = $gf->analyze();
check('24c GF analyze 4 campos, 3 mapeados, 1 perdido', $gf_report['total_fields'] === 4 && $gf_report['mapped_fields'] === 3 && $gf_report['lost_fields'] === 1 );
check('24d GF compatibilidad 75% → can_import', $gf_report['compatibility_pct'] === 75.0 && $gf_report['can_import'] === true );
$gf_fields = $gf->convert_fields();
check('24e GF convert: 3 campos resultantes', count( $gf_fields ) === 3 );
check('24f GF convert: select con options', isset( $gf_fields[2]['options'] ) && count( $gf_fields[2]['options'] ) === 2 );
check('24g GF convert: required preservado', $gf_fields[0]['required'] === true );
$gf_name_split = ( new C\GfImporter( array( 'version' => '2', 'id' => 1, 'title' => 'X', 'fields' => array( array( 'id' => 1, 'type' => 'name', 'label' => 'Nombre' ) ) ) ) )->convert_fields();
check('24h GF split name → 2 campos', count( $gf_name_split ) === 2 && $gf_name_split[0]['name'] === 'nombre' );
check('24i GF import sin DB → error', ( new C\GfImporter( $gf_json ) )->import() !== array() );
check('24j GF compat < 70% → no importa', ( new C\GfImporter( array( 'version' => '2', 'id' => 1, 'title' => 'X', 'fields' => array( array( 'id' => 1, 'type' => 'captcha' ), array( 'id' => 2, 'type' => 'captcha' ), array( 'id' => 3, 'type' => 'captcha' ) ) ) ) )->analyze()['can_import'] === false );

// ── 25. F4: experiencias conversacionales publicables ──────────────────────
$fr_src = (string) file_get_contents( dirname(__DIR__) . '/includes/FormRenderer.php' );
$sv_src = (string) file_get_contents( dirname(__DIR__) . '/includes/SurveyRenderer.php' );
check('25a dispatch experiencias (quote → SurveyRenderer)', CE::is_conversational( 'quote' ) === true && strpos( $fr_src, 'is_conversational' ) !== false );
check('25b SurveyRenderer lee conversation_config para experiencias', strpos( $sv_src, 'conversation_config' ) !== false );
check('25c experiencias sin render → las 9 del catálogo conversacional', count( CE::EXPERIENCES ) === 9 );

// ── 25d. FORM_ID_UNIQUE_V1: id único por instancia (página + popup) ─────────
check('25d1 FormRenderer usa id único por instancia', strpos( $fr_src, 'private static $render_seq = 0;' ) !== false && strpos( $fr_src, '$el_id   = \'estandar-form-v2-\' . $id . \'-\' . self::$render_seq;' ) !== false );
check('25d2 FormRenderer envuelve cada instancia', strpos( $fr_src, '\'<div id="\' . $wrap_id . \'">\'' ) !== false && strpos( $fr_src, 'cierre wrapper FORM_ID_UNIQUE_V1' ) !== false );

// ── 25e. POPUP_SKIP_SUBMITTED_V2: popup no abre a quienes ya enviaron (por popup) ─
$pf_src      = (string) file_get_contents( dirname(__DIR__) . '/includes/PopupsFrontend.php' );
$pl_engine   = (string) file_get_contents( dirname(__DIR__) . '/assets/js/engine.v2.js' );
$popups_tpl  = (string) file_get_contents( dirname(__DIR__) . '/includes/templates/feature-popups.php' );
check('25e1 payload del popup expone hideIfSubmitted + formIds', strpos( $pf_src, 'hideIfSubmitted' ) !== false && strpos( $pf_src, 'formIds' ) !== false );
check('25e2 engine.js suprime por popup (popupSubmitted)', strpos( $pl_engine, 'popupSubmitted' ) !== false && strpos( $pl_engine, 'es_fv2_done_' ) !== false && strpos( $pl_engine, 'anyFormSubmitted' ) === false );
check('25e3 editor v2 configura "no mostrar a quien ya envió"', strpos( $popups_tpl, 'hide_if_submitted' ) !== false );
// ── 25f. ASSET_VERSION_V1: chatbot sin versión hardcodeada (cache-bust) ──────
$cf_src = (string) file_get_contents( dirname(__DIR__) . '/includes/ChatbotFrontend.php' );
check('25f1 chatbot usa asset_version() derivada', strpos( $cf_src, 'function asset_version()' ) !== false && strpos( $cf_src, "defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' )" ) !== false );
check('25f2 sin versión hardcodeada 13.0.193', strpos( $cf_src, "'13.0.193'" ) === false );

// ── 25f3. MAQ_VALID_V1: el form del maquetador no debe quedar inválido ──────
// El campo delay_minutes (min=1) renderizaba "0" cuando no había config → el
// navegador bloqueaba el submit ("Actualizar formulario" no hacía nada).
$maq_src = (string) file_get_contents( dirname(__DIR__) . '/includes/templates/maquetador.php' );
check('25f3 maquetador: delay_minutes vacío si sin config (no bloquea submit)', strpos( $maq_src, "?: 0 ); ?>" ) === false && strpos( $maq_src, '$mcfg_d_delay > 0' ) !== false );

// ── 25f4. SUCCESS_REDIRECT_V1: redirigir tras completar el form ─────────────
$fr_src3 = (string) file_get_contents( dirname(__DIR__) . '/includes/FormRenderer.php' );
check('25f4 success_view redirige si success_redirect', strpos( $fr_src3, 'SUCCESS_REDIRECT_V1' ) !== false && strpos( $fr_src3, "window.location.href=" ) !== false );
check('25f5 maquetador campo "Redirigir tras completar"', strpos( $maq_src, 'data-key="success_redirect"' ) !== false );
check('25f6 NAME_STABLE: el maquetador no re-deriva names existentes', strpos( $maq_src, 'if ( ! f.name || f.name === \'\' ) {' ) !== false );
// MAQ_TYPE_PRESERVE_V1 (09/09 acacios, form 21): el maquetador de formularios
// degradaba dependent_select → text al guardar (el select .maq-type solo listaba
// los TYPES conocidos; f.type = typeSel.value caía a 'text'). Fix: (1) el editor
// de forms lista dependent_select/project_select/heading/html/hidden para
// conocerlos; (2) el JS solo sobrescribe f.type si el select tiene el tipo real.
$maq_types_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/templates/feature-forms-product.php' );
check( '25f7 field_types lead incluye dependent_select', strpos( $maq_types_src, "'dependent_select' => 'Desplegable dependiente'" ) !== false && strpos( $maq_types_src, "'project_select' => 'Proyecto (lista)'" ) !== false );
check( '25f8 JS solo sobrescribe tipo si existe en el select', strpos( $maq_src, 'typeSel.querySelector(\'option[value="\' + (f.type || \'\') + \'"]\')' ) !== false );

// MAQ_TYPE_GUARD_V1 (09/09 acacios, form 21): guard de SERVIDOR — un campo con
// depends_on no vacío pero tipo no dependiente (text) es una degradación del
// maquetador → se repara a dependent_select en toda escritura (vía Forms::sanitize).
$dep_ok = C\Forms::repair_dependent_fields( array(
	'sections' => array( array( 'fields' => array(
		array( 'name' => 'calle', 'type' => 'text', 'depends_on' => 'proyecto' ),
		array( 'name' => 'email', 'type' => 'email' ),
	) ) ),
) );
$dep_fields = $dep_ok['sections'][0]['fields'];
check( '25f9 guard repara text+depends_on → dependent_select', $dep_fields[0]['type'] === 'dependent_select' );
check( '25f10 guard NO toca campos sin depends_on', $dep_fields[1]['type'] === 'email' );
check( '25f11 guard deja dependent_select intacto', C\Forms::repair_dependent_fields( array( 'sections' => array( array( 'fields' => array( array( 'name' => 'calle', 'type' => 'dependent_select', 'depends_on' => 'proyecto' ) ) ) ) ) )['sections'][0]['fields'][0]['type'] === 'dependent_select' );
$rep = C\Forms::repair_dependent_fields( array( 'fields_json' => array( array( 'name' => 'calle', 'type' => 'text', 'depends_on' => 'proyecto' ), array( 'name' => 'x', 'type' => 'text' ) ) ) );
check( '25f12 guard repara también fields_json', $rep['fields_json'][0]['type'] === 'dependent_select' && $rep['fields_json'][1]['type'] === 'text' );

// MAQ_DUPE_FIELD_V1 (09/09): botón ⧉ para duplicar/clonar un campo del maquetador.
// Clona el campo (copia profunda) insertándolo después del original con name único.
check( '25g1 maquetador tiene botón duplicar (maq-dupe)', strpos( $maq_src, 'maq-dupe' ) !== false && strpos( $maq_src, 'Duplicar campo (clon)' ) !== false );
check( '25g2 existe función dupeField con name único', strpos( $maq_src, 'function dupeField(si, fi)' ) !== false && strpos( $maq_src, 'function uniqueFieldName(si, base)' ) !== false );
check( '25g3 clon inserta tras el original y abre edición', strpos( $maq_src, 'SECTIONS[si].fields.splice(fi + 1, 0, copy)' ) !== false );
// MAQ_ROUTE_PRESERVE_V1 (09/09 acacios, form 21): el route map se vaciaba al
// guardar desde una sub-vista sin el bloque .es-route-map (correos→notify).
// El fix solo recompone notify_route_map_text si hay filas visibles en el DOM.
check( '25i1 route map solo se recompone con filas presentes', strpos( $maq_src, 'if (rows.length) {' ) !== false && strpos( $maq_src, 'var rows = document.querySelectorAll(\'.es-route-map\');' ) !== false );
// PRESERVE_ON_PARTIAL_V1: update parcial no borra name/slug/estado.
$forms_src = (string) file_get_contents( dirname(__DIR__) . '/includes/Forms.php' );
check('25f7 PRESERVE_ON_PARTIAL: update parcial conserva el resto', strpos( $forms_src, 'PRESERVE_ON_PARTIAL_V1' ) !== false && strpos( $forms_src, 'array_key_exists( $pk, $raw )' ) !== false );
// SYSFIELD_HIDE_V1: campos de sistema ocultos del editor pero conservados al guardar.
check('25f8 SYSFIELD_HIDE: editor no lista campos de sistema', strpos( $maq_src, 'isSystemField' ) !== false && strpos( $maq_src, "f.type === 'hidden'" ) !== false && strpos( $maq_src, "f.name.charAt(0) === '_'" ) !== false );

// ── 25g. COOKIE_DONE_V1: popup no se renderiza para quien ya envió ──────────
check('25g1 done_cookie_set sin cookie → false', C\FormRenderer::done_cookie_set() === false );
$_COOKIE['estandar_fv2_done_3'] = '1';
check('25g2 done_cookie_set con cookie → true', C\FormRenderer::done_cookie_set() === true );
unset( $_COOKIE['estandar_fv2_done_3'] );
check('25g3 mark_done_cookie existe y se usa al enviar', strpos( $fr_src, 'function mark_done_cookie' ) !== false && strpos( $fr_src, 'self::mark_done_cookie' ) !== false );
$pl_frontend2 = (string) file_get_contents( dirname(__DIR__) . '/includes/PopupsFrontend.php' );
check('25g4 supresión POR POPUP (no global)', strpos( $pl_frontend2, 'public static function suppressed' ) !== false && strpos( $pl_frontend2, 'project_done_cookie_set' ) !== false && strpos( $pl_frontend2, 'self::DONE_CK' ) !== false );
check('25g4b inline elimina el popup del DOM solo si hide_if_submitted', strpos( $pl_frontend2, 'removeChild(el)' ) !== false && strpos( $pl_frontend2, 'if ( $hide_if_submitted' ) !== false );
check('25g5 engine revisa cookie de respaldo', strpos( $pl_engine, "document.cookie.indexOf('estandar_fv2_done_')" ) !== false );
require_once dirname(__DIR__) . '/includes/PopupsFrontend.php';
$pf_item = array( 'content' => '[estandar_form_v2 id="3"]', 'hide_if_submitted' => true, 'project_slug' => '' );
unset( $_COOKIE['estandar_fv2_done_3'], $_COOKIE['estandar_fv2_done_99'] );
check('25g4c popup no suprime si su form no fue enviado', C\PopupsFrontend::suppressed( $pf_item ) === false );
$_COOKIE['estandar_fv2_done_99'] = '1'; // envío en OTRA landing
check('25g4d envío en otra landing NO suprime este popup', C\PopupsFrontend::suppressed( $pf_item ) === false );
$_COOKIE['estandar_fv2_done_3'] = '1';
check('25g4e envío del form del popup SÍ suprime', C\PopupsFrontend::suppressed( $pf_item ) === true );
unset( $_COOKIE['estandar_fv2_done_3'], $_COOKIE['estandar_fv2_done_99'] );
check('25g4f hide_if_submitted=0 nunca suprime', C\PopupsFrontend::suppressed( array( 'content' => '[estandar_form_v2 id="3"]', 'hide_if_submitted' => false ) ) === false );
$fr_src2 = (string) file_get_contents( dirname(__DIR__) . '/includes/FormRenderer.php' );
check('25g6 guard doble-envío (página+popup) en render_form', strpos( $fr_src2, '$post_results' ) !== false && strpos( $fr_src2, 'mark_done_cookie' ) !== false );

// ── 25g-ttl. SUBMITTED_TTL_V1: duración configurable (sin año hardcodeado) ───
check('25g-ttl1 default 365 días', C\FormRenderer::submitted_cookie_days() === 365 && C\FormRenderer::submitted_restriction_enabled() === true );
$GLOBALS['__test_options']['estandar_suite_capture_popups_settings'] = array( 'cookie_days' => 90 );
check('25g-ttl2 option 90 → 90 días y TTL 90d', C\FormRenderer::submitted_cookie_days() === 90 && C\FormRenderer::submitted_cookie_ttl() === 90 * 86400 );
$GLOBALS['__test_options']['estandar_suite_capture_popups_settings'] = array( 'cookie_days' => 0 );
check('25g-ttl3 option 0 → sin restricción', C\FormRenderer::submitted_cookie_days() === 0 && C\FormRenderer::submitted_restriction_enabled() === false );
$_COOKIE['estandar_fv2_done_3'] = '1';
check('25g-ttl4 cookie done pero restricción 0 → false', C\FormRenderer::done_cookie_set() === false );
unset( $_COOKIE['estandar_fv2_done_3'] );
unset( $GLOBALS['__test_options']['estandar_suite_capture_popups_settings'] );
check('25g-ttl5 FormRenderer sin YEAR_IN_SECONDS en done', strpos( $fr_src2, 'time() + YEAR_IN_SECONDS' ) === false && strpos( $fr_src2, '31536000' ) === false );
check('25g-ttl6 frontend usa duración configurable', strpos( $pl_frontend2, 'submitted_restriction_enabled()' ) !== false && strpos( $pl_frontend2, 'submittedTtlDays' ) !== false );
check('25g-ttl7 engine caduca localStorage por TTL', strpos( $pl_engine, 'submittedTtlDays' ) !== false && strpos( $pl_engine, '/_t$/' ) !== false );
$admin_src_ttl = (string) file_get_contents( dirname(__DIR__) . '/includes/Admin.php' );
check('25g-ttl8 Admin: campo cookie_days + handler general', strpos( $admin_src_ttl, "'cookie_days'" ) !== false && strpos( $admin_src_ttl, 'handle_save_popups_general' ) !== false && strpos( $admin_src_ttl, 'popups_control_rows' ) !== false );
check('25g-ttl9 sin modulo legacy; contenido editable con bloques (CPT estandar_pop)', ! file_exists( dirname(__DIR__) . '/includes/popups-legacy/frontend-v304.php' ) && strpos( (string) file_get_contents( dirname(__DIR__) . '/includes/Popups.php' ), "const CONTENT_CPT = 'estandar_pop'" ) !== false );

// ── 25h. PIXEL_MANAGER_V1: Meta Pixel + Google (gtag) vía plugin (sin legacy) ─
require_once dirname(__DIR__) . '/includes/PixelManager.php';
$pm_src = (string) file_get_contents( dirname(__DIR__) . '/includes/PixelManager.php' );
check('25h1 PixelManager hookea wp_head', strpos( $pm_src, "add_action( 'wp_head'" ) !== false && strpos( $pm_src, 'inject_pixels' ) !== false );
$fb = C\PixelManager::meta_pixel_snippet( '123456789' );
check('25h2 snippet Meta Pixel con fbq init + PageView', strpos( $fb, "fbq('init', '123456789')" ) !== false && strpos( $fb, "fbq('track', 'PageView')" ) !== false && strpos( $fb, 'connect.facebook.net' ) !== false );
$gt = C\PixelManager::gtag_snippet( 'G-ABC123', '987654321' );
check('25h3 gtag GA4 + Ads (AW-)', strpos( $gt, "gtag('config', 'G-ABC123')" ) !== false && strpos( $gt, "gtag('config', 'AW-987654321')" ) !== false && strpos( $gt, 'googletagmanager.com/gtag/js' ) !== false );
$gt_no = C\PixelManager::gtag_snippet( 'G-ABC123', '' );
check('25h4 gtag sin Ads → solo GA4', strpos( $gt_no, "AW-" ) === false && strpos( $gt_no, "G-ABC123" ) !== false );
// inject_pixels: guards stubs + opciones
if ( ! function_exists( 'is_admin' ) ) { function is_admin(): bool { return false; } }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax(): bool { return false; } }
if ( ! function_exists( 'wp_is_json_request' ) ) { function wp_is_json_request(): bool { return false; } }
if ( ! function_exists( 'wp_doing_rest' ) ) { function wp_doing_rest(): bool { return false; } }
$GLOBALS['__test_options']['estandar_suite_capture_leads_settings'] = array( 'integraciones' => array( 'meta_pixel_id' => '111111111', 'google_ga4_id' => 'G-TEST1' ) );
ob_start(); C\PixelManager::inject_pixels(); $out1 = ob_get_clean();
check('25h5 inject emite fbq + gtag con opciones v2', strpos( $out1, "fbq('init', '111111111')" ) !== false && strpos( $out1, "gtag('config', 'G-TEST1')" ) !== false );
check('25h5b inject con GA4 emite el key event generate_lead', strpos( $out1, '"generate_lead"' ) !== false );
$GLOBALS['__test_options']['estandar_suite_capture_leads_settings'] = array( 'integraciones' => array( 'google_ga4_id' => 'G-TEST2', 'ga4_lead_event' => 'lead_form' ) );
ob_start(); C\PixelManager::inject_pixels(); $out1b = ob_get_clean();
check('25h5c ga4_lead_event configurable se respeta', strpos( $out1b, '"lead_form"' ) !== false && strpos( $out1b, '"generate_lead"' ) === false );
unset( $GLOBALS['__test_options']['estandar_suite_capture_leads_settings'] );
$GLOBALS['__test_options']['estandar_leads_meta_pixel_id'] = '222222222';
ob_start(); C\PixelManager::inject_pixels(); $out2 = ob_get_clean();
unset( $GLOBALS['__test_options']['estandar_leads_meta_pixel_id'] );
check('25h6 fallback a legacy meta pixel (adoptado) si v2 vacío', strpos( $out2, "fbq('init', '222222222')" ) !== false );
ob_start(); C\PixelManager::inject_pixels(); $out3 = ob_get_clean();
check('25h7 sin IDs → no emite nada', trim( $out3 ) === '' );
// Conversión por form
$cv = C\PixelManager::conversion_script( '987654321', 'generate_lead' );
check('25h8 conversión dispara fbq Lead + GA4 generate_lead + Ads AW-', strpos( $cv, "fbq('track','Lead')" ) !== false && strpos( $cv, '"generate_lead"' ) !== false && strpos( $cv, '"AW-987654321"' ) !== false && strpos( $cv, '__estandarCvFired' ) !== false );
$cv_ga = C\PixelManager::conversion_script( '', 'custom_event' );
check('25h8b sin Ads → solo GA4 evento configurable', strpos( $cv_ga, 'AW-' ) === false && strpos( $cv_ga, '"custom_event"' ) !== false );
$cv_meta = C\PixelManager::conversion_script( '', '' );
check('25h8c sin GA4/Ads → solo Meta Lead', strpos( $cv_meta, "fbq('track','Lead')" ) !== false && strpos( $cv_meta, 'gtag(' ) === false );
check('25h8d DEFAULT_GA4_LEAD_EVENT = generate_lead', C\PixelManager::DEFAULT_GA4_LEAD_EVENT === 'generate_lead' );
check('25h9 conversión NO dispara en estados de reingreso (CONVERSION_ONCE_V1)', strpos( $cv, '.es2-already-sent[data-es-done]' ) === false && strpos( $cv, ':not(.es2-project-done-block)' ) !== false );
// WA_CLICK_LEAD_V1 (2026-09-14): clic a WhatsApp = señal Lead para Meta.
$wa_js = C\PixelManager::wa_click_script();
check('25h10 wa_click_script: Lead con content_category whatsapp', strpos( $wa_js, 'fbq("track","Lead",{content_category:"whatsapp"})' ) !== false );
check('25h11 wa_click_script: endpoint wa/click + beacon + guard __esWaTracked', strpos( $wa_js, 'capture\\/v1\\/wa\\/click' ) !== false && strpos( $wa_js, 'sendBeacon' ) !== false && strpos( $wa_js, '__esWaTracked' ) !== false );
check('25h12 wa_click_script: captura fbclid/gclid y una sola vez por página', strpos( $wa_js, 'fbclid' ) === false ? false : ( strpos( $wa_js, '"fbclid"' ) !== false && strpos( $wa_js, 'if(window.fbq&&!lead)' ) !== false ) );
check('25h13 inject_pixels incluye el tracker de WhatsApp cuando hay pixel', strpos( $out1, 'WhatsApp click' ) !== false );
$rest_src_wa = (string) file_get_contents( dirname(__DIR__) . '/includes/Rest.php' );
check('25h14 whatsapp_redirect sin send_headers (WHATSAPP_REDIRECT_NO_FATAL_V1)', strpos( $rest_src_wa, '->send_headers();' ) === false && strpos( $rest_src_wa, 'WHATSAPP_REDIRECT_NO_FATAL_V1' ) !== false );
$fwa_src_wa = (string) file_get_contents( dirname(__DIR__) . '/includes/FloatingWhatsApp.php' );
check('25h15 FloatingWhatsApp respeta el guard __esWaTracked (sin doble registro)', strpos( $fwa_src_wa, 'if (a.__esWaTracked) { return; }' ) !== false );

// ── 26. Fusión chatbot → capture (módulos PURE) ───────────────────────────
require_once dirname(__DIR__) . '/includes/ChatbotBridge.php';
require_once dirname(__DIR__) . '/includes/LeadsBridge.php';
require_once dirname(__DIR__) . '/includes/ChatbotAdoption.php';
if ( ! defined( 'ESTANDAR_LEADS_VERSION' ) ) { define( 'ESTANDAR_LEADS_VERSION', 'test' ); }

$cb_payload = array(
	'schema_version' => '1.0',
	'source_plugin'  => 'estandar-chatbot',
	'submitted_at'   => gmdate( 'c' ),
	'contact'        => array( 'name' => 'A', 'email' => ' A@X.CL ', 'phone' => '+56 9 1234 5678' ),
	'content'        => array( 'message' => 'Hola', 'source_form_id' => 'chatbot' ),
	'context'        => array( 'project_slug' => 'test-project', 'source_channel' => 'web', 'intent' => 'contact' ),
	'chatbot'        => array( 'chatbot_session_key' => 's1', 'force_human' => true ),
	'scoring'        => array( 'lead_score' => 70, 'lead_tier' => 'hot' ),
	'attribution'    => array( 'landing_url' => 'https://x.cl', 'utm_source' => 'fb', 'session_id' => 's1' ),
	'request'        => array( 'ip_address' => '1.2.3.4' ),
	'spam'           => array( 'honeypot_filled' => false ),
	'meta'           => array( 'source_channel' => 'web' ),
);
$mapped = C\ChatbotBridge::map_payload( $cb_payload );
check('26a map_payload source=chatbot', $mapped['source'] === 'chatbot');
check('26b map_payload contact email lower', $mapped['contact']['email'] === 'a@x.cl');
check('26c map_payload phone raw preservado', $mapped['contact']['phone'] === '+56 9 1234 5678');
check('26d map_payload fields ricos', ( $mapped['fields']['project_slug'] ?? '' ) === 'test-project');
check('26e map_payload force_human en fields', $mapped['fields']['force_human'] === true);
check('26f map_payload scoring preservado', ( $mapped['fields']['scoring']['lead_score'] ?? 0 ) === 70);
check('26g map_payload session_id', $mapped['session_id'] === 's1');
check('26h map_payload ip', $mapped['ip'] === '1.2.3.4');
check('26i map_payload utm', $mapped['attribution']['utm_source'] === 'fb');
check('26j contact_key normaliza (mayúsculas/espacios/56)', C\ChatbotBridge::contact_key( array( 'email' => 'A@X.CL', 'phone' => '56912345678' ) ) === C\ChatbotBridge::contact_key( array( 'email' => 'a@x.cl', 'phone' => '+56912345678' ) ));

$GLOBALS['__test_options'][ C\LeadsBridge::OPTION ] = '1';
check('26k LeadsBridge enabled tras option', C\LeadsBridge::enabled() === true);
check('26l leads_plugin_active true (const test)', C\LeadsBridge::leads_plugin_active() === true);
$nf = C\Ingestion::normalize( array( 'source' => 'form', 'source_form_id' => '1', 'contact' => array( 'email' => 'b@x.cl' ) ) );
check('26m should_emit TRUE form con email', C\LeadsBridge::should_emit( $nf ) === true);
$nc = C\Ingestion::normalize( array( 'source' => 'chatbot', 'contact' => array( 'email' => 'b@x.cl' ) ) );
check('26n should_emit FALSE chatbot', C\LeadsBridge::should_emit( $nc ) === false);
$na = C\Ingestion::normalize( array( 'source' => 'form', 'contact' => array( 'name' => 'anon' ) ) );
check('26o should_emit FALSE anónimo', C\LeadsBridge::should_emit( $na ) === false);
$pl = C\LeadsBridge::build_payload( $nf );
check('26p build_payload source_plugin estandar-capture', $pl['source_plugin'] === 'estandar-capture');
check('26q build_payload meta _capture_origin', $pl['meta']['_capture_origin'] === 'estandar_suite_capture_event');
check('26r build_payload meta _capture_lead_id presente', isset( $pl['meta']['_capture_lead_id'] ));
// SUSPECT_AUTORESPONSE_V1 (2026-08-23): UA vacío → default benigno para que
// el gate anti-spam del autoresponse no bloquee la confirmación al lead.
check('26s build_payload user_agent benigno por defecto', $pl['request']['user_agent'] === 'estandar-capture-bridge');
check('26t build_payload user_agent capturado pasa al payload', C\LeadsBridge::build_payload( $nf + array( 'user_agent' => 'Mozilla/5.0 test' ) )['request']['user_agent'] === 'Mozilla/5.0 test');

check('26s ChatbotAdoption legacy_present sin DB → false', C\ChatbotAdoption::legacy_present() === false);
check('26t ChatbotAdoption import sin DB → 0', C\ChatbotAdoption::import( false )['imported'] === 0);
check('26u ChatbotAdoption rollback sin DB → 0', C\ChatbotAdoption::rollback() === 0);
check('26v ChatbotAdoption counts sin DB → 0s', C\ChatbotAdoption::counts()['legacy_total'] === 0);
$del_stub = C\ChatbotBridge::delegate_save( array( 'name' => 'X', 'email' => 'z@x.cl', 'phone' => '56900000000' ) );
check('26w delegate_save sin DB → error graceful', is_array( $del_stub ) && $del_stub['status'] === 'error');

// ── 27. FloatingWhatsApp (botón flotante configurable por proyecto/rama) ──
require_once dirname( __DIR__ ) . '/includes/FloatingWhatsApp.php';

// Opt-in estricto: default OFF → no imprime nada.
$GLOBALS['__test_options'][ C\FloatingWhatsApp::OPTION ] = '';
check('27a FloatingWhatsApp deshabilitado por defecto', C\FloatingWhatsApp::enabled() === false);
check('27b number vacío por defecto (sin config)', C\FloatingWhatsApp::number() === '');
$GLOBALS['__test_options'][ C\FloatingWhatsApp::OPTION ] = '1';
check('27c enabled tras option=1', C\FloatingWhatsApp::enabled() === true);
// number() lee de apply_filters (mock devuelve el default → '').
check('27d number() sin filter → vacío (no hardcodeado)', C\FloatingWhatsApp::number() === '');
check('27e display_number formato +56 9 XXXX XXXX', ( new ReflectionMethod( C\FloatingWhatsApp::class, 'display_number' ) )->invoke( null, '56942900458' ) === '+56 9 4290 0458');
check('27f display_number corto', ( new ReflectionMethod( C\FloatingWhatsApp::class, 'display_number' ) )->invoke( null, '912345678' ) === '+56 9 1234 5678');
check('27g print_widget sin número → retorna vacío (sin output)', ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )() === '');

// ── 27h. Resolución por página/slug (número por proyecto/rama) ──
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_number'] = '56942900458';
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] = array(
	array( 'match' => 'path', 'path' => '/temuco_inicio/', 'number' => '56923713488', 'message' => 'Hola Temuco' ),
	array( 'match' => 'slug', 'slug' => 'parronales', 'number' => '56942912310', 'message' => 'Hola Parronales' ),
);
check('27h resolved_values sin página matcheada → número global', C\FloatingWhatsApp::resolved_values() === array( '56942900458', C\FloatingWhatsApp::message() ));
$_SERVER['REQUEST_URI'] = '/temuco_inicio/';
check('27i resolved_values matchea path temuco → 56923713488', C\FloatingWhatsApp::resolved_values() === array( '56923713488', 'Hola Temuco' ));
$_SERVER['REQUEST_URI'] = '/temuco_inicio/?branch=temuco';
check('27j matchea path temuco con query', C\FloatingWhatsApp::resolved_values() === array( '56923713488', 'Hola Temuco' ));
$GLOBALS['__test_queried_object'] = (object) array( 'post_name' => 'parronales' );
$_SERVER['REQUEST_URI'] = '/parronales/';
check('27k resolved_values matchea slug parronales → 56942912310', C\FloatingWhatsApp::resolved_values() === array( '56942912310', 'Hola Parronales' ));
$GLOBALS['__test_queried_object'] = null;
$_SERVER['REQUEST_URI'] = '/otra-pagina/';
check('27l resolved_values página sin regla → número global', C\FloatingWhatsApp::resolved_values() === array( '56942900458', C\FloatingWhatsApp::message() ));
unset( $_SERVER['REQUEST_URI'] );
check('27m current_path con URI ausente → /', C\FloatingWhatsApp::current_path() === '/');

// ── 27n. Settings de backend (save_settings + resolución por settings) ──
// Limpiar filters del bloque anterior para que mande el settings (no el filter).
unset( $GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_number'] );
unset( $GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] );
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1,
	'number'  => '56942900458',
	'message' => 'Hola desde settings',
	'show_desktop' => 1,
	'show_mobile'  => 1,
	'selectors' => ".ak-wa-mobile-sticky\n.ak-wa-other",
	'padding_selector' => '#ak-landing-wrapper',
	'rules' => array(
		array( 'match' => 'path', 'path' => '/temuco_inicio/', 'number' => '56923713488', 'message' => 'Hola Temuco' ),
	),
);
check('27n enabled desde settings', C\FloatingWhatsApp::enabled() === true);
check('27o number desde settings', C\FloatingWhatsApp::number() === '56942900458');
check('27p message desde settings', C\FloatingWhatsApp::message() === 'Hola desde settings');
check('27q show_desktop/mobile desde settings', C\FloatingWhatsApp::show_desktop() === true && C\FloatingWhatsApp::show_mobile() === true);
check('27r selectors parsea líneas', C\FloatingWhatsApp::selectors() === array( '.ak-wa-mobile-sticky', '.ak-wa-other' ));
check('27s padding_selector desde settings', C\FloatingWhatsApp::padding_selector() === '#ak-landing-wrapper');
$_SERVER['REQUEST_URI'] = '/temuco_inicio/';
check('27t resolved desde settings rule temuco', C\FloatingWhatsApp::resolved_values() === array( '56923713488', 'Hola Temuco' ));
unset( $_SERVER['REQUEST_URI'] );
$GLOBALS['__test_queried_object'] = (object) array( 'post_name' => 'parronales' );
$_SERVER['REQUEST_URI'] = '/parronales/';
check('27u sin regla slug → usa global de settings', C\FloatingWhatsApp::resolved_values() === array( '56942900458', 'Hola desde settings' ));
unset( $_SERVER['REQUEST_URI'] );
$GLOBALS['__test_queried_object'] = null;

// save_settings sanea y persiste
C\FloatingWhatsApp::save_settings( array(
	'enabled' => '1',
	'number'  => '+56 9 4290 0458',
	'message' => 'Mensaje nuevo',
	'show_desktop' => '1',
	'show_mobile'  => '0',
	'selectors' => ".ak-wa-mobile-sticky",
	'padding_selector' => '',
	'rules' => array(
		array( 'match' => 'slug', 'slug' => 'parronales', 'number' => '56942912310', 'message' => 'Hola Parronales' ),
		array( 'match' => 'path', 'path' => '/otro/', 'number' => '', 'message' => '' ), // sin número → se descarta
	),
) );
$saved = $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ];
check('27v save_settings normaliza número (E.164)', $saved['number'] === '56942900458');
check('27w save_settings aplica show_mobile', $saved['show_mobile'] === 0);
check('27x save_settings filtra reglas sin número', count( $saved['rules'] ) === 1);
check('27y save_settings normaliza match slug', $saved['rules'][0]['match'] === 'slug' && $saved['rules'][0]['number'] === '56942912310');

// ── 27z. WhatsAppClickTracking (click al botón: tabla + dataLayer) ──
require_once dirname( __DIR__ ) . '/includes/WhatsAppClickTracking.php';
check('27z0 ClickTracking deshabilitado por defecto', C\WhatsAppClickTracking::enabled() === false);
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1, 'track_clicks' => 1, 'number' => '56942900458', 'message' => 'x',
	'show_desktop' => 1, 'show_mobile' => 1, 'selectors' => '', 'padding_selector' => '',
	'rules' => array(),
);
check('27z1 ClickTracking enabled con botón flotante + track_clicks', C\WhatsAppClickTracking::enabled() === true);
check('27z2 FloatingWhatsApp::track_clicks lee settings', C\FloatingWhatsApp::track_clicks() === true);
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1, 'track_clicks' => 0, 'number' => '56942900458', 'message' => 'x',
	'show_desktop' => 1, 'show_mobile' => 1, 'selectors' => '', 'padding_selector' => '',
	'rules' => array(),
);
check('27z3 track_clicks OFF cuando el checkbox está apagado', C\FloatingWhatsApp::track_clicks() === false && C\WhatsAppClickTracking::enabled() === false);
// schema: nombre de tabla y const de namespace correctos
check('27z4 tabla y namespace correctos', C\WhatsAppClickTracking::TABLE === 'estandar_wa_clicks' && strpos( C\WhatsAppClickTracking::NS, 'estandar-suite/capture' ) === 0 );
check('27z4b schema version 1.2.1 (slug+number+message por entrada)', C\WhatsAppClickTracking::SCHEMA_VERSION === '1.2.1');

// ── 27R. reserve_space: el botón no debe tapar el footer (móvil) ──
// Default ON aunque la option guardada sea antigua (sin la clave): el fix
// aplica sin obligar a re-guardar en cada sitio.
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1, 'number' => '56942900458', 'message' => 'x',
	'show_desktop' => 1, 'show_mobile' => 1, 'selectors' => '', 'padding_selector' => '',
	'rules' => array(),
);
check('27R1 reserve_space ON por defecto (option sin la clave)', C\FloatingWhatsApp::reserve_space() === true);
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['reserve_space'] = 0;
check('27R2 reserve_space OFF cuando se desactiva', C\FloatingWhatsApp::reserve_space() === false);
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['reserve_space'] = 1;
$fwa_html = ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )();
check('27R3 print_widget marca data-wa-reserve=1', strpos( $fwa_html, 'data-wa-reserve="1"' ) !== false);
check('27R4 print_widget reserva hueco real en <body> (clase + var + medición)', strpos( $fwa_html, 'es-wa-reserve' ) !== false && strpos( $fwa_html, '--es-wa-h' ) !== false && strpos( $fwa_html, 'getBoundingClientRect' ) !== false);
check('27R5 reserva es viewport-aware (recalcula al redimensionar)', strpos( $fwa_html, "addEventListener('resize'" ) !== false);
check('27R6 print_widget marca data-wa-reserve=0 al desactivar', ( function () {
	$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['reserve_space'] = 0;
	$html = ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )();
	$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['reserve_space'] = 1;
	return strpos( $html, 'data-wa-reserve="0"' ) !== false;
} )() === true);
C\FloatingWhatsApp::save_settings( array(
	'enabled' => '1', 'number' => '56942900458', 'message' => 'x',
	'show_desktop' => '1', 'show_mobile' => '1', 'selectors' => '', 'padding_selector' => '',
	'reserve_space' => '',
) );
check('27R7 save_settings persiste reserve_space=0 (desactivado)', $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['reserve_space'] === 0);

// ── 27S. Etiqueta de entrada (slug/message) del click al botón ──
// resolved_slug(): default 'global' sin reglas ni rama.
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] = array();
unset( $_SERVER['REQUEST_URI'] );
check('27S1 resolved_slug sin reglas → global', C\FloatingWhatsApp::resolved_slug() === 'global');
// Regla por path con slug → el slug de la regla.
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] = array(
	array( 'match' => 'path', 'path' => '/temuco/', 'slug' => 'temuco', 'number' => '56923713488', 'message' => 'Hola, estoy en la zona de Temuco y quiero información' ),
	array( 'match' => 'slug', 'slug' => 'parronales', 'path' => '', 'number' => '56942912310', 'message' => 'Hola Parronales' ),
);
$_SERVER['REQUEST_URI'] = '/temuco/';
check('27S2 resolved_slug matchea regla path → slug de la regla', C\FloatingWhatsApp::resolved_slug() === 'temuco');
$_SERVER['REQUEST_URI'] = '/contacto/';
check('27S3 resolved_slug página sin regla → global', C\FloatingWhatsApp::resolved_slug() === 'global');
// Regla por path SIN slug → slug derivado del basename del path.
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] = array(
	array( 'match' => 'path', 'path' => '/contabilidad-empresas/', 'slug' => '', 'number' => '56942900458', 'message' => 'Hola, quiero contabilidad para mi empresa' ),
);
$_SERVER['REQUEST_URI'] = '/contabilidad-empresas/';
check('27S4 resolved_slug regla path sin slug → basename del path', C\FloatingWhatsApp::resolved_slug() === 'contabilidad-empresas');
unset( $_SERVER['REQUEST_URI'] );
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] = array();
// print_widget: emite data-wa-slug y data-wa-message (para que el JS los guarde).
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1, 'track_clicks' => 1, 'number' => '56942900458',
	'message' => 'Hola, llegué desde su sitio web y quiero información sobre contabilidad para mi empresa',
	'show_desktop' => 1, 'show_mobile' => 1, 'selectors' => '', 'padding_selector' => '',
	'rules' => array(),
);
$html = ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )();
check('27S5 widget emite data-wa-slug', strpos( $html, 'data-wa-slug="global"' ) !== false);
check('27S6 widget emite data-wa-message con el prefill', strpos( $html, 'data-wa-message="Hola, llegué desde su sitio web y quiero información sobre contabilidad para mi empresa"' ) !== false);
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );
unset( $GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_page_rules'] );

// ── 27z5. open_new_tab (abrir WhatsApp en pestaña nueva, default ON) ──
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array();
check('27z5 open_new_tab default ON (sin config)', C\FloatingWhatsApp::open_new_tab() === true);
C\FloatingWhatsApp::save_settings( array( 'open_new_tab' => '0', 'enabled' => '1', 'number' => '56942900458' ) );
check('27z6 open_new_tab OFF tras desmarcar', C\FloatingWhatsApp::open_new_tab() === false);
C\FloatingWhatsApp::save_settings( array( 'open_new_tab' => '1', 'enabled' => '1', 'number' => '56942900458' ) );
check('27z7 open_new_tab ON tras marcar', C\FloatingWhatsApp::open_new_tab() === true);
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );

// ── 27H. hide_paths (ocultar el botón flotante en páginas concretas) ──
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );
check('27H1 hide_paths vacío por defecto', C\FloatingWhatsApp::hide_paths() === array());
C\FloatingWhatsApp::save_settings( array(
	'enabled'    => '1',
	'number'     => '56942900458',
	'message'    => 'Hola',
	'show_desktop' => '1',
	'show_mobile'  => '1',
	'hide_paths' => "/temuco_inicio/\n/otra/",
) );
check('27H2 hide_paths parsea líneas', C\FloatingWhatsApp::hide_paths() === array( '/temuco_inicio/', '/otra/' ));
$_SERVER['REQUEST_URI'] = '/temuco_inicio/';
check('27H3 is_hidden_on_current matchea path listado', C\FloatingWhatsApp::is_hidden_on_current() === true);
$_SERVER['REQUEST_URI'] = '/temuco_inicio/?utm_source=google';
check('27H4 is_hidden_on_current matchea path con query', C\FloatingWhatsApp::is_hidden_on_current() === true);
$_SERVER['REQUEST_URI'] = '/contacto/';
check('27H5 is_hidden_on_current NO matchea otra página', C\FloatingWhatsApp::is_hidden_on_current() === false);
$_SERVER['REQUEST_URI'] = '/';
check('27H6 is_hidden_on_current NO oculta home con "/" suelto', C\FloatingWhatsApp::is_hidden_on_current() === false);
unset( $_SERVER['REQUEST_URI'] );
// print_widget no imprime nada en página oculta (sin salida).
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array(
	'enabled' => 1, 'number' => '56942900458', 'message' => 'x',
	'show_desktop' => 1, 'show_mobile' => 1, 'selectors' => '', 'padding_selector' => '',
	'rules' => array(), 'hide_paths' => "/temuco_inicio/",
);
$_SERVER['REQUEST_URI'] = '/temuco_inicio/';
check('27H7 print_widget en página oculta → sin output', ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )() === '');
$_SERVER['REQUEST_URI'] = '/contacto/';
check('27H8 print_widget en página no oculta → imprime widget', strpos( ( function () { ob_start(); C\FloatingWhatsApp::print_widget(); return ob_get_clean(); } )(), 'estandar-wa-floating' ) !== false);
unset( $_SERVER['REQUEST_URI'] );
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );

// ── 27H9. Persistencia no destructiva de hide_paths (CLI) ──
C\FloatingWhatsApp::save_settings( array(
	'enabled' => '1', 'number' => '56942900458', 'message' => 'Hola',
	'show_desktop' => '1', 'show_mobile' => '1',
	'hide_paths' => '',
) );
check('27H9 add_hide_path añade /temuco_inicio/', C\FloatingWhatsApp::add_hide_path( '/temuco_inicio/' ) === array( '/temuco_inicio/' ));
check('27H10 add_hide_path normaliza sin slash final', in_array( '/temuco_inicio/', C\FloatingWhatsApp::add_hide_path( '/temuco_inicio' ), true ));
$raw_after_add = $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ]['hide_paths'];
check('27H11 persistió en la option (sin duplicar)', strpos( $raw_after_add, '/temuco_inicio/' ) !== false && count( C\FloatingWhatsApp::raw_hide_paths() ) === 1);
check('27H12 add_hide_path("/") inválido → sin cambios', C\FloatingWhatsApp::add_hide_path( '/' ) === array( '/temuco_inicio/' ));
C\FloatingWhatsApp::add_hide_path( '/otra/' );
check('27H13 acumula paths', C\FloatingWhatsApp::raw_hide_paths() === array( '/temuco_inicio/', '/otra/' ));
check('27H14 remove_hide_path quita uno', C\FloatingWhatsApp::remove_hide_path( '/temuco_inicio/' ) === array( '/otra/' ));
check('27H15 remove de path inexistente → sin cambios', C\FloatingWhatsApp::remove_hide_path( '/no-existe/' ) === array( '/otra/' ));
C\FloatingWhatsApp::persist_hide_paths( array() );
check('27H16 persist_hide_paths vacío', C\FloatingWhatsApp::raw_hide_paths() === array() && C\FloatingWhatsApp::hide_paths() === array());
// Conserva el resto de la option al persistir hide_paths (enabled/number intactos).
$saved_after = $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ];
check('27H17 persist no destructivo conserva number/enabled', (string) ( $saved_after['number'] ?? '' ) === '56942900458' && ! empty( $saved_after['enabled'] ));
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );

// ── 27B. Condicional de campos (show_when) + paso a paso por form ──
// Fields::render emite data-es-show-when cuando el campo tiene show_when.
$sw_field = \Estandar\Capture\Fields::render( array( 'name' => 'otra_ciudad', 'label' => 'Otra ciudad', 'type' => 'text', 'show_when' => array( 'field' => 'ciudad', 'value' => 'otra' ) ) );
check('27B1 show_when emite data-es-show-when', strpos( $sw_field, 'data-es-show-when="ciudad==otra"' ) !== false);
check('27B2 show_when respeta column_width', strpos( $sw_field, 'width:' ) !== false);
$sw_none = \Estandar\Capture\Fields::render( array( 'name' => 'x', 'label' => 'X', 'type' => 'text' ) );
check('27B3 sin show_when → sin atributo', strpos( $sw_none, 'data-es-show-when' ) === false);
// show_when inválido (sin valor) → sin atributo.
$sw_inv = \Estandar\Capture\Fields::render( array( 'name' => 'y', 'label' => 'Y', 'type' => 'text', 'show_when' => array( 'field' => 'a', 'value' => '' ) ) );
check('27B4 show_when vacío → sin atributo', strpos( $sw_inv, 'data-es-show-when' ) === false);
// SHOW_WHEN_ANY_V1: value '*' → "campo completado de cualquier forma" (campo!=*).
$sw_any = \Estandar\Capture\Fields::render( array( 'name' => 'sw', 'label' => 'SW', 'type' => 'text', 'show_when' => array( 'field' => 'telefono', 'value' => '*' ) ) );
check('27B5 show_when "*" → data-es-show-when="campo!=*"', strpos( $sw_any, 'data-es-show-when="telefono!=*"' ) !== false);

// ── 27C. LABEL_NAME_FALLBACK_V1: un campo de captura sin etiqueta/name NUNCA
// se oculta en silencio — se deriva determinísticamente (name y/o label) y
// siempre renderiza. Protege forms legacy/migrados o guardados sin etiqueta.
$rl_none = \Estandar\Capture\Fields::render( array( 'type' => 'routing', 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) );
check('27C1 routing sin label ni name → se renderiza', strpos( $rl_none, '<select' ) !== false && strpos( $rl_none, 'Routing Campo' ) !== false);
check('27C2 routing sin label → name determinístico', strpos( $rl_none, 'name="routing_campo"' ) !== false);
$tx_label = \Estandar\Capture\Fields::render( array( 'label' => 'Mi campo', 'type' => 'text' ) );
check('27C3 text con label sin name → name derivado del label', strpos( $tx_label, 'name="micampo"' ) !== false);
$tx_name = \Estandar\Capture\Fields::render( array( 'name' => 'telefono', 'type' => 'tel' ) );
check('27C4 tel con name sin label → label derivado', strpos( $tx_name, 'Telefono' ) !== false);
check('27C5 heading vacío → no renderiza', \Estandar\Capture\Fields::render( array( 'type' => 'heading', 'label' => '' ) ) === '');

// ── WHATSAPP_LINK_V1: la autorespuesta puebla {{whatsapp_link}} ──────────
// Fuente: número del operador del proyecto (es_operators) o del botón flotante.
$GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_number'] = '56942900458';
$GLOBALS['__test_transients'] = array();
$cap_wa = array(
	'id' => 200, 'fields' => array('mail' => 'wa@x.cl', 'nombre' => 'Wa', 'project_slug' => 'altojaramillo'),
	'contact' => array('email' => 'wa@x.cl', 'name' => 'Wa'),
	'content' => array('source_form_id' => '20', 'message' => 'hola'), 'email_normalized' => 'wa@x.cl',
);
$wa_res = C\Forms::autoresponse_submission(20, array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'body' => 'Hola {{nombre}} {{whatsapp_link}}', 'email_field' => 'mail')), $cap_wa);
check('27wA whatsapp_link se puebla desde el botón flotante', ($GLOBALS['__test_mail_last']['vars']['whatsapp_link'] ?? '') === 'https://wa.me/56942900458');
$GLOBALS['__test_transients'] = array();
$wa_del = C\Forms::send_delayed_autoresponse(array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'body' => 'ARX {{whatsapp_link}}', 'email_field' => 'mail')), $cap_wa, 'altojaramillo');
check('27wB whatsapp_link en la autorespuesta diferida', ($GLOBALS['__test_mail_last']['vars']['whatsapp_link'] ?? '') === 'https://wa.me/56942900458');
$GLOBALS['__test_transients'] = array();
unset( $GLOBALS['__test_filters']['estandar_suite_capture_floating_whatsapp_number'] );
$wa_none = C\Forms::autoresponse_submission(20, array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'body' => 'X', 'email_field' => 'mail')), $cap_wa);
check('27wC sin número → whatsapp_link vacío', ($GLOBALS['__test_mail_last']['vars']['whatsapp_link'] ?? 'x') === '');
$GLOBALS['__test_transients'] = array();

// ── REGION_ROUTING_V1: routing por lista de región (campo ciudad) ─────────
check('28rA normalize acentos', C\Forms::region_normalize('Pucón') === 'pucon' && C\Forms::region_normalize('Licán Ray') === 'lican ray');
check('28rB zona temuco', C\Forms::region_zone('araucania', 'Temuco') === 'temuco');
check('28rC zona villarrica', C\Forms::region_zone('araucania', 'Pucón') === 'villarrica');
check('28rD zona villarrica sin acento', C\Forms::region_zone('araucania', 'Pucon') === 'villarrica');
check('28rE zona villarrica propia', C\Forms::region_zone('araucania', 'Villarrica') === 'villarrica');
check('28rF zona fuera de región', C\Forms::region_zone('araucania', 'Santiago') === '');
check('28rG lista embebida no vacía', count( C\Forms::region_list('araucania') ) >= 40);
$rr_cfg = array(
	'region_routing' => array(
		'list' => 'araucania',
		'value_field' => 'escribe_tu_ciudad',
		'fallback_field' => 'desde_que_ciudad_nos_escribes',
		'zones' => array(
			'temuco'     => array( 'project' => 'sucursal-demo', 'project_area' => 'Sucursal Demo', 'redirect' => 'https://cliente3.example.com/temuco_inicio/', 'pipeline' => true ),
			'villarrica' => array( 'project' => '', 'redirect' => '', 'pipeline' => false ),
		),
	),
);
$rr_tem = C\Forms::region_routing_resolve( $rr_cfg, array( 'desde_que_ciudad_nos_escribes' => 'temuco' ) );
check('28rH texto libre temuco → proyecto', $rr_tem['project_slug'] === 'sucursal-demo' && $rr_tem['zone'] === 'temuco');
check('28rI temuco → redirect landing', $rr_tem['redirect'] === 'https://cliente3.example.com/temuco_inicio/' && $rr_tem['pipeline'] === true);
$rr_vil = C\Forms::region_routing_resolve( $rr_cfg, array( 'desde_que_ciudad_nos_escribes' => 'otra_ciudad', 'escribe_tu_ciudad' => 'Licán Ray' ) );
check('28rJ zona villarrica → sin proyecto (sucursal pendiente)', $rr_vil['zone'] === 'villarrica' && $rr_vil['project_slug'] === '' && $rr_vil['redirect'] === '');
$rr_na = C\Forms::region_routing_resolve( $rr_cfg, array( 'escribe_tu_ciudad' => 'Viña del Mar' ) );
check('28rK sin match → sin routing', $rr_na === array());
$rr_seed = C\Forms::region_seed( 'araucania', false );
check('28rL seed dry-run reporta count', ! empty( $rr_seed['ok'] ) && $rr_seed['count'] >= 40);

// ── PROGRESSIVE_FORM_V1: pasos progresivos (actions_json.flow) ─────────────
$pf_config = array(
	'actions_json' => wp_json_encode( array(
		'flow' => array( 'mode' => 'progressive', 'steps' => array(
			array( 'id' => 'qualification', 'fields' => array( 'que_necesitas_resolver', 'cual_es_la_situacion_actual_de_tu_empresa', 'actualmente_tienes_contador', 'cuando_necesitas_comenzar' ), 'initial' => true, 'next_label' => 'Continuar' ),
			array( 'id' => 'contact', 'fields' => array( 'como_te_llamas', 'cual_es_tu_email', 'telefono', 'desde_que_ciudad_nos_escribes', 'escribe_tu_ciudad', 'hay_algo_mas_que_debamos_saber' ) ),
		) ),
	) ),
	'experience_config_json' => wp_json_encode( array( 'layout' => 'progressive', 'initial_step' => 'qualification', 'show_progress' => true, 'progress_labels' => array( 'Tu necesidad', 'Tus datos' ) ) ),
);
$pf_steps = C\Forms::form_flow_steps( $pf_config );
check('29pA pasos progresivos parseados', count( $pf_steps ) === 2 && $pf_steps[0]['id'] === 'qualification' && ! empty( $pf_steps[0]['initial'] ));
check('29pB sin flow → sin pasos', C\Forms::form_flow_steps( array( 'actions_json' => '{}' ) ) === array());
check('29pC flow no progressive → sin pasos', C\Forms::form_flow_steps( array( 'actions_json' => wp_json_encode( array( 'flow' => array( 'mode' => 'simple' ) ) ) ) ) === array());
$pf_fields = array(
	array( 'name' => 'que_necesitas_resolver', 'type' => 'select' ),
	array( 'name' => 'cual_es_la_situacion_actual_de_tu_empresa', 'type' => 'select' ),
	array( 'name' => 'actualmente_tienes_contador', 'type' => 'select' ),
	array( 'name' => 'cuando_necesitas_comenzar', 'type' => 'select' ),
	array( 'name' => 'como_te_llamas', 'type' => 'text' ),
	array( 'name' => 'cual_es_tu_email', 'type' => 'email' ),
	array( 'name' => 'telefono', 'type' => 'tel' ),
	array( 'name' => 'desde_que_ciudad_nos_escribes', 'type' => 'select' ),
	array( 'name' => 'escribe_tu_ciudad', 'type' => 'text' ),
	array( 'name' => 'hay_algo_mas_que_debamos_saber', 'type' => 'textarea' ),
);
$pf_map = C\Forms::progressive_step_map( $pf_fields, $pf_steps );
check('29pD mapa cubre todos los campos', is_array( $pf_map ) && count( $pf_map ) === 10 && $pf_map['telefono'] === 1 && $pf_map['que_necesitas_resolver'] === 0);
$pf_missing = C\Forms::progressive_step_map( array_merge( $pf_fields, array( array( 'name' => 'campo_extra', 'type' => 'text' ) ) ), $pf_steps );
check('29pE campo fuera de pasos → null (no ocultar nada)', $pf_missing === null);
check('29pF <2 pasos → null', C\Forms::progressive_step_map( $pf_fields, array_slice( $pf_steps, 0, 1 ) ) === null);

// ── 30a. CHATBOT_WHATSAPP_REDIRECT_V1: endpoint v2 del botón WhatsApp ───────
// El chatbot emitía la ruta legacy estandar-leads/v1/whatsapp-redirect (404 en
// sitios sin el legacy, p. ej. guillaume). Ahora usa la ruta v2 de la Suite.
$cf_src   = (string) file_get_contents( dirname(__DIR__) . '/includes/ChatbotFrontend.php' );
$rest_src = (string) file_get_contents( dirname(__DIR__) . '/includes/Rest.php' );
check('30a1 chatbot usa ruta v2 (no la legacy)', strpos( $cf_src, "'estandar-suite/capture/v1/whatsapp-redirect'" ) !== false && strpos( $cf_src, "rest_url( 'estandar-leads" ) === false );
check('30a2 Rest registra /whatsapp-redirect', strpos( $rest_src, "'/whatsapp-redirect'" ) !== false && strpos( $rest_src, 'whatsapp_redirect' ) !== false && strpos( $rest_src, 'resolve_whatsapp_number' ) !== false );
require_once dirname(__DIR__) . '/includes/Rest.php';
$rm_num = new ReflectionMethod( 'Estandar\\Capture\\Rest', 'resolve_whatsapp_number' );
$rm_msg = new ReflectionMethod( 'Estandar\\Capture\\Rest', 'resolve_whatsapp_message' );
$GLOBALS['__test_options']['chatbot_human_whatsapp_number'] = '+56 9 8889 5086';
check('30a3 número desde option (solo dígitos)', $rm_num->invoke( null, '' ) === '56988895086' );
check('30a4 parámetro válido tiene prioridad', $rm_num->invoke( null, '+56911112222' ) === '56911112222' );
$GLOBALS['__test_options']['chatbot_human_whatsapp_number'] = '';
check('30a5 sin número → vacío', $rm_num->invoke( null, '12' ) === '' );
unset( $GLOBALS['__test_options']['chatbot_human_whatsapp_number'] );
$GLOBALS['__test_options']['chatbot_human_whatsapp_message'] = 'Hola desde guillaume';
check('30a6 mensaje desde option', $rm_msg->invoke( null ) === 'Hola desde guillaume' );
unset( $GLOBALS['__test_options']['chatbot_human_whatsapp_message'] );
$default_msg = $rm_msg->invoke( null );
check('30a7 mensaje default no vacío', is_string( $default_msg ) && trim( $default_msg ) !== '' );
$wa_src = (string) file_get_contents( dirname(__DIR__) . '/includes/WhatsAppClickTracking.php' );
check('30a8 WhatsAppClickTracking::record_event reutilizable', strpos( $wa_src, 'public static function record_event' ) !== false && strpos( $wa_src, 'self::record_event(' ) !== false );

// ── 30b. CHATBOT_WA_CLICK_ALWAYS_V1: el clic del chatbot se registra
// SIEMPRE (best-effort), NO condicionado a WhatsAppClickTracking::enabled().
// El opt-in sigue gobernando solo el endpoint genérico /wa/click.
$GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] = array( 'enabled' => 0, 'track_clicks' => 0 );
unset( $GLOBALS['__test_options']['estandar_suite_capture_wa_clicks_enabled'] );
check('30b1 tracking genérico sigue deshabilitado', C\WhatsAppClickTracking::enabled() === false);
$GLOBALS['__wa_inserts'] = array();
$GLOBALS['wpdb'] = new class {
	public $prefix = '';
	public function insert( $t, $d, $f ) { $GLOBALS['__wa_inserts'][] = array( 'table' => $t, 'data' => $d ); return true; }
};
C\Rest::record_chatbot_whatsapp_click( array(
	'session' => 'web_1', 'slug' => 'temuco', 'number' => '56911112222',
	'message' => 'Hola', 'page' => 'https://x.cl/temuco/',
) );
check('30b2 registra el clic aun con enabled()=false', count( $GLOBALS['__wa_inserts'] ) === 1);
$wa_row = $GLOBALS['__wa_inserts'][0]['data'] ?? array();
check('30b3 guarda landing_url en page_path', ( $wa_row['page_path'] ?? '' ) === 'https://x.cl/temuco/');
check('30b4 guarda number', ( $wa_row['number'] ?? '' ) === '56911112222');
check('30b5 guarda slug', ( $wa_row['slug'] ?? '' ) === 'temuco');
unset( $GLOBALS['wpdb'] );
unset( $GLOBALS['__test_options'][ C\FloatingWhatsApp::SETTINGS_OPTION ] );

// ── 30c. CHAT_LEAD_V1: detección PHP de email/teléfono en texto libre ──────
$ex = C\Ingestion::extract_contact( 'Hola, mi correo es juan@cliente1.example.com y mi fono +56 9 1234 5678' );
check('30c1 detecta email', ( $ex['email'] ?? '' ) === 'juan@cliente1.example.com');
check('30c2 detecta teléfono', ( $ex['phone'] ?? '' ) === '56912345678');
$ex_none = C\Ingestion::extract_contact( 'Hola, quiero información' );
check('30c3 sin datos → vacío', ( $ex_none['email'] ?? 'x' ) === '' && ( $ex_none['phone'] ?? 'x' ) === '');
$ex_name = C\Ingestion::extract_contact( 'Me llamo maría josé, mi correo es m@x.cl' );
check('30c4 detecta nombre declarado', ( $ex_name['name'] ?? '' ) === 'María José');

// ── 30d. LOG_CLIENT_EVENT_V1: handler + whitelist + tracking del JS ────────
check('30d1 handler log_client_event registrado', strpos( $cf_src, 'wp_ajax_estandar_chatbot_log_client_event' ) !== false && strpos( $cf_src, 'function ajax_log_client_event' ) !== false);
check('30d2 whitelist de eventos', strpos( $cf_src, "'chatbot_open'" ) !== false && strpos( $cf_src, "'chatbot_whatsapp_click'" ) !== false && strpos( $cf_src, "'chatbot_message_sent'" ) !== false && strpos( $cf_src, "'chatbot_lead_shown'" ) !== false);
$cb_js = (string) file_get_contents( dirname(__DIR__) . '/frontend/chatbot.js' );
check('30d3 JS redirect agrega landing_url', strpos( $cb_js, 'params.set("landing_url"' ) !== false);
check('30d4 JS redirect agrega number', strpos( $cb_js, 'params.set("number"' ) !== false);
check('30d5 Rest ya no condiciona el clic a enabled()', strpos( $rest_src, '&& WhatsAppClickTracking::enabled()' ) === false && strpos( $rest_src, 'record_chatbot_whatsapp_click' ) !== false);

// ── A19 CHAT_LEAD_V1: persistir email/teléfono como lead ──────────────────
// El widget persiste un lead cuando el visitante deja email O teléfono en la
// conversación (no exige ambos). Cableado en ajax_ai y en ajax_ai_stream.
$cl_calls = substr_count( $cf_src, 'self::capture_lead_from_conversation(' );
check('A19a capture_lead_from_conversation cableado en ajax_ai y ajax_ai_stream', $cl_calls >= 2);
check('A19b persiste con email O teléfono', strpos( $cf_src, "if ( \$email === '' && \$phone === '' )" ) !== false);
check('A19c persistencia idempotente por sesión', strpos( $cf_src, 'self::lead_already_captured( $session_key )' ) !== false && strpos( $cf_src, 'self::mark_lead_captured( $session_key )' ) !== false);
check('A19d prompt de captación natural configurable', strpos( $cf_src, 'lead_capture_ask_email' ) !== false && strpos( $cf_src, 'lead_capture_ask_phone' ) !== false);

// ── 31a. POPUP_MIGRATE_V2: v2 como fuente de verdad del frontend ────────────
require_once dirname(__DIR__) . '/includes/Popups.php';
check('31a1 encode/decode id de frontend v2', C\Popups::encode_frontend_id( 3 ) === 1000003 && C\Popups::is_frontend_id( 1000003 ) === true && C\Popups::frontend_row_id( 1000003 ) === 3 && C\Popups::is_frontend_id( 24012 ) === false );
$pop_src = (string) file_get_contents( dirname(__DIR__) . '/includes/Popups.php' );
check('31a2 Popups: frontend_items + contadores + scope_css + contenido bloques', strpos( $pop_src, 'public static function frontend_items' ) !== false && strpos( $pop_src, 'public static function increment_stat' ) !== false && strpos( $pop_src, 'public static function scope_css' ) !== false && strpos( $pop_src, 'public static function register_content_cpt' ) !== false && strpos( $pop_src, 'public static function resolve_content' ) !== false && strpos( $pop_src, 'migrate_legacy' ) === false );
$pf_src_31 = (string) file_get_contents( dirname(__DIR__) . '/includes/PopupsFrontend.php' );
check('31a3 PopupsFrontend: items + suppressed + track_event', strpos( $pf_src_31, 'public static function items' ) !== false && strpos( $pf_src_31, 'public static function suppressed' ) !== false && strpos( $pf_src_31, 'public static function track_event' ) !== false );
$eng_src_31 = (string) file_get_contents( dirname(__DIR__) . '/assets/js/engine.v2.js' );
check('31a4 engine suprime por popup (formIds/projectScopes)', strpos( $eng_src_31, 'popupSubmitted' ) !== false && strpos( $eng_src_31, 'projectScopes' ) !== false && strpos( $eng_src_31, 'anyFormSubmitted' ) === false );
$rest_src_31 = (string) file_get_contents( dirname(__DIR__) . '/includes/Rest.php' );
check('31a5 REST /popups CRUD remoto', strpos( $rest_src_31, "'/popups'" ) !== false && strpos( $rest_src_31, 'popups_upsert' ) !== false );
$admin_src_31 = (string) file_get_contents( dirname(__DIR__) . '/includes/Admin.php' );
check('31a6 Admin: editor estructurado, sin migración legacy', strpos( $admin_src_31, 'popup_config_from_post' ) !== false && strpos( $admin_src_31, 'handle_migrate_popups_legacy' ) === false && strpos( $admin_src_31, 'estandar_pop' ) === false );
check('31a7 scope_css prefija con #ep-popup-<frontend id>', strpos( C\Popups::scope_css( 'h2{color:red}', 1000003 ), '#ep-popup-1000003 h2' ) === 0 );
$popups_tpl_src = (string) file_get_contents( dirname(__DIR__) . '/includes/templates/feature-popups.php' );
check('31a7b contenido editable con bloques (CPT + ensure_content_post + botón)', strpos( $pop_src, "const CONTENT_CPT = 'estandar_pop'" ) !== false && strpos( $pop_src, 'public static function ensure_content_post' ) !== false && strpos( $popups_tpl_src, 'content_post_id' ) !== false && strpos( $popups_tpl_src, 'open_blocks' ) !== false );
check('31a7c popup body NO usa the_content (evita inyección de Elementor/plantilla)', strpos( $pf_src_31, "apply_filters( 'the_content'" ) === false && strpos( $pf_src_31, 'public static function render_body_content' ) !== false );
// POPUP_EDITOR_SAFE_V1: el editor estructurado guarda; un config_json vacío o
// inválido NO vacía la config (bug que dejó popups de ak sin contenido/trigger).
$_POST = array(
	'content'      => '[estandar_form_v2 id="7"]',
	'trigger_time' => '3',
	'seg_post_ids' => '10, 20',
	'freq_mode'    => 'always',
);
$built = C\Admin::resolve_popup_config( '' );
check('31a8 editor compone content/trigger/seg desde campos', strpos( (string) $built['content'], '[estandar_form_v2 id="7"]' ) !== false && (int) $built['triggers']['time'] === 3 && $built['segmentation']['post_ids'] === array( 10, 20 ) && $built['frequency']['mode'] === 'always' );
$built_bad = C\Admin::resolve_popup_config( '[estandar_form_v2 id="7"]' );
check('31a9 config_json inválido NO vacía la config (usa campos)', strpos( (string) $built_bad['content'], '[estandar_form_v2 id="7"]' ) !== false && (int) $built_bad['triggers']['time'] === 3 );
$built_ok = C\Admin::resolve_popup_config( (string) wp_json_encode( array( 'content' => 'X', 'triggers' => array( 'time' => 9 ) ) ) );
check('31a10 config_json válido reemplaza la config', $built_ok['content'] === 'X' && (int) $built_ok['triggers']['time'] === 9 );
$_POST = array();
// FORM_STATUS_GATE_V1: solo los forms `active` se publican en el frontend.
check('32a form draft NO publicable', C\FormRenderer::is_publishable( array( 'status' => 'draft' ) ) === false );
check('32b form trashed NO publicable', C\FormRenderer::is_publishable( array( 'status' => 'trashed' ) ) === false );
check('32c form active publicable', C\FormRenderer::is_publishable( array( 'status' => 'active' ) ) === true );
check('32d sin status → publicable (compat legacy)', C\FormRenderer::is_publishable( array() ) === true );
// DEPENDENT_INLINE_MAP_V1: calles inline desde el registry (sin AJAX/nonce, a
// prueba de caché). Antes el nonce cacheado expiraba y el select quedaba vacío.
$fld_src33 = (string) file_get_contents( dirname(__DIR__) . '/includes/Fields.php' );
$dep_js33  = (string) file_get_contents( dirname(__DIR__) . '/assets/js/estandar-dependent-select.js' );
check('33a norm_key normaliza el valor de proyecto', C\Fields::norm_key( 'los_vinedos_nancagua' ) === 'losvinedosnancagua' && C\Fields::norm_key( 'LOS PARRONALES-NANCAGUA' ) === 'losparronalesnancagua' );
check('33b dependent_select embebe data-streets + streets_map', strpos( $fld_src33, 'public static function streets_map' ) !== false && strpos( $fld_src33, 'data-streets' ) !== false );
check('33c JS usa data-streets antes que el AJAX', strpos( $dep_js33, 'data-streets' ) !== false && strpos( $dep_js33, 'inlineOptions' ) !== false );
$fr_src33 = (string) file_get_contents( dirname(__DIR__) . '/includes/FormRenderer.php' );
check('33d JS dependent va inline (no encolado; evita combine/caché SG)', strpos( $fr_src33, "wp_enqueue_script( 'estandar-dependent-select'" ) === false && strpos( $fr_src33, 'print_dependent_script_footer' ) !== false );

// STREETS_MAP_CITY_V1 (2026-10-02): el value del form de postventa
// (`los_parronales_nancagua`) calza con la fila del registry
// (`parronales` / `Los Parronales` / `Nancagua`). Antes el mapa no incluía la
// ciudad → el select de calles quedaba vacío SOLO para ese proyecto.
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new class {
	public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return strpos( (string) $q, 'SHOW TABLES' ) !== false ? 'wp_estandar_project_registry' : null; }
	public function get_results( $q, $mode = null ) {
		return array(
			array( 'project_id' => 'parronales', 'label' => 'Los Parronales', 'city' => 'Nancagua', 'streets_json' => json_encode( array( 'CALLE A', 'PASAJE B' ) ) ),
			array( 'project_id' => 'altacruz', 'label' => 'Alta Cruz', 'city' => 'Santa Cruz', 'streets_json' => json_encode( array( 'CALLE VIÑAS' ) ) ),
			array( 'project_id' => 'los_vinedos_nancagua', 'label' => 'LOS VIÑEDOS- NANCAGUA', 'city' => '', 'streets_json' => json_encode( array( 'CALLE DIEGO PORTALES' ) ) ),
		);
	}
};
$map33 = C\Fields::streets_map();
check('33e streets_map indexa label+city → losparronalesnancagua', ! empty( $map33['losparronalesnancagua'] ) && in_array( 'CALLE A', $map33['losparronalesnancagua'], true ));
check('33f streets_map conserva claves previas project_id/label', ! empty( $map33['parronales'] ) && ! empty( $map33['losparronales'] ) );
check('33g resolve_project_id reconoce el value del form', C\Fields::resolve_project_id( 'los_parronales_nancagua' ) === 'parronales' );
check('33h resolve_project_id sin match devuelve slug no vacío', C\Fields::resolve_project_id( 'zzz_sin_match' ) !== '' );
unset( $GLOBALS['wpdb'] );

// MATRIX_ARRAY_V1: un campo matrix (array) valida como array, no como "Array".
check('33i validate matrix acepta array', C\Fields::validate( array( 'name' => 'm', 'type' => 'matrix', 'required' => 1 ), array( 'r1' => 4 ) ) === array() );
check('33j validate matrix rechaza basura escalar', C\Fields::validate( array( 'name' => 'm', 'type' => 'matrix' ), 'Array' ) !== array() );

// ── 34. MetaLeadAds: resolución ADAPTATIVA de proyecto (opcional) ──────────
// Cascada: form map → ids de anuncio/conjunto/campaña → nombre de campaña →
// filtro. Todo guardado: sin esas features devuelve '' sin romper.
$mla = 'Estandar\\Capture\\MetaLeadAds';
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_options']['estandar_suite_capture_meta_forms'] = array( '111' => 'altacruz' );
check('34a form map explícito resuelve', $mla::resolve_project_slug( 111, array(), array() ) === 'altacruz' );
check('34b form desconocido sin contexto → vacío', $mla::resolve_project_slug( 999, array(), array() ) === '' );
$GLOBALS['__test_options']['estandar_campaign_labels_v1'] = array( 'ads' => array( '555' => array( 'project' => 'parronales', 'label' => 'LOS PARRONALES' ) ) );
check('34c resuelve por ad_id del registro', $mla::resolve_project_slug( 999, array( 'ad_id' => '555' ), array() ) === 'parronales' );
unset( $GLOBALS['__test_options']['estandar_campaign_labels_v1'], $GLOBALS['__test_options']['estandar_suite_capture_meta_forms'] );
$cand = array( 'altojaramillo' => 'Altojaramillo', 'parronales' => 'LOS PARRONALES-NANCAGUA', 'altacruz' => 'ALTACRUZ' );
check('34d match nombre → altojaramillo', $mla::match_project_by_name( 'ACA | AltoJaramillo | Leads - Sep2026', $cand ) === 'altojaramillo' );
check('34e match nombre → parronales', $mla::match_project_by_name( 'ACP | Parronales | Leads', $cand ) === 'parronales' );
check('34f match nombre → altacruz', $mla::match_project_by_name( 'ALTACRUZ — Santa Cruz', $cand ) === 'altacruz' );
check('34g match nombre sin coincidencia → vacío', $mla::match_project_by_name( 'Campaña generica', $cand ) === '' );
$GLOBALS['__test_filters']['estandar_capture_meta_lead_project_slug'] = 'don-nicolas';
check('34h filtro override/off', $mla::resolve_project_slug( 999, array(), array() ) === 'don-nicolas' );
unset( $GLOBALS['__test_filters']['estandar_capture_meta_lead_project_slug'] );

// ── 35. NOTIFY_BODY_HEAL_V1 (plantilla corrupta) + phone_display ───────────
check('35a repair repara `<div>\\n\\nstyle=`', strpos( C\Forms::repair_notify_body( "<div>\n\nstyle=\"max-width:560px;\">X</div>" ), '<div style="max-width:560px;">' ) !== false );
check('35b repair vacío → default', C\Forms::repair_notify_body( '' ) === C\Forms::notification_body_default() );
check('35c repair cuerpo sano intacto', C\Forms::repair_notify_body( '<div style="x">Hola</div>' ) === '<div style="x">Hola</div>' );
check('35d repair `style=` suelto al inicio → default', C\Forms::repair_notify_body( 'style="x">Hola' ) === C\Forms::notification_body_default() );
check('35e is_corrupt detecta corrupción', C\Forms::is_corrupt_notify_body( "<div>\n style=\"x\">" ) === true && C\Forms::is_corrupt_notify_body( '<div style="x">ok</div>' ) === false );
check('35f phone_display 9 dígitos → +56', C\Forms::phone_display( '9 2001 9321' ) === '+56 9 2001 9321' );
check('35g phone_display con 56 → +56', C\Forms::phone_display( '+56968168452' ) === '+56 9 6816 8452' );
$ms_heal = C\Forms::mail_settings( array( 'send_email' => 1, 'notify_emails' => 'a@b.cl', 'mail_body' => "<div>\n\nstyle=\"x\">Hola" ) );
check('35h mail_settings sanea el body al render', strpos( (string) $ms_heal['notify']['body'], '<div style="x">' ) !== false );
check('35i mail_settings sin body → default', C\Forms::mail_settings( array( 'send_email' => 1, 'notify_emails' => 'a@b.cl' ) )['notify']['body'] === C\Forms::notification_body_default() );
check('35j repair `<div>style=` (sin espacio) y detecta', strpos( C\Forms::repair_notify_body( '<div>style="x">Hola</div>' ), '<div style="x">' ) !== false && C\Forms::is_corrupt_notify_body( '<div>style="x">' ) === true && C\Forms::is_corrupt_notify_body( '<div style="x">ok</div>' ) === false );

// ── 36. LEAD_RESPONSE_META_V1: autorespuesta de LEADS (Meta) con PDF ───────
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_mail_last'] = null;
if (!function_exists('get_attached_file')) { function get_attached_file($id) { return $GLOBALS['__test_attached'][$id] ?? false; } }
if (!function_exists('wp_get_attachment_url')) { function wp_get_attachment_url($id) { return $GLOBALS['__test_attached_url'][$id] ?? ''; } }
// Legacy con DUPLICADO de altojaramillo (primero sin PDF) → debe ganar el CON PDF.
$GLOBALS['__test_options']['estandar_leads_autoresponse_settings'] = array(
    'a1' => array('enabled' => 1, 'project_slug' => 'altojaramillo', 'subject' => 'SIN PDF', 'body' => 'b', 'pdf_attachment_id' => 0),
    'a2' => array('enabled' => 1, 'project_slug' => 'altojaramillo', 'subject' => 'CON PDF', 'body' => 'b', 'pdf_attachment_id' => 24030),
    'p1' => array('enabled' => 1, 'project_slug' => 'parronales', 'subject' => 'Parr', 'body' => 'b', 'pdf_attachment_id' => 0),
);
$lar_map36 = C\Forms::leads_autoresponse_adopt();
check('36a adopción legacy prefiere la entrada con PDF', (int) ($lar_map36['altojaramillo']['pdf_attachment_id'] ?? 0) === 24030 && ($lar_map36['altojaramillo']['subject'] ?? '') === 'CON PDF');
check('36b adopción incluye parronales', isset($lar_map36['parronales']));

// legacy_project_autoresponse (vía la autorespuesta de forms web) también prefiere el PDF.
$cap_l36 = array('fields' => array('mail' => 'x@y.cl', 'project_slug' => 'altojaramillo'), 'contact' => array('email' => 'x@y.cl'));
$cfg_l36 = array('name' => 'F', 'autoresponse' => array('enabled' => '1', 'email_field' => 'mail'));
C\Forms::autoresponse_submission(77, $cfg_l36, $cap_l36);
check('36c legacy dup: gana el subject de la entrada con PDF', ($GLOBALS['__test_mail_last']['subject'] ?? '') === 'CON PDF');

// Envío de leads: proyecto sin config → disabled.
check('36d leads AR proyecto sin config → disabled', (C\Forms::send_leads_autoresponse(array('fields' => array('email' => 'x@y.cl')), 'desconocido')['error'] ?? '') === 'disabled');

// Envío real (con PDF adjunto legible) para altojaramillo.
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_mail_last'] = null;
$GLOBALS['__test_attached'][24030] = __FILE__; // archivo legible para probar el adjunto
$cap_lar36 = array('source_form_id' => '1122861677086451', 'fields' => array('email' => 'lead@x.cl', 'project_slug' => 'altojaramillo'), 'contact' => array('email' => 'lead@x.cl'), 'name' => 'Ana', 'email_normalized' => 'lead@x.cl');
$res_lar36 = C\Forms::send_leads_autoresponse($cap_lar36, 'altojaramillo');
check('36e leads AR envía ok con asunto y destinatario', !empty($res_lar36['ok']) && ($GLOBALS['__test_mail_last']['subject'] ?? '') === 'CON PDF' && ($GLOBALS['__test_mail_last']['to'] ?? '') === 'lead@x.cl');
check('36f leads AR adjunta el PDF', !empty($res_lar36['pdf_attached']) && !empty($GLOBALS['__test_mail_last']['attachments']));
$res_lar36b = C\Forms::send_leads_autoresponse($cap_lar36, 'altojaramillo');
check('36g leads AR dedupe en el segundo envío', ($res_lar36b['error'] ?? '') === 'dedupe_window');

// El disparador solo actúa para leads de Meta (source='facebook').
$GLOBALS['__test_mail_last'] = null;
C\Forms::leads_autoresponse_on_entry($cap_lar36, 'v2', 5);
check('36h no dispara para forms web (source≠facebook)', $GLOBALS['__test_mail_last'] === null);

// REGRESIÓN: la captura CANÓNICA (Ingestion::normalize) NO trae `fields`; el
// project_slug viene en el NIVEL SUPERIOR. El disparador debe enviar igual.
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_mail_last'] = null;
$canon36 = array('source' => 'form', 'source_form_id' => '2050272335601149', 'project_slug' => 'altojaramillo', 'name' => 'Ana Canónica', 'email_normalized' => 'leadcanon@x.cl', 'fields_json' => json_encode(array('email' => 'leadcanon@x.cl', 'project_slug' => 'altojaramillo')));
C\Forms::leads_autoresponse_on_entry($canon36, 'facebook', 2050272335601149);
check('36j dispara con captura canónica (project_slug top-level)', !empty($GLOBALS['__test_mail_last']) && ($GLOBALS['__test_mail_last']['to'] ?? '') === 'leadcanon@x.cl');

// Lista de proyectos candidatos incluye los slugs del mapa de Meta.
$GLOBALS['__test_options']['estandar_suite_capture_meta_forms'] = array('9' => 'don-nicolas');
$projs36 = C\Admin::leads_autoresponse_projects();
check('36i lista de proyectos incluye don-nicolas (mapa Meta)', isset($projs36['don-nicolas']));

// LINK_TEXT_V1: token CON texto → hipervínculo (no URL cruda).
$GLOBALS['__test_options'][C\Forms::OPTION_LEADS_AR] = array('altacruz' => array('enabled' => 1, 'subject' => 'S', 'body' => '📄 {{pdf_link|Descargar ficha del proyecto}}', 'pdf_attachment_id' => 24030));
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_mail_last'] = null;
$GLOBALS['__test_attached'][24030] = __FILE__;
$GLOBALS['__test_attached_url'][24030] = 'https://x.cl/folleto.pdf';
$cap_lt = array('project_slug' => 'altacruz', 'email_normalized' => 'lk@x.cl', 'name' => 'L', 'source_form_id' => '1');
C\Forms::send_leads_autoresponse($cap_lt, 'altacruz');
$lt_html = (string) ($GLOBALS['__test_mail_last']['html'] ?? '');
check('36k token con texto → hipervínculo al PDF', strpos($lt_html, '<a href="https://x.cl/folleto.pdf"') !== false && strpos($lt_html, 'Descargar ficha del proyecto') !== false && strpos($lt_html, '{{pdf_link|') === false);

// MAIL_TRACKING_V2: con el motor de tracking disponible, el enlace del PDF se
// reescribe a la ruta de clic y se agrega el píxel de apertura + evento de envío.
if ( ! class_exists( 'Estandar\\Core\\Leads\\MailTracking' ) ) {
	class EstandarTest_MailTracking_Stub {
		public static function resolve_lead_id( $c, $e = '', $p = '' ) { return 4321; }
		public static function click_url( $t, $d ) { return 'https://acacios.cl/wp-json/estandar-suite/core/v1/track/c?u=' . rawurlencode( $t ); }
		public static function pixel( $d ) { return '<img src="https://acacios.cl/wp-json/estandar-suite/core/v1/track/o" width="1" height="1" />'; }
		public static function record( $t, $d, $x = null ) { $GLOBALS['__test_track_record'][] = $t; }
	}
	class_alias( 'EstandarTest_MailTracking_Stub', 'Estandar\\Core\\Leads\\MailTracking' );
}
$GLOBALS['__test_options'][C\Forms::OPTION_LEADS_AR] = array('altacruz' => array('enabled' => 1, 'subject' => 'S', 'body' => 'Mira {{pdf_link|Descargar ficha}} aquí', 'pdf_attachment_id' => 24030));
$GLOBALS['__test_options'][C\Forms::OPTION_LEADS_AR_TRACK] = '1';
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_mail_last'] = null;
$GLOBALS['__test_track_record'] = array();
$GLOBALS['__test_attached'][24030] = __FILE__;
$GLOBALS['__test_attached_url'][24030] = 'https://x.cl/f.pdf';
C\Forms::send_leads_autoresponse(array('project_slug' => 'altacruz', 'email_normalized' => 'trk@x.cl', 'name' => 'T', 'source_form_id' => '1'), 'altacruz');
$lt_html2 = (string) ($GLOBALS['__test_mail_last']['html'] ?? '');
check('36l tracking: link trackeado + píxel + evento sent', strpos($lt_html2, 'track/c') !== false && strpos($lt_html2, 'track/o') !== false && in_array('autoresponse_email_sent', $GLOBALS['__test_track_record'] ?? array(), true));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
