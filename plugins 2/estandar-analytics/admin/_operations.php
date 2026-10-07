<?php
/**
 * Partial: secciones de operación comercial.
 *
 * Incluido desde results.php y operacion.php.
 * Espera $operations (array de get_commercial_operations_summary).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$op_totals  = $operations['totals']    ?? array();
$op_aging   = $operations['aging']     ?? array();
$recontacts = $operations['recontacts'] ?? array();
?>
<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Gestión comercial últimos 30 días', 'estandar-analytics' ); ?></h2>
	</header>
	<?php
	if ( function_exists( 'estandar_render_kpi_cards' ) ) {
		estandar_render_kpi_cards( array(
			array( 'label' => __( 'Total leads', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_totals['total'] ?? 0 ) ), 'status' => 'info' ),
			array( 'label' => __( 'Clasificados', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_totals['classified'] ?? 0 ) ), 'status' => 'success' ),
			array( 'label' => __( 'Sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_totals['unclassified'] ?? 0 ) ), 'status' => 'warning' ),
			array( 'label' => __( 'Classification Rate', 'estandar-analytics' ), 'value' => number_format_i18n( (float) ( $op_totals['classification_rate'] ?? 0 ), 1 ) . ' %', 'status' => 'info' ),
			array( 'label' => __( 'Con estado comercial', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_totals['staged'] ?? 0 ) ), 'status' => 'info' ),
			array( 'label' => __( 'Adopción estado comercial', 'estandar-analytics' ), 'value' => number_format_i18n( (float) ( $op_totals['stage_adoption_rate'] ?? 0 ), 1 ) . ' %', 'status' => 'warning' ),
		) );
		estandar_render_kpi_cards( array(
			array( 'label' => __( '0-24h sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_aging['0_24'] ?? 0 ) ), 'status' => 'info' ),
			array( 'label' => __( '24-72h sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_aging['24_72'] ?? 0 ) ), 'status' => 'warning' ),
			array( 'label' => __( '>72h sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $op_aging['72_plus'] ?? 0 ) ), 'status' => 'danger' ),
		) );
	}
	?>
</section>

<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Recontactos últimos 30 días', 'estandar-analytics' ); ?></h2>
	</header>
	<?php
	if ( function_exists( 'estandar_render_kpi_cards' ) ) {
		estandar_render_kpi_cards( array(
			array( 'label' => __( 'Total recontactos', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['recontacted_leads'] ?? 0 ) ), 'status' => 'info' ),
			array( 'label' => __( 'Recontactados sin acción', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['without_action_total'] ?? 0 ) ), 'status' => 'danger' ),
			array( 'label' => __( 'Repeat Lead Rate', 'estandar-analytics' ), 'value' => number_format_i18n( (float) ( $recontacts['recontact_rate'] ?? 0 ), 1 ) . ' %', 'status' => 'success' ),
			array( 'label' => __( 'Recontactos sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['unclassified_recontacts'] ?? 0 ) ), 'status' => 'warning' ),
			array( 'label' => __( 'Promedio ingresos', 'estandar-analytics' ), 'value' => number_format_i18n( (float) ( $recontacts['without_action_avg_submissions'] ?? 0 ), 1 ), 'status' => 'info' ),
			array( 'label' => __( 'Máximo ingresos', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['without_action_max_submissions'] ?? 0 ) ), 'status' => 'warning' ),
		) );
	}
	?>
	<?php if ( ! empty( $recontacts['without_action_top_project'] ) || ! empty( $recontacts['without_action_top_operator'] ) ) : ?>
		<p style="padding:0 16px 8px;margin:0;color:#646970;font-size:12px;">
			<?php esc_html_e( 'Proyecto con más recontactos sin acción:', 'estandar-analytics' ); ?>
			<strong><?php echo esc_html( resolve_project_display_name( $recontacts['without_action_top_project'] ?? '', '' ) ?: (string) ( $recontacts['without_action_top_project'] ?? '—' ) ); ?></strong>
			&nbsp;·&nbsp;
			<?php esc_html_e( 'Operador con más recontactos sin acción:', 'estandar-analytics' ); ?>
			<strong><?php echo esc_html( (string) ( $recontacts['without_action_top_operator'] ?? '—' ) ); ?></strong>
		</p>
	<?php endif; ?>
	<?php if ( empty( $recontacts['without_action_rows'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'Sin recontactados sin acción para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll" id="recontactados-sin-accion">
			<table class="ea-table" style="font-size:13px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Ingresos', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Primer ingreso', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Último ingreso', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Horas desde último ingreso', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Estado actual', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Acción', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recontacts['without_action_rows'] as $row ) : ?>
					<tr>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['name'] ?? '(sin nombre)' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( resolve_project_display_name( $row['project_slug'] ?? '', '' ) ?: (string) ( $row['project_slug'] ?? '—' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['operator_name'] ?? 'Sin asignar' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['submission_count'] ?? 0 ) ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['first_submitted_at'] ?? '—' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['last_submitted_at'] ?? '—' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( (string) ( $row['hours_since_last_label'] ?? '—' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['status'] ?? '—' ) ); ?></td>
						<td style="padding:5px 12px;"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . absint( $row['id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'Abrir', 'estandar-analytics' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php if ( empty( $recontacts['by_project'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'Sin recontactos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table" style="font-size:13px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Recontactos', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recontacts['by_project'] as $row ) : ?>
					<tr>
						<td style="padding:5px 12px;"><?php echo esc_html( resolve_project_display_name( $row['project'] ?? '', '' ) ?: (string) ( $row['project'] ?? '—' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['recontacts'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Estado comercial → Leads', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['by_stage'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table" style="font-size:13px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Estado comercial', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$stage_labels = class_exists( 'Estandar_Leads_Normalizer' ) && method_exists( 'Estandar_Leads_Normalizer', 'commercial_stage_labels' )
					? Estandar_Leads_Normalizer::commercial_stage_labels()
					: array();
				foreach ( $operations['by_stage'] as $row ) :
					$stage = (string) ( $row['stage'] ?? '' );
				?>
					<tr>
						<td style="padding:5px 12px;"><?php echo esc_html( $stage === 'SIN_ETAPA' ? 'Sin etapa' : ( $stage_labels[ $stage ] ?? $stage ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Operador → Gestión', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['by_operator'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table" style="font-size:13px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Clasificados', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Sin clasificar', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $operations['by_operator'] as $row ) : ?>
					<tr>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['operator'] ?? 'Sin asignar' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['classified'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['unclassified'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Fuente → Gestión', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['by_source'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table" style="font-size:13px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Clasificados', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Sin clasificar', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $operations['by_source'] as $row ) : ?>
					<tr>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['source'] ?? '' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['classified'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( (int) ( $row['unclassified'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<section class="ea-panel" style="margin-bottom:10px;">
	<header class="ea-panel__head">
		<h2 style="color:#d63638;"><?php esc_html_e( 'Leads sin clasificar', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['unclassified_leads'] ) ) : ?>
		<p style="padding:10px 16px;color:#646970;"><?php esc_html_e( 'No hay leads sin clasificar en los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table" style="font-size:12px;">
				<thead>
					<tr>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Canal', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Plugin', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Fecha ingreso', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Edad', 'estandar-analytics' ); ?></th>
						<th style="padding:6px 12px;"><?php esc_html_e( 'Estado', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $operations['unclassified_leads'] as $row ) : ?>
					<tr>
						<td style="padding:5px 12px;">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . absint( $row['id'] ?? 0 ) ) ); ?>">
								<?php echo esc_html( (string) ( $row['name'] ?: '#' . (int) ( $row['id'] ?? 0 ) ) ); ?>
							</a>
						</td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['capture_channel'] ?: '—' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['first_source_plugin'] ?: '—' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( resolve_project_display_name( $row['project_slug'] ?? '', '' ) ?: '—' ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['source'] ?: 'direct' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['assigned_operator_name'] ?: 'Sin asignar' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
						<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( (string) ( $row['age_label'] ?? '' ) ); ?></td>
						<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['status'] ?: 'NEW' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
