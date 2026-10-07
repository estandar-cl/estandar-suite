<?php
/**
 * GfNativeBridge (estandar-capture) — GF_NATIVE_V1 tests (Playground).
 *
 *   php tests/run-gf-native-bridge.php   → expect "0 failed"
 *
 * Cubre el puente aditivo que re-emite el contrato Gravity Forms desde forms
 * nativos v2:
 *   - guard: form sin gf_native → contract null (inerte; no toca otros forms).
 *   - guard: gf_native sin gf_form_id → null.
 *   - contract: contrato válido se lee del config del form.
 *   - build_entry: field_id GF directo, sub-inputs compuestos, labels.
 *   - entry_value / resolve_field_id: mapeo field_id/label/name normalizado.
 *   - on_capture / process: sin contrato no hace nada; con contrato no lanza.
 * Rollback de fixtures.
 */

require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';

if ( ! class_exists( '\Estandar\Capture\GfNativeBridge' ) ) {
	fwrite( STDERR, "run-gf-native-bridge: clase GfNativeBridge no cargada — ¿estandar-capture activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Capture\GfNativeBridge as GNB;
use Estandar\Capture\Forms;

global $wpdb;
$passed = 0;
$failed = 0;

function nb_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$wpdb->query( 'START TRANSACTION' );

$cfg_no = wp_json_encode( array( 'sections' => array( array( 'title' => 'D', 'fields' => array( array( 'type' => 'text', 'name' => 'x', 'label' => 'X' ) ) ) ), 'notify_emails' => 'a@b.cl' ) );
$f_no = Forms::create( array( 'name' => 'Sin Contrato', 'slug' => 'sin-contrato-' . time(), 'form_type' => 'lead', 'form_context' => 'generic', 'config_json' => $cfg_no, 'status' => 'active' ) );
nb_check( 'guard: form sin gf_native → contract null', GNB::contract( $f_no ) === null );

$cfg_bad = wp_json_encode( array( 'gf_native' => array( 'webhook_url' => 'https://x' ) ) );
$f_bad = Forms::create( array( 'name' => 'Bad', 'slug' => 'bad-' . time(), 'form_type' => 'lead', 'form_context' => 'generic', 'config_json' => $cfg_bad, 'status' => 'active' ) );
nb_check( 'guard: gf_native sin gf_form_id → null', GNB::contract( $f_bad ) === null );

$cfg_ok = wp_json_encode( array(
	'gf_native' => array(
		'gf_form_id' => 7,
		'webhook_url' => 'https://n8n.example/webhook/test',
		'webhook_map' => array( 'nombre' => '59', 'correo' => '4', 'fecha' => '5' ),
		'notify_to'   => 'dest@example.cl',
		'mail_subject'=> 'Informe',
		'mail_body'   => '<p>Hola {Nombre de la persona que completa el formulario:59}</p>',
	),
) );
$f_ok = Forms::create( array( 'name' => 'Con GF', 'slug' => 'con-gf-' . time(), 'form_type' => 'lead', 'form_context' => 'generic', 'config_json' => $cfg_ok, 'status' => 'active' ) );
$contract = GNB::contract( $f_ok );
nb_check( 'contract: gf_native válido', is_array( $contract ) && (int) ( $contract['gf_form_id'] ?? 0 ) === 7 );

$fields = array(
	'59' => 'Rocio Becerra', 'Nombre de la persona que completa el formulario' => 'Rocio Becerra',
	'4' => 'ventas@x.cl', 'Correo electrónico' => 'ventas@x.cl',
	'5' => '2026-08-27', 'Fecha' => '2026-08-27',
	'80.2' => 'Chanchito blanco',
);
$entry = GNB::build_entry( 7, $fields );
nb_check( 'build_entry: field_id directo 59', (string) ( $entry['59'] ?? '' ) === 'Rocio Becerra' );
nb_check( 'build_entry: field_id directo 4', (string) ( $entry['4'] ?? '' ) === 'ventas@x.cl' );
nb_check( 'build_entry: sub-input 80.2', (string) ( $entry['80.2'] ?? '' ) === 'Chanchito blanco' );

nb_check( 'entry_value: field_id', GNB::entry_value( $entry, '59' ) === 'Rocio Becerra' );
nb_check( 'entry_value: label mapeado', GNB::entry_value( array( '59' => 'Ana', 'Nombre de la persona que completa el formulario' => 'Ana' ), 'Nombre de la persona que completa el formulario' ) === 'Ana' );
nb_check( 'resolve_field_id: name del mapa GF → fid', GNB::resolve_field_id( array( '60' => array( 'label' => 'Teléfono', 'name' => 'telefono' ) ), '60' ) === '60' );
nb_check( 'resolve_field_id: label normalizado del mapa GF → fid', GNB::resolve_field_id( array( '60' => array( 'label' => 'Teléfono', 'name' => 'telefono' ) ), 'telefono' ) === '60' );

$r = GNB::on_capture( array( 'source_form_id' => (string) $f_no, 'fields' => array( 'x' => '1' ) ) );
nb_check( 'on_capture: form sin contrato → ok (no lanza)', $r === null || is_array( $r ) );

$out = GNB::process( (int) $f_ok, $contract, array( 'source_form_id' => (string) $f_ok, 'fields' => $fields ) );
nb_check( 'process: no lanza y devuelve steps', is_array( $out ) && isset( $out['steps'] ) );

// ── Subject: fallback manual resuelve {Label:id} sin GFCommon ─────────────
$GLOBALS['__nb_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $n, $a ) { $GLOBALS['__nb_mail'][] = $a; return true; }, 10, 2 );
$sub_contract = array(
	'notify_to'    => 'test@example.cl',
	'mail_subject' => 'Informe: {Cliente o proyecto:50} - {Fecha:5}',
	'mail_body'    => '<p>x</p>',
);
$r_sub = GNB::send_mail( 0, $sub_contract, array( '50' => 'ClienteX', '5' => '2026-09-02' ), array() );
$sent = $GLOBALS['__nb_mail'];
$sent_subject = is_array( $sent[0] ?? null ) ? (string) ( $sent[0]['subject'] ?? '' ) : '';
nb_check( 'subject: fallback {Label:id} resuelve field_id', strpos( $sent_subject, 'ClienteX' ) !== false && strpos( $sent_subject, '{Cliente o proyecto:50}' ) === false );
remove_all_filters( 'pre_wp_mail' );

foreach ( array( $f_no, $f_bad, $f_ok ) as $id ) { Forms::trash( (int) $id ); }
$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
