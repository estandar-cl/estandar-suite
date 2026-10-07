<?php
/**
 * Estandar Suite — Capture · Popups (sub-tab con sub-sub-tabs).
 * Rescata estandar-pop: listado v2 (CRUD), tracking legado, configuración global.
 * Sub-sub-tabs: Resumen · Popups · Tracking · Configuración · Settings.
 * Variables: $feature, $feature_view, $feature_fields, $feature_option, $feature_saved.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$v2_popups = isset( $feature_view['v2_popups'] ) ? $feature_view['v2_popups'] : array();
$legacy    = isset( $feature_view['popups'] ) ? $feature_view['popups'] : array();
$edit_id    = isset( $_GET['ep'] ) ? (int) $_GET['ep'] : 0;
$edit_popup = $edit_id ? \Estandar\Capture\Popups::get( $edit_id ) : null;
$groups     = \Estandar\Capture\Admin::popups_settings_groups();
$popup_opt  = get_option( 'estandar_suite_capture_popups_settings', array() );
$popup_opt  = is_array( $popup_opt ) ? $popup_opt : array();

$ssubs = array( 'resumen' => 'Resumen', 'popups' => 'Popups', 'tracking' => 'Tracking', 'configuracion' => 'Configuración', 'settings' => 'Settings' );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Para qué sirve?</strong> Administra los <em>popups</em> de la suite v2: crearlos/editarlos/eliminarlos, ver su tracking (vistas/aperturas/clicks) y definir la configuración visual por defecto.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-capture', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( isset( $_GET['popups_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings de popups guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['popup_saved'] ) ) : ?><div class="notice notice-<?php echo $_GET['popup_saved'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['popup_saved'] ? 'Popup guardado.' : 'No se pudo guardar.'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['popup_deleted'] ) ) : ?><div class="notice notice-<?php echo $_GET['popup_deleted'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['popup_deleted'] ? 'Popup eliminado.' : 'No se pudo eliminar.'; ?></p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Popups v2</strong></td><td><?php echo count( $v2_popups ); ?></td></tr>
		<tr><td><strong>Popups legados</strong></td><td><?php echo count( $legacy ); ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'popups' ) : $creator_id = 'es-creator-popups'; ?>
<h4 style="display:flex;align-items:center;gap:8px;">
	<span>Popups (<?php echo count( $v2_popups ); ?>)</span>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&np=1' ) ); ?>" style="font-weight:600;">+ Crear popup</a>
</h4>

<?php if ( $edit_popup || isset( $_GET['np'] ) ) : ?>
<h5><?php echo $edit_popup ? 'Editar popup (#' . (int) $edit_popup['id'] . ')' : 'Nuevo popup'; ?></h5>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
	<?php wp_nonce_field( 'estandar_suite_capture_save_popup' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_save_popup">
	<input type="hidden" name="popup_id" value="<?php echo $edit_popup ? (int) $edit_popup['id'] : 0; ?>">
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="pp-name">Nombre</label></th><td><input id="pp-name" name="name" class="regular-text" required value="<?php echo esc_attr( $edit_popup['name'] ?? '' ); ?>"></td></tr>
		<tr><th scope="row"><label for="pp-slug">Slug</label></th><td><input id="pp-slug" name="slug" class="regular-text" value="<?php echo esc_attr( $edit_popup['slug'] ?? '' ); ?>"></td></tr>
		<tr><th scope="row"><label for="pp-status">Estado</label></th><td><select id="pp-status" name="status">
			<?php foreach ( array( 'active' => 'Activo', 'draft' => 'Borrador', 'archived' => 'Archivado' ) as $sk => $sl ) : ?>
				<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $edit_popup['status'] ?? 'draft', $sk ); ?>><?php echo esc_html( $sl ); ?></option>
			<?php endforeach; ?>
		</select></td></tr>
		<tr><th scope="row"><label for="pp-config">Config (JSON)</label></th><td><textarea id="pp-config" name="config_json" class="large-text code" rows="4"><?php echo esc_textarea( $edit_popup['config_json'] ?? '{}' ); ?></textarea></td></tr>
	</table>
	<?php submit_button( $edit_popup ? 'Actualizar popup' : 'Crear popup', 'primary', 'save', false ); ?>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups' ) ); ?>">Cancelar</a>
</form>
<?php endif; ?>

<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Creado</th></tr></thead>
	<tbody>
	<?php foreach ( $v2_popups as $p ) : ?>
		<tr>
			<td><?php echo (int) $p['id']; ?></td>
			<td><strong><?php echo esc_html( $p['name'] ); ?></strong><div class="row-actions">
				<span class="edit"><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&ep=' . (int) $p['id'] ) ); ?>">Editar</a> | </span>
				<span class="trash">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('¿Mover a la papelera el popup «<?php echo esc_js( $p['name'] ); ?>»?')">
						<?php wp_nonce_field( 'estandar_suite_capture_delete_popup' ); ?>
						<input type="hidden" name="action" value="estandar_suite_capture_delete_popup">
						<input type="hidden" name="popup_id" value="<?php echo (int) $p['id']; ?>">
						<input type="hidden" name="confirm" value="yes">
						<button class="button-link submitdelete" type="submit">Papelera</button>
					</form>
				</span>
			</div></td>
			<td><?php echo esc_html( $p['status'] ); ?></td>
			<td><?php echo esc_html( $p['created_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2_popups ) ) : ?><tr><td colspan="4">Sin popups v2.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'tracking' ) : ?>
<h4>Popups legados (tracking, v1)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Título</th><th>Estado</th><th>Vistas</th><th>Aperturas</th><th>Clicks</th></tr></thead>
	<tbody>
	<?php foreach ( $legacy as $l ) : ?>
		<tr>
			<td><?php echo (int) $l['id']; ?></td>
			<td><?php echo esc_html( $l['title'] ); ?></td>
			<td><?php echo esc_html( $l['status'] ); ?></td>
			<td><?php echo (int) $l['views']; ?></td>
			<td><?php echo (int) $l['opens']; ?></td>
			<td><?php echo (int) $l['clicks']; ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $legacy ) ) : ?><tr><td colspan="6">Sin popups legados con tracking.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'configuracion' ) : ?>
<h4>Configuración global de popups</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_popups_settings', 'estandar_suite_capture_popups_settings', $groups['configuracion'], '', array( 'group' => 'configuracion' ) ); ?>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', $feature_option, $feature_fields, $feature ); ?>
<?php endif; ?>
