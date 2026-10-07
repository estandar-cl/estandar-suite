<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WhatsAppBot
{
	const SETTINGS_OPTION = 'estandar_suite_capture_whatsapp_settings';
	const DIAG_OPTION     = 'estandar_suite_capture_whatsapp_diag';
	const GRAPH_BASE      = 'https://graph.facebook.com';
	const GRAPH_VERSION   = 'v21.0';
	const CHANNEL         = 'whatsapp';
	const LEAD_PREFIX     = 'estandar_suite_wa_lead_';
	const CTX_PREFIX      = 'estandar_suite_wa_ctx_';
	const MSG_PREFIX      = 'estandar_suite_wa_msg_';
	const RL_PREFIX       = 'estandar_suite_wa_rl_';

	public static function defaults(): array
	{
		return array(
			'enabled'               => 0,
			'phone_number_id'       => '',
			'business_phone'        => '',
			'access_token'          => '',
			'verify_token'          => '',
			'app_secret'            => '',
			'waba_id'               => '',
			'conversation_history'  => 12,
			'lead_capture_enabled'  => 1,
			'welcome_message'       => '',
			'fallback_reply'        => 'Disculpa, en este momento no puedo responder. Intenta en unos minutos.',
			'rate_limit_per_hour'   => 200,
			'debug_log'             => 0,
		);
	}

	public static function fields(): array
	{
		return array(
			'enabled'              => array( 'type' => 'bool', 'label' => 'Activar bot de WhatsApp', 'default' => 0 ),
			'phone_number_id'      => array( 'type' => 'text', 'label' => 'Phone Number ID', 'default' => '' ),
			'business_phone'       => array( 'type' => 'text', 'label' => 'Número de WhatsApp (visual)', 'default' => '' ),
			'access_token'         => array( 'type' => 'secret', 'label' => 'Access Token (permanente)', 'default' => '' ),
			'verify_token'         => array( 'type' => 'secret', 'label' => 'Verify Token (webhook)', 'default' => '' ),
			'app_secret'           => array( 'type' => 'secret', 'label' => 'App Secret (firma de webhook)', 'default' => '' ),
			'waba_id'              => array( 'type' => 'text', 'label' => 'WhatsApp Business Account ID (opcional)', 'default' => '' ),
			'conversation_history' => array( 'type' => 'number', 'label' => 'Contexto (turnos previos)', 'desc' => 'Cuántos mensajes previos envía al modelo. 0 = solo el mensaje actual.', 'min' => 0, 'max' => 40, 'default' => 12 ),
			'lead_capture_enabled' => array( 'type' => 'bool', 'label' => 'Captura de leads por WhatsApp', 'default' => 1 ),
			'welcome_message'      => array( 'type' => 'textarea', 'label' => 'Mensaje de bienvenida', 'desc' => 'Se envía una vez al iniciar una conversación nueva (antes de la primera respuesta). Vacío = no se envía.', 'rows' => 3, 'default' => '' ),
			'fallback_reply'       => array( 'type' => 'text', 'label' => 'Respuesta de respaldo', 'desc' => 'Se usa cuando el motor IA falla y no aplica la captura de datos.', 'default' => 'Disculpa, en este momento no puedo responder. Intenta en unos minutos.' ),
			'rate_limit_per_hour'  => array( 'type' => 'number', 'label' => 'Tope de mensajes por número/hora', 'desc' => '0 = sin tope. Protege el presupuesto de IA.', 'min' => 0, 'max' => 1000, 'default' => 200 ),
			'debug_log'            => array( 'type' => 'bool', 'label' => 'Registrar payloads del webhook (debug)', 'default' => 0 ),
			'attribution_enabled'  => array( 'type' => 'bool', 'label' => 'Atribución de WhatsApp', 'desc' => 'Identifica el click al botón de WhatsApp: enriquece los enlaces wa.me con el click ID de Google Ads (ref:gclid|gbraid|wbraid) para atribuir los cierres de WhatsApp al anuncio.', 'default' => 0 ),
		);
	}

	public static function legacy_settings(): array
	{
		$legacy = array();
		$cb     = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		if ( is_array( $cb ) ) {
			$legacy['enabled']          = (int) ( $cb['whatsapp_enabled'] ?? 0 );
			$legacy['phone_number_id']  = (string) ( $cb['whatsapp_phone_number_id'] ?? '' );
			$legacy['access_token']     = (string) ( $cb['whatsapp_access_token'] ?? '' );
			$legacy['verify_token']     = (string) ( $cb['whatsapp_verify_token'] ?? '' );
		}
		return $legacy;
	}

	public static function settings(): array
	{
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), self::legacy_settings(), $saved );
	}

	public static function get( string $key, $default = null )
	{
		$s = self::settings();
		return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
	}

	public static function enabled(): bool
	{
		return (int) self::get( 'enabled' ) === 1;
	}

	public static function access_token(): string
	{
		return (string) self::get( 'access_token' );
	}

	public static function phone_number_id(): string
	{
		return (string) self::get( 'phone_number_id' );
	}

	public static function verify_token(): string
	{
		return (string) self::get( 'verify_token' );
	}

	public static function app_secret(): string
	{
		return (string) self::get( 'app_secret' );
	}

	public static function webhook_url(): string
	{
		if ( function_exists( 'rest_url' ) ) {
			return rest_url( 'estandar-suite/capture/v1/whatsapp/webhook' );
		}
		return '';
	}

	public static function save_settings( array $raw ): array
	{
		$fields = self::fields();
		$cur    = self::settings();
		$clean  = array();
		foreach ( $fields as $key => $cfg ) {
			$type = $cfg['type'] ?? 'text';
			if ( $type === 'bool' ) {
				$clean[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
			} elseif ( $type === 'number' ) {
				$min = (int) ( $cfg['min'] ?? 0 );
				$max = (int) ( $cfg['max'] ?? 1000 );
				$val = (int) ( $raw[ $key ] ?? ( $cfg['default'] ?? 0 ) );
				$clean[ $key ] = max( $min, min( $max, $val ) );
			} elseif ( $type === 'textarea' ) {
				$clean[ $key ] = sanitize_textarea_field( (string) ( $raw[ $key ] ?? '' ) );
			} elseif ( $type === 'secret' ) {
				$v = trim( (string) ( $raw[ $key ] ?? '' ) );
				$clean[ $key ] = $v !== '' ? sanitize_text_field( $v ) : (string) ( $cur[ $key ] ?? '' );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
			}
		}
		update_option( self::SETTINGS_OPTION, $clean, false );
		return $clean;
	}

	public static function session_key( string $phone ): string
	{
		$digits = preg_replace( '/\D+/', '', $phone );
		return 'wa_' . substr( (string) $digits, 0, 20 );
	}

	public static function diagnostics(): array
	{
		$diag = get_option( self::DIAG_OPTION, array() );
		return is_array( $diag ) ? $diag : array();
	}

	public static function record_diag( array $patch ): void
	{
		$diag = self::diagnostics();
		foreach ( $patch as $k => $v ) {
			$diag[ $k ] = $v;
		}
		$diag['messages_processed'] = (int) ( $diag['messages_processed'] ?? 0 );
		update_option( self::DIAG_OPTION, $diag, false );
	}

	public static function stats(): array
	{
		$conv = array();
		if ( method_exists( 'Estandar\Capture\ChatConversation', 'conversations' ) ) {
			foreach ( ChatConversation::conversations( 500 ) as $c ) {
				if ( ( $c['channel'] ?? '' ) === self::CHANNEL ) {
					$conv[] = $c;
				}
			}
		}
		return array( 'conversations' => $conv );
	}

	public static function health(): array
	{
		$checks = array();
		$enabled = self::enabled();
		$checks['config'] = array(
			'label'  => 'Configuración',
			'status' => $enabled ? 'Activado' : 'Desactivado',
			'desc'   => $enabled ? 'El bot responde mensajes entrantes.' : 'Actívalo en este panel para responder.',
			'level'  => $enabled ? 'ok' : 'warn',
		);
		$token = self::access_token();
		$checks['token'] = array(
			'label'  => 'Access Token',
			'status' => $token !== '' ? 'Configurado' : 'Falta',
			'desc'   => $token !== '' ? 'Permite enviar respuestas por la Cloud API.' : 'Pégalo en el panel para poder responder.',
			'level'  => $token !== '' ? 'ok' : 'bad',
		);
		$phone_id = self::phone_number_id();
		$checks['phone'] = array(
			'label'  => 'Phone Number ID',
			'status' => $phone_id !== '' ? 'Configurado' : 'Falta',
			'desc'   => $phone_id !== '' ? 'Número de negocio conectado.' : 'Indica el Phone Number ID de tu número de WhatsApp.',
			'level'  => $phone_id !== '' ? 'ok' : 'bad',
		);
		$verify = self::verify_token();
		$checks['verify'] = array(
			'label'  => 'Verify Token',
			'status' => $verify !== '' ? 'Configurado' : 'Falta',
			'desc'   => $verify !== '' ? 'Listo para verificar el webhook en Meta.' : 'Defínelo y úsalo igual en Meta for Developers.',
			'level'  => $verify !== '' ? 'ok' : 'warn',
		);
		$checks['webhook'] = array(
			'label'  => 'Webhook',
			'status' => 'URL lista',
			'desc'   => self::webhook_url(),
			'level'  => 'ok',
		);
		$provider = AiChat::provider();
		$ai_key   = AiChat::api_key( $provider );
		$checks['ai'] = array(
			'label'  => 'Motor IA',
			'status' => $ai_key !== '' ? 'Conectado (' . $provider . ')' : 'Sin clave',
			'desc'   => $ai_key !== '' ? 'Mismo motor y comportamiento que el chatbot web.' : 'Agrega la clave en Core → Configuración → Claves API.',
			'level'  => $ai_key !== '' ? 'ok' : 'bad',
		);
		return array( 'checks' => $checks, 'diag' => self::diagnostics() );
	}

	public static function verify_webhook( array $query ): ?string
	{
		$mode      = (string) ( $query['hub_mode'] ?? '' );
		$token     = (string) ( $query['hub_verify_token'] ?? '' );
		$challenge = (string) ( $query['hub_challenge'] ?? '' );
		if ( $mode === 'subscribe' && $token !== '' && $challenge !== '' ) {
			$expected = self::verify_token();
			if ( $expected !== '' && hash_equals( $expected, $token ) ) {
				return $challenge;
			}
		}
		return null;
	}

	public static function signature_valid( string $header, string $raw_body ): bool
	{
		$secret = self::app_secret();
		if ( $secret === '' ) {
			return true;
		}
		if ( strpos( $header, 'sha256=' ) !== 0 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		return hash_equals( $expected, substr( $header, 7 ) );
	}

	public static function parse_payload( array $payload ): array
	{
		$out = array();
		foreach ( $payload['entry'] ?? array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( $entry['changes'] ?? array() as $change ) {
				if ( ! is_array( $change ) || ( $change['field'] ?? '' ) !== 'messages' ) {
					continue;
				}
				$value = is_array( $change['value'] ?? null ) ? $change['value'] : array();
				foreach ( $value['messages'] ?? array() as $m ) {
					if ( ! is_array( $m ) ) {
						continue;
					}
					$type = (string) ( $m['type'] ?? '' );
					if ( $type !== 'text' && $type !== 'interactive' ) {
						continue;
					}
					$id   = sanitize_text_field( (string) ( $m['id'] ?? '' ) );
					$from = preg_replace( '/\D+/', '', (string) ( $m['from'] ?? '' ) );
					$text = '';
					if ( $type === 'text' ) {
						$text = trim( (string) ( $m['text']['body'] ?? '' ) );
					} elseif ( $type === 'interactive' ) {
						$text = trim( (string) ( $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '' ) );
					}
					$ts = (string) ( $m['timestamp'] ?? '' );
					if ( $id === '' || $from === '' || $text === '' ) {
						continue;
					}
					$out[] = array( 'id' => $id, 'from' => $from, 'text' => $text, 'ts' => $ts );
				}
			}
		}
		return $out;
	}

	public static function handle_payload( array $payload ): array
	{
		$messages = self::parse_payload( $payload );
		self::record_diag( array( 'last_webhook_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( (int) self::get( 'debug_log' ) === 1 ) {
			error_log( '[estandar-whatsapp] webhook: ' . wp_json_encode( $payload ) );
		}
		$processed = 0;
		$replies   = 0;
		$skipped   = 0;
		$errors    = 0;
		foreach ( $messages as $msg ) {
			$processed++;
			$key = self::MSG_PREFIX . (string) $msg['id'];
			if ( get_transient( $key ) ) {
				$skipped++;
				continue;
			}
			set_transient( $key, 1, HOUR_IN_SECONDS );
			$r = self::process_message( $msg );
			if ( ! empty( $r['replied'] ) ) {
				$replies++;
			}
			if ( ! empty( $r['error'] ) ) {
				$errors++;
				self::record_diag( array( 'last_error' => (string) $r['error'], 'last_error_ts' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		}
		return array(
			'processed' => $processed,
			'replies'   => $replies,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'status'    => 'ok',
		);
	}

	public static function process_message( array $msg ): array
	{
		$from        = (string) ( $msg['from'] ?? '' );
		$text        = (string) ( $msg['text'] ?? '' );
		$session_key = self::session_key( $from );
		if ( $from === '' || $text === '' ) {
			return array( 'replied' => false, 'error' => 'mensaje sin destinatario o texto.' );
		}
		self::record_diag( array( 'last_message_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! self::enabled() ) {
			ChatConversation::append( $session_key, self::CHANNEL, 'user', $text );
			return array( 'replied' => false, 'session_key' => $session_key, 'error' => 'bot desactivado.' );
		}
		if ( ! self::rate_ok( $from ) ) {
			$reply = (string) self::get( 'fallback_reply' );
			$sent  = self::send_text( $from, $reply );
			return array( 'replied' => ! empty( $sent['ok'] ), 'error' => $sent['error'] ?? null, 'session_key' => $session_key );
		}
		$r    = self::compute_reply( $text, $session_key );
		$sent = self::send_text( $from, (string) $r['reply'] );
		return array(
			'replied'    => ! empty( $sent['ok'] ),
			'lead_id'    => (int) ( $r['lead_id'] ?? 0 ),
			'error'      => $sent['error'] ?? null,
			'session_key'=> $session_key,
		);
	}

	public static function compute_reply( string $text, string $session_key ): array
	{
		$history = self::build_history( $session_key );
		ChatConversation::append( $session_key, self::CHANNEL, 'user', $text );
		$lead = null;
		if ( (int) self::get( 'lead_capture_enabled' ) === 1 ) {
			$lead = self::maybe_capture_lead( $text, $session_key );
		}
		$messages = self::build_messages( $text, $session_key, $history );
		$r = AiChat::chat( $messages, AiChatPrompt::chat_opts() );
		if ( ! empty( $r['error'] ) ) {
			$det   = self::deterministic_lead_reply( $text, $session_key );
			$reply = (string) ( $det['content'] ?? '' );
		} else {
			$reply = (string) ( $r['content'] ?? '' );
		}
		if ( $reply === '' ) {
			$reply = (string) self::get( 'fallback_reply' );
		}
		ChatConversation::append( $session_key, self::CHANNEL, 'assistant', $reply );
		ChatLog::log( $session_key, self::CHANNEL, $text, $reply );
		return array( 'reply' => $reply, 'lead_id' => (int) ( $lead['capture']['id'] ?? $lead['id'] ?? 0 ) );
	}

	public static function build_history( string $session_key ): array
	{
		$limit = (int) self::get( 'conversation_history' );
		if ( $limit <= 0 ) {
			return array();
		}
		$rows = ChatConversation::get_messages( $session_key, self::CHANNEL, $limit * 2 );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array( 'role' => (string) $row['role'], 'content' => (string) $row['content'] );
		}
		return $out;
	}

	public static function build_messages( string $text, string $session_key, array $history = array() ): array
	{
		$messages = array( array( 'role' => 'system', 'content' => AiChatPrompt::system() ) );
		foreach ( $history as $h ) {
			if ( ! isset( $h['role'], $h['content'] ) ) {
				continue;
			}
			$messages[] = array( 'role' => sanitize_key( (string) $h['role'] ), 'content' => sanitize_textarea_field( (string) $h['content'] ) );
		}
		$messages[] = array( 'role' => 'user', 'content' => $text );
		return $messages;
	}

	public static function rate_ok( string $from ): bool
	{
		$limit = (int) self::get( 'rate_limit_per_hour' );
		if ( $limit <= 0 ) {
			return true;
		}
		$key  = self::RL_PREFIX . preg_replace( '/\D+/', '', $from );
		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return false;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return true;
	}

	public static function collect_contact( string $text, string $session_key ): array
	{
		$ctx   = get_option( self::CTX_PREFIX . $session_key, array() );
		$ctx   = is_array( $ctx ) ? $ctx : array();
		$email = (string) ( $ctx['email'] ?? '' );
		$phone = (string) ( $ctx['phone'] ?? '' );
		$name  = (string) ( $ctx['name'] ?? '' );

		if ( $email === '' && preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m ) ) {
			$email = sanitize_email( $m[0] );
		}
		if ( $phone === '' && preg_match( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', $text, $m ) ) {
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
			$c = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', ' ', $text );
			$c = preg_replace( '/(?:\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}/', ' ', $c );
			$c = preg_replace( '/\b(me llamo|mi nombre es|soy|llamo)\b/i', ' ', $c );
			$c = preg_replace( '/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s]/u', ' ', $c );
			$words = array_values( array_filter( preg_split( '/\s+/', trim( $c ) ) ) );
			$cands = array();
			foreach ( $words as $w ) {
				$wl = function_exists( 'mb_strtolower' ) ? mb_strtolower( $w, 'UTF-8' ) : strtolower( $w );
				if ( preg_match( '/^(hola|buenas|necesito|quiero|informacion|información|precio|precios|casa|departamento|contacto|asesor|ayuda|gracias|soy|es|mi|me|por|favor|telefono|teléfono|numero|número|email|correo|whatsapp|celular|movil|móvil|escribir|comunicar)$/', $wl ) ) {
					continue;
				}
				if ( preg_match( '/^[A-Za-zÁÉÍÓÚáéíóúÑñ]{2,}$/u', $w ) ) {
					$cands[] = function_exists( 'mb_convert_case' ) ? mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ) : ucwords( strtolower( $w ) );
				}
				if ( count( $cands ) >= 2 ) {
					break;
				}
			}
			if ( $cands ) {
				$name = implode( ' ', $cands );
			}
		}

		$ctx = array(
			'email'   => $email,
			'phone'   => $phone,
			'name'    => $name,
			'message' => $text,
		);
		update_option( self::CTX_PREFIX . $session_key, $ctx, false );
		return array(
			'email'    => $email,
			'phone'    => $phone,
			'name'     => $name,
			'message'  => $text,
			'complete' => $email !== '' && $phone !== '' && $name !== '',
		);
	}

	public static function maybe_capture_lead( string $text, string $session_key ): ?array
	{
		if ( get_option( self::LEAD_PREFIX . $session_key, 0 ) ) {
			return null;
		}
		$ctx = self::collect_contact( $text, $session_key );
		if ( empty( $ctx['complete'] ) ) {
			return null;
		}
		update_option( self::LEAD_PREFIX . $session_key, 1, false );
		return self::ingest_lead( $ctx, $session_key );
	}

	public static function deterministic_lead_reply( string $message, string $session_key ): array
	{
		$ctx = self::collect_contact( $message, $session_key );
		if ( $ctx['email'] === '' ) {
			return array( 'content' => 'Claro, te ayudo. Para que un asesor te contacte, primero necesito tu correo electrónico. ¿Cuál es?' );
		}
		if ( $ctx['phone'] === '' ) {
			return array( 'content' => 'Perfecto. ¿Y tu número de teléfono (idealmente con WhatsApp)?' );
		}
		if ( $ctx['name'] === '' ) {
			return array( 'content' => '¿Y tu nombre?' );
		}
		if ( ! get_option( self::LEAD_PREFIX . $session_key, 0 ) ) {
			update_option( self::LEAD_PREFIX . $session_key, 1, false );
			$result  = self::ingest_lead( $ctx, $session_key );
			$lead_id = (int) ( $result['capture']['id'] ?? $result['id'] ?? 0 );
			return array(
				'content' => '¡Gracias ' . $ctx['name'] . '! Ya quedó registrado tu contacto (' . $ctx['email'] . ' · ' . $ctx['phone'] . '). Un asesor se pondrá en contacto contigo a la brevedad.',
				'lead_id' => $lead_id,
			);
		}
		return array( 'content' => '¡Gracias! Ya recibí tu contacto. Un asesor te contactará pronto.' );
	}

	public static function ingest_lead( array $ctx, string $session_key ): array
	{
		if ( ! class_exists( 'Estandar\Capture\Ingestion' ) ) {
			return array();
		}
		$result = Ingestion::ingest( array(
			'source'       => 'whatsapp',
			'submitted_at' => gmdate( 'c' ),
			'session_id'   => $session_key,
			'contact'      => array(
				'name'  => (string) ( $ctx['name'] ?? '' ),
				'email' => (string) ( $ctx['email'] ?? '' ),
				'phone' => (string) ( $ctx['phone'] ?? '' ),
			),
			'content'      => array(
				'message'        => (string) ( $ctx['message'] ?? '' ),
				'source_form_id' => 'whatsapp',
			),
			'context'      => array(
				'intent'  => 'contact',
				'channel' => self::CHANNEL,
			),
			'attribution'  => array(
				'channel'     => self::CHANNEL,
				'landing_url' => '',
			),
		) );
		return is_array( $result ) ? $result : array();
	}

	public static function send_text( string $to, string $text ): array
	{
		$phone_id = self::phone_number_id();
		$token    = self::access_token();
		if ( $phone_id === '' || $token === '' ) {
			return array( 'ok' => false, 'error' => 'WhatsApp no configurado (Phone Number ID y Access Token).' );
		}
		$last = array( 'ok' => false, 'error' => 'sin envíos' );
		foreach ( self::split_text( $text ) as $chunk ) {
			$last = self::graph_request( 'messages', array(
				'messaging_product' => 'whatsapp',
				'to'                => $to,
				'type'              => 'text',
				'text'              => array( 'body' => $chunk ),
			) );
			if ( empty( $last['ok'] ) ) {
				break;
			}
			self::record_diag( array( 'last_send_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		return $last;
	}

	public static function split_text( string $text ): array
	{
		$text = trim( $text );
		if ( $text === '' ) {
			return array();
		}
		$has_mb = function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' );
		$len    = $has_mb ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= 4096 ) {
			return array( $text );
		}
		$chunks = array();
		$buf    = '';
		$lines  = preg_split( '/\n/', $text );
		foreach ( $lines as $line ) {
			$candidate = $buf === '' ? $line : $buf . "\n" . $line;
			$clen      = $has_mb ? mb_strlen( $candidate ) : strlen( $candidate );
			if ( $clen > 4096 ) {
				if ( $buf !== '' ) {
					$chunks[] = $buf;
					$buf      = '';
				}
				while ( ( $has_mb ? mb_strlen( $line ) : strlen( $line ) ) > 4096 ) {
					$chunks[] = $has_mb ? mb_substr( $line, 0, 4096 ) : substr( $line, 0, 4096 );
					$line     = $has_mb ? mb_substr( $line, 4096 ) : substr( $line, 4096 );
				}
				$buf = $line;
			} else {
				$buf = $candidate;
			}
		}
		if ( $buf !== '' ) {
			$chunks[] = $buf;
		}
		return $chunks;
	}

	public static function graph_request( string $endpoint, array $body ): array
	{
		$phone_id = self::phone_number_id();
		$token    = self::access_token();
		if ( $phone_id === '' || $token === '' ) {
			return array( 'ok' => false, 'error' => 'WhatsApp no configurado.' );
		}
		$url = self::GRAPH_BASE . '/' . self::GRAPH_VERSION . '/' . rawurlencode( $phone_id ) . '/' . ltrim( $endpoint, '/' );
		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'         => true,
				'status'     => $status,
				'body'       => $data,
				'message_id' => (string) ( $data['messages'][0]['id'] ?? '' ),
			);
		}
		return array(
			'ok'     => false,
			'status' => $status,
			'error'  => (string) ( $data['error']['message'] ?? 'Error de la API de WhatsApp.' ),
			'body'   => $data,
		);
	}

	public static function test_connection(): array
	{
		$phone_id = self::phone_number_id();
		$token    = self::access_token();
		if ( $phone_id === '' || $token === '' ) {
			return array( 'ok' => false, 'error' => 'Configura Phone Number ID y Access Token primero.' );
		}
		$url = self::GRAPH_BASE . '/' . self::GRAPH_VERSION . '/' . rawurlencode( $phone_id )
			. '?fields=id,display_phone_number,verified_name,quality_rating,messaging_product';
		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		if ( $status >= 200 && $status < 300 ) {
			return array( 'ok' => true, 'status' => $status, 'data' => $data );
		}
		return array( 'ok' => false, 'status' => $status, 'error' => (string) ( $data['error']['message'] ?? 'No se pudo conectar.' ) );
	}

	public static function send_test( string $phone ): array
	{
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( $digits === '' ) {
			return array( 'ok' => false, 'error' => 'Número inválido.' );
		}
		return self::send_text( $digits, 'Hola, esto es una prueba del bot de WhatsApp de la Suite. Si recibes este mensaje, la conexión funciona.' );
	}

	public static function webhook_verify( \WP_REST_Request $request )
	{
		$challenge = self::verify_webhook( $_GET );
		if ( $challenge !== null ) {
			header( 'Content-Type: text/plain' );
			status_header( 200 );
			echo $challenge;
			die;
		}
		status_header( 403 );
		echo 'Verification failed';
		die;
	}

	public static function webhook_handle( \WP_REST_Request $request )
	{
		$raw       = (string) $request->get_body();
		$signature = (string) ( $request->get_header( 'x_hub_signature_256' ) ?? '' );
		if ( self::app_secret() !== '' && ! self::signature_valid( $signature, $raw ) ) {
			return new \WP_Error( 'estandar_suite_wa_signature', 'Invalid signature', array( 'status' => 403 ) );
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'estandar_suite_wa_payload', 'Invalid payload', array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::handle_payload( $payload ) );
	}
}
