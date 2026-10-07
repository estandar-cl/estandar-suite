<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outcomes
{
	const TABLE = 'estandar_flow_outcomes';

	const STAGES = array( 'NEW', 'CAPTURED', 'CONTACTED', 'ENGAGED', 'QUALIFIED', 'MEETING', 'PROPOSAL', 'NEGOTIATING', 'CLOSED_WON', 'LOST' );

	const REVENUE_SOURCES = array( 'deal_won', 'recurring', 'upsell', 'referral' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage varchar(32) NOT NULL DEFAULT 'NEW',
			revenue decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT 'CLP',
			revenue_source varchar(24) NOT NULL DEFAULT 'deal_won',
			closed_at datetime DEFAULT NULL,
			metadata_json longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY pipeline (pipeline_id),
			KEY stage (stage)
		) $charset;" );
		update_option( 'estandar_suite_outcomes_schema_version', '1.0.0', false );
	}

	public static function record_deal( int $pipeline_id, float $revenue, string $currency = 'CLP', string $source = 'deal_won' ): ?int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $pipeline_id <= 0 ) {
			return null;
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'pipeline_id'    => $pipeline_id,
			'stage'          => 'CLOSED_WON',
			'revenue'        => $revenue,
			'currency'       => $currency,
			'revenue_source' => $source,
			'closed_at'      => gmdate( 'Y-m-d H:i:s' ),
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%s', '%f', '%s', '%s', '%s', '%s' ) );

		$outcome_id = (int) $wpdb->insert_id;
		do_action( 'estandar_suite_outcome_recorded', $outcome_id, $pipeline_id, $revenue );
		return $outcome_id;
	}

	public static function total_revenue( string $from = '', string $to = '' ): float
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0.0;
		}
		$where = array( '1=1' );
		$args  = array();
		if ( $from ) { $where[] = 'closed_at >= %s'; $args[] = $from; }
		if ( $to )   { $where[] = 'closed_at <= %s'; $args[] = $to; }
		$sql = "SELECT COALESCE(SUM(revenue), 0) FROM {$wpdb->prefix}" . self::TABLE . " WHERE " . implode( ' AND ', $where );
		return (float) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	public static function conversion_rate( string $from = '', string $to = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'total' => 0, 'won' => 0, 'rate' => 0.0 );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . \Estandar\Flow\Pipeline::TABLE );
		$won   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . " WHERE stage = 'CLOSED_WON'" );
		return array(
			'total' => $total,
			'won'   => $won,
			'rate'  => $total > 0 ? round( $won / $total * 100, 1 ) : 0.0,
		);
	}

	public static function stage_duration_avg(): array
	{
		global $wpdb;
		$durations = array();
		if ( ! isset( $wpdb ) ) {
			return $durations;
		}
		$events = $wpdb->get_results(
			"SELECT pipeline_id, from_stage, to_stage, created_at FROM {$wpdb->prefix}estandar_flow_stage_events ORDER BY pipeline_id, created_at ASC"
		);
		if ( ! $events ) {
			return $durations;
		}
		$by_pipeline = array();
		foreach ( $events as $ev ) {
			$by_pipeline[ $ev->pipeline_id ][] = $ev;
		}
		foreach ( $by_pipeline as $evs ) {
			for ( $i = 1; $i < count( $evs ); $i++ ) {
				$stage = $evs[ $i - 1 ]->to_stage;
				$duration = strtotime( $evs[ $i ]->created_at ) - strtotime( $evs[ $i - 1 ]->created_at );
				if ( ! isset( $durations[ $stage ] ) ) {
					$durations[ $stage ] = array( 'total' => 0, 'count' => 0 );
				}
				$durations[ $stage ]['total'] += $duration;
				$durations[ $stage ]['count']++;
			}
		}
		$result = array();
		foreach ( $durations as $stage => $d ) {
			$result[ $stage ] = round( $d['total'] / $d['count'] / 86400, 1 );
		}
		return $result;
	}
}
