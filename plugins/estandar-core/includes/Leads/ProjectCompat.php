<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ProjectCompat — funciones globales `estandar_core_*` de primer (port v2).
 *
 * estandar-primer define funciones globales `estandar_core_get_client_slug`,
 * `estandar_core_get_project_streets`, `estandar_core_get_projects_for_select`
 * y `estandar_core_geo_ip_lookup` que el v2 usa (con guards function_exists).
 * Para que la cadena no dependa de primer al retirarlo, estas definiciones
 * quedan acá (misma firma, misma fuente: la tabla `estandar_project_registry`).
 * Prioridad: si primer aún está presente, su definición gana (guard).
 *
 * SIN namespace a propósito: son funciones del espacio GLOBAL (los
 * consumidores las llaman sin namespace, igual que primer).
 */

if ( ! function_exists( 'estandar_core_get_client_slug' ) ) {
	function estandar_core_get_client_slug( string $fallback = '' ): string {
		$value = (string) get_option( 'estandar_client_slug', '' );
		if ( $value !== '' ) {
			return trim( $value, '-_ ' );
		}
		return trim( (string) $fallback, '-_ ' );
	}
}

if ( ! function_exists( 'estandar_core_get_project_streets' ) ) {
	function estandar_core_get_project_streets( string $project_id ): array {
		global $wpdb;
		$pid = sanitize_key( $project_id );
		if ( $pid === '' ) {
			return array();
		}
		$table = $wpdb->prefix . 'estandar_project_registry';
		$row   = $wpdb->get_var( $wpdb->prepare(
			"SELECT streets_json FROM {$table} WHERE project_id = %s LIMIT 1", $pid
		) );
		$data  = is_string( $row ) ? json_decode( $row, true ) : null;
		return is_array( $data ) ? $data : array();
	}
}

if ( ! function_exists( 'estandar_core_get_project_url_patterns' ) ) {
	function estandar_core_get_project_url_patterns( string $project_id ): array {
		global $wpdb;
		$pid = sanitize_key( $project_id );
		if ( $pid === '' ) {
			return array();
		}
		$table = $wpdb->prefix . 'estandar_project_registry';
		$row   = $wpdb->get_var( $wpdb->prepare(
			"SELECT url_patterns FROM {$table} WHERE project_id = %s LIMIT 1", $pid
		) );
		$data  = is_string( $row ) ? json_decode( $row, true ) : null;
		return is_array( $data ) ? $data : array();
	}
}

if ( ! function_exists( 'estandar_core_get_projects_for_select' ) ) {
	function estandar_core_get_projects_for_select(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'estandar_project_registry';
		$rows  = $wpdb->get_results(
			"SELECT project_id, label FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'value' => (string) ( $r['project_id'] ?? '' ),
				'label' => (string) ( $r['label'] ?? '' ),
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'estandar_core_geo_ip_lookup' ) ) {
	function estandar_core_geo_ip_lookup( string $ip ): array {
		if ( function_exists( 'estandar_chatbot_get_ip_geolocation' ) ) {
			return (array) estandar_chatbot_get_ip_geolocation( $ip );
		}

		$ip = trim( $ip );
		if ( $ip === '' || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return array();
		}

		$cache_key = 'estandar_core_geo_ip_v2_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city,lat,lon',
			array( 'timeout' => 2, 'redirection' => 1 )
		);
		$body = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'success' || ! isset( $body['lat'], $body['lon'] ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$data = array(
			'ip'      => $ip,
			'city'    => sanitize_text_field( (string) ( $body['city'] ?? '' ) ),
			'region'  => sanitize_text_field( (string) ( $body['regionName'] ?? '' ) ),
			'country' => sanitize_text_field( (string) ( $body['country'] ?? '' ) ),
			'lat'     => round( (float) $body['lat'], 4 ),
			'lng'     => round( (float) $body['lon'], 4 ),
		);
		set_transient( $cache_key, $data, 14 * DAY_IN_SECONDS );
		return $data;
	}
}
