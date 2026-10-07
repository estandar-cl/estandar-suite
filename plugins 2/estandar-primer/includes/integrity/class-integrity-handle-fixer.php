<?php
/**
 * INTEGRITY_AUTOFIX_V1 — deterministic asset-handle rename engine.
 *
 * Shared by every rule whose remediation is "rename an asset handle"
 * (R5 generic handles, PREFIX). Single implementation: no duplicated
 * remediation logic across rules.
 *
 * Safety model:
 *  - Replacements are context-anchored: only the handle position of known
 *    WP asset functions and quoted entries inside the argument span of
 *    wp_register_ / wp_enqueue_ calls (dependency arrays). A same-named CSS
 *    class or option key elsewhere in the file is never touched.
 *  - Cross-plugin guard: a handle referenced by another active Estandar
 *    plugin is skipped (renaming one side would break the consumer).
 *  - Verification gate: the rewritten file must tokenize (TOKEN_PARSE)
 *    before it replaces the original; on failure the original is restored.
 *  - Backup: every modified file is copied to uploads/estandar-integrity-backups/
 *    before writing (enables future Rollback capability).
 *  - Idempotent: a second run finds no occurrences and reports zero changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Integrity_Handle_Fixer {

	/** Asset functions whose FIRST argument is a handle. */
	const HANDLE_FNS = '(?:wp_(?:register|enqueue|dequeue|deregister)_(?:script|style)|wp_localize_script|wp_add_inline_script|wp_add_inline_style|wp_script_is|wp_style_is|wp_set_script_translations)';

	/**
	 * Deterministic new-name derivation: prefix the handle with the plugin
	 * slug, collapsing a leading 'estandar-' from the old handle so
	 * 'estandar-admin' under estandar-forms becomes 'estandar-forms-admin'.
	 * If the candidate is still ambiguous per the auditor's own predicate,
	 * '-asset' is appended (stable fallback). Returns '' when no safe name
	 * can be derived.
	 */
	public static function derive_new_handle( string $old, string $plugin_slug ): string {
		$old         = trim( $old );
		$plugin_slug = trim( $plugin_slug );
		if ( $old === '' || $plugin_slug === '' ) {
			return '';
		}
		if ( strpos( $old, $plugin_slug . '-' ) === 0 ) {
			return ''; // already carries the plugin prefix — nothing to derive
		}

		$suffix = $old;
		if ( strpos( $suffix, 'estandar-' ) === 0 ) {
			$suffix = substr( $suffix, strlen( 'estandar-' ) );
		}
		$candidate = $plugin_slug . '-' . $suffix;

		if ( function_exists( 'estandar_core_diag_is_ambiguous_handle' ) && estandar_core_diag_is_ambiguous_handle( $candidate ) ) {
			$candidate .= '-asset';
		}
		if ( function_exists( 'estandar_core_diag_is_ambiguous_handle' ) && estandar_core_diag_is_ambiguous_handle( $candidate ) ) {
			return ''; // give up rather than guess further
		}
		return $candidate;
	}

	/**
	 * True when another ACTIVE Estandar plugin references the handle —
	 * renaming inside $plugin_file would break that consumer.
	 */
	public static function referenced_elsewhere( string $handle, string $plugin_file ): bool {
		if ( ! function_exists( 'estandar_core_diag_collect_plugin_php_files' ) ) {
			return true; // cannot verify → be conservative
		}
		$active = array_values( (array) get_option( 'active_plugins', array() ) );
		foreach ( $active as $other ) {
			$other = (string) $other;
			if ( $other === $plugin_file || ! function_exists( 'estandar_core_diag_is_estandar_plugin' ) || ! estandar_core_diag_is_estandar_plugin( $other ) ) {
				continue;
			}
			foreach ( estandar_core_diag_collect_plugin_php_files( $other ) as $abs ) {
				$content = self::read_file( (string) $abs );
				if ( $content === '' ) {
					continue;
				}
				if ( strpos( $content, "'" . $handle . "'" ) !== false || strpos( $content, '"' . $handle . '"' ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Compute the rename plan without writing anything (dry-run).
	 *
	 * @param string               $plugin_file plugin basename (dir/file.php)
	 * @param array<string,string> $renames     old => new
	 * @return array{files:array<string,array<string,int>>, skipped:array<string,string>, total:int}
	 *         files: abs_path => (old handle => replacement count)
	 *         skipped: old handle => reason
	 */
	public static function plan( string $plugin_file, array $renames ): array {
		$out = array( 'files' => array(), 'skipped' => array(), 'total' => 0 );

		if ( ! function_exists( 'estandar_core_diag_collect_plugin_php_files' ) ) {
			return $out;
		}

		$safe = array();
		foreach ( $renames as $old => $new ) {
			$old = (string) $old;
			$new = (string) $new;
			if ( $old === '' || $new === '' || $old === $new ) {
				$out['skipped'][ $old ] = 'sin nombre destino seguro';
				continue;
			}
			if ( self::referenced_elsewhere( $old, $plugin_file ) ) {
				$out['skipped'][ $old ] = 'referenciado por otro plugin activo (requiere intervención manual)';
				continue;
			}
			$safe[ $old ] = $new;
		}

		foreach ( estandar_core_diag_collect_plugin_php_files( $plugin_file ) as $abs ) {
			$abs     = (string) $abs;
			$content = self::read_file( $abs );
			if ( $content === '' ) {
				continue;
			}
			foreach ( $safe as $old => $new ) {
				$counts = 0;
				self::rewrite( $content, $old, $new, $counts );
				if ( $counts > 0 ) {
					$out['files'][ $abs ][ $old ] = $counts;
					$out['total']                += $counts;
				}
			}
		}

		return $out;
	}

	/**
	 * Apply the rename plan. Deterministic, idempotent, verified per file.
	 *
	 * @param array<string,string> $renames old => new
	 */
	public static function apply( string $plugin_file, array $renames ): Estandar_Integrity_Fix_Result {
		$plan = self::plan( $plugin_file, $renames );

		if ( 0 === $plan['total'] && empty( $plan['skipped'] ) ) {
			return Estandar_Integrity_Fix_Result::already_compliant( 'No quedan registros con esos handles.' );
		}
		if ( 0 === $plan['total'] ) {
			return Estandar_Integrity_Fix_Result::failed( 'Ningún handle es renombrable automáticamente: ' . self::describe_skips( $plan['skipped'] ) );
		}

		$backup_root = self::backup_dir( $plugin_file );
		$changed     = array();
		$applied     = 0;

		foreach ( $plan['files'] as $abs => $handle_counts ) {
			$original = self::read_file( $abs );
			if ( $original === '' ) {
				continue;
			}

			$new_content = $original;
			foreach ( $handle_counts as $old => $ignored_count ) {
				$c = 0;
				$new_content = self::rewrite( $new_content, (string) $old, (string) $renames[ $old ], $c );
			}
			if ( $new_content === $original ) {
				continue;
			}

			// Verification gate: the rewritten file must still be valid PHP.
			try {
				token_get_all( $new_content, TOKEN_PARSE );
			} catch ( Throwable $e ) {
				return Estandar_Integrity_Fix_Result::failed( 'Verificación de sintaxis falló en ' . basename( $abs ) . ' — no se escribió ningún cambio en ese archivo.' );
			}

			if ( ! is_writable( $abs ) ) {
				return Estandar_Integrity_Fix_Result::failed( 'Archivo no escribible: ' . basename( $abs ) . '. Corregidos hasta ahora: ' . $applied . ' registro(s).' );
			}

			if ( $backup_root !== '' ) {
				self::backup_file( $abs, $plugin_file, $backup_root );
			}

			if ( false === file_put_contents( $abs, $new_content, LOCK_EX ) ) {
				return Estandar_Integrity_Fix_Result::failed( 'No se pudo escribir ' . basename( $abs ) . '.' );
			}

			$changed[] = str_replace( trailingslashit( WP_PLUGIN_DIR ), '', $abs );
			$applied  += array_sum( $handle_counts );
		}

		$renamed_list = array();
		foreach ( $renames as $old => $new ) {
			if ( ! isset( $plan['skipped'][ $old ] ) ) {
				$renamed_list[] = $old . ' → ' . $new;
			}
		}

		$msg = 'Renombrados ' . $applied . ' registro(s) en ' . count( $changed ) . ' archivo(s): ' . implode( ' · ', $renamed_list ) . '.';
		if ( ! empty( $plan['skipped'] ) ) {
			$msg .= ' Omitidos: ' . self::describe_skips( $plan['skipped'] );
		}

		return Estandar_Integrity_Fix_Result::fixed( $msg, $changed );
	}

	/**
	 * Context-anchored rewrite of one handle inside one PHP source string.
	 * Pass 1: full argument span of wp_register_ / wp_enqueue_ calls
	 *         (handle position + dependency arrays), balanced to one nested
	 *         call level. Pass 2: handle position of the remaining asset
	 *         functions (localize, inline, is, dequeue, deregister, ...).
	 */
	public static function rewrite( string $content, string $old, string $new, int &$count ): string {
		$count = 0;
		$q     = preg_quote( $old, '/' );

		$span_pattern = '/wp_(?:register|enqueue)_(?:script|style)\s*\((?:[^()]|\([^()]*\))*\)/s';
		$content      = (string) preg_replace_callback(
			$span_pattern,
			static function ( array $m ) use ( $q, $new, &$count ): string {
				$inner = 0;
				$out   = (string) preg_replace( '/([\'"])' . $q . '\1/', '${1}' . $new . '${1}', $m[0], -1, $inner );
				$count += $inner;
				return $out;
			},
			$content
		);

		$fn_pattern = '/(' . self::HANDLE_FNS . '\s*\(\s*)([\'"])' . $q . '\2/';
		$inner      = 0;
		$content    = (string) preg_replace( $fn_pattern, '${1}${2}' . $new . '${2}', $content, -1, $inner );
		$count     += $inner;

		return $content;
	}

	/* ── internals ─────────────────────────────────────────────────────── */

	private static function read_file( string $abs ): string {
		if ( $abs === '' || ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return '';
		}
		$size = filesize( $abs );
		if ( false === $size || $size <= 0 || $size > 2 * 1024 * 1024 ) {
			return '';
		}
		$content = file_get_contents( $abs );
		return is_string( $content ) ? $content : '';
	}

	private static function backup_dir( string $plugin_file ): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( $base === '' ) {
			return '';
		}
		$dir = $base . '/estandar-integrity-backups/' . sanitize_key( dirname( $plugin_file ) ) . '/' . gmdate( 'Ymd-His' );
		if ( function_exists( 'wp_mkdir_p' ) && wp_mkdir_p( $dir ) ) {
			return $dir;
		}
		return '';
	}

	private static function backup_file( string $abs, string $plugin_file, string $backup_root ): void {
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . dirname( $plugin_file );
		$relative   = ltrim( str_replace( $plugin_dir, '', $abs ), '/' );
		$target     = $backup_root . '/' . $relative;
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( dirname( $target ) );
		}
		@copy( $abs, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** @param array<string,string> $skipped */
	private static function describe_skips( array $skipped ): string {
		$parts = array();
		foreach ( $skipped as $handle => $reason ) {
			$parts[] = $handle . ' (' . $reason . ')';
		}
		return implode( ' · ', $parts );
	}
}
