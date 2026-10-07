<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadsBridge — puente opt-in capture v2 → contrato legacy `estandar_lead_captured`.
 *
 * Pre-requisito identificado en sesiones 006/007: Capture hoy NO emite
 * `estandar_lead_captured`, por lo que en sitios con CRM legacy (estandar-leads
 * → `es_leads` + notificación al operador) retirar un capturador legacy rompería
 * la entrada de leads. Este puente re-emite el contrato legacy para las capturas
 * NATIVAS v2 (form/survey/incident/popup/rest) cuando:
 *   - el puente está habilitado (opt-in, `estandar_suite_capture_leads_bridge`), y
 *   - estandar-leads está activo, y
 *   - el origen NO es `chatbot` (ese ya alimenta es_leads por su propia emisión).
 *
 * Guards:
 *  - Estático $emitting anti-bucle (no re-emite dentro de su propia emisión).
 *  - Marca `meta._capture_origin` para que ChatbotBridge ignore el eco
 *    (la captura nativa ya está en submissions; no debe duplicarse).
 */
class LeadsBridge
{
	const OPTION = 'estandar_suite_capture_leads_bridge';

	private static $emitting = false;

	public static function init(): void
	{
		add_action( 'estandar_suite_capture_event', array( __CLASS__, 'on_capture_event' ), 20, 1 );
	}

	/** ¿Puente habilitado (opt-in)? */
	public static function enabled(): bool
	{
		return get_option( self::OPTION, '0' ) === '1';
	}

	public static function set_enabled( bool $on ): void
	{
		update_option( self::OPTION, $on ? '1' : '0', false );
	}

	/** ¿estandar-leads legacy está activo? */
	public static function leads_plugin_active(): bool
	{
		return defined( 'ESTANDAR_LEADS_VERSION' )
			|| class_exists( 'Estandar_Leads_Ingestion' )
			|| function_exists( 'estandar_leads_capture' );
	}

	/** ¿Hay un escritor de es_leads (legacy o el CrmIngestion v2)? */
	public static function writer_available(): bool
	{
		if ( self::leads_plugin_active() ) {
			return true;
		}
		// El CrmIngestion v2 escribe SOLO cuando el legacy está ausente (gate interno).
		return class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' );
	}

	/** ¿Esta captura debe re-emitirse al contrato legacy? (PURE) */
	public static function should_emit( array $capture ): bool
	{
		if ( ! self::enabled() || ! self::writer_available() || self::$emitting ) {
			return false;
		}
		$source = sanitize_key( (string) ( $capture['source'] ?? '' ) );
		if ( $source === '' || $source === 'chatbot' ) {
			// chatbot ya alimenta es_leads por su propia emisión.
			return false;
		}
		// Postventa (puente GF→Capture y nativos incident) NO crea leads de CRM:
		// sus submissions viven en estandar_capture_submissions / Panel Postventa.
		$form_id = sanitize_text_field( (string) ( $capture['source_form_id'] ?? '' ) );
		if ( $source === 'gf_postventa' || strpos( $form_id, 'gf-' ) === 0 ) {
			return false;
		}
		// Form nativo: decide si es un form de LEADS.
		//   - form_type='incident' (postventa nativa legada) → NO es de leads.
		//   - LEAD_FORM_SETTING_V1: "Solo correo simple del form"
		//     (form_funnel_mode='simple') = form que NO es de leads → NO entra al
		//     CRM ni dispara el correo del operador (es una consecuencia de no
		//     pasar por leads). Siguen el correo del form/contrato, la
		//     autorespuesta y el webhook (viven en Actions::run, no aquí).
		if ( is_numeric( $form_id ) && (int) $form_id > 0 && class_exists( 'Estandar\\Capture\\Forms' ) ) {
			$nf = \Estandar\Capture\Forms::get( (int) $form_id );
			if ( is_array( $nf ) ) {
				if ( (string) ( $nf['form_type'] ?? '' ) === 'incident' ) {
					return false;
				}
				$ncfg = json_decode( (string) ( $nf['config_json'] ?? '' ), true );
				if ( is_array( $ncfg ) && (string) ( $ncfg['form_funnel_mode'] ?? '' ) === 'simple' ) {
					return false;
				}
			}
		}
		// Runtime: el modo capturado (config del form o selección de un campo
		// 'routing') manda igual — 'simple' = no es de leads.
		$fields = json_decode( (string) ( $capture['fields_json'] ?? '' ), true );
		$fields = is_array( $fields ) ? $fields : array();
		if ( (string) ( $fields['_form_funnel_mode'] ?? '' ) === 'simple' ) {
			return false;
		}
		if ( ! empty( $fields['_bridge_origin'] ) ) {
			return false;
		}
		// Sin identidad → estandar-leads lo rechazaría igual; no re-emitir.
		return trim( (string) ( $capture['email_normalized'] ?? '' ) ) !== ''
			|| trim( (string) ( $capture['phone_normalized'] ?? '' ) ) !== '';
	}

	/** Hook: captura v2 nativa → contrato legacy. */
	public static function on_capture_event( $capture ): void
	{
		if ( ! is_array( $capture ) || ! self::should_emit( $capture ) ) {
			return;
		}

		$payload = self::build_payload( $capture );
		self::$emitting = true;
		try {
			do_action( 'estandar_lead_captured', $payload );
		} finally {
			self::$emitting = false;
		}
	}

	/** PURE: proyecta una captura v2 al contrato legacy. */
	public static function build_payload( array $capture ): array
	{
		$fields = json_decode( (string) ( $capture['fields_json'] ?? '' ), true );
		$fields = is_array( $fields ) ? $fields : array();

		$click_ids = json_decode( (string) ( $capture['click_ids_json'] ?? '' ), true );
		$click_ids = is_array( $click_ids ) ? $click_ids : array();

		$context = array(
			'source_channel' => sanitize_key( (string) ( $fields['source_channel'] ?? 'form' ) ),
		);
		if ( ! empty( $fields['project_slug'] ) ) {
			$context['project_slug'] = sanitize_key( (string) $fields['project_slug'] );
		}
		if ( ! empty( $fields['project_area'] ) ) {
			$context['project_area'] = sanitize_text_field( (string) $fields['project_area'] );
		}
		// FUNNEL_V1: modo de destino del form ('funnel' | 'simple') — el correo
		// enriquecido del operador se suprime cuando el form es 'simple'.
		if ( ! empty( $fields['_form_funnel_mode'] ) ) {
			$context['funnel_mode'] = sanitize_key( (string) $fields['_form_funnel_mode'] );
		}
		// ROUTING_FIELD_V1: correos extra de la opción de routing seleccionada —
		// el correo enriquecido del operador también les llega.
		if ( ! empty( $fields['_routing_emails'] ) ) {
			$context['notify_extra'] = sanitize_text_field( (string) $fields['_routing_emails'] );
		}
		if ( ! empty( $fields['intent'] ) ) {
			$context['intent'] = sanitize_key( (string) $fields['intent'] );
		}
		if ( ! empty( $fields['lead_intent'] ) ) {
			$context['lead_intent'] = sanitize_key( (string) $fields['lead_intent'] );
		}
		if ( ! empty( $fields['conversation_summary'] ) ) {
			$context['conversation_summary'] = sanitize_textarea_field( (string) $fields['conversation_summary'] );
		}

		$scoring = is_array( $fields['scoring'] ?? null ) ? $fields['scoring'] : array();

		// NAME_JOIN_V1: los forms capturan nombre + apellido por separado, pero
		// la captura v2 normalizaba solo el campo "nombre" → es_leads.name (y el
		// correo del operador) mostraba solo el primer nombre. Aquí se unen ambos
		// cuando existen, y se propagan los campos crudos para que es_leads.name
		// quede completo y form_fields_json llegue poblado (el template ya junta
		// nombre+apellido desde form_fields).
		$nombre      = trim( (string) ( $fields['nombre'] ?? $fields['name'] ?? '' ) );
		$apellido    = trim( (string) ( $fields['apellido'] ?? $fields['apellidos'] ?? $fields['last_name'] ?? '' ) );
		$full_name   = trim( $nombre . ( $apellido !== '' ? ' ' . $apellido : '' ) );
		$contact_name = $full_name !== ''
			? $full_name
			: (string) ( $capture['name'] ?? '' );
		// NAME_ADORN_V1 (2026-09-15): el nombre se sanea también aquí (no solo en
		// la captura) para que el CRM/aviso al operador no reciba adornos tipo
		// "꧁ ✷ 𝚁 𝚘 𝚜 𝚊 𝚛 𝚒 𝚘 ✷ ꧂" (emoji, símbolos, deletreo con espacios).
		$contact_name = Ingestion::normalize_name( $contact_name );

		// ORIGIN_V1: el `_origin` de la captura (web_form/web_popup/instant_fb/
		// instant_ig) define el source_plugin del CRM. Los instant forms de Meta
		// quedan como facebook/instagram para que el aviso muestre el origen real.
		$origin        = sanitize_key( (string) ( $fields['_origin'] ?? '' ) );
		$source_plugin = 'estandar-capture';
		if ( $origin === 'instant_ig' ) {
			$source_plugin = 'instagram';
		} elseif ( $origin === 'instant_fb' ) {
			$source_plugin = 'facebook';
		} elseif ( ! empty( $fields['_source_plugin'] ) ) {
			$source_plugin = sanitize_key( (string) $fields['_source_plugin'] );
		}

		return array(
			'schema_version' => '1.0',
			'source_plugin'  => $source_plugin,
			'submitted_at'   => gmdate( 'c' ),
			'contact'        => array(
				'name'  => sanitize_text_field( $contact_name ),
				'email' => sanitize_email( (string) ( $capture['email_normalized'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $capture['phone_normalized'] ?? '' ) ),
			),
			// NAME_JOIN_V1: campos crudos del form para que CrmIngestion
			// persista form_fields_json (es_lead_submissions) con valores reales.
			'form_fields'    => $fields,
			'content'        => array(
				'message'        => sanitize_textarea_field( (string) ( $capture['message'] ?? '' ) ),
				'source_form_id' => sanitize_text_field( (string) ( $capture['source_form_id'] ?? '' ) ),
			),
			'context'        => $context,
			'scoring'        => $scoring,
			'attribution'    => array(
				'utm_source'   => (string) ( $capture['utm_source'] ?? '' ),
				'utm_medium'   => (string) ( $capture['utm_medium'] ?? '' ),
				'utm_campaign' => (string) ( $capture['utm_campaign'] ?? '' ),
				'utm_term'     => (string) ( $capture['utm_term'] ?? '' ),
				'utm_content'  => (string) ( $capture['utm_content'] ?? '' ),
				'landing_url'  => (string) ( $capture['landing_url'] ?? '' ),
				'referrer'     => (string) ( $capture['referrer'] ?? '' ),
				'session_id'   => (string) ( $capture['session_id'] ?? '' ),
				// Click IDs de ads (gclid, gbraid, wbraid, fbclid, ...): se
				// proyectan desde click_ids_json para que estandar-leads los
				// guarde como first_gclid/first_gbraid/first_wbraid y Radar
				// pueda usarlos en la atribución (cobertura GCLID).
				'gclid'    => (string) ( $click_ids['gclid']    ?? '' ),
				'gbraid'   => (string) ( $click_ids['gbraid']   ?? '' ),
				'wbraid'   => (string) ( $click_ids['wbraid']   ?? '' ),
				'fbclid'   => (string) ( $click_ids['fbclid']   ?? '' ),
				'ttclid'   => (string) ( $click_ids['ttclid']   ?? '' ),
				'msclkid'  => (string) ( $click_ids['msclkid']  ?? '' ),
				'meta_fbc' => (string) ( $click_ids['meta_fbc'] ?? '' ),
				'meta_fbp' => (string) ( $click_ids['meta_fbp'] ?? '' ),
			),
			'request'        => array(
				'ip_address' => sanitize_text_field( (string) ( $capture['ip_address'] ?? '' ) ),
				// SUSPECT_AUTORESPONSE_V1 (2026-08-23): capture no propaga
				// user_agent y el gate anti-spam del autoresponse de
				// estandar-leads marca `missing_user_agent` → la confirmación
				// al lead quedaba 100% bloqueada (suspect). Mismo patrón que
				// estandar-forms (class-forms.php:1359-1361): UA vacío →
				// default benigno para que su ausencia no marque suspect.
				'user_agent' => (string) ( $capture['user_agent'] ?? '' ) !== ''
					? sanitize_text_field( (string) $capture['user_agent'] )
					: 'estandar-capture-bridge',
			),
			'spam'           => array(
				'honeypot_filled' => ! empty( $capture['spam_honeypot'] ),
				'seconds_to_submit' => null,
			),
			'meta'           => array(
				'_capture_lead_id' => (int) ( $capture['id'] ?? 0 ),
				'_capture_origin'  => 'estandar_suite_capture_event',
				'_capture_source'  => sanitize_key( (string) ( $capture['source'] ?? '' ) ),
				'source_channel'   => $context['source_channel'],
				'scoring'          => $scoring,
				// NAME_JOIN_V1: campos crudos para el enriquecimiento del
				// notifier del operador (template usa form_fields para juntar
				// nombre + apellido y mostrar el nombre completo).
				'sanitized_raw_fields' => $fields,
			),
		);
	}
}
