<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'herramientas' ) ) {
	wp_die( 'No autorizado.' );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';

$leads_count     = function_exists( 'estandar_chatbot_count_leads' ) ? (int) estandar_chatbot_count_leads() : 0;
$logs_count      = function_exists( 'estandar_chatbot_count_logs' )  ? (int) estandar_chatbot_count_logs()  : 0;
$retention_days  = (int) get_option( 'chatbot_logs_retention_days', 0 );
$leads_csv_exists = defined( 'ESTANDAR_CHATBOT_LEADS_FILE' ) && file_exists( ESTANDAR_CHATBOT_LEADS_FILE );
$logs_csv_exists  = defined( 'ESTANDAR_CHATBOT_LOGS_FILE' ) && file_exists( ESTANDAR_CHATBOT_LOGS_FILE );
$hay_csv_legacy   = $leads_csv_exists || $logs_csv_exists;
$uninstall_wipe   = (int) get_option( 'ecb_uninstall_wipe_data', 0 );

// Descripción de retención legible
if ( $retention_days <= 0 ) {
	$retention_label = 'Sin límite (se guardan indefinidamente)';
} elseif ( $retention_days === 1 ) {
	$retention_label = '1 día';
} else {
	$retention_label = $retention_days . ' días';
}
?>

<div>
	<h3 class="ecb-section-title">Herramientas</h3>
	<p class="ecb-section-copy">Acciones para operar, mantener o limpiar el sistema.</p>

	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo ( $status === 'ok' ) ? 'notice-success' : 'notice-error'; ?> inline">
			<p><?php echo esc_html( $msg ); ?></p>
		</div>
	<?php endif; ?>

	<div class="ecb-card-grid" style="margin:12px 0;">
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Leads registrados:</strong></p>
			<p style="margin:4px 0 0;font-size:22px;font-weight:700;color:#1d2327;"><?php echo esc_html( (string)$leads_count ); ?></p>
		</div>
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Conversaciones guardadas:</strong></p>
			<p style="margin:4px 0 0;font-size:22px;font-weight:700;color:#1d2327;"><?php echo esc_html( (string)$logs_count ); ?></p>
		</div>
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Retención de conversaciones:</strong></p>
			<p style="margin:4px 0 0;font-size:13px;color:#1d2327;"><?php echo esc_html( $retention_label ); ?></p>
		</div>
	</div>

	<?php // ── operación segura ── ?>
	<section class="ecb-tool-section">
		<div class="ecb-tool-section__head">
			<h4 style="margin:0 0 4px;">Operación segura</h4>
			<p class="description" style="margin:0;">Acciones rutinarias que no modifican ni eliminan datos.</p>
		</div>
		<div class="ecb-card-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
				<input type="hidden" name="action" value="estandar_chatbot_tools_export_data" />
				<?php wp_nonce_field( 'estandar_chatbot_tools_export_data' ); ?>
				<h4 style="margin-top:0;">Exportar datos</h4>
				<p class="description">Descarga un ZIP con leads y conversaciones para respaldo o cumplimiento.</p>
				<button type="submit" class="button">Descargar exportación</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
				<input type="hidden" name="action" value="estandar_chatbot_tools_test_email" />
				<?php wp_nonce_field( 'estandar_chatbot_tools_test_email' ); ?>
				<h4 style="margin-top:0;">Probar email de notificación</h4>
				<p class="description">Verifica que las notificaciones de leads estén llegando correctamente.</p>
				<button type="submit" class="button">Enviar email de prueba</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
				<input type="hidden" name="action" value="estandar_chatbot_export_conversation_effectiveness" />
				<?php wp_nonce_field( 'estandar_chatbot_export_conversation_effectiveness' ); ?>
				<h4 style="margin-top:0;">Exportar conversaciones (audit)</h4>
				<p class="description">Descarga un JSON con las últimas conversaciones (mín. 2 mensajes) y su lead vinculado, para CONVERSATION_EFFECTIVENESS_AUDIT_V1. Solo lectura, no se guarda nada en el servidor.</p>
				<button type="submit" class="button">Descargar export (JSON)</button>
			</form>
		</div>
	</section>

	<?php // ── mantenimiento ── ?>
	<section class="ecb-tool-section">
		<div class="ecb-tool-section__head">
			<h4 style="margin:0 0 4px;">Mantenimiento</h4>
			<p class="description" style="margin:0;">Acciones para limpiar o ajustar sin borrar toda la operación.</p>
		</div>
		<div class="ecb-card-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
				<input type="hidden" name="action" value="estandar_chatbot_tools_purge_logs" />
				<?php wp_nonce_field( 'estandar_chatbot_tools_purge_logs' ); ?>
				<h4 style="margin-top:0;">Purga de conversaciones antiguas</h4>
				<p class="description">
					<?php if ( $retention_days > 0 ) : ?>
						Elimina las conversaciones con más de <?php echo esc_html( (string)$retention_days ); ?> días de antigüedad.
						Podés ajustar este límite en Configuración.
					<?php else : ?>
						La retención está en "sin límite". Para activar la purga automática, definí un período de retención en Configuración.
					<?php endif; ?>
				</p>
				<button type="submit" class="button" <?php echo $retention_days <= 0 ? 'disabled title="Configurá un período de retención primero"' : ''; ?>>
					Ejecutar purga ahora
				</button>
			</form>

			<?php if ( $hay_csv_legacy ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
				<input type="hidden" name="action" value="estandar_chatbot_tools_retry_migration" />
				<?php wp_nonce_field( 'estandar_chatbot_tools_retry_migration' ); ?>
				<h4 style="margin-top:0;">Migrar archivos del sistema anterior</h4>
				<p class="description">
					Se detectaron archivos del sistema anterior (CSV). Ejecutá esto para migrarlos a la base de datos.
					Podés hacerlo varias veces sin riesgo de duplicar datos.
				</p>
				<button type="submit" class="button">Ejecutar migración</button>
			</form>
			<?php endif; ?>
		</div>
	</section>

	<?php // ── acciones críticas ── ?>
	<section class="ecb-tool-section ecb-tool-section--critical">
		<div class="ecb-tool-section__head">
			<h4 style="margin:0 0 4px;">Acciones críticas</h4>
			<p class="description" style="margin:0;">Solo para casos extremos. Estas acciones eliminan datos de forma irreversible.</p>
		</div>
		<div class="ecb-card-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card"
				onsubmit="return confirm('¿Eliminar TODOS los datos del chatbot (leads, conversaciones, eventos)?\n\nEsta acción no se puede deshacer.');">
				<input type="hidden" name="action" value="estandar_chatbot_tools_wipe_data" />
				<?php wp_nonce_field( 'estandar_chatbot_tools_wipe_data' ); ?>
				<h4 style="margin-top:0;">Borrar todos los datos</h4>
				<p class="description">Elimina leads, conversaciones y eventos del chatbot. Los ajustes de configuración no se borran.</p>
				<label style="display:block;margin-bottom:10px;">
					<input type="checkbox" name="ecb_wipe_confirm" value="1" required />
					Confirmo que quiero eliminar todos los datos
				</label>
				<button type="submit" class="button ecb-danger-button">Eliminar todo</button>
			</form>
		</div>
	</section>

	<?php // ── ajustes avanzados (colapsados) ── ?>
	<details style="margin-top:16px;">
		<summary style="cursor:pointer;color:#2271b1;font-size:13px;padding:4px 0;">Ajustes avanzados de desinstalación</summary>
		<div class="ecb-card" style="margin-top:8px;max-width:520px;">
			<form method="post" action="<?php echo esc_url( 'options.php' ); ?>">
				<?php settings_fields( 'estandar_chatbot_tools' ); ?>
				<h4 style="margin-top:0;">Al desinstalar el plugin</h4>
				<label>
					<input type="hidden" name="ecb_uninstall_wipe_data" value="0" />
					<input type="checkbox" name="ecb_uninstall_wipe_data" value="1" <?php checked( $uninstall_wipe, 1 ); ?> />
					Borrar tablas y datos al desinstalar
				</label>
				<p class="description" style="margin:6px 0 10px;">Si está desactivado (recomendado), los datos se conservan aunque desinstales el plugin.</p>
				<?php submit_button( 'Guardar preferencia', 'secondary', 'submit', false ); ?>
			</form>
		</div>
	</details>
</div>
