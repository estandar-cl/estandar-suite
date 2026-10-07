<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Estandar_SEO_Schema {
    const SCHEMA_VERSION = '1.1.0';

    public static function activate(): void {
        self::create_tables();
        update_option( 'estandar_seo_intelligence_schema_version', self::SCHEMA_VERSION, false );
    }

    public static function maybe_migrate(): void {
        if ( get_option( 'estandar_seo_intelligence_schema_version' ) !== self::SCHEMA_VERSION ) {
            self::activate();
        }
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $landings = $wpdb->prefix . 'es_seo_landings';
        $metrics = $wpdb->prefix . 'es_seo_landing_metrics';
        $queries = $wpdb->prefix . 'es_seo_queries';
        $runs = $wpdb->prefix . 'es_seo_sync_runs';

        dbDelta( "CREATE TABLE $landings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url text NOT NULL,
            normalized_url text NOT NULL,
            normalized_url_hash char(64) NOT NULL,
            last_sync datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY normalized_url_hash (normalized_url_hash)
        ) $charset;" );
        dbDelta( "CREATE TABLE $metrics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            landing_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            ctr decimal(12,8) NOT NULL DEFAULT 0,
            average_position decimal(12,4) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY landing_date (landing_id,date),
            KEY date (date)
        ) $charset;" );
        dbDelta( "CREATE TABLE $queries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            landing_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            query_text text NOT NULL,
            query_hash char(64) NOT NULL,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            ctr decimal(12,8) NOT NULL DEFAULT 0,
            average_position decimal(12,4) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY landing_date_query (landing_id,date,query_hash),
            KEY landing_date (landing_id,date)
        ) $charset;" );
        dbDelta( "CREATE TABLE $runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL,
            trigger_type varchar(32) NOT NULL,
            commercial_date date DEFAULT NULL,
            status varchar(20) NOT NULL,
            started_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            rows_received bigint(20) unsigned NOT NULL DEFAULT 0,
            rows_inserted bigint(20) unsigned NOT NULL DEFAULT 0,
            rows_skipped bigint(20) unsigned NOT NULL DEFAULT 0,
            error_message varchar(1000) NOT NULL DEFAULT '',
            next_attempt_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY status_started (status,started_at),
            KEY commercial_date (commercial_date)
        ) $charset;" );
    }
}
