<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured health/diagnostics (the `estandar doctor` of v2). Pure checks +
 * DB checks; never mutates. Structured array output (WP-CLI + REST).
 */
class Health
{
	public static function snapshot(): array
	{
		$checks = array(
			'core_schema'     => self::check_option( Schema::OPTION, Schema::VERSION ),
			'legacy_present'  => AdoptionProbe::probe_legacy(),
			'php'             => PHP_VERSION,
			'wp'              => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'standalone',
			'secret_key_set'  => self::check_option( Secrets::KEY_OPTION, '' ),
			'plugins_v2'      => self::plugin_status(),
		);
		return array( 'ok' => true, 'checks' => $checks );
	}

	private static function check_option( string $option, string $expected_when_empty = '' ): bool
	{
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$value = (string) get_option( $option, '' );
		return $expected_when_empty === '' ? $value !== '' : $value === $expected_when_empty;
	}

	private static function plugin_status(): array
	{
		$out = array();
		foreach ( array( 'estandar-core', 'estandar-capture', 'estandar-brain', 'estandar-flow', 'estandar-insights' ) as $slug ) {
			$out[ $slug ] = function_exists( 'is_plugin_active' )
				? is_plugin_active( $slug . '/' . $slug . '.php' )
				: false;
		}
		return $out;
	}

	/** Render checks as plain text lines (for CLI). */
	public static function render_text( array $snapshot ): string
	{
		$lines = array();
		$lines[] = 'PHP ' . $snapshot['checks']['php'] . ' · WP ' . $snapshot['checks']['wp'];
		$lines[] = 'Schema core: ' . ( $snapshot['checks']['core_schema'] ? 'OK' : 'MISSING' );
		$lines[] = 'Instalación legada detectada: ' . ( $snapshot['checks']['legacy_present'] ? 'SI' : 'NO' );
		$lines[] = 'Secret key: ' . ( $snapshot['checks']['secret_key_set'] ? 'set' : 'unset' );
		foreach ( (array) $snapshot['checks']['plugins_v2'] as $slug => $active ) {
			$lines[] = $slug . ': ' . ( $active ? 'active' : 'inactive' );
		}
		return implode( "\n", $lines );
	}
}
