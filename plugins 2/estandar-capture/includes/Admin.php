<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture admin page — adoption status, historical preview, import (dry-run /
 * commit) and rollback. All mutations behind nonce + capability.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_capture_adoption_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_estandar_suite_capture_adoption_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		add_action( 'admin_post_estandar_suite_capture_save_form', array( __CLASS__, 'handle_save_form' ) );
		add_action( 'admin_post_estandar_suite_capture_delete_form', array( __CLASS__, 'handle_delete_form' ) );
		add_action( 'admin_post_estandar_suite_capture_restore_form', array( __CLASS__, 'handle_restore_form' ) );
		add_action( 'admin_post_estandar_suite_capture_purge_form', array( __CLASS__, 'handle_purge_form' ) );
		add_action( 'admin_post_estandar_suite_capture_duplicate_form', array( __CLASS__, 'handle_duplicate_form' ) );
		add_action( 'admin_post_estandar_suite_capture_save_popup', array( __CLASS__, 'handle_save_popup' ) );
		add_action( 'admin_post_estandar_suite_capture_delete_popup', array( __CLASS__, 'handle_delete_popup' ) );
		add_action( 'admin_post_estandar_suite_capture_save_capabilities', array( __CLASS__, 'handle_save_capabilities' ) );
		add_action( 'admin_post_estandar_suite_capture_save_caps_matrix', array( __CLASS__, 'handle_save_caps_matrix' ) );
		add_action( 'admin_post_estandar_suite_capture_save_chatbot_settings', array( __CLASS__, 'handle_save_chatbot_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_popups_settings', array( __CLASS__, 'handle_save_popups_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_leads_settings', array( __CLASS__, 'handle_save_leads_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_entries_import', array( __CLASS__, 'handle_forms_entries_import' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_entries_rollback', array( __CLASS__, 'handle_forms_entries_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_responses_import', array( __CLASS__, 'handle_forms_responses_import' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_responses_rollback', array( __CLASS__, 'handle_forms_responses_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_migrate_form', array( __CLASS__, 'handle_migrate_form' ) );
		add_action( 'admin_post_estandar_suite_capture_migrate_rollback', array( __CLASS__, 'handle_migrate_rollback' ) );
	}

	/** Generic merge-save for a capture settings group (chatbot/popups/leads). */
	private static function merge_group_save( string $option, array $groups, string $redirect_sub ): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_' . $redirect_sub . '_settings' );
		$group = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( $option, array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( $option, $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $redirect_sub . '&ss=' . $group . '&' . $redirect_sub . '_saved=1' ) );
		exit;
	}

	/** Popups settings grouped by sub-tab. */
	public static function popups_settings_groups(): array
	{
		return array(
			'configuracion' => array(
				'default_size'      => array( 'type' => 'select', 'label' => 'Tamaño por defecto', 'options' => array( 'small' => 'Pequeño', 'medium' => 'Mediano', 'large' => 'Grande' ), 'default' => 'medium' ),
				'default_animation' => array( 'type' => 'select', 'label' => 'Animación por defecto', 'options' => array( 'fade' => 'Fade', 'slide' => 'Slide', 'zoom' => 'Zoom' ), 'default' => 'fade' ),
				'default_overlay'   => array( 'type' => 'bool', 'label' => 'Overlay oscuro', 'desc' => 'Fondo oscurecido detrás del popup.', 'default' => 1 ),
				'overlay_color'     => array( 'type' => 'text', 'label' => 'Color del overlay', 'default' => '#000000' ),
				'overlay_opacity'   => array( 'type' => 'text', 'label' => 'Opacidad del overlay', 'default' => '0.6' ),
				'z_index'           => array( 'type' => 'text', 'label' => 'Z-index', 'default' => '99999' ),
				'track_views'       => array( 'type' => 'bool', 'label' => 'Registrar vistas', 'desc' => 'Cuenta cada visualización del popup.', 'default' => 1 ),
				'track_clicks'      => array( 'type' => 'bool', 'label' => 'Registrar clicks', 'desc' => 'Cuenta los clicks en el popup.', 'default' => 1 ),
			),
		);
	}

	/** admin_post handler: save popups settings group (merge). */
	public static function handle_save_popups_settings(): void
	{
		self::merge_group_save( 'estandar_suite_capture_popups_settings', self::popups_settings_groups(), 'popups' );
	}

	/** Leads settings grouped by sub-tab. */
	public static function leads_settings_groups(): array
	{
		return array(
			'email' => array(
				'sender_name'    => array( 'type' => 'text', 'label' => 'Remitente (nombre)', 'default' => '' ),
				'sender_email'   => array( 'type' => 'text', 'label' => 'Remitente (email)', 'default' => '' ),
				'reply_to_email' => array( 'type' => 'text', 'label' => 'Responder a', 'default' => '' ),
				'cc_email'       => array( 'type' => 'text', 'label' => 'CC', 'default' => '' ),
				'bcc_email'      => array( 'type' => 'text', 'label' => 'CCO', 'default' => '' ),
				'subject'        => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Nuevo Lead Recibido' ),
				'preheader'      => array( 'type' => 'text', 'label' => 'Preheader', 'default' => '' ),
				'intro_text'     => array( 'type' => 'text', 'label' => 'Texto intro', 'default' => '' ),
				'brand_color'    => array( 'type' => 'text', 'label' => 'Color de marca', 'default' => '#1a73e8' ),
			),
			'autorespuesta' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Autorespuesta activa', 'default' => 0 ),
				'subject'        => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Gracias por tu interés' ),
				'body'           => array( 'type' => 'text', 'label' => 'Cuerpo', 'default' => '' ),
				'throttle_hours' => array( 'type' => 'text', 'label' => 'Espera entre envíos (horas)', 'default' => '24' ),
			),
			'integraciones' => array(
				'meta_pixel_id'      => array( 'type' => 'text', 'label' => 'Meta Pixel ID', 'default' => '' ),
				'meta_enabled'       => array( 'type' => 'bool', 'label' => 'Meta CAPI activo', 'default' => 0 ),
				'meta_server_events' => array( 'type' => 'bool', 'label' => 'Server events', 'default' => 1 ),
				'google_enabled'     => array( 'type' => 'bool', 'label' => 'Google Ads activo', 'default' => 0 ),
				'google_customer_id' => array( 'type' => 'text', 'label' => 'Customer ID Google', 'default' => '' ),
				'google_conversion_action_id' => array( 'type' => 'text', 'label' => 'Conversion action ID', 'default' => '' ),
			),
			'calidad' => array(
				'fit_enabled'         => array( 'type' => 'bool', 'label' => 'Indicador de fit activo', 'default' => 0 ),
				'assessment_enabled'  => array( 'type' => 'bool', 'label' => 'Assessment IA activo', 'default' => 0 ),
				'compliance_enabled'  => array( 'type' => 'bool', 'label' => 'Motor de compliance', 'default' => 0 ),
				'feedback_due_hours'  => array( 'type' => 'text', 'label' => 'Feedback vence (horas)', 'default' => '48' ),
				'recovery_enabled'    => array( 'type' => 'bool', 'label' => 'Recuperación de leads', 'default' => 1 ),
				'recovery_delay_hours'=> array( 'type' => 'text', 'label' => 'Espera de recuperación (horas)', 'default' => '24' ),
			),
		);
	}

	/** Leads settings flattened (CLI). */
	public static function leads_settings_fields(): array
	{
		$out = array();
		foreach ( self::leads_settings_groups() as $g => $fields ) { $out = array_merge( $out, $fields ); }
		return $out;
	}

	/** admin_post handler: save leads settings group (merge). */
	public static function handle_save_leads_settings(): void
	{
		self::merge_group_save( 'estandar_suite_capture_leads_settings', self::leads_settings_groups(), 'leads' );
	}

	const LEADS_ADOPT_FLAG = 'estandar_suite_capture_leads_credentials_adopted';

	/**
	 * Absorb legacy ad credentials (estandar_leads_meta y google) into the
	 * v2 leads settings WITHOUT overwriting v2 values and NEVER touching the
	 * legacy options. Idempotent; run once (flag) or via CLI.
	 */
	public static function adopt_leads_credentials( bool $force = false ): array
	{
		$map = array(
			'meta_pixel_id'             => 'estandar_leads_meta_pixel_id',
			'meta_enabled'              => 'estandar_leads_meta_enabled',
			'google_customer_id'        => 'estandar_leads_google_customer_id',
			'google_conversion_action_id' => 'estandar_leads_google_conversion_action_id',
		);
		$cur = get_option( 'estandar_suite_capture_leads_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$stats = array( 'adopted' => 0, 'kept' => 0, 'legacy_read' => 0 );
		foreach ( $map as $v2key => $legacy_opt ) {
			$legacy = get_option( $legacy_opt, '' );
			if ( is_string( $legacy ) && $legacy !== '' ) {
				$stats['legacy_read']++;
				if ( empty( $cur[ $v2key ] ) ) {
					$cur[ $v2key ] = $legacy;
					$stats['adopted']++;
				} else {
					$stats['kept']++;
				}
			}
		}
		update_option( 'estandar_suite_capture_leads_settings', $cur, false );
		update_option( self::LEADS_ADOPT_FLAG, '1', false );
		return $stats;
	}

	/** Chatbot settings grouped by sub-tab (config categories). */
	public static function chatbot_settings_groups(): array
	{
		return array(
			'conversacion' => array(
				'activar'         => array( 'type' => 'bool', 'label' => 'Activar chatbot', 'desc' => 'Habilita el chatbot en el sitio.', 'default' => 1 ),
				'solo_loggeados'  => array( 'type' => 'bool', 'label' => 'Solo usuarios logueados', 'desc' => 'Mostrar solo a usuarios con sesión.', 'default' => 0 ),
				'nombre'          => array( 'type' => 'text', 'label' => 'Nombre del asistente', 'desc' => 'Nombre que muestra el chatbot.', 'default' => 'Asistente' ),
				'mostrar_nombre'  => array( 'type' => 'bool', 'label' => 'Mostrar nombre', 'desc' => 'Muestra el nombre en la conversación.', 'default' => 1 ),
				'idioma'          => array( 'type' => 'select', 'label' => 'Idioma', 'options' => array( 'es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués' ), 'default' => 'es' ),
				'perfil'          => array( 'type' => 'select', 'label' => 'Perfil / flujo', 'options' => array( 'default' => 'Default', 'lead' => 'Captura de leads', 'survey' => 'Encuesta' ), 'default' => 'default' ),
			),
			'captacion' => array(
				'lead_capture_enabled'     => array( 'type' => 'bool', 'label' => 'Captura de leads', 'desc' => 'Pide datos de contacto al visitante.', 'default' => 1 ),
				'auto_open_enabled'        => array( 'type' => 'bool', 'label' => 'Apertura automática', 'desc' => 'Abre la burbuja por sí sola.', 'default' => 0 ),
				'auto_open_type'           => array( 'type' => 'select', 'label' => 'Tipo de apertura', 'options' => array( 'time' => 'Por tiempo', 'scroll' => 'Por scroll' ), 'default' => 'time' ),
				'auto_open_value'          => array( 'type' => 'text', 'label' => 'Valor de apertura', 'desc' => 'Segundos o % de scroll.', 'default' => '6' ),
				'human_escalation_enabled' => array( 'type' => 'bool', 'label' => 'Escalada a humano', 'desc' => 'Permite derivar a una persona.', 'default' => 1 ),
				'human_escalation_mode'    => array( 'type' => 'select', 'label' => 'Modo de escalada', 'options' => array( 'lead_form' => 'Formulario', 'whatsapp' => 'WhatsApp' ), 'default' => 'lead_form' ),
				'human_whatsapp_number'    => array( 'type' => 'text', 'label' => 'WhatsApp de escalada', 'desc' => 'Número para derivar (si modo WhatsApp).', 'default' => '' ),
				'post_lead_message'        => array( 'type' => 'text', 'label' => 'Mensaje post-lead', 'desc' => 'Mensaje tras capturar el lead.', 'default' => 'Perfecto, ya tengo tus datos.' ),
				'business_industry'        => array( 'type' => 'text', 'label' => 'Industria del negocio', 'default' => 'servicios' ),
				'business_goal'            => array( 'type' => 'text', 'label' => 'Objetivo del negocio', 'default' => 'captar contactos' ),
				'business_primary_cta'     => array( 'type' => 'text', 'label' => 'CTA principal', 'default' => 'Quiero avanzar' ),
				'business_tone'            => array( 'type' => 'text', 'label' => 'Tono de voz', 'default' => 'claro y profesional' ),
				'business_sensitive_topics'=> array( 'type' => 'text', 'label' => 'Temas sensibles', 'desc' => 'Temas que derivar a humano.', 'default' => 'precio, plazos' ),
			),
			'ia' => array(
				'openai_model'       => array( 'type' => 'text', 'label' => 'Modelo OpenAI', 'default' => 'gpt-4o-mini' ),
				'openai_max_tokens'  => array( 'type' => 'text', 'label' => 'Máx. tokens', 'default' => '500' ),
				'openai_temperature' => array( 'type' => 'text', 'label' => 'Temperatura', 'default' => '0.7' ),
				'system_prompt'      => array( 'type' => 'text', 'label' => 'System prompt', 'desc' => 'Instrucciones base del asistente.', 'default' => '' ),
				'context_txt'        => array( 'type' => 'text', 'label' => 'Contexto (texto)', 'desc' => 'Base de conocimiento del negocio.', 'default' => '' ),
			),
			'apariencia' => array(
				'theme_accent'         => array( 'type' => 'text', 'label' => 'Color de acento', 'default' => '#111827' ),
				'visual_style'         => array( 'type' => 'select', 'label' => 'Estilo visual', 'options' => array( 'default' => 'Default', 'rounded' => 'Redondeado', 'flat' => 'Plano' ), 'default' => 'default' ),
				'bubble_shape'         => array( 'type' => 'select', 'label' => 'Forma de burbuja', 'options' => array( 'rounded' => 'Redondeada', 'circle' => 'Círculo', 'square' => 'Cuadrada' ), 'default' => 'rounded' ),
				'theme_position'       => array( 'type' => 'select', 'label' => 'Posición', 'options' => array( 'right' => 'Derecha', 'left' => 'Izquierda' ), 'default' => 'right' ),
				'bubble_size'          => array( 'type' => 'text', 'label' => 'Tamaño de burbuja (px)', 'default' => '58' ),
				'bubble_image_url'     => array( 'type' => 'text', 'label' => 'Imagen de burbuja (URL)', 'default' => '' ),
				'inline_sidebar_enabled' => array( 'type' => 'bool', 'label' => 'Barra lateral inline', 'desc' => 'Muestra el chatbot como panel lateral.', 'default' => 0 ),
			),
			'integraciones' => array(
				'webhook_enabled'        => array( 'type' => 'bool', 'label' => 'Webhook', 'desc' => 'Envía eventos a un webhook.', 'default' => 0 ),
				'webhook_url'            => array( 'type' => 'text', 'label' => 'URL del webhook', 'default' => '' ),
				'webhook_provider'       => array( 'type' => 'select', 'label' => 'Proveedor webhook', 'options' => array( 'n8n' => 'n8n', 'generic' => 'Genérico' ), 'default' => 'n8n' ),
				'webhook_secret'         => array( 'type' => 'text', 'label' => 'Secreto del webhook', 'default' => '' ),
				'crm_enabled'            => array( 'type' => 'bool', 'label' => 'CRM', 'desc' => 'Envía leads a un CRM.', 'default' => 0 ),
				'crm_url'                => array( 'type' => 'text', 'label' => 'URL del CRM', 'default' => '' ),
				'crm_secret'             => array( 'type' => 'text', 'label' => 'Secreto del CRM', 'default' => '' ),
				'notify_email_enabled'   => array( 'type' => 'bool', 'label' => 'Notificar por email', 'desc' => 'Avisa al completar un lead.', 'default' => 1 ),
				'notify_email_to'        => array( 'type' => 'text', 'label' => 'Email de notificación', 'default' => '' ),
				'notify_email_subject'   => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Nuevo lead desde chatbot' ),
				'whatsapp_enabled'       => array( 'type' => 'bool', 'label' => 'WhatsApp Cloud API', 'desc' => 'Conecta con WhatsApp Cloud API.', 'default' => 0 ),
				'whatsapp_phone_number_id' => array( 'type' => 'text', 'label' => 'Phone Number ID', 'default' => '' ),
				'whatsapp_access_token'  => array( 'type' => 'text', 'label' => 'Access token', 'default' => '' ),
				'whatsapp_verify_token'  => array( 'type' => 'text', 'label' => 'Verify token', 'default' => '' ),
			),
			'sistema' => array(
				'logs_retention_days' => array( 'type' => 'text', 'label' => 'Retención de logs (días)', 'desc' => '0 = indefinido.', 'default' => '0' ),
				'uninstall_wipe'      => array( 'type' => 'bool', 'label' => 'Borrar datos al desinstalar', 'default' => 0 ),
				'licencia_plan'       => array( 'type' => 'select', 'label' => 'Plan de licencia', 'options' => array( 'free' => 'Free', 'demo' => 'Demo', 'pro' => 'PRO' ), 'default' => 'free' ),
				'licencia_key'        => array( 'type' => 'text', 'label' => 'Clave de licencia', 'default' => '' ),
			),
		);
	}

	/** Chatbot settings flattened (all groups) for CLI. */
	public static function chatbot_settings_fields(): array
	{
		$out = array();
		foreach ( self::chatbot_settings_groups() as $g => $fields ) {
			$out = array_merge( $out, $fields );
		}
		return $out;
	}

	/** admin_post handler: save a chatbot settings group (merge, keeps others). */
	public static function handle_save_chatbot_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_chatbot_settings' );
		$group = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::chatbot_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( 'estandar_suite_capture_chatbot_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=' . $group . '&chatbot_saved=1' ) );
		exit;
	}

	/** admin_post handler: persist the capabilities matrix (type × cap). */
	public static function handle_save_caps_matrix(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_caps_matrix' );
		$types = array( 'lead', 'survey', 'incident' );
		$raw = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? $_POST['matrix'] : array();
		foreach ( $types as $t ) {
			$catalog = Forms::product_capabilities( $t );
			foreach ( $catalog as $cap => $cfg ) {
				Forms::set_capability( $t, $cap, ! empty( $raw[ $t ][ $cap ] ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=settings&sub=capacidades&matrix_saved=1' ) );
		exit;
	}

	/** admin_post handler: persist per-product capabilities. */
	public static function handle_save_capabilities(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_capabilities' );
		$type = sanitize_key( (string) ( $_POST['type'] ?? 'lead' ) );
		$catalog = Forms::product_capabilities( $type );
		$raw = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? $_POST['caps'] : array();
		foreach ( $catalog as $cap => $cfg ) {
			Forms::set_capability( $type, $cap, ! empty( $raw[ $cap ] ) );
		}
		$sub = array( 'lead' => 'forms', 'survey' => 'surveys', 'incident' => 'postventa' )[ $type ] ?? 'forms';
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $sub . '&caps_saved=1' ) );
		exit;
	}

	/** admin_post handler: create or update a v2-managed popup. */
	public static function handle_save_popup(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_popup' );
		$id = isset( $_POST['popup_id'] ) ? (int) $_POST['popup_id'] : 0;
		$raw = array(
			'name'        => (string) ( $_POST['name'] ?? '' ),
			'slug'        => (string) ( $_POST['slug'] ?? '' ),
			'status'      => (string) ( $_POST['status'] ?? 'draft' ),
			'config_json' => (string) ( $_POST['config_json'] ?? '{}' ),
		);
		if ( $id ) {
			$ok = Popups::update( $id, $raw );
		} else {
			$id = Popups::create( $raw );
			$ok = $id > 0;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_saved=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: delete a v2-managed popup. */
	public static function handle_delete_popup(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_delete_popup' );
		$id = isset( $_POST['popup_id'] ) ? (int) $_POST['popup_id'] : 0;
		if ( $id && ( empty( $_POST['confirm'] ) || $_POST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_deleted=0' ) );
			exit;
		}
		$ok = $id ? Popups::delete( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_deleted=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: create or update a v2-managed form. */
	public static function handle_save_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_form' );
		$id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$raw = array(
			'name'         => (string) ( $_POST['name'] ?? '' ),
			'slug'         => (string) ( $_POST['slug'] ?? '' ),
			'form_type'    => (string) ( $_POST['form_type'] ?? 'lead' ),
			'form_context' => (string) ( $_POST['form_context'] ?? 'generic' ),
			'config_json'  => (string) ( $_POST['config_json'] ?? '{}' ),
			'status'       => (string) ( $_POST['status'] ?? 'active' ),
		);
		if ( $id ) {
			// Fusión no destructiva: el documento existente nunca se pierde por un
			// guardado parcial/vacío (protege config no visible de otras subtabs).
			$existing = Forms::get( $id );
			if ( $existing ) {
				$raw['config_json'] = Forms::merge_config_json( (string) $existing['config_json'], (string) $raw['config_json'] );
				// Cambio de tipo canónico → conversión no destructiva (mismo motor).
				if ( (string) $raw['form_type'] !== (string) $existing['form_type'] ) {
					$conv = Forms::convert_type( $id, (string) $raw['form_type'], $raw );
					$ok = ! empty( $conv['ok'] );
				} else {
					$ok = Forms::update( $id, $raw );
				}
			} else {
				$ok = false;
			}
		} else {
			$id = Forms::create( $raw );
			$ok = $id > 0;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&form_saved=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: move a v2-managed form to the papelera (soft delete). */
	public static function handle_delete_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_delete_form' );
		$id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( $id && ( empty( $_POST['confirm'] ) || $_POST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&form_trashed=0' ) );
			exit;
		}
		$ok = $id ? Forms::trash( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&form_trashed=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: restore a trashed form. */
	public static function handle_restore_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_restore_form' );
		$id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$ok = $id ? Forms::restore( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&papelera=1&form_restored=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: permanently delete a trashed form. */
	public static function handle_purge_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_purge_form' );
		$id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		if ( $id && ( empty( $_POST['confirm'] ) || $_POST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&papelera=1&form_purged=0' ) );
			exit;
		}
		$ok = $id ? Forms::delete( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&papelera=1&form_purged=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** Map a form id to its product sub-tab slug. */
	private static function form_sub_for( int $id ): string
	{
		$f = Forms::get( $id );
		if ( $f ) {
			return array( 'lead' => 'forms', 'survey' => 'surveys', 'incident' => 'postventa' )[ $f['form_type'] ] ?? 'forms';
		}
		return 'forms';
	}

	/**
	 * Subtabs del editor (maquetador). Registro canónico y extensible: añadir una
	 * clave aquí y un panel <div class="es-st-panel" data-panel="..."> en el
	 * template. La primera clave es la subtab por defecto.
	 */
	public static function editor_subtabs(): array
	{
		return array(
			'diseno'        => 'Diseño',
			'configuracion' => 'Configuración',
			'correo'        => 'Correo',
			'entradas'      => 'Entradas',
		);
	}

	/** Alias de subtabs antiguas absorbidas por Configuración (deep-links compat). */
	public static function editor_subtab_aliases(): array
	{
		return array(
			'general'    => 'configuracion',
			'repeticion' => 'configuracion',
		);
	}

	/**
	 * Subtab de editor solicitada (parámetro canónico 'st'), con fallback seguro.
	 * Las subtabs históricas (general/repeticion) mapean a Configuración.
	 */
	public static function resolve_editor_subtab(): string
	{
		$req = sanitize_key( (string) ( $_GET['st'] ?? '' ) );
		$aliases = self::editor_subtab_aliases();
		if ( $req !== '' && isset( $aliases[ $req ] ) ) {
			return $aliases[ $req ];
		}
		return \Estandar\Core\Admin::resolve_ssub( self::editor_subtabs(), 'diseno', 'st' );
	}

	/**
	 * ¿Mostrar el bloque "Configuración heredada" (Forms legacy) bajo la lista de
	 * Forms? Oculta por defecto; recuperable vía option técnica o filtro.
	 */
	public static function show_legacy_config(): bool
	{
		if ( get_option( 'estandar_suite_capture_legacy_config_visible', '0' ) === '1' ) {
			return true;
		}
		return (bool) apply_filters( 'estandar_suite_capture_show_legacy_config', false );
	}

	/**
	 * ¿Mostrar el breadcrumb completo del editor ("← Formularios · {nombre}")?
	 * Oculta por defecto; el volver sigue disponible vía enlace compacto.
	 * Recuperable vía option técnica o filtro.
	 */
	public static function show_editor_breadcrumb(): bool
	{
		if ( get_option( 'estandar_suite_capture_editor_breadcrumb_visible', '0' ) === '1' ) {
			return true;
		}
		return (bool) apply_filters( 'estandar_suite_capture_show_editor_breadcrumb', false );
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Capture', 'Capture', 'manage_options', 'estandar-suite-capture', array( __CLASS__, 'render' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'forms' => array(
				'enabled'      => array( 'type' => 'bool', 'label' => 'Módulo de formularios habilitado', 'desc' => 'Activa el módulo de formularios en la suite.', 'default' => 1 ),
				'notify_email' => array( 'type' => 'text', 'label' => 'Email de notificación', 'desc' => 'Recibe aviso de nuevas entradas de formularios.', 'default' => '' ),
				'store_entries'=> array( 'type' => 'bool', 'label' => 'Almacenar entradas', 'desc' => 'Guarda cada envío en la tabla de entradas.', 'default' => 1 ),
			),
			'surveys' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de encuestas habilitado', 'desc' => 'Activa el módulo de encuestas en la suite.', 'default' => 1 ),
				'default_mode'   => array( 'type' => 'select', 'label' => 'Modo de identificación por defecto', 'desc' => 'Cómo se identifica al encuestado.', 'default' => 'anonymous', 'options' => array( 'anonymous' => 'Anónimo', 'identity' => 'Con identidad' ) ),
			),
			'postventa' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de postventa habilitado', 'desc' => 'Activa el módulo de postventa en la suite.', 'default' => 1 ),
				'default_channel'=> array( 'type' => 'select', 'label' => 'Canal de soporte por defecto', 'desc' => 'Canal principal de los casos de postventa.', 'default' => 'incident', 'options' => array( 'incident' => 'Incidente', 'soporte' => 'Soporte', 'postventa' => 'Postventa' ) ),
			),
			'chatbot' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de chatbot habilitado', 'desc' => 'Activa el módulo de chatbot en la suite.', 'default' => 1 ),
				'default_channel'=> array( 'type' => 'select', 'label' => 'Canal por defecto', 'desc' => 'Canal principal del chatbot.', 'default' => 'web', 'options' => array( 'web' => 'Web', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'api' => 'API' ) ),
			),
			'popups' => array(
				'enabled'      => array( 'type' => 'bool', 'label' => 'Módulo de popups habilitado', 'desc' => 'Activa el módulo de popups en la suite.', 'default' => 1 ),
				'track_events' => array( 'type' => 'bool', 'label' => 'Registrar views/opens/clicks', 'desc' => 'Anota la actividad de cada popup.', 'default' => 1 ),
			),
			'leads' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de leads habilitado', 'desc' => 'Activa la vista operativa de leads en la suite.', 'default' => 1 ),
				'default_status' => array( 'type' => 'select', 'label' => 'Estado inicial', 'desc' => 'Estado por defecto de un lead nuevo.', 'default' => 'new', 'options' => array( 'new' => 'New', 'contacted' => 'Contactado', 'qualified' => 'Calificado' ) ),
			),
		);
	}

	/** Data provider for a feature sub-tab (read-only legacy + v2). */
	public static function feature_data( string $name ): array
	{
		$data = array( 'forms' => array(), 'entries' => array(), 'by_form' => array(), 'incidents' => 0, 'surveys' => 0 );
		if ( in_array( $name, array( 'forms', 'surveys', 'postventa' ), true ) ) {
			$data['forms']     = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id DESC LIMIT 200' );
			$data['v2_forms']  = Forms::list();
			$data['entries']   = \Estandar\Core\Admin::legacy_rows( 'estandar_form_entries', 'ORDER BY id DESC LIMIT 500' );
			$data['responses'] = \Estandar\Core\Admin::legacy_rows( 'estandar_survey_responses', 'ORDER BY id DESC LIMIT 500' );
			$data['incidents'] = \Estandar\Core\Admin::legacy_count( 'estandar_incidents' );
			$data['surveys']   = \Estandar\Core\Admin::legacy_count( 'estandar_survey_responses' );
			if ( \Estandar\Core\Admin::legacy_table( 'estandar_form_entries' ) ) {
				global $wpdb;
				$rows = $wpdb->get_results( 'SELECT form_id, COUNT(*) AS n FROM ' . $wpdb->prefix . 'estandar_form_entries GROUP BY form_id', ARRAY_A );
				foreach ( (array) $rows as $r ) {
					$data['by_form'][ (int) $r['form_id'] ] = (int) $r['n'];
				}
			}
		} elseif ( $name === 'chatbot' ) {
			$data['conversations'] = \Estandar\Core\Admin::legacy_rows( 'ecb_conversations', 'ORDER BY id DESC LIMIT 30' );
			$data['leads']         = \Estandar\Core\Admin::legacy_rows( 'ecb_leads', 'ORDER BY id DESC LIMIT 30' );
			$data['events']        = \Estandar\Core\Admin::legacy_rows( 'ecb_events', 'ORDER BY id DESC LIMIT 30' );
			$data['logs']          = \Estandar\Core\Admin::legacy_rows( 'ecb_logs', 'ORDER BY id DESC LIMIT 30' );
		} elseif ( $name === 'popups' ) {
			$data['popups']    = self::popups();
			$data['v2_popups'] = Popups::list();
		} elseif ( $name === 'leads' ) {
			$data['leads']         = \Estandar\Core\Admin::legacy_rows( 'es_leads', 'ORDER BY id DESC LIMIT 50' );
			$data['submissions']   = \Estandar\Core\Admin::legacy_count( 'es_lead_submissions' );
			$data['lead_events']   = \Estandar\Core\Admin::legacy_count( 'es_lead_events' );
			$data['v2_submissions'] = \Estandar\Core\Admin::legacy_count( 'estandar_capture_submissions' );
			$data['customers']     = \Estandar\Core\Admin::legacy_rows( 'es_customers', 'ORDER BY id DESC LIMIT 30' );
			$data['operators']     = \Estandar\Core\Admin::legacy_rows( 'es_operators', 'ORDER BY id DESC LIMIT 30' );
			// Leads capturados por el chatbot (ecb_leads): viven en el área de
			// Leads para no duplicar la vista dentro del chatbot.
			$data['chatbot_leads'] = \Estandar\Core\Admin::legacy_rows( 'ecb_leads', 'ORDER BY id DESC LIMIT 30' );
		}
		return $data;
	}

	/** Popups are a CPT (post_type estandar_pop) — read-only list. */
	private static function popups(): array
	{
		$posts = get_posts( array(
			'post_type'      => 'estandar_pop',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 50,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		) );
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'views'  => (int) get_post_meta( $p->ID, '_ep_views', true ),
				'opens'  => (int) get_post_meta( $p->ID, '_ep_opens', true ),
				'clicks' => (int) get_post_meta( $p->ID, '_ep_clicks', true ),
			);
		}
		return $out;
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_capture_' . $feature . '_settings', \Estandar\Core\Admin::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&feature_saved=1' ) );
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

	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		$tabs = array(
			'estado'   => 'Estado',
			'config'   => 'Catchers',
			'datos'    => 'Datos',
			'settings' => 'Settings',
			'avanzado' => 'Avanzado',
		);
		$tab     = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'forms' => 'Forms', 'surveys' => 'Surveys', 'postventa' => 'Postventa', 'chatbot' => 'Chatbot', 'popups' => 'Popups', 'leads' => 'Leads', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción', 'historial' => 'Histórico' ),
			'settings' => array( 'capacidades' => 'Capacidades', 'modulos' => 'Módulos' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'capture', $tabs, $subs );
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'capture', $t, $s_list );
		}
		$tabs = $visible_tabs;
		if ( isset( $tabs['config'] ) ) {
			$subs['config']['visibilidad'] = 'Visibilidad';
		}
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ] ) : '';

		// ── Settings tab data: capability matrix + feature fields ──────────
		$settings_matrix = Forms::capability_matrix();
		$settings_types  = array( 'lead' => 'Formularios', 'survey' => 'Encuestas', 'incident' => 'Postventa' );
		$settings_features = array( 'forms', 'surveys', 'postventa', 'popups', 'leads' );
		$feature_fields_all = self::feature_fields();
		$legacy  = Adoption::legacy_present();
		$history = $legacy ? Adoption::historical( 50, 0 ) : array();
		$imported = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$rolled   = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;

		// ── Feature sub-tab data (forms/chatbot/popups/leads) ───────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && isset( $feature_fields[ $sub ] ) ) {
			$feature_view = self::feature_data( $sub );
		}

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'activate_schema'  => array( 'estandar_suite_capture_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_capture_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'Ingestion.php', 'desc' => 'Crea la tabla estandar_capture_submissions al activar.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Ingestion.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'columns', 'file' => 'Ingestion.php', 'desc' => 'Definición de columnas de la tabla de capturas.' ),
				array( 'fn' => 'activate_schema', 'file' => 'Forms.php', 'desc' => 'Crea la tabla estandar_capture_forms (forms v2) al activar.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Forms.php', 'desc' => 'Migra el esquema de forms v2 según versión guardada.' ),
				array( 'fn' => 'types', 'file' => 'Forms.php', 'desc' => 'Tipos de formulario extensibles (lead/incident/survey + filter).' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'ingest', 'file' => 'Ingestion.php', 'desc' => 'Ingesta normalizada de una captura.' ),
				array( 'fn' => 'list', 'file' => 'Forms.php', 'desc' => 'Lista forms v2 (opcionalmente por tipo).' ),
				array( 'fn' => 'get', 'file' => 'Forms.php', 'desc' => 'Lee un form v2 por id.' ),
				array( 'fn' => 'create', 'file' => 'Forms.php', 'desc' => 'Crea un form v2 (name requerido).' ),
				array( 'fn' => 'update', 'file' => 'Forms.php', 'desc' => 'Actualiza un form v2.' ),
				array( 'fn' => 'delete', 'file' => 'Forms.php', 'desc' => 'Elimina un form v2.' ),
				array( 'fn' => 'sanitize', 'file' => 'Forms.php', 'desc' => 'Sanitiza/defaults el payload de un form v2.' ),
				array( 'fn' => 'product_capabilities', 'file' => 'Forms.php', 'desc' => 'Catálogo de capacidades por tipo de producto (motor compartido).' ),
				array( 'fn' => 'contexts', 'file' => 'Forms.php', 'desc' => 'Lista cerrada de contextos de negocio (5 + otros).' ),
				array( 'fn' => 'context_label', 'file' => 'Forms.php', 'desc' => 'Etiqueta legible de un contexto.' ),
				array( 'fn' => 'duplicate', 'file' => 'Forms.php', 'desc' => 'Duplica un form (copia en borrador, slug único).' ),
				array( 'fn' => 'trash', 'file' => 'Forms.php', 'desc' => 'Mueve un form a la papelera (soft delete).' ),
				array( 'fn' => 'restore', 'file' => 'Forms.php', 'desc' => 'Restaura un form de la papelera.' ),
				array( 'fn' => 'list_trashed', 'file' => 'Forms.php', 'desc' => 'Lista los forms en papelera.' ),
				array( 'fn' => 'is_capability_enabled', 'file' => 'Forms.php', 'desc' => '¿Está habilitada una capacidad para un tipo?' ),
				array( 'fn' => 'set_capability', 'file' => 'Forms.php', 'desc' => 'Habilita/deshabilita una capacidad por tipo.' ),
				array( 'fn' => 'caps_overrides', 'file' => 'Forms.php', 'desc' => 'Overrides guardados de capacidades (por tipo).' ),
				array( 'fn' => 'normalize', 'file' => 'Ingestion.php', 'desc' => 'Normaliza una captura a la estructura canónica.' ),
				array( 'fn' => 'normalize_phone', 'file' => 'Ingestion.php', 'desc' => 'Normaliza el teléfono (formato consistente).' ),
				array( 'fn' => 'fallback_uuid', 'file' => 'Ingestion.php', 'desc' => 'Genera un UUID si la captura no trae uno.' ),
				array( 'fn' => 'row_for_insert', 'file' => 'Ingestion.php', 'desc' => 'Prepara la fila para insertar.' ),
				array( 'fn' => 'persist', 'file' => 'Ingestion.php', 'desc' => 'Guarda la captura en la tabla propia.' ),
				array( 'fn' => 'get', 'file' => 'Ingestion.php', 'desc' => 'Lee capturas recientes.' ),
				array( 'fn' => 'extract_click_ids', 'file' => 'Attribution.php', 'desc' => 'Extrae click IDs de la captura.' ),
				array( 'fn' => 'channel', 'file' => 'Attribution.php', 'desc' => 'Resuelve el canal de la captura.' ),
				array( 'fn' => 'has_click_evidence', 'file' => 'Attribution.php', 'desc' => 'Indica si hay evidencia de click.' ),
				array( 'fn' => 'fallback_click_ids', 'file' => 'Attribution.php', 'desc' => 'Click IDs de respaldo (cookies).' ),
				array( 'fn' => 'fallback_channel', 'file' => 'Attribution.php', 'desc' => 'Canal de respaldo.' ),
				array( 'fn' => 'fallback_evidence', 'file' => 'Attribution.php', 'desc' => 'Evidencia de respaldo para atribución.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta tablas legadas de leads/submissions.' ),
				array( 'fn' => 'project_submission', 'file' => 'Adoption.php', 'desc' => 'Proyecta una submission legada a la estructura nueva.' ),
				array( 'fn' => 'historical', 'file' => 'Adoption.php', 'desc' => 'Lista submissions históricas para previsualizar.' ),
				array( 'fn' => 'import', 'file' => 'Adoption.php', 'desc' => 'Importa submissions históricas (dry-run o commit).' ),
				array( 'fn' => 'rollback_import', 'file' => 'Adoption.php', 'desc' => 'Elimina solo los registros adoptados.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'ingest → estandar_suite_capture_event', 'file' => 'Ingestion.php', 'desc' => 'Emite el evento canónico de captura al persistir.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (POST /captures, GET /adoption/historical).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'ingest', 'file' => 'Cli.php', 'desc' => 'Ingesta una captura por CLI.' ),
				array( 'fn' => 'adoption_status', 'file' => 'Cli.php', 'desc' => 'Estado de la adopción de leads.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa leads históricos (--commit).' ),
				array( 'fn' => 'adoption_rollback', 'file' => 'Cli.php', 'desc' => 'Rollback de adoptados (--confirm=yes).' ),
				array( 'fn' => 'forms_list|legacy|create|update|delete|summary|entries|responses', 'file' => 'Cli.php', 'desc' => 'CRUD de forms v2 + legado + entradas/respuestas.' ),
				array( 'fn' => 'surveys_list|create|update|delete|responses|summary|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de encuestas (producto Surveys) + respuestas.' ),
				array( 'fn' => 'postventa_list|create|update|delete|entries|summary|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de casos de postventa + entradas.' ),
				array( 'fn' => 'products caps get|set', 'file' => 'Cli.php', 'desc' => 'Consulta/configura capacidades por tipo de producto.' ),
				array( 'fn' => 'chatbot_settings_groups', 'file' => 'Admin.php', 'desc' => 'Catálogo de settings del chatbot por sub-tab (6 grupos).' ),
				array( 'fn' => 'chatbot_settings_fields', 'file' => 'Admin.php', 'desc' => 'Settings del chatbot aplanados (CLI).' ),
				array( 'fn' => 'handle_save_chatbot_settings', 'file' => 'Admin.php', 'desc' => 'Handler admin_post: guarda un grupo de settings del chatbot (merge).' ),
				array( 'fn' => 'handle_save_caps_matrix', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar la matriz de capacidades.' ),
				array( 'fn' => 'handle_save_capabilities', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar capacidades por producto.' ),
				array( 'fn' => 'chatbot_list|summary|leads|logs', 'file' => 'Cli.php', 'desc' => 'Lista/resumen/leads/logs del chatbot.' ),
				array( 'fn' => 'popups_list|create|update|delete|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de popups v2.' ),
				array( 'fn' => 'handle_save_popup', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear/actualizar popup v2.' ),
				array( 'fn' => 'handle_delete_popup', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar popup v2.' ),
				array( 'fn' => 'leads_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de leads legados.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Capture bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Capture con sus 4 tabs.' ),
				array( 'fn' => 'feature_data', 'file' => 'Admin.php', 'desc' => 'Datos de los sub-tabs forms/chatbot/popups/leads (read-only legado).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'handle_save_form', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear/actualizar un form v2.' ),
				array( 'fn' => 'handle_delete_form', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar un form v2.' ),
				array( 'fn' => 'handle_import', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para import de adopción.' ),
				array( 'fn' => 'handle_rollback', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para rollback de adopción.' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature.' ),
				array( 'fn' => 'legacy_table', 'file' => 'Admin.php', 'desc' => 'Verifica que exista una tabla legada (delega en Core).' ),
				array( 'fn' => 'legacy_rows', 'file' => 'Admin.php', 'desc' => 'Lee filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_count', 'file' => 'Admin.php', 'desc' => 'Cuenta filas de una tabla legada (read-only).' ),
			),
		);

		include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/capture-admin.php';
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_capture_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de la tabla estandar_capture_submissions.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_adoption_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = Adoption::import( ! $commit, max( 1, min( 500, $limit ) ) );
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( $commit ? 'info' : 'debug', 'CAPTURE_ADOPTION', $commit ? 'Capture adoption committed.' : 'Capture adoption dry-run.', array( 'source_plugin' => 'estandar-capture', 'imported' => $r['imported'], 'dry_run' => ! $commit ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_adoption_rollback' );
		$n = Adoption::rollback_import();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&rolled=' . (int) $n ) );
		exit;
	}

	// ── Forms adoption (entries / survey responses): import + rollback ─────

	public static function handle_forms_entries_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_entries_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = FormsAdoption::import_entries( ! $commit, max( 1, min( 500, $limit ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_forms_entries_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_entries_rollback' );
		$n = FormsAdoption::rollback_entries();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_rolled=' . (int) $n ) );
		exit;
	}

	public static function handle_forms_responses_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_responses_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = FormsAdoption::import_responses( ! $commit, max( 1, min( 500, $limit ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_responses_imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_forms_responses_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_responses_rollback' );
		$n = FormsAdoption::rollback_responses();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_responses_rolled=' . (int) $n ) );
		exit;
	}

	/** Read-only status for the Forms adoption panel (origin/mode/usage). */
	public static function forms_adoption_summary(): array
	{
		return FormsAdoption::resource_counts();
	}

	/** Migrar un formulario legacy a nativo (dry-run por defecto). */
	public static function handle_migrate_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_migrate_form' );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		$commit  = ! empty( $_POST['commit'] );
		$r       = Forms::migrate_legacy( ! $commit, $form_id, true );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&migrated=' . (int) $r['migrated'] . '&migrated_commit=' . ( $commit ? 1 : 0 ) . '&migrated_existing=' . (int) $r['existing'] ) );
		exit;
	}

	/** Rollback de migración (solo nativos migrados/adoptados). */
	public static function handle_migrate_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_migrate_rollback' );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		$n       = Forms::rollback_migration( $form_id );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&migrated_rollback=' . (int) $n ) );
		exit;
	}

	/**
	 * Vista read-only de la configuración heredada de Estandar Forms.
	 * Muestra por formulario legacy: origen, estado, modo, utilización y
	 * config sensible enmascarada. Nunca edita, nunca duplica configuración.
	 */
	public static function render_legacy_forms_config(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id ASC LIMIT 200' );
		if ( ! $rows ) {
			echo '<p class="description">Sin formularios legacy (tabla <code>estandar_forms</code> no presente o vacía).</p>';
			return;
		}
		global $wpdb;
		$mask = static function ( string $key, $value ) {
			if ( $value === '' || $value === null ) {
				return '—';
			}
			if ( preg_match( '/(token|secret|password|private_?key|api[_-]?key|client_?secret)/i', $key ) ) {
				return '•••••• (oculto)';
			}
			$s = (string) $value;
			return strlen( $s ) > 120 ? substr( $s, 0, 120 ) . '…' : $s;
		};
		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;font-size:14px;">Configuración heredada — Estandar Forms (solo lectura)</h2></div>';
		echo '<div class="inside"><p class="description">Lee <code>estandar_forms</code> directamente. No se edita aquí; para administrar un formulario como nativo, mígralo a la Suite v2 (el original legacy queda intacto).</p>';

		if ( isset( $_GET['migrated'] ) ) {
			$commit = ! empty( $_GET['migrated_commit'] );
			$n      = (int) $_GET['migrated'];
			echo '<div class="notice notice-' . ( $commit ? 'success' : 'info' ) . ' is-dismissible"><p>Migración ' . ( $commit ? 'aplicada: ' : 'previsualizada: ' ) . '<strong>' . $n . '</strong> formulario(s). Ya existentes: <strong>' . (int) ( $_GET['migrated_existing'] ?? 0 ) . '</strong>. El legacy no se tocó.</p></div>';
		}
		if ( isset( $_GET['migrated_rollback'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Rollback: <strong>' . (int) $_GET['migrated_rollback'] . '</strong> formulario(s) nativo(s) migrado(s) eliminado(s).</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr><th>Origen</th><th>Estado</th><th>Modo</th><th>Utilización</th><th>Config (lectura)</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $rows as $f ) {
			$fid      = (int) $f['id'];
			$util     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'estandar_form_entries WHERE form_id = %d', $fid ) );
			$cfg_bits = array();
			foreach ( array( 'notify_emails' => 'notify', 'webhook_url' => 'webhook', 'send_email' => 'send_email', 'send_webhook' => 'send_webhook', 'require_recaptcha' => 'recaptcha', 'store_entries' => 'store_entries', 'success_message' => 'success' ) as $col => $label ) {
				if ( isset( $f[ $col ] ) && $f[ $col ] !== '' && $f[ $col ] !== 'null' ) {
					$cfg_bits[] = '<code>' . esc_html( $label ) . '=' . esc_html( $mask( $col, $f[ $col ] ) ) . '</code>';
				}
			}
			$mode = (string) ( $f['form_type'] ?? 'lead' );
			if ( ! empty( $f['request_type'] ) && $f['request_type'] !== 'null' ) {
				$mode .= ' / ' . (string) $f['request_type'];
			}
			$migrated  = Forms::is_migrated( $fid );
			$migratable = Forms::legacy_form_type_map( (string) ( $f['form_type'] ?? '' ) ) !== '';
			echo '<tr>'
				. '<td><code>estandar_forms#' . (int) $fid . '</code> ' . esc_html( (string) ( $f['name'] ?? '' ) ) . '</td>'
				. '<td>' . ( $migrated ? '<span style="color:#2271b1;">migrado a nativo</span>' : 'activo' ) . '</td>'
				. '<td>' . esc_html( $mode ) . '</td>'
				. '<td>' . (int) $util . ' entries</td>'
				. '<td>' . ( $cfg_bits ? implode( ' ', $cfg_bits ) : '—' ) . '</td>'
				. '<td>' . ( $migratable
					? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">
							' . wp_nonce_field( 'estandar_suite_capture_migrate_form', '_wpnonce', true, false ) . '
							<input type="hidden" name="action" value="estandar_suite_capture_migrate_form">
							<input type="hidden" name="form_id" value="' . $fid . '">
							<button class="button button-small" type="submit">' . ( $migrated ? 'Re-migrar' : 'Migrar a nativo' ) . '</button>
						</form>
						<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">
							' . wp_nonce_field( 'estandar_suite_capture_migrate_form', '_wpnonce', true, false ) . '
							<input type="hidden" name="action" value="estandar_suite_capture_migrate_form">
							<input type="hidden" name="form_id" value="' . $fid . '">
							<input type="hidden" name="commit" value="1">
							<button class="button button-small button-primary" type="submit">' . ( $migrated ? 'Re-migrar (commit)' : 'Migrar y aplicar' ) . '</button>
						</form>'
					: '<span class="description">fuera de alcance (survey/postventa)</span>' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;" onsubmit="return confirm(\'¿Eliminar los formularios nativos migrados? El legacy no se toca.\')">'
			. wp_nonce_field( 'estandar_suite_capture_migrate_rollback', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="estandar_suite_capture_migrate_rollback">'
			. '<button class="button" type="submit">Rollback de migración (solo nativos migrados)</button>'
			. '</form>';
		echo '</div></div>';
	}
}
