<?php
/**
 * META_GATE_V1 / GOOGLE_GATE_V1 — acceptance C.
 *
 * C. Lead Google con `_fbp` pero sin evidencia Meta:
 *    - Meta no se encola ni despacha;
 *    - queda decisión NOT_ELIGIBLE/skipped persistida (ads_dispatch_skipped);
 *    - OCI sigue evaluándose independientemente (Google eligible).
 *
 * Run: php tests/run-meta-gate-skip.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => 'PX-1',
	'estandar_leads_meta_access_token' => 'TOKEN-1',
);
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }
function add_action( ...$a ) {}
function do_action( ...$a ) {}
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }

// ── Repository stub: captures persisted decision events ──────────────────────
class Estandar_Leads_Repository {
	public static $events = array();
	public static function insert_event( $lead_id, $event_type, $args = array() ) {
		self::$events[] = array( 'lead_id' => (int) $lead_id, 'type' => (string) $event_type, 'args' => $args );
		return count( self::$events );
	}
}

// ── QA Policy stub: real lead, no policy skip ────────────────────────────────
class Estandar_Leads_QA_Policy {
	public static function is_test_lead( int $lead_id ): bool { return false; }
	public static function from_payload( array $payload ): array { return array( 'is_test_lead' => false ); }
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool { return false; }
}

// ── Fake wpdb: one lead row + query capture ──────────────────────────────────
class FakeWpdb {
	public $prefix  = 'wp_';
	public $lead    = null;
	public $queries = array();
	public function prepare( $q, ...$a ) {
		foreach ( $a as $v ) { $q = preg_replace( '/%[ds]/', (string) $v, $q, 1 ); }
		return $q;
	}
	public function get_row( $q ) {
		if ( strpos( $q, 'FROM wp_es_leads' ) !== false ) { return $this->lead; }
		return null;
	}
	public function get_var( $q ) { return null; }
	public function query( $q ) { $this->queries[] = $q; return 1; }
	public function get_results( $q ) { return array(); }
	public function update( ...$a ) { return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../includes/class-estandar-leads-attribution.php';
require __DIR__ . '/../includes/class-estandar-leads-meta-adapter.php';
require __DIR__ . '/../includes/class-estandar-leads-google-adapter.php';

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

// ── C · Lead adquirido por Google Ads con cookie _fbp presente ───────────────
$GLOBALS['wpdb']->lead = (object) array(
	'id'             => 42,
	'lead_uuid'      => 'uuid-42',
	'first_gclid'    => 'gclid-999',
	'first_meta_fbp' => 'fb.1.1700000000.111', // _fbp cookie: NUNCA habilita Meta
	'first_fbclid'   => '',
	'first_meta_fbc' => '',
	'utm_source'     => 'google',
	'utm_medium'     => 'cpc',
	'project_slug'   => 'ak-contadores',
);

Estandar_Leads_Meta_Adapter::on_lead_ingested( 42, 7, true, array() );

$meta_inserts = array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) {
	return strpos( $q, 'es_meta_queue' ) !== false;
} );
check( 'C: Meta NOT enqueued (google acquisition, _fbp only)', empty( $meta_inserts ) );

$skips = array_values( array_filter( Estandar_Leads_Repository::$events, static function ( $e ) {
	return $e['type'] === 'ads_dispatch_skipped';
} ) );
check( 'C: skip decision persisted (ads_dispatch_skipped)', count( $skips ) === 1 );
$meta_skip = $skips[0]['args']['metadata'] ?? array();
check( 'C: decision = not_eligible on platform meta', ( $meta_skip['decision'] ?? '' ) === 'not_eligible' && ( $meta_skip['platform'] ?? '' ) === 'meta' );
check( 'C: reason persisted (non-Meta acquisition / _fbp insufficient)', stripos( (string) ( $skips[0]['args']['reason'] ?? '' ), '_fbp' ) !== false
	|| stripos( (string) ( $skips[0]['args']['reason'] ?? '' ), 'non-meta' ) !== false );
check( 'C: canonical channel recorded', ( $meta_skip['channel'] ?? '' ) === 'google_ads' );

// status-changed path also gated (no queue write, decision persisted per intento).
Estandar_Leads_Meta_Adapter::on_status_changed( 42, 'NEW', 'QUALIFIED', '2026-07-14 12:00:00' );
$meta_inserts = array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) {
	return strpos( $q, 'es_meta_queue' ) !== false;
} );
check( 'C: status change to QUALIFIED still not enqueued to Meta', empty( $meta_inserts ) );

// ── C · OCI se evalúa independiente: Google gate = eligible ──────────────────
$google_gate = Estandar_Leads_Google_Adapter::dispatch_gate( 42, 'QUALIFIED' );
check( 'C: Google OCI gate independent → eligible (gclid)', ! empty( $google_gate['allowed'] )
	&& $google_gate['evidence'] === 'gclid' && $google_gate['channel'] === 'google_ads' );

// ── Control positivo: lead Meta legítimo SÍ se encola ────────────────────────
Estandar_Leads_Repository::$events = array();
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->lead = (object) array(
	'id'             => 43,
	'lead_uuid'      => 'uuid-43',
	'first_gclid'    => '',
	'first_meta_fbp' => 'fb.1.1700000000.222',
	'first_fbclid'   => 'fbclid-abc',
	'first_meta_fbc' => '',
	'utm_source'     => 'facebook',
	'utm_medium'     => 'paid',
	'project_slug'   => 'ak-contadores',
);
Estandar_Leads_Meta_Adapter::on_lead_ingested( 43, 8, true, array() );
$meta_inserts = array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) {
	return strpos( $q, 'es_meta_queue' ) !== false;
} );
check( 'control: Meta lead with fbclid IS enqueued', count( $meta_inserts ) === 1 );
check( 'control: no skip event for eligible lead', empty( Estandar_Leads_Repository::$events ) );

// ── Guard: _fbp solo, canal desconocido → tampoco habilita Meta ──────────────
Estandar_Leads_Repository::$events = array();
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->lead = (object) array(
	'id'             => 44,
	'lead_uuid'      => 'uuid-44',
	'first_gclid'    => '',
	'first_meta_fbp' => 'fb.1.1700000000.333',
	'first_fbclid'   => '',
	'first_meta_fbc' => '',
	'utm_source'     => '',
	'utm_medium'     => '',
	'project_slug'   => '',
);
Estandar_Leads_Meta_Adapter::on_lead_ingested( 44, 9, true, array() );
$meta_inserts = array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) {
	return strpos( $q, 'es_meta_queue' ) !== false;
} );
check( 'guard: _fbp alone (no channel) never enqueues Meta', empty( $meta_inserts ) );
check( 'guard: decision persisted for _fbp-only lead', count( Estandar_Leads_Repository::$events ) === 1 );

echo "\n" . ( $failed === 0 ? 'ALL PASS' : $failed . ' FAILED' ) . "\n";
exit( $failed === 0 ? 0 : 1 );
