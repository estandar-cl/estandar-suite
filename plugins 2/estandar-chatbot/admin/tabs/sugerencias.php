<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'sugerencias' ) ) {
	wp_die( 'No autorizado.' );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';
$days   = isset($_GET['days']) ? max( 7, min( 90, intval( $_GET['days'] ) ) ) : 30;
$suggestions = function_exists( 'estandar_chatbot_get_learning_suggestions' )
	? estandar_chatbot_get_learning_suggestions( $days )
	: [];
$applied = function_exists( 'estandar_chatbot_get_applied_suggestions' )
	? estandar_chatbot_get_applied_suggestions()
	: [];
?>
<div>
	<h3 class="ecb-section-title">Sugerencias de mejora</h3>
	<p class="ecb-section-copy">Este módulo cruza logs, conversaciones y leads para proponer cambios concretos que mejoren conversión y reduzcan fricción.</p>

	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo $status === 'ok' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<div class="ecb-toolbar">
		<form method="get" style="margin:0;display:flex;gap:8px;align-items:center;">
			<input type="hidden" name="page" value="estandar-chatbot" />
			<input type="hidden" name="tab" value="sugerencias" />
			<label>
				Ventana:
				<select name="days">
					<option value="7" <?php selected( $days, 7 ); ?>>7 días</option>
					<option value="30" <?php selected( $days, 30 ); ?>>30 días</option>
					<option value="90" <?php selected( $days, 90 ); ?>>90 días</option>
				</select>
			</label>
			<button class="button">Actualizar lectura</button>
		</form>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto' ) ); ?>">Ver IA y Contexto</a>
	</div>

	<?php if ( empty( $suggestions ) ) : ?>
		<p class="description" style="margin:12px 0;padding:10px 14px;background:#f6f7f7;border-radius:6px;">
			Aún no hay suficiente patrón repetido para generar sugerencias automáticas. Se activará cuando haya más conversaciones y logs acumulados.
		</p>
	<?php else : ?>
		<div class="ecb-card-grid" style="margin-top:12px;">
			<?php foreach ( $suggestions as $suggestion ) :
				$id            = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
				$is_applied    = in_array( $id, $applied, true );
				$prompt_append = (string) ( $suggestion['prompt_append'] ?? '' );
				?>
				<div class="ecb-card" style="<?php echo $is_applied ? 'opacity:.7;' : ''; ?>">
					<p class="ecb-metric" style="display:flex;align-items:center;gap:8px;">
						<strong><?php echo esc_html( (string) ( $suggestion['title'] ?? '' ) ); ?></strong>
						<?php if ( $is_applied ) : ?><span style="font-size:11px;background:#edfaed;color:#008a20;border:1px solid #bde8bd;border-radius:10px;padding:2px 8px;">✓ Aplicada</span><?php endif; ?>
					</p>
					<p class="description" style="margin-top:8px;"><strong>Problema:</strong> <?php echo esc_html( (string) ( $suggestion['problem'] ?? '' ) ); ?></p>
					<p class="description" style="margin-top:6px;"><strong>Evidencia:</strong> <?php echo esc_html( (string) ( $suggestion['evidence'] ?? '' ) ); ?></p>
					<p class="description" style="margin-top:6px;"><strong>Sugerencia:</strong> <?php echo esc_html( (string) ( $suggestion['suggestion'] ?? '' ) ); ?></p>

					<?php if ( $prompt_append !== '' ) : ?>
					<details style="margin-top:10px;">
						<summary style="cursor:pointer;color:#2271b1;font-size:13px;">Ver qué se agregaría al prompt</summary>
						<pre style="margin:8px 0 0;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $prompt_append ); ?></pre>
						<p class="description" style="margin:4px 0 0;">Este texto se añadiría al final de tu prompt de sistema como regla de prioridad máxima.</p>
					</details>
					<?php endif; ?>

					<div class="ecb-action-stack" style="margin-top:12px;">
						<?php if ( ! $is_applied ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;"
								onsubmit="return confirm('¿Aplicar esta regla al prompt del chatbot?\n\nEsto agregará una instrucción de prioridad máxima. Podrás revertirla desde la pestaña IA → Configuración avanzada → Historial.');">
								<input type="hidden" name="action" value="estandar_chatbot_apply_suggestion" />
								<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( $id ); ?>" />
								<?php wp_nonce_field( 'estandar_chatbot_apply_suggestion' ); ?>
								<button type="submit" class="button button-secondary">Aplicar al prompt</button>
							</form>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto' ) ); ?>">Editar prompt manualmente</a>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $applied ) ) : ?>
	<div class="ecb-card" style="margin-top:16px;">
		<h4 style="margin-top:0;font-size:13px;color:#646970;">Sugerencias aplicadas anteriormente</h4>
		<ul style="margin:0;padding-left:18px;">
			<?php foreach ( $applied as $applied_id ) : ?>
				<li style="font-size:13px;color:#646970;"><code><?php echo esc_html( (string)$applied_id ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<p class="description" style="margin:8px 0 0;">Para revertir, ve a <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto' ) ); ?>">IA → Configuración avanzada → Historial</a>.</p>
	</div>
	<?php endif; ?>
</div>
