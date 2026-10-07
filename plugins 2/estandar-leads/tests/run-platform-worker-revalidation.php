<?php
/** Worker-level platform eligibility re-validation. No transport classes loaded. */
define( 'ABSPATH', __DIR__ );

$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id' => 'PIX', 'estandar_leads_meta_access_token' => 'TOK',
	'estandar_leads_google_enabled' => '1', 'estandar_leads_google_customer_id' => '1',
	'estandar_leads_google_conversion_action_id' => '2', 'estandar_leads_google_oauth_client_id' => '3',
	'estandar_leads_google_oauth_client_secret' => '4', 'estandar_leads_google_oauth_refresh_token' => '5',
);
function get_option( $key, $default = false ) { return $GLOBALS['__opts'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) { if ( isset( $GLOBALS['__opts'][ $key ] ) ) { return false; } $GLOBALS['__opts'][ $key ] = $value; return true; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__opts'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['__opts'][ $key ] ); return true; }
function add_action() {}
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}

class Estandar_Leads_QA_Policy {
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool { return false; }
}
class WorkerWpdb {
	public $prefix = 'wp_';
	public $lead;
	public $updates = array();
	public function prepare( $query, ...$args ) { return array( 'q' => $query, 'a' => $args ); }
	public function get_var( $query ) { return 0; }
	public function get_row( $prepared ) { return $this->lead; }
	public function get_results( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$table = strpos( $query, 'es_google_queue' ) !== false ? 'google' : 'meta';
		return array( (object) array(
			'id' => $table === 'google' ? 2 : 1, 'lead_id' => 7, 'export_status' => 'QUALIFIED',
			'event_time' => '2026-07-15 10:00:00', 'attempts' => 0, 'queue_status' => 'pending',
		) );
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$this->updates[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}
}
$GLOBALS['wpdb'] = new WorkerWpdb();
$GLOBALS['wpdb']->lead = (object) array(
	'id' => 7, 'lead_uuid' => 'uuid-7', 'first_utm_source' => 'google', 'first_utm_medium' => 'cpc',
	'first_gclid' => '', 'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => '',
	'first_meta_fbc' => '', 'first_meta_fbp' => '',
);

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-google-adapter.php';

Estandar_Leads_Meta_Adapter::process_queue();
Estandar_Leads_Google_Adapter::process_queue();

$meta_dead = $google_dead = false;
foreach ( $GLOBALS['wpdb']->updates as $update ) {
	if ( ( $update['data']['queue_status'] ?? '' ) !== 'dead' ) { continue; }
	$meta_dead   = $meta_dead || strpos( $update['table'], 'es_meta_queue' ) !== false;
	$google_dead = $google_dead || strpos( $update['table'], 'es_google_queue' ) !== false;
}

$pass = 0; $fail = 0;
function check( $label, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
check( 'Meta worker re-validates and dead-letters non-Meta acquisition before transport', $meta_dead );
check( 'Google worker re-validates and dead-letters tagged-only acquisition before transport', $google_dead );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
