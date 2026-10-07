<?php

namespace Estandar\Core\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeliveryLog — registro técnico de entregas de correo de la Suite.
 *
 * Registra transporte usado, ok/error, destinatarios y asunto, SIN secretos ni
 * contenido sensible. Persistencia: telemetría de Core (si existe) + resumen
 * en una option rotativa (últimas 50 entregas).
 */
class DeliveryLog
{
	const OPTION = 'estandar_suite_core_mail_delivery_log';
	const MAX = 50;

	/** Registra un resultado de envío. */
	public static function record( array $result ): void
	{
		$entry = array(
			'ts'        => gmdate( 'Y-m-d H:i:s' ),
			'transport' => (string) ( $result['transport'] ?? '' ),
			'ok'        => ! empty( $result['ok'] ),
			'to'        => is_array( $result['to'] ?? null ) ? array_values( array_slice( $result['to'], 0, 3 ) ) : array( (string) ( $result['to'] ?? '' ) ),
			'subject'   => mb_substr( (string) ( $result['subject'] ?? '' ), 0, 60 ),
			'error'     => mb_substr( (string) ( $result['error'] ?? '' ), 0, 120 ),
		);
		$log = (array) get_option( self::OPTION, array() );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log, false );

		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit(
				$entry['ok'] ? 'info' : 'error',
				'SUITE_MAIL',
				$entry['ok'] ? 'Email enviado (transport=' . $entry['transport'] . ').' : 'Email fallido (transport=' . $entry['transport'] . ').',
				array(
					'source_plugin' => 'estandar-core',
					'transport' => $entry['transport'],
					'ok'        => $entry['ok'],
					'error'     => $entry['error'],
				)
			);
		}
	}

	/** Últimas entregas (sin secretos). */
	public static function recent( int $limit = 20 ): array
	{
		$log = (array) get_option( self::OPTION, array() );
		return array_slice( $log, 0, max( 1, min( $limit, self::MAX ) ) );
	}
}
