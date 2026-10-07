<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'integraciones' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

$webhook_enabled   = (int) get_option( 'chatbot_webhook_enabled', 0 );
$webhook_url       = get_option( 'chatbot_webhook_url', '' );
$webhook_provider  = get_option( 'chatbot_webhook_provider', 'n8n' );
$webhook_secret    = get_option( 'chatbot_webhook_secret', '' );
$crm_enabled       = (int) get_option( 'chatbot_crm_enabled', 0 );
$crm_url           = get_option( 'chatbot_crm_url', '' );
$crm_provider      = get_option( 'chatbot_crm_provider', 'generic' );
$crm_secret        = get_option( 'chatbot_crm_secret', '' );
$notify_on         = (int) get_option( 'chatbot_notify_email_enabled', 1 );
$notification_mode = sanitize_key( (string) get_option( 'chatbot_notification_mode', 'legacy' ) );
$notify_to         = get_option( 'chatbot_notify_email_to', get_option( 'admin_email', '' ) );
$notify_subj       = get_option( 'chatbot_notify_email_subject', __( 'Nuevo lead desde chatbot', 'estandar-chatbot' ) );
$notify_template   = (string) get_option( 'chatbot_notify_email_template', '' );
$notify_signature  = (int) get_option( 'chatbot_notify_email_include_signature', 1 );
$whatsapp_enabled  = (int) get_option( 'chatbot_whatsapp_enabled', 0 );
$whatsapp_phone_number_id = get_option( 'chatbot_whatsapp_phone_number_id', '' );
$whatsapp_access_token   = get_option( 'chatbot_whatsapp_access_token', '' );
$whatsapp_verify_token   = get_option( 'chatbot_whatsapp_verify_token', '' );
$whatsapp_business_account_id = get_option( 'chatbot_whatsapp_business_account_id', '' );
$whatsapp_app_secret     = get_option( 'chatbot_whatsapp_app_secret', '' );
$whatsapp_webhook_url    = rest_url( 'estandar-chatbot/v1/whatsapp/webhook' );
$logs_retention_days    = (int) get_option( 'chatbot_logs_retention_days', 0 );
$email_template_default = function_exists( 'estandar_chatbot_get_default_lead_email_template' )
	? estandar_chatbot_get_default_lead_email_template()
	: '';
$email_signature_default = function_exists( 'estandar_chatbot_get_default_email_signature_template' )
	? estandar_chatbot_get_default_email_signature_template()
	: '';
$email_template_preview = function_exists( 'estandar_chatbot_render_lead_email_template' )
	? estandar_chatbot_render_lead_email_template(
		$notify_template !== '' ? $notify_template : $email_template_default,
		[
			'nombre' => 'Rodrigo',
			'email' => 'rodrigo@email.com',
			'telefono' => '984291014',
			'mensaje' => 'Quiero que me contacten para cotizar',
			'intent' => 'Quiere cotizar un servicio',
			'origen' => 'Página de servicios',
		]
	)
	: '';
if ( $notify_signature && $email_signature_default !== '' ) {
	$email_template_preview .= $email_signature_default;
}
$integration_summary = [
	[
		'label' => __( 'Canal humano', 'estandar-chatbot' ),
		'value' => $whatsapp_enabled ? __( 'WhatsApp activo', 'estandar-chatbot' ) : __( 'Sin canal activo', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Email', 'estandar-chatbot' ),
		'value' => $notify_on ? __( 'Notificaciones activas', 'estandar-chatbot' ) : __( 'Desactivado', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Webhook', 'estandar-chatbot' ),
		'value' => $webhook_enabled ? __( 'Activo', 'estandar-chatbot' ) : __( 'Inactivo', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'CRM', 'estandar-chatbot' ),
		'value' => $crm_enabled ? __( 'Activo', 'estandar-chatbot' ) : __( 'Inactivo', 'estandar-chatbot' ),
	],
];
?>
<form method="post" action="options.php">
	<?php settings_fields( 'estandar_chatbot_settings' ); ?>

	<div class="ecb-admin-surface" style="margin-bottom:16px;">
		<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Ruta del lead', 'estandar-chatbot' ); ?></h2>
		<p class="ecb-section-copy" style="margin:0 0 16px;"><?php esc_html_e( 'Define qué ocurre después del contacto: notificación interna, derivación a WhatsApp o envío a sistemas externos.', 'estandar-chatbot' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
			<?php foreach ( $integration_summary as $item ) : ?>
				<div style="padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;">
					<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $item['label'] ); ?></div>
					<div style="margin-top:6px;font-weight:600;color:#111827;"><?php echo esc_html( $item['value'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<h3 class="ecb-section-title">Después del contacto</h3>
	<p class="ecb-section-copy">Define cómo se mueve el lead una vez capturado: notificaciones, envío externo, CRM y canales adicionales.</p>

	<div class="ecb-integration-list">
		<div class="ecb-integration-card" id="ecb-email-delivery">
			<div class="ecb-integration-card__head">
				<div>
					<h4 style="margin:0 0 4px;">Webhook</h4>
					<p class="description" style="margin:0;">Envía leads captados a n8n, Zapier, Make u otro endpoint.</p>
				</div>
				<label class="ecb-switch-line">
					<input type="hidden" name="chatbot_webhook_enabled" value="0" />
					<input type="checkbox" name="chatbot_webhook_enabled" value="1" <?php checked( $webhook_enabled, 1 ); ?> />
					<span class="ecb-switch-state <?php echo $webhook_enabled ? 'is-on' : ''; ?>"><?php echo $webhook_enabled ? 'Activo' : 'Inactivo'; ?></span>
				</label>
			</div>
			<details <?php echo $webhook_enabled ? 'open' : ''; ?>>
				<summary>Avanzado</summary>
				<p style="margin-top:8px;"><label>Webhook URL</label><input type="url" name="chatbot_webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text" /></p>
				<p><label>Proveedor</label>
					<select name="chatbot_webhook_provider">
						<option value="n8n" <?php selected( $webhook_provider, 'n8n' ); ?>>n8n</option>
						<option value="zapier" <?php selected( $webhook_provider, 'zapier' ); ?>>Zapier</option>
						<option value="make" <?php selected( $webhook_provider, 'make' ); ?>>Make</option>
						<option value="custom" <?php selected( $webhook_provider, 'custom' ); ?>>Custom</option>
					</select>
				</p>
				<p><label>Secret (opcional)</label><input type="password" name="chatbot_webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" class="regular-text" autocomplete="off" /></p>
			</details>
		</div>

		<div class="ecb-integration-card">
			<div class="ecb-integration-card__head">
				<div>
					<h4 style="margin:0 0 4px;">CRM</h4>
					<p class="description" style="margin:0;">Sincroniza leads con tu CRM externo.</p>
				</div>
				<label class="ecb-switch-line">
					<input type="hidden" name="chatbot_crm_enabled" value="0" />
					<input type="checkbox" name="chatbot_crm_enabled" value="1" <?php checked( $crm_enabled, 1 ); ?> />
					<span class="ecb-switch-state <?php echo $crm_enabled ? 'is-on' : ''; ?>"><?php echo $crm_enabled ? 'Activo' : 'Inactivo'; ?></span>
				</label>
			</div>
			<details <?php echo $crm_enabled ? 'open' : ''; ?>>
				<summary>Avanzado</summary>
				<p style="margin-top:8px;"><label>CRM URL</label><input type="url" name="chatbot_crm_url" value="<?php echo esc_attr( $crm_url ); ?>" class="regular-text" /></p>
				<p><label>Proveedor</label>
					<select name="chatbot_crm_provider">
						<option value="generic" <?php selected( $crm_provider, 'generic' ); ?>>Generic bridge</option>
						<option value="hubspot" <?php selected( $crm_provider, 'hubspot' ); ?>>HubSpot</option>
						<option value="salesforce" <?php selected( $crm_provider, 'salesforce' ); ?>>Salesforce</option>
						<option value="zoho" <?php selected( $crm_provider, 'zoho' ); ?>>Zoho</option>
						<option value="custom" <?php selected( $crm_provider, 'custom' ); ?>>Custom</option>
					</select>
				</p>
				<p><label>CRM secret (opcional)</label><input type="password" name="chatbot_crm_secret" value="<?php echo esc_attr( $crm_secret ); ?>" class="regular-text" autocomplete="off" /></p>
			</details>
		</div>

		<div class="ecb-integration-card">
			<div class="ecb-integration-card__head">
				<div>
					<h4 style="margin:0 0 4px;">Email</h4>
					<p class="description" style="margin:0;">Recibe notificaciones cuando entra o cambia un lead.</p>
				</div>
				<label class="ecb-switch-line">
					<input type="hidden" name="chatbot_notify_email_enabled" value="0" />
					<input type="checkbox" name="chatbot_notify_email_enabled" value="1" <?php checked( $notify_on, 1 ); ?> />
					<span class="ecb-switch-state <?php echo $notify_on ? 'is-on' : ''; ?>"><?php echo $notify_on ? 'Activo' : 'Inactivo'; ?></span>
				</label>
			</div>
				<details <?php echo $notify_on ? 'open' : ''; ?>>
					<summary>Avanzado</summary>
					<p style="margin-top:8px;"><label>Modo de notificación</label>
						<select name="chatbot_notification_mode">
							<option value="legacy" <?php selected( $notification_mode, 'legacy' ); ?>>Chatbot legacy</option>
							<option value="leads" <?php selected( $notification_mode, 'leads' ); ?>>Leads</option>
						</select>
					</p>
					<p class="description" style="margin-top:-4px;">En modo Leads, el chatbot web captura y entrega el lead a Estandar Leads sin enviar email propio.</p>
					<p style="margin-top:8px;"><label>Destinatarios</label><input type="text" name="chatbot_notify_email_to" value="<?php echo esc_attr( $notify_to ); ?>" class="regular-text" /></p>
				<p class="description" style="margin-top:-4px;">Uno o más emails separados por coma.</p>
				<p><label>Asunto</label><input type="text" name="chatbot_notify_email_subject" value="<?php echo esc_attr( $notify_subj ); ?>" class="regular-text" /></p>
				<p><label>Template del correo</label></p>
				<textarea name="chatbot_notify_email_template" rows="10" class="large-text code" placeholder="<?php echo esc_attr( $email_template_default ); ?>"><?php echo esc_textarea( $notify_template ); ?></textarea>
				<p class="description">Variables disponibles: <code>{{nombre}}</code>, <code>{{email}}</code>, <code>{{telefono}}</code>, <code>{{mensaje}}</code>, <code>{{intent}}</code>, <code>{{origen}}</code>.</p>
				<p class="description">HTML permitido: <code>&lt;p&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;a&gt;</code>, <code>&lt;img&gt;</code>.</p>
				<p>
					<label>
						<input type="hidden" name="chatbot_notify_email_include_signature" value="0" />
						<input type="checkbox" name="chatbot_notify_email_include_signature" value="1" <?php checked( $notify_signature, 1 ); ?> />
						Incluir firma Estandar Studio
					</label>
				</p>
				<div class="ecb-card" style="margin-top:12px;">
					<p class="ecb-metric"><strong>Vista previa del correo</strong></p>
					<div style="margin-top:10px;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff;">
						<?php echo wp_kses( $email_template_preview, function_exists( 'estandar_chatbot_get_allowed_email_template_tags' ) ? estandar_chatbot_get_allowed_email_template_tags() : [] ); ?>
					</div>
				</div>
			</details>
		</div>

		<div class="ecb-integration-card">
			<div class="ecb-integration-card__head">
				<div>
					<h4 style="margin:0 0 4px;">WhatsApp</h4>
					<p class="description" style="margin:0;">Activa el canal de Meta Cloud API para recibir y responder mensajes desde WhatsApp.</p>
				</div>
				<label class="ecb-switch-line">
					<input type="hidden" name="chatbot_whatsapp_enabled" value="0" />
					<input type="checkbox" name="chatbot_whatsapp_enabled" value="1" <?php checked( $whatsapp_enabled, 1 ); ?> />
					<span class="ecb-switch-state <?php echo $whatsapp_enabled ? 'is-on' : ''; ?>"><?php echo $whatsapp_enabled ? 'Activo' : 'Inactivo'; ?></span>
				</label>
			</div>
			<details <?php echo $whatsapp_enabled ? 'open' : ''; ?>>
				<summary>Avanzado</summary>
				<p style="margin-top:8px;"><label>Webhook URL (Meta)</label><input type="text" readonly value="<?php echo esc_attr( $whatsapp_webhook_url ); ?>" class="regular-text" /></p>
				<p><label>Verify Token</label><input type="text" name="chatbot_whatsapp_verify_token" value="<?php echo esc_attr( $whatsapp_verify_token ); ?>" class="regular-text" /></p>
				<p><label>Phone Number ID</label><input type="text" name="chatbot_whatsapp_phone_number_id" value="<?php echo esc_attr( $whatsapp_phone_number_id ); ?>" class="regular-text" /></p>
				<p><label>Access Token</label><input type="password" name="chatbot_whatsapp_access_token" value="<?php echo esc_attr( $whatsapp_access_token ); ?>" class="regular-text" autocomplete="off" /></p>
				<p><label>Business Account ID (opcional)</label><input type="text" name="chatbot_whatsapp_business_account_id" value="<?php echo esc_attr( $whatsapp_business_account_id ); ?>" class="regular-text" /></p>
				<p><label>App Secret (opcional)</label><input type="password" name="chatbot_whatsapp_app_secret" value="<?php echo esc_attr( $whatsapp_app_secret ); ?>" class="regular-text" autocomplete="off" /></p>
			</details>
		</div>

		<div class="ecb-integration-card">
			<div class="ecb-integration-card__head">
				<div>
					<h4 style="margin:0 0 4px;">Operación</h4>
					<p class="description" style="margin:0;">Ajustes globales que afectan limpieza de datos y operación técnica.</p>
				</div>
			</div>
			<p><label>Retención de logs (días)</label><input type="number" min="0" step="1" name="chatbot_logs_retention_days" value="<?php echo esc_attr( (string) $logs_retention_days ); ?>" class="small-text" /></p>
			<p class="description">0 = no borrar automáticamente. Para limpieza inmediata usa Herramientas.</p>
			<details>
				<summary>Otros canales (avanzado)</summary>
				<p class="description" style="margin-top:8px;"><?php esc_html_e( 'El núcleo ya separa conversaciones y leads por canal. WhatsApp está implementado vía Cloud API. Para Telegram u otros messengers: usa los filtros de WordPress estandar_chatbot_allowed_channels y estandar_chatbot_channel_labels, registra un webhook REST y reutiliza las mismas funciones de conversación y scoring que el canal web/WhatsApp.', 'estandar-chatbot' ); ?></p>
			</details>
		</div>
	</div>

	<?php submit_button( __( 'Guardar integraciones', 'estandar-chatbot' ) ); ?>
</form>
