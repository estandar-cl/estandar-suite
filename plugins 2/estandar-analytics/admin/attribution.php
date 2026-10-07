<?php
/**
 * Attribution V1 — Canal → Sesión → Lead → Conversión
 *
 * Variables disponibles:
 *   $data    — resultado de EA_Dashboard::get_attribution_data()
 *   $filters — filtros activos
 *   $options — filtros disponibles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sources         = $data['sources']         ?? [];
$funnel          = $data['funnel_by_source'] ?? [];
$campaigns       = $data['campaigns']       ?? [];
$projects        = $data['projects']        ?? [];
$signal          = $data['signal']          ?? [];
$has_leads       = ! empty( $funnel ) || ! empty( $projects );

// Colores por canal
$canal_colors = [
	'Paid Search'    => '#1a73e8',
	'Paid Social'    => '#1877f2',
	'Organic Search' => '#1e7e34',
	'Organic Social' => '#23a455',
	'Direct'         => '#5f6368',
	'Email'          => '#ea4335',
	'Referral'       => '#9334e6',
	'Unknown'        => '#bdc1c6',
];

function ea_attr_canal_dot( string $canal ): string {
	global $canal_colors;
	$color = $canal_colors[ $canal ] ?? '#bdc1c6';
	return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $color ) . ';margin-right:6px;vertical-align:middle;"></span>';
}

function ea_attr_pct_bar( float $pct, string $canal = '' ): string {
	global $canal_colors;
	$color = $canal_colors[ $canal ] ?? '#2271b1';
	$w     = min( 100, max( 0, $pct ) );
	return '<div style="height:6px;background:#e8eaed;border-radius:3px;overflow:hidden;min-width:80px;"><div style="height:100%;width:' . $w . '%;background:' . esc_attr( $color ) . ';border-radius:3px;"></div></div>';
}

function ea_attr_rate_badge( float $rate ): string {
	if ( $rate === 0.0 ) {
		return '<span style="color:#bdc1c6;">0%</span>';
	}
	$color = $rate >= 5 ? '#1e7e34' : ( $rate >= 2 ? '#996800' : '#d63638' );
	return '<span style="font-weight:600;color:' . $color . ';">' . $rate . '%</span>';
}
?>

<?php /* ── FILTROS ─────────────────────────────────────────────────────── */ ?>
<div class="ea-filter-bar" style="margin-bottom:10px;">
	<?php EA_Admin_UI::render_range_shortcuts( $filters, 'ea-attribution' ); ?>
</div>
<?php EA_Admin_UI::render_project_trust_badge( 'unsupported' ); ?>

<?php /* ── W1: FUENTES ─────────────────────────────────────────────────── */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Fuentes de tráfico', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;">Sesiones por canal de origen.</span></h2>
	</header>
	<?php if ( empty( $sources ) ) : ?>
		<p style="padding:10px 18px;color:#646970;"><?php esc_html_e( 'Sin datos.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Canal', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
					<th style="min-width:120px;"><?php esc_html_e( 'Peso', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( '% Total', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Con gclid', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Con fbclid', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Duración avg', 'estandar-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $sources as $row ) :
				$canal = (string) ( $row['canal'] ?: 'Unknown' );
				$sess  = (int)    $row['sesiones'];
				$pct   = (float)  $row['pct'];
			?>
				<tr>
					<td><?php echo ea_attr_canal_dot( $canal ) . esc_html( $canal ); ?></td>
					<td style="text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $sess ) ); ?></td>
					<td><?php echo ea_attr_pct_bar( $pct, $canal ); ?></td>
					<td style="text-align:right;"><?php echo esc_html( $pct ); ?>%</td>
					<td style="text-align:right;color:<?php echo $row['con_gclid'] > 0 ? '#1a73e8' : '#bdc1c6'; ?>;">
						<?php echo esc_html( number_format_i18n( (int) $row['con_gclid'] ) ); ?>
					</td>
					<td style="text-align:right;color:<?php echo $row['con_fbclid'] > 0 ? '#1877f2' : '#bdc1c6'; ?>;">
						<?php echo esc_html( number_format_i18n( (int) $row['con_fbclid'] ) ); ?>
					</td>
					<td style="text-align:right;color:#646970;">
						<?php
						$dur = (int) $row['duracion_avg'];
						echo $dur > 0 ? esc_html( gmdate( 'i:s', $dur ) ) : '—';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>

<?php /* ── W2: FUNNEL POR FUENTE ──────────────────────────────────────── */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Funnel por fuente', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;">Canal → Sesiones → Leads → Conversiones · JOIN: <code>ea_sessions ↔ es_leads</code></span></h2>
	</header>
	<?php if ( ! $has_leads ) : ?>
		<p style="padding:10px 18px;color:#646970;">
			<?php esc_html_e( 'Estandar Leads no está activo. Este widget requiere la tabla es_leads.', 'estandar-analytics' ); ?>
		</p>
	<?php elseif ( empty( $funnel ) ) : ?>
		<p style="padding:10px 18px;color:#646970;"><?php esc_html_e( 'Sin datos.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Canal', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Conversiones', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Tasa Lead %', 'estandar-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $funnel as $row ) :
				$canal = (string) ( $row['canal'] ?: 'Unknown' );
			?>
				<tr>
					<td><?php echo ea_attr_canal_dot( $canal ) . esc_html( $canal );
						if ( $row['source'] ) echo ' <span style="font-size:11px;color:#888;">(' . esc_html( $row['source'] ) . ')</span>';
					?></td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row['sesiones'] ) ); ?></td>
					<td style="text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( (int) $row['leads'] ) ); ?></td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row['conversiones'] ) ); ?></td>
					<td style="text-align:right;"><?php echo ea_attr_rate_badge( (float) $row['conv_rate'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>

<?php /* ── W3: CAMPAÑAS ────────────────────────────────────────────────── */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Campañas', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;">Basado en <code>utm_campaign</code>. Sin datos: revisar URLs de anuncios.</span></h2>
	</header>
	<?php if ( empty( $campaigns ) ) : ?>
		<p style="padding:10px 18px;color:#646970;">
			<?php esc_html_e( 'Sin campañas detectadas. Agrega utm_campaign= a las URLs de tus anuncios.', 'estandar-analytics' ); ?>
		</p>
	<?php else : ?>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Campaña', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Conv %', 'estandar-analytics' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $campaigns as $row ) : ?>
				<tr>
					<td style="font-weight:600;"><?php $campaign = (string) ( $row['campana'] ?? '' ); $campaign = ( $campaign !== '' && class_exists( 'Estandar_Campaign_Label_Resolver' ) ) ? Estandar_Campaign_Label_Resolver::campaign( $campaign ) : $campaign; echo esc_html( $campaign !== '' ? $campaign : '—' ); ?></td>
					<td>
						<?php
						$src = trim( ( $row['source'] ?? '' ) . ' / ' . ( $row['medium'] ?? '' ), ' /' );
						echo esc_html( $src ?: '—' );
						?>
					</td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row['sesiones'] ) ); ?></td>
					<td style="text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( (int) $row['leads'] ) ); ?></td>
					<td style="text-align:right;"><?php echo ea_attr_rate_badge( (float) $row['conv_rate'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>

<?php /* ── W4: PROYECTOS ───────────────────────────────────────────────── */ ?>
<?php if ( $has_leads ) : ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Proyectos', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;">Leads por proyecto con desglose de fuente · <code>es_leads.project_slug</code></span></h2>
	</header>
	<?php if ( empty( $projects ) ) : ?>
		<p style="padding:10px 18px;color:#646970;"><?php esc_html_e( 'Sin datos.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Total leads', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;" title="Google Ads">🔵 Google Ads</th>
					<th style="text-align:right;" title="Meta Ads">🟦 Meta</th>
					<th style="text-align:right;" title="Organic Search">🟢 Orgánico</th>
					<th style="text-align:right;" title="Direct">⚫ Directo</th>
					<th style="text-align:right;" title="Sin sesión linkada">❓ Sin sesión</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $projects as $row ) :
				$total = (int) $row['leads'];
			?>
				<tr>
					<td style="font-weight:600;"><?php echo esc_html( resolve_project_display_name( $row['project_slug'] ?? '', '' ) ); ?></td>
					<td style="text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					<td style="text-align:right;color:#1a73e8;"><?php echo esc_html( number_format_i18n( (int) $row['leads_google_ads'] ) ); ?></td>
					<td style="text-align:right;color:#1877f2;"><?php echo esc_html( number_format_i18n( (int) $row['leads_meta'] ) ); ?></td>
					<td style="text-align:right;color:#1e7e34;"><?php echo esc_html( number_format_i18n( (int) $row['leads_organic'] ) ); ?></td>
					<td style="text-align:right;color:#5f6368;"><?php echo esc_html( number_format_i18n( (int) $row['leads_direct'] ) ); ?></td>
					<td style="text-align:right;color:<?php echo $row['leads_sin_sesion'] > 0 ? '#d63638' : '#bdc1c6'; ?>;">
						<?php echo esc_html( number_format_i18n( (int) $row['leads_sin_sesion'] ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>
<?php endif; ?>

<?php /* ── W5: CALIDAD DE SEÑAL ──────────────────────────────────────── */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Calidad de señal', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;">🟢 OK · 🟡 Revisar · 🔴 Problema</span></h2>
	</header>
	<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;padding:12px 16px;">

		<?php
		$total_s = (int) $signal['total_sessions'];

		$metrics = [];

		// Sessions con gclid
		$gclid_cnt   = (int) ( $signal['con_gclid'] ?? 0 );
		$gclid_paid  = array_sum( array_column(
			array_filter( $sources, function( $r ) { return $r['canal'] === 'Paid Search'; } ),
			'sesiones'
		) );
		$gclid_ok    = $gclid_paid === 0 || $gclid_cnt >= $gclid_paid * 0.5;
		$metrics[] = [
			'label'  => 'Sesiones con gclid',
			'value'  => $gclid_cnt . ' / ' . $gclid_paid . ' Paid Search',
			'status' => $gclid_paid === 0 ? 'neutral' : ( $gclid_ok ? 'ok' : 'warn' ),
			'help'   => $gclid_paid > 0 && ! $gclid_ok ? 'Activa auto-tagging en Google Ads o agrega ?gclid= manualmente.' : '',
		];

		// Sessions con fbclid
		$fbclid_cnt  = (int) ( $signal['con_fbclid'] ?? 0 );
		$meta_paid   = array_sum( array_column(
			array_filter( $sources, function( $r ) { return $r['canal'] === 'Paid Social'; } ),
			'sesiones'
		) );
		$metrics[] = [
			'label'  => 'Sesiones con fbclid',
			'value'  => $fbclid_cnt . ' / ' . $meta_paid . ' Paid Social',
			'status' => 'neutral',
			'help'   => '',
		];

		// gbraid / meta_fbp (solo si columnas nuevas existen)
		if ( isset( $signal['con_gbraid'] ) ) {
			$metrics[] = [
				'label'  => 'Sesiones con gbraid',
				'value'  => (int) $signal['con_gbraid'],
				'status' => 'neutral',
				'help'   => 'Google iOS / Privacy Sandbox attribution.',
			];
			$metrics[] = [
				'label'  => 'Sesiones con _fbp cookie',
				'value'  => (int) $signal['con_meta_fbp'],
				'status' => 'neutral',
				'help'   => 'Requerido para Meta CAPI.',
			];
		}

		// Unknown traffic
		$unknown_cnt = (int) ( $signal['unknown_traffic'] ?? 0 );
		$unknown_pct = $total_s > 0 ? round( $unknown_cnt * 100 / $total_s, 1 ) : 0;
		$metrics[]   = [
			'label'  => 'Tráfico Unknown',
			'value'  => $unknown_cnt . ' (' . $unknown_pct . '%)',
			'status' => $unknown_pct > 20 ? 'error' : ( $unknown_pct > 5 ? 'warn' : 'ok' ),
			'help'   => $unknown_pct > 5 ? 'UTMs con medium no reconocido. Revisar campañas.' : '',
		];

		// Leads con session_id
			if ( isset( $signal['leads_total'] ) ) {
				$join_rate = (float) ( $signal['session_join_rate'] ?? 0 );
				$metrics[] = [
					'label'  => 'Session join rate',
				'value'  => $signal['leads_linkados'] . ' / ' . $signal['leads_total'] . ' leads (' . $join_rate . '%)',
				'status' => $join_rate >= 70 ? 'ok' : ( $join_rate >= 40 ? 'warn' : 'error' ),
					'help'   => $join_rate < 70 ? 'Leads sin sesión linkada no pueden atribuirse a una fuente.' : '',
				];
			}

			$identity = is_array( $signal['identity_diagnostics'] ?? null ) ? $signal['identity_diagnostics'] : [];
			if ( ! empty( $identity ) ) {
				$metrics[] = [
					'label'  => 'Leads con sesión sin match',
					'value'  => (int) ( $identity['leads_session_no_match'] ?? 0 ),
					'status' => (int) ( $identity['leads_session_no_match'] ?? 0 ) > 0 ? 'error' : 'ok',
					'help'   => (int) ( $identity['leads_session_no_match'] ?? 0 ) > 0 ? 'first_session_id no existe en Analytics.' : '',
				];
				$metrics[] = [
					'label'  => 'Submissions con sesión sin match',
					'value'  => (int) ( $identity['submissions_session_no_match'] ?? 0 ),
					'status' => (int) ( $identity['submissions_session_no_match'] ?? 0 ) > 0 ? 'error' : 'ok',
					'help'   => (int) ( $identity['submissions_session_no_match'] ?? 0 ) > 0 ? 'Submission tiene session_id sin session Analytics.' : '',
				];
				$metrics[] = [
					'label'  => 'Click IDs perdidos hacia Lead',
					'value'  => (int) ( $identity['submissions_click_id_lost'] ?? 0 ),
					'status' => (int) ( $identity['submissions_click_id_lost'] ?? 0 ) > 0 ? 'error' : 'ok',
					'help'   => (int) ( $identity['submissions_click_id_lost'] ?? 0 ) > 0 ? 'Submission tiene click ID que no llegó a first_*.' : '',
				];
				$metrics[] = [
					'label'  => 'Exportables sin identidad mínima',
					'value'  => (int) ( $identity['exportable_without_identity'] ?? 0 ),
					'status' => (int) ( $identity['exportable_without_identity'] ?? 0 ) > 0 ? 'error' : 'ok',
					'help'   => (int) ( $identity['exportable_without_identity'] ?? 0 ) > 0 ? 'Lead comercial exportable sin click ID ni hash.' : '',
				];
			}

		$status_styles = [
			'ok'      => 'border-left:3px solid #1e7e34;',
			'warn'    => 'border-left:3px solid #996800;',
			'error'   => 'border-left:3px solid #d63638;',
			'neutral' => 'border-left:3px solid #bdc1c6;',
		];
		$status_icons = [ 'ok' => '🟢', 'warn' => '🟡', 'error' => '🔴', 'neutral' => '⚪' ];

		foreach ( $metrics as $m ) :
			$st = $m['status'];
			?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px 12px;<?php echo $status_styles[ $st ] ?? ''; ?>">
				<div style="font-size:11px;color:#646970;margin-bottom:2px;">
					<?php echo esc_html( $status_icons[ $st ] ?? '' ); ?>
					<?php echo esc_html( $m['label'] ); ?>
				</div>
				<div style="font-size:15px;font-weight:700;line-height:1.3;">
					<?php echo esc_html( (string) $m['value'] ); ?>
				</div>
				<?php if ( ! empty( $m['help'] ) ) : ?>
					<div style="font-size:11px;color:#d63638;margin-top:4px;">
						<?php echo esc_html( $m['help'] ); ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
