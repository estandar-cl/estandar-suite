<?php
/**
 * Estandar Suite — Capture · Popups (v2). Sub-sub-tabs:
 * Resumen · Popups · Tracking · Configuración · Settings.
 * Variables: $feature, $feature_view, $feature_fields, $feature_option, $feature_saved.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$v2_popups = isset( $feature_view['v2_popups'] ) ? $feature_view['v2_popups'] : array();
$edit_id    = isset( $_GET['ep'] ) ? (int) $_GET['ep'] : 0;
$edit_popup = $edit_id ? \Estandar\Capture\Popups::get( $edit_id ) : null;
$groups     = \Estandar\Capture\Admin::popups_settings_groups();

$popup_cfg = static function ( $row ): array {
	$cfg = json_decode( (string) ( $row['config_json'] ?? '' ), true );
	return is_array( $cfg ) ? $cfg : array();
};

$ssubs = \Estandar\Core\Admin::filter_ssubs( 'capture', 'config', 'popups', \Estandar\Capture\Admin::popups_ssubs() );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );

$tot_views = $tot_opens = $tot_clicks = $tot_submits = 0;
$n_active  = 0;
foreach ( $v2_popups as $p ) {
	$tot_views   += (int) $p['views'];
	$tot_opens   += (int) $p['opens'];
	$tot_clicks  += (int) $p['clicks'];
	$tot_submits += (int) $p['submits'];
	if ( (string) $p['status'] === 'active' ) {
		$n_active++;
	}
}
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Para qué sirve?</strong> Administra los <em>popups</em> del sitio: créalos/editarlos/eliminarlos, define cuándo y dónde aparecen y revisa su tracking (vistas/aperturas/clicks/envíos). Un popup puede embeber un formulario con <code>[estandar_form_v2 id="N"]</code>.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-capture', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( isset( $_GET['popups_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings de popups guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['popups_general_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Popups actualizados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['popup_saved'] ) ) : ?><div class="notice notice-<?php echo $_GET['popup_saved'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['popup_saved'] ? 'Popup guardado.' : 'No se pudo guardar.'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['popup_deleted'] ) ) : ?><div class="notice notice-<?php echo $_GET['popup_deleted'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['popup_deleted'] ? 'Popup eliminado.' : 'No se pudo eliminar.'; ?></p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Popups</strong></td><td><?php echo count( $v2_popups ); ?> (<?php echo (int) $n_active; ?> activos)</td></tr>
		<tr><td><strong>Vistas</strong></td><td><?php echo (int) $tot_views; ?></td></tr>
		<tr><td><strong>Aperturas</strong></td><td><?php echo (int) $tot_opens; ?></td></tr>
		<tr><td><strong>Clicks</strong></td><td><?php echo (int) $tot_clicks; ?></td></tr>
		<tr><td><strong>Envíos</strong></td><td><?php echo (int) $tot_submits; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'popups' ) : ?>
<?php
$c_trig = array();
$c_seg  = array();
$c_freq = array();
$c_des  = array();
if ( $edit_popup ) {
	$c      = $popup_cfg( $edit_popup );
	$c_trig = is_array( $c['triggers'] ?? null ) ? $c['triggers'] : array();
	$c_seg  = is_array( $c['segmentation'] ?? null ) ? $c['segmentation'] : array();
	$c_freq = is_array( $c['frequency'] ?? null ) ? $c['frequency'] : array();
	$c_des  = is_array( $c['design'] ?? null ) ? $c['design'] : array();
}
// POPUP_BLOCKS_V1: post de contenido vinculado (editor de bloques), si existe.
$edit_cfg    = $edit_popup ? $popup_cfg( $edit_popup ) : array();
$linked_cpid = 0;
if ( $edit_popup ) {
	$linked_cpid = \Estandar\Capture\Popups::content_post_id( $edit_cfg );
	if ( $linked_cpid <= 0 ) {
		$lp = (int) ( $edit_cfg['legacy_post_id'] ?? 0 );
		if ( $lp > 0 && function_exists( 'get_post' ) && get_post( $lp ) ) {
			$linked_cpid = $lp;
		}
	}
}
?>
<h4 style="display:flex;align-items:center;gap:8px;">
	<span>Popups (<?php echo count( $v2_popups ); ?>)</span>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&np=1' ) ); ?>" style="font-weight:600;">+ Crear popup</a>
</h4>

<?php if ( $edit_popup || isset( $_GET['np'] ) ) : ?>
<h5><?php echo $edit_popup ? 'Editar popup (#' . (int) $edit_popup['id'] . ')' : 'Nuevo popup'; ?></h5>
<p class="description" style="max-width:760px;margin:6px 0 12px;padding:8px 12px;background:#f6f7f7;border-left:4px solid #2271b1;">
	<strong>Cómo funciona este popup:</strong>
	<strong>Disparadores</strong> = cuándo se abre (tiempo / scroll);
	<strong>Segmentación</strong> = en qué páginas aparece;
	<strong>Frecuencia</strong> = cada cuánto puede volver a salir para un mismo visitante;
	<strong>Estado</strong> = ponlo en «Activo» para que se muestre.
</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;">
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
		<tr><th scope="row">Contenido</th><td>
			<input type="hidden" name="content_post_id" value="<?php echo (int) $linked_cpid; ?>">
			<?php if ( $linked_cpid ) : ?>
				<p>El contenido se maqueta con el <strong>editor de bloques</strong> (Post #<?php echo (int) $linked_cpid; ?>).</p>
				<p class="description">Usa «Editar contenido en el editor de bloques» o <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $linked_cpid . '&action=edit' ) ); ?>">abre el editor</a>.</p>
			<?php else : ?>
				<textarea id="pp-content" name="content" class="large-text code" rows="8" placeholder="[estandar_form_v2 id=&quot;N&quot;]"><?php echo esc_textarea( (string) ( $edit_cfg['content'] ?? '' ) ); ?></textarea>
				<p class="description">Para maquetar con el <strong>editor de bloques</strong> (columnas, imágenes, etc.) usa el botón de abajo; embebe el formulario con <code>[estandar_form_v2 id="N"]</code>.</p>
			<?php endif; ?>
		</td></tr>
		<tr><th scope="row">Disparadores</th><td>
			<p class="description" style="margin-top:0;">El popup se abre cuando ocurre <strong>el primero</strong> de estos eventos. Deja en <code>0</code> los que no uses.</p>
			<label>Tiempo (s) <input type="number" name="trigger_time" min="0" style="width:90px;" value="<?php echo (int) ( $c_trig['time'] ?? 0 ); ?>"></label>&nbsp;
			<label>Scroll (%) <input type="number" name="trigger_scroll" min="0" max="100" style="width:90px;" value="<?php echo (int) ( $c_trig['scroll'] ?? 0 ); ?>"></label>&nbsp;
			<label>Inactividad (s) <input type="number" name="trigger_inactivity" min="0" style="width:90px;" value="<?php echo (int) ( $c_trig['inactivity'] ?? 0 ); ?>"></label><br>
			<label style="display:block;margin-top:6px;">Selector click (CSS) <input type="text" name="trigger_click" class="regular-text" value="<?php echo esc_attr( (string) ( $c_trig['click_selector'] ?? '' ) ); ?>" placeholder=".mi-boton"></label>
			<p class="description">Ej.: <code>Tiempo = 5</code> abre a los 5 segundos; <code>Scroll = 40</code> abre al 40% de la página. Si pones varios, abre el que ocurra primero.</p>
		</td></tr>
		<tr><th scope="row">Segmentación</th><td>
			<label style="display:block;">IDs de página (separados por coma) <input type="text" name="seg_post_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', array_map( 'intval', (array) ( $c_seg['post_ids'] ?? array() ) ) ) ); ?>"></label>
			<label style="display:block;margin-top:6px;">URL contiene (una por línea o coma) <textarea name="seg_url_contains" rows="2" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) ( $c_seg['url_contains'] ?? array() ) ) ); ?></textarea></label>
			<label style="display:block;margin-top:6px;">Desde <input type="date" name="seg_date_start" value="<?php echo esc_attr( (string) ( $c_seg['date_start'] ?? '' ) ); ?>"></label>
			<label style="display:block;margin-top:6px;">Hasta <input type="date" name="seg_date_end" value="<?php echo esc_attr( (string) ( $c_seg['date_end'] ?? '' ) ); ?>"></label>
			<p class="description">Sin IDs ni patrones = aparece en todo el sitio.</p>
		</td></tr>
		<tr><th scope="row"><label for="pp-freq">Frecuencia</label></th><td>
			<select id="pp-freq" name="freq_mode">
				<?php foreach ( array(
					'always'    => 'Siempre — cada vez que se entra a la página',
					'session'   => 'Una vez por sesión (hasta cerrar el navegador)',
					'once_ever' => 'Una sola vez por visitante (nunca más)',
					'1day'      => 'Una vez al día',
					'7days'     => 'Una vez cada 7 días',
					'30days'    => 'Una vez cada 30 días',
					'custom'    => 'Personalizada — cada N días',
				) as $fk => $fl ) : ?>
					<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( (string) ( $c_freq['mode'] ?? 'always' ), $fk ); ?>><?php echo esc_html( $fl ); ?></option>
				<?php endforeach; ?>
			</select>
			<label style="margin-left:10px;">Días <input type="number" name="freq_days" min="0" style="width:90px;" value="<?php echo (int) ( $c_freq['days'] ?? 0 ); ?>"></label>
			<p class="description">Cada cuánto puede <strong>volver a aparecer</strong> para un mismo visitante. <strong>Para que se abra en cada visita, elige «Siempre».</strong> El campo «Días» solo se usa en «Personalizada».</p>
		</td></tr>
		<tr><th scope="row">Apariencia</th><td>
			<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
				<label style="display:flex;flex-direction:column;gap:4px;">Tamaño
					<select name="design_size">
						<?php foreach ( array( 'small' => 'Pequeño', 'medium' => 'Mediano', 'large' => 'Grande', 'custom' => 'Personalizado' ) as $dk => $dl ) : ?>
							<option value="<?php echo esc_attr( $dk ); ?>" <?php selected( (string) ( $c_des['size'] ?? 'medium' ), $dk ); ?>><?php echo esc_html( $dl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;">Ancho (Personalizado)
					<input type="text" name="design_custom_width" style="width:120px;" value="<?php echo esc_attr( (string) ( $c_des['custom_width'] ?? '' ) ); ?>" placeholder="600px">
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;">Efecto de entrada
					<select name="design_animation">
						<?php foreach ( \Estandar\Capture\Popups::animations() as $ak => $al ) : ?>
							<option value="<?php echo esc_attr( $ak ); ?>" <?php selected( \Estandar\Capture\Popups::normalize_animation( $c_des['animation'] ?? 'fade', 'fade' ), $ak ); ?>><?php echo esc_html( $al ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;">Efecto de salida
					<select name="design_exit_animation">
						<option value="same" <?php selected( (string) ( $c_des['exit_animation'] ?? 'same' ), 'same' ); ?>>Igual a la entrada</option>
						<?php foreach ( \Estandar\Capture\Popups::animations() as $ak => $al ) : ?>
							<option value="<?php echo esc_attr( $ak ); ?>" <?php selected( (string) ( $c_des['exit_animation'] ?? 'same' ), $ak ); ?>><?php echo esc_html( $al ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:flex;flex-direction:column;gap:4px;">Duración
					<select name="design_duration">
						<?php foreach ( array( '0.15' => 'Rápida (0.15 s)', '0.25' => 'Ágil (0.25 s)', '0.4' => 'Normal (0.4 s)', '0.6' => 'Suave (0.6 s)', '0.8' => 'Lenta (0.8 s)', '1' => 'Muy lenta (1 s)' ) as $dk => $dl ) : ?>
							<option value="<?php echo esc_attr( $dk ); ?>" <?php selected( (string) ( $c_des['duration'] ?? '0.4' ), $dk ); ?>><?php echo esc_html( $dl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<?php $c_op = (float) ( $c_des['overlay_opacity'] ?? 0.6 ); ?>
			<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:12px;padding:10px 12px;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:8px;">
				<label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="design_overlay" value="1" <?php checked( ! empty( $c_des['overlay'] ) || ! $edit_popup ); ?>> Fondo oscurecido</label>
				<label style="display:flex;align-items:center;gap:6px;">Color <input type="color" name="design_overlay_color" value="<?php echo esc_attr( (string) ( $c_des['overlay_color'] ?? '#000000' ) ); ?>"></label>
				<label style="display:flex;align-items:center;gap:6px;">Intensidad
					<input type="range" name="design_overlay_opacity" min="0" max="1" step="0.05" value="<?php echo esc_attr( (string) $c_op ); ?>" oninput="this.nextElementSibling.textContent=this.value;" style="width:140px;vertical-align:middle;">
					<span style="font-size:12px;color:#646970;min-width:28px;display:inline-block;"><?php echo esc_html( (string) $c_op ); ?></span>
				</label>
			</div>
			<p class="description" style="margin:8px 0 0;">La apariencia es propia de cada popup. El <em>z-index</em> (99999) y el CSS base se ajustan en la Config avanzada (JSON) si hiciera falta.</p>
		</td></tr>
		<tr><th scope="row">Comportamiento</th><td>
			<label><input type="checkbox" name="hide_if_submitted" value="1" <?php checked( ! empty( $popup_cfg( $edit_popup ?: array() )['hide_if_submitted'] ) ); ?>> No mostrar a quien ya envió <span class="description">(solo este popup / sus formularios, no global)</span></label>
			<label style="display:block;margin-top:6px;">Proyecto (slug, opcional) <input type="text" name="project_slug" class="regular-text" value="<?php echo esc_attr( (string) ( $popup_cfg( $edit_popup ?: array() )['project_slug'] ?? '' ) ); ?>"></label>
		</td></tr>
		<tr><th scope="row"><label for="pp-css">CSS propio</label></th><td>
			<textarea id="pp-css" name="custom_css" class="large-text code" rows="4"><?php echo esc_textarea( (string) ( $popup_cfg( $edit_popup ?: array() )['custom_css'] ?? '' ) ); ?></textarea>
			<p class="description">Se prefija automáticamente con el id del popup.</p>
		</td></tr>
		<tr><th scope="row"><label for="pp-config">Config avanzada (JSON)</label></th><td>
			<textarea id="pp-config" name="config_json" class="large-text code" rows="3" placeholder="(opcional) Pega un JSON completo solo si quieres reemplazar TODA la config de una vez. Normalmente déjalo vacío y usa los campos de arriba."></textarea>
			<p class="description">Déjalo vacío: los campos de arriba son los que se guardan. Si pegas un JSON inválido, se guardan igual los campos de arriba (no se borra nada).</p>
		</td></tr>
	</table>
	<?php submit_button( $edit_popup ? 'Actualizar popup' : 'Crear popup', 'primary', 'save', false ); ?>
	<button class="button" type="submit" name="open_blocks" value="1"><?php echo $linked_cpid ? 'Editar contenido en el editor de bloques' : 'Guardar y editar contenido (bloques)'; ?></button>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups' ) ); ?>">Cancelar</a>
</form>
<?php endif; ?>

<table class="widefat striped" style="margin-top:14px;">
	<thead><tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Frecuencia</th><th>Vistas</th><th>Aperturas</th><th>Clicks</th><th>Envíos</th><th>Creado</th></tr></thead>
	<tbody>
	<?php foreach ( $v2_popups as $p ) :
		$pc    = json_decode( (string) ( $p['config_json'] ?? '' ), true );
		$fmode = is_array( $pc ) ? (string) ( $pc['frequency']['mode'] ?? 'always' ) : 'always';
		$flabels = array(
			'always'    => 'Siempre (cada visita)',
			'session'   => 'Una vez por sesión',
			'once_ever' => 'Una sola vez',
			'1day'      => 'Una vez al día',
			'7days'     => 'Cada 7 días',
			'30days'    => 'Cada 30 días',
			'custom'    => 'Personalizada',
		);
		$flabel = $flabels[ $fmode ] ?? $fmode;
		?>
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
			<td><strong><?php echo esc_html( $flabel ); ?></strong></td>
			<td><?php echo (int) $p['views']; ?></td>
			<td><?php echo (int) $p['opens']; ?></td>
			<td><?php echo (int) $p['clicks']; ?></td>
			<td><?php echo (int) $p['submits']; ?></td>
			<td><?php echo esc_html( $p['created_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2_popups ) ) : ?><tr><td colspan="9">Sin popups. Crea el primero con «+ Crear popup».</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'tracking' ) : ?>
<h4>Tracking de popups</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Vistas</th><th>Aperturas</th><th>Clicks</th><th>Envíos</th></tr></thead>
	<tbody>
	<?php foreach ( $v2_popups as $p ) : ?>
		<tr>
			<td><?php echo (int) $p['id']; ?></td>
			<td><?php echo esc_html( $p['name'] ); ?></td>
			<td><?php echo esc_html( $p['status'] ); ?></td>
			<td><?php echo (int) $p['views']; ?></td>
			<td><?php echo (int) $p['opens']; ?></td>
			<td><?php echo (int) $p['clicks']; ?></td>
			<td><?php echo (int) $p['submits']; ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2_popups ) ) : ?><tr><td colspan="7">Sin popups con tracking.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'configuracion' ) : ?>
<h4>Configuración global de popups</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_popups_settings', 'estandar_suite_capture_popups_settings', $groups['configuracion'], '', array( 'group' => 'configuracion' ) ); ?>

<?php $control_rows = \Estandar\Capture\Admin::popups_control_rows(); ?>
<h4 style="margin-top:26px;">Mostrar / ocultar popups (atajo)</h4>
<p class="description" style="max-width:760px;">Activa o desactiva cada popup y su restricción <em>"no mostrar a quien ya envió"</em> sin entrar a editarlo. La duración de esa restricción se define arriba en <strong>Duración de la restricción "ya envió"</strong> (0 = sin restricción).</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
	<?php wp_nonce_field( 'estandar_suite_capture_save_popups_general' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_save_popups_general">
	<table class="widefat striped" style="max-width:820px;">
		<thead><tr>
			<th>Popup</th>
			<th style="width:110px;">Activo</th>
			<th style="width:210px;">No mostrar a quien ya envió</th>
			<th style="width:90px;">Editar</th>
		</tr></thead>
		<tbody>
		<?php foreach ( $control_rows as $row ) : ?>
			<tr>
				<td><input type="hidden" name="popups[<?php echo (int) $row['id']; ?>][_present]" value="1"><strong><?php echo esc_html( $row['title'] ); ?></strong> <span style="color:#888;">#<?php echo (int) $row['id']; ?></span></td>
				<td><label><input type="checkbox" name="popups[<?php echo (int) $row['id']; ?>][active]" value="1" <?php checked( $row['active'] ); ?>></label></td>
				<td><label><input type="checkbox" name="popups[<?php echo (int) $row['id']; ?>][hide]" value="1" <?php checked( $row['hide'] ); ?>></label></td>
				<td><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&ep=' . (int) $row['id'] ) ); ?>">Editar</a></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( empty( $control_rows ) ) : ?><tr><td colspan="4">Sin popups.</td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php submit_button( 'Guardar popups', 'primary', 'save', false ); ?>
</form>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', $feature_option, $feature_fields, $feature ); ?>
<?php endif; ?>
