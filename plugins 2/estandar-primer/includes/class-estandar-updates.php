<?php
/**
 * Estandar Updates V1
 *
 * Self-hosted plugin updates via WordPress native Update URI (WP 5.8+).
 * Remote: https://updates.estandar.cl/versions.json + ZIP files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Updates {

	const BASE_URL  = 'https://updates.estandar.cl/';
	const HOSTNAME  = 'updates.estandar.cl';
	const CACHE_KEY = 'estandar_updates_remote_versions';
	const CACHE_TTL = HOUR_IN_SECONDS;

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'update_plugins_' . self::HOSTNAME, array( __CLASS__, 'check_update' ), 10, 4 );
	}

	/**
	 * WP 5.8+ per-plugin filter: fired when Update URI hostname matches.
	 *
	 * @return array|false Update data or false (no update).
	 */
	public static function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		$slug = self::slug_from_file( $plugin_file );
		if ( $slug === '' ) {
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

	/**
	 * Fetch remote versions.json with transient cache.
	 *
	 * @return array<string, string> slug => version
	 */
	private static function fetch_versions(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::BASE_URL . 'versions.json', array(
			'timeout' => 10,
		) );

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

	/**
	 * "estandar-leads/estandar-leads.php" → "estandar-leads"
	 */
	private static function slug_from_file( string $plugin_file ): string {
		$dir = dirname( $plugin_file );
		if ( $dir === '.' || strpos( $dir, 'estandar' ) !== 0 ) {
			return '';
		}
		return $dir;
	}
}
