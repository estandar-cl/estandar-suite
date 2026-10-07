<?php
/**
 * Actualizaciones del hub y de sus plugins alojados (self-hosted, WP 5.8+).
 *
 * Usa el mecanismo nativo `update_plugins_{hostname}` del `Update URI`. El hub
 * responde por sí mismo y por todos los plugins registrados. Si `estandar-core`
 * está presente, se le delega (él ya implementa política/auto-update) para no
 * duplicar.
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inyecta actualizaciones desde el canal de Estandar.
 */
class Updates {

	/**
	 * Canal de releases.
	 */
	private const CHANNEL = 'https://updates.estandar.cl/staging/v2/';

	/**
	 * Hostname del `Update URI` (debe coincidir con el header de los plugins).
	 */
	private const HOSTNAME = 'updates.estandar.cl';

	/**
	 * Cache del manifiesto de versiones.
	 */
	private const CACHE_KEY = 'estandar_suite_updates_versions';

	/**
	 * TTL de la cache (segundos).
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Si estandar-core está activo, él ya gestiona las actualizaciones.
		if ( class_exists( '\Estandar\Core\Updates' ) ) {
			return;
		}

		add_filter( 'update_plugins_' . self::HOSTNAME, array( __CLASS__, 'check' ), 10, 4 );
	}

	/**
	 * Slugs cubiertos por el hub (él mismo + sus plugins alojados).
	 *
	 * @return array
	 */
	private static function slugs(): array {
		$slugs = array( ESTANDAR_SUITE_SLUG );

		foreach ( Registry::plugins() as $plugin ) {
			if ( ! empty( $plugin['id'] ) ) {
				$slugs[] = (string) $plugin['id'];
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Versiones del canal (con cache).
	 *
	 * @return array
	 */
	private static function versions(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::CHANNEL . 'versions.json', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
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
	 * Responde a WP si hay una versión mayor en el canal.
	 *
	 * @param mixed  $update      Update previo.
	 * @param array  $plugin_data Datos del plugin (incluye Version).
	 * @param string $plugin_file Ruta relativa del plugin.
	 * @param array  $locales     Locales.
	 * @return mixed
	 */
	public static function check( $update, array $plugin_data, string $plugin_file, array $locales ) {
		$slug = dirname( (string) $plugin_file );

		if ( ! in_array( $slug, self::slugs(), true ) ) {
			return $update;
		}

		$versions = self::versions();
		if ( ! isset( $versions[ $slug ] ) ) {
			return $update;
		}

		$remote = (string) $versions[ $slug ];
		$local  = (string) ( $plugin_data['Version'] ?? '0' );

		if ( ! version_compare( $remote, $local, '>' ) ) {
			return $update;
		}

		return array(
			'slug'    => $slug,
			'version' => $remote,
			'package' => self::CHANNEL . $slug . '.zip',
			'url'     => 'https://updates.estandar.cl/',
		);
	}
}
