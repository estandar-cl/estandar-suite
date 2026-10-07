<?php
/**
 * Regression: CHATBOT_ATTRIBUTION smoke-test failure (Lead #466).
 *
 * Contract under test — attribution precedence at the ingestion choke point:
 *   explicit payload > landing_url params > cookies > analytics session > ''
 *
 * Production failure reproduced: chatbot emitted the minimal attribution
 * shape (landing_url + session_id only, because core-mu lacked the canonical
 * builder) and merge_analytics() filled utm_source/utm_medium from a
 * pre-existing direct/none session, swallowing the campaign parameters that
 * were present in the landing URL. merge_analytics() now routes the payload
 * through estandar_core_build_attribution_context() before the session
 * fallback, so URL-derived values always outrank the session.
 *
 * Run: php tests/run-attribution-merge-precedence.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------------------------------------------------------------------------
// Minimal WP stubs (same approach as run-capture-attribution.php in core-mu).
// ---------------------------------------------------------------------------
function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	return trim( $str );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function esc_url_raw( $url ) {
	return trim( (string) $url );
}
function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}
function do_action( ...$args ) {}

// Analytics session fallback: the pre-existing "direct" visit observed in the
// production smoke test (session created before the parameterized landing).
$GLOBALS['mock_session'] = array();
function estandar_analytics_get_attribution_by_session( $session_id ) {
	return $GLOBALS['mock_session'];
}

// Canonical builder (real Primer code, not a copy) + class under test.
require dirname( __DIR__, 2 ) . '/estandar-primer/includes/capture-attribution.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';

$fail  = 0;
$check = static function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$fail += $ok ? 0 : 1;
};

$smoke_url = 'https://acacios.cl/acacios/inmobiliaria/parronales/?fbclid=TEST_FBCLID_001&utm_source=facebook&utm_medium=paid_social&utm_campaign=TEST_CHATBOT&utm_content=SMOKE_TEST';

$direct_session = array(
	'utm_source'            => 'direct',
	'utm_medium'            => 'none',
	'utm_campaign'          => '',
	'utm_term'              => 'session-term',
	'utm_content'           => '',
	'gclid'                 => '',
	'fbclid'                => '',
	'landing_url'           => 'https://acacios.cl/',
	'referrer'              => '',
	'first_touch_timestamp' => '2026-07-09 10:00:00',
	'last_touch_timestamp'  => '2026-07-09 10:05:00',
	'session_id'            => 'ea-preexisting',
	'capture_method'        => 'analytics_session',
);

// ---------------------------------------------------------------------------
// 1. Canonical chatbot path: builder output → merge_analytics with a
//    pre-existing direct session. Landing-derived campaign values must win.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = $direct_session;
$ctx  = estandar_core_build_attribution_context( array(
	'landing_url' => $smoke_url,
	'session_id'  => 'ea-preexisting',
) );
$attr = Estandar_Leads_Attribution::merge_analytics( $ctx );

$check( '1a first_utm_source = facebook (not direct)', $attr['utm_source'] === 'facebook' );
$check( '1b first_utm_medium = paid_social (not none)', $attr['utm_medium'] === 'paid_social' );
$check( '1c first_utm_campaign = TEST_CHATBOT', $attr['utm_campaign'] === 'TEST_CHATBOT' );
$check( '1d first_utm_content = SMOKE_TEST', $attr['utm_content'] === 'SMOKE_TEST' );
$check( '1e first_fbclid = TEST_FBCLID_001', $attr['fbclid'] === 'TEST_FBCLID_001' );
$check( '1f landing_url preserved with query string', $attr['landing_url'] === $smoke_url );
$check( '1g session fills only genuinely empty fields (utm_term)', $attr['utm_term'] === 'session-term' );
$check( '1h session fills only genuinely empty fields (first_touch)', $attr['first_touch_timestamp'] === '2026-07-09 10:00:00' );

// ---------------------------------------------------------------------------
// 2. Production defect regression: MINIMAL shape (landing_url + session_id
//    only — what the chatbot emits when core-mu lacks the builder) must now
//    be derived at the ingestion choke point instead of falling through to
//    the direct session.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = $direct_session;
$attr = Estandar_Leads_Attribution::merge_analytics( array(
	'landing_url' => $smoke_url,
	'session_id'  => 'ea-preexisting',
) );
$check( '2a minimal shape: utm_source derived from URL, not session', $attr['utm_source'] === 'facebook' );
$check( '2b minimal shape: utm_medium = paid_social', $attr['utm_medium'] === 'paid_social' );
$check( '2c minimal shape: fbclid = TEST_FBCLID_001', $attr['fbclid'] === 'TEST_FBCLID_001' );
$check( '2d minimal shape: capture method = landing_url', ( $attr['attribution_capture_method'] ?? '' ) === 'landing_url' );

// ---------------------------------------------------------------------------
// 3. Explicit payload values outrank URL values.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = $direct_session;
$attr = Estandar_Leads_Attribution::merge_analytics( array(
	'utm_source'  => 'explicit-source',
	'gclid'       => 'explicit-gclid',
	'landing_url' => $smoke_url,
	'session_id'  => 'ea-preexisting',
) );
$check( '3a explicit utm_source wins over URL', $attr['utm_source'] === 'explicit-source' );
$check( '3b explicit gclid wins', $attr['gclid'] === 'explicit-gclid' );
$check( '3c URL still fills fields the payload left empty', $attr['utm_medium'] === 'paid_social' );

// ---------------------------------------------------------------------------
// 4. URL values outrank session analytics even when session is non-empty.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = array_merge( $direct_session, array( 'utm_campaign' => 'session-campaign' ) );
$attr = Estandar_Leads_Attribution::merge_analytics( array(
	'landing_url' => $smoke_url,
	'session_id'  => 'ea-preexisting',
) );
$check( '4a URL utm_campaign outranks session utm_campaign', $attr['utm_campaign'] === 'TEST_CHATBOT' );

// ---------------------------------------------------------------------------
// 5. Forms behavior unchanged: a fully explicit attribution payload (what
//    estandar-forms emits) passes through untouched.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = $direct_session;
$forms_attr = array(
	'utm_source'            => 'google',
	'utm_medium'            => 'cpc',
	'utm_campaign'          => 'forms-campaign',
	'utm_term'              => 'kw',
	'utm_content'           => 'ad1',
	'gclid'                 => 'g-123',
	'gbraid'                => '',
	'wbraid'                => '',
	'fbclid'                => '',
	'meta_fbc'              => '',
	'meta_fbp'              => '',
	'ttclid'                => '',
	'msclkid'               => '',
	'landing_url'           => 'https://cliente.cl/contacto/',
	'referrer'              => 'https://google.com/',
	'first_touch_timestamp' => '2026-07-01 09:00:00',
	'last_touch_timestamp'  => '2026-07-01 09:00:00',
	'session_id'            => 'forms-session',
);
$attr = Estandar_Leads_Attribution::merge_analytics( $forms_attr );
$forms_ok = true;
foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'landing_url', 'referrer', 'session_id' ) as $f ) {
	if ( $attr[ $f ] !== $forms_attr[ $f ] ) {
		$forms_ok = false;
	}
}
$check( '5a Forms explicit attribution unchanged', $forms_ok );

// ---------------------------------------------------------------------------
// 6. Session fallback still works when there is genuinely nothing in the URL.
// ---------------------------------------------------------------------------
$GLOBALS['mock_session'] = $direct_session;
$attr = Estandar_Leads_Attribution::merge_analytics( array(
	'landing_url' => 'https://acacios.cl/contacto/',
	'session_id'  => 'ea-preexisting',
) );
$check( '6a clean URL: session fills utm_source', $attr['utm_source'] === 'direct' );
$check( '6b clean URL: landing_url NOT replaced by session landing', $attr['landing_url'] === 'https://acacios.cl/contacto/' );

echo "\n" . ( $fail === 0 ? 'ALL PASS' : $fail . ' FAILED' ) . "\n";
exit( $fail === 0 ? 0 : 1 );
