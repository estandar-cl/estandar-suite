<?php
/**
 * Adquisición — ADQUISICION_UX_V1 (2026-07).
 *
 * Estructura: capa de respuesta primero, detalle después.
 *   1. Resumen 30 segundos  — KPIs del período (mismo dataset que el detalle).
 *   2. ¿De dónde vienen los leads? — funnel por fuente con barras.
 *   3. Campañas con resultado — top campañas con barras.
 *   4. Detalle completo — los 4 paneles legados, colapsados.
 *
 * Regla UX transversal: todo el resumen deriva de $data_attr (el MISMO
 * dataset que renderiza attribution.php más abajo). Sin datos → ausencia
 * de evidencia explícita, nunca simulación.
 *
 * Variables disponibles (via extract en shell()):
 *   $data_attr — EA_Dashboard::get_attribution_data()
 *   $data_acq  — EA_Dashboard::get_acquisition()
 *   $data_beh  — EA_Dashboard::get_behavior()
 *   $data_time — EA_Dashboard::get_time_analysis()
 *   $filters   — filtros activos
 *   $options   — filtros disponibles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$adq_sessions   = (int) ( $data_attr['total_sessions'] ?? 0 );
$adq_signal     = is_array( $data_attr['signal'] ?? null ) ? $data_attr['signal'] : array();
$adq_leads      = (int) ( $adq_signal['leads_total'] ?? 0 );
$adq_join_rate  = (float) ( $adq_signal['session_join_rate'] ?? 0 );
$adq_conv_rate  = $adq_sessions > 0 ? round( $adq_leads * 100 / $adq_sessions, 1 ) : null;
$adq_funnel     = is_array( $data_attr['funnel_by_source'] ?? null ) ? $data_attr['funnel_by_source'] : array();
$adq_campaigns  = is_array( $data_attr['campaigns'] ?? null ) ? $data_attr['campaigns'] : array();
$adq_has_data   = $adq_sessions > 0 || $adq_leads > 0;

// Fuente y campaña top (por leads; si nadie tiene leads, por sesiones).
$adq_top_source   = null;
$adq_top_campaign = null;
foreach ( $adq_funnel as $adq_row ) {
	if ( null === $adq_top_source
		|| (int) $adq_row['leads'] > (int) $adq_top_source['leads']
		|| ( (int) $adq_row['leads'] === (int) $adq_top_source['leads'] && (int) $adq_row['sesiones'] > (int) $adq_top_source['sesiones'] ) ) {
		$adq_top_source = $adq_row;
	}
}
foreach ( $adq_campaigns as $adq_row ) {
	if ( null === $adq_top_campaign
		|| (int) $adq_row['leads'] > (int) $adq_top_campaign['leads']
		|| ( (int) $adq_row['leads'] === (int) $adq_top_campaign['leads'] && (int) $adq_row['sesiones'] > (int) $adq_top_campaign['sesiones'] ) ) {
		$adq_top_campaign = $adq_row;
	}
}

$adq_source_label = static function ( array $row ): string {
	$canal  = trim( (string) ( $row['canal'] ?? '' ) );
	$source = trim( (string) ( $row['source'] ?? '' ) );
	if ( '' !== $source && '' !== $canal && strtolower( $source ) !== strtolower( $canal ) ) {
		return $canal . ' · ' . $source;
	}
	return '' !== $canal ? $canal : ( '' !== $source ? $source : __( '(directo)', 'estandar-analytics' ) );
};
?>

<style>
	.ea-adq-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;}
	.ea-adq-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;}
	.ea-adq-kpi b{display:block;font-size:22px;line-height:1.15;color:#111827;font-weight:700;}
	.ea-adq-kpi span{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px;}
	.ea-adq-kpi em{display:block;font-size:11px;color:#9ca3af;font-style:normal;margin-top:3px;}
	.ea-adq-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
	.ea-adq-table th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:9px 12px;border-bottom:1px solid #e5e7eb;background:#f9fafb;}
	.ea-adq-table td{padding:8px 12px;font-size:13px;color:#1d2327;border-bottom:1px solid #f0f0f1;vertical-align:middle;}
	.ea-adq-table tr:last-child td{border-bottom:0;}
	.ea-adq-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
	.ea-adq-bar{position:relative;height:16px;background:#f3f4f6;border-radius:3px;min-width:120px;overflow:hidden;}
	.ea-adq-bar i{position:absolute;inset:0 auto 0 0;border-radius:3px;background:var(--estandar-color-primary,#f95b1f);opacity:.85;}
	.ea-adq-bar.is-sessions i{background:#2271b1;}
	.ea-adq-section-title{margin:18px 0 8px;font-size:14px;font-weight:600;color:#1d2327;}
	.ea-adq-section-title small{font-weight:400;color:#787c82;margin-left:6px;}
	.ea-adquisicion-section{margin-top:10px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;}
	.ea-adquisicion-section > summary{padding:10px 14px;cursor:pointer;}
	.ea-adquisicion-section > summary:hover{background:#f9fafb;}
	.ea-adquisicion-section[open] > summary{border-bottom:1px solid #f0f0f1;}
	.ea-adquisicion-section .ea-adq-body{padding:6px 14px 14px;}
</style>

<?php /* ── 1 · Resumen 30 segundos ─────────────────────────────────────── */ ?>
<div class="ea-adq-kpis">
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></span>
		<b><?php echo esc_html( number_format_i18n( $adq_sessions ) ); ?></b>
		<em><?php esc_html_e( 'Visitas al sitio en el período', 'estandar-analytics' ); ?></em>
	</div>
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></span>
		<b><?php echo esc_html( number_format_i18n( $adq_leads ) ); ?></b>
		<em><?php esc_html_e( 'Personas que dejaron contacto', 'estandar-analytics' ); ?></em>
	</div>
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Conversión', 'estandar-analytics' ); ?></span>
		<b><?php echo null === $adq_conv_rate ? '—' : esc_html( number_format_i18n( $adq_conv_rate, 1 ) . ' %' ); ?></b>
		<em><?php esc_html_e( 'Sesión → lead', 'estandar-analytics' ); ?></em>
	</div>
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Atribuibles', 'estandar-analytics' ); ?></span>
		<b style="<?php echo $adq_leads > 0 && $adq_join_rate < 50 ? 'color:#b45309;' : ''; ?>"><?php echo $adq_leads > 0 ? esc_html( number_format_i18n( $adq_join_rate, 1 ) . ' %' ) : '—'; ?></b>
		<em><?php esc_html_e( 'Leads con origen identificado', 'estandar-analytics' ); ?></em>
	</div>
	<?php if ( $adq_top_source && ( (int) $adq_top_source['leads'] > 0 || (int) $adq_top_source['sesiones'] > 0 ) ) : ?>
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Fuente top', 'estandar-analytics' ); ?></span>
		<b style="font-size:15px;"><?php echo esc_html( $adq_source_label( $adq_top_source ) ); ?></b>
		<em><?php echo esc_html( sprintf( __( '%1$s leads · %2$s sesiones', 'estandar-analytics' ), number_format_i18n( (int) $adq_top_source['leads'] ), number_format_i18n( (int) $adq_top_source['sesiones'] ) ) ); ?></em>
	</div>
	<?php endif; ?>
	<?php if ( $adq_top_campaign && (int) $adq_top_campaign['leads'] > 0 ) : ?>
	<div class="ea-adq-kpi">
		<span><?php esc_html_e( 'Campaña top', 'estandar-analytics' ); ?></span>
		<b style="font-size:15px;"><?php echo esc_html( (string) $adq_top_campaign['campana'] ); ?></b>
		<em><?php echo esc_html( sprintf( __( '%s leads', 'estandar-analytics' ), number_format_i18n( (int) $adq_top_campaign['leads'] ) ) ); ?></em>
	</div>
	<?php endif; ?>
</div>

<?php if ( ! $adq_has_data ) : ?>
	<div style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;color:#646970;font-size:13px;margin-bottom:4px;">
		<strong><?php esc_html_e( 'Sin tráfico registrado en el período seleccionado.', 'estandar-analytics' ); ?></strong>
		<?php esc_html_e( 'Las secciones de detalle de abajo estarán vacías; cambia el rango de fechas en cada sección o espera nuevas visitas.', 'estandar-analytics' ); ?>
	</div>
<?php else : ?>

	<?php /* ── 2 · ¿De dónde vienen los leads? ─────────────────────────── */ ?>
	<?php
	$adq_rows      = array_slice( $adq_funnel, 0, 8 );
	$adq_has_leads = false;
	$adq_max       = 1;
	foreach ( $adq_rows as $adq_row ) {
		if ( (int) $adq_row['leads'] > 0 ) {
			$adq_has_leads = true;
		}
	}
	foreach ( $adq_rows as $adq_row ) {
		$adq_max = max( $adq_max, $adq_has_leads ? (int) $adq_row['leads'] : (int) $adq_row['sesiones'] );
	}
	?>
	<?php if ( ! empty( $adq_rows ) ) : ?>
	<h3 class="ea-adq-section-title">
		<?php esc_html_e( '¿De dónde vienen los leads?', 'estandar-analytics' ); ?>
		<small><?php echo $adq_has_leads ? esc_html__( 'barra = leads por fuente', 'estandar-analytics' ) : esc_html__( 'sin leads en el período — barra = sesiones por fuente', 'estandar-analytics' ); ?></small>
	</h3>
	<table class="ea-adq-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
				<th class="num"><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
				<th class="num"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
				<th class="num"><?php esc_html_e( 'Conversión', 'estandar-analytics' ); ?></th>
				<th style="width:34%;"></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $adq_rows as $adq_row ) :
			$adq_v   = $adq_has_leads ? (int) $adq_row['leads'] : (int) $adq_row['sesiones'];
			$adq_pct = round( $adq_v * 100 / $adq_max );
		?>
			<tr>
				<td><?php echo esc_html( $adq_source_label( $adq_row ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $adq_row['sesiones'] ) ); ?></td>
				<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $adq_row['leads'] ) ); ?></strong></td>
				<td class="num"><?php echo '' === (string) ( $adq_row['conv_rate'] ?? '' ) ? '—' : esc_html( number_format_i18n( (float) $adq_row['conv_rate'], 1 ) . ' %' ); ?></td>
				<td><div class="ea-adq-bar<?php echo $adq_has_leads ? '' : ' is-sessions'; ?>"><i style="width:<?php echo esc_attr( (string) max( 2, $adq_pct ) ); ?>%;"></i></div></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php /* ── 3 · Campañas con resultado ──────────────────────────────── */ ?>
	<?php
	$adq_camp_rows = array_slice( $adq_campaigns, 0, 5 );
	$adq_camp_max  = 1;
	foreach ( $adq_camp_rows as $adq_row ) {
		$adq_camp_max = max( $adq_camp_max, max( (int) $adq_row['leads'], 1 ) );
	}
	?>
	<h3 class="ea-adq-section-title">
		<?php esc_html_e( 'Campañas con resultado', 'estandar-analytics' ); ?>
		<small><?php esc_html_e( 'basado en utm_campaign', 'estandar-analytics' ); ?></small>
	</h3>
	<?php if ( empty( $adq_camp_rows ) ) : ?>
		<div style="padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;color:#646970;font-size:12px;">
			<?php esc_html_e( 'Sin campañas detectadas en el período. Para que aparezcan aquí, las URLs de los anuncios deben incluir utm_campaign=.', 'estandar-analytics' ); ?>
		</div>
	<?php else : ?>
	<table class="ea-adq-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Campaña', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Fuente / medio', 'estandar-analytics' ); ?></th>
				<th class="num"><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
				<th class="num"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
				<th style="width:28%;"></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $adq_camp_rows as $adq_row ) :
			$adq_camp_label    = (string) $adq_row['campana'];
			$adq_camp_unmapped = false;
			// Solo IDs numéricos de plataforma pasan por el resolver.
			if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) && ctype_digit( $adq_camp_label ) && strlen( $adq_camp_label ) >= 8 ) {
				$adq_resolved = (string) Estandar_Campaign_Label_Resolver::campaign( $adq_camp_label );
				if ( '' !== $adq_resolved ) {
					$adq_camp_unmapped = false !== strpos( $adq_resolved, 'no clasificad' );
					$adq_camp_label    = $adq_resolved;
				}
			}
		?>
			<tr>
				<td>
					<?php if ( $adq_camp_unmapped ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_campaigns' ) ); ?>" title="<?php esc_attr_e( 'Sin etiqueta: click para nombrarla en Campañas', 'estandar-analytics' ); ?>" style="color:#996800;text-decoration:none;border-bottom:1px dashed #b7791f;"><?php echo esc_html( $adq_camp_label ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $adq_camp_label ); ?>
					<?php endif; ?>
				</td>
				<td style="color:#6b7280;"><?php echo esc_html( trim( (string) $adq_row['source'] . ' / ' . (string) $adq_row['medium'], ' /' ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $adq_row['sesiones'] ) ); ?></td>
				<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $adq_row['leads'] ) ); ?></strong></td>
				<td><div class="ea-adq-bar"><i style="width:<?php echo esc_attr( (string) max( 2, round( (int) $adq_row['leads'] * 100 / $adq_camp_max ) ) ); ?>%;"></i></div></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

<?php endif; // adq_has_data ?>

<?php /* ── 4 · Detalle completo (paneles legados, colapsados) ──────────── */ ?>
<h3 class="ea-adq-section-title" style="margin-top:22px;">
	<?php esc_html_e( 'Detalle completo', 'estandar-analytics' ); ?>
	<small><?php esc_html_e( 'las mismas cifras de arriba, desglosadas — cada sección tiene su propio selector de rango', 'estandar-analytics' ); ?></small>
</h3>

<details class="ea-adquisicion-section">
	<summary class="ea-adquisicion-section__title">
		<h2 style="display:inline;font-size:14px;font-weight:600;"><?php esc_html_e( 'Atribución', 'estandar-analytics' ); ?></h2>
		<span style="color:#646970;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Canal → sesión → lead · calidad de señal y diagnóstico técnico', 'estandar-analytics' ); ?></span>
	</summary>
	<div class="ea-adq-body">
	<?php
	$data = $data_attr;
	include __DIR__ . '/attribution.php';
	?>
	</div>
</details>

<details class="ea-adquisicion-section">
	<summary class="ea-adquisicion-section__title">
		<h2 style="display:inline;font-size:14px;font-weight:600;"><?php esc_html_e( 'Origen', 'estandar-analytics' ); ?></h2>
		<span style="color:#646970;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Fuentes, campañas y dominios referentes', 'estandar-analytics' ); ?></span>
	</summary>
	<div class="ea-adq-body">
	<?php
	// traffic.php usa $data_acq y $data_beh directamente — ya disponibles.
	include __DIR__ . '/traffic.php';
	?>
	</div>
</details>

<details class="ea-adquisicion-section">
	<summary class="ea-adquisicion-section__title">
		<h2 style="display:inline;font-size:14px;font-weight:600;"><?php esc_html_e( 'Páginas / URLs', 'estandar-analytics' ); ?></h2>
		<span style="color:#646970;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Comportamiento en sitio, scroll y clics', 'estandar-analytics' ); ?></span>
	</summary>
	<div class="ea-adq-body">
	<?php
	$data = $data_beh;
	include __DIR__ . '/behavior.php';
	?>
	</div>
</details>

<details class="ea-adquisicion-section">
	<summary class="ea-adquisicion-section__title">
		<h2 style="display:inline;font-size:14px;font-weight:600;"><?php esc_html_e( 'Horarios', 'estandar-analytics' ); ?></h2>
		<span style="color:#646970;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Cuándo llega tráfico y cuándo convierte', 'estandar-analytics' ); ?></span>
	</summary>
	<div class="ea-adq-body">
	<?php
	$data = $data_time;
	include __DIR__ . '/times.php';
	?>
	</div>
</details>
