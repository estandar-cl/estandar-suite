<?php
/**
 * submission notify (estandar-capture) — SUBMISSION_NOTIFY_RESEND_V1 tests (Playground).
 *
 *   php tests/run-submission-notify.php   → expect "0 failed"
 *
 * Cubre:
 *   - Forms::submission_context(): reconstruye form_id+config+capture desde una submission.
 *   - Forms::notify_existing_submission(): reenvía a notify_emails; salta dedupe por defecto.
 *   - opts['to']: reemplaza el To y evita el desvío al operador.
 *   - opts['skip_dedupe']=false: respeta la ventana de dedupe (2º envío → dedupe_window).
 *   - Forms::preview_existing_notification(): to/cc/subject/enabled sin enviar.
 *   - submission inexistente → error.
 * Rollback de fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Capture\Forms' ) ) {
	fwrite( STDERR, "run-submission-notify: clase Forms no cargada — ¿estandar-capture activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Capture\Forms;
use Estandar\Capture\Ingestion;

global $wpdb;
$passed = 0;
$failed = 0;

function sn_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$wpdb->query( 'START TRANSACTION' );

// ── fixtures ─────────────────────────────────────────────────────────────
$form_cfg = array(
	'name' => 'Notifica Test',
	'send_email' => '1',
	'notify_emails' => 'gestion@cliente.example.com, rramirez@cliente.example.com',
	'mail_subject' => 'Nuevo envío: {{form_name}}',
	'notify_cc_admin' => '0',
	'autoresponse' => array( 'enabled' => '0' ),
	'form_funnel_mode' => 'simple',
	'sections' => array( array( 'title' => 'Datos', 'fields' => array(
		array( 'type' => 'text', 'name' => 'lote', 'label' => 'Lote' ),
		array( 'type' => 'number', 'name' => 'temp_real', 'label' => 'Temp real' ),
	) ) ),
);
$form_id = Forms::create( array(
	'name' => 'Notifica Test', 'slug' => 'notifica-test', 'form_type' => 'lead',
	'form_context' => 'generic', 'config_json' => wp_json_encode( $form_cfg ), 'status' => 'active',
) );
sn_check( 'setup: form creado', $form_id > 0 );

$ingest = Ingestion::ingest( array(
	'source' => 'form',
	'content' => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
	'contact' => array(),
	'fields'  => array( 'lote' => 'F 14', 'temp_real' => '26' ),
	'attribution' => array(), 'ip' => '', 'session_id' => '', 'time_on_page' => 0, 'spam' => array(),
) );
$sub_id = (int) ( $ingest['id'] ?? 0 );
sn_check( 'setup: submission creada', $sub_id > 0 );

if ( $form_id > 0 && $sub_id > 0 ) {
	// ── submission_context ──────────────────────────────────────────────
	$ctx = Forms::submission_context( $sub_id );
	sn_check( 'context: existe', is_array( $ctx ) );
	sn_check( 'context: form_id correcto', (int) ( $ctx['form_id'] ?? 0 ) === $form_id );
	sn_check( 'context: capture id', (int) ( $ctx['capture']['id'] ?? 0 ) === $sub_id );
	sn_check( 'context: fields desde fields_json', (string) ( $ctx['capture']['fields']['lote'] ?? '' ) === 'F 14' );
	sn_check( 'context: config cargada', (string) ( $ctx['config']['name'] ?? '' ) === 'Notifica Test' );
	sn_check( 'context: submission inexistente → null', Forms::submission_context( 99999999 ) === null );

	// ── preview ─────────────────────────────────────────────────────────
	$pv = Forms::preview_existing_notification( $sub_id );
	sn_check( 'preview: enabled', ! empty( $pv['enabled'] ) );
	sn_check( 'preview: To = notify_emails', in_array( 'gestion@cliente.example.com', (array) $pv['to'], true ) && in_array( 'rramirez@cliente.example.com', (array) $pv['to'], true ) );
	sn_check( 'preview: sin CC', count( (array) $pv['cc'] ) === 0 );
	sn_check( 'preview: asunto', strpos( (string) $pv['subject'], 'Nuevo envío' ) !== false );
	$pv2 = Forms::preview_existing_notification( $sub_id, array( 'to' => 'otro@cliente.example.com' ) );
	sn_check( 'preview: --to reemplaza', (array) $pv2['to'] === array( 'otro@cliente.example.com' ) );

	// ── interceptar wp_mail ─────────────────────────────────────────────
	$GLOBALS['__sn_mail'] = array();
	add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
		$GLOBALS['__sn_mail'][] = $atts;
		return true;
	}, 10, 2 );

	// ── notify_existing_submission (default: salta dedupe) ──────────────
	$r1 = Forms::notify_existing_submission( $sub_id );
	sn_check( 'resend: ok', ! empty( $r1['ok'] ) );
	sn_check( 'resend: submission_id en resultado', (int) ( $r1['submission_id'] ?? 0 ) === $sub_id );
	$to1 = (array) ( $GLOBALS['__sn_mail'][0]['to'] ?? array() );
	sn_check( 'resend: To = notify_emails (2)', in_array( 'gestion@cliente.example.com', $to1, true ) && in_array( 'rramirez@cliente.example.com', $to1, true ) );

	// Segundo reenvío inmediato: sigue ok porque salta la ventana de dedupe.
	$r2 = Forms::notify_existing_submission( $sub_id );
	sn_check( 'resend: repetido salta dedupe (ok)', ! empty( $r2['ok'] ) );

	// ── opts['to'] reemplaza el To ──────────────────────────────────────
	$GLOBALS['__sn_mail'] = array();
	$r3 = Forms::notify_existing_submission( $sub_id, array( 'to' => 'destino@cliente.example.com' ) );
	$to3 = (array) ( $GLOBALS['__sn_mail'][0]['to'] ?? array() );
	sn_check( 'resend --to: ok', ! empty( $r3['ok'] ) );
	sn_check( 'resend --to: To dirigido', in_array( 'destino@cliente.example.com', $to3, true ) && ! in_array( 'gestion@cliente.example.com', $to3, true ) );

	// ── respetar dedupe (skip_dedupe=false) ─────────────────────────────
	$GLOBALS['__sn_mail'] = array();
	$d1 = Forms::notify_existing_submission( $sub_id, array( 'skip_dedupe' => false ) );
	$d2 = Forms::notify_existing_submission( $sub_id, array( 'skip_dedupe' => false ) );
	sn_check( 'dedupe: 1er envío ok', ! empty( $d1['ok'] ) );
	sn_check( 'dedupe: 2º envío bloqueado', empty( $d2['ok'] ) && (string) ( $d2['error'] ?? '' ) === 'dedupe_window' );

	// ── submission inexistente ──────────────────────────────────────────
	$r_none = Forms::notify_existing_submission( 99999999 );
	sn_check( 'resend: submission inexistente → error', empty( $r_none['ok'] ) && (string) ( $r_none['error'] ?? '' ) === 'submission_no_existe' );

	remove_all_filters( 'pre_wp_mail' );

	// Limpiar.
	Forms::delete_submission( $sub_id );
	Forms::trash( $form_id );
}

$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
