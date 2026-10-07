<?php
/**
 * Estandar Suite — Capture · Producto de captura genérico (Forms/Surveys/Postventa).
 * Lista los forms del tipo del producto ($fp_type). "+ Crear" y "Editar" abren el
 * MAQUETADOR visual dedicado. Cada form es clickeable → detalle con sus entradas o
 * respuestas. Variables: $feature, $feature_view, $fp_type, $fp_title, $fp_singular,
 * $fp_use_responses, $fp_help, $fp_create_label.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$v2_forms  = isset( $feature_view['v2_forms'] ) ? $feature_view['v2_forms'] : array();
$entries   = isset( $feature_view['entries'] ) ? $feature_view['entries'] : array();
$responses = isset( $feature_view['responses'] ) ? $feature_view['responses'] : array();
$types     = \Estandar\Capture\Forms::types();

$forms = array();
foreach ( $v2_forms as $vf ) {
	if ( (string) $vf['form_type'] === $fp_type && (string) $vf['status'] !== 'trashed' ) {
		$forms[] = $vf;
	}
}
$trashed = \Estandar\Capture\Forms::list_trashed( $fp_type );
$papelera = isset( $_GET['papelera'] );

$form_id = isset( $_GET['form'] ) ? (int) $_GET['form'] : 0;
$detail  = $form_id ? \Estandar\Capture\Forms::get( $form_id ) : null;
$nuevo      = isset( $_GET['nuevo'] );
$editar_id  = isset( $_GET['editar'] ) ? (int) $_GET['editar'] : 0;
$editar_form = $editar_id ? \Estandar\Capture\Forms::get( $editar_id ) : null;
$maq_mode   = $nuevo || $editar_form;

$fd = 'info';
if ( $detail ) {
	$fd_subs = $fp_use_responses ? array( 'info' => 'Información', 'respuestas' => 'Respuestas' ) : array( 'info' => 'Información', 'entradas' => 'Entradas' );
	$fd = \Estandar\Core\Admin::resolve_ssub( $fd_subs, 'info', 'fd' );
}

if ( $maq_mode ) {
	$maq_config = array();
	if ( $editar_form ) {
		$maq_config = json_decode( (string) $editar_form['config_json'], true );
		if ( ! is_array( $maq_config ) ) { $maq_config = array(); }
	}
	$maq_caps = array();
	foreach ( \Estandar\Capture\Forms::product_capabilities( $fp_type ) as $mc => $mcfg ) {
		if ( \Estandar\Capture\Forms::is_capability_enabled( $fp_type, $mc ) ) { $maq_caps[ $mc ] = true; }
	}
	$field_types = array(
		'text' => 'Texto', 'textarea' => 'Texto largo', 'email' => 'Email', 'tel' => 'Teléfono', 'phone' => 'Teléfono',
		'rut' => 'RUT (Chile)', 'number' => 'Número', 'date' => 'Fecha', 'select' => 'Lista de opciones',
		'radio' => 'Opción única', 'checkbox' => 'Casillas de verificación', 'rating' => 'Estrellas',
		'cards' => 'Tarjetas', 'emoji' => 'Emoji', 'matrix' => 'Matriz / tabla', 'file' => 'Archivo',
		'nps' => 'NPS (0-10)', 'custom' => 'Personalizado',
	);
	$form  = $editar_form;
	$config = $maq_config;
	$caps  = $maq_caps;
	include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/maquetador.php';
	return;
}
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, $fp_help ); ?>

<?php if ( isset( $_GET['form_saved'] ) ) : ?><div class="notice notice-<?php echo $_GET['form_saved'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['form_saved'] ? ucfirst( $fp_singular ) . ' guardado.' : 'No se pudo guardar (nombre requerido).'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['form_trashed'] ) ) : ?><div class="notice notice-<?php echo $_GET['form_trashed'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['form_trashed'] ? ucfirst( $fp_singular ) . ' movido a la papelera.' : 'No se pudo mover a la papelera.'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['form_restored'] ) ) : ?><div class="notice notice-<?php echo $_GET['form_restored'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['form_restored'] ? ucfirst( $fp_singular ) . ' restaurado.' : 'No se pudo restaurar.'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['form_purged'] ) ) : ?><div class="notice notice-<?php echo $_GET['form_purged'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['form_purged'] ? ucfirst( $fp_singular ) . ' eliminado definitivamente.' : 'No se pudo eliminar definitivamente.'; ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['form_duplicated'] ) ) : ?><div class="notice notice-<?php echo $_GET['form_duplicated'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['form_duplicated'] ? ucfirst( $fp_singular ) . ' duplicado.' : 'No se pudo duplicar.'; ?></p></div><?php endif; ?>

<?php if ( $detail ) : $f = $detail; $fid = (int) $f['id']; ?>
<h4><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' ) ); ?>">← <?php echo esc_html( $fp_title ); ?></a> · <?php echo esc_html( $f['name'] ); ?> (<?php echo esc_html( $fp_singular ); ?> #<?php echo $fid; ?>)</h4>
<?php $fd_tabs = array();
foreach ( $fd_subs as $k => $l ) {
	$fd_tabs[ $k ] = array(
		'label' => $l,
		'url'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&form=' . $fid . '&fd=' . $k ),
	);
}
$fd_tabs['editar'] = array(
	'label' => 'Editar',
	'url'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&editar=' . $fid ),
);
echo \Estandar\Core\Admin::subtab_nav_html( $fd_tabs, $fd ); ?>

<?php if ( $fd === 'info' ) : ?>
<table class="widefat striped" style="max-width:820px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Nombre</strong></td><td><?php echo esc_html( $f['name'] ); ?></td></tr>
		<tr><td><strong>Shortcode</strong></td><td><?php echo \Estandar\Core\Embed::copy_control( \Estandar\Core\Embed::shortcode( 'form', $fid ) ); ?></td></tr>
		<tr><td><strong>Slug (técnico)</strong></td><td><code><?php echo esc_html( $f['slug'] ); ?></code></td></tr>
		<tr><td><strong>Tipo</strong></td><td><?php echo esc_html( isset( $types[ $f['form_type'] ] ) ? $types[ $f['form_type'] ] : $f['form_type'] ); ?></td></tr>
		<tr><td><strong>Contexto</strong></td><td><?php echo esc_html( \Estandar\Capture\Forms::context_label( $f['form_context'] ) ); ?></td></tr>
		<tr><td><strong>Estado</strong></td><td><?php echo esc_html( $f['status'] ); ?></td></tr>
		<tr><td><strong>Creado</strong></td><td><?php echo esc_html( $f['created_at'] ); ?></td></tr>
	</tbody>
</table>
<p style="margin-top:10px;">
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&editar=' . $fid ) ); ?>">Editar</a>
</p>
<?php echo \Estandar\Core\Embed::copy_script(); ?>

<?php elseif ( $fd === 'entradas' ) : ?>
<h4>Entradas de «<?php echo esc_html( $f['name'] ); ?>»</h4>
<?php $subs = \Estandar\Capture\Forms::submissions( $fid, 100 ); ?>
<?php if ( $subs ) : ?>
<p class="description">Submissions nativas v2 de este formulario (registro operativo; el Lead comercial se crea en el pipeline de Flow).</p>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>UUID</th><th>Nombre</th><th>Email</th><th>Lead (pipeline)</th><th>Fecha</th></tr></thead>
	<tbody>
	<?php foreach ( $subs as $s ) : $sid = (int) $s['id']; $pl = \Estandar\Capture\Forms::pipeline_for_capture( $sid ); ?>
		<tr>
			<td><?php echo $sid; ?></td>
			<td><code><?php echo esc_html( $s['capture_uuid'] ); ?></code></td>
			<td><?php echo esc_html( $s['name'] ); ?></td>
			<td><?php echo esc_html( $s['email_normalized'] ); ?></td>
			<td><?php echo $pl ? '<a href="' . esc_url( admin_url( 'admin.php?page=estandar-suite-flow&lead=' . (int) $pl['id'] ) ) . '">#' . (int) $pl['id'] . ' · ' . esc_html( $pl['stage'] ) . '</a>' : '<span class="description">—</span>'; ?></td>
			<td><?php echo esc_html( $s['created_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php else : ?><p class="description">Sin submissions nativas v2 todavía.</p><?php endif; ?>

<?php $n = 0; foreach ( $entries as $e ) : if ( (int) $e['form_id'] !== $fid ) { continue; } $n++; endforeach; ?>
<?php if ( $n ) : ?>
<h4 style="margin-top:16px;">Entradas históricas (legacy)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Estado</th><th>Datos</th><th>Fecha</th></tr></thead>
	<tbody>
	<?php foreach ( $entries as $e ) : if ( (int) $e['form_id'] !== $fid ) { continue; } $data = json_decode( (string) $e['data_json'], true ); ?>
		<tr>
			<td><?php echo (int) $e['id']; ?></td>
			<td><?php echo esc_html( $e['status'] ); ?></td>
			<td><code><?php echo esc_html( is_array( $data ) ? implode( ' · ', array_map( 'strval', $data ) ) : $e['data_json'] ); ?></code></td>
			<td><?php echo esc_html( $e['created_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php elseif ( $fd === 'respuestas' ) : ?>
<h4>Respuestas de «<?php echo esc_html( $f['name'] ); ?>»</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Sesión</th><th>Pregunta</th><th>Respuesta</th><th>Respondido</th></tr></thead>
	<tbody>
	<?php $n = 0; foreach ( $responses as $r ) : if ( (int) $r['form_id'] !== $fid ) { continue; } $n++; ?>
		<tr>
			<td><?php echo (int) $r['id']; ?></td>
			<td><code><?php echo esc_html( (string) $r['session_token'] ); ?></code></td>
			<td><?php echo esc_html( $r['question_label'] ); ?></td>
			<td><?php echo esc_html( $r['answer_value'] !== '' ? $r['answer_value'] : $r['answer_label'] ); ?></td>
			<td><?php echo esc_html( $r['answered_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( ! $n ) : ?><tr><td colspan="5">Sin respuestas para esta <?php echo esc_html( $fp_singular ); ?>.</td></tr><?php endif; ?>
	</tbody>
</table>
<?php endif; ?>

<?php elseif ( $papelera ) : ?>
<h4><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' ) ); ?>">← <?php echo esc_html( $fp_title ); ?></a> · Papelera (<?php echo count( $trashed ); ?>)</h4>
<p class="description">Los <?php echo esc_html( strtolower( $fp_title ) ); ?> eliminados se guardan aquí por un tiempo. Puedes restaurarlos o eliminarlos definitivamente.</p>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Contexto</th><th>Eliminado</th></tr></thead>
	<tbody>
	<?php foreach ( $trashed as $vf ) : $fid = (int) $vf['id']; ?>
		<tr>
			<td><?php echo $fid; ?></td>
			<td>
				<strong><?php echo esc_html( $vf['name'] ); ?></strong>
				<div class="row-actions">
					<span class="restore">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_capture_restore_form' ); ?>
							<input type="hidden" name="action" value="estandar_suite_capture_restore_form">
							<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
							<button class="button-link" type="submit">Restaurar</button>
						</form> |
					</span>
					<span class="trash">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('¿Eliminar DEFINITIVAMENTE el <?php echo esc_js( $fp_singular ); ?> «<?php echo esc_js( $vf['name'] ); ?>»? No se podrá recuperar.')">
							<?php wp_nonce_field( 'estandar_suite_capture_purge_form' ); ?>
							<input type="hidden" name="action" value="estandar_suite_capture_purge_form">
							<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
							<input type="hidden" name="confirm" value="yes">
							<button class="button-link submitdelete" type="submit">Eliminar definitivamente</button>
						</form>
					</span>
				</div>
			</td>
			<td><?php echo esc_html( \Estandar\Capture\Forms::context_label( $vf['form_context'] ) ); ?></td>
			<td><?php echo esc_html( $vf['updated_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $trashed ) ) : ?><tr><td colspan="4">La papelera está vacía.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php else : ?>
<h4 style="display:flex;align-items:center;gap:8px;">
	<span><?php echo esc_html( $fp_title ); ?> (<?php echo count( $forms ); ?>)</span>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&nuevo=1' ) ); ?>" style="font-weight:600;">+ <?php echo esc_html( $fp_create_label ); ?></a>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&papelera=1' ) ); ?>">Papelera (<?php echo count( $trashed ); ?>)</a>
</h4>

<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Shortcode</th><th>Contexto</th><th>Estado</th><th>Entradas</th></tr></thead>
	<tbody>
	<?php foreach ( $forms as $vf ) : $fid = (int) $vf['id']; $n_entries = 0; foreach ( $entries as $e ) { if ( (int) $e['form_id'] === $fid ) { $n_entries++; } } ?>
		<tr>
			<td><?php echo $fid; ?></td>
			<td>
				<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&form=' . $fid ) ); ?>"><?php echo esc_html( $vf['name'] ); ?></a></strong>
				<div class="row-actions">
					<span class="edit"><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios&editar=' . $fid ) ); ?>">Editar</a> | </span>
					<span class="connect"><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=integraciones&form_source=v2&form_id=' . $fid ) ); ?>">Conectar a…</a> | </span>
					<span class="duplicate">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_capture_duplicate_form' ); ?>
							<input type="hidden" name="action" value="estandar_suite_capture_duplicate_form">
							<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
							<button class="button-link" type="submit">Duplicar</button>
						</form> |
					</span>
					<span class="trash">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('¿Mover a la papelera el <?php echo esc_js( $fp_singular ); ?> «<?php echo esc_js( $vf['name'] ); ?>»?')">
							<?php wp_nonce_field( 'estandar_suite_capture_delete_form' ); ?>
							<input type="hidden" name="action" value="estandar_suite_capture_delete_form">
							<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
							<input type="hidden" name="confirm" value="yes">
							<button class="button-link submitdelete" type="submit">Papelera</button>
						</form>
					</span>
				</div>
			</td>
			<td><?php echo \Estandar\Core\Embed::copy_control( \Estandar\Core\Embed::shortcode( 'form', $fid ) ); ?></td>
			<td><?php echo esc_html( \Estandar\Capture\Forms::context_label( $vf['form_context'] ) ); ?></td>
			<td><?php echo esc_html( $vf['status'] ); ?></td>
			<td><?php echo (int) $n_entries; ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $forms ) ) : ?><tr><td colspan="6">Sin <?php echo esc_html( strtolower( $fp_title ) ); ?>. Usa el botón <strong>+</strong> para crear el primero.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php echo \Estandar\Core\Embed::copy_script(); ?>

<p class="description">Los settings del módulo y las capacidades de este producto se gestionan en la pestaña <strong>Settings</strong> de Capture.</p>
<?php endif; ?>
