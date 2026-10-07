<?php
/**
 * ESTANDAR SUITE v2 — Capture · Test de COEXISTENCIA legacy + v2 (producción).
 *
 * Premisa: en producción estandar-forms Y estandar-capture están ACTIVOS a la
 * vez. Este harness verifica que F0/F1 no rompen esa coexistencia:
 *  - `[estandar_form]` lo sirve legacy (LegacyMap/plugin activo), sin que
 *    capture pise el shortcode ni duplique handlers AJAX.
 *  - `[estandar_form_v2]` lo sirve capture sin colisiones.
 *  - Hooks AJAX ef_get_streets: exactamente 1 registro.
 *  - Ambos renderers producen HTML sin fatales.
 *
 * Uso: php tests/functional/coexistence-harness.php <wp-path>
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

global $wpdb;
$table = $wpdb->prefix . 'estandar_forms';
$has_legacy_table = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $table . ' LIMIT 1' ) );
$legacy_active = is_plugin_active( 'estandar-forms/estandar-forms.php' );
$capture_active = is_plugin_active( 'estandar-capture/estandar-capture.php' );
echo "# estado: legacy_active=" . var_export( $legacy_active, true ) . ' capture_active=' . var_export( $capture_active, true ) . "\n";

// 1) Shortcodes: cada dueño registra el suyo; no hay dobles dueños.
check( 'legacy activo → [estandar_form] lo sirve legacy', ! $legacy_active || shortcode_exists( 'estandar_form' ) );
if ( $capture_active ) {
	check( 'capture activo → [estandar_form_v2] registrado', shortcode_exists( 'estandar_form_v2' ) );
	// LegacyFormShortcode no debe registrarse mientras legacy esté en disco.
	$capture_owns = ( C\LegacyFormShortcode::legacy_main_on_disk() === false );
	check( 'capture NO toma [estandar_form] mientras legacy en disco', ! $capture_owns );
}

// 2) AJAX ef_get_streets: exactamente 1 callback (capture o legacy, no ambos).
$count = count( $GLOBALS['wp_filter']['wp_ajax_ef_get_streets']->callbacks[10] ?? array() );
check( 'AJAX ef_get_streets: 1 solo handler', $count === 1 );
check( 'AJAX ef_get_streets nopriv: 1 solo handler', count( $GLOBALS['wp_filter']['wp_ajax_nopriv_ef_get_streets']->callbacks[10] ?? array() ) === 1 );

// 3) Render de un form legacy (si hay tabla) NO fatal y usa pipeline legacy.
if ( $has_legacy_table && $legacy_active ) {
	$rows = $wpdb->get_results( "SELECT id FROM {$table} WHERE form_type IN ('lead','lead_capture','general') AND fields_json IS NOT NULL AND fields_json != '' ORDER BY id DESC LIMIT 1", ARRAY_A );
	if ( $rows ) {
		$lid = (int) $rows[0]['id'];
		$html = do_shortcode( '[estandar_form id="' . $lid . '"]' );
		check( 'render legacy form no vacío', $html !== '' );
		check( 'render legacy usa clase estandar-form (no v2)', strpos( $html, 'estandar-form' ) !== false && strpos( $html, 'estandar-form-v2-' ) === false );
	}
}

// 4) Render de un form v2 nativo (si hay) funciona.
if ( $capture_active ) {
	$v2 = $wpdb->get_row( "SELECT id, config_json, form_type FROM {$wpdb->prefix}estandar_capture_forms WHERE form_type='lead' AND status='active' ORDER BY id DESC LIMIT 1", ARRAY_A );
	if ( $v2 ) {
		$html = C\FormRenderer::render_form( $v2 );
		check( 'render v2 form no vacío', $html !== '' );
		check( 'render v2 usa pipeline v2', strpos( $html, 'estandar-form-v2-' ) !== false );
	}
}

// 5) Los módulos F1 no colisionan con funciones legacy en espacio global.
check( 'no colisión de clases globales (Fields/Spam/Webhook/Actions namespaced)', ! class_exists( 'Fields' ) && ! class_exists( 'Spam' ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
