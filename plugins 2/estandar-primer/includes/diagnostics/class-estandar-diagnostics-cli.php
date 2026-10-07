<?php
/**
 * Estandar Diagnostics Engine — Phase 1 WP-CLI command.
 *
 * Public CLI: `estandar doctor [<group>] [--format=] [--severity-min=] [--exit-code]`
 * Read-only. No repair (Phase 3).
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnose an Estandar installation (read-only).
 */
final class Estandar_Diagnostics_CLI {

	/**
	 * Run diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [<group>]
	 * : Group to run (e.g. updates, plugins). Omit, 'all' or 'default' = every check.
	 *
	 * [--format=<format>]
	 * : Output format. One of: console, json. Default: console.
	 *
	 * [--severity-min=<level>]
	 * : Only show results at or above this severity (PASS|INFO|WARNING|FAIL|FATAL).
	 *
	 * [--exit-code]
	 * : Set process exit code from the max severity (WARNING/FAIL=1, FATAL=2).
	 *
	 * ## EXAMPLES
	 *
	 *     wp estandar doctor
	 *     wp estandar doctor updates
	 *     wp estandar doctor plugins --format=json
	 *     wp estandar doctor all --exit-code
	 *
	 * @when after_wp_load
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function __invoke( $args, $assoc ) {
		$group = isset( $args[0] ) ? (string) $args[0] : '';
		$run   = Estandar_Diagnostics::run( $group );

		$results = $this->filter_min( $run['results'], (string) ( $assoc['severity-min'] ?? '' ) );

		$format   = strtolower( (string) ( $assoc['format'] ?? 'console' ) );
		$reporter = ( $format === 'json' )
			? new Estandar_Diagnostic_Json_Reporter()
			: new Estandar_Diagnostic_Console_Reporter();

		$output = $reporter->report( $results, $run['summary'] );

		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::line( $output );
		} else {
			echo $output; // fallback (non-CLI harness)
		}

		if ( isset( $assoc['exit-code'] ) && class_exists( 'WP_CLI' ) ) {
			WP_CLI::halt( $this->exit_code( (string) ( $run['summary']['max_severity'] ?? 'PASS' ) ) );
		}
	}

	/**
	 * @param Estandar_Diagnostic_Result[] $results
	 * @return Estandar_Diagnostic_Result[]
	 */
	private function filter_min( array $results, string $min ): array {
		$min = strtoupper( trim( $min ) );
		if ( ! isset( Estandar_Diagnostic_Result::RANK[ $min ] ) ) {
			return $results;
		}
		$floor = Estandar_Diagnostic_Result::RANK[ $min ];
		return array_values( array_filter( $results, static function ( Estandar_Diagnostic_Result $r ) use ( $floor ) {
			return $r->rank() >= $floor;
		} ) );
	}

	private function exit_code( string $max_severity ): int {
		switch ( $max_severity ) {
			case Estandar_Diagnostic_Result::FATAL:
				return 2;
			case Estandar_Diagnostic_Result::FAIL:
			case Estandar_Diagnostic_Result::WARNING:
				return 1;
			default:
				return 0;
		}
	}
}
