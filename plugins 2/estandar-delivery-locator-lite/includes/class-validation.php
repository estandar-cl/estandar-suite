<?php
/**
 * Validación y parseo de coordenadas y texto.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Validation {

	const MAX_ADDRESS_LENGTH = 2000;

	/**
	 * Parsea latitud o longitud desde POST (rechaza vacío, no numérico, infinito).
	 *
	 * @param mixed $raw Valor crudo.
	 * @param float $min Mínimo permitido.
	 * @param float $max Máximo permitido.
	 * @return float|null Float válido o null.
	 */
	public static function parse_coord( $raw, $min, $max ) {
		if ( null === $raw ) {
			return null;
		}
		if ( is_string( $raw ) && '' === trim( $raw ) ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		if ( is_float( $raw ) && ( is_nan( $raw ) || is_infinite( $raw ) ) ) {
			return null;
		}

		$f = filter_var( $raw, FILTER_VALIDATE_FLOAT );
		if ( false === $f || ! is_finite( $f ) ) {
			return null;
		}
		if ( $f < $min || $f > $max ) {
			return null;
		}
		return (float) $f;
	}

	/**
	 * Coordenadas sospechosas (0,0) típicas de error o manipulación.
	 *
	 * @param float $lat Latitud.
	 * @param float $lng Longitud.
	 * @return bool True si debe rechazarse.
	 */
	public static function is_null_island( $lat, $lng ) {
		return abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001;
	}
}
