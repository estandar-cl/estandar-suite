<?php
/**
 * Arranque del plugin: assets y submódulos.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		$settings = new Estandar_Delivery_Locator_Settings();
		$settings->register();

		$admin_orders = new Estandar_Delivery_Locator_Admin_Orders();
		$admin_orders->register();

		$ajax = new Estandar_Delivery_Locator_Ajax();
		$ajax->register();

		$shortcode = new Estandar_Delivery_Locator_Shortcode();
		$shortcode->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( (string) $hook, 'estandar-dl' ) === false ) {
			return;
		}

		if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
			estandar_core_enqueue_admin_ui();
		}
	}

	/**
	 * Carga traducciones (si existen /languages/).
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'estandar-delivery-locator-lite',
			false,
			dirname( ESTANDAR_DL_BASENAME ) . '/languages'
		);
	}

	/**
	 * Registra hojas y scripts (encolado desde el shortcode).
	 */
	public function register_assets() {
		$ver = ESTANDAR_DL_VERSION;

		wp_register_style(
			'estandar-dl-leaflet',
			'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css',
			array(),
			'1.9.4'
		);

		wp_register_style(
			'estandar-delivery-locator-lite-frontend',
			ESTANDAR_DL_PLUGIN_URL . 'assets/css/frontend.css',
			array( 'estandar-dl-leaflet' ),
			$ver
		);

		wp_register_script(
			'estandar-dl-leaflet',
			'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
			array(),
			'1.9.4',
			true
		);

		wp_register_script(
			'estandar-delivery-locator-lite-frontend',
			ESTANDAR_DL_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'estandar-dl-leaflet' ),
			$ver,
			true
		);
	}
}
