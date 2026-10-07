<?php
/**
 * INTEGRITY_AUTOFIX_V1 — Fix Provider framework (contracts + registry + lock).
 *
 * Every integrity rule owns its own detection, remediation and verification.
 * The Dashboard renders rules generically from the registry: no switch/case by
 * rule type, no rule-specific code outside the rule itself.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'estandar_integrity_autofix_enabled' ) ) {
	/**
	 * Security gate for INTEGRITY_AUTOFIX_V1 — single source of truth.
	 *
	 * Remediation (auto_fix / fix_all / dry_run) is DISABLED unless the
	 * installation explicitly opts in via wp-config.php:
	 *
	 *     define( 'ESTANDAR_INTEGRITY_AUTOFIX', true );
	 *
	 * Constant-only by design: an attacker with DB access could flip a
	 * wp_option, but not a constant. Detection (check/recheck) and the
	 * read-only auditor cards are NOT gated.
	 */
	function estandar_integrity_autofix_enabled(): bool {
		return defined( 'ESTANDAR_INTEGRITY_AUTOFIX' ) && ESTANDAR_INTEGRITY_AUTOFIX === true;
	}
}

/**
 * Contract for a single integrity rule (Fix Provider).
 *
 * check() re-evaluates ONLY this rule's subject — deterministic and cheap
 * enough to run immediately after auto_fix() for verification.
 * auto_fix() MUST be idempotent: running it on a compliant subject returns
 * ALREADY_COMPLIANT and changes nothing.
 */
interface Estandar_Integrity_Rule {

	/** Stable unique id: '<rule>.<subject>', e.g. 'r5.estandar-forms'. */
	public function id(): string;

	/** Canonical rule code ('R1'…'R6', 'PREFIX', 'IDENTITY', 'DEPLOY'). */
	public function rule_code(): string;

	/** HIGH | MEDIUM | LOW. */
	public function severity(): string;

	/** Human subject label (plugin name or area). */
	public function plugin_label(): string;

	/** Short finding title. */
	public function label(): string;

	/** Operator-facing risk description. */
	public function description(): string;

	/** Technical explanation (what exactly was detected / how it is fixed). */
	public function technical(): string;

	/** Optional URL for manual resolution ('' when none). */
	public function details_url(): string;

	/** Re-evaluate the rule now. Read-only. */
	public function check(): Estandar_Integrity_Audit_Result;

	public function can_auto_fix(): bool;

	/** Execute remediation. Never throws; failures are FixResult::FAILED. */
	public function auto_fix(): Estandar_Integrity_Fix_Result;

	/**
	 * Optional capabilities the UI may expose. Known: 'auto_fix', 'dry_run',
	 * 're_run', 'details'. Future capabilities render without UI changes.
	 *
	 * @return string[]
	 */
	public function capabilities(): array;
}

/** Immutable detection result. */
final class Estandar_Integrity_Audit_Result {

	private bool $passing;
	private string $status_text;
	private array $evidence;

	private function __construct( bool $passing, string $status_text, array $evidence ) {
		$this->passing     = $passing;
		$this->status_text = $status_text;
		$this->evidence    = $evidence;
	}

	public static function pass( string $status_text = 'Sin incidencias.', array $evidence = array() ): self {
		return new self( true, $status_text, $evidence );
	}

	public static function fail( string $status_text, array $evidence = array() ): self {
		return new self( false, $status_text, $evidence );
	}

	public function passing(): bool { return $this->passing; }
	public function status_text(): string { return $this->status_text; }
	public function evidence(): array { return $this->evidence; }

	public function to_array(): array {
		return array(
			'passing'     => $this->passing,
			'status_text' => $this->status_text,
			'evidence'    => $this->evidence,
		);
	}
}

/** Immutable remediation result. */
final class Estandar_Integrity_Fix_Result {

	const FIXED             = 'fixed';
	const FAILED            = 'failed';
	const ALREADY_COMPLIANT = 'already_compliant';

	private string $status;
	private string $message;
	private array $changed;

	private function __construct( string $status, string $message, array $changed ) {
		$this->status  = $status;
		$this->message = $message;
		$this->changed = $changed;
	}

	public static function fixed( string $message, array $changed = array() ): self {
		return new self( self::FIXED, $message, $changed );
	}

	public static function failed( string $message ): self {
		return new self( self::FAILED, $message, array() );
	}

	public static function already_compliant( string $message = 'Ya estaba conforme.' ): self {
		return new self( self::ALREADY_COMPLIANT, $message, array() );
	}

	public function status(): string { return $this->status; }
	public function message(): string { return $this->message; }
	public function changed(): array { return $this->changed; }

	public function to_array(): array {
		return array(
			'status'  => $this->status,
			'message' => $this->message,
			'changed' => $this->changed,
		);
	}
}

/**
 * Base rule: sane defaults so future rules only override what they need.
 * Backward compatible: a rule without remediation is just detection.
 */
abstract class Estandar_Integrity_Base_Rule implements Estandar_Integrity_Rule {

	public function details_url(): string { return ''; }

	public function can_auto_fix(): bool { return false; }

	public function auto_fix(): Estandar_Integrity_Fix_Result {
		return Estandar_Integrity_Fix_Result::failed( 'Esta regla no expone corrección automática.' );
	}

	public function capabilities(): array {
		$caps = array( 're_run', 'details' );
		if ( $this->can_auto_fix() ) {
			$caps[] = 'auto_fix';
		}
		return $caps;
	}
}

/**
 * Registry: builds the deterministic rule set for this installation.
 * Built-ins first, then the extensibility hook — future rules plug in without
 * any Dashboard change.
 */
final class Estandar_Integrity_Registry {

	const HOOK_REGISTER = 'estandar_integrity_register';

	/** @var array<string,Estandar_Integrity_Rule> */
	private array $rules = array();

	public function register( Estandar_Integrity_Rule $rule ): void {
		$id = $rule->id();
		if ( $id === '' ) {
			return;
		}
		$this->rules[ $id ] = $rule; // dedupe by id, last wins
	}

	/** @return Estandar_Integrity_Rule[] severity DESC, id ASC — stable. */
	public function all(): array {
		$rules = array_values( $this->rules );
		$rank  = array( 'HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2 );
		usort( $rules, static function ( Estandar_Integrity_Rule $a, Estandar_Integrity_Rule $b ) use ( $rank ): int {
			$sa = $rank[ $a->severity() ] ?? 3;
			$sb = $rank[ $b->severity() ] ?? 3;
			if ( $sa !== $sb ) {
				return $sa <=> $sb;
			}
			return strcmp( $a->id(), $b->id() );
		} );
		return $rules;
	}

	public function get( string $id ): ?Estandar_Integrity_Rule {
		return $this->rules[ $id ] ?? null;
	}

	/**
	 * Build the live registry: built-in rules derived from the current audit
	 * summary + contributed rules via the hook.
	 */
	public static function build(): self {
		$registry = new self();
		if ( class_exists( 'Estandar_Integrity_Builtin_Rules' ) ) {
			Estandar_Integrity_Builtin_Rules::register( $registry );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( self::HOOK_REGISTER, $registry );
		}
		return $registry;
	}
}

/**
 * Execution lock — avoids concurrent fixes. Option-based (add_option is an
 * INSERT: effectively atomic for this console's single-admin use case).
 */
final class Estandar_Integrity_Lock {

	const OPTION    = 'estandar_integrity_fix_lock';
	const STALE_TTL = 120; // seconds

	public static function acquire(): bool {
		$existing = get_option( self::OPTION, 0 );
		if ( $existing && ( time() - (int) $existing ) > self::STALE_TTL ) {
			delete_option( self::OPTION ); // stale lock from a dead request
		}
		return (bool) add_option( self::OPTION, time(), '', 'no' );
	}

	public static function release(): void {
		delete_option( self::OPTION );
	}
}
