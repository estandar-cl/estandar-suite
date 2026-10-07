<?php
/**
 * Tráfico — adquisición + comportamiento consolidados.
 * Vista de contexto, no de decisión principal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	?>
		<?php EA_Admin_UI::render_project_trust_badge( 'unsupported' ); ?>
		<?php
		// ── Fuentes ───────────────────────────────────────────────────────────
	$source_rows = array();
	foreach ( (array) $data_acq['source_medium'] as $row ) {
		$source_rows[] = array(
			'label' => trim( ( $row['source'] ?? '' ) . ' / ' . ( $row['medium'] ?? '' ), ' /' ),
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}
	$campaign_rows = array();
	foreach ( (array) $data_acq['campaigns'] as $row ) {
		$campaign = (string) ( $row['campaign'] ?? '' );
		$campaign_rows[] = array(
			'label' => class_exists( 'Estandar_Campaign_Label_Resolver' ) ? Estandar_Campaign_Label_Resolver::campaign( $campaign ) : $campaign,
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}
	$referrer_rows = array();
	foreach ( (array) $data_acq['referrers'] as $row ) {
		$referrer_rows[] = array( 'label' => (string) ( $row['referrer_domain'] ?? '' ), 'total' => (int) ( $row['total'] ?? 0 ) );
	}
	?>

	<div class="ea-panels-grid">
		<?php EA_Admin_UI::render_ranked_table( __( 'Fuente / medio', 'estandar-analytics' ), $source_rows, 'source' ); ?>
		<?php EA_Admin_UI::render_ranked_table( __( 'Campañas', 'estandar-analytics' ), $campaign_rows ); ?>
		<?php EA_Admin_UI::render_ranked_table( __( 'Dominios referentes', 'estandar-analytics' ), $referrer_rows, 'source' ); ?>
	</div>

	<!-- ── CTAs y comportamiento ────────────────────────────────────────── -->
	<div class="ea-panels-grid">
		<?php
		EA_Admin_UI::render_ranked_table( __( 'Interacciones comerciales', 'estandar-analytics' ), $data_beh['cta'] ?? array() );
		EA_Admin_UI::render_ranked_table( __( 'Páginas más vistas', 'estandar-analytics' ),
			array_map( function( $r ) {
				return array( 'label' => $r['label'] ?? '', 'total' => (int) ( $r['total'] ?? 0 ) );
			}, $data_beh['pages'] ?? array() )
		);
		?>
	</div>

	<!-- ── UTM detalle ──────────────────────────────────────────────────── -->
	<?php if ( ! empty( $data_acq['utm'] ) ) : ?>
	<section class="ea-panel">
		<header class="ea-panel__head"><h2><?php esc_html_e( 'Desglose UTM', 'estandar-analytics' ); ?></h2></header>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead><tr>
					<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Medio', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Campaña', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Anuncio', 'estandar-analytics' ); ?></th>
					<th><?php esc_html_e( 'Sesiones', 'estandar-analytics' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $data_acq['utm'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['source'] ); ?></td>
						<td><?php echo esc_html( (string) $row['medium'] ); ?></td>
						<td><?php echo esc_html( class_exists( 'Estandar_Campaign_Label_Resolver' ) ? Estandar_Campaign_Label_Resolver::campaign( $row['campaign'] ) : (string) $row['campaign'] ); ?></td>
						<td><?php echo esc_html( class_exists( 'Estandar_Campaign_Label_Resolver' ) ? Estandar_Campaign_Label_Resolver::ad( $row['content'] ) : (string) $row['content'] ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<p class="ea-export-row">
		<a class="ea-btn ea-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ea_export&type=acquisition' ), 'ea_export' ) ); ?>">
			<?php esc_html_e( 'Exportar CSV', 'estandar-analytics' ); ?>
		</a>
	</p>
