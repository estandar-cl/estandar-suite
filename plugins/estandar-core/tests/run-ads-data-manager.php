<?php
/**
 * ESTANDAR SUITE v2 — Core AdsDataManager tests (pure logic + guarded paths).
 *
 *   php tests/run-ads-data-manager.php   → expect "0 failed"
 *
 * Cobertura: build de evento DM (click IDs, valor por calidad, dedup, RFC3339),
 * destination (MCC opcional), conversion_value (negativos→0), redacción de
 * secretos, y click_ids desde payload/captura. Sin red (Http guardado).
 */

define( 'ABSPATH', __DIR__ );
$GLOBALS['__test_options'] = array();
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return ''; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t ) { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $o = 0 ) { return json_encode( $v ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return false; } }

require dirname( __DIR__ ) . '/includes/Crypto.php';
require dirname( __DIR__ ) . '/includes/Http.php';

// Http con network guardado: sin env de test → devuelve error sin tocar red.
$GLOBALS['__http_mode'] = 'error';
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		if ( $GLOBALS['__http_mode'] === 'error' ) { return new WP_Error( 'no_network', 'test' ); }
		return array( 'response' => array( 'code' => 200 ), 'body' => $GLOBALS['__http_body'] ?? '{}', 'headers' => array() );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $r ) { return $r instanceof WP_Error; } }
class WP_Error { public $c; public $m; public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } public function get_error_message() { return $this->m; } }

require dirname( __DIR__ ) . '/includes/AdsDataManager.php';

use Estandar\Core\AdsDataManager;

$pass = 0; $fail = 0;
function check( $l, $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }

// ── Event DM: click IDs, dedup, timestamp ───────────────────────────────────
$payload = array(
	'event'        => 'lead_qualified',
	'lead_id'      => 42,
	'capture_uuid' => '00000000-0000-0000-0000-0000000000aa',
	'gclid'        => 'GCL123',
	'gbraid'       => '',
	'quality'      => 'HIGH',
	'occurred_at'  => '2026-07-13 10:00:00',
);
$ev = AdsDataManager::build_event( $payload );
check( 'event no vacío', is_array( $ev ) && ! empty( $ev ) );
check( 'gclid mapeado a adIdentifiers', ( $ev['adIdentifiers']['gclid'] ?? '' ) === 'GCL123' );
check( 'transactionId dedup uuid:EVENT', ( $ev['transactionId'] ?? '' ) === '00000000-0000-0000-0000-0000000000aa:LEAD_QUALIFIED' );
check( 'eventTimestamp RFC3339 UTC', ( $ev['eventTimestamp'] ?? '' ) === '2026-07-13T10:00:00Z' );
check( 'consent adUserData', ( $ev['consent']['adUserData'] ?? '' ) === 'CONSENT_GRANTED' );

// ── Valor por calidad (discriminación de ticket) ────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ads_dm'] = array(
	'enabled' => true, 'dry_run' => true, 'customer_id' => '1071498042',
	'conversion_action_id' => '7635084208', 'currency' => 'CLP',
	'value_by_quality' => array( 'EXCEPTIONAL' => 300000, 'HIGH' => 300000, 'MEDIUM' => 100000, 'LOW' => 30000, 'UNCLASSIFIED' => 0 ),
);
check( 'HIGH → 300000', AdsDataManager::conversion_value( array( 'quality' => 'HIGH' ) ) === 300000.0 );
check( 'LOW → 30000', AdsDataManager::conversion_value( array( 'quality' => 'LOW' ) ) === 30000.0 );
check( 'EXCEPTIONAL → 300000', AdsDataManager::conversion_value( array( 'quality' => 'EXCEPTIONAL' ) ) === 300000.0 );
check( 'UNCLASSIFIED → 0', AdsDataManager::conversion_value( array( 'quality' => 'UNCLASSIFIED' ) ) === 0.0 );
check( 'sin calidad → usa revenue', AdsDataManager::conversion_value( array( 'revenue' => 800000 ) ) === 800000.0 );
check( 'negativo fuerza 0', AdsDataManager::conversion_value( array( 'quality' => 'HIGH', 'negative' => true ) ) === 0.0 );

// ── Event con valor (conversionValue + currency) ────────────────────────────
$evHigh = AdsDataManager::build_event( array( 'event' => 'deal_won', 'lead_id' => 7, 'gclid' => 'G', 'quality' => 'EXCEPTIONAL', 'occurred_at' => '2026-08-01T12:00:00Z' ) );
check( 'conversionValue presente', ( $evHigh['conversionValue'] ?? 0 ) == 300000 );
check( 'currency CLP', ( $evHigh['currency'] ?? '' ) === 'CLP' );

// ── Event sin click ID → [] (no atribuible) ─────────────────────────────────
check( 'sin click ID → evento vacío', AdsDataManager::build_event( array( 'event' => 'lead_captured', 'lead_id' => 1 ) ) === array() );

// ── Destination: MCC opcional ───────────────────────────────────────────────
$dest = AdsDataManager::build_destination();
check( 'destination cuenta 1071498042', ( $dest['operatingAccount']['accountId'] ?? '' ) === '1071498042' );
check( 'destination productDestinationId 7635084208', ( $dest['productDestinationId'] ?? '' ) === '7635084208' );

// ── click_ids desde captura v2 ──────────────────────────────────────────────
$click = AdsDataManager::click_ids( array( 'click_ids' => array( 'gclid' => 'A', 'gbraid' => '0AB', 'wbraid' => '' ) ) );
check( 'click_ids gclid+gbraid', ( $click['gclid'] ?? '' ) === 'A' && ( $click['gbraid'] ?? '' ) === '0AB' && empty( $click['wbraid'] ) );

// ── Masked: secretos nunca en claro ─────────────────────────────────────────
// Sin token configurado → vacío. Con token cifrado → '••••', jamás en claro.
$m = AdsDataManager::masked();
check( 'masked devuelve array', is_array( $m ) );
check( 'masked sin secretos configurados → vacío', ( $m['oauth_refresh_token'] ?? 'x' ) === '' );
$GLOBALS['__test_options']['estandar_suite_core_ads_dm']['oauth_refresh_token'] = 'CIFRADO-BASE64-...';
$m2 = AdsDataManager::masked();
check( 'masked con secretos → redactado ••••', ( $m2['oauth_refresh_token'] ?? '' ) === '••••' );
check( 'masked no expone el valor cifrado', strpos( ( $m2['oauth_refresh_token'] ?? '' ), 'CIFRADO' ) === false );

// ── WA_BACKFILL_V1: cruce estandar_wa_clicks ↔ capturas (pure helpers) ─────
check( 'wa _has_google_click gclid', AdsDataManager::_has_google_click( array( 'gclid' => 'G1' ) ) === true );
check( 'wa _has_google_click gbraid', AdsDataManager::_has_google_click( array( 'gbraid' => '0AB' ) ) === true );
check( 'wa _has_google_click vacío → false', AdsDataManager::_has_google_click( array() ) === false );
check( 'wa _has_google_click solo fbclid → false', AdsDataManager::_has_google_click( array( 'fbclid' => 'F' ) ) === false );
check( 'wa _merge llena hueco gclid', AdsDataManager::_merge_click_ids( array(), array( 'gclid' => 'G1', 'gbraid' => '' ) )['gclid'] === 'G1' );
check( 'wa _merge no pisa existente', AdsDataManager::_merge_click_ids( array( 'gclid' => 'G0' ), array( 'gclid' => 'G1' ) )['gclid'] === 'G0' );
check( 'wa _latest sin DB → [] (guard)', AdsDataManager::_latest_wa_click_ids( 'ses-1', '1.2.3.4' ) === array() );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
