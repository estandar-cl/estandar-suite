<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/capture/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/captures', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$raw = (array) $request->get_json_params();
				$result = Ingestion::ingest( $raw );
				if ( empty( $result['id'] ) ) {
					return new \WP_Error( 'estandar_suite_capture_failed', 'Captura no persistida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'id' => $result['id'], 'capture_uuid' => $result['capture']['capture_uuid'] ) );
			},
		) );

		register_rest_route( self::NS, '/adoption/historical', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array(
					'legacy_present' => Adoption::legacy_present(),
					'historical'     => Adoption::historical( (int) $request->get_param( 'limit' ) ?: 100, 0 ),
				) );
			},
		) );

		register_rest_route( self::NS, '/forms/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$limit = (int) $request->get_param( 'limit' ) ?: 100;
				return rest_ensure_response( array(
					'legacy_present'  => FormsAdoption::legacy_present(),
					'resources'       => FormsAdoption::resource_counts(),
					'entries'         => FormsAdoption::historical_entries( $limit, 0 ),
					'responses'       => FormsAdoption::historical_responses( $limit, 0 ),
				) );
			},
		) );

		// ── Herencia v2: adopción forms (entries + survey responses) ────────
		register_rest_route( self::NS, '/forms/adoption/import', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$dry   = empty( $request->get_param( 'commit' ) );
				$limit = max( 10, (int) $request->get_param( 'limit' ) ?: 100 );
				return rest_ensure_response( array(
					'entries' => FormsAdoption::import_entries( $dry, $limit ),
					'responses' => FormsAdoption::import_responses( $dry, $limit ),
					'commit'  => ! $dry,
				) );
			},
		) );

		register_rest_route( self::NS, '/forms/adoption/rollback', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( array(
					'entries_rolled'   => FormsAdoption::rollback_entries(),
					'responses_rolled' => FormsAdoption::rollback_responses(),
				) );
			},
		) );

		// ── Herencia v2: adopción chatbot (leads + conversaciones/logs/eventos) ──
		register_rest_route( self::NS, '/chatbot/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( array(
					'leads'    => ChatbotAdoption::counts(),
					'mirrors'  => ChatbotAdoption::mirror_status(),
				) );
			},
		) );

		register_rest_route( self::NS, '/chatbot/adoption/import', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$dry   = empty( $request->get_param( 'commit' ) );
				$limit = max( 10, (int) $request->get_param( 'limit' ) ?: 100 );
				return rest_ensure_response( ChatbotAdoption::import_all( $dry, $limit ) + array( 'commit' => ! $dry ) );
			},
		) );

		register_rest_route( self::NS, '/chatbot/adoption/rollback', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( array( 'rolled' => ChatbotAdoption::rollback_all() ) );
			},
		) );

		register_rest_route( self::NS, '/forms', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$source = sanitize_key( (string) $request->get_param( 'source' ) );
				return rest_ensure_response( array( 'source' => $source, 'forms' => FormBridge::forms( $source ) ) );
			},
		) );

		register_rest_route( self::NS, '/forms/fields', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$source  = sanitize_key( (string) $request->get_param( 'source' ) );
				$form_id = (int) $request->get_param( 'form_id' );
				return rest_ensure_response( array( 'fields' => FormBridge::fields( $source, $form_id ) ) );
			},
		) );

		// REGION_ROUTING_V1 · Seed/list de regiones por REST (producción sin SSH).
		register_rest_route( self::NS, '/regions/(?P<region>[a-z0-9_-]+)', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$key = sanitize_key( (string) $request->get_param( 'region' ) );
				return rest_ensure_response( array(
					'region' => $key,
					'list'   => Forms::region_list( $key ),
				) );
			},
		) );

		register_rest_route( self::NS, '/regions/seed', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$key    = sanitize_key( (string) $request->get_param( 'region' ) );
				$commit = ! empty( $request->get_param( 'commit' ) );
				if ( $key === '' ) {
					return new \WP_Error( 'estandar_suite_region_missing', 'Requiere región (region).', array( 'status' => 400 ) );
				}
				return rest_ensure_response( Forms::region_seed( $key, $commit ) );
			},
		) );

		// Lectura remota del config de un form (admin) — inspección sin SSH.
		register_rest_route( self::NS, '/forms/(?P<form_id>\d+)/config', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$id = (int) $request->get_param( 'form_id' );
				$f  = Forms::form_config( $id );
				if ( ! $f ) {
					return new \WP_Error( 'estandar_suite_form_not_found', 'Formulario inexistente.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $f );
			},
		) );

		// REGION_ROUTING_V1 · Aplica config region_routing a un form (merge).
		register_rest_route( self::NS, '/forms/(?P<form_id>\d+)/region-routing', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$id = (int) $request->get_param( 'form_id' );
				$rr = (array) $request->get_json_params();
				$r  = Forms::region_routing_save( $id, $rr );
				if ( empty( $r['ok'] ) ) {
					return new \WP_Error( 'estandar_suite_region_routing_invalid', (string) ( $r['error'] ?? 'No se pudo guardar.' ), array( 'status' => 400 ) );
				}
				return rest_ensure_response( $r );
			},
		) );

		// Merge remoto de config de un form (admin) — operación sin SSH.
		register_rest_route( self::NS, '/forms/(?P<form_id>\d+)/config', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$id    = (int) $request->get_param( 'form_id' );
				$patch = (array) $request->get_json_params();
				$r     = Forms::config_merge_save( $id, $patch );
				if ( empty( $r['ok'] ) ) {
					return new \WP_Error( 'estandar_suite_form_config_invalid', (string) ( $r['error'] ?? 'No se pudo guardar.' ), array( 'status' => 400 ) );
				}
				return rest_ensure_response( $r );
			},
		) );

		// POPUPS_V2 · CRUD remoto de popups v2 (gestión sin SSH).
		register_rest_route( self::NS, '/popups', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					$rows = Popups::list();
					foreach ( $rows as &$row ) {
						$row['config'] = json_decode( (string) ( $row['config_json'] ?? '' ), true );
					}
					unset( $row );
					return rest_ensure_response( array( 'popups' => $rows ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => array( __CLASS__, 'popups_upsert' ),
			),
		) );

		register_rest_route( self::NS, '/popups/(?P<popup_id>\d+)', array(
			'methods'             => 'DELETE',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$id = (int) $request->get_param( 'popup_id' );
				return rest_ensure_response( array( 'deleted' => Popups::delete( $id ) ) );
			},
		) );

		register_rest_route( self::NS, '/chat', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return true; // public — rate limited by IP below.
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'cli' );
				if ( ! self::chat_rate_ok( $ip ) ) {
					return new \WP_Error( 'estandar_suite_chat_rate_limited', 'Demasiadas solicitudes. Intenta en un minuto.', array( 'status' => 429 ) );
				}
				// CHAT_HARDEN_V1: presupuesto diario de llamadas (anti-drenaje API).
				if ( ! self::chat_budget_ok() ) {
					return new \WP_Error( 'estandar_suite_chat_budget', 'El asistente está en pausa por alta demanda. Intenta más tarde.', array( 'status' => 429 ) );
				}
				$in      = (array) $request->get_json_params();
				$message = trim( (string) ( $in['message'] ?? '' ) );
				if ( $message === '' ) {
					return new \WP_Error( 'estandar_suite_chat_empty', 'Escribe un mensaje.', array( 'status' => 400 ) );
				}
				$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 );

				$messages = array( array(
					'role'    => 'system',
					'content' => AiChatPrompt::system(
						(string) ( $in['page_url'] ?? '' ),
						(string) ( $in['context_slug'] ?? '' ),
						$message
					),
				) );
				$history  = isset( $in['history'] ) && is_array( $in['history'] ) ? $in['history'] : array();
				// CHAT_HARDEN_V1: tope de historial (últimas 20, cada una ≤ 2000).
				$history = array_slice( $history, -20 );
				foreach ( $history as $h ) {
					if ( ! isset( $h['role'], $h['content'] ) ) {
						continue;
					}
					$messages[] = array(
						'role'    => sanitize_key( (string) $h['role'] ),
						'content' => sanitize_textarea_field( (string) $h['content'] ),
					);
				}
				// PROJECT_FOCUS_V1: nota de proyecto junto al turno del usuario.
				$user_content = $message;
				$page_note    = AiChatPrompt::page_context_note(
					(string) ( $in['page_url'] ?? '' ),
					(string) ( $in['context_slug'] ?? '' )
				);
				if ( $page_note !== '' ) {
					$user_content = $page_note . "\n\n" . $message;
				}
				$messages[] = array( 'role' => 'user', 'content' => $user_content );

				$opts = AiChatPrompt::chat_opts();
				$r    = AiChat::chat( $messages, $opts );

				// Persistir en las tablas v2 (sesión determinista por IP+hash) para
				// que la pestaña Conversaciones y los Logs del admin reflejen el chat.
				$session_key = 'rest_' . md5( $ip . '|' . gmdate( 'Y-m-d' ) );
				ChatConversation::append( $session_key, 'web', 'user', $message );

				// CHAT_FALLBACK_V2: resolver SIEMPRE una respuesta final (aunque la
				// IA falle) y persistirla, para no dejar turnos sin respuesta.
				$is_error = ! empty( $r['error'] );
				$content  = (string) ( $r['content'] ?? '' );
				if ( $content !== '' && ! AiChatPrompt::reply_is_clean( $content ) ) {
					$content = 'Prefiero no compartir esa información. ¿En qué más puedo ayudarte?';
					$r['filtered'] = true;
				}
				if ( $is_error || $content === '' ) {
					$content = 'Disculpa, en este momento no puedo responder con IA. ¿Quieres intentarlo de nuevo o prefieres que te contacte una persona del equipo?';
				}
				$r['content'] = $content;

				ChatConversation::append( $session_key, 'web', 'assistant', $content );
				ChatLog::log( $session_key, 'web', $message, $content, $is_error ? ChatLog::RESULT_FALLBACK : '' );

				// CHAT_LEAD_V1 (GENÉRICO): si el visitante dejó email/teléfono en
				// la conversación, persistir el lead una sola vez por sesión.
				$user_msgs = array();
				foreach ( $history as $h ) {
					if ( ( $h['role'] ?? '' ) === 'user' && ! empty( $h['content'] ) ) {
						$user_msgs[] = (string) $h['content'];
					}
				}
				$user_msgs[] = $message;
				if ( class_exists( __NAMESPACE__ . '\\ChatbotFrontend' ) ) {
					ChatbotFrontend::capture_lead_from_conversation( $session_key, $user_msgs, (string) ( $in['page_url'] ?? '' ) );
				}

				if ( $is_error ) {
					return rest_ensure_response( array(
						'reply' => $content,
						'error' => $r['error'],
						'code'  => $r['error_code'] ?? '',
					) );
				}
				return rest_ensure_response( array(
					'reply'    => $content,
					'model'    => (string) ( $r['model'] ?? '' ),
					'provider' => AiChat::provider(),
					'filtered' => ! empty( $r['filtered'] ),
				) );
			},
		) );

		register_rest_route( self::NS, '/whatsapp/webhook', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return true;
			},
			'callback'            => array( 'Estandar\\Capture\\WhatsAppBot', 'webhook_verify' ),
		) );

		register_rest_route( self::NS, '/whatsapp/webhook', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return true;
			},
			'callback'            => array( 'Estandar\\Capture\\WhatsAppBot', 'webhook_handle' ),
		) );

		register_rest_route( self::NS, '/forms/jotform', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return true; // public — authenticated by shared webhook key
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$provided = (string) $request->get_header( 'X-Estandar-Webhook-Key' );
				if ( $provided === '' ) {
					$provided = sanitize_text_field( (string) $request->get_param( 'key' ) );
				}
				if ( $provided === '' || ! hash_equals( FormBridge::webhook_key(), $provided ) ) {
					return new \WP_Error( 'estandar_suite_webhook_unauthorized', 'Clave de webhook inválida.', array( 'status' => 401 ) );
				}
				$payload = (array) $request->get_json_params();
				$result  = FormBridge::handle_jotform( $payload );
				if ( empty( $result['id'] ) ) {
					return new \WP_Error( 'estandar_suite_jotform_failed', 'Entrada no persistida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'id' => $result['id'], 'capture_uuid' => $result['capture']['capture_uuid'] ?? '' ) );
			},
		) );

		// META_LEADGEN_WEBHOOK_V1 (2026-09-14): receptor de los formularios
		// instantáneos de Meta (Lead Ads). GET = verificación (hub.challenge);
		// POST = notificación con leadgen_id → se resuelve y se ingesta.
		register_rest_route( self::NS, '/forms/facebook', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return true;
				},
				'callback'            => array( MetaLeadAds::class, 'verify' ),
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return true; // público — autenticado por firma X-Hub-Signature-256
				},
				'callback'            => array( MetaLeadAds::class, 'handle_notification' ),
			),
		) );

		// CHATBOT_WHATSAPP_REDIRECT_V1 (2026-09-10): endpoint v2 del botón
		// "Hablar por WhatsApp" del chatbot. Reemplaza la ruta legacy
		// `estandar-leads/v1/whatsapp-redirect` (retirada con el legacy), que en
		// sitios sin estandar-leads devolvía rest_no_route (404). GET público que
		// resuelve número + mensaje y hace 302 a wa.me.
		register_rest_route( self::NS, '/whatsapp-redirect', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'whatsapp_redirect' ),
			'args'                => array(
				'source'       => array( 'sanitize_callback' => 'sanitize_key' ),
				'session_id'   => array( 'sanitize_callback' => 'sanitize_key' ),
				'project_slug' => array( 'sanitize_callback' => 'sanitize_key' ),
				'number'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'landing_url'  => array( 'sanitize_callback' => 'esc_url_raw' ),
			),
		) );
	}

	/** POPUPS_V2: crea/actualiza un popup v2 (remoto, sin SSH). */
	public static function popups_upsert( \WP_REST_Request $request )
	{
		$in = (array) $request->get_json_params();
		$id = isset( $in['id'] ) ? (int) $in['id'] : 0;
		$cur = $id ? Popups::get( $id ) : null;

		$cfg = array();
		if ( isset( $in['config'] ) && is_array( $in['config'] ) ) {
			$cfg = $in['config'];
		} elseif ( isset( $in['config_json'] ) ) {
			$decoded = json_decode( (string) $in['config_json'], true );
			$cfg = is_array( $decoded ) ? $decoded : array();
		}

		if ( $cur ) {
			$prev = json_decode( (string) ( $cur['config_json'] ?? '' ), true );
			if ( is_array( $prev ) ) {
				foreach ( array( 'legacy_post_id', 'stats', 'open_method' ) as $k ) {
					if ( isset( $prev[ $k ] ) && ! isset( $cfg[ $k ] ) ) {
						$cfg[ $k ] = $prev[ $k ];
					}
				}
			}
		}

		$data = array(
			'name'   => sanitize_text_field( (string) ( $in['name'] ?? ( $cur['name'] ?? '' ) ) ),
			'slug'   => sanitize_title( (string) ( $in['slug'] ?? ( $cur['slug'] ?? '' ) ) ),
			'status' => sanitize_key( (string) ( $in['status'] ?? ( $cur['status'] ?? 'draft' ) ) ),
		);

		if ( $id ) {
			$cfg['custom_css_scoped'] = Popups::scope_css( (string) ( $cfg['custom_css'] ?? '' ), Popups::encode_frontend_id( $id ) );
			$data['config_json'] = (string) wp_json_encode( $cfg );
			$ok = Popups::update( $id, $data );
			return $ok
				? rest_ensure_response( array( 'id' => $id, 'updated' => true ) )
				: new \WP_Error( 'estandar_suite_popup_update', 'No se pudo actualizar el popup.', array( 'status' => 400 ) );
		}

		$data['config_json'] = (string) wp_json_encode( $cfg );
		$new_id = Popups::create( $data );
		if ( ! $new_id ) {
			return new \WP_Error( 'estandar_suite_popup_create', 'No se pudo crear (nombre requerido).', array( 'status' => 400 ) );
		}
		$cfg['custom_css_scoped'] = Popups::scope_css( (string) ( $cfg['custom_css'] ?? '' ), Popups::encode_frontend_id( $new_id ) );
		$data['config_json'] = (string) wp_json_encode( $cfg );
		Popups::update( $new_id, $data );

		return rest_ensure_response( array( 'id' => $new_id, 'created' => true ) );
	}

	/** CHATBOT_WHATSAPP_REDIRECT_V1: resuelve número+mensaje y redirige a wa.me. */
	public static function whatsapp_redirect( \WP_REST_Request $request )
	{
		$number = self::resolve_whatsapp_number( (string) $request->get_param( 'number' ) );
		if ( $number === '' ) {
			// WHATSAPP_REDIRECT_NO_FATAL_V1 (2026-09-14): antes esta rama llamaba
			// $response->send_headers() —método inexistente en WP_REST_Response—
			// seguido de echo+exit → FATAL 500 en todo sitio sin número de
			// WhatsApp configurado. Se devuelve la WP_REST_Response y el servidor
			// REST la serializa normalmente.
			return new \WP_REST_Response(
				array(
					'code'    => 'estandar_suite_no_whatsapp_number',
					'message' => __( 'No hay un número de WhatsApp configurado para este sitio.', 'estandar-suite-capture' ),
				),
				404
			);
		}

		$message = self::resolve_whatsapp_message();
		$url     = 'https://wa.me/' . $number . ( $message !== '' ? '?text=' . rawurlencode( $message ) : '' );

		// CHATBOT_WA_CLICK_ALWAYS_V1 (2026-09-11): el clic del chatbot se
		// registra SIEMPRE (best-effort), sin depender de WhatsAppClickTracking
		// ::enabled(). Ese opt-in queda solo para el endpoint genérico
		// /wa/click; aquí el objetivo es saber si el visitante derivó a
		// WhatsApp. Nunca debe bloquear el redirect (try/catch interno).
		self::record_chatbot_whatsapp_click( array(
			'session' => (string) $request->get_param( 'session_id' ),
			'slug'    => (string) $request->get_param( 'project_slug' ),
			'number'  => $number,
			'message' => $message,
			'page'    => (string) $request->get_param( 'landing_url' ),
		) );

		wp_redirect( $url, 302 );
		exit;
	}

	/**
	 * Registra best-effort un clic del chatbot al botón de WhatsApp. Es
	 * deliberadamente independiente de WhatsAppClickTracking::enabled(): el
	 * clic del chatbot siempre interesa. Nunca lanza (el redirect no depende
	 * del tracking).
	 */
	public static function record_chatbot_whatsapp_click( array $params ): void
	{
		try {
			if ( ! class_exists( __NAMESPACE__ . '\\WhatsAppClickTracking' ) ) {
				return;
			}
			$device = (string) ( $params['device'] ?? '' );
			if ( $device === '' ) {
				$device = ( function_exists( 'wp_is_mobile' ) && wp_is_mobile() ) ? 'mobile' : 'desktop';
			}
			WhatsAppClickTracking::record_event( array(
				'session' => (string) ( $params['session'] ?? '' ),
				'slug'    => (string) ( $params['slug'] ?? '' ),
				'number'  => (string) ( $params['number'] ?? '' ),
				'message' => (string) ( $params['message'] ?? '' ),
				'page'    => (string) ( $params['page'] ?? '' ),
				'device'  => $device,
			) );
		} catch ( \Throwable $e ) {
			// noop: el redirect no depende del tracking.
		}
	}

	/** Número de WhatsApp: parámetro válido > chatbot > botón flotante. */
	private static function resolve_whatsapp_number( string $param ): string
	{
		$param = preg_replace( '/\D+/', '', $param );
		if ( strlen( $param ) >= 8 && strlen( $param ) <= 15 ) {
			return $param;
		}
		$settings = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$num      = (string) ( $settings['human_whatsapp_number'] ?? get_option( 'chatbot_human_whatsapp_number', '' ) );
		$num      = preg_replace( '/\D+/', '', $num );
		if ( $num !== '' ) {
			return $num;
		}
		if ( class_exists( __NAMESPACE__ . '\\FloatingWhatsApp' ) ) {
			return FloatingWhatsApp::number();
		}
		return '';
	}

	/** Mensaje prefill: chatbot > botón flotante > default. */
	private static function resolve_whatsapp_message(): string
	{
		$msg = (string) get_option( 'chatbot_human_whatsapp_message', '' );
		if ( trim( $msg ) === '' && class_exists( __NAMESPACE__ . '\\FloatingWhatsApp' ) ) {
			$msg = FloatingWhatsApp::message();
		}
		if ( trim( $msg ) === '' ) {
			$msg = 'Hola, vengo desde la web y quiero información.';
		}
		return $msg;
	}

	/** Rate limit público por IP para el chat (20 req/min). */
	private static function chat_rate_ok( string $ip ): bool
	{
		// Compartido con el AJAX del widget (CHAT_AJAX_HARDEN_V1).
		return AiChatPrompt::chat_rate_ok( $ip );
	}

	/** Presupuesto diario de llamadas al chat (CHAT_HARDEN_V1). */
	private static function chat_budget_ok(): bool
	{
		// Compartido con el AJAX del widget (CHAT_AJAX_HARDEN_V1).
		return AiChatPrompt::chat_budget_ok();
	}
}
