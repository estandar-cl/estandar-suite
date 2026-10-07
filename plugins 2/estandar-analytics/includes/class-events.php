<?php
/**
 * Behavior events and conversion detection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Events {

	public static function record_events( $session_id, $events ) {
		if ( ! is_array( $events ) || $session_id === '' ) {
			return;
		}

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			self::record_single( $session_id, $event );
		}
	}

	public static function record_single( $session_id, $event ) {
		global $wpdb;

		$classified = EA_Utils::classify_event( $event['type'] ?? '', $event );
		$type       = $classified['event_type'] ?? '';
		if ( $type === '' ) {
			return;
		}
		if ( in_array( strtolower( $type ), array( 'whatsappclick', 'whatsapp_click' ), true ) ) {
			$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			self::record_whatsapp_click(
				(string) $session_id,
				(string) ( $meta['project_slug'] ?? '' ),
				(string) ( $meta['sid_ref'] ?? '' ),
				array_merge( $meta, array(
					'event_id' => (string) ( $meta['event_id'] ?? $event['event_id'] ?? '' ),
					'page_url' => (string) ( $event['page_url'] ?? '' ),
					'destination_url' => (string) ( $meta['destination_url'] ?? $event['label'] ?? '' ),
					'timestamp' => (string) ( $meta['timestamp'] ?? '' ),
				) )
			);
			return;
		}

		$event = self::with_form_project_authority( $event, $type );

		$table = EA_Utils::table( 'events' );
		$wpdb->insert(
			$table,
			array(
				'session_id'  => EA_Utils::sanitize_text( $session_id, 64 ),
				'event_type'  => $type,
				'event_label' => EA_Utils::sanitize_text( $classified['event_label'] ?? ( $event['label'] ?? '' ), 255 ),
				'page_url'    => EA_Utils::sanitize_url( $event['page_url'] ?? '' ),
				'event_value' => EA_Utils::sanitize_text( $event['value'] ?? '', 500 ),
				'meta_json'   => self::event_meta_json( $event ),
				'occurred_at' => EA_Utils::now(),
			)
		);

		$date = EA_Utils::today();
		EA_Utils::increment_aggregate( $date, 'events' );
		EA_Utils::increment_aggregate( $date, 'event_type', 1, 'type', $type );

		self::maybe_record_conversion( $session_id, $type, $event );
	}

	private static function event_meta_json( array $event ): string {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		foreach ( array( 'project_slug', 'lead_id', 'submission_id', 'chatbot_session_key', 'popup_id', 'popup_name', 'source', 'landing_url' ) as $key ) {
			if ( isset( $event[ $key ] ) && ! isset( $meta[ $key ] ) ) {
				$meta[ $key ] = $event[ $key ];
			}
		}
		$meta = self::sanitize_event_meta( $meta );
		return empty( $meta ) ? '' : (string) wp_json_encode( $meta );
	}

	private static function sanitize_event_meta( array $meta ): array {
		$out = array();
		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' || ! is_scalar( $value ) ) {
				continue;
			}
			$value = EA_Utils::sanitize_text( (string) $value, 500 );
			if ( $value !== '' ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	public static function maybe_record_conversion( $session_id, $event_type, $event ) {
		$settings     = EA_Settings::get();
		$definitions  = (array) ( $settings['conversions'] ?? array() );
		$page_url     = EA_Utils::sanitize_url( $event['page_url'] ?? '' );
		$matched_key  = '';

		foreach ( $definitions as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$type = $def['type'] ?? '';
			$key  = $def['key'] ?? '';

			if ( self::matches_conversion( $type, $def, $event_type, $event, $page_url ) ) {
				$matched_key = $key;
				break;
			}
		}

		$matched_key = apply_filters( 'ea_conversion_detection', $matched_key, $event_type, $event, $session_id );
		if ( $matched_key === '' ) {
			return;
		}

		self::record_conversion( $session_id, $matched_key, $event );
	}

	private static function matches_conversion( $type, $def, $event_type, $event, $page_url ) {
		switch ( $type ) {
			case 'whatsapp_click':
				return in_array( $event_type, array( 'whatsapp_click', 'WhatsAppClick' ), true );
			case 'phone_click':
				return $event_type === 'phone_click';
			case 'email_click':
				return $event_type === 'email_click';
			case 'form_submit':
				return $event_type === 'form_submit';
			case 'custom_selector':
				$selector = (string) ( $def['value'] ?? '' );
				return $event_type === 'custom_selector' && $selector !== '' && ( (string) ( $event['value'] ?? '' ) === $selector || (string) ( $event['label'] ?? '' ) === $selector );
			case 'thank_you_page':
				$paths = (array) EA_Settings::get()['thank_you_paths'];
				if ( ! EA_Utils::matches_thank_you_path( $page_url, $paths ) ) {
					return false;
				}
				return $event_type === 'pageview' || $event_type === 'thank_you_page';
			default:
				return false;
		}
	}

	public static function record_conversion( $session_id, $conversion_key, $event = array() ) {
		global $wpdb;

		$conversions_table = EA_Utils::table( 'conversions' );
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$conversions_table} WHERE session_id = %s AND conversion_key = %s LIMIT 1",
				$session_id,
				$conversion_key
			)
		);
		if ( $exists ) {
			return;
		}

		$sessions_table = EA_Utils::table( 'sessions' );
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source, medium, campaign, landing_page, first_project_slug,
				        gclid, gbraid, wbraid, fbclid, meta_fbp, meta_fbc, ttclid, msclkid
				 FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
				$session_id
			),
			ARRAY_A
		);
		if ( ! is_array( $session ) ) {
			return;
		}

		$label = $conversion_key;
		foreach ( (array) EA_Settings::get()['conversions'] as $def ) {
			if ( is_array( $def ) && ( $def['key'] ?? '' ) === $conversion_key ) {
				$label = $def['label'] ?? $conversion_key;
				break;
			}
		}

		$project_slug = self::project_slug_for_conversion( $event, $event['type'] ?? '', $session );

		// conversion_value: intenta leer price_from del Project Registry.
		$conversion_value = 0;
		if ( $project_slug !== '' && function_exists( 'estandar_core_get_project' ) ) {
			$project = estandar_core_get_project( $project_slug );
			$conversion_value = (int) ( $project['price_from'] ?? 0 );
		}

		$wpdb->insert(
			$conversions_table,
			array(
				'session_id'       => EA_Utils::sanitize_text( $session_id, 64 ),
				'conversion_key'   => EA_Utils::sanitize_text( $conversion_key, 100 ),
				'conversion_label' => EA_Utils::sanitize_text( $label, 255 ),
				'page_url'         => EA_Utils::sanitize_url( $event['page_url'] ?? '' ),
				'project_slug'     => $project_slug,
				'source'           => EA_Utils::sanitize_text( $session['source'] ?? '', 100 ),
				'medium'           => EA_Utils::sanitize_text( $session['medium'] ?? '', 100 ),
				'campaign'         => EA_Utils::sanitize_text( $session['campaign'] ?? '', 255 ),
				'landing_page'     => EA_Utils::sanitize_url( $session['landing_page'] ?? '' ),
				'conversion_value' => $conversion_value,
				'converted_at'     => EA_Utils::now(),
			)
		);

		$date = EA_Utils::today();
		EA_Utils::increment_aggregate( $date, 'conversions' );
		EA_Utils::increment_aggregate( $date, 'conversion_key', 1, 'key', $conversion_key );
		if ( ! empty( $session['source'] ) ) {
			EA_Utils::increment_aggregate( $date, 'conversion_source', 1, 'source', $session['source'] );
		}
		if ( $project_slug !== '' ) {
			EA_Utils::increment_aggregate( $date, 'conversion_project', 1, 'project', $project_slug );
		}

		/**
		 * Hook de integración para plataformas de anuncios.
		 *
		 * Punto de extensión canónico para:
		 *   - Meta Conversions API (CAPI)
		 *   - Google Ads Enhanced Conversions
		 *   - TikTok Events API
		 *
		 * @param array $context {
		 *   @type string session_id
		 *   @type string conversion_key
		 *   @type string conversion_label
		 *   @type string project_slug
		 *   @type int    conversion_value   UF, 0 si no definido
		 *   @type string source
		 *   @type string medium
		 *   @type string campaign
		 *   @type string landing_page
		 *   @type string gclid             Google click ID
		 *   @type string gbraid            Google iOS click ID
		 *   @type string wbraid            Google web-to-app click ID
		 *   @type string fbclid            Meta click ID
		 *   @type string meta_fbp          Meta browser cookie (_fbp)
		 *   @type string meta_fbc          Meta click cookie (_fbc)
		 *   @type string ttclid            TikTok click ID
		 *   @type string msclkid           Microsoft Ads click ID
		 *   @type string converted_at      UTC datetime
		 * }
		 */
		do_action( 'estandar_analytics_conversion_recorded', array(
			'session_id'       => $session_id,
			'conversion_key'   => $conversion_key,
			'conversion_label' => $label,
			'project_slug'     => $project_slug,
			'conversion_value' => $conversion_value,
			'source'           => (string) ( $session['source']       ?? '' ),
			'medium'           => (string) ( $session['medium']       ?? '' ),
			'campaign'         => (string) ( $session['campaign']     ?? '' ),
			'landing_page'     => (string) ( $session['landing_page'] ?? '' ),
			'gclid'            => (string) ( $session['gclid']        ?? '' ),
			'gbraid'           => (string) ( $session['gbraid']       ?? '' ),
			'wbraid'           => (string) ( $session['wbraid']       ?? '' ),
			'fbclid'           => (string) ( $session['fbclid']       ?? '' ),
			'meta_fbp'         => (string) ( $session['meta_fbp']     ?? '' ),
			'meta_fbc'         => (string) ( $session['meta_fbc']     ?? '' ),
			'ttclid'           => (string) ( $session['ttclid']       ?? '' ),
			'msclkid'          => (string) ( $session['msclkid']      ?? '' ),
			'converted_at'     => EA_Utils::now(),
		) );
	}

	private static function with_form_project_authority( array $event, string $event_type ): array {
		if ( ! in_array( $event_type, array( 'form_start', 'form_submit' ), true ) ) {
			return $event;
		}

		$project_slug = sanitize_title( remove_accents( (string) ( $event['project_slug'] ?? '' ) ) );
		$authority = $project_slug !== '' ? 'form_project_slug' : '';
		if ( $project_slug === '' && class_exists( 'EA_Attribution' ) ) {
			$url = (string) ( $event['landing_url'] ?? ( $event['page_url'] ?? '' ) );
			$project_slug = EA_Attribution::resolve_project_slug( $url );
			$authority = $project_slug !== '' ? 'landing_resolver' : '';
		}
		if ( $project_slug === '' ) {
			return $event;
		}

		$event['project_slug'] = $project_slug;
		if ( ! isset( $event['meta'] ) || ! is_array( $event['meta'] ) ) {
			$event['meta'] = array();
		}
		$event['meta']['project_slug'] = $project_slug;
		$event['meta']['project_authority'] = $authority;

		return $event;
	}

	private static function project_slug_for_conversion( array $event, string $event_type, array $session ): string {
		if ( in_array( $event_type, array( 'form_start', 'form_submit' ), true ) ) {
			$event = self::with_form_project_authority( $event, $event_type );
			return EA_Utils::sanitize_text( $event['project_slug'] ?? '', 190 );
		}

		return EA_Utils::sanitize_text( $event['project_slug'] ?? ( $session['first_project_slug'] ?? '' ), 190 );
	}

	/**
	 * Record a server-side WhatsApp click event with SID metadata.
	 *
	 * @param string $session_id  Analytics session ID.
	 * @param string $project_slug
	 * @param string $sid_ref     Human-readable SID reference code (e.g. AC-X7K2P9).
	 * @param array  $meta        Additional metadata: operator_uuid, routing_mode, source, whatsapp_number.
	 */
	public static function record_whatsapp_click( string $session_id, string $project_slug, string $sid_ref, array $meta = [] ): void {
		global $wpdb;

		if ( $session_id === '' ) {
			return;
		}

		$table    = EA_Utils::table( 'events' );
		$event_id = EA_Utils::sanitize_text( $meta['event_id'] ?? '', 64 );
		if ( $event_id === '' ) {
			$event_id = wp_generate_uuid4();
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, meta_json FROM {$table} WHERE event_id = %s LIMIT 1", $event_id ), ARRAY_A );
		if ( is_array( $existing ) ) {
			update_option( 'ea_whatsapp_duplicates_avoided', (int) get_option( 'ea_whatsapp_duplicates_avoided', 0 ) + 1, false );
			$old_meta = json_decode( (string) ( $existing['meta_json'] ?? '' ), true );
			if ( ( $meta['reliability'] ?? '' ) === 'confirmed' && ( $old_meta['reliability'] ?? '' ) !== 'confirmed' ) {
				$old_meta = array_merge( is_array( $old_meta ) ? $old_meta : array(), $meta, array( 'event_id' => $event_id ) );
				$wpdb->update( $table, array( 'meta_json' => wp_json_encode( self::sanitize_event_meta( $old_meta ) ) ), array( 'id' => absint( $existing['id'] ) ) );
			}
			return;
		}
		$attribution = function_exists( 'estandar_analytics_get_attribution_by_session' ) ? estandar_analytics_get_attribution_by_session( $session_id ) : array();
		$meta_out = array_filter( [
			'event_id'       => $event_id,
			'project_slug'   => $project_slug,
			'project_name'   => (string) ( $meta['project_name'] ?? '' ),
			'sid_ref'        => $sid_ref,
			'operator_uuid'  => (string) ( $meta['operator_uuid'] ?? '' ),
			'routing_mode'   => (string) ( $meta['routing_mode'] ?? '' ),
			'whatsapp_number'=> (string) ( $meta['whatsapp_number'] ?? '' ),
			'destination_url'=> (string) ( $meta['destination_url'] ?? '' ),
			'source'         => (string) ( $meta['source'] ?? 'bridge' ),
			'reliability'    => (string) ( $meta['reliability'] ?? 'confirmed' ),
			'utm_source'     => (string) ( $attribution['utm_source'] ?? '' ),
			'utm_medium'     => (string) ( $attribution['utm_medium'] ?? '' ),
			'utm_campaign'   => (string) ( $attribution['utm_campaign'] ?? '' ),
			'gclid'          => (string) ( $attribution['gclid'] ?? '' ),
			'fbclid'         => (string) ( $attribution['fbclid'] ?? '' ),
		], fn( $v ) => $v !== '' );

		$occurred_at = EA_Utils::now();
		$timestamp = strtotime( (string) ( $meta['timestamp'] ?? '' ) );
		if ( $timestamp ) {
			$occurred_at = gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		$inserted = $wpdb->insert(
			$table,
			[
				'event_id'    => $event_id,
				'session_id'  => EA_Utils::sanitize_text( $session_id, 64 ),
				'event_type'  => 'WhatsAppClick',
				'event_label' => EA_Utils::sanitize_text( $project_slug, 255 ),
				'page_url'    => EA_Utils::sanitize_url( $meta['page_url'] ?? '' ),
				'event_value' => EA_Utils::sanitize_text( $sid_ref, 500 ),
				'meta_json'   => wp_json_encode( $meta_out ),
				'occurred_at' => $occurred_at,
			]
		);
		if ( ! $inserted ) {
			update_option( 'ea_whatsapp_errors', (int) get_option( 'ea_whatsapp_errors', 0 ) + 1, false );
			return;
		}

		$date = EA_Utils::today();
		EA_Utils::increment_aggregate( $date, 'events' );
		EA_Utils::increment_aggregate( $date, 'event_type', 1, 'type', 'WhatsAppClick' );
	}
}

/**
 * Record a server-side WhatsApp click into analytics.
 * Thin wrapper around EA_Events::record_whatsapp_click().
 *
 * @param string $session_id
 * @param string $project_slug
 * @param string $sid_ref
 * @param array  $meta
 */
function estandar_analytics_record_whatsapp_click( string $session_id, string $project_slug, string $sid_ref, array $meta = [] ): void {
	EA_Events::record_whatsapp_click( $session_id, $project_slug, $sid_ref, $meta );
}

function estandar_analytics_record_event( string $session_id, string $event_type, array $event = array() ): void {
	if ( $session_id === '' || $event_type === '' ) {
		return;
	}
	$event['type'] = $event_type;
	EA_Events::record_single( $session_id, $event );
}
