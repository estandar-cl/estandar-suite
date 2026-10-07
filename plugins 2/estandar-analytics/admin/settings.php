<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$roles    = wp_roles() ? wp_roles()->get_names() : array();
$locales  = EA_I18n::available_locales();
$wa_status = EA_WhatsApp::status();
$wa_bridge_url = rest_url( 'ea/v1/whatsapp/' . ( $settings['whatsapp_bridge_slug'] ?? 'run' ) );
?>
	<?php
	?>

	<form method="post" action="options.php" class="ea-settings-form">
		<?php settings_fields( 'ea_settings_group' ); ?>
		<?php $s = $settings; ?>

		<section class="ea-panel">
			<header class="ea-panel__head"><h2><?php esc_html_e( 'General', 'estandar-analytics' ); ?></h2></header>
			<table class="form-table ea-form-table">
				<tr>
					<th><?php esc_html_e( 'Idioma del panel', 'estandar-analytics' ); ?></th>
					<td>
						<select name="ea_settings[admin_locale]">
							<?php foreach ( $locales as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['admin_locale'] ?? 'es_ES', $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Español por defecto. Inglés disponible; arquitectura preparada para más idiomas.', 'estandar-analytics' ); ?></p>
					</td>
				</tr>
					<tr>
						<th><?php esc_html_e( 'Activar Analytics', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[enable_tracking]" value="1" <?php checked( ! empty( $s['enable_tracking'] ) ); ?>><span><?php esc_html_e( 'Habilitado', 'estandar-analytics' ); ?></span></label>
							<p class="description"><?php esc_html_e( 'Registra sesiones, páginas vistas y eventos comerciales del sitio.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Filtrar bots', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[bot_filtering]" value="1" <?php checked( ! empty( $s['bot_filtering'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Reduce visitas automáticas que pueden inflar las estadísticas.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Excluir administradores', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[exclude_admin]" value="1" <?php checked( ! empty( $s['exclude_admin'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Evita que la navegación interna contamine los datos comerciales.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Ignorar roles con sesión', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[ignore_logged_in_roles]" value="1" <?php checked( ! empty( $s['ignore_logged_in_roles'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'No mide visitas de usuarios conectados con los roles marcados abajo.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Anonimizar IP', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[anonymize_ip]" value="1" <?php checked( ! empty( $s['anonymize_ip'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Guarda identificadores anonimizados en lugar de IPs completas.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
				<tr>
					<th><?php esc_html_e( 'Retención (días)', 'estandar-analytics' ); ?></th>
						<td>
							<select name="ea_settings[retention_days]">
							<?php foreach ( array( 30, 90, 180, 365 ) as $days ) : ?>
								<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( (int) $s['retention_days'], $days ); ?>><?php echo esc_html( (string) $days ); ?></option>
							<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Tiempo que se conservarán los datos analíticos antes de limpieza automática.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
			</table>
		</section>

		<section class="ea-panel" id="ea-whatsapp-settings">
			<header class="ea-panel__head"><h2><?php esc_html_e( 'WhatsApp', 'estandar-analytics' ); ?></h2></header>
			<table class="form-table ea-form-table">
				<tr><th>Detector automático</th><td><label class="ea-switch"><input type="checkbox" name="ea_settings[enable_whatsapp_tracking]" value="1" <?php checked( ! empty( $s['enable_whatsapp_tracking'] ) ); ?>><span>Habilitado</span></label></td></tr>
				<tr><th>Bridge URL</th><td><input id="ea-whatsapp-bridge-url" class="large-text code" type="text" readonly value="<?php echo esc_attr( $wa_bridge_url ); ?>"> <button type="button" class="button" data-ea-copy="#ea-whatsapp-bridge-url">Copiar</button> <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'test', '1', $wa_bridge_url ) ); ?>">Probar</a></td></tr>
				<tr><th>Slug configurable</th><td><input name="ea_settings[whatsapp_bridge_slug]" type="text" value="<?php echo esc_attr( $s['whatsapp_bridge_slug'] ?? 'run' ); ?>" pattern="[a-z0-9-]+"></td></tr>
				<tr><th>Aliases activos</th><td><code><?php echo esc_html( implode( ', ', (array) ( $s['whatsapp_bridge_aliases'] ?? array() ) ) ?: '—' ); ?></code></td></tr>
				<tr><th>Estado</th><td><?php echo empty( $wa_status['diagnostics'] ) ? 'OK' : 'REVISAR'; ?> · Bridge <?php echo $wa_status['bridge_active'] ? 'activo' : 'inactivo'; ?> · Detector <?php echo $wa_status['detector_active'] ? 'activo' : 'inactivo'; ?></td></tr>
				<tr><th>Actividad</th><td>Último clic: <?php echo esc_html( $wa_status['last_use'] ?: '—' ); ?> · Último confirmed: <?php echo esc_html( $wa_status['last_confirmed'] ?: '—' ); ?></td></tr>
				<tr><th>Eventos</th><td>Observed: <?php echo esc_html( (string) $wa_status['observed'] ); ?> · Confirmed: <?php echo esc_html( (string) $wa_status['confirmed'] ); ?> · Conversion Rate: <?php echo esc_html( (string) $wa_status['conversion_rate'] ); ?>%</td></tr>
				<tr><th>Fuentes</th><td>Detector-only: <?php echo esc_html( (string) $wa_status['detector_only'] ); ?> · Bridge-only: <?php echo esc_html( (string) $wa_status['bridge_only'] ); ?> · Enlaces directos: <?php echo esc_html( (string) $wa_status['direct_links'] ); ?></td></tr>
				<tr><th>Contadores</th><td>Total: <?php echo esc_html( (string) $wa_status['total'] ); ?> · Duplicados evitados: <?php echo esc_html( (string) $wa_status['duplicates'] ); ?> · Errores: <?php echo esc_html( (string) $wa_status['errors'] ); ?></td></tr>
				<tr><th>Diagnóstico</th><td><?php if ( empty( $wa_status['diagnostics'] ) ) : ?>Sin anomalías detectadas.<?php else : ?><ul><?php foreach ( $wa_status['diagnostics'] as $recommendation ) : ?><li><?php echo esc_html( $recommendation ); ?></li><?php endforeach; ?></ul><?php endif; ?></td></tr>
			</table>
		</section>

		<section class="ea-panel">
			<header class="ea-panel__head"><h2><?php esc_html_e( 'Eventos', 'estandar-analytics' ); ?></h2></header>
			<table class="form-table ea-form-table">
					<tr>
						<th><?php esc_html_e( 'Tracking de clics', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[enable_click_tracking]" value="1" <?php checked( ! empty( $s['enable_click_tracking'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Mide clics relevantes como WhatsApp, teléfono, email y botones configurados.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tracking de scroll', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[enable_scroll_tracking]" value="1" <?php checked( ! empty( $s['enable_scroll_tracking'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Registra profundidad de lectura: 25%, 50%, 75% y 100%.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tracking de formularios', 'estandar-analytics' ); ?></th>
						<td>
							<label class="ea-switch"><input type="checkbox" name="ea_settings[enable_form_tracking]" value="1" <?php checked( ! empty( $s['enable_form_tracking'] ) ); ?>></label>
							<p class="description"><?php esc_html_e( 'Mide envíos de formularios del sitio cuando el tracker puede detectarlos.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Selectores personalizados', 'estandar-analytics' ); ?></th>
						<td>
							<textarea name="ea_settings[custom_selectors]" rows="4" class="large-text" placeholder=".btn-whatsapp&#10;.btn-cotizar&#10;.hero-cta"><?php echo esc_textarea( implode( "\n", (array) $s['custom_selectors'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Mide clics en botones específicos del sitio. Ingresa un selector CSS por línea.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Páginas que indican una conversión', 'estandar-analytics' ); ?></th>
						<td>
							<textarea name="ea_settings[thank_you_paths]" rows="3" class="large-text" placeholder="/gracias&#10;/thank-you&#10;/cotizacion-recibida"><?php echo esc_textarea( implode( "\n", (array) $s['thank_you_paths'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Cuando un usuario llegue a una de estas URLs, Analytics registrará una conversión. Usa solo páginas finales reales.', 'estandar-analytics' ); ?></p>
						</td>
					</tr>
			</table>
		</section>

			<section class="ea-panel">
				<header class="ea-panel__head"><h2><?php esc_html_e( 'Roles ignorados', 'estandar-analytics' ); ?></h2></header>
				<p class="description" style="margin:0 0 12px;"><?php esc_html_e( 'Recomendado: mantener Administrador y Editor marcados para que las visitas internas no alteren las estadísticas.', 'estandar-analytics' ); ?></p>
				<div class="ea-role-grid">
				<?php foreach ( $roles as $role_key => $role_label ) : ?>
					<label><input type="checkbox" name="ea_settings[ignored_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $s['ignored_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role_label ) ); ?></label>
				<?php endforeach; ?>
			</div>
		</section>

			<section class="ea-panel">
				<header class="ea-panel__head">
					<h2><?php esc_html_e( '¿Qué acciones consideramos una conversión?', 'estandar-analytics' ); ?></h2>
					<p style="margin:4px 0 0;font-size:12px;color:#646970;font-weight:normal;"><?php esc_html_e( 'Estas acciones cuentan como objetivos comerciales dentro de Analytics.', 'estandar-analytics' ); ?></p>
				</header>
				<table class="ea-table" id="ea-conversions-table">
					<thead><tr><th><?php esc_html_e( 'Clave interna', 'estandar-analytics' ); ?></th><th><?php esc_html_e( 'Nombre visible', 'estandar-analytics' ); ?></th><th><?php esc_html_e( 'Acción medida', 'estandar-analytics' ); ?></th><th><?php esc_html_e( 'Valor opcional', 'estandar-analytics' ); ?></th></tr></thead>
				<tbody>
				<?php
				$conversions = (array) $s['conversions'];
				if ( empty( $conversions ) ) {
					$conversions = array( array( 'key' => '', 'label' => '', 'type' => 'whatsapp_click', 'value' => '' ) );
				}
				foreach ( $conversions as $i => $conv ) :
					?>
					<tr>
						<td><input type="text" name="ea_settings[conversions][<?php echo esc_attr( (string) $i ); ?>][key]" value="<?php echo esc_attr( (string) ( $conv['key'] ?? '' ) ); ?>"></td>
						<td><input type="text" name="ea_settings[conversions][<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( (string) ( $conv['label'] ?? '' ) ); ?>"></td>
						<td>
							<select name="ea_settings[conversions][<?php echo esc_attr( (string) $i ); ?>][type]">
								<?php foreach ( array( 'whatsapp_click', 'phone_click', 'email_click', 'thank_you_page', 'custom_selector', 'form_submit' ) as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $conv['type'] ?? '', $type ); ?>><?php echo esc_html( $type ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" name="ea_settings[conversions][<?php echo esc_attr( (string) $i ); ?>][value]" value="<?php echo esc_attr( (string) ( $conv['value'] ?? '' ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<?php submit_button( __( 'Guardar cambios', 'estandar-analytics' ) ); ?>
	</form>
