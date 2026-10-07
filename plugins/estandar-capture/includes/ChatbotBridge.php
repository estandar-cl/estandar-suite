<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatbotBridge — fusion edge chatbot legacy ↔ capture v2.
 *
 * Escucha la emisión canónica `estandar_lead_captured` del plugin legado
 * estandar-chatbot (4 emisores: web, IA directo, WhatsApp handoff) y la
 * ingesta en el pipeline unificado de Capture (`estandar_capture_submissions`
 * + evento `estandar_suite_capture_event` consumido por Brain/Flow/Insights).
 *
 * El chatbot NO se modifica en este modo: sigue siendo el escritor de sus
 * tablas y el emisor del contrato legacy; Capture se suma como hub de datos
 * (dual-write durante la coexistencia, igual que forms).
 *
 * Guards:
 *  - Anti-bucle: los payloads re-emitidos por `LeadsBridge` llevan
 *    `meta._capture_origin` y se ignoran (ya están en submissions).
 *  - Anti-duplicado en modo `pipeline=capture`: cuando el chatbot delega el
 *    almacenamiento a `delegate_save()`, la emisión posterior del mismo lead
 *    (mismo request, identidad normalizada) se salta para no duplicar filas.
 *
 * Contrato de salida de `delegate_save()`: compatible con
 * `estandar_chatbot_save_lead()` ({status, lead_id, lead}).
 */
class ChatbotBridge
{
	const SOURCE = 'chatbot';

	/** Option con estadísticas de puente (observabilidad, sin datos sensibles). */
	const STATS_OPTION = 'estandar_suite_capture_chatbot_bridge_stats';

	/** Identidades ya delegadas en este request (pipeline=capture). */
	private static $delegated = array();

	public static function init(): void
	{
		add_action( 'estandar_lead_captured', array( __CLASS__, 'bridge_lead' ), 9, 1 );
	}

	/** ¿El plugin legacy estandar-chatbot está activo? */
	public static function legacy_chatbot_active(): bool
	{
		return function_exists( 'estandar_chatbot_save_lead' )
			|| defined( 'ESTANDAR_CHATBOT_VERSION' )
			|| class_exists( 'Estandar_Chatbot_Loader' );
	}

	/** Hook: cada lead del chatbot legacy → pipeline v2. */
	public static function bridge_lead( $payload ): void
	{
		if ( ! is_array( $payload ) ) {
			return;
		}
		// Re-emisión de LeadsBridge (captura nativa ya persistida): ignorar.
		if ( ! empty( $payload['meta']['_capture_origin'] ) ) {
			return;
		}
		// Modo pipeline=capture: el mismo lead ya fue delegado (mismo request).
		$dedupe = self::contact_key( $payload['contact'] ?? array() );
		if ( $dedupe !== '' && isset( self::$delegated[ $dedupe ] ) ) {
			return;
		}

		$raw = self::map_payload( $payload );
		$result = Ingestion::ingest( $raw );

		if ( (int) $result['id'] > 0 ) {
			$capture = $result['capture'];
			do_action( 'estandar_suite_chatbot_lead_bridged', $capture, $payload );
			self::bump_stats( (int) $result['id'], $payload );
		}
	}

	/**
	 * Mapea el payload legacy (contrato `estandar_lead_captured`) a un array
	 * bruto de `Ingestion`. PURE → unit-testable.
	 */
	public static function map_payload( array $payload ): array
	{
		$contact   = is_array( $payload['contact'] ?? null ) ? $payload['contact'] : array();
		$content   = is_array( $payload['content'] ?? null ) ? $payload['content'] : array();
		$context   = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();
		$chatbot   = is_array( $payload['chatbot'] ?? null ) ? $payload['chatbot'] : array();
		$scoring   = is_array( $payload['scoring'] ?? null ) ? $payload['scoring'] : array();
		$spam      = is_array( $payload['spam'] ?? null ) ? $payload['spam'] : array();
		$meta      = is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array();
		$request   = is_array( $payload['request'] ?? null ) ? $payload['request'] : array();
		$attrib    = is_array( $payload['attribution'] ?? null ) ? $payload['attribution'] : array();

		$session_id = (string) ( $attrib['session_id'] ?? '' );
		if ( $session_id === '' ) {
			$session_id = (string) ( $chatbot['chatbot_session_key'] ?? '' );
		}

		// El contenido rico del lead se preserva en fields_json para
		// Brain/Flow/Insights y para auditoría (jamás en columnas técnicas).
		$fields = array(
			'chatbot'              => $chatbot,
			'context'              => $context,
			'scoring'              => $scoring,
			'spam'                 => $spam,
			'meta'                 => $meta,
			'schema_version'       => (string) ( $payload['schema_version'] ?? '1.0' ),
			'source_plugin'        => (string) ( $payload['source_plugin'] ?? 'estandar-chatbot' ),
			'submitted_at'         => (string) ( $payload['submitted_at'] ?? '' ),
			'source_channel'       => (string) ( $context['source_channel'] ?? $meta['source_channel'] ?? '' ),
			'conversation_summary' => (string) ( $context['conversation_summary'] ?? $meta['conversation_summary'] ?? '' ),
			'last_user_message'    => (string) ( $context['last_user_message'] ?? $meta['last_user_message'] ?? '' ),
		);
		if ( ! empty( $meta['conversation_recent'] ) ) {
			$fields['conversation_recent'] = (string) $meta['conversation_recent'];
		}
		if ( ! empty( $context['intent'] ) ) {
			$fields['intent'] = sanitize_key( (string) $context['intent'] );
		}
		if ( ! empty( $context['lead_intent'] ) ) {
			$fields['lead_intent'] = sanitize_key( (string) $context['lead_intent'] );
		}
		if ( ! empty( $context['intent_category'] ) ) {
			$fields['intent_category'] = sanitize_key( (string) $context['intent_category'] );
		}
		if ( isset( $context['intent_mismatch'] ) ) {
			$fields['intent_mismatch'] = (bool) $context['intent_mismatch'];
		}
		if ( ! empty( $context['project_slug'] ) ) {
			$fields['project_slug'] = sanitize_key( (string) $context['project_slug'] );
		}
		if ( ! empty( $context['project_area'] ) ) {
			$fields['project_area'] = sanitize_text_field( (string) $context['project_area'] );
		}
		if ( ! empty( $context['city'] ) ) {
			$fields['city'] = sanitize_text_field( (string) $context['city'] );
		}
		if ( ! empty( $chatbot['force_human'] ) ) {
			$fields['force_human'] = (bool) $chatbot['force_human'];
		}
		if ( ! empty( $chatbot['escalation_requested'] ) ) {
			$fields['escalation_requested'] = (bool) $chatbot['escalation_requested'];
			if ( ! empty( $chatbot['escalation_channel'] ) ) {
				$fields['escalation_channel'] = sanitize_key( (string) $chatbot['escalation_channel'] );
			}
		}

		$source_form_id = (string) ( $content['source_form_id'] ?? 'chatbot' );
		$source_form_id = $source_form_id === '' ? 'chatbot' : $source_form_id;

		return array(
			'source'         => self::SOURCE,
			'source_form_id' => $source_form_id,
			'contact'        => array(
				'name'  => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ),
			),
			'content'        => array(
				'message'        => sanitize_textarea_field( (string) ( $content['message'] ?? '' ) ),
				'source_form_id' => $source_form_id,
			),
			'fields'         => $fields,
			'attribution'    => array(
				'utm_source'   => (string) ( $attrib['utm_source'] ?? '' ),
				'utm_medium'   => (string) ( $attrib['utm_medium'] ?? '' ),
				'utm_campaign' => (string) ( $attrib['utm_campaign'] ?? '' ),
				'utm_term'     => (string) ( $attrib['utm_term'] ?? '' ),
				'utm_content'  => (string) ( $attrib['utm_content'] ?? '' ),
				'landing_url'  => (string) ( $attrib['landing_url'] ?? '' ),
				'referrer'     => (string) ( $attrib['referrer'] ?? '' ),
				'session_id'   => $session_id,
			),
			'ip'             => (string) ( $request['ip_address'] ?? '' ),
			'session_id'     => $session_id,
			'spam'           => array(
				'honeypot_filled' => ! empty( $spam['honeypot_filled'] ),
			),
		);
	}

	/**
	 * Modo pipeline=capture: el chatbot delega el almacenamiento aquí.
	 * NO escribe `ecb_leads`; persiste en capture y devuelve el contrato de
	 * `estandar_chatbot_save_lead()` para que el emisor legacy siga intacto.
	 */
	public static function delegate_save( array $clean ): array
	{
		$email = isset( $clean['email'] ) ? strtolower( trim( (string) $clean['email'] ) ) : '';
		$phone = Ingestion::normalize_phone( (string) ( $clean['phone'] ?? '' ) );
		$key   = md5( $email . '|' . $phone );
		self::$delegated[ $key ] = true;

		$channel = sanitize_key( (string) ( $clean['source_channel'] ?? 'web' ) );

		$fields = $clean;
		unset( $fields['name'], $fields['email'], $fields['phone'], $fields['email_raw'], $fields['phone_raw'], $fields['source_channel'] );

		$raw = array(
			'source'       => self::SOURCE,
			'source_form_id' => 'chatbot',
			'contact'      => array(
				'name'  => sanitize_text_field( (string) ( $clean['name'] ?? '' ) ),
				'email' => $email,
				'phone' => (string) ( $clean['phone'] ?? '' ),
			),
			'content'      => array(
				'message'        => sanitize_textarea_field( (string) ( $clean['requirement'] ?? $clean['message'] ?? '' ) ),
				'source_form_id' => 'chatbot',
			),
			'fields'       => $fields,
			'attribution'  => array(
				'landing_url' => esc_url_raw( (string) ( $clean['source_page_url'] ?? '' ) ),
				'session_id'  => (string) ( $clean['analytics_session_id'] ?? $clean['session_id'] ?? '' ),
			),
			'ip'           => '',
			'session_id'   => (string) ( $clean['analytics_session_id'] ?? $clean['session_id'] ?? '' ),
			'spam'         => array( 'honeypot_filled' => false ),
		);
		$raw['fields']['source_channel'] = $channel;

		$result = Ingestion::ingest( $raw );
		$id     = (int) $result['id'];

		if ( $id > 0 ) {
			do_action( 'estandar_suite_chatbot_lead_bridged', $result['capture'], array( 'delegated' => true ) );
			self::bump_stats( $id, array() );
		}

		return array(
			'status'  => $id > 0 ? 'created' : 'error',
			'code'    => $id > 0 ? '' : 'capture_unavailable',
			'lead_id' => $id,
			'lead'    => array_merge( $clean, array( 'capture_lead_id' => $id ) ),
			'message' => $id > 0 ? 'Lead capturado vía Capture.' : 'No se pudo capturar el lead en Capture.',
		);
	}

	/** Clave de identidad normalizada para deduplicación. */
	public static function contact_key( array $contact ): string
	{
		$email = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
		$phone = Ingestion::normalize_phone( (string) ( $contact['phone'] ?? '' ) );
		if ( $email === '' && $phone === '' ) {
			return '';
		}
		return md5( $email . '|' . $phone );
	}

	private static function bump_stats( int $id, array $payload ): void
	{
		$stats = get_option( self::STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$stats['total']    = (int) ( $stats['total'] ?? 0 ) + 1;
		$stats['last_id']  = $id;
		$stats['last_ts']  = gmdate( 'Y-m-d H:i:s' );
		$stats['last_channel'] = sanitize_key( (string) ( $payload['context']['source_channel'] ?? $payload['meta']['source_channel'] ?? '' ) );
		update_option( self::STATS_OPTION, $stats, false );
	}

	/** Estadísticas del puente (lectura). */
	public static function stats(): array
	{
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}
}
