<?php
/** Focused attribution/platform gate regressions. No network. */
define( 'ABSPATH', __DIR__ );

$GLOBALS['__opts'] = array();
$GLOBALS['__queries'] = array();
$GLOBALS['__blocked'] = false;
function get_option( $key, $default = false ) { return $GLOBALS['__opts'][ $key ] ?? $default; }
function add_action() {}
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function gmdate_stub() {}

class Estandar_Leads_QA_Policy {
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool {
		return (bool) $GLOBALS['__blocked'];
	}
	public static function is_test_lead( int $lead_id ): bool { return (bool) $GLOBALS['__blocked']; }
}
class PlatformWpdb {
	public $prefix = 'wp_';
	public $lead;
	public function prepare( $query, ...$args ) { return array( 'q' => $query, 'a' => $args ); }
	public function get_row( $prepared ) { return $this->lead; }
	public function query( $prepared ) { $GLOBALS['__queries'][] = $prepared; return 1; }
}
$GLOBALS['wpdb'] = new PlatformWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-google-adapter.php';

$pass = 0; $fail = 0;
function check( $label, $condition ) {
	global $pass, $fail;
	$condition ? $pass++ : $fail++;
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
}
function lead( array $fields ): object {
	return (object) array_merge( array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'first_utm_source' => '', 'first_utm_medium' => '',
		'first_gclid' => '', 'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => '',
		'first_meta_fbc' => '', 'first_meta_fbp' => '', 'source_plugin' => '',
	), $fields );
}
function configure_platforms(): void {
	$GLOBALS['__opts'] = array(
		'estandar_leads_meta_pixel_id' => 'PIX', 'estandar_leads_meta_access_token' => 'TOK',
		'estandar_leads_google_enabled' => '1', 'estandar_leads_google_customer_id' => '1',
		'estandar_leads_google_conversion_action_id' => '2', 'estandar_leads_google_oauth_client_id' => '3',
		'estandar_leads_google_oauth_client_secret' => '4', 'estandar_leads_google_oauth_refresh_token' => '5',
	);
}
function enqueue_counts( object $lead ): array {
	configure_platforms();
	$GLOBALS['__blocked'] = false;
	$GLOBALS['__queries'] = array();
	$GLOBALS['wpdb']->lead = $lead;
	Estandar_Leads_Meta_Adapter::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-15 10:00:00' );
	Estandar_Leads_Google_Adapter::on_lead_qualified( 7, 'NEW', 'QUALIFIED', '2026-07-15 10:00:00' );
	$meta = $google = 0;
	foreach ( $GLOBALS['__queries'] as $query ) {
		strpos( $query['q'], 'es_meta_queue' ) !== false ? $meta++ : null;
		strpos( $query['q'], 'es_google_queue' ) !== false ? $google++ : null;
	}
	return array( $meta, $google );
}

check( 'Google organic + _fbp keeps canonical Google Organic', Estandar_Leads_Attribution::channel_label( lead( array( 'first_utm_source' => 'google', 'first_utm_medium' => 'organic', 'first_meta_fbp' => 'fb.1.browser' ) ) ) === 'Google Organic' );
check( 'Google organic + _fbp enqueues neither', enqueue_counts( lead( array( 'first_utm_source' => 'google', 'first_utm_medium' => 'organic', 'first_meta_fbp' => 'fb.1.browser' ) ) ) === array( 0, 0 ) );
check( 'Google Ads + GCLID enqueues Google only', enqueue_counts( lead( array( 'first_gclid' => 'g-1' ) ) ) === array( 0, 1 ) );
check( 'Google Ads + GBRAID enqueues Google only', enqueue_counts( lead( array( 'first_gbraid' => 'gb-1' ) ) ) === array( 0, 1 ) );
check( 'Google Ads + WBRAID enqueues Google only', enqueue_counts( lead( array( 'first_wbraid' => 'wb-1' ) ) ) === array( 0, 1 ) );
$meta_fbclid_only = lead( array( 'first_fbclid' => 'fb-1' ) );
$meta_fbclid_gate = Estandar_Leads_Attribution::platform_eligibility( 'meta', $meta_fbclid_only, true );
$google_for_meta   = Estandar_Leads_Attribution::platform_eligibility( 'google', $meta_fbclid_only, true );
check( 'Meta fbclid-only canonical channel = Meta Ads', Estandar_Leads_Attribution::channel_label( $meta_fbclid_only ) === 'Meta Ads' );
check( 'Meta fbclid-only is eligible with fbclid evidence', $meta_fbclid_gate['eligible'] === true && $meta_fbclid_gate['evidence'] === 'fbclid' );
check( 'Meta fbclid-only enqueues Meta only', enqueue_counts( $meta_fbclid_only ) === array( 1, 0 ) );
check( 'Meta fbclid-only is not Google eligible', $google_for_meta['eligible'] === false );
check( 'Meta Ads + fbc enqueues Meta only', enqueue_counts( lead( array( 'first_meta_fbc' => 'fb.1.1.click' ) ) ) === array( 1, 0 ) );
$google_tagged_only = lead( array( 'first_utm_source' => 'google', 'first_utm_medium' => 'cpc' ) );
$google_tagged_gate = Estandar_Leads_Attribution::platform_eligibility( 'google', $google_tagged_only, true );
$meta_for_google    = Estandar_Leads_Attribution::platform_eligibility( 'meta', $google_tagged_only, true );
check( 'Google cpc without click ID canonical channel = Google Ads', Estandar_Leads_Attribution::resolve_channel( $google_tagged_only ) === 'google_ads' );
check( 'Google cpc without click ID is not Google eligible', $google_tagged_gate['eligible'] === false && strpos( $google_tagged_gate['reason'], 'missing Google Ads click identifier' ) !== false );
check( 'Google cpc without click ID enqueues neither', enqueue_counts( $google_tagged_only ) === array( 0, 0 ) );
check( 'Google cpc without click ID is not Meta eligible', $meta_for_google['eligible'] === false );
check( '_fbp alone enqueues neither', enqueue_counts( lead( array( 'first_meta_fbp' => 'fb.1.browser' ) ) ) === array( 0, 0 ) );
check( 'Direct enqueues neither', enqueue_counts( lead( array( 'first_utm_source' => 'direct' ) ) ) === array( 0, 0 ) );
check( 'Referral enqueues neither', enqueue_counts( lead( array( 'first_utm_source' => 'partner', 'first_utm_medium' => 'referral' ) ) ) === array( 0, 0 ) );
check( 'Formulario Web enqueues neither', enqueue_counts( lead( array( 'source_plugin' => 'estandar-forms' ) ) ) === array( 0, 0 ) );

configure_platforms();
$GLOBALS['wpdb']->lead = lead( array( 'first_fbclid' => 'fb-1' ) );
$GLOBALS['__blocked'] = true;
$GLOBALS['__queries'] = array();
Estandar_Leads_Meta_Adapter::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-15 10:00:00' );
Estandar_Leads_Google_Adapter::on_lead_qualified( 7, 'NEW', 'QUALIFIED', '2026-07-15 10:00:00' );
check( 'QA or ads_feedback_policy skip enqueues neither', count( $GLOBALS['__queries'] ) === 0 );

$blocked = Estandar_Leads_Meta_Adapter::dispatch_backfill_purchase( lead( array( 'first_utm_source' => 'google', 'first_utm_medium' => 'organic', 'first_meta_fbp' => 'fb.1.browser' ) ), 'backfill-1' );
check( 'Meta direct/backfill path blocks ineligible acquisition before send', ! empty( $blocked['response']['skipped'] ) && (int) $blocked['response']['http_code'] === 0 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
