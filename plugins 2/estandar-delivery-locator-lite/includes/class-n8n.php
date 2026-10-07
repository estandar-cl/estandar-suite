<?php
/**
 * Webhook JSON hacia n8n (u otro orquestador) tras eventos del localizador.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_N8n {

	/**
	 * Tras confirmar ubicación y guardar pedido.
	 *
	 * @param int    $order_id       ID pedido.
	 * @param string $name           Nombre.
	 * @param string $phone          Teléfono.
	 * @param string $address        Dirección.
	 * @param float  $lat            Latitud.
	 * @param float  $lng            Longitud.
	 * @param bool   $in_zone        Dentro del radio.
	 * @param string $google_maps_url URL mapa.
	 */
	public static function notify_order_confirmed( $order_id, $name, $phone, $address, $lat, $lng, $in_zone, $google_maps_url ) {
		$s = Estandar_Delivery_Locator_Settings::get();
		if ( empty( $s['n8n_notify_confirm'] ) ) {
			return;
		}

		$payload = array(
			'event'             => 'estandar_dl.order_confirmed',
			'version'           => 1,
			'site_url'          => home_url( '/' ),
			'order_id'          => (int) $order_id,
			'name'              => $name,
			'phone'             => $phone,
			'address'           => $address,
			'lat'               => (float) $lat,
			'lng'               => (float) $lng,
			'in_delivery_zone'  => (bool) $in_zone,
			'google_maps_url'   => $google_maps_url,
			'created_at_utc'    => gmdate( 'c' ),
		);

		$payload = apply_filters( 'estandar_dl_n8n_payload', $payload, 'estandar_dl.order_confirmed', $order_id );

		self::post_json( $payload );

		/**
		 * Tras encolar el POST al webhook (no bloqueante).
		 *
		 * @param int   $order_id ID pedido.
		 * @param array $payload  Cuerpo enviado (tras filtro).
		 */
		do_action( 'estandar_dl_n8n_order_confirmed_sent', $order_id, $payload );
	}

	/**
	 * Tras enviar correo al administrador correctamente.
	 *
	 * @param array $row Fila de Estandar_Delivery_Locator_Database::get_by_id.
	 */
	public static function notify_email_sent( $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return;
		}

		$s = Estandar_Delivery_Locator_Settings::get();
		if ( empty( $s['n8n_notify_email'] ) ) {
			return;
		}

		$maps = 'https://www.google.com/maps?q=' . rawurlencode( $row['lat'] . ',' . $row['lng'] );

		$payload = array(
			'event'            => 'estandar_dl.email_sent',
			'version'          => 1,
			'site_url'         => home_url( '/' ),
			'order_id'         => (int) $row['id'],
			'name'             => $row['name'],
			'phone'            => $row['phone'],
			'address'          => $row['address'],
			'lat'              => (float) $row['lat'],
			'lng'              => (float) $row['lng'],
			'google_maps_url'  => $maps,
			'created_at_utc'   => $row['created_at'],
			'email_sent_at_utc'=> gmdate( 'c' ),
		);

		$payload = apply_filters( 'estandar_dl_n8n_payload', $payload, 'estandar_dl.email_sent', (int) $row['id'] );

		self::post_json( $payload );

		do_action( 'estandar_dl_n8n_email_sent_sent', (int) $row['id'], $payload );
	}

	/**
	 * POST JSON no bloqueante.
	 *
	 * @param array $payload Cuerpo.
	 */
	private static function post_json( array $payload ) {
		$s  = Estandar_Delivery_Locator_Settings::get();
		$url = isset( $s['n8n_webhook_url'] ) ? trim( (string) $s['n8n_webhook_url'] ) : '';

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
		);

		$secret = isset( $s['n8n_webhook_secret'] ) ? trim( (string) $s['n8n_webhook_secret'] ) : '';
		if ( '' !== $secret ) {
			$headers['X-Estandar-DL-Secret'] = $secret;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'   => 8,
				'blocking'  => false,
				'headers'   => $headers,
				'body'      => wp_json_encode( $payload ),
				'sslverify' => true,
			)
		);
	}
}
