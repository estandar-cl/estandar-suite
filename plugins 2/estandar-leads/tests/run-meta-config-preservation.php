<?php
/**
 * Regression: la pantalla Integraciones / Integration Registry NO borra ni
 * altera la configuración Meta existente.
 *
 * Cubre: config legacy intacta (mismas 4 wp_options pre-upgrade) · rutas de
 * render con CERO escrituras · guardado sin token conserva el token ·
 * secreto vacío no pisa el guardado · guardado parcial no borra claves no
 * enviadas · provider sin core = no-op sin fatal · provider con core real
 * (require del class-integration-registry.php de estandar-core-mu).
 *
 * Run: php tests/run-meta-config-preservation.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs (con contador de escrituras) ─────────────────────────────
$GLOBALS['__opts']         = array();
$GLOBALS['__opt_writes']   = array();   // toda update_option queda registrada
$GLOBALS['__opt_deletes']  = array();
$GLOBALS['__http_calls']   = array();
$GLOBALS['__next']         = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__opt_writes'][] = $k;
	$GLOBALS['__opts'][ $k ]   = $v;
	return true;
}
function delete_option( $k ) { $GLOBALS['__opt_deletes'][] = $k; unset( $GLOBALS['__opts'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function do_action( $hook = '', ...$args ) {
	if ( $hook === 'estandar_integrations_register' && isset( $GLOBALS['__register_cb'] ) ) {
		call_user_func( $GLOBALS['__register_cb'] );
	}
}
function wp_remote_post( $url, $args ) { $GLOBALS['__http_calls'][] = $args; return $GLOBALS['__next']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// ── Fake wpdb (read-only para queue_stats/log viewer) ────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { $this->queries[] = $p; return 1; }
	public function get_results( $p ) { return array(); }
	public function get_row( $p ) { return null; }
	public function get_var( $p ) { return null; } // SHOW TABLES → tabla ausente
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-meta-pixel.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

// Instalación LEGACY real: solo las 4 wp_options que existían antes de 13.0.47.
const LEGACY = array(
	'estandar_leads_meta_pixel_id'        => '111222333444555',
	'estandar_leads_meta_access_token'    => 'EAA-LEGACY-TOKEN',
	'estandar_leads_meta_test_event_code' => 'TEST77',
	'estandar_leads_meta_test_mode'       => '0',
);
function seed_legacy() {
	$GLOBALS['__opts']        = LEGACY;
	$GLOBALS['__opt_writes']  = array();
	$GLOBALS['__opt_deletes'] = array();
}

// ── 1. Config legacy: lectura intacta + defaults correctos ──────────────────
seed_legacy();
$s = Estandar_Leads_Meta_Adapter::get_settings();
check( 'legacy: pixel_id intacto', $s['pixel_id'] === '111222333444555' );
check( 'legacy: access_token intacto', $s['access_token'] === 'EAA-LEGACY-TOKEN' );
check( 'legacy: test_event_code intacto', $s['test_event_code'] === 'TEST77' );
check( 'legacy sin toggles → Meta enabled activo', Estandar_Leads_Meta_Adapter::is_enabled() === true );
check( 'legacy sin toggles → Server Events activo', Estandar_Leads_Meta_Adapter::server_events_enabled() === true );
check( 'legacy sin toggles → Browser Events inactivo', Estandar_Leads_Meta_Pixel::is_active() === false );
check( 'leer settings NO escribe ninguna opción', $GLOBALS['__opt_writes'] === array() );

// ── 2. Rutas de render (pantalla Integraciones) = CERO escrituras ───────────
seed_legacy();
Estandar_Leads_Meta_Adapter::queue_stats();
Estandar_Leads_Meta_Adapter::recent_log_rows( 20 );
Estandar_Leads_Meta_Pixel::is_active();
check( 'queue_stats + recent_log_rows + is_active → 0 update_option', $GLOBALS['__opt_writes'] === array() );
check( 'rutas de render → 0 delete_option', $GLOBALS['__opt_deletes'] === array() );

// ── 3. Guardar sin tocar el token (campo vacío) lo conserva ─────────────────
seed_legacy();
Estandar_Leads_Meta_Adapter::save_settings( array(
	'meta_pixel_id'        => '111222333444555',
	'meta_access_token'    => '',              // campo password renderizado vacío
	'meta_test_event_code' => 'TEST77',
	'meta_enabled'         => '1',
	'meta_server_events'   => '1',
) );
check( 'guardado con token vacío → token legacy conservado',
	get_option( 'estandar_leads_meta_access_token' ) === 'EAA-LEGACY-TOKEN' );
check( 'guardado con token vacío → no hubo write sobre access_token',
	! in_array( 'estandar_leads_meta_access_token', $GLOBALS['__opt_writes'], true ) );

// ── 4. Rotación explícita sí escribe ─────────────────────────────────────────
seed_legacy();
Estandar_Leads_Meta_Adapter::save_settings( array( 'meta_access_token' => 'EAA-NUEVO' ) );
check( 'token nuevo no vacío → rotado', get_option( 'estandar_leads_meta_access_token' ) === 'EAA-NUEVO' );

// ── 5. Guardado parcial: claves ausentes del POST no se tocan ────────────────
seed_legacy();
Estandar_Leads_Meta_Adapter::save_settings( array( 'meta_pixel_id' => '999000999000999' ) );
check( 'parcial: pixel_id actualizado', get_option( 'estandar_leads_meta_pixel_id' ) === '999000999000999' );
check( 'parcial: access_token no enviado → intacto', get_option( 'estandar_leads_meta_access_token' ) === 'EAA-LEGACY-TOKEN' );
check( 'parcial: test_event_code no enviado → intacto', get_option( 'estandar_leads_meta_test_event_code' ) === 'TEST77' );
check( 'nunca se ejecuta delete_option', $GLOBALS['__opt_deletes'] === array() );

// ── 6. Provider sin core → no-op sin fatal ───────────────────────────────────
check( 'sin core: Estandar_Integration_Provider no existe', ! class_exists( 'Estandar_Integration_Provider' ) );
require $base . 'class-estandar-leads-meta-integration-provider.php'; // return temprano
check( 'sin core: include del provider no define la clase ni fatalea',
	! class_exists( 'Estandar_Leads_Meta_Integration_Provider' ) );

// ── 7. Con Integration Registry REAL (Primer) ───────────────────────────────
$core_registry = dirname( __DIR__, 2 ) . '/estandar-primer/includes/class-integration-registry.php';
check( 'Primer class-integration-registry.php existe', file_exists( $core_registry ) );
require_once $core_registry;
// require plano (no _once): el paso 6 ya incluyó el archivo con early-return;
// ahora con el core cargado debe re-ejecutarse para definir la clase.
require $base . 'class-estandar-leads-meta-integration-provider.php';
check( 'con core: provider definido', class_exists( 'Estandar_Leads_Meta_Integration_Provider' ) );

$GLOBALS['__register_cb'] = function () {
	Estandar_Integration_Registry::register( new Estandar_Leads_Meta_Integration_Provider() );
};

seed_legacy();
$meta = Estandar_Integration_Registry::get( 'meta' );
check( 'registry resuelve meta → provider real (no placeholder)',
	$meta instanceof Estandar_Leads_Meta_Integration_Provider );
check( 'placeholders google/linkedin presentes sin implementación',
	Estandar_Integration_Registry::get( 'google' ) instanceof Estandar_Integration_Placeholder_Provider
	&& Estandar_Integration_Registry::get( 'linkedin' ) instanceof Estandar_Integration_Placeholder_Provider );
check( 'Registry::settings(meta) == adapter get_settings (misma fuente, sin option names)',
	Estandar_Integration_Registry::settings( 'meta' ) === Estandar_Leads_Meta_Adapter::get_settings() );

// Abrir la pantalla = settings_fields() + status() + get_settings() de cada provider.
seed_legacy();
$meta->settings_fields();
$meta->status();
Estandar_Integration_Registry::settings( 'meta' );
Estandar_Integration_Registry::is_active( 'meta' );
check( 'abrir pantalla (fields+status+settings) → 0 update_option', $GLOBALS['__opt_writes'] === array() );
check( 'abrir pantalla → 0 delete_option', $GLOBALS['__opt_deletes'] === array() );

$fields = $meta->settings_fields();
$token_field = null;
foreach ( $fields as $f ) {
	if ( $f['name'] === 'meta_access_token' ) { $token_field = $f; }
}
check( 'campo token del provider se renderiza vacío (secreto nunca al DOM)',
	$token_field !== null && $token_field['value'] === '' );

// Guardar desde la pantalla (provider->save_settings) con token vacío.
seed_legacy();
$meta->save_settings( array(
	'meta_pixel_id'       => '111222333444555',
	'meta_access_token'   => '',
	'meta_enabled'        => '1',
	'meta_server_events'  => '1',
) );
check( 'guardar desde Integraciones sin token → token legacy conservado',
	get_option( 'estandar_leads_meta_access_token' ) === 'EAA-LEGACY-TOKEN' );
check( 'guardar desde Integraciones → mismas wp_options, cero claves nuevas fuera del prefijo',
	array_values( array_filter( array_keys( $GLOBALS['__opts'] ), function ( $k ) {
		return strpos( $k, 'estandar_leads_meta_' ) !== 0;
	} ) ) === array() );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
