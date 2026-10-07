<?php
/**
 * INTEGRITY_AUTOFIX_V1 — built-in rules.
 *
 * Detection is delegated to the auditor's existing scan predicates
 * (estandar_core_scan_plugin_files, estandar_core_diag_is_ambiguous_handle,
 * estandar_core_diag_handle_prefix_ok, ...) so this module adds remediation
 * and per-rule verification WITHOUT duplicating detection logic. Instances
 * are created only for currently-failing findings (from the same cached
 * summary that powers the menu badge — one dataset, one truth).
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Per-request memoized plugin scan — rules for one plugin share one scan. */
final class Estandar_Integrity_Scan_Cache {

	/** @var array<string,array> */
	private static array $scans = array();

	public static function scan( string $plugin_file ): array {
		if ( ! isset( self::$scans[ $plugin_file ] ) ) {
			if ( ! function_exists( 'estandar_core_scan_plugin_files' ) || ! function_exists( 'estandar_core_diag_collect_plugin_php_files' ) ) {
				return array();
			}
			self::$scans[ $plugin_file ] = estandar_core_scan_plugin_files(
				estandar_core_diag_collect_plugin_php_files( $plugin_file )
			);
		}
		return self::$scans[ $plugin_file ];
	}

	public static function forget( string $plugin_file ): void {
		unset( self::$scans[ $plugin_file ] );
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* R5 — generic/ambiguous handles (AUTO-FIXABLE)                              */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Rule_Generic_Handles extends Estandar_Integrity_Base_Rule {

	private string $plugin_file;
	private string $plugin_name;

	public function __construct( string $plugin_file, string $plugin_name ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_name = $plugin_name;
	}

	public function id(): string { return 'r5.' . sanitize_key( dirname( $this->plugin_file ) ); }
	public function rule_code(): string { return 'R5'; }
	public function severity(): string { return 'MEDIUM'; }
	public function plugin_label(): string { return $this->plugin_name; }
	public function label(): string { return 'Handles JS/CSS genéricos'; }

	public function description(): string {
		return 'Posibles colisiones con otros plugins.';
	}

	public function technical(): string {
		return 'Handles genéricos o ambiguos en wp_register/enqueue. La corrección renombra cada handle con el prefijo del plugin (registro + arrays de dependencias), preservando el orden de carga, con respaldo previo y verificación de sintaxis por archivo.';
	}

	public function can_auto_fix(): bool { return estandar_integrity_autofix_enabled(); }

	public function capabilities(): array {
		// Gate OFF → read-only card: no auto_fix, no dry_run (depends on the fixer).
		if ( ! estandar_integrity_autofix_enabled() ) {
			return array( 're_run', 'details' );
		}
		return array( 're_run', 'details', 'auto_fix', 'dry_run' );
	}

	/** @return string[] deterministic (sorted) ambiguous handles. */
	public function ambiguous_handles(): array {
		$scan = Estandar_Integrity_Scan_Cache::scan( $this->plugin_file );
		$out  = array();
		foreach ( (array) ( $scan['handles'] ?? array() ) as $h ) {
			$h = (string) $h;
			if ( $h !== '' && function_exists( 'estandar_core_diag_is_ambiguous_handle' ) && estandar_core_diag_is_ambiguous_handle( $h ) ) {
				$out[] = $h;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/** @return array<string,string> old => new, deterministic. */
	public function renames(): array {
		$slug    = dirname( $this->plugin_file );
		$renames = array();
		foreach ( $this->ambiguous_handles() as $old ) {
			$new = Estandar_Integrity_Handle_Fixer::derive_new_handle( $old, $slug );
			if ( $new !== '' ) {
				$renames[ $old ] = $new;
			}
		}
		ksort( $renames );
		return $renames;
	}

	public function check(): Estandar_Integrity_Audit_Result {
		$ambiguous = $this->ambiguous_handles();
		if ( empty( $ambiguous ) ) {
			return Estandar_Integrity_Audit_Result::pass( 'Sin handles genéricos.' );
		}
		return Estandar_Integrity_Audit_Result::fail(
			count( $ambiguous ) . ' handle(s) requieren corrección.',
			array( 'handles' => $ambiguous )
		);
	}

	public function auto_fix(): Estandar_Integrity_Fix_Result {
		$renames = $this->renames();
		if ( empty( $renames ) && empty( $this->ambiguous_handles() ) ) {
			return Estandar_Integrity_Fix_Result::already_compliant();
		}
		$result = Estandar_Integrity_Handle_Fixer::apply( $this->plugin_file, $renames );
		Estandar_Integrity_Scan_Cache::forget( $this->plugin_file );
		return $result;
	}

	/** Dry-run plan (View Diff / Dry Run capability). */
	public function dry_run(): array {
		return Estandar_Integrity_Handle_Fixer::plan( $this->plugin_file, $this->renames() );
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* PREFIX — handles without the plugin prefix (AUTO-FIXABLE, LOW/heuristic)   */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Rule_Handle_Prefix extends Estandar_Integrity_Base_Rule {

	private string $plugin_file;
	private string $plugin_name;
	private array $plugin_data;

	public function __construct( string $plugin_file, string $plugin_name, array $plugin_data ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_name = $plugin_name;
		$this->plugin_data = $plugin_data;
	}

	public function id(): string { return 'prefix.' . sanitize_key( dirname( $this->plugin_file ) ); }
	public function rule_code(): string { return 'PREFIX'; }
	public function severity(): string { return 'LOW'; }
	public function plugin_label(): string { return $this->plugin_name; }
	public function label(): string { return 'Handles sin prefijo del plugin'; }

	public function description(): string {
		return 'Riesgo heurístico de colisión futura (severidad baja).';
	}

	public function technical(): string {
		return 'Handles propios sin el prefijo esperado del plugin. Misma corrección que R5: renombrado contextual con respaldo y verificación, omitiendo handles referenciados por otros plugins.';
	}

	public function can_auto_fix(): bool { return estandar_integrity_autofix_enabled(); }

	public function capabilities(): array {
		// Gate OFF → read-only card: no auto_fix, no dry_run (depends on the fixer).
		if ( ! estandar_integrity_autofix_enabled() ) {
			return array( 're_run', 'details' );
		}
		return array( 're_run', 'details', 'auto_fix', 'dry_run' );
	}

	/** Mirrors the summary's bad_prefix predicate exactly. @return string[] */
	public function bad_prefix_handles(): array {
		if ( ! function_exists( 'estandar_core_diag_expected_handle_prefixes' ) || ! function_exists( 'estandar_core_diag_handle_prefix_ok' ) ) {
			return array();
		}
		$scan     = Estandar_Integrity_Scan_Cache::scan( $this->plugin_file );
		$misuse   = (array) ( $scan['shared_core_handle_misuse'] ?? array() );
		$prefixes = estandar_core_diag_expected_handle_prefixes( $this->plugin_file, $this->plugin_data );
		$is_core  = function_exists( 'estandar_core_diag_is_core_plugin' ) && estandar_core_diag_is_core_plugin( $this->plugin_file, $this->plugin_data );

		$bad = array();
		foreach ( (array) ( $scan['handles'] ?? array() ) as $h ) {
			$h = (string) $h;
			if ( '' === $h ) {
				continue;
			}
			if ( function_exists( 'estandar_core_diag_is_shared_core_handle' ) && estandar_core_diag_is_shared_core_handle( $h ) && ( $is_core || ! in_array( $h, $misuse, true ) ) ) {
				continue;
			}
			if ( ! estandar_core_diag_handle_prefix_ok( $h, $this->plugin_file, $prefixes ) ) {
				$bad[] = $h;
			}
		}
		$bad = array_values( array_unique( $bad ) );
		sort( $bad );
		return $bad;
	}

	public function check(): Estandar_Integrity_Audit_Result {
		$bad = $this->bad_prefix_handles();
		if ( empty( $bad ) ) {
			return Estandar_Integrity_Audit_Result::pass( 'Todos los handles llevan prefijo del plugin.' );
		}
		return Estandar_Integrity_Audit_Result::fail(
			count( $bad ) . ' handle(s) sin prefijo.',
			array( 'handles' => $bad )
		);
	}

	public function auto_fix(): Estandar_Integrity_Fix_Result {
		$slug    = dirname( $this->plugin_file );
		$renames = array();
		foreach ( $this->bad_prefix_handles() as $old ) {
			$new = Estandar_Integrity_Handle_Fixer::derive_new_handle( $old, $slug );
			if ( $new !== '' ) {
				$renames[ $old ] = $new;
			}
		}
		ksort( $renames );
		if ( empty( $renames ) && empty( $this->bad_prefix_handles() ) ) {
			return Estandar_Integrity_Fix_Result::already_compliant();
		}
		$result = Estandar_Integrity_Handle_Fixer::apply( $this->plugin_file, $renames );
		Estandar_Integrity_Scan_Cache::forget( $this->plugin_file );
		return $result;
	}

	public function dry_run(): array {
		$slug    = dirname( $this->plugin_file );
		$renames = array();
		foreach ( $this->bad_prefix_handles() as $old ) {
			$new = Estandar_Integrity_Handle_Fixer::derive_new_handle( $old, $slug );
			if ( $new !== '' ) {
				$renames[ $old ] = $new;
			}
		}
		return Estandar_Integrity_Handle_Fixer::plan( $this->plugin_file, $renames );
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* R3 — cross-plugin handle collisions (manual)                               */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Rule_Handle_Collision extends Estandar_Integrity_Base_Rule {

	private string $plugin_file;
	private string $plugin_name;
	private array $collisions;

	public function __construct( string $plugin_file, string $plugin_name, array $collisions ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_name = $plugin_name;
		$this->collisions  = array_values( array_map( 'strval', $collisions ) );
	}

	public function id(): string { return 'r3.' . sanitize_key( dirname( $this->plugin_file ) ); }
	public function rule_code(): string { return 'R3'; }
	public function severity(): string { return 'HIGH'; }
	public function plugin_label(): string { return $this->plugin_name; }
	public function label(): string { return 'Colisión de identificadores JS/CSS'; }

	public function description(): string {
		return 'Riesgo real de romper la UI admin: el mismo handle está registrado por más de un plugin.';
	}

	public function technical(): string {
		return 'Handles en colisión: ' . implode( ', ', array_slice( $this->collisions, 0, 5 ) ) . '. Requiere decidir manualmente qué plugin es el dueño del handle antes de renombrar el resto (renombrar el lado equivocado rompe al consumidor).';
	}

	public function check(): Estandar_Integrity_Audit_Result {
		// Re-verify against the other side: a collision disappears when the
		// handle stops being registered by more than one active plugin.
		$still = array();
		foreach ( $this->collisions as $handle ) {
			if ( $this->registered_by_others( $handle ) && in_array( $handle, (array) ( Estandar_Integrity_Scan_Cache::scan( $this->plugin_file )['collision_handles'] ?? array() ), true ) ) {
				$still[] = $handle;
			}
		}
		if ( empty( $still ) ) {
			return Estandar_Integrity_Audit_Result::pass( 'Sin colisiones activas.' );
		}
		return Estandar_Integrity_Audit_Result::fail(
			count( $still ) . ' handle(s) en colisión.',
			array( 'handles' => $still )
		);
	}

	private function registered_by_others( string $handle ): bool {
		$active = array_values( (array) get_option( 'active_plugins', array() ) );
		foreach ( $active as $other ) {
			$other = (string) $other;
			if ( $other === $this->plugin_file || ! function_exists( 'estandar_core_diag_is_estandar_plugin' ) || ! estandar_core_diag_is_estandar_plugin( $other ) ) {
				continue;
			}
			$scan = Estandar_Integrity_Scan_Cache::scan( $other );
			if ( in_array( $handle, (array) ( $scan['collision_handles'] ?? array() ), true ) ) {
				return true;
			}
		}
		return false;
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* R1/R2/R4/R6 — per-plugin scan-flag rules (manual, data-driven)             */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Rule_Scan_Flag extends Estandar_Integrity_Base_Rule {

	/** rule code => [severity, label, description, technical]. */
	const RULES = array(
		'R1' => array( 'MEDIUM', 'Detección de Primer frágil', 'Patrón débil de core-detection: puede causar drift silencioso.', 'Usar el polyfill dinámico estandar_core_mu_is_active() / primer_is_active() en vez de comprobaciones estáticas. Corrección manual: el refactor toca ramas de arranque.' ),
		'R2' => array( 'LOW', 'Pantalla admin sin contenedor estándar', 'Inconsistencia visual, usualmente no rompe nada.', 'Envolver la pantalla con el shell de Primer (estandar_core_render_admin_shell). Corrección manual: requiere decidir dónde abre/cierra el shell.' ),
		'R4' => array( 'MEDIUM', 'Helpers de core duplicados', 'Riesgo de drift y conflictos futuros.', 'Eliminar la copia local y consumir el helper de Primer. Corrección manual: hay que verificar los llamadores de la copia local.' ),
		'R6' => array( 'LOW', 'Clases legadas .ecb-* sin par canónico', 'Compatibilidad transicional; solo advertencia.', 'Migrar a clases .estandar-* (las .ecb-* son compatibilidad temporal). Corrección manual: toca markup y CSS.' ),
	);

	private string $code;
	private string $plugin_file;
	private string $plugin_name;

	public function __construct( string $code, string $plugin_file, string $plugin_name ) {
		$this->code        = isset( self::RULES[ $code ] ) ? $code : 'R1';
		$this->plugin_file = $plugin_file;
		$this->plugin_name = $plugin_name;
	}

	public function id(): string { return strtolower( $this->code ) . '.' . sanitize_key( dirname( $this->plugin_file ) ); }
	public function rule_code(): string { return $this->code; }
	public function severity(): string { return self::RULES[ $this->code ][0]; }
	public function plugin_label(): string { return $this->plugin_name; }
	public function label(): string { return self::RULES[ $this->code ][1]; }
	public function description(): string { return self::RULES[ $this->code ][2]; }
	public function technical(): string { return self::RULES[ $this->code ][3]; }

	public function check(): Estandar_Integrity_Audit_Result {
		$scan = Estandar_Integrity_Scan_Cache::scan( $this->plugin_file );
		if ( empty( $scan ) ) {
			return Estandar_Integrity_Audit_Result::fail( 'No se pudo re-escanear el plugin.' );
		}
		$ok = true;
		if ( 'R1' === $this->code ) {
			$ok = ! (bool) ( $scan['weak_core_detection'] ?? false );
		} elseif ( 'R2' === $this->code ) {
			$ok = ! (bool) ( $scan['mentions_admin_ui'] ?? false )
				|| (bool) ( ( $scan['mentions_wrapper'] ?? false ) || ( $scan['mentions_layout_helpers'] ?? false ) || ( $scan['mentions_estandar_classes'] ?? false ) || ( $scan['mentions_core_menu'] ?? false ) );
		} elseif ( 'R4' === $this->code ) {
			$ok = ! (bool) ( $scan['duplicate_helpers'] ?? false );
		} elseif ( 'R6' === $this->code ) {
			$ok = ! (bool) ( $scan['mentions_ecb_classes'] ?? false )
				|| ( (bool) ( $scan['mentions_wrapper'] ?? false ) && (bool) ( $scan['mentions_estandar_classes'] ?? false ) );
		}
		return $ok
			? Estandar_Integrity_Audit_Result::pass( 'Regla ' . $this->code . ' conforme.' )
			: Estandar_Integrity_Audit_Result::fail( 'La regla ' . $this->code . ' sigue sin cumplirse.' );
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* IDENTITY / DEPLOY — configuration & deployment findings (manual)           */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Rule_Site_Identity extends Estandar_Integrity_Base_Rule {

	private string $issue;

	public function __construct( string $issue ) {
		$this->issue = $issue;
	}

	public function id(): string { return 'identity.' . substr( md5( $this->issue ), 0, 8 ); }
	public function rule_code(): string { return 'IDENTITY'; }
	public function severity(): string { return 'HIGH'; }
	public function plugin_label(): string { return 'Configuración'; }
	public function label(): string { return 'Identidad del sitio incompleta'; }
	public function description(): string { return $this->issue; }

	public function technical(): string {
		return 'Dato de configuración que solo el operador puede completar — no existe corrección automática.';
	}

	public function details_url(): string {
		return admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion' );
	}

	public function check(): Estandar_Integrity_Audit_Result {
		if ( ! function_exists( 'estandar_core_diag_site_identity' ) ) {
			return Estandar_Integrity_Audit_Result::fail( 'No se pudo re-verificar.' );
		}
		$identity = estandar_core_diag_site_identity();
		$issues   = array_map( 'strval', (array) ( $identity['issues'] ?? array() ) );
		return in_array( $this->issue, $issues, true )
			? Estandar_Integrity_Audit_Result::fail( 'El dato sigue incompleto.' )
			: Estandar_Integrity_Audit_Result::pass( 'Configuración completada.' );
	}
}

final class Estandar_Integrity_Rule_Deployment extends Estandar_Integrity_Base_Rule {

	private string $key;
	private string $check_label;
	private string $evidence;
	private string $fix_url;

	public function __construct( array $failed_check ) {
		$this->key         = (string) ( $failed_check['key'] ?? md5( wp_json_encode( $failed_check ) ) );
		$this->check_label = (string) ( $failed_check['label'] ?? $this->key );
		$this->evidence    = (string) ( $failed_check['evidence'] ?? '' );
		$this->fix_url     = (string) ( $failed_check['fix_url'] ?? '' );
	}

	public function id(): string { return 'deploy.' . sanitize_key( $this->key ); }
	public function rule_code(): string { return 'DEPLOY'; }
	public function severity(): string { return 'HIGH'; }
	public function plugin_label(): string { return 'Deployment'; }
	public function label(): string { return $this->check_label; }

	public function description(): string {
		return $this->evidence !== '' ? $this->evidence : 'Check de deployment en estado FAIL.';
	}

	public function technical(): string {
		return 'Check del validador de deployment. Depende del entorno del servidor — sin corrección automática.';
	}

	public function details_url(): string { return $this->fix_url; }

	public function check(): Estandar_Integrity_Audit_Result {
		if ( ! function_exists( 'estandar_core_diag_deployment_validator' ) ) {
			return Estandar_Integrity_Audit_Result::fail( 'No se pudo re-verificar.' );
		}
		$validator = estandar_core_diag_deployment_validator();
		foreach ( (array) ( $validator['failed'] ?? array() ) as $failed ) {
			if ( is_array( $failed ) && (string) ( $failed['key'] ?? '' ) === $this->key ) {
				return Estandar_Integrity_Audit_Result::fail( 'El check sigue en FAIL.' );
			}
		}
		return Estandar_Integrity_Audit_Result::pass( 'Check en PASS.' );
	}
}

/* ────────────────────────────────────────────────────────────────────────── */
/* Builder — instantiate rules for the CURRENT findings                       */
/* ────────────────────────────────────────────────────────────────────────── */

final class Estandar_Integrity_Builtin_Rules {

	public static function register( Estandar_Integrity_Registry $registry ): void {
		if ( ! function_exists( 'estandar_core_diag_get_summary' ) ) {
			return;
		}
		$summary = estandar_core_diag_get_summary();
		$details = isset( $summary['details'] ) && is_array( $summary['details'] ) ? $summary['details'] : array();

		foreach ( (array) ( ( $details['site_identity'] ?? array() )['issues'] ?? array() ) as $issue ) {
			$registry->register( new Estandar_Integrity_Rule_Site_Identity( (string) $issue ) );
		}

		foreach ( (array) ( ( $details['deployment_validator'] ?? array() )['failed'] ?? array() ) as $failed ) {
			if ( is_array( $failed ) ) {
				$registry->register( new Estandar_Integrity_Rule_Deployment( $failed ) );
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();

		foreach ( (array) ( $details['per_plugin'] ?? array() ) as $plugin_file => $row ) {
			$plugin_file = (string) $plugin_file;
			$name        = (string) ( $row['name'] ?? $plugin_file );
			$rules       = isset( $row['rules'] ) && is_array( $row['rules'] ) ? $row['rules'] : array();
			$plugin_data = isset( $all_plugins[ $plugin_file ] ) && is_array( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();

			foreach ( array( 'R1', 'R2', 'R4', 'R6' ) as $code ) {
				if ( isset( $rules[ $code ] ) && ! (bool) $rules[ $code ] ) {
					$registry->register( new Estandar_Integrity_Rule_Scan_Flag( $code, $plugin_file, $name ) );
				}
			}

			if ( isset( $rules['R5'] ) && ! (bool) $rules['R5'] ) {
				$registry->register( new Estandar_Integrity_Rule_Generic_Handles( $plugin_file, $name ) );
			}

			if ( isset( $rules['R3'] ) && ! (bool) $rules['R3'] && ! empty( $row['collisions'] ) ) {
				$registry->register( new Estandar_Integrity_Rule_Handle_Collision( $plugin_file, $name, (array) $row['collisions'] ) );
			}

			if ( ! empty( $row['bad_prefix'] ) ) {
				$registry->register( new Estandar_Integrity_Rule_Handle_Prefix( $plugin_file, $name, $plugin_data ) );
			}
		}
	}
}
