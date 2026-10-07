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

		\Estandar\Core\Sandbox::activate_schema();

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

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_secrets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			secret_hash char(64) NOT NULL,
			purpose varchar(64) NOT NULL DEFAULT '',
			payload_ciphertext text,
			expires_at datetime NOT NULL,
			used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY secret_hash (secret_hash),
			KEY expires_at (expires_at),
			KEY used_at (used_at)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_destinations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			type varchar(24) NOT NULL DEFAULT 'webhook',
			target_url text,
			headers_json text,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			config_json longtext,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY connection_id (connection_id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_connections (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(40) NOT NULL DEFAULT '',
			label varchar(190) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'active',
			tokens_encrypted longtext,
			scopes_json text,
			provider_config_json text,
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY provider (provider),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_integrations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_source varchar(24) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			destination_id bigint(20) unsigned NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			mode varchar(20) NOT NULL DEFAULT 'parallel',
			priority int(11) NOT NULL DEFAULT 10,
			condition_json text,
			error_policy varchar(20) NOT NULL DEFAULT 'retry',
			mapping_json longtext,
			last_ok_at datetime DEFAULT NULL,
			last_error text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_form_dest (form_source, form_id, destination_id),
			KEY destination (destination_id),
			KEY enabled (enabled)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_integration_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'success',
			action varchar(24) NOT NULL DEFAULT 'write',
			response_summary varchar(500) NOT NULL DEFAULT '',
			error text,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY integration_id (integration_id),
			KEY entry_id (entry_id),
			KEY created_at (created_at)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_sandbox_files (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_key varchar(80) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			sheet_title varchar(190) NOT NULL DEFAULT 'Datos',
			columns_json longtext,
			rows_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_key (file_key)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_core_deliveries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			destination_id bigint(20) unsigned NOT NULL DEFAULT 0,
			integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(64) NOT NULL DEFAULT '',
			entity_type varchar(32) NOT NULL DEFAULT '',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			idempotency_key char(64) NOT NULL DEFAULT '',
			payload_json longtext,
			status varchar(24) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text,
			next_attempt_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_delivery (destination_id, idempotency_key),
			KEY status (status),
			KEY integration (integration_id, status),
			KEY destination (destination_id, status)
		) $charset;" );
	}

	/** Protected list — core never writes to any of these (safety gate). */
	public static function protected_legacy_prefixes(): array
	{
		return array( 'es_', 'ea_', 'ecb_', 'estandar_leads_' );
	}
}
