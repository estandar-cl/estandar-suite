<?php
/**
 * Límite de peticiones por IP (ventanas fijas con transients).
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Rate_Limit {

	const ACTION_CONFIRM = 'confirm';
	const ACTION_EMAIL   = 'email';

	/**
	 * Permite la acción si no se supera el máximo en la ventana temporal.
	 *
	 * @param string $action Acción interna (confirm|email).
	 * @param int    $max    Máximo de intentos por ventana.
	 * @param int    $window Segundos de la ventana (p. ej. 3600).
	 * @return bool True si se permite.
	 */
	public static function allow( $action, $max, $window ) {
		$ip = self::get_client_ip();
		$now = time();
		$bucket = (int) floor( $now / max( 1, (int) $window ) );
		$key    = 'estandar_dl_rl_' . sanitize_key( $action ) . '_' . md5( $ip ) . '_' . $bucket;

		$count = (int) get_transient( $key );
		if ( $count >= (int) $max ) {
			return false;
		}

		$count++;
		set_transient( $key, $count, (int) $window * 2 );

		return true;
	}

	/**
	 * IP remota (solo REMOTE_ADDR para evitar suplantación vía cabeceras).
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '0.0.0.0';
	}
}
