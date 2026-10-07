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

$ssubs = array(
	'resumen' => 'Resumen',
	'conversacion' => 'Conversación',
	'captacion' => 'Captación de leads',
	'ia' => 'IA y conocimiento',
	'apariencia' => 'Apariencia',
	'chat' => 'Chat (agente público)',
	'conversaciones' => 'Conversaciones',
	'logs' => 'Logs',
	'sugerencias' => 'Sugerencias',
	'integraciones' => 'Integraciones',
	'sistema' => 'Sistema',
);
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
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Conversaciones</strong></td><td><?php echo count( $conversations ); ?></td></tr>
		<tr><td><strong>Leads capturados</strong></td><td><?php echo count( $leads ); ?></td></tr>
		<tr><td><strong>Eventos</strong></td><td><?php echo count( $events ); ?></td></tr>
		<tr><td><strong>Registros (logs)</strong></td><td><?php echo count( $logs ); ?></td></tr>
	</tbody>
</table>
<p class="description">La <strong>analítica</strong> de los eventos del chatbot vive en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics&ss=chatbot' ) ); ?>">Insights → Analytics → Chatbot</a>, y los <strong>leads</strong> capturados se gestionan en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=leads&ss=chatbot' ) ); ?>">Capture → Leads → Chatbot</a>.</p>
<p class="description">Estado del chatbot. Configuración en las sub-tabs Conversación · Captación · IA · Apariencia · Integraciones · Sistema.</p>

<?php elseif ( in_array( $ss, array( 'conversacion', 'captacion', 'ia', 'apariencia', 'integraciones', 'sistema' ), true ) ) : ?>
<h4><?php echo esc_html( $ssubs[ $ss ] ); ?></h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_chatbot_settings', 'estandar_suite_capture_chatbot_settings', $groups[ $ss ], '', array( 'group' => $ss ) ); ?>

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

<?php elseif ( $ss === 'conversaciones' ) : ?>
<h4>Conversaciones (<?php echo count( $conversations ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Canal</th><th>Estado</th><th>Email</th><th>Teléfono</th><th>Mensajes</th><th>Última actividad</th></tr></thead>
	<tbody>
	<?php foreach ( $conversations as $c ) : ?>
		<tr>
			<td><?php echo (int) $c['id']; ?></td>
			<td><?php echo esc_html( $c['channel'] ); ?></td>
			<td><?php echo esc_html( $c['conversation_status'] ); ?></td>
			<td><?php echo esc_html( $c['lead_email'] ); ?></td>
			<td><?php echo esc_html( $c['lead_phone'] ); ?></td>
			<td><?php echo (int) $c['message_count']; ?></td>
			<td><?php echo esc_html( $c['last_message_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $conversations ) ) : ?><tr><td colspan="7">Sin conversaciones.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'logs' ) : ?>
<h4>Registro pregunta → respuesta (<?php echo count( $logs ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Fecha</th><th>Pregunta</th><th>Respuesta</th></tr></thead>
	<tbody>
	<?php foreach ( $logs as $g ) : ?>
		<tr><td><?php echo (int) $g['id']; ?></td><td><?php echo esc_html( $g['datetime'] ); ?></td><td><?php echo esc_html( wp_trim_words( (string) $g['question'], 12 ) ); ?></td><td><?php echo esc_html( wp_trim_words( (string) $g['answer'], 16 ) ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $logs ) ) : ?><tr><td colspan="4">Sin registros.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'sugerencias' ) : $sin_resp = array(); foreach ( $logs as $g ) { $a = trim( (string) $g['answer'] ); if ( $a === '' || strlen( $a ) < 3 ) { $sin_resp[] = $g; } } ?>
<h4>Preguntas sin responder (<?php echo count( $sin_resp ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>Fecha</th><th>Pregunta</th></tr></thead>
	<tbody>
	<?php foreach ( $sin_resp as $g ) : ?>
		<tr><td><?php echo esc_html( $g['datetime'] ); ?></td><td><?php echo esc_html( (string) $g['question'] ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $sin_resp ) ) : ?><tr><td colspan="2">Sin preguntas sin responder.</td></tr><?php endif; ?>
	</tbody>
</table>
<p class="description">Estas preguntas pueden incorporarse a la base de conocimiento (IA y conocimiento).</p>
<?php endif; ?>
