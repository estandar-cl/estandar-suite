<?php
/**
 * Estandar Diagnostics Engine — Phase 1 (read-only).
 *
 * Core engine: Check contract, Result, Context, Registry, Runner and Reporters.
 * No client logic. No repair. CLI-only load path.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a single diagnostic check.
 * Every check is deterministic and read-only inside run().
 */
interface Estandar_Diagnostic_Check {
	/** Stable, namespaced id: '<plugin>.<area>.<name>'. */
	public function id(): string;
	/** Group slug, e.g. 'updates', 'plugins'. */
	public function group(): string;
	/** Human label. */
	public function label(): string;
	/** Whether a repair action exists (Phase 1 never executes it). */
	public function is_repairable(): bool;
	/** Evaluate. MUST NOT mutate state. */
	public function run( Estandar_Diagnostic_Context $context ): Estandar_Diagnostic_Result;
}

/**
 * Immutable result of one check.
 */
final class Estandar_Diagnostic_Result {

	const PASS    = 'PASS';
	const INFO    = 'INFO';
	const WARNING = 'WARNING';
	const FAIL    = 'FAIL';
	const FATAL   = 'FATAL';

	/** Severity ordering (higher = worse). */
	const RANK = array( self::PASS => 0, self::INFO => 1, self::WARNING => 2, self::FAIL => 3, self::FATAL => 4 );

	private string $check_id;
	private string $group;
	private string $label;
	private string $severity;
	private string $message;
	private array $evidence;
	private string $remediation;
	private bool $repair_available;

	private function __construct(
		string $check_id, string $group, string $label, string $severity,
		string $message, array $evidence, string $remediation, bool $repair_available
	) {
		$this->check_id         = $check_id;
		$this->group            = $group;
		$this->label            = $label;
		$this->severity         = $severity;
		$this->message          = $message;
		$this->evidence         = $evidence;
		$this->remediation      = $remediation;
		$this->repair_available = $repair_available;
	}

	/** Build a result from the check that produced it. */
	public static function make(
		Estandar_Diagnostic_Check $check, string $severity, string $message,
		array $evidence = array(), string $remediation = ''
	): self {
		$severity = isset( self::RANK[ $severity ] ) ? $severity : self::INFO;
		return new self(
			$check->id(), $check->group(), $check->label(), $severity,
			$message, $evidence, $remediation, $check->is_repairable()
		);
	}

	public static function pass( Estandar_Diagnostic_Check $c, string $m, array $e = array() ): self {
		return self::make( $c, self::PASS, $m, $e );
	}
	public static function info( Estandar_Diagnostic_Check $c, string $m, array $e = array() ): self {
		return self::make( $c, self::INFO, $m, $e );
	}
	public static function warning( Estandar_Diagnostic_Check $c, string $m, array $e = array(), string $r = '' ): self {
		return self::make( $c, self::WARNING, $m, $e, $r );
	}
	public static function fail( Estandar_Diagnostic_Check $c, string $m, array $e = array(), string $r = '' ): self {
		return self::make( $c, self::FAIL, $m, $e, $r );
	}
	public static function fatal( Estandar_Diagnostic_Check $c, string $m, array $e = array(), string $r = '' ): self {
		return self::make( $c, self::FATAL, $m, $e, $r );
	}

	public function check_id(): string { return $this->check_id; }
	public function group(): string { return $this->group; }
	public function label(): string { return $this->label; }
	public function severity(): string { return $this->severity; }
	public function message(): string { return $this->message; }
	public function evidence(): array { return $this->evidence; }
	public function remediation(): string { return $this->remediation; }
	public function repair_available(): bool { return $this->repair_available; }
	public function rank(): int { return self::RANK[ $this->severity ] ?? 0; }

	public function to_array(): array {
		return array(
			'check_id'         => $this->check_id,
			'group'            => $this->group,
			'label'            => $this->label,
			'severity'         => $this->severity,
			'message'          => $this->message,
			'evidence'         => $this->evidence,
			'remediation'      => $this->remediation,
			'repair_available' => $this->repair_available,
		);
	}
}

/**
 * Read-only capability object handed to every check. The ONLY channel through
 * which a check touches installation state. No writes, ever.
 */
final class Estandar_Diagnostic_Context {

	/** @var array<string,array>|null */
	private ?array $plugins = null;

	public function is_core_active(): bool {
		return defined( 'ESTANDAR_CORE_MU_VERSION' )
			|| ( function_exists( 'estandar_core_mu_is_active' ) && estandar_core_mu_is_active() );
	}

	public function core_version(): string {
		return defined( 'ESTANDAR_CORE_MU_VERSION' ) ? (string) ESTANDAR_CORE_MU_VERSION : '';
	}

	public function wp_version(): string {
		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
	}

	/** @return array<string,array> file => plugin data */
	public function plugins(): array {
		if ( $this->plugins === null ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		}
		return $this->plugins;
	}

	/** Estandar plugins only (folder slug prefixed 'estandar'). @return array<string,array> */
	public function estandar_plugins(): array {
		$out = array();
		foreach ( $this->plugins() as $file => $data ) {
			if ( strpos( (string) $file, 'estandar' ) === 0 ) {
				$out[ $file ] = $data;
			}
		}
		return $out;
	}

	public function is_plugin_active( string $file ): bool {
		return function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $file ) : false;
	}

	public function has_filter( string $tag ) {
		return function_exists( 'has_filter' ) ? has_filter( $tag ) : false;
	}

	/** @return mixed */
	public function get_option( string $key, $default = false ) {
		return function_exists( 'get_option' ) ? get_option( $key, $default ) : $default;
	}

	/** @return mixed */
	public function get_transient( string $key ) {
		return function_exists( 'get_transient' ) ? get_transient( $key ) : false;
	}

	/** @return mixed */
	public function get_site_transient( string $key ) {
		return function_exists( 'get_site_transient' ) ? get_site_transient( $key ) : false;
	}

	/**
	 * Idempotent HTTP GET. Read-only.
	 * @return array{code:int, body:string, error:string}
	 */
	public function http_get( string $url, int $timeout = 10 ): array {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return array( 'code' => 0, 'body' => '', 'error' => 'wp_remote_get unavailable' );
		}
		$r = wp_remote_get( $url, array( 'timeout' => $timeout ) );
		if ( is_wp_error( $r ) ) {
			return array( 'code' => 0, 'body' => '', 'error' => $r->get_error_message() );
		}
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $r ),
			'body'  => (string) wp_remote_retrieve_body( $r ),
			'error' => '',
		);
	}
}

/**
 * Registry of checks. Populated by the core built-ins + the
 * 'estandar_diagnostics_register' hook. Dedupes by id.
 */
final class Estandar_Diagnostic_Registry {

	/** @var array<string,Estandar_Diagnostic_Check> */
	private array $checks = array();

	public function register( Estandar_Diagnostic_Check $check ): void {
		$id = $check->id();
		if ( $id === '' ) {
			return;
		}
		$this->checks[ $id ] = $check; // last wins; dedupe by id
	}

	/** @return Estandar_Diagnostic_Check[] */
	public function all(): array {
		$checks = array_values( $this->checks );
		usort( $checks, static function ( $a, $b ) {
			return strcmp( $a->id(), $b->id() ); // deterministic order
		} );
		return $checks;
	}

	/** @return Estandar_Diagnostic_Check[] */
	public function by_group( string $group ): array {
		return array_values( array_filter( $this->all(), static function ( $c ) use ( $group ) {
			return $c->group() === $group;
		} ) );
	}

	/** @return string[] */
	public function groups(): array {
		$groups = array();
		foreach ( $this->checks as $c ) {
			$groups[ $c->group() ] = true;
		}
		$out = array_keys( $groups );
		sort( $out );
		return $out;
	}
}

/**
 * Runs checks with per-check failure isolation.
 */
final class Estandar_Diagnostic_Runner {

	/**
	 * @param Estandar_Diagnostic_Check[] $checks
	 * @return Estandar_Diagnostic_Result[]
	 */
	public function run( array $checks, Estandar_Diagnostic_Context $context ): array {
		$results = array();
		foreach ( $checks as $check ) {
			if ( ! $check instanceof Estandar_Diagnostic_Check ) {
				continue;
			}
			try {
				$result = $check->run( $context );
				if ( ! $result instanceof Estandar_Diagnostic_Result ) {
					$result = Estandar_Diagnostic_Result::fatal( $check, 'Check returned an invalid result.' );
				}
			} catch ( Throwable $e ) {
				$result = Estandar_Diagnostic_Result::fatal(
					$check,
					'Check threw: ' . $e->getMessage(),
					array( 'exception' => get_class( $e ) )
				);
			}
			$results[] = $result;
		}
		return $results;
	}

	/** @param Estandar_Diagnostic_Result[] $results */
	public static function max_severity( array $results ): string {
		$max = Estandar_Diagnostic_Result::PASS;
		foreach ( $results as $r ) {
			if ( $r->rank() > ( Estandar_Diagnostic_Result::RANK[ $max ] ?? 0 ) ) {
				$max = $r->severity();
			}
		}
		return $max;
	}

	/** @param Estandar_Diagnostic_Result[] $results @return array<string,int> */
	public static function summary( array $results ): array {
		$counts = array( 'PASS' => 0, 'INFO' => 0, 'WARNING' => 0, 'FAIL' => 0, 'FATAL' => 0 );
		foreach ( $results as $r ) {
			$counts[ $r->severity() ] = ( $counts[ $r->severity() ] ?? 0 ) + 1;
		}
		$counts['max_severity'] = self::max_severity( $results );
		$counts['total']        = count( $results );
		return $counts;
	}
}

/** Reporter contract — formats results; never alters severity/data. */
interface Estandar_Diagnostic_Reporter {
	/**
	 * @param Estandar_Diagnostic_Result[] $results
	 * @param array<string,int|string>     $summary
	 */
	public function report( array $results, array $summary ): string;
}

/** Human-oriented console reporter. */
final class Estandar_Diagnostic_Console_Reporter implements Estandar_Diagnostic_Reporter {
	public function report( array $results, array $summary ): string {
		$lines = array();
		foreach ( $results as $r ) {
			$lines[] = '[' . str_pad( $r->severity(), 7 ) . '] ' . $r->check_id() . ' — ' . $r->message();
			if ( $r->rank() >= Estandar_Diagnostic_Result::RANK[ Estandar_Diagnostic_Result::WARNING ] ) {
				foreach ( $r->evidence() as $k => $v ) {
					$lines[] = '           · ' . $k . ': ' . self::scalar( $v );
				}
				if ( $r->remediation() !== '' ) {
					$lines[] = '           → ' . $r->remediation();
				}
			}
		}
		$lines[] = '';
		$lines[] = sprintf(
			'SUMMARY: PASS=%d INFO=%d WARNING=%d FAIL=%d FATAL=%d | max=%s',
			$summary['PASS'] ?? 0, $summary['INFO'] ?? 0, $summary['WARNING'] ?? 0,
			$summary['FAIL'] ?? 0, $summary['FATAL'] ?? 0, $summary['max_severity'] ?? 'PASS'
		);
		return implode( "\n", $lines ) . "\n";
	}

	/** @param mixed $v */
	private static function scalar( $v ): string {
		if ( is_bool( $v ) ) { return $v ? 'true' : 'false'; }
		if ( is_scalar( $v ) || $v === null ) { return (string) $v; }
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $v ) : json_encode( $v );
	}
}

/** Machine-oriented JSON reporter. */
final class Estandar_Diagnostic_Json_Reporter implements Estandar_Diagnostic_Reporter {
	public function report( array $results, array $summary ): string {
		$payload = array(
			'summary' => $summary,
			'results' => array_map( static function ( Estandar_Diagnostic_Result $r ) {
				return $r->to_array();
			}, $results ),
		);
		return function_exists( 'wp_json_encode' )
			? (string) wp_json_encode( $payload, JSON_PRETTY_PRINT )
			: json_encode( $payload, JSON_PRETTY_PRINT );
	}
}
