<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Schema {
	const OPTION = 'estandar_quotes_schema_version';
	public static function activate(): void { self::create_tables(); update_option( self::OPTION, ESTANDAR_QUOTES_SCHEMA_VERSION, false ); }
	public static function maybe_migrate(): void {
		$current_version = get_option( self::OPTION );
		if ( $current_version !== ESTANDAR_QUOTES_SCHEMA_VERSION ) {
			self::create_tables();
			update_option( self::OPTION, ESTANDAR_QUOTES_SCHEMA_VERSION, false );
		}
		// Always ensure existing tables have all required columns.
		// dbDelta creates but does not ALTER existing tables.
		self::migrate_columns();
	}

	public static function next_quote_number(): string {
		global $wpdb;
		$max = (int) $wpdb->get_var( "SELECT MAX(CAST(quote_number AS UNSIGNED)) FROM " . self::quotes_table() );
		return sprintf( '%03d', $max + 1 );
	}

	/**
	 * Add missing columns to existing tables. Runs on every request but
	 * only executes ALTER TABLE when a column is actually missing.
	 */
	private static function migrate_columns(): void {
		global $wpdb;
		$tables = array(
			self::quotes_table() => array(
				'operator_id bigint(20) unsigned DEFAULT NULL',
				"quote_number varchar(16) NOT NULL DEFAULT ''",
			),
			self::operator_sessions_table() => array(),
			self::handoffs_table() => array(),
			self::revisions_table() => array(),
			self::events_table() => array(),
			self::artifacts_table() => array(),
			self::deliveries_table() => array(),
		);
		foreach ( $tables as $table => $cols ) {
			$existing = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
			if ( ! $existing ) { continue; }
			$col_names = array();
			foreach ( (array) $existing as $col ) {
				$col_names[] = $col->Field;
			}
			foreach ( $cols as $def ) {
				$col_name = explode( ' ', $def )[0];
				if ( ! in_array( $col_name, $col_names, true ) ) {
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$def}" );
				}
			}
		}
	}
	public static function quotes_table(): string { global $wpdb; return $wpdb->prefix . 'es_quotes'; }
	public static function revisions_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_revisions'; }
	public static function events_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_events'; }
	public static function artifacts_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_artifacts'; }
	public static function deliveries_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_deliveries'; }
	public static function handoffs_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_handoffs'; }
	public static function operator_sessions_table(): string { global $wpdb; return $wpdb->prefix . 'es_quote_operator_sessions'; }
	public static function create_tables(): void {
		global $wpdb; if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; } $charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::quotes_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, quote_uuid char(36) NOT NULL, project_slug varchar(191) NOT NULL, lead_id bigint(20) unsigned DEFAULT NULL, submission_id bigint(20) unsigned DEFAULT NULL, profile_key varchar(64) NOT NULL, profile_version varchar(32) NOT NULL, status varchar(32) NOT NULL DEFAULT 'draft', current_revision_no int(10) unsigned NOT NULL DEFAULT 0, lead_snapshot_json longtext NOT NULL, draft_document_json longtext DEFAULT NULL, created_by bigint(20) unsigned DEFAULT NULL, operator_id bigint(20) unsigned DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY quote_uuid (quote_uuid), KEY project_status (project_slug,status), KEY submission_id (submission_id), KEY lead_id (lead_id), KEY operator_id (operator_id) ) {$charset};" );

		dbDelta( "CREATE TABLE " . self::operator_sessions_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_token_hash char(64) NOT NULL, quote_id bigint(20) unsigned NOT NULL, operator_id bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, expires_at datetime NOT NULL, last_used_at datetime NOT NULL, revoked_at datetime DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY session_token_hash (session_token_hash), KEY quote_id (quote_id), KEY operator_id (operator_id) ) {$charset};" );
		dbDelta( "CREATE TABLE " . self::handoffs_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, token_hash char(64) NOT NULL, submission_id bigint(20) unsigned NOT NULL, operator_id bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, consumed_at datetime DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash), KEY submission_id (submission_id) ) {$charset};" );
		dbDelta( "CREATE TABLE " . self::revisions_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, quote_id bigint(20) unsigned NOT NULL, revision_no int(10) unsigned NOT NULL, revision_uuid char(36) NOT NULL, snapshot_json longtext NOT NULL, snapshot_hash char(64) NOT NULL, created_by bigint(20) unsigned DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY quote_revision (quote_id,revision_no), UNIQUE KEY revision_uuid (revision_uuid), KEY quote_id (quote_id) ) {$charset};" );
		dbDelta( "CREATE TABLE " . self::events_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, quote_id bigint(20) unsigned NOT NULL, revision_id bigint(20) unsigned DEFAULT NULL, event_type varchar(64) NOT NULL, actor_id bigint(20) unsigned DEFAULT NULL, metadata_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY quote_event (quote_id,event_type), KEY revision_id (revision_id) ) {$charset};" );
		dbDelta( "CREATE TABLE " . self::artifacts_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, artifact_uuid char(36) NOT NULL, quote_id bigint(20) unsigned NOT NULL, revision_id bigint(20) unsigned DEFAULT NULL, revision_uuid char(36) DEFAULT NULL, source_type varchar(16) NOT NULL, source_hash char(64) NOT NULL, renderer varchar(64) NOT NULL, renderer_version varchar(64) NOT NULL, template_version varchar(64) NOT NULL, theme_version varchar(64) NOT NULL, layout_version varchar(64) NOT NULL, generation_no int(10) unsigned NOT NULL DEFAULT 1, pdf_sha256 char(64) NOT NULL, byte_size bigint(20) unsigned NOT NULL, storage_key varchar(191) NOT NULL, status varchar(16) NOT NULL, generated_at datetime NOT NULL, created_by bigint(20) unsigned DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY artifact_uuid (artifact_uuid), UNIQUE KEY artifact_source (quote_id,revision_uuid,source_hash,renderer,template_version,generation_no), KEY revision_uuid (revision_uuid), KEY status (status) ) {$charset};" );
		dbDelta( "CREATE TABLE " . self::deliveries_table() . " (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, delivery_uuid char(36) NOT NULL, revision_uuid char(36) NOT NULL, artifact_uuid char(36) NOT NULL, generation_no int(10) unsigned NOT NULL, operator_id bigint(20) unsigned DEFAULT NULL, recipients_json longtext NOT NULL, subject varchar(255) NOT NULL, template_key varchar(64) NOT NULL, language varchar(12) NOT NULL, metadata_json longtext NOT NULL, status varchar(16) NOT NULL, attempts smallint unsigned NOT NULL DEFAULT 0, last_attempt_at datetime DEFAULT NULL, last_error varchar(255) NOT NULL DEFAULT '', idempotency_key char(64) NOT NULL, created_at datetime NOT NULL, sent_at datetime DEFAULT NULL, cancelled_at datetime DEFAULT NULL, updated_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY delivery_uuid(delivery_uuid), UNIQUE KEY idempotency_key(idempotency_key), KEY revision_uuid(revision_uuid), KEY status(status)) {$charset};" );
	}
}
