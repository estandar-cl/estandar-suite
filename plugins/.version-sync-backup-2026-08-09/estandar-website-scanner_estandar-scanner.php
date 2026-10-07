<?php
/**
 * Plugin Name:       Estandar Website Scanner
 * Plugin URI:        https://www.estandar.cl
 * Description:       Analyze public websites and generate a technical diagnostic score and report.
 * Version: 13.0.172
 * Author:            Luis Rodrigo Valdés
 * Author URI:        https://www.estandar.cl
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       estandar-website-scanner
 * Update URI:        https://updates.estandar.cl
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EWS_VERSION', '13.0.158' );
define( 'EWS_PLUGIN_FILE', __FILE__ );
define( 'EWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EWS_PLUGIN_DIR . 'admin/dashboard.php';
require_once EWS_PLUGIN_DIR . 'admin/scanner-form.php';
require_once EWS_PLUGIN_DIR . 'admin/settings.php';
require_once EWS_PLUGIN_DIR . 'admin/report-design.php';
require_once EWS_PLUGIN_DIR . 'admin/ai-settings.php';

require_once EWS_PLUGIN_DIR . 'engine/site-fetcher.php';
require_once EWS_PLUGIN_DIR . 'engine/pagespeed-api.php';
require_once EWS_PLUGIN_DIR . 'engine/tech-detector.php';
require_once EWS_PLUGIN_DIR . 'engine/seo-checker.php';
require_once EWS_PLUGIN_DIR . 'engine/seo-opportunity-detector.php';
require_once EWS_PLUGIN_DIR . 'engine/accessibility-check.php';
require_once EWS_PLUGIN_DIR . 'engine/infrastructure-check.php';
require_once EWS_PLUGIN_DIR . 'engine/score-calculator.php';
require_once EWS_PLUGIN_DIR . 'engine/value-analyzer.php';
require_once EWS_PLUGIN_DIR . 'engine/crawler.php';
require_once EWS_PLUGIN_DIR . 'engine/conversion-check.php';
require_once EWS_PLUGIN_DIR . 'engine/scan-repository.php';
require_once EWS_PLUGIN_DIR . 'engine/scan-queue.php';
require_once EWS_PLUGIN_DIR . 'engine/broken-links.php';
require_once EWS_PLUGIN_DIR . 'engine/internal-structure.php';
require_once EWS_PLUGIN_DIR . 'engine/structured-data.php';
require_once EWS_PLUGIN_DIR . 'engine/keyword-signals.php';
require_once EWS_PLUGIN_DIR . 'engine/site-intent.php';
require_once EWS_PLUGIN_DIR . 'engine/competitor-snapshot.php';
require_once EWS_PLUGIN_DIR . 'engine/crux-api.php';
require_once EWS_PLUGIN_DIR . 'engine/url-safety.php';
require_once EWS_PLUGIN_DIR . 'engine/decision-engine.php';
require_once EWS_PLUGIN_DIR . 'includes/class-ai-analysis.php';
require_once EWS_PLUGIN_DIR . 'includes/class-client-reports.php';
require_once EWS_PLUGIN_DIR . 'includes/class-logger.php';
require_once EWS_PLUGIN_DIR . 'includes/class-report-i18n.php';
require_once EWS_PLUGIN_DIR . 'includes/class-report-design.php';

require_once EWS_PLUGIN_DIR . 'reports/report-generator.php';
require_once EWS_PLUGIN_DIR . 'reports/pdf-template.php';
require_once EWS_PLUGIN_DIR . 'templates/report-layout.php';

/**
 * Main plugin bootstrap.
 */
class Estandar_Website_Scanner {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::load_dompdf();
		self::ensure_schema();
		EWS_Client_Reports::init();
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_admin_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_action( 'ews_scan_queue_worker', array( __CLASS__, 'process_scan_queue' ) );
		add_action( 'wp_ajax_ews_enqueue_scan', array( __CLASS__, 'handle_enqueue_scan' ) );
		add_action( 'wp_ajax_ews_get_queue_status', array( __CLASS__, 'handle_get_queue_status' ) );
		add_action( 'wp_ajax_ews_get_queue_list', array( __CLASS__, 'handle_get_queue_list' ) );
		add_action( 'wp_ajax_ews_cancel_scan', array( __CLASS__, 'handle_cancel_scan' ) );
		add_action( 'wp_ajax_ews_approve_design_marketing', array( __CLASS__, 'handle_approve_design_marketing' ) );
		add_action( 'wp_ajax_estandar_run_scan', array( __CLASS__, 'handle_run_scan' ) );
		add_action( 'admin_post_ews_download_report', array( __CLASS__, 'handle_download_report' ) );
		add_action( 'admin_post_nopriv_ews_download_report', array( __CLASS__, 'handle_download_report' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dompdf_notice' ) );
		self::ensure_queue_worker_scheduled();
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		EWS_Scan_Repository::create_table();
		EWS_Scan_Queue::create_table();
		EWS_Client_Reports::register_post_type();
		self::ensure_queue_worker_scheduled();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ews_scan_queue_worker' );
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'ews_settings_group',
			'estandar_pagespeed_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'ews_settings_group',
			'estandar_crux_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'ews_settings_group',
			'estandar_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				)
			);
			register_setting(
				'ews_settings_group',
				'ews_report_cta',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'default'           => '',
				)
			);
			register_setting(
				'ews_settings_group',
				'ews_show_cta',
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 1,
				)
			);
			register_setting(
				'ews_settings_group',
				'ews_default_report_language',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'es',
				)
			);
			register_setting(
				'ews_report_design_group',
				'ews_report_layout_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'EWS_Report_Design', 'sanitize' ),
				'default'           => EWS_Report_Design::defaults(),
			)
		);
		register_setting( 'ews_ai_settings_group', 'ews_prompt_executive', array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ) );
		register_setting( 'ews_ai_settings_group', 'ews_prompt_technical', array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ) );
		register_setting( 'ews_ai_settings_group', 'ews_prompt_seo', array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ) );
		register_setting( 'ews_ai_settings_group', 'ews_prompt_conversion', array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ) );
	}

	/**
	 * Load DOMPDF autoloader from plugin vendor directory.
	 *
	 * @return void
	 */
	private static function load_dompdf(): void {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return;
		}

		$composer_autoload = EWS_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $composer_autoload ) ) {
			require_once $composer_autoload;
		}

		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return;
		}

		$legacy_autoload = EWS_PLUGIN_DIR . 'vendor/dompdf/dompdf/autoload.inc.php';
		if ( file_exists( $legacy_autoload ) ) {
			require_once $legacy_autoload;
		}
	}

	/**
	 * Show admin notice when DOMPDF dependency is missing.
	 *
	 * @return void
	 */
	public static function maybe_show_dompdf_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( empty( $screen ) || false === strpos( (string) $screen->id, 'estandar-scanner' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'PDF export requires DOMPDF. Run "composer install" inside the plugin folder to enable PDF downloads.', 'estandar-website-scanner' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register plugin menu and pages.
	 *
	 * @return void
	 */
	public static function register_admin_menu(): void {
		$pages = self::admin_pages();
		$core_active = function_exists( 'primer_is_active' ) && primer_is_active();
		$main_callback = array( __CLASS__, 'render_admin_page' );

		if ( $core_active && function_exists( 'estandar_register_submenu' ) ) {
			$main = $pages['estandar-scanner-run'];
			estandar_register_submenu(
				array(
					'slug'       => 'estandar-scanner-run',
					'title'      => $main['title'],
					'menu_title' => __( 'Scanner', 'estandar-website-scanner' ),
					'capability' => 'manage_options',
					'callback'   => $main_callback,
				)
			);

			return;
		}

		add_menu_page(
			$pages['estandar-scanner-run']['title'],
			$pages['estandar-scanner-run']['menu_title'],
			'manage_options',
			'estandar-scanner-run',
			$main_callback,
			'dashicons-chart-area',
			26
		);
	}

	/**
	 * Redirect old internal admin page slugs to the unified tabbed screen.
	 *
	 * @return void
	 */
	public static function redirect_legacy_admin_pages(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$map  = self::legacy_tab_map();

		if ( ! isset( $map[ $page ] ) || 'estandar-scanner-run' === $page ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'estandar-scanner-run',
					'tab'  => $map[ $page ],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the single Scanner admin page and route internal tabs.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'estandar-website-scanner' ) );
		}

		$tabs       = self::admin_tabs();
		$active_tab = self::resolve_admin_tab();
		$active     = $tabs[ $active_tab ] ?? $tabs['run'];
		?>
		<div class="wrap estandar-admin ews-admin-tabs">
			<h1><?php esc_html_e( 'Estandar Scanner', 'estandar-website-scanner' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Scanner sections', 'estandar-website-scanner' ); ?>">
				<?php foreach ( $tabs as $tab_key => $tab ) : ?>
					<?php
					$url     = add_query_arg(
						array(
							'page' => 'estandar-scanner-run',
							'tab'  => $tab_key,
						),
						admin_url( 'admin.php' )
					);
					$classes = ( $tab_key === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
					?>
					<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( (string) $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php

		$callback = $active['callback'];
		if ( is_callable( $callback ) ) {
			call_user_func( $callback );
		}
	}

	/**
	 * Admin pages registry.
	 *
	 * @return array<string,array{title:string,menu_title:string,callback:callable}>
	 */
	private static function admin_pages(): array {
		return array(
			'estandar-scanner-run' => array(
				'title'      => __( 'Estandar Scanner', 'estandar-website-scanner' ),
				'menu_title' => __( 'Estandar Scanner', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_scanner_form_page',
			),
			'estandar-scanner-dashboard' => array(
				'title'      => __( 'Dashboard', 'estandar-website-scanner' ),
				'menu_title' => __( 'Dashboard', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_dashboard_page',
			),
			'estandar-scanner-history' => array(
				'title'      => __( 'Scan History', 'estandar-website-scanner' ),
				'menu_title' => __( 'Scan History', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_scan_history_page',
			),
			'estandar-scanner-clients' => array(
				'title'      => __( 'Client Reports', 'estandar-website-scanner' ),
				'menu_title' => __( 'Client Reports', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_client_reports_page',
			),
			'estandar-scanner-debug' => array(
				'title'      => __( 'Debug Logs', 'estandar-website-scanner' ),
				'menu_title' => __( 'Debug Logs', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_debug_logs_page',
			),
			'estandar-scanner-report-design' => array(
				'title'      => __( 'Report Design', 'estandar-website-scanner' ),
				'menu_title' => __( 'Report Design', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_report_design_page',
			),
			'estandar-scanner-ai-settings' => array(
				'title'      => __( 'AI Settings', 'estandar-website-scanner' ),
				'menu_title' => __( 'AI Settings', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_ai_settings_page',
			),
			'estandar-scanner-settings' => array(
				'title'      => __( 'Settings', 'estandar-website-scanner' ),
				'menu_title' => __( 'Settings', 'estandar-website-scanner' ),
				'callback'   => 'ews_render_settings_page',
			),
		);
	}

	/**
	 * Unified tab registry.
	 *
	 * @return array<string,array{label:string,callback:callable,legacy_slug:string}>
	 */
	private static function admin_tabs(): array {
		$pages = self::admin_pages();

		return array(
			'run' => array(
				'label'       => __( 'Run Scan', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-run']['callback'],
				'legacy_slug' => 'estandar-scanner-run',
			),
			'dashboard' => array(
				'label'       => __( 'Dashboard', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-dashboard']['callback'],
				'legacy_slug' => 'estandar-scanner-dashboard',
			),
			'history' => array(
				'label'       => __( 'Scan History', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-history']['callback'],
				'legacy_slug' => 'estandar-scanner-history',
			),
			'reports' => array(
				'label'       => __( 'Client Reports', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-clients']['callback'],
				'legacy_slug' => 'estandar-scanner-clients',
			),
			'logs' => array(
				'label'       => __( 'Debug Logs', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-debug']['callback'],
				'legacy_slug' => 'estandar-scanner-debug',
			),
			'design' => array(
				'label'       => __( 'Report Design', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-report-design']['callback'],
				'legacy_slug' => 'estandar-scanner-report-design',
			),
			'ai' => array(
				'label'       => __( 'AI Settings', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-ai-settings']['callback'],
				'legacy_slug' => 'estandar-scanner-ai-settings',
			),
			'settings' => array(
				'label'       => __( 'Settings', 'estandar-website-scanner' ),
				'callback'    => $pages['estandar-scanner-settings']['callback'],
				'legacy_slug' => 'estandar-scanner-settings',
			),
		);
	}

	/**
	 * Legacy page slug to tab key map.
	 *
	 * @return array<string,string>
	 */
	private static function legacy_tab_map(): array {
		$map = array();
		foreach ( self::admin_tabs() as $tab_key => $tab ) {
			$map[ (string) $tab['legacy_slug'] ] = (string) $tab_key;
		}

		return $map;
	}

	/**
	 * Resolve the active Scanner tab from the current request.
	 *
	 * @return string
	 */
	private static function resolve_admin_tab(): string {
		$tabs = self::admin_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$map  = self::legacy_tab_map();

		return $map[ $page ] ?? 'run';
	}

	/**
	 * Load plugin assets in scanner pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'estandar-scanner' ) ) {
			return;
		}

		wp_enqueue_media();

		$admin_ui_css = EWS_PLUGIN_DIR . 'assets/css/estandar-admin-ui.css';
		$core_active = function_exists( 'primer_is_active' ) && primer_is_active();
		if ( ! $core_active && file_exists( $admin_ui_css ) ) {
			wp_enqueue_style(
				'estandar-website-scanner-admin-ui',
				EWS_PLUGIN_URL . 'assets/css/estandar-admin-ui.css',
				array(),
				EWS_VERSION
			);
		}

		wp_enqueue_style(
			'estandar-website-scanner-admin',
			EWS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			EWS_VERSION
		);

		wp_enqueue_script( 'ews-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), EWS_VERSION, true );
		wp_enqueue_script( 'ews-d3', 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js', array(), EWS_VERSION, true );
		wp_enqueue_script(
			'estandar-website-scanner-admin',
			EWS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'ews-chartjs', 'ews-d3' ),
			EWS_VERSION,
			true
		);

		wp_localize_script(
			'estandar-website-scanner-admin',
			'estandarScanner',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'estandar_run_scan_nonce' ),
				'totalSteps' => 6,
			)
		);
	}

	/**
	 * Backward-compatible direct scan endpoint.
	 *
	 * @return void
	 */
	public static function handle_run_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}

		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );

		$raw_url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$auto_competitors = ! empty( $_POST['auto_competitors'] );
		$raw_competitors = isset( $_POST['competitors'] ) ? sanitize_text_field( wp_unslash( $_POST['competitors'] ) ) : '';
		$cover_image_url = isset( $_POST['cover_image_url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['cover_image_url'] ) ) ) : '';
		$website_image_url = isset( $_POST['website_image_url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['website_image_url'] ) ) ) : '';
		$url = esc_url_raw( $raw_url );
		$competitors = array_slice(
			array_filter(
				array_map(
					'trim',
					explode( ',', $raw_competitors )
				)
			),
			0,
			3
		);

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid URL.', 'estandar-website-scanner' ) ), 400 );
		}
		try {
			EWS_Url_Safety::assert_safe_url( $url, 'scan target' );
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => __( 'Unsafe URL provided.', 'estandar-website-scanner' ) ), 400 );
		}

		try {
			$report = self::build_scan_report(
				$url,
				$competitors,
				null,
				$auto_competitors,
				array(
					'cover_image_url' => $cover_image_url,
					'website_image_url' => $website_image_url,
				)
			);
		} catch ( Exception $e ) {
			EWS_Logger::log( 'ERROR (direct scan): ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success( self::with_download_url( $report ) );
	}

	/**
	 * Enqueue scan and return queue ID.
	 *
	 * @return void
	 */
	public static function handle_enqueue_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}

		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );

		$url = isset( $_POST['url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) : '';
		$cover_image_url = isset( $_POST['cover_image_url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['cover_image_url'] ) ) ) : '';
		$website_image_url = isset( $_POST['website_image_url'] ) ? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['website_image_url'] ) ) ) : '';
		$auto_competitors  = ! empty( $_POST['auto_competitors'] );
		$raw_competitors = isset( $_POST['competitors'] ) ? sanitize_text_field( wp_unslash( $_POST['competitors'] ) ) : '';
		$competitors = array_slice(
			array_filter(
				array_map(
					'trim',
					explode( ',', $raw_competitors )
				)
			),
			0,
			3
		);

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid URL.', 'estandar-website-scanner' ) ), 400 );
		}
		try {
			EWS_Url_Safety::assert_safe_url( $url, 'scan target' );
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => __( 'Unsafe URL provided.', 'estandar-website-scanner' ) ), 400 );
		}

		$queue_id = EWS_Scan_Queue::enqueue(
			$url,
			$competitors,
			array(
				'cover_image_url' => $cover_image_url,
				'website_image_url' => $website_image_url,
			),
			$auto_competitors
		);
		EWS_Logger::log( 'Scan queued #' . $queue_id . ' for ' . $url );

		self::ensure_queue_worker_scheduled();
		wp_schedule_single_event( time() + 3, 'ews_scan_queue_worker' );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		wp_send_json_success(
			array(
				'queue_id' => $queue_id,
				'message'  => __( 'Scan added to queue.', 'estandar-website-scanner' ),
			)
		);
	}

	/**
	 * Return queue row status.
	 *
	 * @return void
	 */
	public static function handle_get_queue_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}
		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );

		$queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
		if ( $queue_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid queue ID.', 'estandar-website-scanner' ) ), 400 );
		}

		$row = EWS_Scan_Queue::get_by_id( $queue_id );
		if ( empty( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'Queue item not found.', 'estandar-website-scanner' ) ), 404 );
		}

		$payload = array(
			'queue_id'  => (int) $row['id'],
			'status'    => (string) $row['status'],
			'step'      => (int) ( $row['progress_step'] ?? 0 ),
			'total'     => (int) ( $row['progress_total'] ?? 6 ),
			'message'   => (string) ( $row['progress_message'] ?? '' ),
			'error'     => (string) ( $row['error_message'] ?? '' ),
			'progress'  => round( ( (int) ( $row['progress_step'] ?? 0 ) / max( 1, (int) ( $row['progress_total'] ?? 6 ) ) ) * 100 ),
		);

		if ( 'completed' === $row['status'] ) {
			$report = null;
			if ( ! empty( $row['results_json'] ) ) {
				$decoded = json_decode( (string) $row['results_json'], true );
				if ( is_array( $decoded ) ) {
					$report = $decoded;
				}
			}
			if ( ! is_array( $report ) && ! empty( $row['report_id'] ) ) {
				$report = EWS_Report_Generator::get_report( (string) $row['report_id'] );
				if ( ! is_array( $report ) ) {
					$report = EWS_Scan_Repository::get_report_by_id( (string) $row['report_id'] );
				}
			}
			if ( is_array( $report ) ) {
				$payload['report'] = self::with_download_url( $report );
			}
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Return queue list.
	 *
	 * @return void
	 */
	public static function handle_get_queue_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}
		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );
		wp_send_json_success( array( 'rows' => EWS_Scan_Queue::get_recent( 20 ) ) );
	}

	/**
	 * Cancel a queued scan item.
	 *
	 * @return void
	 */
	public static function handle_cancel_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}
		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );

		$queue_id = isset( $_POST['queue_id'] ) ? absint( (string) wp_unslash( $_POST['queue_id'] ) ) : 0;
		if ( $queue_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid queue id.', 'estandar-website-scanner' ) ), 400 );
		}

		$row = EWS_Scan_Queue::get_by_id( $queue_id );
		if ( empty( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'Queue item not found.', 'estandar-website-scanner' ) ), 404 );
		}

		$status = (string) ( $row['status'] ?? '' );
		if ( 'completed' === $status ) {
			wp_send_json_error( array( 'message' => __( 'This scan is already completed.', 'estandar-website-scanner' ) ), 400 );
		}
		if ( 'cancelled' === $status ) {
			wp_send_json_success( array( 'queue_id' => $queue_id, 'message' => __( 'Cancellation already requested.', 'estandar-website-scanner' ) ) );
		}

		EWS_Scan_Queue::mark_cancelled( $queue_id, 'Cancelled by user.' );
		wp_send_json_success( array( 'queue_id' => $queue_id, 'message' => __( 'Cancellation requested.', 'estandar-website-scanner' ) ) );
	}

	/**
	 * Approve and save Design & Marketing narrative into the report payload.
	 *
	 * @return void
	 */
	public static function handle_approve_design_marketing(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'estandar-website-scanner' ) ), 403 );
		}
		check_ajax_referer( 'estandar_run_scan_nonce', 'nonce' );

		$report_id = isset( $_POST['report_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['report_id'] ) ) : '';
		$text      = isset( $_POST['design_marketing_text'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['design_marketing_text'] ) ) : '';

		if ( '' === $report_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid report id.', 'estandar-website-scanner' ) ), 400 );
		}

		$report = EWS_Report_Generator::get_report( $report_id );
		if ( ! is_array( $report ) ) {
			wp_send_json_error( array( 'message' => __( 'Report not found.', 'estandar-website-scanner' ) ), 404 );
		}

		$report['design_marketing_analysis_draft'] = (string) ( $report['design_marketing_analysis_draft'] ?? '' );
		$report['design_marketing_analysis_approved'] = $text;
		$report['design_marketing_analysis_status'] = '' !== trim( $text ) ? 'approved' : 'pending';

		EWS_Report_Generator::update_report( $report );
		wp_send_json_success( array( 'message' => __( 'Saved.', 'estandar-website-scanner' ), 'approved' => $report['design_marketing_analysis_approved'] ) );
	}

	/**
	 * Process next pending queue scan.
	 *
	 * @return void
	 */
	public static function process_scan_queue(): void {
		// Global guard to reduce concurrent scans from multiple cron triggers.
		$lock_key = 'ews_scan_queue_worker_lock';
		if ( false !== get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 90 );

		if ( EWS_Scan_Queue::has_running() ) {
			delete_transient( $lock_key );
			return;
		}

		$item = EWS_Scan_Queue::get_next_pending();
		if ( empty( $item ) ) {
			return;
		}

		$queue_id = (int) ( $item['id'] ?? 0 );
		if ( $queue_id <= 0 ) {
			return;
		}

		$url = esc_url_raw( (string) ( $item['target_url'] ?? '' ) );
		$competitors = EWS_Scan_Queue::decode_competitors( $item );
		$auto_competitors = EWS_Scan_Queue::decode_auto_competitors( $item );
		$assets = EWS_Scan_Queue::decode_assets( $item );
		EWS_Scan_Queue::mark_running( $queue_id );
		EWS_Logger::log( 'Queue #' . $queue_id . ' started for ' . $url );

		try {
			$report = self::build_scan_report( $url, $competitors, $queue_id, $auto_competitors, $assets );
			EWS_Scan_Queue::mark_completed( $queue_id, $report );
			EWS_Logger::log( 'Queue #' . $queue_id . ' completed. Report: ' . (string) ( $report['report_id'] ?? '' ) );
		} catch ( Exception $e ) {
			if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
				EWS_Scan_Queue::mark_cancelled( $queue_id, $e->getMessage() );
				EWS_Logger::log( 'Queue #' . $queue_id . ' cancelled.' );
			} else {
				EWS_Scan_Queue::mark_failed( $queue_id, $e->getMessage() );
				EWS_Logger::log( 'ERROR Queue #' . $queue_id . ': ' . $e->getMessage() );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Build one full report from URL.
	 *
	 * @param string             $url Target URL.
	 * @param array<int, string> $competitors Competitors list.
	 * @param int|null           $queue_id Queue ID.
	 * @return array<string, mixed>
	 * @throws Exception When scan fails.
	 */
	private static function build_scan_report( string $url, array $competitors, ?int $queue_id, bool $auto_competitors = false, array $assets = array() ): array {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new Exception( 'Invalid URL.' );
		}
		// Mitigate SSRF-like issues by restricting private/loopback/link-local targets.
		EWS_Url_Safety::assert_safe_url( $url, 'scan target' );
		// If assets are unsafe, drop them so we never download/inline them during PDF generation.
		if ( ! empty( $assets['cover_image_url'] ) && ! EWS_Url_Safety::is_safe_url( (string) $assets['cover_image_url'] ) ) {
			$assets['cover_image_url'] = '';
		}
		if ( ! empty( $assets['website_image_url'] ) && ! EWS_Url_Safety::is_safe_url( (string) $assets['website_image_url'] ) ) {
			$assets['website_image_url'] = '';
		}

		$pagespeed = null;

		// Screenshot capability: if no manual override is provided, generate a screenshot URL.
		// This makes the screenshot consistently "applied" to the report payload.
		if ( empty( $assets['website_image_url'] ) ) {
			// Alternative screenshot capture: Lighthouse thumbnails via PageSpeed API.
			// This returns a small base64 image embedded as a `data:` URI.
			if ( class_exists( 'EWS_PageSpeed_API' ) ) {
				if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
					throw new Exception( 'Scan cancelled.' );
				}

				$pagespeed_payload = EWS_PageSpeed_API::run( $url );
				if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
					throw new Exception( 'Scan cancelled.' );
				}
				$shot_data_uri     = '';
				$shot_data_uri     = (string) ( $pagespeed_payload['desktop']['screenshot_data_uri'] ?? '' );
				if ( '' === trim( $shot_data_uri ) ) {
					$shot_data_uri = (string) ( $pagespeed_payload['mobile']['screenshot_data_uri'] ?? '' );
				}
				if ( '' !== trim( $shot_data_uri ) && 0 === strpos( $shot_data_uri, 'data:' ) ) {
					$assets['website_image_url'] = $shot_data_uri;
					$pagespeed = $pagespeed_payload; // Reuse later in Step 2/6.
				} else {
					// Still reuse the payload later for scoring checks.
					$pagespeed = $pagespeed_payload;
				}
			}

			// Fallback to existing screenshot service.
			if ( empty( $assets['website_image_url'] ) && function_exists( 'ews_report_website_screenshot_url' ) ) {
				$generated = (string) ews_report_website_screenshot_url( $url );
				if ( '' !== trim( $generated ) && EWS_Url_Safety::is_safe_url( $generated ) ) {
					$assets['website_image_url'] = $generated;
				}
			}
		}

		$scan_started = microtime( true );
		$progress_timeline     = array();
		$timeline_start        = $scan_started;
		$progress_total_steps  = 6;
		$log_progress          = function ( int $step, string $message ) use ( &$progress_timeline, $queue_id, $timeline_start, $progress_total_steps ): void {
			$elapsed_s = microtime( true ) - $timeline_start;
			$progress_timeline[] = array(
				't_ms'              => (int) round( $elapsed_s * 1000 ),
				'step'              => $step,
				'message'          => $message,
				'progress_percent' => round( ( $step / max( 1, $progress_total_steps ) ) * 100, 2 ),
			);
			self::update_scan_progress( $queue_id, $step, $message );
		};
		$max_seconds  = (float) apply_filters( 'ews_max_scan_seconds', 180 );
		$deadline_ts  = $scan_started + max( 1, $max_seconds );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) max( 1, min( 300, $max_seconds ) ) );
		}

		$openai_key_hash = md5( (string) get_option( 'estandar_openai_api_key', '' ) );
		$asset_hash      = md5( wp_json_encode( $assets ) );
		$scan_cache_key = 'ews_scan_v10_' . md5( $url . '|' . wp_json_encode( $competitors ) . '|' . $openai_key_hash . '|' . $asset_hash );
		$cached_result  = get_transient( $scan_cache_key );
		if ( false !== $cached_result && is_array( $cached_result ) ) {
			return $cached_result;
		}

		if ( microtime( true ) > $deadline_ts ) {
			throw new Exception( 'Scan time budget exceeded.' );
		}

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$log_progress( 1, 'Step 1/6: Fetching website' );
		$site_probe = EWS_Site_Fetcher::fetch( $url );
		if ( ! empty( $site_probe['error'] ) ) {
			EWS_Logger::log( 'Warning fetch stage: ' . (string) $site_probe['error'] );
		}

		if ( microtime( true ) > $deadline_ts ) {
			throw new Exception( 'Scan time budget exceeded.' );
		}

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$log_progress( 2, 'Step 2/6: Running performance analysis' );
		if ( null === $pagespeed ) {
			$pagespeed = EWS_PageSpeed_API::run( $url );
		}
		$crux      = EWS_CrUX_API::query( $url );

		if ( microtime( true ) > $deadline_ts ) {
			throw new Exception( 'Scan time budget exceeded.' );
		}

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$log_progress( 3, 'Step 3/6: Running technical checks' );
		$tech      = EWS_Tech_Detector::analyze( $url );
		$crawler_limit = (int) apply_filters( 'ews_crawler_page_limit', 30 );
		$crawler_limit = max( 10, min( 50, $crawler_limit ) );
		$crawler   = EWS_Crawler::crawl( $url, $crawler_limit, $queue_id );
		$seo       = EWS_SEO_Checker::analyze( $url, $crawler );
		$access    = EWS_Accessibility_Check::analyze( $url, $pagespeed );
		$infra     = EWS_Infrastructure_Check::analyze( $url );

		if ( microtime( true ) > $deadline_ts ) {
			throw new Exception( 'Scan time budget exceeded.' );
		}

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}

		$log_progress( 4, 'Step 4/6: Running crawler modules' );
		$conversion         = EWS_Conversion_Check::analyze( $url );
		$broken_links       = EWS_Broken_Links::analyze( $crawler, $queue_id );
		$internal_structure = EWS_Internal_Structure::analyze( $crawler );
		$structured_data    = EWS_Structured_Data::analyze( $crawler );
		$keyword_signals    = EWS_Keyword_Signals::analyze( $url );
		$site_intent        = EWS_Site_Intent::analyze( $url, $site_probe, $crawler, $conversion, $keyword_signals );
		$seo_opportunities  = EWS_SEO_Opportunity_Detector::analyze( $crawler, $seo, $access, $structured_data );
		$report_language    = self::resolve_report_language();

		$performance_score = ( isset( $pagespeed['scores']['performance'] ) && is_numeric( $pagespeed['scores']['performance'] ) )
			? (float) $pagespeed['scores']['performance']
			: null;
		$crux_score = EWS_CrUX_API::score_from_metrics( $crux );
		if ( is_numeric( $crux_score ) ) {
			$performance_score = round( ( $performance_score ?? 0 ) * 0.4 + (float) $crux_score * 0.6, 2 );
		}

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$log_progress( 5, 'Step 5/6: Running competitor and AI analysis' );
		$score_breakdown = EWS_Score_Calculator::calculate(
			array(
				'performance'          => $performance_score,
				'seo_technical'        => $seo['score'] ?? 0,
				'accessibility'        => $access['score'] ?? 0,
				'infrastructure'       => $infra['score'] ?? 0,
				'architecture'         => $tech['score'] ?? 0,
				'crawler_structure'    => $internal_structure['score'] ?? 0,
				'conversion_signals'   => $conversion['score'] ?? 0,
				'broken_links'         => $broken_links['score'] ?? 0,
				'structured_data'      => $structured_data['score'] ?? 0,
				'keyword_signals'      => $keyword_signals['score'] ?? 0,
			)
		);
		if ( microtime( true ) > $deadline_ts ) {
			throw new Exception( 'Scan time budget exceeded.' );
		}

		// Auto-detect competitors with AI using initial analysis signals.
		if ( $auto_competitors ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$host = strtolower( trim( $host ) );

			$ai_context = array(
				'purpose'          => array(
					'detected_intent' => $site_intent['detected_intent'] ?? '',
					'expected_intent' => $site_intent['expected_intent'] ?? '',
					'alignment_score' => $site_intent['alignment_score'] ?? 0,
					'impact'          => $site_intent['impact'] ?? '',
				),
				'keyword_signals' => $keyword_signals,
				'conversion'      => $conversion,
				'tech'            => $tech,
				'seo_opportunities'=> $seo_opportunities,
			);

			$competitors = EWS_AI_Analysis::detect_competitors_via_ai(
				$url,
				$ai_context,
				array( $host ),
				$report_language,
				$competitors,
				$deadline_ts
			);
		}

		$competitor_snapshot = EWS_Competitor_Snapshot::analyze(
			$url,
			$competitors,
			array( 'score' => $score_breakdown ),
			$deadline_ts,
			$queue_id
		);

		$value_analysis = EWS_Value_Analyzer::generate(
			array(
				'score'         => $score_breakdown,
				'pagespeed'     => $pagespeed,
				'seo'           => $seo,
				'accessibility' => $access,
				'infra'         => $infra,
				'tech'          => $tech,
				'site_intent'   => $site_intent,
				'diagnostics'   => array(
					'pagespeed_errors'       => $pagespeed['errors'] ?? array(),
					'pagespeed_available'    => ! empty( $pagespeed['available'] ),
					'unavailable_components' => $score_breakdown['unavailable_components'] ?? array(),
				),
			)
		);

		$crawler_report = $crawler;
		if ( ! empty( $crawler_report['pages'] ) && is_array( $crawler_report['pages'] ) ) {
			foreach ( $crawler_report['pages'] as $index => $page ) {
				if ( isset( $page['html'] ) ) {
					unset( $crawler_report['pages'][ $index ]['html'] );
				}
			}
		}

		$raw_report_payload = array(
			'url'                 => $url,
			'score'               => $score_breakdown,
			'pagespeed'           => $pagespeed,
			'tech'                => $tech,
			'seo'                 => $seo,
			'accessibility'       => $access,
			'infra'               => $infra,
			'crawler'             => $crawler_report,
			'internal_structure'  => $internal_structure,
			'broken_links'        => $broken_links,
			'structured_data'     => $structured_data,
			'keyword_signals'     => $keyword_signals,
			'site_intent'         => $site_intent,
			'competitor_snapshot' => $competitor_snapshot,
			'crux'                => $crux,
			'conversion'          => $conversion,
			'progress_timeline'  => $progress_timeline,
			'diagnostics'         => array(
				'pagespeed_errors'       => $pagespeed['errors'] ?? array(),
				'pagespeed_available'    => ! empty( $pagespeed['available'] ),
				'crux_available'         => ! empty( $crux['available'] ),
				'crux_error'             => $crux['error'] ?? null,
				'unavailable_components' => $score_breakdown['unavailable_components'] ?? array(),
			),
			'competitors'         => $competitors,
			'value_analysis'      => $value_analysis,
			'seo_opportunities'   => $seo_opportunities,
			'report_language'     => $report_language,
			'custom_cover_image'  => ( isset( $assets['cover_image_url'] ) && 0 === strpos( (string) $assets['cover_image_url'], 'data:' ) ) ? (string) $assets['cover_image_url'] : esc_url_raw( (string) ( $assets['cover_image_url'] ?? '' ) ),
			'custom_website_image'=> ( isset( $assets['website_image_url'] ) && 0 === strpos( (string) $assets['website_image_url'], 'data:' ) ) ? (string) $assets['website_image_url'] : esc_url_raw( (string) ( $assets['website_image_url'] ?? '' ) ),
		);
		$raw_report_payload['ai_analysis'] = EWS_AI_Analysis::analyze_scan_results( $raw_report_payload );

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$design_marketing_ai = EWS_AI_Analysis::analyze_design_marketing_analysis( $raw_report_payload );
		$design_marketing_draft = (string) ( $design_marketing_ai['draft'] ?? '' );
		$raw_report_payload['design_marketing_analysis_draft'] = $design_marketing_draft;
		$raw_report_payload['design_marketing_analysis_approved'] = '';
		$raw_report_payload['design_marketing_analysis_status'] = '' !== trim( $design_marketing_draft ) ? 'pending' : 'unavailable';
		$decision_layer = EWS_Decision_Engine::analyze( $raw_report_payload );
		$raw_report_payload = array_merge( $raw_report_payload, $decision_layer );

		if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
			throw new Exception( 'Scan cancelled.' );
		}
		$log_progress( 6, 'Step 6/6: Generating report' );
		$report_data = EWS_Report_Generator::build( $raw_report_payload );
		EWS_Scan_Repository::save_scan( $report_data );
		EWS_Client_Reports::sync_from_scan( $report_data );
		set_transient( $scan_cache_key, $report_data, 10 * MINUTE_IN_SECONDS );

		return $report_data;
	}

	/**
	 * Log and write queue progress.
	 *
	 * @param int|null $queue_id Queue ID.
	 * @param int      $step Step number.
	 * @param string   $message Message.
	 * @return void
	 */
	private static function update_scan_progress( ?int $queue_id, int $step, string $message ): void {
		EWS_Logger::log( $message );
		if ( is_int( $queue_id ) && $queue_id > 0 ) {
			EWS_Scan_Queue::update_progress( $queue_id, $step, $message );
		}
	}

	/**
	 * Register one-minute cron interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['ews_every_minute'] ) ) {
			$schedules['ews_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (Estandar Scanner)', 'estandar-website-scanner' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure queue worker cron is scheduled.
	 *
	 * @return void
	 */
	private static function ensure_queue_worker_scheduled(): void {
		if ( ! wp_next_scheduled( 'ews_scan_queue_worker' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ews_every_minute', 'ews_scan_queue_worker' );
		}
	}

	/**
	 * Resolve default report language.
	 *
	 * @return string
	 */
	private static function resolve_report_language(): string {
		$lang = sanitize_text_field( (string) get_option( 'ews_default_report_language', 'es' ) );
		return in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'es';
	}

	/**
	 * Ensure DB schema exists for queue/repository.
	 *
	 * @return void
	 */
	private static function ensure_schema(): void {
		EWS_Scan_Repository::create_table();
		EWS_Scan_Queue::create_table();
	}

	/**
	 * Attach a fresh nonce-based download URL to report payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, mixed>
	 */
	private static function with_download_url( array $report ): array {
		$report_id = isset( $report['report_id'] ) ? sanitize_text_field( (string) $report['report_id'] ) : '';
		if ( '' === $report_id ) {
			return $report;
		}

		$nonce = wp_create_nonce( 'ews_download_report_' . $report_id );
		$report['download_url'] = add_query_arg(
			array(
				'action'   => 'ews_download_report',
				'report_id'=> $report_id,
				'_wpnonce' => $nonce,
				'nonce'    => $nonce,
			),
			admin_url( 'admin-post.php' )
		);

		return $report;
	}

	/**
	 * Handle PDF report download.
	 *
	 * @return void
	 */
	public static function handle_download_report(): void {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'estandar-website-scanner' ) );
		}

		$report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';
		$lang      = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : 'en';
		$lang      = in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'en';
		$nonce     = '';
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		} elseif ( isset( $_GET['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
		}

		if ( empty( $report_id ) || ! wp_verify_nonce( $nonce, 'ews_download_report_' . $report_id ) ) {
			wp_die( esc_html__( 'Invalid report request.', 'estandar-website-scanner' ) );
		}

		$report = EWS_Report_Generator::get_report( $report_id );
		if ( empty( $report ) ) {
			$report = EWS_Scan_Repository::get_report_by_id( $report_id );
		}
		if ( empty( $report ) ) {
			wp_die( esc_html__( 'Report not found.', 'estandar-website-scanner' ) );
		}

		EWS_PDF_Template::stream( $report, $lang );
	}
}

register_activation_hook( EWS_PLUGIN_FILE, array( 'Estandar_Website_Scanner', 'activate' ) );
register_deactivation_hook( EWS_PLUGIN_FILE, array( 'Estandar_Website_Scanner', 'deactivate' ) );
Estandar_Website_Scanner::init();
