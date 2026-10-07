<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Signal_Core_Schema {

	const OPTION = 'estandar_signal_core_schema_version';

	// B6 — batched/resumable advertising-hash repair.
	const HASH_REPAIR_HOOK        = 'estandar_signal_core_hash_repair_continue';
	const HASH_REPAIR_DONE        = 'estandar_signal_core_hash_repair_done';
	const HASH_REPAIR_CURSOR      = 'estandar_signal_core_hash_repair_cursor';
	const HASH_REPAIR_BATCH       = 500;
	const HASH_REPAIR_MAX_PER_RUN = 20000;
	const HASH_REPAIR_MAX_RETRIES = 3;
	const HASH_REPAIR_RETRY_KEY  = 'estandar_signal_core_hash_repair_attempts';

	public static function activate(): void {
		self::create_tables();
		self::schedule();
		Estandar_Signal_Core_Repository::bootstrap_client_id();
		update_option( self::OPTION, ESTANDAR_SIGNAL_CORE_SCHEMA_VERSION, false );
	}

	public static function maybe_migrate(): void {
		if ( get_option( self::OPTION ) !== ESTANDAR_SIGNAL_CORE_SCHEMA_VERSION ) {
			self::create_tables();
			Estandar_Signal_Core_Repository::bootstrap_client_id();
			self::backfill_basic();              // Crea signals faltantes (idempotente).
			self::repair_advertising_hashes();   // Corrige HMAC→SHA256 en signals existentes.
			update_option( self::OPTION, ESTANDAR_SIGNAL_CORE_SCHEMA_VERSION, false );
		}
		self::schedule();
	}

	/**
	 * Repair pass: recalculate email_hash / phone_hash for all signal events
	 * using SHA-256(normalized_value) instead of the HMAC previously copied from es_leads.
	 *
	 * Safe to run multiple times (idempotent UPDATE).
	 * Only touches rows where the corresponding lead still exists.
	 *
	 * Called on every schema version upgrade so historical signals are corrected
	 * before any exporter is activated.
	 */
	public static function repair_advertising_hashes(): void {
		global $wpdb;

		// Already completed — cheap no-op on every subsequent upgrade.
		if ( '1' === (string) get_option( self::HASH_REPAIR_DONE ) ) {
			return;
		}

		// Retry gate: prevent infinite re-execution on persistent failure.
		$attempts = (int) get_option( self::HASH_REPAIR_RETRY_KEY, 0 );
		if ( $attempts >= self::HASH_REPAIR_MAX_RETRIES ) {
			// Mark done so subsequent upgrades skip the broken repair.
			update_option( self::HASH_REPAIR_DONE, '1', false );
			return;
		}
		update_option( self::HASH_REPAIR_RETRY_KEY, $attempts + 1, false );

		$events_table = self::events_table();
		$leads_table  = $wpdb->prefix . 'es_leads';
		$cursor       = (int) get_option( self::HASH_REPAIR_CURSOR, 0 );
		$now          = gmdate( 'Y-m-d H:i:s' );
		$processed    = 0;

		// Batched, resumable, JOIN-based (no N+1). Bounded memory (one batch in flight)
		// and bounded work per invocation; continues on a scheduled tick until done.
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.id AS event_id, l.email_normalized, l.phone_normalized
					 FROM {$events_table} e
					 INNER JOIN {$leads_table} l ON l.id = e.lead_id
					 WHERE e.lead_id > 0 AND e.id > %d
					 ORDER BY e.id ASC
					 LIMIT %d",
					$cursor,
					self::HASH_REPAIR_BATCH
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				self::finish_hash_repair();
				return;
			}

			foreach ( $rows as $row ) {
				$wpdb->update(
					$events_table,
					array(
						'email_hash' => Estandar_Signal_Core_Repository::advertising_hash( (string) ( $row['email_normalized'] ?? '' ) ),
						'phone_hash' => Estandar_Signal_Core_Repository::advertising_hash( (string) ( $row['phone_normalized'] ?? '' ) ),
						'updated_at' => $now,
					),
					array( 'id' => absint( $row['event_id'] ) )
				);
				$cursor = (int) $row['event_id'];
				$processed++;
			}

			update_option( self::HASH_REPAIR_CURSOR, $cursor, false );

			if ( count( $rows ) < self::HASH_REPAIR_BATCH ) {
				// Partial batch = no more rows above the cursor — fully repaired.
				self::finish_hash_repair();
				return;
			}
		} while ( $processed < self::HASH_REPAIR_MAX_PER_RUN );

		// Hit the per-invocation cap with rows still remaining — resume on a scheduled tick.
		if ( ! wp_next_scheduled( self::HASH_REPAIR_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HASH_REPAIR_HOOK );
		}
	}

	private static function finish_hash_repair(): void {
		update_option( self::HASH_REPAIR_DONE, '1', false );
		delete_option( self::HASH_REPAIR_CURSOR );
		delete_option( self::HASH_REPAIR_RETRY_KEY );
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$clients = self::clients_table();
		dbDelta(
			"CREATE TABLE {$clients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(120) NOT NULL DEFAULT '',
				client_name varchar(190) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id),
				KEY status (status)
			) {$charset};"
		);

		$events = self::events_table();
		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				signal_uuid char(36) NOT NULL DEFAULT '',
				client_id varchar(120) NOT NULL DEFAULT '',
				project_slug varchar(190) NOT NULL DEFAULT '',
				lead_id bigint(20) unsigned DEFAULT NULL,
				submission_id bigint(20) unsigned DEFAULT NULL,
				source_event_id bigint(20) unsigned DEFAULT NULL,
				source_plugin varchar(64) NOT NULL DEFAULT '',
				event_name varchar(80) NOT NULL DEFAULT '',
				event_stage varchar(80) NOT NULL DEFAULT '',
				lifecycle_key varchar(160) NOT NULL DEFAULT '',
				idempotency_key char(64) NOT NULL DEFAULT '',
				occurrence_index int(10) unsigned NOT NULL DEFAULT 1,
				audit_only tinyint(1) NOT NULL DEFAULT 1,
				export_status varchar(32) NOT NULL DEFAULT 'audit_only',
				event_time datetime NOT NULL,
				value_decimal decimal(18,4) DEFAULT NULL,
				currency varchar(8) NOT NULL DEFAULT '',
				quality varchar(32) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT '',
				email_hash char(64) NOT NULL DEFAULT '',
				phone_hash char(64) NOT NULL DEFAULT '',
				session_id varchar(190) NOT NULL DEFAULT '',
				gclid varchar(255) NOT NULL DEFAULT '',
				gbraid varchar(255) NOT NULL DEFAULT '',
				wbraid varchar(255) NOT NULL DEFAULT '',
				fbclid varchar(255) NOT NULL DEFAULT '',
				meta_fbc varchar(255) NOT NULL DEFAULT '',
				meta_fbp varchar(255) NOT NULL DEFAULT '',
				ttclid varchar(255) NOT NULL DEFAULT '',
				msclkid varchar(255) NOT NULL DEFAULT '',
				identity_json longtext,
				payload_json longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY signal_uuid (signal_uuid),
				KEY client_project (client_id, project_slug),
				KEY lead_event (lead_id, event_name),
				KEY event_time (event_time),
				KEY export_status (export_status),
				KEY audit_only (audit_only)
			) {$charset};"
		);

		$queue = self::queue_table();
		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				signal_id bigint(20) unsigned NOT NULL,
				queue_status varchar(32) NOT NULL DEFAULT 'audit_only',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_run_at datetime DEFAULT NULL,
				last_error text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY signal_id (signal_id),
				KEY queue_status (queue_status),
				KEY next_run_at (next_run_at)
			) {$charset};"
		);
	}

	public static function backfill_basic(): void {
		global $wpdb;

		$leads = $wpdb->prefix . 'es_leads';
		if ( ! self::table_exists( $leads ) ) {
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT id, created_at, updated_at, status
			 FROM {$leads}
			 ORDER BY id DESC
			 LIMIT 200",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$lead_id = absint( $row['id'] ?? 0 );
			if ( $lead_id <= 0 ) {
				continue;
			}
			Estandar_Signal_Core_Repository::record_from_lead(
				$lead_id,
				'lead_created',
				array(
					'event_time' => (string) ( $row['created_at'] ?? '' ),
					'source'     => 'backfill_basic',
				)
			);
			$event = Estandar_Signal_Core_Hooks::event_for_status( (string) ( $row['status'] ?? '' ) );
			if ( $event !== '' ) {
				Estandar_Signal_Core_Repository::record_from_lead(
					$lead_id,
					$event,
					array(
						'event_time' => (string) ( $row['updated_at'] ?? ( $row['created_at'] ?? '' ) ),
						'source'     => 'backfill_basic',
						'status'     => (string) ( $row['status'] ?? '' ),
					)
				);
			}
		}
	}

	private static function schedule(): void {
		// Resume a paused hash repair when its scheduled continuation tick fires.
		add_action( self::HASH_REPAIR_HOOK, array( __CLASS__, 'repair_advertising_hashes' ) );

		if ( ! wp_next_scheduled( Estandar_Signal_Core_Scheduler::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Estandar_Signal_Core_Scheduler::CRON_HOOK );
		}
	}

	public static function clients_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'es_signal_clients';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'es_signal_events';
	}

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'es_signal_queue';
	}

	public static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
