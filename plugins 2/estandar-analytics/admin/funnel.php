<?php
/**
 * Funnel comercial — dashboard ejecutivo.
 *
 * Variables disponibles:
 *   $funnel            — resultado de EA_Dashboard::get_funnel()
 *   $options           — filtros disponibles
 *   $system_health     — resultado de EA_Dashboard::get_system_health()
 *   $diagnosis         — resultado de EA_Dashboard::get_funnel_diagnosis()
 *   $breakdown_project — Proyecto → Resultado
 *   $operators_list    — operadores disponibles
 *   $filters           — filtros activos
 *
 * Layout (30-second rule):
 *   1. Filtros
 *   2. Embudo visual         — ¿cuánto tráfico? ¿cuánto convirtió? ¿dónde está el cuello?
 *   3. Alertas               — ¿qué requiere acción? (crítico → warning → healthy)
 *   4. Proyecto → Resultado  — detalle
 *   5. Fuente → Resultado    — detalle
 *   6. Diagnóstico           — ¿por qué? (comprimido)
 *   7. Dispositivos          — resumen compacto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stages           = $funnel['stages']           ?? array();
$breakdown        = $funnel['breakdown']        ?? array();
$intent_breakdown = $funnel['intent_breakdown'] ?? array();
$active_filters   = $funnel['filters']          ?? array();
$operator_filter  = $funnel['operator_filter']  ?? '';
$project_filter   = $funnel['project_filter']   ?? $active_filters['project'] ?? '';
$diagnosis        = $diagnosis                  ?? array();
$breakdown_project = $breakdown_project         ?? array();
$system_health    = $system_health              ?? array();
$total_spend      = (float) ( $funnel['spend']  ?? 0 );

// Configuración de canales de intención (WHATSAPP_FUNNEL_VISIBILITY_V1).
$intent_channels = array(
	'whatsapp' => array( 'icon' => '💬', 'label' => __( 'WhatsApp',   'estandar-analytics' ), 'color' => '#25D366' ),
	'form'     => array( 'icon' => '📝', 'label' => __( 'Formulario', 'estandar-analytics' ), 'color' => '#2271b1' ),
	'chatbot'  => array( 'icon' => '🤖', 'label' => __( 'Chatbot',    'estandar-analytics' ), 'color' => '#8b5cf6' ),
	'other'    => array( 'icon' => '∙',  'label' => __( 'Otros',      'estandar-analytics' ), 'color' => '#a7aaad' ),
);

$first_count = (int) ( $stages[0]['count'] ?? 0 );

// Período activo — SSOT: ea_from / ea_to (ANALYTICS_RANGE_FIX, 2026-06-14)
$range_from = $filters['from'] ?? '';
$range_to   = $filters['to'] ?? '';

// Helpers de color para diagnóstico
$diag_colors = array(
	'danger'  => array( 'bg' => '#fff0f0', 'border' => '#d63638', 'icon' => '🔴' ),
	'warning' => array( 'bg' => '#fffbe6', 'border' => '#996800', 'icon' => '🟡' ),
	'info'    => array( 'bg' => '#f0f6fc', 'border' => '#2271b1', 'icon' => '🔵' ),
);

$health_icon = array( 'green' => '🟢', 'yellow' => '🟡', 'red' => '🔴' );
$health_links = array(
	'no_operator_fresh' => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'unassigned_24h' ), admin_url( 'admin.php' ) ),
	'no_operator_aging' => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'unassigned_72h' ), admin_url( 'admin.php' ) ),
	'no_operator_cold'  => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'unassigned_72h' ), admin_url( 'admin.php' ) ),
	'stale'             => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'stale_7d' ), admin_url( 'admin.php' ) ),
	'no_source'         => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'no_source' ), admin_url( 'admin.php' ) ),
	'converted_no_attr' => add_query_arg( array( 'page' => 'estandar-leads-portal', 'issue' => 'converted_no_attr' ), admin_url( 'admin.php' ) ),
	'tracking_active'   => add_query_arg( array( 'page' => 'ea-funnel', 'ea_from' => $range_from, 'ea_to' => $range_to ), admin_url( 'admin.php' ) ),
);

// Ordenar alertas: crítico (red) → advertencia (yellow) → saludable (green).
if ( ! empty( $system_health ) ) {
	$_health_order = array( 'red' => 0, 'yellow' => 1, 'green' => 2 );
	uasort( $system_health, static function ( $a, $b ) use ( $_health_order ) {
		return ( $_health_order[ $a['status'] ] ?? 9 ) <=> ( $_health_order[ $b['status'] ] ?? 9 );
	} );
}
?>

<?php /* ═══════════════════════════════════════════════════════
   § FILTROS — período + proyecto + operador (comprimido)
   ═══════════════════════════════════════════════════════ */ ?>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
	<?php
	$range_extra_args = array();
	if ( $project_filter !== '' ) {
		$range_extra_args['ea_project'] = $project_filter;
	}
	if ( $operator_filter !== '' ) {
		$range_extra_args['ea_operator'] = $operator_filter;
	}
	if ( ! empty( $_GET['ea_source'] ) ) {
		$range_extra_args['ea_source'] = sanitize_text_field( wp_unslash( $_GET['ea_source'] ) );
	}
	EA_Admin_UI::render_range_shortcuts( $filters, 'ea-funnel', $range_extra_args );
	?>

	<?php
	$funnel_filters = array();
	if ( ! empty( $options['projects'] ) ) {
		$project_options = array_map(
			static function ( $slug ) {
				return array(
					'value' => $slug,
					'label' => resolve_project_display_name( $slug, $slug ),
				);
			},
			$options['projects']
		);
		$funnel_filters[] = array( 'name' => 'ea_project', 'label' => __( 'Proyecto', 'estandar-analytics' ), 'options' => $project_options, 'current' => $project_filter );
	}
	if ( ! empty( $operators_list ) ) {
		$funnel_filters[] = array( 'name' => 'ea_operator', 'label' => __( 'Operador', 'estandar-analytics' ), 'options' => $operators_list, 'current' => $operator_filter );
	}
	if ( ! empty( $funnel_filters ) ) :
		$hidden = array( 'page' => 'ea-funnel', 'ea_from' => $range_from, 'ea_to' => $range_to );
	?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
		<?php foreach ( $hidden as $k => $v ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<?php endforeach; ?>
		<?php foreach ( $funnel_filters as $f ) : ?>
			<select name="<?php echo esc_attr( $f['name'] ); ?>" style="font-size:12px;padding:3px 6px;">
				<option value=""><?php echo esc_html( $f['label'] ); ?>: <?php esc_html_e( 'Todos', 'estandar-analytics' ); ?></option>
				<?php foreach ( (array) $f['options'] as $opt ) :
					$val   = is_array( $opt ) ? ( $opt['value'] ?? $opt['slug'] ?? '' ) : $opt;
					$label = is_array( $opt ) ? ( $opt['label'] ?? $opt['name'] ?? $val ) : $opt;
				?>
					<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $f['current'], $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endforeach; ?>
		<button type="submit" class="button" style="font-size:12px;padding:2px 10px;"><?php esc_html_e( 'Filtrar', 'estandar-analytics' ); ?></button>
		<?php if ( $project_filter !== '' || $operator_filter !== '' ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ea-funnel', 'ea_from' => $range_from, 'ea_to' => $range_to ), admin_url( 'admin.php' ) ) ); ?>" style="font-size:11px;color:#646970;">✕ <?php esc_html_e( 'Limpiar', 'estandar-analytics' ); ?></a>
		<?php endif; ?>
	</form>
	<?php endif; ?>
</div>
<?php EA_Admin_UI::render_project_trust_badge( 'approx', __( 'Sesiones e intención usan ea_sessions.first_project_slug; leads y ventas usan es_leads.project_slug.', 'estandar-analytics' ) ); ?>

<?php /* ═══════════════════════════════════════════════════════
   § EMBUDO VISUAL — foco principal
   ═══════════════════════════════════════════════════════ */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Embudo comercial', 'estandar-analytics' ); ?></h2>
		<?php if ( $total_spend > 0 ) : ?>
			<span style="font-size:13px;font-weight:400;color:#646970;margin-left:12px;">
				<?php
				printf(
					/* translators: %s = formatted spend amount */
					esc_html__( 'Inversión: $%s', 'estandar-analytics' ),
					esc_html( number_format_i18n( $total_spend, 0 ) )
				);
				?>
			</span>
		<?php endif; ?>
	</header>

	<?php if ( $first_count === 0 ) : ?>
		<p style="padding:14px 18px;color:#646970;">
			<?php esc_html_e( 'Sin datos para el período seleccionado.', 'estandar-analytics' ); ?>
		</p>
	<?php else : ?>
		<div style="padding:10px 18px 14px;">
			<?php
			$prev_tier = '';
			foreach ( $stages as $i => $stage ) :
				$count      = (int) $stage['count'];
				$pct_prev   = (float) $stage['pct'];
				$bar_pct    = $first_count > 0 ? max( 1, round( ( $count / $first_count ) * 100 ) ) : 0;
				$is_first   = ( $i === 0 );
				$tier       = $stage['tier'] ?? 'site';
				$is_bottleneck = ( ! $is_first && $pct_prev > 0 && $pct_prev < 30 );
				$tier_colors = array( 'ads' => '#8b5cf6', 'site' => '#2271b1', 'lead' => '#1e8e3e' );
				$bar_color  = $is_bottleneck ? '#d63638' : ( $count === 0 ? '#c3c4c7' : ( $tier_colors[ $tier ] ?? '#2271b1' ) );
				$tier_labels = array(
					'ads'  => __( 'Plataformas de ads (datos importados)', 'estandar-analytics' ),
					'site' => __( 'Sitio web (tracker)', 'estandar-analytics' ),
					'lead' => __( 'Leads (datos comerciales)', 'estandar-analytics' ),
				);
				$precision_icons = array( 'ads' => '◆', 'site' => '≈', 'lead' => '✓' );
				$precision_colors = array( 'ads' => '#8b5cf6', 'site' => '#8a6100', 'lead' => '#1e8e3e' );
			?>
				<?php if ( $tier !== $prev_tier && $prev_tier !== '' ) : ?>
					<div style="border-top:1px dashed #c3c4c7;margin:8px 0 3px;position:relative;">
						<span style="position:absolute;top:-8px;right:0;font-size:10px;color:#a7aaad;background:#fff;padding:0 6px;">
							<?php echo esc_html( $tier_labels[ $tier ] ?? '' ); ?>
						</span>
					</div>
				<?php endif; ?>
				<?php if ( ! $is_first ) : ?>
				<div style="display:flex;align-items:center;gap:8px;margin:2px 0 2px 10px;">
					<span style="color:#c3c4c7;font-size:16px;line-height:1;">↓</span>
					<span style="font-size:12px;color:<?php echo $is_bottleneck ? '#d63638' : '#646970'; ?>;font-weight:<?php echo $is_bottleneck ? '700' : 'normal'; ?>;">
						<?php echo esc_html( number_format_i18n( $pct_prev, 1 ) . ' %' ); ?>
						<?php if ( $is_bottleneck ) : ?>
							<span title="<?php esc_attr_e( 'Cuello de botella', 'estandar-analytics' ); ?>"> ⚠ <?php esc_html_e( 'cuello', 'estandar-analytics' ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>

				<div style="display:grid;grid-template-columns:24px 130px 1fr 64px;align-items:center;gap:8px;margin-bottom:2px;">
					<span title="<?php echo esc_attr( $tier_labels[ $tier ] ?? '' ); ?>"
					      style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $precision_colors[ $tier ] ?? '#646970' ); ?>;">
						<?php echo esc_html( $precision_icons[ $tier ] ?? '·' ); ?>
					</span>
					<span style="font-size:13px;color:#1d2327;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
					<?php echo esc_html( $stage['label'] ); ?>
				</span>
				<div style="background:#f0f0f1;border-radius:3px;height:18px;overflow:hidden;">
					<div style="height:100%;width:<?php echo esc_attr( $bar_pct ); ?>%;background:<?php echo esc_attr( $bar_color ); ?>;border-radius:3px;transition:width .3s;opacity:<?php echo $count > 0 ? '0.85' : '0.3'; ?>;"></div>
				</div>
				<span style="font-size:15px;font-weight:700;color:<?php echo esc_attr( $bar_color ); ?>;text-align:right;">
					<?php echo esc_html( number_format_i18n( $count ) ); ?>
				</span>
			</div>

			<?php
			// WHATSAPP_FUNNEL_VISIBILITY_V1 — desglose por canal bajo "Intención comercial".
			if ( $stage['label'] === __( 'Intención comercial', 'estandar-analytics' ) && ! empty( $intent_breakdown ) ) :
				$intent_total = max( 1, $count );
			?>
			<div style="margin:2px 0 4px 20px;padding:8px 12px;background:#f6f7f7;border-radius:4px;border-left:2px solid #dcdcde;">
				<p style="font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#646970;margin:0 0 6px;">
					<?php esc_html_e( 'por canal', 'estandar-analytics' ); ?>
				</p>
				<?php foreach ( $intent_channels as $ch_key => $ch ) :
					$ch_count = (int) ( $intent_breakdown[ $ch_key ] ?? 0 );
					if ( $ch_count === 0 ) continue;
					$ch_bar_pct = min( 100, round( ( $ch_count / $intent_total ) * 100 ) );
				?>
				<div style="display:grid;grid-template-columns:110px 1fr 38px;align-items:center;gap:6px;margin-bottom:4px;">
					<span style="font-size:12px;color:#1d2327;">
						<?php echo $ch['icon']; ?> <?php echo esc_html( $ch['label'] ); ?>
					</span>
					<div style="background:#e8e8e8;border-radius:2px;height:12px;overflow:hidden;">
						<div style="height:100%;width:<?php echo esc_attr( $ch_bar_pct ); ?>%;background:<?php echo esc_attr( $ch['color'] ); ?>;border-radius:2px;opacity:0.8;"></div>
					</div>
					<span style="font-size:12px;font-weight:600;color:<?php echo esc_attr( $ch['color'] ); ?>;text-align:right;">
						<?php echo esc_html( number_format_i18n( $ch_count ) ); ?>
					</span>
				</div>
				<?php endforeach; ?>
				<p style="font-size:10px;color:#a7aaad;margin:4px 0 0;">
					<?php esc_html_e( 'Sesiones únicas por canal · una sesión puede contar en más de uno', 'estandar-analytics' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php
			$prev_tier = $tier;
			endforeach; ?>
		</div>

		<?php /* Leyenda de precisión */ ?>
		<div style="padding:0 18px 10px;display:flex;gap:14px;flex-wrap:wrap;">
			<span style="font-size:11px;color:#8b5cf6;">◆ <?php esc_html_e( 'Ads (importado)', 'estandar-analytics' ); ?></span>
			<span style="font-size:11px;color:#8a6100;">≈ <?php esc_html_e( 'Sitio (tracker)', 'estandar-analytics' ); ?></span>
			<span style="font-size:11px;color:#1e8e3e;">✓ <?php esc_html_e( 'Leads (exacto)', 'estandar-analytics' ); ?></span>
		</div>
	<?php endif; ?>
</section>

<?php /* ═══════════════════════════════════════════════════════
   § ALERTAS — crítico → advertencia → saludable
   ═══════════════════════════════════════════════════════ */ ?>
<?php if ( ! empty( $system_health ) ) : ?>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
	<?php foreach ( $system_health as $key => $metric ) :
		$icon   = $health_icon[ $metric['status'] ] ?? '⚪';
		$is_red = ( $metric['status'] === 'red' );
		$should_link = (int) ( $metric['raw'] ?? 0 ) > 0 || ( $key === 'tracking_active' && $is_red );
		$link   = ( $should_link && ! empty( $health_links[ $key ] ) ) ? $health_links[ $key ] : '';
		$tag    = $link !== '' ? 'a' : 'div';
	?>
		<<?php echo esc_html( $tag ); ?>
			<?php if ( $link !== '' ) : ?>href="<?php echo esc_url( $link ); ?>"<?php endif; ?>
			style="display:flex;align-items:flex-start;gap:7px;padding:8px 12px;border-radius:6px;
		            background:<?php echo $is_red ? '#fff0f0' : '#f6f7f7'; ?>;
		            border:1px solid <?php echo $is_red ? '#d63638' : '#dcdcde'; ?>;
		            min-width:140px;flex:1 1 140px;max-width:240px;
		            <?php echo $link !== '' ? 'text-decoration:none;cursor:pointer;' : ''; ?>">
			<span style="font-size:15px;line-height:1.4;flex-shrink:0;"><?php echo $icon; ?></span>
			<div>
				<div style="font-size:13px;font-weight:600;color:<?php echo $is_red ? '#d63638' : '#1d2327'; ?>;">
					<?php echo esc_html( $metric['value'] ); ?>
					<span style="font-weight:400;color:#646970;font-size:12px;"> <?php echo esc_html( $metric['label'] ); ?></span>
				</div>
				<?php if ( ! empty( $metric['detail'] ) ) : ?>
					<div style="font-size:11px;color:#646970;margin-top:1px;"><?php echo esc_html( $metric['detail'] ); ?></div>
				<?php endif; ?>
				<?php if ( $link !== '' ) : ?>
					<div style="font-size:11px;color:#2271b1;margin-top:3px;"><?php esc_html_e( 'Ver afectados →', 'estandar-analytics' ); ?></div>
				<?php endif; ?>
			</div>
		</<?php echo esc_html( $tag ); ?>>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════
   § PROYECTO → RESULTADO
   ═══════════════════════════════════════════════════════ */ ?>
<?php if ( ! empty( $breakdown_project ) ) : ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Proyecto → Resultado', 'estandar-analytics' ); ?></h2>
	</header>
	<div class="ea-table-scroll">
		<table class="ea-table" style="font-size:13px;">
			<thead>
				<tr>
					<th style="padding:6px 12px;"><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reservas', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Ventas', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Cierre', 'estandar-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $breakdown_project as $row ) :
				$leads    = (int) ( $row['leads']    ?? 0 );
				$reservas = (int) ( $row['reservas'] ?? 0 );
				$ventas   = (int) ( $row['ventas']   ?? 0 );
				$cierre = $leads > 0 ? round( $ventas / $leads * 100, 1 ) : 0;
			?>
				<tr>
					<td style="padding:5px 12px;"><?php echo esc_html( resolve_project_display_name( $row['project'] ?? '', '' ) ?: '—' ); ?></td>
					<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( $leads ) ); ?></td>
					<td style="text-align:right;padding:5px 12px;">
						<?php if ( $reservas > 0 ) : ?>
							<strong><?php echo esc_html( number_format_i18n( $reservas ) ); ?></strong>
						<?php else : ?>
							<span style="color:#c3c4c7;">0</span>
						<?php endif; ?>
					</td>
					<td style="text-align:right;padding:5px 12px;">
						<?php if ( $ventas > 0 ) : ?>
							<strong style="color:#00a32a;"><?php echo esc_html( number_format_i18n( $ventas ) ); ?></strong>
						<?php else : ?>
							<span style="color:#c3c4c7;">0</span>
						<?php endif; ?>
					</td>
					<td style="text-align:right;padding:5px 12px;color:<?php echo $cierre >= 20 ? '#00a32a' : ( $cierre >= 10 ? '#996800' : '#d63638' ); ?>;">
						<?php echo esc_html( number_format_i18n( $cierre, 1 ) . ' %' ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════
   § FUENTE → RESULTADO
   ═══════════════════════════════════════════════════════ */ ?>
<?php if ( ! empty( $breakdown ) ) : ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2>
			<?php esc_html_e( 'Fuente → Resultado', 'estandar-analytics' ); ?>
			<span style="font-size:11px;font-weight:400;color:#646970;margin-left:8px;"><?php esc_html_e( 'Clic en una fuente para filtrar.', 'estandar-analytics' ); ?></span>
		</h2>
	</header>
	<div class="ea-table-scroll">
		<table class="ea-table" style="font-size:13px;">
			<thead>
				<tr>
					<th style="padding:6px 12px;"><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reservas', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Ventas', 'estandar-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $breakdown as $row ) :
				$src = (string) ( $row['source'] ?? '' );
				$reservas = (int) ( $row['reservations'] ?? 0 );
				$conv = (int) ( $row['converted'] ?? 0 );
				$filter_url = add_query_arg( array( 'page' => 'ea-funnel', 'ea_source' => rawurlencode( $src ), 'ea_from' => $range_from, 'ea_to' => $range_to ), admin_url( 'admin.php' ) );
			?>
				<tr>
					<td style="padding:5px 12px;">
						<a href="<?php echo esc_url( $filter_url ); ?>" style="text-decoration:none;">
							<?php echo esc_html( $src ); ?>
						</a>
					</td>
					<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) $row['leads'] ) ); ?></td>
					<td style="text-align:right;padding:5px 12px;">
						<?php if ( $reservas > 0 ) : ?>
							<strong><?php echo esc_html( number_format_i18n( $reservas ) ); ?></strong>
						<?php else : ?>
							<span style="color:#c3c4c7;">0</span>
						<?php endif; ?>
					</td>
					<td style="text-align:right;padding:5px 12px;">
						<?php if ( $conv > 0 ) : ?>
							<strong style="color:#00a32a;"><?php echo esc_html( number_format_i18n( $conv ) ); ?></strong>
						<?php else : ?>
							<span style="color:#c3c4c7;">0</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════
   § DIAGNÓSTICO — comprimido en panel único
   ═══════════════════════════════════════════════════════ */ ?>
<?php if ( ! empty( $diagnosis ) ) : ?>
<div style="margin-bottom:10px;padding:10px 14px;background:#f6f7f7;border-radius:6px;border:1px solid #dcdcde;">
	<p style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#646970;margin:0 0 6px;">
		<?php esc_html_e( 'DIAGNÓSTICO', 'estandar-analytics' ); ?>
	</p>
	<div style="display:flex;flex-direction:column;gap:4px;">
		<?php foreach ( $diagnosis as $finding ) :
			$dc = $diag_colors[ $finding['level'] ] ?? $diag_colors['info'];
		?>
			<div style="display:flex;align-items:baseline;gap:8px;font-size:12px;line-height:1.5;color:#1d2327;
			            padding-left:6px;border-left:3px solid <?php echo esc_attr( $dc['border'] ); ?>;">
				<span style="flex-shrink:0;"><?php echo $dc['icon']; ?></span>
				<span><?php echo esc_html( $finding['message'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<p style="color:#a7aaad;font-size:11px;margin:0 0 10px;">
	<?php esc_html_e( 'Intención comercial = sesiones únicas con al menos un evento de:', 'estandar-analytics' ); ?>
	<?php echo esc_html( implode( ', ', EA_Dashboard::high_intent_events() ) ); ?>
</p>

<?php /* ═══════════════════════════════════════════════════════
   § DISPOSITIVOS — resumen compacto
   ═══════════════════════════════════════════════════════ */ ?>
<?php
global $wpdb;
$ea_devices  = EA_Utils::table( 'devices' );
$ea_sessions = EA_Utils::table( 'sessions' );
list( $dev_start, $dev_end ) = EA_Dashboard::dt_bounds( $filters['from'], $filters['to'] );
$device_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT d.device_type AS device, COUNT(*) AS total
		FROM {$ea_devices} d
		INNER JOIN {$ea_sessions} s ON s.session_id = d.session_id
		WHERE s.first_seen >= %s AND s.first_seen < %s AND d.device_type != ''
		GROUP BY d.device_type
		ORDER BY total DESC",
		$dev_start,
		$dev_end
	),
	ARRAY_A
) ?: array();
$device_total = 0;
foreach ( $device_rows as $dr ) {
	$device_total += (int) $dr['total'];
}
$device_icons = array( 'mobile' => '📱', 'desktop' => '💻', 'tablet' => '📟' );
?>
<section class="ea-panel">
	<div style="padding:10px 16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
		<span style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#646970;white-space:nowrap;"><?php esc_html_e( 'Dispositivos', 'estandar-analytics' ); ?></span>
		<?php if ( empty( $device_rows ) ) : ?>
			<span style="font-size:12px;color:#a7aaad;"><?php esc_html_e( 'Sin datos en este período.', 'estandar-analytics' ); ?></span>
		<?php else : ?>
			<?php foreach ( $device_rows as $dr ) :
				$pct  = $device_total > 0 ? round( ( (int) $dr['total'] / $device_total ) * 100 ) : 0;
				$icon = $device_icons[ $dr['device'] ] ?? '🖥️';
			?>
			<span style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:#f6f7f7;border-radius:20px;font-size:13px;">
				<span><?php echo $icon; ?></span>
				<span style="text-transform:capitalize;color:#50575e;"><?php echo esc_html( $dr['device'] ); ?></span>
				<strong style="color:#1d2327;"><?php echo esc_html( (string) $pct ); ?>%</strong>
				<span style="font-size:11px;color:#a7aaad;"><?php echo esc_html( number_format_i18n( (int) $dr['total'] ) ); ?></span>
			</span>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>
