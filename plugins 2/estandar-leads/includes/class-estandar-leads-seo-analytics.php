<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only commercial aggregate contract for SEO Intelligence. Owns all Leads SQL. */
final class Estandar_Leads_SEO_Analytics {
    public static function get_organic_conversion_summary( array $period, array $filters = array() ): array {
        global $wpdb;
        $from = self::date( $period['from'] ?? '' ); $to = self::date( $period['to'] ?? '' );
        if ( ! $from || ! $to || $from > $to ) { return self::result( 'invalid_period', $period ); }
        $leads = $wpdb->prefix . 'es_leads'; $submissions = $wpdb->prefix . 'es_lead_submissions';
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.project_slug, l.quality, l.is_valid_lead, l.sales_stage, l.first_landing_url, l.first_utm_source, l.first_utm_medium, l.first_gclid, l.first_gbraid, l.first_wbraid, l.first_referrer, l.created_at, s.landing_url, s.utm_source, s.utm_medium, s.gclid, s.gbraid, s.wbraid, s.referrer, s.created_at AS submission_created_at FROM {$leads} l LEFT JOIN {$submissions} s ON s.id = (SELECT ss.id FROM {$submissions} ss WHERE ss.lead_id = l.id ORDER BY ss.created_at ASC, ss.id ASC LIMIT 1) WHERE l.created_at >= %s AND l.created_at < %s ORDER BY l.id ASC LIMIT 5000",
            $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00'
        ), ARRAY_A );
        $out = array();
        foreach ( $rows as $row ) {
            if ( ! class_exists( 'Estandar_Leads_Attribution' ) || Estandar_Leads_Attribution::resolve_channel( $row ) !== 'google_organic' ) { continue; }
            $landing = self::landing( $row ); if ( $landing === '' ) { continue; }
            $project = Estandar_Leads_Projects::normalize_slug( $row['project_slug'] ?? '' );
            if ( ! empty( $filters['project'] ) && $project !== Estandar_Leads_Projects::normalize_slug( $filters['project'] ) ) { continue; }
            if ( ! empty( $filters['landing'] ) && $landing !== self::normalize( $filters['landing'] ) ) { continue; }
            $key = $project . '|' . $landing;
            if ( ! isset( $out[$key] ) ) { $out[$key] = array( 'project_id' => $project, 'landing_url' => $landing, 'google_organic_leads' => 0, 'classified_leads' => 0, 'qualified_leads' => 0, 'commercial_results_available' => 0 ); }
            $out[$key]['google_organic_leads']++;
            $classified = trim( (string) ( $row['quality'] ?? '' ) ) !== '' || $row['is_valid_lead'] !== null;
            if ( $classified ) { $out[$key]['classified_leads']++; }
            if ( self::qualified( $row ) ) { $out[$key]['qualified_leads']++; }
            if ( trim( (string) ( $row['sales_stage'] ?? '' ) ) !== '' ) { $out[$key]['commercial_results_available']++; }
        }
        return array( 'status' => 'available', 'period' => array( 'from' => $from, 'to' => $to ), 'dimensions' => array_values( $out ), 'warnings' => count( $rows ) >= 5000 ? array( 'commercial_rows_truncated' ) : array(), 'read_only' => true );
    }
    public static function get_landing_conversion_metrics( $url, array $period, array $filters = array() ): array { $filters['landing'] = $url; return self::get_organic_conversion_summary( $period, $filters ); }
    public static function get_project_organic_performance( $project_id, array $period, array $filters = array() ): array { $filters['project'] = $project_id; return self::get_organic_conversion_summary( $period, $filters ); }
    private static function result( string $status, array $period ): array { return array( 'status' => $status, 'period' => $period, 'dimensions' => array(), 'warnings' => array(), 'read_only' => true ); }
    private static function date( $date ): string { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? (string) $date : ''; }
    private static function landing( array $row ): string { return self::normalize( $row['landing_url'] ?? $row['first_landing_url'] ?? '' ); }
    private static function normalize( $url ): string { if ( class_exists( 'Estandar_SEO_URL' ) ) { return Estandar_SEO_URL::normalize( $url ); } return trim( (string) $url ); }
    private static function qualified( array $row ): bool { return ! empty( $row['is_valid_lead'] ) && ! in_array( strtolower( trim( (string) ( $row['quality'] ?? '' ) ) ), array( 'junk', 'invalid', 'unqualified' ), true ); }
}
