<?php
/**
 * Regression: FORMS_PIPELINE_OBSERVABILITY_V1 + FORMS_ORPHAN_RECONCILIATION_V1.
 *
 * Caso de producción cubierto (entry #521 / debug_ref EF-68277F24A9):
 *  - toda respuesta de error de pipeline deja telemetría correlacionable por
 *    debug_ref con etapa + form_id + entry_id + project_slug;
 *  - los errores de validación de campos NO generan ruido de telemetría;
 *  - una entry huérfana (persistida sin submission canónica) puede
 *    re-emitirse de forma idempotente (reemit_entry);
 *  - la confirmación de ingesta (last_ingested) distingue persistencia
 *    confirmada, duplicado idempotente y no-confirmada.
 *
 * Mismo patrón que run-meta-lead-response.php: clases REALES + stubs WP.
 * Run: php tests/run-pipeline-failure-observability.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__json'] = null;
$GLOBALS['__telemetry'] = [];
$GLOBALS['__captured_payloads'] = [];
$GLOBALS['__on_lead_captured'] = null;

class JsonSent extends Exception {}
class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function add_action() {}
function add_filter() {}
function has_action() { return 10; }
function do_action( $hook, ...$args ) {
	if ( $hook === 'estandar_lead_captured' ) {
		$GLOBALS['__captured_payloads'][] = $args[0];
		if ( is_callable( $GLOBALS['__on_lead_captured'] ) ) {
			( $GLOBALS['__on_lead_captured'] )( $args[0] );
		}
	}
}
function get_option( $k, $d = false ) { return $d; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9\-]/', '', strtolower( str_replace( ' ', '-', (string) $s ) ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ?: ''; }
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
function remove_accents( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function esc_url_raw( $u ) { return trim( (string) $u ); }
function wp_generate_uuid4() { return sprintf( '%08x-0000-4000-8000-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffffffffffff ) ); }
function wp_send_json_success( $data ) { $GLOBALS['__json'] = [ 'success' => true, 'data' => $data ]; throw new JsonSent(); }
function wp_send_json_error( $data, $code = 0 ) { $GLOBALS['__json'] = [ 'success' => false, 'data' => $data ]; throw new JsonSent(); }
function estandar_forms_telemetry_event( $severity, $code, $message, $context = [] ) {
	$GLOBALS['__telemetry'][] = [ 'severity' => $severity, 'code' => $code, 'context' => $context ];
}
// Contract-level stub del resolver de routing (la función real vive en
// helpers.php, no cargable standalone). Mismo shape de salida.
function estandar_forms_routing_context( $raw ): array {
	$d = json_decode( (string) $raw, true ) ?: [];
	$r = isset( $d['routing'] ) && is_array( $d['routing'] ) ? $d['routing'] : $d;
	return [
		'mode'              => (string) ( $r['mode'] ?? 'none' ),
		'project_slug'      => (string) ( $r['project_slug'] ?? '' ),
		'project_name'      => (string) ( $r['project_name'] ?? '' ),
		'project_area'      => (string) ( $r['project_area'] ?? '' ),
		'intent'            => (string) ( $r['intent'] ?? '' ),
		'selector_projects' => [],
	];
}

// ── Fake wpdb programable ────────────────────────────────────────────────────
class Fake_Wpdb {
	public $prefix = 'wp_';
	public $rows = [];        // substring de tabla => row|null
	public $vars = [];        // substring de tabla => scalar|null
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[ds]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_row( $sql ) {
		foreach ( $this->rows as $needle => $row ) {
			if ( strpos( $sql, $needle ) !== false ) { return $row; }
		}
		return null;
	}
	public function get_var( $sql ) {
		foreach ( $this->vars as $needle => $var ) {
			if ( strpos( $sql, $needle ) !== false ) { return $var; }
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

// ── Clases REALES ────────────────────────────────────────────────────────────
require_once dirname( __DIR__, 2 ) . '/estandar-leads/includes/class-estandar-leads-ingestion.php';
require_once dirname( __DIR__ ) . '/includes/class-forms.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function set_last_ingested( $value ) {
	$ref = new ReflectionProperty( 'Estandar_Leads_Ingestion', 'last_ingested' );
	$ref->setValue( null, $value );
}
function set_failure_context( $value ) {
	$ref = new ReflectionProperty( 'Estandar_Forms_Handler', 'failure_context' );
	$ref->setValue( null, $value );
}
function mark_stage( $stage, $context ) {
	$m = new ReflectionMethod( 'Estandar_Forms_Handler', 'mark_failure_stage' );
	$m->invoke( null, $stage, $context );
}
function respond_error( $messages, $field_errors = [] ) {
	$GLOBALS['__json'] = null;
	$GLOBALS['__telemetry'] = [];
	$m = new ReflectionMethod( 'Estandar_Forms_Handler', 'redirect_with_feedback' );
	try {
		$m->invoke( null, '/gracias', 5, 'error', $messages, [], $field_errors );
	} catch ( JsonSent $e ) {}
	return $GLOBALS['__json'];
}
$_POST['estandar_ajax'] = '1';

// ── 1. Correlación debug_ref ↔ telemetría (caso EF-68277F24A9) ──────────────
set_failure_context( null );
mark_stage( 'lead_pipeline_exception', [ 'form_id' => 5, 'entry_id' => 521, 'project_slug' => 'alto-jaramillo', 'exception' => 'RuntimeException' ] );
$json = respond_error( [ 'No fue posible enviar el formulario. Intenta nuevamente.' ] );
$events = array_values( array_filter( $GLOBALS['__telemetry'], fn( $e ) => $e['code'] === 'FORMS_SUBMISSION_ERROR_RESPONSE' ) );
check( '1a error response emite FORMS_SUBMISSION_ERROR_RESPONSE', count( $events ) === 1 );
check( '1b debug_ref del JSON == debug_ref de telemetría', $events && $json['data']['debug_ref'] === $events[0]['context']['debug_ref'] );
check( '1c etapa exacta registrada', $events && $events[0]['context']['stage'] === 'lead_pipeline_exception' );
check( '1d entry_id + form_id + project_slug presentes', $events
	&& $events[0]['context']['entry_id'] === 521
	&& $events[0]['context']['form_id'] === 5
	&& $events[0]['context']['project_slug'] === 'alto-jaramillo' );
check( '1e code submission_error preservado hacia el cliente', $json['data']['code'] === 'submission_error' );

// Sin etapa marcada, un submission_error residual igual queda registrado.
set_failure_context( null );
$json = respond_error( [ 'No fue posible enviar el formulario. Intenta nuevamente.' ] );
$events = array_values( array_filter( $GLOBALS['__telemetry'], fn( $e ) => $e['code'] === 'FORMS_SUBMISSION_ERROR_RESPONSE' ) );
check( '1f submission_error sin etapa marcada → stage=unknown registrado', $events && $events[0]['context']['stage'] === 'unknown' );

// Errores de validación de campos: sin ruido de telemetría.
set_failure_context( null );
respond_error( [ 'El campo "Email" es obligatorio.' ], [ 'email' => 'El campo "Email" es obligatorio.' ] );
$events = array_values( array_filter( $GLOBALS['__telemetry'], fn( $e ) => $e['code'] === 'FORMS_SUBMISSION_ERROR_RESPONSE' ) );
check( '1g validation error NO emite telemetría de pipeline', count( $events ) === 0 );

// ── 2. Confirmación de ingesta (semántica parcial vs total) ─────────────────
$confirmed = new ReflectionMethod( 'Estandar_Forms_Handler', 'confirmed_ingestion' );
set_last_ingested( null );
check( '2a sin snapshot → ingesta NO confirmada', $confirmed->invoke( null ) === null );
set_last_ingested( [ 'lead_id' => 9, 'lead_uuid' => 'ffffffff-0000-4000-8000-000000000001' ] );
check( '2b snapshot post-COMMIT → ingesta confirmada', is_array( $confirmed->invoke( null ) ) );
set_last_ingested( [ 'lead_id' => 9, 'lead_uuid' => 'ffffffff-0000-4000-8000-000000000001', 'duplicate' => true ] );
check( '2c duplicado idempotente → ingesta confirmada (no es fallo)', is_array( $confirmed->invoke( null ) ) );

// Duplicado no re-emite Meta browser event (comportamiento previo preservado).
check( '2d duplicado no emite meta_lead_event_id', Estandar_Forms_Handler::meta_lead_event_id_for_response() === '' );
set_last_ingested( [ 'lead_id' => 9, 'lead_uuid' => 'ffffffff-0000-4000-8000-000000000001' ] );
check( '2e captura nueva sí emite meta_lead_event_id', Estandar_Forms_Handler::meta_lead_event_id_for_response() === 'ffffffff-0000-4000-8000-000000000001:CAPTURED' );

// ── 3. Reconciliación de entry huérfana ──────────────────────────────────────
$wpdb = $GLOBALS['wpdb'];
$entry_row = (object) [
	'id'         => 521,
	'form_id'    => 5,
	'status'     => 'active',
	'created_at' => '2026-07-10 15:00:00',
	'data_json'  => json_encode( [
		'nombre'   => 'Luis Rodrigo',
		'apellido' => 'Valdés Muñoz',
		'email'    => 'rodrigovaldes@me.com',
		'telefono' => '984291014',
	] ),
];
$form_row = (object) [
	'id'             => 5,
	'fields_json'    => json_encode( [
		[ 'name' => 'nombre', 'type' => 'text', 'label' => 'Nombre' ],
		[ 'name' => 'email', 'type' => 'email', 'label' => 'Email' ],
		[ 'name' => 'telefono', 'type' => 'tel', 'label' => 'Teléfono' ],
	] ),
	'context_schema' => json_encode( [ 'routing' => [ 'mode' => 'fixed_project', 'project_slug' => 'alto-jaramillo', 'project_name' => 'Alto Jaramillo', 'project_area' => 'Inmobiliaria' ] ] ),
];

// 3a entry inexistente
$wpdb->rows = []; $wpdb->vars = [];
$r = Estandar_Forms_Handler::reemit_entry( 999 );
check( '3a entry inexistente → WP_Error forms_entry_not_found', is_wp_error( $r ) && $r->get_error_code() === 'forms_entry_not_found' );

// 3b entry ya vinculada → idempotencia
$wpdb->rows = [ 'estandar_form_entries' => $entry_row, 'estandar_forms WHERE' => $form_row ];
$wpdb->vars = [ 'es_lead_submissions' => 88 ];
$r = Estandar_Forms_Handler::reemit_entry( 521 );
check( '3b entry ya vinculada → WP_Error forms_entry_already_linked (idempotente)', is_wp_error( $r ) && $r->get_error_code() === 'forms_entry_already_linked' );

// 3c happy path: re-emisión con payload completo + confirmación
$wpdb->vars = [ 'es_lead_submissions' => null ];
$GLOBALS['__captured_payloads'] = [];
$GLOBALS['__on_lead_captured'] = function ( $payload ) {
	set_last_ingested( [ 'lead_id' => 77, 'lead_uuid' => 'ffffffff-0000-4000-8000-000000000077' ] );
};
set_last_ingested( null );
$r = Estandar_Forms_Handler::reemit_entry( 521 );
$p = $GLOBALS['__captured_payloads'][0] ?? null;
check( '3c re-emisión dispara estandar_lead_captured', is_array( $p ) );
check( '3d payload enlaza la entry (source_entry_id=521, form 5)', $p && $p['meta']['source_entry_id'] === 521 && $p['content']['source_form_id'] === 5 );
check( '3e payload marcado como reconciliation', $p && $p['meta']['reconciliation'] === true );
check( '3f contacto reconstruido desde data_json', $p
	&& $p['contact']['name'] === 'Luis Rodrigo'
	&& $p['contact']['email'] === 'rodrigovaldes@me.com'
	&& $p['contact']['phone'] === '984291014' );
check( '3g fixed_project recuperado desde context_schema', $p && $p['context']['project_slug'] === 'alto-jaramillo' );
check( '3h submitted_at = timestamp original de la entry', $p && strpos( (string) $p['submitted_at'], '2026-07-10T15:00:00' ) === 0 );
check( '3i sin señales de spam sintéticas (no suspect)', $p && $p['spam']['honeypot_filled'] === false && $p['spam']['seconds_to_submit'] === null && $p['request']['user_agent'] !== '' );
check( '3j retorna snapshot confirmado {lead_id:77}', is_array( $r ) && $r['lead_id'] === 77 );

// 3k ingesta no confirma → WP_Error explícito (no éxito falso)
$GLOBALS['__on_lead_captured'] = null;
set_last_ingested( null );
$r = Estandar_Forms_Handler::reemit_entry( 521 );
check( '3k sin confirmación de ingesta → WP_Error forms_reconciliation_not_confirmed', is_wp_error( $r ) && $r->get_error_code() === 'forms_reconciliation_not_confirmed' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
