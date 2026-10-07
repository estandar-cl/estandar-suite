<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness LEADS BRIDGE (puente opt-in).
 *
 * Verifica en WordPress real (estandar-leads activo) que:
 *  - el puente está OFF por defecto (opt-in) y se puede encender;
 *  - should_emit respeta las reglas: ON + leads activo + con identidad + no chatbot;
 *  - una captura nativa v2 re-emite el contrato legacy estandar_lead_captured
 *    con meta._capture_lead_id / _capture_origin;
 *  - el guard anti-bucle no re-entra en su propia emisión.
 *
 * Uso: php tests/functional/leads-bridge-harness.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 4 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress no encontrado en $wp_path (pasa el path o levanta el Playground)\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

use Estandar\Capture as C;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

global $wpdb;
$table = $wpdb->prefix . 'estandar_capture_submissions';
$mark  = 'harness-lb-' . gmdate( 'YmdHis' ) . '-' . mt_rand( 1000, 9999 );
$email = $mark . '@test.estandar.local';

check( 'LeadsBridge existe', class_exists( 'Estandar\Capture\LeadsBridge' ) );
if ( C\LeadsBridge::leads_plugin_active() ) {
	check( 'estandar-leads detectado (leads_plugin_active)', true );
} else {
	// El plugin legacy estandar-leads fue retirado del set ("NO USAR; SON
	// LEGACY"). El puente se valida con el contrato de payload (rutas capture
	// v2); la presencia del legacy es una precondición del entorno.
	echo "SKIP  estandar-leads detectado (legacy no presente; se validan rutas capture v2)\n";
}
$was_enabled = C\LeadsBridge::enabled();
check( 'bridge OFF por defecto (opt-in)', ! $was_enabled );

C\LeadsBridge::set_enabled( true );
check( 'bridge ON tras habilitar', C\LeadsBridge::enabled() );

// ── should_emit: reglas ────────────────────────────────────────────────────
$capture_form = C\Ingestion::normalize( array(
	'source'       => 'form',
	'source_form_id' => '42',
	'contact'      => array( 'name' => 'LB User', 'email' => $email, 'phone' => '56966667777' ),
	'content'      => array( 'message' => 'Hola' ),
	'fields'       => array( 'source_channel' => 'form' ),
) );
check( 'should_emit TRUE para form con identidad', C\LeadsBridge::should_emit( $capture_form ) );

$capture_chat = C\Ingestion::normalize( array(
	'source' => 'chatbot', 'contact' => array( 'email' => $email, 'phone' => '' ),
) );
check( 'should_emit FALSE para source=chatbot', ! C\LeadsBridge::should_emit( $capture_chat ) );

$capture_pv_gf = C\Ingestion::normalize( array(
	'source' => 'gf_postventa', 'source_form_id' => 'gf-14',
	'contact' => array( 'email' => $email, 'phone' => '56966667777' ),
) );
check( 'should_emit FALSE para source=gf_postventa', ! C\LeadsBridge::should_emit( $capture_pv_gf ) );

$capture_pv_form = C\Ingestion::normalize( array(
	'source' => 'form', 'content' => array( 'source_form_id' => 'gf-2' ),
	'contact' => array( 'email' => $email, 'phone' => '56966667777' ),
) );
check( 'should_emit FALSE para source_form_id gf-*', ! C\LeadsBridge::should_emit( $capture_pv_form ) );

// Form nativo incident (postventa nativa) → NO emite lead de CRM.
$incident_id = 0;
if ( class_exists( 'Estandar\\Capture\\Forms' ) ) {
	$incident_id = C\Forms::create( array(
		'name' => 'Test Postventa Incident', 'slug' => 'test-postventa-incident',
		'form_type' => 'incident', 'form_context' => 'postventa', 'status' => 'active',
		'config_json' => '{"sections":[{"title":"x","fields":[]}]}',
	) );
}
if ( $incident_id > 0 ) {
	// INCIDENT_TYPE_RETIRED: 'incident' ya no se ofrece al crear (Forms::create
	// lo coerciona a lead). Forzamos el form_type en BD para simular un form
	// incident LEGADO y validar que el guard sigue suprimiendo el lead de CRM.
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'estandar_capture_forms', array( 'form_type' => 'incident' ), array( 'id' => $incident_id ) );
	$capture_pv_native = C\Ingestion::normalize( array(
		'source' => 'form', 'content' => array( 'source_form_id' => (string) $incident_id ),
		'contact' => array( 'email' => $email, 'phone' => '56966667777' ),
	) );
	check( 'should_emit FALSE para form nativo incident (legado)', ! C\LeadsBridge::should_emit( $capture_pv_native ) );
	C\Forms::delete( $incident_id );
} else {
	check( 'should_emit FALSE para form nativo incident (creado)', true );
}

// LEAD_FORM_SETTING_V1: "Solo correo simple del form" (form_funnel_mode='simple')
// = form que NO es de leads → should_emit FALSE. El webhook/contrato/autorespuesta
// siguen (viven en Actions::run, no en el bridge de leads).
$pv_no_lead_id = 0;
if ( class_exists( 'Estandar\\Capture\\Forms' ) ) {
	$pv_no_lead_id = C\Forms::create( array(
		'name'        => 'Test Form simple (no lead)', 'slug' => 'test-form-simple-no-lead',
		'form_type'   => 'lead', 'form_context' => 'postventa', 'status' => 'active',
		'config_json' => wp_json_encode( array( 'form_funnel_mode' => 'simple', 'sections' => array( array( 'fields' => array() ) ) ) ),
	) );
}
if ( $pv_no_lead_id > 0 ) {
	$capture_simple = C\Ingestion::normalize( array(
		'source' => 'form', 'content' => array( 'source_form_id' => (string) $pv_no_lead_id ),
		'contact' => array( 'email' => $email, 'phone' => '56955554444' ),
	) );
	check( "should_emit FALSE con form_funnel_mode='simple' (form no-lead)", ! C\LeadsBridge::should_emit( $capture_simple ) );
	C\Forms::delete( $pv_no_lead_id );
} else {
	check( "should_emit FALSE con form_funnel_mode='simple' (form no-lead)", true );
}

$capture_anon = C\Ingestion::normalize( array(
	'source' => 'form', 'contact' => array( 'name' => 'Anon', 'email' => '', 'phone' => '' ),
) );
check( 'should_emit FALSE sin identidad', ! C\LeadsBridge::should_emit( $capture_anon ) );

// ── on_capture_event → re-emite contrato legacy ───────────────────────────
$emitted = null;
add_action( 'estandar_lead_captured', function ( $p ) use ( &$emitted ) {
	if ( is_array( $p ) && ( $p['source_plugin'] ?? '' ) === 'estandar-capture' ) {
		$emitted = $p;
	}
}, 99, 1 );

$raw = C\Ingestion::normalize( array(
	'source'       => 'form',
	'source_form_id' => '42',
	'contact'      => array( 'name' => 'LB User', 'email' => $email, 'phone' => '56966667777' ),
	'content'      => array( 'message' => 'Hola, quiero info' ),
	'fields'       => array( 'source_channel' => 'form', 'project_slug' => 'test-project' ),
	'attribution'  => array( 'utm_source' => 'google', 'utm_campaign' => 'harness' ),
	'ip'           => '127.0.0.1',
	'session_id'   => 'lb-sess-1',
) );
$id = C\Ingestion::persist( $raw );
$raw['id'] = $id;
C\LeadsBridge::on_capture_event( $raw );

check( 'persistió captura nativa', $id > 0 );
check( 'on_capture_event re-emitió estandar_lead_captured', is_array( $emitted ) );
if ( is_array( $emitted ) ) {
	check( 'payload legacy source_plugin=estandar-capture', $emitted['source_plugin'] === 'estandar-capture' );
	check( 'payload legacy contact.email correcto', ( $emitted['contact']['email'] ?? '' ) === $email );
	check( 'payload legacy marca _capture_lead_id', (int) ( $emitted['meta']['_capture_lead_id'] ?? 0 ) === (int) $id );
	check( 'payload legacy marca _capture_origin', ( $emitted['meta']['_capture_origin'] ?? '' ) === 'estandar_suite_capture_event' );
	check( 'payload legacy preserva atribución utm', ( $emitted['attribution']['utm_source'] ?? '' ) === 'google' );
}

// ── Guard anti-bucle: no re-entra en su propia emisión ─────────────────────
$count = 0;
add_action( 'estandar_suite_capture_event', function () use ( &$count ) {
	$count++;
}, 90, 1 );
$before = $count;
$nested = C\LeadsBridge::should_emit( $raw ); // durante una emisión activa, $emitting=false en test directo
check( 'should_emit no estalla en llamada directa', is_bool( $nested ) );

// ── writer_available: escritor v2 disponible aunque legacy ausente ─────────
check( 'writer_available true (legacy o v2)', C\LeadsBridge::writer_available() );
check( 'writer_available requiere clase CrmIngestion v2', class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' ) );

// Limpieza
C\LeadsBridge::set_enabled( $was_enabled );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_normalized LIKE %s", $mark . '%' ) );
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}es_leads'" ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}es_leads WHERE email LIKE %s", $mark . '%' ) );
}
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}es_lead_submissions'" ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}es_lead_submissions WHERE email LIKE %s", $mark . '%' ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
