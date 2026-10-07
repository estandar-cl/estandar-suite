<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {

	const SLUG    = 'estandar-noticias';
	const HOST    = 'updates.estandar.cl';
	const BASE    = 'https://updates.estandar.cl/staging/v2/';
	const TRANSIENT = 'estandar_noticias_versions';

	public static function init(): void {
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto' ), 10, 2 );
	}

	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( dirname( (string) $plugin_file ) !== self::SLUG ) {
			return $update;
		}
		$remote = self::remote_version();
		if ( $remote === '' || version_compare( $remote, ESTANDAR_NOTICIAS_VERSION, '<=' ) ) {
			return $update;
		}
		return (object) array(
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'new_version'  => $remote,
			'url'          => 'https://estandar.cl',
			'package'      => self::BASE . self::SLUG . '.zip',
			'tested'       => '7.0',
			'requires_php' => '8.0',
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	public static function auto( $update, $item ) {
		if ( isset( $item->plugin ) && dirname( (string) $item->plugin ) === self::SLUG ) {
			return true;
		}
		return $update;
	}

	public static function remote_version(): string {
		$versions = get_transient( self::TRANSIENT );
		if ( ! is_array( $versions ) ) {
			$res = wp_remote_get( self::BASE . 'versions.json', array( 'timeout' => 10 ) );
			$versions = array();
			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				if ( is_array( $decoded ) ) {
					$versions = $decoded;
				}
			}
			set_transient( self::TRANSIENT, $versions, 6 * HOUR_IN_SECONDS );
		}
		return isset( $versions[ self::SLUG ] ) ? (string) $versions[ self::SLUG ] : '';
	}

	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}
}
