<?php
if ( ! defined('ABSPATH') ) exit;

class Estandar_Survey_DB {

    const DB_VERSION = '1.1';
    const DB_OPTION  = 'estandar_surveys_db_version';

    public static function maybe_install(): void {
        $installed = (string) get_option( self::DB_OPTION );
        if ( $installed === self::DB_VERSION ) {
            return;
        }
        // v1.0 created 3 tables — obsolete sessions + events never reached production.
        if ( $installed === '1.0' ) {
            self::migrate_from_v1();
        }
        self::install();
    }

    private static function migrate_from_v1(): void {
        global $wpdb;

        // Retire redundant v1.0 tables NON-DESTRUCTIVELY. A DROP was previously
        // reachable on a retried/partial migration (when the _legacy target already
        // existed) — risking loss of a populated table. retire_table() never drops:
        // it renames to _legacy, or to a unique _legacy_<ts> if that name is taken.
        // Idempotent: a re-run is a no-op once the source table is gone.
        self::retire_table( $wpdb->prefix . 'estandar_survey_sessions' );
        self::retire_table( $wpdb->prefix . 'estandar_survey_events' );

        // survey_responses: retire only if it still carries the old session_id INT FK column.
        $responses = $wpdb->prefix . 'estandar_survey_responses';
        $has_old_col = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '$responses'
             AND COLUMN_NAME = 'session_id'"
        );
        if ( $has_old_col ) {
            self::retire_table( $responses );
        }
    }

    /**
     * Move a table out of the way without ever dropping it. Renames to <table>_legacy,
     * or to <table>_legacy_<timestamp> when a prior backup already occupies that name.
     * No-op when the source table does not exist (keeps migrate_from_v1 idempotent).
     */
    private static function retire_table( string $table ): void {
        global $wpdb;

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) {
            return;
        }

        $legacy = $table . '_legacy';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$legacy'" ) ) {
            // Preserve the existing backup — never drop. Use a unique, timestamped name.
            $legacy = $table . '_legacy_' . gmdate( 'YmdHis' );
        }

        $wpdb->query( "RENAME TABLE $table TO $legacy" );
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Single table: per-question responses.
        // Funnel events → ea_events (Estandar Analytics).
        // Session metadata  → estandar_form_entries (data_json blob).
        $responses = $wpdb->prefix . 'estandar_survey_responses';

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $responses (
                id                 BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
                session_token      VARCHAR(64)         NOT NULL DEFAULT '',
                form_id            BIGINT UNSIGNED     NOT NULL,
                entry_id           BIGINT UNSIGNED     NULL,
                question_index     TINYINT UNSIGNED    NOT NULL DEFAULT 0,
                question_label     VARCHAR(500)        NULL,
                question_type      VARCHAR(32)         NOT NULL DEFAULT 'text',
                answer_value       TEXT                NULL,
                answer_label       TEXT                NULL,
                answered_at        DATETIME            NOT NULL,
                time_spent_seconds SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY session_token (session_token),
                KEY form_id (form_id),
                KEY entry_id (entry_id),
                KEY question_type (question_type)
            ) $charset"
        );

        update_option( self::DB_OPTION, self::DB_VERSION );
    }
}
