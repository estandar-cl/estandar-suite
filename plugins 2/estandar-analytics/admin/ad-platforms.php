<?php
/**
 * Ad Platforms V1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$platforms = (array) ( $data['platforms'] ?? array() );
$total     = (array) ( $data['total'] ?? array() );
$phase2    = (array) ( $data['phase2'] ?? array() );

$money = static function ( $value ): string {
	return is_null( $value ) ? '-' : '$' . number_format_i18n( (float) $value, 0 );
};
$pct = static function ( $value ): string {
	return is_null( $value ) ? '-' : number_format_i18n( (float) $value, 2 ) . '%';
};
$num = static function ( $value ): string {
	return number_format_i18n( (int) $value );
};
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
	<?php EA_Admin_UI::render_range_shortcuts( $filters, 'ea-ad-platforms' ); ?>
</div>

<?php if ( isset( $_GET['ea_imported'] ) ) : ?>
	<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( __( 'Filas importadas: %d', 'estandar-analytics' ), absint( $_GET['ea_imported'] ) ) ); ?></p></div>
<?php endif; ?>

<section class="ea-panel" style="margin-bottom:16px;">
	<header class="ea-panel__head"><h2><?php esc_html_e( 'Resumen', 'estandar-analytics' ); ?></h2></header>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plataforma', 'estandar-analytics' ); ?></th>
					<th style="text-align:right;">Imp.</th>
					<th style="text-align:right;">Reach</th>
					<th style="text-align:right;">Clicks</th>
					<th style="text-align:right;">Spend</th>
					<th style="text-align:right;">CTR</th>
					<th style="text-align:right;">CPC</th>
					<th style="text-align:right;">CPM</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $platforms as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( strtoupper( (string) ( $row['platform'] ?? '' ) ) ); ?></strong></td>
						<td style="text-align:right;"><?php echo esc_html( $num( $row['impressions'] ?? 0 ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $num( $row['reach'] ?? 0 ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $num( $row['clicks'] ?? 0 ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $money( $row['spend'] ?? 0 ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $pct( $row['ctr'] ?? null ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $money( $row['cpc'] ?? null ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $money( $row['cpm'] ?? null ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td><strong><?php esc_html_e( 'Total', 'estandar-analytics' ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $num( $total['impressions'] ?? 0 ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $num( $total['reach'] ?? 0 ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $num( $total['clicks'] ?? 0 ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $money( $total['spend'] ?? 0 ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $pct( $total['ctr'] ?? null ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $money( $total['cpc'] ?? null ) ); ?></strong></td>
					<td style="text-align:right;"><strong><?php echo esc_html( $money( $total['cpm'] ?? null ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>

<section class="ea-panel" style="margin-bottom:16px;">
	<header class="ea-panel__head"><h2><?php esc_html_e( 'Funnel superior', 'estandar-analytics' ); ?></h2></header>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<tbody>
				<tr><th>Impressions</th><td style="text-align:right;"><?php echo esc_html( $num( $total['impressions'] ?? 0 ) ); ?></td></tr>
				<tr><th>Reach</th><td style="text-align:right;"><?php echo esc_html( $num( $total['reach'] ?? 0 ) ); ?></td></tr>
				<tr><th>Clicks</th><td style="text-align:right;"><?php echo esc_html( $num( $total['clicks'] ?? 0 ) ); ?></td></tr>
				<tr><th>Sessions</th><td style="text-align:right;"><?php echo esc_html( $num( $total['sessions'] ?? 0 ) ); ?></td></tr>
				<tr><th>Leads</th><td style="text-align:right;"><?php echo esc_html( $num( $total['leads'] ?? 0 ) ); ?></td></tr>
				<tr><th>Qualified Leads</th><td style="text-align:right;"><?php echo esc_html( $num( $total['qualified_leads'] ?? 0 ) ); ?></td></tr>
				<tr><th>Reservations</th><td style="text-align:right;"><?php echo esc_html( $num( $total['reservations'] ?? 0 ) ); ?></td></tr>
				<tr><th>Cost / Session</th><td style="text-align:right;"><?php echo esc_html( $money( $total['cost_per_session'] ?? null ) ); ?></td></tr>
				<tr><th>Cost / Lead</th><td style="text-align:right;"><?php echo esc_html( $money( $total['cost_per_lead'] ?? null ) ); ?></td></tr>
				<tr><th>Cost / Qualified Lead</th><td style="text-align:right;"><?php echo esc_html( $money( $total['cost_per_qualified_lead'] ?? null ) ); ?></td></tr>
				<tr><th>Cost / Reservation</th><td style="text-align:right;"><?php echo esc_html( $money( $total['cost_per_reservation'] ?? null ) ); ?></td></tr>
			</tbody>
		</table>
	</div>
</section>

<section class="ea-panel" style="margin-bottom:16px;">
	<header class="ea-panel__head"><h2><?php esc_html_e( 'Importación CSV manual', 'estandar-analytics' ); ?></h2></header>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="padding:16px 24px;">
		<input type="hidden" name="action" value="ea_ads_daily_import">
		<input type="hidden" name="ea_return_page" value="ea-ad-platforms">
		<?php wp_nonce_field( 'ea_ads_daily_import' ); ?>
		<textarea name="ea_ads_daily_csv" rows="7" style="width:100%;font-family:monospace;" placeholder="date,platform,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,clicks,spend"></textarea>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Importar Meta / Google CSV', 'estandar-analytics' ); ?></button></p>
	</form>
</section>

<section class="ea-panel">
	<header class="ea-panel__head"><h2><?php esc_html_e( 'Fase 2 - Diseño API', 'estandar-analytics' ); ?></h2></header>
	<div class="ea-table-scroll">
		<table class="ea-table">
			<tbody>
				<?php foreach ( $phase2 as $platform => $notes ) : ?>
					<tr><th colspan="2"><?php echo esc_html( $platform ); ?></th></tr>
					<?php foreach ( (array) $notes as $key => $value ) : ?>
						<tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( $value ); ?></td></tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
