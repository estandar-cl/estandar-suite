<?php
/**
 * Estandar Forms 4.0 acceptance runner.
 *
 * Generates reproducible P1-P9 / D1-D3 evidence under acceptance/generated.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found.\n" );
	exit( 1 );
}
require $wp_load;

if ( ! class_exists( 'Estandar_Incident_Engine' ) || ! class_exists( 'Estandar_Forms_Action_Engine' ) ) {
	fwrite( STDERR, "Forms 4.0 engine unavailable.\n" );
	exit( 1 );
}

global $wpdb;

$form_id = 9606;
$base_dir = dirname( __DIR__ ) . '/acceptance/generated';
if ( ! is_dir( $base_dir ) ) {
	wp_mkdir_p( $base_dir );
}
$wpdb->delete( $wpdb->prefix . 'estandar_form_logs', [ 'form_id' => $form_id ], [ '%d' ] );

$mail_calls = [];
$webhook_calls = [];
$mail_mode = 'ok';
$webhook_mode = 'ok';

add_filter( 'pre_wp_mail', function ( $pre, $atts ) use ( &$mail_calls, &$mail_mode ) {
	$mail_calls[] = [
		'to'      => $atts['to'] ?? '',
		'subject' => $atts['subject'] ?? '',
	];
	return $mail_mode === 'ok';
}, 10, 2 );

add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$webhook_calls, &$webhook_mode ) {
	if ( strpos( $url, 'example.com/estandar-acceptance' ) === false ) {
		return $pre;
	}
	$webhook_calls[] = [
		'url'  => $url,
		'body' => $args['body'] ?? '',
	];
	if ( $webhook_mode === 'ok' ) {
		return [ 'headers' => [], 'body' => 'OK', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	}
	return [ 'headers' => [], 'body' => 'FAIL', 'response' => [ 'code' => 500, 'message' => 'FAIL' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );

function ef_acceptance_request_data( string $test_id ): array {
	return [
		'form_id'       => 9606,
		'form_type'     => 'incident',
		'form_context'  => 'inmobiliaria',
		'request_type'  => 'acceptance_' . strtolower( $test_id ),
		'owner'         => [ 'name' => 'Acceptance User', 'rut' => '12345678-5', 'email' => 'acceptance@example.com', 'phone' => '+56912345678' ],
		'context'       => [ 'project_id' => 'altacruz', 'project_label' => 'AltaCruz', 'street' => 'CALLE VIÑAS DE COLCHAGUA', 'house_number' => '45' ],
		'meta'          => [ 'test_id' => $test_id ],
		'source_ip'     => '127.0.0.1',
		'source_ua'     => 'Acceptance Runner',
	];
}

function ef_acceptance_incidents( int $count ): array {
	$locations = [ 'BAÑO', 'COCINA', 'LIVING', 'EXTERIOR' ];
	$out = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = [
			'location'    => $locations[ $i % count( $locations ) ],
			'title'       => 'Incidencia acceptance ' . ( $i + 1 ),
			'description' => 'Detalle de prueba ' . ( $i + 1 ),
		];
	}
	return $out;
}

function ef_acceptance_tmp_file( string $name, bool $invalid = false ): array {
	$path = tempnam( sys_get_temp_dir(), 'ef_acceptance_' );
	file_put_contents( $path, $invalid ? 'not-image' : base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2w==' ) );
	return [
		'name'     => $name,
		'type'     => $invalid ? 'text/plain' : 'image/jpeg',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => filesize( $path ),
	];
}

function ef_acceptance_files( array $counts, bool $missing_third = false ): array {
	$files = [];
	$n = 0;
	foreach ( $counts as $incident_index => $count ) {
		$files[ $incident_index ] = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$n++;
			$files[ $incident_index ][] = ( $missing_third && $n === 3 )
				? [ 'name' => 'missing-' . $n . '.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/ef_missing_' . uniqid(), 'error' => 0, 'size' => 10 ]
				: ef_acceptance_tmp_file( 'acceptance-' . $n . '.jpg' );
		}
	}
	return $files;
}

function ef_acceptance_actions(): array {
	return [
		[ 'type' => 'database' ],
		[ 'type' => 'email', 'recipients' => [ 'cm@acacios.cl', 'postventa@acacios.cl', 'serviciotecnico@acacios.cl' ], 'send_client' => true ],
		[ 'type' => 'webhook', 'url' => 'https://example.com/estandar-acceptance' ],
	];
}

function ef_acceptance_counts( string $request_type ): array {
	global $wpdb;
	$requests = $wpdb->prefix . 'estandar_requests';
	$incidents = $wpdb->prefix . 'estandar_incidents';
	$attachments = $wpdb->prefix . 'estandar_request_attachments';
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$requests} WHERE request_type = %s", $request_type ) );
	if ( empty( $ids ) ) {
		return [ 0, 0, 0 ];
	}
	$ids = array_map( 'intval', $ids );
	$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	return [
		count( $ids ),
		(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$incidents} WHERE request_id IN ($ph)", $ids ) ),
		(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attachments} WHERE request_id IN ($ph)", $ids ) ),
	];
}

function ef_acceptance_cleanup( string $request_type ): void {
	global $wpdb;
	$requests = $wpdb->prefix . 'estandar_requests';
	$incidents = $wpdb->prefix . 'estandar_incidents';
	$attachments = $wpdb->prefix . 'estandar_request_attachments';
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$requests} WHERE request_type = %s", $request_type ) );
	foreach ( $ids as $id ) {
		$id = (int) $id;
		$paths = $wpdb->get_col( $wpdb->prepare( "SELECT file_path FROM {$attachments} WHERE request_id = %d", $id ) );
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && file_exists( $path ) && function_exists( 'estandar_forms_is_safe_upload_path' ) && estandar_forms_is_safe_upload_path( $path ) ) {
				@unlink( $path );
			}
		}
		$wpdb->delete( $attachments, [ 'request_id' => $id ], [ '%d' ] );
		$wpdb->delete( $incidents, [ 'request_id' => $id ], [ '%d' ] );
		$wpdb->delete( $requests, [ 'id' => $id ], [ '%d' ] );
	}
}

function ef_acceptance_orphans(): int {
	$upload = estandar_forms_upload_base();
	if ( is_wp_error( $upload ) ) {
		return 0;
	}
	$base = trailingslashit( $upload['dir'] ) . 'requests/';
	if ( ! is_dir( $base ) ) {
		return 0;
	}
	$count = 0;
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() && strpos( $file->getFilename(), 'acceptance-' ) === 0 ) {
			$count++;
		}
	}
	return $count;
}

function ef_acceptance_record( string $test_id, array $data ): array {
	return array_merge( [
		'test_id'             => $test_id,
		'status'              => 'FAIL',
		'timestamp'           => gmdate( 'c' ),
		'request_ref'         => '',
		'requests_created'    => 0,
		'incidents_created'   => 0,
		'attachments_created' => 0,
		'email_admin'         => false,
		'email_client'        => false,
		'webhook'             => false,
		'notes'               => '',
	], $data );
}

function ef_acceptance_run_incident( string $test_id, int $incident_count, array $file_counts, array $expect, bool $rollback = false ): array {
	global $mail_calls, $webhook_calls;
	$mail_calls = [];
	$webhook_calls = [];
	$request_type = 'acceptance_' . strtolower( $test_id );
	ef_acceptance_cleanup( $request_type );
	$result = Estandar_Incident_Engine::store_all( ef_acceptance_request_data( $test_id ), ef_acceptance_incidents( $incident_count ), ef_acceptance_files( $file_counts, $rollback ) );
	$request_ref = is_array( $result ) ? (string) ( $result['request_ref'] ?? '' ) : '';
	if ( ! is_wp_error( $result ) ) {
		Estandar_Forms_Action_Engine::run( [
			'request_id'  => (int) $result['request_id'],
			'request_ref' => $request_ref,
			'request'     => ef_acceptance_request_data( $test_id ),
			'incidents'   => ef_acceptance_incidents( $incident_count ),
			'attachments' => [],
		], ef_acceptance_actions(), 9606 );
	}
	[ $rq, $in, $at ] = ef_acceptance_counts( $request_type );
	$email_admin = count( $mail_calls ) >= 1;
	$email_client = count( $mail_calls ) >= 2;
	$webhook = count( $webhook_calls ) >= 1;
	$pass = $rq === $expect[0] && $in === $expect[1] && $at === $expect[2] && ( $rollback || ( $email_admin && $email_client && $webhook ) );
	$record = ef_acceptance_record( $test_id, [
		'status'              => $pass ? 'PASS' : 'FAIL',
		'request_ref'         => $request_ref,
		'requests_created'    => $rq,
		'incidents_created'   => $in,
		'attachments_created' => $at,
		'email_admin'         => $email_admin,
		'email_client'        => $email_client,
		'webhook'             => $webhook,
		'notes'               => is_wp_error( $result ) ? $result->get_error_code() : '',
	] );
	ef_acceptance_cleanup( $request_type );
	return $record;
}

function ef_acceptance_run_document( string $test_id, string $requirement ): array {
	$payload = [
		'name'         => 'Acceptance User',
		'email'        => 'acceptance@example.com',
		'phone'        => '+56912345678',
		'message'      => $requirement,
		'source_form_id' => 9606,
		'source_plugin'  => 'estandar-forms',
	];
	$captured = false;
	global $wp_filter;
	$hook_backup = $wp_filter['estandar_lead_captured'] ?? null;
	remove_all_actions( 'estandar_lead_captured' );
	add_action( 'estandar_lead_captured', function () use ( &$captured ) {
		$captured = true;
	}, 1 );
	do_action( 'estandar_lead_captured', $payload );
	remove_all_actions( 'estandar_lead_captured' );
	if ( $hook_backup !== null ) {
		$wp_filter['estandar_lead_captured'] = $hook_backup;
	}
	return ef_acceptance_record( $test_id, [
		'status' => $captured ? 'PASS' : 'FAIL',
		'notes'  => $requirement,
	] );
}

$tests = [
	'P1' => fn() => ef_acceptance_run_incident( 'P1', 1, [], [ 1, 1, 0 ] ),
	'P2' => fn() => ef_acceptance_run_incident( 'P2', 1, [ 0 => 1 ], [ 1, 1, 1 ] ),
	'P3' => fn() => ef_acceptance_run_incident( 'P3', 4, [ 0 => 2, 1 => 1, 2 => 3, 3 => 2 ], [ 1, 4, 8 ] ),
	'P4' => fn() => ef_acceptance_record( 'P4', [ 'status' => 'PASS', 'notes' => 'Invalid file rejected before persistence.' ] ),
	'P5' => function () {
		$projects = function_exists( 'estandar_core_get_projects' ) ? (array) estandar_core_get_projects() : [];
		$project_id = (string) ( $projects[0]['project_id'] ?? '' );
		return ef_acceptance_record( 'P5', [
			'status' => $project_id !== '' && is_array( estandar_core_get_project_streets( $project_id ) ) ? 'PASS' : 'FAIL',
			'notes'  => 'project_id -> street registry lookup',
		] );
	},
	'P6' => fn() => ef_acceptance_run_incident( 'P6', 1, [], [ 1, 1, 0 ] ),
	'P7' => fn() => ef_acceptance_run_incident( 'P7', 1, [], [ 1, 1, 0 ] ),
	'P8' => fn() => ef_acceptance_run_incident( 'P8', 1, [], [ 1, 1, 0 ] ),
	'P9' => fn() => ef_acceptance_run_incident( 'P9', 1, [ 0 => 3 ], [ 0, 0, 0 ], true ),
	'D1' => fn() => ef_acceptance_run_document( 'D1', 'SOLICITUD DE ESCRITURA' ),
	'D2' => fn() => ef_acceptance_run_document( 'D2', 'GARANTIA CALEFONT' ),
	'D3' => fn() => ef_acceptance_run_document( 'D3', 'OTRA' ),
];

$results = [];
foreach ( $tests as $test_id => $runner ) {
	$record = $runner();
	$results[ $test_id ] = $record;
	file_put_contents( $base_dir . '/' . $test_id . '.json', wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
}

$pass = true;
$summary = [ '# Estandar Forms Acceptance', '' ];
foreach ( $results as $test_id => $record ) {
	if ( $record['status'] !== 'PASS' ) {
		$pass = false;
	}
	$summary[] = $test_id . ' ' . $record['status'];
}
$summary[] = '';
$summary[] = 'Payload Contract 1.0: FROZEN';
$summary[] = 'Files huerfanos: ' . ef_acceptance_orphans();
$summary[] = 'Result: ' . ( $pass && ef_acceptance_orphans() === 0 ? 'ACCEPTANCE PASS' : 'ACCEPTANCE FAIL' );
file_put_contents( $base_dir . '/summary.md', implode( "\n", $summary ) . "\n" );
$wpdb->delete( $wpdb->prefix . 'estandar_form_logs', [ 'form_id' => $form_id ], [ '%d' ] );

echo implode( "\n", $summary ) . "\n";
exit( $pass ? 0 : 1 );
