<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatbotFrontend — port del widget legacy (burbuja) a Capture v2.
 *
 * Reutiliza TAL CUAL los assets del plugin legacy (chatbot-loader.js, chatbot.js,
 * state-manager.js, decision-engine.js, chatbot.css) copiados a /frontend, y
 * reconstruye el contrato `chatbotConfig` que espera ese JS (idéntico al legacy)
 * leyendo los settings de Capture (`estandar_suite_capture_chatbot_settings`)
 * con fallback a las opciones legacy `chatbot_*` cuando el sitio las conserva.
 *
 * Los AJAX del legacy (`estandar_chatbot_ai`, `estandar_chatbot_runtime_nonce`,
 * `estandar_chatbot_get_conversation`, `estandar_chatbot_save_lead`) se re-registran
 * aquí delegando al motor v2 (AiChat / AiChatPrompt / Ingestion), de modo que el
 * widget portado funciona sin tocar una línea de JS.
 */
class ChatbotFrontend
{
	/** CHATBOT_LOG_RETENTION_V1: hook del cron diario de poda de logs. */
	const PRUNE_HOOK = 'estandar_capture_chatbot_prune';

	// ASSET_VERSION_V1: deriva de la versión del plugin (antes hardcodeada en
	// 13.0.193 → los assets del chatbot nunca invalidaban caché de navegador).
	public static function asset_version(): string
	{
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? \ESTANDAR_SUITE_CAPTURE_VERSION : '13.0.0';
		// ASSET_VERSION_MTIME_V1: incluir el mtime de chatbot.js para invalidar la
		// caché del navegador en cada cambio del chatbot (aunque no suba la versión).
		$file = __DIR__ . '/../frontend/chatbot.js';
		if ( is_readable( $file ) ) {
			$ver .= '.' . filemtime( $file );
		}
		return $ver;
	}

	public static function init(): void
	{
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'estandar_chatbot', array( __CLASS__, 'shortcode' ) );

		// AJAX legacy → motor v2.
		add_action( 'wp_ajax_estandar_chatbot_ai', array( __CLASS__, 'ajax_ai' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_ai', array( __CLASS__, 'ajax_ai' ) );
		// CHAT_STREAM_V1: mismo motor, salida progresiva (SSE). Fallback en el frontend.
		add_action( 'wp_ajax_estandar_chatbot_ai_stream', array( __CLASS__, 'ajax_ai_stream' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_ai_stream', array( __CLASS__, 'ajax_ai_stream' ) );
		add_action( 'wp_ajax_estandar_chatbot_runtime_nonce', array( __CLASS__, 'ajax_runtime_nonce' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_runtime_nonce', array( __CLASS__, 'ajax_runtime_nonce' ) );
		add_action( 'wp_ajax_estandar_chatbot_get_conversation', array( __CLASS__, 'ajax_get_conversation' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_get_conversation', array( __CLASS__, 'ajax_get_conversation' ) );
		add_action( 'wp_ajax_estandar_chatbot_save_lead', array( __CLASS__, 'ajax_save_lead' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_save_lead', array( __CLASS__, 'ajax_save_lead' ) );
		// LOG_CLIENT_EVENT_V1 (2026-09-11): el widget emite eventos de cliente
		// (apertura, click a WhatsApp, mensajes, lead mostrado). Antes no había
		// handler y se perdían; ahora se persisten como filas role=system.
		add_action( 'wp_ajax_estandar_chatbot_log_client_event', array( __CLASS__, 'ajax_log_client_event' ) );
		add_action( 'wp_ajax_nopriv_estandar_chatbot_log_client_event', array( __CLASS__, 'ajax_log_client_event' ) );
		// CHATBOT_LEAD_NOTIFY_V1: el lead del chatbot dispara las acciones del
		// proyecto (notificación al operador + webhook n8n + autorespuesta),
		// igual que un envío de formulario.
		add_action( 'estandar_suite_capture_event', array( __CLASS__, 'on_capture_notify' ), 30, 1 );

		// CHATBOT_LOG_RETENTION_V1: poda de logs/mensajes (WP-Cron diario).
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_logs' ) );
		add_action( 'init', array( __CLASS__, 'ensure_prune_schedule' ) );
		self::maybe_prune_logs();
		// CHATBOT_BRANDING_V1: migración inicial del branding legacy a v2 (1 vez).
		self::maybe_migrate_branding();

		// CACHE_PURGE_V1 (2026-10-06): al cambiar los settings del chatbot
		// (activar, páginas visibles, apariencia, contacto) se purga la caché de
		// página. Sin esto el toggle parece "no funcionar": la caché dinámica del
		// hosting sigue sirviendo un HTML con la burbuja aunque el bot esté
		// apagado (o al revés). Cubre cualquier vía de guardado (admin/CLI/código).
		add_action( 'update_option_' . AiChatPrompt::SETTINGS_OPTION, array( __CLASS__, 'purge_caches' ), 10, 3 );
		add_action( 'add_option_' . AiChatPrompt::SETTINGS_OPTION, array( __CLASS__, 'purge_caches' ), 10, 2 );
	}

	/**
	 * CACHE_PURGE_V1 (2026-10-06): purga la caché de página tras cambiar los
	 * settings del chatbot. Prefiere el purge total de SiteGround (dynamic cache
	 * + optimizer); cae a APIs genéricas. Idempotente y a prueba de ausencias.
	 */
	public static function purge_caches( $old_value = null, $value = null, $option = '' ): void
	{
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			@sg_cachepress_purge_everything();
		} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			@sg_cachepress_purge_cache();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		// Gancho para otras cachés (plugins de cache, CDN).
		do_action( 'estandar_suite_chatbot_purge_caches' );
	}

	/**
	 * CHATBOT_BRANDING_V1 (2026-09-21): copia una sola vez los colores del
	 * branding legacy (`chatbot_branding_config`) a la config v2, para que la
	 * pestaña Apariencia refleje el look real y sea editable. No cambia el look
	 * (los valores son los mismos); solo los hace visibles en el admin.
	 */
	public static function maybe_migrate_branding(): void
	{
		if ( get_option( 'estandar_chatbot_branding_migrated' ) ) {
			return;
		}
		update_option( 'estandar_chatbot_branding_migrated', '1', false );
		$legacy = get_option( 'chatbot_branding_config', array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}
		$s   = AiChatPrompt::settings();
		$map = array(
			'bot_bubble_color'  => 'branding_bot_bubble',
			'user_bubble_color' => 'branding_user_bubble',
			'button_color'      => 'branding_button_color',
			'bg_color'          => 'branding_bg_color',
			'text_color'        => 'branding_text_color',
			'bot_avatar'        => 'branding_avatar',
			'shadow_enabled'    => 'branding_shadow_enabled',
			'shadow_intensity'  => 'branding_shadow_intensity',
		);
		$changed = false;
		foreach ( $map as $lk => $vk ) {
			if ( ! isset( $s[ $vk ] ) && isset( $legacy[ $lk ] ) && $legacy[ $lk ] !== '' ) {
				$s[ $vk ] = $legacy[ $lk ];
				$changed  = true;
			}
		}
		if ( $changed ) {
			update_option( 'estandar_suite_capture_chatbot_settings', $s, false );
		}
	}

	/**
	 * CHATBOT_LOG_RETENTION_V1 (2026-09-21, WP-Cron 2026-09-27): si el admin
	 * define `logs_retention_days > 0`, elimina logs y mensajes de chat más
	 * antiguos. Default 90 días (higiene); 0 = no borra nada.
	 *
	 * El borrado corre por WP-Cron (`self::PRUNE_HOOK`, diario). `maybe_prune_logs`
	 * mantiene el respaldo oportunista (1 vez/día vía transient) para sitios con
	 * cron deshabilitado.
	 */
	public static function retention_days(): int
	{
		return max( 0, (int) ( AiChatPrompt::settings()['logs_retention_days'] ?? 90 ) );
	}

	/** Programa el cron diario de poda si la retención está activa. */
	public static function ensure_prune_schedule(): void
	{
		if ( self::retention_days() <= 0 ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' )
			&& ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			$when = function_exists( 'time' ) ? time() + HOUR_IN_SECONDS : 0;
			wp_schedule_event( $when, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Respaldo oportunista (1 vez/día) por si WP-Cron no dispara. Idempotente.
	 */
	public static function maybe_prune_logs(): void
	{
		if ( self::retention_days() <= 0 ) {
			return;
		}
		if ( get_transient( 'estandar_chatbot_prune_done' ) ) {
			return;
		}
		set_transient( 'estandar_chatbot_prune_done', 1, DAY_IN_SECONDS );
		self::prune_logs();
	}

	/** Ejecuta el borrado de logs/mensajes caducados (idempotente). */
	public static function prune_logs(): void
	{
		$days = self::retention_days();
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}estandar_capture_chat_logs WHERE created_at < %s", $cut ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}estandar_capture_chat_messages WHERE created_at < %s", $cut ) );
	}

	/** ¿Activo y visible para el usuario actual? (gate básico, igual que el
	 *  legacy `estandar_chatbot_is_visible_for_current_user`). El shortcode usa
	 *  este gate; la burbuja flotante usa además el filtro por páginas. */
	public static function is_visible(): bool
	{
		$s = AiChatPrompt::settings();
		$enabled = isset( $s['activar'] ) ? (int) $s['activar'] : 1;
		if ( ! $enabled ) {
			return false;
		}
		// CHATBOT_CLEANUP_V1 (2026-09-21): se retiró "solo_loggeados" (sin uso
		// real: los clientes no tienen usuarios WP). El bot se muestra a todos
		// cuando está activo.
		return true;
	}

	/** ¿Cargar la burbuja flotante en la página actual? Aplica la lista de
	 *  páginas visibles (allow-list), retrocompatible con la opción legacy
	 *  `chatbot_paginas_visibles`. El shortcode siempre fuerza la carga. */
	public static function should_load_floating(): bool
	{
		if ( ! self::is_visible() ) {
			return false;
		}
		if ( self::current_page_has_shortcode() ) {
			return true;
		}
		$paginas = self::visible_pages();
		if ( empty( $paginas ) ) {
			return true; // sin restricción = todo el sitio.
		}
		return ! empty( array_intersect( self::current_view_page_ids(), $paginas ) );
	}

	/** Páginas donde se muestra la burbuja (settings v2 con fallback legacy). */
	public static function visible_pages(): array
	{
		$s = AiChatPrompt::settings();
		$pages = isset( $s['paginas_visibles'] ) && is_array( $s['paginas_visibles'] ) ? $s['paginas_visibles'] : get_option( 'chatbot_paginas_visibles', array() );
		$pages = is_array( $pages ) ? $pages : array();
		return array_values( array_unique( array_filter( array_map( 'intval', $pages ) ) ) );
	}

	/** ¿La página actual contiene el shortcode [estandar_chatbot]? */
	public static function current_page_has_shortcode(): bool
	{
		if ( ! is_singular() ) {
			return false;
		}
		global $post;
		if ( ! ( $post instanceof \WP_Post ) ) {
			return false;
		}
		return has_shortcode( (string) $post->post_content, 'estandar_chatbot' );
	}

	/** IDs de la vista actual: singular + front page + blog page. */
	public static function current_view_page_ids(): array
	{
		$ids = array();
		if ( is_singular() ) {
			$ids[] = (int) get_queried_object_id();
		}
		if ( is_front_page() ) {
			$front = (int) get_option( 'page_on_front', 0 );
			if ( $front > 0 ) {
				$ids[] = $front;
			}
		}
		if ( is_home() ) {
			$posts = (int) get_option( 'page_for_posts', 0 );
			if ( $posts > 0 ) {
				$ids[] = $posts;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	public static function enqueue(): void
	{
		if ( ! self::should_load_floating() ) {
			return;
		}
		$base = plugin_dir_url( dirname( __FILE__ ) );

		wp_enqueue_style(
			'estandar-chatbot-frontend-style',
			$base . 'frontend/chatbot.css',
			array(),
			self::asset_version()
		);

		wp_enqueue_script(
			'estandar-chatbot-frontend-script',
			$base . 'frontend/chatbot-loader.js',
			array(),
			self::asset_version(),
			true
		);

		wp_localize_script( 'estandar-chatbot-frontend-script', 'chatbotConfig', self::config() );
	}

	/** Shortcode [estandar_chatbot] — modo inline/widget, mismo contrato. */
	public static function shortcode( $atts = array() ): string
	{
		if ( ! self::is_visible() ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'mode'    => 'inline',
			'height'  => 680,
			'context' => '',
		), (array) $atts, 'estandar_chatbot' );

		$mode = sanitize_key( (string) $atts['mode'] );
		if ( ! in_array( $mode, array( 'inline', 'widget' ), true ) ) {
			$mode = 'inline';
		}
		$height = min( 1100, max( 420, (int) $atts['height'] ) );
		$context = sanitize_key( (string) $atts['context'] );

		return sprintf(
			'<div class="estandar-chatbot-shortcode" data-ecb-shortcode="1" data-mode="%1$s" data-height="%2$d"%3$s></div>',
			esc_attr( $mode ),
			$height,
			$context !== '' ? ' data-context-slug="' . esc_attr( $context ) . '"' : ''
		);
	}

	/** Construye el objeto `chatbotConfig` (contrato legacy). */
	public static function config(): array
	{
		$s    = AiChatPrompt::settings();
		$base = plugin_dir_url( dirname( __FILE__ ) );

		$get = function ( $key, $default = null ) use ( $s ) {
			return array_key_exists( $key, $s ) ? $s[ $key ] : get_option( $key, $default );
		};

		$branding = $get( 'chatbot_branding_config', array() );
		if ( ! is_array( $branding ) ) {
			$branding = array();
		}
		// CHATBOT_BRANDING_V1: overrides v2 (si están definidos) ganan sobre el legacy.
		$v2b = array(
			'bot_bubble_color'  => (string) ( $s['branding_bot_bubble'] ?? '' ),
			'user_bubble_color' => (string) ( $s['branding_user_bubble'] ?? '' ),
			'button_color'      => (string) ( $s['branding_button_color'] ?? '' ),
			'bg_color'          => (string) ( $s['branding_bg_color'] ?? '' ),
			'text_color'        => (string) ( $s['branding_text_color'] ?? '' ),
			'bot_avatar'        => (string) ( $s['branding_avatar'] ?? '' ),
		);
		foreach ( $v2b as $bk => $bv ) {
			if ( $bv !== '' ) {
				$branding[ $bk ] = $bv;
			}
		}
		if ( isset( $s['branding_shadow_enabled'] ) && $s['branding_shadow_enabled'] !== '' ) {
			$branding['shadow_enabled'] = ! empty( $s['branding_shadow_enabled'] ) ? 1 : 0;
		}
		if ( isset( $s['branding_shadow_intensity'] ) && $s['branding_shadow_intensity'] !== '' ) {
			$branding['shadow_intensity'] = (int) $s['branding_shadow_intensity'];
		}
		$branding = array_merge( array(
			'bg_color'          => '#ffffff',
			'bot_bubble_color'  => '#f3f4f6',
			'user_bubble_color' => '#e8f0fe',
			'button_color'      => '#2563eb',
			'text_color'        => '#111827',
			'background_image'  => '',
			'bot_avatar'        => '',
			'shadow_enabled'    => 1,
			'shadow_intensity'  => 6,
		), $branding );

		$model = (string) ( $s['openai_model'] ?? '' );
		if ( $model === '' ) {
			$model = AiChat::default_model();
		}

		// UX_CHATBOT_CONTEXT_V1 (P0.0): contexto de la página/proyecto actual para
		// el saludo contextual y los chips por defecto. Usa el mismo registry y
		// matcher que el backend de leads (sin duplicar lógica).
		$current_url = function_exists( 'home_url' ) ? home_url( add_query_arg( array() ) ) : '';
		$registry    = AiChatPrompt::project_registry();
		$match       = $current_url !== '' ? AiChatPrompt::match_project_for_url( $current_url, $registry ) : array();
		$current_project = array(
			'slug' => (string) ( $match['project_slug'] ?? $match['slug'] ?? '' ),
			'name' => (string) ( $match['name'] ?? $match['label'] ?? $match['project_name'] ?? '' ),
		);

		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'estandar_chatbot_nonce' ),
			'cssUrl'  => $base . 'frontend/chatbot.css?ver=' . rawurlencode( self::asset_version() ),
			'scriptUrls' => array(
				'stateManager'   => $base . 'frontend/state-manager.js?ver=' . rawurlencode( self::asset_version() ),
				'decisionEngine' => $base . 'frontend/decision-engine.js?ver=' . rawurlencode( self::asset_version() ),
				'chatbot'        => $base . 'frontend/chatbot.js?ver=' . rawurlencode( self::asset_version() ),
			),
			'activo'  => true,
			'siteName' => self::brand_label(),
			'botName' => (string) ( $s['nombre'] ?? get_option( 'chatbot_nombre', 'Asistente' ) ),
			'mostrarNombre' => (int) ( $s['mostrar_nombre'] ?? get_option( 'chatbot_mostrar_nombre', 1 ) ) === 1,
			'botones' => $get( 'chatbot_botones_iniciales', array() ),
			'idioma'  => (string) ( $s['idioma'] ?? 'es' ),
			'leadCaptureEnabled' => (int) ( $s['lead_capture_enabled'] ?? get_option( 'chatbot_lead_capture_enabled', 1 ) ) === 1,
			// CHAT_CAPTURE_FIELDS_V1: campos que el widget/flujo piden y capturan.
			'captureFields' => self::capture_fields(),
			// CHAT_STREAM_V1: el frontend intenta respuesta progresiva (con fallback).
			'streamEnabled' => AiChatPrompt::stream_enabled(),
			'autoOpen' => array(
				'enabled' => (int) ( $s['auto_open_enabled'] ?? get_option( 'chatbot_auto_open_enabled', 0 ) ) === 1,
				'type'    => (string) ( $s['auto_open_type'] ?? get_option( 'chatbot_auto_open_type', 'time' ) ),
				'value'   => (int) ( $s['auto_open_value'] ?? get_option( 'chatbot_auto_open_value', 6 ) ),
			),
			'leadIntroText' => (string) ( $s['lead_intro_text'] ?? get_option( 'chatbot_lead_intro_text', 'Te ayudo rápidamente. Primero dime qué necesitas y te conecto con el equipo correcto del proyecto.' ) ),
			'leadFields'    => $get( 'chatbot_lead_fields', array() ),
			'leadIntents'   => $get( 'chatbot_lead_intents', array() ),
			'hideMenuOnClick' => (int) ( $s['hide_menu_on_click'] ?? get_option( 'chatbot_hide_menu_on_click', 1 ) ) === 1,
			'humanEscalationEnabled' => (int) ( $s['human_escalation_enabled'] ?? get_option( 'chatbot_human_escalation_enabled', 1 ) ) === 1,
			'humanEscalationText'    => (string) ( $s['human_escalation_text'] ?? get_option( 'chatbot_human_escalation_text', 'Si prefieres, te conecto ahora mismo con un ejecutivo del proyecto.' ) ),
			'humanEscalationMode'    => (string) ( $s['human_escalation_mode'] ?? get_option( 'chatbot_human_escalation_mode', 'lead_form' ) ),
			'humanEscalationWhatsAppNumber'  => (string) ( $s['human_whatsapp_number'] ?? get_option( 'chatbot_human_whatsapp_number', '' ) ),
			'humanEscalationWhatsAppMessage' => (string) ( $s['human_escalation_whatsapp_message'] ?? get_option( 'chatbot_human_whatsapp_message', 'Hola, quiero hablar con un ejecutivo del proyecto. Dejé mis datos en la web.' ) ),
			// PROJECT_FOCUS_V1: registry real (v2 Core o legacy) para que el
			// widget resuelva el proyecto de la página y lo envíe al backend.
			'projectRegistry' => $registry,
			// UX_CHATBOT_CONTEXT_V1 (P0.0): proyecto de la página actual + URL,
			// para saludo contextual, chips y mensajes de WhatsApp.
			'currentProject'  => $current_project,
			'currentUrl'      => function_exists( 'esc_url_raw' ) ? esc_url_raw( $current_url ) : $current_url,
			// UX_CHATBOT_CHIPS_V1 (P0.1): chips por defecto si el admin no definió
			// botones. Configurable por la option `chatbot_default_chips`.
			'defaultChips'    => self::default_chips(),
			// UX_CHATBOT_DEBUG_V1 (P2.9): silencia logs de consola salvo debug.
			'debug'           => (int) ( $s['debug'] ?? get_option( 'chatbot_debug', 0 ) ) === 1,

			'capabilities' => array(
				'enableWebsiteDemo'      => (int) get_option( 'chatbot_enable_website_demo', 0 ) === 1,
				'requiresDisambiguation' => (int) get_option( 'chatbot_requires_disambiguation', 0 ) === 1,
			),
			'businessContext' => array(
				'industry'        => (string) ( $s['business_industry'] ?? get_option( 'chatbot_business_industry', 'servicios' ) ),
				'goal'            => (string) ( $s['business_goal'] ?? get_option( 'chatbot_business_goal', 'captar contactos' ) ),
				'primaryCta'      => (string) ( $s['business_primary_cta'] ?? get_option( 'chatbot_business_primary_cta', 'Quiero avanzar' ) ),
				'sensitiveTopics' => (string) ( $s['business_sensitive_topics'] ?? get_option( 'chatbot_business_sensitive_topics', 'precio, plazos, disponibilidad' ) ),
				'tone'            => (string) ( $s['business_tone'] ?? get_option( 'chatbot_business_tone', 'claro y profesional' ) ),
			),
			'postLeadMessage' => (string) ( $s['post_lead_message'] ?? get_option( 'chatbot_post_lead_message', "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp." ) ),
			'postLeadActions' => $get( 'chatbot_post_lead_actions', array(
				array( 'action' => 'whatsapp', 'label' => 'Hablar por WhatsApp', 'enabled' => 1 ),
				array( 'action' => 'deferred', 'label' => 'Prefiero que me contacten', 'enabled' => 1 ),
				array( 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ),
			) ),
			'cta' => $get( 'chatbot_cta_labels', array(
				'default'   => 'Ver más',
				'pagar'     => 'Pagar ahora',
				'precio'    => 'Ver precios',
				'cotizar'   => 'Cotizar ahora',
				'contratar' => 'Contratar ahora',
			) ),
			'behaviorCopy' => $get( 'chatbot_behavior_copy', array() ),
			'theme' => array(
				'accent'         => (string) ( $s['theme_accent'] ?? get_option( 'chatbot_theme_accent', '#111827' ) ),
				'visualStyle'    => (string) ( $s['visual_style'] ?? get_option( 'chatbot_visual_style', 'default' ) ),
				'shadowEnabled'  => (int) get_option( 'chatbot_theme_shadow_enabled', 1 ) === 1,
				'userBubble'     => (string) get_option( 'chatbot_theme_user_bubble', '#111827' ),
				'botBubble'      => (string) get_option( 'chatbot_theme_bot_bubble', '#ffffff' ),
				'radius'         => (int) ( $s['bubble_radius'] ?? get_option( 'chatbot_theme_radius', 22 ) ),
				'bubbleShape'    => (string) ( $s['bubble_shape'] ?? get_option( 'chatbot_theme_bubble_shape', 'rounded' ) ),
				'position'       => (string) ( $s['theme_position'] ?? get_option( 'chatbot_theme_position', 'right' ) ),
				'bubbleSize'     => (int) ( $s['bubble_size'] ?? get_option( 'chatbot_bubble_size', 58 ) ),
			),
			'branding' => $branding,
			'posicion' => array(
				'modo'   => (string) ( $s['theme_position'] ?? get_option( 'chatbot_theme_position', 'right' ) ),
				'bottom' => (int) get_option( 'chatbot_position_bottom', 22 ),
				'left'   => (string) get_option( 'chatbot_position_left', '' ),
				'right'  => (string) get_option( 'chatbot_position_right', '' ),
			),
			'inlineFeatures' => array(
				'sidebar'       => (int) ( $s['inline_sidebar_enabled'] ?? get_option( 'chatbot_inline_sidebar_enabled', 0 ) ) === 1,
				'fixedComposer' => (int) get_option( 'chatbot_inline_fixed_composer_enabled', 0 ) === 1,
				'fullpage'      => (int) get_option( 'chatbot_inline_fullpage_enabled', 0 ) === 1,
			),
			'bubbleImageUrl' => self::bubble_image_url( $s ),
			'bubbleTooltip'  => __( '¿Necesitas ayuda?', 'estandar-suite-capture' ),
			'leadConfirmMsg' => __( '¡Gracias! Hemos recibido tu información. Te contactaremos pronto.', 'estandar-suite-capture' ),
			'errorFriendly'  => array(
				'ai_fail'   => __( 'No pude procesar tu mensaje en este momento. ¿Quieres intentar de nuevo?', 'estandar-suite-capture' ),
				'lead_fail' => __( 'No pude registrar tus datos. ¿Quieres intentarlo nuevamente?', 'estandar-suite-capture' ),
				'offline'   => __( 'En este momento no tengo conexión. Puedes intentar en unos minutos o usar los botones disponibles.', 'estandar-suite-capture' ),
			),
			'flowEnabled' => (int) get_option( 'chatbot_flow_enabled', 0 ) === 1,
			'flowJson'    => (string) get_option( 'chatbot_flow_json', '' ),
			// CHATBOT_WHATSAPP_REDIRECT_V1: ruta v2 de la Suite. Antes apuntaba a
			// la ruta legacy `estandar-leads/v1/whatsapp-redirect`, retirada con
			// el legacy → devolvía rest_no_route (404) al pulsar "Hablar por
			// WhatsApp". Ver Rest::whatsapp_redirect.
			'whatsappRedirectUrl' => rest_url( 'estandar-suite/capture/v1/whatsapp-redirect' ),
			'operationalHours'    => array(),
		);
	}

	/**
	 * UX_CHATBOT_CHIPS_V1 (P0.1): chips/opciones por defecto cuando el admin no
	 * configuró `botones`. Configurable vía la option `chatbot_default_chips`
	 * (array de etiquetas o de {label,id}); vacío = no forzar chips.
	 *
	 * @return array<int,array{label:string,id:string}>
	 */
	private static function default_chips(): array
	{
		$raw = get_option( 'chatbot_default_chips', array( 'Comprar', 'Arrendar', 'Terrenos', 'Hablar con un ejecutivo' ) );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$label = (string) ( $item['label'] ?? $item['texto'] ?? $item['text'] ?? '' );
				$id    = (string) ( $item['id'] ?? $item['value'] ?? $item['payload'] ?? '' );
			} else {
				$label = (string) $item;
				$id    = '';
			}
			$label = trim( $label );
			if ( $label === '' ) {
				continue;
			}
			if ( $id === '' ) {
				$id = strtolower( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) ) );
				$id = trim( (string) $id, '-' );
			}
			$out[] = array( 'label' => $label, 'id' => $id );
		}
		return $out;
	}

	/** AJAX: motor IA (contrato legacy {success, data:{answer, session_id}}). */
	public static function ajax_ai(): void
	{
		check_ajax_referer( 'estandar_chatbot_nonce', 'nonce', false );

		// CHAT_WIDGET_GUARD_V1: protección del widget (rate por IP + presupuesto
		// diario). Default ON (opt-out con `ajax_guard_enabled=0`); el filtro de
		// salida `reply_is_clean` se aplica siempre (seguridad).
		$client_ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( AiChatPrompt::widget_guard_enabled() ) {
			if ( ! AiChatPrompt::chat_rate_ok( $client_ip ) ) {
				wp_send_json_error( array( 'message' => 'Demasiadas solicitudes. Intenta nuevamente en un minuto.' ), 429 );
			}
			if ( ! AiChatPrompt::chat_budget_ok() ) {
				wp_send_json_success( array(
					'answer'     => 'En este momento no puedo responder con IA. ¿Quieres que te contacte una persona del equipo?',
					'session_id' => sanitize_key( (string) ( $_POST['session_id'] ?? '' ) ),
					'budget'     => true,
				) );
			}
		}

		$message = trim( sanitize_textarea_field( (string) ( $_POST['message'] ?? '' ) ) );
		if ( $message === '' ) {
			wp_send_json_error( array( 'message' => 'Mensaje vacío.' ), 400 );
		}
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 );

		$history = array();
		if ( ! empty( $_POST['history'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['history'] ), true );
			if ( is_array( $decoded ) ) {
				$history = array_slice( $decoded, -12 );
			}
		}

		// PROJECT_FOCUS_V1: el widget envía page_url y context_slug; se usan para
		// enfocar el prompt en el proyecto de la página (multi-proyecto).
		$messages = array( array(
			'role'    => 'system',
			'content' => AiChatPrompt::system(
				(string) ( $_POST['page_url'] ?? '' ),
				(string) ( $_POST['context_slug'] ?? '' ),
				$message
			),
		) );
		foreach ( $history as $h ) {
			if ( ! isset( $h['role'], $h['content'] ) ) {
				continue;
			}
			$role = sanitize_key( (string) $h['role'] );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array( 'role' => $role, 'content' => sanitize_textarea_field( (string) $h['content'] ) );
		}
		// PROJECT_FOCUS_V1: nota de proyecto junto al turno del usuario (más
		// efectiva que la instrucción en el system prompt con prompts largos).
		$user_content = $message;
		$page_note    = AiChatPrompt::page_context_note(
			(string) ( $_POST['page_url'] ?? '' ),
			(string) ( $_POST['context_slug'] ?? '' )
		);
		if ( $page_note !== '' ) {
			$user_content = $page_note . "\n\n" . $message;
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_content );

		$r = AiChat::chat( $messages, AiChatPrompt::chat_opts() );
		if ( ! empty( $r['error'] ) ) {
			// CHAT_FALLBACK_V2 (2026-09-22): cuando la IA falla NO se secuestra la
			// conversación pidiendo el correo. Solo se usa la captación
			// determinística si el visitante YA dejó email/teléfono (para
			// completar el lead); si no, se responde con un aviso amable. En
			// ambos casos el log se marca como `fallback` (antes quedaba como
			// `ok` y ocultaba el problema).
			// CHAT_SESSION_KEY_V1: clave derivada del servidor (anti-spoof).
			$session_key = AiChatPrompt::server_session_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ), 'web', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

			$blob = $message;
			foreach ( $history as $h ) {
				if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
					$blob .= ' ' . (string) $h['content'];
				}
			}
			$has_contact = (bool) preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $blob )
				|| (bool) preg_match( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', $blob );

			if ( $has_contact && class_exists( __NAMESPACE__ . '\\Ingestion' ) ) {
				$det       = self::deterministic_lead_reply( $message, $history, $session_key );
				$content   = (string) $det['content'];
				$lead_id   = (int) ( $det['lead_id'] ?? 0 );
			} else {
				$content = 'No pude procesar tu mensaje en este momento. ¿Quieres intentarlo de nuevo o prefieres que te contacte una persona del equipo?';
				$lead_id = 0;
			}

			ChatConversation::append( $session_key, 'web', 'user', $message );
			ChatConversation::append( $session_key, 'web', 'assistant', $content );
			ChatLog::log( $session_key, 'web', $message, $content, ChatLog::RESULT_FALLBACK );

			wp_send_json_success( array(
				'answer'       => $content,
				'session_id'   => $session_key,
				'deterministic'=> true,
				'fallback'     => true,
				'lead_id'      => $lead_id,
			) );
		}

		// CHAT_SESSION_KEY_V1: clave derivada del servidor (anti-spoof).
		$session_key = AiChatPrompt::server_session_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ), 'web', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		// CHAT_HARDEN_V1: filtro de salida (no exponer prompt/instrucciones),
		// igual que el REST /chat.
		$answer = (string) ( $r['content'] ?? '' );
		if ( $answer !== '' && ! AiChatPrompt::reply_is_clean( $answer ) ) {
			$answer = 'Prefiero no compartir esa información. ¿En qué más puedo ayudarte?';
		}
		$r['content'] = $answer;

		// Persistir mensajes (Sprint 3): tabla capture chat.
		ChatConversation::append( $session_key, 'web', 'user', $message );
		ChatConversation::append( $session_key, 'web', 'assistant', (string) $r['content'] );
		// Log de interacción (Sprint 5): fricción + KPIs.
		ChatLog::log( $session_key, 'web', $message, (string) $r['content'] );

		// CHAT_LEAD_V1 (GENÉRICO): si el visitante dejó email/teléfono en la
		// conversación, persistir el lead una sola vez por sesión (best-effort).
		$user_msgs = array( $message );
		foreach ( $history as $h ) {
			if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
				$user_msgs[] = (string) $h['content'];
			}
		}
		self::capture_lead_from_conversation( $session_key, $user_msgs, (string) ( $_POST['page_url'] ?? '' ) );

		wp_send_json_success( array(
			'answer'     => (string) $r['content'],
			'session_id' => $session_key,
		) );
	}

	/**
	 * CHAT_STREAM_V1 (2026-09-27): variante streaming del motor IA (SSE).
	 * Emite `delta` (fragmentos) y un `final` con la respuesta canónica (ya
	 * filtrada y con el lead capturado). Reutiliza el mismo prompt/guardas/
	 * persistencia que `ajax_ai`, de modo que el resultado final es idéntico.
	 * Si el proveedor falla, emite el mismo fallback determinístico.
	 */
	public static function ajax_ai_stream(): void
	{
		check_ajax_referer( 'estandar_chatbot_nonce', 'nonce', false );

		// Cabeceras SSE sin buffering (para que cada delta salga al instante).
		@header( 'Content-Type: text/event-stream; charset=utf-8' );
		@header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		@header( 'X-Accel-Buffering: no' );
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'implicit_flush', '1' );
		while ( ob_get_level() > 0 ) { @ob_end_flush(); }

		$emit = static function ( string $type, array $data ): void {
			echo 'data: ' . wp_json_encode( array_merge( array( 'type' => $type ), $data ) ) . "\n\n";
			if ( function_exists( 'ob_flush' ) ) { @ob_flush(); }
			@flush();
		};

		$client_ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( AiChatPrompt::widget_guard_enabled() ) {
			if ( ! AiChatPrompt::chat_rate_ok( $client_ip ) ) {
				$emit( 'final', array( 'answer' => 'Demasiadas solicitudes. Intenta nuevamente en un minuto.', 'session_id' => '' ) );
				exit;
			}
			if ( ! AiChatPrompt::chat_budget_ok() ) {
				$emit( 'final', array( 'answer' => 'En este momento no puedo responder con IA. ¿Quieres que te contacte una persona del equipo?', 'session_id' => '', 'budget' => true ) );
				exit;
			}
		}

		$message = trim( sanitize_textarea_field( (string) ( $_POST['message'] ?? '' ) ) );
		if ( $message === '' ) {
			$emit( 'final', array( 'answer' => 'Mensaje vacío.', 'session_id' => '' ) );
			exit;
		}
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 );

		$history = array();
		if ( ! empty( $_POST['history'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['history'] ), true );
			if ( is_array( $decoded ) ) {
				$history = array_slice( $decoded, -12 );
			}
		}

		$messages = array( array(
			'role'    => 'system',
			'content' => AiChatPrompt::system(
				(string) ( $_POST['page_url'] ?? '' ),
				(string) ( $_POST['context_slug'] ?? '' ),
				$message
			),
		) );
		foreach ( $history as $h ) {
			if ( ! isset( $h['role'], $h['content'] ) ) {
				continue;
			}
			$role = sanitize_key( (string) $h['role'] );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array( 'role' => $role, 'content' => sanitize_textarea_field( (string) $h['content'] ) );
		}
		$user_content = $message;
		$page_note    = AiChatPrompt::page_context_note(
			(string) ( $_POST['page_url'] ?? '' ),
			(string) ( $_POST['context_slug'] ?? '' )
		);
		if ( $page_note !== '' ) {
			$user_content = $page_note . "\n\n" . $message;
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_content );

		$session_key = AiChatPrompt::server_session_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ), 'web', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		// Emitir cada fragmento en vivo.
		$r = AiChat::chat_stream( $messages, static function ( $delta ) use ( $emit ) {
			$emit( 'delta', array( 't' => (string) $delta ) );
		}, AiChatPrompt::chat_opts() );

		if ( ! empty( $r['error'] ) ) {
			$blob = $message;
			foreach ( $history as $h ) {
				if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
					$blob .= ' ' . (string) $h['content'];
				}
			}
			$has_contact = (bool) preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $blob )
				|| (bool) preg_match( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', $blob );
			if ( $has_contact && class_exists( __NAMESPACE__ . '\\Ingestion' ) ) {
				$det     = self::deterministic_lead_reply( $message, $history, $session_key );
				$content = (string) $det['content'];
				$lead_id = (int) ( $det['lead_id'] ?? 0 );
			} else {
				$content = 'No pude procesar tu mensaje en este momento. ¿Quieres intentarlo de nuevo o prefieres que te contacte una persona del equipo?';
				$lead_id = 0;
			}
			ChatConversation::append( $session_key, 'web', 'user', $message );
			ChatConversation::append( $session_key, 'web', 'assistant', $content );
			ChatLog::log( $session_key, 'web', $message, $content, ChatLog::RESULT_FALLBACK );
			$emit( 'final', array( 'answer' => $content, 'session_id' => $session_key, 'fallback' => true, 'lead_id' => $lead_id ) );
			exit;
		}

		$content = (string) ( $r['content'] ?? '' );
		if ( $content !== '' && ! AiChatPrompt::reply_is_clean( $content ) ) {
			$content = 'Prefiero no compartir esa información. ¿En qué más puedo ayudarte?';
		}

		ChatConversation::append( $session_key, 'web', 'user', $message );
		ChatConversation::append( $session_key, 'web', 'assistant', $content );
		ChatLog::log( $session_key, 'web', $message, $content );

		$user_msgs = array( $message );
		foreach ( $history as $h ) {
			if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
				$user_msgs[] = (string) $h['content'];
			}
		}
		self::capture_lead_from_conversation( $session_key, $user_msgs, (string) ( $_POST['page_url'] ?? '' ) );

		$emit( 'final', array( 'answer' => $content, 'session_id' => $session_key ) );
		exit;
	}

	/** AJAX: nonce de runtime (contrato legacy). */
	public static function ajax_runtime_nonce(): void	{
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'estandar_chatbot_nonce' ), 'expires' => time() + HOUR_IN_SECONDS ) );
	}

	/** AJAX: historial de una sesión (contrato legacy). */
	public static function ajax_get_conversation(): void
	{
		check_ajax_referer( 'estandar_chatbot_nonce', 'nonce', false );
		$session_key = AiChatPrompt::server_session_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ), 'web', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( $session_key === '' ) {
			wp_send_json_success( array( 'messages' => array() ) );
		}
		$messages = array();
		foreach ( ChatConversation::get_messages( $session_key, 'web', 20 ) as $m ) {
			$messages[] = array( 'role' => sanitize_key( (string) $m['role'] ), 'content' => (string) $m['content'] );
		}
		wp_send_json_success( array( 'messages' => $messages ) );
	}

	/** AJAX: guardar lead (contrato legacy) — Sprint 4. */
	public static function ajax_save_lead(): void
	{
		check_ajax_referer( 'estandar_chatbot_nonce', 'nonce', false );
		$lead   = self::normalize_lead_payload( $_POST );
		$ingest = self::build_lead_ingest( $lead );

		if ( class_exists( 'Estandar\\Capture\\Ingestion' ) ) {
			$result = Ingestion::ingest( $ingest );
			if ( ! empty( $result['id'] ) ) {
				self::mark_lead_captured( (string) ( $ingest['session_id'] ?? '' ) );
				wp_send_json_success( array( 'lead_id' => (int) ( $result['capture']['id'] ?? $result['id'] ), 'status' => 'created' ) );
			}
		}

		wp_send_json_error( array( 'message' => 'No pude registrar tus datos.' ), 500 );
	}

	/**
	 * SAVE_LEAD_FLAT_V1: normaliza el payload del lead. El widget envía los
	 * campos PLANOS (intent, requirement, conversation_summary,
	 * source_page_url, message, …) además de los `lead[...]`. Se leen ambos y
	 * se hace merge SIN pisar lo que venga dentro de `lead`/`payload`.
	 * Pura y testeable.
	 */
	public static function normalize_lead_payload( array $post ): array
	{
		$payload = isset( $post['lead'] ) && is_array( $post['lead'] )
			? $post['lead']
			: ( isset( $post['payload'] ) && is_array( $post['payload'] ) ? $post['payload'] : array() );

		$lead = array();
		if ( is_array( $payload ) ) {
			foreach ( $payload as $k => $v ) {
				if ( is_array( $v ) ) {
					$lead[ sanitize_key( $k ) ] = $v;
				} else {
					$lead[ sanitize_key( $k ) ] = sanitize_textarea_field( (string) $v );
				}
			}
		}

		// Campos planos del widget (no anidados): se agregan si `lead` no los trae.
		$flat = array(
			'name', 'email', 'phone', 'intent', 'requirement', 'conversation_summary',
			'source_page_url', 'message', 'last_user_message', 'conversation_recent',
			'session_id', 'channel', 'ea_sid', 'lead_intent', 'context_slug',
			'estandar_landing',
		);
		foreach ( $flat as $fk ) {
			if ( array_key_exists( $fk, $lead ) ) {
				continue;
			}
			if ( ! isset( $post[ $fk ] ) || is_array( $post[ $fk ] ) ) {
				continue;
			}
			$lead[ $fk ] = sanitize_textarea_field( (string) $post[ $fk ] );
		}
		return $lead;
	}

	/**
	 * Construye el payload canónico de Ingestion a partir del lead normalizado.
	 * Garantiza que content.message, context.source_page_url y
	 * attribution.landing_url no queden vacíos cuando el widget manda campos
	 * planos. Pura y testeable.
	 */
	public static function build_lead_ingest( array $lead ): array
	{
		$message = trim( (string) ( $lead['requirement'] ?? '' ) );
		if ( $message === '' ) {
			$message = trim( (string) ( $lead['conversation_summary'] ?? $lead['message'] ?? '' ) );
		}

		$page = esc_url_raw( (string) ( $lead['source_page_url'] ?? '' ) );
		if ( $page === '' ) {
			$page = esc_url_raw( (string) ( $lead['estandar_landing'] ?? '' ) );
		}

		$ingest = array(
			'source'       => 'chatbot',
			'submitted_at' => gmdate( 'c' ),
			'ip'           => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'session_id'   => sanitize_text_field( (string) ( $lead['session_id'] ?? '' ) ),
			'contact'      => array(
				'name'  => (string) ( $lead['name'] ?? '' ),
				'email' => sanitize_email( (string) ( $lead['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $lead['phone'] ?? '' ) ),
			),
			'content'      => array(
				'message'        => $message,
				'source_form_id' => 'chatbot',
			),
			'context'      => array(
				'source_page_url' => $page,
				'intent'          => 'contact',
			),
			'attribution'  => array(
				'landing_url' => $page,
			),
		);

		// Campos extra opcionales → fields_json (requirement, intent, etc.).
		$extra_fields = array();
		foreach ( array( 'requirement', 'intent', 'lead_intent', 'conversation_summary' ) as $fk ) {
			if ( isset( $lead[ $fk ] ) && $lead[ $fk ] !== '' ) {
				$extra_fields[ $fk ] = $lead[ $fk ];
			}
		}
		if ( ! empty( $extra_fields ) ) {
			$ingest['fields'] = $extra_fields;
		}

		// PROJECT_SLUG_FROM_PAGE_V1: el chatbot resuelve el proyecto de la página
		// (url_patterns del registry) y lo adjunta al capture para que el ruteo
		// (pipeline/operador y notificación) apunte al proyecto correcto.
		if ( class_exists( __NAMESPACE__ . '\\AiChatPrompt' ) ) {
			$ctx     = sanitize_key( (string) ( $lead['context_slug'] ?? '' ) );
			$registry = \Estandar\Capture\AiChatPrompt::project_registry();
			$match   = \Estandar\Capture\AiChatPrompt::match_project_for_url( $page, $registry );
			if ( ! empty( $match['project_slug'] ) ) {
				$ingest['project_slug'] = (string) $match['project_slug'];
			}
		}

		return $ingest;
	}

	/**
	 * CHATBOT_LEAD_NOTIFY_V1: notifica el lead del chatbot con el ruteo del
	 * proyecto (reusa las acciones del form lead del proyecto: notificación al
	 * operador + webhook + autorespuesta).
	 */
	public static function on_capture_notify( $capture ): void {
		if ( ! is_array( $capture ) || (string) ( $capture['source'] ?? '' ) !== 'chatbot' ) {
			return;
		}
		// CHATBOT_INTEGRATIONS_V1: salidas genéricas (webhook/CRM/email), off por
		// defecto y sin depender del proyecto.
		self::dispatch_integrations( $capture );
		$slug = sanitize_title( (string) ( $capture['project_slug'] ?? '' ) );
		if ( $slug === '' ) {
			return;
		}
		$form = self::find_project_lead_form( $slug );
		if ( ! $form ) {
			return;
		}
		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();
		if ( class_exists( __NAMESPACE__ . '\\Actions' ) ) {
			Actions::run( (int) $form['id'], $config, $capture );
		}
	}

	/**
	 * CHATBOT_INTEGRATIONS_V1 (2026-09-21): salidas genéricas del chatbot al
	 * capturar un lead — webhook (POST firmado), CRM (POST) y aviso por correo.
	 * OFF por defecto (grupo `integraciones`). Genérico, sin marcas ni IDs de
	 * cliente. No altera el ruteo/notificación del proyecto.
	 */
	private static function dispatch_integrations( array $capture ): void
	{
		$s       = AiChatPrompt::settings();
		$contact = isset( $capture['contact'] ) && is_array( $capture['contact'] ) ? $capture['contact'] : array();
		$fields  = isset( $capture['fields'] ) && is_array( $capture['fields'] ) ? $capture['fields'] : array();
		$payload = array(
			'source'     => 'chatbot',
			'lead_id'    => (int) ( $capture['id'] ?? 0 ),
			'project'    => (string) ( $capture['project_slug'] ?? '' ),
			'name'       => (string) ( $contact['name'] ?? '' ),
			'email'      => (string) ( $contact['email'] ?? '' ),
			'phone'      => (string) ( $contact['phone'] ?? '' ),
			'message'    => (string) ( $capture['content']['message'] ?? '' ),
			'page'       => (string) ( $capture['context']['source_page_url'] ?? ( $capture['attribution']['landing_url'] ?? '' ) ),
			'fields'     => $fields,
			'created_at' => gmdate( 'c' ),
		);

		// Webhook (POST JSON, firmado con HMAC-SHA256 si hay secreto).
		if ( ! empty( $s['webhook_enabled'] ) && ! empty( $s['webhook_url'] ) ) {
			$body    = (string) wp_json_encode( $payload );
			$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
			if ( ! empty( $s['webhook_secret'] ) ) {
				$headers['X-Estandar-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $s['webhook_secret'] );
			}
			wp_remote_post( esc_url_raw( (string) $s['webhook_url'] ), array( 'timeout' => 10, 'headers' => $headers, 'body' => $body ) );
		}

		// CRM (POST JSON genérico; Bearer si hay secreto).
		if ( ! empty( $s['crm_enabled'] ) && ! empty( $s['crm_url'] ) ) {
			$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
			if ( ! empty( $s['crm_secret'] ) ) {
				$headers['Authorization'] = 'Bearer ' . (string) $s['crm_secret'];
			}
			wp_remote_post( esc_url_raw( (string) $s['crm_url'] ), array( 'timeout' => 10, 'headers' => $headers, 'body' => (string) wp_json_encode( $payload ) ) );
		}

		// Aviso por correo.
		if ( ! empty( $s['notify_email_enabled'] ) ) {
			$to = sanitize_email( (string) ( $s['notify_email_to'] ?? '' ) );
			if ( $to !== '' && function_exists( 'wp_mail' ) ) {
				$lines = array(
					'Nuevo lead desde el chatbot.',
					'Nombre: ' . $payload['name'],
					'Email: ' . $payload['email'],
					'Teléfono: ' . $payload['phone'],
					'Proyecto: ' . $payload['project'],
					'Mensaje: ' . $payload['message'],
					'Página: ' . $payload['page'],
				);
				wp_mail( $to, (string) ( $s['notify_email_subject'] ?? 'Nuevo lead desde chatbot' ), implode( "\n", $lines ) );
			}
		}
	}

	/** Busca el form lead activo del proyecto (para reusar su ruteo/acciones). */
	private static function find_project_lead_form( string $slug ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$t    = $wpdb->prefix . 'estandar_capture_forms';
		$rows = $wpdb->get_results( "SELECT id, name, form_type, config_json FROM {$t} WHERE status='active'", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $r ) {
			if ( (string) ( $r['form_type'] ?? '' ) !== 'lead' ) {
				continue;
			}
			$c = json_decode( (string) ( $r['config_json'] ?? '{}' ), true );
			if ( is_array( $c ) && sanitize_title( (string) ( $c['form_project'] ?? '' ) ) === $slug ) {
				return $r;
			}
		}
		return array();
	}

	/**
	 * AJAX: registra los eventos de cliente que emite el widget (apertura,
	 * click a WhatsApp, mensajes, lead mostrado). Se persisten como filas
	 * `role=system` en ChatConversation para que sean visibles en
	 * Conversaciones. Whitelist estricta: no se guarda contenido arbitrario.
	 */
	public static function ajax_log_client_event(): void
	{
		if ( ! check_ajax_referer( 'estandar_chatbot_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'nonce_invalid', 'message' => 'Nonce inválido.' ), 403 );
		}

		$allowed = array(
			'chatbot_open', 'chatbot_whatsapp_click', 'chatbot_message_sent',
			'chatbot_lead_shown', 'chatbot_opened', 'button_click',
			'human_whatsapp_redirect', 'hot_lead_redirect',
		);

		$event = sanitize_key( (string) ( $_POST['event'] ?? '' ) );
		if ( $event === '' ) {
			$label = sanitize_key( (string) ( $_POST['label'] ?? '' ) );
			$itype = sanitize_key( (string) ( $_POST['interaction_type'] ?? '' ) );
			if ( in_array( $label, $allowed, true ) ) {
				$event = $label;
			} elseif ( in_array( $itype, $allowed, true ) ) {
				$event = $itype;
			}
		}
		if ( ! in_array( $event, $allowed, true ) ) {
			wp_send_json_success( array( 'logged' => false, 'reason' => 'event_not_allowed' ) );
		}

		$session_key = sanitize_key( (string) ( $_POST['session_id'] ?? '' ) );
		if ( $session_key === '' ) {
			$session_key = 'web_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ) . gmdate( 'Y-m-d' ) );
		}

		$payload = sanitize_text_field( (string) ( $_POST['payload'] ?? '' ) );
		if ( $payload !== '' ) {
			$payload = function_exists( 'mb_substr' ) ? mb_substr( $payload, 0, 200 ) : substr( $payload, 0, 200 );
		}
		$meta = array(
			'event'            => $event,
			'interaction_type' => sanitize_key( (string) ( $_POST['interaction_type'] ?? '' ) ),
			'label'            => sanitize_text_field( (string) ( $_POST['label'] ?? '' ) ),
			'page_url'         => esc_url_raw( (string) ( $_POST['page_url'] ?? '' ) ),
			'lead_identifier'  => sanitize_text_field( (string) ( $_POST['lead_identifier'] ?? '' ) ),
		);
		if ( $payload !== '' ) {
			$meta['payload'] = $payload;
		}

		ChatConversation::append( $session_key, 'web', 'system', 'event=' . $event . ' ' . wp_json_encode( $meta ) );
		wp_send_json_success( array( 'logged' => true, 'event' => $event ) );
	}

	/**
	 * CHAT_LEAD_V1 (GENÉRICO): detecta email/teléfono en los mensajes del
	 * usuario y persiste un lead (source=chatbot) una sola vez por sesión.
	 * Best-effort: si Ingestion falla, no rompe la conversación.
	 */
	public static function capture_lead_from_conversation( string $session_key, array $user_messages, string $page_url = '' ): int
	{
		if ( $session_key === '' || ! class_exists( __NAMESPACE__ . '\\Ingestion' ) ) {
			return 0;
		}
		if ( self::lead_already_captured( $session_key ) ) {
			return 0;
		}

		$texts = array();
		foreach ( $user_messages as $m ) {
			$m = trim( (string) $m );
			if ( $m !== '' ) {
				$texts[] = $m;
			}
		}
		if ( empty( $texts ) ) {
			return 0;
		}

		$contact = Ingestion::extract_contact( implode( "\n", $texts ) );
		$email   = (string) ( $contact['email'] ?? '' );
		$phone   = (string) ( $contact['phone'] ?? '' );
		if ( $email === '' && $phone === '' ) {
			return 0;
		}

		$summary = implode( ' · ', array_slice( $texts, -2 ) );
		$summary = function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 500 ) : substr( $summary, 0, 500 );

		$ingest = array(
			'source'       => 'chatbot',
			'submitted_at' => gmdate( 'c' ),
			'ip'           => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'session_id'   => $session_key,
			'contact'      => array(
				'name'  => (string) ( $contact['name'] ?? '' ),
				'email' => $email,
				'phone' => $phone,
			),
			'content'      => array(
				'message'        => $summary,
				'source_form_id' => 'chatbot',
			),
			'context'      => array(
				'source_page_url' => esc_url_raw( $page_url ),
				'intent'          => 'contact',
			),
			'attribution'  => array(
				'landing_url' => esc_url_raw( $page_url ),
			),
		);

		// PROJECT_SLUG_FROM_PAGE_V1 (2026-09-21): resolver el proyecto de la
		// página para que el aviso al operador apunte al proyecto correcto.
		// Antes faltaba aquí → el lead se guardaba pero NO se notificaba.
		if ( class_exists( __NAMESPACE__ . '\\AiChatPrompt' ) ) {
			$registry = AiChatPrompt::project_registry();
			$match    = AiChatPrompt::match_project_for_url( $page_url, $registry );
			if ( ! empty( $match['project_slug'] ) ) {
				$ingest['project_slug'] = (string) $match['project_slug'];
			}
		}

		// CHAT_RUT_V1: capturar RUT si está habilitado y aparece en la conversación.
		if ( in_array( 'rut', self::capture_fields(), true ) ) {
			$rut = self::extract_rut( $texts );
			if ( $rut !== '' ) {
				$ingest['fields'] = array_merge(
					isset( $ingest['fields'] ) && is_array( $ingest['fields'] ) ? $ingest['fields'] : array(),
					array( 'rut' => $rut )
				);
			}
		}

		try {
			$result = Ingestion::ingest( $ingest );
		} catch ( \Throwable $e ) {
			return 0;
		}
		if ( empty( $result['id'] ) ) {
			return 0;
		}

		$lead_id = (int) ( $result['capture']['id'] ?? $result['id'] );
		self::mark_lead_captured( $session_key );
		ChatConversation::append(
			$session_key,
			'web',
			'system',
			'lead_captured ' . wp_json_encode( array( 'lead_id' => $lead_id, 'email' => $email, 'phone' => $phone ) )
		);
		return $lead_id;
	}

	/** Marca una sesión como "ya capturó un lead" (evita duplicados). */
	public static function mark_lead_captured( string $session_key ): void
	{
		if ( $session_key !== '' && function_exists( 'set_transient' ) ) {
			$ttl = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
			set_transient( self::session_capture_key( $session_key ), 1, $ttl );
		}
	}

	/** ¿Ya se capturó un lead en esta sesión? */
	public static function lead_already_captured( string $session_key ): bool
	{
		return $session_key !== '' && function_exists( 'get_transient' ) && (bool) get_transient( self::session_capture_key( $session_key ) );
	}

	private static function session_capture_key( string $session_key ): string
	{
		return 'estandar_chatbot_lead_captured_' . md5( $session_key );
	}

	/** Nombre de marca para el header del chat. */
	private static function brand_label(): string
	{
		$name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		return $name !== '' ? $name : 'nuestro equipo';
	}

	/**
	 * URL de la imagen de burbuja (BUBBLE_DEFAULT_V1).
	 * Precedencia: setting v2 `bubble_image_url` → opción legacy
	 * `chatbot_bubble_image_url` → GIF por defecto embebido en el plugin
	 * (assets/animations/bubble-default.gif). Antes, al quedar vacías las dos
	 * config, la burbuja caía al ícono 💬.
	 */
	private static function bubble_image_url( array $s ): string
	{
		$url = trim( (string) ( $s['bubble_image_url'] ?? get_option( 'chatbot_bubble_image_url', '' ) ) );
		if ( $url !== '' ) {
			return $url;
		}
		if ( defined( 'ESTANDAR_SUITE_CAPTURE_FILE' ) ) {
			return plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/animations/bubble-default.gif';
		}
		return '';
	}

	/**
	 * LEAD_CAPTURE_COPY_V1: resuelve el copy del flujo determinístico de
	 * captación. Precedencia: setting v2 → opción legacy `chatbot_…` → default
	 * amable. Permite que cada instalación ajuste el tono sin tocar código.
	 */
	private static function lead_capture_copy( string $key, string $legacy, string $default ): string
	{
		$s   = AiChatPrompt::settings();
		$val = trim( (string) ( $s[ $key ] ?? '' ) );
		if ( $val === '' ) {
			$val = trim( (string) get_option( $legacy, '' ) );
		}
		return $val !== '' ? $val : $default;
	}

	/**
	 * Fallback determinístico de captura de lead (SIN IA).
	 *
	 * Cuando el motor LLM no está disponible (clave ausente o error), el chatbot
	 * guía al visitante paso a paso para dejar sus datos de contacto y los
	 * captura con la misma ingesta que el form: correo → teléfono → nombre →
	 * captura. Devuelve la siguiente pregunta o la confirmación con lead_id.
	 */
	/**
	 * NAME_EXTRACT_V2 (2026-09-22): extracción de nombre CONSERVADORA.
	 * 1) Patrones explícitos ("me llamo X", "mi nombre es X", "soy X").
	 * 2) Si no, solo si el mensaje es corto (≤3 palabras) y queda una palabra
	 *    tipo nombre tras quitar datos de contacto y palabras de relleno.
	 * Devuelve '' si no hay confianza (mejor preguntar que guardar basura).
	 */
	public static function extract_name( array $texts ): string
	{
		$stop = array( 'el','la','los','las','un','una','unos','unas','de','del','al','y','o','u','mi','tu','su','me','te','se','es','son','soy','esta','estan','hola','buenas','buenos','dias','tardes','noches','gracias','por','para','con','sin','favor','necesito','necesio','quiero','quisiera','busco','vendo','vende','comprar','compra','correo','email','telefono','numero','celular','movil','whatsapp','contacto','asesor','vendedor','informacion','precio','precios','casa','departamento','planta','plantas','uva','vino','urgente','ayuda','escribir','comunicar' );

		// 1) Patrón explícito.
		foreach ( $texts as $txt ) {
			if ( preg_match( '/\b(?:me llamo|mi nombre es|soy)\s+([A-Za-zÁÉÍÓÚÜáéíóúüÑñ]{2,20}(?:\s+[A-Za-zÁÉÍÓÚÜáéíóúüÑñ]{2,20})?)/iu', (string) $txt, $m ) ) {
				$cand  = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
				$first = strtok( $cand, ' ' );
				if ( $first !== false && $first !== '' && ! in_array( self::strip_accents_lower( $first ), $stop, true ) ) {
					return mb_convert_case( $cand, MB_CASE_TITLE, 'UTF-8' );
				}
			}
		}
		// 2) Mensaje corto tipo nombre.
		foreach ( $texts as $txt ) {
			$c = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', ' ', (string) $txt );
			$c = preg_replace( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', ' ', (string) $c );
			$c = preg_replace( '/[^A-Za-zÁÉÍÓÚÜáéíóúüÑñ\s]/u', ' ', (string) $c );
			$words = array_values( array_filter( preg_split( '/\s+/', trim( (string) $c ) ) ) );
			if ( count( $words ) > 3 ) {
				continue;
			}
			$cands = array();
			foreach ( $words as $w ) {
				if ( in_array( self::strip_accents_lower( $w ), $stop, true ) ) {
					continue;
				}
				if ( preg_match( '/^[A-Za-zÁÉÍÓÚÜáéíóúüÑñ]{2,20}$/u', $w ) ) {
					$cands[] = $w;
				}
			}
			if ( count( $cands ) >= 1 && count( $cands ) <= 2 ) {
				return mb_convert_case( implode( ' ', $cands ), MB_CASE_TITLE, 'UTF-8' );
			}
		}
		return '';
	}

	/** Normaliza para comparar contra stopwords (sin acentos, minúsculas). */
	private static function strip_accents_lower( string $w ): string
	{
		$w = function_exists( 'remove_accents' ) ? remove_accents( $w ) : $w;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $w ), 'UTF-8' ) : strtolower( trim( $w ) );
	}

	/**
	 * CHAT_CAPTURE_FIELDS_V1 (2026-09-27): campos de captación configurables.
	 * Default `name,email,phone`; `rut` opcional (habitual en Chile). Acepta
	 * lista v2 o CSV legacy.
	 */
	public static function capture_fields(): array
	{
		$s   = AiChatPrompt::settings();
		$raw = $s['capture_fields'] ?? null;
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array( 'name', 'email', 'phone' );
		}
		$allowed = array( 'name', 'email', 'phone', 'rut' );
		$out     = array_values( array_intersect( array_map( 'sanitize_key', $raw ), $allowed ) );
		return ! empty( $out ) ? $out : array( 'name', 'email', 'phone' );
	}

	/** CHAT_RUT_V1 (2026-09-27): extrae un RUT chileno válido de los textos. */
	public static function extract_rut( array $texts ): string
	{
		foreach ( $texts as $t ) {
			if ( preg_match( '/(\d{1,2}\.?\d{3}\.?\d{3})-?\s?([0-9kK])\b/u', (string) $t, $m ) ) {
				$body = preg_replace( '/\D/', '', $m[1] );
				if ( $body === '' ) {
					continue;
				}
				$rut = $body . '-' . strtoupper( $m[2] );
				if ( self::rut_is_valid( $rut ) ) {
					return $rut;
				}
			}
		}
		return '';
	}

	/** CHAT_RUT_V1: valida un RUT chileno por módulo 11. */
	public static function rut_is_valid( string $rut ): bool
	{
		$rut = strtoupper( preg_replace( '/[^0-9kK]/', '', $rut ) );
		if ( strlen( $rut ) < 8 ) {
			return false;
		}
		$dv   = substr( $rut, -1 );
		$body = substr( $rut, 0, -1 );
		$sum  = 0;
		$mult = 2;
		for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
			$sum += (int) $body[ $i ] * $mult;
			$mult = $mult === 7 ? 2 : $mult + 1;
		}
		$res  = 11 - ( $sum % 11 );
		$calc = $res === 11 ? '0' : ( $res === 10 ? 'K' : (string) $res );
		return $calc === $dv;
	}

	public static function deterministic_lead_reply( string $message, array $history, string $session_key ): array
	{
		$email = '';
		$phone = '';
		$name  = '';

		$user_msgs = array( $message );
		foreach ( $history as $h ) {
			if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
				$user_msgs[] = (string) $h['content'];
			}
		}

		foreach ( $user_msgs as $txt ) {
			if ( $email === '' && preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $txt, $m ) ) {
				$email = sanitize_email( $m[0] );
			}
			if ( $phone === '' && preg_match( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', $txt, $m ) ) {
				$digits = preg_replace( '/\D+/', '', $m[0] );
				if ( strlen( $digits ) === 8 ) {
					$phone = '569' . $digits;
				} elseif ( strlen( $digits ) === 9 && $digits[0] === '9' ) {
					$phone = '56' . $digits;
				} else {
					$phone = $digits;
				}
			}
			if ( $name === '' ) {
				// NAME_EXTRACT_V2: la extracción conservadora se hace al final,
				// sobre todos los mensajes (evita guardar basura como "Necesio El").
			}
		}

		$name = $name !== '' ? $name : self::extract_name( $user_msgs );

		$intro     = self::lead_capture_copy( 'lead_capture_intro', 'chatbot_lead_capture_intro', 'Con gusto te ayudo.' );
		$ask_email = self::lead_capture_copy( 'lead_capture_ask_email', 'chatbot_lead_capture_ask_email', '¿Me dejas tu correo para que una persona del equipo te contacte y te dé una respuesta a medida?' );
		$ask_phone = self::lead_capture_copy( 'lead_capture_ask_phone', 'chatbot_lead_capture_ask_phone', '¡Gracias! ¿Y un número de WhatsApp o teléfono donde te podamos ubicar?' );
		$ask_name  = self::lead_capture_copy( 'lead_capture_ask_name', 'chatbot_lead_capture_ask_name', 'Por último, ¿cómo te llamas?' );
		$ask_rut   = self::lead_capture_copy( 'lead_capture_ask_rut', 'chatbot_lead_capture_ask_rut', '¿Me dejas también tu RUT? Lo usamos para tu ficha.' );
		$done      = self::lead_capture_copy( 'lead_capture_done', 'chatbot_lead_capture_done', '¡Gracias, {name}! Ya quedó registrado tu contacto ({email} · {phone}). Una persona del equipo te escribirá muy pronto.' );

		$need_rut = in_array( 'rut', self::capture_fields(), true );
		$rut      = $need_rut ? self::extract_rut( $user_msgs ) : '';

		if ( $email === '' ) {
			$content = trim( $intro . ' ' . $ask_email );
			return array( 'content' => $content !== '' ? $content : $ask_email );
		}
		if ( $phone === '' ) {
			return array( 'content' => $ask_phone );
		}
		if ( $name === '' || mb_strlen( $name, 'UTF-8' ) < 2 ) {
			return array( 'content' => $ask_name );
		}
		if ( $need_rut && $rut === '' ) {
			return array( 'content' => $ask_rut );
		}

		$landing = esc_url_raw( (string) ( $_POST['estandar_landing'] ?? ( $_SERVER['HTTP_REFERER'] ?? '' ) ) );
		$ingest  = array(
			'source'       => 'chatbot',
			'submitted_at' => gmdate( 'c' ),
			'ip'           => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'session_id'   => $session_key,
			'contact'      => array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
			),
			'content'      => array(
				'message'        => $message,
				'source_form_id' => 'chatbot',
			),
			'context'      => array(
				'source_page_url' => $landing,
				'intent'          => 'contact',
			),
			'attribution'  => array(
				'landing_url' => $landing,
			),
		);
		if ( $rut !== '' ) {
			$ingest['fields'] = array( 'rut' => $rut );
		}
		// PROJECT_SLUG_FROM_PAGE_V1: resolver proyecto para notificar al operador.
		if ( class_exists( __NAMESPACE__ . '\\AiChatPrompt' ) ) {
			$registry = AiChatPrompt::project_registry();
			$match    = AiChatPrompt::match_project_for_url( $landing, $registry );
			if ( ! empty( $match['project_slug'] ) ) {
				$ingest['project_slug'] = (string) $match['project_slug'];
			}
		}
		$result = Ingestion::ingest( $ingest );
		if ( ! empty( $result['id'] ) ) {
			self::mark_lead_captured( $session_key );
			$reply = strtr( $done, array(
				'{name}'  => $name,
				'{email}' => $email,
				'{phone}' => $phone,
			) );
			return array( 'content' => $reply, 'lead_id' => (int) ( $result['capture']['id'] ?? $result['id'] ) );
		}
		return array( 'content' => 'Gracias por tus datos. Una persona del equipo te contactará pronto.' );
	}
}
