<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only bridge: Leads resolves the Lead; SEO Intelligence owns all SEO reads. */
final class Estandar_Leads_SEO_Intelligence {
    public static function context_for_lead( $lead_id, $days = 28, $limit = 5 ): array {
        $base = self::base( 'seo_unavailable' );
        if ( ! class_exists( 'Estandar_SEO_Intelligence' ) ) { return $base; }
        global $wpdb; $lead_id = absint( $lead_id ); if ( ! $lead_id ) { return self::base( 'no_landing' ); }
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT id, first_landing_url, first_referrer, first_utm_source, first_utm_medium, first_session_id FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id ) );
        if ( ! $lead ) { return self::base( 'no_landing' ); }
        $submission = $wpdb->get_row( $wpdb->prepare( "SELECT landing_url, referrer, session_id, utm_source, utm_medium, gclid, gbraid, wbraid, fbclid, meta_fbc, source_plugin FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY created_at DESC LIMIT 1", $lead_id ) );
        $channel = class_exists( 'Estandar_Leads_Attribution' ) ? Estandar_Leads_Attribution::resolve_channel( $submission ?: $lead ) : '';
        if ( $channel !== 'google_organic' ) { return self::base( 'not_organic' ); }
        $session_id = trim( (string) ( $submission->session_id ?? $lead->first_session_id ?? '' ) ); $session_landing = '';
        if ( $session_id !== '' ) { $session_landing = (string) $wpdb->get_var( $wpdb->prepare( "SELECT landing_page FROM {$wpdb->prefix}ea_sessions WHERE session_id = %s LIMIT 1", $session_id ) ); }
        $landing = self::canonical_landing( $lead, $submission, $session_landing );
        if ( $landing['url'] === '' ) { return self::base( 'no_landing' ); }
        try { return Estandar_SEO_Intelligence::get_landing_organic_context( $landing['url'], $days, $limit ); }
        catch ( Throwable $e ) { return self::base( 'unavailable', $landing['url'] ); }
    }

    public static function top_queries_for_lead( $lead_id, $limit = 10 ): array { return (array) ( self::context_for_lead( $lead_id, 28, $limit )['top_queries'] ?? array() ); }

    public static function render_context_block( $lead_id ): void {
        $context = self::context_for_lead( $lead_id ); if ( ! in_array( $context['status'], array( 'available', 'stale' ), true ) ) { return; }
        echo '<section class="ow-captured"><h2>Contexto SEO de la landing</h2><p><strong>Clicks:</strong> ' . (int) $context['metrics']['clicks'] . ' · <strong>Impresiones:</strong> ' . (int) $context['metrics']['impressions'] . ' · <strong>CTR:</strong> ' . esc_html( number_format_i18n( (float) $context['metrics']['ctr'] * 100, 1 ) . '%' ) . ' · <strong>Posición media:</strong> ' . esc_html( number_format_i18n( (float) $context['metrics']['average_position'], 1 ) ) . '</p>';
        echo '<p>Período: ' . esc_html( $context['period']['from'] . ' a ' . $context['period']['to'] . ' (' . $context['period']['timezone'] . ')' ) . ' · Frescura: ' . (int) $context['freshness']['age_days'] . ' días' . ( $context['freshness']['stale'] ? ' · datos desactualizados' : '' ) . '</p>';
        if ( ! empty( $context['top_queries'] ) ) { echo '<p><strong>Top queries agregadas:</strong> ' . esc_html( implode( ' · ', array_map( static function ( $query ) { return (string) $query['query']; }, $context['top_queries'] ) ) ) . '</p>'; }
        echo '<p><em>Datos agregados de la página; no representan necesariamente la búsqueda exacta de este prospecto.</em></p></section>';
    }

    private static function canonical_landing( $lead, $submission, string $session_landing ): array {
        $candidates = array( 'explicit' => trim( (string) ( $submission->landing_url ?? '' ) ), 'submission' => trim( (string) ( $lead->first_landing_url ?? '' ) ), 'session' => $session_landing, 'referrer' => trim( (string) ( $submission->referrer ?? $lead->first_referrer ?? '' ) ), 'home' => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '' );
        return class_exists( 'Estandar_Leads_Attribution' ) ? Estandar_Leads_Attribution::canonical_landing( $candidates ) : array( 'url' => $candidates['explicit'] ?: $candidates['submission'], 'source' => 'fallback' );
    }
    private static function base( string $status, string $landing = '' ): array { return array( 'status' => $status, 'landing_url' => $landing, 'normalized_landing_url' => '', 'period' => array( 'from' => '', 'to' => '', 'timezone' => '' ), 'metrics' => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'average_position' => 0 ), 'top_queries' => array(), 'freshness' => array( 'latest_commercial_date' => '', 'age_days' => null, 'stale' => false ), 'attribution' => array( 'source' => 'google_search_console', 'scope' => 'landing_aggregate', 'exact_query_known' => false ) ); }
}
