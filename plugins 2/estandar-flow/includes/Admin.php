<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flow admin page — pipeline viewer, transition form, customers, quotes, and
 * adoption import/rollback. Mutations behind nonce + capability.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_flow_transition', array( __CLASS__, 'handle_transition' ) );
		add_action( 'admin_post_estandar_suite_flow_adoption_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_estandar_suite_flow_adoption_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_estandar_suite_flow_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		add_action( 'admin_post_estandar_suite_flow_save_quotes_settings', array( __CLASS__, 'handle_save_quotes_settings' ) );
	}

	/** Quotes settings grouped (rescata estandar-quotes defaults). */
	public static function quotes_settings_groups(): array
	{
		return array(
			'documento' => array(
				'company_name'    => array( 'type' => 'text', 'label' => 'Nombre de la empresa', 'default' => '' ),
				'company_website' => array( 'type' => 'text', 'label' => 'Sitio web', 'default' => '' ),
				'company_phone'   => array( 'type' => 'text', 'label' => 'Teléfono', 'default' => '' ),
				'company_email'   => array( 'type' => 'text', 'label' => 'Email', 'default' => '' ),
				'company_address' => array( 'type' => 'text', 'label' => 'Dirección', 'default' => '' ),
				'primary_color'   => array( 'type' => 'text', 'label' => 'Color primario', 'default' => '#1d2327' ),
				'accent_color'    => array( 'type' => 'text', 'label' => 'Color de acento', 'default' => '#2271b1' ),
				'document_title'  => array( 'type' => 'text', 'label' => 'Título del documento', 'default' => 'Cotización' ),
				'conditions'      => array( 'type' => 'text', 'label' => 'Condiciones', 'default' => '' ),
				'footer_legal'    => array( 'type' => 'text', 'label' => 'Pie legal', 'default' => '' ),
				'renderer'        => array( 'type' => 'select', 'label' => 'Renderizador PDF', 'options' => array( 'dompdf' => 'dompdf' ), 'default' => 'dompdf' ),
			),
			'finanzas' => array(
				'validity_days'       => array( 'type' => 'text', 'label' => 'Validez (días)', 'default' => '7' ),
				'uf_source'           => array( 'type' => 'select', 'label' => 'Fuente de UF', 'options' => array( 'manual' => 'Manual', 'api' => 'API' ), 'default' => 'manual' ),
				'uf_value_clp'        => array( 'type' => 'text', 'label' => 'Valor UF (CLP)', 'default' => '' ),
				'annual_rate_percent' => array( 'type' => 'text', 'label' => 'Tasa anual (%)', 'default' => '' ),
				'credit_percent'      => array( 'type' => 'text', 'label' => 'Crédito (%)', 'default' => '80' ),
				'income_multiple'     => array( 'type' => 'text', 'label' => 'Múltiplo de ingreso', 'default' => '4' ),
			),
			'sala_ventas' => array(
				'sales_room_name'    => array( 'type' => 'text', 'label' => 'Nombre sala de ventas', 'default' => '' ),
				'sales_room_address' => array( 'type' => 'text', 'label' => 'Dirección', 'default' => '' ),
				'sales_room_phone'   => array( 'type' => 'text', 'label' => 'Teléfono', 'default' => '' ),
				'sales_room_email'   => array( 'type' => 'text', 'label' => 'Email', 'default' => '' ),
				'sales_room_hours'   => array( 'type' => 'text', 'label' => 'Horario', 'default' => '' ),
			),
			'autoridad' => array(
				'authority_name' => array( 'type' => 'text', 'label' => 'Nombre del firmante', 'default' => '' ),
				'authority_role' => array( 'type' => 'text', 'label' => 'Cargo', 'default' => '' ),
				'authority_id'   => array( 'type' => 'text', 'label' => 'Identificación', 'default' => '' ),
				'operator_copy'  => array( 'type' => 'text', 'label' => 'Copia al operador (email)', 'default' => '' ),
			),
			'email' => array(
				'sender_name'          => array( 'type' => 'text', 'label' => 'Remitente (nombre)', 'default' => '' ),
				'sender_email'         => array( 'type' => 'text', 'label' => 'Remitente (email)', 'default' => '' ),
				'email_subject_template' => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Cotización' ),
				'email_cc'             => array( 'type' => 'text', 'label' => 'CC', 'default' => '' ),
				'email_bcc'            => array( 'type' => 'text', 'label' => 'CCO', 'default' => '' ),
			),
		);
	}

	/** Quotes settings flattened (CLI). */
	public static function quotes_settings_fields(): array
	{
		$out = array();
		foreach ( self::quotes_settings_groups() as $g => $fs ) { $out = array_merge( $out, $fs ); }
		return $out;
	}

	/** admin_post handler: save quotes settings group (merge). */
	public static function handle_save_quotes_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_quotes_settings' );
		$group  = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::quotes_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( 'estandar_suite_flow_quotes_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( 'estandar_suite_flow_quotes_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=quotes&ss=' . $group . '&quotes_saved=1' ) );
		exit;
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Flow', 'Flow', 'manage_options', 'estandar-suite-flow', array( __CLASS__, 'render' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'quotes' => array(
				'enabled'  => array( 'type' => 'bool', 'label' => 'Módulo de cotizaciones habilitado', 'desc' => 'Activa el módulo de cotizaciones en la suite.', 'default' => 1 ),
				'currency' => array( 'type' => 'text', 'label' => 'Moneda por defecto', 'desc' => 'Código de moneda (CLP, USD, EUR).', 'default' => 'CLP' ),
				'vat'      => array( 'type' => 'text', 'label' => 'IVA (%)', 'desc' => 'Porcentaje de impuesto aplicado por defecto.', 'default' => '19' ),
			),
		);
	}

	/** Data provider for a feature sub-tab (read-only legacy + v2). */
	public static function feature_data( string $name ): array
	{
		$data = array( 'quotes' => array(), 'v2_quotes' => 0 );
		if ( $name === 'quotes' ) {
			$data['quotes']   = \Estandar\Core\Admin::legacy_rows( 'es_quotes', 'ORDER BY id DESC LIMIT 50' );
			$data['v2_quotes'] = \Estandar\Core\Admin::legacy_count( 'estandar_flow_quotes' );
		}
		return $data;
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_flow_' . $feature . '_settings', \Estandar\Core\Admin::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
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
			'config'   => array( 'etapas' => 'Etapas', 'quotes' => 'Cotizaciones', 'settings' => 'Settings', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'flow', $tabs, $subs );
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'flow', $t, $s_list );
		}
		$tabs = $visible_tabs;
		if ( isset( $tabs['config'] ) ) {
			$subs['config']['visibilidad'] = 'Visibilidad';
		}
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ] ) : '';
		$stage = sanitize_key( (string) ( $_GET['stage'] ?? '' ) );
		$pipeline = Pipeline::list( $stage );
		$customers = Customers::list( 50 );
		$quotes    = Quotes::list( 50 );
		$legacy    = Adoption::legacy_present();
		$trans = isset( $_GET['transitioned'] );
		$imp   = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$rol   = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;

		// ── Feature sub-tab data (quotes) ───────────────────────────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && isset( $feature_fields[ $sub ] ) ) {
			$feature_view = self::feature_data( $sub );
		}

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'activate_schema'  => array( 'estandar_suite_flow_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_flow_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'Pipeline.php', 'desc' => 'Crea estandar_flow_pipeline, stage_events, customers, quotes, tasks.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Pipeline.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'is_valid_stage', 'file' => 'Pipeline.php', 'desc' => 'Valida una etapa contra las canónicas.' ),
				array( 'fn' => 'status_label', 'file' => 'Pipeline.php', 'desc' => 'Etiqueta legible de una etapa.' ),
				array( 'fn' => 'fingerprint', 'file' => 'Customers.php', 'desc' => 'Huella UNIQUE para evitar clientes duplicados.' ),
				array( 'fn' => 'quote_uuid', 'file' => 'Quotes.php', 'desc' => 'Genera el UUID de una cotización.' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'transition', 'file' => 'Pipeline.php', 'desc' => 'Transición auditada entre etapas del pipeline.' ),
				array( 'fn' => 'list', 'file' => 'Pipeline.php', 'desc' => 'Lista el pipeline con filtro por etapa.' ),
				array( 'fn' => 'on_capture', 'file' => 'Pipeline.php', 'desc' => 'Consume estandar_suite_capture_event → crea/actualiza pipeline.' ),
				array( 'fn' => 'create', 'file' => 'Customers.php', 'desc' => 'Crea un cliente con fingerprint único.' ),
				array( 'fn' => 'list', 'file' => 'Customers.php', 'desc' => 'Lista clientes.' ),
				array( 'fn' => 'create', 'file' => 'Quotes.php', 'desc' => 'Crea una cotización.' ),
				array( 'fn' => 'list', 'file' => 'Quotes.php', 'desc' => 'Lista cotizaciones.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta estado comercial histórico de es_leads.' ),
				array( 'fn' => 'project_lead', 'file' => 'Adoption.php', 'desc' => 'Proyecta un lead legado al pipeline.' ),
				array( 'fn' => 'historical_leads', 'file' => 'Adoption.php', 'desc' => 'Lista leads históricos para previsualizar.' ),
				array( 'fn' => 'import_pipeline', 'file' => 'Adoption.php', 'desc' => 'Importa el pipeline histórico (dry-run o commit).' ),
				array( 'fn' => 'rollback_pipeline', 'file' => 'Adoption.php', 'desc' => 'Elimina solo los pipelines adoptados.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'on_capture', 'file' => 'Pipeline.php', 'desc' => 'Consume estandar_suite_capture_event.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (GET /pipeline, POST /pipeline/{id}/transition, GET /customers, GET /quotes).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'pipeline', 'file' => 'Cli.php', 'desc' => 'Ver el pipeline.' ),
				array( 'fn' => 'transition', 'file' => 'Cli.php', 'desc' => 'Mover una entidad de etapa.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa el pipeline histórico (--commit).' ),
				array( 'fn' => 'adoption_rollback', 'file' => 'Cli.php', 'desc' => 'Rollback de pipeline adoptado (--confirm=yes).' ),
				array( 'fn' => 'quotes_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de cotizaciones legadas.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Flow bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Flow con sus 4 tabs.' ),
				array( 'fn' => 'feature_data', 'file' => 'Admin.php', 'desc' => 'Datos del sub-tab quotes (read-only legado, es_quotes).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'handle_transition', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para transición.' ),
				array( 'fn' => 'handle_import', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para import de pipeline.' ),
				array( 'fn' => 'handle_rollback', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para rollback de pipeline.' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature.' ),
			),
		);

		include ESTANDAR_SUITE_FLOW_PATH . 'includes/templates/flow-admin.php';
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_flow_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_flow_*.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_transition(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_transition' );
		Pipeline::transition( (int) ( $_POST['pipeline_id'] ?? 0 ), sanitize_key( (string) ( $_POST['stage'] ?? '' ) ), (string) ( $_POST['reason'] ?? '' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&transitioned=1' ) );
		exit;
	}

	public static function handle_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_adoption_import' );
		$r = Adoption::import_pipeline( empty( $_POST['commit'] ), (int) ( $_POST['limit'] ?? 100 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_adoption_rollback' );
		$n = Adoption::rollback_pipeline();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&rolled=' . (int) $n ) );
		exit;
	}
}
