<?php
/**
 * Retention cleanup cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Retention {

	public static function init() {
		add_action( 'ea_daily_retention', array( __CLASS__, 'run' ) );
	}

	public static function run() {
		global $wpdb;

		$settings = EA_Settings::get();
		$days     = max( 30, (int) ( $settings['retention_days'] ?? 90 ) );
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

		do_action( 'ea_retention_cleanup_before', $cutoff, $days );

		$tables_raw = array( 'pageviews', 'events', 'conversions', 'referrers', 'devices' );
		foreach ( $tables_raw as $short ) {
			$table = EA_Utils::table( $short );
			if ( $short === 'devices' || $short === 'referrers' ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE d FROM {$table} d INNER JOIN " . EA_Utils::table( 'sessions' ) . " s ON d.session_id = s.session_id WHERE s.first_seen < %s",
						$cutoff
					)
				);
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"DELETE t FROM {$table} t INNER JOIN " . EA_Utils::table( 'sessions' ) . " s ON t.session_id = s.session_id WHERE s.first_seen < %s",
					$cutoff
				)
			);
		}

		$sessions = EA_Utils::table( 'sessions' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sessions} WHERE first_seen < %s", $cutoff ) );

		do_action( 'ea_retention_cleanup_after', $cutoff, $days );
	}
}
