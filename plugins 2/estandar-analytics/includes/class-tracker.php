<?php
/**
 * Frontend tracker enqueue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Tracker {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function enqueue() {
		if ( is_admin() || ! EA_Privacy::should_track() ) {
			return;
		}

		$settings = EA_Settings::get();
		if ( empty( $settings['enable_tracking'] ) ) {
			return;
		}

		wp_enqueue_script(
			'ea-tracker',
			EA_URL . 'assets/tracker.js',
			array(),
			EA_VERSION,
			true
		);

		wp_script_add_data( 'ea-tracker', 'strategy', 'defer' );

		wp_localize_script(
			'ea-tracker',
			'eaTrackerConfig',
			EA_Settings::get_tracker_config()
		);
	}
}
