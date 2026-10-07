<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recovery
{
	const OPTION = 'estandar_suite_recovery_config';

	public static function schedule_from_lost( int $pipeline_id ): void
	{
		$config = get_option( self::OPTION, array() );
		$config = is_array( $config ) ? $config : array();
		if ( empty( $config['from_lost']['enabled'] ) ) {
			return;
		}
		$attempts = $config['from_lost']['attempts'] ?? array();
		foreach ( $attempts as $attempt ) {
			$days = (int) ( $attempt['days_after_lost'] ?? 30 );
			$scheduled = time() + $days * 86400;
			wp_schedule_single_event( $scheduled, 'estandar_suite_recovery_attempt', array( $pipeline_id, $attempt ) );
		}
	}

	public static function initiate( int $pipeline_id, string $source = 'lost' ): ?array
	{
		$pipeline_row = \Estandar\Flow\Pipeline::get( $pipeline_id );
		if ( ! $pipeline_row ) {
			return null;
		}

		$valid_sources = array( 'lost', 'churning', 'manual', 'reengagement' );
		if ( ! in_array( $source, $valid_sources, true ) ) {
			return null;
		}

		$transition = \Estandar\Flow\Pipeline::journey_transition( $pipeline_id, 'RECOVERY', "Recovery iniciado desde {$source}" );
		if ( ! $transition ) {
			return null;
		}

		do_action( 'estandar_suite_recovery_initiated', $pipeline_id, $source );

		return array(
			'pipeline_id'    => $pipeline_id,
			'source'         => $source,
			'journey_state'  => 'RECOVERY',
			'from'           => $transition['from'],
		);
	}

	public static function attempt( int $pipeline_id, array $attempt_config ): array
	{
		$channel  = $attempt_config['channel'] ?? 'email';
		$template = $attempt_config['template'] ?? '';
		$is_final = ! empty( $attempt_config['final'] );

		$result = array(
			'pipeline_id' => $pipeline_id,
			'channel'     => $channel,
			'template'    => $template,
			'sent_at'     => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $channel === 'email' && $template ) {
			do_action( 'estandar_suite_recovery_email', $pipeline_id, $template );
		}

		do_action( 'estandar_suite_recovery_contacted', $pipeline_id, $channel );

		if ( $is_final ) {
			self::fail( $pipeline_id );
		}

		return $result;
	}

	public static function mark_reengaged( int $pipeline_id ): ?array
	{
		return \Estandar\Flow\Pipeline::journey_transition( $pipeline_id, 'ENGAGED', 'Recovery exitoso - prospecto reconectó' );
	}

	public static function fail( int $pipeline_id ): ?array
	{
		$row = \Estandar\Flow\Pipeline::get( $pipeline_id );
		if ( ! $row ) {
			return null;
		}
		$return_state = ( $row['journey_state'] === 'RECOVERY' && $row['journey_state'] !== 'CHURNING' ) ? 'LOST' : 'CHURNED';
		return \Estandar\Flow\Pipeline::journey_transition( $pipeline_id, $return_state, 'Recovery fallido' );
	}
}
