<?php
/**
 * Public client report pages.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles scanner_reports CPT and public rendering.
 */
class EWS_Client_Reports {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'the_content', array( __CLASS__, 'render_public_report' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public_assets' ) );
	}

	/**
	 * Register scanner report post type.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			'scanner_report',
			array(
				'labels' => array(
					'name'          => __( 'Scanner Reports', 'estandar-website-scanner' ),
					'singular_name' => __( 'Scanner Report', 'estandar-website-scanner' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'scanner' ),
				'supports'     => array( 'title' ),
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Upsert scanner report post by domain.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return void
	 */
	public static function sync_from_scan( array $report ): void {
		$url    = esc_url_raw( (string) ( $report['url'] ?? '' ) );
		$domain = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $domain ) {
			return;
		}

		$slug = sanitize_title( str_replace( '.', '-', $domain ) );

		$existing = get_posts(
			array(
				'post_type'      => 'scanner_report',
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		$post_data = array(
			'post_title'  => $domain,
			'post_name'   => $slug,
			'post_type'   => 'scanner_report',
			'post_status' => 'publish',
		);

		if ( ! empty( $existing[0] ) ) {
			$post_data['ID'] = (int) $existing[0];
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) || ! is_numeric( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, '_ews_domain', sanitize_text_field( $domain ) );
		update_post_meta( $post_id, '_ews_score', (float) ( $report['score']['health_score'] ?? 0 ) );
		update_post_meta( $post_id, '_ews_report_id', sanitize_text_field( (string) ( $report['report_id'] ?? '' ) ) );
	}

	/**
	 * Render public report content.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public static function render_public_report( string $content ): string {
		if ( ! is_singular( 'scanner_report' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		$domain  = (string) get_post_meta( $post_id, '_ews_domain', true );
		if ( '' === $domain ) {
			return '<p>No report data available.</p>';
		}

		$report  = EWS_Scan_Repository::get_latest_report_by_domain( $domain );
		$history = EWS_Scan_Repository::get_history_by_domain( $domain );
		if ( empty( $report ) ) {
			return '<p>No report data available.</p>';
		}

		$default_lang = isset( $report['report_language'] ) ? (string) $report['report_language'] : 'en';
		$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : $default_lang; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang = in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'en';
		$layout = ews_render_report_layout(
			$report,
			array(
				'mode' => 'web',
				'lang' => $lang,
			)
		);

		ob_start();
		echo $layout; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="ews-public-report-history">
			<h3>Historical Reports</h3>
			<ul>
				<?php foreach ( $history as $row ) : ?>
					<li>
						<?php echo esc_html( (string) ( $row['scan_date'] ?? '' ) ); ?> — Score <?php echo esc_html( (string) ( $row['score_total'] ?? '' ) ); ?> (<?php echo esc_html( strtoupper( (string) ( $row['report_language'] ?? 'en' ) ) ); ?>)
						<?php if ( ! empty( $row['pdf_url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $row['pdf_url'] ); ?>" target="_blank" rel="noopener">PDF</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Load chart/d3 libs for public report pages.
	 *
	 * @return void
	 */
	public static function enqueue_public_assets(): void {
		if ( ! is_singular( 'scanner_report' ) ) {
			return;
		}
		wp_enqueue_style( 'ews-public-report', EWS_PLUGIN_URL . 'assets/css/public-report.css', array(), EWS_VERSION );
	}
}
