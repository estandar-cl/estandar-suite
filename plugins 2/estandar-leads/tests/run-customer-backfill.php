<?php
/**
 * Contract test for CUSTOMER_BACKFILL_V1.
 *
 * Reconciled customer → canonical lead hydration → audited Meta Purchase.
 * Run: php tests/run-customer-backfill.php
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => 'PIX',
	'estandar_leads_meta_access_token' => 'TOK',
	'estandar_commercial_currency'     => 'CLP',
);
$GLOBALS['__http'] = array();

// Queue of canned transport outcomes, consumed in order. Default = HTTP 200.
$GLOBALS['__http_queue'] = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function absint( $v ) { return abs( (int) $v ); }
class WP_Error { public function get_error_message() { return 'transport down'; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_post( $u, $a ) {
	$GLOBALS['__http'][] = array( 'url' => $u, 'args' => $a );
	$next = array_shift( $GLOBALS['__http_queue'] );
	if ( $next === null ) {
		$next = array( 'code' => 200, 'body' => '{"events_received":1}' );
	}
	return array( 'response' => array( 'code' => $next['code'] ), 'body' => $next['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

/** QA policy stub — lead 105 is a QA/test lead, everything else is real. */
class Estandar_Leads_QA_Policy {
	public static $blocked = array( 105 );
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool {
		return in_array( $lead_id, self::$blocked, true );
	}
}

class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();          // es_conversion_backfill_audit
	public $customers = array();     // es_customers
	public $leads = array();         // es_leads
	public $writes = array();        // every table written to (guard: es_leads/es_customers must never appear)

	public function prepare( $q, ...$a ) {
		if ( count( $a ) === 1 && is_array( $a[0] ) ) { $a = $a[0]; }
		return array( 'q' => $q, 'a' => $a );
	}

	public function insert( $t, $data, $f = null ) {
		$this->writes[] = $t;
		foreach ( $this->rows as $row ) {
			if ( $row->event_id === $data['event_id'] ) { return false; }
		}
		$data['id']   = ++$this->insert_id;
		$this->rows[] = (object) $data;
		return 1;
	}

	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$this->writes[] = $t;
		foreach ( $this->rows as $row ) {
			if ( (int) $row->id === (int) $where['id'] ) {
				foreach ( $data as $k => $v ) { $row->$k = $v; }
				return 1;
			}
		}
		return 0;
	}

	public function get_var( $p ) {
		$q = is_array( $p ) ? $p['q'] : $p;
		if ( strpos( $q, 'WHERE event_id = %s' ) !== false ) {
			$event_id = $p['a'][0] ?? '';
			foreach ( $this->rows as $row ) {
				if ( $row->event_id === $event_id ) { return 1; }
			}
		}
		return 0;
	}

	public function get_row( $p, $output = null ) {
		$q  = is_array( $p ) ? $p['q'] : $p;
		$id = (int) ( $p['a'][0] ?? 0 );

		if ( strpos( $q, 'es_leads' ) !== false ) {
			$lead = $this->leads[ $id ] ?? null;
			if ( ! $lead ) { return null; }
			if ( $output === ARRAY_A ) { return $lead; }
			return (object) $lead; // SELECT * → full canonical row
		}
		return null;
	}

	public function get_results( $p, $output = null ) {
		$q = is_array( $p ) ? $p['q'] : $p;

		if ( strpos( $q, 'es_customers' ) !== false ) {
			return array_values( $this->customers ); // ARRAY_A rows, filters unused in fixture
		}
		if ( strpos( $q, 'GROUP BY status' ) !== false ) {
			$counts = array();
			foreach ( $this->rows as $row ) { $counts[ $row->status ] = ( $counts[ $row->status ] ?? 0 ) + 1; }
			$out = array();
			foreach ( $counts as $status => $total ) { $out[] = array( 'status' => $status, 'total' => $total ); }
			return $out;
		}
		if ( strpos( $q, "status = 'pending'" ) !== false ) {
			return array_values( array_filter( $this->rows, function ( $r ) { return $r->status === 'pending'; } ) );
		}
		return array_reverse( $this->rows );
	}
}
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

// ── Fixtures ────────────────────────────────────────────────────────────────────
// Lead 101: the real AltaCruz buyer — full attribution, the payload must carry it.
$wpdb->leads[101] = array(
	'id' => 101, 'lead_uuid' => 'uuid-101', 'project_slug' => 'altacruz',
	'email' => 'Buyer@Example.com', 'phone' => '+56 9 1234 5678',
	'first_name' => 'Ada', 'last_name' => 'Lovelace',
	'first_meta_fbc' => 'fb.1.1700000000000.CLICKID', 'first_meta_fbp' => 'fb.1.1700000000000.99',
	'first_fbclid' => 'CLICKID',
	'first_utm_source' => 'ig', 'first_utm_medium' => 'paid', 'first_utm_campaign' => '120248585033280457',
	'first_ip_address' => '190.1.2.3', 'first_user_agent' => 'Mozilla/5.0',
	'first_landing_url' => 'https://acacios.cl/altacruz', 'created_at' => '2026-01-02 10:00:00',
	'status' => 'QUALIFIED',
);
$wpdb->leads[104] = array( 'id' => 104, 'lead_uuid' => '', 'email' => 'nouuid@example.com' );
$wpdb->leads[105] = array( 'id' => 105, 'lead_uuid' => 'uuid-105', 'email' => 'qa@example.com' );
$wpdb->leads[106] = array( 'id' => 106, 'lead_uuid' => 'uuid-106', 'email' => 'retry@example.com', 'first_fbclid' => 'fb-paid-106' );
$wpdb->leads[107] = array( 'id' => 107, 'lead_uuid' => 'uuid-107', 'email' => 'terminal@example.com', 'first_fbclid' => 'fb-paid-107' );

$wpdb->customers = array(
	array( 'id' => 1, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
	       'amount' => '181038075.00', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 101, 'matched_at' => '2026-02-02 09:00:00' ),
	array( 'id' => 2, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Sin', 'last_name' => 'Match',
	       'amount' => '1000', 'completed_at' => null, 'matched_lead_id' => null, 'matched_at' => null ),
	array( 'id' => 3, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Lead', 'last_name' => 'Borrado',
	       'amount' => '1000', 'completed_at' => null, 'matched_lead_id' => 999, 'matched_at' => '2026-02-02 09:00:00' ),
	array( 'id' => 4, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Sin', 'last_name' => 'Uuid',
	       'amount' => '1000', 'completed_at' => null, 'matched_lead_id' => 104, 'matched_at' => '2026-02-02 09:00:00' ),
	array( 'id' => 5, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Lead', 'last_name' => 'QA',
	       'amount' => '1000', 'completed_at' => null, 'matched_lead_id' => 105, 'matched_at' => '2026-02-02 09:00:00' ),
);

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-conversion-backfill-service.php';
require_once $base . 'class-estandar-leads-customer-backfill.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}

// ── 1. Dry-run sends nothing ────────────────────────────────────────────────────
$dry = Estandar_Leads_Customer_Backfill::run( array(), 100, true );
check( '1. dry-run: 1 eligible, 4 skipped, nothing sent', $dry['eligible'] === 1 && $dry['skipped'] === 4 && $dry['sent'] === 0 );
check( '1. dry-run: zero HTTP calls', count( $GLOBALS['__http'] ) === 0 );
check( '1. dry-run: zero audit rows written', count( $wpdb->rows ) === 0 );
check( '1. dry-run: dry_run flag reported', $dry['dry_run'] === true );

// ── 4 + 5. Skip rules ───────────────────────────────────────────────────────────
$reasons = $dry['reasons'];
check( '4. unmatched customer skipped', ( $reasons[ Estandar_Leads_Customer_Backfill::SKIP_UNMATCHED ] ?? 0 ) === 1 );
check( '4. customer whose lead vanished skipped', ( $reasons[ Estandar_Leads_Customer_Backfill::SKIP_LEAD_MISSING ] ?? 0 ) === 1 );
check( '4. lead without lead_uuid skipped (no deterministic event_id)', ( $reasons[ Estandar_Leads_Customer_Backfill::SKIP_LEAD_UUID_MISSING ] ?? 0 ) === 1 );
check( '5. QA-blocked lead skipped', ( $reasons[ Estandar_Leads_Customer_Backfill::SKIP_QA_BLOCKED ] ?? 0 ) === 1 );

// ── 2. Eligible reconciled customer emits exactly one Purchase ──────────────────
$run = Estandar_Leads_Customer_Backfill::run( array(), 100, false );
check( '2. real run: exactly 1 Purchase sent', $run['sent'] === 1 && $run['failed'] === 0 && $run['retry'] === 0 );
check( '2. real run: exactly 1 HTTP call', count( $GLOBALS['__http'] ) === 1 );

$body    = json_decode( $GLOBALS['__http'][0]['args']['body'], true );
$event   = $body['data'][0] ?? array();
$ud      = $event['user_data'] ?? array();
$cd      = $event['custom_data'] ?? array();

check( '2. event_name is Purchase', ( $event['event_name'] ?? '' ) === 'Purchase' );
check( '2. deterministic event_id (lead + buyer + Purchase)',
	( $event['event_id'] ?? '' ) === 'conversion-backfill:uuid-101:' . Estandar_Leads_Customer_Backfill::buyer_uuid( 1 ) . ':Purchase' );
check( '2. sale value + currency from the customer record', (float) ( $cd['value'] ?? 0 ) === 181038075.0 && ( $cd['currency'] ?? '' ) === 'CLP' );

// ── 6. Canonical lead attribution + identity reach the payload ──────────────────
check( '6. em is SHA-256 of the normalized canonical email',
	( $ud['em'] ?? '' ) === hash( 'sha256', 'buyer@example.com' ) );
check( '6. ph is SHA-256 of canonical digits', ( $ud['ph'] ?? '' ) === hash( 'sha256', '56912345678' ) );
check( '6. fbc from canonical lead', ( $ud['fbc'] ?? '' ) === 'fb.1.1700000000000.CLICKID' );
check( '6. fbp from canonical lead', ( $ud['fbp'] ?? '' ) === 'fb.1.1700000000000.99' );
check( '6. client_ip_address + client_user_agent from canonical lead (raw)',
	( $ud['client_ip_address'] ?? '' ) === '190.1.2.3' && ( $ud['client_user_agent'] ?? '' ) === 'Mozilla/5.0' );
check( '6. external_id = canonical lead_uuid', ( $ud['external_id'] ?? '' ) === 'uuid-101' );
check( '6. UTM campaign from canonical lead', ( $cd['utm_campaign'] ?? '' ) === '120248585033280457' && ( $cd['utm_source'] ?? '' ) === 'ig' );
check( '6. action_source = website (canonical landing url present)', ( $event['action_source'] ?? '' ) === 'website' );

// PII must not be persisted in the audit row: buyer_json carries commercial facts only.
$audit_row = $wpdb->rows[0];
$buyer_json = (string) $audit_row->buyer_json;
check( '6. buyer_json carries no PII (hydration is from es_leads)',
	strpos( $buyer_json, 'buyer@example.com' ) === false && strpos( $buyer_json, '56912345678' ) === false
	&& strpos( $buyer_json, '"lead_id":101' ) !== false );

// ── Never modifies leads or customers ───────────────────────────────────────────
$touched_forbidden = array_filter( $wpdb->writes, function ( $t ) {
	return strpos( $t, 'es_leads' ) !== false || strpos( $t, 'es_customers' ) !== false;
} );
check( 'guard: never writes es_leads or es_customers (no status change)', empty( $touched_forbidden ) );

// ── 3. Duplicate execution emits nothing ────────────────────────────────────────
$again = Estandar_Leads_Customer_Backfill::run( array(), 100, false );
check( '3. re-run: 0 eligible, 1 counted as already dispatched', $again['eligible'] === 0 && $again['duplicate'] === 1 );
check( '3. re-run: nothing sent, no new HTTP call', $again['sent'] === 0 && count( $GLOBALS['__http'] ) === 1 );
check( '3. re-run: audit still holds exactly 1 row', count( $wpdb->rows ) === 1 );

// ── 7. Retryable vs terminal transport failures are audited distinctly ──────────
$wpdb->customers = array(
	array( 'id' => 6, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Retry', 'last_name' => 'Case',
	       'amount' => '1000', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 106, 'matched_at' => '2026-02-02 09:00:00' ),
	array( 'id' => 7, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Terminal', 'last_name' => 'Case',
	       'amount' => '1000', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 107, 'matched_at' => '2026-02-02 09:00:00' ),
);
$GLOBALS['__http_queue'] = array(
	array( 'code' => 500, 'body' => '{"error":{"message":"server"}}' ),  // retryable
	array( 'code' => 400, 'body' => '{"error":{"message":"bad payload"}}' ), // terminal
);

$fails = Estandar_Leads_Customer_Backfill::run( array(), 100, false );
check( '7. one retryable + one terminal failure, zero sent', $fails['sent'] === 0 && $fails['retry'] === 1 && $fails['failed'] === 1 );

$by_uuid = array();
foreach ( $wpdb->rows as $row ) { $by_uuid[ $row->lead_uuid ] = $row; }
check( '7. retryable (5xx) audited as retry with http 500',
	( $by_uuid['uuid-106']->status ?? '' ) === 'retry' && (int) ( $by_uuid['uuid-106']->response_code ?? 0 ) === 500 );
check( '7. terminal (4xx) audited as failed with http 400',
	( $by_uuid['uuid-107']->status ?? '' ) === 'failed' && (int) ( $by_uuid['uuid-107']->response_code ?? 0 ) === 400 );
check( '7. failed/retry rows are not re-dispatched on the next run',
	Estandar_Leads_Customer_Backfill::run( array(), 100, false )['sent'] === 0 && count( $GLOBALS['__http'] ) === 3 );

// ── QA gate holds at dispatch time too (lead flagged after enqueue) ─────────────
$wpdb->customers = array(
	array( 'id' => 8, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Late', 'last_name' => 'QA',
	       'amount' => '1000', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 108, 'matched_at' => '2026-02-02 09:00:00' ),
);
$wpdb->leads[108] = array( 'id' => 108, 'lead_uuid' => 'uuid-108', 'email' => 'late@example.com', 'first_fbclid' => 'fb-paid-108' );
$plan_ok = Estandar_Leads_Customer_Backfill::plan( array(), 100 );
check( 'QA gate: lead is eligible before being flagged', $plan_ok['eligible'] === 1 );

Estandar_Leads_QA_Policy::$blocked[] = 108; // flagged between planning and dispatch
$http_before = count( $GLOBALS['__http'] );
$late = Estandar_Leads_Customer_Backfill::run( array(), 100, false );
check( 'QA gate: blocked at dispatch → skipped, nothing sent',
	$late['sent'] === 0 && count( $GLOBALS['__http'] ) === $http_before );

// ── Orphan recovery: a run that died between enqueue and dispatch ───────────────
// The audit row is 'pending' and plan() now classifies the customer as a duplicate.
// The drain must still deliver it, otherwise the row could never be sent.
$wpdb->customers = array(
	array( 'id' => 9, 'project_slug' => 'altacruz', 'customer_stage' => 'cerrado', 'first_name' => 'Orphan', 'last_name' => 'Row',
	       'amount' => '5000', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 109, 'matched_at' => '2026-02-02 09:00:00' ),
);
$wpdb->leads[109] = array( 'id' => 109, 'lead_uuid' => 'uuid-109', 'email' => 'orphan@example.com', 'first_fbclid' => 'fb-paid-109' );

// Simulate the interrupted run: enqueue only, no dispatch.
$svc = new Estandar_Leads_Conversion_Backfill_Service();
$svc->enqueue( array( array(
	'approved' => true, 'buyer_uuid' => Estandar_Leads_Customer_Backfill::buyer_uuid( 9 ),
	'lead_uuid' => 'uuid-109', 'lead_id' => 109, 'purchase_value' => 5000, 'currency' => 'CLP',
	'purchase_metadata' => array( 'event_time' => '2026-02-01 12:00:00' ), 'lead' => array(),
) ) );

$orphan_plan = Estandar_Leads_Customer_Backfill::plan( array(), 100 );
check( 'orphan: pending row is reported as duplicate by plan()', $orphan_plan['duplicate'] === 1 && $orphan_plan['eligible'] === 0 );

$http_pre = count( $GLOBALS['__http'] );
$recovered = Estandar_Leads_Customer_Backfill::run( array(), 100, false );
check( 'orphan: the pending row is still dispatched (no lost conversion)',
	$recovered['sent'] === 1 && count( $GLOBALS['__http'] ) === $http_pre + 1 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
