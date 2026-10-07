<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Admin Controller
 *
 * Responsabilidad:
 * - Registrar menú en wp-admin
 * - Cargar assets (CSS / JS)
 * - Exponer endpoints AJAX
 * - Renderizar vista admin (page.php)
 *
 * Nota crítica anti “pantalla en blanco”:
 * - Render NO depende de que exista Estandar_WPE_Exporter.
 * - Si falta vista o hay error, muestra fallback visible.
 */

final class Estandar_WPE_Admin {

	/** @var Estandar_WPE_Exporter|null */
	private $exporter = null;

	/**
	 * Admin hook suffixes supported for enqueuing assets.
	 *
	 * - tools_page_* is the canonical WordPress Tools screen.
	 * - estandar_page_* is a safe future variant if the page is later registered under the Estandar hub.
	 *
	 * @return string[]
	 */
	private static function admin_hook_suffixes(): array {
		return [
			'tools_page_' . ESTANDAR_WPE_SLUG,
			// If later registered under the Estandar hub (callback mode), WP hook suffix will include the parent slug.
			'estandar_main_menu_page_' . ESTANDAR_WPE_SLUG,
			// Back-compat for earlier assumptions (kept harmlessly exact-match).
			'estandar_page_' . ESTANDAR_WPE_SLUG,
		];
	}

	private static function is_exporter_admin_screen( string $hook = '' ): bool {
		$hook = (string) $hook;
		$suffixes = self::admin_hook_suffixes();

		if ( '' !== $hook && in_array( $hook, $suffixes, true ) ) {
			return true;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && in_array( (string) $screen->id, $suffixes, true ) ) {
				return true;
			}
		}

		// Last-resort guard: allow only when the page param matches the plugin slug and we're on a page-based admin screen.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== ESTANDAR_WPE_SLUG ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		return in_array( $pagenow, [ 'tools.php', 'admin.php' ], true );
	}

	public function __construct() {

		// Menú y assets siempre (para evitar pantallas en blanco)
		add_action('admin_menu', [ $this, 'register_menu' ]);
		add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);

		// Exporter solo si existe (AJAX depende de esto)
		if ( class_exists('Estandar_WPE_Exporter') ) {
			$this->exporter = new Estandar_WPE_Exporter();
		}

		// AJAX (solo si hay exporter)
		add_action('wp_ajax_estandar_wpe_init', [ $this, 'ajax_init' ]);
		add_action('wp_ajax_estandar_wpe_step', [ $this, 'ajax_step' ]);
		add_action('wp_ajax_estandar_wpe_cleanup', [ $this, 'ajax_cleanup' ]);
		add_action('wp_ajax_estandar_wpe_download', [ $this, 'ajax_download' ]);
	}

	/* ======================================================
	   Admin UI
	====================================================== */

	public function register_menu() : void {
		add_management_page(
			'Estandar WP Exporter',
			'Estandar WP Exporter',
			'manage_options',
			ESTANDAR_WPE_SLUG,
			[ $this, 'render_page' ]
		);

		// Integración con Estandar Core (MU-plugin). Usa callback directo para evitar fallback por redirects tardíos.
		if ( function_exists( 'estandar_register_submenu' ) ) {
			estandar_register_submenu(
				[
					'slug'       => ESTANDAR_WPE_SLUG,
					'title'      => 'Estandar WP Exporter',
					'menu_title' => 'WP Exporter',
					'capability' => 'manage_options',
					'callback'   => [ $this, 'render_page' ],
				]
			);

			// Oculta entrada original en Herramientas (la página sigue existiendo y es accesible por URL).
			remove_submenu_page( 'tools.php', ESTANDAR_WPE_SLUG );
		}
	}

	public function enqueue_assets(string $hook) : void {

		if ( ! self::is_exporter_admin_screen( $hook ) ) {
			return;
		}

		// Si Estandar Core está activo (MU), no duplicar la capa UI.
		// Si no, mantener fallback local para no cambiar apariencia.
		if ( ! function_exists( 'primer_is_active' ) ) {
			$admin_ui_css = ESTANDAR_WPE_PATH . 'assets/estandar-admin-ui.css';
			if ( file_exists( $admin_ui_css ) ) {
				wp_enqueue_style(
					'estandar-wp-exporter-admin-ui',
					ESTANDAR_WPE_URL . 'assets/estandar-admin-ui.css',
					[],
					ESTANDAR_WPE_VERSION
				);
			}
		}

		wp_enqueue_style(
			'estandar-wp-exporter-admin',
			ESTANDAR_WPE_URL . 'assets/admin.css',
			[],
			ESTANDAR_WPE_VERSION
		);

		wp_enqueue_script(
			'estandar-wp-exporter-admin',
			ESTANDAR_WPE_URL . 'assets/admin.js',
			[ 'jquery' ],
			ESTANDAR_WPE_VERSION,
			true
		);

		wp_localize_script(
			'estandar-wp-exporter-admin',
			'EstandarWPE',
			[
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('estandar_wpe_nonce'),
				'presets' => $this->get_presets_payload(),
			]
		);
	}

	public function render_page() : void {

		if ( ! current_user_can('manage_options') ) {
			wp_die(__('No autorizado', 'estandar-wpe'));
		}

		// Preparar data para la vista
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$themes  = wp_get_themes();
		$current_theme = wp_get_theme();
		$jobs = Estandar_WPE_Job_Store::list_recent(12);

		$view = ESTANDAR_WPE_PATH . 'admin/views/page.php';

		// Fallbacks anti-blanco
		if ( ! file_exists($view) ) {
			echo '<div class="wrap estandar-admin"><h1>Estandar WP Exporter</h1>';
			echo '<p><strong>Error:</strong> No se encontró la vista:</p>';
			echo '<p><code>' . esc_html($view) . '</code></p></div>';
			return;
		}

		// Incluir vista de forma segura: si algo explota, al menos dejamos huella.
		// (Con WP_DEBUG off, esto ayuda a no quedar a ciegas.)
		ob_start();
		include $view;
		$html = ob_get_clean();

		if ( trim((string)$html) === '' ) {
			echo '<div class="wrap estandar-admin"><h1>Estandar WP Exporter</h1>';
			echo '<p><strong>La vista se incluyó pero no generó salida.</strong></p>';
			echo '<p>Revisa warnings/fatals en debug.log o errores de PHP del hosting.</p>';
			echo '</div>';
			return;
		}

		echo $html;
	}

	private function get_presets_payload() : array {

		$active_plugins = array_values((array) get_option('active_plugins', []));
		$current_theme  = wp_get_theme();
		$theme_stylesheet = $current_theme->get_stylesheet();
		$theme_template   = $current_theme->get_template();
		$theme_stack      = array_values(array_unique(array_filter([ $theme_stylesheet, $theme_template ])));
		$visual_plugins   = array_values(array_filter($active_plugins, static function (string $plugin_file) : bool {
			return strpos($plugin_file, 'elementor/') === 0
				|| strpos($plugin_file, 'essential-addons-for-elementor-lite/') === 0
				|| strpos($plugin_file, 'elementor-pro/') === 0;
		}));

		return [
			'activePlugins' => $active_plugins,
			'themeStack'    => $theme_stack,
			'visualStack'   => array_values(array_unique(array_merge($theme_stack, $visual_plugins))),
			'plugin'        => [ ESTANDAR_WPE_SLUG . '/' . ESTANDAR_WPE_SLUG . '.php' ],
		];
	}

	/* ======================================================
	   AJAX
	====================================================== */

	public function ajax_init() : void {

		check_ajax_referer('estandar_wpe_nonce', 'nonce');

		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['error' => 'No autorizado'], 403);
		}

		if ( ! $this->exporter ) {
			wp_send_json_error(['error' => 'Exporter no disponible (clase no cargada).'], 500);
		}

		$selection = [
			'plugins' => isset($_POST['plugins']) && is_array($_POST['plugins'])
				? array_map('sanitize_text_field', $_POST['plugins'])
				: [],
			'themes' => isset($_POST['themes']) && is_array($_POST['themes'])
				? array_map('sanitize_text_field', $_POST['themes'])
				: [],
		];

		$settings = [
			'batch_files' => isset($_POST['batch_files']) ? (int) $_POST['batch_files'] : 200,
			'max_file_mb' => isset($_POST['max_file_mb']) ? (int) $_POST['max_file_mb'] : 25,
		];

		$result = $this->exporter->init_job($selection, $settings);

		if ( empty($result['ok']) ) {
			wp_send_json_error($result, 400);
		}

		wp_send_json_success($result);
	}

	public function ajax_step() : void {

		check_ajax_referer('estandar_wpe_nonce', 'nonce');

		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['error' => 'No autorizado'], 403);
		}

		if ( ! $this->exporter ) {
			wp_send_json_error(['error' => 'Exporter no disponible (clase no cargada).'], 500);
		}

		$job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
		$token  = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

		if ( $job_id === '' || $token === '' ) {
			wp_send_json_error(['error' => 'Parámetros inválidos'], 400);
		}

		$result = $this->exporter->step_job($job_id, $token);

		if ( empty($result['ok']) ) {
			wp_send_json_error($result, 400);
		}

		wp_send_json_success($result);
	}
		public function ajax_cleanup() : void {

		check_ajax_referer('estandar_wpe_nonce', 'nonce');

		if ( ! current_user_can('manage_options') ) {
			wp_send_json_error(['error' => 'No autorizado'], 403);
		}

		if ( ! $this->exporter ) {
			wp_send_json_error(['error' => 'Exporter no disponible'], 500);
		}

		$job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
		$token  = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

		if ( $job_id === '' || $token === '' ) {
			wp_send_json_error(['error' => 'Parámetros inválidos'], 400);
		}

		$result = $this->exporter->cleanup_job($job_id, $token);

		if ( empty($result['ok']) ) {
			wp_send_json_error($result, 400);
		}

		wp_send_json_success($result);
	}

	public function ajax_download() : void {

		if ( ! current_user_can('manage_options') ) {
			wp_die('No autorizado', 403);
		}

		$job_id = isset($_GET['job_id']) ? sanitize_text_field($_GET['job_id']) : '';
		$token  = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

			if ( $job_id === '' ) {
				wp_die('Job inválido', 400);
			}

			$job_id = estandar_wpe_sanitize_slug($job_id);
			check_ajax_referer('estandar_wpe_download_' . $job_id, '_wpnonce');
			$job    = Estandar_WPE_Job_Store::get($job_id);

		if ( ! is_array($job) ) {
			wp_die('Job no encontrado', 404);
		}

		if ( empty($job['token']) || $job['token'] !== $token ) {
			wp_die('Token inválido', 403);
		}

		$zip_path = $job['result']['zip_path'] ?? '';

		if ( ! is_string($zip_path) || $zip_path === '' || ! estandar_wpe_is_path_within($zip_path, estandar_wpe_exports_dir()) || ! file_exists($zip_path) ) {
			wp_die('ZIP no disponible', 404);
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
		header('Content-Length: ' . filesize($zip_path));

		readfile($zip_path);

		exit;
	}
}
