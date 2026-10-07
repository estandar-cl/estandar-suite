<?php
/**
 * Estandar Suite — Core · Manager de PROYECTOS (sub-pestaña primera).
 * Registry estandar_project_registry. Vista limpia estilo WP: botón
 * "＋ Crear nuevo proyecto" + Lista WP de proyectos. El formulario de
 * alta/edición aparece solo al crear/editar. Genérico, sin sesgo inmobiliario.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
$rows = $PR !== '' ? $PR::manager_list() : array();
$edit_slug = sanitize_title( wp_unslash( (string) ( $_GET['project'] ?? '' ) ) );
$edit = array();
if ( $edit_slug !== '' ) {
	foreach ( $rows as $r ) {
		if ( sanitize_title( (string) $r['project_id'] ) === $edit_slug ) {
			$edit = $r;
			break;
		}
	}
}
$show_form = (bool) $edit || isset( $_GET['new'] );
$Ops      = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
$ops_rows = $Ops !== '' ? $Ops::list() : array();
$admin_email = (string) get_option( 'admin_email', '' );
$admin_name  = 'Administrador de la web';
$au = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'display_name', 'user_email' ) ) );
if ( $au ) {
	$admin_name  = (string) $au[0]->display_name;
	$admin_email = (string) $au[0]->user_email;
}
$cur_email = sanitize_email( (string) ( $edit['operator_email'] ?? '' ) );
$cur_known = false;
foreach ( (array) $ops_rows as $orow ) {
	if ( sanitize_email( (string) ( $orow['email'] ?? '' ) ) === $cur_email ) {
		$cur_known = true;
	}
}
$proj_url = admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos' );

$errors = array(
	'project_label_required'   => 'El nombre del proyecto es obligatorio.',
	'project_slug_exists'      => 'Ya existe un proyecto con ese slug. Elige otro o deja el slug vacío (se genera del nombre).',
	'project_insert_failed'    => 'No se pudo crear el proyecto en el registro.',
	'project_update_failed'    => 'No se pudo actualizar el proyecto.',
	'project_missing'          => 'Proyecto no encontrado.',
	'project_nothing_to_update'=> 'No hay cambios que guardar.',
	'project_auto_failed'      => 'No se pudo crear el proyecto del sitio.',
	'project_invalid'          => 'El proyecto elegido no está activo.',
	'delete_requires_target'   => 'Este proyecto tiene formularios asociados: elige a qué proyecto se trasladan antes de eliminarlo.',
);
?>

<?php if ( isset( $_GET['prj_first'] ) ) : ?><div class="notice notice-info is-dismissible"><p><strong>Todo catcher pertenece a un proyecto.</strong> Crea aquí el primer proyecto (o usa el «proyecto del sitio»).</p></div><?php endif; ?>
<?php if ( isset( $_GET['project_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Proyecto guardado.</p></div><?php endif; ?>
<?php if ( isset( $_GET['project_archived'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Estado del proyecto actualizado.</p></div><?php endif; ?>
<?php if ( isset( $_GET['project_deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Proyecto eliminado.</p></div><?php endif; ?>
<?php if ( isset( $_GET['project_error'] ) ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $errors[ sanitize_key( wp_unslash( $_GET['project_error'] ) ) ] ?? 'No se pudo guardar el proyecto.' ); ?></p></div><?php endif; ?>

<?php
// ── Pantalla "Eliminar proyecto" (traslado de formularios, estilo WP) ──
$del_slug = sanitize_title( wp_unslash( (string) ( $_GET['delete'] ?? '' ) ) );
if ( $del_slug !== '' ) :
	$del_row = array();
	foreach ( $rows as $r ) {
		if ( sanitize_title( (string) $r['project_id'] ) === $del_slug ) {
			$del_row = $r;
			break;
		}
	}
	$Forms = class_exists( '\Estandar\Capture\Forms' ) ? '\Estandar\Capture\Forms' : '';
	$assoc = ( $Forms !== '' ) ? $Forms::forms_of_project( $del_slug ) : array();
	?>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:720px;">
		<h3 style="margin:0 0 8px;">Eliminar proyecto</h3>
		<?php if ( ! $del_row ) : ?>
			<p class="description">Proyecto no encontrado. <a href="<?php echo esc_url( $proj_url ); ?>">Volver</a></p>
		<?php else : ?>
			<p><strong><?php echo esc_html( (string) ( $del_row['label'] ?? '' ) ); ?></strong> <code><?php echo esc_html( $del_slug ); ?></code> — se eliminará del registro de proyectos.</p>
			<?php if ( $assoc ) : ?>
				<p><?php echo count( $assoc ); ?> formulario(s) pertenecen a este proyecto. Elige a qué proyecto se traslada su propiedad:</p>
				<ul style="list-style:square;padding-left:20px;">
					<?php foreach ( array_slice( $assoc, 0, 20 ) as $a ) : ?>
						<li><?php echo esc_html( (string) $a['name'] ); ?> <em>(#<?php echo (int) $a['id']; ?>)</em></li>
					<?php endforeach; ?>
					<?php if ( count( $assoc ) > 20 ) : ?><li>… y <?php echo count( $assoc ) - 20; ?> más.</li><?php endif; ?>
				</ul>
			<?php else : ?>
				<p class="description">Este proyecto no tiene formularios asociados; puedes eliminarlo directamente.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'estandar_suite_core_projects_delete' ); ?>
				<input type="hidden" name="action" value="estandar_suite_core_projects_delete">
				<input type="hidden" name="project" value="<?php echo esc_attr( $del_slug ); ?>">
				<?php if ( $assoc ) : ?>
				<table class="form-table" role="presentation" style="margin:6px 0;">
					<tr><th scope="row"><label for="es-del-dest">Trasladar a</label></th><td>
						<select id="es-del-dest" name="dest" style="width:100%;" required>
							<option value="">— Elige el proyecto destino —</option>
							<?php foreach ( $rows as $r ) : $rid = sanitize_title( (string) $r['project_id'] ); if ( $rid === '' || $rid === $del_slug ) { continue; } ?>
								<option value="<?php echo esc_attr( $rid ); ?>"><?php echo esc_html( trim( (string) ( $r['label'] ?? $rid ) . ' (' . $rid . ')' ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description" style="display:block;margin-top:4px;">Sus formularios se trasladarán a este proyecto y luego se eliminará <code><?php echo esc_html( $del_slug ); ?></code>.</span>
					</td></tr>
				</table>
				<?php endif; ?>
				<p style="margin:6px 0;">
					<?php submit_button( $assoc ? 'Trasladar y eliminar' : 'Eliminar proyecto', 'delete', 'delete_confirm', false ); ?>
					<a class="button" href="<?php echo esc_url( $proj_url ); ?>">Cancelar</a>
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php return; ?>
<?php endif; ?>


<?php if ( ! $edit ) : ?>
<p style="margin:0 0 12px;">
	<a class="button button-primary button-hero" id="es-prj-new-btn" href="<?php echo esc_url( add_query_arg( 'new', '1', $proj_url ) ); ?>">＋ Crear nuevo proyecto</a>
</p>
<?php endif; ?>

<div id="es-new-project"<?php echo $show_form ? '' : ' style="display:none;"'; ?>>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:680px;margin:0 0 16px;">
		<h3 style="margin:0 0 10px;"><?php echo $edit ? 'Editar proyecto' : 'Nuevo proyecto'; ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_core_projects_save' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_projects_save">
			<?php if ( $edit ) : ?><input type="hidden" name="project_slug" value="<?php echo esc_attr( $edit['project_id'] ); ?>"><?php endif; ?>
			<table class="form-table" role="presentation" style="margin:0;">
				<tr><th scope="row"><label for="es-prj-label">Nombre *</label></th><td><input id="es-prj-label" name="label" class="regular-text" style="width:100%;" required value="<?php echo esc_attr( (string) ( $edit['label'] ?? '' ) ); ?>" placeholder="Ej: Proyecto Centro"></td></tr>
				<tr><th scope="row"><label for="es-prj-slug">Slug</label></th><td><input id="es-prj-slug" name="new_slug" class="regular-text" style="width:100%;" value="<?php echo esc_attr( $edit ? (string) $edit['project_id'] : '' ); ?>" <?php echo $edit ? 'readonly' : ''; ?> placeholder="proyecto-centro">
					<?php if ( ! $edit ) : ?><span class="description">Vacío = se genera desde el nombre.</span><?php endif; ?></td></tr>
				<?php if ( $edit ) :
					$assigned_ids = array();
					if ( class_exists( '\Estandar\Core\Leads\Operators' ) ) {
						foreach ( \Estandar\Core\Leads\Operators::assigned( (string) $edit['project_id'] ) as $ao ) {
							$assigned_ids[ (int) ( $ao['id'] ?? 0 ) ] = true;
						}
					}
				?>
				<tr><th scope="row">Operadores</th><td>
					<input type="hidden" name="operator_ids_present" value="1">
					<?php if ( empty( $ops_rows ) ) : ?>
						<p class="description">Todavía no hay operadores. Créalos en la sub-pestaña <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=operadores' ) ); ?>">Operadores</a>.</p>
					<?php else : ?>
						<?php foreach ( (array) $ops_rows as $orow ) : $oid = (int) ( $orow['id'] ?? 0 ); if ( $oid <= 0 ) { continue; } ?>
							<label style="display:flex;gap:8px;align-items:center;margin:4px 0;">
								<input type="checkbox" name="operator_ids[]" value="<?php echo esc_attr( $oid ); ?>" <?php checked( isset( $assigned_ids[ $oid ] ) ); ?>>
								<span><strong><?php echo esc_html( (string) ( $orow['name'] ?? '' ) ); ?></strong>
								<?php if ( ! empty( $orow['email'] ) ) : ?> · <?php echo esc_html( (string) $orow['email'] ); ?><?php endif; ?>
								<?php if ( ! empty( $orow['whatsapp_number'] ) ) : ?> · <?php echo esc_html( (string) $orow['whatsapp_number'] ); ?><?php endif; ?></span>
							</label>
						<?php endforeach; ?>
						<span class="description" style="display:block;margin-top:4px;">Marca uno o varios operadores que atienden este proyecto. Desmarcar un operador lo devuelve al proyecto del sitio.</span>
					<?php endif; ?>
				</td></tr>
				<?php else : ?>
				<tr><th scope="row">Operadores</th><td><p class="description">Tras crear el proyecto podrás asignarle uno o varios operadores desde esta misma edición.</p></td></tr>
				<?php endif; ?>
				<?php if ( $edit ) :
					$skins_all = get_option( 'estandar_suite_core_project_branding', array() );
					$skins_all = is_array( $skins_all ) ? $skins_all : array();
					$bskin = $skins_all[ $edit['project_id'] ] ?? array();
				?>
				<tr><th scope="row" colspan="2" style="padding-bottom:0;"><h4 style="margin:14px 0 2px;">Branding de correo (opcional)</h4>
					<p class="description" style="margin:0 0 4px;">Identidad visual de los correos de este proyecto (color, logo, cabecera, footer). Vacío = branding general del sitio.</p></th></tr>
				<tr><th scope="row"><label for="es-prj-brand-color">Color de marca</label></th><td><input id="es-prj-brand-color" class="es-color-field" type="text" name="brand[brand_color]" value="<?php echo esc_attr( (string) ( $bskin['brand_color'] ?? '' ) ); ?>" placeholder="#1a73e8" style="width:180px;" data-default-color="#1a73e8"></td></tr>
				<tr><th scope="row"><label for="es-prj-brand-logo">Logo</label></th><td>
					<input id="es-prj-brand-logo" type="hidden" name="brand[logo_url]" value="<?php echo esc_attr( (string) ( $bskin['logo_url'] ?? '' ) ); ?>">
					<div style="display:flex;gap:10px;align-items:flex-start;">
						<img id="es-prj-brand-logo-prev" src="<?php echo esc_url( (string) ( $bskin['logo_url'] ?? '' ) ); ?>" style="max-width:90px;max-height:40px;<?php echo empty( $bskin['logo_url'] ?? '' ) ? 'display:none;' : ''; ?>">
						<div>
							<button type="button" class="button" id="es-prj-brand-logo-btn">Elegir de la biblioteca de medios</button>
							<button type="button" class="button-link" id="es-prj-brand-logo-clear" style="<?php echo empty( $bskin['logo_url'] ?? '' ) ? 'display:none;' : ''; ?>">Quitar logo</button>
						</div>
					</div>
				</td></tr>
				<tr><th scope="row"><label for="es-prj-brand-header">Nombre en cabecera</label></th><td><input id="es-prj-brand-header" type="text" name="brand[header_text]" value="<?php echo esc_attr( (string) ( $bskin['header_text'] ?? '' ) ); ?>" class="regular-text" style="width:60%;"></td></tr>
				<tr><th scope="row"><label for="es-prj-brand-footer">Footer (HTML)</label></th><td><textarea id="es-prj-brand-footer" name="brand[footer_text]" rows="2" class="regular-text" style="width:90%;"><?php echo esc_textarea( (string) ( $bskin['footer_text'] ?? '' ) ); ?></textarea></td></tr>
				<script>
				(function(){
					function setup(){
						if ( window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker ) {
							jQuery('.es-color-field').wpColorPicker();
						}
						var inp = document.getElementById('es-prj-brand-logo');
						var btn = document.getElementById('es-prj-brand-logo-btn');
						var clr = document.getElementById('es-prj-brand-logo-clear');
						var prv = document.getElementById('es-prj-brand-logo-prev');
						if ( btn && inp && window.wp && wp.media ) {
							btn.addEventListener('click', function (e) {
								e.preventDefault();
								var frame = wp.media({ title: 'Elegir logo', button: { text: 'Usar este logo' }, multiple: false });
								frame.on('select', function () {
									var att = frame.state().get('selection').first().toJSON();
									var url = att.url || '';
									inp.value = url;
									if ( prv ) { prv.src = url; prv.style.display = ''; }
									if ( clr ) { clr.style.display = ''; }
								});
								frame.open();
							});
						}
						if ( clr && inp ) {
							clr.addEventListener('click', function (e) {
								e.preventDefault();
								inp.value = '';
								if ( prv ) { prv.style.display = 'none'; }
								clr.style.display = 'none';
							});
						}
					}
					if ( document.readyState === 'loading' ) { document.addEventListener('DOMContentLoaded', setup); } else { setup(); }
				})();
				</script>
				<?php endif; ?>
			</table>
			<input type="hidden" name="city" value="<?php echo esc_attr( (string) ( $edit['city'] ?? '' ) ); ?>">
			<p style="margin:12px 0 0;">
				<?php submit_button( $edit ? 'Guardar cambios' : 'Crear proyecto', 'primary', 'save', false ); ?>
				<?php if ( $edit ) : ?> <a class="button" href="<?php echo esc_url( $proj_url ); ?>">Cancelar</a><?php endif; ?>
			</p>
		</form>
	</div>
</div>

<?php if ( ! $edit ) : ?>
<script>
(function(){
	var btn = document.getElementById('es-prj-new-btn'), box = document.getElementById('es-new-project');
	if ( btn && box ) { btn.addEventListener('click', function (e) { e.preventDefault(); box.style.display = 'block'; btn.style.display = 'none'; try { var l = document.getElementById('es-prj-label'); if (l) { l.focus(); } } catch (err) {} }); }
})();
</script>
<?php endif; ?>

<h3 style="margin:0 0 10px;">Proyectos registrados</h3>
<?php
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/ProjectListTable.php';
$prj_list = new Estandar_Core_ProjectListTable();
$prj_list->prepare_items();
echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
wp_nonce_field( 'estandar_suite_core_projects_bulk' );
echo '<input type="hidden" name="action" value="estandar_suite_core_projects_bulk">';
$prj_list->display();
echo '</form>';
?>
