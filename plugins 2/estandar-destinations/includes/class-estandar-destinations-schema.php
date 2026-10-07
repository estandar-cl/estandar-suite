<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema bootstrap for estandar-destinations.
 *
 * Owns the DDL for adapter queues that will migrate from estandar-leads.
 * During the migration period, Leads still creates these tables via its own
 * schema class — dbDelta() is idempotent, so dual ownership is safe until
 * Phase 5 removes the Leads copies.
 *
 * Tables owned here:
 *   es_oci_queue          Google Ads OCI upload queue
 *   es_meta_queue         Meta Conversions API upload queue
 */
final class Estandar_Destinations_Schema {

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// ── Google Ads OCI queue ──────────────────────────────────────────────────
		$oci_queue = $wpdb->prefix . 'es_oci_queue';
		dbDelta( "CREATE TABLE $oci_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			conversion_status varchar(32) NOT NULL,
			gclid varchar(255) NOT NULL DEFAULT '',
			conversion_action varchar(255) NOT NULL DEFAULT '',
			conversion_time varchar(32) NOT NULL DEFAULT '',
			queue_status enum('pending','success','failed','dead') NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_attempted_at datetime DEFAULT NULL,
			last_error text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lead_conversion (lead_id, conversion_status),
			KEY queue_status (queue_status, attempts),
			KEY created_at (created_at)
		) $charset;" );

		// ── Meta Ads queue ────────────────────────────────────────────────────────
		$meta_queue = $wpdb->prefix . 'es_meta_queue';
		dbDelta( "CREATE TABLE $meta_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			export_status varchar(32) NOT NULL,
			event_time varchar(32) NOT NULL DEFAULT '',
			queue_status enum('pending','processing','completed','retry','dead') NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_attempted_at datetime DEFAULT NULL,
			last_error text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lead_export (lead_id, export_status),
			KEY queue_status (queue_status, attempts),
			KEY created_at (created_at)
		) $charset;" );
	}

	/**
	 * Returns true when both adapter queue tables exist.
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		$oci  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'es_oci_queue' ) );
		$meta = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'es_meta_queue' ) );
		return ( $oci !== null && $meta !== null );
	}
}
