<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'estado-operacional' ) ) {
	wp_die( 'No autorizado.' );
}

// ── helpers locales ──────────────────────────────────────────────────────────

if ( ! function_exists( 'estandar_chatbot_ops_table_exists' ) ) {
	function estandar_chatbot_ops_table_exists( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( $table === '' ) return false;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}

if ( ! function_exists( 'estandar_chatbot_ops_count_table_rows' ) ) {
	function estandar_chatbot_ops_count_table_rows( $table, $exists ) {
		global $wpdb;
		if ( ! $exists ) return null;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}

if ( ! function_exists( 'estandar_chatbot_ops_latest_row' ) ) {
	function estandar_chatbot_ops_latest_row( $table, $exists, $columns, $order_column = 'id', $where = '' ) {
		global $wpdb;
		if ( ! $exists ) return null;
		$where_sql = $where !== '' ? ' WHERE ' . $where : '';
		$row = $wpdb->get_row( "SELECT {$columns} FROM {$table}{$where_sql} ORDER BY {$order_column} DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'estandar_chatbot_ops_event_meta' ) ) {
	function estandar_chatbot_ops_event_meta( $row ) {
		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		return is_array( $meta ) ? $meta : [];
	}
}

if ( ! function_exists( 'estandar_chatbot_ops_human_diff' ) ) {
	/**
	 * Devuelve diferencia legible: "hace 3 días", "hoy", "hace 2 horas".
	 */
	function estandar_chatbot_ops_human_diff( $datetime_str ) {
		if ( empty( $datetime_str ) ) return 'Sin registro';
		$ts   = strtotime( (string) $datetime_str );
		if ( ! $ts ) return esc_html( (string) $datetime_str );
		$diff = time() - $ts;
		if ( $diff < 60 )        return 'hace un momento';
		if ( $diff < 3600 )      return 'hace ' . floor( $diff / 60 ) . ' min';
		if ( $diff < 86400 )     return 'hace ' . floor( $diff / 3600 ) . ' h';
		if ( $diff < 172800 )    return 'ayer';
		if ( $diff < 604800 )    return 'hace ' . floor( $diff / 86400 ) . ' días';
		return wp_date( 'd/m/Y', $ts );
	}
}

// ── datos ────────────────────────────────────────────────────────────────────

global $wpdb, $wp_version;

$leads_table  = function_exists( 'estandar_chatbot_get_leads_table' )  ? estandar_chatbot_get_leads_table()  : $wpdb->prefix . 'ecb_leads';
$logs_table   = function_exists( 'estandar_chatbot_get_logs_table' )   ? estandar_chatbot_get_logs_table()   : $wpdb->prefix . 'ecb_logs';
$events_table = function_exists( 'estandar_chatbot_get_events_table' ) ? estandar_chatbot_get_events_table() : $wpdb->prefix . 'ecb_events';

$leads_exists  = estandar_chatbot_ops_table_exists( $leads_table );
$logs_exists   = estandar_chatbot_ops_table_exists( $logs_table );
$events_exists = estandar_chatbot_ops_table_exists( $events_table );
$tables_ok     = $leads_exists && $logs_exists && $events_exists;

$leads_count  = estandar_chatbot_ops_count_table_rows( $leads_table, $leads_exists );
$logs_count   = estandar_chatbot_ops_count_table_rows( $logs_table, $logs_exists );
$events_count = estandar_chatbot_ops_count_table_rows( $events_table, $events_exists );

$runtime_path     = defined( 'ESTANDAR_CHATBOT_STORAGE' ) ? ESTANDAR_CHATBOT_STORAGE : estandar_chatbot_storage_path();
$runtime_exists   = is_dir( $runtime_path );
$runtime_writable = $runtime_exists && wp_is_writable( $runtime_path );

$legacy_paths = [
	ESTANDAR_CHATBOT_PATH . 'storage/leads/leads.csv',
	ESTANDAR_CHATBOT_PATH . 'storage/logs/logs.csv',
	ESTANDAR_CHATBOT_PATH . 'storage/logs/interactions_audit.csv',
];
$detected_legacy = [];
foreach ( $legacy_paths as $lp ) {
	if ( file_exists( $lp ) ) $detected_legacy[] = $lp;
}

$runtime_csv_paths = [
	defined( 'ESTANDAR_CHATBOT_LEADS_FILE' )              ? ESTANDAR_CHATBOT_LEADS_FILE              : estandar_chatbot_storage_path( 'leads/leads.csv' ),
	defined( 'ESTANDAR_CHATBOT_LOGS_FILE' )               ? ESTANDAR_CHATBOT_LOGS_FILE               : estandar_chatbot_storage_path( 'logs/logs.csv' ),
	defined( 'ESTANDAR_CHATBOT_INTERACTIONS_AUDIT_CSV' )  ? ESTANDAR_CHATBOT_INTERACTIONS_AUDIT_CSV  : estandar_chatbot_storage_path( 'logs/interactions_audit.csv' ),
];

$latest_lead     = estandar_chatbot_ops_latest_row( $leads_table,  $leads_exists,  'id, created_at, last_interaction_at, email, phone, data' );
$latest_log      = estandar_chatbot_ops_latest_row( $logs_table,   $logs_exists,   'id, datetime, question, answer, meta' );
$latest_delivery = estandar_chatbot_ops_latest_row( $events_table, $events_exists, 'id, created_at, event_name, lead_id, lead_email, lead_phone, meta', 'id', "event_name = 'lead.delivery_status'" );
$latest_csv_export = estandar_chatbot_ops_latest_row( $events_table, $events_exists, 'id, created_at, event_name, meta', 'id', "event_name = 'csv.export'" );

$latest_delivery_meta  = $latest_delivery  ? estandar_chatbot_ops_event_meta( $latest_delivery )  : [];
$latest_csv_meta       = $latest_csv_export ? estandar_chatbot_ops_event_meta( $latest_csv_export ) : [];
$latest_lead_data      = $latest_lead ? json_decode( (string)( $latest_lead['data'] ?? '{}' ), true ) : [];
$latest_lead_data      = is_array( $latest_lead_data ) ? $latest_lead_data : [];

$migration_leads_done = (int) get_option( defined( 'ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION' ) ? ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION : 'chatbot_leads_migrated_to_db', 0 );
$migration_logs_done  = (int) get_option( defined( 'ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION' )  ? ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION  : 'chatbot_logs_migrated_to_db',  0 );

// ── canales de delivery ──────────────────────────────────────────────────────

$delivery_channels = [
	'email'   => [ 'success' => 0, 'failed' => 0, 'skipped' => 0 ],
	'webhook' => [ 'success' => 0, 'failed' => 0, 'skipped' => 0 ],
	'crm'     => [ 'success' => 0, 'failed' => 0, 'skipped' => 0 ],
];
$recent_delivery_failures = [];
if ( $events_exists ) {
	$delivery_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT created_at, lead_id, lead_email, lead_phone, meta FROM {$events_table} WHERE event_name = %s ORDER BY id DESC LIMIT %d",
			'lead.delivery_status',
			100
		),
		ARRAY_A
	);
	foreach ( (array) $delivery_rows as $row ) {
		$meta    = estandar_chatbot_ops_event_meta( $row );
		$channel = sanitize_key( (string)( $meta['delivery_channel'] ?? '' ) );
		$status  = sanitize_key( (string)( $meta['status'] ?? '' ) );
		if ( isset( $delivery_channels[ $channel ][ $status ] ) ) {
			$delivery_channels[ $channel ][ $status ]++;
		}
		if ( $status === 'failed' && count( $recent_delivery_failures ) < 8 ) {
			$recent_delivery_failures[] = [
				'created_at' => (string)( $row['created_at'] ?? '' ),
				'lead_id'    => (string)( $row['lead_id'] ?? '' ),
				'channel'    => $channel,
				'message'    => (string)( $meta['message'] ?? '' ),
			];
		}
	}
}

// ── alertas ──────────────────────────────────────────────────────────────────

$alertas = [];
if ( ! empty( $detected_legacy ) ) {
	$alertas[] = [
		'nivel'      => 'warning',
		'titulo'     => 'Archivos de versión anterior detectados',
		'ocurre'     => 'Hay ' . count( $detected_legacy ) . ' archivo(s) CSV del sistema anterior dentro del plugin.',
		'impacto'    => 'Pueden mantener datos legados pendientes de migración o limpieza.',
		'correccion' => 'Revisá la migración y eliminá los archivos solo cuando el proceso esté confirmado.',
		'cta_url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=herramientas' ),
		'cta_label'  => 'Abrir Herramientas →',
	];
}
if ( ! $runtime_exists ) {
	$alertas[] = [
		'nivel'      => 'error',
		'titulo'     => 'Carpeta de almacenamiento no existe',
		'ocurre'     => 'El directorio de uploads del chatbot no existe.',
		'impacto'    => 'El chatbot no puede almacenar archivos temporales.',
		'correccion' => 'Creá el directorio esperado y verificá sus permisos en el servidor.',
	];
} elseif ( ! $runtime_writable ) {
	$alertas[] = [
		'nivel'      => 'error',
		'titulo'     => 'Carpeta de almacenamiento sin permisos de escritura',
		'ocurre'     => 'El directorio de uploads existe pero no es escribible.',
		'impacto'    => 'El chatbot no puede guardar archivos temporales.',
		'correccion' => 'Corregí propietario y permisos de escritura en el servidor.',
	];
}
if ( ! $tables_ok ) {
	$alertas[] = [
		'nivel'      => 'error',
		'titulo'     => 'Tablas de base de datos incompletas',
		'ocurre'     => 'Una o más tablas requeridas por el chatbot no existen.',
		'impacto'    => 'Captura, historial o funciones operativas pueden fallar.',
		'correccion' => 'Revisá el estado del plugin antes de regenerar las tablas.',
		'cta_url'    => admin_url( 'plugins.php' ),
		'cta_label'  => 'Revisar plugins →',
	];
}
if ( ! empty( $recent_delivery_failures ) ) {
	$alertas[] = [
		'nivel'      => 'error',
		'titulo'     => 'Hay fallas en el envío de notificaciones',
		'ocurre'     => count( $recent_delivery_failures ) . ' notificacion(es) recientes no se enviaron correctamente.',
		'impacto'    => 'Los destinatarios pueden no haber recibido avisos operativos.',
		'correccion' => 'Revisá credenciales y entrega de email en Integraciones.',
		'cta_url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=integraciones#ecb-email-delivery' ),
		'cta_label'  => 'Configurar notificaciones →',
	];
}
if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
	$alertas[] = [
		'nivel'      => 'warning',
		'titulo'     => 'WP Cron deshabilitado',
		'ocurre'     => 'WordPress no ejecuta tareas programadas por tráfico porque DISABLE_WP_CRON está activo.',
		'impacto'    => 'Los recordatorios automáticos, escalaciones y digest del Compliance Engine no se ejecutarán solos.',
		'correccion' => 'Configurá un cron externo apuntando a wp-cron.php o habilitá WP Cron en wp-config.php.',
		'cta_url'    => admin_url( 'admin.php?page=estandar-leads-settings#compliance-engine' ),
		'cta_label'  => 'Configurar Compliance Engine →',
	];
}

// ── semáforo global ──────────────────────────────────────────────────────────

$errores   = array_filter( $alertas, fn( $a ) => $a['nivel'] === 'error' );
$warnings  = array_filter( $alertas, fn( $a ) => $a['nivel'] === 'warning' );

if ( ! empty( $errores ) ) {
	$semaforo_class  = 'ecb-semaforo--error';
	$semaforo_icon   = '🔴';
	$semaforo_titulo = 'Requiere atención';
	$semaforo_desc   = count( $errores ) . ' problema(s) detectado(s) que pueden afectar la operación del chatbot.';
} elseif ( ! empty( $warnings ) ) {
	$semaforo_class  = 'ecb-semaforo--warning';
	$semaforo_icon   = '🟡';
	$semaforo_titulo = 'Atención';
	$semaforo_desc   = count( $warnings ) . ' aviso(s) que no bloquean la operación pero conviene revisar.';
} else {
	$semaforo_class  = 'ecb-semaforo--ok';
	$semaforo_icon   = '✅';
	$semaforo_titulo = 'Sistema operativo';
	$semaforo_desc   = 'Todo funciona correctamente. No se detectan problemas.';
}
?>

<div>
	<h3 class="ecb-section-title">Estado del sistema</h3>
	<p class="ecb-section-copy">Vista de salud operacional. Solo lectura — no ejecuta cambios.</p>

	<?php // ── semáforo ── ?>
	<div class="ecb-semaforo <?php echo esc_attr( $semaforo_class ); ?>" style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:8px;margin:12px 0;border:1px solid currentColor;">
		<span style="font-size:28px;line-height:1;"><?php echo $semaforo_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div>
			<strong style="font-size:15px;"><?php echo esc_html( $semaforo_titulo ); ?></strong>
			<p style="margin:2px 0 0;font-size:13px;"><?php echo esc_html( $semaforo_desc ); ?></p>
		</div>
	</div>

	<?php // ── alertas activas ── ?>
	<?php if ( ! empty( $alertas ) ) : ?>
	<div style="margin:12px 0;">
		<?php foreach ( $alertas as $alerta ) :
			$notice_class = $alerta['nivel'] === 'error' ? 'notice-error' : 'notice-warning';
			?>
			<div class="notice <?php echo esc_attr( $notice_class ); ?> inline" style="margin-bottom:8px;">
				<div style="padding:10px 0;">
					<strong><?php echo esc_html( $alerta['titulo'] ); ?></strong>
					<p style="margin:6px 0 0;font-size:13px;"><strong>Qué ocurre:</strong> <?php echo esc_html( $alerta['ocurre'] ); ?></p>
					<p style="margin:4px 0 0;font-size:13px;"><strong>Impacto:</strong> <?php echo esc_html( $alerta['impacto'] ); ?></p>
					<p style="margin:4px 0 0;font-size:13px;"><strong>Cómo corregirlo:</strong> <?php echo esc_html( $alerta['correccion'] ); ?></p>
					<?php if ( ! empty( $alerta['cta_url'] ) && ! empty( $alerta['cta_label'] ) ) : ?>
						<p style="margin:8px 0 0;"><a class="button button-small" href="<?php echo esc_url( $alerta['cta_url'] ); ?>"><?php echo esc_html( $alerta['cta_label'] ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php // ── actividad reciente ── ?>
	<section class="ecb-tool-section" style="margin-top:16px;">
		<h4 style="margin:0 0 10px;">Actividad reciente</h4>
		<div class="ecb-card-grid">
			<div class="ecb-card">
				<p class="ecb-metric" style="margin:0 0 4px;"><strong>Último lead</strong></p>
				<p style="margin:0;font-size:22px;font-weight:700;color:#1d2327;">
					<?php echo esc_html( estandar_chatbot_ops_human_diff( $latest_lead['created_at'] ?? '' ) ); ?>
				</p>
				<?php if ( $latest_lead ) : ?>
					<p class="description" style="margin:4px 0 0;">ID #<?php echo esc_html( (string)$latest_lead['id'] ); ?></p>
				<?php else : ?>
					<p class="description" style="margin:4px 0 0;">Sin leads registrados aún</p>
				<?php endif; ?>
			</div>
			<div class="ecb-card">
				<p class="ecb-metric" style="margin:0 0 4px;"><strong>Última conversación</strong></p>
				<p style="margin:0;font-size:22px;font-weight:700;color:#1d2327;">
					<?php echo esc_html( estandar_chatbot_ops_human_diff( $latest_log['datetime'] ?? '' ) ); ?>
				</p>
				<?php if ( $latest_log ) : ?>
					<p class="description" style="margin:4px 0 0;"><?php echo esc_html( mb_strimwidth( (string)( $latest_log['question'] ?? '' ), 0, 48, '…' ) ); ?></p>
				<?php else : ?>
					<p class="description" style="margin:4px 0 0;">Sin interacciones registradas aún</p>
				<?php endif; ?>
			</div>
			<div class="ecb-card">
				<p class="ecb-metric" style="margin:0 0 4px;"><strong>Última notificación</strong></p>
				<p style="margin:0;font-size:22px;font-weight:700;color:#1d2327;">
					<?php echo esc_html( estandar_chatbot_ops_human_diff( $latest_delivery['created_at'] ?? '' ) ); ?>
				</p>
				<?php if ( $latest_delivery ) :
					$d_canal  = strtolower( (string)( $latest_delivery_meta['delivery_channel'] ?? '' ) );
					$d_status = sanitize_key( (string)( $latest_delivery_meta['status'] ?? '' ) );
					$d_label  = ( $d_status === 'success' || $d_status === 'ok' ) ? '✓ Enviada' : ( $d_status === 'failed' ? '✗ Falló' : ucfirst( $d_status ) );
					?>
					<p class="description" style="margin:4px 0 0;"><?php echo esc_html( $d_label . ( $d_canal ? ' · ' . $d_canal : '' ) ); ?></p>
				<?php else : ?>
					<p class="description" style="margin:4px 0 0;">Sin notificaciones registradas</p>
				<?php endif; ?>
			</div>
			<div class="ecb-card">
				<p class="ecb-metric" style="margin:0 0 4px;"><strong>Base de datos</strong></p>
				<p style="margin:0;font-size:22px;font-weight:700;color:#1d2327;">
					<?php echo esc_html( $tables_ok ? '✓ OK' : '✗ Error' ); ?>
				</p>
				<p class="description" style="margin:4px 0 0;">
					<?php echo esc_html( (string)( $leads_count ?? 0 ) ); ?> leads · <?php echo esc_html( (string)( $logs_count ?? 0 ) ); ?> logs
				</p>
			</div>
		</div>
	</section>

	<?php // ── canales de notificación ── ?>
	<section class="ecb-tool-section" style="margin-top:16px;">
		<h4 style="margin:0 0 10px;">Canales de notificación</h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Canal</th>
					<th>Estado</th>
					<th>Enviadas</th>
					<th>Fallidas</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $delivery_channels as $canal => $counts ) :
					$total_canal  = $counts['success'] + $counts['failed'] + $counts['skipped'];
					$todo_skipped = $counts['skipped'] > 0 && $counts['success'] === 0 && $counts['failed'] === 0;
					$hay_fallas   = $counts['failed'] > 0;

					if ( $todo_skipped ) {
						$estado_label = 'Canal no activo';
						$estado_style = 'color:#646970;';
					} elseif ( $hay_fallas ) {
						$estado_label = '⚠ Con fallas';
						$estado_style = 'color:#b32d2e;font-weight:600;';
					} elseif ( $counts['success'] > 0 ) {
						$estado_label = '✓ Operativo';
						$estado_style = 'color:#008a20;font-weight:600;';
					} else {
						$estado_label = 'Sin actividad';
						$estado_style = 'color:#646970;';
					}

					$nombres = [ 'email' => 'Email', 'webhook' => 'Webhook', 'crm' => 'CRM' ];
					?>
					<tr>
						<td><?php echo esc_html( $nombres[ $canal ] ?? strtoupper( $canal ) ); ?></td>
						<td style="<?php echo esc_attr( $estado_style ); ?>"><?php echo esc_html( $estado_label ); ?></td>
						<td><?php echo $todo_skipped ? '<span style="color:#646970;">—</span>' : esc_html( (string)$counts['success'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $todo_skipped ? '<span style="color:#646970;">—</span>' : ( $counts['failed'] > 0 ? '<strong style="color:#b32d2e;">' . esc_html( (string)$counts['failed'] ) . '</strong>' : esc_html( (string)$counts['failed'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $recent_delivery_failures ) ) : ?>
		<div style="margin-top:12px;">
			<h4 style="margin:0 0 8px;color:#b32d2e;">Fallas recientes</h4>
			<table class="widefat striped">
				<thead><tr><th>Fecha</th><th>Canal</th><th>Lead</th><th>Mensaje</th></tr></thead>
				<tbody>
					<?php foreach ( $recent_delivery_failures as $falla ) : ?>
						<tr>
							<td style="font-size:12px;white-space:nowrap;"><?php echo esc_html( $falla['created_at'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $falla['channel'] ) ); ?></td>
							<td><?php echo esc_html( '#' . $falla['lead_id'] ); ?></td>
							<td><?php echo esc_html( $falla['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</section>

	<?php // ── diagnóstico técnico (colapsado) ── ?>
	<details style="margin-top:20px;">
		<summary style="cursor:pointer;color:#2271b1;font-size:13px;padding:4px 0;">Diagnóstico técnico</summary>
		<div style="margin-top:10px;">

			<h4 style="margin:0 0 6px;font-size:13px;color:#646970;text-transform:uppercase;letter-spacing:.5px;">Almacenamiento</h4>
			<table class="widefat striped" style="margin-bottom:12px;">
				<tbody>
					<tr><th style="width:200px;">Tabla leads</th><td><?php echo esc_html( $leads_table ); ?> · <?php echo esc_html( $leads_exists ? 'existe' : 'FALTANTE' ); ?></td></tr>
					<tr><th>Tabla logs</th><td><?php echo esc_html( $logs_table ); ?> · <?php echo esc_html( $logs_exists ? 'existe' : 'FALTANTE' ); ?></td></tr>
					<tr><th>Tabla eventos</th><td><?php echo esc_html( $events_table ); ?> · <?php echo esc_html( $events_exists ? 'existe' : 'FALTANTE' ); ?></td></tr>
					<tr><th>Carpeta runtime</th><td><?php echo '<code>' . esc_html( $runtime_path ) . '</code>' . ' · ' . esc_html( $runtime_writable ? 'escribible' : 'NO escribible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th>CSV archivos legacy</th><td><?php echo empty( $detected_legacy ) ? 'No detectados' : esc_html( implode( ', ', array_map( 'basename', $detected_legacy ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th>Migración leads</th><td><?php echo esc_html( $migration_leads_done ? 'Completada' : 'No registrada' ); ?></td></tr>
					<tr><th>Migración logs</th><td><?php echo esc_html( $migration_logs_done ? 'Completada' : 'No registrada' ); ?></td></tr>
				</tbody>
			</table>

			<h4 style="margin:0 0 6px;font-size:13px;color:#646970;text-transform:uppercase;letter-spacing:.5px;">Entorno</h4>
			<table class="widefat striped">
				<tbody>
					<tr><th style="width:200px;">PHP</th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><th>WordPress</th><td><?php echo esc_html( (string) $wp_version ); ?></td></tr>
					<tr><th>Plugin</th><td><?php echo esc_html( ESTANDAR_CHATBOT_VERSION ); ?></td></tr>
					<tr><th>WP Cron</th><td>
						<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
							<span style="color:#b32d2e;">Deshabilitado (DISABLE_WP_CRON = true)</span>
						<?php else : ?>
							<span style="color:#008a20;">Habilitado</span>
						<?php endif; ?>
					</td></tr>
					<tr><th>Tablas BD</th><td><?php echo esc_html( $tables_ok ? 'Completas' : 'Incompletas' ); ?></td></tr>
				</tbody>
			</table>

		</div>
	</details>
</div>
