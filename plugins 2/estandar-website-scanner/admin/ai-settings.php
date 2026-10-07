<?php
/**
 * AI settings page.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render AI settings.
 *
 * @return void
 */
function ews_render_ai_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap estandar-admin">
		<h1><?php esc_html_e( 'AI Settings', 'estandar-website-scanner' ); ?></h1>
		<p><?php esc_html_e( 'Configure AI prompt templates used to generate strategic analysis.', 'estandar-website-scanner' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'ews_ai_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ews_prompt_executive">AI Executive Analysis Prompt</label></th>
					<td><textarea class="large-text" rows="6" id="ews_prompt_executive" name="ews_prompt_executive"><?php echo esc_textarea( (string) get_option( 'ews_prompt_executive', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_prompt_technical">AI Technical Recommendations Prompt</label></th>
					<td><textarea class="large-text" rows="6" id="ews_prompt_technical" name="ews_prompt_technical"><?php echo esc_textarea( (string) get_option( 'ews_prompt_technical', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_prompt_seo">AI SEO Opportunities Prompt</label></th>
					<td><textarea class="large-text" rows="6" id="ews_prompt_seo" name="ews_prompt_seo"><?php echo esc_textarea( (string) get_option( 'ews_prompt_seo', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="ews_prompt_conversion">AI Conversion Recommendations Prompt</label></th>
					<td><textarea class="large-text" rows="6" id="ews_prompt_conversion" name="ews_prompt_conversion"><?php echo esc_textarea( (string) get_option( 'ews_prompt_conversion', '' ) ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save AI Prompts', 'estandar-website-scanner' ) ); ?>
		</form>
	</div>
	<?php
}
