<?php
/**
 * Estandar Suite — Flow · Proyectos (wizard de cotizaciones).
 *
 * Flujo:
 *   0  lista de proyectos          (sin step)         → "Añadir cotización"
 *   1  header + bottom + plantilla (step=1)           → "Continuar"
 *   2  editor de plantilla         (step=2) prefijado → "Guardar"
 *   3  confirmación                (step=3)           → "Continuar"
 *   4  lista de leads globales     (step=4)           → "Crear cotización"
 *   5  editor de cotización        (step=5) prellenado → "Ver y enviar"
 *   6  PDF + envío (+ CC)          (step=6)
 *
 * Variables: $project, $step, $lead_id, $quote_id, $search, $notice,
 * $notice_type, $has_settings, $has_editor, $projects, $project_label,
 * $project_operator, $project_settings, $templates, $selected_template, $doc,
 * $leads, $lead, $quote, $lead_email, $preview_url, $download_url,
 * $project_operator_email.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base = 'Estandar\Flow\Admin';
if ( empty( $templates ) ) {
	$templates = array();
}
$notice_colors = array(
	'ok'    => array( '#e7f6ec', '#01651f' ),
	'error' => array( '#fcf0f1', '#b32d2e' ),
	'info'  => array( '#f0f6fc', '#2271b1' ),
);
$nc = $notice_colors[ $notice_type ] ?? $notice_colors['info'];
?>
<?php if ( '' !== (string) $notice ) : ?>
	<div class="notice inline" style="background:<?php echo esc_attr( $nc[0] ); ?>;color:<?php echo esc_attr( $nc[1] ); ?>;border-left-color:<?php echo esc_attr( $nc[1] ); ?>;">
		<p><?php echo esc_html( (string) $notice ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! $has_settings ) : ?>
	<div class="notice notice-error inline"><p>El módulo de cotizaciones no está disponible en esta instalación.</p></div>
	<?php return; ?>
<?php endif; ?>

<?php if ( $step >= 1 && $step <= 6 && '' === (string) $project ) : ?>
	<div class="notice notice-warning inline"><p>Falta seleccionar un proyecto. <a href="<?php echo esc_url( $base::projects_url() ); ?>">Volver a Proyectos</a>.</p></div>
	<?php return; ?>
<?php endif; ?>

<?php if ( in_array( $step, array( 2, 5 ), true ) && ! $has_editor ) : ?>
	<div class="notice notice-error inline"><p>El editor de cotizaciones no está disponible (falta el módulo v2).</p></div>
	<?php return; ?>
<?php endif; ?>

<?php if ( 0 === $step || $step > 6 ) : /* ── Paso 0: lista de proyectos ── */ ?>
	<h1 style="font-size:22px;">Proyectos</h1>
	<p class="description" style="max-width:820px;">Proyectos de esta instalación. Elige un proyecto y añade una cotización: asignas el encabezado y el pie del documento, revisas la plantilla y eliges el lead al que enviarás la propuesta.</p>

	<table class="widefat striped" style="max-width:1000px;margin-top:12px;">
		<thead><tr><th>Proyecto</th><th>Slug</th><th>Operador</th><th style="width:170px;">Acciones</th></tr></thead>
		<tbody>
		<?php if ( empty( $projects ) ) : ?>
			<tr><td colspan="4">No hay proyectos registrados todavía.</td></tr>
		<?php else : ?>
			<?php foreach ( $projects as $slug => $label ) : ?>
				<tr>
					<td><strong><?php echo esc_html( (string) $label ); ?></strong></td>
					<td><code><?php echo esc_html( (string) $slug ); ?></code></td>
					<td><?php echo esc_html( (string) $base::project_operator( (string) $slug ) ); ?></td>
					<td>
						<a class="button button-primary" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $slug, 'step' => 1 ) ) ); ?>">Añadir cotización</a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

<?php elseif ( 1 === $step ) : /* ── Paso 1: header + bottom ── */ ?>
	<?php
	$header_id  = (int) ( $project_settings['header_image_id'] ?? 0 );
	$header_url = (string) ( $project_settings['header_image_url'] ?? '' );
	$bottom_id  = (int) ( $project_settings['bottom_image_id'] ?? 0 );
	$bottom_url = (string) ( $project_settings['bottom_image_url'] ?? '' );
	?>
	<h1 style="font-size:22px;">Añadir cotización — <?php echo esc_html( (string) $project_label ); ?></h1>
	<p class="description" style="max-width:820px;">Paso 1 de 5 · Asigna la imagen del <strong>encabezado</strong> (banda superior) y del <strong>pie</strong> (banda inferior) del documento, y elige la plantilla.</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;">
		<?php wp_nonce_field( 'estandar_suite_flow_proj_header_bottom' ); ?>
		<input type="hidden" name="action" value="estandar_suite_flow_proj_header_bottom">
		<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label>Imagen del encabezado</label></th>
				<td>
					<input type="hidden" name="header_image_id" id="esfp-header-id" value="<?php echo esc_attr( (string) $header_id ); ?>">
					<input type="url" name="header_image_url" id="esfp-header-url" value="<?php echo esc_attr( $header_url ); ?>" class="regular-text" placeholder="https://...">
					<button type="button" class="button esfp-media-pick" data-url="esfp-header-url" data-id="esfp-header-id">Elegir de la biblioteca</button>
					<p class="description">Se muestra como banda superior (calidad de impresión, jpg/png).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>Imagen del pie</label></th>
				<td>
					<input type="hidden" name="bottom_image_id" id="esfp-bottom-id" value="<?php echo esc_attr( (string) $bottom_id ); ?>">
					<input type="url" name="bottom_image_url" id="esfp-bottom-url" value="<?php echo esc_attr( $bottom_url ); ?>" class="regular-text" placeholder="https://...">
					<button type="button" class="button esfp-media-pick" data-url="esfp-bottom-url" data-id="esfp-bottom-id">Elegir de la biblioteca</button>
					<p class="description">Banda inferior full-bleed del documento.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label>Plantilla</label></th>
				<td>
					<select name="template">
						<?php foreach ( $templates as $tid => $tpl ) : ?>
							<option value="<?php echo esc_attr( (string) $tid ); ?>" <?php selected( $selected_template, (string) $tid ); ?>><?php echo esc_html( (string) ( $tpl['name'] ?? $tid ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php
			$gallery = ( isset( $project_settings['images'] ) && is_array( $project_settings['images'] ) )
				? array_values( $project_settings['images'] )
				: array();
			?>
			<tr>
				<th scope="row"><label>Imágenes del proyecto</label></th>
				<td>
					<input type="hidden" name="project_images_json" id="esfp-gallery-json" value="<?php echo esc_attr( (string) wp_json_encode( $gallery ) ); ?>">
					<div class="esfp-gallery-preview" id="esfp-gallery-preview" data-json="esfp-gallery-json" style="margin-bottom:8px;">
						<?php foreach ( $gallery as $gi => $gimg ) : ?>
							<?php
							$gimg = is_array( $gimg ) ? $gimg : array();
							$gurl = (string) ( $gimg['url'] ?? '' );
							if ( '' === $gurl ) {
								continue;
							}
							?>
							<span style="display:inline-block;margin:0 6px 6px 0;text-align:center;">
								<img src="<?php echo esc_url( $gurl ); ?>" style="width:90px;height:70px;object-fit:cover;display:block;border:1px solid #dcdcde;" alt="">
								<button type="button" class="button-link esfp-gallery-remove" data-index="<?php echo esc_attr( (string) $gi ); ?>" style="color:#b32d2e;cursor:pointer;">Quitar</button>
							</span>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button esfp-media-gallery-pick" data-json="esfp-gallery-json" data-preview="esfp-gallery-preview">Agregar imágenes</button>
					<p class="description">Hasta 6 imágenes de la biblioteca. Se muestran en el documento tras el financiamiento; si no hay imágenes, la sección no aparece.</p>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary">Continuar</button>
			<a class="button" href="<?php echo esc_url( $base::projects_url() ); ?>">Volver</a>
		</p>
	</form>

	<?php /* El binding del selector de medios se inyecta en `media-views` (ver Admin::enqueue_projects_assets). */ ?>

<?php elseif ( 2 === $step ) : /* ── Paso 2: editor de plantilla ── */ ?>
	<h1 style="font-size:22px;">Plantilla — <?php echo esc_html( (string) $project_label ); ?></h1>
	<p class="description" style="max-width:820px;">Paso 2 de 5 · Revisa y edita los textos del documento. Lo que dejes aquí queda precargado para las cotizaciones de este proyecto.</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="esq-form" style="max-width:1100px;">
		<?php wp_nonce_field( 'estandar_suite_flow_proj_save_template' ); ?>
		<input type="hidden" name="action" value="estandar_suite_flow_proj_save_template">
		<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">
		<input type="hidden" name="document_json" value="">

		<div class="card" style="max-width:100%;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;">
			<?php echo \Estandar\Flow\Quotes\Editor::canvas( $doc, array( 'editable' => true, 'gallery' => true, 'projects_url' => $base::projects_url() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php
		$field_option_catalog = class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ? \Estandar\Flow\Quotes\Settings::option_field_catalog() : array();
		$current_field_options = class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ? \Estandar\Flow\Quotes\Settings::field_options( (string) $project ) : array();
		?>
		<?php if ( ! empty( $field_option_catalog ) ) : ?>
			<div class="card" style="max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;">
				<h3 style="margin-top:0;">Opciones de campos</h3>
				<p class="description">Una opción por línea. Estos valores se mostrarán como menús desplegables al llenar la cotización de este proyecto. Déjalo vacío para escribir el valor libremente.</p>
				<div style="display:flex;flex-wrap:wrap;gap:14px;">
					<?php foreach ( $field_option_catalog as $path => $label ) : ?>
						<?php $option_values = isset( $current_field_options[ $path ] ) && is_array( $current_field_options[ $path ] ) ? $current_field_options[ $path ] : array(); ?>
						<label style="flex:1 1 240px;font-size:12px;color:#646970;">
							<?php echo esc_html( (string) $label ); ?>
							<textarea name="field_options[<?php echo esc_attr( (string) $path ); ?>]" rows="3" style="width:100%;font-family:inherit;"><?php echo esc_textarea( implode( "\n", $option_values ) ); ?></textarea>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<p>
			<button type="submit" class="button button-primary">Guardar</button>
			<a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 1 ) ) ); ?>">Volver</a>
		</p>
	</form>

<?php elseif ( 3 === $step ) : /* ── Paso 3: confirmación ── */ ?>
	<h1 style="font-size:22px;"><?php echo esc_html( (string) $project_label ); ?></h1>
	<div class="notice inline" style="background:#e7f6ec;color:#01651f;border-left-color:#01651f;">
		<p style="font-size:15px;"><strong>Ya puedes hacer tus cotizaciones</strong></p>
	</div>
	<p class="description" style="max-width:820px;">La plantilla y los datos del proyecto quedaron guardados. Continúa para elegir el lead al que le enviarás la cotización.</p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 4 ) ) ); ?>">Continuar</a>
		<a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 2 ) ) ); ?>">Volver a la plantilla</a>
	</p>

<?php elseif ( 4 === $step ) : /* ── Paso 4: leads globales ── */ ?>
	<h1 style="font-size:22px;">Elige a quién le quieres mandar una cotización</h1>
	<p class="description">Paso 3 de 5 · Leads de esta instalación. Elige uno para crear su cotización.</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:10px 0;">
		<input type="hidden" name="page" value="estandar-suite-flow">
		<input type="hidden" name="tab" value="proyectos">
		<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">
		<input type="hidden" name="step" value="4">
		<input type="search" name="q" value="<?php echo esc_attr( (string) $search ); ?>" placeholder="Buscar por nombre, email o teléfono">
		<button type="submit" class="button">Buscar</button>
	</form>

	<details style="margin:10px 0;max-width:820px;">
		<summary style="cursor:pointer;font-weight:600;">Añadir lead</summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<?php wp_nonce_field( 'estandar_suite_flow_proj_lead_create' ); ?>
			<input type="hidden" name="action" value="estandar_suite_flow_proj_lead_create">
			<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="esfp-lead-name">Nombre</label></th>
					<td><input name="name" id="esfp-lead-name" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="esfp-lead-email">Email</label></th>
					<td><input name="email" id="esfp-lead-email" type="email" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="esfp-lead-phone">Teléfono</label></th>
					<td><input name="phone" id="esfp-lead-phone" type="text" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row">Proyecto</th>
					<td><code><?php echo esc_html( '' !== (string) $project_label ? (string) $project_label : (string) $project ); ?></code> <span class="description">(fijo)</span></td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary">Añadir lead</button></p>
		</form>
	</details>

	<table class="widefat striped" style="max-width:1100px;">
		<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Teléfono</th><th>Proyecto</th><th style="width:170px;">Acciones</th></tr></thead>
		<tbody>
		<?php if ( empty( $leads ) ) : ?>
			<tr><td colspan="6">No hay leads<?php echo '' !== $search ? ' de este proyecto que coincidan con la búsqueda' : ' de este proyecto'; ?>.</td></tr>
		<?php else : ?>
			<?php foreach ( $leads as $l ) : ?>
				<?php $lid = (int) ( $l['id'] ?? 0 ); ?>
				<tr>
					<td><?php echo $lid; ?></td>
					<td><strong><?php echo esc_html( (string) ( $l['name'] ?? '' ) ); ?></strong></td>
					<td><?php echo esc_html( (string) ( $l['email'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $l['phone'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $l['project_slug'] ?? '' ) ); ?></td>
					<td>
						<a class="button button-primary" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 5, 'lead' => $lid ) ) ); ?>">Crear cotización</a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	<p style="margin-top:10px;"><a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 3 ) ) ); ?>">Volver</a></p>

<?php elseif ( 5 === $step ) : /* ── Paso 5: editor de cotización ── */ ?>
	<?php
	$client_name = (string) ( $lead['name'] ?? ( $doc['client']['name'] ?? '' ) );
	$title       = 'Cotización — ' . ( '' !== $client_name ? $client_name : 'lead #' . $lead_id );
	?>
	<h1 style="font-size:22px;"><?php echo esc_html( $title ); ?></h1>
	<p class="description" style="max-width:820px;">Paso 4 de 5 · Todo el contenido está precargado (plantilla + datos del proyecto + datos del lead) y es editable. Al terminar, guarda y revisa el PDF.</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="esq-form" style="max-width:1100px;">
		<?php wp_nonce_field( 'estandar_suite_flow_proj_quote_save' ); ?>
		<input type="hidden" name="action" value="estandar_suite_flow_proj_quote_save">
		<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">
		<input type="hidden" name="lead_id" value="<?php echo (int) $lead_id; ?>">
		<input type="hidden" name="quote_id" value="<?php echo (int) $quote_id; ?>">
		<input type="hidden" name="document_json" value="">

		<div class="card" style="max-width:100%;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;">
			<?php echo \Estandar\Flow\Quotes\Editor::canvas( $doc, array( 'editable' => true, 'gallery' => true, 'projects_url' => $base::projects_url() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<p>
			<button type="submit" class="button">Guardar</button>
			<button type="submit" class="button button-primary" name="wizard_next" value="1">Ver y enviar</button>
			<a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 4 ) ) ); ?>">Volver</a>
		</p>
	</form>

<?php elseif ( 6 === $step ) : /* ── Paso 6: PDF + envío ── */ ?>
	<h1 style="font-size:22px;">Revisa y envía</h1>
	<p class="description">Paso 5 de 5 · Esta es la cotización tal como la verá el cliente (PDF real).</p>

	<?php if ( empty( $quote ) ) : ?>
		<div class="notice notice-error inline"><p>No se encontró la cotización (quote #<?php echo (int) $quote_id; ?>). <a href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 4 ) ) ); ?>">Volver a leads</a>.</p></div>
	<?php else : ?>
		<p>
			<iframe src="<?php echo esc_url( $preview_url ); ?>" title="Vista previa de la cotización" style="width:100%;max-width:1000px;height:900px;border:1px solid #dcdcde;border-radius:8px;background:#fff;"></iframe>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 5, 'quote' => (int) $quote_id, 'lead' => (int) $lead_id ) ) ); ?>">Volver y corregir</a>
			<a class="button" href="<?php echo esc_url( (string) ( $download_url ?? '' ) ); ?>">Descargar</a>
			<a class="button" href="<?php echo esc_url( $base::projects_url( array( 'project' => (string) $project, 'step' => 4 ) ) ); ?>">Ver leads</a>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
			<?php wp_nonce_field( 'estandar_suite_flow_quote_send' ); ?>
			<input type="hidden" name="action" value="estandar_suite_flow_quote_send">
			<input type="hidden" name="quote_id" value="<?php echo (int) $quote_id; ?>">
			<input type="hidden" name="lead_id" value="<?php echo (int) $lead_id; ?>">
			<input type="hidden" name="project" value="<?php echo esc_attr( (string) $project ); ?>">
			<input type="hidden" name="redirect" value="proyectos">
			<input type="hidden" name="to" value="<?php echo esc_attr( $lead_email ); ?>">

			<p>
				<button type="submit" class="button button-primary" <?php disabled( '' === $lead_email ); ?>>Enviar a <?php echo esc_html( '' !== $lead_email ? $lead_email : '(sin email)' ); ?></button>
				<?php if ( '' === $lead_email ) : ?><span class="description">El lead no tiene email registrado; igual puedes enviar con una copia.</span><?php endif; ?>
			</p>

			<p>
				<button type="submit" class="button" name="to_operator" value="1" <?php disabled( '' === (string) ( $project_operator_email ?? '' ) ); ?>>Enviar a operador</button>
				<?php if ( '' !== (string) ( $project_operator_email ?? '' ) ) : ?>
					<span class="description">Se enviará a <?php echo esc_html( (string) $project_operator_email ); ?> (el lead queda en copia si tiene email).</span>
				<?php else : ?>
					<span class="description">El proyecto no tiene email de operador registrado.</span>
				<?php endif; ?>
			</p>

			<fieldset style="border:1px solid #dcdcde;border-radius:8px;padding:12px;max-width:560px;">
				<legend><strong>Enviar con copia a</strong></legend>
				<input type="email" name="cc" value="" class="regular-text" placeholder="otro@correo.cl">
				<button type="submit" class="button">Enviar con copia</button>
			</fieldset>
		</form>
	<?php endif; ?>

<?php endif; ?>
