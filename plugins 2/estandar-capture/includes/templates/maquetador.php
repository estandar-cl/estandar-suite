<?php
/**
 * Estandar Suite — Capture · Maquetador visual de formularios (editor dedicado).
 * Diseña el form con secciones y campos, edición inline, reordenar y vista previa
 * en vivo. Serializa a config_json (clave "sections") al guardar.
 * Variables: $feature, $fp_type, $fp_title, $fp_singular, $fp_create_label,
 * $form (array|null), $config (array), $caps (bool map), $field_types.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$edit_config = $config;
// Backward-compat: flat fields_json → one section (may be a JSON string or array).
$flat_fields = isset( $edit_config['fields_json'] ) ? $edit_config['fields_json'] : array();
if ( is_string( $flat_fields ) ) {
	$flat_fields = json_decode( $flat_fields, true );
}
if ( ! is_array( $flat_fields ) ) { $flat_fields = array(); }
$sections = isset( $edit_config['sections'] ) && is_array( $edit_config['sections'] ) ? $edit_config['sections'] : array();
if ( ! $sections && $flat_fields ) {
	$sections = array( array( 'title' => 'Contenido', 'fields' => $flat_fields ) );
}
$sc = isset( $edit_config['survey_config'] ) && is_array( $edit_config['survey_config'] ) ? $edit_config['survey_config'] : array();

// ── Subtabs del editor (navegación estable y extensible) ────────────────────
// Registro canónico de módulos en \Estandar\Capture\Admin::editor_subtabs().
// Para añadir uno nuevo basta añadir la clave al registro y un panel
// <div class="es-st-panel" data-panel="..."> en el formulario.
$st_subs = \Estandar\Capture\Admin::editor_subtabs();
$st      = \Estandar\Capture\Admin::resolve_editor_subtab();
$st_id = $form ? '&editar=' . (int) $form['id'] : '&nuevo=1';
$st_tabs = array();
foreach ( $st_subs as $st_k => $st_l ) {
	$st_tabs[ $st_k ] = array(
		'label' => $st_l,
		'url'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' . $st_id . '&st=' . $st_k ),
	);
}
?>
<?php if ( \Estandar\Capture\Admin::show_editor_breadcrumb() ) : ?>
<h4><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' ) ); ?>">← <?php echo esc_html( $fp_title ); ?></a> · <?php echo $form ? esc_html( $form['name'] ) : 'Nuevo ' . esc_html( $fp_singular ); ?></h4>
<?php endif; // Oculta por defecto ("← Volver a Formularios"); recuperable vía option/filtro show_editor_breadcrumb. ?>

<div class="es-st-nav"><?php echo \Estandar\Core\Admin::subtab_nav_html( $st_tabs, $st ); ?></div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="es-maq-form-<?php echo esc_attr( $feature ); ?>">
	<?php wp_nonce_field( 'estandar_suite_capture_save_form' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_save_form">
	<input type="hidden" name="form_id" value="<?php echo $form ? (int) $form['id'] : 0; ?>">
	<input type="hidden" name="form_type" value="<?php echo esc_attr( $fp_type ); ?>">
	<input type="hidden" name="config_json" id="es-config-<?php echo esc_attr( $feature ); ?>" value="<?php echo esc_attr( wp_json_encode( $edit_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">

	<div class="es-st-panel" data-panel="diseno"<?php echo $st !== 'diseno' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Diseño del <?php echo esc_html( $fp_singular ); ?></h4>
	<div id="es-maq-preview-bar" style="margin-bottom:8px;">
		<label><input type="checkbox" id="es-maq-preview-toggle" checked> <strong>Vista previa</strong></label>
		<span class="description">marca para ver cómo se verá el <?php echo esc_html( $fp_singular ); ?>.</span>
	</div>
	<div style="display:flex;gap:16px;align-items:flex-start;">
		<div style="flex:1;min-width:0;" id="es-maq-editor"></div>
		<div style="flex:1;min-width:0;" id="es-maq-preview"></div>
	</div>
	<p style="margin-top:12px;">
		<button type="button" class="button" id="es-maq-add-section" style="font-weight:600;">+ Agregar sección</button>
	</p>
	</div>

	<div class="es-st-panel" data-panel="configuracion"<?php echo $st !== 'configuracion' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Configuración</h4>

	<?php // ── Identidad: nombre, estado, contexto y tipo canónico (motor compartido). ── ?>
	<h4 style="margin:16px 0 4px;">Identidad</h4>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row"><label for="es-maq-name">Nombre</label></th><td><input id="es-maq-name" name="name" class="regular-text" style="width:100%;" required value="<?php echo esc_attr( $form['name'] ?? '' ); ?>" placeholder="Ej: Formulario de contacto"></td></tr>
		<tr><th scope="row"><label for="es-maq-status">Estado</label></th><td><select id="es-maq-status" name="status" style="width:100%;">
			<?php foreach ( array( 'active' => 'Activo', 'draft' => 'Borrador', 'archived' => 'Archivado' ) as $sk => $sl ) : ?>
				<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $form['status'] ?? 'active', $sk ); ?>><?php echo esc_html( $sl ); ?></option>
			<?php endforeach; ?>
		</select></td></tr>
		<tr><th scope="row"><label for="es-maq-context">Contexto</label></th><td><select id="es-maq-context" name="form_context" style="width:100%;">
			<?php foreach ( \Estandar\Capture\Forms::contexts() as $ck => $cl ) : ?>
				<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $form['form_context'] ?? 'generic', $ck ); ?>><?php echo esc_html( $cl ); ?></option>
			<?php endforeach; ?>
		</select></td></tr>
		<tr><th scope="row"><label for="es-maq-type">Tipo (motor compartido)</label></th><td>
			<?php $cur_type = (string) ( $form['form_type'] ?? $fp_type ); ?>
			<select id="es-maq-type" name="form_type" style="width:100%;" data-prev="<?php echo esc_attr( $cur_type ); ?>">
				<option value="lead" <?php selected( $cur_type, 'lead' ); ?>>Formulario</option>
				<option value="survey" <?php selected( $cur_type, 'survey' ); ?>>Encuesta</option>
				<option value="incident" <?php selected( $cur_type, 'incident' ); ?>>Postventa</option>
			</select>
			<span class="description" id="es-maq-type-warn" style="display:none;color:#b26a00;display:block;margin-top:4px;">Al cambiar el tipo se preservan identidad, campos, envíos e historial (conversión no destructiva). El render frontend v2 está implementado solo para <strong>Formulario</strong>.</span>
		</td></tr>
	</table>

	<?php // ── Comportamiento: repetición + opciones del motor (según capacidades). ── ?>
	<h4 style="margin:18px 0 4px;">Comportamiento</h4>
	<?php $rpol = \Estandar\Capture\Forms::repeat_visibility( $edit_config ); $rren = ! empty( $edit_config['repeat_reason']['enabled'] ); ?>
	<p class="description">Repetición — política por formulario: por defecto el <?php echo esc_html( $fp_singular ); ?> siempre se muestra; el ocultamiento temporal es opcional y nunca global.</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Visibilidad tras responder</th><td>
			<select class="es-cfg-field" data-nest="repeat_visibility" data-key="mode">
				<option value="always_visible" <?php selected( $rpol['mode'], 'always_visible' ); ?>>Siempre visible (por defecto)</option>
				<option value="hide_temporarily" <?php selected( $rpol['mode'], 'hide_temporarily' ); ?>>Ocultar temporalmente</option>
			</select>
			<span class="description">Tras una respuesta, oculta el formulario por un tiempo.</span>
		</td></tr>
		<tr><th scope="row">Duración</th><td>
			<input type="number" min="1" class="es-cfg-field regular-text" style="width:90px;" data-nest="repeat_visibility" data-key="duration" value="<?php echo (int) $rpol['duration']; ?>">
			<select class="es-cfg-field" data-nest="repeat_visibility" data-key="unit">
				<?php foreach ( array( 'minutes' => 'minutos', 'hours' => 'horas', 'days' => 'días' ) as $uk => $ul ) : ?>
					<option value="<?php echo esc_attr( $uk ); ?>" <?php selected( $rpol['unit'], $uk ); ?>><?php echo esc_html( $ul ); ?></option>
				<?php endforeach; ?>
			</select>
		</td></tr>
		<tr><th scope="row">Al expirar</th><td>
			<select class="es-cfg-field" data-nest="repeat_visibility" data-key="behavior">
				<option value="show_again" <?php selected( $rpol['behavior'], 'show_again' ); ?>>Volver a mostrar el formulario</option>
			</select>
		</td></tr>
		<tr><th scope="row">Mensaje mientras oculto</th><td>
			<textarea class="es-cfg-field regular-text" rows="2" style="width:80%;" data-nest="repeat_visibility" data-key="message"><?php echo esc_textarea( $rpol['message'] ); ?></textarea>
		</td></tr>
		<tr><th scope="row">Motivo de recontacto</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-nest="repeat_reason" data-key="enabled" <?php checked( $rren ); ?> value="1"> <span class="description">Después de un envío, ofrecer motivos por los que la persona vuelve a contactar</span></label>
		</td></tr>
	</table>

	<?php
	$opt_row = function ( $label, $key, $value, $type = 'text', $extra = array() ) {
		$nest = ! empty( $extra['nest'] ) ? ' data-nest="' . esc_attr( $extra['nest'] ) . '"' : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		if ( $type === 'bool' ) {
			echo '<label><input type="checkbox" class="es-cfg-field" data-key="' . esc_attr( $key ) . '"' . $nest . ( ! empty( $value ) ? ' checked' : '' ) . ' value="1"> ' . esc_html( $extra['desc'] ?? '' ) . '</label>';
		} elseif ( $type === 'select' ) {
			echo '<select class="es-cfg-field" data-key="' . esc_attr( $key ) . '"' . $nest . '>';
			foreach ( ( $extra['options'] ?? array() ) as $ok => $ol ) {
				echo '<option value="' . esc_attr( $ok ) . '"' . selected( (string) $value, (string) $ok, false ) . '>' . esc_html( $ol ) . '</option>';
			}
			echo '</select> <span class="description">' . esc_html( $extra['desc'] ?? '' ) . '</span>';
		} else {
			echo '<input type="text" class="es-cfg-field regular-text" data-key="' . esc_attr( $key ) . '"' . $nest . ' value="' . esc_attr( (string) $value ) . '"> <span class="description">' . esc_html( $extra['desc'] ?? '' ) . '</span>';
		}
		echo '</td></tr>';
	};
	$has = function ( $cap ) use ( $caps ) { return ! empty( $caps[ $cap ] ); };
	$has_opciones = $has( 'step_by_step' ) || $has( 'hero' ) || $has( 'completion' ) || $has( 'identification' ) || $has( 'webhook' ) || $has( 'notify_email' ) || $has( 'recaptcha' ) || $has( 'attachments' );
	if ( $has_opciones ) :
	?>
	<p class="description" style="margin-top:12px;">Opciones del motor (visibles según las capacidades habilitadas del producto).</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<?php if ( $has( 'step_by_step' ) ) : $opt_row( 'Mostrar pregunta por pregunta', 'step_by_step', $edit_config['step_by_step'] ?? '1', 'bool', array( 'desc' => 'Presenta los campos uno a la vez, con animaciones.' ) ); endif; ?>
		<?php if ( $has( 'hero' ) ) : $opt_row( 'Título de inicio', 'hero_title', $sc['hero_title'] ?? '', 'text', array( 'desc' => 'Pantalla inicial', 'nest' => 'survey_config' ) ); endif; ?>
		<?php if ( $has( 'completion' ) ) : $opt_row( 'Mensaje de cierre', 'completion_message', $sc['completion_message'] ?? '', 'text', array( 'desc' => 'Texto al terminar', 'nest' => 'survey_config' ) ); endif; ?>
		<?php if ( $has( 'identification' ) ) : $opt_row( 'Identificación', 'identification_mode', $sc['identification_mode'] ?? 'anonymous', 'select', array( 'desc' => '¿Se identifica al respondiente?', 'nest' => 'survey_config', 'options' => array( 'anonymous' => 'Anónimo', 'optional' => 'Opcional', 'required' => 'Obligatoria' ) ) ); endif; ?>
		<?php if ( $has( 'webhook' ) ) : $opt_row( 'Webhook de salida', 'webhook_url', $edit_config['webhook_url'] ?? '', 'text', array( 'desc' => 'URL que recibe la captura' ) ); endif; ?>
		<?php if ( $has( 'notify_email' ) ) : $opt_row( 'Notificar por email', 'notify_email', $edit_config['notify_email'] ?? '', 'text', array( 'desc' => 'Correo que recibe un aviso' ) ); endif; ?>
		<?php if ( $has( 'recaptcha' ) ) : $opt_row( 'Protección anti-spam', 'require_recaptcha', $edit_config['require_recaptcha'] ?? '0', 'bool', array( 'desc' => 'Activa reCAPTCHA' ) ); endif; ?>
		<?php if ( $has( 'attachments' ) ) : $opt_row( 'Adjuntos', 'attachments_enabled', $edit_config['attachments_enabled'] ?? '0', 'bool', array( 'desc' => 'Permite adjuntar archivos' ) ); endif; ?>
	</table>
	<?php else : ?>
	<p class="description" style="margin-top:12px;">Sin opciones funcionales habilitadas. Activa capacidades en <strong>Settings → Capacidades</strong>.</p>
	<?php endif; ?>

	<?php // ── Información técnica: shortcode y datos internos. ── ?>
	<h4 style="margin:18px 0 4px;">Información técnica</h4>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row"><label for="es-maq-slug">Slug</label></th><td><input id="es-maq-slug" name="slug" class="regular-text" style="width:100%;" value="<?php echo esc_attr( $form['slug'] ?? '' ); ?>" placeholder="se genera del nombre"></td></tr>
		<tr><th scope="row">ID técnico</th><td><code><?php echo $form ? (int) $form['id'] : '—'; ?></code></td></tr>
		<tr><th scope="row">Shortcode</th><td><?php echo \Estandar\Core\Embed::copy_control( $form ? \Estandar\Core\Embed::shortcode( 'form', (int) $form['id'] ) : '' ); ?></td></tr>
	</table>
	<?php echo \Estandar\Core\Embed::copy_script(); ?>
	</div>

	<div class="es-st-panel" data-panel="correo"<?php echo $st !== 'correo' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Correo (notificación y autorespuesta)</h4>
	<p class="description">Entregas gestionadas por el motor único de la Suite (<code>Core\Mail</code> + TransportRegistry).</p>
	<table class="widefat striped" style="max-width:840px;">
		<tbody>
			<?php $mcfg = \Estandar\Capture\Forms::mail_settings( $edit_config ); ?>
			<tr><th scope="row">Notificación interna</th><td>
				<label><input type="checkbox" class="es-cfg-field" data-key="send_email" <?php checked( ! empty( $edit_config['send_email'] ) ); ?> value="1"> <span class="description">Enviar aviso interno al recibir un envío</span></label><br>
				<label><span class="description">Destinatarios (separados por coma)</span></label><br><input type="text" class="es-cfg-field regular-text" data-key="notify_emails" style="width:60%;" value="<?php echo esc_attr( $mcfg['notify']['to'] ); ?>"><br>
				<label><span class="description">Asunto</span></label><br><input type="text" class="es-cfg-field regular-text" data-key="mail_subject" style="width:60%;" value="<?php echo esc_attr( $mcfg['notify']['subject'] ); ?>"><br>
				<label><span class="description">Cuerpo HTML (variables {{name}}, {{email}}, {{fields}}, {{form_name}})</span></label><br><textarea class="es-cfg-field regular-text" data-key="mail_body" rows="3" style="width:60%;"><?php echo esc_textarea( $mcfg['notify']['body'] ); ?></textarea>
			</td></tr>
			<tr><th scope="row">Autorespuesta al remitente</th><td>
				<label><input type="checkbox" class="es-cfg-field" data-nest="autoresponse" data-key="enabled" <?php checked( ! empty( $mcfg['autoresponse']['enabled'] ) ); ?> value="1"> <span class="description">Responder automáticamente al remitente</span></label><br>
				<label><span class="description">Campo de email del remitente</span></label><br><input type="text" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="email_field" style="width:40%;" value="<?php echo esc_attr( $mcfg['autoresponse']['email_field'] ); ?>"><br>
				<label><span class="description">Asunto</span></label><br><input type="text" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="subject" style="width:60%;" value="<?php echo esc_attr( $mcfg['autoresponse']['subject'] ); ?>"><br>
				<label><span class="description">Cuerpo HTML</span></label><br><textarea class="es-cfg-field regular-text" data-nest="autoresponse" data-key="body" rows="3" style="width:60%;"><?php echo esc_textarea( $mcfg['autoresponse']['body'] ); ?></textarea>
			</td></tr>
		</tbody>
	</table>
	</div>

	<div class="es-st-panel" data-panel="entradas"<?php echo $st !== 'entradas' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Entradas de este <?php echo esc_html( $fp_singular ); ?></h4>
	<?php if ( $form ) :
		$entradas = \Estandar\Capture\Forms::submissions_for_form( (int) $form['id'], 100 );
		if ( $entradas ) : ?>
	<p class="description">Envíos nativos v2 relacionados con este <?php echo esc_html( $fp_singular ); ?> (relación canónica <code>source_form_id</code>).</p>
	<table class="widefat striped">
		<thead><tr><th>Fecha</th><th>Identificador</th><th>Contacto</th><th>Estado</th><th>Lead asociado</th><th>Origen</th><th>Detalle</th></tr></thead>
		<tbody>
		<?php foreach ( $entradas as $en ) : $ep = \Estandar\Capture\Forms::pipeline_for_capture( (int) $en['id'] ); $edata = json_decode( (string) ( $en['fields_json'] ?? '' ), true ); ?>
			<tr>
				<td><?php echo esc_html( (string) $en['created_at'] ); ?></td>
				<td><code><?php echo $en['name'] !== '' ? esc_html( (string) $en['name'] ) : '#' . (int) $en['id']; ?></code></td>
				<td><?php $contact = trim( (string) $en['email_normalized'] . ( (string) $en['phone_normalized'] !== '' ? ' · ' . (string) $en['phone_normalized'] : '' ) ); echo $contact !== '' ? esc_html( $contact ) : '<span class="description">—</span>'; ?></td>
				<td><?php if ( (string) $en['repeat_reason'] !== '' ) { $rr = \Estandar\Capture\Forms::repeat_reasons(); echo esc_html( 'recontacto: ' . ( $rr[ $en['repeat_reason'] ] ?? $en['repeat_reason'] ) ); } elseif ( $ep ) { echo esc_html( 'lead ' . $ep['stage'] ); } else { echo '<span class="description">—</span>'; } ?></td>
				<td><?php echo $ep ? '<a href="' . esc_url( admin_url( 'admin.php?page=estandar-suite-flow&lead=' . (int) $ep['id'] ) ) . '">#' . (int) $ep['id'] . ' · ' . esc_html( $ep['stage'] ) . '</a>' : '<span class="description">—</span>'; ?></td>
				<td><?php echo esc_html( (string) $en['source'] ); ?></td>
				<td>
					<details style="max-width:340px;"><summary>Ver</summary>
						<div style="margin-top:6px;">
							<?php if ( is_array( $edata ) && $edata ) : foreach ( $edata as $efk => $efv ) : ?>
								<p style="margin:2px 0;"><code><?php echo esc_html( (string) $efk ); ?>:</code> <?php echo esc_html( (string) $efv ); ?></p>
							<?php endforeach; else : ?><span class="description">sin datos</span><?php endif; ?>
							<?php if ( (string) $en['repeat_note'] !== '' ) : ?><p style="margin:2px 0;"><code>nota:</code> <?php echo esc_html( (string) $en['repeat_note'] ); ?></p><?php endif; ?>
						</div>
					</details>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
		<?php else : ?><p class="description">Este <?php echo esc_html( $fp_singular ); ?> aún no tiene envíos. Cuando alguien lo complete, aparecerán aquí.</p><?php endif; ?>
	<?php else : ?><p class="description">Guarda el <?php echo esc_html( $fp_singular ); ?> para ver sus entradas.</p><?php endif; ?>
	</div>

	<p style="margin-top:16px;">
		<?php submit_button( $form ? 'Actualizar ' . $fp_singular : $fp_create_label, 'primary', 'save', false ); ?>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' ) ); ?>">Cancelar</a>
	</p>
</form>

<script>
(function () {
	var fid = <?php echo wp_json_encode( $feature ); ?>;
	var TYPES = <?php echo wp_json_encode( $field_types, JSON_UNESCAPED_UNICODE ); ?>;
	var SECTIONS = <?php echo wp_json_encode( $sections, JSON_UNESCAPED_UNICODE ); ?>;
	var $ = function (id) { return document.getElementById(id); };
	var editor = $('es-maq-editor');
	var previewEl = $('es-maq-preview');
	var cfgInput = $('es-config-' + fid);
	var optionsTypes = { select: 1, radio: 1, checkbox: 1, cards: 1, emoji: 1 };
	var openField = null;

	function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	function slugify(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,''); }
	function optLabel(o) { return typeof o === 'object' && o !== null ? (o.label || '') : (o || ''); }

	function typeLabel(t) { return TYPES[t] || t; }

	function fieldEditorHTML(si, fi, f) {
		var typeOptions = Object.keys(TYPES).map(function (t) { return '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + TYPES[t] + '</option>'; }).join('');
		var opts = optionsTypes[f.type]
			? '<label>Opciones (una por línea)</label><textarea class="maq-options" rows="2" style="width:100%;">' + esc((f.options || []).map(optLabel).join('\n')) + '</textarea>'
			: '';
		return '<div class="maq-field" data-si="' + si + '" data-fi="' + fi + '" style="border:1px solid #c3c4c7;border-radius:6px;padding:8px 10px;margin-bottom:8px;background:#fff;">'
			+ '<div style="display:flex;align-items:center;gap:8px;">'
			+ '<strong style="flex:1;">' + esc(f.label || '(sin etiqueta)') + '</strong>'
			+ '<span class="description">' + esc(typeLabel(f.type)) + '</span>'
			+ '<button type="button" class="button maq-up" title="Subir">↑</button>'
			+ '<button type="button" class="button maq-down" title="Bajar">↓</button>'
			+ '<button type="button" class="button-link-delete maq-del" title="Quitar campo">✕</button>'
			+ '<button type="button" class="button maq-edit">Editar</button>'
			+ '</div>'
			+ '<div class="maq-editbox" style="' + (openField === si + ':' + fi ? '' : 'display:none;') + 'margin-top:8px;">'
			+ '<table class="form-table"><tr><th>Etiqueta</th><td><input class="maq-label regular-text" value="' + esc(f.label) + '" placeholder="Ej: Nombre completo"></td></tr>'
			+ '<tr><th>Tipo</th><td><select class="maq-type">' + typeOptions + '</select></td></tr>'
			+ '<tr><th>Obligatorio</th><td><input class="maq-required" type="checkbox"' + (f.required ? ' checked' : '') + '> Sí</td></tr>'
			+ opts
			+ '</table>'
			+ '</div>'
			+ '</div>';
	}

	function render() {
		editor.innerHTML = SECTIONS.map(function (sec, si) {
			return '<div class="maq-section" data-si="' + si + '" style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;margin-bottom:14px;background:#f6f7f7;">'
				+ '<div style="display:flex;align-items:center;gap:8px;">'
				+ '<strong style="flex:1;">Sección</strong>'
				+ '<input class="maq-section-title regular-text" value="' + esc(sec.title || '') + '" placeholder="Título de la sección (opcional)">'
				+ '<button type="button" class="button maq-sec-del" title="Quitar sección">✕</button>'
				+ '</div>'
				+ '<div style="margin-top:8px;">' + (sec.fields || []).map(function (f, fi) { return fieldEditorHTML(si, fi, f); }).join('') + '</div>'
				+ '<button type="button" class="button maq-add-field" style="margin-top:4px;">+ Agregar campo</button>'
				+ '</div>';
		}).join('');
		bind();
	}

	function bind() {
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-field'), function (el) {
			var si = parseInt(el.getAttribute('data-si'), 10), fi = parseInt(el.getAttribute('data-fi'), 10);
			el.querySelector('.maq-edit').addEventListener('click', function () {
				var k = si + ':' + fi;
				openField = openField === k ? null : k;
				render();
			});
			el.querySelector('.maq-up').addEventListener('click', function () { moveField(si, fi, -1); });
			el.querySelector('.maq-down').addEventListener('click', function () { moveField(si, fi, 1); });
			el.querySelector('.maq-del').addEventListener('click', function () { SECTIONS[si].fields.splice(fi, 1); openField = null; render(); });
			var typeSel = el.querySelector('.maq-type');
			if (typeSel) { typeSel.addEventListener('change', function () { render(); }); }
		});
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-add-field'), function (btn) {
			btn.addEventListener('click', function () {
				var si = parseInt(btn.closest('.maq-section').getAttribute('data-si'), 10);
				SECTIONS[si].fields.push({ name: '', label: '', type: 'text', required: false, options: [] });
				openField = si + ':' + (SECTIONS[si].fields.length - 1);
				render();
			});
		});
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-sec-del'), function (btn) {
			btn.addEventListener('click', function () {
				var si = parseInt(btn.closest('.maq-section').getAttribute('data-si'), 10);
				SECTIONS.splice(si, 1); openField = null; render();
			});
		});
	}

	function moveField(si, fi, d) {
		var j = fi + d;
		if (j < 0 || j >= SECTIONS[si].fields.length) { return; }
		var t = SECTIONS[si].fields[fi]; SECTIONS[si].fields[fi] = SECTIONS[si].fields[j]; SECTIONS[si].fields[j] = t;
		render();
	}

	$('es-maq-add-section').addEventListener('click', function () {
		SECTIONS.push({ title: '', fields: [] });
		render();
	});

	// Live preview.
	$('es-maq-preview-toggle').addEventListener('change', function (e) {
		previewEl.style.display = e.target.checked ? 'block' : 'none';
		if (e.target.checked) { renderPreview(); }
	});
	function optInputs(f) {
		return (f.options || []).map(function (o) {
			return '<label style="display:block;"><input type="' + (f.type === 'checkbox' ? 'checkbox' : 'radio') + '"> ' + esc(optLabel(o)) + '</label>';
		}).join('');
	}
	function renderPreview() {
		var html = SECTIONS.map(function (sec) {
			var h = '';
			if (sec.title) { h += '<h4>' + esc(sec.title) + '</h4>'; }
			(sec.fields || []).forEach(function (f) {
				var t = f.type || 'text';
				h += '<p><label>' + esc(f.label) + (f.required ? ' <span style="color:#d63638;">*</span>' : '') + '</label><br>';
				if (t === 'textarea') { h += '<textarea rows="2" style="width:100%;"></textarea>'; }
				else if (t === 'select') { h += '<select>' + (f.options || []).map(function (o) { return '<option>' + esc(optLabel(o)) + '</option>'; }).join('') + '</select>'; }
				else if (t === 'radio' || t === 'checkbox' || t === 'cards') { h += optInputs(f); }
				else if (t === 'emoji') { h += (f.options || []).map(function (o) { return '<span style="font-size:20px;margin-right:6px;cursor:pointer;">' + esc(optLabel(o)) + '</span>'; }).join(''); }
				else if (t === 'rating') { h += '<span style="letter-spacing:3px;font-size:18px;">★ ★ ★ ★ ★</span>'; }
				else if (t === 'matrix') { h += '<em class="description">(matriz / tabla de respuestas)</em>'; }
				else if (t === 'file') { h += '<input type="file" disabled>'; }
				else if (t === 'nps') { h += '<span style="letter-spacing:4px;">0 1 2 3 4 5 6 7 8 9 10</span>'; }
				else {
					var itype = t === 'email' ? 'email' : (t === 'number' ? 'number' : (t === 'date' ? 'date' : (t === 'tel' || t === 'phone' ? 'tel' : (t === 'rut' ? 'text' : 'text'))));
					var ph = t === 'rut' ? ' placeholder="12.345.678-9"' : '';
					h += '<input type="' + itype + '"' + ph + ' style="width:100%;">';
				}
				h += '</p>';
			});
			return h;
		}).join('');
		previewEl.innerHTML = '<div style="border:1px dashed #2271b1;border-radius:8px;padding:12px;background:#fff;">' + html + '</div>';
	}

	function collect() {
		return SECTIONS.map(function (sec) {
			var fields = [];
			Array.prototype.forEach.call(sec.fields, function (f) {
				// Values are read from the DOM after render(); SECTIONS holds references only for structure.
			});
			return { title: sec.title, fields: sec.fields };
		});
	}

	cfgInput.form.addEventListener('submit', function () {
		// Sync section titles from DOM.
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-section'), function (el, si) {
			SECTIONS[si].title = el.querySelector('.maq-section-title').value;
		});
		// Sync fields from DOM.
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-field'), function (el) {
			var si = parseInt(el.getAttribute('data-si'), 10), fi = parseInt(el.getAttribute('data-fi'), 10);
			var f = SECTIONS[si].fields[fi];
			f.label = el.querySelector('.maq-label').value;
			f.type = el.querySelector('.maq-type').value;
			f.required = el.querySelector('.maq-required').checked;
			var ta = el.querySelector('.maq-options');
			f.options = ta ? ta.value.split('\n').map(function (s) { s = s.trim(); return s ? { value: slugify(s), label: s } : null; }).filter(Boolean) : [];
			f.name = slugify(f.label);
		});
		var cfg = {};
		try { cfg = JSON.parse(cfgInput.value || '{}') || {}; } catch (e) { cfg = {}; }
		cfg.sections = collect();
		// Capability options.
		Array.prototype.forEach.call(document.querySelectorAll('.es-cfg-field'), function (el) {
			var key = el.getAttribute('data-key') || '';
			if (!key) { return; }
			var value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
			var nest = el.getAttribute('data-nest') || '';
			if (nest) { cfg[nest] = cfg[nest] || {}; cfg[nest][key] = value; }
			else { cfg[key] = value; }
		});
		cfgInput.value = JSON.stringify(cfg);
	});

	render();
	renderPreview();
})();

(function () {
	// Navegación por subtabs del editor: cambia el panel visible SIN recargar
	// (preserva cambios sin guardar) y actualiza la URL con el parámetro canónico
	// 'st'. Sin JS, los enlaces siguen siendo URLs completas (fallback por recarga).
	var nav = document.querySelector('.es-st-nav');
	var panels = document.querySelectorAll('.es-st-panel');
	if (!nav || !panels.length) { return; }
	var current = null;
	Array.prototype.forEach.call(panels, function (p) { if (p.style.display !== 'none') { current = p.getAttribute('data-panel'); } });
	if (!current) { current = panels[0].getAttribute('data-panel'); }
	function activate(key) {
		current = key;
		Array.prototype.forEach.call(panels, function (p) {
			p.style.display = p.getAttribute('data-panel') === key ? '' : 'none';
		});
		Array.prototype.forEach.call(nav.querySelectorAll('a[data-st]'), function (a) {
			if (a.getAttribute('data-st') === key) { a.classList.add('nav-tab-active'); }
			else { a.classList.remove('nav-tab-active'); }
		});
		var url = new URL(window.location.href);
		url.searchParams.set('st', key);
		window.history.replaceState(null, '', url.toString());
	}
	Array.prototype.forEach.call(nav.querySelectorAll('a[data-st]'), function (a) {
		a.addEventListener('click', function (e) {
			var key = a.getAttribute('data-st');
			if (!key || key === current) { return; }
			e.preventDefault();
			activate(key);
		});
	});
})();

(function () {
	// Aviso de conversión de tipo (no destructiva; gap de render frontend documentado).
	var sel = document.getElementById('es-maq-type');
	if (!sel) { return; }
	var warn = document.getElementById('es-maq-type-warn');
	function sync() {
		if (warn) { warn.style.display = sel.value !== sel.getAttribute('data-prev') ? 'block' : 'none'; }
	}
	sel.addEventListener('change', sync);
	sync();
})();
</script>
