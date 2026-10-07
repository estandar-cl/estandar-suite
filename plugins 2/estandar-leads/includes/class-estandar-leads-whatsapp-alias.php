<?php
/**
 * Public alias: GET /go/whatsapp → Estandar_Leads_Whatsapp_Redirect::handle().
 *
 * WHATSAPP_ALIAS_V1 — thin shim, zero duplicated logic.
 *
 * Route registered:  GET /go/whatsapp  (clean URL, no /wp-json/ prefix)
 * Mechanism:         template_redirect hook at priority 1, subdirectory-safe
 *                    path matching. No rewrite rules, no flush_rewrite_rules(),
 *                    no new options, no schema changes.
 *
 * Delegates 100% to Estandar_Leads_Whatsapp_Redirect::handle():
 *   - same $_GET input  (handler reads $_GET directly, not $request params)
 *   - same business logic, tracking, telemetry, events
 *   - same redirect target
 *
 * Both endpoints remain active simultaneously:
 *   /go/whatsapp                                      ← this alias
 *   /wp-json/estandar-leads/v1/whatsapp-redirect      ← original REST route
 *
 * Ownership: estandar-leads.
 * Since: 10.3.15 (WHATSAPP_ALIAS_V1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Whatsapp_Alias {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function maybe_handle(): void {
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path    = trim( (string) wp_parse_url( $raw_uri, PHP_URL_PATH ), '/' );

		// Subdirectory installs: strip the site path prefix before matching.
		// e.g. home_url('/') = https://example.com/wp/ → site_path = 'wp'.
		$site_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $site_path !== '' && strpos( $path, $site_path . '/' ) === 0 ) {
			$path = substr( $path, strlen( $site_path ) + 1 );
		}

		if ( $path !== 'go/whatsapp' ) {
			return;
		}

		// Build a minimal WP_REST_Request. The handler reads $_GET directly,
		// so this object carries only the route metadata — no param mapping needed.
		$request = new WP_REST_Request(
			'GET',
			'/' . Estandar_Leads_Whatsapp_Redirect::ROUTE_NAMESPACE
			. Estandar_Leads_Whatsapp_Redirect::ROUTE_PATH
		);

		Estandar_Leads_Whatsapp_Redirect::handle( $request );
		// handle() always exits via wp_redirect()+exit or wp_die().
		// Safety net in case future refactors change that.
		exit;
	}
}
