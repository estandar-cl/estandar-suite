<?php
/**
 * Analytics ↔ Core Integration Layer.
 *
 * Responsabilidades:
 *   - Registrar Analytics en el ecosistema Estandar (metadata, diagnóstico).
 *   - Cargar assets de Core cuando corresponde.
 *
 * Fuera de scope:
 *   - Navegación (→ EA_Dashboard::register_menu).
 *   - Definición de páginas (→ EA_Dashboard::PAGES_PRIMARY / PAGES_LEGACY).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Core_Compat {

	const MODULE_ID = 'estandar_analytics';

	/** Página de entrada canónica del plugin. */
	const SLUG_HOME = 'ea-funnel';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_metadata' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_core_assets' ), 9 );
		}

		add_filter( 'estandar_core_diagnostics_contracts', array( __CLASS__, 'register_diagnostics' ) );
	}

	// -------------------------------------------------------------------------
	// Ecosystem registration
	// -------------------------------------------------------------------------

	public static function metadata(): array {
		return array(
			'id'            => self::MODULE_ID,
			'name'          => 'Estandar Analytics',
			'slug'          => self::SLUG_HOME,
			'version'       => defined( 'ESTANDAR_ANALYTICS_VERSION' ) ? ESTANDAR_ANALYTICS_VERSION : EA_VERSION,
			'type'          => 'analytics',
			'capability'    => 'manage_options',
			'standalone'    => false,
			'core_optional' => false,
			'supports'      => array(
				'tracking',
				'dashboard',
				'conversions',
				'csv_export',
				'retention',
				'i18n',
				'privacy',
			),
		);
	}

	public static function register_metadata(): void {
		$metadata = apply_filters( 'ea_core_compat_metadata', self::metadata() );
		$metadata = apply_filters( 'estandar_updater_module_metadata', $metadata, self::MODULE_ID );
		do_action( 'estandar_module_registered', $metadata );
		do_action( 'ea_core_compat_registered', $metadata );
	}

	// -------------------------------------------------------------------------
	// Asset bootstrap
	// -------------------------------------------------------------------------

	public static function enqueue_core_assets( $hook ): void {
		if ( ! self::is_analytics_admin_screen( $hook ) ) {
			return;
		}

		if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
			estandar_core_enqueue_admin_ui();
		}
	}

	/**
	 * Determina si la pantalla actual pertenece a Analytics.
	 * Fuente canónica de páginas: EA_Dashboard::all_pages().
	 */
	public static function is_analytics_admin_screen( string $hook = '' ): bool {
		if ( strpos( $hook, 'ea-' ) !== false || strpos( $hook, 'ea_' ) !== false ) {
			return true;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, EA_Dashboard::all_pages(), true );
	}

	// -------------------------------------------------------------------------
	// Core diagnostics contract
	// -------------------------------------------------------------------------

	public static function register_diagnostics( array $contracts ): array {
		$contracts[ self::MODULE_ID ] = array(
			'id'                  => self::MODULE_ID,
			'name'                => 'Estandar Analytics',
			'plugin_active'       => true,
			'tracker_enabled'     => self::is_tracker_enabled(),
			'rest_route'          => 'ea/v1/collect',
			'rest_route_expected' => true,
			'db_tables_expected'  => self::expected_tables(),
			'retention_scheduled' => (bool) wp_next_scheduled( 'ea_daily_retention' ),
			'version'             => defined( 'ESTANDAR_ANALYTICS_VERSION' ) ? ESTANDAR_ANALYTICS_VERSION : EA_VERSION,
			'standalone'          => false,
			'core_optional'       => false,
		);

		return $contracts;
	}

	private static function is_tracker_enabled(): bool {
		$settings = EA_Settings::get();
		return ! empty( $settings['enable_tracking'] );
	}

	private static function expected_tables(): array {
		$tables = array( 'sessions', 'pageviews', 'events', 'conversions', 'referrers', 'devices', 'daily_aggregates' );
		$out    = array();

		foreach ( $tables as $table ) {
			$out[] = EA_Utils::table( $table );
		}

		return $out;
	}
}
