<?php
/**
 * Estandar Suite — Capture · línea 03 compartida para los 3 tipos hermanos
 * (Formularios/Surveys/Postventa). Mismo motor, mismas estructura: lista ·
 * registros · importar · configuración. Variaciones solo de etiqueta/objetivo.
 * Espera $m: feature, type, title, singular, use_responses, create_label, help,
 * modules (map fm=>label), fields, settings_opt, import (bool), bridge (bool).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$feature       = (string) $m['feature'];
$fp_type       = (string) $m['type'];
$fp_title      = (string) $m['title'];
$fp_singular   = (string) $m['singular'];
$fp_use_responses = ! empty( $m['use_responses'] );
$fp_create_label  = (string) $m['create_label'];
$fp_help       = (string) $m['help'];
$feature_fields = $m['fields'];
$feature_option = (string) $m['settings_opt'];

$fm = sanitize_key( (string) ( $_GET['fm'] ?? 'list' ) );
if ( ! isset( $m['modules'][ $fm ] ) ) {
	$fm = 'list';
}
$fm_items = array();
foreach ( $m['modules'] as $mk => $ml ) {
	$fm_items[] = array(
		'href'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&fm=' . $mk ),
		'label'  => $ml,
		'active' => ( $fm === $mk ),
	);
}
\Estandar\Core\Admin::render_nav_tabs( $fm_items );

if ( $fm === 'list' ) {
	include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-forms-product.php';
	if ( ! empty( $m['bridge'] ) && method_exists( '\Estandar\Capture\Admin', 'render_gf_postventa_bridge' ) ) {
		\Estandar\Capture\Admin::render_gf_postventa_bridge();
	}
} elseif ( $fm === 'entradas' ) {
	echo \Estandar\Core\Admin::help_html( 'entradas', '<strong>' . esc_html( $fp_title ) . ' — registros</strong> de los ' . esc_html( strtolower( $fp_title ) ) . ' (solo este tipo).' );
	if ( ! class_exists( '\\WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
	require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Admin/EntriesListTable.php';
	$et = new \Estandar\Capture\Admin\EntriesListTable( $fp_type, $feature );
	$et->process_row_actions();
	$et->process_bulk_action();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&fm=entradas' ) ) . '">';
	$et->prepare_items();
	$et->display();
	echo '</form>';
} elseif ( $fm === 'importar' ) {
	if ( ! empty( $m['import'] ) ) {
		\Estandar\Core\Admin::help_html( 'importar', '<strong>Importar</strong> ' . esc_html( strtolower( $fp_title ) ) . ' desde JSON o Gravity Forms.' );
		echo '<div style="background:#f9f9f6;border:1px solid #c3c4c7;border-radius:8px;padding:14px;max-width:820px;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="margin:0 0 10px;">';
		wp_nonce_field( 'estandar_suite_capture_form_json' );
		echo '<input type="hidden" name="action" value="estandar_suite_capture_form_json_import">';
		echo '<label>JSON (envelope v1):</label> <input type="file" name="form_json" accept=".json"> <button class="button" type="submit">Importar</button></form>';
		echo '</div>';
	} else {
		echo \Estandar\Core\Admin::help_html( 'importar', '<strong>Importar</strong> — por ahora la importación externa aplica a <strong>Formularios</strong>. Para ' . esc_html( $fp_title ) . ' se crean desde su lista o maquetador.' );
	}
} elseif ( $fm === 'configuracion' ) {
	echo \Estandar\Core\Admin::help_html( 'configuracion', '<strong>Configuración del módulo ' . esc_html( $fp_title ) . '</strong>.' );
	\Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', $feature_option, $feature_fields, $feature );
}
