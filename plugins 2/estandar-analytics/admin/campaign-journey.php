<?php
/**
 * Campaign Journey V1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows  = (array) ( $data['rows'] ?? array() );
$audit = (array) ( $data['audit'] ?? array() );
$lead_rows = (array) ( $data['lead_drilldown'] ?? array() );
$base_hidden = array(
	'page'    => 'ea-campaign-journey',
	'ea_from' => $filters['from'] ?? '',
	'ea_to'   => $filters['to'] ?? '',
);
$money = static function ( $value ): string {
	return '$' . number_format_i18n( (float) $value, 0 );
};
$cpa = static function ( $spend, $count ) use ( $money ): string {
	$count = (int) $count;
	return $count > 0 ? $money( (float) $spend / $count ) : '-';
};
$project_label = static function ( string $slug ): string {
	return function_exists( 'resolve_project_display_name' ) ? resolve_project_display_name( $slug, $slug ) : $slug;
};
$lead_drilldown_active = ( $filters['campaign_id'] ?? '' ) !== '' || ( $filters['adset_id'] ?? '' ) !== '' || ( $filters['ad_id'] ?? '' ) !== '';

// CAMPAIGN_JOURNEY_UX_V1 — nombre humano por nivel: nombre importado (CSV) →
// etiqueta del diccionario Campaign Labels → fallback legible del resolver.
// Nunca el ID crudo como título; el ID queda en la línea gris de trazabilidad.
$labels_url = admin_url( 'admin.php?page=estandar_campaigns' );
$nice_label = static function ( string $type, $id, $name = '' ): array {
	$name = trim( (string) $name );
	if ( '' !== $name ) {
		return array( $name, false );
	}
	$id = trim( (string) $id );
	if ( '' === $id ) {
		return array( '', false );
	}
	// Solo IDs numéricos de plataforma pasan por el resolver; un valor humano
	// (utm_campaign tipo "parronales") se muestra tal cual.
	if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) && ctype_digit( $id ) && strlen( $id ) >= 8 ) {
		$resolved = (string) Estandar_Campaign_Label_Resolver::resolve( $type, $id );
		// true = quedó en fallback "no clasificado" → accionable (link a etiquetas).
		return array( $resolved, false !== strpos( $resolved, 'no clasificad' ) );
	}
	return array( $id, false );
};

// Métricas de plataforma (impresiones/reach/clicks/gasto) solo existen si se
// importó el CSV de Meta/Google (tabla ea_ads_daily). Sin datos → columnas fuera.
$has_platform = false;
foreach ( $rows as $r ) {
	if ( (int) ( $r['impressions'] ?? 0 ) > 0 || (int) ( $r['reach'] ?? 0 ) > 0 || (int) ( $r['clicks'] ?? 0 ) > 0 || (float) ( $r['spend'] ?? 0 ) > 0 ) {
		$has_platform = true;
		break;
	}
}
$lead_link = static function ( array $row, string $level ) use ( $filters ): string {
	$args = array(
		'page'    => 'ea-campaign-journey',
		'ea_from' => $filters['from'] ?? '',
		'ea_to'   => $filters['to'] ?? '',
	);
	foreach ( array( 'project' => 'ea_project', 'source' => 'ea_source' ) as $filter_key => $query_key ) {
		if ( ( $filters[ $filter_key ] ?? '' ) !== '' ) {
			$args[ $query_key ] = $filters[ $filter_key ];
		}
	}
	if ( ( $row['campaign_id'] ?? '' ) !== '' ) {
		$args['ea_campaign_id'] = $row['campaign_id'];
	}
	if ( in_array( $level, array( 'adset', 'ad' ), true ) && ( $row['adset_id'] ?? '' ) !== '' ) {
		$args['ea_adset_id'] = $row['adset_id'];
	}
	if ( $level === 'ad' && ( $row['ad_id'] ?? '' ) !== '' ) {
		$args['ea_ad_id'] = $row['ad_id'];
	}
	return admin_url( 'admin.php?' . http_build_query( $args, '', '&' ) );
};
?>

<?php /* ── § FILTROS ──────────────────────────────────────────────────── */ ?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
	<?php EA_Admin_UI::render_range_shortcuts( $filters, 'ea-campaign-journey' ); ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
		<?php foreach ( $base_hidden as $k => $v ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<?php endforeach; ?>
		<select name="ea_project" style="font-size:12px;padding:3px 6px;">
			<option value=""><?php esc_html_e( 'Proyecto: Todos', 'estandar-analytics' ); ?></option>
			<?php foreach ( (array) ( $options['projects'] ?? array() ) as $project ) : ?>
				<option value="<?php echo esc_attr( $project ); ?>"<?php selected( $filters['project'] ?? '', $project ); ?>><?php echo esc_html( $project_label( (string) $project ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="ea_source" style="font-size:12px;padding:3px 6px;">
			<option value=""><?php esc_html_e( 'Canal: Todos', 'estandar-analytics' ); ?></option>
			<?php foreach ( (array) ( $options['sources'] ?? array() ) as $source ) : ?>
				<option value="<?php echo esc_attr( $source ); ?>"<?php selected( $filters['source'] ?? '', $source ); ?>><?php echo esc_html( $source ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="text" name="ea_campaign_id" value="<?php echo esc_attr( $filters['campaign_id'] ?? '' ); ?>" placeholder="Campaign ID" style="font-size:12px;padding:3px 6px;width:100px;">
		<input type="text" name="ea_adset_id" value="<?php echo esc_attr( $filters['adset_id'] ?? '' ); ?>" placeholder="Ad Set ID" style="font-size:12px;padding:3px 6px;width:80px;">
		<input type="text" name="ea_ad_id" value="<?php echo esc_attr( $filters['ad_id'] ?? '' ); ?>" placeholder="Ad ID" style="font-size:12px;padding:3px 6px;width:70px;">
		<button type="submit" class="button" style="font-size:12px;padding:2px 10px;"><?php esc_html_e( 'Filtrar', 'estandar-analytics' ); ?></button>
	</form>
</div>

<?php if ( isset( $_GET['ea_imported'] ) ) : ?>
	<div class="notice notice-success inline" style="margin-bottom:8px;"><p><?php echo esc_html( sprintf( __( 'Filas importadas: %d', 'estandar-analytics' ), absint( $_GET['ea_imported'] ) ) ); ?></p></div>
<?php endif; ?>

<?php /* ── § CAMPAIGN JOURNEY (bloque principal) ─────────────────────── */ ?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head"><h2><?php esc_html_e( 'Campaign Journey', 'estandar-analytics' ); ?></h2></header>
	<?php if ( empty( $rows ) ) : ?>
		<?php EA_Admin_UI::render_empty( __( 'Sin journeys para el período seleccionado.', 'estandar-analytics' ) ); ?>
	<?php else : ?>
	<?php if ( ! $has_platform ) : ?>
		<p style="margin:0 16px 8px;font-size:11px;color:#787c82;">
			<?php esc_html_e( 'Impresiones, reach, clicks de anuncio y gasto no se muestran: requieren importar el CSV de Meta/Google Ads (sección "Carga manual" al final de esta página). Los datos visibles provienen del sitio (sesiones, leads, resultados).', 'estandar-analytics' ); ?>
		</p>
	<?php endif; ?>
	<div class="ea-table-scroll">
		<table class="ea-table" style="font-size:12px;">
			<thead>
				<tr>
					<th style="min-width:160px;"><?php esc_html_e( 'Campaña / Anuncio', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
					<?php if ( $has_platform ) : ?>
					<th style="text-align:right;">Imp.</th>
					<th style="text-align:right;">Reach</th>
					<th style="text-align:right;">Clicks</th>
					<?php endif; ?>
					<th style="text-align:right;">Landing</th>
					<th style="text-align:right;">Popup</th>
					<th style="text-align:right;">Chatbot</th>
					<th style="text-align:right;">Forms</th>
					<th style="text-align:right;">Leads</th>
					<th style="text-align:right;">Calif.</th>
					<th style="text-align:right;">Visitas</th>
					<th style="text-align:right;">Reservas</th>
					<th style="text-align:right;">Ventas</th>
					<?php if ( $has_platform ) : ?>
					<th style="text-align:right;">Spend</th>
					<th style="text-align:right;">CPL</th>
					<th style="text-align:right;">CP Venta</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td style="padding:5px 10px;">
							<?php
							list( $camp_label, $camp_unmapped )   = $nice_label( 'campaigns', $row['campaign_key'] ?? ( $row['utm_campaign'] ?? '' ), $row['campaign_name'] ?? '' );
							list( $adset_label, $adset_unmapped ) = $nice_label( 'adsets', $row['adset_key'] ?? '', $row['adset_name'] ?? '' );
							list( $ad_label, $ad_unmapped )       = $nice_label( 'ads', $row['ad_key'] ?? '', $row['ad_name'] ?? '' );
							?>
							<strong style="font-size:12px;">
								<?php if ( $camp_unmapped ) : ?>
									<a href="<?php echo esc_url( $labels_url ); ?>" title="<?php esc_attr_e( 'Sin etiqueta: click para nombrarla en Campañas', 'estandar-analytics' ); ?>" style="color:#996800;text-decoration:none;border-bottom:1px dashed #b7791f;"><?php echo esc_html( $camp_label ); ?></a>
								<?php else : ?>
									<?php echo esc_html( '' !== $camp_label ? $camp_label : '-' ); ?>
								<?php endif; ?>
							</strong>
							<?php if ( '' !== $adset_label || '' !== $ad_label ) : ?>
								<span style="display:block;font-size:11px;color:#374151;margin-top:1px;">
									<?php if ( '' !== $adset_label ) echo esc_html( 'Conjunto: ' . $adset_label ); ?>
									<?php if ( '' !== $adset_label && '' !== $ad_label ) echo ' · '; ?>
									<?php if ( '' !== $ad_label ) echo esc_html( 'Anuncio: ' . $ad_label ); ?>
								</span>
							<?php endif; ?>
							<small style="font-size:10px;color:#a7aaad;display:block;margin-top:1px;">
								<?php echo esc_html( (string) ( $row['campaign_key'] ?? '-' ) ); ?> · <?php echo esc_html( (string) ( $row['adset_key'] ?? '-' ) ); ?> · <?php echo esc_html( (string) ( $row['ad_key'] ?? '-' ) ); ?>
							</small>
							<?php if ( ! empty( $row['campaign_id'] ) ) : ?>
								<div style="margin-top:4px;display:flex;gap:3px;flex-wrap:wrap;">
									<a class="button button-small" style="font-size:10px;padding:1px 6px;" href="<?php echo esc_url( $lead_link( $row, 'campaign' ) ); ?>"><?php esc_html_e( 'Leads campaña', 'estandar-analytics' ); ?></a>
									<?php if ( ! empty( $row['adset_id'] ) ) : ?>
										<a class="button button-small" style="font-size:10px;padding:1px 6px;" href="<?php echo esc_url( $lead_link( $row, 'adset' ) ); ?>"><?php esc_html_e( 'Leads conjunto', 'estandar-analytics' ); ?></a>
									<?php endif; ?>
									<?php if ( ! empty( $row['ad_id'] ) ) : ?>
										<a class="button button-small" style="font-size:10px;padding:1px 6px;" href="<?php echo esc_url( $lead_link( $row, 'ad' ) ); ?>"><?php esc_html_e( 'Leads anuncio', 'estandar-analytics' ); ?></a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="padding:5px 10px;font-size:12px;"><?php echo esc_html( $project_label( (string) ( $row['project_slug'] ?? '' ) ) ); ?></td>
						<?php if ( $has_platform ) : ?>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['impressions'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['reach'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ) ); ?></td>
						<?php endif; ?>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['landing_sessions'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['popup_opens'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['chatbot_starts'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $row['chatbot_leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['form_starts'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $row['form_submits'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><strong><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></strong></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['qualified_leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['visits'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['reservations'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( number_format_i18n( (int) ( $row['sales'] ?? 0 ) ) ); ?></td>
						<?php if ( $has_platform ) : ?>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( $money( $row['spend'] ?? 0 ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( $cpa( $row['spend'] ?? 0, $row['leads'] ?? 0 ) ); ?></td>
						<td style="text-align:right;padding:5px 8px;"><?php echo esc_html( $cpa( $row['spend'] ?? 0, $row['sales'] ?? 0 ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>

<?php /* ── § LEADS FILTRADOS ──────────────────────────────────────────── */ ?>
<?php if ( $lead_drilldown_active ) : ?>
	<section class="ea-panel" style="margin-bottom:10px;">
		<header class="ea-panel__head"><h2><?php esc_html_e( 'Leads filtrados', 'estandar-analytics' ); ?></h2></header>
		<?php if ( empty( $lead_rows ) ) : ?>
			<?php EA_Admin_UI::render_empty( __( 'No hay leads para el filtro seleccionado.', 'estandar-analytics' ) ); ?>
		<?php else : ?>
			<div class="ea-table-scroll">
				<table class="ea-table" style="font-size:12px;">
					<thead>
						<tr>
							<th style="padding:5px 10px;"><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
							<th style="padding:5px 10px;"><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
							<th style="padding:5px 10px;"><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
							<th style="padding:5px 10px;"><?php esc_html_e( 'Estado', 'estandar-analytics' ); ?></th>
							<th style="padding:5px 10px;"><?php esc_html_e( 'Fecha', 'estandar-analytics' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $lead_rows as $lead ) :
							$lead_id  = absint( $lead['id'] ?? 0 );
							$operator = trim( (string) ( $lead['assigned_operator_name'] ?? '' ) );
							if ( $operator === '' ) {
								$operator = trim( (string) ( $lead['assigned_operator_email'] ?? '' ) );
							}
						?>
							<tr>
								<td style="padding:5px 10px;">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads-portal&view=detail&lead_id=' . $lead_id ) ); ?>">
										<?php echo esc_html( 'Lead #' . $lead_id ); ?>
									</a>
									<?php if ( ! empty( $lead['name'] ) ) : ?>
										<br><small style="color:#646970;"><?php echo esc_html( (string) $lead['name'] ); ?></small>
									<?php endif; ?>
								</td>
								<td style="padding:5px 10px;"><?php echo esc_html( $operator !== '' ? $operator : __( 'Sin asignar', 'estandar-analytics' ) ); ?></td>
								<td style="padding:5px 10px;"><?php echo esc_html( $project_label( (string) ( $lead['project_slug'] ?? '' ) ) ); ?></td>
								<td style="padding:5px 10px;"><?php echo esc_html( (string) ( $lead['status'] ?? 'NEW' ) ); ?></td>
								<td style="padding:5px 10px;"><?php echo esc_html( (string) ( $lead['created_at'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php /* ── § MAPA FASE 0 (diagnóstico colapsado) ─────────────────────── */ ?>
<details class="ea-panel" style="margin-bottom:10px;">
	<summary style="padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;">
		<?php esc_html_e( 'Mapa Fase 0', 'estandar-analytics' ); ?>
		<span style="font-size:11px;font-weight:400;color:#646970;"><?php esc_html_e( 'Diagnóstico de columnas', 'estandar-analytics' ); ?></span>
	</summary>
	<div class="ea-table-scroll" style="padding:0 0 8px;">
		<table class="ea-table" style="font-size:12px;">
			<tbody>
				<?php foreach ( $audit as $field => $status ) : ?>
					<tr><th style="padding:4px 12px;"><code><?php echo esc_html( $field ); ?></code></th><td style="padding:4px 12px;"><?php echo esc_html( $status ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</details>

<?php /* ── § IMPORTAR CSV (utilidad avanzada, colapsada) ───────────────── */ ?>
<details class="ea-panel">
	<summary style="padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;">
		<?php esc_html_e( 'Carga manual Meta / Google Ads', 'estandar-analytics' ); ?>
		<span style="font-size:11px;font-weight:400;color:#646970;"><?php esc_html_e( 'Importar CSV', 'estandar-analytics' ); ?></span>
	</summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="padding:10px 16px;">
		<input type="hidden" name="action" value="ea_ads_daily_import">
		<input type="hidden" name="ea_return_page" value="ea-campaign-journey">
		<?php wp_nonce_field( 'ea_ads_daily_import' ); ?>
		<textarea name="ea_ads_daily_csv" rows="3" style="width:100%;font-family:monospace;font-size:12px;" placeholder="date,channel,project_slug,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,clicks,spend"></textarea>
		<p style="margin:6px 0 0;"><button type="submit" class="button button-primary" style="font-size:12px;"><?php esc_html_e( 'Importar CSV', 'estandar-analytics' ); ?></button></p>
	</form>
</details>
