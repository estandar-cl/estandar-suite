<?php
/**
 * Plugin settings page.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render settings page.
 *
 * @return void
 */
function ews_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Estandar Scanner Settings', 'estandar-website-scanner' ); ?></h1>
		<p><?php esc_html_e( 'Configure the Google PageSpeed Insights API key used for performance and Lighthouse diagnostics.', 'estandar-website-scanner' ); ?></p>

		<form method="post" action="options.php" class="ews-settings-form">
			<?php settings_fields( 'ews_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="estandar_pagespeed_api_key"><?php esc_html_e( 'PageSpeed API Key', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="estandar_pagespeed_api_key"
							name="estandar_pagespeed_api_key"
							value="<?php echo esc_attr( (string) get_option( 'estandar_pagespeed_api_key', '' ) ); ?>"
							class="regular-text"
							autocomplete="off"
						/>
						<p class="description"><?php esc_html_e( 'Required for PageSpeed API calls and full score coverage.', 'estandar-website-scanner' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="estandar_crux_api_key"><?php esc_html_e( 'CrUX API Key', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="estandar_crux_api_key"
							name="estandar_crux_api_key"
							value="<?php echo esc_attr( (string) get_option( 'estandar_crux_api_key', '' ) ); ?>"
							class="regular-text"
							autocomplete="off"
						/>
						<p class="description"><?php esc_html_e( 'Optional. If empty, the PageSpeed API key will be used for CrUX requests.', 'estandar-website-scanner' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="estandar_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="estandar_openai_api_key"
							name="estandar_openai_api_key"
							value="<?php echo esc_attr( (string) get_option( 'estandar_openai_api_key', '' ) ); ?>"
							class="regular-text"
							autocomplete="off"
						/>
						<p class="description"><?php esc_html_e( 'Used for AI Strategic Analysis section.', 'estandar-website-scanner' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ews_default_report_language"><?php esc_html_e( 'Default Report Language', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<select id="ews_default_report_language" name="ews_default_report_language">
							<option value="es" <?php selected( (string) get_option( 'ews_default_report_language', 'es' ), 'es' ); ?>>Español</option>
							<option value="en" <?php selected( (string) get_option( 'ews_default_report_language', 'es' ), 'en' ); ?>>English</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ews_show_cta"><?php esc_html_e( 'Show CTA in reports', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="ews_show_cta" value="0" />
						<label>
							<input
								type="checkbox"
								id="ews_show_cta"
								name="ews_show_cta"
								value="1"
								<?php checked( (int) get_option( 'ews_show_cta', 1 ), 1 ); ?>
							/>
							<?php esc_html_e( 'Display CTA block in report pages and PDF.', 'estandar-website-scanner' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ews_report_cta"><?php esc_html_e( 'Report CTA Text', 'estandar-website-scanner' ); ?></label>
					</th>
					<td>
						<textarea
							id="ews_report_cta"
							name="ews_report_cta"
							rows="8"
							class="large-text"
							placeholder="Enter CTA text. Supports line breaks and links."
						><?php echo esc_textarea( (string) get_option( 'ews_report_cta', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'If empty, default CTA text is used based on report language (English/Spanish).', 'estandar-website-scanner' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'estandar-website-scanner' ) ); ?>
		</form>
	</div>
	<?php
}
