<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated REST for Core. Application-Passwords/standard WP auth via
 * permission_callback (is_user_logged_in + capability). No secret exposure.
 */
class Rest
{
	const NS = 'estandar-suite/core/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( Health::snapshot() );
			},
		) );

		register_rest_route( self::NS, '/telemetry', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'events' => Telemetry::recent( (int) $request->get_param( 'limit' ) ?: 50 ) ) );
			},
		) );

		register_rest_route( self::NS, '/secrets', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$payload  = sanitize_textarea_field( (string) $request->get_param( 'payload' ) );
				$purpose  = sanitize_key( (string) $request->get_param( 'purpose' ) );
				$created  = Secrets::create( $payload, $purpose );
				if ( empty( $created ) ) {
					return new \WP_Error( 'estandar_suite_secret_create_failed', 'No se pudo crear el secreto.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( $created );
			},
		) );

		// ── Integrations (OAuth + adapters) ───────────────────────────────

		register_rest_route( self::NS, '/oauth/begin', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
				$adapter  = sanitize_key( (string) $request->get_param( 'adapter' ) );
				if ( ! OAuth::def( $provider ) ) {
					return new \WP_Error( 'estandar_suite_oauth_provider', 'Proveedor inválido.', array( 'status' => 400 ) );
				}
				$app = OAuth::app_config( $provider );
				if ( empty( $app['client_id'] ) ) {
					return new \WP_Error( 'estandar_suite_oauth_unconfigured', "App {$provider} sin client_id.", array( 'status' => 400 ) );
				}
				$scopes = array();
				if ( $adapter !== '' && Adapters::get( $adapter ) ) {
					$scopes = Adapters::get( $adapter )->scopes();
				} else {
					$scopes = array_values( array_filter( preg_split( '/[\s,]+/', (string) $request->get_param( 'scopes' ) ) ) );
				}
				$redirect = (string) ( $request->get_param( 'redirect_uri' ) ?: ( $app['redirect_uri'] ?? OAuth::callback_url() ) );
				$label    = sanitize_text_field( (string) ( $request->get_param( 'label' ) ?: ( $provider === 'google' ? 'Google' : 'Microsoft' ) ) );
				$extra    = array();
				$tenant   = sanitize_text_field( (string) $request->get_param( 'tenant' ) );
				if ( $provider === 'microsoft' ) {
					$extra['tenant'] = $tenant !== '' ? $tenant : 'common';
				}
				$state = OAuth::begin_state( $provider, array(
					'user_id'      => get_current_user_id(),
					'label'        => $label,
					'scopes'       => $scopes,
					'tenant'       => $extra['tenant'] ?? '',
					'adapter'      => $adapter,
					'redirect_uri' => $redirect,
				) );
				if ( $state === '' ) {
					return new \WP_Error( 'estandar_suite_oauth_state', 'No se pudo iniciar el flujo OAuth.', array( 'status' => 500 ) );
				}
				$url = OAuth::authorize_url( $provider, array( 'client_id' => $app['client_id'], 'redirect_uri' => $redirect ), $state, $scopes, $extra );
				return rest_ensure_response( array( 'ok' => true, 'url' => $url, 'provider' => $provider ) );
			},
		) );

		register_rest_route( self::NS, '/oauth/callback', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return true; // public — protected by the signed state
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
				$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
				$meta  = OAuth::consume_state( $state );
				if ( ! $meta ) {
					return self::render_callback_page( false, 'El enlace de autorización expiró o no es válido. Vuelve a iniciar la conexión.' );
				}
				$provider = (string) ( $meta['provider'] ?? '' );
				if ( $code === '' ) {
					$err = sanitize_text_field( (string) $request->get_param( 'error' ) );
					return self::render_callback_page( false, 'Autorización denegada' . ( $err !== '' ? ': ' . $err : '.' ) );
				}
				$app   = OAuth::app_config( $provider );
				$extra = array();
				if ( ! empty( $meta['tenant'] ) ) {
					$extra['tenant'] = (string) $meta['tenant'];
				}
				$redirect = (string) ( $meta['redirect_uri'] ?? OAuth::callback_url() );
				$res = OAuth::exchange( $provider, $code, $redirect, $app, $extra );
				if ( empty( $res['ok'] ) || empty( $res['tokens'] ) ) {
					return self::render_callback_page( false, 'No se pudo intercambiar la autorización: ' . ( $res['error'] ?? 'error desconocido' ) );
				}
				$scopes = array();
				if ( ! empty( $meta['scopes'] ) && is_array( $meta['scopes'] ) ) {
					$scopes = $meta['scopes'];
				}
				$cfg = array();
				if ( ! empty( $meta['tenant'] ) ) {
					$cfg['tenant'] = (string) $meta['tenant'];
				}
				$id = Connections::register( $provider, (string) ( $meta['label'] ?? $provider ), $res['tokens'], $scopes, $cfg );
				if ( $id <= 0 ) {
					return self::render_callback_page( false, 'No se pudo guardar la conexión.' );
				}
				return self::render_callback_page( true, 'Conexión ' . esc_html( $provider ) . ' registrada (# ' . $id . '). Ya puedes crear destinos con esta cuenta.' );
			},
		) );

		register_rest_route( self::NS, '/integrations/catalog', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( array(
					'providers'   => OAuth::providers(),
					'adapters'    => Adapters::catalog(),
					'connections' => Connections::list(),
				) );
			},
		) );

		register_rest_route( self::NS, '/integrations/discover', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::discover( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/columns', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::columns( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/preview', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::preview( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/auto-map', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$fields  = (array) $request->get_param( 'fields' );
				$headers = (array) $request->get_param( 'headers' );
				return rest_ensure_response( array(
					'mapping' => FieldMap::auto_map( $fields, array_values( array_filter( array_map( 'strval', $headers ) ) ) ),
				) );
			},
		) );

		register_rest_route( self::NS, '/integrations/destinations', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::register_destination( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/destination', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::destination( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/bind', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( IntegrationsRest::bind( $request ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/connections/revoke', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$n = Connections::revoke( (int) $request->get_param( 'id' ) );
				return rest_ensure_response( array( 'ok' => $n > 0, 'revoked' => $n ) );
			},
		) );

		register_rest_route( self::NS, '/integrations/sandbox-connect', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( IntegrationsRest::sandbox_connect() );
			},
		) );
	}

	/** Minimal success/error page for the OAuth browser round-trip. */
	public static function render_callback_page( bool $ok, string $message ): \WP_REST_Response
	{
		$html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>Integraciones</title></head><body style="font-family:system-ui,sans-serif;background:#f6f7f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">'
			. '<div style="background:#fff;max-width:520px;width:90%;padding:32px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);text-align:center;">'
			. '<h2>' . ( $ok ? 'Conexión completa' : 'No se pudo conectar' ) . '</h2>'
			. '<p style="color:' . ( $ok ? '#007017' : '#b32d2e' ) . ';font-weight:600;">' . $message . '</p>'
			. '<p><a class="button" style="display:inline-block;background:#1d2327;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;" href="' . esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=integraciones' ) ) . '">Volver a Estandar Suite</a></p>'
			. '</div></body></html>';
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		echo $html;
		exit;
	}
}
