<?php
/**
 * Estandar Suite — Flow · Cotizaciones (sub-tab con sub-sub-tabs).
 * Cotizaciones de la instalación legada (v1, es_quotes) + cotizaciones v2 + settings.
 * Sub-sub-tabs: Resumen · Cotizaciones v2 · Cotizaciones · Editor · Documento · Finanzas · Sala de ventas · Autoridad · Email · Settings · Plantillas.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$quotes    = isset( $feature_view['quotes'] ) ? $feature_view['quotes'] : array();
$v2_quotes = isset( $feature_view['v2_quotes'] ) ? (int) $feature_view['v2_quotes'] : 0;
$v2        = isset( $feature_view['v2'] ) && is_array( $feature_view['v2'] ) ? $feature_view['v2'] : array();
$adoption  = isset( $feature_view['adoption'] ) && is_array( $feature_view['adoption'] ) ? $feature_view['adoption'] : array();
$ssubs = \Estandar\Core\Admin::filter_ssubs( 'flow', 'config', 'quotes', \Estandar\Flow\Admin::quotes_ssubs() );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'quotes', '<strong>¿Para qué sirve?</strong> Muestra las <em>cotizaciones</em> de la instalación anterior (v1) y las <strong>cotizaciones v2</strong> (operativas: crear, editar, enviar, cambiar estado, generar PDF y duplicar). <br><br>Es el <em>historial de propuestas</em>: ves qué cotizaciones se generaron y en qué estado están. Los sub-sub-tabs <strong>Documento, Finanzas, Sala de ventas, Autoridad y Email</strong> rescatan la configuración de <code>estandar-quotes</code> (v1) para que las nuevas cotizaciones v2 respeten los mismos datos.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-flow', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['quotes_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de cotización guardada.</p></div><?php endif; ?>
<?php if ( isset( $_GET['templates_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Plantillas guardadas.</p></div><?php endif; ?>
<?php if ( isset( $_GET['project_settings_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings del proyecto guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['quote_sent'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Cotización enviada al cliente.</p></div><?php endif; ?>
<?php if ( ! empty( $_GET['quote_duplicated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Cotización duplicada: <strong>#<?php echo (int) $_GET['quote_duplicated']; ?></strong> (borrador).</p></div><?php endif; ?>
<?php if ( ! empty( $_GET['quote_status'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Estado actualizado a <strong><?php echo esc_html( sanitize_key( (string) $_GET['quote_status'] ) ); ?></strong>.</p></div><?php endif; ?>
<?php if ( isset( $_GET['quote_pdf_bytes'] ) ) : ?><div class="notice notice-success is-dismissible"><p>PDF generado (<?php echo (int) $_GET['quote_pdf_bytes']; ?> bytes).</p></div><?php endif; ?>
<?php if ( ! empty( $_GET['quote_error'] ) ) : ?><div class="notice notice-error is-dismissible"><p>No se pudo completar la operación: <code><?php echo esc_html( sanitize_text_field( (string) $_GET['quote_error'] ) ); ?></code></p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:520px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Cotizaciones v2</strong></td><td><?php echo (int) $v2_quotes; ?></td></tr>
		<tr><td><strong>Cotizaciones legadas</strong></td><td><?php echo count( $quotes ); ?></td></tr>
		<?php if ( $adoption ) : ?>
			<tr><td><strong>Legado (es_quotes)</strong></td><td><?php echo (int) ( $adoption['legacy_total'] ?? 0 ); ?> · adoptadas <?php echo (int) ( $adoption['adopted'] ?? 0 ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'v2' ) : ?>
<p>
	<a class="button button-primary" href="<?php echo esc_url( \Estandar\Flow\Admin::quote_editor_url() ); ?>">Nueva cotización</a>
</p>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>N°</th><th>Título</th><th>Cliente / lead</th><th>Ítems</th><th>Total</th><th>Estado</th><th>Enviada</th><th>Acciones</th></tr></thead>
	<tbody>
	<?php foreach ( $v2 as $q ) : ?>
		<?php
		$qid     = (int) ( $q['id'] ?? 0 );
		$qlead   = (int) ( $q['lead_id'] ?? 0 );
		$status  = sanitize_key( (string) ( $q['status'] ?? 'draft' ) );
		$total   = \Estandar\Flow\Quotes::format_amount( (float) ( $q['total'] ?? 0 ), (string) ( $q['currency'] ?? 'CLP' ) );
		$edit    = \Estandar\Flow\Admin::quote_editor_url( $qlead, $qid );
		$actions = admin_url( 'admin-post.php' );
		// M8: link público (solo si la cotización ya tiene token emitido).
		$ptoken  = strtolower( trim( (string) ( $q['public_token'] ?? '' ) ) );
		$plink   = ( '' !== $ptoken && class_exists( 'Estandar\\Flow\\Quotes\\Gateway' ) )
			? home_url( '/' . \Estandar\Flow\Quotes\Gateway::SLUG . '/' . rawurlencode( $ptoken ) )
			: '';
		?>
		<tr>
			<td><?php echo $qid; ?></td>
			<td><code><?php echo esc_html( (string) ( $q['number'] ?? '' ) ); ?></code></td>
			<td><?php echo esc_html( (string) ( $q['title'] ?? '' ) ); ?></td>
			<td>
				<?php if ( '' !== (string) ( $q['client_label'] ?? '' ) ) : ?>
					<?php echo esc_html( (string) $q['client_label'] ); ?>
				<?php elseif ( $qlead > 0 ) : ?>
					lead#<?php echo $qlead; ?>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) ( $q['items_summary'] ?? '—' ) ); ?> (<?php echo (int) ( $q['items_count'] ?? 0 ); ?>)</td>
			<td><?php echo esc_html( $total ); ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( $actions ); ?>" style="display:flex;gap:4px;align-items:center;">
					<?php wp_nonce_field( 'estandar_suite_flow_quote_status' ); ?>
					<input type="hidden" name="action" value="estandar_suite_flow_quote_status">
					<input type="hidden" name="quote_id" value="<?php echo $qid; ?>">
					<select name="status">
						<?php foreach ( \Estandar\Flow\Quotes::STATUSES as $st ) : ?>
							<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $st ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button button-small" type="submit">Cambiar</button>
				</form>
			</td>
			<td><?php echo ! empty( $q['sent_at'] ) ? esc_html( (string) $q['sent_at'] ) : '—'; ?></td>
			<td style="white-space:nowrap;">
				<a class="button button-small" href="<?php echo esc_url( $edit ); ?>">Editar</a>
				<form method="post" action="<?php echo esc_url( $actions ); ?>" style="display:inline">
					<?php wp_nonce_field( 'estandar_suite_flow_quote_send' ); ?>
					<input type="hidden" name="action" value="estandar_suite_flow_quote_send">
					<input type="hidden" name="quote_id" value="<?php echo $qid; ?>">
					<button class="button button-small" type="submit">Enviar</button>
				</form>
				<form method="post" action="<?php echo esc_url( $actions ); ?>" style="display:inline">
					<?php wp_nonce_field( 'estandar_suite_flow_quote_pdf' ); ?>
					<input type="hidden" name="action" value="estandar_suite_flow_quote_pdf">
					<input type="hidden" name="quote_id" value="<?php echo $qid; ?>">
					<button class="button button-small" type="submit">PDF</button>
				</form>
				<form method="post" action="<?php echo esc_url( $actions ); ?>" style="display:inline">
					<?php wp_nonce_field( 'estandar_suite_flow_quote_duplicate' ); ?>
					<input type="hidden" name="action" value="estandar_suite_flow_quote_duplicate">
					<input type="hidden" name="quote_id" value="<?php echo $qid; ?>">
					<button class="button button-small" type="submit">Duplicar</button>
				</form>
				<?php if ( '' !== $plink ) : ?>
					<input type="text" readonly value="<?php echo esc_attr( $plink ); ?>" onclick="this.select();" style="display:block;width:150px;font-size:11px;margin-top:3px;" title="Link público (clic para copiar)">
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2 ) ) : ?><tr><td colspan="9">Sin cotizaciones v2.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'cotizaciones' ) : ?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>UUID</th><th>Número</th><th>Proyecto</th><th>Lead</th><th>Perfil</th><th>Estado</th><th>Revisión</th><th>Actualizada</th></tr></thead>
	<tbody>
	<?php foreach ( $quotes as $q ) : ?>
		<tr>
			<td><?php echo (int) $q['id']; ?></td>
			<td><code><?php echo esc_html( $q['quote_uuid'] ); ?></code></td>
			<td><?php echo esc_html( $q['quote_number'] ); ?></td>
			<td><?php echo esc_html( $q['project_slug'] ); ?></td>
			<td><?php echo $q['lead_id'] ? (int) $q['lead_id'] : '—'; ?></td>
			<td><?php echo esc_html( $q['profile_key'] ); ?></td>
			<td><?php echo esc_html( $q['status'] ); ?></td>
			<td><?php echo (int) $q['current_revision_no']; ?></td>
			<td><?php echo esc_html( $q['updated_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $quotes ) ) : ?><tr><td colspan="9">Sin cotizaciones legadas.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'branding' ) : ?>
<?php if ( isset( $_GET['project_branding_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Branding por proyecto guardado.</p></div><?php endif; ?>
<?php $qgroups = \Estandar\Flow\Admin::quotes_settings_groups(); ?>
<?php if ( isset( $qgroups['branding'] ) ) : ?>
	<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_flow_save_quotes_settings', 'estandar_suite_flow_quotes_settings', $qgroups['branding'], '', array( 'group' => 'branding' ) ); ?>
<?php endif; ?>
<?php echo \Estandar\Flow\Admin::render_project_branding_editor(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<?php elseif ( $ss === 'templates' ) : ?>
<?php echo \Estandar\Flow\Admin::render_templates_editor(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<?php elseif ( in_array( $ss, array( 'documento', 'finanzas', 'sala_ventas', 'autoridad', 'email' ), true ) ) : ?>
<?php $qgroups = \Estandar\Flow\Admin::quotes_settings_groups(); ?>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_flow_save_quotes_settings', 'estandar_suite_flow_quotes_settings', $qgroups[ $ss ], '', array( 'group' => $ss ) ); ?>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_flow_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
