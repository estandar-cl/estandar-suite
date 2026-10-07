<?php
/**
 * ESTANDAR SUITE v2 — Core · Harness EVENT BUS (S15).
 *
 * Verifica en WordPress real (Playground local) que los eventos planificados
 * del catálogo que faltaban ya emiten en su origen y llegan al bus:
 *  - el bus (Destinations::dispatch_event) encola `survey_completed` y
 *    `quote_issued` a un destino mapeado (fila en estandar_core_deliveries);
 *  - el emisor de encuesta (SurveyHandler::emit_completed) dispara el hook
 *    semántico y el bus lo encola;
 *  - el emisor de cotización (Quotes::emit_issued + issued_payload) dispara el
 *    hook y el bus lo encola (payload mínimo, sin email).
 *
 * Uso: php tests/functional/event-bus-harness.php [wp-path]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 4 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress no encontrado en $wp_path (levanta el Playground)\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

check( 'Destinations cargada (core)', class_exists( 'Estandar\Core\Destinations' ) );
check( 'SurveyHandler cargada (capture)', class_exists( 'Estandar\Capture\SurveyHandler' ) );
check( 'Quotes cargada (flow)', class_exists( 'Estandar\Flow\Quotes' ) );

global $wpdb;
$prefix = $wpdb->prefix;
$slug   = 'evbus-' . substr( md5( (string) mt_rand() ), 0, 8 );
$had_map = get_option( 'estandar_suite_core_event_map', array() );

// Limpieza previa de residuos del harness.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$prefix}estandar_core_deliveries WHERE event_type IN ('survey_completed','quote_issued') AND entity_type IN ('survey','quote')"
) );

// ── 1. Destino "sink" (puerto local cerrado → el drain falla al instante y la
// fila queda visible con status retry; sin red externa) ─────────────────────
$did = \Estandar\Core\Destinations::register_destination( $slug, 'webhook', 'http://127.0.0.1:9/' . $slug );
check( '1a destino webhook registrado', $did > 0 );

if ( $did > 0 ) {
	\Estandar\Core\Destinations::map_events( $slug, array( 'survey_completed', 'quote_issued' ) );

	// ── 2. Emisor de encuesta: seam → hook → bus encola ─────────────────────
	$got = array();
	add_action( 'estandar_suite_survey_completed', function ( $p ) use ( &$got ) { $got[] = $p; }, 99, 1 );
	\Estandar\Capture\SurveyHandler::emit_completed( 424242, 7, 'survey' );
	check( '2a survey seam emitió hook semántico', count( $got ) === 1 );
	check( '2b payload mínimo survey', (int) ( $got[0]['session_id'] ?? 0 ) === 424242 && (int) ( $got[0]['form_id'] ?? 0 ) === 7 && ( $got[0]['status'] ?? '' ) === 'completed' );
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$prefix}estandar_core_deliveries WHERE event_type = 'survey_completed' AND entity_type = 'survey' AND entity_id = %d LIMIT 1",
		424242
	), ARRAY_A );
	check( '2c bus encoló survey_completed', is_array( $row ) && in_array( $row['status'], array( 'pending', 'retry' ), true ) && (int) $row['destination_id'] === (int) $did );

	// ── 3. Emisor de cotización: issued_payload + seam → hook → bus ─────────
	$pl = \Estandar\Flow\Quotes::issued_payload( array(
		'id' => 999, 'quote_uuid' => 'abc', 'title' => 'COT test', 'amount' => 123.4, 'currency' => 'CLP',
	) );
	check( '3a issued_payload normaliza status a sent', (int) $pl['id'] === 999 && $pl['status'] === 'sent' && abs( (float) $pl['amount'] - 123.4 ) < 0.001 );

	$gotq = array();
	add_action( 'estandar_suite_quote_issued', function ( $p ) use ( &$gotq ) { $gotq[] = $p; }, 99, 1 );
	\Estandar\Flow\Quotes::emit_issued( array(
		'id' => 31337, 'quote_uuid' => 'q-' . gmdate( 'YmdHis' ), 'title' => 'T', 'amount' => 500, 'currency' => 'CLP',
	) );
	check( '3b quote seam emitió hook semántico', count( $gotq ) === 1 && (int) ( $gotq[0]['id'] ?? 0 ) === 31337 );
	$rowq = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$prefix}estandar_core_deliveries WHERE event_type = 'quote_issued' AND entity_type = 'quote' AND entity_id = %d LIMIT 1",
		31337
	), ARRAY_A );
	check( '3c bus encoló quote_issued', is_array( $rowq ) && in_array( $rowq['status'], array( 'pending', 'retry' ), true ) );

	// ── Limpieza (destino + filas + opción) ─────────────────────────────────
	$wpdb->delete( $prefix . 'estandar_core_destinations', array( 'id' => $did ), array( '%d' ) );
}
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$prefix}estandar_core_deliveries WHERE event_type IN ('survey_completed','quote_issued') AND entity_type IN ('survey','quote')"
) );
if ( is_array( $had_map ) && $had_map ) {
	update_option( 'estandar_suite_core_event_map', $had_map, false );
} else {
	delete_option( 'estandar_suite_core_event_map' );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
