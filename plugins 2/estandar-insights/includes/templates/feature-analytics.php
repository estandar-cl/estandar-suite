<?php
/**
 * Estandar Suite — Insights · Analytics (sub-tab con sub-sub-tabs).
 * Sesiones, tráfico, páginas, eventos y tendencia del analytics legado (ea_*).
 * Sub-sub-tabs: Resumen · Tráfico · Páginas · Eventos · Tendencia · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$sessions  = isset( $feature_view['sessions'] ) ? $feature_view['sessions'] : array();
$events    = isset( $feature_view['events'] ) ? $feature_view['events'] : array();
$trend     = isset( $feature_view['trend'] ) ? $feature_view['trend'] : array();
$by_source = isset( $feature_view['by_source'] ) ? $feature_view['by_source'] : array();
$by_medium = isset( $feature_view['by_medium'] ) ? $feature_view['by_medium'] : array();
$top_pages = isset( $feature_view['top_pages'] ) ? $feature_view['top_pages'] : array();
$conv    = isset( $feature_view['conv'] ) ? (int) $feature_view['conv'] : 0;
$total_pv = isset( $feature_view['total_pv'] ) ? (int) $feature_view['total_pv'] : 0;
$total_ev = isset( $feature_view['total_ev'] ) ? (int) $feature_view['total_ev'] : 0;
$chatbot_events        = isset( $feature_view['chatbot_events'] ) ? $feature_view['chatbot_events'] : array();
$chatbot_conversations = isset( $feature_view['chatbot_conversations'] ) ? (int) $feature_view['chatbot_conversations'] : 0;
$chatbot_leads         = isset( $feature_view['chatbot_leads'] ) ? (int) $feature_view['chatbot_leads'] : 0;
$ssubs = array( 'resumen' => 'Resumen', 'trafico' => 'Tráfico', 'paginas' => 'Páginas', 'eventos' => 'Eventos', 'chatbot' => 'Chatbot', 'tendencia' => 'Tendencia', 'configuracion' => 'Configuración', 'settings' => 'Settings' );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'analytics', '<strong>¿Para qué sirve?</strong> Muestra el <em>analytics</em> de la instalación anterior (v1): cuántas sesiones y páginas vistas hubo, de dónde vino el tráfico (fuente/medio), qué páginas se visitan más y qué eventos de comportamiento se registraron. <br><br>Es el <em>visor del pasado</em>: la analítica real vivió en el plugin legado; aquí la ves sin tocarla. <em>Ejemplo:</em> los agregados diarios te dejan ver la tendencia de tráfico sin abrir el plugin v1.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-insights', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Sesiones</strong></td><td><?php echo count( $sessions ); ?></td></tr>
		<tr><td><strong>Páginas vistas</strong></td><td><?php echo (int) $total_pv; ?></td></tr>
		<tr><td><strong>Eventos de comportamiento</strong></td><td><?php echo (int) $total_ev; ?></td></tr>
		<tr><td><strong>Conversiones</strong></td><td><?php echo (int) $conv; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'trafico' ) : ?>
<h4>Por fuente</h4>
<table class="widefat striped" style="max-width:520px;">
	<thead><tr><th>Fuente</th><th>Sesiones</th></tr></thead>
	<tbody>
	<?php foreach ( $by_source as $k => $n ) : ?>
		<tr><td><?php echo esc_html( $k ); ?></td><td><?php echo (int) $n; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $by_source ) ) : ?><tr><td colspan="2">Sin datos de fuente.</td></tr><?php endif; ?>
	</tbody>
</table>
<h4>Por medio</h4>
<table class="widefat striped" style="max-width:520px;">
	<thead><tr><th>Medio</th><th>Sesiones</th></tr></thead>
	<tbody>
	<?php foreach ( $by_medium as $k => $n ) : ?>
		<tr><td><?php echo esc_html( $k ); ?></td><td><?php echo (int) $n; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $by_medium ) ) : ?><tr><td colspan="2">Sin datos de medio.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'paginas' ) : ?>
<table class="widefat striped">
	<thead><tr><th>Página</th><th>Visitas</th></tr></thead>
	<tbody>
	<?php foreach ( $top_pages as $k => $n ) : ?>
		<tr><td><code><?php echo esc_html( $k ); ?></code></td><td><?php echo (int) $n; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $top_pages ) ) : ?><tr><td colspan="2">Sin páginas vistas.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'eventos' ) : ?>
<table class="widefat striped">
	<thead><tr><th>Fecha</th><th>Tipo</th><th>Etiqueta</th><th>Valor</th><th>Página</th></tr></thead>
	<tbody>
	<?php foreach ( $events as $e ) : ?>
		<tr>
			<td><?php echo esc_html( $e['occurred_at'] ); ?></td>
			<td><code><?php echo esc_html( $e['event_type'] ); ?></code></td>
			<td><?php echo esc_html( $e['event_label'] ); ?></td>
			<td><?php echo esc_html( $e['event_value'] ); ?></td>
			<td><code><?php echo esc_html( (string) $e['page_url'] ); ?></code></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $events ) ) : ?><tr><td colspan="5">Sin eventos.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'chatbot' ) : $cb_by_type = array(); $cb_by_channel = array(); foreach ( $chatbot_events as $ce ) { $cb_by_type[ $ce['event_name'] ] = ( $cb_by_type[ $ce['event_name'] ] ?? 0 ) + 1; $ch = $ce['channel'] ? $ce['channel'] : '—'; $cb_by_channel[ $ch ] = ( $cb_by_channel[ $ch ] ?? 0 ) + 1; } ?>
<p><strong>Conversaciones: <?php echo (int) $chatbot_conversations; ?></strong> · <strong>Leads del chatbot: <?php echo (int) $chatbot_leads; ?></strong></p>
<h4>Eventos del chatbot por tipo</h4>
<table class="widefat striped" style="max-width:520px;">
	<thead><tr><th>Tipo</th><th>Cantidad</th></tr></thead>
	<tbody>
	<?php arsort( $cb_by_type ); foreach ( $cb_by_type as $t => $n ) : ?><tr><td><code><?php echo esc_html( $t ); ?></code></td><td><?php echo (int) $n; ?></td></tr><?php endforeach; ?>
	<?php if ( empty( $cb_by_type ) ) : ?><tr><td colspan="2">Sin eventos del chatbot.</td></tr><?php endif; ?>
	</tbody>
</table>
<h4>Eventos recientes del chatbot (<?php echo count( $chatbot_events ); ?>)</h4>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Tipo</th><th>Canal</th><th>Fecha</th></tr></thead>
	<tbody>
	<?php foreach ( $chatbot_events as $ce ) : ?>
		<tr><td><?php echo (int) $ce['id']; ?></td><td><code><?php echo esc_html( $ce['event_name'] ); ?></code></td><td><?php echo esc_html( $ce['channel'] ); ?></td><td><?php echo esc_html( $ce['created_at'] ?? $ce['event_time'] ?? '' ); ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $chatbot_events ) ) : ?><tr><td colspan="4">Sin eventos del chatbot.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'tendencia' ) : ?>
<table class="widefat striped">
	<thead><tr><th>Fecha</th><th>Métrica</th><th>Valor</th></tr></thead>
	<tbody>
	<?php foreach ( $trend as $t ) : ?>
		<tr><td><?php echo esc_html( $t['aggregate_date'] ); ?></td><td><code><?php echo esc_html( $t['metric_key'] ); ?></code></td><td><?php echo (int) $t['metric_value']; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $trend ) ) : ?><tr><td colspan="3">Sin agregados diarios.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( $ss === 'configuracion' ) : ?>
<?php $agroups = \Estandar\Insights\Admin::analytics_settings_groups(); $agroup = \Estandar\Core\Admin::resolve_sub( $agroups, 'tracking' ); ?>
<?php if ( isset( $_GET['analytics_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de analítica guardada.</p></div><?php endif; ?>
<table class="widefat" style="max-width:520px;">
	<tr><th style="width:180px;">Área</th><td>
		<?php foreach ( array_keys( $agroups ) as $gk ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'ss' => 'configuracion', 'fd' => $gk ), remove_query_arg( array( 'analytics_saved' ) ) ) ); ?>" class="button button-small<?php echo $gk === $agroup ? ' button-primary' : ''; ?>"><?php echo esc_html( ucfirst( $gk ) ); ?></a>
		<?php endforeach; ?>
	</td></tr>
</table>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_analytics_settings', 'estandar_suite_insights_analytics_settings', $agroups[ $agroup ], '', array( 'group' => $agroup ) ); ?>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
