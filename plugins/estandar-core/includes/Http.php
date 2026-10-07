<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP client for outbound adapter calls. Normalizes wp_remote_request
 * into {ok, http, body, error} so every adapter shares one error shape and no
 * adapter reaches into WP internals. Provider-agnostic.
 */
class Http
{
	public static function request( string $method, string $url, string $access_token = '', array $args = array() ): array
	{
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => 'entorno sin HTTP' );
		}
		$headers = array( 'Accept' => 'application/json' );
		if ( $access_token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}
		$body = null;
		if ( array_key_exists( 'json', $args ) && is_array( $args['json'] ) ) {
			$headers['Content-Type'] = 'application/json';
			$body = wp_json_encode( $args['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( array_key_exists( 'form', $args ) && is_array( $args['form'] ) ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body = http_build_query( $args['form'] );
		} elseif ( isset( $args['body'] ) ) {
			$body = (string) $args['body'];
		}
		$req = array(
			'method'      => strtoupper( $method ),
			'timeout'     => (int) ( $args['timeout'] ?? 30 ),
			'redirection' => 0,
			'headers'     => array_merge( $headers, isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array() ),
		);
		if ( $body !== null ) {
			$req['body'] = $body;
		}
		$resp = wp_remote_request( $url, $req );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = (string) wp_remote_retrieve_body( $resp );
		$dec  = json_decode( $raw, true );
		$data = is_array( $dec ) ? $dec : $raw;
		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'http' => $code, 'body' => $data, 'error' => '' );
		}
		$err = (string) $raw;
		if ( is_array( $dec ) ) {
			$err = (string) ( $dec['error']['message'] ?? $dec['error'] ?? $dec['error_description'] ?? $raw );
		}
		return array( 'ok' => false, 'http' => $code, 'body' => $data, 'error' => $err );
	}

	public static function get( string $url, string $access_token = '', array $args = array() ): array
	{
		return self::request( 'GET', $url, $access_token, $args );
	}

	public static function post( string $url, string $access_token = '', array $args = array() ): array
	{
		return self::request( 'POST', $url, $access_token, $args );
	}
}
