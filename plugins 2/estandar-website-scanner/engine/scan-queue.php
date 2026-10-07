<?php
/**
 * Scan queue persistence.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue table operations.
 */
class EWS_Scan_Queue {

	/**
	 * Table helper.
	 *
	 * @return string
	 */
	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'estandar_scan_queue';
	}

	/**
	 * Create queue table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(190) NULL,
			target_url TEXT NOT NULL,
			competitors TEXT NULL,
			auto_competitors TINYINT(1) NOT NULL DEFAULT 0,
			cover_image_url TEXT NULL,
			website_image_url TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			progress_step INT NOT NULL DEFAULT 0,
			progress_total INT NOT NULL DEFAULT 6,
			progress_message TEXT NULL,
			error_message TEXT NULL,
			report_id VARCHAR(64) NULL,
			results_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY domain_idx (domain(190))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Enqueue new scan.
	 *
	 * @param string             $url Target URL.
	 * @param array<int, string> $competitors Competitors.
	 * @param bool               $auto_competitors Whether to ask AI to detect competitors.
	 * @return int
	 */
	public static function enqueue( string $url, array $competitors = array(), array $assets = array(), bool $auto_competitors = false ): int {
		global $wpdb;

		$url    = esc_url_raw( $url );
		$domain = (string) wp_parse_url( $url, PHP_URL_HOST );
		$cover_image   = esc_url_raw( (string) ( $assets['cover_image_url'] ?? '' ) );
		$website_image = esc_url_raw( (string) ( $assets['website_image_url'] ?? '' ) );
		$auto_flag     = (int) ( $auto_competitors ? 1 : 0 );

		$wpdb->insert(
			self::table_name(),
			array(
				'domain'           => sanitize_text_field( $domain ),
				'target_url'       => $url,
				'competitors'      => wp_json_encode( array_slice( $competitors, 0, 3 ) ),
				'auto_competitors' => $auto_flag,
				'cover_image_url'  => $cover_image,
				'website_image_url'=> $website_image,
				'status'           => 'pending',
				'progress_step'    => 0,
				'progress_total'   => 6,
				'progress_message' => 'Pending',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get queue row by ID.
	 *
	 * @param int $queue_id Queue ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_by_id( int $queue_id ): ?array {
		global $wpdb;
		$query = $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
			$queue_id
		);
		$row   = $wpdb->get_row( $query, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get recent queue rows.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent( int $limit = 20 ): array {
		global $wpdb;
		$query = $wpdb->prepare(
			'SELECT id, domain, target_url, cover_image_url, website_image_url, status, progress_step, progress_total, progress_message, error_message, report_id, created_at, started_at, finished_at FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d',
			max( 1, absint( $limit ) )
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get next pending scan.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_next_pending(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT * FROM ' . self::table_name() . " WHERE status = 'pending' ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Check if queue has running job.
	 *
	 * @return bool
	 */
	public static function has_running(): bool {
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . self::table_name() . " WHERE status = 'running'" );
		return $count > 0;
	}

	/**
	 * Mark queue row running.
	 *
	 * @param int $queue_id Queue ID.
	 * @return void
	 */
	public static function mark_running( int $queue_id ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'           => 'running',
				'started_at'       => current_time( 'mysql' ),
				'progress_step'    => 1,
				'progress_message' => 'Step 1/6: Fetching website',
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update progress text.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param int    $step Step number.
	 * @param string $message Message.
	 * @return void
	 */
	public static function update_progress( int $queue_id, int $step, string $message ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'progress_step'    => max( 0, $step ),
				'progress_message' => sanitize_text_field( $message ),
			),
			array( 'id' => $queue_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark queue row completed.
	 *
	 * @param int                  $queue_id Queue ID.
	 * @param array<string, mixed> $report Report payload.
	 * @return void
	 */
	public static function mark_completed( int $queue_id, array $report ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'           => 'completed',
				'progress_step'    => 6,
				'progress_message' => 'Step 6/6: Report generated',
				'report_id'        => sanitize_text_field( (string) ( $report['report_id'] ?? '' ) ),
				'results_json'     => wp_json_encode( $report ),
				'finished_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark queue row failed.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param string $error Error message.
	 * @return void
	 */
	public static function mark_failed( int $queue_id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'           => 'failed',
				'error_message'    => sanitize_text_field( $error ),
				'progress_message' => 'Failed',
				'finished_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark queue row as cancelled by user.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param string $reason Reason message.
	 * @return void
	 */
	public static function mark_cancelled( int $queue_id, string $reason = 'Cancelled by user.'): void {
		global $wpdb;
		$reason = sanitize_text_field( $reason );
		$wpdb->update(
			self::table_name(),
			array(
				'status'           => 'cancelled',
				'error_message'    => $reason,
				'progress_message' => 'Cancelled',
				'finished_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Check whether a queue item is cancelled.
	 *
	 * @param int|null $queue_id Queue ID.
	 * @return bool
	 */
	public static function is_cancelled( ?int $queue_id ): bool {
		if ( empty( $queue_id ) || $queue_id <= 0 ) {
			return false;
		}
		$row = self::get_by_id( (int) $queue_id );
		if ( empty( $row ) || empty( $row['status'] ) ) {
			return false;
		}
		return 'cancelled' === (string) $row['status'];
	}

	/**
	 * Decode competitors from queue row.
	 *
	 * @param array<string, mixed> $queue_row Queue row.
	 * @return array<int, string>
	 */
	public static function decode_competitors( array $queue_row ): array {
		$json = isset( $queue_row['competitors'] ) ? (string) $queue_row['competitors'] : '';
		if ( '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? array_values( array_filter( array_map( 'sanitize_text_field', $data ) ) ) : array();
	}

	/**
	 * Decode image assets from queue row.
	 *
	 * @param array<string, mixed> $queue_row Queue row.
	 * @return array<string, string>
	 */
	public static function decode_assets( array $queue_row ): array {
		return array(
			'cover_image_url'   => esc_url_raw( (string) ( $queue_row['cover_image_url'] ?? '' ) ),
			'website_image_url' => esc_url_raw( (string) ( $queue_row['website_image_url'] ?? '' ) ),
		);
	}

	/**
	 * Decode auto competitors flag from queue row.
	 *
	 * @param array<string, mixed> $queue_row Queue row.
	 * @return bool
	 */
	public static function decode_auto_competitors( array $queue_row ): bool {
		return ! empty( $queue_row['auto_competitors'] ) && (int) $queue_row['auto_competitors'] === 1;
	}
}
