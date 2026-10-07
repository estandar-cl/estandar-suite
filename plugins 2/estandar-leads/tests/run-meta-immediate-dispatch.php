<?php
/**
 * Contract test: dispatch inmediato in-process de Meta CAPI (hook 'shutdown',
 * sin loopback HTTP, sin endpoints públicos, independiente de WP-Cron).
 *
 * Cubre: registro diferido en shutdown · idempotencia por request · dispatch
 * inmediato procesa con cap IMMEDIATE_BATCH · lock anti-concurrencia (cero
 * doble procesamiento) · cron como recovery (BATCH_SIZE) · takeover de lock
 * huérfano · test_mode sigue reteniendo. Stubs $wpdb + HTTP + options. Run:
 *   php tests/run-meta-immediate-dispatch.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']       = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']       = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
$GLOBALS['__hooks']      = array();
$GLOBALS['__telemetry']  = array();

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function add_option( $k, $v, $d = '', $a = 'yes' ) {
	if ( isset( $GLOBALS['__opts'][ $k ] ) ) { return false; }
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function do_action( $hook = '', ...$args ) {
	if ( $hook === 'estandar_telemetry_event' ) {
		$GLOBALS['__telemetry'][] = $args[0] ?? array();
	}
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__hooks'][ $hook ][] = array( 'cb' => $cb, 'prio' => $prio );
}
function wp_next_scheduled( $h ) { return time(); }
function wp_schedule_event( $t, $i, $h ) {}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__next'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// ── Fake wpdb ────────────────────────────────────────────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $updates = array();
	public $selects = 0;
	public $lastSelectArgs = array();
	public $pendingItems = array();
	public $leadRow = null;
	public $insertResult = 1;
	public $throwOnSelect = false;

	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { $this->queries[] = $p; return $this->insertResult; }
	public function get_results( $p ) {
		$this->selects++;
		$this->lastSelectArgs = is_array( $p ) ? $p['a'] : array();
		if ( $this->throwOnSelect ) {
			$this->throwOnSelect = false;
			throw new RuntimeException( 'boom-select' );
		}
		return $this->pendingItems;
	}
	public function get_row( $p ) { return $this->leadRow; }
	public function get_var( $p ) { return 0; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { $this->updates[] = array( 'data' => $d, 'where' => $w ); return 1; }
	public function reset() { $this->queries = array(); $this->updates = array(); $this->selects = 0; $this->lastSelectArgs = array(); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function configure( array $extra = array() ) {
	$GLOBALS['__opts'] = array_merge( array(
		'estandar_leads_meta_pixel_id'     => 'PIX1',
		'estandar_leads_meta_access_token' => 'TOK1',
	), $extra );
}
function reset_all() {
	$GLOBALS['wpdb']->reset();
	$GLOBALS['__http_calls'] = array();
	$GLOBALS['__telemetry']  = array();
}
function shutdown_cbs() {
	return $GLOBALS['__hooks']['shutdown'] ?? array();
}
function pendingItem() {
	return (object) array(
		'id' => 1, 'lead_id' => 87, 'export_status' => 'CAPTURED',
		'event_time' => '2026-07-10 12:00:00', 'attempts' => 0, 'queue_status' => 'pending',
	);
}
$GLOBALS['wpdb']->leadRow = (object) array(
	'id' => 87, 'lead_uuid' => 'uuid-87', 'email' => 'x@y.com',
	'project_slug' => '', 'updated_at' => '2026-07-01 09:00:00', 'first_fbclid' => 'fb-paid-87',
);
$LOCK = 'estandar_leads_meta_worker_lock';

// ── 1. init: cron conservado, CERO endpoints públicos ────────────────────────
configure();
Estandar_Leads_Meta_Adapter::init();
check( 'init conserva el hook cron (recovery)', isset( $GLOBALS['__hooks'][ Estandar_Leads_Meta_Adapter::CRON_HOOK ] ) );
check( 'init NO registra endpoints wp_ajax (sin superficie pública)',
	count( array_filter( array_keys( $GLOBALS['__hooks'] ), function ( $h ) {
		return strpos( $h, 'wp_ajax' ) === 0;
	} ) ) === 0 );

// ── 2. test_mode=1 → enqueue NO difiere dispatch (cola retenida) ─────────────
configure( array( 'estandar_leads_meta_test_mode' => '1' ) );
reset_all();
Estandar_Leads_Meta_Adapter::on_lead_ingested( 90, 510, true, array() );
check( 'test_mode=1 → enqueue sin registro en shutdown', count( shutdown_cbs() ) === 0 );

// ── 3. Enqueue de fila nueva → UN registro en shutdown ───────────────────────
configure();
reset_all();
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 501, true, array() );
check( 'fila nueva → dispatch diferido a shutdown (1 registro)', count( shutdown_cbs() ) === 1 );
check( 'registro con prioridad PHP_INT_MAX (último del shutdown)',
	( shutdown_cbs()[0]['prio'] ?? null ) === PHP_INT_MAX );

// ── 4. Idempotencia por request: dup y segundo lead no re-registran ──────────
$GLOBALS['wpdb']->insertResult = 0; // INSERT IGNORE absorbió el duplicado
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 502, false, array() );
$GLOBALS['wpdb']->insertResult = 1; // segundo lead real, mismo request
Estandar_Leads_Meta_Adapter::on_lead_ingested( 88, 503, true, array() );
check( 'dup + segundo enqueue → sigue habiendo 1 registro shutdown', count( shutdown_cbs() ) === 1 );

// ── 5. Shutdown → worker procesa con cap inmediato y libera lock ─────────────
reset_all();
$GLOBALS['wpdb']->pendingItems = array( pendingItem() );
Estandar_Leads_Meta_Adapter::dispatch_on_shutdown();
check( 'dispatch inmediato → consulta la cola (1 SELECT)', $GLOBALS['wpdb']->selects === 1 );
check( 'dispatch inmediato → envía a Graph (1 HTTP)', count( $GLOBALS['__http_calls'] ) === 1 );
check( 'dispatch inmediato → item completed',
	( end( $GLOBALS['wpdb']->updates )['data']['queue_status'] ?? '' ) === 'completed' );
check( 'lock liberado tras el dispatch', ! isset( $GLOBALS['__opts'][ $LOCK ] ) );
check( 'cap inmediato: SELECT con LIMIT ' . Estandar_Leads_Meta_Adapter::IMMEDIATE_BATCH,
	end( $GLOBALS['wpdb']->lastSelectArgs ) === Estandar_Leads_Meta_Adapter::IMMEDIATE_BATCH );

// ── 6. Flag reseteado → un request posterior vuelve a diferir ────────────────
Estandar_Leads_Meta_Adapter::on_lead_ingested( 89, 504, true, array() );
check( 'nuevo request (flag reseteado) → nuevo registro shutdown', count( shutdown_cbs() ) === 2 );

// ── 7. Lock ocupado → cero doble procesamiento; cron recupera ────────────────
configure();
reset_all();
$GLOBALS['__opts'][ $LOCK ] = (string) time(); // otro worker activo
Estandar_Leads_Meta_Adapter::dispatch_on_shutdown();
check( 'lock ocupado → 0 SELECT / 0 HTTP / 0 transiciones',
	$GLOBALS['wpdb']->selects === 0 && count( $GLOBALS['__http_calls'] ) === 0 && count( $GLOBALS['wpdb']->updates ) === 0 );
check( 'lock ocupado → el lock ajeno NO se libera', isset( $GLOBALS['__opts'][ $LOCK ] ) );

unset( $GLOBALS['__opts'][ $LOCK ] ); // el otro worker terminó
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
check( 'fallback cron: con lock libre el item pending se procesa',
	( end( $GLOBALS['wpdb']->updates )['data']['queue_status'] ?? '' ) === 'completed' );
check( 'cap cron: SELECT con LIMIT ' . Estandar_Leads_Meta_Adapter::BATCH_SIZE,
	end( $GLOBALS['wpdb']->lastSelectArgs ) === Estandar_Leads_Meta_Adapter::BATCH_SIZE );

// ── 8. Lock huérfano (worker muerto) → el cron lo toma y libera ──────────────
configure();
reset_all();
$GLOBALS['__opts'][ $LOCK ] = (string) ( time() - 999 ); // > LOCK_TTL
Estandar_Leads_Meta_Adapter::process_queue();
check( 'lock huérfano → takeover y el item se procesa', $GLOBALS['wpdb']->selects === 1 );
check( 'takeover → lock liberado al terminar', ! isset( $GLOBALS['__opts'][ $LOCK ] ) );

// ── 9. test_mode=1 → el dispatch inmediato tampoco procesa ───────────────────
configure( array( 'estandar_leads_meta_test_mode' => '1' ) );
reset_all();
Estandar_Leads_Meta_Adapter::dispatch_on_shutdown();
check( 'test_mode=1 → dispatch inmediato retiene (0 SELECT / 0 HTTP)',
	$GLOBALS['wpdb']->selects === 0 && count( $GLOBALS['__http_calls'] ) === 0 );

// ── 10. Excepción en el worker → shutdown NO propaga; telemetría + pending ───
configure();
reset_all();
$GLOBALS['wpdb']->pendingItems  = array( pendingItem() );
$GLOBALS['wpdb']->throwOnSelect = true;
Estandar_Leads_Meta_Adapter::dispatch_on_shutdown(); // un Throwable aquí abortaría el test
check( 'excepción capturada → el test sigue vivo (no propaga al shutdown)', true );
$err = array_values( array_filter( $GLOBALS['__telemetry'], function ( $e ) {
	return ( $e['code'] ?? '' ) === 'META_IMMEDIATE_DISPATCH_FAILED';
} ) );
check( 'excepción → telemetría META_IMMEDIATE_DISPATCH_FAILED', count( $err ) === 1 );
check( 'telemetría sanitizada: clase + mensaje truncado, sin payload',
	( $err[0]['context']['exception'] ?? '' ) === 'RuntimeException'
	&& ( $err[0]['context']['message'] ?? '' ) === 'boom-select'
	&& array_keys( $err[0]['context'] ) === array( 'exception', 'message' ) );
check( 'excepción → 0 transiciones (fila sigue pending)', count( $GLOBALS['wpdb']->updates ) === 0 );
check( 'excepción → lock liberado (finally del worker)', ! isset( $GLOBALS['__opts'][ $LOCK ] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
