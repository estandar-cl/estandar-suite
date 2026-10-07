<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dataset provider for Estandar Forms.
 *
 * ── Extension point (canonical) ──────────────────────────────────────────────
 *
 *   add_filter( 'estandar_forms_register_datasets', function( $provider ) {
 *       $provider->register( 'my_dataset', [ ... ] );
 *   } );
 *
 * ── Extension point (legacy — per-dataset filters) ───────────────────────────
 *
 *   add_filter( 'estandar_forms_dataset_my_dataset', fn() => [ ... ] );
 *
 *   Legacy filters still work but are superseded by the single hook above.
 *
 * ── API ───────────────────────────────────────────────────────────────────────
 *
 *   Estandar_Forms_Dataset_Provider::get( 'cl_regions' )           → array
 *   Estandar_Forms_Dataset_Provider::exists( 'cl_regions' )        → bool
 *   Estandar_Forms_Dataset_Provider::registered()                  → string[]
 *   Estandar_Forms_Dataset_Provider::register( 'name', $data )     → void
 */
class Estandar_Forms_Dataset_Provider {

	/** @var array<string, array> Runtime-registered and lazy-loaded datasets. */
	private static array $registry = [];

	/** @var bool Whether the bootstrap hook has already fired. */
	private static bool $bootstrapped = false;

	/**
	 * Built-in dataset slug → file path.
	 * Files are loaded lazily on first access.
	 *
	 * @var array<string, string>
	 */
	private static array $builtins = [
		'cl_regions'          => __DIR__ . '/chile/regions.php',
		'cl_regions_communes' => __DIR__ . '/chile/communes.php',
	];

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	/**
	 * Fire the single registration hook once.
	 * External plugins attach to 'estandar_forms_register_datasets' and call
	 * $provider->register() on the instance they receive.
	 */
	private static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		// Pass the provider as the filter value so external code calls:
		//   $provider->register( 'name', $data );
		apply_filters( 'estandar_forms_register_datasets', new self() );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return all entries for the named dataset.
	 *
	 * Each entry is an associative array. Built-in datasets return:
	 *   ['value' => string, 'label' => string]
	 * or, for dependent datasets:
	 *   ['value' => string, 'label' => string, 'parent' => string]
	 *
	 * @param  string $dataset Dataset slug, e.g. 'cl_regions'.
	 * @return array<int, array<string, string>>
	 */
	public static function get( string $dataset ): array {
		self::bootstrap();

		if ( isset( self::$registry[ $dataset ] ) ) {
			return self::$registry[ $dataset ];
		}

		if ( isset( self::$builtins[ $dataset ] ) ) {
			$data = require self::$builtins[ $dataset ];
			self::$registry[ $dataset ] = is_array( $data ) ? $data : [];
			return self::$registry[ $dataset ];
		}

		// Legacy: per-dataset filter (still supported, superseded by the single hook).
		$data = apply_filters( 'estandar_forms_dataset_' . $dataset, null );
		if ( is_array( $data ) ) {
			self::$registry[ $dataset ] = $data;
			return self::$registry[ $dataset ];
		}

		return [];
	}

	/**
	 * Register a dataset at runtime.
	 * Takes precedence over built-ins and legacy filters.
	 *
	 * @param string                              $name  Dataset slug.
	 * @param array<int, array<string, string>>   $data  Option entries.
	 */
	public static function register( string $name, array $data ): void {
		self::$registry[ $name ] = $data;
	}

	/**
	 * Return true if a dataset is available (built-in or registered).
	 *
	 * @param string $name Dataset slug.
	 */
	public static function exists( string $name ): bool {
		self::bootstrap();
		return isset( self::$registry[ $name ] ) || isset( self::$builtins[ $name ] );
	}

	/**
	 * Return the names of all known datasets (registered + built-ins).
	 * Built-ins are listed even when not yet lazy-loaded.
	 *
	 * @return string[]
	 */
	public static function registered(): array {
		self::bootstrap();
		return array_values(
			array_unique(
				array_merge( array_keys( self::$registry ), array_keys( self::$builtins ) )
			)
		);
	}
}
