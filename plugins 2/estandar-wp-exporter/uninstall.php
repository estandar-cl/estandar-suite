<?php
/**
 * Uninstall script for Estandar WP Exporter
 *
 * Se ejecuta SOLO cuando el plugin es eliminado desde wp-admin.
 * Limpia cualquier rastro creado por el plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * IMPORTANTE
 * ----------
 * Este plugin NO guarda:
 * - Opciones persistentes
 * - Custom tables
 * - CPTs
 *
 * Solo puede haber:
 * - Archivos temporales generados durante exportaciones
 *
 * La limpieza es conservadora y segura.
 */

// 1. Limpiar transients propios (si existieran)
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"
		DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		",
		$wpdb->esc_like('_transient_estandar_wpe_') . '%'
	)
);

$wpdb->query(
	$wpdb->prepare(
		"
		DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		",
		$wpdb->esc_like('_transient_timeout_estandar_wpe_') . '%'
	)
);

// 2. Limpieza defensiva de archivos temporales

$runtime_dirs = [
	trailingslashit( get_temp_dir() ) . 'estandar-wpe',
	trailingslashit( wp_get_upload_dir()['basedir'] ) . 'estandar-wpe',
];

foreach ( $runtime_dirs as $runtime_dir ) {
	if ( ! is_dir( $runtime_dir ) || ! is_readable( $runtime_dir ) ) {
		continue;
	}

	$files = glob( trailingslashit( $runtime_dir ) . '*/*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
}

// 3. No se elimina uploads/, storage/, ni carpetas arbitrarias
//    → evita borrar datos de otros plugins o del sitio
