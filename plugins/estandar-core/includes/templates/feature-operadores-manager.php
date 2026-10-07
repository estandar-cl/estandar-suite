<?php
/**
 * Estandar Suite — Core · Ventana de OPERADORES (sub-pestaña; alias en Leads).
 * Fuente: es_operators. Alta + edición por fila (email/whatsapp/activo).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( isset( $_GET['operator_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Operador(es) guardado(s).</p></div><?php endif;
if ( isset( $_GET['operator_added'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Operador creado.</p></div><?php endif;
global $wpdb;
$rows = (array) $wpdb->get_results(
	"SELECT id, name, email, project_slug, whatsapp_number, active FROM {$wpdb->prefix}es_operators ORDER BY project_slug ASC, name ASC",
	ARRAY_A
);
echo \Estandar\Core\Admin::help_html( 'operadores', '<strong>Operadores por proyecto</strong> — define el correo de notificación y el WhatsApp de quien atiende cada proyecto (fuente real: <code>es_operators</code>).' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;margin:10px 0 16px;">
	<?php wp_nonce_field( 'estandar_suite_core_operators_add' ); ?>
	<input type="hidden" name="action" value="estandar_suite_core_operators_add">
	<h4 style="margin:4px 0;">＋ Crear operador</h4>
	<table class="form-table" role="presentation" style="margin:0;">
		<tr><th scope="row"><label>Nombre</label></th><td><input type="text" name="name" class="regular-text" style="width:100%;" placeholder="Nombre del operador"></td></tr>
		<tr><th scope="row"><label>Email</label></th><td><input type="email" name="email" class="regular-text" style="width:100%;" placeholder="quien@atiende.cl"></td></tr>
		<tr><th scope="row"><label>Teléfono</label></th><td><input type="text" name="whatsapp" class="regular-text" style="width:100%;" placeholder="+569… (opcional)"></td></tr>
		<tr><th scope="row"><label for="es-op-project">Proyecto</label></th><td>
			<?php
			$op_default_slug = '';
			if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
				$op_default_slug = \Estandar\Core\Leads\ProjectRegistry::auto_project_slug();
			}
			$op_projects = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? \Estandar\Core\Leads\ProjectRegistry::manager_list() : array();
			?>
			<select id="es-op-project" name="project_slug" style="width:100%;">
				<?php foreach ( (array) $op_projects as $op_p ) : $op_pid = sanitize_title( (string) ( $op_p['project_id'] ?? '' ) ); if ( $op_pid === '' ) { continue; }
					$op_sel = isset( $_GET['project'] ) ? ( sanitize_key( (string) $_GET['project'] ) === $op_pid ) : ( $op_pid === $op_default_slug );
				?>
					<option value="<?php echo esc_attr( $op_pid ); ?>" <?php selected( $op_sel, true ); ?>><?php echo esc_html( trim( (string) ( $op_p['label'] ?? $op_pid ) . ' (' . $op_pid . ')' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description" style="display:block;margin-top:4px;">Todo operador pertenece a un proyecto. Por defecto queda en el proyecto del sitio.</span>
		</td></tr>
	</table>
	<p style="margin:8px 0 0;"><?php submit_button( 'Crear operador', 'primary', 'add_operator', false ); ?></p>
</form>

<?php if ( ! $rows ) : ?><p class="description">Sin operadores registrados.</p><?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px;">
	<?php wp_nonce_field( 'estandar_suite_core_operators_save' ); ?>
	<input type="hidden" name="action" value="estandar_suite_core_operators_save">
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Nombre</th><th>Proyecto</th><th>Email de notificación</th><th>Teléfono</th><th>Activo</th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $r ) : $oid = (int) $r['id']; ?>
			<tr>
				<td><?php echo $oid; ?></td>
				<td><input type="text" name="operators[<?php echo $oid; ?>][name]" value="<?php echo esc_attr( (string) $r['name'] ); ?>" style="width:100%;min-width:160px;"></td>
				<td>
					<select name="operators[<?php echo $oid; ?>][project_slug]" style="width:100%;min-width:170px;">
						<?php foreach ( (array) $op_projects as $op_p ) : $op_pid = sanitize_title( (string) ( $op_p['project_id'] ?? '' ) ); if ( $op_pid === '' ) { continue; } ?>
							<option value="<?php echo esc_attr( $op_pid ); ?>" <?php selected( sanitize_title( (string) $r['project_slug'] ), $op_pid ); ?>><?php echo esc_html( trim( (string) ( $op_p['label'] ?? $op_pid ) . ' (' . $op_pid . ')' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input type="email" name="operators[<?php echo $oid; ?>][email]" value="<?php echo esc_attr( (string) $r['email'] ); ?>" style="width:100%;min-width:260px;"></td>
				<td><input type="text" name="operators[<?php echo $oid; ?>][whatsapp]" value="<?php echo esc_attr( (string) $r['whatsapp_number'] ); ?>" style="width:100%;min-width:150px;"></td>
				<td><input type="hidden" name="operators[<?php echo $oid; ?>][active]" value="0"><input type="checkbox" name="operators[<?php echo $oid; ?>][active]" value="1" <?php checked( (int) $r['active'], 1 ); ?>></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p style="margin:8px 0 0;"><?php submit_button( 'Guardar operadores', 'primary', 'save_operators', false ); ?></p>
</form>
<?php endif; ?>
