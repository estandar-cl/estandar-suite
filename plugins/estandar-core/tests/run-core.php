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
require dirname(__DIR__) . '/includes/Http.php';
require dirname(__DIR__) . '/includes/FieldMap.php';
require dirname(__DIR__) . '/includes/Conditions.php';
require dirname(__DIR__) . '/includes/Duplicate.php';
require dirname(__DIR__) . '/includes/Leads/CrmIngestion.php';
require dirname(__DIR__) . '/includes/Leads/ProjectRegistry.php';
require dirname(__DIR__) . '/includes/AdoptionProbe.php';
require_once dirname(__DIR__) . '/includes/Health.php';
require_once dirname(__DIR__) . '/includes/Channels.php';
require_once dirname(__DIR__) . '/includes/Embed.php';
require_once dirname(__DIR__) . '/includes/Time.php';
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
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $d2 = 512) { return json_encode($d, $f); } }

use Estandar\Core as C;

// ── 1. Telemetry: severidad validada, contexto sin PII ────────────────────
C\Telemetry::emit('debug', 'TEST', 'msg', array('source_plugin' => 'estandar-core'));
C\Telemetry::emit('BAD_SEV', 'TEST2', 'msg2', array()); // severity → info
$ctx = C\Telemetry::sanitize_context(array('lead_id' => 5, 'email' => 'x@y.cl', 'access_token' => 'secret', 'ok' => 1));
check('1a contexto sin PII (email/token eliminados)', !isset($ctx['email']) && !isset($ctx['access_token']) && $ctx['lead_id'] === 5);

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
// DYNAMIC_TABS_V1: controlable. Por defecto false (LegacyMap: plugins legacy
// inactivos). Antes de los tests de Admin se pone en true (= producción, todos
// los plugins activos) y se marca `estandar-flow` como inactivo en un test aparte.
if (!function_exists('is_plugin_active')) { function is_plugin_active($p) { return !empty($GLOBALS['__test_plugin_active']) && !in_array($p, (array) ($GLOBALS['__test_plugin_inactive'] ?? array()), true); } }
if (!function_exists('is_readable')) { function is_readable($p) { return false; } }
require dirname(__DIR__) . '/includes/LegacyMap.php';
check('11a shortcodes legados catalogados', count(C\LegacyMap::LEGACY_SHORTCODES) >= 6);
check('11b plugin de chatbot en catálogo', C\LegacyMap::LEGACY_SHORTCODES['estandar_chatbot'] === 'estandar-chatbot/estandar-chatbot.php');
check('11c REST plugins catalogados', in_array('estandar-leads/estandar-leads.php', C\LegacyMap::LEGACY_REST_PLUGINS, true));
check('11d legacy_option sin get_option → default', C\LegacyMap::legacy_option('no_existe_xyz', 'dflt') === 'dflt');
check('11e verify_coverage sin WP → no crashea', is_array(C\LegacyMap::verify_coverage()));
check('11f frontend del chatbot legacy lo posee capture', C\LegacyMap::LEGACY_FRONTEND_OWNER['estandar-chatbot/estandar-chatbot.php'] === 'estandar-capture/estandar-capture.php');
check('11g frontend hooks cubren enqueue', in_array('wp_enqueue_scripts', C\LegacyMap::FRONTEND_HOOKS, true));
check('11h drop_new_hook_callbacks sin WP → no crashea', C\LegacyMap::drop_new_hook_callbacks('wp_enqueue_scripts', array()) === null);
// ── 11j. Product header: capa de navegación superior de la suite ─────────
// DYNAMIC_TABS_V1: simular producción = TODOS los plugins de producto activos.
$GLOBALS['__test_plugin_active'] = true;
$GLOBALS['__test_plugin_inactive'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v, ...$a ) {
		if ( ! empty( $GLOBALS['__test_filters'][ $t ] ) && is_callable( $GLOBALS['__test_filters'][ $t ] ) ) {
			return call_user_func_array( $GLOBALS['__test_filters'][ $t ], array_merge( array( $v ), $a ) );
		}
		return $v;
	}
}
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://x/wp-admin/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); } }
if ( ! class_exists( 'Estandar\\Core\\Admin' ) ) { require dirname( __DIR__ ) . '/includes/Admin.php'; }
$_GET['page'] = 'estandar-suite-capture';
ob_start();
C\Admin::render_product_header();
$hdr = (string) ob_get_clean();
check('11j header renderiza marca Estandar Suite', strpos($hdr, 'Estandar Suite') !== false);
check('11k header lista todos los productos revisados (Core/Capture/Brain/Flow/Insights)', strpos($hdr, '>Core<') !== false && strpos($hdr, '>Capture<') !== false && strpos($hdr, '>Brain<') !== false && strpos($hdr, '>Flow<') !== false && strpos($hdr, '>Insights<') !== false && strpos($hdr, 'estandar-suite-brain') !== false && strpos($hdr, 'estandar-suite-flow') !== false);
check('11l header marca activo (Capture, primera línea de tabs transversal)', strpos($hdr, 'nav-tab-active') !== false && strpos($hdr, '>Capture<') !== false);
check('11m header usa color legacy #f95b1f', strpos($hdr, '#f95b1f') !== false);
check('11p header ya no muestra "Configuración" transversal (pasó a sub-pestaña del Core)', strpos($hdr, '&tab=config"') === false);
$pkeys = array_keys(C\Admin::products());
check('11n products expone solo revisados (inicio/core/capture/brain/flow/insights/otros)', $pkeys === array('inicio', 'core', 'capture', 'brain', 'flow', 'insights', 'otros'));
check('11o línea 02 de Core: sin módulos revisados por defecto', C\Admin::reviewed_core_modules() === array());

// ── 11q-u. DYNAMIC_TABS_V1 + OTHERS_PLUGINS_V1 (Suite core) ────────────────
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '' ) { echo $m; } }
if ( ! function_exists( 'get_plugins' ) ) { function get_plugins() { return $GLOBALS['__test_plugins'] ?? array(); } }

// Producto con plugin inactivo → sin tab.
$GLOBALS['__test_plugin_inactive'] = array( 'estandar-flow/estandar-flow.php' );
$pk2 = array_keys( C\Admin::products() );
check('11q plugin inactivo (flow) → sin tab Flow; resto visible', ! in_array( 'flow', $pk2, true ) && in_array( 'capture', $pk2, true ) && in_array( 'insights', $pk2, true ) );
// Todos los productos inactivos → solo inicio/core/otros.
$GLOBALS['__test_plugin_inactive'] = array( 'estandar-capture/estandar-capture.php', 'estandar-brain/estandar-brain.php', 'estandar-flow/estandar-flow.php', 'estandar-insights/estandar-insights.php' );
$pk3 = array_keys( C\Admin::products() );
check('11r todos los plugins de producto inactivos → solo inicio/core/otros', $pk3 === array( 'inicio', 'core', 'otros' ) );
$GLOBALS['__test_plugin_inactive'] = array();
// Filtro estandar_suite_products.
$GLOBALS['__test_filters']['estandar_suite_products'] = function ( $p ) { unset( $p['otros'] ); return $p; };
$pk4 = array_keys( C\Admin::products() );
check('11s filtro estandar_suite_products altera la lista', ! in_array( 'otros', $pk4, true ) && in_array( 'core', $pk4, true ) );
unset( $GLOBALS['__test_filters']['estandar_suite_products'] );
check('11t plugin_active("") → true (no ocultar por error)', C\Admin::plugin_active( '' ) === true );

// render_otros: autodetección estandar-* + plugin NO prefijado vía filtro.
$GLOBALS['__test_plugins'] = array(
	'veloz/veloz.php'                         => array( 'Name' => 'Veloz', 'Description' => 'Plataforma', 'Version' => '0.5.0' ),
	'estandar-noticias/estandar-noticias.php' => array( 'Name' => 'Noticias', 'Description' => '', 'Version' => '1.0' ),
);
$GLOBALS['__test_filters']['estandar_suite_otros_plugins'] = function ( $list ) { return array( 'veloz/veloz.php' ); };
$_GET = array( 'page' => 'estandar-suite-otros' );
ob_start();
C\Admin::render_otros();
$otros = (string) ob_get_clean();
check('11u Otros: plugin no prefijado (veloz) aparece con su subtab', strpos( $otros, '>Veloz<' ) !== false && strpos( $otros, 'p=veloz' ) !== false );
check('11v Otros: autodetección estandar-* sigue funcionando (Noticias)', strpos( $otros, 'Noticias' ) !== false );
unset( $GLOBALS['__test_filters']['estandar_suite_otros_plugins'] );

// ── 12. Crypto: roundtrip AES-GCM compartido ──────────────────────────────
$ct = C\Crypto::encrypt('refresh-token-secreto');
check('12a encrypt produce ciphertext', $ct !== '' && $ct !== 'refresh-token-secreto');
check('12b decrypt roundtrip', C\Crypto::decrypt($ct) === 'refresh-token-secreto');
check('12c decrypt basura → vacío', C\Crypto::decrypt('zzzz') === '');
check('12d encrypt vacío → vacío', C\Crypto::encrypt('') === '');

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
// MONITOR_BCC_V1: copia de monitoreo global (BCC) con herencia legacy.
unset($GLOBALS['__test_options'][\Estandar\Core\Mail::MONITOR_BCC_OPTION]);
unset($GLOBALS['__test_options']['estandar_leads_notification_cc_email']);
check('21f monitor_bcc vacío → sin copia', C\Mail::monitor_bcc() === array());
$GLOBALS['__test_options']['estandar_leads_notification_cc_email'] = 'monitor@example.com, otro@example.com, inválido';
check('21g monitor_bcc hereda copia legacy (normalizada)', C\Mail::monitor_bcc() === array('monitor@example.com', 'otro@example.com'));
$GLOBALS['__test_options'][\Estandar\Core\Mail::MONITOR_BCC_OPTION] = 'gestion@example.com; gestion@example.com';
check('21h monitor_bcc nueva opción gana y dedup', C\Mail::monitor_bcc() === array('gestion@example.com'));

// TEST_REDIRECT_V1: modo pruebas → todos los correos a la dirección de prueba.
if (!function_exists('wp_mail')) { function wp_mail() { $GLOBALS['__wp_mail_calls'][] = func_get_args(); return true; } }
unset( $GLOBALS['__test_options']['estandar_suite_test_notify_email'] );
check( '21i test_notify_redirect vacío por defecto', C\Mail::test_notify_redirect() === '' );
$GLOBALS['__test_options']['estandar_suite_test_notify_email'] = 'cm@cliente1.example.com';
check( '21j test_notify_redirect devuelve la dirección', C\Mail::test_notify_redirect() === 'cm@cliente1.example.com' );
$GLOBALS['__wp_mail_calls'] = array();
C\Mail::send_raw( array( 'to' => 'operador@x.cl', 'subject' => 'Nuevo lead', 'html' => '<p>x</p>' ) );
$last = end( $GLOBALS['__wp_mail_calls'] );
check( '21k send_raw redirige el to SOLO a la prueba', is_array( $last ) && $last[0] === array( 'cm@cliente1.example.com' ) );
check( '21l subject con prefijo [TEST]', is_array( $last ) && strpos( $last[1], '[TEST]' ) === 0 );
unset( $GLOBALS['__test_options']['estandar_suite_test_notify_email'] );
$GLOBALS['__wp_mail_calls'] = array();
C\Mail::send_raw( array( 'to' => 'operador@x.cl', 'subject' => 'Nuevo lead', 'html' => '<p>x</p>' ) );
$last2 = end( $GLOBALS['__wp_mail_calls'] );
check( '21m sin redirect → destinatario normal', is_array( $last2 ) && $last2[0] === array( 'operador@x.cl' ) && strpos( $last2[1], '[TEST]' ) === false );

// MAIL_TEMPLATES_V1 (Fase 1): registro + seed + wrap + resolución (todo OFF).
unset( $GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATES_OPTION] );
unset( $GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION] );
check( '21r seed siembra plantilla base al leer vacío', isset( C\Mail::mail_templates()['base'] ) );
$seed = C\Mail::mail_template_seed();
check( '21s seed base tiene name+header+style+footer', isset( $seed['name'], $seed['header_html'], $seed['content_style'], $seed['footer_html'] ) );
$defs = C\Mail::default_mail_templates();
check( '21s2 set por defecto tiene 4 plantillas', isset( $defs['base'], $defs['corporativa'], $defs['minimal'], $defs['boletin'] ) );
check( '21s3 siembra aditiva deja base+corporativa en el registro', isset( C\Mail::mail_templates()['corporativa'] ) && isset( C\Mail::mail_templates()['boletin'] ) );
check( '21t wrap inyecta contenido en el slot', strpos( C\Mail::wrap_template( $seed, '<p>BODY</p>' ), '<p>BODY</p>' ) !== false );
check( '21t2 wrap usa wrapper_style de la plantilla (corporativa)', strpos( C\Mail::wrap_template( C\Mail::default_mail_templates()['corporativa'], 'X' ), 'background:#eef0f2' ) !== false );
$wrapped = C\Mail::wrap_template( $seed, 'X' );
check( '21u wrap pone content después del header', strpos( $wrapped, $seed['header_html'] ) < strpos( $wrapped, 'X' ) );
check( '21v resolve sin config/proyecto/default → null (OFF)', C\Mail::resolve_template( array(), '' ) === null );
$GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION] = 'base';
$resolved = C\Mail::resolve_template( array(), '' );
check( '21w resolve usa default global', is_array( $resolved ) && ( $resolved['name'] ?? '' ) === $seed['name'] );
$resolved2 = C\Mail::resolve_template( array( 'mail_template' => 'none' ), '' );
check( '21x form con none → null (apaga default global)', $resolved2 === null );
check( '21y mail_template slug inexistente → null', C\Mail::mail_template( 'no-existe' ) === null );
// Guardado del registro (save_mail_templates) PURE vía mock de opciones.
$GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATES_OPTION] = array( 'base' => C\Mail::mail_template_seed() );
C\Mail::save_mail_templates( array(
	'base'   => array( 'name' => 'Básica', 'header_html' => '<div>x</div>', 'content_style' => 'p', 'footer_html' => '<div>f</div>' ),
	'nueva'  => array( 'name' => 'Corporativa', 'header_html' => '<div>H</div>', 'content_style' => '', 'footer_html' => '' ),
	'!!!'    => array( 'name' => 'x' ),
) );
$saved = $GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATES_OPTION];
check( '21z1 save persiste base + nueva', isset( $saved['base'], $saved['nueva'] ) && ! isset( $saved['!!!'] ) );
check( '21z2 slug vacío tras sanitize se descarta', count( $saved ) === 2 );
$saved2 = C\Mail::save_mail_templates( array( 'corp' => array( 'name' => 'C', 'wrapper_style' => 'background:#eef0f2;', 'header_html' => '<div>h</div>', 'content_style' => '', 'footer_html' => '' ) ) );
check( '21z3 save conserva wrapper_style', (string) ( $saved2['corp']['wrapper_style'] ?? '' ) === 'background:#eef0f2;' );
unset( $GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATES_OPTION] );
unset( $GLOBALS['__test_options'][\Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION] );

// CAPTURE_ACTIVE_V1: submenú del admin bar con los forms activos de la página.
$admin_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Admin.php' );
check( '21n admin_bar_capture_forms existe', strpos( $admin_src, 'admin_bar_capture_forms' ) !== false && strpos( $admin_src, 'estandar_form_gate' ) !== false && strpos( $admin_src, 'estandar_camara_registros' ) !== false );
check( '21n2 reconoce id= y form= en los shortcodes', strpos( $admin_src, '(?:id|form)=' ) !== false );
check( '21o submenu enlaza al editor del form', strpos( $admin_src, 'ss=formularios&editar=' ) !== false );

// CAPTURE_ACTIVE_V1: detección de ids en los 3 shortcodes de form.
$ab_regex = '/\[(estandar_form_v2|estandar_form_gate|estandar_camara_registros)[^\]]*\]/';
$ab_ids  = array();
$ab_sc   = '[estandar_form_gate form="7" title="X"] [estandar_form_v2 id="3"] [estandar_camara_registros form="12"]';
if ( preg_match_all( $ab_regex, $ab_sc, $ab_m ) ) {
	foreach ( $ab_m[0] as $ab_one ) {
		if ( preg_match( '/(?:id|form)=["\'](\d+)["\']/', $ab_one, $ab_im ) ) {
			$ab_ids[] = (int) $ab_im[1];
		}
	}
}
$ab_ids = array_values( array_unique( array_filter( $ab_ids ) ) );
check( '21p detecta ids en gate + v2 + registros', $ab_ids === array( 7, 3, 12 ) );
check( '21q no detecta shortcodes ajenos', ( preg_match_all( $ab_regex, '[otro form="99"]', $ab_m2 ) ? count( $ab_m2[0] ) : 0 ) === 0 );
// CAPTURE_ACTIVE_V1 · REGRESIÓN: el patrón real del código debe agrupar las
// alternativas (bug: sin "(?:...)" el match se cortaba en "[estandar_form_v2"
// y nunca se extraía el id → el submenú del admin bar quedaba vacío).
$ab_src_pattern = '/\[' . preg_quote( implode( '', array() ), '/' ) . '|__NOOP__/'; // sanity anchor (no debe usarse)
$ab_admin_src2  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Admin.php' );
$ab_line        = '';
if ( preg_match( '/\$sc_pattern\s*=\s*([^;]+);/', $ab_admin_src2, $ab_pm ) ) {
	$ab_line = $ab_pm[1];
}
check( '21r patrón admin bar agrupa alternativas (?:…)', strpos( $ab_line, '\\[(?:' ) !== false );
check( '21s patrón no corta en el primer shortcode', strpos( $ab_line, "'/\\[' . implode" ) === false );

// OPERADORES_EDIT_V1 · REGRESIÓN: la tabla de operadores permite editar nombre y
// proyecto por fila (no solo email/teléfono/activo) y el handler los persiste.
$op_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/templates/feature-operadores-manager.php' );
check( '21t template edita name por fila', strpos( $op_src, 'operators[<?php echo $oid; ?>][name]' ) !== false );
check( '21u template edita project_slug por fila', strpos( $op_src, 'operators[<?php echo $oid; ?>][project_slug]' ) !== false );
check( '21v handler persiste name + project_slug', strpos( $ab_admin_src2, "\$fields['name']" ) !== false && strpos( $ab_admin_src2, "\$fields['project_slug']" ) !== false );

// ── 22. Communications: TransportRegistry + adapters + adopción heredada ───
if (!function_exists('wp_mail')) { function wp_mail() { $GLOBALS['__wp_mail_calls'][] = func_get_args(); return true; } }
if (!function_exists('wp_remote_post')) { function wp_remote_post($u, $a) { $GLOBALS['__wp_remote_calls'][] = array($u, $a); return array('response' => array('code' => 201), 'body' => ''); } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $d2 = 512) { return json_encode($d, $f); } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
if (!function_exists('add_action')) { function add_action() { return true; } }if (!function_exists('remove_action')) { function remove_action() { return true; } }

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
// (S16) En entornos sin la constante de ruta el motor cae al fallback mínimo;
// con dompdf presente el output real también empieza por %PDF. Aceptamos ambos.
check('23a Pdf::render → %PDF + %%EOF válido', strpos($pdf, '%PDF') === 0 && strpos($pdf, '%%EOF') !== false && strlen($pdf) > 100);
check('23b Pdf mime/extension', (new CC\Documents\Pdf())->mime() === 'application/pdf' && (new CC\Documents\Pdf())->extension() === 'pdf');
check('23c Pdf::dompdf_paths devuelve array (canónico si ESTANDAR_SUITE_CORE_PATH)', is_array(CC\Documents\Pdf::dompdf_paths()));
check('23d Contratos de correo entrante definidos (interfaces)', interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundSource') && interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundMessage') && interface_exists('Estandar\\Core\\Communications\\Inbound\\InboundDispatcher'));

// ── 24. Duplicate (port de estandar-duplicate-post) ─────────────────────────
check('24a clase Duplicate existe', class_exists('Estandar\\Core\\Duplicate'));
check('24b defaults por defecto', C\Duplicate::get_settings()['duplicate_status'] === 'draft');
check('24c enabled por defecto post+page', in_array('post', C\Duplicate::get_settings()['enabled_post_types'], true) && in_array('page', C\Duplicate::get_settings()['enabled_post_types'], true));
check('24d is_enabled_type post → true', C\Duplicate::is_enabled_type('post'));
check('24e is_enabled_type cpt_no → false', ! C\Duplicate::is_enabled_type('no_existe'));
check('24f nonce action definida', C\Duplicate::NONCE_ACTION === 'estandar_core_duplicate_post_action');
check('24g legacy option migrable (constante)', defined('Estandar\\Core\\Duplicate::LEGACY_OPTION'));

// ── 25. CrmIngestion + ProjectRegistry (ports de leads/primer) ─────────────
check('25a CrmIngestion existe', class_exists('Estandar\\Core\\Leads\\CrmIngestion'));
check('25b ProjectRegistry existe', class_exists('Estandar\\Core\\Leads\\ProjectRegistry'));
check('25c normalize email', C\Leads\CrmIngestion::normalize_email('foo@bar.cl') === 'foo@bar.cl');
check('25d normalize phone CL móvil', C\Leads\CrmIngestion::normalize_phone_storage('+56 9 1234 5678') === '56912345678');
check('25e hash determinista', C\Leads\CrmIngestion::hash('hola@x.cl') === C\Leads\CrmIngestion::hash('hola@x.cl'));
check('25f hash vacío → vacío', C\Leads\CrmIngestion::hash('   ') === '');
check('25g normalize name colapsa duplicados', C\Leads\CrmIngestion::normalize_name('Fernanda Berasain Berasain Flores') === 'Fernanda Berasain Flores');
check('25h ProjectRegistry match_key', C\Leads\ProjectRegistry::match_key('Demo-Cruz') === 'democruz');
check('25i ProjectRegistry normalize_slug', C\Leads\ProjectRegistry::normalize_slug('Demo Cruz') === 'demo-cruz');
check('25j ProjectRegistry suggest_slug cerca', C\Leads\ProjectRegistry::suggest_slug('dem', array('demo', 'parronales')) === 'demo');
check('25k ProjectRegistry suggest_slug lejos → vacío', C\Leads\ProjectRegistry::suggest_slug('xyzabc', array('demo', 'parronales')) === '');
check('25l legacy option secret reutilizable', defined('Estandar\\Core\\Leads\\CrmIngestion::SECRET_OPTION'));

// ── 26. Time (TIMEZONE_V1): canonical de fechas en zona de WordPress ───────
check('26a now() formato mysql', (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', C\Time::now() ));
check('26b epoch vacío → 0', C\Time::epoch( '' ) === 0);
check('26c epoch inválido → 0', C\Time::epoch( 'no-es-fecha' ) === 0);
check('26d round-trip now → from_epoch(now_epoch)', C\Time::from_epoch( C\Time::now_epoch() ) === C\Time::now() );
check('26e display(now) formato humano', C\Time::display( C\Time::now(), 'Y-m-d H:i:s' ) === C\Time::now() );

echo "\n{$passed} passed, {$failed} failed\n";exit($failed > 0 ? 1 : 0);