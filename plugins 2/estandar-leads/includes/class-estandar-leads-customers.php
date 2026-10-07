<?php
/**
 * CUSTOMER_REGISTRY_V1
 *
 * Imports external customer records and matches them against existing leads.
 * Write target: es_customers only. es_leads is never modified.
 *
 * Matching priority (first hit wins):
 *   1. RUT      (exact, normalised: digits + optional K)
 *   2. email    (normalised lowercase)
 *   3. phone    (via Estandar_Leads_Normalizer::phone_storage)
 *   4. name     (first_name + last_name, whitespace-collapsed, lowercase)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Customers {

    const TABLE = 'es_customers';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Import an array of external customer records into es_customers.
     *
     * Each row must be an associative array. Unknown keys are silently ignored.
     * Required: at least one of rut|email|phone (rows with none are skipped).
     *
     * @param  array[] $rows
     * @return array { imported: int, skipped: int }
     */
    public static function import( array $rows ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );

        $imported = 0;
        $skipped  = 0;

        foreach ( $rows as $row ) {
            $row = self::sanitize_row( $row );
            if ( ! self::has_identity( $row ) ) {
                $skipped++;
                continue;
            }
            $wpdb->insert(
                $table,
                array(
                    'project_slug'   => $row['project_slug'],
                    'external_id'    => $row['external_id'],
                    'rut'            => $row['rut'],
                    'email'          => $row['email'],
                    'phone'          => $row['phone'],
                    'first_name'     => $row['first_name'],
                    'last_name'      => $row['last_name'],
                    'customer_stage' => $row['customer_stage'],
                    'matched_lead_id' => null,
                    'matched_by'     => null,
                    'matched_at'     => null,
                    'created_at'     => $now,
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
            );
            if ( $wpdb->rows_affected > 0 ) {
                $imported++;
            }
        }

        return array( 'imported' => $imported, 'skipped' => $skipped );
    }

    /**
     * Attempt to match every unmatched record against es_leads.
     *
     * Matching order: RUT → email → phone → name.
     * Writes matched_lead_id + matched_by + matched_at.
     * Never modifies es_leads.
     *
     * @return array { matched: int, unmatched: int }
     */
    /**
     * Match unmatched customer records against es_leads.
     *
     * Paginates internally (MATCH_BATCH rows at a time) to avoid OOM on
     * large datasets. Use $limit=0 to process all rows (legacy behavior,
     * memory-unsafe for >10K records).
     *
     * @param  int $limit Max records per call. 0 = unlimited (calls in a loop).
     * @return array { matched: int, unmatched: int }
     */
    const MATCH_BATCH = 1000;

    public static function match( int $limit = 0 ): array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $leads  = $wpdb->prefix . 'es_leads';
        $now    = gmdate( 'Y-m-d H:i:s' );

        $batch_size = $limit > 0 ? $limit : self::MATCH_BATCH;

        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, rut, email, phone, first_name, last_name
               FROM $table
              WHERE matched_lead_id IS NULL
              ORDER BY id ASC
              LIMIT %d",
            $batch_size
        ), ARRAY_A );

        $matched   = 0;
        $unmatched = 0;

        foreach ( (array) $pending as $rec ) {
            $result = self::find_lead( $rec, $leads );
            if ( $result === null ) {
                $unmatched++;
                continue;
            }
            $wpdb->update(
                $table,
                array(
                    'matched_lead_id' => (int) $result['lead_id'],
                    'matched_by'      => $result['method'],
                    'matched_at'      => $now,
                ),
                array( 'id' => (int) $rec['id'] ),
                array( '%d', '%s', '%s' ),
                array( '%d' )
            );
            $matched++;
        }

        return array( 'matched' => $matched, 'unmatched' => $unmatched, 'more' => count( $pending ) >= $batch_size );
    }

    /**
     * Return the customer record for a given lead_id, or null if none.
     *
     * @param  int $lead_id
     * @return array|null
     */
    public static function get_customer_by_lead( int $lead_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE matched_lead_id = %d ORDER BY matched_at DESC LIMIT 1",
                $lead_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Try each matching strategy in priority order.
     *
     * @param  array  $rec   Row from es_customers.
     * @param  string $leads Full table name for es_leads.
     * @return array|null { lead_id: int, method: string } or null
     */
    private static function find_lead( array $rec, string $leads ): ?array {
        global $wpdb;

        // 1. RUT
        $rut = self::normalize_rut( $rec['rut'] ?? '' );
        if ( $rut !== '' ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE rut = %s LIMIT 1",
                $rut
            ) );
            if ( $id > 0 ) {
                return array( 'lead_id' => $id, 'method' => 'rut' );
            }
        }

        // 2. Email
        $email = Estandar_Leads_Normalizer::email( $rec['email'] ?? '' );
        if ( $email !== '' ) {
            $hash = Estandar_Leads_Normalizer::hash( $email );
            $id   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE email_hash = %s LIMIT 1",
                $hash
            ) );
            if ( $id > 0 ) {
                return array( 'lead_id' => $id, 'method' => 'email' );
            }
        }

        // 3. Phone
        $phone = Estandar_Leads_Normalizer::phone_storage( $rec['phone'] ?? '' );
        if ( $phone !== '' ) {
            $hash = Estandar_Leads_Normalizer::hash( $phone );
            $id   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE phone_hash = %s LIMIT 1",
                $hash
            ) );
            if ( $id > 0 ) {
                return array( 'lead_id' => $id, 'method' => 'phone' );
            }
        }

        // 4. Name (first + last, normalised)
        $name = self::normalize_name( $rec['first_name'] ?? '', $rec['last_name'] ?? '' );
        if ( $name !== '' ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads
                  WHERE LOWER(TRIM(REGEXP_REPLACE(name, '\\\\s+', ' '))) = %s
                  LIMIT 1",
                $name
            ) );
            if ( $id > 0 ) {
                return array( 'lead_id' => $id, 'method' => 'name' );
            }
        }

        return null;
    }

    /** Sanitize and normalise a raw input row. */
    private static function sanitize_row( array $row ): array {
        return array(
            'project_slug'   => sanitize_key( (string) ( $row['project_slug'] ?? '' ) ),
            'external_id'    => sanitize_text_field( (string) ( $row['external_id'] ?? '' ) ),
            'rut'            => self::normalize_rut( (string) ( $row['rut'] ?? '' ) ),
            'email'          => sanitize_email( (string) ( $row['email'] ?? '' ) ),
            'phone'          => sanitize_text_field( (string) ( $row['phone'] ?? '' ) ),
            'first_name'     => sanitize_text_field( (string) ( $row['first_name'] ?? '' ) ),
            'last_name'      => sanitize_text_field( (string) ( $row['last_name'] ?? '' ) ),
            'customer_stage' => sanitize_key( (string) ( $row['customer_stage'] ?? '' ) ),
        );
    }

    /** True if the row has at least one matchable identity field. */
    private static function has_identity( array $row ): bool {
        return $row['rut'] !== ''
            || $row['email'] !== ''
            || $row['phone'] !== ''
            || ( $row['first_name'] !== '' && $row['last_name'] !== '' );
    }

    /**
     * Normalise a RUT: delegates to Estandar_Leads_Customer_Matching (single
     * canonical implementation).
     */
    private static function normalize_rut( string $raw ): string {
        return class_exists( 'Estandar_Leads_Customer_Matching' )
            ? Estandar_Leads_Customer_Matching::normalize_rut( $raw )
            : '';
    }

    /**
     * Normalise a name: delegates to Estandar_Leads_Customer_Matching (single
     * canonical implementation).
     */
    private static function normalize_name( string $first, string $last ): string {
        return class_exists( 'Estandar_Leads_Customer_Matching' )
            ? Estandar_Leads_Customer_Matching::normalize_name( $first, $last )
            : '';
    }
}
