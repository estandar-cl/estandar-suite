<?php
/**
 * Google Data Manager API event builder (pure) for Estandar Leads.
 *
 * Deterministic: given the same lead data and status, build() always returns
 * the identical associative array. It performs NO transport, NO HTTP, NO token
 * exchange, NO queue/worker/telemetry/persistence. It only shapes data.
 *
 * Output shape = one Event resource of the Data Manager API
 * (POST /v1/events:ingest → events[]). Destinations, encoding and validateOnly
 * are REQUEST-level concerns and belong to the DM Client, never here.
 *
 * Hashing policy (Google spec):
 *   - emailAddress  = SHA-256 hex of lowercased/trimmed email — only if valid.
 *   - phoneNumber   = SHA-256 hex of E.164 (+56…) — only if plausible E.164.
 *   - Already-hashed values (64-char hex) pass through, never hashed twice.
 *   - Never hashed: gclid, gbraid, wbraid, transactionId.
 *
 * Emptiness policy: empty values are omitted entirely. An event with neither
 * adIdentifiers nor userData cannot match anything → build() returns [].
 *
 * @package EstandarLeads
 * @since   13.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Google_Payload_Builder {

	/**
	 * Build one Data Manager Event from a lead + commercial status.
	 *
	 * @param object|array $lead    Lead record (es_leads row or compatible shape).
	 * @param string       $status  Commercial status (e.g. QUALIFIED).
	 * @return array  Event resource ready for events:ingest, or [] when the
	 *                event would be unmatchable (no click IDs and no user data).
	 */
	public static function build( $lead, $status ) {
		$status = strtoupper( trim( (string) $status ) );

		$event = array(
			'transactionId'  => self::transaction_id( $lead, $status ),
			'eventTimestamp' => self::event_timestamp( $lead ),
			'eventSource'    => 'WEB',
		);

		$ad_identifiers = self::build_ad_identifiers( $lead );
		if ( ! empty( $ad_identifiers ) ) {
			$event['adIdentifiers'] = $ad_identifiers;
		}

		$user_identifiers = self::build_user_identifiers( $lead );
		if ( ! empty( $user_identifiers ) ) {
			$event['userData'] = array( 'userIdentifiers' => $user_identifiers );
		}

		// Unmatchable event: no click IDs and no hashable user data. Exporting
		// it would only burn quota and produce a permanent row-level error.
		if ( empty( $ad_identifiers ) && empty( $user_identifiers ) ) {
			return array();
		}

		$value = self::field( $lead, 'conversion_value', '' );
		if ( $value !== '' && is_numeric( $value ) ) {
			$event['conversionValue'] = (float) $value;
			$currency = strtoupper( trim( (string) self::field( $lead, 'currency' ) ) );
			if ( $currency !== '' ) {
				$event['currency'] = $currency;
			}
		}

		// Leads reach this pipeline only by submitting a contact form/chatbot —
		// first-party data supplied voluntarily. Event-level consent per DM spec.
		$event['consent'] = array(
			'adUserData'        => 'CONSENT_GRANTED',
			'adPersonalization' => 'CONSENT_GRANTED',
		);

		return $event;
	}

	// ── Identity (deterministic — the cross-system idempotency key) ─────────────

	/**
	 * transactionId: same derivation as the Meta event_id — lead identity +
	 * status. Google dedups repeated uploads of the same transactionId within
	 * the conversion window, so a re-send after an ambiguous failure is safe.
	 */
	public static function transaction_id( $lead, $status ) {
		$lead_uuid = trim( (string) self::field( $lead, 'lead_uuid' ) );
		if ( $lead_uuid === '' ) {
			$lead_uuid = 'lead-' . (int) self::field( $lead, 'id', 0 );
		}
		return $lead_uuid . ':' . strtoupper( trim( (string) $status ) );
	}

	// ── adIdentifiers ────────────────────────────────────────────────────────────

	private static function build_ad_identifiers( $lead ) {
		$out = array();
		// Persisted first-touch click IDs win; live fields are the fallback so
		// synthetic/injected rows keep working (mirrors the Meta builder).
		self::put( $out, 'gclid', trim( (string) self::field( $lead, 'first_gclid', self::field( $lead, 'gclid' ) ) ) );
		self::put( $out, 'gbraid', trim( (string) self::field( $lead, 'first_gbraid', self::field( $lead, 'gbraid' ) ) ) );
		self::put( $out, 'wbraid', trim( (string) self::field( $lead, 'first_wbraid', self::field( $lead, 'wbraid' ) ) ) );
		return $out;
	}

	// ── userData ─────────────────────────────────────────────────────────────────

	/**
	 * userIdentifiers per DM spec: one object per identifier. Invalid email or
	 * phone is OMITTED, never hashed — a hash of garbage can never match and
	 * degrades upload diagnostics.
	 */
	private static function build_user_identifiers( $lead ) {
		$out = array();

		$email = self::normalized_email( $lead );
		if ( $email !== '' ) {
			$out[] = array( 'emailAddress' => self::sha256( $email ) );
		}

		$phone = self::normalized_phone_e164( $lead );
		if ( $phone !== '' ) {
			$out[] = array( 'phoneNumber' => self::sha256( $phone ) );
		}

		return $out;
	}

	/**
	 * Valid, normalized (lowercased/trimmed) email — or '' when unusable.
	 * A stored 64-hex value is treated as pre-hashed and passed through.
	 */
	private static function normalized_email( $lead ) {
		$raw = trim( (string) self::field( $lead, 'email_normalized', self::field( $lead, 'email' ) ) );
		if ( $raw === '' ) {
			return '';
		}
		if ( self::is_sha256( $raw ) ) {
			return $raw; // pre-hashed → sha256() passes it through untouched
		}
		$email = strtolower( $raw );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * E.164 phone (+569XXXXXXXX) via the canonical normalizer — or '' when the
	 * digits do not form a plausible E.164 number (9–15 digits after '+').
	 */
	private static function normalized_phone_e164( $lead ) {
		$raw = trim( (string) self::field( $lead, 'phone_normalized', self::field( $lead, 'phone' ) ) );
		if ( $raw === '' ) {
			return '';
		}
		if ( self::is_sha256( $raw ) ) {
			return $raw;
		}
		$e164 = class_exists( 'Estandar_Leads_Normalizer' )
			? Estandar_Leads_Normalizer::phone( $raw )
			: ( '+' . preg_replace( '/\D+/', '', $raw ) );
		if ( ! preg_match( '/^\+\d{9,15}$/', $e164 ) ) {
			return '';
		}
		return $e164;
	}

	// ── Timestamp ────────────────────────────────────────────────────────────────

	/**
	 * RFC 3339 UTC timestamp of the QUALIFICATION moment. 'event_time' is the
	 * canonical business timestamp injected from the queue row (the instant the
	 * status transition happened) — never rebuilt from "now".
	 */
	private static function event_timestamp( $lead ) {
		foreach ( array( 'event_time', 'updated_at', 'created_at' ) as $key ) {
			$raw = trim( (string) self::field( $lead, $key ) );
			if ( $raw === '' ) {
				continue;
			}
			$ts = ctype_digit( $raw ) ? (int) $raw : strtotime( $raw . ' UTC' );
			if ( $ts ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
			}
		}
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private static function sha256( $value ) {
		if ( self::is_sha256( $value ) ) {
			return strtolower( $value );
		}
		return hash( 'sha256', $value );
	}

	private static function is_sha256( $value ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/i', (string) $value );
	}

	private static function put( &$arr, $key, $value ) {
		if ( $value !== '' && $value !== null ) {
			$arr[ $key ] = $value;
		}
	}

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
