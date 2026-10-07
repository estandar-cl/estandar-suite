<?php
/**
 * Meta — proveedor del Integration Registry (Estandar Core).
 *
 * Adaptador fino: expone la integración Meta existente (Adapter/Builder/Client/
 * Pixel) bajo el contrato común Estandar_Integration_Provider. NO contiene
 * lógica de negocio propia: configuración y estado delegan íntegramente en
 * Estandar_Leads_Meta_Adapter (los option names siguen encapsulados allí).
 *
 * ESTE ARCHIVO SOLO SE CARGA cuando el core (Integration Registry) está
 * presente — ver el hook 'estandar_integrations_register' en estandar-leads.php.
 * Nunca hacer require incondicional: extiende una clase del core opcional.
 *
 * @package EstandarLeads
 * @since   13.0.48
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Integration_Provider' ) ) {
	return; // Core ausente — el registro simplemente no ocurre (CORE_OPTIONALITY).
}

class Estandar_Leads_Meta_Integration_Provider extends Estandar_Integration_Provider {

	public function id(): string {
		return 'meta';
	}

	public function label(): string {
		return 'Meta';
	}

	public function description(): string {
		return 'Browser Pixel + Conversions API (CAPI) con cola, retries y Purchase Backfill.';
	}

	public function get_settings(): array {
		return Estandar_Leads_Meta_Adapter::get_settings();
	}

	public function save_settings( array $post ): void {
		Estandar_Leads_Meta_Adapter::save_settings( $post );
	}

	/**
	 * Schema declarativo render-ready. Los names 'meta_*' son el contrato de
	 * POST que Estandar_Leads_Meta_Adapter::save_settings() ya espera —
	 * idénticos al formulario legacy (regla: name= attributes no cambian).
	 */
	public function settings_fields(): array {
		$s = $this->get_settings();

		return array(
			array(
				'name'           => 'meta_enabled',
				'label'          => 'Activar Meta',
				'type'           => 'checkbox',
				'checked'        => $s['enabled'] !== '0',
				'checkbox_label' => 'Interruptor maestro — apaga Browser y Server Events sin borrar la configuración',
			),
			array(
				'name'        => 'meta_pixel_id',
				'label'       => 'Pixel ID',
				'type'        => 'text',
				'value'       => $s['pixel_id'],
				'placeholder' => '123456789012345',
				'description' => 'Dataset ID de Meta Events Manager. Visible en la URL al editar el Pixel.',
			),
			array(
				'name'        => 'meta_access_token',
				'label'       => 'Access Token',
				'type'        => 'password',
				// El secreto nunca viaja al DOM: campo vacío = conservar el guardado.
				'value'       => '',
				'placeholder' => $s['access_token'] !== '' ? '•••••••• (token guardado)' : '',
				'description' => $s['access_token'] !== ''
					? 'Hay un token guardado. Dejar vacío para <strong>conservarlo</strong>; pegar uno nuevo para rotarlo.'
					: 'Token de acceso del sistema desde Meta Business Manager → Configuración del sistema.',
			),
			array(
				'name'        => 'meta_test_event_code',
				'label'       => 'Test Event Code',
				'type'        => 'text',
				'value'       => $s['test_event_code'],
				'placeholder' => 'TEST12345',
				'description' => 'Opcional. Mientras esté presente, TODOS los envíos van a Events Manager → Test Events.',
			),
			array(
				'name'           => 'meta_browser_events',
				'label'          => 'Browser Events',
				'type'           => 'checkbox',
				'checked'        => $s['browser_events'] === '1',
				'checkbox_label' => "Imprimir el Pixel en el sitio — fbq('init'), PageView, ViewContent (Lead lo dispara la captura de formularios)",
				'description'    => '⚠ Activar solo DESPUÉS de desactivar cualquier snippet personalizado del pixel de Facebook — ambos activos a la vez duplican PageView.',
			),
			array(
				'name'           => 'meta_server_events',
				'label'          => 'Server Events',
				'type'           => 'checkbox',
				'checked'        => $s['server_events'] !== '0',
				'checkbox_label' => 'Enviar eventos comerciales vía Conversions API (cola + retries)',
			),
			array(
				'name'           => 'meta_debug_logging',
				'label'          => 'Debug Logging',
				'type'           => 'checkbox',
				'checked'        => $s['debug_logging'] === '1',
				'checkbox_label' => 'Registrar cada envío en Telemetría (event_id, HTTP, duración — sin PII ni tokens)',
			),
			array(
				'name'           => 'meta_test_mode',
				'label'          => 'Modo test',
				'type'           => 'checkbox',
				'checked'        => $s['test_mode'] === '1',
				'checkbox_label' => 'Encolar y loguear conversiones sin enviarlas a Meta',
				'description'    => 'La cola queda en <code>pending</code> y se despacha al desactivar el modo test.',
			),
		);
	}

	public function status(): array {
		$s          = $this->get_settings();
		$configured = $s['pixel_id'] !== '' && $s['access_token'] !== '';
		$enabled    = $s['enabled'] !== '0';
		$browser_on = class_exists( 'Estandar_Leads_Meta_Pixel' ) && Estandar_Leads_Meta_Pixel::is_active();
		$server_on  = $enabled && $configured && $s['server_events'] !== '0';

		$parts = array(
			'Browser ' . ( $browser_on ? '✓' : '—' ),
			'Server ' . ( $server_on ? '✓' : '—' ),
		);
		if ( $s['test_mode'] === '1' ) {
			$parts[] = 'MODO TEST';
		}
		$queue = Estandar_Leads_Meta_Adapter::queue_stats();
		$parts[] = 'cola pending: ' . (int) ( $queue['counts']['pending'] ?? 0 );

		return array(
			'configured' => $configured,
			'active'     => $enabled && ( $browser_on || $server_on ),
			'summary'    => implode( ' · ', $parts ),
		);
	}

	/**
	 * Panel propio: diagnostics + Meta Status + visor de eventos.
	 * Markup en el partial compartido (fuente única con el fallback standalone).
	 */
	public function render_extra(): void {
		$panel = ESTANDAR_LEADS_PATH . 'templates/admin/meta-integration-panel.php';
		if ( file_exists( $panel ) ) {
			include $panel;
		}
	}
}
