<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'logs' ) ) {
	wp_die( 'No autorizado.' );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';
$days   = isset($_GET['days']) ? max( 7, min( 90, intval( $_GET['days'] ) ) ) : 30;

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
if ( ! in_array( $limit, [50, 200, 500], true ) ) $limit = 200;
$paged = isset($_GET['paged']) ? max( 1, intval($_GET['paged']) ) : 1;

$total = function_exists('estandar_chatbot_count_logs') ? estandar_chatbot_count_logs() : 0;
$total_pages = max( 1, (int) ceil( $total / $limit ) );
if ( $paged > $total_pages ) {
	$paged = $total_pages;
}
$offset = ( $paged - 1 ) * $limit;
$logs = function_exists('estandar_chatbot_get_logs') ? estandar_chatbot_get_logs($limit, $offset) : [];
$friction = function_exists( 'estandar_chatbot_get_logs_friction_insights' )
	? estandar_chatbot_get_logs_friction_insights( $days )
	: [ 'top_questions' => [], 'fallbacks' => [], 'unresolved' => [], 'abandonments' => [] ];

$download_url = wp_nonce_url(
	add_query_arg(
		'action',
		'estandar_chatbot_export_logs_csv',
		admin_url( 'admin-post.php' )
	),
	'ecb_download_logs'
);
$base_page_url = admin_url( 'admin.php?page=estandar-chatbot&tab=logs&limit=' . $limit );
?>
<div>
	<h3 class="ecb-section-title">Errores y fricción</h3>
	<p class="ecb-section-copy">Esta vista detecta qué preguntas se repiten, dónde el bot falla y en qué puntos el usuario abandona.</p>

	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo $status === 'ok' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<div class="ecb-toolbar">
		<form method="get" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="estandar-chatbot" />
			<input type="hidden" name="tab" value="logs" />
			<label>
				Ventana:
				<select name="days">
					<option value="7" <?php selected( $days, 7 ); ?>>7 días</option>
					<option value="30" <?php selected( $days, 30 ); ?>>30 días</option>
					<option value="90" <?php selected( $days, 90 ); ?>>90 días</option>
				</select>
			</label>
			<label>
				Mostrar:
				<select name="limit">
					<option value="50"  <?php selected($limit, 50); ?>>50</option>
					<option value="200" <?php selected($limit, 200); ?>>200</option>
					<option value="500" <?php selected($limit, 500); ?>>500</option>
				</select>
			</label>
			<button class="button">Aplicar</button>
		</form>
		<a href="<?php echo esc_url($download_url); ?>" class="button">⬇ Descargar CSV</a>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
	<div style="display:flex;align-items:center;gap:8px;margin:8px 0 0;font-size:13px;">
		<span style="color:#646970;">Página <?php echo esc_html( (string)$paged ); ?> de <?php echo esc_html( (string)$total_pages ); ?> · <?php echo esc_html( (string)$total ); ?> logs totales</span>
		<?php if ( $paged > 1 ) : ?>
			<a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'paged' => $paged - 1 ], $base_page_url ) ); ?>">← Anterior</a>
		<?php endif; ?>
		<?php if ( $paged < $total_pages ) : ?>
			<a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'paged' => $paged + 1 ], $base_page_url ) ); ?>">Siguiente →</a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="ecb-card-grid" style="margin:12px 0;">
		<div class="ecb-card"><p class="ecb-metric"><strong>Preguntas repetidas</strong></p><p class="ecb-overview-number"><?php echo esc_html( (string) count( (array) $friction['top_questions'] ) ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Fallbacks</strong></p><p class="ecb-overview-number"><?php echo esc_html( (string) count( (array) $friction['fallbacks'] ) ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Sin respuesta útil</strong></p><p class="ecb-overview-number"><?php echo esc_html( (string) count( (array) $friction['unresolved'] ) ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Abandonos</strong></p><p class="ecb-overview-number"><?php echo esc_html( (string) count( (array) $friction['abandonments'] ) ); ?></p></div>
	</div>

	<div class="ecb-card-grid" style="grid-template-columns:1fr 1fr;align-items:start;">
		<div class="ecb-card">
			<h4 style="margin-top:0;">Top 10 preguntas repetidas</h4>
			<ol style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $friction['top_questions'] as $item ) : ?>
					<li style="margin-bottom:6px;"><?php echo esc_html( (string) $item['question'] ); ?> · <strong><?php echo esc_html( (string) $item['count'] ); ?></strong></li>
				<?php endforeach; ?>
				<?php if ( empty( $friction['top_questions'] ) ) : ?>
					<li>No hay suficiente repetición aún.</li>
				<?php endif; ?>
			</ol>
			<p style="margin-top:12px;"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=sugerencias' ) ); ?>">Crear mejora</a></p>
		</div>

		<div class="ecb-card">
			<h4 style="margin-top:0;">Puntos de abandono</h4>
			<ul style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $friction['abandonments'] as $item ) : ?>
					<li style="margin-bottom:8px;"><?php echo esc_html( (string) $item['last_message'] ); ?><br><span class="description"><?php echo esc_html( (string) $item['last_message_at'] ); ?> · <?php echo esc_html( (string) $item['channel'] ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( empty( $friction['abandonments'] ) ) : ?>
					<li>No hay abandonos detectados en esta ventana.</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>

	<div class="ecb-card-grid" style="grid-template-columns:1fr 1fr;align-items:start;margin-top:12px;">
		<div class="ecb-card">
			<h4 style="margin-top:0;">Fallbacks recientes</h4>
			<ul style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $friction['fallbacks'] as $item ) : ?>
					<li style="margin-bottom:8px;"><?php echo esc_html( (string) $item['question'] ); ?><br><span class="description"><?php echo esc_html( (string) $item['answer'] ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( empty( $friction['fallbacks'] ) ) : ?>
					<li>No hay fallbacks relevantes en esta ventana.</li>
				<?php endif; ?>
			</ul>
		</div>

		<div class="ecb-card">
			<h4 style="margin-top:0;">Mensajes sin respuesta útil</h4>
			<ul style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $friction['unresolved'] as $item ) : ?>
					<li style="margin-bottom:8px;"><?php echo esc_html( (string) $item['question'] ); ?><br><span class="description"><?php echo esc_html( (string) $item['datetime'] ); ?></span></li>
				<?php endforeach; ?>
				<?php if ( empty( $friction['unresolved'] ) ) : ?>
					<li>No se detectan mensajes sin respuesta útil.</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>

	<details style="margin-top:16px;">
		<summary style="cursor:pointer;color:#2271b1;">Filtros de exclusión de ruido</summary>
		<div class="ecb-card" style="margin-top:8px;">
			<p class="description" style="margin-top:0;">Patrones adicionales para excluir del análisis de preguntas repetidas. Uno por línea o separados por coma. Ya se filtran automáticamente: saludos, "?", "ok", "gracias" y textos ≤ 2 caracteres.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( 'estandar_chatbot_settings' );
				$current_exclusions = (string) get_option( 'chatbot_excluded_question_patterns', '' );
				?>
				<textarea name="chatbot_excluded_question_patterns" rows="3" class="large-text" placeholder="ej: test, prueba, asdf"><?php echo esc_textarea( $current_exclusions ); ?></textarea>
				<?php submit_button( 'Guardar exclusiones', 'secondary small', 'submit_exclusions', false ); ?>
			</form>
		</div>
	</details>

	<details style="margin-top:8px;">
		<summary style="cursor:pointer;color:#2271b1;">Ver logs crudos <?php if ( $total > 0 ) : ?><span style="color:#646970;font-size:12px;">(<?php echo esc_html( (string)$total ); ?> registros)</span><?php endif; ?></summary>
		<div class="ecb-table-wrap" style="margin-top:12px;overflow-x:auto;">
			<table id="ecb-logs-table" class="widefat striped" style="min-width:1040px;">
				<thead>
					<tr>
						<th style="width:140px;">Fecha</th>
						<th style="width:90px;">Tipo</th>
						<th style="width:140px;">Sesión</th>
						<th>Pregunta</th>
						<th>Respuesta</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $logs as $row ) :
						$meta        = function_exists( 'estandar_chatbot_extract_log_meta' ) ? estandar_chatbot_extract_log_meta( $row ) : [];
						$session_key = (string) ( $meta['session_key'] ?? $meta['session_id'] ?? $meta['conversation_id'] ?? '' );
						$session_short = $session_key !== '' ? substr( $session_key, 0, 10 ) . '…' : '—';
						?>
						<tr>
							<td style="font-size:12px;white-space:nowrap;"><?php echo esc_html( (string) ( $row['datetime'] ?? '' ) ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( (string) ( $meta['interaction_type'] ?? 'question' ) ); ?></code></td>
							<td style="font-size:11px;font-family:monospace;" title="<?php echo esc_attr( $session_key ); ?>"><?php echo esc_html( $session_short ); ?></td>
							<td style="white-space:pre-wrap;max-width:280px;"><?php echo esc_html( (string) ( $row['question'] ?? '' ) ); ?></td>
							<td style="white-space:pre-wrap;max-width:380px;"><?php echo esc_html( (string) ( $row['answer'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="5">Aún no hay logs registrados.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
			<div style="display:flex;gap:8px;align-items:center;margin-top:10px;font-size:13px;">
				<span style="color:#646970;">Pág. <?php echo esc_html( (string)$paged ); ?>/<?php echo esc_html( (string)$total_pages ); ?></span>
				<?php if ( $paged > 1 ) : ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'paged' => $paged - 1 ], $base_page_url ) ); ?>">← Ant</a><?php endif; ?>
				<?php if ( $paged < $total_pages ) : ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'paged' => $paged + 1 ], $base_page_url ) ); ?>">Sig →</a><?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</details>
</div>
