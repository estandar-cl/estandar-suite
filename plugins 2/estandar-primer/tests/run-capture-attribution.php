<?php
/**
 * Standalone unit test for CAPTURE_ATTRIBUTION_CONTEXT_V1.
 * Run: php tests/run-capture-attribution.php   (no WP runtime required)
 *
 * Contract under test: estandar_core_build_attribution_context() emits the
 * exact key set consumed by Estandar_Leads_Attribution::merge_analytics(),
 * with precedence explicit input > landing_url query > _fbc/_fbp cookie > ''.
 */

define( 'ABSPATH', __DIR__ );

// Minimal WP stubs.
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $v ) { return filter_var( trim( (string) $v ), FILTER_SANITIZE_URL ) ?: ''; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}

require __DIR__ . '/../includes/capture-attribution.php';

$pass = 0;
$fail = 0;

function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS  $label\n";
	} else {
		$fail++;
		echo "FAIL  $label\n";
	}
}

// The exact contract merge_analytics() iterates (class-estandar-leads-attribution.php).
$contract = array(
	'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
	'gclid', 'gbraid', 'wbraid', 'fbclid', 'meta_fbc', 'meta_fbp', 'ttclid', 'msclkid',
	'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type',
	'landing_url', 'referrer', 'first_touch_timestamp', 'last_touch_timestamp', 'session_id',
);

// 1. Empty input → canonical template: every contract key present, all '',
//    no *_capture_method keys (whatsapp-handler uses this as a field-list template).
$_COOKIE = array();
$empty = estandar_core_build_attribution_context();
check( '1a empty input contains all contract keys', array_values( array_diff( $contract, array_keys( $empty ) ) ) === array() );
check( '1b empty input all values empty', count( array_filter( $empty, fn( $v ) => $v !== '' ) ) === 0 );
check( '1c empty input has no capture_method keys', ! isset( $empty['gclid_capture_method'] ) && ! isset( $empty['fbclid_capture_method'] ) && ! isset( $empty['attribution_capture_method'] ) );

// 2. Landing URL derivation (the chatbot leads-endpoint call shape).
$ctx = estandar_core_build_attribution_context( array(
	'landing_url' => 'https://acacios.cl/parronales/?utm_source=facebook&utm_medium=paid&utm_campaign=120248585650840457&fbclid=IwAR123abc&gclid=Cj0KCQ',
	'session_id'  => 'ea_9f8b7c6d5e4a',
) );
check( '2a utm_source derived from landing_url', $ctx['utm_source'] === 'facebook' );
check( '2b fbclid derived from landing_url', $ctx['fbclid'] === 'IwAR123abc' );
check( '2c gclid derived from landing_url', $ctx['gclid'] === 'Cj0KCQ' );
check( '2d session_id passthrough', $ctx['session_id'] === 'ea_9f8b7c6d5e4a' );
check( '2e fbclid_capture_method=landing_url', ( $ctx['fbclid_capture_method'] ?? '' ) === 'landing_url' );
check( '2f gclid_capture_method=landing_url', ( $ctx['gclid_capture_method'] ?? '' ) === 'landing_url' );
check( '2g attribution_capture_method=landing_url', ( $ctx['attribution_capture_method'] ?? '' ) === 'landing_url' );

// 3. Explicit input wins over landing_url.
$ctx = estandar_core_build_attribution_context( array(
	'gclid'       => 'EXPLICIT_GCLID',
	'landing_url' => 'https://x.cl/?gclid=URL_GCLID&utm_term=roto',
) );
check( '3a explicit gclid wins', $ctx['gclid'] === 'EXPLICIT_GCLID' );
check( '3b gclid_capture_method=payload', ( $ctx['gclid_capture_method'] ?? '' ) === 'payload' );
check( '3c attribution_capture_method=payload', ( $ctx['attribution_capture_method'] ?? '' ) === 'payload' );
check( '3d other fields still derived', $ctx['utm_term'] === 'roto' );

// 4. Aliases _fbc/_fbp/ea_sid.
$ctx = estandar_core_build_attribution_context( array(
	'_fbc'   => 'fb.1.1720000000.IwAR123',
	'_fbp'   => 'fb.1.1720000000.987654',
	'ea_sid' => 'sid_abc123',
) );
check( '4a _fbc alias → meta_fbc', $ctx['meta_fbc'] === 'fb.1.1720000000.IwAR123' );
check( '4b _fbp alias → meta_fbp', $ctx['meta_fbp'] === 'fb.1.1720000000.987654' );
check( '4c ea_sid alias → session_id', $ctx['session_id'] === 'sid_abc123' );

// 5. Cookie fallback for Meta identifiers (valid format only).
$_COOKIE = array( '_fbc' => 'fb.1.1720000001.IwARcookie', '_fbp' => 'not-a-meta-cookie' );
$ctx = estandar_core_build_attribution_context( array( 'landing_url' => 'https://x.cl/' ) );
check( '5a valid _fbc cookie fills meta_fbc', $ctx['meta_fbc'] === 'fb.1.1720000001.IwARcookie' );
check( '5b malformed _fbp cookie ignored', $ctx['meta_fbp'] === '' );
$_COOKIE = array( '_fbc' => 'fb.1.1720000001.IwARcookie' );
$ctx = estandar_core_build_attribution_context( array( 'meta_fbc' => 'fb.1.1.explicit' ) );
check( '5c explicit meta_fbc wins over cookie', $ctx['meta_fbc'] === 'fb.1.1.explicit' );
$_COOKIE = array();

// 6. Hostile/degenerate input.
$ctx = estandar_core_build_attribution_context( array(
	'gclid'       => array( 'inject' => 'x' ),
	'landing_url' => 'https://x.cl/?utm_source=<script>alert(1)</script>ok',
) );
check( '6a array value coerced to empty', is_string( $ctx['gclid'] ) && $ctx['gclid'] === '' );
check( '6b derived value sanitized (no tags)', strpos( $ctx['utm_source'], '<' ) === false );

// 7. Provided capture_method is respected, never overwritten.
$ctx = estandar_core_build_attribution_context( array(
	'gclid'                => 'X1',
	'gclid_capture_method' => 'cookie_first_touch',
) );
check( '7a provided gclid_capture_method respected', ( $ctx['gclid_capture_method'] ?? '' ) === 'cookie_first_touch' );

// 8. No URL query → nothing derived, no methods emitted.
$ctx = estandar_core_build_attribution_context( array( 'landing_url' => 'https://x.cl/contacto/' ) );
check( '8a no query no derivation', $ctx['utm_source'] === '' && $ctx['gclid'] === '' );
check( '8b no methods emitted', ! isset( $ctx['attribution_capture_method'] ) );

// 9. Full ValueTrack payload and aliases.
$ctx = estandar_core_build_attribution_context( array(
	'landing_url' => 'https://x.cl/?gad_campaignid=111&adgroup_id=222&asset_group_id=333&creative_id=444&keyword=casa&match_type=e&network=g&device=m&placement=example.test&campaign_type=search',
) );
check( '9a full ValueTrack IDs captured', $ctx['campaign_id'] === '111' && $ctx['adgroup_id'] === '222' && $ctx['asset_group_id'] === '333' && $ctx['creative_id'] === '444' );
check( '9b full ValueTrack context captured', $ctx['keyword'] === 'casa' && $ctx['match_type'] === 'e' && $ctx['network'] === 'g' && $ctx['device'] === 'm' && $ctx['placement'] === 'example.test' && $ctx['campaign_type'] === 'search' );
check( '9c ValueTrack provenance is explicit URL', ( $ctx['attribution_provenance']['campaign_id'] ?? '' ) === 'explicit_url' );

// 10. Search and Performance Max shapes stay sparse; gclid alone yields no IDs.
$search = estandar_core_build_attribution_context( array( 'landing_url' => 'https://x.cl/?campaignid=10&adgroupid=20&keyword=deptos&matchtype=p&network=s' ) );
check( '10a Search aliases normalized', $search['campaign_id'] === '10' && $search['adgroup_id'] === '20' && $search['keyword'] === 'deptos' && $search['match_type'] === 'p' && $search['network'] === 's' );
$pmax = estandar_core_build_attribution_context( array( 'landing_url' => 'https://x.cl/?campaign_id=30&assetgroupid=40&network=x' ) );
check( '10b PMax sparse payload captured', $pmax['campaign_id'] === '30' && $pmax['asset_group_id'] === '40' && $pmax['network'] === 'x' && $pmax['adgroup_id'] === '' );
$gclid_only = estandar_core_build_attribution_context( array( 'landing_url' => 'https://x.cl/?gclid=CLICK_ONLY' ) );
check( '10c gclid alone does not infer IDs', $gclid_only['gclid'] === 'CLICK_ONLY' && $gclid_only['campaign_id'] === '' && $gclid_only['adgroup_id'] === '' && $gclid_only['asset_group_id'] === '' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
