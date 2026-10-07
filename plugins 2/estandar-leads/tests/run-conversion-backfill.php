<?php
/**
 * Contract test for Conversion Backfill V1.
 * Run: php tests/run-conversion-backfill.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => 'PIX',
	'estandar_leads_meta_access_token' => 'TOK',
);
$GLOBALS['__http'] = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
class WP_Error { public function get_error_message() { return ''; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_post( $u, $a ) { $GLOBALS['__http'][] = array( 'url' => $u, 'args' => $a ); return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function insert( $t, $data, $f = null ) {
		foreach ( $this->rows as $row ) {
			if ( $row->event_id === $data['event_id'] ) {
				return false;
			}
		}
		$data['id'] = ++$this->insert_id;
		$this->rows[] = (object) $data;
		return 1;
	}
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		foreach ( $this->rows as $row ) {
			if ( (int) $row->id === (int) $where['id'] ) {
				foreach ( $data as $k => $v ) {
					$row->$k = $v;
				}
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
				if ( $row->event_id === $event_id ) {
					return 1;
				}
			}
		}
		return 0;
	}
	public function get_results( $p, $output = null ) {
		$q = is_array( $p ) ? $p['q'] : $p;
		if ( strpos( $q, 'GROUP BY status' ) !== false ) {
			$counts = array();
			foreach ( $this->rows as $row ) {
				$counts[ $row->status ] = ( $counts[ $row->status ] ?? 0 ) + 1;
			}
			$out = array();
			foreach ( $counts as $status => $total ) {
				$out[] = array( 'status' => $status, 'total' => $total );
			}
			return $out;
		}
		if ( strpos( $q, "status = 'pending'" ) !== false ) {
			return array_values( array_filter( $this->rows, function ( $row ) { return $row->status === 'pending'; } ) );
		}
		return array_reverse( $this->rows );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-conversion-backfill-service.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

$buyers = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$approved = $i <= 14;
	$buyers[] = array(
		'status'            => $approved ? 'approved' : 'pending_manual_review',
		'buyer_uuid'        => sprintf( 'buyer-%02d', $i ),
		'lead_uuid'         => sprintf( 'lead-%02d', $i ),
		'purchase_value'    => 181038075,
		'currency'          => 'CLP',
		'purchase_metadata' => array( 'event_time' => '2026-01-15T12:00:00-03:00' ),
		'lead'              => array(
			'id'         => $i,
			'lead_uuid'  => sprintf( 'lead-%02d', $i ),
			'email'      => sprintf( 'buyer%02d@example.com', $i ),
			'phone'      => '+56912345678',
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'first_fbclid' => 'fb-paid-' . $i,
		),
	);
}

$service = new Estandar_Leads_Conversion_Backfill_Service();
$enqueued = $service->enqueue( $buyers );
check( 'benchmark enqueue creates exactly 14 approved buyers', $enqueued['created'] === 14 && $enqueued['skipped'] === 6 );

$dispatched = $service->dispatch();
check( 'benchmark dispatch sends exactly 14 purchases', $dispatched['sent'] === 14 && $dispatched['failed'] === 0 && count( $GLOBALS['__http'] ) === 14 );
check( 'audit has 14 sent rows', ( $service->status()['sent'] ?? 0 ) === 14 );

$event_ids = array_map( function ( $row ) { return $row->event_id; }, $GLOBALS['wpdb']->rows );
check( 'zero duplicated event_id', count( $event_ids ) === count( array_unique( $event_ids ) ) );

$service->enqueue( $buyers );
$again = $service->dispatch();
check( 'repeated execution creates zero duplicate dispatches', $again['sent'] === 0 && count( $GLOBALS['__http'] ) === 14 );

$payload = json_decode( $GLOBALS['__http'][0]['args']['body'], true )['data'][0] ?? array();
check( 'Purchase payload includes value/currency/event_id', ( $payload['event_name'] ?? '' ) === 'Purchase' && (float) ( $payload['custom_data']['value'] ?? 0 ) === 181038075.0 && ( $payload['custom_data']['currency'] ?? '' ) === 'CLP' && isset( $payload['event_id'] ) );
check( 'payload hashes persisted', strlen( (string) $GLOBALS['wpdb']->rows[0]->payload_hash ) === 64 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
