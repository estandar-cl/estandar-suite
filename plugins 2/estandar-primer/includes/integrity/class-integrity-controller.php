<?php
/**
 * INTEGRITY_AUTOFIX_V1 — orchestrator + AJAX endpoints.
 *
 * Workflow per fix: lock → auto_fix() → re-run check() → invalidate the
 * cached audit summary → JSON response. Fix All iterates the registry's
 * deterministic order (severity DESC, id ASC), isolates each failing rule
 * and continues with the rest. The controller never contains rule-specific
 * logic — it only speaks the Estandar_Integrity_Rule contract.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-integrity-framework.php';
require_once __DIR__ . '/class-integrity-handle-fixer.php';
require_once __DIR__ . '/class-integrity-rules.php';
require_once __DIR__ . '/integrity-ui.php';

final class Estandar_Integrity_Controller {

	const NONCE = 'estandar_integrity';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'wp_ajax_estandar_integrity_fix', array( __CLASS__, 'ajax_fix' ) );
		add_action( 'wp_ajax_estandar_integrity_fix_all', array( __CLASS__, 'ajax_fix_all' ) );
		add_action( 'wp_ajax_estandar_integrity_recheck', array( __CLASS__, 'ajax_recheck' ) );
		add_action( 'wp_ajax_estandar_integrity_dry_run', array( __CLASS__, 'ajax_dry_run' ) );
	}

	/* ── AJAX endpoints ───────────────────────────────────────────────── */

	public static function ajax_fix(): void {
		self::guard();
		self::guard_autofix();

		$rule = self::requested_rule();
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => 'Regla no encontrada.' ), 404 );
		}
		if ( ! $rule->can_auto_fix() ) {
			wp_send_json_error( array( 'message' => 'La regla no expone corrección automática.' ), 400 );
		}

		if ( ! Estandar_Integrity_Lock::acquire() ) {
			wp_send_json_error( array( 'message' => 'Otra corrección está en curso. Reintentar en unos segundos.' ), 409 );
		}

		try {
			$report = self::execute_rule( $rule );
		} finally {
			Estandar_Integrity_Lock::release();
		}

		self::invalidate_summary();
		wp_send_json_success( $report );
	}

	public static function ajax_fix_all(): void {
		self::guard();
		self::guard_autofix();

		if ( ! Estandar_Integrity_Lock::acquire() ) {
			wp_send_json_error( array( 'message' => 'Otra corrección está en curso. Reintentar en unos segundos.' ), 409 );
		}

		$reports = array();
		try {
			// Registry order is already deterministic: severity DESC, id ASC.
			foreach ( Estandar_Integrity_Registry::build()->all() as $rule ) {
				if ( ! $rule->can_auto_fix() ) {
					continue;
				}
				// Abort only the failing rule; continue remaining fixes.
				try {
					$reports[] = self::execute_rule( $rule );
				} catch ( Throwable $e ) {
					$reports[] = array(
						'rule_id'  => $rule->id(),
						'plugin'   => $rule->plugin_label(),
						'label'    => $rule->label(),
						'fix'      => array( 'status' => Estandar_Integrity_Fix_Result::FAILED, 'message' => 'Excepción: ' . $e->getMessage(), 'changed' => array() ),
						'recheck'  => array( 'passing' => false, 'status_text' => 'No re-verificado (la corrección lanzó una excepción).', 'evidence' => array() ),
						'resolved' => false,
					);
				}
			}
		} finally {
			Estandar_Integrity_Lock::release();
		}

		self::invalidate_summary();
		wp_send_json_success( array( 'reports' => $reports ) );
	}

	public static function ajax_recheck(): void {
		self::guard();

		$rule = self::requested_rule();
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => 'Regla no encontrada.' ), 404 );
		}

		$check = $rule->check();
		if ( $check->passing() ) {
			self::invalidate_summary();
		}
		wp_send_json_success( array(
			'rule_id'  => $rule->id(),
			'recheck'  => $check->to_array(),
			'resolved' => $check->passing(),
		) );
	}

	public static function ajax_dry_run(): void {
		self::guard();
		self::guard_autofix();

		$rule = self::requested_rule();
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => 'Regla no encontrada.' ), 404 );
		}
		if ( ! in_array( 'dry_run', $rule->capabilities(), true ) || ! method_exists( $rule, 'dry_run' ) ) {
			wp_send_json_error( array( 'message' => 'La regla no soporta dry-run.' ), 400 );
		}

		wp_send_json_success( array(
			'rule_id' => $rule->id(),
			'plan'    => $rule->dry_run(),
		) );
	}

	/* ── orchestration ─────────────────────────────────────────────────── */

	/**
	 * autoFix → re-check → structured report. Single code path for the
	 * individual button and Fix All.
	 */
	private static function execute_rule( Estandar_Integrity_Rule $rule ): array {
		try {
			$fix = $rule->auto_fix();
		} catch ( Throwable $e ) {
			$fix = Estandar_Integrity_Fix_Result::failed( 'Excepción durante la corrección: ' . $e->getMessage() );
		}

		try {
			$recheck = $rule->check();
		} catch ( Throwable $e ) {
			$recheck = Estandar_Integrity_Audit_Result::fail( 'Re-verificación lanzó excepción: ' . $e->getMessage() );
		}

		return array(
			'rule_id'  => $rule->id(),
			'plugin'   => $rule->plugin_label(),
			'label'    => $rule->label(),
			'fix'      => $fix->to_array(),
			'recheck'  => $recheck->to_array(),
			'resolved' => $recheck->passing(),
		);
	}

	private static function requested_rule(): ?Estandar_Integrity_Rule {
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['rule_id'] ) ) : '';
		if ( $rule_id === '' ) {
			return null;
		}
		return Estandar_Integrity_Registry::build()->get( $rule_id );
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Remediation gate (defense in depth: rules also refuse via can_auto_fix()).
	 * Applies to fix / fix_all / dry_run. recheck is NEVER gated.
	 */
	private static function guard_autofix(): void {
		if ( ! estandar_integrity_autofix_enabled() ) {
			wp_send_json_error( array( 'message' => 'La corrección automática está deshabilitada en esta instalación (ESTANDAR_INTEGRITY_AUTOFIX).' ), 403 );
		}
	}

	/** The badge/dashboard read a 10-min cached summary — recompute after fixes. */
	private static function invalidate_summary(): void {
		if ( function_exists( 'estandar_core_diag_summary_transient_key' ) ) {
			delete_transient( estandar_core_diag_summary_transient_key() );
		}
	}
}
