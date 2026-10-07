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
		$cur = get_option( 'estandar_suite_brain_exporters_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( 'estandar_suite_brain_exporters_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=exportadores&ss=' . $group . '&exporters_saved=1' ) );
		exit;
	}

	/** Data for the señales sub-tab (legacy ledger es_signal_events). */
	public static function signal_data(): array
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'es_signal_events', 'ORDER BY id DESC LIMIT 40' );
		$queue = \Estandar\Core\Admin::legacy_count( 'es_signal_queue' );
		return array( 'rows' => $rows, 'queue' => $queue, 'total' => \Estandar\Core\Admin::legacy_count( 'es_signal_events' ) );
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
			foreach ( $pairs as $v2key => $legacy_opt ) {
				$legacy = get_option( $legacy_opt, '' );
				if ( is_string( $legacy ) && $legacy !== '' ) {
					$stats['legacy_read']++;
					if ( empty( $cur[ $v2key ] ) ) {
						$cur[ $v2key ] = $legacy;
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
		$tab     = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'intents' => 'Intents', 'reglas' => 'Reglas', 'senales' => 'Señales', 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'brain', $tabs, $subs );
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'brain', $t, $s_list );
		}
		$tabs = $visible_tabs;
		if ( isset( $tabs['config'] ) ) {
			$subs['config']['visibilidad'] = 'Visibilidad';
		}
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ] ) : '';
		$intents  = IntentMap::mapping();
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
		$map = array();
		foreach ( (array) ( $_POST['intent'] ?? array() ) as $key => $points ) {
			$key = sanitize_key( $key );
			if ( $key === '' ) {
				continue;
			}
			$map[ $key ] = max( 0, min( 100, (int) $points ) );
		}
		update_option( IntentMap::OPTION, $map, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-brain&intents_saved=1' ) );
		exit;
	}

	public static function handle_save_rules(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_brain_save_rules' );
		$json = sanitize_textarea_field( (string) ( $_POST['rules_json'] ?? '' ) );
		$decoded = json_decode( $json, true );
		$rules = is_array( $decoded ) ? $decoded : array();
		// Validate structure: keep only usable rules (drop bad entries).
		$clean = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && Rules::normalize( $rule ) !== null ) {
				$clean[] = $rule;
			}
		}
		update_option( Rules::OPTION, array( 'global' => $clean ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-brain&rules_saved=1' ) );
		exit;
	}
}
