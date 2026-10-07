<?php
/**
 * Contract test for Commercial Outcomes (domain refactor).
 * Stubs WP options + $wpdb (incl. the log table). No real DB/network.
 * Run: php tests/run-commercial-outcomes.php
 */

define( 'ABSPATH', __DIR__ );

// ── WP stubs ─────────────────────────────────────────────────────────────────
$GLOBALS['__opts']  = array();
$GLOBALS['__http']  = array();
$GLOBALS['__next']  = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function add_option( $k, $v, $deprecated = '', $autoload = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['__opts'] ) ) { return false; } $GLOBALS['__opts'][ $k ] = $v; return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function do_action() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
class WP_Error { private $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_post( $u, $a ) { $GLOBALS['__http'][] = array( 'url' => $u, 'args' => $a ); return $GLOBALS['__next']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $logRows = array();
	public $pendingItems = array();
	public $leadRow = null;
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { return 1; }
	public function get_var( $p ) { return $this->prefix . 'es_commercial_outcomes_log'; } // table exists
	public function get_row( $p ) { return $this->leadRow; }
	public function get_results( $p ) {
		$q = is_array( $p ) ? $p['q'] : $p;
		if ( strpos( $q, 'es_commercial_outcomes_log' ) !== false ) {
			return array_map( function ( $r ) { return (object) $r; }, $this->logRows );
		}
		return $this->pendingItems;
	}
	public function insert( $t, $data, $f = null ) { $this->logRows[] = $data; $this->insert_id = count( $this->logRows ); return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
foreach ( array(
	'commercial-outcomes/class-estandar-commercial-money.php',
	'commercial-outcomes/class-estandar-commercial-stage.php',
	'commercial-outcomes/class-estandar-commercial-outcome.php',
	'commercial-outcomes/class-estandar-commercial-value-provider.php',
	'commercial-outcomes/class-estandar-commercial-outcomes-value-provider.php',
	'commercial-outcomes/class-estandar-commercial-stages.php',
	'commercial-outcomes/class-estandar-commercial-outcomes-log.php',
	'commercial-outcomes/class-estandar-commercial-outcomes.php',
	'class-estandar-leads-attribution.php',
	'class-estandar-leads-meta-payload-builder.php',
	'class-estandar-leads-meta-graph-client.php',
	'class-estandar-leads-meta-adapter.php',
) as $f ) { require_once $base . $f; }

$pass = 0; $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

// ── NO-REGRESSION INVARIANT ───────────────────────────────────────────────────
$const = ( new ReflectionClass( 'Estandar_Leads_Meta_Adapter' ) )->getConstant( 'EXPORT_STATUSES' );
check( 'Stages::defaults() === Meta_Adapter::EXPORT_STATUSES', Estandar_Commercial_Stages::defaults() === $const );

// ── Domain value objects ──────────────────────────────────────────────────────
$m = new Estandar_Commercial_Money( 15000.0, 'clp' );
check( 'Money normalizes currency + amount', $m->amount() === 15000.0 && $m->currency() === 'CLP' && $m->has_currency() );
check( 'Money empty currency', ( new Estandar_Commercial_Money( 1, '' ) )->has_currency() === false );

$s = new Estandar_Commercial_Stage( ' reserved ' );
check( 'Stage normalizes code', $s->code() === 'RESERVED' && $s->is_valid() );
check( 'Stage equals', $s->equals( new Estandar_Commercial_Stage( 'RESERVED' ) ) );

$o = new Estandar_Commercial_Outcome( array( 'lead_id' => 87, 'stage' => 'RESERVED', 'adapter' => 'meta', 'event' => 'InitiateCheckout', 'http_code' => 200, 'status' => 'success' ) );
check( 'Outcome holds fields + stage_code()', $o->lead_id === 87 && $o->stage_code() === 'RESERVED' && $o->company_id === null );

// ── Stages registry ───────────────────────────────────────────────────────────
$GLOBALS['__opts'] = array();
$stages = new Estandar_Commercial_Stages();
check( 'unconfigured stages = defaults', $stages->all() === $const );
check( 'is_exportable RESERVED', $stages->is_exportable( 'RESERVED' ) === true );
check( 'is_exportable NEW = false', $stages->is_exportable( 'NEW' ) === false );
check( 'is_exportable accepts Stage object', $stages->is_exportable( new Estandar_Commercial_Stage( 'visited' ) ) === true );
check( 'stages() returns Stage objects', $stages->stages()[0] instanceof Estandar_Commercial_Stage );
$GLOBALS['__opts']['estandar_commercial_stages_v1'] = 'RESERVED, SALE';
check( 'configured override', ( new Estandar_Commercial_Stages() )->all() === array( 'RESERVED', 'SALE' ) );

// ── Value provider → Money ────────────────────────────────────────────────────
$GLOBALS['__opts'] = array();
$vp   = new Estandar_Commercial_Outcomes_Value_Provider();
$lead = (object) array( 'project_slug' => 'parronales' );
check( 'value implements contract', $vp instanceof Estandar_Commercial_Value_Provider );
check( 'unconfigured value → null', $vp->value_for( $lead, 'RESERVED' ) === null );
$GLOBALS['__opts']['estandar_commercial_values_v1'] = "RESERVED=15000\nparronales|RESERVED=20000";
$GLOBALS['__opts']['estandar_commercial_currency']  = 'clp';
$mv = $vp->value_for( $lead, new Estandar_Commercial_Stage( 'RESERVED' ) );
check( 'project-scoped Money precedence', $mv instanceof Estandar_Commercial_Money && $mv->amount() === 20000.0 && $mv->currency() === 'CLP' );
check( 'bare-status Money', $vp->value_for( (object) array( 'project_slug' => 'altacruz' ), 'RESERVED' )->amount() === 15000.0 );
check( 'unmapped → null', $vp->value_for( $lead, 'QUALIFIED' ) === null );

// ── Table-backed log ──────────────────────────────────────────────────────────
$GLOBALS['wpdb']->logRows = array();
$id = ( new Estandar_Commercial_Outcomes_Log() )->record( $o );
check( 'log writes to es_commercial_outcomes_log (not options)', count( $GLOBALS['wpdb']->logRows ) === 1 && $id === 1 );
check( 'log row has adapter/stage/status', $GLOBALS['wpdb']->logRows[0]['adapter'] === 'meta' && $GLOBALS['wpdb']->logRows[0]['stage'] === 'RESERVED' && $GLOBALS['wpdb']->logRows[0]['status'] === 'success' );
check( 'no log option written', ! isset( $GLOBALS['__opts']['estandar_commercial_conversions_log'] ) );
check( 'log recent() reads table', count( ( new Estandar_Commercial_Outcomes_Log() )->recent() ) === 1 );

// ── Locator: single resolution point ──────────────────────────────────────────
check( 'locator is singleton', Estandar_Commercial_Outcomes::locator() === Estandar_Commercial_Outcomes::locator() );
check( 'locator->stages()', Estandar_Commercial_Outcomes::locator()->stages() instanceof Estandar_Commercial_Stages );
check( 'locator->value_provider()', Estandar_Commercial_Outcomes::locator()->value_provider() instanceof Estandar_Commercial_Value_Provider );
check( 'locator->log()', Estandar_Commercial_Outcomes::locator()->log() instanceof Estandar_Commercial_Outcomes_Log );

// ── INTEGRATION through the real adapter (module loaded) ───────────────────────
function lead_row() { return (object) array( 'id' => 87, 'lead_uuid' => 'uuid-87', 'email' => 'x@y.com', 'project_slug' => 'parronales', 'updated_at' => '2026-06-25 09:00:00', 'first_fbclid' => 'fb-paid-87' ); }
function meta_item() { return (object) array( 'id' => 1, 'lead_id' => 87, 'export_status' => 'RESERVED', 'event_time' => '2026-06-20 12:00:00', 'attempts' => 0, 'queue_status' => 'pending' ); }
function last_meta_body() { $c = end( $GLOBALS['__http'] ); return json_decode( $c['args']['body'], true ); }

// configured Meta, NO commercial values → payload must omit value (no regression).
$GLOBALS['__opts'] = array( 'estandar_leads_meta_pixel_id' => 'PIX', 'estandar_leads_meta_access_token' => 'TOK' );
$GLOBALS['wpdb']->leadRow = lead_row();
$GLOBALS['wpdb']->pendingItems = array( meta_item() );
$GLOBALS['wpdb']->logRows = array();
$GLOBALS['__http'] = array();
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
Estandar_Leads_Meta_Adapter::process_queue();
$cd = last_meta_body()['data'][0]['custom_data'] ?? array();
check( 'no-regression: no conversion_value when unconfigured', ! isset( $cd['conversion_value'] ) );
check( 'integration: outcome logged to table (adapter=meta)', count( $GLOBALS['wpdb']->logRows ) === 1 && $GLOBALS['wpdb']->logRows[0]['event'] === 'InitiateCheckout' && $GLOBALS['wpdb']->logRows[0]['status'] === 'success' );

// configured values → Money flows into the payload via the adapter.
$GLOBALS['__opts']['estandar_commercial_values_v1'] = "parronales|RESERVED=20000";
$GLOBALS['__opts']['estandar_commercial_currency']  = 'clp';
$GLOBALS['wpdb']->leadRow = lead_row();
$GLOBALS['wpdb']->pendingItems = array( meta_item() );
$GLOBALS['__http'] = array();
Estandar_Leads_Meta_Adapter::process_queue();
$cd = last_meta_body()['data'][0]['custom_data'] ?? array();
check( 'Money flows: conversion_value present when configured', isset( $cd['conversion_value'] ) && (float) $cd['conversion_value'] === 20000.0 );
check( 'Money flows: currency present', ( $cd['currency'] ?? '' ) === 'CLP' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
