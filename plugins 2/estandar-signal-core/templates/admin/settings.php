<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client_id       = (string) get_option( 'estandar_signal_core_client_id', '' );

// Meta CAPI
$meta_pixel_id   = (string) get_option( 'estandar_signal_core_meta_pixel_id', '' );
$meta_token_set  = get_option( 'estandar_signal_core_meta_access_token', '' ) !== '';
$meta_test_code  = (string) get_option( 'estandar_signal_core_meta_test_event_code', '' );
$meta_active     = $meta_pixel_id !== '' && $meta_token_set;

// Google Ads OCI
$g_developer_token     = (string) get_option( 'estandar_signal_core_google_developer_token', '' );
$g_client_id           = (string) get_option( 'estandar_signal_core_google_client_id', '' );
$g_client_secret_set   = get_option( 'estandar_signal_core_google_client_secret', '' ) !== '';
$g_refresh_token_set   = get_option( 'estandar_signal_core_google_refresh_token', '' ) !== '';
$g_customer_id         = (string) get_option( 'estandar_signal_core_google_customer_id', '' );
$g_conv_lead           = (string) get_option( 'estandar_signal_core_google_conv_lead_created', '' );
$g_conv_meeting        = (string) get_option( 'estandar_signal_core_google_conv_meeting_booked', '' );
$g_conv_sale           = (string) get_option( 'estandar_signal_core_google_conv_sale_closed', '' );
$google_active         = $g_customer_id !== '' && $g_refresh_token_set && $g_developer_token !== '';
?>
<div class="wrap">
	<h1>Signal Core — Configuración</h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'estandar_signal_core_save_settings' ); ?>
		<input type="hidden" name="action" value="estandar_signal_core_save_settings">

		<!-- ── Identidad del sitio ──────────────────────────────────────────── -->
		<h2 style="margin-top:24px;">Identidad del sitio</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="signal_client_id">Client ID</label></th>
				<td>
					<input type="text"
					       id="signal_client_id"
					       name="signal_client_id"
					       value="<?php echo esc_attr( $client_id ); ?>"
					       class="regular-text"
					       placeholder="ej: akcontadores-cl">
					<p class="description">
						Identificador estable de este sitio en el ledger de señales.
						Se genera automáticamente al activar el plugin — solo cambia si migraste de dominio.
					</p>
				</td>
			</tr>
		</table>

		<!-- ── Meta Conversions API ─────────────────────────────────────────── -->
		<h2 style="margin-top:32px;">
			Meta Conversions API (CAPI)
			<?php if ( $meta_active ) : ?>
				<span style="font-size:13px;font-weight:400;color:#00a32a;margin-left:8px;">● Activo</span>
			<?php else : ?>
				<span style="font-size:13px;font-weight:400;color:#999;margin-left:8px;">○ Inactivo</span>
			<?php endif; ?>
		</h2>

		<p style="color:#555;max-width:600px;">
			Envía eventos comerciales directamente a Meta desde el servidor.
			Requiere un <strong>Pixel ID</strong> y un <strong>System User Access Token</strong>
			generado en Meta Business Manager → Configuración → Usuarios del sistema.
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="meta_pixel_id">Pixel ID</label></th>
				<td>
					<input type="text"
					       id="meta_pixel_id"
					       name="meta_pixel_id"
					       value="<?php echo esc_attr( $meta_pixel_id ); ?>"
					       class="regular-text"
					       placeholder="Ej: 1234567890123456">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="meta_access_token">Access Token</label></th>
				<td>
					<input type="password"
					       id="meta_access_token"
					       name="meta_access_token"
					       value=""
					       class="regular-text"
					       autocomplete="new-password"
					       placeholder="<?php echo $meta_token_set ? 'Token guardado — dejar en blanco para no cambiar' : 'Pegar token aquí'; ?>">
					<?php if ( $meta_token_set ) : ?>
						<p class="description" style="color:#00a32a;">✓ Token configurado.</p>
					<?php else : ?>
						<p class="description">Token de sistema (larga duración). No usar token personal.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="meta_test_event_code">Test Event Code</label></th>
				<td>
					<input type="text"
					       id="meta_test_event_code"
					       name="meta_test_event_code"
					       value="<?php echo esc_attr( $meta_test_code ); ?>"
					       class="regular-text"
					       placeholder="TEST12345">
					<p class="description">
						Opcional. Código de la herramienta de prueba en Meta Events Manager.
						<strong>Borrar en producción.</strong>
					</p>
				</td>
			</tr>
		</table>

		<!-- ── Eventos exportados ───────────────────────────────────────────── -->
		<?php if ( $meta_active ) : ?>
		<h3 style="margin-top:24px;">Eventos que se envían a Meta</h3>
		<table class="widefat striped" style="max-width:480px;">
			<thead>
				<tr><th>Evento interno</th><th>Evento Meta</th></tr>
			</thead>
			<tbody>
				<tr><td><code>lead_created</code></td><td>Lead</td></tr>
				<tr><td><code>meeting_booked</code></td><td>Schedule</td></tr>
				<tr><td><code>sale_closed</code></td><td>Purchase</td></tr>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- ── Google Ads OCI ───────────────────────────────────────────────── -->
		<h2 style="margin-top:32px;">
			Google Ads Offline Conversion Import
			<?php if ( $google_active ) : ?>
				<span style="font-size:13px;font-weight:400;color:#00a32a;margin-left:8px;">● Activo</span>
			<?php else : ?>
				<span style="font-size:13px;font-weight:400;color:#999;margin-left:8px;">○ Inactivo</span>
			<?php endif; ?>
		</h2>

		<p style="color:#555;max-width:620px;">
			Reporta conversiones offline a Google Ads cuando hay un <code>gclid</code> en el lead.
			Requiere credenciales OAuth2. Para obtener el <strong>Refresh Token</strong>:
			usa <a href="https://developers.google.com/oauthplayground" target="_blank" rel="noopener">OAuth 2.0 Playground</a>
			con el scope <code>https://www.googleapis.com/auth/adwords</code> y tus credenciales de app.
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="google_developer_token">Developer Token</label></th>
				<td>
					<input type="text"
					       id="google_developer_token"
					       name="google_developer_token"
					       value="<?php echo esc_attr( $g_developer_token ); ?>"
					       class="regular-text"
					       placeholder="Google Ads API Center → Developer Token">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_customer_id">Customer ID</label></th>
				<td>
					<input type="text"
					       id="google_customer_id"
					       name="google_customer_id"
					       value="<?php echo esc_attr( $g_customer_id ); ?>"
					       class="regular-text"
					       placeholder="Ej: 1234567890 (sin guiones)">
					<p class="description">ID de la cuenta Google Ads (no la cuenta de administrador).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_client_id">OAuth2 Client ID</label></th>
				<td>
					<input type="text"
					       id="google_client_id"
					       name="google_client_id"
					       value="<?php echo esc_attr( $g_client_id ); ?>"
					       class="regular-text"
					       placeholder="Google Cloud Console → Credentials → OAuth 2.0 Client ID">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_client_secret">OAuth2 Client Secret</label></th>
				<td>
					<input type="password"
					       id="google_client_secret"
					       name="google_client_secret"
					       value=""
					       class="regular-text"
					       autocomplete="new-password"
					       placeholder="<?php echo $g_client_secret_set ? 'Client secret guardado — dejar en blanco para no cambiar' : 'Pegar client secret'; ?>">
					<?php if ( $g_client_secret_set ) : ?>
						<p class="description" style="color:#00a32a;">✓ Client secret configurado.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_refresh_token">Refresh Token</label></th>
				<td>
					<input type="password"
					       id="google_refresh_token"
					       name="google_refresh_token"
					       value=""
					       class="regular-text"
					       autocomplete="new-password"
					       placeholder="<?php echo $g_refresh_token_set ? 'Refresh token guardado — dejar en blanco para no cambiar' : 'Generar en OAuth Playground'; ?>">
					<?php if ( $g_refresh_token_set ) : ?>
						<p class="description" style="color:#00a32a;">✓ Refresh token configurado.</p>
					<?php else : ?>
						<p class="description">Token de larga duración. Genera uno en <a href="https://developers.google.com/oauthplayground" target="_blank" rel="noopener">OAuth Playground</a>.</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h3 style="margin-top:20px;">Conversion Actions</h3>
		<p style="color:#555;max-width:620px;">
			Resource name de la conversion action en Google Ads.
			Formato: <code>customers/{id}/conversionActions/{id}</code>.
			Dejar en blanco para no exportar ese evento.
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="google_conv_lead_created">lead_created</label></th>
				<td>
					<input type="text"
					       id="google_conv_lead_created"
					       name="google_conv_lead_created"
					       value="<?php echo esc_attr( $g_conv_lead ); ?>"
					       class="large-text"
					       placeholder="customers/1234567890/conversionActions/987654321">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_conv_meeting_booked">meeting_booked</label></th>
				<td>
					<input type="text"
					       id="google_conv_meeting_booked"
					       name="google_conv_meeting_booked"
					       value="<?php echo esc_attr( $g_conv_meeting ); ?>"
					       class="large-text"
					       placeholder="customers/1234567890/conversionActions/987654322">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_conv_sale_closed">sale_closed</label></th>
				<td>
					<input type="text"
					       id="google_conv_sale_closed"
					       name="google_conv_sale_closed"
					       value="<?php echo esc_attr( $g_conv_sale ); ?>"
					       class="large-text"
					       placeholder="customers/1234567890/conversionActions/987654323">
				</td>
			</tr>
		</table>

		<?php submit_button( 'Guardar configuración' ); ?>
	</form>
</div>
