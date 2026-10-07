<?php
/**
 * Estandar Diagnostics Engine — Phase 1 facade / bootstrap.
 *
 * Wires the engine, registers built-in checks, fires the extensibility hook
 * ('estandar_diagnostics_register') and registers the WP-CLI command.
 * CLI-only load path (required from core bootstrap under WP_CLI). Read-only.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-estandar-diagnostics-engine.php';
require_once __DIR__ . '/class-estandar-diagnostics-checks.php';
require_once __DIR__ . '/class-estandar-diagnostics-cli.php';

final class Estandar_Diagnostics {

	const HOOK_REGISTER = 'estandar_diagnostics_register';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'estandar doctor', 'Estandar_Diagnostics_CLI' );
		}
	}

	/**
	 * Build a registry with built-ins + plugin-contributed checks.
	 * Plugins subscribe to HOOK_REGISTER and call $registry->register( ... ).
	 */
	public static function build_registry(): Estandar_Diagnostic_Registry {
		$registry = new Estandar_Diagnostic_Registry();
		Estandar_Diagnostics_Builtins::register( $registry );
		if ( function_exists( 'do_action' ) ) {
			do_action( self::HOOK_REGISTER, $registry );
		}
		return $registry;
	}

	/**
	 * Select + run checks. Read-only.
	 *
	 * @param string $group '' or 'all'/'default' → every check; otherwise a group slug.
	 * @return array{results:Estandar_Diagnostic_Result[], summary:array, group:string}
	 */
	public static function run( string $group = '' ): array {
		$registry = self::build_registry();

		$normalized = strtolower( trim( $group ) );
		if ( $normalized === '' || $normalized === 'all' || $normalized === 'default' ) {
			$checks = $registry->all();
		} else {
			$checks = $registry->by_group( $normalized );
		}

		$runner  = new Estandar_Diagnostic_Runner();
		$results = $runner->run( $checks, new Estandar_Diagnostic_Context() );

		return array(
			'results' => $results,
			'summary' => Estandar_Diagnostic_Runner::summary( $results ),
			'group'   => $normalized,
		);
	}

	/** Known groups (built-ins + contributed). */
	public static function groups(): array {
		return self::build_registry()->groups();
	}
}
