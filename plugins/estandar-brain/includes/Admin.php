<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brain admin page — intent map editor, rules editor (JSON), signals viewer.
 * Mutations behind nonce + capability + sanitize.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_brain_save_intents', array( __CLASS__, 'handle_save_intents' ) );
		add_action( 'admin_post_estandar_suite_brain_save_rules', array( __CLASS__, 'handle_save_rules' ) );
		add_action( 'admin_post_estandar_suite_brain_save_exporters', array( __CLASS__, 'handle_save_exporters' ) );
		add_action( 'admin_post_estandar_suite_brain_save_agent_activation', array( __CLASS__, 'handle_save_agent_activation' ) );
		add_action( 'admin_post_estandar_suite_brain_save_scoring', array( __CLASS__, 'handle_save_scoring' ) );
		add_action( 'admin_post_estandar_suite_brain_reindex_knowledge', array( __CLASS__, 'handle_reindex_knowledge' ) );
	}

	/** admin_post handler: guarda la activación del agente (burbuja/interno/público). */
	public static function handle_save_agent_activation(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_agent_activation' );
		$keys = array( 'bubble_admin', 'agent_internal', 'agent_public' );
		$opt  = get_option( 'estandar_suite_brain_agent_settings', array() );
		$opt  = is_array( $opt ) ? $opt : array();
		foreach ( $keys as $k ) {
			$opt[ $k ] = ! empty( $_POST[ $k ] ) ? 1 : 0;
		}
		update_option( 'estandar_suite_brain_agent_settings', $opt, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=agente&agent_saved=1' ) );
		exit;
	}

	/** Exportadores settings grouped (Meta CAPI / Google OCI) — rescata signal-core. */
	public static function exporters_settings_groups(): array
	{
		return array(
			'meta' => array(
				'enabled'         => array( 'type' => 'bool', 'label' => 'Meta Conversions API activo', 'default' => 0 ),
				'pixel_id'        => array( 'type' => 'text', 'label' => 'Pixel ID', 'default' => '' ),
				'access_token'    => array( 'type' => 'text', 'label' => 'Access token', 'default' => '' ),
				'test_event_code' => array( 'type' => 'text', 'label' => 'Test event code', 'default' => '' ),
				'send_lead'       => array( 'type' => 'bool', 'label' => 'Enviar también Lead (server) — evita doble conteo del pixel', 'default' => 0 ),
			),
			'google' => array(
				'enabled'             => array( 'type' => 'bool', 'label' => 'Google Ads OCI activo', 'default' => 0 ),
				'developer_token'     => array( 'type' => 'text', 'label' => 'Developer token', 'default' => '' ),
				'client_id'           => array( 'type' => 'text', 'label' => 'OAuth Client ID', 'default' => '' ),
				'client_secret'       => array( 'type' => 'text', 'label' => 'OAuth Client Secret', 'default' => '' ),
				'customer_id'         => array( 'type' => 'text', 'label' => 'Customer ID', 'default' => '' ),
				'refresh_token'       => array( 'type' => 'text', 'label' => 'Refresh token', 'default' => '' ),
				'conv_lead_created'   => array( 'type' => 'text', 'label' => 'Conversion: lead_created', 'default' => '' ),
				'conv_meeting_booked' => array( 'type' => 'text', 'label' => 'Conversion: meeting_booked', 'default' => '' ),
				'conv_sale_closed'    => array( 'type' => 'text', 'label' => 'Conversion: sale_closed', 'default' => '' ),
			),
		);
	}

	/** admin_post handler: save exportadores settings group (merge). */
	public static function handle_save_exporters(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_exporters' );
		$group  = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::exporters_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		// FEATURE_GROUP_NESTED_V1: escribe `['meta']`/`['google']` anidado (igual
		// que leen MetaCapi/GoogleOci) y migra cualquier clave plana legacy.
		\Estandar\Core\Admin::save_feature_group( 'estandar_suite_brain_exporters_settings', $groups[ $group ], $group, $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=exportadores&ss=' . $group . '&exporters_saved=1' ) );
		exit;
	}

	/** Data for the señales sub-tab (signal core legacy es_signal_*). */
	public static function signal_data(): array
	{
		$rows  = SignalAdoption::rows( 'events', 'ORDER BY id DESC LIMIT 40' );
		$queue = SignalAdoption::count( 'queue' );
		$total = SignalAdoption::count( 'events' );
		return array( 'rows' => $rows, 'queue' => $queue, 'total' => $total );
	}

	const ADOPT_FLAG = 'estandar_suite_brain_exporters_adopted';

	/**
	 * Absorb legacy credentials (estandar_signal_core_*) into the v2 exporters
	 * settings WITHOUT overwriting existing v2 values and NEVER touching the
	 * legacy options. Idempotent; run once (flag) or via CLI `exporters adopt`.
	 */
	public static function adopt_legacy_credentials( bool $force = false ): array
	{
		$map = array(
			'meta' => array(
				'pixel_id'        => 'estandar_signal_core_meta_pixel_id',
				'access_token'    => 'estandar_signal_core_meta_access_token',
				'test_event_code' => 'estandar_signal_core_meta_test_event_code',
			),
			'google' => array(
				'developer_token'     => 'estandar_signal_core_google_developer_token',
				'client_id'           => 'estandar_signal_core_google_client_id',
				'client_secret'       => 'estandar_signal_core_google_client_secret',
				'customer_id'         => 'estandar_signal_core_google_customer_id',
				'refresh_token'       => 'estandar_signal_core_google_refresh_token',
				'conv_lead_created'   => 'estandar_signal_core_google_conv_lead_created',
				'conv_meeting_booked' => 'estandar_signal_core_google_conv_meeting_booked',
				'conv_sale_closed'    => 'estandar_signal_core_google_conv_sale_closed',
			),
		);
		$cur = get_option( 'estandar_suite_brain_exporters_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$stats = array( 'adopted' => 0, 'kept' => 0, 'legacy_read' => 0 );
		foreach ( $map as $group => $pairs ) {
			if ( ! isset( $cur[ $group ] ) || ! is_array( $cur[ $group ] ) ) {
				$cur[ $group ] = array();
			}
			foreach ( $pairs as $v2key => $legacy_opt ) {
				$legacy = get_option( $legacy_opt, '' );
				if ( is_string( $legacy ) && $legacy !== '' ) {
					$stats['legacy_read']++;
					// FEATURE_GROUP_NESTED_V1: valor canónico anidado; se respeta
					// también el plano legacy para no sobreescribir nada.
					$has = ! empty( $cur[ $group ][ $v2key ] ) || ! empty( $cur[ $v2key ] );
					if ( ! $has ) {
						$cur[ $group ][ $v2key ] = $legacy;
						$stats['adopted']++;
					} else {
						$stats['kept']++; // v2 already has a value — never overwrite.
					}
				}
			}
		}
		update_option( 'estandar_suite_brain_exporters_settings', $cur, false );
		update_option( self::ADOPT_FLAG, '1', false );
		return $stats;
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Brain', 'Brain', 'manage_options', 'estandar-suite-brain', array( __CLASS__, 'render' ) );
	}

	/** Estructura de visibilidad de este producto (para el manager central de core). */
	public static function visibility_structure(): array
	{
		return array(
			'product' => 'brain',
			'page'    => 'estandar-suite-brain',
			'label'   => 'Brain',
			'subs'    => array(
				'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
				'config'   => array( 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings' ),
				'datos'    => array( 'adopcion' => 'Adopción' ),
				'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
			),
			'ss' => array(
				'config' => array(
					'exportadores' => self::exporters_ssubs(),
					'settings'     => self::settings_ssubs(),
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
		$tab     = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		$ssubs = array( 'settings' => self::settings_ssubs() );
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'brain', $tabs, $subs );
		if ( empty( $visible_tabs ) ) {
			\Estandar\Core\Admin::render_visibility_none( 'brain' );
			return;
		}
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'brain', $t, $s_list );
		}
		$tabs = $visible_tabs;
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		// Compat: los antiguos sub=intents/reglas ahora viven dentro de Settings.
		// DEBE resolverse ANTES de resolve_sub (que reemplazaría el sub
		// desconocido por el primero de la lista).
		$req_sub = isset( $_GET['sub'] ) ? sanitize_key( (string) $_GET['sub'] ) : '';
		if ( in_array( $req_sub, array( 'intents', 'reglas' ), true ) ) {
			$_GET['sub'] = 'settings';
			$_GET['ss']  = $req_sub;
		}
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ], ( $tab === 'config' && ! isset( $_GET['sub'] ) ) ? 'settings' : '' ) : '';
		$ss = ( $sub === 'settings' && isset( $ssubs['settings'] ) )
			? \Estandar\Core\Admin::resolve_ssub( $ssubs['settings'], 'scoring', 'ss' )
			: '';
		$intents  = IntentMap::mapping();
		$area     = IntentMap::area();
		$rules    = Rules::load();
		$signals  = SignalLedger::recent( 25 );
		$legacy   = Adoption::legacy_present();
		$intent_saved = isset( $_GET['intents_saved'] );
		$rule_saved   = isset( $_GET['rules_saved'] );

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'mapping'       => array( 'estandar_suite_brain_intent_map' ),
			'derive'        => array( 'estandar_suite_brain_intent_map' ),
			'load'          => array( 'estandar_suite_brain_rules' ),
			'evaluate'      => array( 'estandar_suite_brain_rules' ),
			'normalize'     => array( 'estandar_suite_brain_rules' ),
			'activate_schema'  => array( 'estandar_suite_brain_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_brain_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'SignalLedger.php', 'desc' => 'Crea estandar_brain_signals y estandar_brain_signal_queue.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'SignalLedger.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'idempotency_key', 'file' => 'SignalLedger.php', 'desc' => 'Clave para señales idempotentes (sin duplicados).' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'calculate', 'file' => 'Scoring.php', 'desc' => 'Calcula el score wC/wQ/wB.' ),
				array( 'fn' => 'behavior_score', 'file' => 'Scoring.php', 'desc' => 'Score a partir de señales de comportamiento.' ),
				array( 'fn' => 'decay', 'file' => 'Scoring.php', 'desc' => 'Aplica decaimiento temporal al score.' ),
				array( 'fn' => 'clamp', 'file' => 'Scoring.php', 'desc' => 'Limita el score al rango 0-100.' ),
				array( 'fn' => 'load', 'file' => 'Rules.php', 'desc' => 'Carga las reglas deterministas.' ),
				array( 'fn' => 'evaluate', 'file' => 'Rules.php', 'desc' => 'Evalúa una regla contra la captura.' ),
				array( 'fn' => 'matches', 'file' => 'Rules.php', 'desc' => 'Comprueba si una condición coincide.' ),
				array( 'fn' => 'condition', 'file' => 'Rules.php', 'desc' => 'Valida una condición individual.' ),
				array( 'fn' => 'field_tier', 'file' => 'Rules.php', 'desc' => 'Nivel de jerarquía de un campo.' ),
				array( 'fn' => 'comparable', 'file' => 'Rules.php', 'desc' => 'Compara un valor contra el operador.' ),
				array( 'fn' => 'normalize', 'file' => 'Rules.php', 'desc' => 'Normaliza la estructura de una regla.' ),
				array( 'fn' => 'sort', 'file' => 'Rules.php', 'desc' => 'Ordena las reglas por prioridad.' ),
				array( 'fn' => 'defaults', 'file' => 'IntentMap.php', 'desc' => 'Mapa de intents por defecto.' ),
				array( 'fn' => 'mapping', 'file' => 'IntentMap.php', 'desc' => 'Devuelve el mapa de intents actual.' ),
				array( 'fn' => 'derive', 'file' => 'IntentMap.php', 'desc' => 'Deriva el intent de una captura.' ),
				array( 'fn' => 'record', 'file' => 'SignalLedger.php', 'desc' => 'Registra una señal canónica.' ),
				array( 'fn' => 'recent', 'file' => 'SignalLedger.php', 'desc' => 'Últimas señales del ledger.' ),
				array( 'fn' => 'on_capture', 'file' => 'SignalLedger.php', 'desc' => 'Consume estandar_suite_capture_event → scoring + señal.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta señales legadas de es_lead_events/es_leads.' ),
				array( 'fn' => 'legacy_lead_score', 'file' => 'Adoption.php', 'desc' => 'Lee el score histórico del lead.' ),
				array( 'fn' => 'behavior_signals', 'file' => 'Adoption.php', 'desc' => 'Lista señales de comportamiento legadas.' ),
				array( 'fn' => 'event_equivalence', 'file' => 'Adoption.php', 'desc' => 'Mapea eventos legados a canónicos.' ),
				array( 'fn' => 'import_signals', 'file' => 'Adoption.php', 'desc' => 'Importa señales históricas.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'on_capture', 'file' => 'SignalLedger.php', 'desc' => 'Consume estandar_suite_capture_event.' ),
				array( 'fn' => 'record → estandar_suite_signal_recorded', 'file' => 'SignalLedger.php', 'desc' => 'Emite la señal canónica registrada.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (POST /score, GET /signals, POST /rules).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'score', 'file' => 'Cli.php', 'desc' => 'Calcula el score de una entidad.' ),
				array( 'fn' => 'signals', 'file' => 'Cli.php', 'desc' => 'Ver señales del ledger.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa señales históricas (--lead_id=N, --commit).' ),
				array( 'fn' => 'intents', 'file' => 'Cli.php', 'desc' => 'Ver el mapa de intents.' ),
				array( 'fn' => 'intents_set', 'file' => 'Cli.php', 'desc' => 'Guardar el mapa de intents.' ),
				array( 'fn' => 'rules', 'file' => 'Cli.php', 'desc' => 'Ver las reglas.' ),
				array( 'fn' => 'rules_set', 'file' => 'Cli.php', 'desc' => 'Guardar las reglas.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Brain bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Brain con sus 4 tabs.' ),
				array( 'fn' => 'handle_save_intents', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar intents.' ),
				array( 'fn' => 'handle_save_rules', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar reglas.' ),
			),
		);

		include ESTANDAR_SUITE_BRAIN_PATH . 'includes/templates/brain-admin.php';
	}

	/** Data accessor for the template (señales legacy). */
	public static function template_signal_data(): array
	{
		return self::signal_data();
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_brain_intent_map', 'label' => 'Mapa de intents', 'desc' => 'Peso (0-100) de cada intent para el scoring.', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_brain_rules', 'label' => 'Reglas deterministas', 'desc' => 'Reglas JSON que evalúan capturas (buckets).', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_brain_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_brain_*.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_save_intents(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_intents' );

		$area = isset( $_POST['area'] ) ? sanitize_key( wp_unslash( $_POST['area'] ) ) : '';

		// Cargar el preset del área elegida (reemplaza el vocabulario actual).
		if ( ! empty( $_POST['load_preset'] ) ) {
			IntentMap::save(
				array(
					'area'    => $area,
					'intents' => IntentPresets::preset( $area ),
				)
			);
			self::redirect( 'intents', array( 'intents_saved' => 1, 'preset_loaded' => 1 ) );
		}

		// Guardado normal: editar puntos, quitar y agregar intents.
		$map = array();
		foreach ( (array) ( $_POST['intent'] ?? array() ) as $key => $points ) {
			$key = sanitize_key( $key );
			if ( $key === '' ) {
				continue;
			}
			$map[ $key ] = max( 0, min( 100, (int) $points ) );
		}
		foreach ( (array) ( $_POST['remove'] ?? array() ) as $key => $on ) {
			unset( $map[ sanitize_key( $key ) ] );
		}
		$new_keys   = (array) ( $_POST['new_intent_key'] ?? array() );
		$new_points = (array) ( $_POST['new_intent_points'] ?? array() );
		foreach ( $new_keys as $i => $k ) {
			$k = sanitize_key( (string) $k );
			if ( $k === '' ) {
				continue;
			}
			$map[ $k ] = max( 0, min( 100, (int) ( $new_points[ $i ] ?? 10 ) ) );
		}

		IntentMap::save( array( 'area' => $area, 'intents' => $map ) );
		self::redirect( 'intents', array( 'intents_saved' => 1 ) );
	}

	/** Redirige a una sub-tab de Brain con avisos (no pierde la ubicación). */
	private static function redirect( string $sub, array $args = array() ): void
	{
		$args = array_merge( array( 'page' => 'estandar-suite-brain', 'tab' => 'config', 'sub' => $sub ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** admin_post: reindexa el conocimiento del sitio (fuente del agente público). */
	public static function handle_reindex_knowledge(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_reindex_knowledge' );
		$res = Knowledge::index_all();
		self::redirect( 'agente', array( 'knowledge_reindexed' => (int) ( $res['chunks'] ?? 0 ) ) );
	}

	/** Sub-tabs de Settings: el motor de decisión (score + intenciones + reglas). */
	public static function settings_ssubs(): array
	{
		return array(
			'scoring' => 'Ajustes de score',
			'intents' => 'Intenciones',
			'reglas'  => 'Reglas',
		);
	}

	/** admin_post: guarda los pesos/umbrales/decaimiento del scoring. */
	public static function handle_save_scoring(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_scoring' );

		$f = function ( $k, $default ) {
			return isset( $_POST[ $k ] ) && is_numeric( $_POST[ $k ] ) ? (float) wp_unslash( $_POST[ $k ] ) : $default;
		};

		$weights = array();
		foreach ( (array) ( $_POST['behavior'] ?? array() ) as $k => $v ) {
			$k = sanitize_key( $k );
			if ( $k !== '' && is_numeric( $v ) ) {
				$weights[ $k ] = (int) $v;
			}
		}

		Scoring::save(
			array(
				'w_capture'       => $f( 'w_capture', 0.30 ),
				'w_qualification' => $f( 'w_qualification', 0.40 ),
				'w_behavior'      => $f( 'w_behavior', 0.30 ),
				'w_profile'       => $f( 'w_profile', 0.20 ),
				'w_intent'        => $f( 'w_intent', 0.10 ),
				'hot'             => $f( 'hot', 70 ),
				'warm'            => $f( 'warm', 40 ),
				'decay_half_days' => $f( 'decay_half_days', 15 ),
				'decay_zero_days' => $f( 'decay_zero_days', 30 ),
				'behavior_weights' => $weights,
			)
		);
		update_option( AssessmentEngine::OPTION, array( 'enabled' => ! empty( $_POST['assessment_enabled'] ) ), false );
		self::redirect( 'settings', array( 'scoring_saved' => 1 ) );
	}

	public static function handle_save_rules(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_rules' );

		// Modo avanzado: el JSON manda (reemplaza todo el set).
		if ( ! empty( $_POST['use_json'] ) ) {
			$decoded = json_decode( sanitize_textarea_field( (string) ( $_POST['rules_json'] ?? '' ) ), true );
			$clean   = array();
			foreach ( (array) $decoded as $rule ) {
				if ( is_array( $rule ) && Rules::normalize( $rule ) !== null ) {
					$clean[] = $rule;
				}
			}
			update_option( Rules::OPTION, array( 'global' => $clean ), false );
			self::redirect( 'settings', array( 'ss' => 'reglas', 'rules_saved' => 1 ) );
		}

		// Editor guiado: una condición por fila.
		$fields = (array) ( $_POST['cond_field'] ?? array() );
		$ops    = (array) ( $_POST['cond_op'] ?? array() );
		$values = (array) ( $_POST['cond_value'] ?? array() );
		$fits   = (array) ( $_POST['act_fit'] ?? array() );
		$integ  = (array) ( $_POST['act_integrity'] ?? array() );
		$stops  = (array) ( $_POST['act_stop'] ?? array() );
		$prios  = (array) ( $_POST['priority'] ?? array() );
		$remove = (array) ( $_POST['remove_rule'] ?? array() );

		$clean = array();
		foreach ( $fields as $i => $field ) {
			$field = sanitize_text_field( wp_unslash( (string) $field ) );
			if ( $field === '' || isset( $remove[ $i ] ) ) {
				continue;
			}
			$action = array();
			$fit    = sanitize_text_field( wp_unslash( (string) ( $fits[ $i ] ?? '' ) ) );
			$ig     = sanitize_text_field( wp_unslash( (string) ( $integ[ $i ] ?? '' ) ) );
			if ( $fit !== '' ) { $action['commercial_fit'] = $fit; }
			if ( $ig !== '' ) { $action['integrity'] = $ig; }
			if ( ! empty( $stops[ $i ] ) ) { $action['stop'] = true; }
			if ( ! $action ) {
				continue;
			}
			$rule = array(
				'id'         => 'r' . ( count( $clean ) + 1 ),
				'conditions' => array( array(
					'field' => $field,
					'op'    => sanitize_key( (string) ( $ops[ $i ] ?? 'contains' ) ),
					'value' => sanitize_text_field( wp_unslash( (string) ( $values[ $i ] ?? '' ) ) ),
				) ),
				'action'     => $action,
				'priority'   => (int) ( $prios[ $i ] ?? 10 ),
			);
			if ( Rules::normalize( $rule ) !== null ) {
				$clean[] = $rule;
			}
		}

		update_option( Rules::OPTION, array( 'global' => $clean ), false );
		self::redirect( 'settings', array( 'ss' => 'reglas', 'rules_saved' => 1 ) );
	}

	/** Catálogo de sub-sub-tabs (ss=) del sub-tab Exportadores (fuente única). */
	public static function exporters_ssubs(): array
	{
		return array( 'meta' => 'Meta CAPI', 'google' => 'Google Ads OCI' );
	}
}
