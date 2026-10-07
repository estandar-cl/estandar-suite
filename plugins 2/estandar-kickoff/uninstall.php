<?php
/**
 * Uninstall cleanup for Estandar KickOff.
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Progressive reveal options
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'estandar_pr_' ) . '%'
	)
);

// Disable Comments module option (module removed in v1.1.0)
delete_option( 'estandar_kickoff_disable_comments' );

// Remove stale module keys from the shared modules-enabled option
$modules_option = get_option( 'estandar_kickoff_modules_enabled', [] );
if ( is_array( $modules_option ) ) {
	$removed_slugs = [ 'disable-comments', 'database-cleanup', 'regenerate-thumbnails' ];
	foreach ( $removed_slugs as $slug ) {
		unset( $modules_option[ $slug ] );
	}
	update_option( 'estandar_kickoff_modules_enabled', $modules_option );
}
