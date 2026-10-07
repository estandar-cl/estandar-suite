<?php
/**
 * Admin dashboard, menus, exports, and queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Dashboard {

	/**
	 * Catálogo canónico de páginas admin. Única fuente de verdad.
	 *
	 * Agregar una pantalla primaria = añadir una entrada aquí.
	 * register_menu(), render_nav(), enqueue_assets() y all_pages() derivan de esto.
	 *
	 * 'callback' debe ser un método público de esta clase.
	 * 'label'    es el texto que aparece en los tabs de navegación.
	 *
	 * @return array<string, array{label: string, callback: string}>
	 */
		public static function pages_primary(): array {
			return array(
				'ea-funnel'           => array( 'label' => __( 'Funnel',         'estandar-analytics' ), 'callback' => 'render_funnel' ),
				'ea-adquisicion'      => array( 'label' => __( 'Adquisición',    'estandar-analytics' ), 'callback' => 'render_adquisicion' ),
				'ea-campaign-journey' => array( 'label' => __( 'Campañas',       'estandar-analytics' ), 'callback' => 'render_campaign_journey' ),
				'ea-acquisition-intel' => array( 'label' => __( 'Acquisition Intelligence', 'estandar-analytics' ), 'callback' => 'render_acquisition_intelligence' ),
				'ea-results'          => array( 'label' => __( 'Resultados',     'estandar-analytics' ), 'callback' => 'render_results' ),
				// ea-operacion → legacy (2026-07): duplicaba el bloque Riesgos/backlog
				// del portal de Leads (que es donde se actúa). URL sigue accesible.
				'ea-settings'         => array( 'label' => __( 'Configuración',  'estandar-analytics' ), 'callback' => 'render_settings' ),
			);
		}

	/**
	 * Páginas de nivel 2: accesibles por URL directa, sin aparecer en menú ni tabs.
	 * Maps slug → método render de esta clase.
	 *
	 * @return array<string, string>
	 */
	public static function pages_legacy(): array {
		return array(
			'ea-analytics'    => 'render_overview',
			'ea-visitors'     => 'render_visitors',
			'ea-acquisition'  => 'render_acquisition',
			'ea-conversions'  => 'render_conversions',
			'ea-ad-platforms' => 'render_ad_platforms_legacy',
			'ea-attribution'  => 'render_attribution_legacy',
			'ea-behavior'     => 'render_behavior_legacy',
			'ea-events'       => 'render_events',
			'ea-times'        => 'render_times_legacy',
			'ea-traffic'      => 'render_traffic_legacy',
			'ea-operacion'    => 'render_operacion',
		);
	}

	/** Todos los slugs conocidos del plugin. */
	public static function all_pages(): array {
		$primary = array_filter( self::pages_primary(), static fn( $p ) => empty( $p['external'] ) );
		return array_merge( array_keys( $primary ), array_keys( self::pages_legacy() ) );
	}

	private static function legacy_to_primary(): array {
		return array(
			'ea-analytics'    => 'ea-funnel',
			'ea-attribution'  => 'ea-adquisicion',
			'ea-behavior'     => 'ea-adquisicion',
			'ea-times'        => 'ea-adquisicion',
			'ea-traffic'      => 'ea-adquisicion',
			'ea-ad-platforms' => 'ea-campaign-journey',
			'ea-acquisition'  => 'ea-adquisicion',
			'ea-conversions'  => 'ea-results',
			'ea-visitors'     => 'ea-funnel',
			'ea-operacion'    => 'ea-results',
		);
	}

	/**
	 * Resuelve el slug primario activo (colapsa legacy → primary) para las tabs.
	 */
	private static function resolve_active_slug( string $current ): string {
		$map = self::legacy_to_primary();
		return $map[ $current ] ?? $current;
	}

	/**
	 * Tabs canónicas (mismo formato que consumen estandar_render_tabs()/
	 * estandar_core_render_subtabs() en el resto del ecosistema — slug => [label,url]).
	 */
	private static function nav_tabs(): array {
		$tabs = [];
		foreach ( self::pages_primary() as $slug => $page ) {
			$tabs[ $slug ] = [
				'label' => $page['label'],
				'url'   => admin_url( 'admin.php?page=' . $slug ),
			];
		}
		return $tabs;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_ea_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ea_ads_daily_import', array( __CLASS__, 'handle_ads_daily_import' ) );
	}

	public static function register_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$primary = self::pages_primary();
		$first   = array_key_first( $primary );

		// ── Navegación via Core Framework ─────────────────────────────────────
		if ( function_exists( 'estandar_register_submenu' ) ) {
			// La primera página primaria es el punto de entrada visible en el menú Estandar.
			estandar_register_submenu( array(
				'slug'       => $first,
				'title'      => __( 'Funnel comercial', 'estandar-analytics' ),
				'menu_title' => __( 'Analytics', 'estandar-analytics' ),
				'capability' => 'manage_options',
				'callback'   => array( __CLASS__, $primary[ $first ]['callback'] ),
				'icon'       => 'dashicons-chart-area',
				'position'   => 58,
			) );
			// Las demás páginas primarias se registran ocultas (navegación vía tabs).
			// Las páginas marcadas como 'external' son de otro plugin — solo aparecen en nav.
			foreach ( $primary as $slug => $page ) {
				if ( $slug === $first || ! empty( $page['external'] ) ) {
					continue;
				}
				add_submenu_page( null, $page['label'], $page['label'], 'manage_options', $slug, array( __CLASS__, $page['callback'] ) );
			}
		} else {
			// Fallback si Core no está activo: menú propio con todas las páginas primarias.
			add_menu_page( __( 'Analytics', 'estandar-analytics' ), __( 'Analytics', 'estandar-analytics' ), 'manage_options', $first, array( __CLASS__, $primary[ $first ]['callback'] ), 'dashicons-chart-area', 58 );
			foreach ( $primary as $slug => $page ) {
				if ( ! empty( $page['external'] ) ) {
					continue;
				}
				add_submenu_page( $first, $page['label'], $page['label'], 'manage_options', $slug, array( __CLASS__, $page['callback'] ) );
			}
		}

		// Páginas legacy — accesibles por URL directa, sin aparecer en menú ni tabs.
		foreach ( self::pages_legacy() as $slug => $method ) {
			if ( method_exists( __CLASS__, $method ) ) {
				add_submenu_page( null, '', '', 'manage_options', $slug, array( __CLASS__, $method ) );
			}
		}
	}

	public static function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$hook_matches = strpos( $hook, 'ea-' ) !== false || strpos( $hook, 'ea_' ) !== false || strpos( $hook, 'page_ea-' ) !== false;
		if ( ! $hook_matches && ! in_array( $page, self::all_pages(), true ) ) {
			return;
		}
		// Core Framework CSS (tokens, shell, tabs, cards, tables, KPIs, filter bar).
		if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
			estandar_core_enqueue_admin_ui();
		}
		// Analytics-specific CSS (charts, ranked table bars, ea-panel).
		wp_enqueue_style( 'ea-admin', EA_URL . 'assets/admin.css', array( 'estandar-admin-ui' ), EA_VERSION );
		wp_enqueue_script( 'ea-admin', EA_URL . 'assets/admin.js', array(), EA_VERSION, true );

		$filters = EA_Utils::admin_filters();
		$charts  = self::get_chart_data( $filters );
		wp_localize_script(
			'ea-admin',
			'eaAdminCharts',
			$charts
		);
	}

	public static function render_overview() {
		self::render_funnel();
	}

	public static function render_funnel() {
		self::require_cap();
		$filters        = EA_Utils::admin_filters();
		$options        = self::get_filter_options();
		$funnel         = self::get_funnel( $filters );
		$system_health  = self::get_system_health();
		$operators_list = array();
		if ( self::leads_table_exists() ) {
			global $wpdb;
			$operators_list = $wpdb->get_col(
				"SELECT DISTINCT assigned_operator_name
				 FROM {$wpdb->prefix}es_leads
				 WHERE assigned_operator_name != ''
				 ORDER BY assigned_operator_name ASC LIMIT 50"
			) ?: array();
		}
		$diagnosis        = self::get_funnel_diagnosis( $funnel['stages'] );
		$breakdown_project = self::leads_table_exists() ? self::get_project_breakdown_for_funnel( $filters ) : array();
		self::shell( __( 'Funnel comercial', 'estandar-analytics' ), __( 'De sesión a venta — dónde está el cuello de botella', 'estandar-analytics' ), EA_PATH . 'admin/funnel.php',
			compact( 'filters', 'options', 'funnel', 'system_health', 'operators_list', 'diagnosis', 'breakdown_project' ) );
	}

	public static function render_traffic() {
		self::require_cap();
		$filters  = EA_Utils::admin_filters();
		$options  = self::get_filter_options();
		$sessions = self::get_sessions( $filters );
		$data_acq = self::get_acquisition( $filters );
		$data_beh = self::get_behavior( $filters );
		self::shell( __( 'Tráfico', 'estandar-analytics' ), __( 'Fuentes, sesiones y comportamiento', 'estandar-analytics' ), EA_PATH . 'admin/traffic.php',
			compact( 'filters', 'options', 'sessions', 'data_acq', 'data_beh' ) );
	}

	public static function render_campaign_journey() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();
		$data    = self::get_campaign_journey( $filters );
		self::shell( __( 'Campaign Journey', 'estandar-analytics' ), __( 'Campaña → anuncio → landing → lead → resultado comercial', 'estandar-analytics' ), EA_PATH . 'admin/campaign-journey.php',
			compact( 'filters', 'options', 'data' ) );
	}

	/**
	 * Acquisition Intelligence — commercial outcomes grouped by utm_campaign.
	 * Read-only. Reuses es_leads (first_utm_* attribution) and existing helpers.
	 * No new tables, tracking, endpoints, cron or cache.
	 */
	public static function render_acquisition_intelligence() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();

		$drill    = isset( $_GET['drill'] );
		$campaign = isset( $_GET['campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) : '';

		if ( $drill ) {
			$mode    = 'detail';
			$summary = self::get_acquisition_campaign_summary( $filters, $campaign );
			$rows    = self::get_acquisition_campaign_detail( $filters, $campaign );
			self::shell( __( 'Campaign Detail', 'estandar-analytics' ), __( 'Leads de la campaña — timeline, estado, atribución', 'estandar-analytics' ), EA_PATH . 'admin/acquisition-intelligence.php',
				compact( 'filters', 'options', 'mode', 'rows', 'campaign', 'summary' ) );
			return;
		}

		$mode      = 'list';
		$rows      = self::get_acquisition_intelligence( $filters );
		$executive = self::acquisition_executive_summary( $rows );
		$global    = $executive['global'];
		$top       = $executive['top'];
		self::shell( __( 'Acquisition Intelligence', 'estandar-analytics' ), __( 'Funnel comercial ejecutivo por campaña (utm_campaign) — últimos 30 días', 'estandar-analytics' ), EA_PATH . 'admin/acquisition-intelligence.php',
			compact( 'filters', 'options', 'mode', 'rows', 'campaign', 'global', 'top' ) );
	}

	/**
	 * Single canonical status→bucket mapping for Acquisition Intelligence.
	 * Same taxonomy already used across this dashboard; centralized so the
	 * list, detail-summary and global views never diverge. Emits SUM() columns.
	 *
	 * Canonical vocabulary source of truth: Estandar_Leads_Normalizer::statuses().
	 * Full deterministic coverage (each status → exactly one bucket, no overlap):
	 *   Contacted : CONTACTED
	 *   Qualified : QUALIFIED, MEETING_BOOKED, VISIT_SCHEDULED, VISITED
	 *   Proposal Sent     : PROPOSAL_SENT
	 *   Proposal Accepted : NEGOTIATION, BANK_EVALUATION
	 *   Won  : RESERVED, DEED_SIGNED, DELIVERED, CONVERTED
	 *   Lost : LOST, CLOSED_LOST
	 *   — (leads-only, no funnel bucket): NEW, NO_RESPONSE, UNREACHABLE, INVALID
	 */
	/**
	 * ANALYTICS_NORMALIZATION_V1 — canonical commercial stage taxonomy.
	 *
	 * Single source: Estandar_Analytics_Commercial_Stage_Map.
	 * All status lists in this class are derived from that provider.
	 * No hardcoded status string appears in any SQL query.
	 */
	private static function _cmap(): Estandar_Analytics_Commercial_Stage_Map { return new Estandar_Analytics_Commercial_Stage_Map(); }

	private static function ai_bucket_columns_sql(): string {
		$q = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$m = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$r = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$s = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
		$w = "'" . implode( "','", array_map( 'esc_sql', array_merge( Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'reserved' ), Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'sold' ) ) ) ) . "'";
		$l = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'lost' );

		if ( defined( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1' ) && ESTANDAR_ANALYTICS_NORMALIZATION_V1 ) {
			$ps = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'proposal_sent' );
			$pa = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'proposal_accepted' );
		} else {
			$ps = "'PROPOSAL_SENT'";
			$pa = "'NEGOTIATION','BANK_EVALUATION'";
		}
		return "
				SUM( status IN ({$q}) )                                                     AS qualified,
				SUM( status IN ({$m}) )                                                     AS meeting,
				SUM( status IN ({$ps}) )                                                    AS proposal_sent,
				SUM( status IN ({$pa}) )                                                    AS proposal_accepted,
				SUM( status IN ({$r}) )                                                     AS reserved,
				SUM( status IN ({$s}) )                                                     AS sold,
				SUM( status IN ({$w}) )                                                     AS won,
				SUM( status IN ({$l}) )                                                     AS lost,
				SUM( first_gclid <> '' )                                                    AS google_ids,
				SUM( first_fbclid <> '' )                                                   AS meta_ids,
				COUNT( DISTINCT NULLIF( assigned_operator_name, '' ) )                       AS operators";
	}

	/**
	 * DETERMINISTIC CONTRACT — Acquisition Intelligence commercial taxonomy.
	 *
	 *  • Every new canonical status (Estandar_Leads_Normalizer::statuses()) requires
	 *    exactly one mapping — either a funnel bucket or an explicit non-funnel entry.
	 *  • Legacy aliases are compatibility-only and NEVER participate in coverage assertions.
	 *  • Non-funnel statuses are intentional (counted in Leads only, no funnel bucket).
	 *  • Analytics must never invent a fallback bucket — an unmapped canonical status
	 *    is a hard FAIL surfaced by ai_taxonomy_assert() / ai_taxonomy_self_test().
	 *
	 * This PHP taxonomy is the declarative mirror of ai_bucket_columns_sql(); the two
	 * MUST stay in sync. The self-test enforces the taxonomy against the canonical list.
	 *
	 * Canonical funnel buckets — status → exactly one bucket (aliases excluded).
	 * @return array<string,string[]>
	 */
	private static function ai_funnel_buckets(): array {
		// Base commercial taxonomy buckets. Non-overlapping.
		// AI-specific refinements (proposal_sent, proposal_accepted) live only
		// in ai_bucket_columns_sql() — they are sub-buckets of 'qualified'.
		return array(
			'qualified' => Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'qualified' ),
			'meeting'   => Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'meeting' ),
			'reserved'  => Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'reserved' ),
			'sold'      => Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'sold' ),
			'lost'      => Estandar_Analytics_Commercial_Stage_Map::get_statuses( 'lost' ),
		);
	}

	/** Intentional non-funnel canonical statuses (Leads-only, no funnel bucket). */
	private static function ai_non_funnel_statuses(): array {
		return array( 'NEW', 'NO_RESPONSE', 'UNREACHABLE', 'INVALID' );
	}

	/** Legacy aliases → canonical target. Compatibility-only; excluded from assertions. */
	private static function ai_status_aliases(): array {
		return array( 'CLOSED_LOST' => 'LOST' );
	}

	/**
	 * ANALYTICS_NORMALIZATION_V1 — canonical commercial stage column SQL.
	 * Single source for the 4 status-grouped aggregates used across
	 * funnel, results, campaign journey, behavior-to-result, and system health.
	 * Replaces 8+ duplicate hardcoded status lists.
	 */
	private static function _stage_columns_sql( string $prefix = 'l.' ): string {
		$q = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$m = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$r = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$s = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
		return "
			SUM( {$prefix}status IN ({$q}) ) AS calificados,
			SUM( {$prefix}status IN ({$m}) ) AS reuniones,
			SUM( {$prefix}status IN ({$r}) ) AS reservas,
			SUM( {$prefix}status IN ({$s}) ) AS ventas";
	}

	/**
	 * Engineering diagnostic — self-validates the commercial taxonomy against the
	 * canonical status list. Deterministic; read-only; NOT exposed in production UI.
	 * On any structural defect the returned 'errors' array is non-empty and 'ok' is false.
	 *
	 * @return array<string,mixed>
	 */
	public static function ai_taxonomy_self_test(): array {
		$canonical = class_exists( 'Estandar_Leads_Normalizer' )
			? array_values( (array) Estandar_Leads_Normalizer::statuses() )
			: array();
		$buckets    = self::ai_funnel_buckets();
		$non_funnel = self::ai_non_funnel_statuses();
		$aliases    = self::ai_status_aliases();

		// status → list of buckets it belongs to (funnel + explicit non-funnel; aliases excluded).
		$map = array();
		foreach ( $buckets as $bucket => $statuses ) {
			foreach ( $statuses as $st ) { $map[ $st ][] = $bucket; }
		}
		foreach ( $non_funnel as $st ) { $map[ $st ][] = '(non-funnel)'; }

		$errors     = array();
		$unmapped   = array();
		$duplicates = array();

		// TASK 3 + TASK 4 — every canonical status maps to exactly one bucket.
		foreach ( $canonical as $st ) {
			$n = isset( $map[ $st ] ) ? count( $map[ $st ] ) : 0;
			if ( $n === 0 ) {
				$unmapped[] = $st;
				$errors[]   = 'Unmapped canonical status: ' . $st;
			} elseif ( $n > 1 ) {
				$duplicates[ $st ] = $map[ $st ];
				$errors[]          = 'Duplicate mapping: ' . $st . ' -> ' . implode( ', ', $map[ $st ] );
			}
		}

		// Aliases must never be canonical, nor participate in bucket mapping.
		foreach ( $aliases as $alias => $target ) {
			if ( in_array( $alias, $canonical, true ) ) {
				$errors[] = 'Alias declared as canonical: ' . $alias;
			}
			if ( isset( $map[ $alias ] ) ) {
				$errors[] = 'Alias participates in bucket mapping: ' . $alias;
			}
		}

		// Statuses present in the mapping but neither canonical nor alias = unknown.
		$unknown = array();
		foreach ( array_keys( $map ) as $st ) {
			if ( ! in_array( $st, $canonical, true ) && ! isset( $aliases[ $st ] ) ) {
				$unknown[] = $st;
			}
		}

		$mapped_count    = 0;
		$nonfunnel_count = 0;
		foreach ( $canonical as $st ) {
			if ( in_array( $st, $non_funnel, true ) ) {
				$nonfunnel_count++;
			} elseif ( isset( $map[ $st ] ) && count( $map[ $st ] ) === 1 ) {
				$mapped_count++;
			}
		}
		$total    = count( $canonical );
		$covered  = $total - count( $unmapped ) - count( $duplicates );
		$coverage = $total > 0 ? round( ( $covered / $total ) * 100, 1 ) : 100.0;

		return array(
			'canonical'       => $canonical,
			'canonical_count' => $total,
			'mapped'          => $mapped_count,
			'non_funnel'      => $nonfunnel_count,
			'aliases'         => array_keys( $aliases ),
			'unknown'         => $unknown,
			'unmapped'        => $unmapped,
			'duplicates'      => $duplicates,
			'coverage_pct'    => $coverage,
			'errors'          => $errors,
			'ok'              => empty( $errors ),
		);
	}

	/**
	 * FAIL-FAST assertion (development/testing only). Throws on any taxonomy defect
	 * so a future unmapped/duplicated canonical status can never silently disappear.
	 * Never called from the render/production path — engineering guard only.
	 */
	public static function ai_taxonomy_assert(): void {
		$r = self::ai_taxonomy_self_test();
		if ( ! $r['ok'] ) {
			throw new RuntimeException( "Acquisition Intelligence taxonomy invalid:\n- " . implode( "\n- ", $r['errors'] ) );
		}
	}

	/**
	 * Aggregation: commercial outcomes grouped by first_utm_campaign.
	 * Revenue column has no source (no honorarios field) → returned as null → "—".
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_acquisition_intelligence( array $filters ): array {
		if ( ! self::leads_table_exists() ) {
			return array();
		}
		global $wpdb;
		$leads = $wpdb->prefix . 'es_leads';
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$project = (string) ( $filters['project'] ?? '' );
		$project_where = $project !== '' ? $wpdb->prepare( ' AND project_slug = %s', $project ) : '';
		$buckets = self::ai_bucket_columns_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					first_utm_campaign AS campaign,
					COUNT(*) AS leads,{$buckets}
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				GROUP BY first_utm_campaign
				ORDER BY leads DESC",
				$start,
				$end
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( (array) $results as $r ) {
			if ( (int) $r['leads'] === 0 ) {
				continue; // ignore campaigns with zero leads
			}
			$rows[] = array(
				'campaign'          => (string) $r['campaign'],
				'leads'             => (int) $r['leads'],
				'contacted'         => (int) ($r['contacted'] ?? 0),
				'qualified'         => (int) $r['qualified'],
				'proposal_sent'     => (int) $r['proposal_sent'],
				'proposal_accepted' => (int) $r['proposal_accepted'],
				'won'               => (int) $r['won'],
				'lost'              => (int) $r['lost'],
				'revenue'           => null, // no honorarios source available
				'operators'         => (int) $r['operators'],
				'google_ids'        => (int) $r['google_ids'],
				'meta_ids'          => (int) $r['meta_ids'],
			);
		}
		return $rows;
	}

	/**
	 * Global executive summary + top campaigns, derived in PHP from the already
	 * fetched campaign rows (no extra query). Revenue has no source → null → "—".
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	private static function acquisition_executive_summary( array $rows ): array {
		$sum = static function ( $key ) use ( $rows ) {
			$t = 0;
			foreach ( $rows as $r ) { $t += (int) ( $r[ $key ] ?? 0 ); }
			return $t;
		};
		$top = static function ( $key ) use ( $rows ) {
			$best = null;
			foreach ( $rows as $r ) {
				if ( $best === null || (int) $r[ $key ] > (int) $best[ $key ] ) { $best = $r; }
			}
			return ( $best && (int) $best[ $key ] > 0 ) ? $best : null;
		};

		return array(
			'global' => array(
				'total_campaigns' => count( $rows ),
				'total_leads'     => $sum( 'leads' ),
				'qualified'       => $sum( 'qualified' ),
				'won'             => $sum( 'won' ),
				'lost'            => $sum( 'lost' ),
				'revenue'         => null,
				'google_ids'      => $sum( 'google_ids' ),
				'meta_ids'        => $sum( 'meta_ids' ),
			),
			'top' => array(
				'leads'     => $top( 'leads' ),
				'qualified' => $top( 'qualified' ),
				'won'       => $top( 'won' ),
				'revenue'   => null, // no revenue source to rank by
			),
		);
	}

	/**
	 * Drilldown summary: single-campaign aggregated buckets + derived rates.
	 * Reuses the canonical bucket mapping (no capped-row derivation). Rates are
	 * guarded against zero denominators (→ null → "—"). Revenue → null → "—".
	 *
	 * @return array<string,mixed>
	 */
	private static function get_acquisition_campaign_summary( array $filters, string $campaign ): array {
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$base = array(
			'campaign' => $campaign,
			'period'   => trim( (string) ( $filters['from'] ?? '' ) . ' → ' . (string) ( $filters['to'] ?? '' ), ' →' ),
			'leads' => 0, 'contacted' => 0, 'qualified' => 0, 'proposal_sent' => 0,
			'proposal_accepted' => 0, 'won' => 0, 'lost' => 0, 'revenue' => null,
			'google_ids' => 0, 'meta_ids' => 0,
			'conversion_pct' => null, 'qualification_pct' => null,
			'acceptance_pct' => null, 'win_pct' => null,
		);
		if ( ! self::leads_table_exists() ) {
			return $base;
		}
		global $wpdb;
		$leads = $wpdb->prefix . 'es_leads';
		$project = (string) ( $filters['project'] ?? '' );
		$project_where = $project !== '' ? $wpdb->prepare( ' AND project_slug = %s', $project ) : '';
		$buckets = self::ai_bucket_columns_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS leads,{$buckets}
				FROM {$leads}
				WHERE first_utm_campaign = %s AND created_at >= %s AND created_at < %s{$project_where}",
				$campaign,
				$start,
				$end
			),
			ARRAY_A
		);
		if ( ! $r ) {
			return $base;
		}

		$leads_n           = (int) $r['leads'];
		$contacted         = (int) ($r['contacted'] ?? 0);
		$qualified         = (int) $r['qualified'];
		$proposal_sent     = (int) $r['proposal_sent'];
		$proposal_accepted = (int) $r['proposal_accepted'];
		$won               = (int) $r['won'];

		// Guarded ratios (null when denominator is zero → template shows "—").
		$rate = static function ( $num, $den ) {
			return $den > 0 ? round( ( $num / $den ) * 100, 1 ) : null;
		};

		return array_merge( $base, array(
			'leads'             => $leads_n,
			'contacted'         => $contacted,
			'qualified'         => $qualified,
			'proposal_sent'     => $proposal_sent,
			'proposal_accepted' => $proposal_accepted,
			'won'               => $won,
			'lost'              => (int) $r['lost'],
			'google_ids'        => (int) $r['google_ids'],
			'meta_ids'          => (int) $r['meta_ids'],
			'conversion_pct'    => $rate( $contacted, $leads_n ),          // Contacted / Leads
			'qualification_pct' => $rate( $qualified, $leads_n ),          // Qualified / Leads
			'acceptance_pct'    => $rate( $proposal_accepted, $proposal_sent ), // Accepted / Sent
			'win_pct'           => $rate( $won, $leads_n ),                // Won / Leads
		) );
	}

	/**
	 * Drilldown: per-lead detail for a single utm_campaign.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_acquisition_campaign_detail( array $filters, string $campaign ): array {
		if ( ! self::leads_table_exists() ) {
			return array();
		}
		global $wpdb;
		$leads = $wpdb->prefix . 'es_leads';
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$project = (string) ( $filters['project'] ?? '' );
		$project_where = $project !== '' ? $wpdb->prepare( ' AND project_slug = %s', $project ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					created_at,
					status,
					assigned_operator_name AS operator,
					first_landing_url      AS landing_url,
					first_utm_source       AS utm_source,
					first_utm_medium       AS utm_medium,
					first_utm_campaign     AS utm_campaign,
					first_gclid            AS gclid,
					first_fbclid           AS fbclid
				FROM {$leads}
				WHERE first_utm_campaign = %s AND created_at >= %s AND created_at < %s{$project_where}
				ORDER BY created_at ASC
				LIMIT 500",
				$campaign,
				$start,
				$end
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( (array) $results as $r ) {
			$rows[] = array(
				'id'           => (int) $r['id'],
				'created_at'   => (string) $r['created_at'],
				'status'       => (string) $r['status'],
				'operator'     => (string) $r['operator'],
				'landing_url'  => (string) $r['landing_url'],
				'utm_source'   => (string) $r['utm_source'],
				'utm_medium'   => (string) $r['utm_medium'],
				'utm_campaign' => (string) $r['utm_campaign'],
				'gclid'        => trim( (string) $r['gclid'] ) !== '',
				'fbclid'       => trim( (string) $r['fbclid'] ) !== '',
			);
		}
		return $rows;
	}

	public static function render_ad_platforms() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();
		$data    = self::get_ad_platforms_summary( $filters );
		self::shell( __( 'Ad Platforms', 'estandar-analytics' ), __( 'Impresiones, clics y gasto importados desde Meta y Google Ads', 'estandar-analytics' ), EA_PATH . 'admin/ad-platforms.php',
			compact( 'filters', 'options', 'data' ) );
	}

	public static function render_attribution() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();
		$data    = self::get_attribution_data( $filters );
		self::shell(
			__( 'Atribución', 'estandar-analytics' ),
			__( 'Canal → Sesión → Lead → Conversión. Dónde viene el negocio real.', 'estandar-analytics' ),
			EA_PATH . 'admin/attribution.php',
			compact( 'filters', 'options', 'data' )
		);
	}

	public static function render_results() {
		self::require_cap();
		$filters          = EA_Utils::admin_filters();
		$options          = self::get_filter_options();
		$data             = self::get_commercial_results( $filters );
		$behavior_results = self::get_behavior_to_result( $filters );
		self::shell( __( 'Resultados comerciales', 'estandar-analytics' ), __( 'Qué canales, campañas y keywords generan mejores clientes', 'estandar-analytics' ), EA_PATH . 'admin/results.php',
			compact( 'filters', 'options', 'data', 'behavior_results' ) );
	}

	public static function render_visitors() {
		self::require_cap();
		$filters  = EA_Utils::admin_filters();
		$options  = self::get_filter_options();
		$sessions = self::get_sessions( $filters );
		self::shell( __( 'Visitantes', 'estandar-analytics' ), '', EA_PATH . 'admin/visitors.php',
			compact( 'filters', 'options', 'sessions' ) );
	}

		public static function render_behavior() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();
		$data    = self::get_behavior( $filters );
		self::shell( __( 'Comportamiento', 'estandar-analytics' ), '', EA_PATH . 'admin/behavior.php',
				compact( 'filters', 'options', 'data' ) );
		}

		public static function render_events() {
			self::require_cap();
			$filters = EA_Utils::admin_filters();
			$event_filter = isset( $_GET['ea_event'] ) ? sanitize_key( wp_unslash( $_GET['ea_event'] ) ) : 'all';
			if ( ! in_array( $event_filter, array( 'all', 'pageview', 'form_start', 'form_submit', 'chatbot' ), true ) ) {
				$event_filter = 'all';
			}
			$events = self::get_recent_visible_events( $filters, $event_filter );
			$journey_context = self::get_session_journey_context( (string) ( $filters['session_id'] ?? '' ) );
			self::shell( __( 'Eventos recientes', 'estandar-analytics' ), __( 'Pageviews y eventos de formularios persistidos en Analytics', 'estandar-analytics' ), EA_PATH . 'admin/events.php',
				compact( 'filters', 'event_filter', 'events', 'journey_context' ) );
				}

		public static function render_times() {
			self::require_cap();
			$filters = EA_Utils::admin_filters();
			$options = self::get_filter_options();
			$data    = self::get_time_analysis( $filters );
			self::shell( __( 'Horarios', 'estandar-analytics' ), __( 'Cuándo llega tráfico y cuándo convierte', 'estandar-analytics' ), EA_PATH . 'admin/times.php',
				compact( 'filters', 'options', 'data' ) );
		}

	public static function render_acquisition() {
		self::render_adquisicion();
	}

	public static function render_conversions() {
		self::render_results();
	}

	public static function render_adquisicion() {
		self::require_cap();
		$filters   = EA_Utils::admin_filters();
		$options   = self::get_filter_options();
		$data_attr = self::get_attribution_data( $filters );
		$data_acq  = self::get_acquisition( $filters );
		$data_beh  = self::get_behavior( $filters );
		$data_time = self::get_time_analysis( $filters );
		self::shell(
			__( 'Adquisición', 'estandar-analytics' ),
			__( '¿De dónde vienen los leads y cómo se comportan?', 'estandar-analytics' ),
			EA_PATH . 'admin/adquisicion.php',
			compact( 'filters', 'options', 'data_attr', 'data_acq', 'data_beh', 'data_time' )
		);
	}

	public static function render_operacion() {
		self::require_cap();
		$filters = EA_Utils::admin_filters();
		$options = self::get_filter_options();
		$data    = self::get_commercial_results( $filters );
		self::shell(
			__( 'Operación comercial', 'estandar-analytics' ),
			__( '¿Los operadores están gestionando los leads?', 'estandar-analytics' ),
			EA_PATH . 'admin/operacion.php',
			compact( 'filters', 'options', 'data' )
		);
	}

	public static function render_ad_platforms_legacy() {
		self::render_campaign_journey();
	}

	public static function render_attribution_legacy() {
		self::render_adquisicion();
	}

	public static function render_behavior_legacy() {
		self::render_adquisicion();
	}

	public static function render_times_legacy() {
		self::render_adquisicion();
	}

	public static function render_traffic_legacy() {
		self::render_adquisicion();
	}

	public static function render_settings() {
		self::require_cap();
		$settings = EA_Settings::get();
		self::shell( __( 'Configuración', 'estandar-analytics' ), '', EA_PATH . 'admin/settings.php', compact( 'settings' ) );
	}

	/**
	 * Envuelve una vista en el shell de Core.
	 *
	 * @param array<string,mixed> $vars Variables que necesita el template (compact() del caller).
	 */
	private static function shell( string $title, string $subtitle, string $template, array $vars = [] ): void {
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$active  = self::resolve_active_slug( $current );

		// Core es dependencia obligatoria. Sin él, el módulo degrada a notice (no fatal).
		// CORE_OPTIONALITY_V1: gate único de entrada; vuelve inalcanzables los
		// estandar_render_* internos de admin/*.php cuando core está ausente.
		if ( ! function_exists( 'estandar_core_render_admin_shell' ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Estandar Analytics requiere Estandar Primer. Actívelo para ver este módulo.', 'estandar-analytics' )
				. '</p></div>';
			return;
		}
		estandar_core_render_admin_shell( array(
			'title'       => __( 'Analytics', 'estandar-analytics' ),
			'subtitle'    => $subtitle,
			'width'       => 'fluid', // ESTANDAR_ADMIN_UI_V0.4 — full-width admin shell.
			// Submenú vía estandar_render_tabs() (mismo look que Comunicaciones/Cotizaciones/
			// Forms). El array 'navigation' del shell V2 usaba .estandar-shell-nav, una clase
			// con reglas CSS ligeramente distintas de .estandar-tabs — de ahí la inconsistencia
			// visual entre módulos que reportó Rodrigo.
			'header_slot' => static function () use ( $active ): void {
				if ( isset( self::pages_primary()[ $active ] ) && function_exists( 'estandar_render_tabs' ) ) {
					estandar_render_tabs( self::nav_tabs(), $active );
				}
			},
			'callback'    => static function() use ( $template, $vars ) {
				// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
				extract( $vars, EXTR_SKIP );
				include $template;
			},
		) );
	}

	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'estandar-analytics' ) );
		}
	}

	/**
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{0:string,1:string}
	 */
	public static function dt_bounds( $from, $to ) {
		return EA_Utils::sql_datetime_bounds( $from, $to );
	}

	public static function get_overview_metrics() {
		global $wpdb;
		$sessions = EA_Utils::table( 'sessions' );
		$today    = EA_Utils::today();
		$yday     = EA_Utils::date_offset( $today, -1 );

		$periods = array(
			'today'     => $today,
			'yesterday' => $yday,
			'7days'     => EA_Utils::date_offset( $today, -6 ),
			'30days'    => EA_Utils::date_offset( $today, -29 ),
		);

		$out = array(
			'visitors'    => array(),
			'sessions'    => array(),
			'pageviews'   => self::count_pageviews_range( $periods['30days'], $today ),
			'avg_duration'=> self::avg_duration( $periods['30days'], $today ),
			'bounce_rate' => self::bounce_rate( $periods['30days'], $today ),
			'conversion_rate' => self::conversion_rate( $periods['30days'], $today ),
			'top_landing' => self::top_landing_pages( $periods['30days'], $today, 10 ),
			'top_exit'    => self::top_exit_pages( $periods['30days'], $today, 10 ),
			'top_sources' => self::top_sources( $periods['30days'], $today, 10 ),
		);

		foreach ( $periods as $key => $from ) {
			list( $start, $end ) = self::dt_bounds( $from, $today );
			$out['visitors'][ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT visitor_hash) FROM {$sessions} WHERE first_seen >= %s AND first_seen < %s",
					$start,
					$end
				)
			);
			$out['sessions'][ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$sessions} WHERE first_seen >= %s AND first_seen < %s",
					$start,
					$end
				)
			);
		}

		return apply_filters( 'ea_dashboard_metrics', $out );
	}

	private static function count_pageviews_range( $from, $to ) {
		global $wpdb;
		$table = EA_Utils::table( 'pageviews' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE viewed_at >= %s AND viewed_at < %s",
				$start,
				$end
			)
		);
	}

	private static function avg_duration( $from, $to ) {
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(duration) FROM {$table} WHERE first_seen >= %s AND first_seen < %s",
				$start,
				$end
			)
		);
	}

	private static function bounce_rate( $from, $to ) {
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE first_seen >= %s AND first_seen < %s",
				$start,
				$end
			)
		);
		if ( ! $total ) {
			return 0;
		}
		$bounces = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE is_bounce = 1 AND first_seen >= %s AND first_seen < %s",
				$start,
				$end
			)
		);
		return round( ( $bounces / $total ) * 100, 1 );
	}

	private static function conversion_rate( $from, $to ) {
		global $wpdb;
		$sessions    = EA_Utils::table( 'sessions' );
		$conversions = EA_Utils::table( 'conversions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sessions} WHERE first_seen >= %s AND first_seen < %s",
				$start,
				$end
			)
		);
		if ( ! $total ) {
			return 0;
		}
		$conv = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT session_id) FROM {$conversions} WHERE converted_at >= %s AND converted_at < %s",
				$start,
				$end
			)
		);
		return round( ( $conv / $total ) * 100, 1 );
	}

	private static function top_landing_pages( $from, $to, $limit ) {
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT landing_page AS label, COUNT(*) AS total FROM {$table} WHERE landing_page != '' AND first_seen >= %s AND first_seen < %s GROUP BY landing_page ORDER BY total DESC LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);
	}

	private static function top_exit_pages( $from, $to, $limit ) {
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exit_page AS label, COUNT(*) AS total FROM {$table} WHERE exit_page != '' AND first_seen >= %s AND first_seen < %s GROUP BY exit_page ORDER BY total DESC LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);
	}

	private static function top_sources( $from, $to, $limit ) {
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $from, $to );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT traffic_type AS label, COUNT(*) AS total FROM {$table} WHERE first_seen >= %s AND first_seen < %s GROUP BY traffic_type ORDER BY total DESC LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);
	}

	public static function get_sessions( $filters ) {
		global $wpdb;
		$sessions = EA_Utils::table( 'sessions' );
		$devices  = EA_Utils::table( 'devices' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$where    = array( 's.first_seen >= %s', 's.first_seen < %s' );
		$params   = array( $start, $end );

		if ( $filters['source'] !== '' ) {
			$where[]  = 's.source = %s';
			$params[] = $filters['source'];
		}
		if ( $filters['campaign'] !== '' ) {
			$where[]  = 's.campaign = %s';
			$params[] = $filters['campaign'];
		}
		if ( $filters['project'] !== '' ) {
			$where[]  = 's.first_project_slug = %s';
			$params[] = $filters['project'];
		}
		if ( $filters['page'] !== '' ) {
			$where[]  = 's.landing_page LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['page'] ) . '%';
		}
		if ( $filters['device'] !== '' ) {
			$where[]  = 'd.device_type = %s';
			$params[] = $filters['device'];
		}

		$sql = "SELECT s.session_id, s.first_seen, s.source, s.landing_page, s.duration, s.page_count, COALESCE(d.device_type, '') AS device_type
			FROM {$sessions} s
			LEFT JOIN {$devices} d ON d.session_id = s.session_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY s.first_seen DESC
			LIMIT 200";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

		public static function get_behavior( $filters ) {
		global $wpdb;
		$events    = EA_Utils::table( 'events' );
		$sessions  = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$project_join = $filters['project'] !== '' ? " INNER JOIN {$sessions} s ON s.session_id = e.session_id" : '';
		$project_sql  = $filters['project'] !== '' ? ' AND s.first_project_slug = %s' : '';
		$project_args = $filters['project'] !== '' ? array( $filters['project'] ) : array();

		$pages = self::get_url_report( $filters, (int) ( $filters['limit'] ?? 50 ) );

		$cta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_type AS label, COUNT(*) AS total FROM {$events} e{$project_join} WHERE e.event_type IN ('cta_click','WhatsAppClick','whatsapp_click','phone_click','email_click') AND e.occurred_at >= %s AND e.occurred_at < %s{$project_sql} GROUP BY e.event_type ORDER BY total DESC",
				array_merge( array( $start, $end ), $project_args )
			),
			ARRAY_A
		);

		$scroll = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_label AS label, COUNT(*) AS total FROM {$events} e{$project_join} WHERE e.event_type = 'scroll_depth' AND e.occurred_at >= %s AND e.occurred_at < %s{$project_sql} GROUP BY e.event_label ORDER BY total DESC LIMIT 10",
				array_merge( array( $start, $end ), $project_args )
			),
			ARRAY_A
		);

		$clicks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_type AS label, COUNT(*) AS total FROM {$events} e{$project_join} WHERE e.event_type LIKE '%click%' AND e.occurred_at >= %s AND e.occurred_at < %s{$project_sql} GROUP BY e.event_type ORDER BY total DESC LIMIT 15",
				array_merge( array( $start, $end ), $project_args )
			),
			ARRAY_A
		);

			return array(
				'pages'  => $pages ?: array(),
				'cta'    => $cta ?: array(),
				'scroll' => $scroll ?: array(),
				'clicks' => $clicks ?: array(),
			);
		}

	private static function get_url_report( $filters, $limit = 50 ) {
		global $wpdb;
		$pageviews = EA_Utils::table( 'pageviews' );
		$sessions  = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$where  = array( 'p.viewed_at >= %s', 'p.viewed_at < %s' );
		$params = array( $start, $end );

		if ( $filters['source'] !== '' ) {
			$where[]  = 's.source = %s';
			$params[] = $filters['source'];
		}
		if ( $filters['campaign'] !== '' ) {
			$where[]  = 's.campaign = %s';
			$params[] = $filters['campaign'];
		}
		if ( $filters['project'] !== '' ) {
			$where[]  = 's.first_project_slug = %s';
			$params[] = $filters['project'];
		}
		if ( $filters['page'] !== '' ) {
			$where[]  = 'p.page_url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['page'] ) . '%';
		}

		$sql = "SELECT p.page_url, p.session_id, p.viewed_at,
				COALESCE(s.source, '') AS source,
				COALESCE(s.medium, '') AS medium,
				COALESCE(s.campaign, '') AS campaign,
				COALESCE(s.first_project_slug, '') AS project
			FROM {$pageviews} p
			LEFT JOIN {$sessions} s ON s.session_id = p.session_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY p.viewed_at DESC
			LIMIT 5000';

		$raw  = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
		$rows = array();

		foreach ( $raw as $row ) {
			$url      = EA_Utils::normalize_report_url( $row['page_url'] ?? '' );
			$source   = (string) ( $row['source'] ?: 'direct' );
			$medium   = (string) ( $row['medium'] ?? '' );
			$campaign = (string) ( $row['campaign'] ?? '' );
			$project  = (string) ( $row['project'] ?? '' );
			$key      = implode( "\t", array( $url, $source, $medium, $campaign, $project ) );

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'label'      => $url,
					'url'        => $url,
					'total'      => 0,
					'unique'     => 0,
					'sessions'   => array(),
					'source'     => $source,
					'medium'     => $medium,
					'campaign'   => $campaign,
					'project'    => $project,
					'first_seen' => (string) ( $row['viewed_at'] ?? '' ),
					'last_seen'  => (string) ( $row['viewed_at'] ?? '' ),
				);
			}

			$rows[ $key ]['total']++;
			if ( ! empty( $row['session_id'] ) ) {
				$rows[ $key ]['sessions'][ (string) $row['session_id'] ] = true;
			}
			$seen = (string) ( $row['viewed_at'] ?? '' );
			if ( $seen !== '' && ( $rows[ $key ]['first_seen'] === '' || $seen < $rows[ $key ]['first_seen'] ) ) {
				$rows[ $key ]['first_seen'] = $seen;
			}
			if ( $seen !== '' && $seen > $rows[ $key ]['last_seen'] ) {
				$rows[ $key ]['last_seen'] = $seen;
			}
		}

		foreach ( $rows as &$row ) {
			$row['unique'] = count( $row['sessions'] );
			unset( $row['sessions'] );
		}
		unset( $row );

		$rows = array_values( $rows );
		usort( $rows, static function( $a, $b ) {
			$by_total = (int) $b['total'] <=> (int) $a['total'];
			return $by_total !== 0 ? $by_total : strcmp( (string) $b['last_seen'], (string) $a['last_seen'] );
		} );

		return array_slice( $rows, 0, max( 1, (int) $limit ) );
	}

			public static function get_recent_visible_events( $filters, $event_filter = 'all' ) {
			global $wpdb;
			$pageviews = EA_Utils::table( 'pageviews' );
			$events    = EA_Utils::table( 'events' );
			$sessions  = EA_Utils::table( 'sessions' );
			list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
			$session_id = EA_Utils::sanitize_text( $filters['session_id'] ?? '', 64 );
			if ( $session_id !== '' && ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $session_id ) ) {
				$session_id = '';
			}

			$params = array();
			$event_type_sql = "e.event_type IN ('form_start','form_submit')";
			if ( in_array( $event_filter, array( 'form_start', 'form_submit' ), true ) ) {
				$event_type_sql = 'e.event_type = %s';
			}

			$parts = array();
			$pageview_params = array();
			if ( $event_filter === 'all' || $event_filter === 'pageview' ) {
				$parts[] = "SELECT
						p.viewed_at AS occurred_at,
						'pageview' AS event_type,
						p.session_id AS session_id,
						p.page_url AS landing,
						'' AS form_id,
						'' AS meta_json,
						COALESCE(s.first_project_slug, '') AS project,
						COALESCE(s.source, '') AS source
					FROM {$pageviews} p
					LEFT JOIN {$sessions} s ON s.session_id = p.session_id
					WHERE " . ( $session_id !== '' ? 'p.session_id = %s' : 'p.viewed_at >= %s AND p.viewed_at < %s' );
				$pageview_params = $session_id !== '' ? array( $session_id ) : array( $start, $end );
			}

			if ( $event_filter === 'all' || $event_filter === 'form_start' || $event_filter === 'form_submit' ) {
				$params = $session_id !== '' ? array( $session_id ) : array( $start, $end );
				if ( in_array( $event_filter, array( 'form_start', 'form_submit' ), true ) ) {
					$params[] = $event_filter;
				}
				$parts[] = "SELECT
						e.occurred_at AS occurred_at,
						e.event_type AS event_type,
						e.session_id AS session_id,
						e.page_url AS landing,
						e.event_label AS form_id,
						e.meta_json AS meta_json,
						COALESCE(s.first_project_slug, '') AS project,
						COALESCE(s.source, '') AS source
					FROM {$events} e
					LEFT JOIN {$sessions} s ON s.session_id = e.session_id
					WHERE " . ( $session_id !== '' ? 'e.session_id = %s' : 'e.occurred_at >= %s AND e.occurred_at < %s' ) . " AND {$event_type_sql}";
			}

			$chatbot_types = array( 'chatbot_open', 'chatbot_message', 'chatbot_started', 'chatbot_opened', 'chatbot_closed', 'chatbot_completed', 'chatbot_message_sent', 'chatbot_intent_selected', 'chatbot_phone_captured', 'chatbot_email_captured', 'chatbot_lead_created', 'chatbot_escalation_requested' );
			if ( $event_filter === 'all' || $event_filter === 'chatbot' ) {
				$cb_params = $session_id !== '' ? array( $session_id ) : array( $start, $end );
				$cb_in = implode( ',', array_fill( 0, count( $chatbot_types ), '%s' ) );
				$cb_params = array_merge( $cb_params, $chatbot_types );
				$parts[] = "SELECT
						e.occurred_at AS occurred_at,
						e.event_type AS event_type,
						e.session_id AS session_id,
						e.page_url AS landing,
						e.event_label AS form_id,
						e.meta_json AS meta_json,
						COALESCE(s.first_project_slug, '') AS project,
						COALESCE(s.source, '') AS source
					FROM {$events} e
					LEFT JOIN {$sessions} s ON s.session_id = e.session_id
					WHERE " . ( $session_id !== '' ? 'e.session_id = %s' : 'e.occurred_at >= %s AND e.occurred_at < %s' ) . " AND e.event_type IN ({$cb_in})";
			} else {
				$cb_params = array();
			}

			$sql = implode( ' UNION ALL ', $parts ) . ' ORDER BY occurred_at DESC LIMIT 200';
			$query_params = array_merge( $pageview_params, $params, $cb_params );

				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ) ?: array();
				foreach ( $rows as &$row ) {
					if ( in_array( (string) ( $row['event_type'] ?? '' ), array( 'form_start', 'form_submit' ), true ) ) {
						$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
						if ( is_array( $meta ) && ! empty( $meta['project_slug'] ) ) {
							$row['project'] = sanitize_title( remove_accents( (string) $meta['project_slug'] ) );
						}
					}
					unset( $row['meta_json'] );
				}
				unset( $row );

				return $rows;
			}

			public static function get_time_analysis( array $filters ): array {
				global $wpdb;

				list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
				$pageviews = EA_Utils::table( 'pageviews' );
				$events    = EA_Utils::table( 'events' );
				$sessions  = EA_Utils::table( 'sessions' );
				$leads     = $wpdb->prefix . 'es_leads';

				$traffic = self::time_bucket_rows(
					self::time_rows_with_session_filters(
						"SELECT p.viewed_at AS occurred_at FROM {$pageviews} p LEFT JOIN {$sessions} s ON s.session_id = p.session_id",
						'p.viewed_at',
						'p.page_url',
						's.first_project_slug',
						$filters,
						$start,
						$end
					)
				);

				$form_submits = self::time_bucket_rows(
					self::time_rows_with_session_filters(
						"SELECT e.occurred_at AS occurred_at FROM {$events} e LEFT JOIN {$sessions} s ON s.session_id = e.session_id",
						'e.occurred_at',
						'e.page_url',
						's.first_project_slug',
						$filters,
						$start,
						$end,
						'e.event_type = %s',
						array( 'form_submit' )
					)
				);

				$lead_created = array();
				if ( self::leads_table_exists() ) {
					$where  = array( 'l.created_at >= %s', 'l.created_at < %s' );
					$params = array( $start, $end );
					if ( $filters['source'] !== '' ) {
						$where[]  = 's.source = %s';
						$params[] = $filters['source'];
					}
					if ( $filters['campaign'] !== '' ) {
						$where[]  = 's.campaign = %s';
						$params[] = $filters['campaign'];
					}
					if ( $filters['project'] !== '' ) {
						$where[]  = 'l.project_slug = %s';
						$params[] = $filters['project'];
					}
					if ( $filters['page'] !== '' ) {
						$where[]  = 's.landing_page LIKE %s';
						$params[] = '%' . $wpdb->esc_like( $filters['page'] ) . '%';
					}
					$rows = $wpdb->get_col( $wpdb->prepare(
						"SELECT l.created_at FROM {$leads} l LEFT JOIN {$sessions} s ON s.session_id = l.first_session_id WHERE " . implode( ' AND ', $where ),
						$params
					) ) ?: array();
					$lead_created = self::time_bucket_rows( $rows );
				}

				$conversion = ! empty( $lead_created['total'] ) ? $lead_created : $form_submits;
				$comparison = self::time_comparison_rows( $traffic['hours'], $conversion['hours'] );

				return array(
					'traffic'           => $traffic,
					'form_submits'      => $form_submits,
					'lead_created'      => $lead_created,
					'conversion_source' => ! empty( $lead_created['total'] ) ? 'lead_created' : 'form_submit',
					'comparison'        => $comparison,
				);
			}

			private static function time_rows_with_session_filters( string $from_sql, string $date_field, string $page_field, string $project_field, array $filters, string $start, string $end, string $extra_where = '', array $extra_params = array() ): array {
				global $wpdb;

				$where  = array( "{$date_field} >= %s", "{$date_field} < %s" );
				$params = array( $start, $end );
				if ( $extra_where !== '' ) {
					$where[] = $extra_where;
					$params  = array_merge( $params, $extra_params );
				}
				if ( $filters['source'] !== '' ) {
					$where[]  = 's.source = %s';
					$params[] = $filters['source'];
				}
				if ( $filters['campaign'] !== '' ) {
					$where[]  = 's.campaign = %s';
					$params[] = $filters['campaign'];
				}
				if ( $filters['project'] !== '' ) {
					$where[]  = "{$project_field} = %s";
					$params[] = $filters['project'];
				}
				if ( $filters['page'] !== '' ) {
					$where[]  = "{$page_field} LIKE %s";
					$params[] = '%' . $wpdb->esc_like( $filters['page'] ) . '%';
				}

				return $wpdb->get_col( $wpdb->prepare(
					$from_sql . ' WHERE ' . implode( ' AND ', $where ),
					$params
				) ) ?: array();
			}

			private static function time_bucket_rows( array $timestamps ): array {
				$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
				$utc   = new DateTimeZone( 'UTC' );
				$days  = array();
				$slots = array();
				$hours = array();
				foreach ( $timestamps as $timestamp ) {
					$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $timestamp, $utc );
					if ( ! $dt ) {
						continue;
					}
					$local = $dt->setTimezone( $tz );
					$day   = self::weekday_label( (int) $local->format( 'N' ) );
					$hour  = $local->format( 'H:00' );
					$slot  = $day . ' ' . $hour;
					$days[ $day ]   = ( $days[ $day ] ?? 0 ) + 1;
					$slots[ $slot ] = ( $slots[ $slot ] ?? 0 ) + 1;
					$hours[ $hour ] = ( $hours[ $hour ] ?? 0 ) + 1;
				}

				return array(
					'total' => array_sum( $hours ),
					'days'  => self::top_time_rows( $days, 10 ),
					'slots' => self::top_time_rows( $slots, 10 ),
					'hours' => $hours,
				);
			}

			private static function top_time_rows( array $values, int $limit ): array {
				arsort( $values, SORT_NUMERIC );
				$out = array();
				foreach ( array_slice( $values, 0, $limit, true ) as $label => $total ) {
					$out[] = array( 'label' => (string) $label, 'total' => (int) $total );
				}
				return $out;
			}

			private static function time_comparison_rows( array $traffic, array $leads ): array {
				$rows = array();
				for ( $h = 0; $h < 24; $h++ ) {
					$hour = sprintf( '%02d:00', $h );
					$t    = (int) ( $traffic[ $hour ] ?? 0 );
					$l    = (int) ( $leads[ $hour ] ?? 0 );
					if ( $t === 0 && $l === 0 ) {
						continue;
					}
					$rows[] = array(
						'hour'    => $hour,
						'traffic' => $t,
						'leads'   => $l,
						'rate'    => $t > 0 ? round( ( $l / $t ) * 100, 1 ) : 0,
					);
				}
				usort( $rows, static function( $a, $b ) {
					return ( (int) $b['traffic'] + (int) $b['leads'] ) <=> ( (int) $a['traffic'] + (int) $a['leads'] );
				} );
				return array_slice( $rows, 0, 10 );
			}

			private static function weekday_label( int $day ): string {
				$labels = array( 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' );
				return $labels[ $day ] ?? '';
			}

				private static function get_session_journey_context( string $session_id ): array {
				global $wpdb;

				$session_id = EA_Utils::sanitize_text( $session_id, 64 );
				if ( $session_id === '' || ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $session_id ) ) {
					return array();
				}

				$sessions    = EA_Utils::table( 'sessions' );
				$leads       = $wpdb->prefix . 'es_leads';
				$submissions = $wpdb->prefix . 'es_lead_submissions';
				$operators   = $wpdb->prefix . 'es_operators';
				$has_leads       = self::table_exists( $leads );
				$has_submissions = self::table_exists( $submissions );

				$session = self::table_exists( $sessions )
					? (array) $wpdb->get_row( $wpdb->prepare(
						"SELECT session_id, source, medium, campaign, traffic_type, first_project_slug
						FROM {$sessions}
						WHERE session_id = %s
						LIMIT 1",
						$session_id
					), ARRAY_A )
					: array();

				error_log(
					'[EA_QUERY_00_SESSION] session_id=' . $session_id .
					' | found=' . ( ! empty( $session ) ? 'YES' : 'NO' ) .
					' | first_project_slug=[' . ( $session['first_project_slug'] ?? 'NULL' ) . ']'
				);

				$row = array();
				$query_executed = 'NONE';

				if ( $has_submissions ) {
					if ( $has_leads ) {
						$query_executed = 'SUBMISSIONS_WITH_LEADS_JOIN';
						$row = (array) $wpdb->get_row( $wpdb->prepare(
							"SELECT sub.id AS submission_id, sub.lead_id, sub.utm_source, sub.utm_medium, sub.utm_campaign,
									sub.source_form_id, sub.submitted_at,
									l.id AS lead_id, l.project_slug, l.project_area, l.first_utm_source, l.first_utm_medium,
									l.assigned_operator_id, l.assigned_operator_name, l.assigned_operator_email
							FROM {$submissions} sub
							LEFT JOIN {$leads} l ON l.id = sub.lead_id
							WHERE sub.session_id = %s
							ORDER BY sub.submitted_at DESC, sub.id DESC
							LIMIT 1",
							$session_id
						), ARRAY_A );
					} else {
						$query_executed = 'SUBMISSIONS_ONLY';
						$row = (array) $wpdb->get_row( $wpdb->prepare(
							"SELECT id AS submission_id, lead_id, utm_source, utm_medium, utm_campaign,
									source_form_id, submitted_at
							FROM {$submissions}
							WHERE session_id = %s
							ORDER BY submitted_at DESC, id DESC
							LIMIT 1",
							$session_id
						), ARRAY_A );
					}
					error_log( '[EA_QUERY_01_' . $query_executed . '] found_rows=' . count( $row ) );
				}

				if ( empty( $row ) && $has_leads ) {
					$query_executed = 'LEADS_BY_FIRST_SESSION_ID';
					$row = (array) $wpdb->get_row( $wpdb->prepare(
						"SELECT id AS lead_id, project_slug, project_area, first_utm_source, first_utm_medium,
								assigned_operator_id, assigned_operator_name, assigned_operator_email
						FROM {$leads}
						WHERE first_session_id = %s
						ORDER BY created_at DESC, id DESC
						LIMIT 1",
						$session_id
					), ARRAY_A );
					error_log( '[EA_QUERY_02_LEADS_BY_FIRST_SESSION_ID] found_rows=' . count( $row ) );

					if ( ! empty( $row['lead_id'] ) && $has_submissions ) {
						$submission = (array) $wpdb->get_row( $wpdb->prepare(
							"SELECT id AS submission_id, utm_source, utm_medium, utm_campaign, source_form_id, submitted_at
							FROM {$submissions}
							WHERE lead_id = %d
							ORDER BY submitted_at DESC, id DESC
							LIMIT 1",
							(int) $row['lead_id']
						), ARRAY_A );
						$row = array_merge( $submission, $row );
					}
				}

				$operator = self::first_non_empty( array( $row['assigned_operator_name'] ?? '', $row['assigned_operator_email'] ?? '' ) );
				if ( $operator === '' && ! empty( $row['assigned_operator_id'] ) && self::table_exists( $operators ) ) {
					$operator = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT name FROM {$operators} WHERE id = %d LIMIT 1",
						(int) $row['assigned_operator_id']
					) );
				}

				$source  = self::first_non_empty( array( $session['source'] ?? '', $row['utm_source'] ?? '', $row['first_utm_source'] ?? '' ) );
				$medium  = self::first_non_empty( array( $session['medium'] ?? '', $row['utm_medium'] ?? '', $row['first_utm_medium'] ?? '' ) );
				$channel = trim( $source . ( $medium !== '' ? ' / ' . $medium : '' ) );
				if ( $channel === '' ) {
					$channel = (string) ( $session['traffic_type'] ?? '' );
				}

				$lead_id       = (int) ( $row['lead_id'] ?? 0 );
				$submission_id = (int) ( $row['submission_id'] ?? 0 );

					$resolved_project_slug = self::first_non_empty( array( $row['project_slug'] ?? '', $session['first_project_slug'] ?? '' ) );
					$resolved_project_area = $row['project_area'] ?? '';
					$resolved_project_display = resolve_project_display_name( $resolved_project_slug, $resolved_project_area );

					return array(
					'lead_id'        => $lead_id,
					'submission_id'  => $submission_id,
					'project'        => $resolved_project_display,
					'channel'        => $channel,
					'operator'       => $operator,
					'lead_url'       => $lead_id > 0 ? admin_url( 'admin.php?page=estandar-leads-portal&view=detail&lead_id=' . $lead_id ) : '',
					'submission_url' => $submission_id > 0 ? admin_url( 'admin.php?page=estandar-forms-entries&view=submission&submission_id=' . $submission_id ) : '',
				);
			}

			private static function first_non_empty( array $values ): string {
				foreach ( $values as $value ) {
					$value = trim( (string) $value );
					if ( $value !== '' ) {
						return $value;
					}
				}

				return '';
			}

		// -------------------------------------------------------------------------
	// ATTRIBUTION REPORTING V1
	// -------------------------------------------------------------------------

	/**
	 * Datos completos para la pantalla de Atribución.
	 * Cinco secciones: fuentes, funnel por fuente, campañas, proyectos, señal.
	 *
	 * @param array $filters  Desde EA_Utils::admin_filters().
	 * @return array
	 */
	public static function get_attribution_data( array $filters ): array {
		global $wpdb;

		$s  = EA_Utils::table( 'sessions' );
		$c  = EA_Utils::table( 'conversions' );
		$l  = $wpdb->prefix . 'es_leads';
		$submissions = $wpdb->prefix . 'es_lead_submissions';
		$has_leads = self::leads_table_exists();
		$has_submissions = self::table_exists( $submissions );

		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		// ── W1: Fuentes de tráfico ────────────────────────────────────────────
		$sources_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					traffic_type                             AS canal,
					COUNT(*)                                 AS sesiones,
					SUM(CASE WHEN gclid  != '' THEN 1 ELSE 0 END) AS con_gclid,
					SUM(CASE WHEN fbclid != '' THEN 1 ELSE 0 END) AS con_fbclid,
					SUM(is_bounce)                           AS bounces,
					ROUND(AVG(duration))                     AS duracion_avg
				 FROM {$s}
				 WHERE first_seen >= %s AND first_seen < %s
				 GROUP BY traffic_type
				 ORDER BY sesiones DESC",
				$start, $end
			),
			ARRAY_A
		) ?: [];

		$total_sessions = array_sum( array_column( $sources_raw, 'sesiones' ) );
		$sources = array_map( static function( $r ) use ( $total_sessions ) {
			$r['pct'] = $total_sessions > 0 ? round( $r['sesiones'] * 100 / $total_sessions, 1 ) : 0;
			return $r;
		}, $sources_raw );

		// ── W2: Funnel por fuente (sesión → lead → conversión) ────────────────
		$funnel_by_source = [];
		if ( $has_leads ) {
			$funnel_by_source = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						COALESCE(NULLIF(s.traffic_type,''), 'Unknown') AS canal,
						s.source                                        AS source,
						COUNT(DISTINCT s.id)                            AS sesiones,
						COUNT(DISTINCT l.id)                            AS leads,
						COUNT(DISTINCT cv.id)                           AS conversiones,
						ROUND(
							COUNT(DISTINCT l.id) * 100.0
							/ NULLIF(COUNT(DISTINCT s.id), 0),
						1)                                              AS conv_rate
					 FROM {$s} s
					 LEFT JOIN {$l} l  ON l.first_session_id = s.session_id
					 LEFT JOIN {$c} cv ON cv.session_id      = s.session_id
					 WHERE s.first_seen >= %s AND s.first_seen < %s
					 GROUP BY canal, s.source
					 ORDER BY leads DESC, sesiones DESC
					 LIMIT 15",
					$start, $end
				),
				ARRAY_A
			) ?: [];
		}

		// ── W3: Campañas ──────────────────────────────────────────────────────
		// Sintaxis explícita (sin ternario encadenado) para compatibilidad PHP 7/8.
		if ( $has_leads ) {
			$campaigns = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						s.campaign           AS campana,
						s.source,
						s.medium,
						COUNT(DISTINCT s.id) AS sesiones,
						COUNT(DISTINCT l.id) AS leads,
						ROUND(
							COUNT(DISTINCT l.id) * 100.0
							/ NULLIF(COUNT(DISTINCT s.id), 0),
						1)                   AS conv_rate
					 FROM {$s} s
					 LEFT JOIN {$l} l ON l.first_session_id = s.session_id
					 WHERE s.campaign != ''
					   AND s.first_seen >= %s AND s.first_seen < %s
					 GROUP BY s.campaign, s.source, s.medium
					 ORDER BY leads DESC, sesiones DESC
					 LIMIT 20",
					$start, $end
				),
				ARRAY_A
			);
			$campaigns = is_array( $campaigns ) ? $campaigns : [];
		} else {
			$campaigns = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT campaign AS campana, source, medium,
					        COUNT(*) AS sesiones, 0 AS leads, 0.0 AS conv_rate
					 FROM {$s}
					 WHERE campaign != '' AND first_seen >= %s AND first_seen < %s
					 GROUP BY campaign, source, medium
					 ORDER BY sesiones DESC LIMIT 20",
					$start, $end
				),
				ARRAY_A
			);
			$campaigns = is_array( $campaigns ) ? $campaigns : [];
		}

		// ── W4: Proyectos ─────────────────────────────────────────────────────
		$projects = [];
		if ( $has_leads ) {
			$projects = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						l.project_slug,
						COUNT(DISTINCT l.id)                                               AS leads,
						SUM(CASE WHEN s.traffic_type = 'Paid Search'    THEN 1 ELSE 0 END) AS leads_google_ads,
						SUM(CASE WHEN s.traffic_type = 'Paid Social'    THEN 1 ELSE 0 END) AS leads_meta,
						SUM(CASE WHEN s.traffic_type = 'Organic Search' THEN 1 ELSE 0 END) AS leads_organic,
						SUM(CASE WHEN s.traffic_type = 'Direct'         THEN 1 ELSE 0 END) AS leads_direct,
						SUM(CASE WHEN s.session_id IS NULL              THEN 1 ELSE 0 END) AS leads_sin_sesion
					 FROM {$l} l
					 LEFT JOIN {$s} s ON s.session_id = l.first_session_id
					 WHERE l.project_slug != ''
					   AND l.created_at  >= %s AND l.created_at < %s
					 GROUP BY l.project_slug
					 ORDER BY leads DESC
					 LIMIT 20",
					$start, $end
				),
				ARRAY_A
			) ?: [];
		}

		// ── W5: Calidad de señal ──────────────────────────────────────────────
		$total_sess = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE first_seen >= %s AND first_seen < %s", $start, $end )
		);

		$signal = [
			'total_sessions'  => $total_sess,
			'con_gclid'       => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$s} WHERE gclid != '' AND first_seen >= %s AND first_seen < %s", $start, $end ) ),
			'con_fbclid'      => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$s} WHERE fbclid != '' AND first_seen >= %s AND first_seen < %s", $start, $end ) ),
			'unknown_traffic' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$s} WHERE traffic_type IN ('Unknown','') AND first_seen >= %s AND first_seen < %s", $start, $end ) ),
			'leads_total'     => 0,
			'leads_con_sid'   => 0,
			'leads_linkados'  => 0,
			'session_join_rate' => 0.0,
		];

		// Columnas nuevas (V1 — pueden no existir en producción sin migrar)
		$cols = self::table_columns( $s );
		if ( in_array( 'gbraid', $cols, true ) ) {
			$signal['con_gbraid'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$s} WHERE gbraid != '' AND first_seen >= %s AND first_seen < %s", $start, $end ) );
			$signal['con_meta_fbp'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$s} WHERE meta_fbp != '' AND first_seen >= %s AND first_seen < %s", $start, $end ) );
		}

		if ( $has_leads ) {
			$signal['leads_total']   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$l} WHERE created_at >= %s AND created_at < %s", $start, $end ) );
			$signal['leads_con_sid'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$l} WHERE first_session_id != '' AND created_at >= %s AND created_at < %s", $start, $end ) );
			$signal['leads_linkados'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT l.id) FROM {$l} l
				 INNER JOIN {$s} s ON s.session_id = l.first_session_id
				 WHERE l.created_at >= %s AND l.created_at < %s", $start, $end ) );
				$signal['session_join_rate'] = $signal['leads_total'] > 0
					? round( $signal['leads_linkados'] * 100 / $signal['leads_total'], 1 )
					: 0.0;

				$lead_cols = self::table_columns( $l );
				$signal['identity_diagnostics'] = self::identity_diagnostics(
					$l,
					$submissions,
					$s,
					$lead_cols,
					self::table_columns( $submissions ),
					$has_submissions,
					$start,
					$end
				);
			}

		return compact( 'sources', 'funnel_by_source', 'campaigns', 'projects', 'signal', 'total_sessions' );
	}

	private static function identity_diagnostics(
		string $leads,
		string $submissions,
		string $sessions,
		array $lead_cols,
		array $submission_cols,
		bool $has_submissions,
		string $start,
		string $end
	): array {
		global $wpdb;

		$out = array(
			'leads_session_no_match' => 0,
			'submissions_session_no_match' => 0,
			'submissions_click_id_lost' => 0,
			'exportable_without_identity' => 0,
		);

		$out['leads_session_no_match'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads} l
				 LEFT JOIN {$sessions} s ON s.session_id = l.first_session_id
				 WHERE l.created_at >= %s AND l.created_at < %s
				   AND l.first_session_id != ''
				   AND s.session_id IS NULL",
				$start,
				$end
			)
		);

		if ( $has_submissions && in_array( 'session_id', $submission_cols, true ) ) {
			$out['submissions_session_no_match'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$submissions} sub
					 LEFT JOIN {$sessions} s ON s.session_id = sub.session_id
					 WHERE sub.created_at >= %s AND sub.created_at < %s
					   AND sub.session_id != ''
					   AND s.session_id IS NULL",
					$start,
					$end
				)
			);
		}

		if ( $has_submissions ) {
			$checks = array(
				'gclid' => 'first_gclid',
				'gbraid' => 'first_gbraid',
				'wbraid' => 'first_wbraid',
				'fbclid' => 'first_fbclid',
				'meta_fbc' => 'first_meta_fbc',
				'meta_fbp' => 'first_meta_fbp',
				'ttclid' => 'first_ttclid',
				'msclkid' => 'first_msclkid',
			);
			$loss_clauses = array();
			foreach ( $checks as $sub_col => $lead_col ) {
				if ( in_array( $sub_col, $submission_cols, true ) && in_array( $lead_col, $lead_cols, true ) ) {
					$loss_clauses[] = "(sub.{$sub_col} != '' AND (l.{$lead_col} = '' OR l.{$lead_col} IS NULL))";
				}
			}
			if ( ! empty( $loss_clauses ) ) {
				$out['submissions_click_id_lost'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT sub.id) FROM {$submissions} sub
						 INNER JOIN {$leads} l ON l.id = sub.lead_id
						 WHERE sub.created_at >= %s AND sub.created_at < %s
						   AND (" . implode( ' OR ', $loss_clauses ) . ')',
						$start,
						$end
					)
				);
			}
		}

		$identity_clauses = array();
		foreach ( array( 'first_gclid', 'first_gbraid', 'first_wbraid', 'first_fbclid', 'first_meta_fbc', 'first_meta_fbp', 'first_ttclid', 'first_msclkid', 'email_hash', 'phone_hash' ) as $field ) {
			if ( in_array( $field, $lead_cols, true ) ) {
				$identity_clauses[] = "({$field} != '' AND {$field} IS NOT NULL)";
			}
		}
		if ( ! empty( $identity_clauses ) ) {
			$commercial_statuses_sql = defined( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1' ) && ESTANDAR_ANALYTICS_NORMALIZATION_V1
				? Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' ) . ',' . Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' ) . ',' . Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' ) . ',' . Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' )
				: "'QUALIFIED','MEETING_BOOKED','VISIT_SCHEDULED','VISITED','RESERVED','DEED_SIGNED','DELIVERED'";
			$out['exportable_without_identity'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$leads}
					 WHERE created_at >= %s AND created_at < %s
					   AND status IN ({$commercial_statuses_sql})
					   AND NOT (" . implode( ' OR ', $identity_clauses ) . ')',
					$start,
					$end
				)
			);
		}

		return $out;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function table_columns( string $table ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		if ( empty( $rows ) ) {
			$rows = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row['Field'] ?? ( $row['name'] ?? '' ) );
			if ( $name !== '' ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	public static function get_acquisition( $filters ) {
		global $wpdb;
		$sessions  = EA_Utils::table( 'sessions' );
		$referrers = EA_Utils::table( 'referrers' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$source_medium = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, medium, COUNT(*) AS total FROM {$sessions} WHERE first_seen >= %s AND first_seen < %s GROUP BY source, medium ORDER BY total DESC LIMIT 50",
				$start,
				$end
			),
			ARRAY_A
		);

		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT campaign, COUNT(*) AS total FROM {$sessions} WHERE campaign != '' AND first_seen >= %s AND first_seen < %s GROUP BY campaign ORDER BY total DESC LIMIT 30",
				$start,
				$end
			),
			ARRAY_A
		);

		$utm = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, medium, campaign, content, term, COUNT(*) AS total FROM {$sessions} WHERE (campaign != '' OR content != '' OR term != '') AND first_seen >= %s AND first_seen < %s GROUP BY source, medium, campaign, content, term ORDER BY total DESC LIMIT 50",
				$start,
				$end
			),
			ARRAY_A
		);

		$refs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain, COUNT(*) AS total FROM {$referrers} WHERE referrer_domain != '' AND recorded_at >= %s AND recorded_at < %s GROUP BY referrer_domain ORDER BY total DESC LIMIT 30",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'source_medium' => $source_medium ?: array(),
			'campaigns'     => $campaigns ?: array(),
			'utm'           => $utm ?: array(),
			'referrers'     => $refs ?: array(),
		);
	}

	public static function get_conversions( $filters ) {
		global $wpdb;
		$conversions = EA_Utils::table( 'conversions' );
		$sessions    = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$where  = array( 'c.converted_at >= %s', 'c.converted_at < %s' );
		$params = array( $start, $end );

		if ( $filters['conversion_type'] !== '' ) {
			$where[]  = 'c.conversion_key = %s';
			$params[] = $filters['conversion_type'];
		}
		if ( $filters['source'] !== '' ) {
			$where[]  = 'c.source = %s';
			$params[] = $filters['source'];
		}

		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.conversion_key, c.conversion_label, c.source, c.landing_page, c.converted_at, c.page_url FROM {$conversions} c WHERE " . implode( ' AND ', $where ) . " ORDER BY c.converted_at DESC LIMIT 200",
				$params
			),
			ARRAY_A
		);

		$by_source = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.source, COUNT(*) AS conversions, (SELECT COUNT(*) FROM {$sessions} s WHERE s.source = c.source AND s.first_seen >= %s AND s.first_seen < %s) AS sessions FROM {$conversions} c WHERE c.converted_at >= %s AND c.converted_at < %s GROUP BY c.source ORDER BY conversions DESC LIMIT 20",
				$start,
				$end,
				$start,
				$end
			),
			ARRAY_A
		);

		$by_landing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT landing_page, COUNT(*) AS conversions FROM {$conversions} WHERE landing_page != '' AND converted_at >= %s AND converted_at < %s GROUP BY landing_page ORDER BY conversions DESC LIMIT 20",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'events'     => $events ?: array(),
			'by_source'  => $by_source ?: array(),
			'by_landing' => $by_landing ?: array(),
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'estandar-analytics' ) );
		}
		check_admin_referer( 'ea_export' );

		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'sessions';
		$filters = EA_Utils::admin_filters();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ea-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}

		switch ( $type ) {
			case 'acquisition':
				self::export_acquisition_csv( $out, $filters );
				break;
			case 'conversions':
				self::export_conversions_csv( $out, $filters );
				break;
			default:
				self::export_sessions_csv( $out, $filters );
		}

		fclose( $out );
		exit;
	}

	private static function export_sessions_csv( $out, $filters ) {
		fputcsv( $out, array( 'session_id', 'first_seen', 'source', 'landing_page', 'duration', 'page_count', 'device' ) );
		foreach ( self::get_sessions( $filters ) as $row ) {
			fputcsv(
				$out,
				array(
					$row['session_id'],
					$row['first_seen'],
					$row['source'],
					$row['landing_page'],
					$row['duration'],
					$row['page_count'],
					$row['device_type'],
				)
			);
		}
	}

	private static function export_acquisition_csv( $out, $filters ) {
		$data = self::get_acquisition( $filters );
		fputcsv( $out, array( 'source', 'medium', 'total' ) );
		foreach ( $data['source_medium'] as $row ) {
			fputcsv( $out, array( $row['source'], $row['medium'], $row['total'] ) );
		}
	}

	private static function export_conversions_csv( $out, $filters ) {
		$data = self::get_conversions( $filters );
		fputcsv( $out, array( 'conversion_key', 'conversion_label', 'source', 'landing_page', 'converted_at', 'page_url' ) );
		foreach ( $data['events'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['conversion_key'],
					$row['conversion_label'],
					$row['source'],
					$row['landing_page'],
					$row['converted_at'],
					$row['page_url'],
				)
			);
		}
	}

	public static function render_filters_form( $options = array() ) {
		EA_Admin_UI::render_filters_toolbar( $options );
	}

	public static function get_filter_options() {
		global $wpdb;
		$sessions    = EA_Utils::table( 'sessions' );
			$devices     = EA_Utils::table( 'devices' );
			$conversions = EA_Utils::table( 'conversions' );

			$sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$sessions} WHERE source != '' ORDER BY source ASC LIMIT 80" );
			$camps   = $wpdb->get_col( "SELECT DISTINCT campaign FROM {$sessions} WHERE campaign != '' ORDER BY campaign ASC LIMIT 80" );
			$devs    = $wpdb->get_col( "SELECT DISTINCT device_type FROM {$devices} WHERE device_type != '' ORDER BY device_type ASC LIMIT 20" );
			$convs   = $wpdb->get_col( "SELECT DISTINCT conversion_key FROM {$conversions} ORDER BY conversion_key ASC LIMIT 50" );

			$projects = array();
			if ( function_exists( 'estandar_core_get_projects' ) ) {
				foreach ( (array) estandar_core_get_projects() as $project ) {
					$slug = sanitize_title( remove_accents( (string) ( $project['project_id'] ?? '' ) ) );
					if ( $slug !== '' ) {
						$projects[] = $slug;
					}
				}
			}

			return array(
				'sources'     => is_array( $sources ) ? $sources : array(),
				'campaigns'   => is_array( $camps ) ? $camps : array(),
				'devices'     => is_array( $devs ) ? $devs : array(),
				'conversions' => is_array( $convs ) ? $convs : array(),
				'projects'    => $projects,
		);
	}

		public static function get_campaign_journey( array $filters ): array {
			global $wpdb;
		$_qual_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$_meet_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
			$sessions = EA_Utils::table( 'sessions' );
			$events   = EA_Utils::table( 'events' );
			list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$where  = array( 's.first_seen >= %s', 's.first_seen < %s' );
		$params = array( $start, $end );
		foreach ( array( 'source', 'campaign', 'campaign_id', 'adset_id', 'ad_id' ) as $field ) {
			if ( ( $filters[ $field ] ?? '' ) !== '' ) {
				$where[]  = "s.{$field} = %s";
				$params[] = $filters[ $field ];
			}
		}
		if ( ( $filters['project'] ?? '' ) !== '' ) {
			$where[]  = 's.first_project_slug = %s';
			$params[] = $filters['project'];
		}
		$where_sql = implode( ' AND ', $where );
		$key_sql   = "COALESCE(NULLIF(s.campaign_id,''), NULLIF(s.campaign,''), '')";
		$set_sql   = "COALESCE(NULLIF(s.adset_id,''), NULLIF(s.term,''), '')";
		$ad_sql    = "COALESCE(NULLIF(s.ad_id,''), NULLIF(s.content,''), '')";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
					"SELECT {$key_sql} AS campaign_key, {$set_sql} AS adset_key, {$ad_sql} AS ad_key,
						MAX(s.campaign_id) AS campaign_id, MAX(s.adset_id) AS adset_id, MAX(s.ad_id) AS ad_id,
						MAX(s.campaign) AS utm_campaign, MAX(s.term) AS utm_term, MAX(s.content) AS utm_content,
						MAX(s.source) AS source, MAX(s.traffic_type) AS channel, MAX(s.first_project_slug) AS project_slug,
						COUNT(DISTINCT s.session_id) AS landing_sessions
				FROM {$sessions} s
				WHERE {$where_sql}
				GROUP BY campaign_key, adset_key, ad_key
				ORDER BY landing_sessions DESC
				LIMIT 100",
				$params
			),
			ARRAY_A
		) ?: array();

		$indexed = array();
		foreach ( $rows as $row ) {
			$key = self::journey_key( $row );
			$indexed[ $key ] = array_merge(
					array(
						'impressions' => 0, 'reach' => 0, 'clicks' => 0, 'spend' => 0.0,
						'campaign_name' => '', 'adset_name' => '', 'ad_name' => '',
						'popup_opens' => 0, 'popup_form_starts' => 0, 'popup_form_submits' => 0,
					'chatbot_starts' => 0, 'chatbot_leads' => 0, 'form_starts' => 0, 'form_submits' => 0,
					'leads' => 0, 'qualified_leads' => 0, 'visits' => 0, 'reservations' => 0, 'sales' => 0,
				),
				$row,
				array( 'journey_key' => $key )
			);
		}

		$event_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$key_sql} AS campaign_key, {$set_sql} AS adset_key, {$ad_sql} AS ad_key,
					SUM(e.event_type = 'popup_opened') AS popup_opens,
					SUM(e.event_type = 'popup_form_started') AS popup_form_starts,
					SUM(e.event_type = 'popup_form_submitted') AS popup_form_submits,
					SUM(e.event_type = 'chatbot_started') AS chatbot_starts,
					SUM(e.event_type IN ('chatbot_lead','chatbot_lead_created')) AS chatbot_leads,
					SUM(e.event_type = 'form_interaction') AS form_starts,
					SUM(e.event_type = 'form_submit') AS form_submits
				FROM {$sessions} s
				INNER JOIN {$events} e ON e.session_id = s.session_id
				WHERE {$where_sql}
				  AND e.occurred_at >= %s AND e.occurred_at < %s
				GROUP BY campaign_key, adset_key, ad_key",
				array_merge( $params, array( $start, $end ) )
			),
			ARRAY_A
		) ?: array();
		foreach ( $event_rows as $row ) {
			$key = self::journey_key( $row );
			if ( ! isset( $indexed[ $key ] ) ) {
				continue;
			}
			foreach ( array( 'popup_opens', 'popup_form_starts', 'popup_form_submits', 'chatbot_starts', 'chatbot_leads', 'form_starts', 'form_submits' ) as $metric ) {
				$indexed[ $key ][ $metric ] = (int) ( $row[ $metric ] ?? 0 );
			}
		}

		if ( self::leads_table_exists() ) {
			$leads = $wpdb->prefix . 'es_leads';
			$lead_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$key_sql} AS campaign_key, {$set_sql} AS adset_key, {$ad_sql} AS ad_key,
						COUNT(DISTINCT l.id) AS leads,
						SUM(l.status IN ({$_qual_sql})) AS qualified_leads,
						SUM(l.status IN ({$_meet_sql})) AS visits,
						SUM(l.status IN ({$_resv_sql})) AS reservations,
						SUM(l.status IN ({$_sold_sql})) AS sales
					FROM {$sessions} s
					INNER JOIN {$leads} l ON l.first_session_id = s.session_id
					WHERE {$where_sql}
					GROUP BY campaign_key, adset_key, ad_key",
					$params
				),
				ARRAY_A
			) ?: array();
			foreach ( $lead_rows as $row ) {
				$key = self::journey_key( $row );
				if ( ! isset( $indexed[ $key ] ) ) {
					continue;
				}
				foreach ( array( 'leads', 'qualified_leads', 'visits', 'reservations', 'sales' ) as $metric ) {
					$indexed[ $key ][ $metric ] = (int) ( $row[ $metric ] ?? 0 );
				}
			}
		}

		$ad_rows = self::get_ads_daily_rollup( $filters );
		foreach ( $ad_rows as $row ) {
			$key = self::journey_key( $row );
			if ( ! isset( $indexed[ $key ] ) ) {
				$indexed[ $key ] = array_merge( array_fill_keys( array( 'landing_sessions', 'popup_opens', 'popup_form_starts', 'popup_form_submits', 'chatbot_starts', 'chatbot_leads', 'form_starts', 'form_submits', 'leads', 'qualified_leads', 'visits', 'reservations', 'sales' ), 0 ), $row, array( 'journey_key' => $key ) );
			}
			foreach ( array( 'impressions', 'reach', 'clicks' ) as $metric ) {
				$indexed[ $key ][ $metric ] = (int) ( $row[ $metric ] ?? 0 );
			}
			$indexed[ $key ]['spend'] = (float) ( $row['spend'] ?? 0 );
				if ( ! empty( $row['campaign_name'] ) ) {
					$indexed[ $key ]['campaign_name'] = $row['campaign_name'];
				}
				if ( ! empty( $row['adset_name'] ) ) {
					$indexed[ $key ]['adset_name'] = $row['adset_name'];
				}
				if ( ! empty( $row['ad_name'] ) ) {
					$indexed[ $key ]['ad_name'] = $row['ad_name'];
				}
			}

			return array(
				'audit'          => self::campaign_journey_audit_map(),
				'rows'           => array_values( $indexed ),
				'lead_drilldown' => self::get_campaign_journey_leads( $filters ),
			);
		}

	private static function journey_key( array $row ): string {
		return implode( '|', array(
			(string) ( $row['campaign_key'] ?? $row['campaign_id'] ?? '' ),
			(string) ( $row['adset_key'] ?? $row['adset_id'] ?? '' ),
			(string) ( $row['ad_key'] ?? $row['ad_id'] ?? '' ),
		) );
	}

	private static function campaign_journey_audit_map(): array {
		return array(
				'campaign_id'   => 'PARTIAL: Analytics ea_sessions.campaign_id desde URL; externo manual en ea_ads_daily.',
				'adset_id'      => 'PARTIAL: Analytics ea_sessions.adset_id desde URL; fallback utm_term.',
				'ad_id'         => 'PARTIAL: Analytics ea_sessions.ad_id desde URL; fallback utm_content.',
				'campaign_name' => 'EXISTS: ea_ads_daily display label; IDs remain primary keys.',
				'adset_name'    => 'EXISTS: ea_ads_daily display label; adset_id remains primary key.',
				'ad_name'       => 'EXISTS: ea_ads_daily display label; ad_id remains primary key.',
			'utm_campaign'  => 'EXISTS: Analytics sessions.campaign; Forms/Leads submissions/first_utm_campaign.',
			'utm_content'   => 'EXISTS: Analytics sessions.content; Forms/Leads submissions/first_utm_content.',
			'utm_term'      => 'EXISTS: Analytics sessions.term; Forms/Leads submissions/first_utm_term.',
			'fbclid'        => 'EXISTS: Analytics sessions.fbclid; Forms/Leads attribution fields.',
			'gclid'         => 'EXISTS: Analytics sessions.gclid; Forms/Leads attribution fields.',
			'lead_id'       => 'EXISTS: Leads es_leads.id; Analytics joins by first_session_id.',
			'submission_id' => 'EXISTS: Leads es_lead_submissions.id; not duplicated in Campaign Journey V1.',
			'session_id'    => 'EXISTS: Analytics ea_sessions.session_id; Forms/Chatbot pass ea_sid.',
		);
	}

	private static function get_ads_daily_rollup( array $filters ): array {
		global $wpdb;
		$table = EA_Utils::table( 'ads_daily' );
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		list( $from, $to ) = EA_Utils::parse_date_range( $filters['from'], $filters['to'] );
		$where  = array( 'stat_date >= %s', 'stat_date <= %s' );
		$params = array( $from, $to );
		$field_map = array(
			'source'      => 'channel',
			'project'     => 'project_slug',
			'campaign_id' => 'campaign_id',
			'adset_id'    => 'adset_id',
			'ad_id'       => 'ad_id',
		);
		foreach ( $field_map as $filter => $column ) {
			if ( ( $filters[ $filter ] ?? '' ) !== '' ) {
				$where[]  = "{$column} = %s";
				$params[] = $filters[ $filter ];
			}
		}
		if ( ( $filters['campaign'] ?? '' ) !== '' ) {
			$where[]  = '(campaign_name = %s OR campaign_id = %s)';
			$params[] = $filters['campaign'];
			$params[] = $filters['campaign'];
		}

		return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT campaign_id AS campaign_key, adset_id AS adset_key, ad_id AS ad_key,
						campaign_id, adset_id, ad_id,
						MAX(campaign_name) AS campaign_name, MAX(adset_name) AS adset_name, MAX(ad_name) AS ad_name,
						MAX(channel) AS source, MAX(project_slug) AS project_slug,
						SUM(impressions) AS impressions, SUM(reach) AS reach, SUM(clicks) AS clicks, SUM(spend) AS spend
				FROM {$table}
				WHERE " . implode( ' AND ', $where ) . '
				GROUP BY campaign_key, adset_key, ad_key',
				$params
			),
			ARRAY_A
			) ?: array();
	}

	public static function get_ad_platforms_summary( array $filters ): array {
		global $wpdb;
		$_qual_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$_meet_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
		$ads      = EA_Utils::table( 'ads_daily' );
		$sessions = EA_Utils::table( 'sessions' );
		$leads    = $wpdb->prefix . 'es_leads';
		list( $from, $to ) = EA_Utils::parse_date_range( $filters['from'], $filters['to'] );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$platforms = array();
		if ( self::table_exists( $ads ) ) {
			$platforms = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT channel AS platform,
						SUM(impressions) AS impressions,
						SUM(reach) AS reach,
						SUM(clicks) AS clicks,
						SUM(spend) AS spend
					 FROM {$ads}
					 WHERE stat_date >= %s AND stat_date <= %s
					 GROUP BY channel
					 ORDER BY spend DESC",
					$from,
					$to
				),
				ARRAY_A
			) ?: array();
		}

		$sessions_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$sessions} WHERE first_seen >= %s AND first_seen < %s",
			$start,
			$end
		) );

		$lead_totals = array( 'leads' => 0, 'qualified_leads' => 0, 'reservations' => 0 );
		if ( self::leads_table_exists() ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS leads,
						SUM(status IN ({$_qual_sql})) AS qualified_leads,
						SUM(status IN ({$_resv_sql})) AS reservations
					 FROM {$leads}
					 WHERE created_at >= %s AND created_at < %s",
					$start,
					$end
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				$lead_totals = array(
					'leads'           => (int) ( $row['leads'] ?? 0 ),
					'qualified_leads' => (int) ( $row['qualified_leads'] ?? 0 ),
					'reservations'    => (int) ( $row['reservations'] ?? 0 ),
				);
			}
		}

		$total = array(
			'impressions'     => 0,
			'reach'           => 0,
			'clicks'          => 0,
			'spend'           => 0.0,
			'sessions'        => $sessions_count,
			'leads'           => $lead_totals['leads'],
			'qualified_leads' => $lead_totals['qualified_leads'],
			'reservations'    => $lead_totals['reservations'],
		);

		foreach ( $platforms as &$row ) {
			$row['platform'] = self::normalize_ads_platform( (string) ( $row['platform'] ?? '' ) );
			foreach ( array( 'impressions', 'reach', 'clicks' ) as $key ) {
				$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
				$total[ $key ] += $row[ $key ];
			}
			$row['spend'] = (float) ( $row['spend'] ?? 0 );
			$total['spend'] += $row['spend'];
			$row = array_merge( $row, self::ad_platform_rates( $row, $sessions_count, $lead_totals ) );
		}
		unset( $row );
		$total = array_merge( $total, self::ad_platform_rates( $total, $sessions_count, $lead_totals ) );

		return array(
			'platforms' => $platforms,
			'total'     => $total,
			'phase2'    => self::ad_platforms_phase2_notes(),
		);
	}

	private static function ad_platform_rates( array $row, int $sessions, array $lead_totals ): array {
		$impressions = max( 0, (int) ( $row['impressions'] ?? 0 ) );
		$clicks      = max( 0, (int) ( $row['clicks'] ?? 0 ) );
		$spend       = max( 0, (float) ( $row['spend'] ?? 0 ) );

		return array(
			'ctr'                     => $impressions > 0 ? ( $clicks / $impressions ) * 100 : null,
			'cpc'                     => $clicks > 0 ? $spend / $clicks : null,
			'cpm'                     => $impressions > 0 ? ( $spend / $impressions ) * 1000 : null,
			'cost_per_session'        => $sessions > 0 ? $spend / $sessions : null,
			'cost_per_lead'           => (int) $lead_totals['leads'] > 0 ? $spend / (int) $lead_totals['leads'] : null,
			'cost_per_qualified_lead' => (int) $lead_totals['qualified_leads'] > 0 ? $spend / (int) $lead_totals['qualified_leads'] : null,
			'cost_per_reservation'    => (int) $lead_totals['reservations'] > 0 ? $spend / (int) $lead_totals['reservations'] : null,
		);
	}

	private static function ad_platforms_phase2_notes(): array {
		return array(
			'Meta Marketing API' => array(
				'requisitos' => 'Business app, ads_read, ad account id, access token de largo plazo.',
				'autenticacion' => 'OAuth Meta; rotación/renovación controlada antes de expiración.',
				'frecuencia' => 'Sincronización diaria; re-sync de últimos 7 días por ajustes de atribución.',
				'riesgos' => 'Permisos Business, límites API, ventanas de atribución y monedas por cuenta.',
			),
			'Google Ads API' => array(
				'requisitos' => 'Developer token, OAuth client, customer id, refresh token, permisos Ads.',
				'autenticacion' => 'OAuth Google con refresh token cifrado en opciones.',
				'frecuencia' => 'Sincronización diaria; re-sync de últimos 7 días por conversion lag.',
				'riesgos' => 'MCC/customer ids, quotas, GAQL versioning y diferencias de zona horaria.',
			),
		);
	}

	private static function get_campaign_journey_leads( array $filters ): array {
		global $wpdb;
		$_qual_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$_meet_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
		if ( ! self::leads_table_exists() ) {
			return array();
		}

		$has_entity_filter = false;
		foreach ( array( 'campaign_id', 'adset_id', 'ad_id' ) as $field ) {
			if ( ( $filters[ $field ] ?? '' ) !== '' ) {
				$has_entity_filter = true;
				break;
			}
		}
		if ( ! $has_entity_filter ) {
			return array();
		}

		$sessions = EA_Utils::table( 'sessions' );
		$leads    = $wpdb->prefix . 'es_leads';
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$where  = array( 's.first_seen >= %s', 's.first_seen < %s' );
		$params = array( $start, $end );

		foreach ( array( 'source', 'campaign_id', 'adset_id', 'ad_id' ) as $field ) {
			if ( ( $filters[ $field ] ?? '' ) !== '' ) {
				$where[]  = "s.{$field} = %s";
				$params[] = $filters[ $field ];
			}
		}
		if ( ( $filters['project'] ?? '' ) !== '' ) {
			$where[]  = 's.first_project_slug = %s';
			$params[] = $filters['project'];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.name, l.project_slug, l.project_area, l.status, l.assigned_operator_name, l.assigned_operator_email, l.created_at
				 FROM {$sessions} s
				 INNER JOIN {$leads} l ON l.first_session_id = s.session_id
				 WHERE " . implode( ' AND ', $where ) . '
				 ORDER BY l.created_at DESC, l.id DESC
				 LIMIT 200',
				$params
			),
			ARRAY_A
		) ?: array();
	}

	public static function handle_ads_daily_import(): void {
		self::require_cap();
		check_admin_referer( 'ea_ads_daily_import' );
		$csv = isset( $_POST['ea_ads_daily_csv'] ) ? (string) wp_unslash( $_POST['ea_ads_daily_csv'] ) : '';
		$count = self::import_ads_daily_csv( $csv );
		$page = isset( $_POST['ea_return_page'] ) ? sanitize_key( wp_unslash( $_POST['ea_return_page'] ) ) : 'ea-ad-platforms';
		if ( ! in_array( $page, array( 'ea-ad-platforms', 'ea-campaign-journey' ), true ) ) {
			$page = 'ea-ad-platforms';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'ea_imported' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function import_ads_daily_csv( string $csv ): int {
		global $wpdb;
		$table = EA_Utils::table( 'ads_daily' );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		if ( empty( $lines ) ) {
			return 0;
		}
		$header = str_getcsv( array_shift( $lines ) );
		$header = array_map( array( __CLASS__, 'normalize_ads_csv_header' ), $header );
		$now = EA_Utils::now();
		$count = 0;
		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			$values = array_slice( str_getcsv( $line ), 0, count( $header ) );
			$row = array_combine( $header, array_pad( $values, count( $header ), '' ) );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = self::parse_ads_csv_date( self::csv_pick( $row, array( 'date', 'day', 'stat_date' ) ) );
			if ( $date === '' ) {
				continue;
			}
				$impressions = self::parse_ads_number( self::csv_pick( $row, array( 'impressions', 'impr' ) ) );
				$clicks = self::parse_ads_number( self::csv_pick( $row, array( 'clicks', 'link_clicks' ) ) );
				$spend = self::parse_ads_money( self::csv_pick( $row, array( 'spend', 'amount_spent', 'cost' ) ) );
				$data = array(
					'stat_date'     => $date,
					'channel'       => self::normalize_ads_platform( self::csv_pick( $row, array( 'platform', 'channel' ) ) ?: self::infer_ads_platform( $row ) ),
					'project_slug'  => sanitize_title( remove_accents( self::csv_pick( $row, array( 'project_slug', 'project' ) ) ) ),
					'campaign_id'   => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'campaign_id', 'campaignid' ) ), 190 ),
					'campaign_name' => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'campaign_name', 'campaign' ) ), 255 ),
					'adset_id'      => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'adset_id', 'ad_set_id', 'ad_group_id', 'adgroup_id' ) ), 190 ),
					'adset_name'    => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'adset_name', 'ad_set_name', 'ad_group_name', 'adgroup_name', 'ad_group' ) ), 255 ),
					'ad_id'         => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'ad_id', 'adid' ) ), 190 ),
					'ad_name'       => EA_Utils::sanitize_text( self::csv_pick( $row, array( 'ad_name', 'ad' ) ), 255 ),
					'impressions'   => $impressions,
				'reach'         => self::parse_ads_number( self::csv_pick( $row, array( 'reach' ) ) ),
				'clicks'        => $clicks,
				'spend'         => $spend,
				'ctr'           => self::parse_ads_rate( self::csv_pick( $row, array( 'ctr' ) ), $impressions > 0 ? ( $clicks / $impressions ) * 100 : 0 ),
				'cpc'           => self::parse_ads_money( self::csv_pick( $row, array( 'cpc' ) ) ) ?: ( $clicks > 0 ? $spend / $clicks : 0 ),
				'cpm'           => self::parse_ads_money( self::csv_pick( $row, array( 'cpm' ) ) ) ?: ( $impressions > 0 ? ( $spend / $impressions ) * 1000 : 0 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			);
			$wpdb->replace( $table, $data );
			$count++;
		}
		return $count;
	}

	private static function normalize_ads_csv_header( $header ): string {
		$key = strtolower( remove_accents( (string) $header ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( (string) $key, '_' );
		$key = sanitize_key( $key );
		$map = array(
			'amount_spent_clp' => 'amount_spent',
			'amount_spent_usd' => 'amount_spent',
			'cost_clp'         => 'cost',
			'impr'             => 'impr',
			'ad_set_id'        => 'ad_set_id',
			'ad_set_name'      => 'ad_set_name',
			'ad_group_id'      => 'ad_group_id',
			'ad_group_name'    => 'ad_group_name',
		);
		return $map[ $key ] ?? $key;
	}

	private static function csv_pick( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && trim( (string) $row[ $key ] ) !== '' ) {
				return trim( (string) $row[ $key ] );
			}
		}
		return '';
	}

	private static function infer_ads_platform( array $row ): string {
		if ( self::csv_pick( $row, array( 'ad_set_id', 'ad_set_name', 'adset_id', 'adset_name' ) ) !== '' ) {
			return 'meta';
		}
		if ( self::csv_pick( $row, array( 'ad_group_id', 'ad_group_name', 'adgroup_id', 'adgroup_name' ) ) !== '' ) {
			return 'google';
		}
		return '';
	}

	private static function normalize_ads_platform( string $platform ): string {
		$platform = sanitize_key( strtolower( trim( $platform ) ) );
		if ( in_array( $platform, array( 'facebook', 'instagram', 'meta_ads' ), true ) ) {
			return 'meta';
		}
		if ( in_array( $platform, array( 'google_ads', 'googleads', 'adwords' ), true ) ) {
			return 'google';
		}
		return $platform;
	}

	private static function parse_ads_csv_date( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		foreach ( array( 'd/m/Y', 'm/d/Y', 'Y/m/d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $value );
			if ( $date instanceof DateTimeImmutable ) {
				return $date->format( 'Y-m-d' );
			}
		}
		return '';
	}

	private static function parse_ads_number( string $value ): int {
		$value = preg_replace( '/[^\d]/', '', $value );
		return max( 0, (int) $value );
	}

	private static function parse_ads_money( string $value ): float {
		$value = trim( preg_replace( '/[^\d,.\-]/', '', $value ) );
		if ( $value === '' ) {
			return 0.0;
		}
		if ( strpos( $value, ',' ) !== false && strpos( $value, '.' ) !== false ) {
			$last_comma = strrpos( $value, ',' );
			$last_dot   = strrpos( $value, '.' );
			if ( $last_dot > $last_comma ) {
				$value = str_replace( ',', '', $value );
			} else {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			}
			return max( 0, (float) $value );
		}
		$value = str_replace( ',', '.', $value );
		return max( 0, (float) $value );
	}

	private static function parse_ads_rate( string $value, float $fallback = 0.0 ): float {
		$value = trim( str_replace( '%', '', $value ) );
		if ( $value === '' ) {
			return max( 0, $fallback );
		}
		return self::parse_ads_money( $value );
	}

	public static function get_chart_data( $filters ) {
		global $wpdb;
		$sessions    = EA_Utils::table( 'sessions' );
		$devices     = EA_Utils::table( 'devices' );
		$conversions = EA_Utils::table( 'conversions' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$visitors_trend = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(first_seen) AS day, COUNT(DISTINCT visitor_hash) AS visitors, COUNT(*) AS sessions
				FROM {$sessions}
				WHERE first_seen >= %s AND first_seen < %s
				GROUP BY DATE(first_seen)
				ORDER BY day ASC",
				$start,
				$end
			),
			ARRAY_A
		);

		$sources_chart = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT traffic_type AS label, COUNT(*) AS total
				FROM {$sessions}
				WHERE first_seen >= %s AND first_seen < %s
				GROUP BY traffic_type
				ORDER BY total DESC
				LIMIT 8",
				$start,
				$end
			),
			ARRAY_A
		);

		$devices_chart = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.device_type AS label, COUNT(*) AS total
				FROM {$devices} d
				INNER JOIN {$sessions} s ON s.session_id = d.session_id
				WHERE s.first_seen >= %s AND s.first_seen < %s AND d.device_type != ''
				GROUP BY d.device_type
				ORDER BY total DESC",
				$start,
				$end
			),
			ARRAY_A
		);

		$conv_trend = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(converted_at) AS day, COUNT(*) AS total
				FROM {$conversions}
				WHERE converted_at >= %s AND converted_at < %s
				GROUP BY DATE(converted_at)
				ORDER BY day ASC",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'visitorsTrend' => $visitors_trend ?: array(),
			'sources'       => $sources_chart ?: array(),
			'devices'       => $devices_chart ?: array(),
			'conversions'   => $conv_trend ?: array(),
		);
	}

	public static function get_insights( $filters, $metrics ) {
		$insights = array();
		$behavior = self::get_behavior( $filters );
		$conv     = self::get_conversions( $filters );
		$sample   = (int) ( $metrics['sessions']['30days'] ?? 0 );

		if ( ! empty( $metrics['top_sources'][0] ) ) {
			$row     = $metrics['top_sources'][0];
			$total   = (int) ( $row['total'] ?? 0 );
			$percent = $sample > 0 ? round( ( $total / $sample ) * 100 ) : 0;
			$insights[] = array(
				'kicker' => __( 'Canal principal', 'estandar-analytics' ),
				'value'  => sprintf(
					/* translators: 1: percentage, 2: traffic source */
					__( '%1$s%% del tráfico actual proviene de %2$s.', 'estandar-analytics' ),
					number_format_i18n( $percent ),
					(string) ( $row['label'] ?? '' )
				),
				'meta'   => sprintf(
					/* translators: %s: session count */
					__( '%s sesiones observadas', 'estandar-analytics' ),
					number_format_i18n( $total )
				),
			);
		}

		if ( (float) ( $metrics['conversion_rate'] ?? 0 ) <= 0 ) {
			$insights[] = array(
				'kicker' => __( 'Conversiones', 'estandar-analytics' ),
				'value'  => __( 'Aún no se registran conversiones definidas.', 'estandar-analytics' ),
				'meta'   => __( 'Revisa eventos de formularios, WhatsApp o páginas de gracias si corresponde.', 'estandar-analytics' ),
			);
		} elseif ( ! empty( $conv['by_landing'][0] ) ) {
			$row = $conv['by_landing'][0];
			$insights[] = array(
				'kicker' => __( 'Página con mayor conversión', 'estandar-analytics' ),
				'value'  => sprintf(
					/* translators: %s: page label */
					__( '%s concentra las conversiones detectadas.', 'estandar-analytics' ),
					EA_Admin_UI::clean_page_label( (string) ( $row['landing_page'] ?? '' ) )
				),
				'meta'   => sprintf(
					__( '%s conversiones', 'estandar-analytics' ),
					number_format_i18n( (int) ( $row['conversions'] ?? 0 ) )
				),
			);
		}

		if ( ! empty( $behavior['cta'][0] ) ) {
			$row = $behavior['cta'][0];
			$insights[] = array(
				'kicker' => __( 'CTA más usado', 'estandar-analytics' ),
				'value'  => sprintf(
					/* translators: %s: CTA label */
					__( '%s es la interacción comercial más frecuente.', 'estandar-analytics' ),
					(string) ( $row['label'] ?? '' )
				),
				'meta'   => sprintf(
					__( '%s interacciones', 'estandar-analytics' ),
					number_format_i18n( (int) ( $row['total'] ?? 0 ) )
				),
			);
		}

		if ( ! empty( $metrics['top_landing'][0] ) ) {
			$row = $metrics['top_landing'][0];
			$insights[] = array(
				'kicker' => __( 'Landing principal', 'estandar-analytics' ),
				'value'  => sprintf(
					/* translators: %s: page label */
					__( '%s concentra la mayor parte de las entradas actuales.', 'estandar-analytics' ),
					EA_Admin_UI::clean_page_label( (string) ( $row['label'] ?? '' ) )
				),
				'meta'   => __( 'Usa la URL completa pasando el cursor sobre la fila de detalle.', 'estandar-analytics' ),
			);
		}

		if ( self::campaign_count( $filters ) === 0 ) {
			$insights[] = array(
				'kicker' => __( 'Campañas', 'estandar-analytics' ),
				'value'  => __( 'No se detectan campañas activas con parámetros UTM.', 'estandar-analytics' ),
				'meta'   => __( 'El tráfico actual se atribuye por fuente/referrer disponible.', 'estandar-analytics' ),
			);
		}

		if ( $sample < EA_Admin_UI::MIN_SAMPLE ) {
			$insights[] = array(
				'kicker' => __( 'Confiabilidad', 'estandar-analytics' ),
				'value'  => __( 'Volumen insuficiente para detectar patrones confiables.', 'estandar-analytics' ),
				'meta'   => __( 'Los conteos son reales; las tendencias deben leerse con cautela.', 'estandar-analytics' ),
			);
		}

		if ( ! empty( $conv['by_source'] ) ) {
			$worst = null;
			foreach ( $conv['by_source'] as $row ) {
				$sessions = max( 1, (int) ( $row['sessions'] ?? 0 ) );
				$rate     = ( (int) ( $row['conversions'] ?? 0 ) ) / $sessions;
				if ( null === $worst || $rate < $worst['rate'] ) {
					$worst = array(
						'row'  => $row,
						'rate' => $rate,
					);
				}
			}
			if ( $worst && (int) ( $worst['row']['sessions'] ?? 0 ) >= EA_Admin_UI::MIN_SAMPLE ) {
				$insights[] = array(
					'kicker' => __( 'Fuente con menor conversión', 'estandar-analytics' ),
					'value'  => (string) ( $worst['row']['source'] ?? '' ),
					'meta'   => EA_Admin_UI::format_percent( $worst['rate'] * 100, (int) $worst['row']['sessions'] ),
				);
			}
		}

		return $insights;
	}

	private static function campaign_count( $filters ) {
		global $wpdb;
		$sessions = EA_Utils::table( 'sessions' );
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sessions} WHERE campaign != '' AND first_seen >= %s AND first_seen < %s",
				$start,
				$end
			)
		);
	}

	// -------------------------------------------------------------------------
	// Resultados comerciales — joins Analytics → Leads
	// -------------------------------------------------------------------------

	/**
	 * Verifica si la tabla es_leads existe (Estandar Leads activo).
	 *
	 * @return bool
	 */
	private static function leads_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'es_leads';
		// Usa una transient breve para no repetir SHOW TABLES en cada pageview admin.
		$cache_key = 'ea_leads_table_exists';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (bool) $cached;
		}
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		set_transient( $cache_key, $exists ? '1' : '0', 5 * MINUTE_IN_SECONDS );
		return $exists;
	}

	/**
	 * Reporte comercial: adquisición cruzada con resultado de leads.
	 *
	 * Dimensiones: fuente, campaña, keyword.
	 * Métricas: leads totales, calificados, reuniones, ventas.
	 *
	 * Join: ea_sessions.session_id ↔ es_leads.first_session_id
	 * No requiere nuevas tablas.
	 *
	 * @param array $filters
	 * @return array{
	 *   leads_active: bool,
	 *   by_source: array,
	 *   by_campaign: array,
	 *   by_keyword: array,
	 *   totals: array
	 * }
	 */

	public static function get_commercial_results( $filters ) {
		$empty = array(
			'leads_active' => false,
			'by_source'    => array(),
			'by_campaign'  => array(),
			'by_keyword'   => array(),
			'by_project'   => array(),
			'totals'       => array(
				'leads'       => 0,
				'atribuidos'  => 0,
				'calificados' => 0,
				'reuniones'   => 0,
				'ventas'      => 0,
			),
			'coverage'     => array(
				'total_leads'      => 0,
				'attributed_leads' => 0,
				'pct'              => 0,
			),
			'operations'   => array(
				'totals'    => array(
					'total'               => 0,
					'classified'          => 0,
					'unclassified'        => 0,
					'classification_rate' => 0,
					'staged'              => 0,
					'stage_adoption_rate' => 0,
				),
				'aging'     => array(
					'0_24'    => 0,
					'24_72'   => 0,
					'72_plus' => 0,
				),
				'by_source' => array(),
				'by_operator' => array(),
				'by_stage' => array(),
					'recontacts' => array(
						'total_leads' => 0,
						'recontacted_leads' => 0,
						'recontact_rate' => 0,
						'unclassified_recontacts' => 0,
						'without_action_total' => 0,
						'without_action_avg_submissions' => 0,
						'without_action_max_submissions' => 0,
						'without_action_top_project' => '',
						'without_action_top_operator' => '',
						'without_action_rows' => array(),
						'one_submission' => 0,
						'two_submissions' => 0,
						'three_plus_submissions' => 0,
						'by_project' => array(),
				),
				'unclassified_leads' => array(),
			),
		);

		if ( ! self::leads_table_exists() ) {
			return $empty;
		}

		global $wpdb;
		$_qual_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
		$_meet_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
		$sessions = EA_Utils::table( 'sessions' );
		$leads    = $wpdb->prefix . 'es_leads';

		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

			$base_where = $wpdb->prepare(
				"s.first_seen >= %s AND s.first_seen < %s",
				$start,
				$end
			);
			$project_filter = (string) ( $filters['project'] ?? '' );
			$lead_project_where = $project_filter !== '' ? $wpdb->prepare( ' AND l.project_slug = %s', $project_filter ) : '';

			// -- Cobertura de atribucion ------------------------------------------
			$total_leads = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$leads} l WHERE created_at >= %s AND created_at < %s{$lead_project_where}",
					$start,
					$end
				)
			);
			$attributed_leads = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT( DISTINCT l.id )
				FROM {$leads} l
				LEFT JOIN {$sessions} s ON s.session_id = l.first_session_id
				WHERE {$base_where}{$lead_project_where}"
			);
			$coverage_pct = $total_leads > 0 ? round( ( $attributed_leads / $total_leads ) * 100, 1 ) : 0;
			$operations   = self::get_commercial_operations_summary( $filters );

		// -- Fila "No atribuido" ----------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unattributed_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT( DISTINCT l.id )                                        AS leads,
					SUM( l.status IN ({$_qual_sql}) ) AS calificados,
					SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
					SUM( l.status IN ({$_resv_sql}) ) AS reservas,
					SUM( l.status IN ({$_sold_sql}) ) AS ventas
					FROM {$leads} l
					WHERE ( l.first_session_id = '' OR l.first_session_id IS NULL )
					  AND l.created_at >= %s AND l.created_at < %s{$lead_project_where}",
					$start,
					$end
				),
			ARRAY_A
		);
		$unattr = array(
			'dimension'   => '(no atribuido)',
			'leads'       => (int) ( $unattributed_row['leads']       ?? 0 ),
			'calificados' => (int) ( $unattributed_row['calificados'] ?? 0 ),
			'reuniones'   => (int) ( $unattributed_row['reuniones']   ?? 0 ),
			'reservas'    => (int) ( $unattributed_row['reservas']    ?? 0 ),
			'ventas'      => (int) ( $unattributed_row['ventas']      ?? 0 ),
		);

		// -- Por fuente -------------------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_source = (array) $wpdb->get_results(
			"SELECT
				COALESCE( NULLIF( s.source, '' ), '(directo)' )              AS dimension,
				COUNT( DISTINCT l.id )                                        AS leads,
				SUM( l.status IN ({$_qual_sql}) ) AS calificados,
				SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
				SUM( l.status IN ({$_resv_sql}) ) AS reservas,
				SUM( l.status IN ({$_sold_sql}) ) AS ventas
				FROM {$sessions} s
				INNER JOIN {$leads} l ON l.first_session_id = s.session_id
				WHERE {$base_where}{$lead_project_where}
				GROUP BY dimension
				ORDER BY ventas DESC, reuniones DESC, leads DESC
				LIMIT 30",
				ARRAY_A
			);
		if ( $unattr['leads'] > 0 ) {
			$by_source[] = $unattr;
		}

		// -- Por campana ------------------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_campaign = (array) $wpdb->get_results(
			"SELECT
				COALESCE( NULLIF( s.campaign, '' ), '(sin campana)' )         AS dimension,
				COUNT( DISTINCT l.id )                                         AS leads,
				SUM( l.status IN ({$_qual_sql}) ) AS calificados,
				SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
				SUM( l.status IN ({$_resv_sql}) ) AS reservas,
				SUM( l.status IN ({$_sold_sql}) ) AS ventas
				FROM {$sessions} s
				INNER JOIN {$leads} l ON l.first_session_id = s.session_id
				WHERE {$base_where}{$lead_project_where}
				GROUP BY dimension
				ORDER BY ventas DESC, reuniones DESC, leads DESC
				LIMIT 30",
				ARRAY_A
			);
		if ( $unattr['leads'] > 0 ) {
			$by_campaign[] = $unattr;
		}

		// -- Por keyword ------------------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_keyword = (array) $wpdb->get_results(
			"SELECT
				COALESCE( NULLIF( s.term, '' ), '(sin keyword)' )             AS dimension,
				COUNT( DISTINCT l.id )                                         AS leads,
				SUM( l.status IN ({$_qual_sql}) ) AS calificados,
				SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
				SUM( l.status IN ({$_resv_sql}) ) AS reservas,
				SUM( l.status IN ({$_sold_sql}) ) AS ventas
				FROM {$sessions} s
				INNER JOIN {$leads} l ON l.first_session_id = s.session_id
				WHERE {$base_where}{$lead_project_where}
				  AND s.term != ''
				GROUP BY dimension
				ORDER BY ventas DESC, reuniones DESC, leads DESC
				LIMIT 30",
				ARRAY_A
			);
		if ( $unattr['leads'] > 0 ) {
			$by_keyword[] = $unattr;
		}

		// -- Por proyecto -------------------------------------------------------
			// Dato histórico: el proyecto aquí es propiedad del lead.
		$by_project = (array) $wpdb->get_results(
			"SELECT
				COALESCE( NULLIF( l.project_slug, '' ), '(sin proyecto)' )    AS dimension,
				COUNT( DISTINCT l.id )                                          AS leads,
				SUM( l.status IN ({$_qual_sql}) ) AS calificados,
				SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
				SUM( l.status IN ({$_resv_sql}) ) AS reservas,
				SUM( l.status IN ({$_sold_sql}) ) AS ventas
				FROM {$leads} l
				LEFT JOIN {$sessions} s ON s.session_id = l.first_session_id
				WHERE {$base_where}{$lead_project_where}
				GROUP BY l.project_slug
				ORDER BY ventas DESC, reuniones DESC, leads DESC
				LIMIT 30",
			ARRAY_A
		) ?: array();

		// -- Totales globales (solo atribuidos) --------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals_row = $wpdb->get_row(
			"SELECT
				COUNT( DISTINCT l.id )                                        AS leads,
				SUM( l.status IN ({$_qual_sql}) ) AS calificados,
				SUM( l.status IN ({$_meet_sql}) ) AS reuniones,
				SUM( l.status IN ({$_resv_sql}) ) AS reservas,
				SUM( l.status IN ({$_sold_sql}) ) AS ventas
			FROM {$sessions} s
			INNER JOIN {$leads} l ON l.first_session_id = s.session_id
				WHERE {$base_where}{$lead_project_where}",
				ARRAY_A
			);

		return array(
			'leads_active' => true,
			'by_source'    => $by_source,
			'by_campaign'  => $by_campaign,
			'by_keyword'   => $by_keyword,
			'by_project'   => $by_project,
			'totals'       => array(
				'leads'       => (int) ( $totals_row['leads']       ?? 0 ),
				'atribuidos'  => $attributed_leads,
				'calificados' => (int) ( $totals_row['calificados'] ?? 0 ),
				'reuniones'   => (int) ( $totals_row['reuniones']   ?? 0 ),
				'reservas'    => (int) ( $totals_row['reservas']    ?? 0 ),
				'ventas'      => (int) ( $totals_row['ventas']      ?? 0 ),
			),
			'coverage'     => array(
				'total_leads'      => $total_leads,
				'attributed_leads' => $attributed_leads,
				'pct'              => $coverage_pct,
			),
			'operations'   => $operations,
		);
	}

	private static function get_commercial_operations_summary( array $filters = array() ): array {
		global $wpdb;

		$leads = $wpdb->prefix . 'es_leads';
		list( $start, $end ) = self::dt_bounds( EA_Utils::date_offset( EA_Utils::today(), -29 ), EA_Utils::today() );
		$classified_sql = "(feedback_completed = 1 OR quality != '' OR last_classified_at IS NOT NULL)";
		$project_filter = (string) ( $filters['project'] ?? '' );
		$project_where = $project_filter !== '' ? $wpdb->prepare( ' AND project_slug = %s', $project_filter ) : '';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM({$classified_sql}) AS classified,
					SUM(NOT {$classified_sql}) AS unclassified
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();

		$total      = (int) ( $row['total'] ?? 0 );
		$classified = (int) ( $row['classified'] ?? 0 );
		$staged     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				  AND sales_stage != ''",
				$start,
				$end
			)
		);

		$unclassified_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT created_at
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				  AND NOT {$classified_sql}",
				$start,
				$end
			)
		) ?: array();
		$aging = array( '0_24' => 0, '24_72' => 0, '72_plus' => 0 );
		$now   = time();
		foreach ( $unclassified_rows as $created_at ) {
			$hours = max( 0, floor( ( $now - strtotime( (string) $created_at ) ) / HOUR_IN_SECONDS ) );
			if ( $hours < 24 ) {
				$aging['0_24']++;
			} elseif ( $hours < 72 ) {
				$aging['24_72']++;
			} else {
				$aging['72_plus']++;
			}
		}

		$by_source = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(first_utm_source, ''), 'direct') AS source,
					COUNT(*) AS leads,
					SUM({$classified_sql}) AS classified,
					SUM(NOT {$classified_sql}) AS unclassified
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				GROUP BY source
				ORDER BY unclassified DESC, leads DESC
				LIMIT 30",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();

		$by_operator = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(assigned_operator_name, ''), 'Sin asignar') AS operator,
					COUNT(*) AS leads,
					SUM({$classified_sql}) AS classified,
					SUM(NOT {$classified_sql}) AS unclassified
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				GROUP BY operator
				ORDER BY unclassified DESC, leads DESC
				LIMIT 30",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();

		$by_stage = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(sales_stage, ''), 'SIN_ETAPA') AS stage,
					COUNT(*) AS leads
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				GROUP BY stage
				ORDER BY leads DESC",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();

		$unclassified_leads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					name,
					project_slug,
					COALESCE(NULLIF(first_utm_source, ''), 'direct') AS source,
					first_source_plugin,
					assigned_operator_name,
					created_at,
					status
				FROM {$leads}
				WHERE created_at >= %s AND created_at < %s{$project_where}
				  AND NOT {$classified_sql}
				ORDER BY created_at ASC
				LIMIT 100",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();
		foreach ( $unclassified_leads as &$lead_row ) {
			$hours = max( 0, floor( ( $now - strtotime( (string) ( $lead_row['created_at'] ?? '' ) ) ) / HOUR_IN_SECONDS ) );
			$lead_row['age_hours'] = $hours;
			$lead_row['age_label'] = $hours >= 24
				? floor( $hours / 24 ) . 'd ' . ( $hours % 24 ) . 'h'
				: $hours . 'h';
			$lead_row['capture_channel'] = self::capture_channel_label( (string) ( $lead_row['first_source_plugin'] ?? '' ) );
		}
		unset( $lead_row );

			$recontacts = self::get_recontact_summary( $start, $end, $classified_sql, $project_filter );

			return array(
			'totals'    => array(
				'total'               => $total,
				'classified'          => $classified,
				'unclassified'        => (int) ( $row['unclassified'] ?? 0 ),
				'classification_rate' => $total > 0 ? round( ( $classified / $total ) * 100, 1 ) : 0,
				'staged'              => $staged,
				'stage_adoption_rate' => $total > 0 ? round( ( $staged / $total ) * 100, 1 ) : 0,
			),
			'aging'     => array(
				'0_24'    => (int) ( $aging['0_24'] ?? 0 ),
				'24_72'   => (int) ( $aging['24_72'] ?? 0 ),
				'72_plus' => (int) ( $aging['72_plus'] ?? 0 ),
			),
			'by_source' => $by_source,
			'by_operator' => $by_operator,
			'by_stage' => $by_stage,
			'recontacts' => $recontacts,
			'unclassified_leads' => $unclassified_leads,
		);
	}

	private static function get_recontact_summary( string $start, string $end, string $classified_sql, string $project_filter = '' ): array {
		global $wpdb;

		$leads = $wpdb->prefix . 'es_leads';
		$submissions = $wpdb->prefix . 'es_lead_submissions';
		$project_where = $project_filter !== '' ? $wpdb->prepare( ' WHERE l.project_slug = %s', $project_filter ) : '';

		$active_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					l.id,
					l.project_slug,
					{$classified_sql} AS classified,
					COUNT(DISTINCT s_all.id) AS submission_count
				FROM {$leads} l
				INNER JOIN {$submissions} s_window ON s_window.lead_id = l.id
					AND s_window.submitted_at >= %s AND s_window.submitted_at < %s
				LEFT JOIN {$submissions} s_all ON s_all.lead_id = l.id
				{$project_where}
				GROUP BY l.id, l.project_slug, classified",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();

		$total = count( $active_rows );
		$recontacted = 0;
		$unclassified = 0;
		$one = 0;
		$two = 0;
		$three_plus = 0;
		$projects = array();

		foreach ( $active_rows as $row ) {
			$count = (int) ( $row['submission_count'] ?? 0 );
			if ( $count <= 1 ) {
				$one++;
				continue;
			}
			if ( $count === 2 ) {
				$two++;
			} else {
				$three_plus++;
			}
			$recontacted++;
			if ( empty( $row['classified'] ) ) {
				$unclassified++;
			}
			$project = (string) ( $row['project_slug'] ?: '(sin proyecto)' );
			if ( ! isset( $projects[ $project ] ) ) {
				$projects[ $project ] = 0;
			}
			$projects[ $project ]++;
		}

			arsort( $projects, SORT_NUMERIC );
		$by_project = array();
		foreach ( array_slice( $projects, 0, 10, true ) as $project => $count ) {
			$by_project[] = array(
				'project' => $project,
				'recontacts' => (int) $count,
			);
		}

			$without_action = self::get_recontact_without_action_summary( $start, $end, $project_filter );

			return array(
				'total_leads' => $total,
				'recontacted_leads' => $recontacted,
				'recontact_rate' => $total > 0 ? round( ( $recontacted / $total ) * 100, 1 ) : 0,
				'unclassified_recontacts' => $unclassified,
				'without_action_total' => (int) ( $without_action['total'] ?? 0 ),
				'without_action_avg_submissions' => (float) ( $without_action['avg_submissions'] ?? 0 ),
				'without_action_max_submissions' => (int) ( $without_action['max_submissions'] ?? 0 ),
				'without_action_top_project' => (string) ( $without_action['top_project'] ?? '' ),
				'without_action_top_operator' => (string) ( $without_action['top_operator'] ?? '' ),
				'without_action_rows' => $without_action['rows'] ?? array(),
				'one_submission' => $one,
				'two_submissions' => $two,
				'three_plus_submissions' => $three_plus,
				'by_project' => $by_project,
			);
		}

		private static function lead_terminal_statuses(): array {
			if ( class_exists( 'Estandar_Leads_Compliance' ) && defined( 'Estandar_Leads_Compliance::FEEDBACK_TERMINAL_STATUSES' ) ) {
				return Estandar_Leads_Compliance::FEEDBACK_TERMINAL_STATUSES;
			}

			if ( defined( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1' ) && ESTANDAR_ANALYTICS_NORMALIZATION_V1 ) {
				$stages = Estandar_Analytics_Commercial_Stage_Map::map();
				$all    = array();
				foreach ( $stages as $stage => $statuses ) {
					if ( in_array( $stage, array( 'lead' ), true ) ) {
						continue;
					}
					$all = array_merge( $all, $statuses );
				}
				return array_values( array_unique( $all ) );
			}

			return array(
				'NO_RESPONSE',
				'UNREACHABLE',
				'QUALIFIED',
				'MEETING_BOOKED',
				'PROPOSAL_SENT',
				'NEGOTIATION',
				'CONVERTED',
				'LOST',
				'INVALID',
				'VISIT_SCHEDULED',
				'VISITED',
				'BANK_EVALUATION',
				'RESERVED',
				'DEED_SIGNED',
				'DELIVERED',
			);
		}

	private static function get_recontact_without_action_summary( string $start, string $end, string $project_filter = '' ): array {
			global $wpdb;

			$leads = $wpdb->prefix . 'es_leads';
			$submissions = $wpdb->prefix . 'es_lead_submissions';
			$terminal_statuses = self::lead_terminal_statuses();
			$terminal_placeholders = implode( ',', array_fill( 0, count( $terminal_statuses ), '%s' ) );
			$project_where = $project_filter !== '' ? $wpdb->prepare( ' AND l.project_slug = %s', $project_filter ) : '';

			$sql = "SELECT
					l.id,
					l.name,
					l.project_slug,
					COALESCE(NULLIF(l.assigned_operator_name, ''), 'Sin asignar') AS operator_name,
					l.status,
					l.feedback_status,
					COUNT(DISTINCT s_all.id) AS submission_count,
					MIN(s_all.submitted_at) AS first_submitted_at,
					MAX(s_all.submitted_at) AS last_submitted_at
				FROM {$leads} l
				INNER JOIN {$submissions} s_window ON s_window.lead_id = l.id
					AND s_window.submitted_at >= %s AND s_window.submitted_at < %s
				LEFT JOIN {$submissions} s_all ON s_all.lead_id = l.id
				WHERE COALESCE(l.feedback_status, '') != 'received'
				  AND l.status NOT IN ({$terminal_placeholders})
				  {$project_where}
				GROUP BY l.id, l.name, l.project_slug, operator_name, l.status, l.feedback_status
				HAVING submission_count >= 2
				ORDER BY submission_count DESC, last_submitted_at DESC
				LIMIT 100";

			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, array_merge( array( $start, $end ), $terminal_statuses ) ),
				ARRAY_A
			) ?: array();

			$total = count( $rows );
			$sum = 0;
			$max = 0;
			$projects = array();
			$operators = array();
			$now = time();

			foreach ( $rows as &$row ) {
				$count = (int) ( $row['submission_count'] ?? 0 );
				$sum += $count;
				$max = max( $max, $count );

				$project = (string) ( $row['project_slug'] ?: '(sin proyecto)' );
				$operator = (string) ( $row['operator_name'] ?: 'Sin asignar' );
				$projects[ $project ] = ( $projects[ $project ] ?? 0 ) + 1;
				$operators[ $operator ] = ( $operators[ $operator ] ?? 0 ) + 1;

				$last = strtotime( (string) ( $row['last_submitted_at'] ?? '' ) );
				$hours = $last ? max( 0, floor( ( $now - $last ) / HOUR_IN_SECONDS ) ) : 0;
				$row['hours_since_last'] = $hours;
				$row['hours_since_last_label'] = $hours >= 24 ? floor( $hours / 24 ) . 'd ' . ( $hours % 24 ) . 'h' : $hours . 'h';
			}
			unset( $row );

			arsort( $projects, SORT_NUMERIC );
			arsort( $operators, SORT_NUMERIC );

			return array(
				'total' => $total,
				'avg_submissions' => $total > 0 ? round( $sum / $total, 1 ) : 0,
				'max_submissions' => $max,
				'top_project' => (string) array_key_first( $projects ),
				'top_operator' => (string) array_key_first( $operators ),
				'rows' => $rows,
			);
		}

	private static function capture_channel_label( string $source_plugin ): string {
		$source_plugin = sanitize_key( $source_plugin );
		if ( strpos( $source_plugin, 'forms' ) !== false ) {
			return 'Formulario';
		}
		if ( strpos( $source_plugin, 'chatbot' ) !== false ) {
			return 'Chatbot';
		}
		if ( strpos( $source_plugin, 'whatsapp' ) !== false ) {
			return 'WhatsApp';
		}
		if ( strpos( $source_plugin, 'import' ) !== false ) {
			return 'Importado';
		}
		if ( $source_plugin === '' || strpos( $source_plugin, 'manual' ) !== false || $source_plugin === 'estandar-leads' ) {
			return 'Manual';
		}
		return 'Otro';
	}

	// -------------------------------------------------------------------------
	// Salud del sistema — calidad de datos operativos
	// -------------------------------------------------------------------------

	/**
	 * Métricas de calidad que determinan la confiabilidad de todos los reportes.
	 * Si estos números son malos, el funnel y los reportes de resultado son ruido.
	 *
	 * @return array
	 */
	public static function get_system_health() {
		global $wpdb;
		$sessions_t = EA_Utils::table( 'sessions' );
		$seven_ago  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$thirty_ago = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		$health = array();

		// 1. Cobertura de atribución (últimos 30 días)
		if ( self::leads_table_exists() ) {
			$leads_t       = $wpdb->prefix . 'es_leads';
			$total_leads   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t} WHERE created_at >= %s", $thirty_ago
			) );
			$attr_leads    = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t} l
				 LEFT JOIN {$sessions_t} s ON s.session_id = l.first_session_id
				 WHERE l.created_at >= %s", $thirty_ago
			) );
			$coverage_pct  = $total_leads > 0 ? round( $attr_leads / $total_leads * 100, 1 ) : 0;

			$health['coverage'] = array(
				'label'  => __( 'Cobertura de atribución', 'estandar-analytics' ),
				'value'  => number_format_i18n( $coverage_pct, 1 ) . ' %',
				'raw'    => $coverage_pct,
				'status' => $coverage_pct >= 80 ? 'green' : ( $coverage_pct >= 50 ? 'yellow' : 'red' ),
				'detail' => sprintf( '%d / %d leads', $attr_leads, $total_leads ),
			);

			// 2a. Leads sin operador <24h (acaba de entrar, urgente pero recuperable)
			$h24_ago     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$no_op_fresh = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE ( assigned_operator_id IS NULL OR assigned_operator_id = 0 )
				   AND status = 'NEW' AND created_at >= %s", $h24_ago
			) );
			$health['no_operator_fresh'] = array(
				'label'  => __( 'Sin operador <24h', 'estandar-analytics' ),
				'value'  => number_format_i18n( $no_op_fresh ),
				'raw'    => $no_op_fresh,
				'status' => $no_op_fresh === 0 ? 'green' : 'yellow',
				'detail' => __( 'Asignar hoy', 'estandar-analytics' ),
			);

			// 2b. Sin operador >24h <72h (riesgo real de perder el lead)
			$h72_ago      = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
			$no_op_aging  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE ( assigned_operator_id IS NULL OR assigned_operator_id = 0 )
				   AND status = 'NEW' AND created_at >= %s AND created_at < %s",
				$h72_ago, $h24_ago
			) );
			$health['no_operator_aging'] = array(
				'label'  => __( 'Sin operador >24h', 'estandar-analytics' ),
				'value'  => number_format_i18n( $no_op_aging ),
				'raw'    => $no_op_aging,
				'status' => $no_op_aging === 0 ? 'green' : 'red',
				'detail' => __( 'Riesgo alto', 'estandar-analytics' ),
			);

			// 2c. Sin operador >72h (lead probablemente frío)
			$no_op_cold = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE ( assigned_operator_id IS NULL OR assigned_operator_id = 0 )
				   AND status = 'NEW' AND created_at < %s", $h72_ago
			) );
			$health['no_operator_cold'] = array(
				'label'  => __( 'Sin operador >72h', 'estandar-analytics' ),
				'value'  => number_format_i18n( $no_op_cold ),
				'raw'    => $no_op_cold,
				'status' => $no_op_cold === 0 ? 'green' : 'red',
				'detail' => __( 'Probablemente frío', 'estandar-analytics' ),
			);

			// 3. Leads NEW sin mover en +7 días
			$stale = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE status = 'NEW' AND last_seen_at < %s", $seven_ago
			) );
			$health['stale'] = array(
				'label'  => __( 'Leads +7 días sin clasificar', 'estandar-analytics' ),
				'value'  => number_format_i18n( $stale ),
				'raw'    => $stale,
				'status' => $stale === 0 ? 'green' : ( $stale <= 3 ? 'yellow' : 'red' ),
				'detail' => '',
			);

			// 4. Leads sin fuente (first_session_id vacío)
			$no_source = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE ( first_session_id = '' OR first_session_id IS NULL )
				   AND created_at >= %s", $thirty_ago
			) );
			$health['no_source'] = array(
				'label'  => __( 'Leads sin fuente atribuida', 'estandar-analytics' ),
				'value'  => number_format_i18n( $no_source ),
				'raw'    => $no_source,
				'status' => $no_source === 0 ? 'green' : ( $no_source <= 10 ? 'yellow' : 'red' ),
				'detail' => __( 'últimos 30 días', 'estandar-analytics' ),
			);

			// 5. Ventas sin atribución (estado comercial de venta real sin session_id)
			$_sold_statuses = defined( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1' ) && ESTANDAR_ANALYTICS_NORMALIZATION_V1
				? Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' )
				: "'DEED_SIGNED','DELIVERED'";
			$conv_no_attr = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$leads_t}
				 WHERE status IN ({$_sold_statuses})
				   AND ( first_session_id = '' OR first_session_id IS NULL )"
			);
			$health['converted_no_attr'] = array(
				'label'  => __( 'Ventas sin atribución', 'estandar-analytics' ),
				'value'  => number_format_i18n( $conv_no_attr ),
				'raw'    => $conv_no_attr,
				'status' => $conv_no_attr === 0 ? 'green' : ( $conv_no_attr <= 2 ? 'yellow' : 'red' ),
				'detail' => __( 'DEED_SIGNED/DELIVERED sin ea_sid', 'estandar-analytics' ),
			);
		}

		// 6. Tracking activo (sesiones en las últimas 24h)
		$recent_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$sessions_t} WHERE first_seen >= %s",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
			$health['tracking_active'] = array(
				'label'  => __( 'Sesiones últimas 24h', 'estandar-analytics' ),
				'value'  => number_format_i18n( $recent_sessions ),
				'raw'    => $recent_sessions,
				'status' => $recent_sessions > 0 ? 'green' : 'red',
				'detail' => $recent_sessions === 0 ? __( 'Tracker posiblemente inactivo', 'estandar-analytics' ) : '',
			);

			$missing_landing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$sessions_t}
				 WHERE first_seen >= %s
				   AND (landing_page = '' OR landing_page IS NULL)",
				$thirty_ago
			) );
			$health['missing_landing'] = array(
				'label'  => __( 'Sesiones sin landing', 'estandar-analytics' ),
				'value'  => number_format_i18n( $missing_landing ),
				'raw'    => $missing_landing,
				'status' => $missing_landing === 0 ? 'green' : 'yellow',
				'detail' => __( 'Landing/Landing Path faltante', 'estandar-analytics' ),
			);

			return $health;
		}

	// -------------------------------------------------------------------------
	// Catálogo oficial de eventos de alta intención comercial.
	// Pageview, scroll_depth, chatbot_open, chatbot_message quedan fuera —
	// son señales de comportamiento pasivo, no de intención de compra.
	// -------------------------------------------------------------------------

	public static function high_intent_events() {
		return array(
			'chatbot_whatsapp_click',
			'whatsapp_click',
			'WhatsAppClick',
			'form_submit',
			'chatbot_lead',
			'chatbot_lead_created',
			'delivery_locator_confirm',
			'delivery_locator_whatsapp',
		);
	}

	// -------------------------------------------------------------------------
	// Proyecto → Resultado simplificado para el funnel ejecutivo
	// -------------------------------------------------------------------------

	public static function get_project_breakdown_for_funnel( $filters ): array {
		global $wpdb;
		if ( ! self::leads_table_exists() ) {
			return array();
		}
		$sessions_t = EA_Utils::table( 'sessions' );
		$leads_t    = $wpdb->prefix . 'es_leads';
		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE( NULLIF( l.project_slug, '' ), '(sin proyecto)' ) AS project,
					COUNT( DISTINCT l.id )                                      AS leads,
					SUM( l.status IN ({$_resv_sql}) )                                 AS reservas,
					SUM( l.status IN ({$_sold_sql}) )               AS ventas
				FROM {$leads_t} l
				LEFT JOIN {$sessions_t} s ON s.session_id = l.first_session_id
				WHERE s.first_seen >= %s AND s.first_seen < %s
				GROUP BY l.project_slug
				ORDER BY ventas DESC, reservas DESC, leads DESC
				LIMIT 10",
				$start, $end
			),
			ARRAY_A
		) ?: array();
	}

	// -------------------------------------------------------------------------
	// Diagnóstico automático del funnel (reglas determinísticas, sin IA).
	// Devuelve máximo 3 hallazgos con nivel y mensaje accionable.
	// -------------------------------------------------------------------------

	/**
	 * @param array $stages  Resultado de get_funnel()['stages'].
	 * @return array  Máx. 3 elementos: [ 'level' => 'warning'|'danger'|'info', 'message' => string ]
	 */
	public static function get_funnel_diagnosis( array $stages ): array {
		$idx = array();
		foreach ( $stages as $i => $s ) {
			$idx[ $s['label'] ] = array_merge( $s, array( 'i' => $i ) );
		}

		$impressions = (int) ( $idx[ __( 'Impresiones',         'estandar-analytics' ) ]['count'] ?? 0 );
		$clicks_ads  = (int) ( $idx[ __( 'Clicks',              'estandar-analytics' ) ]['count'] ?? 0 );
		$sessions    = (int) ( $idx[ __( 'Sesiones',            'estandar-analytics' ) ]['count'] ?? 0 );
		$intent      = (int) ( $idx[ __( 'Intención comercial', 'estandar-analytics' ) ]['count'] ?? 0 );
		$leads       = (int) ( $idx[ __( 'Leads',               'estandar-analytics' ) ]['count'] ?? 0 );
		$assigned    = (int) ( $idx[ __( 'Asignados',           'estandar-analytics' ) ]['count'] ?? 0 );
		$qualified   = (int) ( $idx[ __( 'Calificados',         'estandar-analytics' ) ]['count'] ?? 0 );
		$meetings    = (int) ( $idx[ __( 'Reuniones',           'estandar-analytics' ) ]['count'] ?? 0 );
		$sales       = (int) ( $idx[ __( 'Ventas',              'estandar-analytics' ) ]['count'] ?? 0 );

		$findings = array();

		// Sin datos suficientes
		if ( $sessions === 0 && $impressions === 0 ) {
			return array( array( 'level' => 'info', 'message' => __( 'Sin sesiones ni datos de ads en el período. Verifica que el tracker está activo y que hay datos en Ad Platforms.', 'estandar-analytics' ) ) );
		}
		if ( $sessions === 0 && $impressions > 0 ) {
			return array( array( 'level' => 'info', 'message' => __( 'Sin sesiones en el período pero hay datos de ads. Verifica que el tracker está activo en el sitio.', 'estandar-analytics' ) ) );
		}

		// Ads → Site drop: clicks altos pero pocas sesiones = pérdida en landing
		if ( $clicks_ads > 0 && $sessions > 0 && ( $sessions / $clicks_ads ) < 0.5 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'Menos de la mitad de los clicks de ads generan sesión. Posible pérdida en landing, redirect o tracker.', 'estandar-analytics' ) );
		}

		// CTR bajo en ads
		if ( $impressions >= 1000 && $clicks_ads > 0 && ( $clicks_ads / $impressions ) < 0.005 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'CTR de ads menor a 0.5 %. Revisar creatividades y segmentación.', 'estandar-analytics' ) );
		}

		// Tráfico sin intención comercial
		if ( $sessions > 0 && $intent === 0 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'El tráfico llega pero ningún visitante muestra intención comercial. Revisa CTAs y canales de captación.', 'estandar-analytics' ) );
		} elseif ( $sessions >= 10 && $intent > 0 && ( $intent / $sessions ) < 0.05 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'El tráfico llega pero pocos visitantes muestran intención comercial. Menos del 5 % interactúa con CTAs.', 'estandar-analytics' ) );
		}

		// Leads no asignados (cuello operativo)
		if ( $leads > 0 && $assigned === 0 ) {
			$findings[] = array( 'level' => 'danger', 'message' => __( 'El cuello de botella está en operación. Existen leads capturados que aún no han sido asignados a un operador.', 'estandar-analytics' ) );
		} elseif ( $leads > 0 && $assigned > 0 && $qualified === 0 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'Los operadores reciben leads pero no los califican. Verificar seguimiento post-asignación.', 'estandar-analytics' ) );
		}

		// Reuniones sin cierre
		if ( $meetings > 0 && $sales === 0 ) {
			$findings[] = array( 'level' => 'warning', 'message' => __( 'Las reuniones no están convirtiendo en ventas. Revisar propuesta comercial y proceso de cierre.', 'estandar-analytics' ) );
		}

		// Cuello de botella entre intención y lead
		if ( $intent > 0 && $leads === 0 ) {
			$findings[] = array( 'level' => 'danger', 'message' => __( 'Hay intención comercial pero ningún lead capturado. Revisar formularios y canales de captura.', 'estandar-analytics' ) );
		}

		// Sin hallazgos negativos = positivo
		if ( empty( $findings ) && $sales > 0 ) {
			$findings[] = array( 'level' => 'info', 'message' => __( 'El funnel está funcionando. Continuar optimizando la tasa de cierre.', 'estandar-analytics' ) );
		} elseif ( empty( $findings ) ) {
			$findings[] = array( 'level' => 'info', 'message' => __( 'Datos insuficientes para un diagnóstico preciso. Se necesitan más sesiones en el período.', 'estandar-analytics' ) );
		}

		return array_slice( $findings, 0, 3 );
	}

	// -------------------------------------------------------------------------
	// Funnel comercial
	// Sesiones → Intención → Lead → Qualified → Meeting → Converted
	// -------------------------------------------------------------------------

	/**
	 * @param array $filters  source, campaign, from, to.
	 * @return array  Etapas del funnel con count y pct respecto a la etapa anterior.
	 */
	public static function get_funnel( $filters ) {
		global $wpdb;
		$sessions_t = EA_Utils::table( 'sessions' );
		$events_t   = EA_Utils::table( 'events' );

		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );

		// Construir WHERE adicional para filtros de fuente/campaña.
		$extra_where  = '';
		$extra_params = array();
		if ( $filters['source'] !== '' ) {
			$extra_where   .= ' AND s.source = %s';
			$extra_params[] = $filters['source'];
		}
		if ( $filters['campaign'] !== '' ) {
			$extra_where   .= ' AND s.campaign = %s';
			$extra_params[] = $filters['campaign'];
		}

		// Filtro por operador y proyecto: aplican solo a etapas Lead→Converted.
		$operator_filter   = sanitize_text_field( $_GET['ea_operator'] ?? '' );
		$operator_where    = '';
		$operator_params   = array();
		if ( $operator_filter !== '' && self::leads_table_exists() ) {
			$operator_where    = ' AND l.assigned_operator_name = %s';
			$operator_params[] = $operator_filter;
		}

		$project_filter  = $filters['project'] ?? '';
		$project_where   = '';
		$project_params  = array();
		if ( $project_filter !== '' && self::leads_table_exists() ) {
			$project_where    = ' AND l.project_slug = %s';
			$project_params[] = $project_filter;
		}
		$prelead_project_where  = $project_filter !== '' ? ' AND s.first_project_slug = %s' : '';
		$prelead_project_params = $project_filter !== '' ? array( $project_filter ) : array();

		$base_params         = array_merge( array( $start, $end ), $extra_params );
		$prelead_base_params = array_merge( $base_params, $prelead_project_params );

		$lead_extra_where  = $operator_where . $project_where;
		$lead_extra_params = array_merge( $operator_params, $project_params );

		// 0. Ads layer (ea_ads_daily) — impressions, clicks, spend.
		$ads_t = EA_Utils::table( 'ads_daily' );
		$count_impressions = 0;
		$count_clicks_ads  = 0;
		$total_spend       = 0.0;
		if ( self::table_exists( $ads_t ) ) {
			list( $ads_from, $ads_to ) = EA_Utils::parse_date_range( $filters['from'], $filters['to'] );
			$ads_where  = array( 'stat_date >= %s', 'stat_date <= %s' );
			$ads_params = array( $ads_from, $ads_to );
			if ( $project_filter !== '' ) {
				$ads_where[]  = 'project_slug = %s';
				$ads_params[] = $project_filter;
			}
			if ( $filters['source'] !== '' ) {
				$ads_where[]  = 'channel = %s';
				$ads_params[] = $filters['source'];
			}
			if ( $filters['campaign'] !== '' ) {
				$ads_where[]  = '(campaign_name = %s OR campaign_id = %s)';
				$ads_params[] = $filters['campaign'];
				$ads_params[] = $filters['campaign'];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ads_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(spend) AS spend
					 FROM {$ads_t}
					 WHERE " . implode( ' AND ', $ads_where ),
					$ads_params
				),
				ARRAY_A
			);
			$count_impressions = (int) ( $ads_row['impressions'] ?? 0 );
			$count_clicks_ads  = (int) ( $ads_row['clicks'] ?? 0 );
			$total_spend       = (float) ( $ads_row['spend'] ?? 0 );
		}

		// 1. Sesiones totales en el período.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sessions = (int) $wpdb->get_var(
			$wpdb->prepare(
					"SELECT COUNT(*) FROM {$sessions_t} s
					 WHERE s.first_seen >= %s AND s.first_seen < %s{$extra_where}{$prelead_project_where}",
					$prelead_base_params
				)
			);

		// 2. Sesiones únicas con al menos un evento de alta intención.
		$intent_events  = self::high_intent_events();
		$placeholders   = implode( ', ', array_fill( 0, count( $intent_events ), '%s' ) );
			$intent_params  = array_merge( $prelead_base_params, $intent_events );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_intent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT e.session_id )
					 FROM {$events_t} e
					 INNER JOIN {$sessions_t} s ON s.session_id = e.session_id
					 WHERE s.first_seen >= %s AND s.first_seen < %s{$extra_where}{$prelead_project_where}
					   AND e.event_type IN ( {$placeholders} )",
				$intent_params
			)
		);

		// 3-6. Leads y estados — solo si Leads está activo.
			$count_leads = $count_qualified = $count_meeting = $count_reservations = $count_converted = 0;
		if ( self::leads_table_exists() ) {
			$leads_t     = $wpdb->prefix . 'es_leads';
			$_qual_sql   = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'qualified' );
			$_meet_sql   = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
			$_resv_sql   = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
			$_sold_sql   = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$leads_rows  = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT( DISTINCT l.id )                                   AS leads,
						SUM( l.assigned_operator_id IS NOT NULL
						     AND l.assigned_operator_id > 0 )                    AS assigned,
						SUM( l.status IN ({$_qual_sql}) )                        AS qualified,
						SUM( l.status IN ({$_meet_sql}) )                        AS meeting,
						SUM( l.status IN ({$_resv_sql}) )                        AS reservations,
						SUM( l.status IN ({$_sold_sql}) )                        AS converted
					FROM {$leads_t} l
					LEFT JOIN {$sessions_t} s ON s.session_id = l.first_session_id
					    AND s.first_seen >= %s AND s.first_seen < %s{$extra_where}
					WHERE 1=1{$lead_extra_where}",
					array_merge( $base_params, $lead_extra_params )
				),
				ARRAY_A
			);
			$count_leads     = (int) ( $leads_rows['leads']    ?? 0 );
			$count_assigned  = (int) ( $leads_rows['assigned'] ?? 0 );
			$count_qualified = (int) ( $leads_rows['qualified'] ?? 0 );
			$count_meeting   = (int) ( $leads_rows['meeting']  ?? 0 );
			$count_reservations = (int) ( $leads_rows['reservations'] ?? 0 );
			$count_converted = (int) ( $leads_rows['converted'] ?? 0 );
		}

		// Construir etapas con conversión respecto a la etapa anterior.
		// "Asignados" entre Leads y Calificados revela si el cuello es marketing u operación.
		$ads_stages = array();
		if ( $count_impressions > 0 || $count_clicks_ads > 0 ) {
			$ads_stages = array(
				array( 'label' => __( 'Impresiones', 'estandar-analytics' ), 'count' => $count_impressions, 'tier' => 'ads' ),
				array( 'label' => __( 'Clicks',      'estandar-analytics' ), 'count' => $count_clicks_ads,  'tier' => 'ads' ),
			);
		}

		$stages_raw = array_merge( $ads_stages, array(
			array( 'label' => __( 'Sesiones',           'estandar-analytics' ), 'count' => $count_sessions,     'tier' => 'site' ),
			array( 'label' => __( 'Intención comercial', 'estandar-analytics' ), 'count' => $count_intent,      'tier' => 'site' ),
			array( 'label' => __( 'Leads',              'estandar-analytics' ), 'count' => $count_leads,        'tier' => 'lead' ),
			array( 'label' => __( 'Asignados',          'estandar-analytics' ), 'count' => $count_assigned,     'tier' => 'lead' ),
			array( 'label' => __( 'Calificados',        'estandar-analytics' ), 'count' => $count_qualified,    'tier' => 'lead' ),
			array( 'label' => __( 'Reuniones',          'estandar-analytics' ), 'count' => $count_meeting,      'tier' => 'lead' ),
			array( 'label' => __( 'Reservas',           'estandar-analytics' ), 'count' => $count_reservations, 'tier' => 'lead' ),
			array( 'label' => __( 'Ventas',             'estandar-analytics' ), 'count' => $count_converted,    'tier' => 'lead' ),
		) );

		$stages = array();
		$prev   = null;
		foreach ( $stages_raw as $stage ) {
			$pct = ( $prev !== null && $prev > 0 )
				? round( ( $stage['count'] / $prev ) * 100, 1 )
				: ( $stage['count'] > 0 ? 100.0 : 0.0 );
			$stages[] = array(
				'label' => $stage['label'],
				'count' => $stage['count'],
				'pct'   => $pct,
				'tier'  => $stage['tier'] ?? 'site',
			);
			$prev = $stage['count'];
		}

		// WHATSAPP_FUNNEL_VISIBILITY_V1 — Desglose de intención comercial por canal.
		// Sesiones únicas por canal: WhatsApp, Formulario, Chatbot, Otros.
		// Nota: una sesión puede aparecer en más de un canal (no excluyentes).
		$intent_breakdown_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT
					CASE
						WHEN e.event_type IN ('WhatsAppClick','whatsapp_click','chatbot_whatsapp_click','delivery_locator_whatsapp') THEN 'whatsapp'
						WHEN e.event_type = 'form_submit'  THEN 'form'
						WHEN e.event_type IN ('chatbot_lead','chatbot_lead_created') THEN 'chatbot'
						ELSE 'other'
					END AS channel,
					COUNT(DISTINCT e.session_id) AS sessions
					FROM {$events_t} e
					INNER JOIN {$sessions_t} s ON s.session_id = e.session_id
					WHERE s.first_seen >= %s AND s.first_seen < %s{$extra_where}{$prelead_project_where}
					  AND e.event_type IN ('WhatsAppClick','whatsapp_click','chatbot_whatsapp_click','delivery_locator_whatsapp','form_submit','chatbot_lead','chatbot_lead_created')
					GROUP BY channel
					ORDER BY sessions DESC",
					$prelead_base_params
				),
			ARRAY_A
		) ?: array();

		// Normalizar a array asociativo keyed por canal.
		$intent_breakdown = array( 'whatsapp' => 0, 'form' => 0, 'chatbot' => 0, 'other' => 0 );
		foreach ( $intent_breakdown_rows as $row ) {
			$ch = (string) ( $row['channel'] ?? 'other' );
			if ( isset( $intent_breakdown[ $ch ] ) ) {
				$intent_breakdown[ $ch ] = (int) $row['sessions'];
			}
		}

		// Breakdown de ventas por fuente (siempre útil, sin filtro de fuente activo).
			$breakdown = array();
			if ( self::leads_table_exists() && $filters['source'] === '' ) {
				$leads_t = $wpdb->prefix . 'es_leads';
				$breakdown_project_where = $project_filter !== '' ? ' AND l.project_slug = %s' : '';
				$breakdown_params = $project_filter !== '' ? array( $start, $end, $project_filter ) : array( $start, $end );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$breakdown = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT
						COALESCE( NULLIF( s.source, '' ), '(directo)' ) AS source,
						COUNT( DISTINCT l.id )                           AS leads,
						SUM( l.status IN ({$_resv_sql}) )                    AS reservations,
						SUM( l.status IN ({$_sold_sql}) )  AS converted
						FROM {$leads_t} l
						LEFT JOIN {$sessions_t} s ON s.session_id = l.first_session_id
						WHERE s.first_seen >= %s AND s.first_seen < %s
							{$breakdown_project_where}
						GROUP BY s.source
						ORDER BY converted DESC, reservations DESC, leads DESC
						LIMIT 10",
						$breakdown_params
					),
					ARRAY_A
				) ?: array();
		}

		return array(
			'stages'           => $stages,
			'breakdown'        => $breakdown,
			'intent_breakdown' => $intent_breakdown,
			'filters'          => $filters,
			'operator_filter'  => $operator_filter,
			'project_filter'   => $project_filter,
			'spend'            => $total_spend,
		);
	}

	// -------------------------------------------------------------------------
	// Comportamiento → Resultado
	// Qué eventos de comportamiento predicen conversión.
	// JOIN: ea_sessions ↔ ea_events ↔ es_leads
	// -------------------------------------------------------------------------

	/**
	 * @param array $filters
	 * @return array  Filas ordenadas por converted DESC. Vacío si Leads no activo.
	 */
	public static function get_behavior_to_result( $filters ) {
		if ( ! self::leads_table_exists() ) {
			return array();
		}

		global $wpdb;
		$sessions = EA_Utils::table( 'sessions' );
		$events   = EA_Utils::table( 'events' );
		$leads    = $wpdb->prefix . 'es_leads';

		list( $start, $end ) = self::dt_bounds( $filters['from'], $filters['to'] );
		$project_filter = (string) ( $filters['project'] ?? '' );
		$project_where = $project_filter !== '' ? $wpdb->prepare( ' AND l.project_slug = %s', $project_filter ) : '';

		// Usa el catálogo oficial — misma fuente de verdad que el funnel.
		$commercial_events = self::high_intent_events();
		$placeholders      = implode( ', ', array_fill( 0, count( $commercial_events ), '%s' ) );

		$_meet_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'meeting' );
		$_resv_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'reserved' );
		$_sold_sql = Estandar_Analytics_Commercial_Stage_Map::sql_list( 'sold' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE( NULLIF( s.source, '' ), '(directo)' )               AS source,
					e.event_type                                                    AS evento,
					COUNT( DISTINCT l.id )                                          AS leads,
					SUM( l.status IN ({$_meet_sql}) ) AS meeting,
					SUM( l.status IN ({$_resv_sql}) ) AS reservations,
					SUM( l.status IN ({$_sold_sql}) ) AS converted,
					ROUND( SUM( l.status IN ({$_sold_sql}) ) / COUNT( DISTINCT l.id ) * 100, 1 ) AS conversion_pct
				FROM {$sessions} s
				INNER JOIN {$events}   e ON e.session_id        = s.session_id
				INNER JOIN {$leads}    l ON l.first_session_id  = s.session_id
				WHERE s.first_seen >= %s
				  AND s.first_seen < %s
				  {$project_where}
				  AND e.event_type IN ( {$placeholders} )
				GROUP BY s.source, e.event_type
				ORDER BY converted DESC, reservations DESC, meeting DESC, leads DESC
				LIMIT 50",
				array_merge( array( $start, $end ), $commercial_events )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
