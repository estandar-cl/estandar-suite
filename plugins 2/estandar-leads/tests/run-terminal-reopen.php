<?php
/**
 * Run: php tests/run-terminal-reopen.php
 */

define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_textarea_field( $v ) { return sanitize_text_field( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_option( $k, $d = false ) { return $d; }

class Estandar_Leads_Repository {
	public static $events = array();
	public static function project_for_submission( $submission ): string {
		$ctx = json_decode( (string) ( $submission->context_json ?? '' ), true );
		return (string) ( $ctx['project_slug'] ?? '' );
	}
	public static function insert_event( $lead_id, $event_type, $args = array() ) {
		self::$events[] = compact( 'lead_id', 'event_type', 'args' );
		return count( self::$events );
	}
}

class Terminal_Reopen_DB {
	public $prefix = 'wp_';
	public $duplicate = 0;
	public $updates = array();
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function get_row( $q, $mode = null ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'es_lead_classifications' ) !== false ) {
			return array( 'status' => 'INVALID', 'classified_at' => '2026-01-01 00:00:00' );
		}
		if ( strpos( $sql, 'es_lead_submissions' ) !== false ) {
			return (object) array(
				'source_plugin' => 'forms',
				'utm_campaign' => 'old-campaign',
				'session_id' => 'old-session',
				'context_json' => '{"project_slug":"old-project"}',
			);
		}
		return null;
	}
	public function get_var( $q ) { return $this->duplicate; }
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$this->updates[] = compact( 'table', 'data', 'where' );
		return 1;
	}
}

$GLOBALS['wpdb'] = new Terminal_Reopen_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-ingestion.php';

$method = new ReflectionMethod( 'Estandar_Leads_Ingestion', 'maybe_reopen_terminal_lead' );

$current = array(
	'project_slug' => 'new-project',
	'source_plugin' => 'chatbot',
	'utm_campaign' => 'new-campaign',
	'session_id' => 'new-session',
	'submitted_at' => '2026-02-01 00:00:00',
	'source_form_id' => 'chat',
	'source_entry_id' => 123,
	'payload_hash' => 'hash-new',
	'now' => '2026-02-01 00:00:01',
);

$method->invoke( null, 7, 11, array( 'status' => 'INVALID', 'last_classified_at' => '2026-01-01 00:00:00' ), $current );
$checks = array(
	'reopened status' => ( $GLOBALS['wpdb']->updates[0]['data']['status'] ?? '' ) === 'NEW',
	'feedback reopened' => ( $GLOBALS['wpdb']->updates[0]['data']['feedback_completed'] ?? 1 ) === 0,
	'reopen event' => ( Estandar_Leads_Repository::$events[0]['event_type'] ?? '' ) === 'lead_operational_reopened',
);

$GLOBALS['wpdb']->updates = array();
Estandar_Leads_Repository::$events = array();
$method->invoke( null, 7, 12, array( 'status' => 'NEW', 'last_classified_at' => '2026-01-01 00:00:00' ), $current );
$checks['new unchanged'] = empty( $GLOBALS['wpdb']->updates ) && empty( Estandar_Leads_Repository::$events );

$GLOBALS['wpdb']->duplicate = 1;
$method->invoke( null, 7, 13, array( 'status' => 'INVALID', 'last_classified_at' => '2026-01-01 00:00:00' ), $current );
$checks['duplicate unchanged'] = empty( $GLOBALS['wpdb']->updates ) && empty( Estandar_Leads_Repository::$events );

$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed === 0 ? 0 : 1 );
