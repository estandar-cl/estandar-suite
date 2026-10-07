<?php
/** Historical/backfill platform eligibility reconciliation. No network. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['__opts'] = array( 'estandar_commercial_currency' => 'CLP' );
function get_option( $key, $default = false ) { return $GLOBALS['__opts'][ $key ] ?? $default; }
function absint( $value ) { return abs( (int) $value ); }

class Estandar_Leads_QA_Policy {
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool { return false; }
}
class Estandar_Leads_Conversion_Backfill_Service {
	public static $enqueued = 0;
	public function enqueue( array $buyers ): array { self::$enqueued += count( $buyers ); return array( 'created' => count( $buyers ), 'skipped' => 0 ); }
	public function dispatch( int $limit = 100 ): array { return array( 'sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0 ); }
}
class HistoricalWpdb {
	public $prefix = 'wp_';
	public $customers = array();
	public $leads = array();
	public function prepare( $query, ...$args ) { return array( 'q' => $query, 'a' => $args ); }
	public function get_results( $prepared, $output = null ) { return array_values( $this->customers ); }
	public function get_var( $prepared ) { return 0; }
	public function get_row( $prepared, $output = null ) {
		$id = (int) ( $prepared['a'][0] ?? 0 );
		$lead = $this->leads[ $id ] ?? null;
		return $lead ? ( $output === ARRAY_A ? $lead : (object) $lead ) : null;
	}
}

$db = new HistoricalWpdb();
$GLOBALS['wpdb'] = $db;
$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-customer-backfill.php';

$pass = 0; $fail = 0;
function check( $label, $condition ) {
	global $pass, $fail;
	$condition ? $pass++ : $fail++;
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
}

$db->customers = array(
	array( 'id' => 10, 'project_slug' => 'altacruz', 'email' => 'historical@example.test', 'phone' => '', 'completed_at' => '2025-11-15 10:00:00', 'matched_lead_id' => null ),
	array( 'id' => 11, 'project_slug' => 'altacruz', 'email' => 'matched@example.test', 'phone' => '', 'completed_at' => '2025-11-15 10:00:00', 'matched_lead_id' => 101 ),
);

$historical = Estandar_Leads_Customer_Backfill::plan( array(), 100, Estandar_Leads_Customer_Backfill::MODE_HISTORICAL );
check( 'historical customer without paid attribution is not eligible', $historical['eligible'] === 0 );
check( 'historical reason is canonical Meta platform ineligibility', ( $historical['reasons'][ Estandar_Leads_Customer_Backfill::SKIP_PLATFORM_INELIGIBLE ] ?? 0 ) === 1 );
check( 'matched customer remains excluded from historical mode', ( $historical['reasons'][ Estandar_Leads_Customer_Backfill::SKIP_ALREADY_MATCHED ] ?? 0 ) === 1 );

$run = Estandar_Leads_Customer_Backfill::run( array(), 100, false, Estandar_Leads_Customer_Backfill::MODE_HISTORICAL );
check( 'historical run enqueues and sends nothing', $run['sent'] === 0 && Estandar_Leads_Conversion_Backfill_Service::$enqueued === 0 );

$db->customers = array(
	array( 'id' => 20, 'project_slug' => 'altacruz', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 201 ),
	array( 'id' => 21, 'project_slug' => 'altacruz', 'completed_at' => '2026-02-01 12:00:00', 'matched_lead_id' => 202 ),
);
$db->leads = array(
	201 => array( 'id' => 201, 'lead_uuid' => 'uuid-201', 'first_fbclid' => 'fb-paid-201' ),
	202 => array( 'id' => 202, 'lead_uuid' => 'uuid-202', 'first_utm_source' => 'google', 'first_utm_medium' => 'organic', 'first_meta_fbp' => 'fb.1.browser' ),
);
$reconciled = Estandar_Leads_Customer_Backfill::plan( array(), 100 );
check( 'legitimate paid Meta reconciled customer remains eligible', $reconciled['eligible'] === 1 && (int) $reconciled['buyers'][0]['lead_id'] === 201 );
check( 'organic reconciled customer is blocked before backfill enqueue', ( $reconciled['reasons'][ Estandar_Leads_Customer_Backfill::SKIP_PLATFORM_INELIGIBLE ] ?? 0 ) === 1 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
