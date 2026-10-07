<?php
/**
 * AiContext (estandar-core) — tests funcionales del export IA Context v2.
 *
 *   php tests/run-ai-context.php   → expect "0 failed"
 *
 * Covers:
 *   - build_for_lead(): snapshot, origen, calidad de datos, pipeline,
 *     timeline, submissions.
 *   - Detección de typos de email (gmail.con / hoail.com).
 *   - phone_display() para móvil chileno.
 * Rollbacks all fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Core\Leads\AiContext' ) ) {
	fwrite( STDERR, "run-ai-context: clase AiContext no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}
if ( ! class_exists( '\Estandar\Core\LeadsPanel' ) ) {
	fwrite( STDERR, "run-ai-context: LeadsPanel no cargada\n" );
	exit( 1 );
}

use Estandar\Core\Leads\AiContext as AC;
use Estandar\Core\LeadsPanel as LP;

global $wpdb;
$passed = 0;
$failed = 0;

function ai_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$leads_table = $wpdb->prefix . 'es_leads';
$subs_table  = $wpdb->prefix . 'es_lead_submissions';
$events_table = $wpdb->prefix . 'es_lead_events';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) !== $leads_table
	|| (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subs_table ) ) !== $subs_table ) {
	fwrite( STDERR, "run-ai-context: tablas es_leads / es_lead_submissions no existen — ¿estandar-leads activo en Playground?\n" );
	exit( 1 );
}

$wpdb->query( 'START TRANSACTION' );

$now = gmdate( 'Y-m-d H:i:s' );

$wpdb->insert( $leads_table, array(
	'lead_uuid'           => wp_generate_uuid4(),
	'status'              => 'NEW',
	'name'                => 'María Test',
	'email'               => 'maria@valdesfrenanda.con',
	'phone'               => '+56 9 1234 5678',
	'first_source_plugin' => 'estandar-capture',
	'latest_source_plugin'=> 'estandar-capture',
	'project_slug'        => 'demo',
	'project_area'        => 'Inmobiliaria',
	'lead_intent'         => 'compra',
	'first_utm_source'    => 'google',
	'first_utm_medium'    => 'cpc',
	'first_utm_campaign'  => 'contabilidad-empresas',
	'first_landing_url'   => 'https://x.cl/contacto/',
	'first_referrer'      => '',
	'first_session_id'    => 'sess-abc',
	'first_ip_address'    => '1.2.3.4',
	'temperature'         => 'tibio',
	'priority'            => 'media',
	'behavioral_score'    => 50,
	'first_seen_at'       => $now,
	'last_seen_at'        => $now,
	'created_at'          => $now,
	'updated_at'          => $now,
) );
$lead_id = absint( $wpdb->insert_id );

$wpdb->insert( $subs_table, array(
	'lead_id'        => $lead_id,
	'lead_uuid'      => (string) $wpdb->get_var( $wpdb->prepare( "SELECT lead_uuid FROM {$leads_table} WHERE id = %d", $lead_id ) ),
	'source_plugin'  => 'estandar-capture',
	'source_form_id' => '3',
	'schema_version' => '1.0',
	'submitted_at'   => $now,
	'created_at'     => $now,
	'utm_source'     => 'google',
	'utm_medium'     => 'cpc',
	'utm_campaign'   => 'contabilidad-empresas',
	'landing_url'    => 'https://x.cl/contacto/',
	'session_id'     => 'sess-abc',
	'message'        => 'Quiero cambiar de contador este mes.',
	'form_fields_json' => wp_json_encode( array(
		'name' => 'María Test', 'email' => 'maria@valdesfrenanda.con',
		'phone' => '+56 9 1234 5678', 'necesidad' => 'cambiar_contador',
		'plazo' => 'este_mes', 'ciudad' => 'Rancagua',
	) ),
) );
$sub_id = absint( $wpdb->insert_id );

$wpdb->insert( $events_table, array(
	'lead_id'    => $lead_id,
	'event_type' => 'operator_notified',
	'created_at' => $now,
) );

$md = AC::build_for_lead( $lead_id );
ai_check( 'build_for_lead no vacío', $md !== '' );
ai_check( 'header lead id', strpos( $md, '# IA Context — Lead #' . $lead_id ) !== false );
ai_check( 'sección Snapshot comercial', strpos( $md, '## Snapshot comercial' ) !== false );
ai_check( 'snapshot estado panel', strpos( $md, 'Estado panel' ) !== false );
ai_check( 'snapshot temperatura', strpos( $md, '**Temperature:** tibio' ) !== false );
ai_check( 'snapshot score', strpos( $md, '**Behavioral score:** 50' ) !== false );
ai_check( 'snapshot phone formateado móvil chileno', strpos( $md, '**Phone:** +56 9 1234 5678' ) !== false );
ai_check( 'origen landing', strpos( $md, 'https://x.cl/contacto/' ) !== false );
ai_check( 'origen campaña', strpos( $md, 'contabilidad-empresas' ) !== false );
ai_check( 'contexto de negocio necesidad', strpos( $md, 'Necesidad: cambiar_contador' ) !== false );
$bc_start = strpos( $md, '## Contexto de negocio' );
$bc_end   = strpos( $md, '## Calidad de datos' );
$bc_block = $bc_start !== false && $bc_end !== false ? substr( $md, $bc_start, $bc_end - $bc_start ) : '';
ai_check( 'contexto de negocio sin PII (name/email/phone)', $bc_block !== '' && strpos( $bc_block, 'Name:' ) === false && strpos( $bc_block, 'Email:' ) === false && strpos( $bc_block, 'Phone:' ) === false );
ai_check( 'calidad email typo detectado', strpos( $md, 'probable typo' ) !== false );
ai_check( 'calidad message PASS', strpos( $md, '**Message:** PASS' ) !== false );
ai_check( 'pipeline pasos presentes', strpos( $md, '## Pipeline' ) !== false );
ai_check( 'pipeline paso contacto pendiente', strpos( $md, 'Nunca contactado' ) !== false );
ai_check( 'timeline evento notificado', strpos( $md, 'operator_notified' ) !== false || strpos( $md, 'Notificado' ) !== false );
ai_check( 'submissions con id', strpos( $md, 'Submission 1 · ID ' . $sub_id ) !== false );

$md_tech = AC::build_for_lead( $lead_id, 'technical' );
ai_check( 'vista technical no vacía', $md_tech !== '' );

ai_check( 'build_for_lead id inexistente → vacío', AC::build_for_lead( 999999 ) === '' );
ai_check( 'build_for_lead id 0 → vacío', AC::build_for_lead( 0 ) === '' );

$wpdb->query( 'ROLLBACK' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
