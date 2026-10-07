<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * External-source adapter. It translates Search Console rows into stable domain
 * arrays and exposes no persistence or Lead concern.
 */
final class Estandar_Search_Console_Adapter {
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const TOKEN_TRANSIENT = 'estandar_seo_search_console_token';

    public function configured(): bool { $c = $this->config(); return $c['site_url'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['refresh_token'] !== ''; }

    public function pages( string $date ): array { return $this->query( $date, array( 'page' ) ); }
    /** Lists only properties authorized for the configured OAuth principal. */
    public function properties(): array {
        if ( ! $this->configured() ) { return array( 'success' => false, 'configured' => false, 'temporary' => false, 'message' => 'Google Search Console is not configured.', 'properties' => array() ); }
        $token = $this->access_token(); if ( $token['token'] === '' ) { return array( 'success' => false, 'configured' => true, 'temporary' => ! empty( $token['temporary'] ), 'message' => (string) $token['message'], 'properties' => array() ); }
        $response = wp_remote_get( self::API_BASE . '/sites', array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token['token'] ) ) );
        if ( is_wp_error( $response ) ) { return array( 'success' => false, 'configured' => true, 'temporary' => true, 'message' => $response->get_error_message(), 'properties' => array() ); }
        $code = (int) wp_remote_retrieve_response_code( $response ); $raw = (string) wp_remote_retrieve_body( $response );
        if ( $code !== 200 ) { return array( 'success' => false, 'configured' => true, 'temporary' => ( $code === 401 || $code === 429 || $code >= 500 ), 'message' => self::message( $raw, $code ), 'properties' => array() ); }
        $items = array(); foreach ( (array) ( json_decode( $raw, true )['siteEntry'] ?? array() ) as $entry ) { $url = trim( (string) ( $entry['siteUrl'] ?? '' ) ); if ( $url !== '' ) { $items[] = array( 'url' => $url, 'permission' => (string) ( $entry['permissionLevel'] ?? '' ) ); } }
        return array( 'success' => true, 'configured' => true, 'temporary' => false, 'message' => 'OK', 'properties' => $items );
    }
    public function validate_selected_property(): array {
        $properties = $this->properties(); if ( empty( $properties['success'] ) ) { return $properties; }
        $selected = $this->config()['site_url']; foreach ( $properties['properties'] as $property ) { if ( $property['url'] === $selected ) { return array( 'success' => true, 'configured' => true, 'temporary' => false, 'message' => 'Property authorized: ' . $selected . '.', 'properties' => $properties['properties'] ); } }
        return array( 'success' => false, 'configured' => true, 'temporary' => false, 'message' => 'Selected Search Console property is absent or not authorized.', 'properties' => $properties['properties'] );
    }
    public function queries_for_page( string $date, string $page ): array {
        return $this->query( $date, array( 'page', 'query' ), array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $page ) );
    }

    private function query( string $date, array $dimensions, array $filter = array() ): array {
        if ( ! $this->configured() ) { return array( 'success' => false, 'configured' => false, 'temporary' => false, 'message' => 'Google Search Console is not configured.', 'rows' => array() ); }
        $token = $this->access_token(); if ( $token['token'] === '' ) { return array_merge( $token, array( 'success' => false, 'configured' => true, 'rows' => array() ) ); }
        $all = array(); $start = 0;
        do {
            $body = array( 'startDate' => $date, 'endDate' => $date, 'dimensions' => $dimensions, 'rowLimit' => 25000, 'startRow' => $start, 'dataState' => 'final' );
            if ( ! empty( $filter ) ) { $body['dimensionFilterGroups'] = array( array( 'filters' => array( $filter ) ) ); }
            $config = $this->config();
            $response = wp_remote_post( self::API_BASE . '/sites/' . rawurlencode( $config['site_url'] ) . '/searchAnalytics/query', array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token['token'], 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
            if ( is_wp_error( $response ) ) { return array( 'success' => false, 'configured' => true, 'temporary' => true, 'message' => $response->get_error_message(), 'rows' => array() ); }
            $code = (int) wp_remote_retrieve_response_code( $response ); $raw = (string) wp_remote_retrieve_body( $response );
            if ( $code !== 200 ) { if ( $code === 401 ) { delete_transient( self::TOKEN_TRANSIENT ); } return array( 'success' => false, 'configured' => true, 'temporary' => ( $code === 401 || $code === 429 || $code >= 500 ), 'message' => self::message( $raw, $code ), 'rows' => array() ); }
            $rows = (array) ( json_decode( $raw, true )['rows'] ?? array() ); $all = array_merge( $all, $this->normalize_rows( $rows, $dimensions ) ); $start += count( $rows );
        } while ( count( $rows ) === 25000 );
        return array( 'success' => true, 'configured' => true, 'temporary' => false, 'message' => 'OK', 'rows' => $all );
    }

    private function normalize_rows( array $rows, array $dimensions ): array {
        $out = array(); foreach ( $rows as $row ) { $keys = (array) ( $row['keys'] ?? array() ); $item = array( 'clicks' => (int) ( $row['clicks'] ?? 0 ), 'impressions' => (int) ( $row['impressions'] ?? 0 ), 'ctr' => (float) ( $row['ctr'] ?? 0 ), 'average_position' => (float) ( $row['position'] ?? 0 ) ); foreach ( $dimensions as $i => $dimension ) { $item[ $dimension ] = (string) ( $keys[ $i ] ?? '' ); } if ( $item['page'] ?? '' ) { $out[] = $item; } } return $out;
    }
    private function access_token(): array {
        if ( class_exists( 'Estandar_Google_Integration_Hub' ) ) { $hub = Estandar_Google_Integration_Hub::get_access_token( 'search_console' ); if ( ! empty( $hub['token'] ) ) { return array( 'token' => $hub['token'], 'temporary' => false, 'message' => '' ); } }
        $cached = get_transient( self::TOKEN_TRANSIENT ); if ( is_string( $cached ) && $cached !== '' ) { return array( 'token' => $cached, 'temporary' => false, 'message' => '' ); }
        $c = $this->config(); $response = wp_remote_post( self::TOKEN_URL, array( 'timeout' => 20, 'body' => array( 'grant_type' => 'refresh_token', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'] ) ) );
        if ( is_wp_error( $response ) ) { return array( 'token' => '', 'temporary' => true, 'message' => $response->get_error_message() ); }
        $code = (int) wp_remote_retrieve_response_code( $response ); $data = json_decode( (string) wp_remote_retrieve_body( $response ), true ); $token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
        if ( $code !== 200 || $token === '' ) { return array( 'token' => '', 'temporary' => ( $code === 429 || $code >= 500 ), 'message' => self::message( (string) wp_remote_retrieve_body( $response ), $code ) ); }
        set_transient( self::TOKEN_TRANSIENT, $token, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) ); return array( 'token' => $token, 'temporary' => false, 'message' => '' );
    }
    private function config(): array { $defaults = array( 'site_url' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => '' ); $config = apply_filters( 'estandar_seo_search_console_config', get_option( 'estandar_seo_search_console', array() ) ); $config=array_merge( $defaults, is_array( $config ) ? $config : array() ); if(class_exists('Estandar_Google_Integration_Hub')){$selected=Estandar_Google_Integration_Hub::selected('search_console');if(!empty($selected[0])){$config['site_url']=$selected[0];}} return $config; }
    private static function message( string $raw, int $code ): string { $data = json_decode( $raw, true ); return trim( (string) ( $data['error']['message'] ?? '' ) ) ?: 'Google Search Console HTTP ' . $code . '.'; }
}
