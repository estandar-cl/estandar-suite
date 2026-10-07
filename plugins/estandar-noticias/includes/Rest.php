<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NS = 'estandar-noticias/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'args'                => array(
					'edicion' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
	}

	public static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function run( $req ) {
		$ed = sanitize_key( (string) $req->get_param( 'edicion' ) );
		return rest_ensure_response( Pipeline::run( $ed, (bool) $req->get_param( 'force' ) ) );
	}

	public static function status() {
		$s = Settings::all();
		return rest_ensure_response(
			array(
				'activo'    => (int) $s['general']['activo'],
				'hora'      => (string) $s['general']['hora'],
				'salida'    => (string) $s['general']['salida'],
				'temas'     => count( (array) $s['temas'] ),
				'ediciones' => array_keys( (array) $s['ediciones'] ),
				'ai_key'    => Curate::key() !== '',
				'proximo'   => (int) wp_next_scheduled( Plugin::CRON_HOOK ),
			)
		);
	}
}
