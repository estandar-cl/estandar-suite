<?php
/**
 * WhatsApp redirect endpoint.
 *
 * Ownership: estandar-leads.
 * Route: GET /wp-json/estandar-leads/v1/whatsapp-redirect
 *
 * Resolves the correct operator for a project_slug, generates a SID reference,
 * records the click in ea_whatsapp_sessions + ea_events, then HTTP 302 redirects
 * the user to wa.me with the SID injected into the message text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Whatsapp_Redirect {

	const ROUTE_NAMESPACE = 'estandar-leads/v1';
	const ROUTE_PATH      = '/whatsapp-redirect';

	// Safe SID alphabet: no 0, O, I, 1 to avoid human reading confusion.
	const SID_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const SID_CODE_LEN = 6;

	// 'email_autoresponse' = LEAD_RESPONSE_ACCELERATION_V1 (Fase 1) — WhatsApp
	// CTA in the autoresponse email. Additive entry, owner unchanged.
	const VALID_SOURCES = [ 'chatbot', 'form', 'button', 'email_autoresponse' ];

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [],
			]
		);
	}

	public static function handle( WP_REST_Request $request ): void {
		// sanitize_key() as the FIRST operation on all inputs.
		$project_slug = sanitize_key( (string) ( $_GET['project_slug'] ?? '' ) );
		$source       = sanitize_key( (string) ( $_GET['source'] ?? '' ) );
		$session_id   = sanitize_key( (string) ( $_GET['session_id'] ?? '' ) );
		$event_id     = sanitize_text_field( (string) ( $_GET['event_id'] ?? '' ) );

		// LEAD_RESPONSE_ACCELERATION_V1 (Fase 1) — optional, only meaningful
		// when source = 'email_autoresponse'. Absent for chatbot/form/button.
			$lead_id       = absint( $_GET['lead_id'] ?? 0 );
			$submission_id = absint( $_GET['submission_id'] ?? 0 );
			$tracking_token = (string) ( $_GET['tracking_token'] ?? '' );

			// Contextual message fields — passed as GET params by the form/chatbot JS.
			// No DB query: only what the submission already carries in the URL.
			$city = sanitize_text_field( (string) ( $_GET['city'] ?? '' ) );
			// campaign_location is the canonical name. campaign_city is accepted as an alias
			// for backward compatibility with forms already configured before this rename.
			$campaign_location = sanitize_text_field(
				(string) ( $_GET['campaign_location'] ?? $_GET['campaign_city'] ?? '' )
			);

			// CAMPAIGN_WHATSAPP_ROUTE_OVERRIDE_V1 — request signals for the route
			// filter context. GET only (no DB); a consumer may enrich lazily.
			$utm_source   = sanitize_text_field( (string) ( $_GET['utm_source'] ?? '' ) );
			$utm_medium   = sanitize_text_field( (string) ( $_GET['utm_medium'] ?? '' ) );
			$utm_campaign = sanitize_text_field( (string) ( $_GET['utm_campaign'] ?? '' ) );
			$landing_url  = isset( $_GET['landing_url'] ) ? esc_url_raw( (string) wp_unslash( $_GET['landing_url'] ) ) : '';

		// Source whitelist enforcement.
			if ( ! in_array( $source, self::VALID_SOURCES, true ) ) {
				$source = 'unknown';
			}

			if (
				$source === 'email_autoresponse'
				|| $tracking_token !== ''
				|| $lead_id > 0
				|| $submission_id > 0
			) {
				if ( ! Estandar_Leads_Tokens::verify_whatsapp_tracking(
					$tracking_token,
					$lead_id,
					$submission_id,
					$project_slug,
					$source
				) ) {
					wp_die( esc_html__( 'Enlace inválido o vencido.', 'estandar-leads' ), '', [ 'response' => 404 ] );
				}
			}

		// If project_slug is missing, try to resolve it from the analytics session.
		if ( $project_slug === '' && $session_id !== '' && function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
			$attribution  = estandar_analytics_get_attribution_by_session( $session_id );
			$project_slug = sanitize_key( (string) ( is_array( $attribution ) ? ( $attribution['first_project_slug'] ?? '' ) : '' ) );
		}

			$route = self::resolve_route( $source, $lead_id, $submission_id, $project_slug );

			// CAMPAIGN_WHATSAPP_ROUTE_OVERRIDE_V1 — additive seam. Default no-op:
			// no consumer is registered unless the site configures the campaign map
			// option. A consumer may override number/routing_mode; the base operator
			// is preserved unless the matching rule explicitly requests its removal.
			$filtered = apply_filters( 'estandar_leads_whatsapp_route', $route, array(
				'project_slug'      => $project_slug,
				'source'            => $source,
				'session_id'        => $session_id,
				'utm_source'        => $utm_source,
				'utm_medium'        => $utm_medium,
				'utm_campaign'      => $utm_campaign,
				'campaign_location' => $campaign_location,
				'landing_url'       => $landing_url,
			) );
			// Fail-safe: only accept a well-formed route with a usable number.
			if ( is_array( $filtered ) && isset( $filtered['number'] )
				&& preg_replace( '/\D+/', '', (string) $filtered['number'] ) !== '' ) {
				$route = $filtered;
			}

			$operator     = $route['operator'] ?? null;
			$routing_mode = (string) ( $route['routing_mode'] ?? 'unresolved' );
			$number       = (string) ( $route['number'] ?? '' );

		if ( $number === '' ) {
			// No number configured at all — fail gracefully.
			wp_die( esc_html__( 'No hay un número de WhatsApp configurado para este proyecto.', 'estandar-leads' ), '', [ 'response' => 404 ] );
		}

			// Resolve project context (sid_prefix + display name snapshot) from registry.
		$project_context = self::get_project_context( $project_slug );
		$sid_prefix      = $project_context['sid_prefix'];
		$project_name    = $project_context['project_name'];

		// Generate SID with anti-collision loop.
		$sid_ref = self::generate_sid( $sid_prefix );

		// Persist to wp_es_whatsapp_sessions.
		self::insert_session( $sid_ref, $session_id, $project_slug, $operator, $number, $source, $routing_mode );

			// Record in ea_events with meta_json (includes project_name snapshot).
			self::record_analytics_event( $session_id, $project_slug, $project_name, $sid_ref, $operator, $routing_mode, $source, $number, $event_id );

			if ( $source === 'email_autoresponse' && $lead_id > 0 ) {
				self::record_lead_whatsapp_opened( $lead_id, $submission_id, $project_slug, $source );
			}

			// LEAD_RESPONSE_ACCELERATION_V1 (Fase 2) — WhatsApp CTA click from the
			// autoresponse email counts toward the derived `autoresponse_engaged`
		// event (first of pdf/landing/whatsapp). Additive, no effect on
		// chatbot/form/button sources (lead_id absent → no-op).
		if ( $source === 'email_autoresponse' && $lead_id > 0 ) {
			Estandar_Leads_Autoresponse::record_engagement_if_new(
				$lead_id,
				$submission_id,
				'whatsapp',
				[
					'autoresponse_project_id' => $project_slug,
				]
			);
		}

			// Build wa.me redirect URL.
			$wa_number = preg_replace( '/\D+/', '', $number );

			$form_fields = [
				'city'              => $city,
				'campaign_location' => $campaign_location,
			];

			$message_template = (string) get_option( 'estandar_leads_whatsapp_prefill_message', '' );
			if ( trim( $message_template ) === '' ) {
				// Default template; lines with empty placeholders are stripped below.
				$message_template = $project_slug !== ''
					? implode( "\n", [
						'Hola.',
						'Acabo de solicitar información desde la web sobre {{project_name}} y me gustaría que me contactaran.',
						'Muchas gracias.',
					] )
					: implode( "\n", [
						'Hola.',
						'Acabo de solicitar información desde la web y me gustaría que me contactaran.',
						'Muchas gracias.',
					] );
			}

			$replacements = [
				'{{project_name}}'      => $project_name !== '' ? $project_name : $project_slug,
				'{{project_slug}}'      => $project_slug,
				'{{sid_ref}}'           => $sid_ref,
				'{{city}}'              => $city,
				'{{campaign_location}}' => $campaign_location,
			];

			// Placeholders that are conditional: if the value is empty, any line that
			// contains the placeholder is dropped entirely (not left with orphan text).
			$conditional = [
				'{{city}}'              => $city,
				'{{campaign_location}}' => $campaign_location,
			];

			$resolved_lines = [];
			foreach ( explode( "\n", $message_template ) as $line ) {
				foreach ( $conditional as $placeholder => $value ) {
					if ( $value === '' && strpos( $line, $placeholder ) !== false ) {
						continue 2; // Drop the whole line.
					}
				}
				$resolved = trim( strtr( $line, $replacements ) );
				if ( $resolved !== '' ) {
					$resolved_lines[] = $resolved;
				}
			}
			$default_message = implode( "\n", $resolved_lines );

			$default_message = trim( $default_message );

			/**
			 * Filter the WhatsApp pre-fill message.
			 *
			 * @param string     $default_message Resolved message text.
			 * @param string     $sid_ref         Session ID reference.
			 * @param string     $project_slug    Project slug.
			 * @param array|null $operator        Resolved operator data or null.
			 * @param string     $project_name    Project display name.
			 * @param array      $form_fields     Submission fields (city, campaign_location, …).
			 */
			$message = apply_filters(
				'estandar_leads_whatsapp_message',
				$default_message,
				$sid_ref,
				$project_slug,
				$operator,
				$project_name,
				$form_fields
			);
		$wa_url = 'https://wa.me/' . rawurlencode( $wa_number ) . '?text=' . rawurlencode( $message );

			add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_whatsapp_redirect_host' ) );
			wp_safe_redirect( $wa_url, 302 );
				exit;
			}

			public static function allow_whatsapp_redirect_host( array $hosts ): array {
				$hosts[] = 'wa.me';
				return array_values( array_unique( $hosts ) );
			}

		private static function record_lead_whatsapp_opened( int $lead_id, int $submission_id, string $project_slug, string $source ): void {
			$lead_id = absint( $lead_id );
			if ( $lead_id === 0 ) {
				return;
			}

			$operator = $submission_id > 0 ? Estandar_Leads_Repository::operator_for_submission( $lead_id, $submission_id ) : null;
			$operator_id = $operator ? absint( $operator->operator_id ) : 0;

			Estandar_Leads_Repository::insert_event( $lead_id, 'lead_whatsapp_opened', [
				'submission_id' => $submission_id ?: null,
				'operator_id'   => $operator_id ?: null,
				'to_status'     => sanitize_key( $project_slug ),
				'metadata'      => [
					'project_slug' => sanitize_key( $project_slug ),
					'source'       => sanitize_key( $source ),
				],
			] );

		}

		public static function resolve_route( string $source, int $lead_id, int $submission_id, string $project_slug ): array {
			$source = sanitize_key( $source );
			$project_slug = sanitize_key( $project_slug );
			$operator = null;
			$number = '';
			$routing_mode = 'unresolved';

			if ( $source === 'email_autoresponse' && $lead_id > 0 && $submission_id > 0 && class_exists( 'Estandar_Leads_Commercial' ) ) {
				$assigned = Estandar_Leads_Repository::operator_for_submission( $lead_id, $submission_id );
				$operator = Estandar_Leads_Commercial::from_assigned_operator( $assigned );
				if ( is_array( $operator ) ) {
					$number = (string) $operator['whatsapp_number'];
					$routing_mode = 'assigned_operator';
				} else {
					$fallback = Estandar_Leads_Commercial::fallback_number();
					if ( $fallback !== '' ) {
						$number = $fallback;
						$routing_mode = 'fallback';
					}
				}

				return array( 'operator' => $operator, 'number' => $number, 'routing_mode' => $routing_mode );
			}

			if ( $project_slug !== '' && class_exists( 'Estandar_Leads_Commercial' ) ) {
				$operator = Estandar_Leads_Commercial::resolve_whatsapp( $project_slug );
			}

			if ( is_array( $operator ) && $operator['whatsapp_number'] !== '' ) {
				$number = $operator['whatsapp_number'];
				$routing_mode = 'resolved';
			} elseif ( class_exists( 'Estandar_Leads_Commercial' ) ) {
				$fallback = Estandar_Leads_Commercial::fallback_number();
				if ( $fallback !== '' ) {
					$number = $fallback;
					$routing_mode = 'fallback';
				}
			}

			return array( 'operator' => $operator, 'number' => $number, 'routing_mode' => $routing_mode );
		}

		/**
	 * Resolve sid_prefix and display name snapshot from the project registry.
	 *
	 * @return array{ sid_prefix: string, project_name: string }
	 */
	private static function get_project_context( string $project_slug ): array {
		if ( $project_slug !== '' && function_exists( 'estandar_chatbot_get_project_registry' ) ) {
			$registry = estandar_chatbot_get_project_registry();
			foreach ( (array) $registry as $project ) {
				if ( ( $project['project_slug'] ?? '' ) === $project_slug ) {
					$prefix = strtoupper( (string) ( $project['sid_prefix'] ?? '' ) );
					$name   = (string) ( $project['name'] ?? '' );
					if ( strlen( $prefix ) < 2 ) {
						$slug_upper = strtoupper( preg_replace( '/[^A-Z]/i', '', $project_slug ) );
						$prefix     = substr( $slug_upper . 'XX', 0, 2 );
					}
					return [
						'sid_prefix'   => substr( $prefix, 0, 2 ),
						'project_name' => $name,
					];
				}
			}
		}

		// Slug not found in registry — derive prefix, leave name empty.
		$slug_upper = strtoupper( preg_replace( '/[^A-Z]/i', '', $project_slug ) );
		return [
			'sid_prefix'   => substr( $slug_upper . 'XX', 0, 2 ),
			'project_name' => '',
		];
	}

	private static function generate_sid( string $prefix ): string {
		global $wpdb;

		$table   = $wpdb->prefix . 'es_whatsapp_sessions';
		$alpha   = self::SID_ALPHABET;
		$alpha_len = strlen( $alpha );
		$code_len  = self::SID_CODE_LEN;

		do {
			$code = '';
			for ( $i = 0; $i < $code_len; $i++ ) {
				$code .= $alpha[ random_int( 0, $alpha_len - 1 ) ];
			}
			$sid_ref  = $prefix . '-' . $code;
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE sid_ref = %s LIMIT 1", $sid_ref )
			);
		} while ( $existing !== null );

		return $sid_ref;
	}

	private static function insert_session(
		string $sid_ref,
		string $session_id,
		string $project_slug,
		?array $operator,
		string $number,
		string $source,
		string $routing_mode
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'es_whatsapp_sessions',
			[
				'sid_ref'         => $sid_ref,
				'session_id'      => $session_id,
				'project_slug'    => $project_slug,
				'operator_uuid'   => (string) ( $operator['operator_uuid'] ?? '' ),
				'whatsapp_number' => $number,
				'source'          => $source,
				'routing_mode'    => $routing_mode,
				'created_at'      => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	private static function record_analytics_event(
		string $session_id,
		string $project_slug,
		string $project_name,
		string $sid_ref,
		?array $operator,
		string $routing_mode,
		string $source,
		string $number,
		string $event_id = ''
	): void {
		if ( $session_id === '' ) {
			return;
		}

		if ( function_exists( 'estandar_analytics_record_whatsapp_click' ) ) {
			estandar_analytics_record_whatsapp_click(
				$session_id,
				$project_slug,
				$sid_ref,
				[
					'project_name'    => $project_name,
					'operator_uuid'   => (string) ( $operator['operator_uuid'] ?? '' ),
					'routing_mode'    => $routing_mode,
					'source'          => $source,
					'whatsapp_number' => $number,
					'event_id'         => $event_id,
					'destination_url'  => 'https://wa.me/' . preg_replace( '/\D+/', '', $number ),
					'page_url'         => (string) ( $_SERVER['HTTP_REFERER'] ?? '' ),
					'reliability'      => 'confirmed',
				]
			);
		}
	}
}
