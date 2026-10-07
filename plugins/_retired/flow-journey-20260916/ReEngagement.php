<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReEngagement
{
	const OPTION = 'estandar_suite_reengagement_config';

	const BEHAVIOR_SIGNALS = array(
		'visited_pricing'       => 20,
		'submitted_form'        => 40,
		'chatbot_interaction'   => 25,
		'visited_booking'       => 30,
		'opened_email'          => 15,
		'clicked_cta'           => 35,
		'time_on_site_2min'     => 10,
		'visited_3plus_pages'   => 15,
		'returned_from_ads'     => 30,
	);

	const DEFAULT_THRESHOLD = 40;

	public static function detect( int $pipeline_id, array $signals ): bool
	{
		$config = get_option( self::OPTION, array() );
		$config = is_array( $config ) ? $config : array();
		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		$pipeline_row = \Estandar\Flow\Pipeline::get( $pipeline_id );
		if ( ! $pipeline_row ) {
			return false;
		}

		$cooldown_hours = (int) ( $config['cooldown_hours'] ?? 24 );
		$last_reengagement = $pipeline_row['metadata_json']
			? json_decode( $pipeline_row['metadata_json'], true )['last_reengagement_at'] ?? null
			: null;
		if ( $last_reengagement && strtotime( $last_reengagement ) > time() - $cooldown_hours * 3600 ) {
			return false;
		}

		$score = 0;
		foreach ( $signals as $signal ) {
			$score += self::BEHAVIOR_SIGNALS[ $signal ] ?? 0;
		}

		$threshold = (int) ( $config['behavior_threshold'] ?? self::DEFAULT_THRESHOLD );
		return $score >= $threshold;
	}

	public static function trigger( int $pipeline_id, array $signals ): ?array
	{
		if ( ! self::detect( $pipeline_id, $signals ) ) {
			return null;
		}

		$config = get_option( self::OPTION, array() );
		$config = is_array( $config ) ? $config : array();
		$actions = (array) ( $config['default_actions'] ?? array( 'increase_priority', 'notify_operator' ) );

		$result = array(
			'pipeline_id' => $pipeline_id,
			'signals'     => $signals,
			'actions'     => $actions,
			'triggered_at'=> gmdate( 'Y-m-d H:i:s' ),
		);

		foreach ( $actions as $action ) {
			self::execute_action( $action, $pipeline_id );
		}

		\Estandar\Flow\Pipeline::journey_transition( $pipeline_id, 'RECOVERY', 'Re-engagement detectado' );

		do_action( 'estandar_suite_reengagement_triggered', $pipeline_id, $signals, $actions );

		return $result;
	}

	private static function execute_action( string $action, int $pipeline_id ): void
	{
		switch ( $action ) {
			case 'increase_priority':
				\Estandar\Flow\Pipeline::set_priority( $pipeline_id, 'HIGH' );
				break;
			case 'notify_operator':
				do_action( 'estandar_suite_reengagement_notify_operator', $pipeline_id );
				break;
			case 'auto_email_client':
				do_action( 'estandar_suite_reengagement_email_client', $pipeline_id );
				break;
		}
	}

	public static function log( int $pipeline_id, array $data ): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . 'estandar_flow_reengagement_log';
		$wpdb->insert( $table, array(
			'pipeline_id'   => $pipeline_id,
			'signals_json'  => wp_json_encode( $data['signals'] ?? array() ),
			'actions_json'  => wp_json_encode( $data['actions'] ?? array() ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%s', '%s', '%s' ) );
	}
}
