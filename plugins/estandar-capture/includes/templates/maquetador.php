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
// ROUTING_FORM_AUTOCONFIG_V1: si el form declara un campo 'routing', se apagan
// automáticamente el aviso interno (notify) y el correo diferido; el correo que
// sale (routing) usa el template default. La UI lo refleja (toggles desmarcados
// y deshabilitados).
$maq_has_routing = class_exists( 'Estandar\\Capture\\Forms' ) ? \Estandar\Capture\Forms::has_routing_field( $edit_config ) : false;
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

// Proyectos del registry (para la integración con embudo del proyecto).
$projects = array();
if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
	foreach ( \Estandar\Core\Leads\ProjectRegistry::registry() as $prj ) {
		$projects[ (string) ( $prj['project_slug'] ?? '' ) ] = (string) ( $prj['name'] ?? $prj['project_slug'] ?? '' );
	}
}

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
		<tr><th scope="row"><label for="es-maq-slug">Slug</label></th><td><input id="es-maq-slug" name="slug" class="regular-text" style="width:100%;" value="<?php echo esc_attr( (string) ( $form['slug'] ?? '' ) ); ?>" placeholder="formulario-de-contacto">
			<span class="description">Identificador único (se usa en el shortcode <code>[estandar_form_v2 slug=…]</code>). Vacío = se genera desde el nombre al guardar; renombrar el nombre no lo cambia si ya lo dejaste definido.</span></td></tr>
		<tr><th scope="row"><label for="es-maq-status">Estado</label></th><td><select id="es-maq-status" name="status" style="width:100%;">
			<?php foreach ( array( 'active' => 'Activo', 'draft' => 'Borrador', 'archived' => 'Archivado' ) as $sk => $sl ) : ?>
				<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $form['status'] ?? 'active', $sk ); ?>><?php echo esc_html( $sl ); ?></option>
			<?php endforeach; ?>
		</select></td></tr>
		<input type="hidden" name="form_context" value="<?php echo esc_attr( $form['form_context'] ?? 'generic' ); ?>">
		<tr><th scope="row"><label for="es-maq-type">Tipo (motor compartido)</label></th><td>
			<?php $cur_type = (string) ( $form['form_type'] ?? $fp_type ); ?>
			<select id="es-maq-type" name="form_type" style="width:100%;" data-prev="<?php echo esc_attr( $cur_type ); ?>">
				<option value="lead" <?php selected( $cur_type, 'lead' ); ?>>Formulario</option>
				<option value="survey" <?php selected( $cur_type, 'survey' ); ?>>Encuesta</option>
			</select>
			<span class="description" id="es-maq-type-warn" style="display:none;color:#b26a00;display:block;margin-top:4px;">Al cambiar el tipo se preservan identidad, campos, envíos e historial (conversión no destructiva). El render frontend v2 está implementado solo para <strong>Formulario</strong>.</span>
		</td></tr>
		<?php
		// ── Proyecto (PROJECT_OWNERSHIP_V1): todo catcher pertenece a un proyecto.
		$cur_project = (string) ( $edit_config['form_project'] ?? '' );
		$site_label  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'el sitio';
		$is_new      = ! $form;
		?>
		<tr><th scope="row"><label for="es-maq-project">Proyecto<?php echo $is_new ? ' *' : ''; ?></label></th><td>
			<select id="es-maq-project" name="form_project" style="width:100%;">
				<?php if ( $is_new ) : ?>
					<option value="__auto__">Proyecto del sitio: “<?php echo esc_html( $site_label ); ?>” (automático)</option>
				<?php elseif ( $cur_project === '' ) : ?>
					<option value="" selected>— Sin proyecto asignado —</option>
				<?php endif; ?>
				<?php foreach ( $projects as $pk => $pn ) : ?>
					<option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $cur_project, $pk ); ?>><?php echo esc_html( $pn ); ?></option>
				<?php endforeach; ?>
				<?php if ( $cur_project !== '' && ! isset( $projects[ $cur_project ] ) ) : ?>
					<option value="<?php echo esc_attr( $cur_project ); ?>" selected><?php echo esc_html( $cur_project ); ?></option>
				<?php endif; ?>
				<option value="__new__">＋ Crear nuevo proyecto…</option>
			</select>
			<div id="es-maq-project-new" style="display:none;margin-top:8px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
				<input type="text" name="form_project_new_label" placeholder="Nombre del nuevo proyecto *" style="width:100%;margin-bottom:6px;">
				<input type="text" name="form_project_new_city" placeholder="Ciudad (opcional)" style="width:100%;">
			</div>
			<?php if ( $is_new ) : ?>
				<span class="description" style="display:block;margin-top:4px;">Todo <?php echo esc_html( $fp_singular ); ?> pertenece a un proyecto. Si no eliges, se usará/creará el proyecto del sitio (“<?php echo esc_html( $site_label ); ?>”).</span>
			<?php else : ?>
				<span class="description" style="display:block;margin-top:4px;">El lead se vincula al proyecto (y al correo del operador del proyecto). «＋ Crear nuevo proyecto…» te pide el nombre al guardar.</span>
			<?php endif; ?>
			<script>
			(function(){var s=document.getElementById('es-maq-project'),w=document.getElementById('es-maq-project-new');if(!s||!w)return;function upd(){w.style.display=(s.value==='__new__')?'block':'none';}s.addEventListener('change',upd);upd();})();
			</script>
		</td></tr>
		<tr><th scope="row">Destino del lead</th><td>
			<select class="es-cfg-field" data-key="form_funnel_mode" style="width:100%;">
				<option value="" <?php selected( (string) ( $edit_config['form_funnel_mode'] ?? '' ), '' ); ?>>Heredado (comportamiento actual)</option>
				<option value="funnel" <?php selected( (string) ( $edit_config['form_funnel_mode'] ?? '' ), 'funnel' ); ?>>Embudo del proyecto — solo correo del operador con calificación</option>
				<option value="simple" <?php selected( (string) ( $edit_config['form_funnel_mode'] ?? '' ), 'simple' ); ?>>Solo correo simple del form</option>
			</select>
			<span class="description"><strong>Embudo:</strong> el aviso simple del form se suprime y solo llega el correo enriquecido (con calificación de leads) al/los operadores del proyecto. <strong>Simple:</strong> el form <strong>NO es de leads</strong> — no entra al CRM ni dispara el correo del operador; salen el aviso simple del form, la autorespuesta y el webhook (si está configurado).</span>
		</td></tr>
	</table>

	<?php // ── Comportamiento: confirmación animada + menú de recontacto. ── ?>
	<h4 style="margin:18px 0 4px;">Identidad y comportamiento</h4>
	<p class="description">Los mensajes de confirmación, la animación de éxito y el menú de recontacto se configuran en la pestaña <strong>«Correos y confirmación»</strong>.</p>


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
		<?php if ( $has( 'step_by_step' ) ) : $opt_row( 'Mostrar pregunta por pregunta', 'step_by_step', $sc['step_by_step'] ?? '0', 'bool', array( 'desc' => 'Presenta los campos uno a la vez, con animaciones (estilo encuesta).', 'nest' => 'survey_config' ) ); endif; ?>
		<?php if ( $has( 'hero' ) ) : $opt_row( 'Título de inicio', 'hero_title', $sc['hero_title'] ?? '', 'text', array( 'desc' => 'Pantalla inicial', 'nest' => 'survey_config' ) ); endif; ?>
		<?php if ( $has( 'completion' ) ) : $opt_row( 'Mensaje de cierre', 'completion_message', $sc['completion_message'] ?? '', 'text', array( 'desc' => 'Texto al terminar', 'nest' => 'survey_config' ) ); endif; ?>
		<?php if ( $has( 'identification' ) ) : $opt_row( 'Identificación', 'identification_mode', $sc['identification_mode'] ?? 'anonymous', 'select', array( 'desc' => '¿Se identifica al respondiente?', 'nest' => 'survey_config', 'options' => array( 'anonymous' => 'Anónimo', 'optional' => 'Opcional', 'required' => 'Obligatoria' ) ) ); endif; ?>
		<?php if ( $has( 'webhook' ) ) : ?>
		<tr><th scope="row">Webhook de salida</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-key="send_webhook" <?php checked( ! empty( $edit_config['send_webhook'] ) ); ?> value="1"> <span class="description">Enviar la captura a un destino externo (n8n, Zapier, etc.) al completar el envío</span></label><br>
			<label><span class="description">URL del webhook (destino)</span></label><br><input type="url" class="es-cfg-field regular-text" data-key="webhook_url" style="width:90%;" value="<?php echo esc_attr( (string) ( $edit_config['webhook_url'] ?? '' ) ); ?>" placeholder="https://n8n.ejemplo.cl/webhook/xxxx"><br>
			<label><span class="description">Formato del payload</span></label><br>
			<select class="es-cfg-field" data-key="webhook_payload_mode" style="width:60%;">
				<option value="estandar" <?php selected( (string) ( $edit_config['webhook_payload_mode'] ?? 'estandar' ), 'estandar' ); ?>>Estandar (schema v1.0, JSON)</option>
				<option value="gf_compat" <?php selected( (string) ( $edit_config['webhook_payload_mode'] ?? 'estandar' ), 'gf_compat' ); ?>>Gravity Forms compat (lead con claves GF → n8n)</option>
				<option value="cf7_compat" <?php selected( (string) ( $edit_config['webhook_payload_mode'] ?? 'estandar' ), 'cf7_compat' ); ?>>CF7 compatible (your-name, your-email…)</option>
			</select>
			<span class="description">«Gravity Forms compat» replica la entrada de GF (lead.1.3, lead.16…) para workflows n8n existentes. «CF7 compatible» sirve para workflows que esperan campos tipo Contact Form 7.</span>
		</td></tr>
		<?php endif; ?>
		<?php if ( $has( 'notify_email' ) ) : $opt_row( 'Notificar por email', 'notify_email', $edit_config['notify_email'] ?? '', 'text', array( 'desc' => 'Correo que recibe un aviso' ) ); endif; ?>
		<?php if ( $has( 'recaptcha' ) ) : $opt_row( 'Protección anti-spam', 'require_recaptcha', $edit_config['require_recaptcha'] ?? '0', 'bool', array( 'desc' => 'Activa reCAPTCHA' ) ); endif; ?>
		<?php if ( $has( 'attachments' ) ) : $opt_row( 'Adjuntos', 'attachments_enabled', $edit_config['attachments_enabled'] ?? '0', 'bool', array( 'desc' => 'Permite adjuntar archivos' ) ); endif; ?>
	</table>
	<?php else : ?>
	<p class="description" style="margin-top:12px;">Sin opciones funcionales habilitadas. Activa capacidades en <strong>Settings → Capacidades</strong>.</p>
	<?php endif; ?>

	<?php // ── Protección de acceso (GATE_PER_FORM_V1 + GATE_DETECT_V1) ── ?>
	<?php
	$gate_flag_on = ! empty( $edit_config['gate_enabled'] ) && (string) $edit_config['gate_enabled'] !== '0';
	$gate_pages   = ( $form && ! empty( $form['id'] ) ) ? \Estandar\Capture\Forms::form_gate_pages( (int) $form['id'] ) : array();
	?>
	<h4 style="margin:18px 0 4px;">Protección de acceso</h4>
	<p class="description" style="margin:0 0 6px;">Opcional. Si está activo, el formulario pide acceso con el mismo sistema del panel de leads (PIN y/o enlace por correo) antes de mostrarse.</p>
	<?php if ( $gate_pages ) : ?>
		<div class="notice notice-info inline" style="margin:0 0 8px;max-width:640px;"><p style="margin:0 0 4px;"><strong>Este formulario ya está protegido</strong> por el shortcode <code>[estandar_form_gate]</code> en:</p>
		<ul style="margin:4px 0 4px 18px;list-style:disc;">
			<?php foreach ( $gate_pages as $gp ) : ?>
				<li><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $gp['id'] ) ); ?>"><?php echo esc_html( $gp['title'] !== '' ? $gp['title'] : ( '#' . $gp['id'] ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<p style="margin:0;" class="description">Activa el check para proteger además las incrustaciones con <code>[estandar_form_v2]</code>.</p></div>
	<?php endif; ?>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Acceso protegido</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-key="gate_enabled" <?php checked( $gate_flag_on || ! empty( $gate_pages ) ); ?> value="1"> <span class="description">Pedir acceso de operador antes de mostrar el formulario</span></label>
		</td></tr>
		<tr><th scope="row">Método</th><td>
			<select class="es-cfg-field" data-key="gate_method">
				<?php foreach ( array( 'inherit' => 'Usar el ajuste general', 'both' => 'PIN + correo', 'pin' => 'Solo PIN', 'magic' => 'Solo correo' ) as $gk => $gl ) : ?>
					<option value="<?php echo esc_attr( $gk ); ?>" <?php selected( (string) ( $edit_config['gate_method'] ?? 'inherit' ), $gk ); ?>><?php echo esc_html( $gl ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description">El ajuste general vive en Core → Configuración → Leads → Panel de Leads.</span>
		</td></tr>
		<tr><th scope="row">Título del acceso</th><td><input type="text" class="es-cfg-field regular-text" data-key="gate_title" value="<?php echo esc_attr( (string) ( $edit_config['gate_title'] ?? '' ) ); ?>" placeholder="Acceso de operador"></td></tr>
		<tr><th scope="row">Nombre en el correo</th><td><input type="text" class="es-cfg-field regular-text" data-key="gate_mail_label" value="<?php echo esc_attr( (string) ( $edit_config['gate_mail_label'] ?? '' ) ); ?>" placeholder="(nombre del formulario)"></td></tr>
	</table>

	<?php // ── Visibilidad (FORM_VISIBILITY_V1): fechas y dispositivo. ── ?>
	<h4 style="margin:18px 0 4px;">Visibilidad</h4>
	<p class="description" style="margin:0 0 6px;">Opcional. Controla cuándo y en qué dispositivo se muestra el formulario. Vacío = siempre visible.</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Desde</th><td><input type="date" class="es-cfg-field" data-key="vis_from" value="<?php echo esc_attr( substr( (string) ( $edit_config['vis_from'] ?? '' ), 0, 10 ) ); ?>"> <span class="description">No se muestra antes de esta fecha.</span></td></tr>
		<tr><th scope="row">Hasta</th><td><input type="date" class="es-cfg-field" data-key="vis_to" value="<?php echo esc_attr( substr( (string) ( $edit_config['vis_to'] ?? '' ), 0, 10 ) ); ?>"> <span class="description">No se muestra después de esta fecha.</span></td></tr>
		<tr><th scope="row">Dispositivo</th><td>
			<select class="es-cfg-field" data-key="vis_device">
				<?php foreach ( array( '' => 'Todos', 'desktop' => 'Solo escritorio', 'mobile' => 'Solo móvil' ) as $vk => $vl ) : ?>
					<option value="<?php echo esc_attr( $vk ); ?>" <?php selected( (string) ( $edit_config['vis_device'] ?? '' ), $vk ); ?>><?php echo esc_html( $vl ); ?></option>
				<?php endforeach; ?>
			</select>
		</td></tr>
		<tr><th scope="row">Mensaje al ocultarse</th><td><input type="text" class="es-cfg-field regular-text" data-key="vis_message" value="<?php echo esc_attr( (string) ( $edit_config['vis_message'] ?? '' ) ); ?>" placeholder="(si se deja vacío, no se muestra nada)"></td></tr>
	</table>

	<?php // ── Información técnica: shortcode y datos internos. ── ?>
	<h4 style="margin:18px 0 4px;">Información técnica</h4>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row"><label for="es-maq-slug">Slug</label></th><td><input id="es-maq-slug" name="slug" class="regular-text" style="width:100%;" value="<?php echo esc_attr( $form['slug'] ?? '' ); ?>" placeholder="se genera del nombre"></td></tr>
		<tr><th scope="row">ID técnico</th><td><code><?php echo $form ? (int) $form['id'] : '—'; ?></code></td></tr>
		<tr><th scope="row">Shortcode</th><td><?php echo \Estandar\Core\Embed::copy_control( $form ? \Estandar\Core\Embed::shortcode( 'form', (int) $form['id'] ) : '' ); ?></td></tr>
	</table>
	<?php echo \Estandar\Core\Embed::copy_script(); ?>
	</div>

	<div class="es-st-panel" data-panel="correos"<?php echo $st !== 'correos' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Correos y confirmación</h4>
	<p class="description">Todo lo que ocurre después de un envío, en el orden del pipeline. Cada bloque tiene su <strong>vista previa real</strong> (cómo se verá) y los cuerpos de correo se editan con el <strong>editor visual</strong> de WordPress (pestañas «Visual» / «HTML»). Lo que se guarda es el mismo HTML del motor único de correo (<code>Core\Mail</code>); nada de lo que ya funciona cambia.</p>
	<?php if ( $maq_has_routing ) : ?>
	<div class="notice notice-info inline" style="margin:8px 0;"><p><strong>Form con campo de routing.</strong> El <strong>aviso interno</strong> y el <strong>correo diferido</strong> se desactivan automáticamente: la notificación la define el campo <em>routing</em> (dropdown del frontend) y usa el <strong>template de correo por defecto</strong>. La <strong>autorespuesta</strong> al que completó se mantiene.</p></div>
	<?php endif; ?>

	<?php
	$st_sst  = \Estandar\Capture\Admin::resolve_editor_sst( (string) ( $_GET['st'] ?? '' ) );
	$sst_tabs = array();
	foreach ( \Estandar\Capture\Admin::editor_sstabs() as $sst_k => $sst_l ) {
		$sst_tabs[ $sst_k ] = array(
			'label' => $sst_l,
			'url'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' . $st_id . '&st=correos&sst=' . $sst_k ),
		);
	}
	$mcfg_c = \Estandar\Capture\Forms::mail_settings( $edit_config );
	$ar_body_c = $mcfg_c['autoresponse']['body'] !== '' ? $mcfg_c['autoresponse']['body'] : \Estandar\Capture\Forms::autoresponse_body_default();
	$mcfg_d = \Estandar\Capture\Forms::delayed_autoresponse_config( $edit_config, (string) ( $edit_config['form_project'] ?? '' ) );
	$mcfg_d_delay = isset( $mcfg_d['delay_minutes'] ) ? (int) $mcfg_d['delay_minutes'] : 0;
	$mcfg = $mcfg_c;
	$rf_fields = \Estandar\Capture\Forms::renderable_fields( $edit_config );
	$route_field = (string) ( $edit_config['notify_route_field'] ?? '' );
	$route_map   = $mcfg['notify']['route_map'];
	$route_opts  = array();
	foreach ( $rf_fields as $rf ) {
		$fn = sanitize_key( (string) ( $rf['name'] ?? '' ) );
		if ( $fn === $route_field ) {
			foreach ( (array) ( $rf['options'] ?? array() ) as $o ) {
				$val = (string) ( is_array( $o ) ? ( $o['value'] ?? $o['label'] ?? '' ) : $o );
				$lbl = (string) ( is_array( $o ) ? ( $o['label'] ?? $val ) : $o );
				if ( $val !== '' ) { $route_opts[ $val ] = $lbl; }
			}
		}
	}
	$notify_body = $mcfg['notify']['body'] !== '' ? $mcfg['notify']['body'] : \Estandar\Capture\Forms::notification_body_default();
	$es_mce_removed = true; // MAIL_HTML_V1: los cuerpos de correo se editan en
	// textareas HTML planos (sin wp_editor/TinyMCE) para evitar la corrupción
	// del texto al guardar (marcadores mce:protected). El preview sigue en vivo.
	?>

	<nav class="es-corr-ss-nav nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin:8px 0 12px;padding:0 0 0 4px;" aria-label="Correos y confirmación">
		<?php foreach ( $sst_tabs as $sst_k => $sst_t ) : ?>
		<a href="<?php echo esc_url( $sst_t['url'] ); ?>" class="nav-tab<?php echo $st_sst === $sst_k ? ' nav-tab-active' : ''; ?>" data-corr-ss="<?php echo esc_attr( $sst_k ); ?>" style="font-size:12.5px;padding:5px 10px;"><?php echo esc_html( $sst_t['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="es-corr-ss" data-corr-ss="pantalla"<?php echo $st_sst !== 'pantalla' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin:0 0 4px;">Qué ve la persona después de enviar</h4>
	<p class="description">El mensaje de gracias, la animación de éxito y el menú que aparece si vuelve a enviar. El formulario <strong>siempre</strong> puede volver a llenarse (incluso tras recargar la página).</p>
	<?php $ranim = \Estandar\Capture\Forms::success_animation( $edit_config ); $rmen = \Estandar\Capture\Forms::repeat_menu_enabled( $edit_config ); ?>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Mensaje de éxito</th><td>
			<textarea class="es-cfg-field regular-text" rows="2" style="width:80%;" data-key="success_message"><?php echo esc_textarea( $edit_config['success_message'] ?? '' ); ?></textarea>
			<span class="description">Se muestra junto a la animación tras un envío aceptado. Ej: «Derivaremos tu mensaje al área que corresponda y te contestaremos lo antes posible».</span>
		</td></tr>
		<tr><th scope="row">Animación de éxito</th><td>
			<select class="es-cfg-field" data-key="success_animation">
				<option value="default" <?php selected( $ranim['mode'], 'default' ); ?>>GIF por defecto del plugin</option>
				<option value="none" <?php selected( $ranim['mode'], 'none' ); ?>>Sin animación</option>
				<option value="custom" <?php selected( $ranim['mode'], 'custom' ); ?>>GIF alternativo (URL)</option>
			</select>
			<span class="description">La animación hace evidente que el envío se realizó.</span>
		</td></tr>
		<tr><th scope="row">URL del GIF alternativo</th><td>
			<input type="url" class="es-cfg-field regular-text" style="width:80%;" data-key="success_animation_url" value="<?php echo esc_attr( $edit_config['success_animation_url'] ?? '' ); ?>" placeholder="https://...">
			<span class="description">Solo se usa si la animación está en «GIF alternativo».</span>
		</td></tr>
		<tr><th scope="row">Redirigir tras completar</th><td>
			<input type="url" class="es-cfg-field regular-text" style="width:80%;" data-key="success_redirect" value="<?php echo esc_attr( (string) ( $edit_config['success_redirect'] ?? '' ) ); ?>" placeholder="https://cliente1.example.com/gracias/">
			<span class="description">SUCCESS_REDIRECT_V1: al completar el envío con éxito, se muestra el mensaje ~1,2 s y se redirige a esta URL. Vacío = se queda en la página. No aplica al reenvío con menú de motivos.</span>
		</td></tr>
		<tr><th scope="row">Menú de motivos tras reenvío</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-nest="repeat_reason" data-key="enabled" <?php checked( $rmen ); ?> value="1"> <span class="description">Si alguien que ya envió vuelve a enviar, se muestra el menú «¿los estás volviendo a enviar porque…?» en lugar de la animación (activado por defecto)</span></label>
		</td></tr>
		<tr><th scope="row">Título del menú</th><td>
			<input type="text" class="es-cfg-field regular-text" style="width:80%;" data-key="repeat_title" value="<?php echo esc_attr( (string) ( $edit_config['repeat_title'] ?? '' ) ); ?>" placeholder="Vimos que ya habías enviado tus datos antes. ¿Los estás volviendo a enviar porque…?">
		</td></tr>
		<tr><th scope="row">Etiqueta de detalle</th><td>
			<input type="text" class="es-cfg-field regular-text" style="width:60%;" data-key="repeat_note_label" value="<?php echo esc_attr( (string) ( $edit_config['repeat_note_label'] ?? '' ) ); ?>" placeholder="Detalle (opcional)">
		</td></tr>
		<tr><th scope="row">Texto del botón</th><td>
			<input type="text" class="es-cfg-field regular-text" style="width:60%;" data-key="repeat_submit_label" value="<?php echo esc_attr( (string) ( $edit_config['repeat_submit_label'] ?? '' ) ); ?>" placeholder="Enviar motivo">
		</td></tr>
		<tr><th scope="row">Opciones del menú</th><td>
			<textarea class="es-cfg-field regular-text" rows="5" style="width:80%;" data-key="repeat_reason_options_text" placeholder="not_contacted_yet=Aún no me contactan&#10;add_info=Quiero agregar información&#10;Mi solicitud cambió"><?php echo esc_textarea( $edit_config['repeat_reason_options_text'] ?? '' ); ?></textarea>
			<span class="description">Una por línea: <code>codigo=Etiqueta</code> (o solo «Etiqueta»). Vacío = opciones canónicas.</span>
		</td></tr>
		<tr><th scope="row">Respuesta única por proyecto</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-key="unique_per_project" <?php checked( ! empty( $edit_config['unique_per_project'] ) ); ?> value="1"> <span class="description">UNIQUE_PROJECT_V1 (opcional, por form): si esta persona ya dejó sus datos en <strong>otro formulario del mismo proyecto</strong>, se le muestra «ya nos dejaste tus datos» y no puede volver a llenar este. <strong>Postventa siempre permite reenvíos.</strong> Cada form decide por separado si participa del bloqueo; otros proyectos no se ven afectados.</span></label>
		</td></tr>
		<tr><th scope="row">Mensaje «ya dejaste tus datos»</th><td>
			<input type="text" class="es-cfg-field regular-text" style="width:90%;" data-key="unique_project_message" value="<?php echo esc_attr( (string) ( $edit_config['unique_project_message'] ?? '' ) ); ?>" placeholder="Ya nos dejaste tus datos en este proyecto. Si necesitas más información o tienes otra consulta, escríbenos y te ayudamos.">
			<span class="description">Vacío = mensaje por defecto. Requiere que el form tenga «Proyecto» asignado y la opción de respuesta única activa.</span>
		</td></tr>
		<tr><th scope="row">Siempre permitir volver a llenar</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-key="always_refillable" <?php checked( ! empty( $edit_config['always_refillable'] ) ); ?> value="1"> <span class="description">ALWAYS_REFILLABLE_V1: este form <strong>siempre</strong> se muestra para volver a llenar, aunque este navegador ya haya enviado. Desactiva el estado «ya enviaste», el menú de motivos y el bloqueo por proyecto en este form. Útil para forms que deben aceptar reenvíos ilimitados (p. ej. terrenos en venta).</span></label>
		</td></tr>
		<tr><th scope="row">Máximo de envíos por IP</th><td>
			<select class="es-cfg-field" data-key="spam_rate_max" style="min-width:200px;">
				<option value="" <?php selected( (string) ( $edit_config['spam_rate_max'] ?? '' ), '' ); ?>>Fábrica (5 envíos)</option>
				<option value="1" <?php selected( (string) ( $edit_config['spam_rate_max'] ?? '' ), '1' ); ?>>Solo una vez (1 envío)</option>
				<option value="5" <?php selected( (string) ( $edit_config['spam_rate_max'] ?? '' ), '5' ); ?>>5 envíos</option>
				<option value="15" <?php selected( (string) ( $edit_config['spam_rate_max'] ?? '' ), '15' ); ?>>15 envíos</option>
			</select>
			<span class="description">Límite anti-spam por IP dentro de una <strong>ventana fija de 10 minutos</strong>. «Siempre permitir volver a llenar» salta este límite.</span>
		</td></tr>
	</table>

	<div style="margin-top:14px;max-width:640px;">
		<button type="button" class="button" id="es-corr-pantalla-toggle" aria-expanded="false" style="font-weight:600;">Ver vista previa de la confirmación en pantalla</button>
	</div>
	<div id="es-corr-pantalla-wrap" style="display:none;margin-top:10px;max-width:640px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;overflow:hidden;">
		<div id="es-corr-pantalla-frame" style="padding:20px;"></div>
	</div>
	</div>

	<div class="es-corr-ss" data-corr-ss="autoresponse"<?php echo $st_sst !== 'autoresponse' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin:0 0 4px;">Correo de confirmación al remitente</h4>
	<p class="description">Lo que recibe automáticamente quien envió el formulario, como confirmación de su solicitud. Se usa el correo que la persona escribió en el campo indicado y el motor único de la Suite (<code>Core\Mail</code>).</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Responder al remitente</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-nest="autoresponse" data-key="enabled" <?php checked( ! empty( $mcfg_c['autoresponse']['enabled'] ) ); ?> value="1"> <span class="description">Enviar correo de confirmación al remitente al completar el envío</span></label>
		</td></tr>
		<tr><th scope="row">Campo de email del remitente</th><td>
			<input type="text" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="email_field" style="width:40%;" value="<?php echo esc_attr( $mcfg_c['autoresponse']['email_field'] ); ?>">
			<span class="description">Campo del formulario que contiene el correo del remitente (ej. <code>email</code>).</span>
		</td></tr>
		<tr><th scope="row">Asunto</th><td>
			<input type="text" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="subject" style="width:60%;" value="<?php echo esc_attr( $mcfg_c['autoresponse']['subject'] ); ?>">
		</td></tr>
		<tr><th scope="row">Remitente</th><td>
			<input type="text" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="sender" style="width:40%;" value="<?php echo esc_attr( (string) ( $edit_config['autoresponse']['sender'] ?? '' ) ); ?>" placeholder="Nombre (vacío = default)">
			<input type="email" class="es-cfg-field regular-text" data-nest="autoresponse" data-key="from_email" style="width:40%;" value="<?php echo esc_attr( (string) ( $edit_config['autoresponse']['from_email'] ?? '' ) ); ?>" placeholder="no-reply@dominio.cl">
			<span class="description">Nombre y correo que firman esta confirmación. Vacío = lo que configures en «Identidad visual» (o el administrador del sitio).</span>
		</td></tr>
		<tr><th scope="row">Cuerpo (HTML)</th><td>
			<textarea id="es-corr-ar-body" rows="10" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12.5px;" class="es-cfg-field"><?php echo esc_textarea( $ar_body_c ); ?></textarea>
			<span class="description">Plantilla corporativa precargada; variables {{name}}, {{form_name}}, {{message}}, {{logo}}, {{footer_estandar}}. <strong>Editor en modo HTML</strong> (sin TinyMCE: evita la corrupción del texto al guardar). Usa el botón «Ver vista previa» para el resultado visual.</span>
		</td></tr>
		<tr><th scope="row">Cuerpo para recontactos (opcional)</th><td>
			<textarea id="es-corr-ar-body-rec" rows="6" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12.5px;" class="es-cfg-field"><?php echo esc_textarea( (string) ( $edit_config['autoresponse']['body_recontact'] ?? '' ) ); ?></textarea>
			<span class="description"><strong>RECONTACT_AR_V1:</strong> si la persona vuelve a enviar sus datos (recontacto), se usa este cuerpo en vez del anterior (reconoce el contacto previo). Vacío = se usa el cuerpo normal. Mismas variables {{…}}.</span>
		</td></tr>
	</table>

	<?php
	$corr_prev_vars = array_merge( \Estandar\Capture\Forms::brand_vars(), array(
		'name'          => 'María Pérez',
		'form_name'     => (string) ( $form['name'] ?? 'Formulario' ),
		'message'       => 'Solicitud de postventa de ejemplo enviada desde la web.',
		'email'         => 'maria@ejemplo.cl',
		'phone'         => '9 1234 5678',
		'fields'        => "Nombre: María Pérez\nEmail: maria@ejemplo.cl\nTeléfono: 9 1234 5678",
		'submission_id' => '0',
		'date'          => gmdate( 'Y-m-d H:i:s' ),
	) );
	$corr_render = function ( string $tpl ) use ( $corr_prev_vars ) {
		return class_exists( 'Estandar\\Core\\Mail' ) ? \Estandar\Core\Mail::render( $tpl, $corr_prev_vars ) : $tpl;
	};
	?>
	<div style="margin-top:14px;max-width:640px;">
		<button type="button" class="button es-corr-mail-toggle" data-toggle="es-corr-ar-wrap" aria-expanded="false" style="font-weight:600;">Ver vista previa del correo de confirmación</button>
	</div>
	<div id="es-corr-ar-wrap" class="es-corr-mail-wrap" style="display:none;margin-top:10px;max-width:640px;">
		<div style="margin-bottom:6px;"><strong>Asunto:</strong> <span class="es-corr-subject" data-subject-of="es-corr-ar-body"><?php echo esc_html( $corr_render( $mcfg_c['autoresponse']['subject'] ) ); ?></span></div>
		<iframe data-preview-of="es-corr-ar-body" sandbox="" title="Vista previa del correo de confirmación" style="width:100%;height:300px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;box-sizing:border-box;"></iframe>
		<p class="description" style="margin-top:6px;">Vista previa real (cabecera + cuerpo + pie con la identidad visual del paso 5). Se actualiza al editar.</p>
	</div>
	</div>

	<div class="es-corr-ss" data-corr-ss="diferido"<?php echo $st_sst !== 'diferido' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin:0 0 4px;">Correo diferido (autorespuesta marketera)</h4>
	<p class="description">Instancia SEPARADA de la comprobación inmediata: se programa N minutos después de la ingesta, firmada por el operador asignado, con tu invitación y PDF. Si dejas el cuerpo vacío se usa el de la comprobación o el del proyecto (legacy).</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Habilitar diferido</th><td>
			<label><input type="checkbox" class="es-cfg-field" data-nest="autoresponse_delayed" data-key="enabled"<?php echo $maq_has_routing ? ' disabled' : ''; ?> <?php if ( ! $maq_has_routing ) { checked( ! empty( $mcfg_d['enabled'] ) ); } ?> value="1"> <span class="description">Enviar el correo marketero N minutos después de la comprobación inmediata<?php echo $maq_has_routing ? ' — <strong>desactivado automáticamente</strong>: este form usa un campo de routing.' : ''; ?></span></label>
		</td></tr>
		<tr><th scope="row">Minutos de desfase</th><td>
			<input type="number" min="1" max="4320" class="es-cfg-field regular-text" data-nest="autoresponse_delayed" data-key="delay_minutes" style="width:120px;" value="<?php echo esc_attr( $mcfg_d_delay > 0 ? (string) $mcfg_d_delay : '' ); ?>">
			<span class="description">Ej. 10 = se envía 10 minutos después</span>
		</td></tr>
		<tr><th scope="row">Asunto</th><td>
			<input type="text" class="es-cfg-field regular-text" data-nest="autoresponse_delayed" data-key="subject" style="width:70%;" value="<?php echo esc_attr( (string) ( $edit_config['autoresponse_delayed']['subject'] ?? '' ) ); ?>">
		</td></tr>
		<tr><th scope="row">Cuerpo (HTML)</th><td>
			<textarea id="es-corr-df-body" rows="8" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12.5px;" class="es-cfg-field"><?php echo esc_textarea( (string) ( $edit_config['autoresponse_delayed']['body'] ?? '' ) ); ?></textarea>
			<span class="description">Vacío = usa el de la comprobación o el del proyecto. Variables {{name}}, {{form_name}}, {{message}}, {{logo}}, {{footer_estandar}}, {{pdf_link}}, {{landing_link}}. Editor en modo HTML (sin TinyMCE).</span>
		</td></tr>
		<tr><th scope="row">Adjunto PDF (ID)</th><td>
			<input type="number" min="0" class="es-cfg-field regular-text" data-nest="autoresponse_delayed" data-key="attachment_id" style="width:160px;" value="<?php echo esc_attr( (string) ( $edit_config['autoresponse_delayed']['attachment_id'] ?? '' ) ); ?>">
			<span class="description">ID del archivo adjunto en Medios (se adjunta y enlaza como {{pdf_link}})</span>
		</td></tr>
		<tr><th scope="row">Landing (URL)</th><td>
			<input type="url" class="es-cfg-field regular-text" data-nest="autoresponse_delayed" data-key="landing_url" style="width:80%;" value="<?php echo esc_attr( (string) ( $edit_config['autoresponse_delayed']['landing_url'] ?? '' ) ); ?>" placeholder="https://...">
			<span class="description">Enlace de invitación ({{landing_link}})</span>
		</td></tr>
	</table>
	<div style="margin-top:14px;max-width:640px;">
		<button type="button" class="button es-corr-mail-toggle" data-toggle="es-corr-df-wrap" aria-expanded="false" style="font-weight:600;">Ver vista previa del correo diferido</button>
	</div>
	<div id="es-corr-df-wrap" class="es-corr-mail-wrap" style="display:none;margin-top:10px;max-width:640px;">
		<div style="margin-bottom:6px;"><strong>Asunto:</strong> <span class="es-corr-subject" data-subject-of="es-corr-df-body"><?php echo esc_html( $corr_render( (string) ( $edit_config['autoresponse_delayed']['subject'] ?? '' ) ) ); ?></span></div>
		<iframe data-preview-of="es-corr-df-body" sandbox="" title="Vista previa del correo diferido" style="width:100%;height:300px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;box-sizing:border-box;"></iframe>
		<p class="description" style="margin-top:6px;">Vista previa real (cabecera + cuerpo + pie). Se actualiza al editar.</p>
	</div>
	</div>

	<div class="es-corr-ss" data-corr-ss="notify"<?php echo $st_sst !== 'notify' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin:0 0 4px;">Notificación al equipo</h4>
	<p class="description">Aquí decides <strong>quién recibe el aviso</strong> cuando llega un envío. Es un correo interno distinto del que recibe quien escribe (paso 2). Los correos usan la imagen y los colores de la instalación (paso 5) y se gestionan por el motor único de la Suite (<code>Core\Mail</code>).</p>
	<table class="widefat striped" style="max-width:840px;">
		<tbody>
			<tr><th scope="row">Notificación interna</th><td>
				<label><input type="checkbox" class="es-cfg-field" data-key="send_email"<?php echo $maq_has_routing ? ' disabled' : ''; ?> <?php if ( ! $maq_has_routing ) { checked( ! empty( $edit_config['send_email'] ) ); } ?> value="1"> <span class="description">Enviar aviso interno al recibir un envío<?php echo $maq_has_routing ? ' — <strong>desactivado automáticamente</strong>: este form usa un campo de routing (el aviso va a los correos del routing).' : ''; ?></span></label><br>
				<label><span class="description">Destinatarios (separados por coma)</span></label><br><input type="text" class="es-cfg-field regular-text" data-key="notify_emails" style="width:60%;" value="<?php echo esc_attr( $mcfg['notify']['to'] ); ?>"><br>
				<label><input type="checkbox" class="es-cfg-field" data-key="notify_cc_admin" <?php checked( $mcfg['notify']['cc_admin'] ); ?> value="1"> <span class="description">Enviar copia al administrador de la página (activado por defecto)</span></label><br>
				<label><span class="description">Routing por opción de un selector (opcional): elige el campo y asigna un correo por opción</span></label><br>
				<select class="es-cfg-field" data-key="notify_route_field" id="es-route-field" style="width:60%;">
					<option value="">Sin routing — todos los envíos van a «Destinatarios»</option>
					<?php foreach ( $rf_fields as $rf ) : $fn = sanitize_key( (string) ( $rf['name'] ?? '' ) ); if ( $fn === '' ) { continue; } ?>
						<option value="<?php echo esc_attr( $fn ); ?>" <?php selected( $route_field, $fn ); ?>><?php echo esc_html( (string) ( $rf['label'] ?? $fn ) ); ?></option>
					<?php endforeach; ?>
				</select><br>
				<?php if ( $route_field !== '' && ! empty( $route_opts ) ) : ?>
					<div id="es-route-map" style="margin-top:6px;">
						<?php foreach ( $route_opts as $val => $lbl ) :
							$key   = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $val, 'UTF-8' ) : strtolower( (string) $val );
							$emails = isset( $route_map[ $key ] ) ? $route_map[ $key ] : '';
						?>
							<label style="display:block;margin:4px 0;"><span class="description"><?php echo esc_html( $lbl ); ?> →</span>
							<input type="text" class="es-route-map regular-text" data-opt="<?php echo esc_attr( $val ); ?>" style="width:50%;" value="<?php echo esc_attr( $emails ); ?>" placeholder="correo1, correo2"></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<input type="hidden" class="es-cfg-field" data-key="notify_route_map_text" id="es-route-map-text" value="<?php echo esc_attr( $edit_config['notify_route_map_text'] ?? '' ); ?>"><br>
				<label><span class="description">Remitente</span></label><br>
				<input type="text" class="es-cfg-field regular-text" data-key="mail_sender_name" style="width:40%;" value="<?php echo esc_attr( (string) ( $edit_config['mail_sender_name'] ?? '' ) ); ?>" placeholder="Nombre (vacío = sitio)">
				<input type="email" class="es-cfg-field regular-text" data-key="mail_from_email" style="width:40%;" value="<?php echo esc_attr( (string) ( $edit_config['mail_from_email'] ?? '' ) ); ?>" placeholder="no-reply@dominio.cl">
				<span class="description">Quién firma este aviso. Vacío = el administrador del sitio por defecto.</span><br>
				<label><span class="description">Responder a</span></label><br>
				<input type="text" class="es-cfg-field regular-text" data-key="mail_reply_to_field" style="width:60%;" value="<?php echo esc_attr( (string) ( $edit_config['mail_reply_to_field'] ?? '' ) ); ?>" placeholder="campo_email_o_correo_fijo">
				<span class="description">Vacío = el correo del propio remitente (poder responderle directamente). Puede ser un campo del form (su email) o un correo fijo.</span><br>
				<label><span class="description">Asunto</span></label><br><input type="text" class="es-cfg-field regular-text" data-key="mail_subject" style="width:60%;" value="<?php echo esc_attr( $mcfg['notify']['subject'] ); ?>"><br>
				<label><span class="description">Cuerpo (HTML; plantilla corporativa precargada; variables {{name}}, {{email}}, {{fields}}, {{form_name}}, {{logo}}, {{footer_estandar}})</span></label><br>
				<textarea id="es-corr-nf-body" rows="10" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12.5px;" class="es-cfg-field"><?php echo esc_textarea( $notify_body ); ?></textarea>
				<span class="description">Editor en modo HTML (sin TinyMCE: evita la corrupción del texto al guardar).</span>
			</td></tr>
		</tbody>
	</table>
	<div style="margin-top:14px;max-width:640px;">
		<button type="button" class="button es-corr-mail-toggle" data-toggle="es-corr-nf-wrap" aria-expanded="false" style="font-weight:600;">Ver vista previa de la notificación al equipo</button>
	</div>
	<div id="es-corr-nf-wrap" class="es-corr-mail-wrap" style="display:none;margin-top:10px;max-width:640px;">
		<div style="margin-bottom:6px;"><strong>Asunto:</strong> <span class="es-corr-subject" data-subject-of="es-corr-nf-body"><?php echo esc_html( $corr_render( $mcfg['notify']['subject'] ) ); ?></span></div>
		<iframe data-preview-of="es-corr-nf-body" sandbox="" title="Vista previa de la notificación al equipo" style="width:100%;height:300px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;box-sizing:border-box;"></iframe>
		<p class="description" style="margin-top:6px;">Vista previa real (cabecera + cuerpo + pie). Se actualiza al editar.</p>
	</div>
	</div>

	<?php
	// MAIL_TEMPLATES_V1 · Fase 3: selector de plantilla por form.
	$mtpl         = class_exists( 'Estandar\\Core\\Mail' ) ? \Estandar\Core\Mail::mail_templates() : array();
	$mtpl_default = class_exists( 'Estandar\\Core\\Mail' ) ? (string) get_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, '' ) : '';
	$mtpl_cur     = (string) ( $edit_config['mail_template'] ?? '' );
	$mtpl_js      = array();
	foreach ( (array) $mtpl as $mtslug => $mtt ) {
		$mtpl_js[ $mtslug ] = array(
			'wrapper_style' => (string) ( $mtt['wrapper_style'] ?? '' ),
			'header_html'   => (string) ( $mtt['header_html'] ?? '' ),
			'content_style' => (string) ( $mtt['content_style'] ?? '' ),
			'footer_html'   => (string) ( $mtt['footer_html'] ?? '' ),
		);
	}
	?>

	<div class="es-corr-ss" data-corr-ss="identidad"<?php echo $st_sst !== 'identidad' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin:0 0 4px;">Identidad visual compartida</h4>
	<p class="description">La cabecera (logo o nombre sobre color) y el pie que llevan TODOS los correos de este form (confirmación, diferido y notificación). Vacío = lo que configures en la instalación (Capture → Leads → Email).</p>
	<table class="form-table" role="presentation" style="max-width:640px;">
		<tr><th scope="row">Color de cabecera</th><td>
			<input type="text" class="es-cfg-field regular-text" data-key="mail_brand_color" style="width:140px;" value="<?php echo esc_attr( (string) ( $edit_config['mail_brand_color'] ?? '' ) ); ?>" placeholder="#000000">
			<span class="description">Vacío = color de la instalación (Capture → Leads → Email)</span>
		</td></tr>
		<tr><th scope="row">Logo (URL)</th><td>
			<input type="text" class="es-cfg-field regular-text" data-key="mail_logo_url" style="width:60%;" value="<?php echo esc_attr( (string) ( $edit_config['mail_logo_url'] ?? '' ) ); ?>" placeholder="https://sitio.cl/logo.png">
			<span class="description">Vacío = logo de la instalación</span>
		</td></tr>
		<tr><th scope="row">Nombre de cabecera</th><td>
			<input type="text" class="es-cfg-field regular-text" data-key="mail_header_text" style="width:60%;" value="<?php echo esc_attr( (string) ( $edit_config['mail_header_text'] ?? '' ) ); ?>">
			<span class="description">Vacío = nombre del sitio</span>
		</td></tr>
		<tr><th scope="row">Footer (HTML)</th><td>
			<textarea class="es-cfg-field regular-text" data-key="mail_footer_text" rows="3" style="width:90%;"><?php echo esc_textarea( (string) ( $edit_config['mail_footer_text'] ?? '' ) ); ?></textarea>
			<span class="description">Vacío = footer Estandar</span>
		</td></tr>
		<tr><th scope="row">Fondo del correo</th><td>
			<input type="text" class="es-cfg-field regular-text" data-key="mail_background_color" style="width:140px;" value="<?php echo esc_attr( (string) ( $edit_config['mail_background_color'] ?? '' ) ); ?>" placeholder="#f2f2f2">
			<span class="description">Fondo de página del correo (envuelve el contenido). Vacío = sin fondo (retrocompatible)</span>
		</td></tr>
		<tr><th scope="row">Plantilla de correo</th><td>
			<select class="es-cfg-field" data-key="mail_template" style="width:60%;">
				<option value="" <?php selected( '', $mtpl_cur ); ?>>Heredar (plantilla por defecto de la instalación)</option>
				<option value="none" <?php selected( 'none', $mtpl_cur ); ?>>Ninguna (sin plantilla)</option>
				<?php foreach ( (array) $mtpl as $mtslug => $mtt ) : ?>
					<option value="<?php echo esc_attr( $mtslug ); ?>" <?php selected( $mtslug, $mtpl_cur ); ?>><?php echo esc_html( (string) ( $mtt['name'] ?? $mtslug ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description">Envuelve los correos de este form con la cabecera/pie de la plantilla (Correo → Plantillas). «Heredar» usa el default global<?php echo $mtpl_default !== '' ? ' («' . esc_html( $mtpl_default ) . '»)' : ' (hoy: ninguna)'; ?>. Al usar plantilla, deja de inyectarse la cabecera/pie de identidad de este form.</span>
		</td></tr>
	</table>
	<div style="margin-top:14px;max-width:640px;">
		<button type="button" class="button" id="es-corr-idn-toggle" aria-expanded="false" style="font-weight:600;">Ver vista previa de la identidad</button>
	</div>
	<div id="es-corr-idn-wrap" style="display:none;margin-top:10px;max-width:640px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;overflow:hidden;">
		<div id="es-corr-idn-frame"></div>
	</div>
	</div>

	<script>
	(function () {
		var panel = document.querySelector('.es-st-panel[data-panel="correos"]');
		if (!panel) { return; }
		// Los cuerpos de correo son textareas HTML planos (MAIL_HTML_V1, sin
		// TinyMCE); aquí se les mapea id → ruta del config para la serialización.
		[['es-corr-ar-body','autoresponse','body'],['es-corr-ar-body-rec','autoresponse','body_recontact'],['es-corr-df-body','autoresponse_delayed','body'],['es-corr-nf-body','','mail_body']].forEach(function (m) {
			var el = document.getElementById(m[0]);
			if (!el) { return; }
			if (m[1]) { el.setAttribute('data-nest', m[1]); }
			el.setAttribute('data-key', m[2]);
			el.classList.add('es-cfg-field');
		});
		var SITE_NAME = <?php echo wp_json_encode( (string) ( $corr_prev_vars['site_name'] ?? '' ), JSON_UNESCAPED_UNICODE ); ?>;
		var FORM_NAME = <?php echo wp_json_encode( (string) ( $form['name'] ?? 'Formulario' ), JSON_UNESCAPED_UNICODE ); ?>;
		var ES_CORR_VARS = {
			'{{name}}': 'María Pérez',
			'{{form_name}}': FORM_NAME,
			'{{email}}': 'maria@ejemplo.cl',
			'{{phone}}': '9 1234 5678',
			'{{message}}': 'Solicitud de postventa de ejemplo enviada desde la web.',
			'{{fields}}': 'Nombre: María Pérez\nEmail: maria@ejemplo.cl\nTeléfono: 9 1234 5678',
			'{{date}}': <?php echo wp_json_encode( gmdate( 'Y-m-d H:i:s' ), JSON_UNESCAPED_UNICODE ); ?>,
			'{{submission_id}}': '0'
		};
		var ES_MAIL_TPL = <?php echo wp_json_encode( $mtpl_js ); ?>;
		var ES_MAIL_TPL_DEFAULT = <?php echo wp_json_encode( $mtpl_default ); ?>;
		function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
		function cfgVal(key) {
			var el = panel.querySelector('[data-key="' + key + '"]');
			return el ? el.value : '';
		}
		function identity() {
			return {
				brand_color: cfgVal('mail_brand_color') || '#000000',
				logo_url: cfgVal('mail_logo_url') || '',
				header_text: cfgVal('mail_header_text') || SITE_NAME,
				footer_text: cfgVal('mail_footer_text') || ''
			};
		}
		function headerHtml() {
			var idn = identity();
			var content = idn.logo_url
				? '<img src="' + esc(idn.logo_url) + '" alt="" style="max-height:44px;width:auto;display:block;">'
				: '<span style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-.3px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">' + esc(idn.header_text) + '</span>';
			return '<div style="padding:14px 16px;border-radius:8px 8px 0 0;background:' + esc(idn.brand_color) + ';">' + content + '</div>';
		}
		function footerHtml() {
			return '<div style="margin-top:22px;padding-top:10px;border-top:1px solid #e2e4e7;color:#888;font-size:12px;">' + identity().footer_text + '</div>';
		}
		function tplSubst(s) {
			var idn = identity();
			return String(s)
				.split('{{site_name}}').join(esc(SITE_NAME))
				.split('{{brand_color}}').join(esc(idn.brand_color))
				.split('{{footer_text}}').join(idn.footer_text)
				.split('{{logo}}').join(headerHtml());
		}
		function activeTemplate() {
			var sel = cfgVal('mail_template');
			if (sel === '') { sel = ES_MAIL_TPL_DEFAULT; }
			return (sel && sel !== 'none' && ES_MAIL_TPL[sel]) ? ES_MAIL_TPL[sel] : null;
		}
		function buildBody(body) {
			var t = activeTemplate();
			if (t) {
				var inner = body.split('{{logo}}').join('').split('{{footer_estandar}}').join('');
				var wrapStyle = String(t.wrapper_style || '').replace(/"/g, '&quot;')
					|| 'max-width:600px;margin:0 auto;background:#ffffff;';
				return '<div style="' + wrapStyle + '">'
					+ tplSubst(t.header_html)
					+ '<div style="' + String(t.content_style).replace(/"/g, '&quot;') + '">' + inner + '</div>'
					+ tplSubst(t.footer_html) + '</div>';
			}
			return body.split('{{logo}}').join(headerHtml()).split('{{footer_estandar}}').join(footerHtml());
		}
		function fieldBody(sel) {
			var el = panel.querySelector(sel);
			if (!el) { return ''; }
			if (el.tagName === 'TEXTAREA' && el.id && window.tinymce && tinymce.get(el.id)) {
				return tinymce.get(el.id).getContent();
			}
			return el.value;
		}
		function renderOne(subjectOf, previewOf, subjSel, bodySel) {
			var subjEl = panel.querySelector('.es-corr-subject[data-subject-of="' + subjectOf + '"]');
			var frame  = panel.querySelector('iframe[data-preview-of="' + previewOf + '"]');
			if (!frame) { return; }
			var subject = fieldBody(subjSel) || '';
			var body    = fieldBody(bodySel) || '';
			Object.keys(ES_CORR_VARS).forEach(function (k) {
				subject = subject.split(k).join(ES_CORR_VARS[k]);
				body    = body.split(k).join(ES_CORR_VARS[k]);
			});
			if (subjEl) { subjEl.textContent = subject || '—'; }
			frame.srcdoc = buildBody(body);
		}
		function renderAll() {
			renderOne('es-corr-ar-body', 'es-corr-ar-body', '[data-nest="autoresponse"][data-key="subject"]', '[data-nest="autoresponse"][data-key="body"]');
			renderOne('es-corr-df-body', 'es-corr-df-body', '[data-nest="autoresponse_delayed"][data-key="subject"]', '[data-nest="autoresponse_delayed"][data-key="body"]');
			renderOne('es-corr-nf-body', 'es-corr-nf-body', '[data-key="mail_subject"]', '[data-key="mail_body"]');
			renderPantalla();
			renderIdentidad();
		}
		function renderPantalla() {
			var frame = document.getElementById('es-corr-pantalla-frame');
			if (!frame) { return; }
			var msg = cfgVal('success_message') || '¡Gracias! Hemos recibido tu mensaje y te contactaremos a la brevedad.';
			var anim = cfgVal('success_animation') || 'default';
			var animHtml = anim === 'none' ? '' : '<div style="font-size:34px;line-height:1;margin:6px 0 4px;">🎉</div>';
			frame.innerHTML = '<div style="max-width:420px;margin:0 auto;padding:6px;text-align:center;font-family:Helvetica,Arial,sans-serif;">'
				+ animHtml
				+ '<p style="margin:8px 0 0;font-size:15px;color:#1d2327;">' + esc(msg) + '</p>'
				+ '<p class="description" style="font-size:12px;color:#888;margin-top:10px;">Vista previa de la confirmación en pantalla</p></div>';
		}
		function renderIdentidad() {
			var frame = document.getElementById('es-corr-idn-frame');
			if (!frame) { return; }
			frame.innerHTML = '<div style="max-width:520px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;padding:6px;">'
				+ headerHtml()
				+ '<p style="margin:16px 0 0;">Hola <strong>María Pérez</strong>,</p>'
				+ '<p>Ejemplo del cuerpo con la identidad visual de este form.</p>'
				+ footerHtml()
				+ '</div>';
		}
		// ── Nav interno de sub-sub-tabs (sin recarga, preserva cambios) ──
		var nav = panel.querySelector('.es-corr-ss-nav');
		var sss = panel.querySelectorAll('.es-corr-ss');
		if (nav && sss.length) {
			Array.prototype.forEach.call(nav.querySelectorAll('a[data-corr-ss]'), function (a) {
				a.addEventListener('click', function (e) {
					var key = a.getAttribute('data-corr-ss');
					e.preventDefault();
					Array.prototype.forEach.call(sss, function (p) { p.style.display = p.getAttribute('data-corr-ss') === key ? '' : 'none'; });
					Array.prototype.forEach.call(nav.querySelectorAll('a[data-corr-ss]'), function (x) { x.classList.toggle('nav-tab-active', x === a); });
					var url = new URL(window.location.href);
					url.searchParams.set('st', 'correos');
					url.searchParams.set('sst', key);
					window.history.replaceState(null, '', url.toString());
				});
			});
		}
		// ── Toggles de preview (botones) ──
		Array.prototype.forEach.call(panel.querySelectorAll('.es-corr-mail-toggle'), function (b) {
			b.addEventListener('click', function () {
				var wrap = document.getElementById(b.getAttribute('data-toggle'));
				if (!wrap) { return; }
				var open = wrap.style.display !== 'none';
				wrap.style.display = open ? 'none' : '';
				b.setAttribute('aria-expanded', open ? 'false' : 'true');
				if (!open) { renderAll(); }
			});
		});
		Array.prototype.forEach.call([['es-corr-pantalla-toggle','es-corr-pantalla-wrap'],['es-corr-idn-toggle','es-corr-idn-wrap']], function (pair) {
			var b = document.getElementById(pair[0]);
			var wrap = document.getElementById(pair[1]);
			if (!b || !wrap) { return; }
			b.addEventListener('click', function () {
				var open = wrap.style.display !== 'none';
				wrap.style.display = open ? 'none' : '';
				b.setAttribute('aria-expanded', open ? 'false' : 'true');
				if (!open) { renderAll(); }
			});
		});
		// ── Refresh en vivo: inputs + editores TinyMCE ──
		panel.addEventListener('input', function (e) {
			var el = e.target;
			if (el && el.getAttribute && (el.hasAttribute('data-key') || el.hasAttribute('data-nest'))) { renderAll(); }
		});
		if (window.tinymce && tinymce.on) {
			tinymce.on('AddEditor', function (ev) {
				ev.editor.on('change', function () { renderAll(); });
				ev.editor.on('input', function () { renderAll(); });
			});
		}
		renderAll();
	})();
	</script>
	</div>

	<div class="es-st-panel" data-panel="entradas"<?php echo $st !== 'entradas' ? ' style="display:none;"' : ''; ?>>
	<h4 style="margin-top:0;">Entradas de este <?php echo esc_html( $fp_singular ); ?></h4>
	<?php if ( $form ) :
		$ef_status  = sanitize_key( (string) ( $_GET['efstatus'] ?? 'active' ) );
		$ef_status  = in_array( $ef_status, array( 'active', 'trash' ), true ) ? $ef_status : 'active';
		$ef_search  = trim( (string) ( $_GET['efq'] ?? '' ) );
		$ef_orderby = sanitize_key( (string) ( $_GET['eforderby'] ?? 'id' ) );
		$ef_order   = strtoupper( (string) ( $_GET['eforder'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$entradas   = \Estandar\Capture\Forms::submissions_for_form( (int) $form['id'], 100, 0, $ef_status, $ef_search, $ef_orderby, $ef_order );
		$total_act  = \Estandar\Capture\Forms::submissions_count( (int) $form['id'], 'active' );
		$total_trash = \Estandar\Capture\Forms::submissions_count( (int) $form['id'], 'trash' );
		$base_url   = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&st=entradas' );
		if ( isset( $_GET['submission_notice'] ) ) : $notice = sanitize_key( (string) $_GET['submission_notice'] ); ?>
			<div class="notice notice-success is-dismissible"><p><?php
				$labels = array( 'trashed' => 'Entrada enviada a la papelera.', 'restored' => 'Entrada restaurada.', 'deleted' => 'Entrada eliminada definitivamente.', 'error' => 'No se pudo aplicar la acción.' );
				echo esc_html( $labels[ $notice ] ?? '' );
			?></p></div>
		<?php endif; ?>
	<p class="description">Cada envío del formulario con su contexto completo: campaña, origen, sesión, tiempo en página, geolocalización e IP.</p>
	<ul class="subsubsub" style="margin-bottom:8px;">
		<li><a href="<?php echo esc_url( add_query_arg( 'efstatus', 'active', $base_url ) ); ?>"<?php echo $ef_status === 'active' ? ' class="current" aria-current="page"' : ''; ?>>Activas <span class="count">(<?php echo (int) $total_act; ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( add_query_arg( 'efstatus', 'trash', $base_url ) ); ?>"<?php echo $ef_status === 'trash' ? ' class="current" aria-current="page"' : ''; ?>>Papelera <span class="count">(<?php echo (int) $total_trash; ?>)</span></a></li>
	</ul>
	<div style="margin:0 0 10px;">
		<input type="search" id="es-maq-efq" value="<?php echo esc_attr( $ef_search ); ?>" placeholder="Buscar por nombre, email o teléfono" class="regular-text" style="width:280px;">
		<button type="button" class="button" id="es-maq-ef-search">Buscar</button>
		<?php if ( $ef_search !== '' ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'efstatus', $ef_status, $base_url ) ); ?>">Limpiar</a><?php endif; ?>
	</div>
	<?php if ( $entradas ) : ?>
	<table class="widefat striped" style="border-collapse:collapse;">
		<thead><tr>
			<th><a href="<?php echo esc_url( add_query_arg( array( 'eforderby' => 'created_at', 'eforder' => $ef_orderby === 'created_at' && $ef_order === 'DESC' ? 'ASC' : 'DESC' ), add_query_arg( 'efstatus', $ef_status, $base_url ) ) ); ?>">Fecha</a></th>
			<th><a href="<?php echo esc_url( add_query_arg( array( 'eforderby' => 'name', 'eforder' => $ef_orderby === 'name' && $ef_order === 'DESC' ? 'ASC' : 'DESC' ), add_query_arg( 'efstatus', $ef_status, $base_url ) ) ); ?>">Nombre</a></th>
			<th>Contacto</th>
			<th>Origen (campaña)</th>
			<th>Contexto (sesión · tiempo · geo · IP)</th>
			<th>Estado</th>
			<th>Acciones</th>
		</tr></thead>
		<tbody>
		<?php foreach ( $entradas as $en ) : $ep = \Estandar\Capture\Forms::pipeline_for_capture( (int) $en['id'] ); $edata = json_decode( (string) ( $en['fields_json'] ?? '' ), true ); ?>
			<tr>
				<td style="white-space:nowrap;"><?php echo esc_html( (string) $en['created_at'] ); ?></td>
				<td><?php echo $en['name'] !== '' ? esc_html( (string) $en['name'] ) : '<span class="description">—</span>'; ?></td>
				<td><?php $contact = trim( (string) $en['email_normalized'] . ( (string) $en['phone_normalized'] !== '' ? ' · ' . (string) $en['phone_normalized'] : '' ) ); echo $contact !== '' ? esc_html( $contact ) : '<span class="description">—</span>'; ?></td>
				<td><?php
					$origen = array();
					foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $ok ) { if ( (string) $en[ $ok ] !== '' ) { $origen[] = (string) $en[ $ok ]; } }
					echo $origen ? esc_html( implode( ' · ', $origen ) ) : '<span class="description">directo</span>';
				?></td>
				<td style="font-size:12px;"><?php
					$ctx = array();
					if ( (string) $en['session_id'] !== '' ) { $ctx[] = 'sesión ' . substr( (string) $en['session_id'], 0, 8 ); }
					if ( (int) $en['time_on_page'] > 0 ) { $ctx[] = (int) $en['time_on_page'] . 's en página'; }
					$geo = trim( implode( ', ', array_filter( array( (string) $en['geo_city'], (string) $en['geo_region'], (string) $en['geo_country'] ) ) ) );
					if ( $geo !== '' ) { $ctx[] = $geo; }
					if ( (string) $en['ip_address'] !== '' ) { $ctx[] = 'IP ' . (string) $en['ip_address']; }
					echo $ctx ? esc_html( implode( ' · ', $ctx ) ) : '<span class="description">—</span>';
				?></td>
				<td><?php
					if ( (string) $en['status'] === 'trash' ) { echo '<span class="description">papelera</span>'; }
					elseif ( (string) $en['repeat_reason'] !== '' ) { $rr = \Estandar\Capture\Forms::repeat_reasons( json_decode( (string) $form['config_json'], true ) ); echo esc_html( 'recontacto: ' . ( $rr[ $en['repeat_reason'] ] ?? $en['repeat_reason'] ) ); }
					elseif ( $ep ) { echo esc_html( 'lead ' . $ep['stage'] ); }
					else { echo 'activa'; }
				?></td>
				<td style="white-space:nowrap;">
					<details style="display:inline-block;"><summary class="button-link">Ver detalle</summary>
						<div style="margin-top:6px;max-width:420px;">
							<?php if ( is_array( $edata ) && $edata ) : foreach ( $edata as $efk => $efv ) : ?>
								<p style="margin:2px 0;"><code><?php echo esc_html( (string) $efk ); ?>:</code> <?php echo esc_html( (string) $efv ); ?></p>
							<?php endforeach; else : ?><span class="description">sin datos</span><?php endif; ?>
							<?php if ( (string) $en['landing_url'] !== '' ) : ?><p style="margin:2px 0;"><code>landing:</code> <?php echo esc_html( (string) $en['landing_url'] ); ?></p><?php endif; ?>
							<?php if ( (string) $en['referrer'] !== '' ) : ?><p style="margin:2px 0;"><code>referrer:</code> <?php echo esc_html( (string) $en['referrer'] ); ?></p><?php endif; ?>
							<?php $click = json_decode( (string) ( $en['click_ids_json'] ?? '{}' ), true ); if ( is_array( $click ) && $click ) : foreach ( $click as $ck => $cv ) : ?><p style="margin:2px 0;"><code><?php echo esc_html( (string) $ck ); ?>:</code> <?php echo esc_html( (string) $cv ); ?></p><?php endforeach; endif; ?>
							<?php if ( (string) $en['repeat_note'] !== '' ) : ?><p style="margin:2px 0;"><code>nota:</code> <?php echo esc_html( (string) $en['repeat_note'] ); ?></p><?php endif; ?>
						</div>
					</details>
					<?php if ( (string) $en['status'] === 'trash' ) : ?>
						<button type="button" class="button-link" data-maq-sub="restore" data-id="<?php echo (int) $en['id']; ?>">Restaurar</button> |
						<button type="button" class="button-link" style="color:#b32d2e;" data-maq-sub="purge" data-id="<?php echo (int) $en['id']; ?>" data-confirm="¿Eliminar definitivamente esta entrada?">Eliminar</button>
					<?php else : ?>
						<button type="button" class="button-link" style="color:#b32d2e;" data-maq-sub="trash" data-id="<?php echo (int) $en['id']; ?>">Papelera</button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?><p class="description"><?php echo $ef_status === 'trash' ? 'La papelera está vacía.' : ( 'Este ' . esc_html( $fp_singular ) . ' aún no tiene envíos. Cuando alguien lo complete, aparecerán aquí.' ); ?></p><?php endif; ?>
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
	var PROJECTS = <?php echo wp_json_encode( $projects ); ?>;
	var $ = function (id) { return document.getElementById(id); };
	var editor = $('es-maq-editor');
	var previewEl = $('es-maq-preview');
	var cfgInput = $('es-config-' + fid);
	var optionsTypes = { select: 1, radio: 1, checkbox: 1, cards: 1, emoji: 1, routing: 1 };
	var WIDTH_PRESETS = [100, 75, 66.6, 50, 33.3, 25];
	function widthPreset(w) {
		w = parseFloat(w);
		if (!w || isNaN(w)) { return '100'; }
		var best = '100', bd = 1e9;
		WIDTH_PRESETS.forEach(function (p) { var d = Math.abs(p - w); if (d < bd) { bd = d; best = String(p); } });
		return best;
	}
	// Condicional "Mostrar si" (GRAVITY_UX_V1): guarda show_when en el campo.
	//   campo=''      → sin condición (siempre visible)
	//   mode='any'    → { field, value:'*' }  campo completado de cualquier forma
	//   mode='eq'     → { field, value }      campo igual a una opción concreta
	function readShowWhen(el) {
		var fieldEl = el.querySelector('.maq-show-field');
		if (!fieldEl) { return null; }
		var field = (fieldEl.value || '').trim();
		if (!field) { return null; }
		var modeEl = el.querySelector('.maq-show-mode');
		var mode = modeEl ? modeEl.value : 'eq';
		if (mode === 'any') { return { field: slugify(field), value: '*' }; }
		var valEl = el.querySelector('.maq-show-value');
		var value = (valEl && (valEl.value || '').trim()) || '';
		return value !== '' ? { field: slugify(field), value: value } : null;
	}
	function updateShowWhen(si, fi) {
		if (!SECTIONS[si] || !SECTIONS[si].fields[fi]) { return; }
		var f = SECTIONS[si].fields[fi];
		var el = editor.querySelector('.maq-field[data-si="' + si + '"][data-fi="' + fi + '"]');
		if (!el) { return; }
		var sw = readShowWhen(el);
		if (sw) { f.show_when = sw; } else { delete f.show_when; }
	}
	// Índice de campos del form (nombre + objeto) para el selector "Mostrar si".
	function formFieldsIndex(excludeName) {
		var idx = [];
		SECTIONS.forEach(function (sec) {
			(sec.fields || []).forEach(function (of) {
				if (isSystemField(of)) { return; }
				var n = of.name || slugify(of.label || '');
				if (n && n !== excludeName) { idx.push({ name: n, field: of }); }
			});
		});
		return idx;
	}
	// Control del valor según el campo seleccionado (opciones o texto libre).
	function showWhenValueControl(field, value) {
		if (!field) { return '<input class="maq-show-value regular-text" placeholder="valor" value="" style="width:40%;">'; }
		var opts = field.options || [];
		if (opts.length) {
			var h = '<select class="maq-show-value" style="width:40%;"><option value="">—</option>';
			opts.forEach(function (o) {
				var ov = o.value || slugify(optLabel(o));
				h += '<option value="' + esc(ov) + '"' + (String(ov) === String(value) ? ' selected' : '') + '>' + esc(optLabel(o)) + '</option>';
			});
			return h + '</select>';
		}
		return '<input class="maq-show-value regular-text" placeholder="valor" value="' + esc(value) + '" style="width:40%;">';
	}
	// Editor "Mostrar si" con desplegables (Gravity Forms UX): campo del form +
	// modo (Se completó / Es igual a) + opción del campo seleccionado.
	function showWhenEditorHTML(f) {
		var sw = f.show_when || {};
		var savedField = ((sw.field || '')).trim();
		var savedValue = ((sw.value || '')).trim();
		var mode = savedValue === '*' ? 'any' : 'eq';
		var idx = formFieldsIndex(f.name);
		var fieldOpts = '<option value="">— Siempre visible —</option>';
		var found = false, savedFieldObj = null;
		idx.forEach(function (o) { if (o.name === savedField) { found = true; savedFieldObj = o.field; } });
		if (savedField && !found) { idx.unshift({ name: savedField, field: null }); }
		idx.forEach(function (o) {
			fieldOpts += '<option value="' + esc(o.name) + '"' + (o.name === savedField ? ' selected' : '') + '>' + esc((o.field && o.field.label) || o.name) + '</option>';
		});
		var valueHTML = showWhenValueControl(savedFieldObj, savedField ? savedValue : '');
		return '<span class="maq-show-wrap">'
			+ '<select class="maq-show-field" style="width:44%;">' + fieldOpts + '</select> '
			+ '<select class="maq-show-mode" style="width:15%;">'
			+ '<option value="any"' + (mode === 'any' ? ' selected' : '') + '>Se completó</option>'
			+ '<option value="eq"' + (mode === 'eq' ? ' selected' : '') + '>Es igual a</option>'
			+ '</select> '
			+ '<span class="maq-show-value-wrap" style="display:' + (mode === 'eq' ? '' : 'none') + ';">' + valueHTML + '</span>'
			+ '<br><span class="description">Este campo se muestra si el campo de la izquierda <b>se completó</b> (cualquier valor) o <b>respondió una opción</b> — el desplegable se nutre con los campos del form. Vacío = siempre visible (lógica Gravity Forms).</span>'
			+ '</span>';
	}
	// Binding del editor: repuebla el selector de opciones al cambiar campo/modo.
	function bindShowWhen(el, si, fi) {
		var fieldSel = el.querySelector('.maq-show-field');
		var modeSel = el.querySelector('.maq-show-mode');
		var wrap = el.querySelector('.maq-show-value-wrap');
		if (!fieldSel) { return; }
		function bindValue() {
			var v = wrap ? wrap.querySelector('.maq-show-value') : null;
			if (!v) { return; }
			v.addEventListener('input', function () { updateShowWhen(si, fi); renderPreview(); });
			if (v.tagName === 'SELECT') { v.addEventListener('change', function () { updateShowWhen(si, fi); renderPreview(); }); }
		}
		function refresh() {
			var fname = (fieldSel.value || '').trim();
			var mode = modeSel ? modeSel.value : 'eq';
			var f = null;
			if (fname) {
				SECTIONS.forEach(function (sec) {
					if (f) { return; }
					(sec.fields || []).forEach(function (of) {
						if (f) { return; }
						var n = of.name || slugify(of.label || '');
						if (n === fname) { f = of; }
					});
				});
			}
			if (wrap) {
				wrap.style.display = mode === 'eq' ? '' : 'none';
				if (mode === 'eq') {
					wrap.innerHTML = showWhenValueControl(f, '');
					bindValue();
				}
			}
			updateShowWhen(si, fi);
			renderPreview();
		}
		bindValue();
		fieldSel.addEventListener('change', refresh);
		if (modeSel) { modeSel.addEventListener('change', refresh); }
	}
	function widthOptionsHTML(w) {
		return WIDTH_PRESETS.map(function (p) {
			var label = p === 100 ? '100% — línea completa' : (p === 75 ? '75% — 3/4' : (p === 66.6 ? '66,6% — 2/3' : (p === 50 ? '50% — mitad' : (p === 33.3 ? '33,3% — tercio' : '25% — cuarto'))));
			return '<option value="' + p + '"' + (String(widthPreset(w)) === String(p) ? ' selected' : '') + '>' + label + '</option>';
		}).join('');
	}
	var openField = null;
	var dragField = null;
	var dragSection = null;

	function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	function slugify(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,''); }
	function optLabel(o) { return typeof o === 'object' && o !== null ? (o.label || '') : (o || ''); }

	// SYSFIELD_HIDE_V1: campos de sistema (type hidden o name con prefijo "_",
	// p. ej. `_routing_emails`) NO se listan en el editor ni en la vista previa,
	// pero se conservan en SECTIONS y se serializan al guardar (funcionales).
	function isSystemField(f) {
		return !!f && (f.type === 'hidden' || (typeof f.name === 'string' && f.name.charAt(0) === '_'));
	}

	function typeLabel(t) { return TYPES[t] || t; }
	// HTML_BLOCK_UI_V1: los campos html/heading no tienen etiqueta (su contenido
	// va en `html`). Antes se mostraban como "(sin etiqueta)" y no se veía ni se
	// podía editar el contenido → parecían vacíos y se podían borrar por error.
	function isHtmlType(t) { return t === 'html' || t === 'heading'; }
	function stripTags(s) { return String(s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); }
	function fieldDisplayLabel(f) {
		if (f && String(f.label || '').trim()) { return f.label; }
		if (f && isHtmlType(f.type)) {
			var t = stripTags(f.html || f.content || '');
			return t ? ('HTML: ' + t.slice(0, 48)) : '(bloque HTML vacío)';
		}
		return '(sin etiqueta)';
	}
	function bindHtmlContent(el, si, fi) {
		var ta = el.querySelector('.maq-html');
		if (!ta) { return; }
		ta.addEventListener('input', function () {
			SECTIONS[si].fields[fi].html = this.value;
			var b = el.querySelector('.maq-badge-label');
			if (b) { b.textContent = fieldDisplayLabel(SECTIONS[si].fields[fi]); }
			renderPreview();
		});
	}

	// Normaliza tipos duplicados/legacy (phone → tel: mismo render y validación).
	SECTIONS.forEach(function (sec) { (sec.fields || []).forEach(function (f) { if (f.type === 'phone') { f.type = 'tel'; } }); });

	function fieldEditorHTML(si, fi, f) {
		var typeOptions = Object.keys(TYPES).map(function (t) { return '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + TYPES[t] + '</option>'; }).join('');
		var opts = '';
		if (f.type === 'routing') {
			opts = routingOptionsHTML(f);
		} else if (optionsTypes[f.type]) {
			opts = '<tr class="maq-options-row"><th>Opciones</th><td><label class="description">Una por línea</label><textarea class="maq-options" rows="2" style="width:100%;">' + esc((f.options || []).map(optLabel).join('\n')) + '</textarea></td></tr>';
		}
		var htmlRow = isHtmlType(f.type)
			? '<tr class="maq-html-row"><th>Contenido (HTML)</th><td><textarea class="maq-html" rows="4" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;">' + esc(f.html || f.content || '') + '</textarea><span class="description">Texto/HTML del bloque informativo (se muestra tal cual en el formulario; no es un campo de captura).</span></td></tr>'
			: '';
		return '<div class="maq-field" draggable="true" data-si="' + si + '" data-fi="' + fi + '" style="border:1px solid #c3c4c7;border-radius:6px;padding:8px 10px;margin-bottom:8px;background:#fff;">'
			+ '<div style="display:flex;align-items:center;gap:8px;">'
			+ '<span style="color:#8c8f94;cursor:grab;" title="Arrastrar para reordenar">⋮⋮</span>'
			+ '<strong class="maq-badge-label" style="flex:1;">' + esc(fieldDisplayLabel(f)) + '</strong>'
			+ '<span class="description maq-type-badge">' + esc(typeLabel(f.type)) + '</span>'
			+ '<button type="button" class="button maq-up" title="Subir">↑</button>'
			+ '<button type="button" class="button maq-down" title="Bajar">↓</button>'
			+ '<button type="button" class="button maq-dupe" title="Duplicar campo (clon)">⧉</button>'
			+ '<button type="button" class="button-link-delete maq-del" title="Quitar campo">✕</button>'
			+ '<button type="button" class="button maq-edit">Editar</button>'
			+ '</div>'
			+ '<div class="maq-editbox" style="' + (openField === si + ':' + fi ? '' : 'display:none;') + 'margin-top:8px;">'
			+ '<table class="form-table"><tr><th>Etiqueta</th><td><input class="maq-label regular-text" value="' + esc(f.label) + '" placeholder="Ej: Nombre completo"></td></tr>'
			+ '<tr><th>Tipo</th><td><select class="maq-type">' + typeOptions + '</select></td></tr>'
			+ '<tr><th>Obligatorio</th><td><input class="maq-required" type="checkbox"' + (f.required ? ' checked' : '') + '> Sí</td></tr>'
			+ '<tr><th>Ancho</th><td><select class="maq-width" style="width:100%;">' + widthOptionsHTML(f.column_width) + '</select><span class="description">100% = línea completa · 50% = mitad · 33,3% = tercio. En celular todo pasa a 100%.</span></td></tr>'
			+ '<tr><th>Mostrar si</th><td>' + showWhenEditorHTML(f) + '</td></tr>'
			+ htmlRow
			+ opts
			+ '</table>'
			+ '</div>'
			+ '</div>';
	}

	/** Editor de opciones de un campo routing (ROUTING_FIELD_V1): por opción →
	 * label · proyecto · destino (mail/url/ambos) · correos · URL · pipeline. */
	function routingOptionsHTML(f) {
		var rows = (f.options || []).map(function (o, i) { return routingRowHTML(o); }).join('');
		return '<tr class="maq-options-row"><th>Opciones de routing</th><td>'
			+ '<div class="maq-routing-options" style="width:100%;">' + rows + '</div>'
			+ '<button type="button" class="button maq-ro-add" style="margin-top:4px;">+ Agregar opción</button>'
			+ '<span class="description" style="display:block;margin-top:4px;">Cada opción define a quién llega: <b>proyecto</b> (operadores del proyecto) + <b>destino</b> (mail, url o ambos). El correo deriva la notificación a un mail; la <b>url</b> redirige al visitante tras enviar (no excluyente con el mail). Si <b>entra al pipeline</b>, el lead va al embudo del proyecto (correo enriquecido con calificación); si no, solo aviso simple.</span>'
			+ '</td></tr>';
	}

	/** Destino de una opción (UI): infiere mail/url/ambos desde su contenido. */
	function routingDestino(o) {
		var hasMail = !!((o && o.emails) || '').trim();
		var hasUrl = !!((o && o.url) || '').trim();
		if (hasMail && hasUrl) { return 'ambos'; }
		if (hasUrl) { return 'url'; }
		return 'mail';
	}

	function routingRowHTML(o) {
		o = o || {};
		var destino = routingDestino(o);
		var projectOpts = '<option value="">Sin proyecto</option>';
		Object.keys(PROJECTS).forEach(function (p) {
			projectOpts += '<option value="' + esc(p) + '"' + (String(o.project) === p ? ' selected' : '') + '>' + esc(PROJECTS[p]) + '</option>';
		});
		var showMail = destino === 'url' ? ' style="display:none;"' : '';
		var showUrl = destino === 'mail' ? ' style="display:none;"' : '';
		return '<div class="maq-routing-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
			+ '<input class="maq-ro-label regular-text" placeholder="Opción (ej: Inmobiliaria)" value="' + esc(optLabel(o)) + '" style="width:150px;">'
			+ '<select class="maq-ro-project" style="width:150px;">' + projectOpts + '</select>'
			+ '<select class="maq-ro-destino" style="width:115px;" title="Destino de esta opción: mail, url o ambos">'
			+ '<option value="mail"' + (destino === 'mail' ? ' selected' : '') + '>Mail</option>'
			+ '<option value="url"' + (destino === 'url' ? ' selected' : '') + '>URL</option>'
			+ '<option value="ambos"' + (destino === 'ambos' ? ' selected' : '') + '>Mail + URL</option>'
			+ '</select>'
			+ '<input class="maq-ro-emails regular-text" placeholder="Correos (coma)" value="' + esc(o.emails || '') + '" style="width:190px;"' + showMail + '>'
			+ '<input class="maq-ro-url regular-text" placeholder="URL de redirección" value="' + esc(o.url || '') + '" style="width:190px;"' + showUrl + '>'
			+ '<label title="¿El lead entra al pipeline de leads del proyecto?"><input type="checkbox" class="maq-ro-pipeline"' + (o.pipeline !== false ? ' checked' : '') + '> <span class="description">pipeline</span></label>'
			+ '<button type="button" class="button-link-delete maq-ro-del" title="Quitar opción">✕</button>'
			+ '</div>';
	}

	function routingRowFromDOM(row) {
		var label = row.querySelector('.maq-ro-label').value.trim();
		var project = row.querySelector('.maq-ro-project').value;
		var emails = row.querySelector('.maq-ro-emails').value.trim();
		var url = row.querySelector('.maq-ro-url').value.trim();
		var pipeline = row.querySelector('.maq-ro-pipeline').checked;
		var destino = row.querySelector('.maq-ro-destino').value;
		return label !== '' ? { value: slugify(label), label: label, project: project, emails: emails, url: url, destino: destino, pipeline: pipeline } : null;
	}

	function render() {
		editor.innerHTML = SECTIONS.map(function (sec, si) {
			var fieldsHtml = '';
			(sec.fields || []).forEach(function (f, fi) {
				// SYSFIELD_HIDE_V1: oculta campos de sistema (mantiene el index
				// original para que el sync del submit apunte bien a SECTIONS).
				if (isSystemField(f)) { return; }
				fieldsHtml += fieldEditorHTML(si, fi, f);
			});
			return '<div class="maq-section" data-si="' + si + '" style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;margin-bottom:14px;background:#f6f7f7;">'
				+ '<div style="display:flex;align-items:center;gap:8px;">'
				+ '<span class="maq-sec-drag" draggable="true" style="color:#8c8f94;cursor:grab;" title="Arrastrar para reordenar la sección">⋮⋮</span>'
				+ '<strong style="flex:1;">Sección</strong>'
				+ '<input class="maq-section-title regular-text" value="' + esc(sec.title || '') + '" placeholder="Título de la sección (opcional)">'
				+ '<button type="button" class="button maq-sec-del" title="Quitar sección">✕</button>'
				+ '</div>'
				+ '<div class="maq-fields" style="margin-top:8px;">' + fieldsHtml + '</div>'
				+ '<button type="button" class="button maq-add-field" style="margin-top:4px;">+ Agregar campo</button>'
				+ '</div>';
		}).join('');
		bind();
	}

	function moveField(si, fi, d) {
		var j = fi + d;
		if (j < 0 || j >= SECTIONS[si].fields.length) { return; }
		var t = SECTIONS[si].fields[fi]; SECTIONS[si].fields[fi] = SECTIONS[si].fields[j]; SECTIONS[si].fields[j] = t;
		openField = null; render();
	}

	// MAQ_DUPE_FIELD_V1: clona un campo (copia profunda del objeto) e inserta la
	// copia justo después del original. El name se hace único con sufijo _N
	// (no puede haber dos campos con el mismo name: el capture los pisaría).
	function uniqueFieldName(si, base) {
		var taken = {};
		(SECTIONS[si].fields || []).forEach(function (f) { taken[slugify(f.name || '')] = true; });
		base = slugify(base || '') || 'campo';
		if (!taken[base]) { return base; }
		var n = 2;
		while (taken[base + '_' + n]) { n++; }
		return base + '_' + n;
	}
	function dupeField(si, fi) {
		var src = SECTIONS[si].fields[fi];
		if (!src) { return; }
		var copy = JSON.parse(JSON.stringify(src));
		copy.name = uniqueFieldName(si, src.name || src.label || 'campo');
		// El clon de un campo dependiente NO conserva depends_on hacia sí mismo
		// (apuntaría a un campo inexistente o a sí mismo); si el original depende
		// de un campo, el clon depende del mismo origen (comportamiento esperado).
		SECTIONS[si].fields.splice(fi + 1, 0, copy);
		openField = si + ':' + (fi + 1);
		render();
	}

	function bind() {
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-field'), function (el) {
			var si = parseInt(el.getAttribute('data-si'), 10), fi = parseInt(el.getAttribute('data-fi'), 10);
			el.querySelector('.maq-edit').addEventListener('click', function () {
				var k = si + ':' + fi;
				openField = openField === k ? null : k;
				render();
			});
			// Click en el campo (no en botones ni dentro del editbox) → abre/cierra edición.
			el.addEventListener('click', function (e) {
				if (e.target.closest('.maq-edit, .maq-up, .maq-down, .maq-dupe, .maq-del, .maq-editbox')) { return; }
				var k = si + ':' + fi;
				openField = openField === k ? null : k;
				render();
			});
			el.querySelector('.maq-up').addEventListener('click', function () { moveField(si, fi, -1); });
			el.querySelector('.maq-down').addEventListener('click', function () { moveField(si, fi, 1); });
			el.querySelector('.maq-del').addEventListener('click', function () {
				var f = SECTIONS[si].fields[fi];
				// HTML_BLOCK_UI_V1: avisar si el bloque html/heading tiene contenido
				// (antes se borraba a ciegas porque no se veía su contenido).
				if (f && isHtmlType(f.type)) {
					var txt = stripTags(f.html || f.content || '');
					if (txt && !window.confirm('Este bloque HTML tiene contenido:\n\n«' + txt.slice(0, 140) + (txt.length > 140 ? '…' : '') + '»\n\n¿Quitar el bloque de todos modos?')) { return; }
				}
				SECTIONS[si].fields.splice(fi, 1); openField = null; render();
			});
			var dupeBtn = el.querySelector('.maq-dupe');
			if (dupeBtn) { dupeBtn.addEventListener('click', function () { dupeField(si, fi); }); }
			// Edición en vivo: cualquier cambio actualiza SECTIONS y la vista previa, sin recargar.
			var labelEl = el.querySelector('.maq-label');
			if (labelEl) { labelEl.addEventListener('input', function () { SECTIONS[si].fields[fi].label = this.value; el.querySelector('.maq-badge-label').textContent = fieldDisplayLabel(SECTIONS[si].fields[fi]); renderPreview(); }); }
			var typeSel = el.querySelector('.maq-type');
			if (typeSel) {
				typeSel.addEventListener('change', function () {
					var f = SECTIONS[si].fields[fi];
					f.type = this.value;
					el.querySelector('.maq-type-badge').textContent = typeLabel(f.type);
					var row = el.querySelector('.maq-options-row');
					if (optionsTypes[f.type]) {
						if (!row) {
							row = document.createElement('tr'); row.className = 'maq-options-row';
							row.innerHTML = f.type === 'routing'
								? '<th>Opciones de routing</th><td><div class="maq-routing-options"></div>'
									+ '<button type="button" class="button maq-ro-add" style="margin-top:4px;">+ Agregar opción</button>'
									+ '<span class="description" style="display:block;margin-top:4px;">Cada opción define a quién llega: <b>proyecto</b> (operadores del proyecto) + <b>destino</b> (mail, url o ambos). La <b>url</b> redirige al visitante tras enviar; el <b>pipeline</b> = el lead entra al embudo del proyecto (correo enriquecido con calificación).</span></td>'
								: '<th>Opciones</th><td><label class="description">Una por línea</label><textarea class="maq-options" rows="2" style="width:100%;"></textarea></td>';
							el.querySelector('.maq-editbox table').appendChild(row);
							if (f.type === 'routing') { bindRoutingOptions(el, row.querySelector('.maq-routing-options')); }
							else { row.querySelector('.maq-options').addEventListener('input', function () { SECTIONS[si].fields[fi].options = parseOptions(this.value); renderPreview(); }); }
						}
					} else if (row) { row.parentNode.removeChild(row); }
					var hrow = el.querySelector('.maq-html-row');
					if (isHtmlType(f.type)) {
						if (!hrow) {
							hrow = document.createElement('tr'); hrow.className = 'maq-html-row';
							hrow.innerHTML = '<th>Contenido (HTML)</th><td><textarea class="maq-html" rows="4" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;"></textarea><span class="description">Texto/HTML del bloque informativo (se muestra tal cual en el formulario; no es un campo de captura).</span></td>';
							el.querySelector('.maq-editbox table').appendChild(hrow);
							bindHtmlContent(el, si, fi);
						}
					} else if (hrow) { hrow.parentNode.removeChild(hrow); }
					el.querySelector('.maq-badge-label').textContent = fieldDisplayLabel(f);
					renderPreview();
				});
			}
			var optEl = el.querySelector('.maq-options');
			if (optEl) { optEl.addEventListener('input', function () { SECTIONS[si].fields[fi].options = parseOptions(this.value); renderPreview(); }); }
			var wSel = el.querySelector('.maq-width');
			if (wSel) { wSel.addEventListener('change', function () { SECTIONS[si].fields[fi].column_width = this.value; renderPreview(); }); }
			if (el.querySelector('.maq-html')) { bindHtmlContent(el, si, fi); }
			var swField = el.querySelector('.maq-show-field');
			if (swField) { bindShowWhen(el, si, fi); }
			var rwrap = el.querySelector('.maq-routing-options');
			if (rwrap) { bindRoutingOptions(el, rwrap); }
			var reqEl = el.querySelector('.maq-required');
			if (reqEl) { reqEl.addEventListener('change', function () { SECTIONS[si].fields[fi].required = this.checked; renderPreview(); }); }
			// Drag & drop de campos (reordenar dentro y entre secciones).
			el.addEventListener('dragstart', function (e) {
				if (e.target.closest && e.target.closest('.maq-dupe, .maq-del, .maq-up, .maq-down, .maq-edit, .maq-editbox')) { e.preventDefault(); return; }
				dragField = { si: si, fi: fi }; e.dataTransfer.effectAllowed = 'move'; el.style.opacity = '.45';
			});
			el.addEventListener('dragend', function () { dragField = null; el.style.opacity = ''; });
			el.addEventListener('dragover', function (e) { if (dragField) { e.preventDefault(); } });
			el.addEventListener('drop', function (e) {
				e.preventDefault();
				if (!dragField) { return; }
				var dsi = dragField.si, dfi = dragField.fi;
				var tsi = parseInt(el.getAttribute('data-si'), 10), tfi = parseInt(el.getAttribute('data-fi'), 10);
				var moved = SECTIONS[dsi].fields.splice(dfi, 1)[0];
				var list = SECTIONS[tsi].fields;
				var at = (dsi === tsi && dfi < tfi) ? tfi : Math.min(tfi, list.length);
				list.splice(at, 0, moved);
				openField = null; render();
			});
		});
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-add-field'), function (btn) {
			btn.addEventListener('click', function () {
				var si = parseInt(btn.closest('.maq-section').getAttribute('data-si'), 10);
				SECTIONS[si].fields.push({ name: '', label: '', type: 'text', required: false, options: [], column_width: '100' });
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
		// Soltar un campo en el área vacía de una sección → agregar al final.
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-section'), function (sec) {
			sec.addEventListener('dragover', function (e) { if (dragField || dragSection !== null) { e.preventDefault(); } });
			sec.addEventListener('drop', function (e) {
				e.preventDefault();
				var si = parseInt(sec.getAttribute('data-si'), 10);
				if (dragSection !== null) {
					if (dragSection === si) { return; }
					var s = SECTIONS.splice(dragSection, 1)[0];
					var at = dragSection < si ? si : Math.min(si, SECTIONS.length);
					SECTIONS.splice(at, 0, s);
					openField = null; render();
					return;
				}
				if (!dragField) { return; }
				if (e.target.closest && e.target.closest('.maq-field')) { return; } // lo maneja el campo
				var moved = SECTIONS[dragField.si].fields.splice(dragField.fi, 1)[0];
				SECTIONS[si].fields.push(moved);
				openField = null; render();
			});
		});
	}

	function parseOptions(text) {
		return (text || '').split('\n').map(function (s) { s = s.trim(); return s ? { value: slugify(s), label: s } : null; }).filter(Boolean);
	}

	/** Binding del editor de opciones de routing (ROUTING_FIELD_V1). */
	function bindRoutingOptions(el, wrap) {
		if (!wrap) { return; }
		var si = parseInt(el.getAttribute('data-si'), 10), fi = parseInt(el.getAttribute('data-fi'), 10);
		var row = wrap.closest('tr');
		var addBtn = row ? row.querySelector('.maq-ro-add') : null;
		function sync() {
			var f = SECTIONS[si].fields[fi];
			f.options = Array.prototype.map.call(wrap.querySelectorAll('.maq-routing-row'), routingRowFromDOM).filter(Boolean);
			renderPreview();
		}
		// ROUTING_URL_V1: el desplegable de destino muestra/oculta correos y URL.
		function applyDestino(roRow) {
			var sel = roRow.querySelector('.maq-ro-destino');
			if (!sel) { return; }
			var d = sel.value, mail = roRow.querySelector('.maq-ro-emails'), url = roRow.querySelector('.maq-ro-url');
			if (mail) { mail.style.display = (d === 'url') ? 'none' : ''; }
			if (url) { url.style.display = (d === 'mail') ? 'none' : ''; }
		}
		function bindRow(roRow) {
			var del = roRow.querySelector('.maq-ro-del');
			if (del) { del.addEventListener('click', function () { roRow.parentNode.removeChild(roRow); sync(); }); }
			var dst = roRow.querySelector('.maq-ro-destino');
			if (dst) { dst.addEventListener('change', function () { applyDestino(roRow); sync(); }); }
		}
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				wrap.insertAdjacentHTML('beforeend', routingRowHTML({ value: '', label: '', project: '', emails: '', url: '', destino: 'mail', pipeline: true }));
				bindRow(wrap.lastElementChild);
				sync();
			});
		}
		Array.prototype.forEach.call(wrap.querySelectorAll('.maq-routing-row'), bindRow);
		if (!wrap.getAttribute('data-ro-sync')) {
			wrap.setAttribute('data-ro-sync', '1');
			wrap.addEventListener('input', sync);
			wrap.addEventListener('change', sync);
		}
	}

	function moveSection(si, d) {
		var j = si + d;
		if (j < 0 || j >= SECTIONS.length) { return; }
		var t = SECTIONS[si]; SECTIONS[si] = SECTIONS[j]; SECTIONS[j] = t;
		openField = null; render();
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
	function fieldWidthPct(f) {
		var cw = parseFloat(f && f.column_width);
		if (!cw || isNaN(cw)) { return 100; } // DEFAULT_ANCHO_V1: default 100%
		if (cw >= 1 && cw <= 4) { return Math.round(100 * (cw / 4)); }
		if (cw <= 0 || cw > 100) { return 100; }
		return cw;
	}
	function renderPreview() {
		var html = SECTIONS.map(function (sec) {
			var h = '';
			if (sec.title) { h += '<div style="width:100%;float:left;padding:0 4px;"><h4>' + esc(sec.title) + '</h4></div>'; }
			(sec.fields || []).forEach(function (f) {
				// SYSFIELD_HIDE_V1: no mostrar campos de sistema en la vista previa.
				if (isSystemField(f)) { return; }
				// HTML_BLOCK_UI_V1: los bloques html/heading se muestran con su contenido.
				if (isHtmlType(f.type || '')) {
					var raw = String(f.html || f.content || '');
					h += '<div style="box-sizing:border-box;width:' + fieldWidthPct(f) + '%;float:left;padding:0 4px;margin-bottom:8px;">' + (raw || '<em class="description">(bloque ' + esc(typeLabel(f.type)) + ' sin contenido)</em>') + '</div>';
					return;
				}
				var t = f.type || 'text';
				var inner = '<label>' + esc(f.label || '(sin etiqueta)') + (f.required ? ' <span style="color:#d63638;">*</span>' : '') + '</label><br>';
				if (t === 'textarea') { inner += '<textarea rows="2" style="width:100%;"></textarea>'; }
				else if (t === 'select' || t === 'routing') { inner += '<select style="width:100%;"><option value="">Selecciona…</option>' + (f.options || []).map(function (o) { return '<option>' + esc(optLabel(o)) + '</option>'; }).join('') + '</select>'; }
				else if (t === 'radio' || t === 'checkbox' || t === 'cards') { inner += optInputs(f); }
				else if (t === 'emoji') { inner += (f.options || []).map(function (o) { return '<span style="font-size:20px;margin-right:6px;cursor:pointer;">' + esc(optLabel(o)) + '</span>'; }).join(''); }
				else if (t === 'rating') { inner += '<span style="letter-spacing:3px;font-size:18px;">★ ★ ★ ★ ★</span>'; }
				else if (t === 'matrix') { inner += '<em class="description">(matriz / tabla de respuestas)</em>'; }
				else if (t === 'file') { inner += '<input type="file" disabled>'; }
				else if (t === 'nps') { inner += '<span style="letter-spacing:4px;">0 1 2 3 4 5 6 7 8 9 10</span>'; }
				else {
					var itype = t === 'email' ? 'email' : (t === 'number' ? 'number' : (t === 'date' ? 'date' : (t === 'tel' || t === 'phone' ? 'tel' : (t === 'rut' ? 'text' : 'text'))));
					var ph = t === 'rut' ? ' placeholder="12.345.678-9"' : '';
					inner += '<input type="' + itype + '"' + ph + ' style="width:100%;">';
				}
				// COLUMN_WIDTH_V2: el preview refleja el ancho real del campo.
				h += '<div style="box-sizing:border-box;width:' + fieldWidthPct(f) + '%;float:left;padding:0 4px;margin-bottom:8px;">' + inner + '</div>';
			});
			return h;
		}).join('');
		previewEl.innerHTML = '<div style="border:1px dashed #2271b1;border-radius:8px;padding:12px;background:#fff;overflow:hidden;">' + html + '</div>';
	}

	function collect() {
		return SECTIONS.map(function (sec) { return { title: sec.title, fields: sec.fields }; });
	}

	cfgInput.form.addEventListener('submit', function (e) {
		// Sync section titles from DOM (los campos ya están sincronizados en vivo).
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-section'), function (el, si) {
			if (SECTIONS[si]) { SECTIONS[si].title = el.querySelector('.maq-section-title').value; }
		});
		// Sync fields from DOM (refuerzo: valores actuales de los inputs).
		Array.prototype.forEach.call(editor.querySelectorAll('.maq-field'), function (el) {
			var si = parseInt(el.getAttribute('data-si'), 10), fi = parseInt(el.getAttribute('data-fi'), 10);
			if (!SECTIONS[si] || !SECTIONS[si].fields[fi]) { return; }
			var f = SECTIONS[si].fields[fi];
			var labelEl = el.querySelector('.maq-label');
			if (labelEl) { f.label = labelEl.value; }
			var typeSel = el.querySelector('.maq-type');
			// MAQ_TYPE_PRESERVE_V1: si el campo tiene un tipo que el editor no
			// lista (TYPES) NO se sobrescribe (el select caería al primer valor,
			// p. ej. 'text', y corrompería el campo: dependent_select → text al
			// guardar cambios de ancho/obligatorio). El tipo real se conserva.
			if (typeSel && typeSel.querySelector('option[value="' + (f.type || '') + '"]')) {
				f.type = typeSel.value;
			}
			var reqEl = el.querySelector('.maq-required');
			if (reqEl) { f.required = reqEl.checked; }
			var rw = el.querySelector('.maq-routing-options');
			if (rw) { f.options = Array.prototype.map.call(rw.querySelectorAll('.maq-routing-row'), routingRowFromDOM).filter(Boolean); }
			else { var ta = el.querySelector('.maq-options'); f.options = ta ? parseOptions(ta.value) : []; }
			var wSel = el.querySelector('.maq-width');
			if (wSel) { f.column_width = wSel.value; }
			var htmlEl = el.querySelector('.maq-html');
			if (htmlEl) { f.html = htmlEl.value; }
			var sw = readShowWhen(el);
			if (sw) { f.show_when = sw; } else { delete f.show_when; }
			// NAME_STABLE_V1: el name solo se auto-genera para campos NUEVOS o sin
			// name explícito. NO se re-deriva en cada guardado (rompía campos de
			// sistema con name estable, p. ej. el oculto `_routing_emails`, y
			// desconfiguraba el form al modificar cualquier cosa).
			// LABEL_NAME_FALLBACK_V1: si no hay label, se genera un name
			// determinístico por índice (campo_N) para que el campo SIEMPRE
			// renderice en producción, aunque el backend además deriva la etiqueta.
			if ( ! f.name || f.name === '' ) {
				f.name = slugify( f.label ) || ( 'campo_' + ( fi + 1 ) );
			}
		});
		// ETIQUETA_OBLIGATORIA_V1: fuerza al creador a etiquetar los campos de
		// captura (heading/html/hidden quedan exentos). Sin etiqueta el campo no
		// se ve en producción; se bloquea el guardado señalando el campo.
		var missing = [];
		Array.prototype.forEach.call(SECTIONS, function (sec, si) {
			(sec.fields || []).forEach(function (f, fi) {
				var t = f.type || 'text';
				if (t === 'heading' || t === 'html' || t === 'hidden' || isSystemField(f)) { return; }
				if (!(f.label || '').trim()) { missing.push({ si: si, fi: fi }); }
			});
		});
		if (missing.length) {
			e.preventDefault();
			window.alert('Falta la etiqueta en ' + missing.length + (missing.length === 1 ? ' campo' : ' campos')
				+ '. Agrega una etiqueta antes de guardar para que el campo se muestre en el formulario.');
			var m = missing[0];
			openField = m.si + ':' + m.fi;
			render();
			var mEl = editor.querySelector('.maq-field[data-si="' + m.si + '"][data-fi="' + m.fi + '"]');
			if (mEl) {
				var mlab = mEl.querySelector('.maq-label');
				if (mlab) { mlab.focus(); mlab.style.borderColor = '#d63638'; }
			}
			return;
		}
		var cfg = {};
		try { cfg = JSON.parse(cfgInput.value || '{}') || {}; } catch (e) { cfg = {}; }
		cfg.sections = collect();
		// Routing por opción: componer notify_route_map_text desde las filas UI.
		var routeMapInput = document.getElementById('es-route-map-text');
		if (routeMapInput) {
			// MAQ_ROUTE_PRESERVE_V1: el bloque de filas .es-route-map SOLO existe
			// en la sub-vista correos→notify. Si se guarda desde otra sub-vista
			// (p. ej. diseño/campos), las filas no están en el DOM y recomponer
			// las pisotearía con vacío. Solo se recompone si hay filas visibles.
			var rows = document.querySelectorAll('.es-route-map');
			if (rows.length) {
				var lines = [];
				Array.prototype.forEach.call(rows, function (r) {
					var v = (r.value || '').trim();
					if (v !== '') { lines.push(r.getAttribute('data-opt') + '=' + v); }
				});
				routeMapInput.value = lines.join('\n');
			}
		}
		// Serialización del config (los cuerpos de correo son textareas planos;
		// triggerSave queda como no-op si no hay editores TinyMCE).
		if ( window.tinymce ) { try { window.tinymce.triggerSave(); } catch ( e ) {} }
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
	// Búsqueda de entradas sin `<form>` anidado (rompía el form del editor).
	var q = document.getElementById('es-maq-efq');
	var btn = document.getElementById('es-maq-ef-search');
	if (!q || !btn) { return; }
	var efStatus = <?php echo wp_json_encode( $ef_status ); ?>;
	function go() {
		var url = new URL(window.location.href);
		url.searchParams.set('st', 'entradas');
		url.searchParams.set('efq', q.value);
		url.searchParams.set('efstatus', efStatus);
		window.location.href = url.toString();
	}
	btn.addEventListener('click', go);
	q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
})();

(function () {
	// Acciones de entradas (restaurar / papelera / eliminar) sin `<form>` anidados:
	// los formularios dentro del editor rompían el `<form>` principal y el botón
	// "Actualizar" quedaba fuera de él (no guardaba). Se despachan por JS.
	var MAQ_ADMIN = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
	var MAQ_SUB = {
		restore: { action: 'estandar_suite_capture_restore_submission', nonce: <?php echo wp_json_encode( wp_create_nonce( 'estandar_suite_capture_submission' ) ); ?> },
		trash:   { action: 'estandar_suite_capture_trash_submission',    nonce: <?php echo wp_json_encode( wp_create_nonce( 'estandar_suite_capture_submission' ) ); ?> },
		purge:   { action: 'estandar_suite_capture_delete_submission',   nonce: <?php echo wp_json_encode( wp_create_nonce( 'estandar_suite_capture_submission' ) ); ?> },
	};
	Array.prototype.forEach.call(document.querySelectorAll('[data-maq-sub]'), function (btn) {
		btn.addEventListener('click', function () {
			var cfg = MAQ_SUB[btn.getAttribute('data-maq-sub')];
			if (!cfg) { return; }
			if (btn.getAttribute('data-confirm') && !window.confirm(btn.getAttribute('data-confirm'))) { return; }
			var f = document.createElement('form');
			f.method = 'post'; f.action = MAQ_ADMIN;
			[['action', cfg.action], ['submission_id', btn.getAttribute('data-id')], ['_wpnonce', cfg.nonce]].forEach(function (p) {
				var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
			});
			document.body.appendChild(f);
			f.submit();
		});
	});
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
