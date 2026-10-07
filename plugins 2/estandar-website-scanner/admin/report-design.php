<?php
/**
 * Report design admin page.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render report design settings page.
 *
 * @return void
 */
function ews_render_report_design_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = EWS_Report_Design::get_settings();
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'Report Design', 'estandar-website-scanner' ); ?></h1>
		<p><?php esc_html_e( 'Customize PDF and client report visual style.', 'estandar-website-scanner' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'ews_report_design_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ews_page_size">Page size</label></th>
					<td>
						<select id="ews_page_size" name="ews_report_layout_settings[page_size]">
							<?php foreach ( array( 'a4' => 'A4', 'letter' => 'Letter', 'legal' => 'Legal' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['page_size'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_header_banner">Header banner URL</label></th>
					<td><input type="url" class="regular-text" id="ews_header_banner" name="ews_report_layout_settings[header_banner]" value="<?php echo esc_attr( $settings['header_banner'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_cover_background">Cover background image URL</label></th>
					<td><input type="url" class="regular-text" id="ews_cover_background" name="ews_report_layout_settings[cover_background]" value="<?php echo esc_attr( $settings['cover_background'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_pdf_cover_background">PDF Cover Background Image</label></th>
					<td><input type="url" class="regular-text" id="ews_pdf_cover_background" name="ews_report_layout_settings[pdf_cover_background]" value="<?php echo esc_attr( $settings['pdf_cover_background'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_pdf_cover_title">PDF Cover Title</label></th>
					<td><input type="text" class="regular-text" id="ews_pdf_cover_title" name="ews_report_layout_settings[pdf_cover_title]" value="<?php echo esc_attr( $settings['pdf_cover_title'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_pdf_subtitle_es">PDF Subtitle ES</label></th>
					<td><input type="text" class="regular-text" id="ews_pdf_subtitle_es" name="ews_report_layout_settings[pdf_subtitle_es]" value="<?php echo esc_attr( $settings['pdf_subtitle_es'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_pdf_subtitle_en">PDF Subtitle EN</label></th>
					<td><input type="text" class="regular-text" id="ews_pdf_subtitle_en" name="ews_report_layout_settings[pdf_subtitle_en]" value="<?php echo esc_attr( $settings['pdf_subtitle_en'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_client_logo">Client logo URL</label></th>
					<td><input type="url" class="regular-text" id="ews_client_logo" name="ews_report_layout_settings[client_logo]" value="<?php echo esc_attr( $settings['client_logo'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_logo_position">Logo position</label></th>
					<td>
						<select id="ews_logo_position" name="ews_report_layout_settings[logo_position]">
							<?php foreach ( array( 'top-left', 'top-right', 'center' ) as $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['logo_position'], $value ); ?>><?php echo esc_html( $value ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_font">Font</label></th>
					<td>
						<select id="ews_font" name="ews_report_layout_settings[font]">
							<?php foreach ( array( 'Inter', 'Roboto', 'Montserrat' ) as $font ) : ?>
								<option value="<?php echo esc_attr( $font ); ?>" <?php selected( $settings['font'], $font ); ?>><?php echo esc_html( $font ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_layout">Layout</label></th>
					<td>
						<select id="ews_layout" name="ews_report_layout_settings[layout]">
							<option value="one-column" <?php selected( $settings['layout'], 'one-column' ); ?>>1 column</option>
							<option value="two-columns" <?php selected( $settings['layout'], 'two-columns' ); ?>>2 columns</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_primary_color">Primary color</label></th>
					<td><input type="text" id="ews_primary_color" name="ews_report_layout_settings[primary_color]" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_secondary_color">Secondary color</label></th>
					<td><input type="text" id="ews_secondary_color" name="ews_report_layout_settings[secondary_color]" value="<?php echo esc_attr( $settings['secondary_color'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_chart_palette">Chart palette</label></th>
					<td><input type="text" id="ews_chart_palette" name="ews_report_layout_settings[chart_palette]" value="<?php echo esc_attr( $settings['chart_palette'] ); ?>" class="regular-text" />
						<p class="description">Comma-separated HEX colors. Example: #1e73be,#10b981,#f59e0b</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Design', 'estandar-website-scanner' ) ); ?>
		</form>
	</div>
	<?php
}
