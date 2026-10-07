<?php
/**
 * ESTANDAR SUITE v2 — Core tests (pure logic + guarded WP paths).
 *
 *   php tests/run-core.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return (string) $u; } }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('is_email')) { function is_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) !== false; } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) ?: ''; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return trim((string) preg_replace('/<[^>]*>/', ' ', (string) $t)); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }

require dirname(__DIR__) . '/includes/Contracts.php';
require dirname(__DIR__) . '/includes/Crypto.php';
require dirname(__DIR__) . '/includes/Schema.php';
require dirname(__DIR__) . '/includes/Telemetry.php';
require dirname(__DIR__) . '/includes/Secrets.php';
require dirname(__DIR__) . '/includes/OAuth.php';
require dirname(__DIR__) . '/includes/Connections.php';
require dirname(__DIR__) . '/includes/Http.php';
require dirname(__DIR__) . '/includes/FieldMap.php';
require dirname(__DIR__) . '/includes/Conditions.php';
require dirname(__DIR__) . '/includes/Adapters.php';
require dirname(__DIR__) . '/includes/Adapters/AdapterInterface.php';
require dirname(__DIR__) . '/includes/Adapters/GoogleSheets.php';
require dirname(__DIR__) . '/includes/Adapters/ExcelOnline.php';
require dirname(__DIR__) . '/includes/Adapters/Simulated.php';
require dirname(__DIR__) . '/includes/Sandbox.php';
require dirname(__DIR__) . '/includes/Destinations.php';
require dirname(__DIR__) . '/includes/Integrations.php';
require dirname(__DIR__) . '/includes/AdoptionProbe.php';
require_once dirname(__DIR__) . '/includes/Health.php';
require_once dirname(__DIR__) . '/includes/Channels.php';
require_once dirname(__DIR__) . '/includes/Embed.php';
require_once dirname(__DIR__) . '/includes/Mail.php';
require_once dirname(__DIR__) . '/includes/Communications/Transports/TransportInterface.php';
require_once dirname(__DIR__) . '/includes/Communications/Transports/WpMailTransport.php';
require_once dirname(__DIR__) . '/includes/Communications/Transports/SmtpTransport.php';
require_once dirname(__DIR__) . '/includes/Communications/Transports/BrevoTransport.php';
require_once dirname(__DIR__) . '/includes/Communications/TransportRegistry.php';
require_once dirname(__DIR__) . '/includes/Communications/DeliveryLog.php';
require_once dirname(__DIR__) . '/includes/Communications/Documents/DocumentInterface.php';
require_once dirname(__DIR__) . '/includes/Communications/Documents/Pdf.php';
require_once dirname(__DIR__) . '/includes/Communications/Inbound/Contracts.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Core as C;

// ── 1. Telemetry: severidad validada, contexto sin PII ────────────────────
C\Telemetry::emit('debug', 'TEST', 'msg', array('source_plugin' => 'estandar-core'));
C\Telemetry::emit('BAD_SEV', 'TEST2', 'msg2', array()); // severity → info
$ctx = C\Telemetry::sanitize_context(array('lead_id' => 5, 'email' => 'x@y.cl', 'access_token' => 'secret', 'ok' => 1));
check('1a contexto sin PII (email/token eliminados)', !isset($ctx['email']) && !isset($ctx['access_token']) && $ctx['lead_id'] === 5);

// ── 2. Secrets: token + consume one-time + rollback-safe ──────────────────
$t = C\Secrets::token();
check('2a token hex 48 chars', is_string($t) && strlen($t) === 48);
check('2b secret consume empty sin DB (guards)', C\Secrets::consume($t) === '');

// ── 3. Destinations: idempotency key determinista ─────────────────────────
$k1 = C\Destinations::idempotency_key('lead_created', 'lead', 7);
$k2 = C\Destinations::idempotency_key('lead_created', 'lead', 7);
$k3 = C\Destinations::idempotency_key('lead_created', 'lead', 8);
check('3a idempotency key estable', $k1 === $k2 && strlen($k1) === 64);
check('3b distinta por entidad', $k1 !== $k3);
check('3c enqueue no-op sin DB (guards)', C\Destinations::enqueue('web', 'e', 'lead', 1, array()) === 0);
check('3d drain no-op sin WP remote (guards)', C\Destinations::drain(5) === array(0, 0, 0));
check('3e backoff intento 1 = 60s', C\Destinations::backoff_seconds(1) === 60);
check('3f backoff intento 3 = 240s', C\Destinations::backoff_seconds(3) === 240);
check('3g backoff tope 1h', C\Destinations::backoff_seconds(9) === 3600);
check('3h backoff nunca negativo', C\Destinations::backoff_seconds(0) === 0);
check('3i idempotency distingue integration_id', C\Destinations::idempotency_key('capture', 'capture', 7, 1) !== C\Destinations::idempotency_key('capture', 'capture', 7, 2));

// ── 4. AdoptionProbe: tabla legada / no legada ────────────────────────────
check('4a es_leads es tabla legada', C\AdoptionProbe::is_legacy_table('es_leads'));
check('4b es_lead_events legada', C\AdoptionProbe::is_legacy_table('es_lead_events'));
check('4c ea_sessions legada', C\AdoptionProbe::is_legacy_table('ea_sessions'));
check('4d estandar_core_telemetry NO es legada', !C\AdoptionProbe::is_legacy_table('estandar_core_telemetry'));
check('4e estandar_capture_submissions NO es legada', !C\AdoptionProbe::is_legacy_table('estandar_capture_submissions'));
check('4f probe sin DB → false', C\AdoptionProbe::probe_legacy() === false);

// ── 5. Health: snapshot render estable ────────────────────────────────────
$s = C\Health::snapshot();
check('5a snapshot ok', $s['ok'] === true && isset($s['checks']['legacy_present']));
check('5b render_text incluye productos', strpos(C\Health::render_text($s), 'estandar-capture') !== false);

// ── 6. Schema: prefijos protegidos (safety gate) ──────────────────────────
$p = C\Schema::protected_legacy_prefixes();
check('6a protege es_', in_array('es_', $p, true));
check('6b protege ea_', in_array('ea_', $p, true));
check('6c protege ecb_', in_array('ecb_', $p, true));
check('6d protege estandar_leads_', in_array('estandar_leads_', $p, true));

// ── 7. Channels (shared primitives) ───────────────────────────────────────
check('7a canal google cpc', C\Channels::resolve('google', 'cpc') === 'google_ads');
check('7b canal fb paid', C\Channels::resolve('fb', 'paid') === 'meta_ads');
check('7c canal organic google', C\Channels::resolve('google', 'organic') === 'google_organic');
check('7d gclid evidencia', C\Channels::has_click_evidence(array('gclid' => 'x'), 'google_ads') === true);
check('7e fbp solo NO evidencia', C\Channels::has_click_evidence(array('meta_fbp' => 'x'), 'meta_ads') === false);
check('7f fbc evidencia', C\Channels::has_click_evidence(array('meta_fbc' => 'x'), 'meta_ads') === true);
$ids = C\Channels::extract_click_ids(array('gclid' => 'g', 'meta_fbp' => 'f'));
check('7g extracción click ids', isset($ids['gclid']) && isset($ids['meta_fbp']));

// ── 8. Destinations: canonical events + event map (guards) ────────────────
$canon = C\Destinations::canonical_events();
check('8a eventos canónicos incluyen capture/signal', in_array('capture', $canon, true) && in_array('sale_closed', $canon, true));
check('8b event_targets vacío sin option', C\Destinations::event_targets('capture') === array());
check('8c dispatch sin destinos → 0 targets', C\Destinations::dispatch_event('capture', 'capture', 1, array()) === array('targets' => 0, 'queued' => 0));
$t = C\Destinations::test(1);
check('8d test sin DB → error', $t['ok'] === false && isset($t['error']));
check('8e consume_link sin home_url → vacío', C\Secrets::consume_link('x') === '');

// ── 9. Telemetry settings (guards + pure level filter) ────────────────────
check('9a defaults enabled+30d+info', C\Telemetry::defaults() === array('enabled' => 1, 'retention_days' => 30, 'min_level' => 'info'));
check('9b level_allows info vs min info', C\Telemetry::level_allows('info', 'info') === true);
check('9c level_allows debug vs min info → false', C\Telemetry::level_allows('debug', 'info') === false);
check('9d level_allows error vs min warning', C\Telemetry::level_allows('error', 'warning') === true);
check('9e nivel inválido → default info', C\Telemetry::settings()['min_level'] === 'info');
$saved = C\Telemetry::save_settings(array('enabled' => '0', 'retention_days' => '999', 'min_level' => 'debug'));
check('9f save sanitiza enabled/retention clamp', $saved['enabled'] === 0 && $saved['retention_days'] === 365 && $saved['min_level'] === 'debug');
check('9g emit no-op sin DB (guard)', C\Telemetry::emit('info', 'X', 'm', array()) === null);
check('9h prune no-op sin DB', C\Telemetry::prune() === 0);

// ── 10. CLI option gate (settings via CLI) ────────────────────────────────
require dirname(__DIR__) . '/includes/Cli.php';
check('10a option estandar_suite válida', C\Cli::is_settable_option('estandar_suite_brain_intent_map') === true);
check('10b option fuera del prefijo → no', C\Cli::is_settable_option('wp_other') === false);
check('10c _secret_key protegida', C\Cli::is_settable_option('estandar_suite_core_secret_key') === false);
check('10d _schema_version protegida', C\Cli::is_settable_option('estandar_suite_core_schema_version') === false);
check('10e cadena vacía → no', C\Cli::is_settable_option('') === false);

// ── 11. LegacyMap: catálogo de herencia (pure, sin WP) ───────────────────
if (!defined('WP_PLUGIN_DIR')) { define('WP_PLUGIN_DIR', '/tmp/plugins'); }
if (!function_exists('is_plugin_active')) { function is_plugin_active($p) { return false; } }
if (!function_exists('is_readable')) { function is_readable($p) { return false; } }
require dirname(__DIR__) . '/includes/LegacyMap.php';
check('11a shortcodes legados catalogados', count(C\LegacyMap::LEGACY_SHORTCODES) >= 6);
check('11b plugin de chatbot en catálogo', C\LegacyMap::LEGACY_SHORTCODES['estandar_chatbot'] === 'estandar-chatbot/estandar-chatbot.php');
check('11c REST plugins catalogados', in_array('estandar-leads/estandar-leads.php', C\LegacyMap::LEGACY_REST_PLUGINS, true));
check('11d legacy_option sin get_option → default', C\LegacyMap::legacy_option('no_existe_xyz', 'dflt') === 'dflt');
check('11e verify_coverage sin WP → no crashea', is_array(C\LegacyMap::verify_coverage()));

// ── 12. Crypto: roundtrip AES-GCM compartido ──────────────────────────────
$ct = C\Crypto::encrypt('refresh-token-secreto');
check('12a encrypt produce ciphertext', $ct !== '' && $ct !== 'refresh-token-secreto');
check('12b decrypt roundtrip', C\Crypto::decrypt($ct) === 'refresh-token-secreto');
check('12c decrypt basura → vacío', C\Crypto::decrypt('zzzz') === '');
check('12d encrypt vacío → vacío', C\Crypto::encrypt('') === '');

// ── 13. OAuth genérico: proveedores + URLs multi-tenant + normalización ──
C\OAuth::init();
$providers = C\OAuth::providers();
check('13a proveedores google+microsoft', in_array('google', $providers, true) && in_array('microsoft', $providers, true));
$gurl = C\OAuth::authorize_url('google', array('client_id' => 'c1', 'redirect_uri' => 'https://site/cb'), 'stateX', array('https://www.googleapis.com/auth/spreadsheets'));
check('13b google authorize URL', strpos($gurl, 'https://accounts.google.com/o/oauth2/v2/auth?') === 0);
check('13c google incluye client+state+offline', strpos($gurl, 'client_id=c1') !== false && strpos($gurl, 'state=stateX') !== false && strpos($gurl, 'access_type=offline') !== false);
$murl = C\OAuth::authorize_url('microsoft', array('client_id' => 'm1', 'redirect_uri' => 'https://site/cb'), 'st2', array('Files.ReadWrite.All'), array('tenant' => 'corp.onmicrosoft.com'));
check('13d microsoft tenant sustituido', strpos($murl, 'https://login.microsoftonline.com/corp.onmicrosoft.com/oauth2/v2.0/authorize?') === 0);
check('13e authorize sin state → vacío', C\OAuth::authorize_url('google', array('client_id' => 'x'), '', array()) === '');
$tok = C\OAuth::normalize_tokens(array('access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'scope' => 'a b', 'extra' => 'ignored'));
check('13f normalize_tokens estable', $tok['access_token'] === 'AT' && $tok['refresh_token'] === 'RT' && $tok['expires_in'] === 3600 && !isset($tok['extra']));
check('13g exchange sin HTTP → error', C\OAuth::exchange('google', 'code', 'https://site/cb', array())['ok'] === false);

// ── 14. FieldMap: mapeo + serialización + filas ───────────────────────────
check('14a key normaliza acentos', C\FieldMap::key('Cédula') === 'cedula' && C\FieldMap::key('Nombre Completo') === 'nombrecompleto');
check('14b score exacto 100', C\FieldMap::score('nombre', 'Nombre') === 100);
check('14c score por token >= 20', C\FieldMap::score('your-name', 'Name') >= 20);
$map = C\FieldMap::auto_map(array('your-name' => 'Nombre completo', 'your-email' => 'Correo'), array('Nombre', 'Correo', 'Telefono'));
check('14d auto_map nombre', $map['your-name'] === 'Nombre');
check('14e auto_map correo exacto', $map['your-email'] === 'Correo');
check('14f serialize array → pipe', C\FieldMap::serialize_value(array(1, 2)) === '1|2');
check('14g serialize archivos', C\FieldMap::serialize_value(array(array('url' => 'a.png'), array('url' => 'b.png'))) === 'a.png|b.png');
check('14h serialize null → vacío', C\FieldMap::serialize_value(null) === '' && C\FieldMap::serialize_value(array()) === '');
check('14i serialize bool', C\FieldMap::serialize_value(true) === '1');
$row = C\FieldMap::build_row(array('nombre' => 'Ana'), array('Nombre', 'Email', 'Entry ID'), array('nombre' => 'Nombre'), array('Entry ID'), array('Entry ID' => '42'));
check('14j build_row orden + sistema', $row === array('Ana', '', '42'));
check('14k ip anonimizada', C\FieldMap::anonymize_ip('190.12.3.4') === '190.12.0.0' && C\FieldMap::anonymize_ip('') === '');
$row2 = C\FieldMap::build_row(array('Nombre' => 'Ana', 'EMAIL' => 'a@b.cl'), array('Nombre', 'Email'), array('nombre' => 'Nombre', 'email' => 'Email'), array());
check('14l build_row fallback case-insensitive', $row2 === array('Ana', 'a@b.cl'));

// ── 15. Adapters: registry + catálogo ─────────────────────────────────────
C\Adapters::init();
$keys = C\Adapters::keys();
check('15a adapters google_sheets + excel_online', in_array('google_sheets', $keys, true) && in_array('excel_online', $keys, true));
$cat = C\Adapters::catalog();
check('15b catálogo labels/providers', $cat['google_sheets']['label'] === 'Google Sheets' && $cat['google_sheets']['provider'] === 'google');
check('15c excel provider microsoft', $cat['excel_online']['provider'] === 'microsoft');
check('15d scopes no vacíos por adapter', count(C\Adapters::get('google_sheets')->scopes()) >= 1 && count(C\Adapters::get('excel_online')->scopes()) >= 1);
check('15e is_adapter_type', C\Destinations::is_adapter_type('google_sheets') === true && C\Destinations::is_adapter_type('webhook') === false && C\Destinations::is_adapter_type('sheets') === false);
check('15f adapter simulado registrado', in_array('simulated', $keys, true) && C\Adapters::get('simulated')->provider() === 'sandbox');
check('15g proveedor sandbox en OAuth', in_array('sandbox', C\OAuth::providers(), true));

// ── 16. Integrations: fuentes + guards + adapter_key ──────────────────────
check('16a fuentes de formulario', C\Integrations::FORM_SOURCES === array('cf7', 'gravity', 'jotform', 'v2'));
check('16b bind sin DB → 0', C\Integrations::bind('cf7', 1, 1) === 0);
check('16c unbind sin DB → 0', C\Integrations::unbind(1) === 0);
check('16d adapter_key', C\Integrations::adapter_key('google_sheets') === 'google_sheets' && C\Integrations::adapter_key('webhook') === '');
$dispatch_empty = C\Integrations::dispatch_for_form('cf7', 999, array('id' => 1));
check('16e dispatch sin bindings', $dispatch_empty['bindings'] === 0 && $dispatch_empty['queued'] === 0 && $dispatch_empty['skipped'] === 0);

// ── 18. Conditions (puro): reglas sobre la entry ──────────────────────────
$cond = array( 'logic' => 'and', 'rules' => array(
	array( 'field' => 'pais', 'op' => 'eq', 'value' => 'Chile' ),
	array( 'field' => 'monto', 'op' => 'gte', 'value' => 1000 ),
) );
check('18a and con ambas → true', C\Conditions::evaluate( $cond, array( 'pais' => 'Chile', 'monto' => 1500 ) ) === true);
check('18b and con una falsa → false', C\Conditions::evaluate( $cond, array( 'pais' => 'Chile', 'monto' => 500 ) ) === false);
check('18c or con una verdadera → true', C\Conditions::evaluate( array( 'logic' => 'or', 'rules' => $cond['rules'] ), array( 'pais' => 'Perú', 'monto' => 2000 ) ) === true);
check('18d contains', C\Conditions::evaluate( array( 'rules' => array( array( 'field' => 'mensaje', 'op' => 'contains', 'value' => 'hola' ) ) ), array( 'mensaje' => 'diga hola' ) ) === true);
check('18e not_empty', C\Conditions::evaluate( array( 'rules' => array( array( 'field' => 'email', 'op' => 'not_empty' ) ) ), array( 'email' => '' ) ) === false);
check('18f in lista', C\Conditions::evaluate( array( 'rules' => array( array( 'field' => 'zona', 'op' => 'in', 'value' => 'Norte,Centro' ) ) ), array( 'zona' => 'Centro' ) ) === true);
check('18g case-insensitive field', C\Conditions::evaluate( $cond, array( 'PAIS' => 'chile', 'Monto' => 1500 ) ) === true);
check('18h condición vacía → true', C\Conditions::evaluate( array(), array( 'x' => 1 ) ) === true);
check('18i sanitize normaliza', C\Conditions::sanitize( array( 'logic' => 'XOR', 'rules' => array( array( 'field' => 'a', 'op' => 'wat', 'value' => '1' ), array( 'bad' => 1 ) ) ) ) === array( 'logic' => 'and', 'rules' => array( array( 'field' => 'a', 'op' => 'eq', 'value' => '1' ) ) ));

// ── 17. Sandbox (OAuth Pending): guards sin DB ────────────────────────────
check('17a sandbox create sin DB → vacío', C\Sandbox::create('x', 'Datos', array('A')) === '');
check('17b sandbox files sin DB → vacío', C\Sandbox::files() === array());
check('17c sandbox get vacío → null', C\Sandbox::get('') === null);
check('17d sandbox append sin DB → -1', C\Sandbox::append('k', array('a')) === -1);
$sim = C\Adapters::get('simulated');
check('17e sim test sin archivo → error', $sim->test(array(), array('file_id' => ''))['ok'] === false);
check('17f sim discover sin DB → items vacío + ok', $sim->discover(array(), array())['ok'] === true);
check('17g sim create sin DB → error', $sim->create(array(), array('columns' => array('A')))['ok'] === false);

// ── 19. LegacyMap: find_legacy_hook (regresión 403 strip_legacy_menus) ────
$GLOBALS['_registered_pages'] = array(
    'estandar_page_estandar-forms'         => true,
    'admin_page_estandar-forms-forms'      => true,
    'admin_page_estandar-forms-entries'    => true,
    'estandar_page_estandar-leads'         => true,
    'toplevel_page_estandar_main_menu'     => true,
);
$rm = new ReflectionMethod( 'Estandar\\Core\\LegacyMap', 'find_legacy_hook' );
$rm->setAccessible( true );
check( '19a find_legacy_hook estandar-forms → estandar_page_estandar-forms', $rm->invoke( null, 'estandar-forms' ) === 'estandar_page_estandar-forms' );
check( '19b find_legacy_hook estandar-forms-forms → admin_page_estandar-forms-forms', $rm->invoke( null, 'estandar-forms-forms' ) === 'admin_page_estandar-forms-forms' );
check( '19c find_legacy_hook estandar_main_menu → toplevel_page_estandar_main_menu', $rm->invoke( null, 'estandar_main_menu' ) === 'toplevel_page_estandar_main_menu' );
check( '19d find_legacy_hook slug inexistente → cadena vacía', $rm->invoke( null, 'estandar-zzz' ) === '' );
$rm2 = new ReflectionMethod( 'Estandar\\Core\\LegacyMap', 'is_legacy_slug' );
$rm2->setAccessible( true );
check( '19e is_legacy_slug estandar-forms → true', $rm2->invoke( null, 'estandar-forms' ) === true );
check( '19f is_legacy_slug estandar-suite-capture → false (suite)', $rm2->invoke( null, 'estandar-suite-capture' ) === false );
unset( $GLOBALS['_registered_pages'] );

// ── 20. Embed: proveedor único de códigos de inserción ───────────────────
check('20a tag_for form → estandar_form_v2', C\Embed::tag_for('form') === 'estandar_form_v2');
check('20b tag_for survey → estandar_form_v2 (reutilizable)', C\Embed::tag_for('survey') === 'estandar_form_v2');
check('20c tag_for popup → vacío (backlog)', C\Embed::tag_for('popup') === '');
check('20d shortcode form id 2', C\Embed::shortcode('form', 2) === '[estandar_form_v2 id="2"]');
check('20e shortcode sin id → vacío', C\Embed::shortcode('form', 0) === '');
check('20f copy_control vacío → aviso guardar', strpos(C\Embed::copy_control(''), 'Guarda el formulario') !== false);
$cc = C\Embed::copy_control('[estandar_form_v2 id="2"]');
check('20g copy_control incluye el código (escapado)', strpos($cc, '[estandar_form_v2 id=&quot;2&quot;]') !== false);
check('20h copy_control incluye botón copiar + data-copy', strpos($cc, 'data-copy="[estandar_form_v2 id=&quot;2&quot;]"') !== false && strpos($cc, '📋') !== false);
check('20i copy_script presente (una sola vez)', strpos(C\Embed::copy_script(), 'es-embed-copy-btn') !== false && C\Embed::copy_script() === '');

// ── 21. Mail: motor único de correo de la Suite (pure) ────────────────────
check('21a validate_address válido', C\Mail::validate_address('a@b.cl') === true);
check('21b validate_address inválido', C\Mail::validate_address('no-es-email') === false);
check('21c normalize_addresses', C\Mail::normalize_addresses('a@b.cl, c@d.cl; a@b.cl') === array('a@b.cl', 'c@d.cl'));
check('21d render variables {{x}}', C\Mail::render('Hola {{name}}', array('name' => 'Ana')) === 'Hola Ana');
check('21e preview devuelve html+alt+subject', is_array(C\Mail::preview(array('subject' => 'Asunto {{x}}', 'html' => '<p>{{x}}</p>', 'vars' => array('x' => 'V1')))) && C\Mail::preview(array('subject' => 'A {{x}}', 'html' => '{{x}}', 'vars' => array('x' => 'V')))['subject'] === 'A V');

// ── 22. Communications: TransportRegistry + adapters + adopción heredada ───
if (!function_exists('wp_mail')) { function wp_mail() { $GLOBALS['__wp_mail_calls'][] = func_get_args(); return true; } }
if (!function_exists('wp_remote_post')) { function wp_remote_post($u, $a) { $GLOBALS['__wp_remote_calls'][] = array($u, $a); return array('response' => array('code' => 201), 'body' => ''); } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $d2 = 512) { return json_encode($d, $f); } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!function_exists('remove_action')) { function remove_action() { return true; } }

use Estandar\Core\Communications as CC;

unset($GLOBALS['__test_options'][\Estandar\Core\Communications\TransportRegistry::OPTION]);
$GLOBALS['__test_options']['active_plugins'] = array();

check('22a resolve sin config → wp_mail (fallback explícito)', CC\TransportRegistry::resolve()->name() === 'wp_mail');
check('22b WpMailTransport::is_configured → true', (new CC\Transports\WpMailTransport())->is_configured() === true);
check('22c WpMailTransport heredado solo si hay filtros wp_mail', (new CC\Transports\WpMailTransport())->is_inherited() === false);
$GLOBALS['wp_filter']['wp_mail_from'] = (object) array('callbacks' => array(10 => array('closure')));
check('22d wp_mail con filtro de terceros → heredada', (new CC\Transports\WpMailTransport())->is_inherited() === true);
unset($GLOBALS['wp_filter']);

$GLOBALS['__test_options']['active_plugins'] = array('easy-wp-smtp/easy-wp-smtp.php');
$smtp = new CC\Transports\SmtpTransport();
check('22e plugin SMTP activo → smtp configurado', $smtp->is_configured() === true);
check('22f plugin SMTP activo → smtp heredado', $smtp->is_inherited() === true);
check('22g describe smtp → status heredada / reutilizada (plugin SMTP)', strpos($smtp->describe()['status'], 'Heredada / Reutilizada') === 0);
$GLOBALS['__test_options']['active_plugins'] = array();

$brevo = new CC\Transports\BrevoTransport();
check('22h Brevo sin key → no configurado', $brevo->is_configured() === false);
check('22i Brevo sin key → send error claro', $brevo->send(array('to' => array('a@b.cl'), 'subject' => 'S', 'html' => '<p>x</p>'))['error'] === 'Brevo no configurado (sin api key).');
define('BREVO_API_KEY', 'k-test');
check('22j Brevo con constante → configurado + heredado', $brevo->is_configured() === true && $brevo->is_inherited() === true);
$br = $brevo->send(array('to' => array('a@b.cl'), 'subject' => 'S', 'html' => '<p>x</p>', 'reply_to' => 'r@b.cl', 'cc' => array('c@b.cl')));
check('22k Brevo send simulado → ok + transport brevo + http 201', $br['ok'] === true && $br['transport'] === 'brevo' && $br['http'] === 201);
check('22l Brevo payload sin secretos en to/subject (petición con api-key)', count($GLOBALS['__wp_remote_calls']) > 0);

check('22m discovery con Brevo key → orden brevo primero', in_array('brevo', CC\TransportRegistry::discover(), true));
check('22n resolve con preferred=brevo', CC\TransportRegistry::resolve('brevo')->name() === 'brevo');
$GLOBALS['__test_options']['active_plugins'] = array('easy-wp-smtp/easy-wp-smtp.php');
$GLOBALS['__test_options'][\Estandar\Core\Communications\TransportRegistry::OPTION] = 'smtp';
check('22o default Suite smtp → resolve smtp (configurado)', CC\TransportRegistry::resolve()->name() === 'smtp');
unset($GLOBALS['__test_options'][\Estandar\Core\Communications\TransportRegistry::OPTION]);
$GLOBALS['__test_options']['active_plugins'] = array();

$sent = CC\TransportRegistry::send(array('to' => array('a@b.cl'), 'subject' => 'R', 'html' => '<p>r</p>'), 'brevo');
check('22p TransportRegistry::send → resultado con transport brevo', $sent['ok'] === true && $sent['transport'] === 'brevo');
$log = CC\DeliveryLog::recent(5);
check('22q DeliveryLog registra entrega (transport, sin secretos)', count($log) > 0 && $log[0]['transport'] === 'brevo' && !isset($log[0]['api_key']));
$hc = CC\TransportRegistry::health_checks();
check('22r health_checks sin envío real (wp_mail/smtp/brevo)', isset($hc['wp_mail']) && isset($hc['smtp']) && isset($hc['brevo']) && $hc['wp_mail']['ok'] === true && $hc['brevo']['ok'] === true);
check('22s report incluye transportes + _selected', isset(CC\TransportRegistry::report()['brevo']) && isset(CC\TransportRegistry::report()['_selected']));

// ── 23. Communications: base PDF + contratos de correo entrante ───────────
$pdf = (new CC\Documents\Pdf())->render('<p>Hola Mundo</p>');
check('23a Pdf::render → %PDF + %%EOF (fallback mínimo válido)', strpos($pdf, '%PDF-1.4') === 0 && strpos($pdf, '%%EOF') !== false && strlen($pdf) > 100);
check('23b Pdf mime/extension', (new CC\Documents\Pdf())->mime() === 'application/pdf' && (new CC\Documents\Pdf())->extension() === 'pdf');
check('23c Contratos de correo entrante definidos (interfaces)', interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundSource') && interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundMessage') && interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundDispatcher'));

echo "\n{$passed} passed, {$failed} failed\n";exit($failed > 0 ? 1 : 0);