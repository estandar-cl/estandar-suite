<?php
/**
 * Estandar Suite — Core · Configuración: TELEMETRÍA.
 * Bloque compartido (sub-pestaña "Configuración" y su sub-pestaña alias).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! isset( $telemetry_saved ) ) {
	$telemetry_saved = isset( $_GET['telemetry_saved'] );
}
?>
		<?php if ( $telemetry_saved ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de telemetría guardada.</p></div><?php endif; ?>
		<?php $tel = \Estandar\Core\Telemetry::settings(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
			<?php wp_nonce_field( 'estandar_suite_save_telemetry_settings' ); ?>
			<input type="hidden" name="action" value="estandar_suite_save_telemetry_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Registrar telemetría</th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $tel['enabled'] ) ); ?>> Activa el registro de eventos técnicos</label><?php echo \Estandar\Core\Optimal::note( $tel['enabled'], 1, 'activado' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="es-tel-ret">Retención (días)</label></th>
					<td><input id="es-tel-ret" type="number" name="retention_days" value="<?php echo (int) $tel['retention_days']; ?>" min="1" max="365" style="width:120px;"> <span class="description">los eventos más antiguos se eliminan automáticamente cada día</span><?php echo \Estandar\Core\Optimal::note( $tel['retention_days'], 30, '30' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="es-tel-min">Nivel mínimo a guardar</label></th>
					<td>
						<select id="es-tel-min" name="min_level">
							<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $lvl ) : ?>
								<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $tel['min_level'], $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description">debug registra todo; error solo fallos</span><?php echo \Estandar\Core\Optimal::note( $tel['min_level'], 'info', 'info' ); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar telemetría', 'primary', 'save', false ); ?>
		</form>
