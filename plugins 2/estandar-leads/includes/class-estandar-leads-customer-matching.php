<?php
/**
 * CUSTOMER_REGISTRY_V1 — Matching Service
 *
 * Matches a customer record against existing leads.
 * Read-only access to es_leads. Never writes to es_leads.
 *
 * Matching priority (first hit wins):
 *   1. RUT      → confidence: high
 *   2. Email    → confidence: high
 *   3. Phone    → confidence: medium
 *   4. Name     → confidence: low
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Customer_Matching {

    const CONFIDENCE_HIGH   = 'high';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_LOW    = 'low';

    /**
     * Attempt to match a customer record against es_leads.
     *
     * @param  array $customer  Row from es_customers (or equivalent map with
     *                          rut, email, phone, first_name, last_name keys).
     * @return array|null  { lead_id: int, matched_by: string, confidence: string }
     *                     or null when no match found.
     */
    public static function match( array $customer ): ?array {
        global $wpdb;
        $leads = $wpdb->prefix . 'es_leads';

        // 1. RUT — high confidence
        $rut = self::normalize_rut( (string) ( $customer['rut'] ?? '' ) );
        if ( $rut !== '' ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE rut = %s LIMIT 1",
                $rut
            ) );
            if ( $id > 0 ) {
                return array(
                    'lead_id'    => $id,
                    'matched_by' => 'rut',
                    'confidence' => self::CONFIDENCE_HIGH,
                );
            }
        }

        // 2. Email — high confidence
        $email = Estandar_Leads_Normalizer::email( (string) ( $customer['email'] ?? '' ) );
        if ( $email !== '' ) {
            $hash = Estandar_Leads_Normalizer::hash( $email );
            $id   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE email_hash = %s LIMIT 1",
                $hash
            ) );
            if ( $id > 0 ) {
                return array(
                    'lead_id'    => $id,
                    'matched_by' => 'email',
                    'confidence' => self::CONFIDENCE_HIGH,
                );
            }
        }

        // 3. Phone — medium confidence
        $phone = Estandar_Leads_Normalizer::phone_storage( (string) ( $customer['phone'] ?? '' ) );
        if ( $phone !== '' ) {
            $hash = Estandar_Leads_Normalizer::hash( $phone );
            $id   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads WHERE phone_hash = %s LIMIT 1",
                $hash
            ) );
            if ( $id > 0 ) {
                return array(
                    'lead_id'    => $id,
                    'matched_by' => 'phone',
                    'confidence' => self::CONFIDENCE_MEDIUM,
                );
            }
        }

        // 4. Name (first + last, normalised) — low confidence
        $name = self::normalize_name(
            (string) ( $customer['first_name'] ?? '' ),
            (string) ( $customer['last_name']  ?? '' )
        );
        if ( $name !== '' ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $leads
                  WHERE LOWER(TRIM(REGEXP_REPLACE(name, '\\\\s+', ' '))) = %s
                  LIMIT 1",
                $name
            ) );
            if ( $id > 0 ) {
                return array(
                    'lead_id'    => $id,
                    'matched_by' => 'name',
                    'confidence' => self::CONFIDENCE_LOW,
                );
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Normalisation helpers
    // -------------------------------------------------------------------------

    /**
     * Strip dots, hyphens, spaces; uppercase K.
     * Returns '' when the result is not a valid RUT pattern.
     */
    public static function normalize_rut( string $raw ): string {
        $clean = strtoupper( preg_replace( '/[.\-\s]/', '', trim( $raw ) ) );
        return preg_match( '/^\d+[0-9K]$/', $clean ) ? $clean : '';
    }

    /** Collapse whitespace, lowercase, concatenate first + last. */
    public static function normalize_name( string $first, string $last ): string {
        $full = trim( $first . ' ' . $last );
        if ( $full === '' ) {
            return '';
        }
        return mb_strtolower( preg_replace( '/\s+/', ' ', $full ) );
    }
}
