<?php
/**
 * Commercial routing for WhatsApp.
 *
 * Ownership: estandar-leads.
 * Responsible for: resolving the correct WhatsApp number and operator for a project_slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	class Estandar_Leads_Commercial {

		const CAMPAIGN_UNKNOWN = '(sin campaña)';

		public static function from_assigned_operator( $operator ): ?array {
			if ( ! is_object( $operator ) ) {
				return null;
			}

			$number = preg_replace( '/\D+/', '', (string) ( $operator->whatsapp_number ?? '' ) );
			if ( empty( $operator->active ) || $number === '' ) {
				return null;
			}

			return [
				'operator_uuid'   => (string) ( $operator->operator_uuid ?? '' ),
				'operator_name'   => (string) ( $operator->operator_name ?? '' ),
				'whatsapp_number' => $number,
			];
		}

	/**
	 * Resolve the active WhatsApp operator for a project slug.
	 *
	 * @param string $project_slug
	 * @return array|null { operator_uuid, operator_name, whatsapp_number } or null if not found.
	 */
		public static function resolve_whatsapp( string $project_slug ): ?array {
			$project_slug = Estandar_Leads_Projects::normalize_slug( $project_slug );
			if ( $project_slug === '' ) {
				return null;
			}

			$operator = Estandar_Leads_Operator_Resolver::first_for_project($project_slug, array(
				'require_whatsapp' => true,
				'include_fallback' => false,
			));
			if ( ! is_object( $operator ) ) {
				return null;
			}

			return [
				'operator_uuid'   => (string) ( $operator->operator_uuid ?? '' ),
				'operator_name'   => (string) ( $operator->name ?? '' ),
				'whatsapp_number' => (string) ( $operator->whatsapp_number ?? '' ),
			];
		}

		/**
		 * Commercial Intelligence V1.
		 *
		 * Aggregates existing lead/submission/operator-feedback data by utm_campaign.
		 * No writes, no schema changes, no external APIs.
		 *
		 * @param array $args Optional: start_date, end_date (Y-m-d H:i:s), limit.
		 * @return array<string,array{utm_campaign:string,total_leads:int,empresas_constituidas:int,creando_empresa:int,personas_naturales:int,junk:int,tasa_empresa:float}>
		 */
		public static function intelligence_by_campaign( array $args = array() ): array {
			global $wpdb;

			$submissions     = $wpdb->prefix . 'es_lead_submissions';
			$leads           = $wpdb->prefix . 'es_leads';
			$classifications = $wpdb->prefix . 'es_lead_classifications';

			$where  = array( 's.lead_id > 0' );
			$params = array();

			if ( ! empty( $args['start_date'] ) ) {
				$where[]  = 's.created_at >= %s';
				$params[] = sanitize_text_field( (string) $args['start_date'] );
			}

			if ( ! empty( $args['end_date'] ) ) {
				$where[]  = 's.created_at <= %s';
				$params[] = sanitize_text_field( (string) $args['end_date'] );
			}

			$sql = "
				SELECT
					s.lead_id,
					s.utm_campaign,
					s.context_json,
					s.form_fields_json,
					l.quality AS lead_quality,
					l.is_valid_lead,
					c.quality AS feedback_quality,
					c.is_valid_lead AS feedback_is_valid
				FROM {$submissions} s
				INNER JOIN {$leads} l ON l.id = s.lead_id
				LEFT JOIN (
					SELECT c1.lead_id, c1.quality, c1.is_valid_lead
					FROM {$classifications} c1
					INNER JOIN (
						SELECT lead_id, MAX(id) AS max_id
						FROM {$classifications}
						GROUP BY lead_id
					) latest ON latest.max_id = c1.id
				) c ON c.lead_id = l.id
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY s.created_at DESC, s.id DESC
			";

			$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 0;
			if ( $limit > 0 ) {
				$sql .= ' LIMIT %d';
				$params[] = $limit;
			}

			$rows = empty( $params )
				? $wpdb->get_results( $sql )
				: $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			$aggregates = array();
			$seen       = array();

			foreach ( (array) $rows as $row ) {
				$lead_id  = absint( $row->lead_id ?? 0 );
				$campaign = self::normalize_campaign( (string) ( $row->utm_campaign ?? '' ) );
				if ( $lead_id <= 0 ) {
					continue;
				}

				$key = $campaign . ':' . $lead_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				if ( ! isset( $aggregates[ $campaign ] ) ) {
					$aggregates[ $campaign ] = self::empty_intelligence_row( $campaign );
				}

				$type = self::client_type_from_submission( $row );
				$aggregates[ $campaign ]['total_leads']++;

				if ( $type === 'empresa_constituida' ) {
					$aggregates[ $campaign ]['empresas_constituidas']++;
				} elseif ( $type === 'creando_empresa' ) {
					$aggregates[ $campaign ]['creando_empresa']++;
				} elseif ( $type === 'persona_natural' ) {
					$aggregates[ $campaign ]['personas_naturales']++;
				}

				if ( self::is_junk_feedback( $row ) ) {
					$aggregates[ $campaign ]['junk']++;
				}
			}

			foreach ( $aggregates as &$row ) {
				$empresa_total       = $row['empresas_constituidas'] + $row['creando_empresa'];
				$row['tasa_empresa'] = $row['total_leads'] > 0
					? round( $empresa_total / $row['total_leads'], 4 )
					: 0.0;
			}
			unset( $row );

			uasort( $aggregates, static function ( $a, $b ): int {
				return ( $b['total_leads'] <=> $a['total_leads'] ) ?: strcmp( $a['utm_campaign'], $b['utm_campaign'] );
			} );

			return array_values( $aggregates );
		}

		private static function empty_intelligence_row( string $campaign ): array {
			return array(
				'utm_campaign'           => $campaign,
				'total_leads'            => 0,
				'empresas_constituidas'  => 0,
				'creando_empresa'        => 0,
				'personas_naturales'     => 0,
				'junk'                   => 0,
				'tasa_empresa'           => 0.0,
			);
		}

		private static function normalize_campaign( string $campaign ): string {
			$campaign = trim( sanitize_text_field( $campaign ) );
			return $campaign !== '' ? $campaign : self::CAMPAIGN_UNKNOWN;
		}

		private static function client_type_from_submission( $row ): string {
			$context = self::decode_json_object( $row->context_json ?? '' );
			$fields  = self::decode_json_object( $row->form_fields_json ?? '' );

			$value = self::first_present( $context, array( 'tipo_cliente', 'client_type', 'tipo_de_cliente' ) );
			if ( $value === '' ) {
				$value = self::first_present( $fields, array( 'tipo_cliente', 'client_type', 'tipo_de_cliente' ) );
			}

			$value = strtolower( sanitize_key( $value ) );
			$value = str_replace( array( '-', ' ' ), '_', $value );

			if ( in_array( $value, array( 'empresa_constituida', 'empresa', 'empresa_constituidas' ), true ) ) {
				return 'empresa_constituida';
			}

			if ( in_array( $value, array( 'creando_empresa', 'estoy_creando_una_empresa', 'crear_empresa' ), true ) ) {
				return 'creando_empresa';
			}

			if ( in_array( $value, array( 'persona_natural', 'persona' ), true ) ) {
				return 'persona_natural';
			}

			return '';
		}

		private static function first_present( array $data, array $keys ): string {
			foreach ( $keys as $key ) {
				if ( isset( $data[ $key ] ) && trim( (string) $data[ $key ] ) !== '' ) {
					return (string) $data[ $key ];
				}
			}
			return '';
		}

		private static function decode_json_object( $raw ): array {
			$decoded = json_decode( (string) $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		private static function is_junk_feedback( $row ): bool {
			$lead_quality     = strtoupper( sanitize_key( (string) ( $row->lead_quality ?? '' ) ) );
			$feedback_quality = strtoupper( sanitize_key( (string) ( $row->feedback_quality ?? '' ) ) );

			if ( $lead_quality === 'JUNK' || $feedback_quality === 'JUNK' ) {
				return true;
			}

			if ( isset( $row->is_valid_lead ) && (string) $row->is_valid_lead === '0' ) {
				return true;
			}

			return isset( $row->feedback_is_valid ) && (string) $row->feedback_is_valid === '0';
		}

	/**
	 * Get the configured fallback WhatsApp number.
	 *
	 * @return string E.164-style number or '' if not configured.
	 */
	public static function fallback_number(): string {
		$number = (string) get_option( 'estandar_leads_whatsapp_fallback', '' );
		if ( $number === '' ) {
			// Back-compat: also check the chatbot's general number option.
			$number = (string) get_option( 'chatbot_human_whatsapp_number', '' );
		}
		return preg_replace( '/\D+/', '', $number );
	}

	/**
	 * CAMPAIGN_WHATSAPP_ROUTE_OVERRIDE_V1 — opt-in registration.
	 * Adds the 'estandar_leads_whatsapp_route' consumer ONLY when the site has a
	 * non-empty campaign map option. Sites without the option never register the
	 * filter → zero cost, identical behavior.
	 */
	public static function maybe_register_campaign_override(): void {
		$raw = get_option( 'estandar_leads_campaign_whatsapp_map', '' );
		$has = ( is_string( $raw ) && ! in_array( trim( $raw ), array( '', '[]', '{}' ), true ) )
			|| ( is_array( $raw ) && ! empty( $raw ) );
		if ( ! $has ) {
			return;
		}
		add_filter( 'estandar_leads_whatsapp_route', array( __CLASS__, 'apply_campaign_whatsapp_override' ), 10, 2 );
	}

	/**
	 * CAMPAIGN_WHATSAPP_ROUTE_OVERRIDE_V1 — generic, config-driven consumer.
	 *
	 * Reads the site option 'estandar_leads_campaign_whatsapp_map' and, if a rule
	 * matches the request context, overrides ONLY the WhatsApp number + routing_mode.
	 * The base operator is preserved (commercial traceability) unless the rule sets
	 * destination.override_operator = true. No client/campaign/number strings live here.
	 *
	 * Default no-op: empty/invalid option => $route returned unchanged.
	 *
	 * @param array $route   { operator: ?array, number: string, routing_mode: string }
	 * @param array $context whitelisted request signals
	 * @return array
	 */
	public static function apply_campaign_whatsapp_override( $route, $context ): array {
		if ( ! is_array( $route ) || ! array_key_exists( 'number', $route ) ) {
			return is_array( $route ) ? $route : array( 'operator' => null, 'number' => '', 'routing_mode' => 'unresolved' );
		}
		$context = is_array( $context ) ? $context : array();

		$rules = self::campaign_whatsapp_rules();
		if ( empty( $rules ) ) {
			return $route;
		}

		$allowed_keys = array(
			'project_slug', 'source', 'session_id',
			'utm_source', 'utm_medium', 'utm_campaign', 'campaign_location', 'landing_url',
		);

		foreach ( $rules as $index => $rule ) {
			$matched = true;
			foreach ( $rule['match'] as $key => $expected ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					$matched = false; // fail-closed on unknown key
					break;
				}
				$actual = isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
				// utm_campaign may be absent from GET; enrich once from the session.
				if ( $actual === '' && $key === 'utm_campaign' ) {
					$actual = self::enrich_utm_campaign_from_session( (string) ( $context['session_id'] ?? '' ) );
				}
				if ( self::norm_cmp( $actual ) !== self::norm_cmp( (string) $expected ) ) {
					$matched = false;
					break;
				}
			}
			if ( ! $matched ) {
				continue;
			}

			$phone = preg_replace( '/\D+/', '', (string) ( $rule['destination']['phone'] ?? '' ) );
			if ( $phone === '' ) {
				continue; // invalid destination — keep scanning
			}

			$route['number']       = $phone;
			$mode                  = sanitize_key( (string) ( $rule['routing_mode'] ?? 'campaign_override' ) );
			$route['routing_mode'] = $mode !== '' ? $mode : 'campaign_override';
			// Adjust 1: preserve base operator by default; drop only if requested.
			if ( ! empty( $rule['destination']['override_operator'] ) ) {
				$route['operator'] = null;
			}

			self::log_campaign_override( (int) $index, $context, $route );
			return $route;
		}

		return $route;
	}

	/** Read + validate the campaign map option into a list of well-formed rules. */
	private static function campaign_whatsapp_rules(): array {
		$raw = get_option( 'estandar_leads_campaign_whatsapp_map', '' );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $decoded ) || empty( $decoded['rules'] ) || ! is_array( $decoded['rules'] ) ) {
			return array();
		}

		$rules = array();
		foreach ( $decoded['rules'] as $rule ) {
			if ( count( $rules ) >= 100 ) {
				break; // bound the loop
			}
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$match = $rule['match'] ?? null;
			$dest  = $rule['destination'] ?? null;
			if ( ! is_array( $match ) || $match === array() || ! is_array( $dest ) ) {
				continue;
			}
			if ( (string) ( $dest['type'] ?? 'whatsapp' ) !== 'whatsapp' ) {
				continue; // v1: only whatsapp destinations
			}
			$clean_match = array();
			$rule_ok     = true;
			foreach ( $match as $k => $v ) {
				if ( ! is_scalar( $v ) ) {
					$rule_ok = false;
					break;
				}
				$clean_match[ sanitize_key( (string) $k ) ] = (string) $v;
			}
			if ( ! $rule_ok || $clean_match === array() ) {
				continue;
			}
			$rules[] = array(
				'match'        => $clean_match,
				'destination'  => $dest,
				'routing_mode' => $rule['routing_mode'] ?? 'campaign_override',
			);
		}
		return $rules;
	}

	private static function enrich_utm_campaign_from_session( string $session_id ): string {
		$session_id = sanitize_key( $session_id );
		if ( $session_id === '' || ! function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
			return '';
		}
		$attr = estandar_analytics_get_attribution_by_session( $session_id );
		return is_array( $attr ) ? (string) ( $attr['utm_campaign'] ?? '' ) : '';
	}

	private static function norm_cmp( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private static function log_campaign_override( int $rule_index, array $context, array $route ): void {
		$debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || apply_filters( 'estandar_leads_whatsapp_route_debug', false );
		if ( ! $debug ) {
			return;
		}
		$number = (string) ( $route['number'] ?? '' );
		$masked = $number !== '' ? substr( $number, 0, 4 ) . str_repeat( '*', max( 0, strlen( $number ) - 4 ) ) : '';
		do_action( 'estandar_telemetry_event', array(
			'source_plugin' => 'estandar-leads',
			'type'          => 'business_event',
			'severity'      => 'info',
			'code'          => 'WHATSAPP_ROUTE_CAMPAIGN_OVERRIDE',
			'message'       => 'WhatsApp route overridden by campaign rule.',
			'context'       => array(
				'rule_index'        => $rule_index,
				'project_slug'      => (string) ( $context['project_slug'] ?? '' ),
				'utm_campaign'      => (string) ( $context['utm_campaign'] ?? '' ),
				'campaign_location' => (string) ( $context['campaign_location'] ?? '' ),
				'routing_mode'      => (string) ( $route['routing_mode'] ?? '' ),
				'number_masked'     => $masked,
			),
		) );
	}

	/**
	 * Ensure an operator has a stable operator_uuid.
	 * Called on operator save. Idempotent.
	 *
	 * @param int $operator_id DB primary key.
	 */
	public static function ensure_operator_uuid( int $operator_id ): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'es_operators';
		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT operator_uuid FROM {$table} WHERE id = %d",
				$operator_id
			)
		);

		if ( ! empty( $current ) ) {
			return;
		}

		$uuid = self::generate_uuid_v4();
		$wpdb->update(
			$table,
			[ 'operator_uuid' => $uuid ],
			[ 'id' => $operator_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Backfill operator_uuid for all operators that are missing one.
	 * Run once after schema migration.
	 */
	public static function backfill_operator_uuids(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'es_operators';
		$ids   = $wpdb->get_col(
			"SELECT id FROM {$table} WHERE operator_uuid = '' OR operator_uuid IS NULL"
		);

		foreach ( (array) $ids as $id ) {
			self::ensure_operator_uuid( (int) $id );
		}
	}

	private static function generate_uuid_v4(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
