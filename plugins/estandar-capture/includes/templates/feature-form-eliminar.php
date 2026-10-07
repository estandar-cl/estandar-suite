<?php
/**
 * Estandar Suite — Capture · Pantalla "Eliminar con análisis" de un form.
 * Guía la decisión cuando el form tiene entradas o está publicado:
 *   A) Consolidar con otro form (candidatos del mismo tipo) + backup + papelera.
 *   B) Backup + eliminar SIN migrar (las entries quedan en BD / descargables).
 *   C) Eliminar el form y sus entradas (definitivo).
 * Si no tiene entradas ni páginas → borrado directo.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$fid  = $eliminar_id;
$imp  = \Estandar\Capture\Forms::impact( $fid );
$form = isset( $imp['form'] ) ? $imp['form'] : null;
if ( ! $form ) {
	echo '<div class="notice notice-error"><p>Formulario no encontrado.</p></div>';
	return;
}
$list_url   = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&ss=formularios' );
$candidates = \Estandar\Capture\Forms::consolidation_candidates( $fid );
$has_impact = (int) ( $imp['submissions'] ?? 0 ) > 0 || (int) ( $imp['pages'] ?? 0 ) > 0;
?>
<h4><a href="<?php echo esc_url( $list_url ); ?>">← <?php echo esc_html( $fp_title ); ?></a> · Eliminar «<?php echo esc_html( $form['name'] ); ?>» (<?php echo esc_html( $fp_singular ); ?> #<?php echo $fid; ?>)</h4>

<?php if ( (string) $form['status'] === 'trashed' ) : ?>
	<div class="notice notice-info inline" style="max-width:880px;"><p>Este form ya está en la papelera. Puedes <strong>consolidar/migrar sus páginas a otro form</strong> (opción A), restaurarlo desde la papelera, o eliminarlo definitivamente (opciones B/C).</p></div>
<?php endif; ?>

<?php if ( ! $has_impact ) : ?>
	<div class="notice notice-success inline" style="max-width:860px;">
		<p><strong>Sin impacto:</strong> este form no tiene entradas registradas ni está publicado en ninguna página, así que puedes eliminarlo directamente.</p>
	</div>
	<div style="display:flex;gap:10px;margin-top:10px;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_capture_delete_form' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_delete_form">
			<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
			<input type="hidden" name="confirm" value="yes">
			<button class="button" type="submit">Mover a papelera</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar DEFINITIVAMENTE el form y su configuración?')">
			<?php wp_nonce_field( 'estandar_suite_capture_purge_full' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_purge_full">
			<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
			<input type="hidden" name="confirm" value="yes">
			<button class="button submitdelete" type="submit">Eliminar definitivamente</button>
		</form>
	</div>
	<?php return; endif; ?>

<div class="notice notice-warning inline" style="max-width:880px;">
	<p>
		<strong>Este form tiene impacto:</strong>
		<strong><?php echo (int) $imp['submissions']; ?> entrada(s) registrada(s)</strong>
		<?php if ( (int) $imp['pages'] > 0 ) : ?>
			y está publicado en <strong><?php echo (int) $imp['pages']; ?> página(s)</strong>.
		<?php else : ?>
			y no está publicado en páginas.
		<?php endif; ?>
		Antes de eliminarlo elige cómo conservar esos datos.
	</p>
	<?php if ( ! empty( $imp['placements'] ) ) : ?>
	<ul style="margin:6px 0 0 18px;list-style:disc;">
		<?php foreach ( $imp['placements'] as $pl ) : ?>
			<li><a href="<?php echo esc_url( $pl['url'] ); ?>" target="_blank"><?php echo esc_html( $pl['title'] ); ?></a> <code><?php echo esc_html( implode( ' ', $pl['matched'] ) ); ?></code></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</div>

<?php if ( $candidates ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="var s=this.querySelector('input[name=to_id]:checked'); if(!s){alert('Elige un form destino.');return false;} var n=(s.getAttribute('data-name')||s.value); return confirm('Consolidar «<?php echo esc_js( $form['name'] ); ?>» en «'+n+'»?\nSe moverán <?php echo (int) $imp['submissions']; ?> entrada(s) y se actualizarán <?php echo (int) $imp['pages']; ?> página(s); se generará backup automático y el form origen pasará a la papelera.');">
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:880px;">
			<h3 style="margin:0 0 4px;">A) Consolidar con otro form (recomendado)</h3>
			<p class="description" style="margin:0 0 10px;">Se hace un backup completo, sus entradas pasan al form elegido y las páginas se actualizan al shortcode del destino. El form origen queda en la papelera.</p>
			<?php wp_nonce_field( 'estandar_suite_capture_consolidate' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_consolidate_forms">
			<input type="hidden" name="from_id" value="<?php echo $fid; ?>">
			<input type="hidden" name="confirm" value="yes">
			<table class="widefat striped" style="max-width:100%;">
				<thead><tr><th style="width:32px;"></th><th>Form destino</th><th>Compatibilidad</th><th>Entradas</th><th>Páginas</th></tr></thead>
				<tbody>
				<?php $first = true; foreach ( $candidates as $c ) : $cform = $c['form']; ?>
					<tr>
						<td><input type="radio" name="to_id" value="<?php echo (int) $cform['id']; ?>" data-name="<?php echo esc_attr( $cform['name'] ); ?>" <?php echo $first ? 'checked' : ''; ?>></td>
						<td><strong><?php echo esc_html( $cform['name'] ); ?></strong><div class="description">#<?php echo (int) $cform['id']; ?> · <?php echo esc_html( $cform['slug'] ); ?></div></td>
						<td><?php echo esc_html( $c['badge'] ); ?></td>
						<td><?php echo (int) $c['submissions']; ?></td>
						<td><?php echo (int) $c['pages']; ?></td>
					</tr>
					<?php $first = false; endforeach; ?>
				</tbody>
			</table>
			<p style="margin:12px 0 0;">
				<button class="button button-primary" type="submit">Consolidar y eliminar (con backup)</button>
			</p>
		</div>
	</form>
<?php else : ?>
	<div class="notice notice-info inline" style="max-width:880px;"><p>No hay otro form <strong>del mismo tipo</strong> activo como candidato. Puedes conservar las entradas con backup (opción B) o eliminarlo con sus entradas (opción C).</p></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:12px;max-width:900px;margin-top:14px;">
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;">
		<h3 style="margin:0 0 4px;">B) Eliminar sin migrar, conservando las entradas</h3>
		<p class="description" style="margin:0 0 10px;">Hace un backup descargable del form (config + entradas) y lo mueve a la papelera. Las entries siguen en la base de datos y podrás descargarlas desde el backup; el form se puede restaurar.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Mover a la papelera conservando las entradas? Se generará el backup.')">
			<?php wp_nonce_field( 'estandar_suite_capture_delete_keep' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_form_delete_keep">
			<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
			<input type="hidden" name="confirm" value="yes">
			<button class="button" type="submit">Backup y mover a papelera</button>
		</form>
	</div>
	<div style="background:#fff;border:1px solid #cc2b2b;border-radius:8px;padding:14px 16px;">
		<h3 style="margin:0 0 4px;color:#cc2b2b;">C) Eliminar el form y sus entradas</h3>
		<p class="description" style="margin:0 0 10px;">Borra definitivamente el form y sus <?php echo (int) $imp['submissions']; ?> entrada(s) (no recuperable).</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar DEFINITIVAMENTE el form y sus <?php echo (int) $imp['submissions']; ?> entradas? No se podrá recuperar.')">
			<?php wp_nonce_field( 'estandar_suite_capture_purge_full' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_purge_full">
			<input type="hidden" name="form_id" value="<?php echo $fid; ?>">
			<input type="hidden" name="confirm" value="yes">
			<input type="hidden" name="delete_entries" value="1">
			<button class="button submitdelete" type="submit">Eliminar form y entradas</button>
		</form>
	</div>
</div>
