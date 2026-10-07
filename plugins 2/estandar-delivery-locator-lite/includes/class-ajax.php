<?php
/**
 * Handlers AJAX (confirmar pedido, enviar correo).
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-geo.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-validation.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-rate-limit.php';

class Estandar_Delivery_Locator_Ajax {

	const ACTION_CONFIRM = 'estandar_dl_confirm';
	const ACTION_EMAIL   = 'estandar_dl_email';

	/** @var int */
	const RL_CONFIRM_MAX = 20;

	/** @var int */
	const RL_CONFIRM_WINDOW = 3600;

	/** @var int */
	const RL_EMAIL_MAX = 12;

	/** @var int */
	const RL_EMAIL_WINDOW = 3600;

	/** @var int Segundos de validez del token de correo. */
	const EMAIL_TOKEN_TTL = 3600;

	public function register() {
		add_action( 'wp_ajax_' . self::ACTION_CONFIRM, array( $this, 'confirm_order' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_CONFIRM, array( $this, 'confirm_order' ) );
		add_action( 'wp_ajax_' . self::ACTION_EMAIL, array( $this, 'send_email' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_EMAIL, array( $this, 'send_email' ) );
	}

	/**
	 * Verifica nonce común.
	 */
	private function verify_nonce() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'estandar_dl_frontend' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sesión no válida. Recarga la página.', 'estandar-delivery-locator-lite' ) ), 403 );
		}
	}

	/**
	 * Genera clave de transient para token de correo (longitud acotada).
	 *
	 * @param string $token Token en hex.
	 * @return string
	 */
	private function email_token_transient_key( $token ) {
		return 'estandar_dl_et_' . hash( 'sha256', $token . wp_salt( 'estandar_dl_email' ) );
	}

	/**
	 * Guarda pedido tras confirmar ubicación. Valida zona en servidor.
	 */
	public function confirm_order() {
		$this->verify_nonce();

		if ( ! Estandar_Delivery_Locator_Rate_Limit::allow( Estandar_Delivery_Locator_Rate_Limit::ACTION_CONFIRM, self::RL_CONFIRM_MAX, self::RL_CONFIRM_WINDOW ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Demasiados intentos. Espera un momento e inténtalo de nuevo.', 'estandar-delivery-locator-lite' ) ),
				429
			);
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$address = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
		$lat_raw = isset( $_POST['lat'] ) ? wp_unslash( $_POST['lat'] ) : null;
		$lng_raw = isset( $_POST['lng'] ) ? wp_unslash( $_POST['lng'] ) : null;

		if ( '' === $name || strlen( $name ) > 190 ) {
			wp_send_json_error( array( 'message' => __( 'Indica un nombre válido.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		if ( '' === $phone || strlen( $phone ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Indica un teléfono válido.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		if ( '' === $address ) {
			wp_send_json_error( array( 'message' => __( 'Indica una dirección.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		if ( strlen( $address ) > Estandar_Delivery_Locator_Validation::MAX_ADDRESS_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'La dirección es demasiado larga.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		$lat = Estandar_Delivery_Locator_Validation::parse_coord( $lat_raw, -90, 90 );
		$lng = Estandar_Delivery_Locator_Validation::parse_coord( $lng_raw, -180, 180 );

		if ( null === $lat || null === $lng ) {
			wp_send_json_error( array( 'message' => __( 'Coordenadas no válidas.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		if ( Estandar_Delivery_Locator_Validation::is_null_island( $lat, $lng ) ) {
			wp_send_json_error( array( 'message' => __( 'Ubicación no válida. Ajusta el pin en el mapa.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		$settings = Estandar_Delivery_Locator_Settings::get();
		$inside   = Estandar_Delivery_Locator_Geo::is_inside_zone(
			$lat,
			$lng,
			(float) $settings['center_lat'],
			(float) $settings['center_lng'],
			(float) $settings['radius_km']
		);

		$id = Estandar_Delivery_Locator_Database::insert(
			array(
				'name'    => $name,
				'phone'   => $phone,
				'address' => $address,
				'lat'     => $lat,
				'lng'     => $lng,
			)
		);

		if ( false === $id ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar. Intenta de nuevo.', 'estandar-delivery-locator-lite' ) ), 500 );
		}

		$gmaps_url = 'https://www.google.com/maps?q=' . rawurlencode( $lat . ',' . $lng );

		// ea_sid para atribución en Analytics (enviado por el JS del frontend).
		$ea_sid = function_exists( 'estandar_core_get_ea_sid' ) ? estandar_core_get_ea_sid( $_POST ) : '';
		if ( '' === $ea_sid ) {
			$ea_sid = isset( $_POST['ea_sid'] ) ? sanitize_text_field( wp_unslash( $_POST['ea_sid'] ) ) : '';
			if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $ea_sid ) ) {
				$ea_sid = '';
			}
		}
		$source_page_url = isset( $_POST['source_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_page_url'] ) ) : '';

		/**
		 * Pedido guardado tras confirmar ubicación (antes de respuesta JSON).
		 *
		 * @param int   $order_id ID.
		 * @param array $data     name, phone, address, lat, lng, in_delivery_zone, google_maps_url.
		 */
		do_action(
			'estandar_dl_order_confirmed',
			$id,
			array(
				'name'               => $name,
				'phone'              => $phone,
				'address'            => $address,
				'lat'                => $lat,
				'lng'                => $lng,
				'in_delivery_zone'   => $inside,
				'google_maps_url'    => $gmaps_url,
			)
		);

		/**
		 * Emitir al ledger comercial unificado (wp_es_leads via estandar-leads).
		 * El hook es no-op si estandar-leads no está activo.
		 */
		if ( has_action( 'estandar_lead_captured' ) ) {
			do_action( 'estandar_lead_captured', array(
				'schema_version' => '1.0',
				'source_plugin'  => 'estandar-delivery-locator',
				'submitted_at'   => gmdate( 'c' ),
				'contact'        => array(
					'name'  => $name,
					'email' => '',
					'phone' => $phone,
				),
				'content'        => array(
					'message'        => $address,
					'source_form_id' => 'delivery-locator',
				),
				'context'        => array(
					'project_slug'    => '',
					'source_page_url' => $source_page_url,
				),
				'attribution'    => array(
					'utm_source'   => '',
					'utm_medium'   => '',
					'utm_campaign' => '',
					'utm_term'     => '',
					'utm_content'  => '',
					'gclid'        => '',
					'fbclid'       => '',
					'landing_url'  => $source_page_url,
					'referrer'     => sanitize_text_field( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) ),
					'session_id'   => $ea_sid,
				),
				'request'        => array(
					'ip_address' => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
					'user_agent' => sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				),
				'spam'           => array(
					'honeypot_filled'   => false,
					'seconds_to_submit' => null,
				),
				'meta'           => array(
					'address'          => $address,
					'lat'              => $lat,
					'lng'              => $lng,
					'in_delivery_zone' => $inside,
					'google_maps_url'  => $gmaps_url,
					'order_id'         => $id,
				),
			) );
		}

		Estandar_Delivery_Locator_N8n::notify_order_confirmed( $id, $name, $phone, $address, $lat, $lng, $inside, $gmaps_url );

		$whatsapp = preg_replace( '/[^0-9]/', '', (string) $settings['whatsapp'] );

		$email_token = bin2hex( random_bytes( 32 ) );
		set_transient( $this->email_token_transient_key( $email_token ), $id, self::EMAIL_TOKEN_TTL );

		wp_send_json_success(
			array(
				'order_id'     => $id,
				'email_token'  => $email_token,
				'in_zone'      => $inside,
				'whatsapp'     => $whatsapp,
				'gmaps_link'   => $gmaps_url,
			)
		);
	}

	/**
	 * Envía correo al administrador con los datos del pedido (requiere token emitido al confirmar).
	 */
	public function send_email() {
		$this->verify_nonce();

		if ( ! Estandar_Delivery_Locator_Rate_Limit::allow( Estandar_Delivery_Locator_Rate_Limit::ACTION_EMAIL, self::RL_EMAIL_MAX, self::RL_EMAIL_WINDOW ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Demasiados envíos. Espera un momento e inténtalo de nuevo.', 'estandar-delivery-locator-lite' ) ),
				429
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$token    = isset( $_POST['email_token'] ) ? sanitize_text_field( wp_unslash( $_POST['email_token'] ) ) : '';

		if ( ! $order_id || '' === $token || strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud de correo no válida.', 'estandar-delivery-locator-lite' ) ), 400 );
		}

		$row = Estandar_Delivery_Locator_Database::get_by_id( $order_id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Pedido no encontrado.', 'estandar-delivery-locator-lite' ) ), 404 );
		}

		if ( ! empty( $row['email_sent'] ) ) {
			wp_send_json_success(
				array(
					'message'      => __( 'Este pedido ya tiene el correo enviado.', 'estandar-delivery-locator-lite' ),
					'already_sent' => true,
				)
			);
		}

		$tkey     = $this->email_token_transient_key( $token );
		$expected = get_transient( $tkey );

		if ( false === $expected || (int) $expected !== $order_id ) {
			wp_send_json_error( array( 'message' => __( 'El enlace de correo expiró o no es válido. Confirma de nuevo la ubicación.', 'estandar-delivery-locator-lite' ) ), 403 );
		}

		$settings = Estandar_Delivery_Locator_Settings::get();
		$to       = $settings['email'];
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Email de destino no configurado.', 'estandar-delivery-locator-lite' ) ), 500 );
		}

		$maps_url = 'https://www.google.com/maps?q=' . rawurlencode( $row['lat'] . ',' . $row['lng'] );

		$subject = sprintf(
			/* translators: %s: order id */
			__( '[Delivery Locator] Solicitud #%s', 'estandar-delivery-locator-lite' ),
			$row['id']
		);

		$body  = __( 'Nueva solicitud con ubicación:', 'estandar-delivery-locator-lite' ) . "\n\n";
		$body .= __( 'ID:', 'estandar-delivery-locator-lite' ) . ' ' . $row['id'] . "\n";
		$body .= __( 'Nombre:', 'estandar-delivery-locator-lite' ) . ' ' . $row['name'] . "\n";
		$body .= __( 'Teléfono:', 'estandar-delivery-locator-lite' ) . ' ' . $row['phone'] . "\n";
		$body .= __( 'Dirección:', 'estandar-delivery-locator-lite' ) . ' ' . $row['address'] . "\n";
		$body .= __( 'Latitud:', 'estandar-delivery-locator-lite' ) . ' ' . $row['lat'] . "\n";
		$body .= __( 'Longitud:', 'estandar-delivery-locator-lite' ) . ' ' . $row['lng'] . "\n";
		$body .= __( 'Fecha:', 'estandar-delivery-locator-lite' ) . ' ' . $row['created_at'] . "\n\n";
		$body .= __( 'Mapa:', 'estandar-delivery-locator-lite' ) . ' ' . $maps_url . "\n";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$from_filter = function ( $email ) {
			return function_exists( 'estandar_core_get_sender_email' )
				? estandar_core_get_sender_email( '', (string) $email )
				: $email;
		};
		$name_filter = function ( $name ) {
			return function_exists( 'estandar_core_get_sender_name' )
				? estandar_core_get_sender_name( '', (string) $name )
				: $name;
		};
		add_filter( 'wp_mail_from', $from_filter );
		add_filter( 'wp_mail_from_name', $name_filter );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_filter( 'wp_mail_from', $from_filter );
		remove_filter( 'wp_mail_from_name', $name_filter );

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo enviar el correo. Revisa la configuración del sitio.', 'estandar-delivery-locator-lite' ) ), 500 );
		}

		Estandar_Delivery_Locator_Database::mark_email_sent( $order_id );
		delete_transient( $tkey );

		/**
		 * Correo al administrador enviado correctamente.
		 *
		 * @param int   $order_id ID pedido.
		 * @param array $row      Fila de pedido.
		 */
		do_action( 'estandar_dl_email_sent', $order_id, $row );

		Estandar_Delivery_Locator_N8n::notify_email_sent( $row );

		wp_send_json_success(
			array(
				'message' => __( 'Solicitud enviada por correo.', 'estandar-delivery-locator-lite' ),
			)
		);
	}
}
