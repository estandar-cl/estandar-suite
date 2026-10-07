<?php
/**
 * Cálculo de distancia Haversine (servidor).
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Geo {

	/**
	 * Distancia en kilómetros entre dos puntos WGS84.
	 *
	 * @param float $lat1 Latitud 1.
	 * @param float $lng1 Longitud 1.
	 * @param float $lat2 Latitud 2.
	 * @param float $lng2 Longitud 2.
	 * @return float
	 */
	public static function distance_km( $lat1, $lng1, $lat2, $lng2 ) {
		$earth = 6371.0;
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
			* sin( $d_lng / 2 ) * sin( $d_lng / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth * $c;
	}

	/**
	 * Comprueba si un punto está dentro del radio desde el centro.
	 *
	 * @param float $lat Latitud punto.
	 * @param float $lng Longitud punto.
	 * @param float $center_lat Centro.
	 * @param float $center_lng Centro.
	 * @param float $radius_km Radio km.
	 * @return bool
	 */
	public static function is_inside_zone( $lat, $lng, $center_lat, $center_lng, $radius_km ) {
		return self::distance_km( $lat, $lng, $center_lat, $center_lng ) <= (float) $radius_km;
	}
}
