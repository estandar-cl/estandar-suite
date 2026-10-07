<?php
/**
 * Meta Conversions API payload builder (pure) for Estandar Leads.
 *
 * Deterministic: given the same lead data and status, build() always returns
 * the identical associative array. It performs NO transport, NO HTTP, NO Graph
 * API call, NO queue/worker/telemetry/persistence. It only shapes data.
 *
 * Hashing policy:
 *   - SHA-256 (lowercased/trimmed) only on Meta-required PII: em, ph, fn, ln, ct, country.
 *   - Never hashed: fbclid, fbc, fbp, external_id, client_ip_address,
 *     client_user_agent (identifiers / click params / network signals).
 *   - Already-hashed values (64-char hex) are passed through, never hashed twice.
 *
 * Emptiness policy: empty values are omitted entirely (no empty keys emitted).
 *
 * @package EstandarLeads
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Meta_Payload_Builder {

	/**
	 * Build a Meta Conversions API event payload from a lead + commercial status.
	 *
	 * @param object|array $lead    Lead record (es_leads row or compatible shape).
	 * @param string       $status  Commercial status (e.g. QUALIFIED, RESERVED).
	 * @return array  Associative array ready for the Graph API, or [] if the
	 *                status maps to no Meta event.
	 */
	public static function build( $lead, $status ) {
		$event_name = self::map_event_name( $status );
		if ( $event_name === '' ) {
			return array();
		}

		$payload = array(
			'event_name'   => $event_name,
			'event_time'   => self::event_time( $lead ),
			'action_source' => self::action_source( $lead ),
		);

		$source_url = self::field( $lead, 'first_landing_url', self::field( $lead, 'event_source_url' ) );
		$source_url = trim( (string) $source_url );
		if ( $source_url !== '' ) {
			$payload['event_source_url'] = $source_url;
		}

		$user_data = self::build_user_data( $lead );
		if ( ! empty( $user_data ) ) {
			$payload['user_data'] = $user_data;
		}

		$custom_data = self::build_custom_data( $lead, $status );
		if ( ! empty( $custom_data ) ) {
			$payload['custom_data'] = $custom_data;
		}

		return $payload;
	}

	// ── Event mapping (single source — no inline conditions elsewhere) ───────────

	/**
	 * Map a business commercial status to a Meta standard event name.
	 *
	 * @param string $status
	 * @return string  Meta event name, or '' when unmapped.
	 */
	private static function map_event_name( $status ) {
		$map = array(
			// Captura (lead persistido): el ÚNICO hecho que emite 'Lead'.
			// Browser y Server comparten event_name + event_id (<uuid>:CAPTURED)
			// → deduplicación real en Meta, sin depender de ventanas temporales.
			'CAPTURED'        => 'Lead',
			// Calificación comercial: evento SEPARADO. 'QualifiedLead' es un
			// custom event (CAPI acepta event_name arbitrario; utilizable en
			// custom conversions / Conversion Leads). Distinto event_name y
			// distinto event_id → jamás deduplica contra el Lead de captura.
			'QUALIFIED'       => 'QualifiedLead',
			'MEETING_BOOKED'  => 'Schedule',
			'VISIT_SCHEDULED' => 'Schedule',
			'VISITED'         => 'Contact',
			'RESERVED'        => 'InitiateCheckout',
			'CONVERTED'       => 'Purchase',
			'CLIENT'          => 'Purchase',
			'CLIENT_SIGNED'   => 'Purchase',
			'DEED_SIGNED'     => 'Purchase',
			'DELIVERED'       => 'Purchase',
		);
		$key = strtoupper( trim( (string) $status ) );
		return $map[ $key ] ?? '';
	}

	// ── user_data ────────────────────────────────────────────────────────────────

	private static function build_user_data( $lead ) {
		$out = array();

		// Hashed PII (Meta-required).
		self::put( $out, 'em', self::hash_email( self::field( $lead, 'email_normalized', self::field( $lead, 'email' ) ) ) );
		self::put( $out, 'ph', self::hash_phone( self::field( $lead, 'phone_normalized', self::field( $lead, 'phone' ) ) ) );
		self::put( $out, 'fn', self::hash_pii( self::field( $lead, 'first_name' ) ) );
		self::put( $out, 'ln', self::hash_pii( self::field( $lead, 'last_name' ) ) );
		self::put( $out, 'ct', self::hash_pii( self::field( $lead, 'city' ) ) );
		self::put( $out, 'country', self::hash_pii( self::field( $lead, 'country' ) ) );

		// Identifiers / click params — never hashed.
		self::put( $out, 'external_id', trim( (string) self::field( $lead, 'lead_uuid' ) ) );

		// F1 atribución Meta: el fbc capturado (cookie) siempre gana; sin él,
		// derivar desde el fbclid persistido (nunca pisa un valor existente).
		$fbc = trim( (string) self::field( $lead, 'fbc', self::field( $lead, 'first_meta_fbc' ) ) );
		if ( $fbc === '' ) {
			$fbc = self::derive_fbc( $lead );
		}
		self::put( $out, 'fbc', $fbc );
		self::put( $out, 'fbp', trim( (string) self::field( $lead, 'fbp', self::field( $lead, 'first_meta_fbp' ) ) ) );
		self::put( $out, 'fbclid', trim( (string) self::field( $lead, 'fbclid', self::field( $lead, 'first_fbclid' ) ) ) );

		// F2 señal de red: Meta exige client_ip_address / client_user_agent RAW
		// (jamás hashed) — mejoran match quality sin ser PII hasheable.
		self::put( $out, 'client_ip_address', trim( (string) self::field( $lead, 'client_ip_address', self::field( $lead, 'first_ip_address' ) ) ) );
		self::put( $out, 'client_user_agent', trim( (string) self::field( $lead, 'client_user_agent', self::field( $lead, 'first_user_agent' ) ) ) );

		return $out;
	}

	// ── custom_data ──────────────────────────────────────────────────────────────

	private static function build_custom_data( $lead, $status ) {
		$out = array();

		self::put( $out, 'project_uuid', self::project_uuid( $lead ) );
		self::put( $out, 'project_slug', trim( (string) self::field( $lead, 'project_slug' ) ) );
		self::put( $out, 'commercial_status', strtoupper( trim( (string) $status ) ) );
		self::put( $out, 'operator', trim( (string) self::field( $lead, 'assigned_operator_name', self::field( $lead, 'assigned_operator_email' ) ) ) );

		$value = self::field( $lead, 'conversion_value', '' );
		if ( $value !== '' && is_numeric( $value ) ) {
			$out['conversion_value'] = (float) $value;
			$out['value']            = (float) $value;
		}
		self::put( $out, 'currency', strtoupper( trim( (string) self::field( $lead, 'currency' ) ) ) );

		self::put( $out, 'utm_source', trim( (string) self::field( $lead, 'utm_source', self::field( $lead, 'first_utm_source' ) ) ) );
		self::put( $out, 'utm_medium', trim( (string) self::field( $lead, 'utm_medium', self::field( $lead, 'first_utm_medium' ) ) ) );
		self::put( $out, 'utm_campaign', trim( (string) self::field( $lead, 'utm_campaign', self::field( $lead, 'first_utm_campaign' ) ) ) );

		return $out;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/**
	 * Deriva fbc desde el fbclid persistido según la spec oficial de Meta:
	 * fb.1.{creation_time_MILISEGUNDOS}.{fbclid}. creation_time proviene SOLO
	 * de timestamps persistidos de primera captura — nunca de la hora actual
	 * (un fbc "fresco" falsearía la ventana de atribución). Sin fbclid o sin
	 * timestamp válido → '' (put() lo omite: nunca viaja vacío).
	 */
	private static function derive_fbc( $lead ) {
		$fbclid = trim( (string) self::field( $lead, 'fbclid', self::field( $lead, 'first_fbclid' ) ) );
		if ( $fbclid === '' ) {
			return '';
		}
		$ts = self::first_capture_time( $lead );
		if ( $ts <= 0 ) {
			return '';
		}
		return 'fb.1.' . ( $ts * 1000 ) . '.' . $fbclid;
	}

	/**
	 * Timestamp canónico de primera captura (Unix seconds) desde campos
	 * persistidos, en orden de fidelidad. 0 = no hay timestamp confiable.
	 */
	private static function first_capture_time( $lead ) {
		foreach ( array( 'first_seen_at', 'created_at' ) as $key ) {
			$raw = trim( (string) self::field( $lead, $key ) );
			if ( $raw === '' ) {
				continue;
			}
			if ( ctype_digit( $raw ) ) {
				return (int) $raw;
			}
			$ts = strtotime( $raw . ' UTC' );
			if ( $ts ) {
				return (int) $ts;
			}
		}
		return 0;
	}

	private static function action_source( $lead ) {
		$url = trim( (string) self::field( $lead, 'first_landing_url', self::field( $lead, 'event_source_url' ) ) );
		return $url !== '' ? 'website' : 'system_generated';
	}

	private static function event_time( $lead ) {
		foreach ( array( 'event_time', 'updated_at', 'last_seen_at', 'created_at' ) as $key ) {
			$raw = trim( (string) self::field( $lead, $key ) );
			if ( $raw === '' ) {
				continue;
			}
			if ( ctype_digit( $raw ) ) {
				return (int) $raw;
			}
			$ts = strtotime( $raw . ' UTC' );
			if ( $ts ) {
				return (int) $ts;
			}
		}
		return 0;
	}

	private static function project_uuid( $lead ) {
		$explicit = trim( (string) self::field( $lead, 'project_uuid' ) );
		if ( $explicit !== '' ) {
			return $explicit;
		}
		$slug = trim( (string) self::field( $lead, 'project_slug' ) );
		if ( $slug === '' || ! class_exists( 'Estandar_Leads_Projects' ) ) {
			return '';
		}
		foreach ( Estandar_Leads_Projects::registry() as $project ) {
			if ( (string) ( $project['project_slug'] ?? '' ) === $slug ) {
				return (string) ( $project['project_uuid'] ?? '' );
			}
		}
		return '';
	}

	private static function hash_email( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( self::is_sha256( $value ) ) {
			return strtolower( $value );
		}
		return hash( 'sha256', strtolower( $value ) );
	}

	private static function hash_phone( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( self::is_sha256( $value ) ) {
			return strtolower( $value );
		}
		$digits = preg_replace( '/\D/', '', $value );
		if ( $digits === '' ) {
			return '';
		}
		return hash( 'sha256', $digits );
	}

	private static function hash_pii( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( self::is_sha256( $value ) ) {
			return strtolower( $value );
		}
		return hash( 'sha256', strtolower( $value ) );
	}

	private static function is_sha256( $value ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/i', (string) $value );
	}

	/**
	 * Add a key only when its value is non-empty (deterministic omission).
	 */
	private static function put( &$arr, $key, $value ) {
		if ( $value !== '' && $value !== null ) {
			$arr[ $key ] = $value;
		}
	}

	/**
	 * Read a field from an object or array lead, with a default fallback.
	 */
	private static function field( $lead, $key, $default = '' ) {
		if ( is_array( $lead ) ) {
			return array_key_exists( $key, $lead ) && $lead[ $key ] !== null ? $lead[ $key ] : $default;
		}
		if ( is_object( $lead ) ) {
			return isset( $lead->$key ) && $lead->$key !== null ? $lead->$key : $default;
		}
		return $default;
	}
}
