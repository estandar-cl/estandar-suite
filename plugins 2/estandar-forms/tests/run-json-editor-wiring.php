<?php
/**
 * JSON_LIFECYCLE_V1 — suite de wiring del editor JSON (UI ↔ AJAX ↔ canónico).
 * Run: php tests/run-json-editor-wiring.php
 *
 * Verifica estáticamente que la UI del editor está completa y correctamente
 * cableada: 4 pestañas, controles, nonce, enqueue de CodeMirror con fallback,
 * acciones AJAX registradas cuyo nombre calza exactamente con la UI, gate sin
 * condiciones ocultas (form_type / flags / query params), y que Aplicar pasa
 * por el importador canónico (Estandar_Forms_Template::apply) sin escritura
 * directa a DB desde los handlers AJAX.
 */

define( 'ABSPATH', __DIR__ . '/' );

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

// La UI del ciclo JSON vive en UN solo lugar (partial compartido); los screens
// de Forms y Surveys solo la invocan. $screen = screen + partial para que los
// checks de contrato sigan validando la UI efectivamente renderizada.
$partial       = (string) file_get_contents( dirname( __DIR__ ) . '/admin/partials/json-lifecycle-panel.php' );
$form_screen   = (string) file_get_contents( dirname( __DIR__ ) . '/admin/screens/form-edit.php' );
$survey_screen = (string) file_get_contents( dirname( __DIR__ ) . '/admin/screens/survey-edit.php' );
$screen = $form_screen . "\n" . $partial;
$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/estandar-forms.php' );
$class  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-template.php' );

/* ── 1 · Pestañas ───────────────────────────────────────────────────────── */
check( 'tabs: las 4 pestañas definidas (Visual/JSON/Vista previa/Historial)',
	strpos( $screen, "'visual' => 'Visual'" ) !== false
	&& strpos( $screen, "'json' => 'JSON'" ) !== false
	&& strpos( $screen, "'preview' => 'Vista previa'" ) !== false
	&& strpos( $screen, "'history' => 'Historial'" ) !== false );
check( 'tabs: paneles JSON/preview/history renderizados', strpos( $screen, 'id="ef-panel-json"' ) !== false && strpos( $screen, 'id="ef-panel-preview"' ) !== false && strpos( $screen, 'id="ef-panel-history"' ) !== false );
check( 'tabs: pestaña activa seleccionable por URL (ef_tab)', strpos( $screen, "get('ef_tab')" ) !== false && strpos( $screen, "searchParams.set('ef_tab'" ) !== false );

/* ── 2 · Gate sin condiciones ocultas ───────────────────────────────────── */
$gate_line = '';
foreach ( explode( "\n", $screen ) as $line ) {
	if ( strpos( $line, '$ef_json_lifecycle =' ) !== false ) { $gate_line = $line; break; }
}
check( 'gate: existe y depende solo de form persistido + clase canónica',
	$gate_line !== ''
	&& strpos( $gate_line, '$current' ) !== false
	&& strpos( $gate_line, "class_exists( 'Estandar_Forms_Template' )" ) !== false );
check( 'gate: sin form_type / capability / flag / query / env / versión',
	$gate_line !== ''
	&& strpos( $gate_line, 'form_type' ) === false
	&& strpos( $gate_line, 'current_user_can' ) === false
	&& strpos( $gate_line, '$_GET' ) === false
	&& strpos( $gate_line, 'get_option' ) === false
	&& strpos( $gate_line, 'defined(' ) === false
	&& strpos( $gate_line, 'VERSION' ) === false );

/* ── 3 · Controles del editor ───────────────────────────────────────────── */
foreach ( [
	'textarea host'        => 'id="ef-json-editor"',
	'botón Aplicar'        => 'id="ef-json-apply"',
	'botón Validar'        => 'id="ef-json-validate"',
	'botón Diff'           => 'id="ef-json-diff"',
	'botón Formatear'      => 'id="ef-json-format"',
	'botón Restaurar'      => 'id="ef-json-restore"',
	'botón Copiar'         => 'id="ef-json-copy"',
	'botón Descargar'      => 'id="ef-json-download"',
	'control Subir'        => 'id="ef-json-file"',
	'selector Merge/Replace' => 'id="ef-json-replace"',
	'confirmación de slug' => 'id="ef-json-allow-slug"',
] as $label => $needle ) {
	check( 'control: ' . $label, strpos( $screen, $needle ) !== false );
}
check( 'editor: carga el formulario actual (export + esc_textarea), no plantilla vacía',
	strpos( $screen, 'Estandar_Forms_Template::export( (int) $current->id )' ) !== false
	&& strpos( $screen, 'esc_textarea( $ef_initial_json )' ) !== false );
check( 'preview: render real del form persistido', strpos( $screen, "do_shortcode( '[estandar_form id=\"' . (int) \$current->id . '\"]' )" ) !== false );
check( 'history: fundación consultada vía clase canónica', strpos( $screen, 'Estandar_Forms_Template::history( (int) $current->id )' ) !== false );

/* ── 4 · Assets ─────────────────────────────────────────────────────────── */
check( 'assets: wp_enqueue_code_editor en el screen, gateado al editor', strpos( $screen, "wp_enqueue_code_editor( [ 'type' => 'application/json' ] )" ) !== false );
check( 'assets: settings de CodeMirror pasados a JS', strpos( $screen, 'wp_json_encode( $ef_code_editor_settings' ) !== false && strpos( $screen, 'wp.codeEditor.initialize(textarea, cmSettings)' ) !== false );
check( 'assets: fallback a textarea si CodeMirror no está', strpos( $screen, '&& cmSettings)' ) !== false && strpos( $screen, 'cm ? cm.getValue() : textarea.value' ) !== false );
check( 'assets: nonce + ajaxurl disponibles para el JS', strpos( $screen, "wp_create_nonce( 'estandar_forms_json' )" ) !== false && strpos( $screen, "admin_url( 'admin-ajax.php' )" ) !== false );

/* ── 5 · AJAX: registro ↔ UI ↔ handlers ─────────────────────────────────── */
preg_match_all( "/add_action\(\s*'wp_ajax_(estandar_forms_json_[a-z_]+)'\s*,\s*\[\s*'Estandar_Forms_Template'\s*,\s*'([a-z_]+)'\s*\]/", $plugin, $hooks );
$registered = array_combine( $hooks[1], $hooks[2] );
check( 'ajax: 5 acciones registradas', count( $registered ) === 5 );

preg_match_all( "/post\('(estandar_forms_json_[a-z_]+)'/", $screen, $ui_actions );
$ui_actions = array_unique( $ui_actions[1] );
$unmatched = array_diff( $ui_actions, array_keys( $registered ) );
check( 'ajax: cada acción usada por la UI tiene hook exacto (' . implode( ',', $ui_actions ) . ')', ! empty( $ui_actions ) && empty( $unmatched ) );

require dirname( __DIR__ ) . '/includes/class-template.php';
$methods_ok = true;
foreach ( $registered as $action => $method ) {
	if ( ! method_exists( 'Estandar_Forms_Template', $method ) ) { $methods_ok = false; }
}
check( 'ajax: todos los handlers existen en la clase canónica', $methods_ok );

/* ── 6 · Seguridad + ruta canónica ──────────────────────────────────────── */
$guard = substr( $class, strpos( $class, 'private static function ajax_guard' ), 500 );
check( 'ajax: capability + nonce en el guard común', strpos( $guard, "current_user_can( 'manage_options' )" ) !== false && strpos( $guard, "check_ajax_referer( 'estandar_forms_json', 'nonce' )" ) !== false );

$apply_body = substr( $class, strpos( $class, 'public static function handle_ajax_apply' ), 1600 );
check( 'canónico: Aplicar pasa por Estandar_Forms_Template::apply()', strpos( $apply_body, 'self::apply(' ) !== false );
$ajax_region = substr( $class, strpos( $class, 'private static function ajax_guard' ) );
check( 'canónico: handlers AJAX sin escritura directa a DB', strpos( $ajax_region, '$wpdb->update' ) === false && strpos( $ajax_region, '$wpdb->insert' ) === false );
foreach ( [ 'handle_ajax_validate' => 'self::lint(', 'handle_ajax_diff' => 'self::semantic_diff(' ] as $method => $needle ) {
	$body = substr( $class, strpos( $class, 'public static function ' . $method ), 1600 );
	check( 'canónico: ' . $method . ' usa el serializador único', strpos( $body, $needle ) !== false );
}

/* ── 7 · Surveys — misma UI, cero implementación paralela ───────────────── */
check( 'shared: UI definida una sola vez (partial), no inline en los screens',
	strpos( $partial, 'id="ef-json-apply"' ) !== false
	&& strpos( $form_screen, 'id="ef-json-apply"' ) === false
	&& strpos( $survey_screen, 'id="ef-json-apply"' ) === false );
check( 'shared: ambos screens requieren el partial canónico',
	strpos( $form_screen, "require_once __DIR__ . '/../partials/json-lifecycle-panel.php'" ) !== false
	&& strpos( $survey_screen, "require_once __DIR__ . '/../partials/json-lifecycle-panel.php'" ) !== false );
check( 'shared: ambos screens renderizan tabs + panels vía las funciones compartidas',
	strpos( $form_screen, 'estandar_forms_render_json_lifecycle_tabs()' ) !== false
	&& strpos( $form_screen, "estandar_forms_render_json_lifecycle_panels( \$current, '.estandar-admin-layout' )" ) !== false
	&& strpos( $survey_screen, 'estandar_forms_render_json_lifecycle_tabs()' ) !== false
	&& strpos( $survey_screen, "estandar_forms_render_json_lifecycle_panels( \$survey, '#ef-visual-root' )" ) !== false );

$survey_gate = '';
foreach ( explode( "\n", $survey_screen ) as $line ) {
	if ( strpos( $line, '$ef_json_lifecycle =' ) !== false ) { $survey_gate = $line; break; }
}
check( 'survey gate: depende solo de survey persistido + clase canónica, sin condiciones ocultas',
	$survey_gate !== ''
	&& strpos( $survey_gate, '$survey' ) !== false
	&& strpos( $survey_gate, "class_exists( 'Estandar_Forms_Template' )" ) !== false
	&& strpos( $survey_gate, 'form_type' ) === false
	&& strpos( $survey_gate, '$_GET' ) === false
	&& strpos( $survey_gate, 'get_option' ) === false );
check( 'survey visual: editor envuelto en #ef-visual-root para el toggle de pestañas',
	strpos( $survey_screen, '<div id="ef-visual-root">' ) !== false );
check( 'survey duplicate: acción visual existente intacta (no reemplazada)',
	strpos( $survey_screen, 'admin_post_estandar_survey_duplicate' ) !== false );

echo "\n" . ( $failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n" );
exit( $failed === 0 ? 0 : 1 );
