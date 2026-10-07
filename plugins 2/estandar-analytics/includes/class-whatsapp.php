<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EA_WhatsApp {
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( 'ea/v1', '/whatsapp/(?P<slug>[a-z0-9-]+)', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'bridge' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function bridge( WP_REST_Request $request ) {
		$settings = EA_Settings::get();
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		$active = array_merge( array( $settings['whatsapp_bridge_slug'] ), (array) $settings['whatsapp_bridge_aliases'] );
		if ( ! in_array( $slug, $active, true ) ) {
			return new WP_Error( 'ea_whatsapp_bridge_not_found', 'Bridge no disponible.', array( 'status' => 404 ) );
		}
		if ( $request->get_param( 'test' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'slug' => $slug, 'canonical' => $settings['whatsapp_bridge_slug'] ), 200 );
		}
		$params = $request->get_query_params();
		unset( $params['slug'] );
		$target = add_query_arg( $params, rest_url( 'estandar-leads/v1/whatsapp-redirect' ) );
		wp_redirect( $target, 302 );
		exit;
	}

	public static function status(): array {
		global $wpdb;
		$table = EA_Utils::table( 'events' );
		$rows = $wpdb->get_results( "SELECT occurred_at, meta_json FROM {$table} WHERE event_type IN ('WhatsAppClick','whatsapp_click') ORDER BY id DESC", ARRAY_A );
		$settings = EA_Settings::get();
		$status = array(
			'detector_active' => ! empty( $settings['enable_whatsapp_tracking'] ),
			'bridge_active' => ! empty( $settings['whatsapp_bridge_slug'] ),
			'last_use' => (string) ( $rows[0]['occurred_at'] ?? '' ),
			'last_confirmed' => '', 'observed' => 0, 'confirmed' => 0,
			'detector_only' => 0, 'bridge_only' => 0, 'direct_links' => 0,
			'duplicates' => (int) get_option( 'ea_whatsapp_duplicates_avoided', 0 ),
			'errors' => (int) get_option( 'ea_whatsapp_errors', 0 ),
		);
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			$confirmed = ( $meta['reliability'] ?? '' ) === 'confirmed';
			$status[ $confirmed ? 'confirmed' : 'observed' ]++;
			if ( $confirmed && $status['last_confirmed'] === '' ) { $status['last_confirmed'] = (string) $row['occurred_at']; }
			$source = (string) ( $meta['source'] ?? '' );
			if ( $source === 'detector' ) { $status['detector_only']++; }
			if ( $source !== '' && $source !== 'detector' ) { $status['bridge_only']++; }
			if ( $source === 'detector' && preg_match( '#^(?:https?://(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)|whatsapp://)#i', (string) ( $meta['destination_url'] ?? '' ) ) ) { $status['direct_links']++; }
		}
		$status['total'] = count( $rows );
		$status['conversion_rate'] = $status['observed'] > 0 ? round( $status['confirmed'] * 100 / $status['observed'], 1 ) : 0.0;
		$status['diagnostics'] = self::diagnostics( $status );
		return $status;
	}

	public static function diagnostics( array $status ): array {
		$out = array();
		if ( $status['detector_active'] && ! $status['bridge_active'] ) { $out[] = 'Configura el bridge canónico; el detector está activo sin bridge.'; }
		if ( $status['bridge_active'] && ! $status['detector_active'] ) { $out[] = 'Activa el detector automático; el bridge está configurado sin detector.'; }
		if ( $status['direct_links'] > 0 ) { $out[] = 'Revisa los enlaces WhatsApp directos detectados fuera del bridge canónico.'; }
		if ( $status['bridge_active'] && $status['total'] === 0 ) { $out[] = 'Prueba el bridge: está configurado pero nunca fue utilizado.'; }
		if ( $status['observed'] >= 5 && $status['confirmed'] * 2 < $status['observed'] ) { $out[] = 'Revisa routing y respuesta del bridge: menos del 50% de observed llega a confirmed.'; }
		if ( $status['errors'] > 0 ) { $out[] = 'Revisa el log PHP/SQL: el recorder de WhatsApp reporta errores.'; }
		return $out;
	}
}
