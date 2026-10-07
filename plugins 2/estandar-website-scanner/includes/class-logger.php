<?php
/**
 * Scanner logger.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads scanner debug logs.
 */
class EWS_Logger {

	/**
	 * Get log file path.
	 *
	 * @return string
	 */
	private static function log_file_path(): string {
		$uploads = wp_upload_dir();
		$base    = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		$dir     = trailingslashit( $base ) . 'estandar-scanner-logs';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return trailingslashit( $dir ) . 'scan-log.txt';
	}

	/**
	 * Append message to log file.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( string $message ): void {
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . trim( $message ) . PHP_EOL;
		$file = self::log_file_path();
		file_put_contents( $file, $line, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Read last lines from log file.
	 *
	 * @param int $lines Number of lines.
	 * @return array<int, string>
	 */
	public static function read_tail( int $lines = 200 ): array {
		$file = self::log_file_path();
		if ( ! file_exists( $file ) ) {
			return array();
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}

		$rows = preg_split( '/\R/', trim( $content ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_slice( $rows, -1 * absint( $lines ) );
	}
}

