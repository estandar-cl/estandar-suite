<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter registry — the extensible catalog of destination transports.
 * Registering an Estandar\Core\Adapters\AdapterInterface makes it available to
 * the queue (drain), CLI, REST and the wizard. Built-ins: google_sheets,
 * excel_online. Overridable at runtime so tests can swap in mocks.
 */
class Adapters
{
	/** @var array<string,\Estandar\Core\Adapters\AdapterInterface> */
	private static $map = array();

	public static function register( \Estandar\Core\Adapters\AdapterInterface $adapter ): void
	{
		self::$map[ $adapter->key() ] = $adapter;
	}

	public static function get( string $key ): ?\Estandar\Core\Adapters\AdapterInterface
	{
		return self::$map[ $key ] ?? null;
	}

	public static function keys(): array
	{
		return array_keys( self::$map );
	}

	/** Catalog for pickers: key → {label, provider}. */
	public static function catalog(): array
	{
		$out = array();
		foreach ( self::$map as $key => $a ) {
			$out[ $key ] = array( 'label' => $a->label(), 'provider' => $a->provider() );
		}
		return $out;
	}

	/** Register the built-in adapters. */
	public static function init(): void
	{
		if ( ! self::$map ) {
			self::register( new \Estandar\Core\Adapters\GoogleSheets() );
			self::register( new \Estandar\Core\Adapters\GoogleSheetsLight() );
			self::register( new \Estandar\Core\Adapters\ExcelOnline() );
			self::register( new \Estandar\Core\Adapters\Simulated() );
		}
	}
}
