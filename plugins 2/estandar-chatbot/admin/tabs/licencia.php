<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'licencia' ) ) {
	wp_die( 'No autorizado.' );
}

$license = get_option( 'estandar_chatbot_license', [
	'status' => 'free',
	'key'    => '',
	'source' => 'manual',
]);

$status = sanitize_key( (string)( $license['status'] ?? 'free' ) );
$key    = sanitize_text_field( (string)( $license['key'] ?? '' ) );
$is_pro = function_exists( 'estandar_chatbot_is_pro' ) ? estandar_chatbot_is_pro() : false;

if ( ! function_exists( 'estandar_chatbot_license_badge' ) ) {
	function estandar_chatbot_license_badge( $status ) {
		switch ( $status ) {
			case 'active':
				return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:700;font-size:14px;">PRO activa ✓</span>';
			case 'demo':
				return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:700;font-size:14px;">Demo activa</span>';
			case 'expired':
				return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:14px;">Licencia expirada</span>';
			default:
				return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:#e5e7eb;color:#374151;font-weight:700;font-size:14px;">Plan Free</span>';
		}
	}
}

// Mensajes contextuales por estado
$mensajes_estado = [
	'active'  => 'Tenés acceso a todas las funcionalidades PRO del plugin.',
	'demo'    => 'Estás en modo demo. Algunas funciones pueden estar limitadas.',
	'expired' => 'Tu licencia venció. Para renovarla, contactá a Estandar Studios.',
	'free'    => 'Estás usando el plan gratuito. Las funciones avanzadas requieren licencia PRO.',
];
$mensaje_estado = $mensajes_estado[ $status ] ?? '';
?>

<div>
	<h3 class="ecb-section-title">Licencia</h3>
	<p class="ecb-section-copy">Plan activo del plugin y clave de activación.</p>

	<div class="ecb-card" style="margin:12px 0;max-width:560px;">
		<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
			<div>
				<p style="margin:0 0 6px;font-size:13px;color:#646970;">Plan activo</p>
				<?php echo estandar_chatbot_license_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $mensaje_estado ) : ?>
			<p class="description" style="margin:0;flex:1;min-width:160px;"><?php echo esc_html( $mensaje_estado ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $status === 'free' || $status === 'expired' ) : ?>
	<details style="margin-top:4px;" <?php echo ( $status === 'expired' || $key !== '' ) ? 'open' : ''; ?>>
		<summary style="cursor:pointer;color:#2271b1;font-size:13px;padding:4px 0;">
			<?php echo $status === 'expired' ? 'Renovar licencia' : '¿Tenés una clave PRO? Ingresala aquí'; ?>
		</summary>
		<div class="ecb-card" style="margin-top:8px;max-width:560px;">
			<form method="post" action="options.php">
				<?php settings_fields( 'estandar_chatbot_license' ); ?>
				<input type="hidden" name="estandar_chatbot_license[status]" value="active" />
				<label style="display:block;margin-bottom:6px;font-weight:600;">Clave de licencia</label>
				<input
					type="text"
					name="estandar_chatbot_license[key]"
					value="<?php echo esc_attr( $key ); ?>"
					class="regular-text"
					placeholder="ESTANDAR-XXXXX-XXXXX"
				/>
				<p class="description" style="margin:4px 0 12px;">Ingresá la clave que recibiste al adquirir el plan PRO.</p>
				<?php submit_button( 'Activar licencia', 'primary', 'submit', false ); ?>
			</form>
		</div>
	</details>
	<?php else : ?>
	<details style="margin-top:4px;">
		<summary style="cursor:pointer;color:#2271b1;font-size:13px;padding:4px 0;">Cambiar clave o estado de licencia</summary>
		<div class="ecb-card" style="margin-top:8px;max-width:560px;">
			<form method="post" action="options.php">
				<?php settings_fields( 'estandar_chatbot_license' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Clave de licencia</th>
							<td>
								<input
									type="text"
									name="estandar_chatbot_license[key]"
									value="<?php echo esc_attr( $key ); ?>"
									class="regular-text"
									placeholder="ESTANDAR-XXXXX-XXXXX"
								/>
							</td>
						</tr>
						<tr>
							<th scope="row">Estado</th>
							<td>
								<select name="estandar_chatbot_license[status]">
									<option value="free"    <?php selected( $status, 'free' ); ?>>Plan Free</option>
									<option value="demo"    <?php selected( $status, 'demo' ); ?>>Demo</option>
									<option value="active"  <?php selected( $status, 'active' ); ?>>PRO activa</option>
									<option value="expired" <?php selected( $status, 'expired' ); ?>>Expirada</option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( 'Guardar cambios', 'secondary', 'submit', false ); ?>
			</form>
		</div>
	</details>
	<?php endif; ?>
</div>
