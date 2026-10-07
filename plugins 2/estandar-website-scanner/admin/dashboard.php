<?php
/**
 * Admin dashboard views.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the plugin dashboard page.
 *
 * @return void
 */
function ews_render_dashboard_page(): void {
	?>
	<div class="wrap estandar-admin">
		<div class="ews-panel" style="margin-top:16px;">
			<h2 style="margin:0 0 10px;"><?php esc_html_e( 'Credits', 'estandar-website-scanner' ); ?></h2>
			<div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
				<?php
				$logo_url = 'https://estandar.cl/wp-content/uploads/2024/07/estandar_logo_color-768x227.png';
				?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="Estandar logo" style="height:60px; width:auto;"/>
				<div>
					<p style="margin:0 0 4px;">
						<?php
						/* translators: %s is plugin version. */
						echo esc_html( sprintf( 'Estandar Website Intelligence Scanner v%s', (string) EWS_VERSION ) );
						?>
					</p>
					<p style="margin:0;">
						<?php echo esc_html( 'Estandar.cl' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render scan history page.
 *
 * @return void
 */
function ews_render_scan_history_page(): void {
	$reports = EWS_Report_Generator::get_reports_index();
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Scan History', 'estandar-website-scanner' ); ?></h1>
		<?php if ( empty( $reports ) ) : ?>
			<p><?php esc_html_e( 'No reports generated yet. Run your first scan from Run Scan.', 'estandar-website-scanner' ); ?></p>
		<?php else : ?>
			<?php
			$grouped = array();
			foreach ( $reports as $report ) {
				$url = (string) ( $report['url'] ?? '' );
				$parsed = wp_parse_url( $url );
				$host = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
				$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
				$key = strtolower( trim( $host . $path ) );
				if ( '' === $key ) {
					$key = $url;
				}

				$generated_at = (string) ( $report['generated_at'] ?? '' );
				$ts = (int) strtotime( $generated_at );

				if ( ! isset( $grouped[ $key ] ) ) {
					$label = '' !== trim( $host ) ? $host . ( '' !== $path ? $path : '/' ) : $url;
					$grouped[ $key ] = array(
						'label'     => $label,
						'items'     => array(),
						'latest_ts' => $ts,
					);
				} else {
					if ( $ts > (int) ( $grouped[ $key ]['latest_ts'] ?? 0 ) ) {
						$grouped[ $key ]['latest_ts'] = $ts;
					}
				}
				$grouped[ $key ]['items'][] = $report;
			}

			uasort(
				$grouped,
				static function ( array $a, array $b ): int {
					return ( (int) ( $b['latest_ts'] ?? 0 ) ) <=> ( (int) ( $a['latest_ts'] ?? 0 ) );
				}
			);

			foreach ( $grouped as $group ) :
				$items = (array) ( $group['items'] ?? array() );
				usort(
					$items,
					static function ( array $a, array $b ): int {
						$ta = (int) strtotime( (string) ( $a['generated_at'] ?? '' ) );
						$tb = (int) strtotime( (string) ( $b['generated_at'] ?? '' ) );
						return $tb <=> $ta;
					}
				);
				$group_label = (string) ( $group['label'] ?? '' );
				?>
				<details open class="ews-history-group">
					<summary>
						<?php echo esc_html( $group_label ); ?>
						<span style="color:#666;margin-left:8px;"><?php echo esc_html( (string) count( $items ) ); ?></span>
					</summary>
					<table class="widefat striped" style="margin-top:10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'estandar-website-scanner' ); ?></th>
								<th><?php esc_html_e( 'URL', 'estandar-website-scanner' ); ?></th>
								<th><?php esc_html_e( 'Health Score', 'estandar-website-scanner' ); ?></th>
								<th><?php esc_html_e( 'Status', 'estandar-website-scanner' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'estandar-website-scanner' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $report ) : ?>
								<?php
								$report_id    = (string) ( $report['report_id'] ?? '' );
								$download_url = add_query_arg(
									array(
										'action'    => 'ews_download_report',
										'report_id' => $report_id,
										'_wpnonce'  => wp_create_nonce( 'ews_download_report_' . $report_id ),
									),
									admin_url( 'admin-post.php' )
								);
								$download_en = add_query_arg( 'lang', 'en', $download_url );
								$download_es = add_query_arg( 'lang', 'es', $download_url );
								?>
								<tr>
									<td><?php echo esc_html( (string) ( $report['generated_at'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $report['url'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $report['health_score'] ?? 0 ) ); ?>/10</td>
									<td><?php echo esc_html( (string) ( $report['status'] ?? '' ) ); ?></td>
									<td>
										<a class="button" href="<?php echo esc_url( $download_en ); ?>">English</a>
										<a class="button" href="<?php echo esc_url( $download_es ); ?>">Español</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render client reports admin page.
 *
 * @return void
 */
function ews_render_client_reports_page(): void {
	$reports = get_posts(
		array(
			'post_type'      => 'scanner_report',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Client Reports', 'estandar-website-scanner' ); ?></h1>
		<?php if ( empty( $reports ) ) : ?>
			<p><?php esc_html_e( 'No client report pages generated yet.', 'estandar-website-scanner' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Domain', 'estandar-website-scanner' ); ?></th>
						<th><?php esc_html_e( 'Public URL', 'estandar-website-scanner' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'estandar-website-scanner' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reports as $report_post ) : ?>
						<tr>
							<td><?php echo esc_html( get_the_title( $report_post ) ); ?></td>
							<td><a href="<?php echo esc_url( get_permalink( $report_post ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $report_post ) ); ?></a></td>
							<td><a class="button" href="<?php echo esc_url( get_edit_post_link( $report_post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'estandar-website-scanner' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render debug log page.
 *
 * @return void
 */
function ews_render_debug_logs_page(): void {
	$lines = EWS_Logger::read_tail( 200 );
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Debug Logs', 'estandar-website-scanner' ); ?></h1>
		<p><?php esc_html_e( 'Showing last 200 log lines.', 'estandar-website-scanner' ); ?></p>
		<div class="ews-log-box">
			<pre><?php echo esc_html( implode( PHP_EOL, $lines ) ); ?></pre>
		</div>
	</div>
	<?php
}
