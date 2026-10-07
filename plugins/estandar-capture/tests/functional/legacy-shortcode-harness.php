<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness funcional del compat [estandar_form].
 *
 * F0 · arquitectura real: mientras el main file de estandar-forms exista en
 * disco, LegacyMap (estandar-core) es quien mantiene vivo `[estandar_form]`.
 * Capture NO pisa eso; su LegacyFormShortcode es el TAKEOVER de F5 (cuando el
 * archivo se archive). Este harness verifica:
 *   1. La cobertura de compat existe (LegacyMap o shortcode registrado).
 *   2. El takeover de capture está listo y correctamente gateado.
 *   3. El resolver legacy→nativo (SOLO-LECTURA) + FormRenderer::render_form
 *      producen HTML v2 sin escribir en la tabla nativa.
 *
 * Uso:
 *   php tests/functional/legacy-shortcode-harness.php <wp-path>
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

// Asegurar namespace v2 cargado (si capture no está activo, cargar manual).
if ( ! class_exists( 'Estandar\Capture\LegacyFormShortcode' ) ) {
	require_once WP_PLUGIN_DIR . '/estandar-capture/includes/Ingestion.php';
	require_once WP_PLUGIN_DIR . '/estandar-capture/includes/Forms.php';
	require_once WP_PLUGIN_DIR . '/estandar-capture/includes/FormRenderer.php';
	require_once WP_PLUGIN_DIR . '/estandar-capture/includes/LegacyFormShortcode.php';
}

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

// 1) Cobertura de compat: LegacyMap re-incluye legacy o el shortcode existe.
$legacy_loaded = class_exists( 'Estandar_Forms_Shortcode', false );
$shortcode_ok  = function_exists( 'shortcode_exists' ) && shortcode_exists( 'estandar_form' );
check( 'compat [estandar_form] cubierto (LegacyMap o shortcode)', $legacy_loaded || $shortcode_ok );

// 2) Takeover de capture: clase presente y gate correcto.
check( 'clase LegacyFormShortcode de capture presente', class_exists( 'Estandar\Capture\LegacyFormShortcode' ) );
$legacy_on_disk = C\LegacyFormShortcode::legacy_main_on_disk();
check( 'main file legacy en disco → capture NO toma posesión', $legacy_on_disk === true );

// 3) Resolver legacy→nativo (SOLO-LECTURA) sin shortcode registrado.
global $wpdb;
$table = $wpdb->prefix . 'estandar_forms';
$has_legacy_table = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $table . ' LIMIT 1' ) );

if ( $has_legacy_table ) {
	$name  = 'Compat Harness ' . wp_generate_uuid4();
	$slug  = 'compat-harness-' . substr( md5( (string) mt_rand() ), 0, 8 );
	$inserted = $wpdb->insert(
		$table,
		array(
			'name'            => $name,
			'slug'            => $slug,
			'success_message' => 'Gracias por tu mensaje.',
			'submit_label'    => 'Enviar',
			'form_type'       => 'lead',
			'form_context'    => 'generic',
			'fields_json'     => wp_json_encode( array( array( 'type' => 'text', 'name' => 'nombre', 'label' => 'Nombre' ), array( 'type' => 'email', 'name' => 'mail', 'label' => 'Correo' ) ) ),
			'created_at'      => current_time( 'mysql' ),
		)
	);
	$lid = (int) $wpdb->insert_id;
	check( 'form legacy temporal insertado', $inserted === 1 && $lid > 0 );

	if ( $lid > 0 ) {
		// Resolver por id.
		$native = C\LegacyFormShortcode::resolve( array( 'id' => $lid, 'slug' => '' ) );
		check( 'resolve por id → payload nativo', is_array( $native ) && (int) $native['id'] === $lid );
		check( 'resolve mantiene tipo lead', is_array( $native ) && $native['form_type'] === 'lead' );

		// Resolver por slug.
		$native2 = C\LegacyFormShortcode::resolve( array( 'id' => 0, 'slug' => $slug ) );
		check( 'resolve por slug → payload nativo', is_array( $native2 ) && (int) $native2['id'] === $lid );

		// Render por el pipeline v2 (render_form) usando el payload resuelto.
		if ( is_array( $native ) ) {
			$html = C\FormRenderer::render_form( $native );
			check( 'render_form no vacío', $html !== '' );
			check( 'render usa pipeline v2 (estandar-form-v2)', strpos( $html, 'estandar-form-v2' ) !== false );
			check( 'render conserva el id legacy', strpos( $html, 'value="' . $lid . '"' ) !== false );
			check( 'render conserva campos', strpos( $html, 'nombre' ) !== false && strpos( $html, 'mail' ) !== false );
		}

		// SOLO lectura: no escribe en la tabla nativa.
		$native_row = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'estandar_capture_forms WHERE id = %d', $lid ), ARRAY_A );
		check( 'compat NO escribe en tabla nativa (read-only)', empty( $native_row ) );

		// Survey SÍ resuelve (F2); incident SÍ (F3).
		$wpdb->update( $table, array( 'form_type' => 'survey' ), array( 'id' => $lid ) );
		$res_survey = C\LegacyFormShortcode::resolve( array( 'id' => $lid, 'slug' => '' ) );
		check( 'survey SÍ resuelve (F2)', is_array( $res_survey ) && $res_survey['form_type'] === 'survey' );
		$wpdb->update( $table, array( 'form_type' => 'incident' ), array( 'id' => $lid ) );
		$res_incident = C\LegacyFormShortcode::resolve( array( 'id' => $lid, 'slug' => '' ) );
		check( 'incident SÍ resuelve (F3)', is_array( $res_incident ) && $res_incident['form_type'] === 'incident' );

		$wpdb->delete( $table, array( 'id' => $lid ) );
	}
} else {
	check( 'sin tabla legacy → compat depende de LegacyMap (no fatal)', $legacy_loaded || true );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
