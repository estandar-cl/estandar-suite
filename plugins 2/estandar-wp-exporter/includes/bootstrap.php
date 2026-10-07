<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Bootstrap
 *
 * Responsabilidad ÚNICA:
 * - Cargar archivos del plugin en orden seguro
 * - Inicializar el controlador de admin
 *
 * Este archivo NO:
 * - Ejecuta lógica de exportación
 * - Accede a filesystem
 * - Registra AJAX
 * - Renderiza HTML
 */

final class Estandar_WPE_Bootstrap {

	public static function activate() : void {

		self::load_includes();
		estandar_wpe_ensure_storage_dirs();
		estandar_wpe_cleanup_stale_artifacts();
	}

	public static function deactivate() : void {

		self::load_includes();
		estandar_wpe_cleanup_stale_artifacts(0);
	}

	/**
	 * Punto de entrada exclusivo para wp-admin.
	 * Llamado desde estandar-wp-exporter.php
	 */
	public static function init_admin() : void {

		// Seguridad adicional
		if ( ! is_admin() ) {
			return;
		}

		self::load_includes();
		self::load_admin();
		estandar_wpe_cleanup_stale_artifacts();

		if ( class_exists('Estandar_WPE_Admin') ) {
			new Estandar_WPE_Admin();
		}
	}

	/* ======================================================
	   Loaders
	====================================================== */

	/**
	 * Carga helpers y core lógico (sin UI).
	 */
	private static function load_includes() : void {

		$files = [
			// Helpers base (funciones puras)
			ESTANDAR_WPE_INC . 'helpers.php',

			// Persistencia y escaneo
			ESTANDAR_WPE_INC . 'class-job-store.php',
			ESTANDAR_WPE_INC . 'class-scanner.php',

			// Orquestador principal
			ESTANDAR_WPE_INC . 'class-exporter.php',
		];

		foreach ( $files as $file ) {
			self::safe_require($file);
		}
	}

	/**
	 * Carga la capa admin (UI + AJAX).
	 */
	private static function load_admin() : void {

		$files = [
			ESTANDAR_WPE_ADMIN . 'class-admin.php',
		];

		foreach ( $files as $file ) {
			self::safe_require($file);
		}
	}

	/* ======================================================
	   Require seguro
	====================================================== */

	/**
	 * Require controlado: nunca fatal.
	 */
	private static function safe_require(string $file) : void {

		if ( file_exists($file) ) {
			require_once $file;
			return;
		}

		// Aviso visible solo para administradores
		add_action('admin_notices', function () use ($file) {

			if ( ! current_user_can('manage_options') ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo '<strong>Estandar WP Exporter:</strong> Falta archivo requerido:<br>';
			echo '<code>' . esc_html($file) . '</code>';
			echo '</p></div>';
		});
	}
}
