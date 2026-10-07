<?php
/**
 * Plugin Name: Estandar Destinations
 * Plugin URI:  https://estandar.cl
 * Description: Motor de destinos externos para datos del ecosistema Estandar. Centraliza exportaciones hacia webhooks, hojas de cálculo y CRMs.
 * Version: 13.0.172
 * Author:      Estandar Studios CL
 * Author URI:  https://estandar.cl
 * Text Domain: estandar-destinations
 * Requires PHP: 7.4
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_DESTINATIONS_VERSION', '13.0.158' );
define( 'ESTANDAR_DESTINATIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_DESTINATIONS_URL', plugin_dir_url( __FILE__ ) );

// Phase 0 infrastructure bootstrap.
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/interface-estandar-destination.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-destinations-engine.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-destinations-schema.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-destinations-scheduler.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-destinations-health.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-destinations-settings.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'includes/class-estandar-webhook-worker.php';

// Phase 1 adapters.
require_once ESTANDAR_DESTINATIONS_PATH . 'adapters/class-google-ads-destination.php';
require_once ESTANDAR_DESTINATIONS_PATH . 'adapters/class-estandar-webhook-destination.php';

register_activation_hook( __FILE__, array( 'Estandar_Destinations_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Estandar_Destinations_Scheduler', 'deactivate' ) );

// Register custom cron schedule for webhook queue worker.
add_filter( 'cron_schedules', static function ( $schedules ) {
	$schedules['every_minute'] = array(
		'interval' => 60,
		'display'  => 'Every minute',
	);
	return $schedules;
} );

add_action( 'plugins_loaded', array( 'Estandar_Destinations_Engine', 'boot' ), 20 );
add_action( 'plugins_loaded', array( 'Estandar_Destinations_Scheduler', 'init' ), 21 );
add_action( 'plugins_loaded', static function () {
	Estandar_Destinations_Engine::register( new Estandar_Webhook_Destination() );
}, 25 );

// Register webhook queue worker cron
add_action( Estandar_Webhook_Worker::CRON_HOOK, array( 'Estandar_Webhook_Worker', 'process' ) );
register_activation_hook( __FILE__, array( 'Estandar_Webhook_Worker', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'Estandar_Webhook_Worker', 'unschedule' ) );

/**
 * Polyfill CORE_OPTIONALITY_V1.1 — detección dinámica en call-time.
 * Prohibido return false estático.
 */
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

add_action( 'admin_menu', 'estandar_destinations_register_menu' );

function estandar_destinations_register_menu(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( primer_is_active() && function_exists( 'estandar_register_submenu' ) ) {
		// estandar_register_submenu() wraps callback inside estandar_core_render_admin_shell()
		// automatically — do NOT call the shell again inside the callback.
		estandar_register_navigation_page( [
			'slug'       => 'estandar-destinations',
			'capability' => 'manage_options',
			'callback'   => 'estandar_destinations_render_content',
		] );
	} else {
		add_menu_page(
			'Destinations',
			'Destinations',
			'manage_options',
			'estandar-destinations',
			'estandar_destinations_standalone_page',
			'dashicons-share-alt',
			62
		);
	}
}

function estandar_destinations_standalone_page(): void {
	echo '<div class="wrap"><h1>Destinations</h1>';
	estandar_destinations_render_content();
	echo '</div>';
}

add_action( 'admin_post_estandar_destinations_save_webhook', 'estandar_destinations_save_webhook_action' );
function estandar_destinations_save_webhook_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No autorizado.' ); }
	check_admin_referer( 'estandar_destinations_save_webhook' );

	$destinations = array();
	$urls   = (array) ( $_POST['webhook_url'] ?? array() );
	$secrets = (array) ( $_POST['webhook_secret'] ?? array() );
	$active = (array) ( $_POST['webhook_active'] ?? array() );

	foreach ( $urls as $i => $url_raw ) {
		$url = trim( sanitize_text_field( (string) $url_raw ) );
		if ( $url === '' ) { continue; }
		$destinations[] = array(
			'url'    => $url,
			'secret' => sanitize_text_field( (string) ( $secrets[ $i ] ?? '' ) ),
			'active' => ! empty( $active[ $i ] ),
			'label'  => sanitize_text_field( (string) ( $_POST['webhook_label'][ $i ] ?? '' ) ),
		);
	}
	update_option( 'estandar_webhook_dest_list', $destinations );

	wp_safe_redirect( add_query_arg( 'saved', '1', wp_get_referer() ?: admin_url( 'admin.php?page=estandar-destinations' ) ) );
	exit;
}

function estandar_destinations_render_content(): void {
	$engine_adapters = class_exists( 'Estandar_Destinations_Engine' ) ? Estandar_Destinations_Engine::all() : array();
	$webhook_config  = get_option( 'estandar_webhook_dest_list', array() );
	$webhook_config  = is_array( $webhook_config ) ? $webhook_config : array();

	// Queue stats
	$queue_pending = 0; $queue_retry = 0; $queue_dead = 0; $queue_succeeded = 0;
	if ( class_exists( 'Estandar_Destinations_Health' ) ) {
		$health = Estandar_Destinations_Health::status();
		$queue_pending   = (int) ( $health['queue_pending'] ?? 0 );
		$queue_retry     = (int) ( $health['queue_retry'] ?? 0 );
		$queue_dead      = (int) ( $health['queue_dead'] ?? 0 );
		$queue_succeeded = (int) ( $health['queue_succeeded'] ?? 0 );
	}
	$saved = isset( $_GET['saved'] );
	?>
	<style>
		.ed-banner { display:flex;align-items:center;gap:12px;padding:14px 18px;background:#f0f4ff;border:1px solid #c7d4f7;border-radius:8px;margin-bottom:20px; }
		.ed-banner .dashicons { font-size:22px;color:#3b6fd4;flex-shrink:0; }
		.ed-banner strong { color:#1a3a8a;font-size:13px; }
		.ed-banner p { margin:2px 0 0;color:#4a5568;font-size:12px; }
		.ed-badge { display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;letter-spacing:.3px;text-transform:uppercase; }
		.ed-badge--ok { background:#e7f7ed;color:#007a3d; }
		.ed-badge--warn { background:#fffbeb;color:#92400e; }
		.ed-badge--muted { background:#f3f4f6;color:#6b7280; }
		.ed-section-title { font-size:13px;font-weight:600;color:#1a202c;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e2e8f0; }
		.ed-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:20px; }
		.ed-card { background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px; }
		.ed-card .dashicons { font-size:20px;color:#718096;flex-shrink:0; }
		.ed-card__name { font-size:13px;font-weight:600;color:#2d3748;margin:0 0 3px; }
		.ed-card__desc { font-size:12px;color:#718096;margin:0 0 8px; }
		.ed-flow { display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:16px 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:20px; }
		.ed-flow-node { background:#fff;border:1px solid #d1d5db;border-radius:5px;padding:6px 12px;font-size:11px;font-weight:600;color:#374151;white-space:nowrap; }
		.ed-flow-arrow { color:#9ca3af;font-size:14px;padding:0 2px; }
		.ed-field { margin-bottom:10px; }
		.ed-field label { display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px; }
		.ed-field input[type="text"], .ed-field input[type="url"] { width:100%; }
	</style>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
	<?php endif; ?>

	<div class="ed-banner">
		<span class="dashicons dashicons-<?php echo count( $engine_adapters ) > 0 ? 'yes' : 'clock'; ?>"></span>
		<div>
			<strong>Destinations Engine</strong>
			<p><?php echo count( $engine_adapters ); ?> adapter(es) registrado(s) · <?php echo count( $webhook_config ); ?> webhook(s) configurado(s) · 
			<?php if ( $queue_pending + $queue_retry > 0 ) : ?>
				<?php echo (int) $queue_pending; ?> pendiente(s) · <?php echo (int) $queue_retry; ?> reintento(s)
			<?php else : ?>
				Cola vacía
			<?php endif; ?>
			</p>
		</div>
	</div>

	<h2 class="ed-section-title">Adaptadores</h2>
	<div class="ed-grid">
		<?php if ( empty( $engine_adapters ) ) : ?>
			<div class="ed-card"><p>No hay adaptadores registrados.</p></div>
		<?php else : ?>
			<?php foreach ( $engine_adapters as $adapter ) : 
				$is_webhook = $adapter->id() === 'webhook';
				$configured = $adapter->is_configured();
				$wh_count   = $is_webhook ? count( $webhook_config ) : 0;
			?>
			<div class="ed-card">
				<div style="display:flex;justify-content:space-between;align-items:flex-start;">
					<div>
						<p class="ed-card__name"><?php echo esc_html( $adapter->label() ); ?></p>
						<p class="ed-card__desc">
							<?php echo $is_webhook ? 'Envía eventos a endpoints HTTP vía POST.' : ''; ?>
						</p>
					</div>
					<span class="ed-badge ed-badge--<?php echo $configured ? 'ok' : 'muted'; ?>">
						<?php echo $configured ? 'Configurado' : 'Sin configurar'; ?>
					</span>
				</div>
				<div style="font-size:11px;color:#6b7280;margin-top:6px;">
					<?php if ( $is_webhook ) : ?>
						<?php echo $wh_count; ?> destino(s) configurado(s) · <a href="#ed-webhook-config" style="font-weight:600;">Configurar</a>
					<?php endif; ?>
					<?php if ( $queue_succeeded > 0 ) : ?>
						· Última entrega: exitosa
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<h2 class="ed-section-title">Configuración de Webhook</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ed-webhook-config">
		<input type="hidden" name="action" value="estandar_destinations_save_webhook" />
		<?php wp_nonce_field( 'estandar_destinations_save_webhook' ); ?>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px;">
			<p style="font-size:12px;color:#6b7280;margin:0 0 12px;">Configura las URLs y secretos para recibir eventos. Cada fila es un destino independiente.</p>
			<div id="ed-wh-rows">
				<?php if ( empty( $webhook_config ) ) : ?>
				<div class="ed-wh-row" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:8px;flex-wrap:wrap;">
					<div class="ed-field" style="flex:2;min-width:200px;"><label>URL del Webhook</label><input type="url" name="webhook_url[]" value="" placeholder="https://ejemplo.cl/webhook" class="regular-text" /></div>
					<div class="ed-field" style="flex:1;min-width:120px;"><label>Secreto (opcional)</label><input type="text" name="webhook_secret[]" value="" class="regular-text" /></div>
					<div class="ed-field" style="flex:1;min-width:120px;"><label>Etiqueta</label><input type="text" name="webhook_label[]" value="" placeholder="Mi CRM" class="regular-text" /></div>
					<label style="display:flex;align-items:center;gap:4px;padding-bottom:4px;font-size:12px;"><input type="checkbox" name="webhook_active[]" value="1" checked /> Activo</label>
				</div>
				<?php else : ?>
					<?php foreach ( $webhook_config as $wh ) : ?>
					<div class="ed-wh-row" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:8px;flex-wrap:wrap;">
						<div class="ed-field" style="flex:2;min-width:200px;"><label>URL</label><input type="url" name="webhook_url[]" value="<?php echo esc_attr( $wh['url'] ?? '' ); ?>" class="regular-text" /></div>
						<div class="ed-field" style="flex:1;min-width:120px;"><label>Secreto</label><input type="text" name="webhook_secret[]" value="<?php echo esc_attr( $wh['secret'] ?? '' ); ?>" class="regular-text" /></div>
						<div class="ed-field" style="flex:1;min-width:120px;"><label>Etiqueta</label><input type="text" name="webhook_label[]" value="<?php echo esc_attr( $wh['label'] ?? '' ); ?>" placeholder="Mi CRM" class="regular-text" /></div>
						<label style="display:flex;align-items:center;gap:4px;padding-bottom:4px;font-size:12px;"><input type="checkbox" name="webhook_active[]" value="1" <?php checked( ! empty( $wh['active'] ) ); ?> /> Activo</label>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div style="display:flex;gap:8px;margin-top:8px;">
				<button type="submit" class="button button-primary">Guardar configuración</button>
				<button type="button" class="button" onclick="var r=document.querySelector('.ed-wh-row').cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value='';i.checked=true;});document.getElementById('ed-wh-rows').appendChild(r);">+ Agregar destino</button>
			</div>
		</div>
	</form>

	<h2 class="ed-section-title">Cola de entrega</h2>
	<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
		<div style="padding:12px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;flex:1;min-width:100px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Pendientes</div>
			<div style="font-size:20px;font-weight:700;color:#1a56db;"><?php echo (int) $queue_pending; ?></div>
		</div>
		<div style="padding:12px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;flex:1;min-width:100px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Reintentos</div>
			<div style="font-size:20px;font-weight:700;color:#92400e;"><?php echo (int) $queue_retry; ?></div>
		</div>
		<div style="padding:12px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;flex:1;min-width:100px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Fallos</div>
			<div style="font-size:20px;font-weight:700;color:#991b1b;"><?php echo (int) $queue_dead; ?></div>
		</div>
		<div style="padding:12px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;flex:1;min-width:100px;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Entregados</div>
			<div style="font-size:20px;font-weight:700;color:#007a3d;"><?php echo (int) $queue_succeeded; ?></div>
		</div>
	</div>
	<p style="font-size:12px;color:#6b7280;">Consulta el detalle de entregas en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_telemetry&filter_source=estandar-destinations' ) ); ?>">Telemetría</a>.</p>

	<h2 class="ed-section-title">Flujo de datos</h2>
	<div class="ed-flow">
		<span class="ed-flow-node">Evento</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">Destinations Engine</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">Adapter</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">Cola</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">Worker</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">HTTP POST</span><span class="ed-flow-arrow">→</span>
		<span class="ed-flow-node">Destino externo</span>
	</div>

	<h2 class="ed-section-title">Regla de arquitectura</h2>
	<div style="background:#fffbeb;border:1px solid #f6e05e;border-radius:8px;padding:14px 18px;">
		<p style="margin:0 0 6px;font-size:12px;color:#744210;">Todas las exportaciones e integraciones externas convergen aquí. Prohibido crear integraciones directas dentro de Forms, Chatbot, Leads o Analytics. Un adapter dentro de Destinations Engine es el único camino válido.</p>
	</div>
	<?php
}
