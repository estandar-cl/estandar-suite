<?php
/**
 * Estandar Suite v2 — Updates (canal staging)
 *
 * Self-hosted plugin updates via WordPress native Update URI (WP 5.8+).
 * Canal aislado: https://updates.estandar.cl/staging/v2/ — nunca lee el feed
 * legacy de producción (https://updates.estandar.cl/versions.json).
 */

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updates {

	const BASE_URL  = 'https://updates.estandar.cl/staging/v2/';
	const HOSTNAME  = 'updates.estandar.cl';
	const CACHE_KEY = 'estandar_suite_updates_staging_versions';
	const CACHE_TTL = HOUR_IN_SECONDS;

	const SUITE_SLUGS = array( 'estandar-core', 'estandar-capture', 'estandar-brain', 'estandar-flow', 'estandar-insights' );

	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'update_plugins_' . self::HOSTNAME, array( __CLASS__, 'check_update' ), 10, 4 );
	}

	/**
	 * WP 5.8+ per-plugin filter: fired when the plugin Update URI hostname matches.
	 * Only answers for Suite v2 slugs so a mixed install is never cross-fed.
	 *
	 * @return array|false Update data or false (no update).
	 */
	public static function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		$slug = dirname( $plugin_file );
		if ( ! in_array( $slug, self::SUITE_SLUGS, true ) ) {
			return $update;
		}

		$versions = self::fetch_versions();
		if ( ! isset( $versions[ $slug ] ) ) {
			return $update;
		}

		$remote_version = $versions[ $slug ];
		$local_version  = $plugin_data['Version'] ?? '0';

		if ( ! version_compare( $remote_version, $local_version, '>' ) ) {
			return $update;
		}

		return array(
			'slug'    => $slug,
			'version' => $remote_version,
			'package' => self::BASE_URL . $slug . '.zip',
		);
	}

	private static function fetch_versions(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::BASE_URL . 'versions.json', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}
}
