<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema for estandar-core. Own tables with the product prefix only.
 * NEVER touches the legacy es_ / ea_ / ecb_ / estandar_leads_ tables.
 */
class Schema
{
	const VERSION = '1.3.0';
	const OPTION  = 'estandar_suite_core_schema_version';

	public static function activate(): void
	{
		self::create_tables();
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::create_tables();
			update_option( self::OPTION, self::VERSION, false );
		}
	}

	public static function create_tables(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_telemetry (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			severity varchar(16) NOT NULL DEFAULT 'info',
			code varchar(80) NOT NULL DEFAULT '',
			message text,
			context_json longtext,
			source_plugin varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY code (code),
			KEY severity (severity),
			KEY created_at (created_at)
		) $charset;" );

	}

	/** Protected list — core never writes to any of these (safety gate). */
	public static function protected_legacy_prefixes(): array
	{
		return array( 'es_', 'ea_', 'ecb_', 'estandar_leads_' );
	}
}
