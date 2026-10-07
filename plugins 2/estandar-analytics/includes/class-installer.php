<?php
/**
 * Database installer for Estandar Analytics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Installer {

	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$sessions = EA_Utils::table( 'sessions' );
		$sql      = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			visitor_hash varchar(64) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_activity datetime NOT NULL,
			duration int(10) unsigned NOT NULL DEFAULT 0,
			page_count smallint(5) unsigned NOT NULL DEFAULT 0,
			is_bounce tinyint(1) NOT NULL DEFAULT 1,
			landing_page varchar(2048) NOT NULL DEFAULT '',
			exit_page varchar(2048) NOT NULL DEFAULT '',
			is_returning tinyint(1) NOT NULL DEFAULT 0,
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			content varchar(255) NOT NULL DEFAULT '',
			term varchar(255) NOT NULL DEFAULT '',
			campaign_id varchar(190) NOT NULL DEFAULT '',
			adset_id varchar(190) NOT NULL DEFAULT '',
			ad_id varchar(190) NOT NULL DEFAULT '',
			adgroup_id varchar(190) NOT NULL DEFAULT '',
			asset_group_id varchar(190) NOT NULL DEFAULT '',
			creative_id varchar(190) NOT NULL DEFAULT '',
			keyword varchar(255) NOT NULL DEFAULT '',
			match_type varchar(32) NOT NULL DEFAULT '',
			network varchar(32) NOT NULL DEFAULT '',
			google_device varchar(32) NOT NULL DEFAULT '',
			placement varchar(255) NOT NULL DEFAULT '',
			campaign_type varchar(64) NOT NULL DEFAULT '',
			gclid varchar(255) NOT NULL DEFAULT '',
			gbraid varchar(255) NOT NULL DEFAULT '',
			wbraid varchar(255) NOT NULL DEFAULT '',
			fbclid varchar(255) NOT NULL DEFAULT '',
			meta_fbp varchar(255) NOT NULL DEFAULT '',
			meta_fbc varchar(255) NOT NULL DEFAULT '',
			ttclid varchar(255) NOT NULL DEFAULT '',
			msclkid varchar(255) NOT NULL DEFAULT '',
			referrer varchar(2048) NOT NULL DEFAULT '',
			referrer_domain varchar(255) NOT NULL DEFAULT '',
			traffic_type varchar(50) NOT NULL DEFAULT '',
			first_project_slug varchar(190) NOT NULL DEFAULT '',
			first_project_page varchar(2048) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY visitor_hash (visitor_hash),
			KEY first_seen (first_seen),
			KEY first_project_slug (first_project_slug),
			KEY source (source),
			KEY traffic_type (traffic_type)
			) {$charset};";
			dbDelta( $sql );
			self::ensure_columns(
				$sessions,
				array(
					'gbraid'   => "varchar(255) NOT NULL DEFAULT ''",
					'wbraid'   => "varchar(255) NOT NULL DEFAULT ''",
					'meta_fbp' => "varchar(255) NOT NULL DEFAULT ''",
					'meta_fbc' => "varchar(255) NOT NULL DEFAULT ''",
					'ttclid'   => "varchar(255) NOT NULL DEFAULT ''",
					'msclkid'  => "varchar(255) NOT NULL DEFAULT ''",
					'campaign_id' => "varchar(190) NOT NULL DEFAULT ''",
					'adset_id'    => "varchar(190) NOT NULL DEFAULT ''",
					'ad_id'       => "varchar(190) NOT NULL DEFAULT ''",
					'adgroup_id'      => "varchar(190) NOT NULL DEFAULT ''",
					'asset_group_id'  => "varchar(190) NOT NULL DEFAULT ''",
					'creative_id'     => "varchar(190) NOT NULL DEFAULT ''",
					'keyword'         => "varchar(255) NOT NULL DEFAULT ''",
					'match_type'      => "varchar(32) NOT NULL DEFAULT ''",
					'network'         => "varchar(32) NOT NULL DEFAULT ''",
					'google_device'   => "varchar(32) NOT NULL DEFAULT ''",
					'placement'       => "varchar(255) NOT NULL DEFAULT ''",
					'campaign_type'   => "varchar(64) NOT NULL DEFAULT ''",
				)
			);

		$pageviews = EA_Utils::table( 'pageviews' );
		$sql       = "CREATE TABLE {$pageviews} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			page_url varchar(2048) NOT NULL DEFAULT '',
			page_title varchar(500) NOT NULL DEFAULT '',
			viewed_at datetime NOT NULL,
			time_on_page int(10) unsigned NOT NULL DEFAULT 0,
			scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY viewed_at (viewed_at)
		) {$charset};";
		dbDelta( $sql );

		$events = EA_Utils::table( 'events' );
		$sql    = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(64) DEFAULT NULL,
			session_id varchar(64) NOT NULL,
			event_type varchar(50) NOT NULL,
			event_label varchar(255) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL DEFAULT '',
			event_value varchar(500) NOT NULL DEFAULT '',
			meta_json longtext,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY occurred_at (occurred_at)
		) {$charset};";
		dbDelta( $sql );

		$conversions = EA_Utils::table( 'conversions' );
		$sql         = "CREATE TABLE {$conversions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			conversion_key varchar(100) NOT NULL,
			conversion_label varchar(255) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			landing_page varchar(2048) NOT NULL DEFAULT '',
			conversion_value int(10) unsigned NOT NULL DEFAULT 0,
			converted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY conversion_key (conversion_key),
			KEY converted_at (converted_at),
			KEY source (source),
			KEY project_slug (project_slug)
		) {$charset};";
		dbDelta( $sql );

		$referrers = EA_Utils::table( 'referrers' );
		$sql       = "CREATE TABLE {$referrers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			referrer_url varchar(2048) NOT NULL DEFAULT '',
			referrer_domain varchar(255) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			content varchar(255) NOT NULL DEFAULT '',
			term varchar(255) NOT NULL DEFAULT '',
			traffic_type varchar(50) NOT NULL DEFAULT '',
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY referrer_domain (referrer_domain),
			KEY recorded_at (recorded_at)
		) {$charset};";
		dbDelta( $sql );

		$devices = EA_Utils::table( 'devices' );
		$sql     = "CREATE TABLE {$devices} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			browser varchar(100) NOT NULL DEFAULT '',
			os varchar(100) NOT NULL DEFAULT '',
			device_type varchar(50) NOT NULL DEFAULT '',
			viewport varchar(20) NOT NULL DEFAULT '',
			language varchar(20) NOT NULL DEFAULT '',
			timezone varchar(50) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id)
		) {$charset};";
		dbDelta( $sql );

		$aggregates = EA_Utils::table( 'daily_aggregates' );
		$sql        = "CREATE TABLE {$aggregates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			aggregate_date date NOT NULL,
			metric_key varchar(100) NOT NULL,
			metric_value bigint(20) NOT NULL DEFAULT 0,
			dimension_key varchar(100) NOT NULL DEFAULT '',
			dimension_value varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY agg_unique (aggregate_date, metric_key, dimension_key(50), dimension_value(100)),
			KEY aggregate_date (aggregate_date),
			KEY metric_key (metric_key)
		) {$charset};";
		dbDelta( $sql );

		$ads_daily = EA_Utils::table( 'ads_daily' );
		$sql       = "CREATE TABLE {$ads_daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			channel varchar(50) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			campaign_id varchar(190) NOT NULL DEFAULT '',
			campaign_name varchar(255) NOT NULL DEFAULT '',
			adset_id varchar(190) NOT NULL DEFAULT '',
			adset_name varchar(255) NOT NULL DEFAULT '',
			ad_id varchar(190) NOT NULL DEFAULT '',
			ad_name varchar(255) NOT NULL DEFAULT '',
			impressions bigint(20) unsigned NOT NULL DEFAULT 0,
			reach bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			spend decimal(12,2) NOT NULL DEFAULT 0.00,
			ctr decimal(8,4) NOT NULL DEFAULT 0.0000,
			cpc decimal(12,4) NOT NULL DEFAULT 0.0000,
			cpm decimal(12,4) NOT NULL DEFAULT 0.0000,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stat_key (stat_date, channel, project_slug, campaign_id(80), adset_id(80), ad_id(80)),
			KEY stat_date (stat_date),
			KEY campaign_id (campaign_id),
			KEY ad_id (ad_id)
			) {$charset};";
			dbDelta( $sql );
			self::ensure_columns(
				$ads_daily,
				array(
					'adset_name' => "varchar(255) NOT NULL DEFAULT ''",
					'ad_name'    => "varchar(255) NOT NULL DEFAULT ''",
					'ctr'        => "decimal(8,4) NOT NULL DEFAULT 0.0000",
					'cpc'        => "decimal(12,4) NOT NULL DEFAULT 0.0000",
					'cpm'        => "decimal(12,4) NOT NULL DEFAULT 0.0000",
				)
			);

		if ( ! wp_next_scheduled( 'ea_daily_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ea_daily_retention' );
		}

		update_option( 'ea_db_version', EA_DB_VERSION, false );

		if ( false === get_option( 'ea_settings', false ) ) {
			EA_Settings::save_defaults();
		}
	}

		public static function deactivate() {
			wp_clear_scheduled_hook( 'ea_daily_retention' );
		}

		private static function ensure_columns( string $table, array $columns ): void {
			global $wpdb;

			$existing = self::table_columns( $table );
			foreach ( $columns as $column => $definition ) {
				if ( in_array( $column, $existing, true ) ) {
					continue;
				}
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			}
		}

		private static function table_columns( string $table ): array {
			global $wpdb;

			$out  = array();
			$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			if ( empty( $rows ) ) {
				$rows = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A );
			}
			foreach ( (array) $rows as $row ) {
				$name = (string) ( $row['Field'] ?? ( $row['name'] ?? '' ) );
				if ( $name !== '' ) {
					$out[] = $name;
				}
			}

			return $out;
		}
	}
