<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'analitica' ) ) {
	wp_die( 'No autorizado.' );
}

$days = isset($_GET['days']) ? max( 7, min( 90, intval( $_GET['days'] ) ) ) : 30;
$geo_channel = isset( $_GET['geo_channel'] ) ? sanitize_key( wp_unslash( $_GET['geo_channel'] ) ) : '';
$geo_page    = isset( $_GET['geo_page'] ) ? esc_url_raw( wp_unslash( $_GET['geo_page'] ) ) : '';
$summary = function_exists( 'estandar_chatbot_get_analytics_summary' )
	? estandar_chatbot_get_analytics_summary( $days )
	: [];

// Previous period for trend comparison
$summary_prev = function_exists( 'estandar_chatbot_get_analytics_summary' )
	? estandar_chatbot_get_analytics_summary( $days * 2 )
	: [];
// prev_period = double_period minus current_period
$_prev_convs  = max( 0, (int) ( $summary_prev['total_conversations'] ?? 0 ) - (int) ( $summary['total_conversations'] ?? 0 ) );
$_prev_leads  = max( 0, (int) ( $summary_prev['total_leads'] ?? 0 ) - (int) ( $summary['total_leads'] ?? 0 ) );
$_prev_cr     = $_prev_convs > 0 ? round( ( $_prev_leads / $_prev_convs ) * 100, 1 ) : 0;
$_curr_cr     = (float) ( $summary['conversion_rate'] ?? 0 );
$_curr_abandon = (float) ( $summary['abandonment_rate'] ?? 0 );
$_prev_abandon = (float) ( $summary_prev['abandonment_rate'] ?? 0 );

function ecb_trend_arrow( $current, $previous, $invert = false ) {
	if ( $previous == 0 ) return '';
	$diff = $current - $previous;
	if ( abs( $diff ) < 0.5 ) return '<span style="color:#646970;font-size:13px;" title="Sin cambio vs período anterior">→</span>';
	$up = $diff > 0;
	$good = $invert ? ! $up : $up;
	$color  = $good ? '#008a20' : '#cc1818';
	$symbol = $up ? '↑' : '↓';
	$pct    = $previous > 0 ? round( abs( $diff / $previous ) * 100 ) : 0;
	return '<span style="color:' . $color . ';font-size:13px;margin-left:4px;" title="' . esc_attr( $symbol . ' ' . $pct . '% vs período anterior' ) . '">' . $symbol . ' ' . $pct . '%</span>';
}
$lead_insights = function_exists( 'estandar_chatbot_get_lead_conversion_insights' )
	? estandar_chatbot_get_lead_conversion_insights( $days )
	: [ 'top_messages' => [], 'top_channels' => [], 'top_pages' => [] ];
$conversation_insights = function_exists( 'estandar_chatbot_get_conversation_learning_insights' )
	? estandar_chatbot_get_conversation_learning_insights( $days )
	: [ 'lost_high_intent' => [] ];
$metric_insights = function_exists( 'estandar_chatbot_get_metric_insights' )
	? estandar_chatbot_get_metric_insights( $days )
	: [];
$geo_filter_options = function_exists( 'estandar_chatbot_get_geo_filter_options' )
	? estandar_chatbot_get_geo_filter_options( $days, 800 )
	: [ 'channels' => [], 'pages' => [] ];
$geo_map = function_exists( 'estandar_chatbot_get_geo_interaction_points' )
	? estandar_chatbot_get_geo_interaction_points( $days, 120, [ 'channel' => $geo_channel, 'page' => $geo_page ] )
	: [ 'points' => [], 'unique_places' => 0, 'mapped_interactions' => 0, 'provider' => '' ];

$timeline = (array) ( $summary['timeline'] ?? [] );
$timeline_max = 1;
$timeline_label_step = $days >= 90 ? 7 : ( $days >= 30 ? 3 : 1 );
$total_conversations = (int) ( $summary['total_conversations'] ?? 0 );
$total_leads = (int) ( $summary['total_leads'] ?? 0 );
$conversion_rate = (float) ( $summary['conversion_rate'] ?? 0 );

// El gráfico y su lectura deben derivar del MISMO dato: el timeline diario.
// El timeline llega zero-filled (N filas siempre), así que "hay datos" se
// decide sumando valores reales, nunca contando filas.
$timeline_points = 0;
foreach ( $timeline as $row ) {
	$timeline_points += (int) ( $row['conversations'] ?? 0 ) + (int) ( $row['leads'] ?? 0 ) + (int) ( $row['events'] ?? 0 );
	$timeline_max = max(
		$timeline_max,
		(int) ( $row['conversations'] ?? 0 ),
		(int) ( $row['leads'] ?? 0 ),
		(int) ( $row['events'] ?? 0 )
	);
}
$timeline_has_data = $timeline_points > 0;
$timeline_low_data = $timeline_has_data && $timeline_points < 10;

$trend_note = '';
if ( ! $timeline_has_data ) {
	$trend_note = 'Aún no hay suficientes datos para mostrar tendencia.';
} elseif ( $timeline_low_data ) {
	$trend_note = 'Aún se están acumulando datos. Revisa nuevamente en unos días.';
} elseif ( $total_conversations > 0 && $total_leads === 0 ) {
	$trend_note = 'Hay conversaciones, pero todavía no hay leads capturados en esta ventana. Eso suele indicar que el flujo no está cerrando o que el CTA no está empujando acción.';
} elseif ( $total_conversations > 0 && $conversion_rate < 5 ) {
	$trend_note = 'Hay interés, pero la conversión sigue baja. Conviene simplificar respuestas y reforzar el CTA directo antes.';
} elseif ( $total_conversations > 0 && $total_leads > 0 ) {
	$trend_note = 'El embudo ya está generando movimiento. Ahora la oportunidad está en reducir fricción y acortar el tiempo a lead.';
} else {
	$trend_note = 'Aún no hay suficiente actividad en esta ventana para leer una tendencia clara.';
}
?>
<div>
	<h3 class="ecb-section-title">Analítica</h3>
	<p class="ecb-section-copy">Métricas que ayudan a decidir qué corregir, qué acelerar y dónde está perdiendo tracción el chatbot.</p>

	<div class="ecb-toolbar">
		<form method="get" id="ecb-analytics-range-form" style="margin:0;display:flex;gap:8px;align-items:center;">
			<input type="hidden" name="page" value="estandar-chatbot" />
			<input type="hidden" name="tab" value="analitica" />
			<label>
				Ventana:
				<select name="days" id="ecb-analytics-days">
					<option value="7" <?php selected( $days, 7 ); ?>>7 días</option>
					<option value="30" <?php selected( $days, 30 ); ?>>30 días</option>
					<option value="90" <?php selected( $days, 90 ); ?>>90 días</option>
				</select>
			</label>
			<label>
				Canal:
				<select name="geo_channel" id="ecb-analytics-channel">
					<option value="">Todos</option>
					<?php foreach ( (array) ( $geo_filter_options['channels'] ?? [] ) as $channel ) : ?>
						<option value="<?php echo esc_attr( (string) $channel ); ?>" <?php selected( $geo_channel, (string) $channel ); ?>>
							<?php echo esc_html( function_exists( 'estandar_chatbot_get_channel_label' ) ? estandar_chatbot_get_channel_label( $channel ) : $channel ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="min-width:260px;">
				Página:
				<select name="geo_page" id="ecb-analytics-page" style="min-width:260px;">
					<option value="">Todas</option>
					<?php foreach ( (array) ( $geo_filter_options['pages'] ?? [] ) as $page ) : ?>
						<option value="<?php echo esc_attr( (string) $page ); ?>" <?php selected( $geo_page, (string) $page ); ?>>
							<?php echo esc_html( (string) $page ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>
	</div>

	<div class="ecb-card-grid" style="margin:12px 0;">
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Conversión chat → lead</strong></p>
			<p class="ecb-overview-number"><?php echo esc_html( (string) $_curr_cr ); ?>% <?php echo ecb_trend_arrow( $_curr_cr, $_prev_cr ); ?></p>
			<?php if ( $_prev_cr > 0 ) : ?><p class="description" style="margin:4px 0 0;">Período anterior: <?php echo esc_html( (string) $_prev_cr ); ?>%</p><?php endif; ?>
		</div>
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Abandono</strong></p>
			<p class="ecb-overview-number"><?php echo esc_html( (string) ( $summary['abandonment_rate'] ?? 0 ) ); ?>% <?php echo ecb_trend_arrow( (float)( $summary['abandonment_rate'] ?? 0 ), $_prev_abandon, true ); ?></p>
		</div>
		<div class="ecb-card">
			<p class="ecb-metric"><strong>Tiempo promedio a lead</strong></p>
			<?php $avg_ttl = (int)( $summary['avg_time_to_lead_minutes'] ?? 0 ); ?>
			<p class="ecb-overview-number"><?php echo $avg_ttl > 0 ? esc_html( (string)$avg_ttl ) . ' min' : '<span style="color:#646970;font-size:14px;">Sin datos</span>'; ?></p>
		</div>
		<div class="ecb-card">
			<p class="ecb-metric"><strong>% uso modo asistido</strong></p>
			<?php $assisted = (float)( $summary['assisted_usage_rate'] ?? 0 ); ?>
			<p class="ecb-overview-number"><?php echo esc_html( (string)$assisted ); ?>%
				<?php if ( $assisted >= 100 ) : ?><span style="font-size:12px;color:#646970;" title="Puede indicar datos insuficientes o que todos los leads pasaron por asistencia"> ⚠️</span><?php endif; ?>
			</p>
		</div>
	</div>

	<div class="ecb-card" style="margin:12px 0;">
		<h4 style="margin-top:0;">Insights automáticos</h4>
		<p class="description" style="margin-top:0;">Interpretación directa de métricas para convertir números en decisiones rápidas.</p>
		<div class="ecb-action-stack">
			<?php foreach ( (array) $metric_insights as $insight ) : ?>
				<?php $severity = sanitize_key( (string) ( $insight['severity'] ?? 'low' ) ); ?>
				<div class="ecb-card ecb-insight-card ecb-insight-card--<?php echo esc_attr( $severity ); ?>" style="padding:12px;">
					<p class="ecb-metric"><strong><?php echo esc_html( (string) ( $insight['title'] ?? '' ) ); ?></strong></p>
					<p class="description" style="margin:8px 0 0;"><strong>Lectura:</strong> <?php echo esc_html( (string) ( $insight['message'] ?? '' ) ); ?></p>
					<p class="description" style="margin:8px 0 0;"><strong>Qué haría ahora:</strong> <?php echo esc_html( (string) ( $insight['action'] ?? '' ) ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="ecb-card-grid" style="grid-template-columns:1.15fr 0.85fr;align-items:start;margin:12px 0;">
		<div class="ecb-card">
			<h4 style="margin-top:0;">Tendencia últimos <?php echo esc_html( (string) $days ); ?> días</h4>
			<?php if ( ! $timeline_has_data ) : ?>
				<div style="padding:36px 20px;text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:10px;">
					<p style="margin:0;font-size:14px;color:#6b7280;font-weight:600;">Aún no hay suficientes datos para mostrar tendencia.</p>
					<p class="description" style="margin:6px 0 0;">El gráfico aparecerá automáticamente cuando se registren conversaciones, leads o eventos en esta ventana.</p>
				</div>
			<?php else : ?>
			<div class="ecb-analytics-chart-wrap">
				<div class="ecb-analytics-chart ecb-analytics-chart--days-<?php echo esc_attr( (string) $days ); ?>">
				<?php foreach ( $timeline as $index => $row ) :
					$conv_v  = (int) ( $row['conversations'] ?? 0 );
					$lead_v  = (int) ( $row['leads'] ?? 0 );
					$event_v = (int) ( $row['events'] ?? 0 );
					// Cero = baseline de 2px gris (no inventa volumen); valor real = mínimo 4px.
					$conv_h  = $conv_v > 0 ? max( 4, (int) round( $conv_v / $timeline_max * 160 ) ) : 2;
					$lead_h  = $lead_v > 0 ? max( 4, (int) round( $lead_v / $timeline_max * 160 ) ) : 2;
					$event_h = $event_v > 0 ? max( 4, (int) round( $event_v / $timeline_max * 160 ) ) : 2;
					$show_label = $index % $timeline_label_step === 0 || $index === count( $timeline ) - 1;
				?>
				<div class="ecb-analytics-chart__group">
					<div class="ecb-analytics-chart__bars">
						<div class="ecb-bar ecb-bar--conversations" style="height:<?php echo esc_attr( (string) $conv_h ); ?>px;<?php echo $conv_v === 0 ? 'opacity:.25;' : ''; ?>" title="<?php echo esc_attr( (string)( $row['label'] ?? '' ) . ': ' . (string) $conv_v . ' conversaciones' ); ?>"></div>
						<div class="ecb-bar ecb-bar--leads"         style="height:<?php echo esc_attr( (string) $lead_h ); ?>px;<?php echo $lead_v === 0 ? 'opacity:.25;' : ''; ?>"  title="<?php echo esc_attr( (string)( $row['label'] ?? '' ) . ': ' . (string) $lead_v . ' leads' ); ?>"></div>
						<div class="ecb-bar ecb-bar--events"        style="height:<?php echo esc_attr( (string) $event_h ); ?>px;<?php echo $event_v === 0 ? 'opacity:.25;' : ''; ?>" title="<?php echo esc_attr( (string)( $row['label'] ?? '' ) . ': ' . (string) $event_v . ' eventos' ); ?>"></div>
					</div>
					<div class="ecb-analytics-chart__label"><?php echo $show_label ? esc_html( (string) ( $row['label'] ?? '' ) ) : '&nbsp;'; ?></div>
				</div>
				<?php endforeach; ?>
				</div>
			</div>
			<div class="ecb-chart-legend" aria-label="Leyenda del gráfico">
				<span class="ecb-chart-legend__item"><span class="ecb-chart-legend__dot ecb-chart-legend__dot--conversations"></span>Conversaciones</span>
				<span class="ecb-chart-legend__item"><span class="ecb-chart-legend__dot ecb-chart-legend__dot--leads"></span>Leads</span>
				<span class="ecb-chart-legend__item"><span class="ecb-chart-legend__dot ecb-chart-legend__dot--events"></span>Eventos</span>
				<span class="ecb-chart-legend__item" style="color:#9ca3af;">Escala: máx <?php echo esc_html( (string) $timeline_max ); ?>/día</span>
			</div>
			<p class="description" style="margin-top:8px;">Lectura rápida: conversaciones, leads y eventos por día.</p>
			<?php endif; ?>
			<div class="ecb-chart-reading">
				<p class="ecb-metric"><strong>Lectura de la tendencia:</strong></p>
				<p class="description" style="margin:6px 0 0;"><?php echo esc_html( $trend_note ); ?></p>
			</div>
		</div>

		<?php
		$lost_high_intent_count = count( (array) ( $conversation_insights['lost_high_intent'] ?? [] ) );
		$has_opportunity_data   = $lost_high_intent_count > 0 || ! empty( $lead_insights['top_messages'] );
		?>
		<div class="ecb-card" <?php echo $has_opportunity_data ? '' : 'style="opacity:.75;"'; ?>>
			<h4 style="margin-top:0;">Oportunidades detectadas</h4>
			<?php if ( $lost_high_intent_count > 0 ) : ?>
				<p class="ecb-metric"><strong>Leads perdidos de intención alta:</strong> <?php echo esc_html( (string) $lost_high_intent_count ); ?></p>
				<p class="description" style="margin:4px 0 0;">Conversaciones con señales claras de compra que terminaron sin datos de contacto.</p>
				<?php if ( $lost_high_intent_count < 3 ) : ?>
					<p class="description" style="margin:4px 0 0;color:#9ca3af;">Muestra muy pequeña: revisa el caso puntual antes de sacar conclusiones.</p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! empty( $lead_insights['top_messages'] ) ) : ?>
				<p class="ecb-metric" style="margin-top:10px;"><strong>Top mensajes que convierten:</strong></p>
				<ul style="margin:8px 0 0 18px;">
					<?php foreach ( (array) $lead_insights['top_messages'] as $item ) : ?>
						<li><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?> · <?php echo esc_html( (string) ( $item['count'] ?? 0 ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! $has_opportunity_data ) : ?>
				<p class="description" style="margin:0;">Aún no hay suficiente data para detectar oportunidades. Este bloque se activará solo cuando existan patrones reales.</p>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$has_channels = ! empty( $lead_insights['top_channels'] );
	$has_pages    = ! empty( $lead_insights['top_pages'] );
	if ( $has_channels || $has_pages ) :
	?>
	<div class="ecb-card-grid" style="grid-template-columns:1fr 1fr;align-items:start;">
		<?php if ( $has_channels ) : ?>
		<div class="ecb-card">
			<h4 style="margin-top:0;">Canales que mejor convierten</h4>
			<ul style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $lead_insights['top_channels'] as $channel => $qty ) : ?>
					<li><?php echo esc_html( function_exists( 'estandar_chatbot_get_channel_label' ) ? estandar_chatbot_get_channel_label( $channel ) : $channel ); ?> · <strong><?php echo esc_html( (string) $qty ); ?></strong> HOT leads</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
		<?php if ( $has_pages ) : ?>
		<div class="ecb-card">
			<h4 style="margin-top:0;">Páginas que generan mejores leads</h4>
			<ul style="margin:0;padding-left:18px;">
				<?php foreach ( (array) $lead_insights['top_pages'] as $page => $qty ) : ?>
					<li><a href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $page ); ?></a> · <strong><?php echo esc_html( (string) $qty ); ?></strong></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</div>
	<?php else : ?>
	<p class="description" style="margin:8px 0 16px;padding:10px 14px;background:#f6f7f7;border-radius:6px;">
		Aún no hay suficientes leads HOT para detectar canales o páginas de mejor rendimiento. Se activará automáticamente cuando haya más conversiones.
	</p>
	<?php endif; ?>

	<div class="ecb-card" style="margin-top:12px;">
		<h4 style="margin-top:0;">Mapa de interacciones</h4>
		<p class="description" style="margin-top:0;">Ubicación aproximada de las interacciones recientes, calculada desde IP pública y agregada por zona para mantenerlo liviano.</p>
		<?php if ( ! empty( $geo_map['points'] ) ) : ?>
			<div class="ecb-card-grid" style="grid-template-columns:minmax(0,1.35fr) minmax(260px,0.65fr);align-items:start;">
				<div>
					<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
					<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="" />
					<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="" />
					<div id="ecb-geo-map" style="height:360px;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;"></div>
				</div>
				<div>
					<p class="ecb-metric"><strong>Zonas activas:</strong> <?php echo esc_html( (string) ( $geo_map['unique_places'] ?? 0 ) ); ?></p>
					<p class="ecb-metric"><strong>Interacciones mapeadas:</strong> <?php echo esc_html( (string) ( $geo_map['mapped_interactions'] ?? 0 ) ); ?></p>
					<?php if ( $geo_channel !== '' || $geo_page !== '' ) : ?>
						<p class="description">Filtros activos:
							<?php echo esc_html( $geo_channel !== '' ? ( function_exists( 'estandar_chatbot_get_channel_label' ) ? estandar_chatbot_get_channel_label( $geo_channel ) : $geo_channel ) : 'todos los canales' ); ?>
							<?php echo esc_html( $geo_page !== '' ? ' · página filtrada' : '' ); ?>
						</p>
					<?php endif; ?>
					<p class="description">Proveedor aproximado: <?php echo esc_html( (string) ( $geo_map['provider'] ?? 'n/d' ) ); ?>.</p>
					<ul style="margin:10px 0 0;padding-left:18px;">
						<?php foreach ( array_slice( (array) ( $geo_map['points'] ?? [] ), 0, 6 ) as $point ) : ?>
							<li>
								<?php echo esc_html( (string) ( $point['label'] ?? '' ) ); ?>
								· <?php echo esc_html( (string) ( $point['interactions'] ?? 0 ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php else : ?>
			<p class="description" style="margin:0;">Todavía no hay suficiente ubicación resoluble para dibujar el mapa en esta ventana. Si hay tráfico reciente, puede tardar un poco en llenar caché.</p>
		<?php endif; ?>
	</div>
</div>
<script>
(function(){
	var select = document.getElementById('ecb-analytics-days');
	var form = document.getElementById('ecb-analytics-range-form');
	if (!select || !form) return;
	select.addEventListener('change', function(){
		form.submit();
	});
	var channel = document.getElementById('ecb-analytics-channel');
	var page = document.getElementById('ecb-analytics-page');
	if (channel) {
		channel.addEventListener('change', function(){
			form.submit();
		});
	}
	if (page) {
		page.addEventListener('change', function(){
			form.submit();
		});
	}
})();
</script>
<?php if ( ! empty( $geo_map['points'] ) ) : ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js" crossorigin=""></script>
<script>
(function(){
	var points = <?php echo wp_json_encode( array_values( (array) ( $geo_map['points'] ?? [] ) ) ); ?>;
	var mapEl = document.getElementById('ecb-geo-map');
	if (!mapEl || !window.L || !Array.isArray(points) || !points.length) return;

	var map = L.map(mapEl, {
		scrollWheelZoom: false
	});

	L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 18,
		attribution: '&copy; OpenStreetMap contributors'
	}).addTo(map);

	var bounds = [];
	var markerLayer = window.L.markerClusterGroup ? L.markerClusterGroup() : null;
	var heatPoints = [];
	points.forEach(function(point) {
		var lat = Number(point.lat);
		var lng = Number(point.lng);
		if (!isFinite(lat) || !isFinite(lng)) return;

		var marker = L.circleMarker([lat, lng], {
			radius: Math.max(6, Math.min(18, 4 + Number(point.interactions || 0))),
			color: '#0f172a',
			weight: 1,
			fillColor: '#2563eb',
			fillOpacity: 0.65
		});

		marker.bindPopup(
			'<strong>' + String(point.label || 'Ubicación aproximada') + '</strong><br>' +
			'Interacciones: ' + String(point.interactions || 0) +
			(point.last_seen ? '<br>Última: ' + String(point.last_seen) : '')
		);

		if (markerLayer) {
			markerLayer.addLayer(marker);
		} else {
			marker.addTo(map);
		}

		bounds.push([lat, lng]);
		heatPoints.push([lat, lng, Math.max(0.3, Math.min(1, Number(point.interactions || 0) / 10))]);
	});

	if (markerLayer) {
		map.addLayer(markerLayer);
	}

	if (window.L.heatLayer && heatPoints.length) {
		L.heatLayer(heatPoints, {
			radius: 28,
			blur: 22,
			maxZoom: 10
		}).addTo(map);
	}

	if (bounds.length === 1) {
		map.setView(bounds[0], 8);
	} else if (bounds.length > 1) {
		map.fitBounds(bounds, {padding: [24, 24]});
	}
})();
</script>
<?php endif; ?>
