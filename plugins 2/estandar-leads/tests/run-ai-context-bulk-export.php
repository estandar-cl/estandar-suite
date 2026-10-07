<?php
/**
 * Run: php tests/run-ai-context-bulk-export.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function admin_url( $path = '' ) { return 'http://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_action() {}
function add_filter() {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class Estandar_Leads_AI_Context {
	public static function build_for_lead( int $lead_id, string $mode = 'commercial' ): string {
		return "Linea 1\nLinea \"2\" | Ñandú\nJSON: {\"lead_id\":{$lead_id},\"empty\":\"\"}";
	}
}

require dirname( __DIR__ ) . '/includes/class-estandar-leads-admin.php';

$rows = array(
	array(
		'lead_id' => 77,
		'name' => 'José "Pipe" Núñez',
		'email' => 'jose@example.test',
		'phone' => '+56 9 1234 5678',
		'proyecto' => 'alto|sur',
		'operador' => '',
		'estado_comercial' => 'nuevo',
		'calidad' => '',
		'sales_stage' => 'cotización',
	),
);

$csv = fopen( 'php://temp', 'w+' );
$csv_method = new ReflectionMethod( 'Estandar_Leads_Admin', 'export_ai_context_csv_rows' );
$csv_method->invoke( null, $csv, $rows );
rewind( $csv );
$csv_text = stream_get_contents( $csv );
rewind( $csv );
$parsed_header = fgetcsv( $csv, 0, ',', '"', '' );
$parsed_row = fgetcsv( $csv, 0, ',', '"', '' );

$jsonl = fopen( 'php://temp', 'w+' );
$jsonl_method = new ReflectionMethod( 'Estandar_Leads_Admin', 'export_ai_context_jsonl_rows' );
$jsonl_method->invoke( null, $jsonl, $rows );
rewind( $jsonl );
$jsonl_text = trim( stream_get_contents( $jsonl ) );
$jsonl_row = json_decode( $jsonl_text, true );

$expected_header = array( 'lead_id', 'nombre', 'email', 'telefono', 'proyecto', 'operador', 'estado', 'calidad', 'sales_stage', 'contexto_ia' );
$checks = array(
	'csv header' => $parsed_header === $expected_header,
	'csv columns' => is_array( $parsed_row ) && count( $parsed_row ) === count( $expected_header ),
	'csv parseable multiline' => isset( $parsed_row[9] ) && strpos( $parsed_row[9], "\n" ) !== false,
	'csv quotes pipes accents json empty' => isset( $parsed_row[1], $parsed_row[5], $parsed_row[9] )
		&& $parsed_row[1] === 'José "Pipe" Núñez'
		&& $parsed_row[5] === ''
		&& strpos( $parsed_row[4], '|' ) !== false
		&& strpos( $parsed_row[9], '"lead_id":77' ) !== false,
	'csv escaped quotes present' => strpos( $csv_text, '""Pipe""' ) !== false && strpos( $csv_text, 'Linea ""2""' ) !== false,
	'jsonl valid' => is_array( $jsonl_row ) && json_last_error() === JSON_ERROR_NONE,
	'jsonl fields' => is_array( $jsonl_row )
		&& $jsonl_row['lead_id'] === '77'
		&& $jsonl_row['nombre'] === 'José "Pipe" Núñez'
		&& $jsonl_row['email'] === 'jose@example.test'
		&& $jsonl_row['telefono'] === '+56 9 1234 5678'
		&& $jsonl_row['operador'] === ''
		&& strpos( $jsonl_row['contexto_ia'], "\n" ) !== false,
);

$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

exit( $failed === 0 ? 0 : 1 );
