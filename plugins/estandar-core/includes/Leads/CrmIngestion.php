<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CrmIngestion — escritor v2 del CRM es_* (port de Estandar_Leads_Ingestion).
 *
 * Reemplaza el rol que hoy cumple estandar-leads (vía LegacyMap) para que el
 * plugin legacy se pueda retirar sin romper la cadena:
 *
 *   capture (submissions v2) ──> LeadsBridge emite estandar_lead_captured
 *      └─> [legacy o v2] escribe es_leads + es_lead_submissions + eventos
 *             └─> emite estandar_lead_ingested → OperatorNotifier notifica
 *
 * Contrato de fidelidad (port exacto del legacy, decisión 2026-08-24):
 *   - Normalización e hashes IDÉNTICOS (HMAC-SHA256 con el secret existente
 *     `estandar_leads_secret` → byte-idénticos, el dedupe no rompe).
 *   - find_lead_id por email → phone → legacy_phone (misma prioridad).
 *   - Mutex de identidad (wp_options, TTL 30 s, fail-closed) + transacción.
 *   - INSERT es_leads (nuevo) / UPDATE (existente) con las MISMA columnas.
 *   - INSERT es_lead_submissions + eventos es_lead_events + recontact/reopen.
 *   - Post-commit: release mutex → snapshot → `estandar_lead_ingested`
 *     (4 args: lead_id, submission_id, is_new, payload) → return.
 *
 * Gate de coexistencia (NO ROMPER): mientras estandar-leads esté presente
 * (activo o re-incluido por LegacyMap), el v2 DELEGA y no escribe (evita
 * doble escritura). Solo cuando el legacy desaparezca, el v2 toma el rol.
 */
class CrmIngestion
{
	const SECRET_OPTION = 'estandar_leads_secret';

	/** ¿El legacy estandar-leads está presente (activo o en disco vía LegacyMap)? */
	public static function legacy_present(): bool
	{
		if ( defined( 'ESTANDAR_CRM_INGESTION_FORCE_OWN' ) && ESTANDAR_CRM_INGESTION_FORCE_OWN ) {
			return false;
		}
		return defined( 'ESTANDAR_LEADS_VERSION' )
			|| class_exists( 'Estandar_Leads_Ingestion' )
			|| function_exists( 'estandar_leads_capture' );
	}

	/** ¿El v2 debe tomar el rol (legacy ausente)? */
	public static function should_own(): bool
	{
		return ! self::legacy_present();
	}

	public static function init(): void
	{
		// El mismo contrato que escucha el legacy; el gate decide quién escribe.
		add_action( 'estandar_lead_captured', array( __CLASS__, 'capture' ), 10, 1 );
	}

	// ── Secret / hashes (idéntico al legacy) ────────────────────────────────

	/** PURE: secret del sitio (reutiliza el de estandar-leads, nunca regenera). */
	public static function secret(): string
	{
		if ( function_exists( 'get_option' ) ) {
			$opt = (string) get_option( self::SECRET_OPTION, '' );
			if ( $opt !== '' ) {
				return $opt;
			}
		}
		// Fallback PURE para tests sin WP (no persiste).
		return 'estandar-suite-crm-test-secret';
	}

	/** PURE: HMAC-SHA256 con el secret del sitio (byte-idéntico al legacy). */
	public static function hash( string $value ): string
	{
		$value = trim( $value );
		return $value === '' ? '' : hash_hmac( 'sha256', $value, self::secret() );
	}

	/** PURE: normalizar email (lowercase + trim), igual al legacy. */
	public static function normalize_email( string $email ): string
	{
		if ( function_exists( 'sanitize_email' ) ) {
			$email = sanitize_email( $email );
		}
		return strtolower( trim( $email ) );
	}

	/** PURE: normalizar teléfono a storage (CL → 56XXXXXXXX), igual al legacy. */
	public static function normalize_phone_storage( string $phone ): string
	{
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( $digits === '' ) {
			return '';
		}
		if ( strpos( $digits, '0056' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		if ( preg_match( '/^0?9\d{8}$/', $digits ) ) {
			return '56' . ltrim( $digits, '0' );
		}
		if ( strpos( $digits, '56' ) === 0 ) {
			return $digits;
		}
		if ( strlen( $digits ) === 8 || strlen( $digits ) === 9 ) {
			return '56' . $digits;
		}
		return $digits;
	}

	/** PURE: teléfono display legacy ('+' + storage), para legacy_phone_hash. */
	public static function normalize_phone_display( string $phone ): string
	{
		$digits = self::normalize_phone_storage( $phone );
		return $digits === '' ? '' : '+' . $digits;
	}

	/** PURE: normalizar nombre (colapsa espacios + tokens duplicados).
	 * NAME_ADORN_V1 (2026-09-15): descarta el adorno con que la gente decora su
	 * nombre (emoji/símbolos tipo ꧁ ✷ ★), translitera fuentes fancy vía NFKC
	 * cuando intl está disponible y reunifica nombres deletreados con espacios
	 * ("R O S A R I O" → "ROSARIO"). Conserva la capitalización original. */
	public static function normalize_name( string $name ): string
	{
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return '';
		}
		if ( class_exists( '\\Normalizer' ) && function_exists( '\\normalizer_normalize' ) ) {
			$norm = normalizer_normalize( $name, \Normalizer::NFKC );
			if ( is_string( $norm ) ) {
				$name = $norm;
			}
		}
		$clean = preg_replace( '/[^\p{L}\p{M}\s\'\-\.]/u', ' ', $name );
		if ( $clean !== null ) {
			$name = $clean;
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		$joined = preg_replace_callback(
			'/(?<![\p{L}])(?:\p{L}\s){2,}\p{L}(?![\p{L}])/u',
			static function ( $m ) {
				return preg_replace( '/\s+/u', '', (string) $m[0] );
			},
			$name
		);
		if ( $joined !== null ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', $joined ) );
		}
		if ( $name === '' ) {
			return '';
		}
		$tokens = explode( ' ', $name );
		$out = array();
		$prev = '';
		foreach ( $tokens as $tok ) {
			if ( $tok !== '' && strtolower( $tok ) !== strtolower( $prev ) ) {
				$out[] = $tok;
			}
			if ( $tok !== '' ) {
				$prev = $tok;
			}
		}
		return implode( ' ', $out );
	}

	/** PURE: payload_hash (hash del JSON del payload entrante). */
	public static function payload_hash( array $payload ): string
	{
		return self::hash( (string) wp_json_encode( $payload ) );
	}

	// ── Normalización de identidad ──────────────────────────────────────────

	/** PURE: hashes de identidad de un payload. */
	public static function identity( array $payload ): array
	{
		$contact = isset( $payload['contact'] ) && is_array( $payload['contact'] ) ? $payload['contact'] : array();
		$name  = self::normalize_name( sanitize_text_field( (string) ( $contact['name'] ?? '' ) ) );
		$email = self::normalize_email( (string) ( $contact['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$phone_normalized = self::normalize_phone_storage( $phone );
		$email_hash       = self::hash( $email );
		$phone_hash       = self::hash( $phone_normalized );
		$legacy_phone_hash = self::hash( self::normalize_phone_display( $phone ) );
		return array(
			'name'             => $name,
			'email'            => $email,
			'phone'            => $phone,
			'phone_normalized' => $phone_normalized,
			'email_hash'       => $email_hash,
			'phone_hash'       => $phone_hash,
			'legacy_phone_hash'=> $legacy_phone_hash,
		);
	}

	// ── find_lead_id (email → phone → legacy_phone) ─────────────────────────

	public static function find_lead_id( string $email_hash, string $phone_hash, string $legacy_phone_hash = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		if ( $email_hash !== '' ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}es_leads WHERE email_hash = %s ORDER BY id ASC LIMIT 1",
				$email_hash
			) );
			if ( $found ) {
				return $found;
			}
		}
		if ( $phone_hash !== '' ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}es_leads WHERE phone_hash = %s ORDER BY id ASC LIMIT 1",
				$phone_hash
			) );
			if ( $found ) {
				return $found;
			}
		}
		if ( $legacy_phone_hash !== '' && $legacy_phone_hash !== $phone_hash ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}es_leads WHERE phone_hash = %s ORDER BY id ASC LIMIT 1",
				$legacy_phone_hash
			) );
			if ( $found ) {
				return $found;
			}
		}
		return 0;
	}

	// ── Mutex de identidad (wp_options, TTL 30s, fail-closed) ───────────────

	public static function acquire_identity_mutex( string $email_hash, string $phone_hash )
	{
		$keys = array();
		if ( $email_hash !== '' ) { $keys[] = 'email:' . $email_hash; }
		if ( $phone_hash !== '' ) { $keys[] = 'phone:' . $phone_hash; }
		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );
		if ( empty( $keys ) ) {
			return false;
		}
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
		$locks = array();
		foreach ( $keys as $key ) {
			$transient = 'estandar_leads_identity_' . md5( $key );
			$value_key = '_transient_' . $transient;
			$value     = wp_json_encode( array( 'token' => $token, 'expires' => time() + 30 ) );
			if ( ! add_option( $value_key, $value, '', 'no' ) ) {
				$current_data = get_option( $value_key );
				$current_data = is_string( $current_data ) ? json_decode( $current_data, true ) : null;
				$expires = is_array( $current_data ) ? (int) ( $current_data['expires'] ?? 0 ) : 0;
				if ( $expires > 0 && $expires <= time() ) {
					delete_option( $value_key );
					if ( add_option( $value_key, $value, '', 'no' ) ) {
						$locks[] = $transient;
						continue;
					}
					self::release_identity_mutex( array( 'token' => $token, 'locks' => $locks ) );
					return false;
				}
				self::release_identity_mutex( array( 'token' => $token, 'locks' => $locks ) );
				return false;
			}
			$locks[] = $transient;
		}
		return array( 'token' => $token, 'locks' => $locks );
	}

	public static function release_identity_mutex( $mutex ): void
	{
		if ( ! is_array( $mutex ) || empty( $mutex['locks'] ) ) {
			return;
		}
		$token = (string) ( $mutex['token'] ?? '' );
		foreach ( (array) $mutex['locks'] as $transient ) {
			$value_key = '_transient_' . $transient;
			$current   = get_option( $value_key );
			$current_data = is_string( $current ) ? json_decode( $current, true ) : null;
			if ( is_array( $current_data ) && (string) ( $current_data['token'] ?? '' ) === $token ) {
				delete_option( $value_key );
			}
		}
	}

	// ── Project slug (port del resolver con fallback project_area) ─────────

	public static function project_slug( string $slug, string $fallback_area = '' ): string
	{
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
			$resolved = ProjectRegistry::resolve_slug( $slug, $fallback_area );
			if ( $resolved !== '' ) {
				return $resolved;
			}
		}
		// Fallback: slug normalizado directo (project_area) como el legacy.
		$slug = sanitize_title( remove_accents( (string) $slug ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( remove_accents( (string) $fallback_area ) );
		}
		return trim( (string) $slug, "- \t\n\r\0\x0B" );
	}

	/** Port PURE del payload → fila es_leads (lead nuevo). */
	public static function project_lead( array $payload ): array
	{
		$id   = self::identity( $payload );
		$ctx  = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
		$attr = isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? $payload['attribution'] : array();
		$req  = isset( $payload['request'] ) && is_array( $payload['request'] ) ? $payload['request'] : array();
		$now  = \Estandar\Core\Time::now();

		$project_area = sanitize_text_field( (string) ( $ctx['project_area'] ?? '' ) );
		$project_slug = self::project_slug( (string) ( $ctx['project_slug'] ?? '' ), $project_area );

		$lead_uuid = (string) ( $payload['meta']['lead_uuid'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $lead_uuid ) ) {
			$lead_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
		}

		return array(
			'lead_uuid'             => $lead_uuid,
			'status'                => 'NEW',
			'name'                  => $id['name'],
			'email'                 => $id['email'],
			'phone'                 => $id['phone'],
			'email_normalized'      => $id['email'],
			'phone_normalized'      => $id['phone_normalized'],
			'email_hash'            => $id['email_hash'],
			'phone_hash'            => $id['phone_hash'],
			'first_source_plugin'   => sanitize_key( (string) ( $payload['source_plugin'] ?? '' ) ),
			'latest_source_plugin'  => sanitize_key( (string) ( $payload['source_plugin'] ?? '' ) ),
			'project_slug'          => $project_slug,
			'project_area'          => $project_area,
			'lead_intent'           => sanitize_text_field( (string) ( $ctx['lead_intent'] ?? ( $ctx['intent'] ?? '' ) ) ),
			'first_utm_source'      => sanitize_text_field( (string) ( $attr['utm_source'] ?? '' ) ),
			'first_utm_medium'      => sanitize_text_field( (string) ( $attr['utm_medium'] ?? '' ) ),
			'first_utm_campaign'    => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
			'first_utm_term'        => sanitize_text_field( (string) ( $attr['utm_term'] ?? '' ) ),
			'first_utm_content'     => sanitize_text_field( (string) ( $attr['utm_content'] ?? '' ) ),
			'first_gclid'           => sanitize_text_field( (string) ( $attr['gclid'] ?? '' ) ),
			'first_gbraid'          => sanitize_text_field( (string) ( $attr['gbraid'] ?? '' ) ),
			'first_wbraid'          => sanitize_text_field( (string) ( $attr['wbraid'] ?? '' ) ),
			'first_fbclid'          => sanitize_text_field( (string) ( $attr['fbclid'] ?? '' ) ),
			'first_meta_fbc'        => sanitize_text_field( (string) ( $attr['meta_fbc'] ?? '' ) ),
			'first_meta_fbp'        => sanitize_text_field( (string) ( $attr['meta_fbp'] ?? '' ) ),
			'first_ttclid'          => sanitize_text_field( (string) ( $attr['ttclid'] ?? '' ) ),
			'first_msclkid'         => sanitize_text_field( (string) ( $attr['msclkid'] ?? '' ) ),
			'first_landing_url'     => esc_url_raw( (string) ( $attr['landing_url'] ?? '' ) ),
			'first_referrer'        => esc_url_raw( (string) ( $attr['referrer'] ?? '' ) ),
			'first_session_id'      => sanitize_text_field( (string) ( $attr['session_id'] ?? '' ) ),
			'first_ip_address'      => filter_var( (string) ( $req['ip_address'] ?? '' ), FILTER_VALIDATE_IP ) ? (string) $req['ip_address'] : '',
			'first_user_agent'      => substr( sanitize_text_field( (string) ( $req['user_agent'] ?? '' ) ), 0, 255 ),
			'first_seen_at'         => $now,
			'last_seen_at'          => $now,
			'created_at'            => $now,
			'updated_at'            => $now,
		);
	}

	// ── capture(): flujo principal (gate → mutex → transacción → post-commit) ─

	/**
	 * Hook `estandar_lead_captured` (1 arg, mismo contrato que el legacy).
	 * Si el legacy está presente → delega (no escribe). Si no → toma el rol.
	 *
	 * @return int lead_id (0 = no persistido o delegado)
	 */
	public static function capture( $payload ): int
	{
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return 0;
		}
		if ( ! self::should_own() ) {
			// Legacy presente: el v2 no escribe (evita doble escritura).
			return 0;
		}

		$id = self::identity( $payload );
		$source_plugin = sanitize_key( (string) ( $payload['source_plugin'] ?? '' ) );
		if ( $source_plugin === '' || ( $id['email'] === '' && $id['phone_normalized'] === '' ) ) {
			return 0;
		}

		$mutex = self::acquire_identity_mutex( $id['email_hash'], $id['phone_hash'] );
		if ( $mutex === false ) {
			return 0;
		}

		$in_transaction = false;
		$mutex_released = false;
		try {
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
			$in_transaction = true;

			$lead_id = self::find_lead_id( $id['email_hash'], $id['phone_hash'], $id['legacy_phone_hash'] );
			$is_new  = $lead_id <= 0;

			$projection = self::project_lead( $payload );
			$submitted_at = \Estandar\Core\Time::now();

			if ( $is_new ) {
				$ok = $wpdb->insert( $wpdb->prefix . 'es_leads', $projection );
				$lead_id = absint( $wpdb->insert_id );
				if ( ! $ok || ! $lead_id ) {
					$wpdb->query( 'ROLLBACK' );
					$in_transaction = false;
					return 0;
				}
				self::insert_event( $lead_id, 'lead_created', array(
					'ip_address'  => (string) ( $projection['first_ip_address'] ?? '' ),
					'user_agent'  => (string) ( $projection['first_user_agent'] ?? '' ),
					'metadata'    => array( 'source_plugin' => $source_plugin ),
				) );
			} else {
				// Update: último contacto + source + proyecto (sin pisar first_*).
				$existing = (array) $wpdb->get_row( $wpdb->prepare(
					"SELECT lead_uuid, status, project_slug, latest_source_plugin, name, email, phone, email_normalized, phone_normalized
					 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
					$lead_id
				), ARRAY_A );
				$update = array(
					'latest_source_plugin' => $source_plugin,
					'last_seen_at'         => $submitted_at,
					'updated_at'           => $submitted_at,
				);
				if ( $projection['project_slug'] !== '' ) {
					$update['project_slug'] = $projection['project_slug'];
				}
				// Rellena first_* vacíos (identity_update_for_empty_lead).
				foreach ( array( 'first_session_id', 'first_utm_source', 'first_utm_medium', 'first_utm_campaign',
					'first_utm_term', 'first_utm_content', 'first_gclid', 'first_gbraid', 'first_wbraid',
					'first_fbclid', 'first_meta_fbc', 'first_meta_fbp', 'first_ttclid', 'first_msclkid',
					'first_landing_url', 'first_referrer' ) as $col ) {
					if ( empty( $existing[ $col ] ) && ! empty( $projection[ $col ] ) ) {
						$update[ $col ] = $projection[ $col ];
					}
				}
				$updated = $wpdb->update( $wpdb->prefix . 'es_leads', $update, array( 'id' => $lead_id ) );
				if ( $updated === false ) {
					$wpdb->query( 'ROLLBACK' );
					$in_transaction = false;
					return 0;
				}
			}

			// es_lead_submissions (contrato completo).
			$content  = isset( $payload['content'] ) && is_array( $payload['content'] ) ? $payload['content'] : array();
			$attr     = isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? $payload['attribution'] : array();
			$req      = isset( $payload['request'] ) && is_array( $payload['request'] ) ? $payload['request'] : array();
			$meta     = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
			$ctx      = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
			$scoring  = isset( $payload['scoring'] ) && is_array( $payload['scoring'] ) ? $payload['scoring'] : array();
			$chatbot  = isset( $payload['chatbot'] ) && is_array( $payload['chatbot'] ) ? $payload['chatbot'] : array();
			$spam     = isset( $payload['spam'] ) && is_array( $payload['spam'] ) ? $payload['spam'] : array();
			$suspect  = ! empty( $spam['honeypot_filled'] ) ? 1 : 0;

			$source_form_id = sanitize_text_field( (string) ( $content['source_form_id'] ?? '' ) );
			$entry_id = (int) ( $meta['source_entry_id'] ?? 0 );

			$submission = array(
				'lead_id'            => $lead_id,
				'lead_uuid'          => $projection['lead_uuid'],
				'source_plugin'      => $source_plugin,
				'source_form_id'     => $source_form_id,
				'source_entry_id'    => $entry_id > 0 ? $entry_id : null,
				'schema_version'     => '1.0',
				'submitted_at'       => $submitted_at,
				'created_at'         => $submitted_at,
				'name'               => $id['name'],
				'email'              => $id['email'],
				'phone'              => $id['phone'],
				'email_normalized'   => $id['email'],
				'phone_normalized'   => $id['phone_normalized'],
				'email_hash'         => $id['email_hash'],
				'phone_hash'         => $id['phone_hash'],
				'message'            => sanitize_textarea_field( (string) ( $content['message'] ?? '' ) ),
				'utm_source'         => sanitize_text_field( (string) ( $attr['utm_source'] ?? '' ) ),
				'utm_medium'         => sanitize_text_field( (string) ( $attr['utm_medium'] ?? '' ) ),
				'utm_campaign'       => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
				'utm_term'           => sanitize_text_field( (string) ( $attr['utm_term'] ?? '' ) ),
				'utm_content'        => sanitize_text_field( (string) ( $attr['utm_content'] ?? '' ) ),
				'gclid'              => sanitize_text_field( (string) ( $attr['gclid'] ?? '' ) ),
				'gbraid'             => sanitize_text_field( (string) ( $attr['gbraid'] ?? '' ) ),
				'wbraid'             => sanitize_text_field( (string) ( $attr['wbraid'] ?? '' ) ),
				'fbclid'             => sanitize_text_field( (string) ( $attr['fbclid'] ?? '' ) ),
				'meta_fbc'           => sanitize_text_field( (string) ( $attr['meta_fbc'] ?? '' ) ),
				'meta_fbp'           => sanitize_text_field( (string) ( $attr['meta_fbp'] ?? '' ) ),
				'ttclid'             => sanitize_text_field( (string) ( $attr['ttclid'] ?? '' ) ),
				'msclkid'            => sanitize_text_field( (string) ( $attr['msclkid'] ?? '' ) ),
				'landing_url'        => esc_url_raw( (string) ( $attr['landing_url'] ?? '' ) ),
				'referrer'           => esc_url_raw( (string) ( $attr['referrer'] ?? '' ) ),
				'first_touch_timestamp' => sanitize_text_field( (string) ( $attr['first_touch_timestamp'] ?? '' ) ),
				'last_touch_timestamp'  => sanitize_text_field( (string) ( $attr['last_touch_timestamp'] ?? '' ) ),
				'session_id'         => sanitize_text_field( (string) ( $attr['session_id'] ?? '' ) ),
				'gclid_capture_method' => sanitize_text_field( (string) ( $attr['gclid_capture_method'] ?? '' ) ),
				'fbclid_capture_method'=> sanitize_text_field( (string) ( $attr['fbclid_capture_method'] ?? '' ) ),
				'attribution_capture_method' => sanitize_text_field( (string) ( $attr['attribution_capture_method'] ?? '' ) ),
				'ip_address'         => filter_var( (string) ( $req['ip_address'] ?? '' ), FILTER_VALIDATE_IP ) ? (string) $req['ip_address'] : '',
				'user_agent'         => substr( sanitize_text_field( (string) ( $req['user_agent'] ?? '' ) ), 0, 255 ),
				'suspect'            => $suspect,
				'suspect_reasons'    => '',
				'payload_hash'       => self::payload_hash( $payload ),
				'context_json'       => wp_json_encode( $ctx ),
				'scoring_json'       => wp_json_encode( $scoring ),
				'chatbot_json'       => wp_json_encode( $chatbot ),
				'form_fields_json'   => wp_json_encode( isset( $payload['form_fields'] ) && is_array( $payload['form_fields'] ) ? $payload['form_fields'] : array() ),
			);
			$ok = $wpdb->insert( $wpdb->prefix . 'es_lead_submissions', $submission );
			$submission_id = absint( $wpdb->insert_id );
			if ( ! $ok || ! $submission_id ) {
				$wpdb->query( 'ROLLBACK' );
				$in_transaction = false;
				return 0;
			}

			self::insert_event( $lead_id, 'lead_submitted', array(
				'submission_id' => $submission_id,
				'ip_address'    => (string) ( $submission['ip_address'] ?? '' ),
				'metadata'      => array( 'source_plugin' => $source_plugin ),
			) );

			if ( ! $is_new ) {
				self::insert_event( $lead_id, 'lead_recontacted', array(
					'submission_id' => $submission_id,
					'project_slug'  => $projection['project_slug'],
					'source_plugin' => $source_plugin,
					'metadata'      => array(),
				) );
			}

			$wpdb->query( 'COMMIT' );
			$in_transaction = false;

			// Post-commit: release → snapshot → hook (mismo contrato que legacy).
			self::release_identity_mutex( $mutex );
			$mutex_released = true;

			self::$last_ingested = array(
				'lead_id'   => (int) $lead_id,
				'lead_uuid' => (string) $projection['lead_uuid'],
			);

			do_action( 'estandar_lead_ingested', (int) $lead_id, (int) $submission_id, $is_new, $payload );

			return (int) $lead_id;
		} catch ( \Throwable $e ) {
			global $wpdb;
			if ( $in_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return 0;
		} finally {
			if ( ! $mutex_released ) {
				self::release_identity_mutex( $mutex );
			}
		}
	}

	/** Inserta un evento en es_lead_events (port fiel del repository legacy). */
	public static function insert_event( int $lead_id, string $event_type, array $data ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$operator_id = ! empty( $data['operator_id'] ) ? absint( $data['operator_id'] ) : 0;
		$metadata    = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();
		$payload = array(
			'lead_id'       => absint( $lead_id ),
			'submission_id' => ! empty( $data['submission_id'] ) ? absint( $data['submission_id'] ) : null,
			'operator_id'   => $operator_id ?: null,
			'event_type'    => sanitize_key( $event_type ),
			'from_status'   => sanitize_text_field( (string) ( $data['from_status'] ?? '' ) ),
			'to_status'     => sanitize_text_field( (string) ( $data['to_status'] ?? '' ) ),
			'reason'        => sanitize_textarea_field( (string) ( $data['reason'] ?? '' ) ),
			'metadata'      => wp_json_encode( $metadata ),
			'ip_address'    => sanitize_text_field( (string) ( $data['ip_address'] ?? '' ) ),
			'user_agent'    => sanitize_textarea_field( (string) ( $data['user_agent'] ?? '' ) ),
			'created_at'    => \Estandar\Core\Time::now(),
		);
		$ok = $wpdb->insert( $wpdb->prefix . 'es_lead_events', $payload );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Último lead ingerido (snapshot canónico, lectura). */
	public static function last_ingested(): array
	{
		return is_array( self::$last_ingested ) ? self::$last_ingested : array();
	}

	private static $last_ingested = null;
}
