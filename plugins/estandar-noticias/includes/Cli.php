<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli {

	public static function init(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		\WP_CLI::add_command( 'noticias', array( __CLASS__, 'command' ) );
	}

	public static function command( array $args, array $assoc ): void {
		$sub = (string) ( $args[0] ?? 'run' );
		$ed  = (string) ( $args[1] ?? ( $assoc['edicion'] ?? '' ) );

		switch ( $sub ) {
			case 'run':
				$res = Pipeline::run( $ed, ! empty( $assoc['force'] ) );
				\WP_CLI::log( wp_json_encode( $res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				\WP_CLI::success( 'Pipeline ejecutado.' );
				break;

			case 'status':
				$s    = Settings::all();
				$line = array(
					'activo'   => (int) $s['general']['activo'],
					'hora'     => $s['general']['hora'],
					'salida'   => $s['general']['salida'],
					'temas'    => count( (array) $s['temas'] ),
					'ediciones' => array_keys( (array) $s['ediciones'] ),
					'ai'       => Curate::provider() . ( Curate::key() !== '' ? ' (key OK)' : ' (sin key)' ),
					'proximo'  => wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( Plugin::CRON_HOOK ) ),
				);
				\WP_CLI::log( wp_json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				break;

			case 'candidatos':
				$res = Pipeline::run( $ed );
				\WP_CLI::log( wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
				break;

			default:
				\WP_CLI::error( 'uso: wp noticias run [edicion] | status' );
		}
	}
}
