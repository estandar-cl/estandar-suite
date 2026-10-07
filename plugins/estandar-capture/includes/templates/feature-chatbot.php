<?php
/**
 * Estandar Suite — Capture · Chatbot (sub-tab con sub-sub-tabs).
 * Rescata las funcionalidades del plugin legado estandar-chatbot:
 * Resumen · Conversación · Captación de leads · IA y conocimiento · Apariencia
 * · Conversaciones · Logs · Sugerencias · Integraciones · Sistema.
 * La analítica de eventos vive en Insights → Analytics → Chatbot; los leads
 * capturados se gestionan en Capture → Leads (sin duplicar vistas).
 * Variables: $feature, $feature_view, $feature_saved.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$conversations = isset( $feature_view['conversations'] ) ? $feature_view['conversations'] : array();
$leads         = isset( $feature_view['leads'] ) ? $feature_view['leads'] : array();
$events        = isset( $feature_view['events'] ) ? $feature_view['events'] : array();
$logs          = isset( $feature_view['logs'] ) ? $feature_view['logs'] : array();
$groups        = \Estandar\Capture\Admin::chatbot_settings_groups();
$chatbot_opt   = get_option( 'estandar_suite_capture_chatbot_settings', array() );
$chatbot_opt   = is_array( $chatbot_opt ) ? $chatbot_opt : array();

// CHATBOT_TABS_V1: por defecto se ocultan las tabs avanzadas/técnicas para no
// saturar al operador. Siguen activas (deep link por URL) y se muestran con la
// opción "Mostrar pestañas avanzadas" (Conversación).
$all_ssubs    = \Estandar\Capture\Admin::chatbot_ssubs();
$show_advanced = ! empty( $chatbot_opt['show_advanced_tabs'] );
if ( ! $show_advanced ) {
	foreach ( array( 'sugerencias', 'integraciones', 'fusion', 'sistema', 'chat' ) as $hide_ss ) {
		unset( $all_ssubs[ $hide_ss ] );
	}
}
$ssubs = \Estandar\Core\Admin::filter_ssubs( 'capture', 'config', 'chatbot', $all_ssubs );
$ss = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );

$cval = function ( $key ) use ( $chatbot_opt, $groups, $ss ) {
	$fields = $groups[ $ss ] ?? array();
	$cfg = $fields[ $key ] ?? array();
	return isset( $chatbot_opt[ $key ] ) ? $chatbot_opt[ $key ] : ( $cfg['default'] ?? '' );
};
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Para qué sirve?</strong> El chatbot conversa con los visitantes, capta leads y deriva a humanos. Esta pantalla reúne sus capacidades de conversación, captación de leads, IA/conocimiento, apariencia y las integraciones con webhook/CRM/email/WhatsApp. Su analítica y sus leads se ven en las áreas especializadas de la suite (Insights y Leads).' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-capture', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( isset( $_GET['chatbot_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings del chatbot guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<?php
$chat_health = \Estandar\Capture\ChatbotHealth::snapshot();
$_h_color = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'bad' => '#d63638' );
$_h_label = array( 'ok' => 'Operativo', 'warn' => 'Atención', 'bad' => 'Requiere acción' );
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-width:760px;margin-bottom:14px;">
	<?php foreach ( $chat_health['checks'] as $_ck => $_cval ) : ?>
		<div style="border:1px solid <?php echo esc_attr( $_h_color[ $_cval['level'] ] ); ?>;border-left:4px solid <?php echo esc_attr( $_h_color[ $_cval['level'] ] ); ?>;border-radius:6px;padding:8px 10px;background:#fff;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $_cval['label'] ); ?></div>
			<div style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $_h_color[ $_cval['level'] ] ); ?>;"><?php echo esc_html( $_cval['status'] ); ?></div>
			<div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo esc_html( $_cval['desc'] ); ?></div>
		</div>
	<?php endforeach; ?>
</div>
<p class="description" style="margin-top:0;">La <strong>analítica</strong> de los eventos del chatbot vive en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics&ss=chatbot' ) ); ?>">Insights → Analytics → Chatbot</a>, y los <strong>leads</strong> capturados se gestionan en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=leads&ss=chatbot' ) ); ?>">Capture → Leads → Chatbot</a>.</p>
<p class="description">Configuración en las sub-tabs Conversación · Captación · IA · Apariencia · Integraciones · Sistema, y la fusión de datos con la Suite en <strong>Fusión con la Suite</strong>.</p>

<?php elseif ( $ss === 'ia' ) : ?>
<h4>IA y conocimiento</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_chatbot_settings', 'estandar_suite_capture_chatbot_settings', $groups['ia'], '', array( 'group' => 'ia' ) ); ?>
<div style="margin-top:16px;max-width:760px;">
	<h4>Prompt efectivo (lo que se envía al modelo)</h4>
	<p class="description">Combinación de las instrucciones, el perfil del negocio y el contexto. El modelo usa esto como reglas en cada conversación.</p>
	<textarea readonly rows="10" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px;background:#f6f7f7;color:#1d2327;border:1px solid #dcdcde;border-radius:4px;padding:10px;"><?php echo esc_textarea( \Estandar\Capture\AiChatPrompt::system() ); ?></textarea>
</div>

<?php elseif ( $ss === 'apariencia' ) : $ap = \Estandar\Capture\ChatbotHealth::appearance(); ?>
<h4>Apariencia</h4>
<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">
	<div style="flex:1;min-width:320px;max-width:640px;">
		<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_chatbot_settings', 'estandar_suite_capture_chatbot_settings', $groups['apariencia'], '', array( 'group' => 'apariencia' ) ); ?>
	</div>
	<div style="width:280px;position:sticky;top:40px;">
		<div style="border:1px solid #dcdcde;border-radius:10px;padding:14px;background:#fafafa;box-shadow:0 2px 8px rgba(0,0,0,.06);">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Vista previa</div>
			<div style="border:1px solid #e2e2e2;border-radius:8px;overflow:hidden;background:<?php echo esc_attr( $ap['bg'] ); ?>;">
				<div style="padding:8px 12px;background:<?php echo esc_attr( $ap['accent'] ); ?>;color:#fff;font-size:12px;font-weight:600;"><?php echo esc_html( $ap['bot_name'] ); ?></div>
				<div style="padding:12px;font-size:12px;min-height:110px;background:<?php echo esc_attr( $ap['bg'] ); ?>;color:<?php echo esc_attr( $ap['text'] ); ?>;">
					<div style="background:<?php echo esc_attr( $ap['bot_bubble'] ); ?>;color:<?php echo esc_attr( $ap['text'] ); ?>;border-radius:10px 10px 10px 2px;padding:8px 10px;display:inline-block;max-width:85%;">Hola, ¿en qué te ayudo?</div>
					<div style="text-align:right;margin-top:8px;"><span style="background:<?php echo esc_attr( $ap['user_bubble'] ); ?>;color:<?php echo esc_attr( $ap['text'] ); ?>;border-radius:10px 10px 2px 10px;padding:8px 10px;display:inline-block;max-width:85%;">Necesito información</span></div>
					<div style="margin-top:10px;"><span style="display:inline-block;background:<?php echo esc_attr( $ap['button'] ); ?>;color:#fff;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:600;">Quiero avanzar</span></div>
				</div>
			</div>
			<div style="margin-top:10px;text-align:<?php echo esc_attr( $ap['position'] === 'left' ? 'left' : 'right' ); ?>;">
				<span style="display:inline-flex;align-items:center;justify-content:center;width:<?php echo (int) $ap['size']; ?>px;height:<?php echo (int) $ap['size']; ?>px;border-radius:50%;background:<?php echo esc_attr( $ap['accent'] ); ?>;color:#fff;font-size:20px;overflow:hidden;<?php echo $ap['bubble_image'] !== '' ? 'background-image:url(' . esc_url( $ap['bubble_image'] ) . ');background-size:cover;background-position:center;' : ''; ?>"><?php echo $ap['bubble_image'] !== '' ? '' : '💬'; ?></span>
				<p class="description" style="margin:6px 0 0;font-size:11px;">Burbuja del frontend: <?php echo $ap['bubble_image'] !== '' ? 'imagen configurada' : 'sin imagen'; ?><?php echo (string) ( $chatbot_opt['bubble_image_url'] ?? '' ) === '' && $ap['bubble_image'] !== '' ? ' (GIF animado por defecto del plugin)' : ''; ?>.</p>
			</div>
		</div>
		<p class="description" style="margin-top:8px;">Simulación estática. Los cambios se aplican al guardar.</p>
	</div>
</div>

<?php elseif ( in_array( $ss, array( 'conversacion', 'captacion', 'mensajes', 'integraciones', 'sistema' ), true ) ) : ?>
<h4><?php echo esc_html( $ssubs[ $ss ] ); ?></h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_chatbot_settings', 'estandar_suite_capture_chatbot_settings', $groups[ $ss ], '', array( 'group' => $ss ) ); ?>

<?php elseif ( $ss === 'fusion' ) : $fusion = isset( $feature_view['fusion'] ) ? $feature_view['fusion'] : array(); ?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Qué es?</strong> La <em>fusión con la Suite</em> hace de Capture el hub de datos del chatbot sin tocar su runtime: cada emisión legacy <code>estandar_lead_captured</code> se ingesta en <code>estandar_capture_submissions</code> (ChatbotBridge), las capturas nativas v2 pueden re-emitirse a v1 cuando estandar-leads esté activo (LeadsBridge), y los datos históricos de <code>ecb_leads</code>/<code>ecb_conversations</code>/<code>ecb_logs</code>/<code>ecb_events</code> se adoptan en solo-lectura (ChatbotAdoption).' ); ?>
<h4>Estado de la fusión</h4>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:260px;"><strong>Chatbot legacy activo</strong></td><td><?php echo ! empty( $fusion['legacy_active'] ) ? 'Sí — sus 4 emisores alimentan Capture vía ChatbotBridge.' : 'No detectado (el puente queda inactivo hasta que esté).'; ?></td></tr>
		<tr><td><strong>Leads puenteados (total)</strong></td><td><?php echo (int) ( $fusion['bridge_stats']['total'] ?? 0 ); ?> — último #<?php echo (int) ( $fusion['bridge_stats']['last_id'] ?? 0 ); ?> (<?php echo esc_html( (string) ( $fusion['bridge_stats']['last_ts'] ?? '—' ) ); ?>, canal <?php echo esc_html( (string) ( $fusion['bridge_stats']['last_channel'] ?? '—' ) ); ?>)</td></tr>
		<tr><td><strong>Puente de leads a v1</strong></td><td><?php echo ! empty( $fusion['leads_bridge'] ) ? 'Activo' : 'Inactivo (opt-in)'; ?> — estandar-leads <?php echo ! empty( $fusion['leads_active'] ) ? 'detectado' : 'NO detectado'; ?></td></tr>
		<tr><td><strong>Pipeline de almacenamiento</strong></td><td><?php echo ( $fusion['pipeline_mode'] ?? 'legacy' ) === 'capture' ? '<strong>CAPTURE</strong> — el chatbot no escribe ecb_leads' : 'Legacy — el chatbot escribe ecb_leads (actual)'; ?></td></tr>
		<tr><td><strong>ecb_leads → adoptados</strong></td><td><?php echo (int) ( $fusion['adoption']['legacy_total'] ?? 0 ); ?> → <?php echo (int) ( $fusion['adoption']['adopted'] ?? 0 ); ?> adoptados (total source=chatbot: <?php echo (int) ( $fusion['adoption']['capture_total'] ?? 0 ); ?>)</td></tr>
	</tbody>
</table>
<h4>Espejos v2 (conversaciones · logs · eventos)</h4>
<table class="widefat striped" style="max-width:560px;">
	<thead><tr><th>Superficie</th><th>Legacy (ecb_*)</th><th>Adoptado (v2)</th></tr></thead>
	<tbody>
	<?php foreach ( (array) ( $fusion['mirror_status'] ?? array() ) as $surface => $st ) : ?>
		<tr><td><code><?php echo esc_html( $surface ); ?></code></td><td><?php echo $st['legacy_exists'] ? (int) $st['legacy'] : '—'; ?></td><td><?php echo (int) $st['adopted']; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $fusion['mirror_status'] ) ) : ?><tr><td colspan="3">Sin superficies.</td></tr><?php endif; ?>
	</tbody>
</table>
<?php if ( isset( $_GET['chatbot_adopt_dry'] ) ) : ?><div class="notice notice-info is-dismissible"><p>Dry-run de adopción: <strong><?php echo (int) $_GET['chatbot_adopt_dry']; ?></strong> filas se importarían. Usa «Importar (commit)» para adoptarlas.</p></div><?php endif; ?>
<?php if ( isset( $_GET['chatbot_adopt_ok'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Adoptadas: <strong><?php echo (int) $_GET['chatbot_adopt_ok']; ?></strong> filas nuevas.</p></div><?php endif; ?>
<?php if ( isset( $_GET['chatbot_adopt_rolled'] ) ) : ?><div class="notice notice-warning is-dismissible"><p>Rollback: <strong><?php echo (int) $_GET['chatbot_adopt_rolled']; ?></strong> filas adoptadas eliminadas (los originales quedan intactos).</p></div><?php endif; ?>
<h4>Adopción de datos históricos (solo-lectura, first-writer-wins)</h4>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
	<?php wp_nonce_field( 'estandar_suite_capture_chatbot_adoption' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_chatbot_adoption_import">
	<?php submit_button( 'Dry-run (previsualizar)', 'secondary', 'preview', false ); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
	<?php wp_nonce_field( 'estandar_suite_capture_chatbot_adoption' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_chatbot_adoption_import">
	<input type="hidden" name="commit" value="1">
	<?php submit_button( 'Importar (commit)', 'primary', 'commit', false ); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('¿Eliminar los datos del chatbot adoptados? Los originales (ecb_*) no se tocan.')">
	<?php wp_nonce_field( 'estandar_suite_capture_chatbot_adoption' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_chatbot_adoption_rollback">
	<?php submit_button( 'Rollback (solo adoptados)', 'delete', 'rollback', false ); ?>
</form>
<p class="description">La adopción proyecta cada fila de <code>ecb_leads</code> a una captura v2 (marcador <code>origin_lead_id</code>) y copia conversaciones/logs/eventos a tablas espejo v2 (<code>estandar_capture_legacy_*</code>). Es idempotente, resumible y reversible. Los originales nunca se modifican.</p>

<?php elseif ( $ss === 'chat' ) : ?>
<p class="description">El motor de este chat es el <strong>agente público de Brain</strong> (misma NLU + conocimiento del sitio + acciones de UI). Capture solo lo presenta; la inteligencia vive en Brain.</p>
<?php if ( class_exists( '\Estandar\Brain\PublicChat' ) ) : ?>
<?php echo \Estandar\Brain\PublicChat::bubble( array(
	'mode'        => 'inline',
	'internal'    => false,
	'endpoint'    => rest_url( 'estandar-suite/brain/v1/agent-public' ),
	'welcome'     => 'Pruébame: pregunta por el sitio o pídeme «abre whatsapp».',
	'placeholder' => 'Prueba el agente público…',
	'id'          => 'estandar-agent-inline-capture',
) ); ?>
<?php else : ?>
<p class="description">Activa el plugin <strong>Brain</strong> para usar el motor del agente.</p>
<?php endif; ?>
<h4>Publicar el chat en el sitio</h4>
<p class="description">Coloca el shortcode <code>[estandar_agent]</code> (del plugin Brain) en la página donde quieras la burbuja de chat. El número de WhatsApp y el email de contacto se toman de los settings de este chatbot (Captación → WhatsApp de escalada, e Integraciones → Email de notificación).</p>

<?php elseif ( $ss === 'conversaciones' ) :
	// CHAT_CONV_PAGING_V1: filtros + paginación + total real.
	$v2_filters = array(
		'channel'   => isset( $_GET['chat_channel'] ) ? sanitize_key( (string) $_GET['chat_channel'] ) : '',
		'q'         => isset( $_GET['chat_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['chat_q'] ) ) : '',
		'date_from' => isset( $_GET['chat_from'] ) ? preg_replace( '/[^0-9-]/', '', (string) $_GET['chat_from'] ) : '',
		'date_to'   => isset( $_GET['chat_to'] ) ? preg_replace( '/[^0-9-]/', '', (string) $_GET['chat_to'] ) : '',
	);
	$v2_filters = array_filter( $v2_filters, static function ( $v ) { return $v !== ''; } );
	$v2_per_page = 50;
	$v2_page     = max( 1, (int) ( $_GET['chat_page'] ?? 1 ) );
	$v2_offset   = ( $v2_page - 1 ) * $v2_per_page;
	$v2_total    = \Estandar\Capture\ChatConversation::count_conversations( $v2_filters );
	$v2_pages    = max( 1, (int) ceil( $v2_total / $v2_per_page ) );
	$v2_conv     = \Estandar\Capture\ChatConversation::conversations( $v2_per_page, $v2_filters, $v2_offset );
	$v2_stats    = \Estandar\Capture\ChatConversation::stats();
	$v2_logs = \Estandar\Capture\ChatLog::recent( 500 );
	$log_by_session = array();
	foreach ( (array) $v2_logs as $g ) {
		$k = (string) ( $g['session_key'] ?? '' );
		if ( $k === '' ) { continue; }
		if ( ! isset( $log_by_session[ $k ] ) ) {
			$log_by_session[ $k ] = array( 'ok' => 0, 'fallback' => 0, 'empty' => 0, 'error' => 0, 'total' => 0 );
		}
		$rr = (string) ( $g['result'] ?? 'ok' );
		$log_by_session[ $k ][ $rr ] = ( $log_by_session[ $k ][ $rr ] ?? 0 ) + 1;
		$log_by_session[ $k ]['total']++;
	}
	$_res_color = array( 'ok' => '#00a32a', 'fallback' => '#dba617', 'empty' => '#d63638', 'error' => '#d63638' );
?>
<h4>Conversaciones (<?php echo (int) $v2_total; ?><?php echo $v2_total !== (int) $v2_stats['conversations'] ? ' filtradas de ' . (int) $v2_stats['conversations'] : ''; ?>)</h4>
<p class="description">Historial del chatbot. Cada fila es una sesión; haz clic en <em>Ver transcripción</em> para leer la conversación completa con su hora. La columna <strong>Resultado</strong> resume las respuestas: verde = normal, ámbar = respuesta genérica (fallback), rojo = vacía o con error.</p>
<form method="get" style="margin:8px 0 12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
	<input type="hidden" name="page" value="estandar-suite-capture">
	<input type="hidden" name="tab" value="config">
	<input type="hidden" name="sub" value="chatbot">
	<input type="hidden" name="ss" value="conversaciones">
	<label style="font-size:12px;">Buscar<br><input type="search" name="chat_q" value="<?php echo esc_attr( $v2_filters['q'] ?? '' ); ?>" placeholder="sesión o mensaje" class="regular-text" style="width:200px;"></label>
	<label style="font-size:12px;">Canal<br><input type="text" name="chat_channel" value="<?php echo esc_attr( $v2_filters['channel'] ?? '' ); ?>" placeholder="web" style="width:90px;"></label>
	<label style="font-size:12px;">Desde<br><input type="date" name="chat_from" value="<?php echo esc_attr( $v2_filters['date_from'] ?? '' ); ?>"></label>
	<label style="font-size:12px;">Hasta<br><input type="date" name="chat_to" value="<?php echo esc_attr( $v2_filters['date_to'] ?? '' ); ?>"></label>
	<button class="button">Filtrar</button>
	<?php if ( $v2_filters ) : ?><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=conversaciones' ) ); ?>">Limpiar</a><?php endif; ?>
</form>
<table class="widefat striped">
	<thead><tr><th>Sesión</th><th>Canal</th><th>Mensajes</th><th>Inicio</th><th>Última actividad</th><th>Resultado</th><th></th></tr></thead>
	<tbody>
	<?php foreach ( $v2_conv as $c ) :
		$sk = (string) $c['session_key'];
		$st = $log_by_session[ $sk ] ?? array( 'ok' => 0, 'fallback' => 0, 'empty' => 0, 'error' => 0, 'total' => 0 );
	?>
		<tr>
			<td><code><?php echo esc_html( $sk ); ?></code></td>
			<td><?php echo esc_html( $c['channel'] ); ?></td>
			<td><?php echo (int) $c['message_count']; ?></td>
			<td><?php echo esc_html( $c['first_message_at'] ); ?></td>
			<td><?php echo esc_html( $c['last_message_at'] ); ?></td>
			<td>
				<?php if ( $st['total'] > 0 ) : ?>
					<span style="color:<?php echo esc_attr( $_res_color['ok'] ); ?>;">● <?php echo (int) $st['ok']; ?></span>
					<?php if ( $st['fallback'] > 0 ) : ?><span style="color:<?php echo esc_attr( $_res_color['fallback'] ); ?>;">● <?php echo (int) $st['fallback']; ?></span><?php endif; ?>
					<?php if ( ( $st['empty'] + $st['error'] ) > 0 ) : ?><span style="color:<?php echo esc_attr( $_res_color['empty'] ); ?>;">● <?php echo (int) ( $st['empty'] + $st['error'] ); ?></span><?php endif; ?>
				<?php else : ?><span class="description">—</span><?php endif; ?>
			</td>
			<td><button type="button" class="button-link es-chat-toggle" data-session="<?php echo esc_attr( $sk ); ?>">Ver transcripción</button></td>
		</tr>
		<tr class="es-chat-detail" data-session-detail="<?php echo esc_attr( $sk ); ?>" style="display:none;">
			<td colspan="7" style="background:#fafafa;">
				<?php $msgs = \Estandar\Capture\ChatConversation::get_messages( $sk, $c['channel'], 200 ); ?>
				<?php if ( empty( $msgs ) ) : ?>
					<p class="description">Sin mensajes.</p>
				<?php else : ?>
					<div style="max-width:820px;padding:6px 0;">
					<?php foreach ( $msgs as $m ) :
						$role  = (string) $m['role'];
						$is_u  = ( $role === 'user' );
						$is_s  = ( $role === 'system' );
						$when  = (string) ( $m['created_at'] ?? '' );
						$when  = $when !== '' ? substr( $when, 11, 5 ) : '';
						$bg    = $is_s ? '#fff8e5' : ( $is_u ? '#e8f0fe' : '#f3f4f6' );
					?>
						<div style="margin:5px 0;text-align:<?php echo $is_u ? 'right' : 'left'; ?>;">
							<span style="display:inline-block;max-width:78%;padding:7px 11px;border-radius:11px;font-size:12px;background:<?php echo esc_attr( $bg ); ?>;text-align:left;">
								<strong style="font-size:11px;color:#646970;"><?php echo esc_html( $is_s ? 'evento' : $role ); ?><?php echo $when !== '' ? ' · ' . esc_html( $when ) : ''; ?>:</strong><br>
								<?php echo nl2br( esc_html( (string) $m['content'] ) ); ?>
							</span>
						</div>
					<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2_conv ) ) : ?><tr><td colspan="7">Sin conversaciones aún. Cuando alguien hable con la burbuja, aparecerá aquí.</td></tr><?php endif; ?>
	</tbody>
</table>
<?php if ( $v2_pages > 1 ) :
	$v2_base = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=conversaciones' );
	foreach ( $v2_filters as $fk => $fv ) {
		$map = array( 'channel' => 'chat_channel', 'q' => 'chat_q', 'date_from' => 'chat_from', 'date_to' => 'chat_to' );
		if ( isset( $map[ $fk ] ) ) { $v2_base = add_query_arg( $map[ $fk ], rawurlencode( $fv ), $v2_base ); }
	}
?>
<div class="tablenav"><div class="tablenav-pages">
	<span class="displaying-num"><?php echo (int) $v2_total; ?> sesiones</span>
	<?php if ( $v2_page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'chat_page', $v2_page - 1, $v2_base ) ); ?>">&laquo; Anterior</a><?php endif; ?>
	<span style="margin:0 8px;">Página <?php echo (int) $v2_page; ?> de <?php echo (int) $v2_pages; ?></span>
	<?php if ( $v2_page < $v2_pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'chat_page', $v2_page + 1, $v2_base ) ); ?>">Siguiente &raquo;</a><?php endif; ?>
</div></div>
<?php endif; ?>
<script>
(function () {
	document.querySelectorAll('.es-chat-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var s = btn.getAttribute('data-session');
			var row = document.querySelector('[data-session-detail="' + s + '"]');
			if (row) { row.style.display = row.style.display === 'none' ? '' : 'none'; }
		});
	});
})();
</script>

<?php elseif ( $ss === 'sugerencias' ) : $v2_friction = \Estandar\Capture\ChatLog::friction( 30 ); ?>
<h4>Sugerencias (autoaprendizaje)</h4>
<p class="description">Detecta preguntas que el chatbot no respondió bien en los últimos 30 días, para incorporarlas a la base de conocimiento.</p>
<table class="widefat striped">
	<thead><tr><th>Métrica</th><th>Valor</th></tr></thead>
	<tbody>
		<tr><td>Interacciones totales</td><td><?php echo (int) ( $v2_friction['total'] ?? 0 ); ?></td></tr>
		<tr><td>Respuestas vacías</td><td><?php echo (int) ( $v2_friction['empty'] ?? 0 ); ?></td></tr>
		<tr><td>Fallbacks (respuestas genéricas)</td><td><?php echo (int) ( $v2_friction['fallbacks'] ?? 0 ); ?></td></tr>
	</tbody>
</table>
<h4>Preguntas repetidas (candidatas a la base de conocimiento)</h4>
<table class="widefat striped">
	<thead><tr><th>Pregunta</th><th>Veces</th></tr></thead>
	<tbody>
	<?php foreach ( ( $v2_friction['repeated'] ?? array() ) as $r ) : ?>
		<tr><td><?php echo esc_html( $r['user_message'] ); ?></td><td><?php echo (int) $r['n']; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $v2_friction['repeated'] ) ) : ?><tr><td colspan="2">Sin preguntas repetidas en el período.</td></tr><?php endif; ?>
	</tbody>
</table>
<p class="description">Copia la pregunta en la sub-tab <strong>IA y conocimiento → Contexto</strong> para que el chatbot la responda bien la próxima vez.</p>
<?php endif; ?>
