<?php
/**
 * Estandar Suite — Capture · Leads (sub-tab con sub-sub-tabs).
 * Rescata estandar-leads: portal, reportes, email, autorespuesta, exportaciones,
 * clientes, operadores y configuración (integraciones + calidad).
 * Sub-sub-tabs: Resumen · Leads · Reportes · Email · Autorespuesta · Exportaciones
 * · Clientes · Operadores · Configuración · Settings.
 * Variables: $feature, $feature_view, $feature_fields, $feature_option, $feature_saved.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$leads       = isset( $feature_view['leads'] ) ? $feature_view['leads'] : array();
$submissions = isset( $feature_view['submissions'] ) ? (int) $feature_view['submissions'] : 0;
$lead_events = isset( $feature_view['lead_events'] ) ? (int) $feature_view['lead_events'] : 0;
$v2_submissions = isset( $feature_view['v2_submissions'] ) ? (int) $feature_view['v2_submissions'] : 0;
$customers   = isset( $feature_view['customers'] ) ? $feature_view['customers'] : array();
$operators   = isset( $feature_view['operators'] ) ? $feature_view['operators'] : array();
$chatbot_leads = isset( $feature_view['chatbot_leads'] ) ? $feature_view['chatbot_leads'] : array();
$groups      = \Estandar\Capture\Admin::leads_settings_groups();

$ssubs = array(
	'resumen' => 'Resumen', 'leads' => 'Leads', 'chatbot' => 'Chatbot', 'reportes' => 'Reportes', 'email' => 'Email',
	'autorespuesta' => 'Autorespuesta', 'exportaciones' => 'Exportaciones', 'clientes' => 'Clientes',
	'operadores' => 'Operadores', 'configuracion' => 'Configuración', 'settings' => 'Settings',
);
$ss = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Para qué sirve?</strong> Gestiona el ciclo del lead: portal de leads, reportes, email de notificación, autorespuesta, exportaciones, clientes y operadores. Las integraciones y el motor de calidad se configuran en la pestaña Settings.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-capture', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( isset( $_GET['leads_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings de leads guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Leads legados</strong></td><td><?php echo count( $leads ); ?></td></tr>
		<tr><td><strong>Leads del chatbot</strong></td><td><?php echo count( $chatbot_leads ); ?></td></tr>
		<tr><td><strong>Submissions históricas</strong></td><td><?php echo (int) $submissions; ?></td></tr>
		<tr><td><strong>Eventos de lead</strong></td><td><?php echo (int) $lead_events; ?></td></tr>
		<tr><td><strong>Capturas v2</strong></td><td><?php echo (int) $v2_submissions; ?></td></tr>
		<tr><td><strong>Clientes</strong></td><td><?php echo count( $customers ); ?></td></tr>
		<tr><td><strong>Operadores</strong></td><td><?php echo count( $operators ); ?></td></tr>
	</tbody>
</table>
<p class="description">Todos los leads viven aquí (Leads · Chatbot · Clientes · Operadores); el chatbot solo captura y deriva.</p>

<?php elseif ( $ss === 'leads' ) : ?>
<h4>Leads (<?php echo count( $leads ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Teléfono</th><th>Estado</th><th>Primer canal</th><th>Primera vista</th></tr></thead>
	<tbody>
	<?php foreach ( $leads as $l ) : ?>
		<tr>
			<td><?php echo (int) $l['id']; ?></td>
			<td><?php echo esc_html( $l['name'] ); ?></td>
			<td><?php echo esc_html( $l['email_normalized'] ? $l['email_normalized'] : $l['email'] ); ?></td>
			<td><?php echo esc_html( $l['phone_normalized'] ? $l['phone_normalized'] : $l['phone'] ); ?></td>
			<td><?php echo esc_html( $l['status'] ); ?></td>
			<td><?php echo esc_html( $l['first_source_plugin'] ); ?></td>
			<td><?php echo esc_html( $l['first_seen_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $leads ) ) : ?><tr><td colspan="7">Sin leads.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'chatbot' ) : ?>
<h4>Leads capturados por el chatbot (<?php echo count( $chatbot_leads ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Email</th><th>Teléfono</th><th>Última interacción</th></tr></thead>
	<tbody>
	<?php foreach ( $chatbot_leads as $l ) : ?>
		<tr><td><?php echo (int) $l['id']; ?></td><td><?php echo esc_html( $l['email'] ); ?></td><td><?php echo esc_html( $l['phone'] ); ?></td><td><?php echo esc_html( $l['last_interaction_at'] ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $chatbot_leads ) ) : ?><tr><td colspan="4">Sin leads de chatbot.</td></tr><?php endif; ?>
	</tbody>
</table>
<p class="description">Los leads que capta el chatbot viven en el área de Leads; la analítica de sus eventos está en Insights → Analytics → Chatbot.</p>

<?php elseif ( $ss === 'reportes' ) : $by_status = array(); $by_source = array(); foreach ( $leads as $l ) { $by_status[ $l['status'] ? $l['status'] : '—' ] = ( $by_status[ $l['status'] ? $l['status'] : '—' ] ?? 0 ) + 1; $src = $l['first_source_plugin'] ? $l['first_source_plugin'] : '—'; $by_source[ $src ] = ( $by_source[ $src ] ?? 0 ) + 1; } ?>
<h4>Leads por estado</h4>
<table class="widefat striped" style="max-width:520px;">
	<thead><tr><th>Estado</th><th>Cantidad</th></tr></thead>
	<tbody>
	<?php arsort( $by_status ); foreach ( $by_status as $k => $n ) : ?><tr><td><?php echo esc_html( $k ); ?></td><td><?php echo (int) $n; ?></td></tr><?php endforeach; ?>
	<?php if ( empty( $by_status ) ) : ?><tr><td colspan="2">Sin datos.</td></tr><?php endif; ?>
	</tbody>
</table>
<h4>Leads por canal</h4>
<table class="widefat striped" style="max-width:520px;">
	<thead><tr><th>Canal</th><th>Cantidad</th></tr></thead>
	<tbody>
	<?php arsort( $by_source ); foreach ( $by_source as $k => $n ) : ?><tr><td><?php echo esc_html( $k ); ?></td><td><?php echo (int) $n; ?></td></tr><?php endforeach; ?>
	<?php if ( empty( $by_source ) ) : ?><tr><td colspan="2">Sin datos.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'email' ) : ?>
<h4>Email de notificación</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_leads_settings', 'estandar_suite_capture_leads_settings', $groups['email'], '', array( 'group' => 'email' ) ); ?>

<?php elseif ( $ss === 'autorespuesta' ) : ?>
<h4>Autorespuesta automática</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_leads_settings', 'estandar_suite_capture_leads_settings', $groups['autorespuesta'], '', array( 'group' => 'autorespuesta' ) ); ?>

<?php elseif ( $ss === 'exportaciones' ) : ?>
<h4>Exportaciones (disponibles)</h4>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:260px;"><strong>Submissions históricas</strong></td><td><?php echo (int) $submissions; ?></td></tr>
		<tr><td><strong>Eventos de lead</strong></td><td><?php echo (int) $lead_events; ?></td></tr>
		<tr><td><strong>Clientes</strong></td><td><?php echo count( $customers ); ?></td></tr>
		<tr><td><strong>Operadores</strong></td><td><?php echo count( $operators ); ?></td></tr>
	</tbody>
</table>
<p class="description">Los datos se exportan desde las vistas Leads, Clientes y Operadores (read-only legado).</p>

<?php elseif ( $ss === 'clientes' ) : ?>
<h4>Clientes (<?php echo count( $customers ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Proyecto</th><th>Etapa</th></tr></thead>
	<tbody>
	<?php foreach ( $customers as $c ) : ?>
		<tr>
			<td><?php echo (int) $c['id']; ?></td>
			<td><?php echo esc_html( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) ); ?></td>
			<td><?php echo esc_html( $c['email'] ); ?></td>
			<td><?php echo esc_html( $c['project_slug'] ); ?></td>
			<td><?php echo esc_html( $c['customer_stage'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $customers ) ) : ?><tr><td colspan="5">Sin clientes.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'operadores' ) : ?>
<h4>Operadores (<?php echo count( $operators ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Proyecto</th><th>WhatsApp</th><th>Activo</th></tr></thead>
	<tbody>
	<?php foreach ( $operators as $o ) : ?>
		<tr>
			<td><?php echo (int) $o['id']; ?></td>
			<td><?php echo esc_html( $o['name'] ); ?></td>
			<td><?php echo esc_html( $o['email'] ); ?></td>
			<td><?php echo esc_html( $o['project_slug'] ); ?></td>
			<td><?php echo esc_html( $o['whatsapp_number'] ); ?></td>
			<td><?php echo esc_html( $o['active'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $operators ) ) : ?><tr><td colspan="6">Sin operadores.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'configuracion' ) : ?>
<h4>Integraciones con ads</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_leads_settings', 'estandar_suite_capture_leads_settings', $groups['integraciones'], '', array( 'group' => 'integraciones' ) ); ?>
<h4>Calidad y compliance</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_leads_settings', 'estandar_suite_capture_leads_settings', $groups['calidad'], '', array( 'group' => 'calidad' ) ); ?>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', $feature_option, $feature_fields, $feature ); ?>
<?php endif; ?>
