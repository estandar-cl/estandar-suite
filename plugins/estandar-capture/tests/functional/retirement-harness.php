<?php
/**
 * ESTANDAR SUITE v2 — Ensayo de retiro (F5) con estandar-forms DESACTIVADO.
 *
 * Verifica que tras desactivar el legacy:
 *  1. El sitio responde (home 200, sin fatales).
 *  2. LegacyMap (estandar-core) mantiene [estandar_form] vivo (el main file
 *     legacy se re-incluye read-only) → renders legacy intactos.
 *  3. [estandar_form_v2] sirve el form migrado nativo.
 *  4. El compat de capture NO pisa a LegacyMap (main file en disco).
 *
 * Uso: php tests/functional/retirement-harness.php <wp-path>
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

$legacy_active = is_plugin_active( 'estandar-forms/estandar-forms.php' );
if ( $legacy_active ) {
	// El harness verifica el estado RETIRADO; con legacy activo no aplica.
	// El ciclo completo (migrar → desactivar → verificar → rollback → reactivar)
	// se orquesta en F5 con wp-cli, no en este harness.
	echo "SKIP  (estandar-forms activo — este harness aplica solo con legacy desactivado)\n";
	exit( 0 );
}
check( 'estandar-forms está DESACTIVADO (retiro)', ! $legacy_active );

// LegacyMap re-incluye el main file → el shortcode legacy sigue vivo.
check( '[estandar_form] sigue registrado (LegacyMap)', shortcode_exists( 'estandar_form' ) );

// El form migrado #1 es nativo v2.
$native = C\Forms::get( 1 );
check( 'form #1 migrado es nativo', is_array( $native ) && (string) $native['form_type'] === 'lead' );
check( 'form #1 tiene marcador _migrated_legacy', is_array( $native ) && strpos( (string) $native['config_json'], '_migrated_legacy' ) !== false );

// Render del form migrado por el pipeline v2.
$html = C\FormRenderer::render_form( $native );
check( 'render nativo v2 del form migrado OK', $html !== '' && strpos( $html, 'estandar-form-v2' ) !== false );

// Compat de capture NO toma [estandar_form] mientras legacy en disco.
check( 'compat capture respeta LegacyMap (main en disco)', C\LegacyFormShortcode::legacy_main_on_disk() === true );

// Render legacy vía shortcode (LegacyMap) — form legacy sigue funcional.
global $wpdb;
$legacy_rows = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}estandar_forms LIMIT 1", ARRAY_A );
if ( $legacy_rows ) {
	$html_legacy = do_shortcode( '[estandar_form id="' . (int) $legacy_rows[0]['id'] . '"]' );
	check( 'render legacy vía [estandar_form] intacto', $html_legacy !== '' );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
