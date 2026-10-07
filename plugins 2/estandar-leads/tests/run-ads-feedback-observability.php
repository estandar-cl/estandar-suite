<?php
/**
 * Contract tests for Estandar_Leads_Ads_Feedback_Observability.
 * Stubs $wpdb + options — no real DB, no network, read-only resolver.
 * Run: php tests/run-ads-feedback-observability.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }

// ── Collaborator stubs (controllable) ────────────────────────────────────────
class Estandar_Leads_QA_Policy {
	public static $is_test = false;
	public static function is_test_lead( int $lead_id ): bool { return self::$is_test; }
}
class Estandar_Commercial_Qualification_Engine {
	public static $decision = array();
	public static function decision_for( $lead_id, $submission_id, $payload = null ): array { return self::$decision; }
}
// Google adapter stub = "wired" at runtime (exercises the non-dormant branch).
class Estandar_Leads_Google_Adapter {
	const EXPORT_STATUSES = array( 'QUALIFIED' );
}

// ── Fake wpdb ────────────────────────────────────────────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $tables = array();       // table name => true
	public $lead   = null;          // stdClass row
	public $meta_queue   = array(); // rows for wp_es_meta_queue
	public $google_queue = array(); // rows for wp_es_google_queue
	public $logs         = array(); // rows for wp_es_commercial_outcomes_log (newest-first by adapter)
	public $signals      = array();

	public function prepare( $q, ...$a ) {
		foreach ( $a as $v ) {
			$q = preg_replace( '/%[ds]/', is_int( $v ) ? (string) $v : (string) $v, $q, 1 );
		}
		return $q;
	}
	public function get_var( $q ) {
		if ( strpos( $q, 'SHOW TABLES LIKE' ) !== false ) {
			foreach ( array_keys( $this->tables ) as $t ) {
				if ( strpos( $q, $t ) !== false ) { return $t; }
			}
			return null;
		}
		if ( strpos( $q, 'SELECT id FROM wp_es_lead_submissions' ) !== false ) {
			return 55;
		}
		return null;
	}
	public function get_row( $q ) {
		if ( strpos( $q, 'FROM wp_es_leads' ) !== false ) { return $this->lead; }
		return null;
	}
	public function get_results( $q ) {
		if ( strpos( $q, 'wp_es_meta_queue' ) !== false )   { return $this->meta_queue; }
		if ( strpos( $q, 'wp_es_google_queue' ) !== false ) { return $this->google_queue; }
		if ( strpos( $q, 'wp_es_commercial_outcomes_log' ) !== false ) {
			preg_match( "/adapter = ([a-z_]+)/", $q, $m );
			$adapter = $m[1] ?? '';
			return array_values( array_filter( $this->logs, static function ( $r ) use ( $adapter ) {
				return $r->adapter === $adapter;
			} ) );
		}
		if ( strpos( $q, 'wp_es_signal_events' ) !== false ) { return $this->signals; }
		return array();
	}
}

require __DIR__ . '/../includes/class-estandar-leads-attribution.php';
require __DIR__ . '/../includes/class-estandar-leads-ads-feedback-observability.php';

// ── Harness ──────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  $label\n"; }
	else         { $fail++; echo "FAIL  $label\n"; }
}

function base_lead(): stdClass {
	return (object) array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'status' => 'NEW', 'quality' => '',
		'sales_stage' => '', 'email' => 'p@example.test', 'email_normalized' => 'p@example.test',
		'phone' => '+56911111111', 'phone_normalized' => '56911111111',
		'first_gclid' => '', 'first_gbraid' => '', 'first_wbraid' => '',
		'first_fbclid' => 'fb-default', 'first_meta_fbc' => '', 'first_meta_fbp' => '',
		'first_utm_source' => 'meta', 'first_utm_medium' => 'paid',
	);
}

function scenario( array $opts = array() ): FakeWpdb {
	$GLOBALS['__opts'] = $opts;
	Estandar_Leads_QA_Policy::$is_test = false;
	Estandar_Commercial_Qualification_Engine::$decision = array(
		'ads_feedback_policy' => 'allow', 'qualification' => 'standard',
		'decision_source' => 'DEFAULT', 'commercial_fit' => '', 'quality' => '',
		'sales_stage' => '', 'policy_reason' => 'default_policy',
	);
	$db = new FakeWpdb();
	$db->tables = array(
		'wp_es_meta_queue' => true, 'wp_es_google_queue' => true,
		'wp_es_commercial_outcomes_log' => true,
	);
	$db->lead = base_lead();
	$GLOBALS['wpdb'] = $db;
	return $db;
}

$meta_cfg   = array( 'estandar_leads_meta_pixel_id' => '123', 'estandar_leads_meta_access_token' => 'tok' );
$google_cfg = array(
	'estandar_leads_google_customer_id' => '111', 'estandar_leads_google_conversion_action_id' => '999',
	'estandar_leads_google_oauth_client_id' => 'a', 'estandar_leads_google_oauth_client_secret' => 'b',
	'estandar_leads_google_oauth_refresh_token' => 'c', 'estandar_leads_google_enabled' => '1',
);

// ── 1. Meta lead, valid identifiers, no execution telemetry → UNKNOWN ────────
$db = scenario( $meta_cfg );
$db->lead->first_fbclid = 'fb.1.abc';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '1. meta no-telemetry → PLATFORM_CONFIRMATION_UNKNOWN', $r['meta']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '1. meta basis none (never success)', $r['meta']['basis'] === 'none' );

// ── 2. Google lead, GBRAID, configured+wired, no telemetry → UNKNOWN ─────────
$db = scenario( $google_cfg );
$db->lead->first_gbraid = 'gb-123';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '2. google gbraid no-telemetry → UNKNOWN', $r['google']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '2. click identifier = gbraid', $r['google']['click_identifier'] === 'gbraid' );

// ── 3. Not configured ────────────────────────────────────────────────────────
$db = scenario();
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '3. meta NOT_CONFIGURED', $r['meta']['state'] === 'NOT_CONFIGURED' );
check( '3. google NOT_ELIGIBLE without Google acquisition', $r['google']['state'] === 'NOT_ELIGIBLE' );

// ── 4. QA test lead → NOT_ELIGIBLE, excluded from both platforms ─────────────
$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
Estandar_Leads_QA_Policy::$is_test = true;
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '4. QA → eligibility.is_test_lead', $r['eligibility']['is_test_lead'] === true );
check( '4. QA → meta NOT_ELIGIBLE', $r['meta']['state'] === 'NOT_ELIGIBLE' );
check( '4. QA → google NOT_ELIGIBLE', $r['google']['state'] === 'NOT_ELIGIBLE' );
check( '4. QA reason', strpos( $r['meta']['reason'], 'qa_test_lead' ) !== false );

// ── 5. Skipped by commercial policy ──────────────────────────────────────────
$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
Estandar_Commercial_Qualification_Engine::$decision['ads_feedback_policy'] = 'skip';
Estandar_Commercial_Qualification_Engine::$decision['policy_reason'] = 'disqualified_by_rule';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '5. policy skip → SKIPPED_BY_POLICY (meta)', $r['meta']['state'] === 'SKIPPED_BY_POLICY' );
check( '5. policy skip → SKIPPED_BY_POLICY (google)', $r['google']['state'] === 'SKIPPED_BY_POLICY' );
check( '5. eligibility not eligible', $r['eligibility']['eligible'] === false );

// ── 6. Queued ────────────────────────────────────────────────────────────────
$db = scenario( $meta_cfg );
$db->meta_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-13 10:00:00',
	'queue_status' => 'pending', 'attempts' => 0, 'last_attempted_at' => null, 'last_error' => '',
	'created_at' => '2026-07-13 10:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '6. pending attempts=0 → QUEUED', $r['meta']['state'] === 'QUEUED' );
check( '6. stream exposed', $r['meta']['stream'] === 'CAPTURED' );

// ── 7. Accepted (completed + log success 200 + fbtrace) ──────────────────────
$db = scenario( $meta_cfg );
$db->meta_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-13 10:00:00',
	'queue_status' => 'completed', 'attempts' => 1, 'last_attempted_at' => '2026-07-13 10:00:05',
	'last_error' => 'OK', 'created_at' => '2026-07-13 10:00:00',
) );
$db->logs = array( (object) array(
	'id' => 9, 'stage' => 'CAPTURED', 'adapter' => 'meta', 'event' => 'Lead',
	'response_json' => json_encode( array( 'success' => true, 'http_code' => 200, 'body' => json_encode( array( 'events_received' => 1, 'fbtrace_id' => 'FBTRACE99' ) ) ) ),
	'http_code' => 200, 'status' => 'success', 'created_at' => '2026-07-13 10:00:05',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '7. completed+200 → API_ACCEPTED', $r['meta']['state'] === 'API_ACCEPTED' );
check( '7. basis api_acknowledgement', $r['meta']['basis'] === 'api_acknowledgement' );
check( '7. fbtrace surfaced', $r['meta']['request_id'] === 'FBTRACE99' );
$md = Estandar_Leads_Ads_Feedback_Observability::render( 7 );
check( '7. render event_id deterministic', strpos( $md, '`uuid-7:CAPTURED`' ) !== false );

// ── 8. Rejected (google dead + log 400) ──────────────────────────────────────
$db = scenario( $google_cfg );
$db->lead->first_gclid = 'g-8';
$db->google_queue = array( (object) array(
	'id' => 2, 'lead_id' => 7, 'export_status' => 'QUALIFIED', 'event_time' => '2026-07-13 11:00:00',
	'queue_status' => 'dead', 'attempts' => 1, 'last_attempted_at' => '2026-07-13 11:00:05',
	'next_attempt_at' => null, 'dm_request_id' => 'REQ-1', 'last_error' => 'Google DM bad request: invalid destination',
	'created_at' => '2026-07-13 11:00:00',
) );
$db->logs = array( (object) array(
	'id' => 10, 'stage' => 'QUALIFIED', 'adapter' => 'google_dm', 'event' => 'lead_qualified',
	'response_json' => json_encode( array( 'success' => false ) ), 'http_code' => 400,
	'status' => 'failed', 'created_at' => '2026-07-13 11:00:05',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '8. dead+400 → API_REJECTED', $r['google']['state'] === 'API_REJECTED' );
check( '8. request id surfaced', $r['google']['request_id'] === 'REQ-1' );

// ── 9. Retry pending (google) ────────────────────────────────────────────────
$db = scenario( $google_cfg );
$db->lead->first_gclid = 'g-9';
$db->google_queue = array( (object) array(
	'id' => 3, 'lead_id' => 7, 'export_status' => 'QUALIFIED', 'event_time' => '2026-07-13 11:00:00',
	'queue_status' => 'retry', 'attempts' => 2, 'last_attempted_at' => '2026-07-13 12:00:00',
	'next_attempt_at' => '2026-07-13 16:00:00', 'dm_request_id' => '', 'last_error' => 'HTTP 503',
	'created_at' => '2026-07-13 11:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '9. retry → RETRY_PENDING', $r['google']['state'] === 'RETRY_PENDING' );
check( '9. next retry exposed', $r['google']['retry_next_at'] === '2026-07-13 16:00:00' );
check( '9. attempts exposed', $r['google']['attempts'] === 2 );

// ── 9b. Google dry-run validated → UNKNOWN, never success ────────────────────
$db = scenario( $google_cfg );
$db->lead->first_gclid = 'g-9b';
$db->google_queue = array( (object) array(
	'id' => 4, 'lead_id' => 7, 'export_status' => 'QUALIFIED', 'event_time' => '2026-07-13 11:00:00',
	'queue_status' => 'validated', 'attempts' => 1, 'last_attempted_at' => '2026-07-13 11:00:05',
	'next_attempt_at' => null, 'dm_request_id' => 'REQ-V', 'last_error' => '',
	'created_at' => '2026-07-13 11:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '9b. validated → PLATFORM_CONFIRMATION_UNKNOWN', $r['google']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '9b. reason mentions no conversion recorded', stripos( $r['google']['reason'], 'NO conversion' ) !== false );

// ── 10. Retry idempotency: one queue row + two dispatch logs → single state ──
$db = scenario( $meta_cfg );
$db->meta_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-13 10:00:00',
	'queue_status' => 'completed', 'attempts' => 2, 'last_attempted_at' => '2026-07-13 11:00:00',
	'last_error' => 'OK', 'created_at' => '2026-07-13 10:00:00',
) );
$db->logs = array(
	(object) array( 'id' => 12, 'stage' => 'CAPTURED', 'adapter' => 'meta', 'event' => 'Lead',
		'response_json' => json_encode( array( 'body' => json_encode( array( 'fbtrace_id' => 'FB2' ) ) ) ),
		'http_code' => 200, 'status' => 'success', 'created_at' => '2026-07-13 11:00:00' ),
	(object) array( 'id' => 11, 'stage' => 'CAPTURED', 'adapter' => 'meta', 'event' => 'Lead',
		'response_json' => json_encode( array( 'body' => '' ) ),
		'http_code' => 503, 'status' => 'retry', 'created_at' => '2026-07-13 10:00:05' ),
);
$r  = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
$md = Estandar_Leads_Ads_Feedback_Observability::render( 7 );
check( '10. retries collapse to latest state', $r['meta']['state'] === 'API_ACCEPTED' );
check( '10. trace has both dispatches', substr_count( $md, 'dispatch `Lead`' ) === 2 );
check( '10. trace has exactly one queued entry', substr_count( $md, 'queued (outbox)' ) === 1 );

// ── 11. Secret redaction ─────────────────────────────────────────────────────
$db = scenario( $meta_cfg );
$db->meta_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-13 10:00:00',
	'queue_status' => 'dead', 'attempts' => 3, 'last_attempted_at' => '2026-07-13 11:00:00',
	'last_error' => 'Meta CAPI permanent error: access_token=EAAB1234567890abcdefFAKEFAKE invalid; also raw EAABzzzzzzzzzzzzzzzzzzzzzz leaked',
	'created_at' => '2026-07-13 10:00:00',
) );
$md = Estandar_Leads_Ads_Feedback_Observability::render( 7 );
check( '11. token value never rendered', strpos( $md, 'EAAB1234567890' ) === false && strpos( $md, 'EAABzzzz' ) === false );
check( '11. redaction marker present', strpos( $md, '[REDACTED]' ) !== false );

// ── 12. Export and panel consume the same resolver (source contract) ─────────
$ai_context = file_get_contents( __DIR__ . '/../includes/class-estandar-leads-ai-context.php' );
$bootstrap  = file_get_contents( __DIR__ . '/../estandar-leads.php' );
check( '12. AI Context calls the resolver exactly once',
	substr_count( $ai_context, 'Estandar_Leads_Ads_Feedback_Observability::render(' ) === 1 );
check( '12. AI Context defines no parallel ads section',
	strpos( $ai_context, '### Ads Feedback Observability' ) === false );
check( '12. bootstrap requires the resolver',
	strpos( $bootstrap, 'class-estandar-leads-ads-feedback-observability.php' ) !== false );

// ── 13. QA leak cross-check: execution rows on a QA lead → note ──────────────
$db = scenario( $meta_cfg );
Estandar_Leads_QA_Policy::$is_test = true;
$db->meta_queue = array( (object) array(
	'id' => 1, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-13 10:00:00',
	'queue_status' => 'completed', 'attempts' => 1, 'last_attempted_at' => '2026-07-13 10:00:05',
	'last_error' => 'OK', 'created_at' => '2026-07-13 10:00:00',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '13. QA leak surfaces as note', (bool) preg_grep( '/QA exclusion leak/', $r['notes'] ) );

// ── 14. Eligibility block completeness in render ─────────────────────────────
$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
$md = Estandar_Leads_Ads_Feedback_Observability::render( 7 );
foreach ( array(
	'### Ads Feedback Observability', 'Is test lead (QA)', 'Ads feedback policy', 'Eligible:',
	'Eligible event', 'Eligibility reason', 'Qualification:', 'Qualification source',
	'Commercial fit', 'Quality:', 'Sales stage', '#### Google Ads OCI', '#### Meta CAPI',
	'#### Execution Trace (chronological)', 'Conclusion basis',
) as $needle ) {
	check( "14. render contains '$needle'", strpos( $md, $needle ) !== false );
}
check( '14. no telemetry → trace says so', strpos( $md, 'No persisted ads feedback execution telemetry' ) !== false );

// ── 15. Platform-specific eligibility mirrors adapter gates ─────────────────
$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
$db->lead->first_utm_source = 'google';
$db->lead->first_utm_medium = 'organic';
$db->lead->first_fbclid = '';
$db->lead->first_meta_fbp = 'fb.1.browser';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '15a. Google organic → Google NOT_ELIGIBLE', $r['google']['state'] === 'NOT_ELIGIBLE' );
check( '15a. Google organic reason is non-paid', strpos( $r['google']['reason'], 'organic/non-paid' ) !== false );
check( '15a. _fbp-only → Meta NOT_ELIGIBLE', $r['meta']['state'] === 'NOT_ELIGIBLE' );
check( '15a. Meta reason says _fbp insufficient', strpos( $r['meta']['reason'], '_fbp alone is insufficient' ) !== false );
$db->meta_queue = array( (object) array(
	'id' => 20, 'lead_id' => 7, 'export_status' => 'CAPTURED', 'event_time' => '2026-07-15 10:00:00',
	'queue_status' => 'completed', 'attempts' => 1, 'last_attempted_at' => '2026-07-15 10:00:05',
	'last_error' => 'OK', 'created_at' => '2026-07-15 10:00:00',
) );
$db->logs = array( (object) array(
	'id' => 20, 'stage' => 'CAPTURED', 'adapter' => 'meta', 'event' => 'Lead',
	'response_json' => json_encode( array( 'success' => true, 'body' => '{"events_received":1}' ) ),
	'http_code' => 200, 'status' => 'success', 'created_at' => '2026-07-15 10:00:05',
) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '15a. historical HTTP 200 cannot override current Meta ineligibility', $r['meta']['state'] === 'NOT_ELIGIBLE' );
check( '15a. historical eligibility leak remains visible', (bool) preg_grep( '/historical eligibility leak/', $r['notes'] ) );

$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
$db->lead->first_fbclid = '';
$db->lead->first_utm_source = 'google';
$db->lead->first_utm_medium = 'cpc';
$db->lead->first_gclid = 'g-1';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '15b. Google Ads + GCLID remains eligible/unknown without execution', $r['google']['platform_eligible'] && $r['google']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '15b. Google Ads is not Meta eligible', ! $r['meta']['platform_eligible'] && $r['meta']['state'] === 'NOT_ELIGIBLE' );

$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '15c. Meta Ads remains eligible/unknown without execution', $r['meta']['platform_eligible'] && $r['meta']['state'] === 'PLATFORM_CONFIRMATION_UNKNOWN' );
check( '15c. Meta Ads is not Google eligible', ! $r['google']['platform_eligible'] && $r['google']['state'] === 'NOT_ELIGIBLE' );

$db = scenario( array_merge( $meta_cfg, $google_cfg ) );
$db->lead->first_fbclid = '';
$db->lead->first_utm_source = 'google';
$db->lead->first_utm_medium = 'cpc';
$r = Estandar_Leads_Ads_Feedback_Observability::resolve( 7 );
check( '15d. Google cpc without click ID keeps canonical google_ads channel', $r['google']['channel'] === 'google_ads' );
check( '15d. Google cpc without click ID → Google NOT_ELIGIBLE', ! $r['google']['platform_eligible'] && $r['google']['state'] === 'NOT_ELIGIBLE' );
check( '15d. Google reason requires click identifier', strpos( $r['google']['reason'], 'missing Google Ads click identifier' ) !== false );
check( '15d. Google cpc without click ID → Meta NOT_ELIGIBLE', ! $r['meta']['platform_eligible'] && $r['meta']['state'] === 'NOT_ELIGIBLE' );

// ── Result ───────────────────────────────────────────────────────────────────
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
