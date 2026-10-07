<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insights admin page — funnel, events, observability state and adoption
 * import/rollback. Mutations behind nonce + capability.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_insights_adoption_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_estandar_suite_insights_adoption_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_estandar_suite_insights_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		add_action( 'admin_post_estandar_suite_insights_save_analytics_settings', array( __CLASS__, 'handle_save_analytics_settings' ) );
	}

	/** Analytics configuration grouped (rescata estandar-analytics ea_settings). */
	public static function analytics_settings_groups(): array
	{
		return array(
			'tracking' => array(
				'use_dashboard'          => array( 'type' => 'select', 'label' => 'Dashboard WP (admin bar)', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'use_bundled'            => array( 'type' => 'select', 'label' => 'JS incluido', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'analytics_page_id'      => array( 'type' => 'text', 'label' => 'ID página pública', 'default' => '0' ),
				'track_events'           => array( 'type' => 'select', 'label' => 'Registrar eventos', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'cookie_support'         => array( 'type' => 'select', 'label' => 'Soporte cookies', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'use_admin_role'         => array( 'type' => 'select', 'label' => 'Módulo solo admins', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'use_site_users'         => array( 'type' => 'select', 'label' => 'Modo multisitio', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
				'record_removed_events'  => array( 'type' => 'select', 'label' => 'Conservar eventos borrados', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
				'only_users'             => array( 'type' => 'text', 'label' => 'Usuarios a rastrear', 'default' => '' ),
				'ignore_roles'           => array( 'type' => 'text', 'label' => 'Roles ignorados', 'default' => '' ),
				'expire'                 => array( 'type' => 'text', 'label' => 'Vencimiento datos (días)', 'default' => '7' ),
				'js_url'                 => array( 'type' => 'text', 'label' => 'URL JS externo', 'default' => '' ),
				'connect_theme'          => array( 'type' => 'select', 'label' => 'Conectar tema', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
				'transparent_options'    => array( 'type' => 'select', 'label' => 'Opciones transparentes', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
			),
			'dashboard' => array(
				'show_overview'      => array( 'type' => 'select', 'label' => 'Mostrar resumen', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'show_browsers'      => array( 'type' => 'select', 'label' => 'Mostrar navegadores', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'show_traffic_by_hours' => array( 'type' => 'select', 'label' => 'Tráfico por horas', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
				'color_browser_type' => array( 'type' => 'text', 'label' => 'Color navegadores', 'default' => '#33adff' ),
				'color_theme_dark'   => array( 'type' => 'select', 'label' => 'Tema oscuro', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
			),
			'settings' => array(
				'custom_access_rights' => array( 'type' => 'select', 'label' => 'Derechos personalizados', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
				'manage_users_cap'     => array( 'type' => 'text', 'label' => 'Cap gestionar usuarios', 'default' => 'manage_options' ),
				'view_stats_cap'       => array( 'type' => 'text', 'label' => 'Cap ver estadísticas', 'default' => 'manage_options' ),
				'use_custom_events'    => array( 'type' => 'select', 'label' => 'Eventos personalizados', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '0' ),
				'use_referrals'        => array( 'type' => 'select', 'label' => 'Referidos', 'options' => array( '0' => 'No', '1' => 'Sí' ), 'default' => '1' ),
			),
		);
	}

	/** Analytics configuration flattened (CLI). */
	public static function analytics_settings_fields(): array
	{
		$out = array();
		foreach ( self::analytics_settings_groups() as $g => $fs ) { $out = array_merge( $out, $fs ); }
		return $out;
	}

	/** admin_post handler: save analytics settings group (merge). */
	public static function handle_save_analytics_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_save_analytics_settings' );
		$group  = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::analytics_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( 'estandar_suite_insights_analytics_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( 'estandar_suite_insights_analytics_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics&ss=configuracion&group=' . $group . '&analytics_saved=1' ) );
		exit;
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Insights', 'Insights', 'manage_options', 'estandar-suite-insights', array( __CLASS__, 'render' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'analytics' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de analytics habilitado', 'desc' => 'Activa la vista de analytics en la suite.', 'default' => 1 ),
				'retention_days' => array( 'type' => 'text', 'label' => 'Retención (días)', 'desc' => 'Días que se conservan los eventos antes de purgar.', 'default' => '365' ),
			),
			'seo' => array(
				'enabled'     => array( 'type' => 'bool', 'label' => 'Módulo de SEO habilitado', 'desc' => 'Activa la vista de SEO en la suite.', 'default' => 1 ),
				'sync_enabled'=> array( 'type' => 'bool', 'label' => 'Sincronización activa', 'desc' => 'Permite que el adaptador Search Console sincronice landings.', 'default' => 0 ),
			),
			'scanner' => array(
				'enabled'    => array( 'type' => 'bool', 'label' => 'Módulo de scanner habilitado', 'desc' => 'Activa la vista de escaneos en la suite.', 'default' => 1 ),
				'auto_scan'  => array( 'type' => 'bool', 'label' => 'Escaneo automático', 'desc' => 'Programa escaneos periódicos de los sitios registrados.', 'default' => 0 ),
			),
		);
	}

	/** Data provider for a feature sub-tab (read-only legacy + v2). */
	public static function feature_data( string $name ): array
	{
		$data = array();
		if ( $name === 'analytics' ) {
			$data['sessions']   = \Estandar\Core\Admin::legacy_rows( 'ea_sessions', 'ORDER BY id DESC LIMIT 30' );
			$data['pageviews']  = \Estandar\Core\Admin::legacy_rows( 'ea_pageviews', 'ORDER BY id DESC LIMIT 30' );
			$data['events']     = \Estandar\Core\Admin::legacy_rows( 'ea_events', 'ORDER BY id DESC LIMIT 30' );
			$data['by_source']  = \Estandar\Core\Admin::legacy_group( 'ea_sessions', 'source' );
			$data['by_medium']  = \Estandar\Core\Admin::legacy_group( 'ea_sessions', 'medium' );
			$data['top_pages']  = \Estandar\Core\Admin::legacy_group( 'ea_pageviews', 'page_url' );
			$data['trend']      = \Estandar\Core\Admin::legacy_rows( 'ea_daily_aggregates', 'ORDER BY aggregate_date DESC, metric_key ASC LIMIT 60' );
			$data['conv']       = \Estandar\Core\Admin::legacy_count( 'ea_conversions' );
			$data['total_pv']   = \Estandar\Core\Admin::legacy_count( 'ea_pageviews' );
			$data['total_ev']   = \Estandar\Core\Admin::legacy_count( 'ea_events' );
			// Chatbot events (ecb_*) — the analytics area owns ALL analytics,
			// including the chatbot's, so Capture does not duplicate this view.
			$data['chatbot_events']        = \Estandar\Core\Admin::legacy_rows( 'ecb_events', 'ORDER BY id DESC LIMIT 30' );
			$data['chatbot_conversations'] = \Estandar\Core\Admin::legacy_count( 'ecb_conversations' );
			$data['chatbot_leads']         = \Estandar\Core\Admin::legacy_count( 'ecb_leads' );
		} elseif ( $name === 'seo' ) {
			$data['landings']   = \Estandar\Core\Admin::legacy_rows( 'es_seo_landings', 'ORDER BY id DESC LIMIT 50' );
			$data['metrics']    = \Estandar\Core\Admin::legacy_count( 'es_seo_landing_metrics' );
			$data['queries']    = \Estandar\Core\Admin::legacy_count( 'es_seo_queries' );
		} elseif ( $name === 'scanner' ) {
			$data['scans']      = \Estandar\Core\Admin::legacy_rows( 'estandar_scans', 'ORDER BY id DESC LIMIT 30' );
			$data['scan_queue'] = \Estandar\Core\Admin::legacy_count( 'estandar_scan_queue' );
		}
		return $data;
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_insights_' . $feature . '_settings', \Estandar\Core\Admin::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
	}

	// ── Read-only helpers over legacy tables (never write to them) ─────────

	private static function legacy_table( string $name ): bool
	{
		return \Estandar\Core\Admin::legacy_table( $name );
	}

	private static function legacy_rows( string $name, string $suffix = 'ORDER BY id DESC LIMIT 50' ): array
	{
		return \Estandar\Core\Admin::legacy_rows( $name, $suffix );
	}

	private static function legacy_count( string $name ): int
	{
		return \Estandar\Core\Admin::legacy_count( $name );
	}

	private static function legacy_group( string $name, string $column ): array
	{
		return \Estandar\Core\Admin::legacy_group( $name, $column );
	}

	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		$tabs = array(
			'estado'   => 'Estado',
			'config'   => 'Configuración',
			'datos'    => 'Datos',
			'avanzado' => 'Avanzado',
		);
		$tab    = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'config' => 'Configuración', 'analytics' => 'Analytics', 'seo' => 'SEO', 'scanner' => 'Scanner', 'settings' => 'Settings', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'insights', $tabs, $subs );
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'insights', $t, $s_list );
		}
		$tabs = $visible_tabs;
		if ( isset( $tabs['config'] ) ) {
			$subs['config']['visibilidad'] = 'Visibilidad';
		}
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ] ) : '';
		$counts = array();
		if ( class_exists( 'Estandar\\Flow\\Pipeline' ) ) {
			foreach ( \Estandar\Flow\Pipeline::list() as $row ) {
				$counts[ $row['stage'] ] = (int) ( $counts[ $row['stage'] ] ?? 0 ) + 1;
			}
		}
		$funnel = Funnel::compute( $counts );
		$events = Events::recent( 50 );
		$obs    = Observability::resolve_state( array() );
		$legacy = Adoption::legacy_present();
		$imp    = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$rol    = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'activate_schema'  => array( 'estandar_suite_insights_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_insights_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'Events.php', 'desc' => 'Crea estandar_insights_events al activar.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Events.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'sanitize_type', 'file' => 'Events.php', 'desc' => 'Valida el tipo de evento contra los tipos cerrados.' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'record', 'file' => 'Events.php', 'desc' => 'Registra un evento analítico.' ),
				array( 'fn' => 'recent', 'file' => 'Events.php', 'desc' => 'Últimos eventos analíticos.' ),
				array( 'fn' => 'retention', 'file' => 'Events.php', 'desc' => 'Métrica de retención.' ),
				array( 'fn' => 'on_capture', 'file' => 'Events.php', 'desc' => 'Consume estandar_suite_capture_event.' ),
				array( 'fn' => 'on_signal', 'file' => 'Events.php', 'desc' => 'Consume estandar_suite_signal_recorded.' ),
				array( 'fn' => 'compute', 'file' => 'Funnel.php', 'desc' => 'Calcula el funnel comercial.' ),
				array( 'fn' => 'conversion', 'file' => 'Funnel.php', 'desc' => 'Tasa de conversión entre etapas.' ),
				array( 'fn' => 'channel_mix', 'file' => 'Funnel.php', 'desc' => 'Mezcla de canales.' ),
				array( 'fn' => 'resolve_state', 'file' => 'Observability.php', 'desc' => 'Estado honesto (ausencia → UNKNOWN, nunca inventa éxito).' ),
				array( 'fn' => 'render_line', 'file' => 'Observability.php', 'desc' => 'Representación textual del estado.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta analytics legado de ea_events/ea_sessions.' ),
				array( 'fn' => 'project', 'file' => 'Adoption.php', 'desc' => 'Proyecta un evento legado a la estructura nueva.' ),
				array( 'fn' => 'historical', 'file' => 'Adoption.php', 'desc' => 'Lista eventos históricos para previsualizar.' ),
				array( 'fn' => 'import', 'file' => 'Adoption.php', 'desc' => 'Importa eventos históricos (dry-run o commit).' ),
				array( 'fn' => 'rollback', 'file' => 'Adoption.php', 'desc' => 'Elimina solo los eventos adoptados.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'on_capture', 'file' => 'Events.php', 'desc' => 'Consume estandar_suite_capture_event.' ),
				array( 'fn' => 'on_signal', 'file' => 'Events.php', 'desc' => 'Consume estandar_suite_signal_recorded.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (GET /funnel, GET /events, GET /observability).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'funnel', 'file' => 'Cli.php', 'desc' => 'Ver el funnel comercial.' ),
				array( 'fn' => 'retention', 'file' => 'Cli.php', 'desc' => 'Ver retención.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa analytics histórico (--commit).' ),
				array( 'fn' => 'adoption_rollback', 'file' => 'Cli.php', 'desc' => 'Rollback de adoptados (--confirm=yes).' ),
				array( 'fn' => 'analytics_summary|traffic|pages|trend|events', 'file' => 'Cli.php', 'desc' => 'Resumen/tráfico/páginas/tendencia/eventos del analytics legado.' ),
				array( 'fn' => 'seo_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de landings SEO legadas.' ),
				array( 'fn' => 'scanner_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de escaneos legados.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Insights bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Insights con sus 4 tabs.' ),
				array( 'fn' => 'feature_data', 'file' => 'Admin.php', 'desc' => 'Datos de los sub-tabs analytics/seo/scanner (read-only legado).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'handle_import', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para import de analytics.' ),
				array( 'fn' => 'handle_rollback', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para rollback de analytics.' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature.' ),
				array( 'fn' => 'legacy_table', 'file' => 'Admin.php', 'desc' => 'Verifica que exista una tabla legada (delega en Core).' ),
				array( 'fn' => 'legacy_rows', 'file' => 'Admin.php', 'desc' => 'Lee filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_count', 'file' => 'Admin.php', 'desc' => 'Cuenta filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_group', 'file' => 'Admin.php', 'desc' => 'Agrupa por columna una tabla legada (read-only).' ),
			),
		);

		// ── Feature sub-tab data (analytics / seo / scanner) ────────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && isset( $feature_fields[ $sub ] ) ) {
			$feature_view = self::feature_data( $sub );
		}

		include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/insights-admin.php';
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_insights_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de la tabla estandar_insights_events.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_adoption_import' );
		$r = Adoption::import( empty( $_POST['commit'] ), (int) ( $_POST['limit'] ?? 100 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_adoption_rollback' );
		$n = Adoption::rollback();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&rolled=' . (int) $n ) );
		exit;
	}
}
