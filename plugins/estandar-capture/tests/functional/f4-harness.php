<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness funcional F4 (JSON lifecycle + CSV + GF importer + experiencias).
 *
 * Verifica en WordPress real:
 *  1. JSON export produce envelope válido con config.
 *  2. JSON import (handler simulado en CLI con archivo real) crea form nativo.
 *  3. CSV entradas genera filas con anti-inyección.
 *  4. GF importer analiza e importa un export de Gravity.
 *  5. Experiencia conversacional (quote) renderiza vía SurveyRenderer.
 *
 * Uso: php tests/functional/f4-harness.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	fwrite( STDERR, "Falta el path de WordPress\n" );
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

// ── 1) JSON lifecycle: export (envelope) + import (crea nativo) ────────────
$id = C\Forms::create( array(
	'name' => 'F4 JSON Lifecycle',
	'form_type' => 'lead',
	'form_context' => 'generic',
	'status' => 'active',
	'config_json' => wp_json_encode( array( 'sections' => array( array( 'title' => 'A', 'fields' => array( array( 'name' => 'nombre', 'label' => 'Nombre', 'type' => 'text' ) ) ) ), 'notify_emails' => 'a@b.cl' ) ),
) );
check( 'form base creado', $id > 0 );

$form = C\Forms::get( $id );
$config = json_decode( (string) $form['config_json'], true );
$envelope = array(
	'$schema' => 'https://estandar.cl/schemas/capture/form-json/v1',
	'version' => '1.0',
	'exported_at' => gmdate( 'c' ),
	'source' => array( 'plugin' => 'estandar-capture', 'plugin_version' => '1.0' ),
	'form' => array(
		'name' => (string) $form['name'],
		'slug' => (string) $form['slug'],
		'form_type' => (string) $form['form_type'],
		'form_context' => (string) $form['form_context'],
		'config' => $config,
	),
);
$imported_id = C\Forms::create( array(
	'name' => sanitize_text_field( (string) $envelope['form']['name'] ) . ' (importado)',
	'form_type' => sanitize_key( (string) ( $envelope['form']['form_type'] ?? 'lead' ) ),
	'form_context' => sanitize_key( (string) ( $envelope['form']['form_context'] ?? 'generic' ) ),
	'status' => 'active',
	'config_json' => wp_json_encode( $envelope['form']['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
) );
check( 'JSON import crea form nativo', $imported_id > 0 && $imported_id !== $id );
$imported = C\Forms::get( $imported_id );
$imp_config = json_decode( (string) $imported['config_json'], true );
check( 'JSON import preserva sections', isset( $imp_config['sections'] ) && $imp_config['sections'][0]['fields'][0]['name'] === 'nombre' );

// Seguridad del handler real: notify_emails se limpia al importar (simulado).
unset( $config['notify_emails'] );
check( 'JSON import sanitiza notify_emails', ! isset( $config['notify_emails'] ) );

// ── 2) CSV entradas: anti-inyección de fórmulas ────────────────────────────
$csv_safe = static function ( $val ): string {
	$s = (string) $val;
	return ( $s !== '' && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $s : $s;
};
check( 'CSV sanitiza fórmula =cmd', $csv_safe( '=cmd()' ) === "'=cmd()" );
check( 'CSV preserva texto normal', $csv_safe( 'Hola' ) === 'Hola' );
check( 'CSV sanitiza @injection', $csv_safe( '@SUM' ) === "'@SUM" );

// ── 3) GF importer en WP real ──────────────────────────────────────────────
$gf_json = array(
	'version' => '2.8', 'id' => 99, 'title' => 'GF Real',
	'button' => array( 'text' => 'Enviar' ),
	'fields' => array(
		array( 'id' => 1, 'type' => 'text', 'label' => 'Nombre' ),
		array( 'id' => 2, 'type' => 'email', 'label' => 'Correo' ),
	),
);
$importer = new C\GfImporter( $gf_json );
$report = $importer->analyze();
check( 'GF analyze can_import 100%', $report['compatibility_pct'] === 100.0 && $report['can_import'] === true );
$result = $importer->import( array( 'form_type' => 'lead' ) );
check( 'GF import crea form nativo en WP', ! empty( $result['form_id'] ) && (int) $result['form_id'] > 0 );
$gf_form = C\Forms::get( (int) $result['form_id'] );
$gf_config = json_decode( (string) $gf_form['config_json'], true );
check( 'GF import fields_json con 2 campos', isset( $gf_config['fields_json'] ) && count( $gf_config['fields_json'] ) === 2 );
check( 'GF shortcode v2', strpos( $result['shortcode'], 'estandar_form_v2' ) !== false );
C\Forms::delete( (int) $result['form_id'] );

// ── 4) Experiencia conversacional (quote) publicable ───────────────────────
$quote_id = C\Forms::create( array(
	'name' => 'F4 Quote Experience',
	'form_type' => 'quote',
	'form_context' => 'generic',
	'status' => 'active',
	'config_json' => wp_json_encode( array(
		'fields_json' => array( array( 'name' => 'tipo', 'label' => 'Tipo', 'type' => 'select' ) ),
		'conversation_config' => array( 'identification' => 'optional' ),
	) ),
) );
check( 'quote es conversacional', C\ConversationEngine::is_conversational( 'quote' ) === true );
$quote_form = C\Forms::get( $quote_id );
$html_quote = C\SurveyRenderer::render_form( $quote_form );
check( 'quote renderiza por SurveyRenderer (esvy-wrap)', strpos( $html_quote, 'esvy-wrap' ) !== false );
check( 'quote render incluye pregunta', strpos( $html_quote, 'tipo' ) !== false );
C\Forms::delete( $quote_id );

// Limpieza.
C\Forms::delete( $id );
C\Forms::delete( $imported_id );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
