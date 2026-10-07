<?php
/**
 * CAPTURE_ATTRIBUTION_CONTEXT_V1 — canonical capture attribution contract.
 *
 * Single builder shared by every capture source (Forms, Chatbot web,
 * Chatbot WhatsApp, future sources). Emits exactly the key set consumed by
 * Estandar_Leads_Attribution::merge_analytics() and
 * Estandar_Leads_Ingestion::capture(), so any source that runs its
 * attribution through this function speaks the same contract as Forms.
 *
 * The three chatbot emit points already call this function behind a
 * function_exists() guard (with a minimal-shape fallback), so defining it
 * here activates the canonical contract without touching any caller.
 *
 * Precedence per field:
 *   explicit input  >  landing_url query string  >  _fbc/_fbp cookie  >  ''
 *
 * merge_analytics() later fills any field still empty from the ea_sessions
 * snapshot, so a value produced here always wins over the session lookup —
 * deliberately: the capture-time landing URL is fresher than a possibly
 * stale pre-existing session row (known D1 case: recurring session without
 * fbclid swallowing the click id of the current ad click).
 *
 * Read-only: no DB, no options, no writes. Only $_COOKIE is consulted, and
 * only for the Meta browser identifiers (_fbc/_fbp) when absent from input.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'estandar_core_attribution_context_fields' ) ) {
	/**
	 * Canonical attribution field list (contract shared with
	 * Estandar_Leads_Attribution::merge_analytics()).
	 *
	 * @return string[]
	 */
	function estandar_core_attribution_context_fields(): array {
		return array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'gclid',
			'gbraid',
			'wbraid',
			'fbclid',
			'meta_fbc',
			'meta_fbp',
			'ttclid',
			'msclkid',
			'campaign_id',
			'adgroup_id',
			'asset_group_id',
			'creative_id',
			'keyword',
			'match_type',
			'network',
			'device',
			'placement',
			'campaign_type',
			'landing_url',
			'referrer',
			'first_touch_timestamp',
			'last_touch_timestamp',
			'session_id',
		);
	}
}

if ( ! function_exists( 'estandar_core_build_attribution_context' ) ) {
	/**
	 * Build the canonical attribution context from whatever a capture source
	 * already knows. Every canonical key is always present in the result
	 * (empty string when unknown); *_capture_method keys are only present
	 * when they carry a value, so downstream defaults stay untouched.
	 *
	 * @param array $input Partial attribution (any subset of the canonical
	 *                     keys; aliases _fbc/_fbp/ea_sid are accepted).
	 * @return array Canonical attribution context.
	 */
	function estandar_core_build_attribution_context( array $input = array() ): array {
		$fields = estandar_core_attribution_context_fields();

		// Fields recoverable from the landing URL query string.
		$url_derivable = array(
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid',
			'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword',
			'match_type', 'network', 'device', 'placement', 'campaign_type',
		);
		$url_valued = array( 'landing_url', 'referrer' );

		// Alias normalization: Meta pixel cookie names and the analytics
		// session cookie name, as used by Forms hidden fields / chatbot JS.
		$aliases = array(
			'_fbc'   => 'meta_fbc',
			'_fbp'   => 'meta_fbp',
			'ea_sid' => 'session_id',
			'gad_campaignid' => 'campaign_id',
			'campaignid'     => 'campaign_id',
			'adgroupid'      => 'adgroup_id',
			'assetgroupid'   => 'asset_group_id',
			'creative'       => 'creative_id',
			'matchtype'      => 'match_type',
		);
		foreach ( $aliases as $from => $to ) {
			if ( trim( (string) ( $input[ $to ] ?? '' ) ) === '' && ! is_array( $input[ $from ] ?? null ) && trim( (string) ( $input[ $from ] ?? '' ) ) !== '' ) {
				$input[ $to ] = $input[ $from ];
			}
		}

		$out = array();
		foreach ( $fields as $field ) {
			$value = $input[ $field ] ?? '';
			if ( is_array( $value ) ) {
				$value = '';
			}
			$out[ $field ] = in_array( $field, $url_valued, true )
				? esc_url_raw( (string) $value )
				: sanitize_text_field( (string) $value );
		}

		// Did the source hand us any identifier/UTM directly? (Used for
		// attribution_capture_method only.)
		$from_payload = false;
		foreach ( $url_derivable as $field ) {
			if ( $out[ $field ] !== '' ) {
				$from_payload = true;
				break;
			}
		}

		// Fill gaps from the landing URL query string.
		$derived = array();
		$query = (string) wp_parse_url( $out['landing_url'], PHP_URL_QUERY );
		if ( $query !== '' ) {
			parse_str( $query, $params );
			foreach ( $aliases as $from => $to ) {
				if ( empty( $params[ $to ] ) && isset( $params[ $from ] ) && ! is_array( $params[ $from ] ) ) {
					$params[ $to ] = $params[ $from ];
				}
			}
			foreach ( $url_derivable as $field ) {
				if ( $out[ $field ] !== '' ) {
					continue;
				}
				$candidate = $params[ $field ] ?? '';
				if ( ! is_array( $candidate ) && trim( (string) $candidate ) !== '' ) {
					$out[ $field ] = sanitize_text_field( (string) $candidate );
					$derived[ $field ] = 'landing_url';
				}
			}
		}

		// Meta browser identifiers live in cookies, never in the URL.
		foreach ( array( 'meta_fbc' => '_fbc', 'meta_fbp' => '_fbp' ) as $field => $cookie ) {
			if ( $out[ $field ] !== '' ) {
				continue;
			}
			$raw = isset( $_COOKIE[ $cookie ] ) && ! is_array( $_COOKIE[ $cookie ] ) ? (string) $_COOKIE[ $cookie ] : '';
			if ( $raw !== '' && preg_match( '/^fb\.\d+\.\d+\./', $raw ) ) {
				$out[ $field ] = sanitize_text_field( $raw );
				$derived[ $field ] = 'cookie';
			}
		}

		// Capture-method telemetry. Only emitted when non-empty so callers
		// that never set these keys keep their current downstream defaults.
		foreach ( array( 'gclid' => 'gclid_capture_method', 'fbclid' => 'fbclid_capture_method' ) as $field => $method_key ) {
			$provided = sanitize_key( (string) ( $input[ $method_key ] ?? '' ) );
			if ( $provided !== '' ) {
				$out[ $method_key ] = $provided;
				continue;
			}
			if ( $out[ $field ] === '' ) {
				continue;
			}
			$out[ $method_key ] = isset( $derived[ $field ] ) ? 'landing_url' : 'payload';
		}

		$attribution_method = sanitize_key( (string) ( $input['attribution_capture_method'] ?? '' ) );
		if ( $attribution_method === '' ) {
			if ( $from_payload ) {
				$attribution_method = 'payload';
			} elseif ( ! empty( $derived ) ) {
				$attribution_method = 'landing_url';
			}
		}
		if ( $attribution_method !== '' ) {
			$out['attribution_capture_method'] = $attribution_method;
		}

		$value_track_fields = array( 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type' );
		foreach ( $value_track_fields as $field ) {
			if ( $out[ $field ] !== '' ) {
				$out['attribution_provenance'][ $field ] = isset( $derived[ $field ] ) ? 'explicit_url' : 'explicit_payload';
			}
		}

		return $out;
	}
}
