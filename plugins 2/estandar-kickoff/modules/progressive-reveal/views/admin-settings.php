<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$expiry_value = $settings['token_expiry'] ? gmdate( 'Y-m-d\TH:i', $settings['token_expiry'] + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '';
$preview_url  = add_query_arg( ESTANDAR_PR_PREVIEW_PARAM, $settings['preview_token'], home_url( '/' ) );
?>
<div class="wrap estandar-pr-admin">
	<h1><?php esc_html_e( 'Progressive Reveal', 'estandar-kickoff' ); ?></h1>

	<?php if ( isset( $_GET['estandar_pr_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ajustes guardados.', 'estandar-kickoff' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['estandar_pr_regenerated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token regenerado.', 'estandar-kickoff' ); ?></p></div>
	<?php endif; ?>

	<div class="estandar-pr-progress-card">
		<strong><?php esc_html_e( 'Progreso del rollout', 'estandar-kickoff' ); ?></strong>
		<span><?php echo esc_html( $progress['released'] ); ?> / <?php echo esc_html( $progress['total'] ); ?> <?php esc_html_e( 'páginas liberadas', 'estandar-kickoff' ); ?></span>
		<div class="estandar-pr-admin-progress"><span style="width: <?php echo esc_attr( $progress['percent'] ); ?>%;"></span></div>
		<em><?php echo esc_html( $progress['percent'] ); ?>%</em>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="estandar_pr_save">
		<?php wp_nonce_field( ESTANDAR_PR_NONCE_SAVE ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable module', 'estandar-kickoff' ); ?></th>
				<td>
					<label><input type="checkbox" name="estandar_pr_active" value="1" <?php checked( $settings['active'], 1 ); ?>> <?php esc_html_e( 'Activar Progressive Reveal', 'estandar-kickoff' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Allow editors', 'estandar-kickoff' ); ?></th>
				<td>
					<label><input type="checkbox" name="estandar_pr_allow_editors" value="1" <?php checked( $settings['allow_editors'], 1 ); ?>> <?php esc_html_e( 'Permitir bypass a editores', 'estandar-kickoff' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_whitelist_ips"><?php esc_html_e( 'Whitelist IPs', 'estandar-kickoff' ); ?></label></th>
				<td>
					<textarea name="estandar_pr_whitelist_ips" id="estandar_pr_whitelist_ips" rows="5" class="large-text code"><?php echo esc_textarea( $settings['whitelist_ips'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Preview token', 'estandar-kickoff' ); ?></th>
				<td>
					<code class="estandar-pr-token"><?php echo esc_html( $settings['preview_token'] ); ?></code>
					<p><a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $preview_url ); ?></a></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_token_expiry"><?php esc_html_e( 'Token expiry', 'estandar-kickoff' ); ?></label></th>
				<td>
					<input type="datetime-local" name="estandar_pr_token_expiry" id="estandar_pr_token_expiry" value="<?php echo esc_attr( $expiry_value ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_title"><?php esc_html_e( 'Maintenance title', 'estandar-kickoff' ); ?></label></th>
				<td>
					<input type="text" name="estandar_pr_title" id="estandar_pr_title" value="<?php echo esc_attr( $settings['title'] ); ?>" class="large-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_subtitle"><?php esc_html_e( 'Maintenance subtitle', 'estandar-kickoff' ); ?></label></th>
				<td>
					<textarea name="estandar_pr_subtitle" id="estandar_pr_subtitle" rows="3" class="large-text"><?php echo esc_textarea( $settings['subtitle'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_redirect_page_id"><?php esc_html_e( 'Página de redirección', 'estandar-kickoff' ); ?></label></th>
				<td>
					<select name="estandar_pr_redirect_page_id" id="estandar_pr_redirect_page_id">
						<option value="0" <?php selected( $settings['redirect_page_id'], 0 ); ?>><?php esc_html_e( '— Home (por defecto) —', 'estandar-kickoff' ); ?></option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $settings['redirect_page_id'], (int) $page->ID ); ?>>
								<?php echo esc_html( get_the_title( $page ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Página a la que se redirige al visitante al terminar el countdown.', 'estandar-kickoff' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estandar_pr_redirect_delay"><?php esc_html_e( 'Delay de redirección (segundos)', 'estandar-kickoff' ); ?></label></th>
				<td>
					<input type="number" name="estandar_pr_redirect_delay" id="estandar_pr_redirect_delay" value="<?php echo esc_attr( $settings['redirect_delay'] ); ?>" min="1" max="300" class="small-text">
					<p class="description"><?php esc_html_e( 'Cuántos segundos esperar antes de redirigir. Mínimo 1.', 'estandar-kickoff' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registrar en Analytics', 'estandar-kickoff' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="estandar_pr_track_analytics" value="1" <?php checked( $settings['track_analytics'], 1 ); ?>>
						<?php esc_html_e( 'Registrar cada acceso a la pantalla de mantenimiento como evento en Estandar Analytics', 'estandar-kickoff' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Requiere Estandar Analytics activo. Solo registra visitantes con cookie de sesión activa (ea_sid). Tipo de evento: pr_maintenance_view.', 'estandar-kickoff' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Page checklist', 'estandar-kickoff' ); ?></h2>
		<div class="estandar-pr-pages">
			<?php foreach ( $pages as $page ) : ?>
				<label>
					<input type="checkbox" name="estandar_pr_released_pages[]" value="<?php echo esc_attr( $page->ID ); ?>" <?php checked( in_array( (int) $page->ID, $settings['released_pages'], true ) ); ?>>
					<span><?php echo esc_html( get_the_title( $page ) ); ?></span>
					<a href="<?php echo esc_url( get_permalink( $page->ID ) ); ?>"
						target="_blank"
						rel="noopener noreferrer"
						class="estandar-pr-permalink"><?php echo esc_html( get_permalink( $page->ID ) ); ?></a>
					<a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>"
						class="estandar-pr-edit-link"><?php esc_html_e( 'Edit', 'estandar-kickoff' ); ?></a>
				</label>
			<?php endforeach; ?>
			<?php if ( empty( $pages ) ) : ?>
				<p><?php esc_html_e( 'No hay páginas publicadas.', 'estandar-kickoff' ); ?></p>
			<?php endif; ?>
		</div>

		<?php submit_button( __( 'Guardar ajustes', 'estandar-kickoff' ) ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="estandar-pr-regen-form">
		<input type="hidden" name="action" value="estandar_pr_regen_token">
		<?php wp_nonce_field( ESTANDAR_PR_NONCE_REGEN ); ?>
		<?php submit_button( __( 'Regenerate token', 'estandar-kickoff' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
