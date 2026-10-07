<?php
/**
 * Webhook dispatcher for Estandar Forms.
 *
 * Fires a non-blocking POST to the configured URL when a form lead is captured.
 * Follows the same pattern as estandar-delivery-locator-lite/includes/class-n8n.php.
 *
 * Webhook URL is stored per-form in the send_webhook + webhook_url columns.
 * Default payload is the estandar_lead_captured schema v1.0.
 * Per form, webhook_payload_mode=cf7_compat can emit CF7-like fields for
 * existing n8n workflows.
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Forms_Webhook {

	/**
	 * Register hook listener. Called once on plugin init.
	 */
	public static function init(): void {
		add_action( 'estandar_lead_captured', array( __CLASS__, 'maybe_dispatch' ), 20, 1 );
	}

	/**
	 * Fire webhook if the originating form has send_webhook=1 and a webhook_url set.
	 *
	 * Priority 20 ensures this runs after estandar-leads (priority 10) has already
	 * ingested the lead, so a webhook failure never affects lead storage.
	 *
	 * @param array $payload  estandar_lead_captured payload (schema v1.0).
	 */
	public static function maybe_dispatch( array $payload ): void {
		if ( get_option( 'estandar_leads_webhook_owner', 'legacy_forms' ) === 'leads' ) {
			return;
		}

		// Only handle events emitted by this plugin.
		if ( ( $payload['source_plugin'] ?? '' ) !== 'estandar-forms' ) {
			return;
		}

		$form_id = (int) ( $payload['content']['source_form_id'] ?? 0 );
		if ( $form_id <= 0 ) {
			return;
		}

		$form = self::get_form( $form_id );
		if ( ! $form ) {
			return;
		}

		if ( empty( $form->send_webhook ) || empty( $form->webhook_url ) ) {
			return;
		}

		$url = trim( (string) $form->webhook_url );
		if ( ! wp_http_validate_url( $url ) ) {
			estandar_forms_telemetry_event( 'warning', 'FORMS_WEBHOOK_INVALID_URL', 'Webhook URL is invalid.', array(
				'form_id' => $form_id,
			) );
			return;
		}

		$mode = sanitize_key( (string) ( $form->webhook_payload_mode ?? 'estandar' ) );
		if ( ! in_array( $mode, [ 'estandar', 'cf7_compat' ], true ) ) {
			$mode = 'estandar';
		}

		self::dispatch( $url, $payload, $form_id, $mode );
	}

	/**
	 * Send the webhook payload via non-blocking POST.
	 *
	 * @param string $url      Validated webhook endpoint.
	 * @param array  $payload  Lead payload to send.
	 * @param int    $form_id  For telemetry only.
	 * @param string $mode     Payload mode.
	 */
	private static function dispatch( string $url, array $payload, int $form_id, string $mode = 'estandar' ): void {
		$args = array(
			'timeout'   => 8,
			'blocking'  => false,   // Fire-and-forget: no espera respuesta, no bloquea el request.
			'headers'   => array( 'X-Source' => 'estandar-forms' ),
			'body'      => '',
			'sslverify' => true,
		);

		if ( $mode === 'cf7_compat' ) {
			$sent_payload = self::build_cf7_compat_payload( $payload );
			$sent_payload = (array) apply_filters( 'estandar_forms_webhook_cf7_payload', $sent_payload, $payload, $form_id );
			$args['body'] = $sent_payload;
		} else {
			/**
			 * Allow filtering the webhook payload before dispatch.
			 * Example: add custom fields, strip PII, reformat for a specific CRM.
			 *
			 * @param array $payload  Full estandar_lead_captured payload.
			 * @param int   $form_id
			 */
			$sent_payload = (array) apply_filters( 'estandar_forms_webhook_payload', $payload, $form_id );
			$body = wp_json_encode( $sent_payload );
			if ( ! $body ) {
				return;
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = $body;
		}

		/**
		 * Allow filtering wp_remote_post args before dispatch.
		 *
		 * @param array  $args
		 * @param string $url
		 * @param int    $form_id
		 */
		$args = (array) apply_filters( 'estandar_forms_webhook_args', $args, $url, $form_id );

		wp_remote_post( $url, $args );

		estandar_forms_telemetry_event( 'info', 'FORMS_WEBHOOK_DISPATCHED', 'Webhook dispatched for form submission.', array(
			'form_id'      => $form_id,
			'payload_mode' => $mode,
			'url_domain'   => wp_parse_url( $url, PHP_URL_HOST ),
		) );

		/**
		 * Fired after webhook dispatch attempt (blocking=false means delivery is not guaranteed).
		 *
		 * @param string $url     Endpoint.
		 * @param array  $payload Payload sent.
		 * @param int    $form_id
		 */
		do_action( 'estandar_forms_webhook_sent', $url, $sent_payload, $form_id );
	}

	private static function build_cf7_compat_payload( array $payload ): array {
		$fields = array();
		if ( isset( $payload['meta']['sanitized_raw_fields'] ) && is_array( $payload['meta']['sanitized_raw_fields'] ) ) {
			$fields = $payload['meta']['sanitized_raw_fields'];
		}

		$contact = isset( $payload['contact'] ) && is_array( $payload['contact'] )
			? $payload['contact']
			: array();

		return array(
			'_date'         => date_i18n( 'j \d\e F \d\e Y', current_time( 'timestamp' ) ),
			'your-name'     => self::first_value( $fields, array( 'your-name', 'nombre', 'name', 'first_name', 'firstname' ), (string) ( $contact['name'] ?? '' ) ),
			'your-apellido' => self::first_value( $fields, array( 'your-apellido', 'apellido', 'last_name', 'lastname' ) ),
			'your-email'    => self::first_value( $fields, array( 'your-email', 'email', 'correo', 'mail' ), (string) ( $contact['email'] ?? '' ) ),
			'your-phone'    => self::first_value( $fields, array( 'your-phone', 'telefono', 'phone', 'tel', 'celular', 'mobile' ), (string) ( $contact['phone'] ?? '' ) ),
			'tu-rut'        => self::first_value( $fields, array( 'tu-rut', 'rut', 'RUT' ) ),
		);
	}

	private static function first_value( array $fields, array $keys, string $fallback = '' ): string {
		foreach ( $keys as $key ) {
			if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ) {
				$value = sanitize_text_field( (string) $fields[ $key ] );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}

		return sanitize_text_field( $fallback );
	}

	/** @return object|null */
	private static function get_form( int $form_id ): ?object {
		global $wpdb;
		$form = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT send_webhook, webhook_url FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
				$form_id
			)
		);

		if ( ! $form ) {
			return null;
		}

		$form->webhook_payload_mode = 'estandar';
		if ( self::column_exists( $wpdb->prefix . 'estandar_forms', 'webhook_payload_mode' ) ) {
			$mode = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT webhook_payload_mode FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
					$form_id
				)
			);
			$form->webhook_payload_mode = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : 'estandar';
		}

		return $form;
	}

	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return false;
		}
		foreach ( $columns as $row ) {
			if ( isset( $row['Field'] ) && (string) $row['Field'] === $column ) {
				return true;
			}
		}
		return false;
	}
}
