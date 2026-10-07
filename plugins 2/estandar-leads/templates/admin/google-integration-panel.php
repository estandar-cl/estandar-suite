<?php
/**
 * Panel Google Ads / Data Manager (read-only): checklist BLOCKED + diagnóstico
 * de outbox + visor de últimos jobs + reconciliación por request_id.
 *
 * Fuente ÚNICA de este markup. Consumido por:
 *   - Estandar_Leads_Google_Integration_Provider::render_extra() (pantalla Integraciones, core)
 *
 * Autocontenido: no espera variables del includer. Nunca imprime secretos,
 * PII ni payloads — el outbox no almacena ninguno.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Leads_Google_Adapter' ) ) {
	return;
}

$_gp_settings = Estandar_Leads_Google_Adapter::get_settings();
$_gp_missing  = Estandar_Leads_Google_Adapter::missing_external_config();

if ( ! empty( $_gp_missing ) ) : ?>
	<div style="margin:16px 0;padding:12px 16px;border:1px solid #f59e0b;border-radius:6px;background:#fffbeb;font-size:13px;">
		<strong style="color:#92400e;">BLOCKED — configuración externa pendiente</strong>
		<p style="margin:6px 0 4px;color:#78350f;">La integración está implementada hasta el límite seguro. Falta completar en Google:</p>
		<ul style="margin:4px 0 0 18px;list-style:disc;color:#78350f;">
			<?php foreach ( $_gp_missing as $_gp_item ) : ?>
				<li><?php echo wp_kses_post( $_gp_item ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif;

if ( ! Estandar_Leads_Google_Adapter::is_configured() ) {
	return; // Sin credenciales no hay diagnóstico que mostrar.
}

$_gp_dry     = Estandar_Leads_Google_Adapter::dry_run_enabled();
$_gp_qstats  = Estandar_Leads_Google_Adapter::queue_stats();
$_gp_qcounts = $_gp_qstats['counts'];
$_gp_logrows = Estandar_Leads_Google_Adapter::recent_log_rows( 20 );

$_gp_status_colors = array(
	'pending'   => '#6b7280',
	'validated' => '#1d4ed8',
	'completed' => '#166534',
	'retry'     => '#92400e',
	'dead'      => '#991b1b',
);
?>

<!-- ── Google Data Manager Diagnostics ────────────────────────────────────── -->
<div style="margin:24px 0 32px;">
	<?php if ( $_gp_dry ) : ?>
		<div style="margin:0 0 12px;padding:8px 14px;border:1px solid #bfdbfe;border-radius:6px;background:#eff6ff;font-size:12px;color:#1e40af;max-width:640px;">
			<strong>DRY-RUN activo</strong> — cada lote viaja con <code>validateOnly: true</code>: Google valida el payload pero
			<strong>no registra conversiones</strong>. Las filas quedan en <code>validated</code> y se envían de verdad al desactivar el modo.
		</div>
	<?php endif; ?>

	<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0;">
		<?php foreach ( array( 'pending', 'validated', 'retry', 'completed', 'dead' ) as $_gp_st ) : ?>
			<div style="padding:8px 14px;border:1px solid #e5e7eb;border-radius:6px;background:#f9f9f9;font-size:12px;">
				<span style="color:#6b7280;">Cola <?php echo esc_html( $_gp_st ); ?></span>
				<strong style="display:block;font-size:18px;color:<?php echo esc_attr( $_gp_status_colors[ $_gp_st ] ?? '#111' ); ?>;">
					<?php echo (int) ( $_gp_qcounts[ $_gp_st ] ?? 0 ); ?>
				</strong>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $_gp_qstats['last_attempt'] ) : ?>
		<p style="font-size:12px;color:#6b7280;margin:4px 0;">
			Último intento: <?php echo esc_html( $_gp_qstats['last_attempt'] ); ?> UTC
			<?php if ( $_gp_qstats['last_error'] ) : ?>
				· <span style="color:#991b1b;"><?php echo esc_html( $_gp_qstats['last_error'] ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $_gp_logrows ) ) : ?>
		<table class="widefat striped" style="margin-top:12px;max-width:980px;font-size:12px;">
			<thead>
				<tr>
					<th>Creado (UTC)</th>
					<th>transactionId</th>
					<th>Estado</th>
					<th>Intentos</th>
					<th>Próx. intento</th>
					<th>request_id</th>
					<th>Último error</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $_gp_logrows as $_gp_row ) : ?>
					<tr>
						<td><?php echo esc_html( $_gp_row['created_at'] ); ?></td>
						<td><code style="font-size:11px;"><?php echo esc_html( $_gp_row['transaction_id'] ); ?></code></td>
						<td>
							<strong style="color:<?php echo esc_attr( $_gp_status_colors[ $_gp_row['queue_status'] ] ?? '#111' ); ?>;">
								<?php echo esc_html( $_gp_row['queue_status'] ); ?>
							</strong>
						</td>
						<td><?php echo (int) $_gp_row['attempts']; ?></td>
						<td><?php echo esc_html( $_gp_row['next_attempt_at'] !== '' && $_gp_row['next_attempt_at'] !== '0000-00-00 00:00:00' ? $_gp_row['next_attempt_at'] : '—' ); ?></td>
						<td><code style="font-size:11px;"><?php echo esc_html( $_gp_row['dm_request_id'] !== '' ? $_gp_row['dm_request_id'] : '—' ); ?></code></td>
						<td style="color:#6b7280;"><?php echo esc_html( $_gp_row['last_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p style="font-size:13px;color:#6b7280;">Sin jobs en el outbox todavía — el primer lead que pase a QUALIFIED aparecerá aquí.</p>
	<?php endif; ?>

	<?php
	// Reconciliación read-only bajo demanda (GET + nonce + capability). Consulta
	// requestStatus:retrieve para los últimos envíos reales; nunca muta la cola.
	$_gp_can_reconcile = current_user_can( 'manage_options' );
	$_gp_do_reconcile  = $_gp_can_reconcile
		&& isset( $_GET['es_google_reconcile'], $_GET['_esgnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_esgnonce'] ) ), 'es_google_reconcile' );

	if ( $_gp_can_reconcile ) :
		$_gp_reconcile_url = wp_nonce_url( add_query_arg( 'es_google_reconcile', '1' ), 'es_google_reconcile', '_esgnonce' );
		?>
		<p style="margin-top:16px;">
			<a class="button button-secondary" style="font-size:13px;" href="<?php echo esc_url( $_gp_reconcile_url ); ?>">Reconciliar últimos envíos</a>
			<span style="font-size:12px;color:#6b7280;margin-left:8px;">Consulta <code>requestStatus:retrieve</code> para los últimos request_id completados.</span>
		</p>
	<?php endif;

	if ( $_gp_do_reconcile ) :
		$_gp_recon = Estandar_Leads_Google_Adapter::reconcile_recent( 3 );
		if ( empty( $_gp_recon ) ) : ?>
			<p style="font-size:13px;color:#6b7280;">No hay envíos reales completados con request_id para reconciliar (dry-run no genera envíos reconciliables).</p>
		<?php else : ?>
			<?php foreach ( $_gp_recon as $_gp_r ) : ?>
				<div style="margin:8px 0;padding:10px 14px;border:1px solid #e5e7eb;border-radius:6px;background:#f9f9f9;font-size:12px;max-width:980px;">
					<strong>request_id</strong> <code><?php echo esc_html( $_gp_r['request_id'] ); ?></code>
					· HTTP <?php echo (int) $_gp_r['http_code']; ?>
					· <span style="color:<?php echo $_gp_r['success'] ? '#166534' : '#991b1b'; ?>;font-weight:700;">
						<?php echo $_gp_r['success'] ? 'OK' : 'ERROR'; ?>
					</span>
					<?php if ( ! $_gp_r['success'] ) : ?>
						· <?php echo esc_html( $_gp_r['message'] ); ?>
					<?php endif; ?>
					<?php if ( $_gp_r['body'] !== '' ) : ?>
						<pre style="margin:8px 0 0;padding:8px;background:#fff;border:1px solid #e5e7eb;border-radius:4px;overflow:auto;max-height:220px;font-size:11px;"><?php echo esc_html( $_gp_r['body'] ); ?></pre>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif;
	endif; ?>
</div>
