<?php
/**
 * Scanner form view.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render URL scan form.
 *
 * @return void
 */
function ews_render_scanner_form_page(): void {
	$recent_reports = EWS_Report_Generator::get_reports_index();
	$recent_reports = is_array( $recent_reports ) ? array_slice( $recent_reports, 0, 8 ) : array();

	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Run Scan', 'estandar-website-scanner' ); ?></h1>

		<form id="ews-scan-form" class="ews-scan-form" method="post">
			<?php wp_nonce_field( 'estandar_run_scan_nonce', 'ews_scan_nonce' ); ?>
			<label for="ews-url"><?php esc_html_e( 'Website URL', 'estandar-website-scanner' ); ?></label>
			<input type="url" id="ews-url" name="url" placeholder="https://example.com" required />
			<label for="ews-competitors"><?php esc_html_e( 'Competitors (up to 3, comma separated)', 'estandar-website-scanner' ); ?></label>
			<input type="text" id="ews-competitors" name="competitors" placeholder="competitor1.com, competitor2.com, competitor3.com" />
			<label style="display:block; margin-top:10px;">
				<input type="checkbox" id="ews-auto-competitors" name="auto_competitors" value="1" />
				<?php esc_html_e( 'Auto-detect competitors with AI (top 3) using initial analysis', 'estandar-website-scanner' ); ?>
			</label>
			<label for="ews-website-image-url"><?php esc_html_e( 'Website Screenshot Image URL (optional override)', 'estandar-website-scanner' ); ?></label>
			<div class="ews-media-field">
				<input type="url" id="ews-website-image-url" name="website_image_url" placeholder="https://example.com/site-shot.jpg" />
				<button type="button" class="button ews-media-select" data-target-input="ews-website-image-url"><?php esc_html_e( 'Upload / Select Image', 'estandar-website-scanner' ); ?></button>
			</div>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Scan', 'estandar-website-scanner' ); ?></button>
		</form>

		<div id="ews-scan-progress" class="ews-progress" hidden>
			<h2><?php esc_html_e( 'Running Website Scan', 'estandar-website-scanner' ); ?></h2>
			<div class="ews-progress-track">
				<div id="ews-progress-bar" class="ews-progress-bar" style="width:0%"></div>
			</div>
			<p id="ews-progress-label">Step 0/6</p>
			<p id="ews-progress-message"></p>
		</div>

		<div style="margin-top:12px;">
			<button type="button" id="ews-stop-scan" class="button button-secondary" disabled style="display:none;">
				<?php esc_html_e( 'Stop Scan', 'estandar-website-scanner' ); ?>
			</button>
		</div>

		<div id="ews-scan-results" class="ews-scan-results" aria-live="polite"></div>

		<div class="ews-panel">
			<h2><?php esc_html_e( 'Current Queue', 'estandar-website-scanner' ); ?></h2>
			<div id="ews-queue-table"><?php esc_html_e( 'Loading queue...', 'estandar-website-scanner' ); ?></div>
		</div>

		<?php if ( ! empty( $recent_reports ) ) : ?>
			<div class="ews-panel">
				<h2><?php esc_html_e( 'Repeat Previous Scans', 'estandar-website-scanner' ); ?></h2>
				<p><?php esc_html_e( 'Re-run a scan for a URL that was already analyzed.', 'estandar-website-scanner' ); ?></p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'estandar-website-scanner' ); ?></th>
							<th><?php esc_html_e( 'Health', 'estandar-website-scanner' ); ?></th>
							<th><?php esc_html_e( 'Action', 'estandar-website-scanner' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_reports as $report ) : ?>
							<?php
							$report_url = (string) ( $report['url'] ?? '' );
							$health     = (float) ( $report['health_score'] ?? 0 );
							$status     = (string) ( $report['status'] ?? '' );
							?>
							<tr>
								<td><?php echo esc_html( $report_url ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $health, 2 ) ); ?>/10 (<?php echo esc_html( $status ); ?>)</td>
								<td>
									<button type="button" class="button ews-repeat-scan" data-url="<?php echo esc_attr( $report_url ); ?>">
										<?php esc_html_e( 'Re-scan', 'estandar-website-scanner' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
