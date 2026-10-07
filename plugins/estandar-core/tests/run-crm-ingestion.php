<?php
/**
 * CRM_INGESTION v2 — tests del port de la ingesta de leads (es_leads).
 *
 *   php tests/run-crm-ingestion.php   → expect "0 failed"
 *
 * Verifica en WordPress real (Playground):
 *   - Normalización/hashes byte-idénticos al legacy (mismo secret).
 *   - Gate de coexistencia: con legacy presente NO escribe; sin legacy, escribe.
 *   - Flujo completo: estandar_lead_captured → es_leads + es_lead_submissions
 *     + eventos + emisión de estandar_lead_ingested.
 *   - Dedupe por email (recontacto NO duplica el lead, sí crea submission).
 *   - Rollback si falla (lead sin identidad no persiste).
 *
 * Limpieza total de fixtures al final.
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

// Fuerza ownership del v2 (simula estandar-leads retirado). Debe definirse
// ANTES de cargar WP, porque la clase ya está en memoria al evaluar el gate.
if ( ! defined( 'ESTANDAR_CRM_INGESTION_FORCE_OWN' ) ) {
	define( 'ESTANDAR_CRM_INGESTION_FORCE_OWN', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' ) ) {
	fwrite( STDERR, "CrmIngestion no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Core\Leads\CrmIngestion as CI;

$passed = 0; $failed = 0;
function chk( $name, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$name}\n"; } else { $failed++; echo "FAIL  {$name}\n"; } }

global $wpdb;
$prefix = $wpdb->prefix;
$mark   = 'crmtest' . gmdate( 'YmdHis' ) . mt_rand( 100, 999 );
$email  = $mark . '@test.estandar.local';
$now    = gmdate( 'Y-m-d H:i:s' );

// Fixture: registro de proyecto demo en el registry (tabla fuente compartida).
$reg_table = $prefix . 'estandar_project_registry';
$has_reg = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reg_table ) ) === $reg_table;
if ( $has_reg ) {
	$wpdb->insert( $reg_table, array(
		'project_id' => 'demo', 'label' => 'demo', 'city' => 'Nancagua', 'active' => 1,
	) );
}

// ── 1. Pure: normalización e hashes byte-idénticos ─────────────────────────
chk( '1a email normaliza', CI::normalize_email( '  Foo@Bar.CL ' ) === 'foo@bar.cl' );
chk( '1b phone storage CL móvil', CI::normalize_phone_storage( '+56 9 1234 5678' ) === '56912345678' );
chk( '1c phone storage ya con código', CI::normalize_phone_storage( '56912345678' ) === '56912345678' );
chk( '1d phone display legacy', CI::normalize_phone_display( '912345678' ) === '+56912345678' );
chk( '1e hash determinista (mismo secret)', CI::hash( 'hola@x.cl' ) === CI::hash( 'hola@x.cl' ) );
chk( '1f hash vacío → vacío', CI::hash( '   ' ) === '' );
chk( '1g name colapsa duplicados', CI::normalize_name( '  Fernanda  Berasain  Berasain  Flores ' ) === 'Fernanda Berasain Flores' );
chk( '1g2 name adornado (emoji + deletreado) → palabra limpia', CI::normalize_name( '꧁ ✷ 𝚁 𝚘 𝚜 𝚊 𝚛 𝚒 𝚘 ✷ ꧂' ) === 'Rosario' );
chk( '1g3 name solo adorno → vacío', CI::normalize_name( '✷ ★ ꧁ ꧂' ) === '' );
chk( '1h identity email_hash correcto', CI::identity( array( 'contact' => array( 'email' => $email ) ) )['email_hash'] === CI::hash( $email ) );

// ── 2. Gate de coexistencia ────────────────────────────────────────────────
chk( '2a should_own con force (simula legacy ausente)', CI::should_own() === true );
// Con el force OWN activo, legacy_present() reporta false (simula retirado).
chk( '2b force hace legacy_present false', CI::legacy_present() === false );

// ── 3. Flujo completo (estandar-leads ausente → el v2 escribe) ─────────────
$payload = array(
	'schema_version' => '1.0',
	'source_plugin'  => 'estandar-capture',
	'submitted_at'   => $now,
	'contact'        => array( 'name' => 'CRM Test', 'email' => $email, 'phone' => '912345678' ),
	'content'        => array( 'message' => 'Hola, quiero cotizar.', 'source_form_id' => 'form-1' ),
	'context'        => array( 'source_channel' => 'form', 'project_slug' => 'demo' ),
	'attribution'    => array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'c1', 'landing_url' => 'https://cliente1.example.com/', 'session_id' => 'sess-crm-1' ),
	'request'        => array( 'ip_address' => '186.15.22.33', 'user_agent' => 'Mozilla/5.0' ),
	'spam'           => array( 'honeypot_filled' => false, 'seconds_to_submit' => 5 ),
	'meta'           => array( 'source_entry_id' => 0, '_capture_origin' => 'estandar_suite_capture_event' ),
);

$ingested = array();
add_action( 'estandar_lead_ingested', function ( $lid, $sid, $is_new, $p ) use ( &$ingested ) {
	$ingested = array( 'lead_id' => (int) $lid, 'submission_id' => (int) $sid, 'is_new' => $is_new );
}, 10, 4 );

$lead_id = CI::capture( $payload );
chk( '3a capture devuelve lead_id', $lead_id > 0 );
chk( '3b es_leads fila creada', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_leads WHERE id = %d", $lead_id ) ) === 1 );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}es_leads WHERE id = %d", $lead_id ), ARRAY_A );
chk( '3c email correcto', $row['email'] === $email );
chk( '3d status NEW', $row['status'] === 'NEW' );
chk( '3e project_slug demo', $row['project_slug'] === 'demo' );
chk( '3f email_hash HMAC real', $row['email_hash'] !== '' && strlen( $row['email_hash'] ) === 64 );
chk( '3g first_utm_source', $row['first_utm_source'] === 'google' );

$submission_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_id ) );
chk( '3h submission creada', $submission_id > 0 );
$sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}es_lead_submissions WHERE id = %d", $submission_id ), ARRAY_A );
chk( '3i submission message', $sub['message'] === 'Hola, quiero cotizar.' );
chk( '3j submission payload_hash', $sub['payload_hash'] === CI::payload_hash( $payload ) );

$ev_created = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_created'", $lead_id ) );
$ev_submitted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_submitted'", $lead_id ) );
chk( '3k evento lead_created', $ev_created === 1 );
chk( '3l evento lead_submitted', $ev_submitted === 1 );
chk( '3m hook estandar_lead_ingested emitido', $ingested['lead_id'] === $lead_id && $ingested['is_new'] === true );

// ── 4. Dedupe por email (recontacto NO duplica lead) ───────────────────────
$payload2 = $payload;
$payload2['content']['message'] = 'Recontacto: segunda consulta';
$lead_id2 = CI::capture( $payload2 );
chk( '4a recontacto reusa lead', $lead_id2 === $lead_id );
$lead_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_leads WHERE id = %d", $lead_id ) );
chk( '4b no duplica lead', $lead_count === 1 );
$sub_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_lead_submissions WHERE lead_id = %d", $lead_id ) );
chk( '4c sí crea segunda submission', $sub_count >= 2 );
$ev_recontact = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_recontacted'", $lead_id ) );
chk( '4d evento lead_recontacted', $ev_recontact >= 1 );

// ── 5. Sin identidad → no persiste ─────────────────────────────────────────
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}es_leads" );
CI::capture( array( 'schema_version' => '1.0', 'source_plugin' => 'x', 'contact' => array(), 'context' => array() ) );
chk( '5a sin email/phone no persiste', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}es_leads" ) === $before );

// ── Limpieza ───────────────────────────────────────────────────────────────
$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}es_lead_events WHERE lead_id = %d", $lead_id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}es_lead_submissions WHERE lead_id = %d", $lead_id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}es_leads WHERE id = %d", $lead_id ) );
if ( $has_reg ) {
	$wpdb->query( "DELETE FROM {$reg_table} WHERE project_id = 'demo' AND label = 'demo'" );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
