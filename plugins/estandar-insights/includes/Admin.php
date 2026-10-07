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
		add_action( 'admin_post_estandar_suite_insights_seo_scan', array( __CLASS__, 'handle_seo_scan' ) );
	}

	/** LEGACY-REMOVED (2026-09-27): la configuración rescatada de estandar-analytics
	 *  (tracking/dashboard/settings) era inerte — ningún consumidor en la suite.
	 *  Se retiró junto con `analytics_settings_fields()` y `handle_save_analytics_settings()`. */

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Insights', 'Insights', 'manage_options', 'estandar-suite-insights', array( __CLASS__, 'render' ) );
	}

	/**
	 * Per-functionality settings descriptors (key => field config).
	 *
	 * Solo SEO tiene ajustes operativos reales (`auto_scan`, `max_pages`, leídos
	 * por SeoAudit). Los antiguos `enabled`/`retention_days`/`sync_enabled` eran
	 * controles inertes y se retiraron.
	 */
	public static function feature_fields(): array
	{
		return array(
			'seo' => array(
				'auto_scan'   => array( 'type' => 'bool', 'label' => 'Auditoría automática · semanal', 'desc' => 'Programa una auditoría técnica del sitio cada semana.', 'default' => 0 ),
				'max_pages'   => array( 'type' => 'text', 'label' => 'Páginas por auditoría', 'desc' => 'Cuántas páginas/posts publicados audita cada corrida, de 1 a 500.', 'default' => '50' ),
			),
		);
	}

	/**
	 * Data provider for a feature sub-tab (v2 mirror first, legacy fallback).
	 *
	 * `$ss` es una pista del sub-sub-tab activo para NO calcular lo que no se
	 * muestra (p. ej. no computa el Panorama ni el embudo web si estás en
	 * "Páginas"). Vacío = calcula todo.
	 */
	public static function feature_data( string $name, string $ss = '' ): array
	{
		$data = array();
		$want = static function ( string $key ) use ( $ss ): bool {
			return $ss === '' || $ss === $key;
		};
		if ( $name === 'analytics' ) {
			$data['sessions']   = Analytics::rows( 'sessions', 'ORDER BY id DESC LIMIT 30' );
			$data['pageviews']  = Analytics::rows( 'pageviews', 'ORDER BY id DESC LIMIT 30' );
			$data['events']     = Analytics::rows( 'events', 'ORDER BY id DESC LIMIT 30' );
			$data['by_source']  = Analytics::group( 'sessions', 'source' );
			$data['by_medium']  = Analytics::group( 'sessions', 'medium' );
			$data['top_pages']  = Analytics::group( 'pageviews', 'page_url' );
			$data['trend']      = Analytics::rows( 'aggregates', 'ORDER BY aggregate_date DESC, metric_key ASC LIMIT 60' );
			$data['conv']       = Analytics::count( 'conversions' );
			$data['total_pv']   = Analytics::count( 'pageviews' );
			$data['total_ev']   = Analytics::count( 'events' );
			$data['total_s']    = Analytics::count( 'sessions' );
			if ( $want( 'panorama' ) ) {
				// Vista unificada "Panorama": series por día (sessions/pageviews/events)
				// en el rango pedido (rng=1|7|14|30|90|365|all o from/to específicos).
				$range = self::panorama_range();
				$data['panorama_range'] = $range;
				$data['panorama']   = Analytics::series_between( $range['from'], $range['to'] );
				$data['panorama_conv'] = Analytics::count_between( 'conversions', 'converted_at', $range['from'], $range['to'] );
				$data['panorama_pv'] = (int) array_sum( isset( $data['panorama']['pageviews'] ) ? $data['panorama']['pageviews'] : array() );
				$data['panorama_ev'] = (int) array_sum( isset( $data['panorama']['events'] ) ? $data['panorama']['events'] : array() );
				$data['panorama_s']  = (int) array_sum( isset( $data['panorama']['sessions'] ) ? $data['panorama']['sessions'] : array() );
			}
			if ( $want( 'embudo' ) ) {
				// Embudo web visitas→leads (sesiones → pageviews → leads), mismo rango.
				$wrange = self::panorama_range();
				$data['web_funnel'] = WebFunnel::snapshot( $wrange['from'], $wrange['to'] );
			}
			if ( $want( 'chatbot' ) ) {
				// Chatbot events (ecb_*) — the analytics area owns ALL analytics,
				// including the chatbot's, so Capture does not duplicate this view.
				// v2-first: prefiere los espejos estandar_capture_legacy_* con fallback legacy.
				$cb_events = array();
				$cb_conv   = 0;
				$cb_leads  = 0;
				if ( class_exists( 'Estandar\\Capture\\ChatbotAdoption' ) ) {
					$cb_events = \Estandar\Capture\ChatbotAdoption::rows( 'events', 'ORDER BY id DESC LIMIT 30' );
					$cb_conv   = \Estandar\Capture\ChatbotAdoption::count( 'conversations' );
					$cb_leads  = \Estandar\Core\Admin::legacy_count( 'ecb_leads' );
				}
				if ( empty( $cb_events ) ) {
					$cb_events = \Estandar\Core\Admin::legacy_rows( 'ecb_events', 'ORDER BY id DESC LIMIT 30' );
				}
				if ( $cb_conv === 0 ) {
					$cb_conv = \Estandar\Core\Admin::legacy_count( 'ecb_conversations' );
				}
				if ( $cb_leads === 0 ) {
					$cb_leads = \Estandar\Core\Admin::legacy_count( 'ecb_leads' );
				}
				$data['chatbot_events']        = $cb_events;
				$data['chatbot_conversations'] = $cb_conv;
				$data['chatbot_leads']         = $cb_leads;
			}
		} elseif ( $name === 'seo' ) {
			$data['landings']   = SeoAdoption::rows( 'landings', 'ORDER BY id DESC LIMIT 50' );
			$data['metrics']    = SeoAdoption::count( 'metrics' );
			$data['queries']    = SeoAdoption::count( 'queries' );
			if ( $want( 'resumen' ) || $want( 'auditoria' ) ) {
				// Auditoría técnica propia (Tier 1): sin credenciales, universal.
				$data['audit']       = SeoAudit::rows( 200 );
				$data['audit_count'] = SeoAudit::count();
				$data['audit_last']  = SeoAudit::last_run();
				$data['audit_top']   = SeoAudit::top_issues();
				$data['audit_site']  = get_option( SeoAudit::SITE, array() );
				$data['audit_runs']  = SeoAudit::runs( 12 );
			}
		}
		return $data;
	}

	/** Rangos predefinidos del gráfico Panorama (rng=...). Clave → etiqueta. */
	public static function panorama_presets(): array
	{
		return array(
			'1'  => '1 día',
			'7'  => '1 semana',
			'14' => '14 días',
			'30' => '1 mes',
			'90' => '3 meses',
			'365'=> '1 año',
			'all'=> 'Todo',
		);
	}

	/** Resuelve el rango pedido por URL para el Panorama.
	 *  Prefijo rng=1|7|14|30|90|365|all, o custom desde/hasta (YYYY-MM-DD).
	 *  Devuelve from/to reales (concretos, 'all' → fecha mínima real). */
	public static function panorama_range(): array
	{
		$presets = self::panorama_presets();
		$rng = sanitize_key( (string) ( $_GET['rng'] ?? '14' ) );
		$today = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d', time() );
		$from  = $today;
		$to    = $today;

		if ( $rng === 'custom' || ( isset( $_GET['from'] ) || isset( $_GET['to'] ) ) && ! isset( $presets[ $rng ] ) ) {
			$raw_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
			$raw_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_from ) ) {
				$from = $raw_from;
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_to ) ) {
				$to = $raw_to;
			}
			if ( $from > $to ) {
				$tmp = $from; $from = $to; $to = $tmp;
			}
			$rng = 'custom';
			return array( 'preset' => $rng, 'from' => $from, 'to' => $to, 'label' => 'Personalizado', 'is_custom' => true );
		}

		if ( ! isset( $presets[ $rng ] ) ) {
			$rng = '14';
		}
		if ( $rng === 'all' ) {
			$earliest = Analytics::earliest_date();
			$from = $earliest !== '' ? $earliest : gmdate( 'Y-m-d', time() - ( 29 * DAY_IN_SECONDS ) );
			$to   = $today;
		} elseif ( (int) $rng === 1 ) {
			$from = $today; $to = $today;
		} else {
			$days = (int) $rng;
			$from = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
			$to   = $today;
		}
		return array( 'preset' => $rng, 'from' => $from, 'to' => $to, 'label' => $presets[ $rng ], 'is_custom' => false );
	}

	/** admin_post: corre la auditoría SEO técnica (Tier 1) del sitio. */
	public static function handle_seo_scan(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_seo_scan' );
		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
		$r     = SeoAudit::scan( $limit, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=seo&ss=resumen&audited=' . (int) $r['pages'] . '&avg=' . rawurlencode( (string) $r['avg'] ) ) );
		exit;
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

	/** Estructura de visibilidad de este producto (para el manager central de core). */
	public static function visibility_structure(): array
	{
		return array(
			'product' => 'insights',
			'page'    => 'estandar-suite-insights',
			'label'   => 'Insights',
			'subs'    => array(
				'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
				'config'   => array( 'analytics' => 'Analytics', 'seo' => 'SEO', 'settings' => 'Configuración' ),
				'datos'    => array( 'adopcion' => 'Adopción' ),
				'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
			),
			'ss' => array(
				'config' => array(
					'analytics' => self::analytics_ssubs(),
					'seo'       => self::seo_ssubs(),
				),
			),
		);
	}

 	public static function render(): void
 	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		\Estandar\Core\Admin::render_product_header();
		$tabs = array(
			'estado'   => 'Estado',
			'config'   => 'Configuración',
			'datos'    => 'Datos',
			'avanzado' => 'Avanzado',
		);
		$tab    = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'analytics' => 'Analytics', 'seo' => 'SEO', 'settings' => 'Configuración' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);

		// Adopción: es una MUDANZA de datos legados. En sitios sin legacy mostraba
		// ceros y confundía, así que solo se muestra si HAY algo que adoptar
		// (analytics o SEO). Se puede forzar con ?adopcion=1.
		$adopt_available = \Estandar\Insights\Adoption::legacy_present();
		if ( ! $adopt_available && class_exists( 'Estandar\\Insights\\SeoAdoption' ) ) {
			$adopt_available = \Estandar\Insights\SeoAdoption::legacy_present( 'landings' )
				|| \Estandar\Insights\SeoAdoption::legacy_present( 'queries' )
				|| \Estandar\Insights\SeoAdoption::legacy_present( 'metrics' );
		}
		if ( ! $adopt_available && empty( $_GET['adopcion'] ) ) {
			unset( $subs['datos']['adopcion'] );
			unset( $tabs['datos'] );
		}

		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'insights', $tabs, $subs );
		if ( empty( $visible_tabs ) ) {
			\Estandar\Core\Admin::render_visibility_none( 'insights' );
			return;
		}
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'insights', $t, $s_list );
		}
		$tabs = $visible_tabs;
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ], ( $tab === 'config' && ! isset( $_GET['sub'] ) ) ? 'analytics' : '' ) : '';
		// Compat: el antiguo sub=config (solo informativo) ahora vive en Configuración.
		if ( $tab === 'config' && $sub === 'config' ) { $sub = 'settings'; }
		// Conteo por etapa con agregado en SQL (antes usaba Pipeline::list(), que
		// corta en 100 y subcontaba: había 573 registros y el embudo mostraba 100).
		$counts = array();
		if ( class_exists( 'Estandar\\Flow\\Pipeline' ) && method_exists( 'Estandar\\Flow\\Pipeline', 'counts_by_stage' ) ) {
			$counts = \Estandar\Flow\Pipeline::counts_by_stage();
		}
		$funnel = Funnel::compute( $counts );
		$events = Events::recent( 50 );
		$legacy = Adoption::legacy_present();
		$imp    = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$failed = isset( $_GET['failed'] ) ? (int) $_GET['failed'] : null;
		$rol    = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;
		$analytics_status = Analytics::status();

		// ── Inventario derivado por reflexión (nunca queda desactualizado) ──
		$fn_settings = array(
			'activate_schema'      => array( 'estandar_suite_insights_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_insights_schema_version' ),
		);
		$sets = self::inventory_sets();

		// ── Feature sub-tab data (analytics / seo) ──────────────────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && in_array( $sub, array( 'analytics', 'seo' ), true ) ) {
			$ss_hint      = sanitize_key( (string) ( $_GET['ss'] ?? '' ) );
			$feature_view = self::feature_data( $sub, $ss_hint );
		}

		include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/insights-admin.php';
	}

	/**
	 * Inventario de funciones derivado por reflexión: lista los métodos públicos
	 * de cada clase del plugin, agrupados por clase. Se genera en vivo para no
	 * desincronizarse del código (antes era una lista hardcodeada que quedaba
	 * desactualizada y con duplicados).
	 *
	 * @return array<string,array<int,array{fn:string,file:string,desc:string}>>
	 */
	public static function inventory_sets(): array
	{
		$classes = array( 'Events', 'Funnel', 'Visual', 'WebFunnel', 'Adoption', 'Analytics', 'ExternalDaily', 'SeoAdoption', 'SeoAudit', 'Rest', 'Cli', 'Admin' );
		$sets    = array();
		foreach ( $classes as $short ) {
			$fqcn = 'Estandar\\Insights\\' . $short;
			if ( ! class_exists( $fqcn ) ) {
				continue;
			}
			$rc      = new \ReflectionClass( $fqcn );
			$file    = basename( (string) $rc->getFileName() );
			$entries = array();
			foreach ( $rc->getMethods( \ReflectionMethod::IS_PUBLIC ) as $m ) {
				if ( $m->getDeclaringClass()->getName() !== $fqcn ) {
					continue;
				}
				$entries[] = array( 'fn' => $m->getName(), 'file' => $file, 'desc' => 'Método público de ' . $short . '.' );
			}
			if ( $entries ) {
				$sets[ $short ] = $entries;
			}
		}
		return $sets;
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
		$dry = empty( $_POST['commit'] );
		// Adopt the full analytics surface (sessions/pageviews/events/
		// conversions/aggregates) plus the event log projection (Adoption).
		$r = Analytics::import( $dry, (int) ( $_POST['limit'] ?? 100 ) );
		$total = 0;
		$failed = 0;
		foreach ( $r as $surface ) {
			$total  += (int) $surface['imported'];
			$failed += (int) ( $surface['failed'] ?? 0 );
		}
		if ( ! $dry ) {
			$legacy = Adoption::import( false, (int) ( $_POST['limit'] ?? 100 ) );
			$total  += (int) $legacy['imported'];
			$failed += (int) ( $legacy['failed'] ?? 0 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&imported=' . (int) $total . '&failed=' . (int) $failed ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_insights_adoption_rollback' );
		$n = Analytics::rollback() + Adoption::rollback();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-insights&rolled=' . (int) $n ) );
		exit;
	}

	/** Catálogo de sub-sub-tabs (ss=) del sub-tab Analytics (fuente única). */
	public static function analytics_ssubs(): array
	{
		return array( 'panorama' => 'Panorama', 'embudo' => 'Embudo web', 'resumen' => 'Resumen', 'trafico' => 'Tráfico', 'paginas' => 'Páginas', 'eventos' => 'Eventos', 'chatbot' => 'Chatbot', 'tendencia' => 'Tendencia' );
	}

	/** Catálogo de sub-sub-tabs (ss=) del sub-tab SEO. */
	public static function seo_ssubs(): array
	{
		return array( 'resumen' => 'Resumen', 'auditoria' => 'Auditoría', 'landings' => 'Landings · Search Console', 'settings' => 'Settings' );
	}

}
