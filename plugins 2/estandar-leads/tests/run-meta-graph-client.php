<?php
/**
 * Standalone contract test for Estandar_Leads_Meta_Graph_Client.
 * Stubs the WordPress HTTP API — no real network. Run:
 *   php tests/run-meta-graph-client.php
 */

define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']      = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']      = null; // WP_Error|array returned by wp_remote_post

class WP_Error {
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_remote_post( $url, $args ) {
	$GLOBALS['__http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__next'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require_once dirname( __DIR__ ) . '/includes/class-estandar-leads-meta-graph-client.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function reset_http() { $GLOBALS['__http_calls'] = array(); $GLOBALS['__next'] = null; }

$payload = array( 'event_name' => 'Lead', 'event_time' => 1700000000, 'user_data' => array( 'em' => 'abc' ) );

// 1. Not configured → no HTTP, configured=false.
reset_http();
$GLOBALS['__opts'] = array();
$r = Estandar_Leads_Meta_Graph_Client::send( $payload );
check( 'unconfigured → configured=false', $r['configured'] === false );
check( 'unconfigured → no HTTP request executed', count( $GLOBALS['__http_calls'] ) === 0 );
check( 'unconfigured → success=false, temporary=false', $r['success'] === false && $r['temporary'] === false );

// Configure for the rest.
$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => '1234567890',
	'estandar_leads_meta_access_token' => 'SECRET',
);

// 2. 200 → success, POST executed, endpoint + version centralized.
reset_http();
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"events_received":1}' );
$r = Estandar_Leads_Meta_Graph_Client::send( $payload );
check( 'configured → POST executed', count( $GLOBALS['__http_calls'] ) === 1 );
check( '200 → success=true, temporary=false', $r['success'] === true && $r['temporary'] === false );
check( 'endpoint uses v23.0 + pixel + /events',
	$GLOBALS['__http_calls'][0]['url'] === 'https://graph.facebook.com/v23.0/1234567890/events' );

// 3. Body wraps event in data[] and includes access_token; payload not mutated.
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
check( 'body wraps event as data[0]', isset( $sent['data'][0]['event_name'] ) && $sent['data'][0]['event_name'] === 'Lead' );
check( 'access_token in body', ( $sent['access_token'] ?? '' ) === 'SECRET' );
check( 'no event_id when none supplied', ! isset( $sent['data'][0]['event_id'] ) );
check( 'original payload not mutated', ! isset( $payload['event_id'] ) && array_keys( $payload ) === array( 'event_name', 'event_time', 'user_data' ) );

// 4. event_id propagated when supplied.
reset_http();
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
Estandar_Leads_Meta_Graph_Client::send( $payload, 'lead-9724:RESERVED' );
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
check( 'event_id attached to event', ( $sent['data'][0]['event_id'] ?? '' ) === 'lead-9724:RESERVED' );

// 5. Error-code → temporary mapping.
$cases = array(
	array( 'code' => 429, 'temp' => true ),
	array( 'code' => 500, 'temp' => true ),
	array( 'code' => 503, 'temp' => true ),
	array( 'code' => 400, 'temp' => false ),
	array( 'code' => 401, 'temp' => false ),
	array( 'code' => 403, 'temp' => false ),
);
foreach ( $cases as $c ) {
	reset_http();
	$GLOBALS['__next'] = array( 'response' => array( 'code' => $c['code'] ), 'body' => '{"error":{"message":"x"}}' );
	$r = Estandar_Leads_Meta_Graph_Client::send( $payload );
	check( "HTTP {$c['code']} → success=false, temporary=" . ( $c['temp'] ? 'true' : 'false' ),
		$r['success'] === false && $r['temporary'] === $c['temp'] && $r['http_code'] === $c['code'] );
}

// 6. Transport failure (WP_Error) → temporary=true, http_code=0, no exception.
reset_http();
$GLOBALS['__next'] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$r = Estandar_Leads_Meta_Graph_Client::send( $payload );
check( 'WP_Error → temporary=true, configured=true, code=0', $r['temporary'] === true && $r['configured'] === true && $r['http_code'] === 0 );
check( 'WP_Error message surfaced', strpos( $r['message'], 'timeout' ) !== false );

// 7. Normalized shape always has exactly the 6 keys.
$keys = array( 'success', 'temporary', 'configured', 'http_code', 'message', 'body' );
check( 'normalized shape = exactly 6 keys', array_keys( $r ) === $keys );
check( 'never returns WP_Error / raw response', is_array( $r ) && ! ( $r instanceof WP_Error ) );

// 8. test_event_code: top-level del request (hermano de data), nunca dentro del evento.
reset_http();
$GLOBALS['__opts']['estandar_leads_meta_test_event_code'] = 'TEST99';
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
Estandar_Leads_Meta_Graph_Client::send( $payload, 'uuid-1:QUALIFIED' );
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
check( 'test_event_code presente top-level', ( $sent['test_event_code'] ?? '' ) === 'TEST99' );
check( 'test_event_code NO dentro del evento (data[0])', ! isset( $sent['data'][0]['test_event_code'] ) );

// 9. test_event_code vacío → clave ausente del request.
reset_http();
$GLOBALS['__opts']['estandar_leads_meta_test_event_code'] = '';
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
Estandar_Leads_Meta_Graph_Client::send( $payload );
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
check( 'sin test_event_code → clave ausente', ! array_key_exists( 'test_event_code', $sent ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
