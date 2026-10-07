<?php
/**
 * OCI_OBSERVABILITY_V1 — persisted-decision states in the observability
 * resolver (Fix 4 + Fix 3 rendering).
 *
 *  - dead row with 'skipped/not_eligible:' prefix → NOT_ELIGIBLE (never FAILED)
 *  - execution-log row status 'skipped'           → NOT_ELIGIBLE
 *  - persisted ads_dispatch_skipped event         → NOT_ELIGIBLE + trace line
 *  - no execution at all                          → PLATFORM_CONFIRMATION_UNKNOWN
 *
 * Run: php tests/run-google-oci-observability.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['__opts'] = array(
	// Google fully configured + enabled so config never masks the state under test.
	'estandar_leads_google_customer_id'          => '111',
	'estandar_leads_google_conversion_action_id' => '222',
	'estandar_leads_google_oauth_client_id'      => 'cid',
	'estandar_leads_google_oauth_client_secret'  => 'sec',
	'estandar_leads_google_oauth_refresh_token'  => 'tok',
	'estandar_leads_google_enabled'              => '1',
	'estandar_leads_google_dry_run'              => '1',
);
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }

class Estandar_Leads_QA_Policy {
	public static function is_test_lead( int $lead_id ): bool { return false; }
}
class Estandar_Commercial_Qualification_Engine {
	public static function decision_for( $lead_id, $submission_id, $payload = null ): array {
		return array( 'ads_feedback_policy' => 'allow', 'qualification' => 'standard', 'decision_source' => 'default' );
	}
}
class Estandar_Leads_Google_Adapter {
	const EXPORT_STATUSES = array( 'QUALIFIED' );
}

class FakeWpdb {
	public $prefix = 'wp_';
	public $tables = array( 'wp_es_google_queue' => true, 'wp_es_meta_queue' => true, 'wp_es_commercial_outcomes_log' => true );
	public $lead;
	public $google_queue = array();
	public $meta_queue   = array();
	public $logs         = array();
	public $skip_events  = array();
	public function prepare( $q, ...$a ) {
		foreach ( $a as $v ) { $q = preg_replace( '/%[ds]/', (string) $v, $q, 1 ); }
		return $q;
	}
	public function get_var( $q ) {
		if ( strpos( $q, 'SHOW TABLES LIKE' ) !== false ) {
			foreach ( array_keys( $this->tables ) as $t ) {
				if ( strpos( $q, $t ) !== false ) { return $t; }
			}
			return null;
		}
		if ( strpos( $q, 'SELECT id FROM wp_es_lead_submissions' ) !== false ) { return 55; }
		return null;
	}
	public function get_row( $q ) {
		if ( strpos( $q, 'FROM wp_es_leads' ) !== false ) { return $this->lead; }
		return null;
	}
	public function get_results( $q ) {
		if ( strpos( $q, 'wp_es_google_queue' ) !== false ) { return $this->google_queue; }
		if ( strpos( $q, 'wp_es_meta_queue' ) !== false )   { return $this->meta_queue; }
		if ( strpos( $q, 'ads_dispatch_skipped' ) !== false ) { return $this->skip_events; }
		if ( strpos( $q, 'wp_es_commercial_outcomes_log' ) !== false ) {
			preg_match( '/adapter = ([a-z_]+)/', $q, $m );
			$adapter = $m[1] ?? '';
			return array_values( array_filter( $this->logs, static function ( $r ) use ( $adapter ) {
				return $r->adapter === $adapter;
			} ) );
		}
		return array();
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../includes/class-estandar-leads-attribution.php';
require __DIR__ . '/../includes/class-estandar-leads-ads-feedback-observability.php';

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

// Google-eligible lead (gclid) so platform eligibility never masks the queue state.
$google_lead = (object) array(
	'id' => 7, 'lead_uuid' => 'uuid-7',
	'first_gclid' => 'g-1', 'first_fbclid' => '', 'first_meta_fbc' => '', 'first_meta_fbp' => '',
	'utm_source' => 'google', 'utm_medium' => 'cpc',
	'email' => 'x@y.cl', 'phone' => '+56911111111',
);

// 1 · dead + skipped/not_eligible prefix → NOT_ELIGIBLE, basis policy, never FAILED.
$GLOBALS['wpdb']->lead = $google_lead;
$GLOBALS['wpdb']->google_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'QUALIFIED', 'event_time' => '2026-07-10 10:00:00',
	'queue_status' => 'dead', 'attempts' => 1, 'last_attempted_at' => '2026-07-10 10:05:00',
	'next_attempt_at' => '', 'dm_request_id' => '',
	'last_error' => 'skipped/not_eligible: organic/non-paid channel', 'created_at' => '2026-07-10 10:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '1: dead+skip prefix → NOT_ELIGIBLE (not FAILED)', $r['google']['state'] === 'NOT_ELIGIBLE' );
check( '1: basis = policy', $r['google']['basis'] === 'policy' );
check( '1: persisted reason surfaced', strpos( (string) $r['google']['reason'], 'skipped/not_eligible' ) === 0 );

// 2 · execution-log-only row with status skipped → NOT_ELIGIBLE.
$GLOBALS['wpdb']->google_queue = array();
$GLOBALS['wpdb']->logs = array( (object) array(
	'id' => 9, 'stage' => 'QUALIFIED', 'adapter' => 'google_dm', 'event' => 'dispatch_skipped',
	'response_json' => json_encode( array( 'skipped' => true, 'reason' => 'non-Google Ads acquisition' ) ),
	'http_code' => 0, 'status' => 'skipped', 'created_at' => '2026-07-10 11:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '2: log-only skipped → NOT_ELIGIBLE', $r['google']['state'] === 'NOT_ELIGIBLE' && $r['google']['basis'] === 'policy' );

// 3 · persisted ads_dispatch_skipped event (no queue, no log) → NOT_ELIGIBLE + trace.
$GLOBALS['wpdb']->logs = array();
$GLOBALS['wpdb']->skip_events = array( (object) array(
	'created_at' => '2026-07-10 12:00:00',
	'reason'     => 'non-Meta acquisition; _fbp alone is insufficient',
	'metadata'   => json_encode( array( 'platform' => 'google', 'decision' => 'not_eligible', 'reason' => 'no click id', 'channel' => 'organic', 'evidence' => '', 'export_status' => 'QUALIFIED' ) ),
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '3: persisted skip event → NOT_ELIGIBLE with persisted reason', $r['google']['state'] === 'NOT_ELIGIBLE'
	&& strpos( (string) $r['google']['reason'], 'persisted gate decision' ) !== false );
check( '3: gate decision appears in execution trace', count( array_filter( $r['trace'], static function ( $l ) {
	return strpos( $l, 'gate decision' ) !== false;
} ) ) > 0 );

// 4 · no telemetry at all → PLATFORM_CONFIRMATION_UNKNOWN (export never infers success).
$GLOBALS['wpdb']->skip_events = array();
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '4: no telemetry → PLATFORM_CONFIRMATION_UNKNOWN', $r['google']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '4: basis = none (no persisted evidence)', $r['google']['basis'] === 'none' );

// 5 · dry-run validated stays UNKNOWN — Google recorded nothing.
$GLOBALS['wpdb']->google_queue = array( (object) array(
	'id' => 2, 'lead_id' => 7, 'export_status' => 'QUALIFIED', 'event_time' => '2026-07-10 10:00:00',
	'queue_status' => 'validated', 'attempts' => 1, 'last_attempted_at' => '2026-07-10 10:05:00',
	'next_attempt_at' => '', 'dm_request_id' => 'req-1', 'last_error' => '', 'created_at' => '2026-07-10 10:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '5: validateOnly pass → UNKNOWN, never success', $r['google']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN'
	&& strpos( (string) $r['google']['reason'], 'NO conversion recorded' ) !== false );

echo "\n" . ( $failed === 0 ? 'ALL PASS' : $failed . ' FAILED' ) . "\n";
exit( $failed === 0 ? 0 : 1 );
