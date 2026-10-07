<?php
/**
 * Google Ads (Data Manager) — proveedor del Integration Registry (Estandar Core).
 *
 * Adaptador fino: expone la integración Google existente (Adapter/Builder/
 * DM Client) bajo el contrato común Estandar_Integration_Provider. NO contiene
 * lógica de negocio propia: configuración y estado delegan íntegramente en
 * Estandar_Leads_Google_Adapter (los option names siguen encapsulados allí).
 *
 * ESTE ARCHIVO SOLO SE CARGA cuando el core (Integration Registry) está
 * presente — ver el hook 'estandar_integrations_register' en estandar-leads.php.
 * Nunca hacer require incondicional: extiende una clase del core opcional.
 *
 * @package EstandarLeads
 * @since   13.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Integration_Provider' ) ) {
	return; // Core ausente — el registro simplemente no ocurre (CORE_OPTIONALITY).
}

class Estandar_Leads_Google_Integration_Provider extends Estandar_Integration_Provider {

	public function id(): string {
		return 'google_ads';
	}

	public function label(): string {
		return 'Google Ads';
	}

	public function description(): string {
		return 'Conversiones offline "Lead Calificado" vía Data Manager API — outbox durable, retries con backoff, dry-run (validateOnly) y reconciliación por request_id.';
	}

	public function get_settings(): array {
		return Estandar_Leads_Google_Adapter::get_settings();
	}

	public function save_settings( array $post ): void {
		Estandar_Leads_Google_Adapter::save_settings( $post );
	}

	/**
	 * Schema declarativo render-ready. Los names 'google_*' son el contrato de
	 * POST que Estandar_Leads_Google_Adapter::save_settings() espera.
	 */
	public function settings_fields(): array {
		$s = $this->get_settings();

		return array(
			array(
				'name'           => 'google_enabled',
				'label'          => 'Activar Google Ads',
				'type'           => 'checkbox',
				'checked'        => $s['enabled'] === '1',
				'checkbox_label' => 'Interruptor maestro — apagado por defecto; apagarlo no borra la configuración',
			),
			array(
				'name'        => 'google_customer_id',
				'label'       => 'Customer ID (CID)',
				'type'        => 'text',
				'value'       => $s['customer_id'],
				'placeholder' => '107-149-8042',
				'description' => 'ID de la cuenta Google Ads. Los guiones se eliminan al guardar.',
			),
			array(
				'name'        => 'google_login_customer_id',
				'label'       => 'Login Customer ID (MCC)',
				'type'        => 'text',
				'value'       => $s['login_customer_id'],
				'placeholder' => 'Opcional',
				'description' => 'Solo si el acceso pasa por una cuenta administradora (MCC).',
			),
			array(
				'name'        => 'google_conversion_action_id',
				'label'       => 'Conversion Action ID',
				'type'        => 'text',
				'value'       => $s['conversion_action_id'],
				'placeholder' => '123456789',
				'description' => 'ID de la acción de conversión <strong>"Lead Calificado"</strong> en Google Ads. Debe existir con fuente <em>Sitio web (Importar desde clics)</em>.',
			),
			array(
				'name'        => 'google_oauth_client_id',
				'label'       => 'OAuth Client ID',
				'type'        => 'text',
				'value'       => $s['oauth_client_id'],
				'placeholder' => 'xxxxx.apps.googleusercontent.com',
				'description' => 'Credencial OAuth de Google Cloud Console con la Data Manager API habilitada.',
			),
			array(
				'name'        => 'google_oauth_client_secret',
				'label'       => 'OAuth Client Secret',
				'type'        => 'password',
				'value'       => '',
				'placeholder' => $s['oauth_client_secret'] !== '' ? '•••••••• (secreto guardado)' : '',
				'description' => $s['oauth_client_secret'] !== ''
					? 'Hay un secreto guardado. Dejar vacío para <strong>conservarlo</strong>; pegar uno nuevo para rotarlo.'
					: 'Client Secret del OAuth Client.',
			),
			array(
				'name'        => 'google_oauth_refresh_token',
				'label'       => 'Refresh Token',
				'type'        => 'password',
				'value'       => '',
				'placeholder' => $s['oauth_refresh_token'] !== '' ? '•••••••• (token guardado)' : '',
				'description' => $s['oauth_refresh_token'] !== ''
					? 'Hay un refresh token guardado. Dejar vacío para <strong>conservarlo</strong>; pegar uno nuevo para rotarlo.'
					: 'Refresh token con scope <code>https://www.googleapis.com/auth/datamanager</code>.',
			),
			array(
				'name'           => 'google_dry_run',
				'label'          => 'Dry-run (validateOnly)',
				'type'           => 'checkbox',
				'checked'        => $s['dry_run'] !== '0',
				'checkbox_label' => 'Google valida los payloads pero NO registra conversiones (activado por defecto)',
				'description'    => 'Las filas validadas quedan en <code>validated</code> y se envían de verdad al desactivar este modo.',
			),
			array(
				'name'           => 'google_test_mode',
				'label'          => 'Modo test',
				'type'           => 'checkbox',
				'checked'        => $s['test_mode'] === '1',
				'checkbox_label' => 'Encolar y loguear sin tocar la red (ni siquiera validateOnly)',
				'description'    => 'La cola queda en <code>pending</code> y se despacha al desactivar el modo test.',
			),
			array(
				'name'           => 'google_debug_logging',
				'label'          => 'Debug Logging',
				'type'           => 'checkbox',
				'checked'        => $s['debug_logging'] === '1',
				'checkbox_label' => 'Registrar cada lote en Telemetría (request_id, HTTP, duración — sin PII ni tokens)',
			),
		);
	}

	public function status(): array {
		$configured = Estandar_Leads_Google_Adapter::is_configured();
		$enabled    = Estandar_Leads_Google_Adapter::is_enabled();
		$dry_run    = Estandar_Leads_Google_Adapter::dry_run_enabled();
		$s          = $this->get_settings();

		$parts = array( 'Server ' . ( ( $enabled && $configured ) ? '✓' : '—' ) );
		if ( $dry_run ) {
			$parts[] = 'DRY-RUN';
		}
		if ( $s['test_mode'] === '1' ) {
			$parts[] = 'MODO TEST';
		}
		$queue   = Estandar_Leads_Google_Adapter::queue_stats();
		$parts[] = 'cola pending: ' . (int) ( $queue['counts']['pending'] ?? 0 );

		return array(
			'configured' => $configured,
			'active'     => $enabled && $configured,
			'summary'    => implode( ' · ', $parts ),
		);
	}

	/**
	 * Panel propio: checklist BLOCKED + diagnóstico de cola + visor de outbox
	 * + reconciliación read-only. Markup en el partial compartido.
	 */
	public function render_extra(): void {
		$panel = ESTANDAR_LEADS_PATH . 'templates/admin/google-integration-panel.php';
		if ( file_exists( $panel ) ) {
			include $panel;
		}
	}
}
