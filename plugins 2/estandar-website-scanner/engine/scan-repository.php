<?php
/**
 * Scan persistence module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles scan database storage.
 */
class EWS_Scan_Repository {

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'estandar_scans';
	}

	/**
	 * Create scans table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id VARCHAR(64) NULL,
			site_url TEXT NOT NULL,
			domain VARCHAR(190) NULL,
			scan_date DATETIME NULL,
			created_at DATETIME NOT NULL,
			score_total FLOAT NOT NULL,
			score_coverage INT NOT NULL,
			results_json LONGTEXT NOT NULL,
			summary_text TEXT NULL,
			pdf_path TEXT NULL,
			pdf_url TEXT NULL,
			report_language VARCHAR(8) NULL,
			PRIMARY KEY  (id),
			KEY domain_idx (domain(190)),
			KEY report_id_idx (report_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Persist one scan row.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return int Insert ID.
	 */
	public static function save_scan( array $report ): int {
		global $wpdb;

		$site_url = esc_url_raw( (string) ( $report['url'] ?? '' ) );
		$domain   = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$summary  = (string) ( $report['value_analysis']['executive_summary'] ?? '' );

		$wpdb->insert(
			self::table_name(),
			array(
				'report_id'      => sanitize_text_field( (string) ( $report['report_id'] ?? '' ) ),
				'site_url'       => $site_url,
				'domain'         => sanitize_text_field( $domain ),
				'scan_date'      => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
				'score_total'    => (float) ( $report['score']['health_score'] ?? 0 ),
				'score_coverage' => (int) ( $report['score']['coverage'] ?? 0 ),
				'results_json'   => wp_json_encode( $report ),
				'summary_text'   => sanitize_text_field( $summary ),
				'pdf_path'       => sanitize_text_field( (string) ( $report['pdf_path'] ?? '' ) ),
				'pdf_url'        => esc_url_raw( (string) ( $report['pdf_url'] ?? '' ) ),
				'report_language'=> sanitize_text_field( (string) ( $report['report_language'] ?? 'en' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update stored PDF path/url by report ID.
	 *
	 * @param string $report_id Report ID.
	 * @param string $pdf_path Local file path.
	 * @param string $pdf_url Public file URL.
	 * @param string $lang Report language.
	 * @return void
	 */
	public static function set_pdf_archive( string $report_id, string $pdf_path, string $pdf_url, string $lang = '' ): void {
		global $wpdb;
		$report_id = sanitize_text_field( $report_id );
		if ( '' === $report_id ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			array(
				'pdf_path' => sanitize_text_field( $pdf_path ),
				'pdf_url'  => esc_url_raw( $pdf_url ),
				'report_language' => sanitize_text_field( $lang ),
			),
			array( 'report_id' => $report_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Get latest scan row.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_scan(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get historical scans by domain.
	 *
	 * @param string $domain Domain.
	 * @param int    $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_history_by_domain( string $domain, int $limit = 12 ): array {
		global $wpdb;
		$domain = sanitize_text_field( $domain );
		if ( '' === $domain ) {
			return array();
		}

		$query = $wpdb->prepare(
			'SELECT id, report_id, domain, scan_date, score_total, score_coverage, pdf_url, report_language FROM ' . self::table_name() . ' WHERE domain = %s ORDER BY id DESC LIMIT %d',
			$domain,
			$limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get latest report payload by domain.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_report_by_domain( string $domain ): ?array {
		global $wpdb;
		$domain = sanitize_text_field( $domain );
		if ( '' === $domain ) {
			return null;
		}

		$query = $wpdb->prepare(
			'SELECT results_json FROM ' . self::table_name() . ' WHERE domain = %s ORDER BY id DESC LIMIT 1',
			$domain
		);
		$json = $wpdb->get_var( $query );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get latest report payload by report ID.
	 *
	 * @param string $report_id Report ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_report_by_id( string $report_id ): ?array {
		global $wpdb;
		$report_id = sanitize_text_field( $report_id );
		if ( '' === $report_id ) {
			return null;
		}

		$query = $wpdb->prepare(
			'SELECT results_json FROM ' . self::table_name() . ' WHERE report_id = %s ORDER BY id DESC LIMIT 1',
			$report_id
		);
		$json = $wpdb->get_var( $query );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}
}
