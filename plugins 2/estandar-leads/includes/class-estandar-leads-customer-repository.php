<?php
/**
 * CUSTOMER_REGISTRY_V1 — Repository
 *
 * Thin CRUD layer over es_customers.
 * No business logic. No calls to es_leads writers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Customer_Repository {

    const TABLE = 'es_customers';

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new customer row.
     *
     * @param  array $data  Sanitized field map (see sanitize()).
     * @return int          New row id, or 0 on failure.
     */
    public static function create( array $data ): int {
        global $wpdb;
        $now  = gmdate( 'Y-m-d H:i:s' );
        $row  = self::sanitize( $data );
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        $wpdb->insert( $wpdb->prefix . self::TABLE, $row, self::formats( $row ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing customer row by id.
     *
     * @param  int   $id
     * @param  array $data  Partial field map — only supplied keys are updated.
     * @return bool
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $row = self::sanitize( $data );
        $row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

        $result = $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $row,
            array( 'id' => $id ),
            self::formats( $row ),
            array( '%d' )
        );
        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Find a single customer by primary key.
     *
     * @param  int $id
     * @return array|null
     */
    public static function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Find the customer matched to a given lead.
     * Returns the most recently matched record.
     *
     * @param  int $lead_id
     * @return array|null
     */
    public static function find_by_lead( int $lead_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
                  WHERE matched_lead_id = %d
                  ORDER BY matched_at DESC, id DESC
                  LIMIT 1",
                $lead_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * List customers with optional filters.
     *
     * Supported keys in $args:
     *   project_slug      string
     *   customer_stage    string
     *   matched_lead_id   int
     *   unmatched         bool   — true = only rows with matched_lead_id IS NULL
     *   limit             int    default 50
     *   offset            int    default 0
     *
     * @param  array $args
     * @return array[]
     */
    public static function list( array $args = [] ): array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['project_slug'] ) ) {
            $where[]  = 'project_slug = %s';
            $params[] = sanitize_key( $args['project_slug'] );
        }
        if ( ! empty( $args['customer_stage'] ) ) {
            $where[]  = 'customer_stage = %s';
            $params[] = sanitize_key( $args['customer_stage'] );
        }
        if ( isset( $args['matched_lead_id'] ) && (int) $args['matched_lead_id'] > 0 ) {
            $where[]  = 'matched_lead_id = %d';
            $params[] = (int) $args['matched_lead_id'];
        }
        if ( ! empty( $args['unmatched'] ) ) {
            $where[] = 'matched_lead_id IS NULL';
        }

        $limit  = max( 1, (int) ( $args['limit']  ?? 50 ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0  ) );

        $sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where )
             . " ORDER BY id DESC LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return $rows ?: array();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /** Sanitize a raw data array to safe column values. */
    private static function sanitize( array $data ): array {
        $out = array();
        $str_fields = array(
            'company_id', 'project_slug', 'external_id', 'customer_stage',
            'first_name', 'last_name', 'email', 'phone', 'rut', 'organization',
            'matched_by', 'matched_confidence',
        );
        foreach ( $str_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $out[ $f ] = sanitize_text_field( (string) $data[ $f ] );
            }
        }
        $int_fields = array( 'matched_lead_id' );
        foreach ( $int_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $out[ $f ] = $data[ $f ] !== null ? (int) $data[ $f ] : null;
            }
        }
        $decimal_fields = array( 'amount' );
        foreach ( $decimal_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $out[ $f ] = $data[ $f ] !== null ? (float) $data[ $f ] : null;
            }
        }
        $datetime_fields = array( 'started_at', 'completed_at', 'matched_at' );
        foreach ( $datetime_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $out[ $f ] = $data[ $f ] !== null
                    ? sanitize_text_field( (string) $data[ $f ] )
                    : null;
            }
        }
        if ( array_key_exists( 'metadata_json', $data ) ) {
            $meta = $data['metadata_json'];
            $out['metadata_json'] = is_array( $meta )
                ? wp_json_encode( $meta )
                : ( is_string( $meta ) ? $meta : null );
        }
        return $out;
    }

    /** Return wpdb format strings for a sanitized row. */
    private static function formats( array $row ): array {
        $int_cols  = array( 'matched_lead_id' );
        $float_cols = array( 'amount' );
        $formats   = array();
        foreach ( $row as $col => $val ) {
            if ( $val === null ) {
                $formats[] = '%s'; // wpdb handles NULL via null value regardless of format
            } elseif ( in_array( $col, $int_cols, true ) ) {
                $formats[] = '%d';
            } elseif ( in_array( $col, $float_cols, true ) ) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }
}
