<?php
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_email( $v ) { return trim( strtolower( (string) $v ) ); }
function sanitize_title( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { static $n = 0; return 'uuid-' . ++$n; }
function get_current_user_id() { return 42; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function wp_mkdir_p( $path ) { return mkdir( $path, 0777, true ); }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return 'test-uuid'; } }
$events = array(); function do_action( $hook, ...$args ) { global $events; if ( $hook === 'estandar_quote_event' ) { $events[] = $args; } }
$mail_calls = array(); function wp_mail( $to, $subject, $body, $headers, $attachments ) { global $mail_calls; $mail_calls[] = compact( 'to', 'subject', 'body', 'headers', 'attachments' ); return true; }
$options = array(); function get_option( $k, $d = false ) { global $options; return $options[ $k ] ?? $d; }
class Delivery_DB {
	public $prefix = 'wp_'; public $rows = array(); public $insert_id = 0; public $fail_insert = false;
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_row( $prepared, $format ) { $key = $prepared[1][0]; foreach ( $this->rows as $row ) { if ( $row['idempotency_key'] === $key ) { return $row; } } return null; }
	public function insert( $table, $row ) { if ( $this->fail_insert ) { return false; } $row['id'] = ++$this->insert_id; $this->rows[ $row['id'] ] = $row; return true; }
	public function query( $prepared ) { list( $sql, $args ) = $prepared; if ( strpos( $sql, "SET status = 'sending'" ) !== false ) { $id = (int) $args[2]; $allowed = array_slice( $args, 3 ); if ( ! isset( $this->rows[ $id ] ) || ! in_array( $this->rows[ $id ]['status'], $allowed, true ) ) { return 0; } $this->rows[ $id ]['status'] = 'sending'; $this->rows[ $id ]['attempts']++; $this->rows[ $id ]['last_attempt_at'] = $args[0]; $this->rows[ $id ]['updated_at'] = $args[1]; return 1; }
		if ( strpos( $sql, "SET status = 'cancelled'" ) !== false ) { $id = (int) $args[2]; if ( ! isset( $this->rows[ $id ] ) || ! in_array( $this->rows[ $id ]['status'], array( 'pending', 'failed' ), true ) ) { return 0; } $this->rows[ $id ]['status'] = 'cancelled'; $this->rows[ $id ]['cancelled_at'] = $args[0]; $this->rows[ $id ]['updated_at'] = $args[1]; return 1; } return 0; }
	public function update( $table, $values, $where ) { $id = (int) $where['id']; if ( ! isset( $this->rows[ $id ] ) || ( isset( $where['status'] ) && $this->rows[ $id ]['status'] !== $where['status'] ) ) { return 0; } $this->rows[ $id ] = array_merge( $this->rows[ $id ], $values ); return 1; }
}
$GLOBALS['wpdb'] = new Delivery_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-schema.php';
require dirname( __DIR__ ) . '/includes/interface-estandar-quotes-mail-transport.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-delivery-email.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-fake-mail-transport.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-wp-mail-transport.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-delivery-service.php';
$pass = 0; $fail = 0; function check_delivery( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS {$name}\n"; } else { ++$fail; echo "FAIL {$name}\n"; } }
$ticks = 0; Estandar_Quotes_Delivery_Service::set_runtime_for_tests( function () use ( &$ticks ) { return '2026-07-16 12:00:0' . ++$ticks; }, function () { static $n = 0; return 'delivery-' . ++$n; } );
$revision = array( 'revision_uuid' => 'revision-a' ); $artifact = array( 'artifact_uuid' => 'artifact-a', 'revision_uuid' => 'revision-a', 'generation_no' => 1, 'status' => 'ready', 'byte_size' => 1024, 'storage_key' => 'estandar-quotes/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.pdf' );
$metadata = array( 'customer_name' => 'Ana', 'summary' => 'Casa 12', 'executive_name' => 'Equipo', 'expires_at' => '2026-08-01', 'document_reference' => 'R-1', 'operator_email' => 'operator@example.test', 'ignored' => '<script>' );
$delivery = Estandar_Quotes_Delivery_Service::create( $revision, $artifact, array( 'B@example.test', 'a@example.test', 'B@example.test' ), 'Cotización Acacios', 'client', 'es_CL', $metadata );
check_delivery( 'creation persists pending metadata and canonical recipients', $delivery['status'] === 'pending' && $delivery['attempts'] === 0 && json_decode( $delivery['recipients_json'], true ) === array( 'a@example.test', 'b@example.test' ) && strpos( $delivery['metadata_json'], 'ignored' ) === false );
$same = Estandar_Quotes_Delivery_Service::create( $revision, $artifact, array( 'a@example.test', 'B@example.test' ), 'Cotización Acacios', 'client', 'es_CL', $metadata ); check_delivery( 'creation idempotency returns same delivery', $same['id'] === $delivery['id'] && count( $GLOBALS['wpdb']->rows ) === 1 );
$fake = new Estandar_Quotes_Fake_Mail_Transport(); $ok = Estandar_Quotes_Delivery_Service::send( $delivery, $artifact, $fake ); $stored = $GLOBALS['wpdb']->rows[ $delivery['id'] ]; check_delivery( 'success follows pending sending sent with attempt and operator confirmation', $ok && $stored['status'] === 'sent' && $stored['attempts'] === 1 && $stored['last_attempt_at'] !== null && count( $fake->records() ) === 2 && $fake->records()[1]['metadata']['kind'] === 'operator' );
check_delivery( 'atomic claim rejects both stored and stale repeat sends', ! Estandar_Quotes_Delivery_Service::send( $stored, $artifact, $fake ) && ! Estandar_Quotes_Delivery_Service::send( $delivery, $artifact, $fake ) );
$retry = Estandar_Quotes_Delivery_Service::create_new( $revision, $artifact, array( 'a@example.test' ), 'Otra cotización' ); $failing = new Estandar_Quotes_Fake_Mail_Transport( 'failure' ); $failed = ! Estandar_Quotes_Delivery_Service::send( $retry, $artifact, $failing ); $retry_stored = $GLOBALS['wpdb']->rows[ $retry['id'] ]; check_delivery( 'controlled failure ends failed with attempt and safe error', $failed && $retry_stored['status'] === 'failed' && $retry_stored['attempts'] === 1 && $retry_stored['last_error'] === 'transport_failed' );
$exception = Estandar_Quotes_Delivery_Service::create_new( $revision, $artifact, array( 'a@example.test' ), 'Excepción' ); $exception_ok = Estandar_Quotes_Delivery_Service::send( $exception, $artifact, new Estandar_Quotes_Fake_Mail_Transport( 'exception' ) ); check_delivery( 'transport exception is contained and fails delivery', ! $exception_ok && $GLOBALS['wpdb']->rows[ $exception['id'] ]['last_error'] === 'transport_exception' );
$timeout = Estandar_Quotes_Delivery_Service::create_new( $revision, $artifact, array( 'a@example.test' ), 'Timeout' ); $timeout_ok = Estandar_Quotes_Delivery_Service::send( $timeout, $artifact, new Estandar_Quotes_Fake_Mail_Transport( 'timeout' ) ); check_delivery( 'simulated timeout is deterministic controlled failure', ! $timeout_ok && $GLOBALS['wpdb']->rows[ $timeout['id'] ]['status'] === 'failed' );
$resent = Estandar_Quotes_Delivery_Service::resend( $retry_stored, $artifact, new Estandar_Quotes_Fake_Mail_Transport() ); check_delivery( 'explicit resend uses same delivery and increases attempts', $resent && $GLOBALS['wpdb']->rows[ $retry['id'] ]['attempts'] === 2 && $GLOBALS['wpdb']->rows[ $retry['id'] ]['status'] === 'sent' );
$cancel = Estandar_Quotes_Delivery_Service::create_new( $revision, $artifact, array( 'a@example.test' ), 'Cancelar' ); check_delivery( 'cancel transition is atomic and terminal', Estandar_Quotes_Delivery_Service::cancel( $cancel ) && $GLOBALS['wpdb']->rows[ $cancel['id'] ]['status'] === 'cancelled' && ! Estandar_Quotes_Delivery_Service::send( $GLOBALS['wpdb']->rows[ $cancel['id'] ], $artifact, $fake ) );
try { Estandar_Quotes_Delivery_Service::create( array(), $artifact, array( 'a@example.test' ), 'x' ); $bad_revision = false; } catch ( InvalidArgumentException $e ) { $bad_revision = true; }
try { Estandar_Quotes_Delivery_Service::create( $revision, array( 'status' => 'ready' ), array( 'a@example.test' ), 'x' ); $bad_artifact = false; } catch ( InvalidArgumentException $e ) { $bad_artifact = true; }
try { Estandar_Quotes_Delivery_Service::create( $revision, $artifact, array( 'bad' ), 'x' ); $bad_recipient = false; } catch ( InvalidArgumentException $e ) { $bad_recipient = true; }
check_delivery( 'invalid revision artifact and recipient are rejected', $bad_revision && $bad_artifact && $bad_recipient );
$directory = sys_get_temp_dir() . '/estandar-quotes'; @mkdir( $directory ); $file = $directory . '/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.pdf'; file_put_contents( $file, '%PDF-test' ); $wp = new Estandar_Quotes_WP_Mail_Transport(); $wp_result = $wp->send( $delivery, array( 'kind' => 'client' ), $artifact, array( 'a@example.test' ) ); @unlink( $file ); @rmdir( $directory ); check_delivery( 'wp_mail adapter delegates with attachment and template', $wp_result && count( $mail_calls ) === 1 && strpos( $mail_calls[0]['body'], 'cotización' ) !== false );
check_delivery( 'delivery events include created sent failed cancelled', count( array_filter( $events, function ( $event ) { return in_array( $event[0], array( 'QUOTE_DELIVERY_CREATED', 'QUOTE_DELIVERY_SENT', 'QUOTE_DELIVERY_FAILED', 'QUOTE_DELIVERY_CANCELLED' ), true ); } ) ) >= 4 );
exit( $fail ? 1 : 0 );
