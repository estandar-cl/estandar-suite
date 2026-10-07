<?php
/**
 * Estandar Core – Telemetry Module (Safe MVP v1.0)
 *
 * A) PHP fatal capture: register_shutdown_function + error_get_last (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR only)
 * B) Business event API: do_action( 'estandar_telemetry_event', $payload )
 * C) DB storage (wp_estandar_errors) + file fallback (wp-content/uploads/estandar-telemetry/)
 * D) Safety: re-entry guard, try/catch(Throwable), suppress_errors, URI scrubbing
 * E) Admin viewer: latest 100 rows, source filter, mark resolved, JSON export
 *
 * DO NOT ADD: set_error_handler, set_exception_handler, JS collectors, public REST endpoints,
 *             notice/warning capture, email/Slack alerts, queues, async jobs, third-party SaaS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =====================================================================
// CONSTANTS
// =====================================================================

if ( ! defined( 'ESTANDAR_TELEMETRY_FATAL_TYPES' ) ) {
	define( 'ESTANDAR_TELEMETRY_FATAL_TYPES', [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] );
}

if ( ! defined( 'ESTANDAR_TELEMETRY_SENSITIVE_PARAMS' ) ) {
	define( 'ESTANDAR_TELEMETRY_SENSITIVE_PARAMS', [
		'token', 'nonce', '_wpnonce', 'key', 'estandar_feedback', 'estandar_test_lead',
	] );
}

if ( ! defined( 'ESTANDAR_TELEMETRY_TABLE_OPTION' ) ) {
	define( 'ESTANDAR_TELEMETRY_TABLE_OPTION', 'estandar_telemetry_schema_v1' );
}
if ( ! defined( 'ESTANDAR_TELEMETRY_CLEANUP_OPTION' ) ) {
	define( 'ESTANDAR_TELEMETRY_CLEANUP_OPTION', 'estandar_telemetry_initial_cleanup_v1' );
}

if ( ! defined( 'ESTANDAR_TELEMETRY_MAX_MSG_LEN' ) ) {
	define( 'ESTANDAR_TELEMETRY_MAX_MSG_LEN', 4096 );
}

if ( ! defined( 'ESTANDAR_TELEMETRY_MAX_CTX_LEN' ) ) {
	define( 'ESTANDAR_TELEMETRY_MAX_CTX_LEN', 65535 );
}

// =====================================================================
// URI / CONTEXT SCRUBBING
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_scrub_uri' ) ) {
	/**
	 * Redact sensitive query params from a URI string.
	 * Uses only PHP builtins — safe to call from shutdown handler.
	 */
	function estandar_telemetry_scrub_uri( string $uri ): string {
		$parts = @parse_url( $uri ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return $uri;
		}

		parse_str( $parts['query'], $params );
		foreach ( ESTANDAR_TELEMETRY_SENSITIVE_PARAMS as $param ) {
			if ( array_key_exists( $param, $params ) ) {
				$params[ $param ] = '[REDACTED]';
			}
		}

		// http_build_query URL-encodes values; restore literal marker so logs stay readable.
		$parts['query'] = str_replace( '%5BREDACTED%5D', '[REDACTED]', http_build_query( $params ) );

		$out = '';
		if ( isset( $parts['scheme'] ) ) {
			$out .= $parts['scheme'] . '://';
		}
		if ( isset( $parts['host'] ) ) {
			$out .= $parts['host'];
		}
		if ( isset( $parts['port'] ) ) {
			$out .= ':' . (int) $parts['port'];
		}
		if ( isset( $parts['path'] ) ) {
			$out .= $parts['path'];
		}
		if ( ! empty( $parts['query'] ) ) {
			$out .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) ) {
			$out .= '#' . $parts['fragment'];
		}

		return $out;
	}
}

if ( ! function_exists( 'estandar_telemetry_scrub_context' ) ) {
	/**
	 * Recursively redact sensitive keys from a context array.
	 *
	 * @param array<string|int,mixed> $ctx
	 * @return array<string|int,mixed>
	 */
	function estandar_telemetry_scrub_context( array $ctx ): array {
		$out = [];
		foreach ( $ctx as $k => $v ) {
			$key_lower = strtolower( (string) $k );
			$sensitive = false;
			foreach ( ESTANDAR_TELEMETRY_SENSITIVE_PARAMS as $p ) {
				if ( false !== strpos( $key_lower, $p ) ) {
					$sensitive = true;
					break;
				}
			}

			if ( $sensitive ) {
				$out[ $k ] = '[REDACTED]';
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = estandar_telemetry_scrub_context( $v );
			} elseif ( is_string( $v ) && false !== strpos( $v, '?' ) ) {
				$out[ $k ] = estandar_telemetry_scrub_uri( $v );
			} else {
				$out[ $k ] = $v;
			}
		}

		return $out;
	}
}

// =====================================================================
// STORAGE HELPERS
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_table_name' ) ) {
	function estandar_telemetry_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'estandar_errors';
	}
}

if ( ! function_exists( 'estandar_telemetry_fallback_dir' ) ) {
	function estandar_telemetry_fallback_dir(): string {
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : [];
		$base    = isset( $uploads['basedir'] ) && '' !== (string) $uploads['basedir']
			? (string) $uploads['basedir']
			: ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '' );

		return '' !== $base ? $base . '/estandar-telemetry' : '';
	}
}

if ( ! function_exists( 'estandar_telemetry_create_table' ) ) {
	function estandar_telemetry_create_table(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = estandar_telemetry_table_name();
		$charset = $wpdb->get_charset_collate();

		// Double space before (id) is required by dbDelta parser.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  source_plugin varchar(128) NOT NULL DEFAULT '',
  type varchar(64) NOT NULL DEFAULT '',
  severity varchar(16) NOT NULL DEFAULT 'error',
  code varchar(128) NOT NULL DEFAULT '',
  message text NOT NULL,
  context_json text NOT NULL,
  resolved tinyint(1) unsigned NOT NULL DEFAULT 0,
  resolved_at datetime NULL,
  PRIMARY KEY  (id),
  KEY idx_source (source_plugin(64)),
  KEY idx_created (created_at),
  KEY idx_resolved (resolved)
) {$charset};";

		$suppress              = $wpdb->suppress_errors;
		$wpdb->suppress_errors = true;
		dbDelta( $sql );
		$wpdb->suppress_errors = $suppress;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}

if ( ! function_exists( 'estandar_telemetry_maybe_create_table' ) ) {
	function estandar_telemetry_maybe_create_table(): void {
		if ( ! get_option( ESTANDAR_TELEMETRY_TABLE_OPTION ) ) {
			if ( estandar_telemetry_create_table() ) {
				update_option( ESTANDAR_TELEMETRY_TABLE_OPTION, '1', false );
			}
		}

		estandar_telemetry_ensure_resolved_at_column();
		estandar_telemetry_initial_cleanup();
	}
}

if ( ! function_exists( 'estandar_telemetry_ensure_resolved_at_column' ) ) {
	function estandar_telemetry_ensure_resolved_at_column(): void {
		// Option gate: run once, not on every request.
		// Without this guard the ALTER TABLE fires on every plugins_loaded even
		// when the column already exists, causing a suppressed but real DB round-trip.
		$option_key = 'estandar_telemetry_resolved_at_col_v1';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;

		$table    = estandar_telemetry_table_name();
		$suppress = $wpdb->suppress_errors;
		$wpdb->suppress_errors = true;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN resolved_at datetime NULL" );
		$wpdb->suppress_errors = $suppress;

		update_option( $option_key, '1', false );
	}
}

if ( ! function_exists( 'estandar_telemetry_initial_cleanup' ) ) {
	function estandar_telemetry_initial_cleanup(): void {
		if ( get_option( ESTANDAR_TELEMETRY_CLEANUP_OPTION ) ) {
			return;
		}

		global $wpdb;
		$table = estandar_telemetry_table_name();
		$now = current_time( 'mysql' );

		$suppress = $wpdb->suppress_errors;
		$wpdb->suppress_errors = true;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET resolved = 1, resolved_at = %s
				WHERE resolved = 0
				  AND (
					message LIKE %s
					OR message LIKE %s
					OR message = %s
				  )",
				$now,
				'%EA_Dashboard::PAGES_LEGACY%',
				'%submit_button()%',
				'Telemetry smoke test'
			)
		);
		$wpdb->suppress_errors = $suppress;

		update_option( ESTANDAR_TELEMETRY_CLEANUP_OPTION, '1', false );
	}
}

if ( ! function_exists( 'estandar_telemetry_ensure_fallback_dir' ) ) {
	function estandar_telemetry_ensure_fallback_dir(): string {
		$dir = estandar_telemetry_fallback_dir();
		if ( '' === $dir ) {
			return '';
		}

		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		if ( ! is_dir( $dir ) ) {
			return '';
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}
}

// =====================================================================
// C. STORE: primary (DB) with file fallback
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_store' ) ) {
	/**
	 * Store a telemetry payload. Never throws; never breaks application flow.
	 *
	 * @param array<string,mixed> $payload
	 */
	function estandar_telemetry_store( array $payload ): void {
		static $depth = 0;
		if ( $depth > 0 ) {
			return; // re-entry guard: prevents recursive stores
		}
		$depth++;

		try {
			$source   = substr( sanitize_text_field( (string) ( $payload['source_plugin'] ?? 'unknown' ) ), 0, 128 );
			$type     = substr( sanitize_text_field( (string) ( $payload['type']          ?? 'event' ) ),   0, 64 );
			$severity = substr( sanitize_text_field( (string) ( $payload['severity']      ?? 'error' ) ),   0, 16 );
			$code     = substr( sanitize_text_field( (string) ( $payload['code']          ?? '' ) ),        0, 128 );
			$message  = substr( wp_strip_all_tags(   (string) ( $payload['message']       ?? '' ) ),        0, ESTANDAR_TELEMETRY_MAX_MSG_LEN );

			$context = isset( $payload['context'] ) && is_array( $payload['context'] )
				? estandar_telemetry_scrub_context( $payload['context'] )
				: [];

			$ctx_json = wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
			if ( false === $ctx_json ) {
				$ctx_json = '{}';
			}
			if ( strlen( $ctx_json ) > ESTANDAR_TELEMETRY_MAX_CTX_LEN ) {
				$ctx_json = '{"_truncated":true}';
			}

			$stored = false;

			// Primary: DB insert.
			if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
				global $wpdb;

				$suppress              = $wpdb->suppress_errors;
				$wpdb->suppress_errors = true;

				$result = $wpdb->insert(
					estandar_telemetry_table_name(),
					[
						'created_at'   => current_time( 'mysql' ),
						'source_plugin' => $source,
						'type'          => $type,
						'severity'      => $severity,
						'code'          => $code,
						'message'       => $message,
						'context_json'  => $ctx_json,
						'resolved'      => 0,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
				);

				$wpdb->suppress_errors = $suppress;
				$stored                = ( false !== $result );
			}

			// Fallback: file write (also used when $wpdb is not yet available).
			if ( ! $stored ) {
				estandar_telemetry_store_file( $source, $type, $severity, $code, $message, $ctx_json );
			}

		} catch ( \Throwable $e ) {
			// Intentionally swallowed. Telemetry must never break application flow.
		} finally {
			$depth--;
		}
	}
}

if ( ! function_exists( 'estandar_telemetry_store_file' ) ) {
	function estandar_telemetry_store_file(
		string $source,
		string $type,
		string $severity,
		string $code,
		string $message,
		string $ctx_json
	): void {
		$dir = estandar_telemetry_ensure_fallback_dir();
		if ( '' === $dir ) {
			return;
		}

		$ts   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$file = $dir . '/telemetry-' . gmdate( 'Y-m' ) . '.log';

		$line = wp_json_encode(
			[
				'ts'     => $ts,
				'source' => $source,
				'type'   => $type,
				'sev'    => $severity,
				'code'   => $code,
				'msg'    => $message,
				'ctx'    => $ctx_json,
			],
			JSON_UNESCAPED_UNICODE
		);

		if ( false === $line ) {
			return;
		}

		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
}

// =====================================================================
// A. PHP FATAL CAPTURE
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_detect_rest_autosave' ) ) {
	/**
	 * Extract cheap REST autosave metadata from a scrubbed URI.
	 *
	 * @return array<string,mixed>
	 */
	function estandar_telemetry_detect_rest_autosave( string $uri ): array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! preg_match( '#/wp-json/wp/v2/(pages|posts)/([0-9]+)/autosaves/?$#', $path, $matches ) ) {
			return [];
		}

		$collection = (string) $matches[1];
		return [
			'post_id'             => absint( $matches[2] ),
			'post_type_inferred'  => 'pages' === $collection ? 'page' : 'post',
			'rest_endpoint'       => $path,
			'is_autosave_request' => true,
		];
	}
}

if ( ! function_exists( 'estandar_telemetry_post_context' ) ) {
	/**
	 * Build safe, cheap post metadata. Never stores post content.
	 *
	 * @return array<string,mixed>
	 */
	function estandar_telemetry_post_context( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return [];
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return [
					'post_lookup_failed' => true,
				];
			}

			$content = is_string( $post->post_content ) ? $post->post_content : '';
			return [
				'post_title'                   => sanitize_text_field( (string) $post->post_title ),
				'post_type'                    => sanitize_key( (string) $post->post_type ),
				'post_status'                  => sanitize_key( (string) $post->post_status ),
				'post_modified'                => sanitize_text_field( (string) $post->post_modified ),
				'post_content_length'          => strlen( $content ),
				'block_count'                  => substr_count( $content, '<!-- wp:' ),
				'has_nested_paragraph_pattern' => false !== strpos( $content, '<p><p' ) || false !== strpos( $content, '</p></p>' ),
			];
		} catch ( \Throwable $e ) {
			return [
				'post_lookup_failed' => true,
			];
		}
	}
}

if ( ! function_exists( 'estandar_telemetry_enrich_fatal_context' ) ) {
	/**
	 * Add safe context to fatal telemetry without expensive parsing.
	 *
	 * @param array<string,mixed> $error
	 * @return array<string,mixed>
	 */
	function estandar_telemetry_enrich_fatal_context( array $error, string $uri ): array {
		$context = [
			'file' => (string) ( $error['file'] ?? '' ),
			'line' => (int) ( $error['line'] ?? 0 ),
			'uri'  => $uri,
		];

		$rest_context = estandar_telemetry_detect_rest_autosave( $uri );
		if ( ! empty( $rest_context ) ) {
			$context = array_merge( $context, $rest_context );
			$post_id = isset( $rest_context['post_id'] ) ? absint( $rest_context['post_id'] ) : 0;
			if ( $post_id > 0 ) {
				$context = array_merge( $context, estandar_telemetry_post_context( $post_id ) );
			}
		}

		$message = (string) ( $error['message'] ?? '' );
		$file    = (string) ( $error['file'] ?? '' );
		if (
			false !== stripos( $message, 'Allowed memory size' )
			&& false !== strpos( $file, 'class-wp-block-parser.php' )
			&& ! empty( $context['is_autosave_request'] )
		) {
			$context['likely_cause'] = 'Gutenberg autosave/block parser memory exhaustion';
			$context['suggested_action'] = 'Review post content for invalid nested HTML, oversized block markup, reusable blocks, or corrupted block structure.';
		}

		return $context;
	}
}

if ( ! function_exists( 'estandar_telemetry_shutdown_handler' ) ) {
	function estandar_telemetry_shutdown_handler(): void {
		static $called = false;
		if ( $called ) {
			return;
		}
		$called = true;

		$error = error_get_last();
		if ( null === $error || ! is_array( $error ) ) {
			return;
		}

		if ( ! in_array( (int) $error['type'], ESTANDAR_TELEMETRY_FATAL_TYPES, true ) ) {
			return; // Only capture real fatals; ignore notices/warnings.
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? estandar_telemetry_scrub_uri( $_SERVER['REQUEST_URI'] )
			: '';
		$context = estandar_telemetry_enrich_fatal_context( $error, $uri );

		estandar_telemetry_store( [
			'source_plugin' => 'estandar-core',
			'type'          => 'php_fatal',
			'severity'      => 'critical',
			'code'          => 'E_' . (string) ( $error['type'] ?? '' ),
			'message'       => (string) ( $error['message'] ?? '' ),
			'context'       => $context,
		] );
	}
}

register_shutdown_function( 'estandar_telemetry_shutdown_handler' );

// =====================================================================
// B. BUSINESS EVENT API
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_on_event' ) ) {
	/**
	 * Listener for: do_action( 'estandar_telemetry_event', $payload )
	 *
	 * Required payload keys: source_plugin (string), type (string), severity (string),
	 *                        code (string), message (string), context (array, optional)
	 *
	 * @param array<string,mixed> $payload
	 */
	function estandar_telemetry_on_event( array $payload ): void {
		if ( empty( $payload['source_plugin'] ) || empty( $payload['message'] ) ) {
			return; // Minimum required fields; silently drop malformed payloads.
		}
		estandar_telemetry_store( $payload );
	}
}

add_action( 'estandar_telemetry_event', 'estandar_telemetry_on_event', 10, 1 );
add_action( 'plugins_loaded', 'estandar_telemetry_maybe_create_table', 5 );

// =====================================================================
// E. ADMIN UI — action handlers (admin_init fires before HTML output)
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_admin_init' ) ) {
	function estandar_telemetry_admin_init(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle "mark resolved" POST action.
		if (
			isset( $_POST['estandar_telemetry_action'] ) &&
			'resolve' === (string) wp_unslash( $_POST['estandar_telemetry_action'] )
		) {
			check_admin_referer( 'estandar_telemetry_resolve', 'estandar_telemetry_nonce' );

			$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
			if ( $entry_id > 0 ) {
				global $wpdb;
				$suppress              = $wpdb->suppress_errors;
				$wpdb->suppress_errors = true;
				$wpdb->update(
					estandar_telemetry_table_name(),
					[
						'resolved'    => 1,
						'resolved_at' => current_time( 'mysql' ),
					],
					[ 'id' => $entry_id ],
					[ '%d', '%s' ],
					[ '%d' ]
				);
				$wpdb->suppress_errors = $suppress;
			}

			wp_safe_redirect(
				add_query_arg(
					[ 'page' => 'estandar_telemetry', 'msg' => 'resolved' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Handle "delete resolved" POST action.
		if (
			isset( $_POST['estandar_telemetry_action'] ) &&
			'delete_resolved' === (string) wp_unslash( $_POST['estandar_telemetry_action'] )
		) {
			$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
			check_admin_referer( 'estandar_telemetry_delete_resolved_' . $entry_id, 'estandar_telemetry_nonce' );

			if ( $entry_id > 0 ) {
				global $wpdb;
				$suppress              = $wpdb->suppress_errors;
				$wpdb->suppress_errors = true;
				$wpdb->delete(
					estandar_telemetry_table_name(),
					[
						'id'       => $entry_id,
						'resolved' => 1,
					],
					[ '%d', '%d' ]
				);
				$wpdb->suppress_errors = $suppress;
			}

			wp_safe_redirect(
				add_query_arg(
					[ 'page' => 'estandar_telemetry', 'status' => 'resolved', 'msg' => 'deleted' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Handle "clear resolved" POST action.
		if (
			isset( $_POST['estandar_telemetry_action'] ) &&
			'clear_resolved' === (string) wp_unslash( $_POST['estandar_telemetry_action'] )
		) {
			check_admin_referer( 'estandar_telemetry_clear_resolved', 'estandar_telemetry_nonce' );

			global $wpdb;
			$suppress              = $wpdb->suppress_errors;
			$wpdb->suppress_errors = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM " . estandar_telemetry_table_name() . " WHERE resolved = 1" );
			$wpdb->suppress_errors = $suppress;

			wp_safe_redirect(
				add_query_arg(
					[ 'page' => 'estandar_telemetry', 'status' => 'resolved', 'msg' => 'cleared' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Handle JSON export.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if (
			'estandar_telemetry' === $page &&
			isset( $_GET['estandar_export'] ) &&
			'1' === (string) $_GET['estandar_export']
		) {
			check_admin_referer( 'estandar_telemetry_export', 'estandar_export_nonce' );

			global $wpdb;
			$table = estandar_telemetry_table_name();

			$suppress              = $wpdb->suppress_errors;
			$wpdb->suppress_errors = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500", ARRAY_A );
			$wpdb->suppress_errors = $suppress;

			$rows = is_array( $rows ) ? $rows : [];

			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="estandar-telemetry-' . gmdate( 'Ymd-His' ) . '.json"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			exit;
		}
	}
}

add_action( 'admin_init', 'estandar_telemetry_admin_init' );

// =====================================================================
// E. ADMIN UI — menu registration + page render
// =====================================================================

if ( ! function_exists( 'estandar_telemetry_register_admin_menu' ) ) {
	function estandar_telemetry_register_admin_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( function_exists( 'estandar_admin_menu_register_parent' ) ) {
			estandar_admin_menu_register_parent();
		}

		add_submenu_page(
			defined( 'ESTANDAR_CORE_MENU_SLUG' ) ? ESTANDAR_CORE_MENU_SLUG : 'estandar_main_menu',
			'Estandar · Logs',
			'Logs',
			'manage_options',
			'estandar_telemetry',
			'estandar_telemetry_render_admin_page'
		);
	}
}

add_action( 'admin_menu', 'estandar_telemetry_register_admin_menu', 50 );

if ( ! function_exists( 'estandar_telemetry_render_context_summary' ) ) {
	function estandar_telemetry_render_context_summary( array $ctx ): void {
		$fields = [
			'post_id'             => 'Post ID',
			'post_title'          => 'Título',
			'post_type'           => 'Tipo',
			'post_content_length' => 'Largo contenido',
			'rest_endpoint'       => 'Endpoint',
			'likely_cause'        => 'Causa probable',
			'suggested_action'    => 'Acción sugerida',
		];

		$items = [];
		foreach ( $fields as $key => $label ) {
			if ( ! array_key_exists( $key, $ctx ) || '' === (string) $ctx[ $key ] ) {
				continue;
			}
			$items[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $ctx[ $key ] );
		}

		if ( empty( $items ) ) {
			return;
		}

		echo '<div style="margin-top:6px;font-size:11px;line-height:1.45;color:#374151;">';
		echo implode( '<br>', $items );
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_telemetry_render_admin_page' ) ) {
	function estandar_telemetry_render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'estandar-core-mu' ) );
		}

		global $wpdb;
		$table = estandar_telemetry_table_name();

		// Read filter.
		$filter_source = isset( $_GET['filter_source'] )
			? substr( sanitize_text_field( wp_unslash( (string) $_GET['filter_source'] ) ), 0, 128 )
			: '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : 'pending';
		if ( ! in_array( $status, [ 'pending', 'resolved', 'events' ], true ) ) {
			$status = 'pending';
		}
		$resolved_filter = 'resolved' === $status ? 1 : 0;

		// Vistas:
		//   pending  = Open Issues   → warning/error/critical sin resolver (accionables)
		//   events   = Operational   → severity info: historial operativo, nunca "resolvible"
		//   resolved = Resueltos     → issues ya cerrados
		if ( 'events' === $status ) {
			$where_sql = "severity = 'info'";
		} elseif ( 'resolved' === $status ) {
			$where_sql = "resolved = 1 AND severity <> 'info'";
		} else {
			$where_sql = "resolved = 0 AND severity <> 'info'";
		}

		// Query data.
		$suppress              = $wpdb->suppress_errors;
		$wpdb->suppress_errors = true;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw_sources = $wpdb->get_col( "SELECT DISTINCT source_plugin FROM {$table} ORDER BY source_plugin ASC" );
		$sources     = is_array( $raw_sources )
			? array_values( array_filter( array_map( 'sanitize_text_field', $raw_sources ) ) )
			: [];

		if ( '' !== $filter_source ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE {$where_sql} AND source_plugin = %s ORDER BY created_at DESC LIMIT 100",
					$filter_source
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 100",
				ARRAY_A
			);
		}

		$wpdb->suppress_errors = $suppress;
		$rows                  = is_array( $rows ) ? $rows : [];

		// ── Begin render ─────────────────────────────────────────────

		$_render_content = static function () use ( $rows, $status, $filter_source, $table, $sources ) {
			global $wpdb;

			// Status notice.
		if ( isset( $_GET['msg'] ) && 'resolved' === sanitize_key( wp_unslash( (string) $_GET['msg'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Entrada marcada como resuelta.', 'estandar-core-mu' );
			echo '</p></div>';
		}
		if ( isset( $_GET['msg'] ) && 'cleared' === sanitize_key( wp_unslash( (string) $_GET['msg'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Registros resueltos eliminados.', 'estandar-core-mu' );
			echo '</p></div>';
		}
		if ( isset( $_GET['msg'] ) && 'deleted' === sanitize_key( wp_unslash( (string) $_GET['msg'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Registro resuelto eliminado.', 'estandar-core-mu' );
			echo '</p></div>';
		}

		echo '<div class="estandar-surface" style="padding:1.5em;">';

		$pending_url  = add_query_arg( [ 'page' => 'estandar_telemetry' ], admin_url( 'admin.php' ) );
		$events_url   = add_query_arg( [ 'page' => 'estandar_telemetry', 'status' => 'events' ], admin_url( 'admin.php' ) );
		$resolved_url = add_query_arg( [ 'page' => 'estandar_telemetry', 'status' => 'resolved' ], admin_url( 'admin.php' ) );
		echo '<p style="margin:0 0 1em;">';
		echo '<a class="button' . ( 'pending' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $pending_url ) . '">' . esc_html__( 'Incidencias abiertas', 'estandar-core-mu' ) . '</a> ';
		echo '<a class="button' . ( 'events' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $events_url ) . '">' . esc_html__( 'Eventos operativos', 'estandar-core-mu' ) . '</a> ';
		echo '<a class="button' . ( 'resolved' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $resolved_url ) . '">' . esc_html__( 'Resueltos', 'estandar-core-mu' ) . '</a>';
		if ( 'events' === $status ) {
			echo '<span class="description" style="margin-left:10px;">' . esc_html__( 'Historial normal del sistema (capturas, routing, webhooks). No requiere acción.', 'estandar-core-mu' ) . '</span>';
		}
		if ( 'resolved' === $status ) {
			echo ' <form method="post" style="display:inline;">';
			wp_nonce_field( 'estandar_telemetry_clear_resolved', 'estandar_telemetry_nonce' );
			echo '<input type="hidden" name="estandar_telemetry_action" value="clear_resolved" />';
			echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Eliminar todos los registros resueltos?', 'estandar-core-mu' ) ) . '\');">' . esc_html__( 'Eliminar todos los resueltos', 'estandar-core-mu' ) . '</button>';
			echo '</form>';
		}
		echo '</p>';

		// ── Filter form ───────────────────────────────────────────────
		echo '<form method="get" style="margin-bottom:1.25em;">';
		echo '<input type="hidden" name="page" value="estandar_telemetry" />';
		if ( 'resolved' === $status ) {
			echo '<input type="hidden" name="status" value="resolved" />';
		}
		echo '<label for="estandar_filter_source">' . esc_html__( 'Filtrar por plugin:', 'estandar-core-mu' ) . ' </label>';
		echo '<select id="estandar_filter_source" name="filter_source">';
		echo '<option value="">' . esc_html__( '— Todos —', 'estandar-core-mu' ) . '</option>';
		foreach ( $sources as $src ) {
			echo '<option value="' . esc_attr( $src ) . '"' . selected( $src, $filter_source, false ) . '>';
			echo esc_html( $src );
			echo '</option>';
		}
		echo '</select>';
		echo ' <input type="submit" class="button" value="' . esc_attr__( 'Filtrar', 'estandar-core-mu' ) . '" />';
		echo '</form>';

		// ── Export button ─────────────────────────────────────────────
		$export_url = wp_nonce_url(
			add_query_arg(
				[ 'page' => 'estandar_telemetry', 'estandar_export' => '1' ],
				admin_url( 'admin.php' )
			),
			'estandar_telemetry_export',
			'estandar_export_nonce'
		);
		echo '<p style="margin-bottom:1.5em;">';
		echo '<a href="' . esc_url( $export_url ) . '" class="button button-secondary">';
		echo esc_html__( 'Exportar JSON (últimas 500)', 'estandar-core-mu' );
		echo '</a></p>';

		// ── Table ─────────────────────────────────────────────────────
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No hay registros.', 'estandar-core-mu' ) . '</p>';
		} else {
			echo '<table class="widefat fixed striped" style="margin-top:0.5em;">';
			echo '<thead><tr>';
			foreach ( [
				'ID',
				esc_html__( 'Fecha', 'estandar-core-mu' ),
				esc_html__( 'Plugin', 'estandar-core-mu' ),
				esc_html__( 'Tipo', 'estandar-core-mu' ),
				esc_html__( 'Severidad', 'estandar-core-mu' ),
				esc_html__( 'Mensaje', 'estandar-core-mu' ),
				esc_html__( 'Acción', 'estandar-core-mu' ),
			] as $col ) {
				echo '<th>' . $col . '</th>'; // Already escaped above via esc_html__.
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$row_id   = absint( $row['id'] ?? 0 );
				$ctx = [];
				if ( isset( $row['context_json'] ) && is_string( $row['context_json'] ) && '' !== $row['context_json'] ) {
					$decoded_ctx = json_decode( $row['context_json'], true );
					$ctx = is_array( $decoded_ctx ) ? $decoded_ctx : [];
				}
				$row_severity = strtolower( (string) ( $row['severity'] ?? '' ) );
				$is_info      = ( 'info' === $row_severity );
				$sev_colors   = [
					'info'     => [ '#f3f4f6', '#374151' ],
					'warning'  => [ '#fffbeb', '#92400e' ],
					'error'    => [ '#fef2f2', '#991b1b' ],
					'critical' => [ '#fef2f2', '#991b1b' ],
				];
				$sev_c = $sev_colors[ $row_severity ] ?? [ '#f3f4f6', '#374151' ];
				echo '<tr>';
				echo '<td>' . esc_html( (string) $row_id ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['source_plugin'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $row['type'] ?? '' ) ) . '</code></td>';
				echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:' . esc_attr( $sev_c[0] ) . ';color:' . esc_attr( $sev_c[1] ) . ';">' . esc_html( $row_severity ?: '-' ) . '</span></td>';
				echo '<td><small>' . esc_html( substr( (string) ( $row['message'] ?? '' ), 0, 200 ) ) . '</small>';
				estandar_telemetry_render_context_summary( $ctx );
				echo '</td>';
				echo '<td>';
				if ( $is_info ) {
					// INFO = historial operativo: no hay nada que resolver.
					echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;background:#f3f4f6;border:1px solid #e5e7eb;color:#6b7280;">' . esc_html__( 'Evento', 'estandar-core-mu' ) . '</span>';
				}
				if ( ! $is_info && 'pending' === $status && $row_id > 0 ) {
					echo '<form method="post" style="display:inline;">';
					wp_nonce_field( 'estandar_telemetry_resolve', 'estandar_telemetry_nonce' );
					echo '<input type="hidden" name="estandar_telemetry_action" value="resolve" />';
					echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $row_id ) . '" />';
					echo '<button type="submit" class="button button-small">';
					echo esc_html__( 'Resolver', 'estandar-core-mu' );
					echo '</button></form>';
				}
				if ( ! $is_info && 'resolved' === $status && $row_id > 0 ) {
					echo '<form method="post" style="display:inline;">';
					wp_nonce_field( 'estandar_telemetry_delete_resolved_' . $row_id, 'estandar_telemetry_nonce' );
					echo '<input type="hidden" name="estandar_telemetry_action" value="delete_resolved" />';
					echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) $row_id ) . '" />';
					echo '<button type="submit" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Eliminar este registro resuelto?', 'estandar-core-mu' ) ) . '\');">';
					echo esc_html__( 'Eliminar', 'estandar-core-mu' );
					echo '</button></form>';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>'; // .estandar-surface
		}; // end $_render_content

		if ( function_exists( 'estandar_core_render_admin_shell' ) ) {
			estandar_core_render_admin_shell( [
				'title'    => 'Sistema',
				'subtitle' => 'Logs y telemetría del ecosistema Estandar.',
				'width'    => 'fluid',
				'callback' => $_render_content,
			] );
			return;
		}

		// Legacy fallback (Core absent).
		if ( function_exists( 'estandar_render_layout_start' ) ) {
			estandar_render_layout_start();
		} else {
			echo '<div class="wrap">';
		}
		if ( function_exists( 'estandar_render_header' ) ) {
			estandar_render_header( [
				'title'    => 'Logs',
				'subtitle' => 'Errores fatales y eventos de negocio registrados.',
			] );
		} else {
			echo '<h1>' . esc_html__( 'Logs', 'estandar-core-mu' ) . '</h1>';
		}
		$_render_content();
		if ( function_exists( 'estandar_render_layout_end' ) ) {
			estandar_render_layout_end();
		} else {
			echo '</div>';
		}
	}
}
