<?php
/**
 * Estandar Suite — Insights · Analytics (sub-tab con sub-sub-tabs).
 * Sesiones, tráfico, páginas, eventos y tendencia del analytics legado (ea_*).
 * Sub-sub-tabs: Resumen · Tráfico · Páginas · Eventos · Tendencia · Settings.
 * Variables: $feature, $feature_saved, $feature_view.
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
$total_s  = isset( $feature_view['total_s'] ) ? (int) $feature_view['total_s'] : 0;
$panorama = isset( $feature_view['panorama'] ) ? $feature_view['panorama'] : array();
$chatbot_events        = isset( $feature_view['chatbot_events'] ) ? $feature_view['chatbot_events'] : array();
$chatbot_conversations = isset( $feature_view['chatbot_conversations'] ) ? (int) $feature_view['chatbot_conversations'] : 0;
$chatbot_leads         = isset( $feature_view['chatbot_leads'] ) ? (int) $feature_view['chatbot_leads'] : 0;
$ssubs = \Estandar\Core\Admin::filter_ssubs( 'insights', 'config', 'analytics', \Estandar\Insights\Admin::analytics_ssubs() );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'panorama' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'analytics', '<strong>¿Para qué sirve?</strong> Muestra el <em>analytics</em>: cuántas sesiones y páginas vistas hubo, de dónde vino el tráfico (fuente/medio), qué páginas se visitan más y qué eventos de comportamiento se registraron. <br><br><strong>Dos fuentes, etiquetadas:</strong> <em>Panorama</em> y <em>Embudo web</em> combinan el histórico adoptado con los datos vivos del tracker v2; las pestañas <em>Resumen</em>, <em>Tráfico</em>, <em>Páginas</em>, <em>Eventos</em> y <em>Tendencia</em> muestran el <em>histórico congelado</em> de la instalación anterior (espejo legacy).' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-insights', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'panorama' ) : ?>
<?php
$pan_labels   = isset( $panorama['labels'] ) ? $panorama['labels'] : array();
$pan_sessions = isset( $panorama['sessions'] ) ? $panorama['sessions'] : array();
$pan_pages    = isset( $panorama['pageviews'] ) ? $panorama['pageviews'] : array();
$pan_events   = isset( $panorama['events'] ) ? $panorama['events'] : array();
$pan_range    = isset( $feature_view['panorama_range'] ) ? $feature_view['panorama_range'] : array( 'preset' => '14', 'from' => '', 'to' => '', 'label' => '14 días', 'is_custom' => false );
$pan_presets  = \Estandar\Insights\Admin::panorama_presets();
$pan_conv     = isset( $feature_view['panorama_conv'] ) ? (int) $feature_view['panorama_conv'] : 0;
$pan_total_s  = isset( $feature_view['panorama_s'] ) ? (int) $feature_view['panorama_s'] : 0;
$pan_total_pv = isset( $feature_view['panorama_pv'] ) ? (int) $feature_view['panorama_pv'] : 0;
$pan_total_ev = isset( $feature_view['panorama_ev'] ) ? (int) $feature_view['panorama_ev'] : 0;
$pan_base_url = admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics&ss=panorama' );
$pan_custom   = ! empty( $pan_range['is_custom'] );
$pan_from_v   = $pan_custom ? $pan_range['from'] : '';
$pan_to_v     = $pan_custom ? $pan_range['to'] : '';
?>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;max-width:980px;">
	<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:4px;">
		<strong style="font-size:13px;margin-right:6px;">Rango:</strong>
		<?php foreach ( $pan_presets as $pk => $pl ) : ?>
			<a class="button button-small<?php echo ( ! $pan_custom && $pan_range['preset'] === $pk ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $pan_base_url . '&rng=' . rawurlencode( $pk ) ); ?>"><?php echo esc_html( $pl ); ?></a>
		<?php endforeach; ?>
	</div>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:6px;">
		<input type="hidden" name="page" value="estandar-suite-insights">
		<input type="hidden" name="tab" value="config">
		<input type="hidden" name="sub" value="analytics">
		<input type="hidden" name="ss" value="panorama">
		<input type="hidden" name="rng" value="custom">
		<label class="description" style="margin-right:2px;">Desde</label>
		<input type="date" name="from" value="<?php echo esc_attr( $pan_from_v ); ?>" style="max-width:150px;">
		<label class="description" style="margin:0 2px 0 8px;">Hasta</label>
		<input type="date" name="to" value="<?php echo esc_attr( $pan_to_v ); ?>" style="max-width:150px;">
		<button type="submit" class="button">Aplicar fechas</button>
	</form>
	<p class="description" style="margin:8px 0 0;">Periodo mostrado: <strong><?php echo esc_html( $pan_range['from'] . ' → ' . $pan_range['to'] ); ?></strong> · <?php echo esc_html( $pan_range['label'] ); ?> · fuentes: espejo/histórico + datos vivos (sesiones, pageviews y eventos del v2).</p>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:14px 0;max-width:980px;">
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $pan_total_s; ?></div><div class="description">Sesiones en el periodo</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $pan_total_pv; ?></div><div class="description">Páginas vistas en el periodo</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $pan_total_ev; ?></div><div class="description">Eventos en el periodo (hist. + vivo)</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $pan_conv; ?></div><div class="description">Conversiones en el periodo</div></div>
</div>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:980px;">
	<h3 style="margin:0 0 8px;">Actividad por día <span class="description" style="font-weight:400;">(<?php echo count( $pan_labels ); ?> puntos · haz clic en las series para ocultarlas)</span></h3>
	<?php if ( ! empty( $pan_labels ) ) : ?>
	<div style="position:relative;height:300px;"><canvas id="es-insights-panorama-chart"></canvas></div>
	<?php $es_chart_file = ESTANDAR_SUITE_INSIGHTS_PATH . 'assets/js/chart.umd.min.js'; ?>
	<script src="<?php echo esc_url( add_query_arg( 'ver', file_exists( $es_chart_file ) ? filemtime( $es_chart_file ) : '1', plugins_url( 'assets/js/chart.umd.min.js', ESTANDAR_SUITE_INSIGHTS_FILE ) ) ); ?>"></script>
	<script>
	(function(){
		var c=document.getElementById('es-insights-panorama-chart');
		if(!c||typeof Chart==='undefined'){return;}
		var labels=<?php echo wp_json_encode( $pan_labels ); ?>;
		var sets=[
			{label:'Sesiones',data:<?php echo wp_json_encode( $pan_sessions ); ?>,borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,0.10)',fill:true,tension:0.25,pointRadius:2},
			{label:'Páginas',data:<?php echo wp_json_encode( $pan_pages ); ?>,borderColor:'#00a32a',backgroundColor:'rgba(0,163,42,0.10)',fill:true,tension:0.25,pointRadius:2},
			{label:'Eventos (hist. + v2)',data:<?php echo wp_json_encode( $pan_events ); ?>,borderColor:'#d63638',backgroundColor:'rgba(214,54,56,0.10)',fill:true,tension:0.25,pointRadius:2}
		];
		new Chart(c,{type:'line',data:{labels:labels,datasets:sets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top'},tooltip:{mode:'index'}},scales:{y:{beginAtZero:true,title:{display:true,text:'volumen diario'}},x:{ticks:{maxRotation:0}}}}});
	})();
	</script>
	<?php else : ?><p class="description">Sin datos para la ventana.</p><?php endif; ?>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:980px;margin-top:14px;">
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">
		<h3 style="margin:0 0 8px;font-size:14px;">Páginas más visitadas <span class="description">(top histórico del espejo)</span></h3>
		<table class="widefat striped" style="max-width:100%;">
			<thead><tr><th>Página</th><th style="text-align:right;">Visitas</th></tr></thead>
			<tbody>
			<?php $pan_top = array_slice( $top_pages, 0, 5, true ); foreach ( $pan_top as $pk => $pn ) : ?>
				<tr><td><code style="word-break:break-all;"><?php echo esc_html( $pk ); ?></code></td><td style="text-align:right;"><?php echo (int) $pn; ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $pan_top ) ) : ?><tr><td colspan="2">Sin páginas vistas.</td></tr><?php endif; ?>
			</tbody>
		</table>
	</div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">
		<h3 style="margin:0 0 8px;font-size:14px;">Tráfico por fuente <span class="description">(top histórico del espejo)</span></h3>
		<table class="widefat striped" style="max-width:100%;">
			<thead><tr><th>Fuente</th><th style="text-align:right;">Sesiones</th></tr></thead>
			<tbody>
			<?php $pan_src = array_slice( $by_source, 0, 5, true ); foreach ( $pan_src as $sk => $sn ) : ?>
				<tr><td><?php echo esc_html( $sk ); ?></td><td style="text-align:right;"><?php echo (int) $sn; ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $pan_src ) ) : ?><tr><td colspan="2">Sin datos de fuente.</td></tr><?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<div style="margin-top:10px;max-width:980px;">
	<a class="button" href="<?php echo esc_url( $pan_base_url . '&rng=custom&from=' . gmdate( 'Y-m-d', time() - 364 * DAY_IN_SECONDS ) . '&to=' . gmdate( 'Y-m-d', time() ) ); ?>">Último año</a>
	<a class="button" href="<?php echo esc_url( $pan_base_url . '&rng=all' ); ?>">Todo el histórico</a>
</div>

<?php elseif ( $ss === 'embudo' ) : ?>
<?php
$wf        = isset( $feature_view['web_funnel'] ) ? $feature_view['web_funnel'] : array();
$wf_funnel = isset( $wf['funnel'] ) ? $wf['funnel'] : array();
$wf_stages = isset( $wf_funnel['stages'] ) ? $wf_funnel['stages'] : array();
$wf_counts = isset( $wf['counts'] ) ? $wf['counts'] : array( 'sessions' => 0, 'pageviews' => 0, 'leads' => 0 );
$wf_conv   = isset( $wf_funnel['conversion_sessions_to_leads'] ) ? $wf_funnel['conversion_sessions_to_leads'] : null;
$wf_range  = isset( $wf['range'] ) ? $wf['range'] : array( '', '' );
$wf_base   = admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics&ss=embudo' );
$wf_presets = \Estandar\Insights\Admin::panorama_presets();
$wf_rng    = \Estandar\Insights\Admin::panorama_range();
?>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;max-width:980px;">
	<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:4px;">
		<strong style="font-size:13px;margin-right:6px;">Rango:</strong>
		<?php foreach ( $wf_presets as $pk => $pl ) : ?>
			<a class="button button-small<?php echo ( ! $wf_rng['is_custom'] && $wf_rng['preset'] === $pk ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $wf_base . '&rng=' . rawurlencode( $pk ) ); ?>"><?php echo esc_html( $pl ); ?></a>
		<?php endforeach; ?>
	</div>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:6px;">
		<input type="hidden" name="page" value="estandar-suite-insights">
		<input type="hidden" name="tab" value="config">
		<input type="hidden" name="sub" value="analytics">
		<input type="hidden" name="ss" value="embudo">
		<input type="hidden" name="rng" value="custom">
		<label class="description" style="margin-right:2px;">Desde</label>
		<input type="date" name="from" value="<?php echo esc_attr( $wf_rng['is_custom'] ? $wf_rng['from'] : '' ); ?>" style="max-width:150px;">
		<label class="description" style="margin:0 2px 0 8px;">Hasta</label>
		<input type="date" name="to" value="<?php echo esc_attr( $wf_rng['is_custom'] ? $wf_rng['to'] : '' ); ?>" style="max-width:150px;">
		<button type="submit" class="button">Aplicar fechas</button>
	</form>
	<p class="description" style="margin:8px 0 0;">Embudo web <strong>sesiones → pageviews → leads</strong> (leads = capturas no-spam activas de estandar-capture). Un paso se marca como cuello cuando la conversión respecto al paso anterior es &lt; 30%. Rango: <strong><?php echo esc_html( implode( ' → ', $wf_range ) ); ?></strong>.</p>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0;max-width:980px;">
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $wf_counts['sessions']; ?></div><div class="description">Sesiones</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $wf_counts['pageviews']; ?></div><div class="description">Páginas vistas</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $wf_counts['leads']; ?></div><div class="description">Leads (capturas)</div></div>
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo $wf_conv !== null ? esc_html( $wf_conv . '%' ) : '—'; ?></div><div class="description">Conversión sesiones→leads</div></div>
</div>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:980px;">
	<?php echo \Estandar\Insights\Visual::funnel_svg( \Estandar\Insights\Visual::rows_from_stages( (array) $wf_stages ) ); ?>
</div>

<?php elseif ( $ss === 'resumen' ) : ?>
<p class="description">Histórico adoptado (espejo legacy de la instalación anterior). El tráfico vivo se ve en <strong>Panorama</strong>.</p>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Sesiones</strong></td><td><?php echo (int) $total_s; ?></td></tr>
		<tr><td><strong>Páginas vistas</strong></td><td><?php echo (int) $total_pv; ?></td></tr>
		<tr><td><strong>Eventos de comportamiento</strong></td><td><?php echo (int) $total_ev; ?></td></tr>
		<tr><td><strong>Conversiones</strong></td><td><?php echo (int) $conv; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'trafico' ) : ?>
<p class="description">Histórico adoptado (espejo legacy). El tráfico vivo se ve en <strong>Panorama</strong>.</p>
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
<p class="description">Histórico adoptado (espejo legacy). Las páginas vivas se ven en <strong>Panorama</strong>.</p>
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
<p class="description">Eventos de comportamiento del histórico adoptado (espejo legacy). Los eventos v2 (capture/signal) se ven en <strong>Estado → Datos</strong>.</p>
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
<p class="description">Agregados diarios del histórico adoptado (espejo legacy).</p>
<table class="widefat striped">
	<thead><tr><th>Fecha</th><th>Métrica</th><th>Valor</th></tr></thead>
	<tbody>
	<?php foreach ( $trend as $t ) : ?>
		<tr><td><?php echo esc_html( $t['aggregate_date'] ); ?></td><td><code><?php echo esc_html( $t['metric_key'] ); ?></code></td><td><?php echo (int) $t['metric_value']; ?></td></tr>
	<?php endforeach; ?>
	<?php if ( empty( $trend ) ) : ?><tr><td colspan="3">Sin agregados diarios.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php endif; ?>
