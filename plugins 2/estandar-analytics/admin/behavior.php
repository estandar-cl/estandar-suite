<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$range_extra_args = array();
foreach ( array( 'source' => 'ea_source', 'campaign' => 'ea_campaign', 'project' => 'ea_project' ) as $filter_key => $query_key ) {
	if ( ! empty( $filters[ $filter_key ] ) ) {
		$range_extra_args[ $query_key ] = (string) $filters[ $filter_key ];
	}
}
if ( ! empty( $filters['limit'] ) ) {
	$range_extra_args['ea_limit'] = (string) $filters['limit'];
}
$filter_rows = array(
	array( 'name' => 'ea_source', 'label' => __( 'Fuente', 'estandar-analytics' ), 'options' => $options['sources'] ?? array(), 'current' => $filters['source'] ?? '' ),
	array( 'name' => 'ea_campaign', 'label' => __( 'Campaña', 'estandar-analytics' ), 'options' => $options['campaigns'] ?? array(), 'current' => $filters['campaign'] ?? '' ),
	array( 'name' => 'ea_project', 'label' => __( 'Proyecto', 'estandar-analytics' ), 'options' => $options['projects'] ?? array(), 'current' => $filters['project'] ?? '' ),
	array( 'name' => 'ea_limit', 'label' => __( 'Top', 'estandar-analytics' ), 'options' => array( '20', '50' ), 'current' => (string) ( $filters['limit'] ?? 50 ) ),
);
	?>
		<?php EA_Admin_UI::render_project_trust_badge( 'approx' ); ?>
		<div class="ea-filter-bar" style="margin-bottom:10px;">
			<?php EA_Admin_UI::render_range_shortcuts( $filters, 'ea-behavior', $range_extra_args ); ?>
		</div>

	<?php
	if ( function_exists( 'estandar_render_filter_bar' ) ) {
		estandar_render_filter_bar(
			$filter_rows,
			array(
				'action' => admin_url( 'admin.php' ),
				'hidden' => array(
					'page'    => 'ea-behavior',
					'ea_from' => $filters['from'] ?? '',
					'ea_to'   => $filters['to'] ?? '',
				),
			)
		);
	}
	?>

	<?php EA_Admin_UI::render_url_report_table( __( 'Páginas / URLs', 'estandar-analytics' ), $data['pages'] ); ?>

	<div class="ea-panels-grid">
		<?php EA_Admin_UI::render_ranked_table( __( 'CTA y contacto', 'estandar-analytics' ), $data['cta'], 'conversions' ); ?>
		<?php EA_Admin_UI::render_ranked_table( __( 'Profundidad de scroll', 'estandar-analytics' ), $data['scroll'] ); ?>
		<?php EA_Admin_UI::render_ranked_table( __( 'Métricas de clics', 'estandar-analytics' ), $data['clicks'] ); ?>
	</div>
