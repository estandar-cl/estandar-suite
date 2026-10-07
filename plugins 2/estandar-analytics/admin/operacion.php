<?php
/**
 * Operación comercial — Centro de Acción Operacional.
 *
 * Variables disponibles (via extract en shell()):
 *   $data    — resultado de EA_Dashboard::get_commercial_results()
 *   $filters — filtros activos
 *   $options — filtros disponibles
 *
 * REORGANIZACIÓN VISUAL: este archivo NO incluye el partial compartido
 * _operations.php (usado también por results.php) para no alterar ea-results.
 * Renderiza las MISMAS métricas/tablas en orden ejecutivo. Sin queries nuevas,
 * sin cálculos nuevos: sólo reordenamiento y orden visual de filas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$operations = $data['operations'] ?? array();
$op_totals  = $operations['totals'] ?? array();
$op_aging   = $operations['aging'] ?? array();
$recontacts = $operations['recontacts'] ?? array();

// Orden visual (no modifica datos): copias ordenadas.
$ops_by_operator = $operations['by_operator'] ?? array();
usort( $ops_by_operator, function ( $a, $b ) {
	return (int) ( $b['unclassified'] ?? 0 ) <=> (int) ( $a['unclassified'] ?? 0 );
} );

$recon_by_project = $recontacts['by_project'] ?? array();
usort( $recon_by_project, function ( $a, $b ) {
	return (int) ( $b['recontacts'] ?? 0 ) <=> (int) ( $a['recontacts'] ?? 0 );
} );
?>

<!-- ── 1. SALUD OPERACIONAL ───────────────────────────────────────────────── -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Salud operacional · últimos 30 días', 'estandar-analytics' ); ?></h2>
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

<!-- ── RECONTACTOS (métricas) ─────────────────────────────────────────────── -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Recontactos · últimos 30 días', 'estandar-analytics' ); ?></h2>
	</header>
	<?php
	if ( function_exists( 'estandar_render_kpi_cards' ) ) {
		estandar_render_kpi_cards( array(
			array( 'label' => __( 'Total recontactos', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['recontacted_leads'] ?? 0 ) ), 'status' => 'info' ),
			array( 'label' => __( 'Recontactados sin acción', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['without_action_total'] ?? 0 ) ), 'status' => 'danger' ),
			array( 'label' => __( 'Repeat Lead Rate', 'estandar-analytics' ), 'value' => number_format_i18n( (float) ( $recontacts['recontact_rate'] ?? 0 ), 1 ) . ' %', 'status' => 'success' ),
			array( 'label' => __( 'Recontactos sin clasificar', 'estandar-analytics' ), 'value' => number_format_i18n( (int) ( $recontacts['unclassified_recontacts'] ?? 0 ) ), 'status' => 'warning' ),
		) );
	}
	?>
	<?php if ( ! empty( $recontacts['without_action_top_project'] ) || ! empty( $recontacts['without_action_top_operator'] ) ) : ?>
		<p style="padding:0 24px 12px;margin:0;color:#646970;font-size:12px;">
			<?php esc_html_e( 'Proyecto con más recontactos sin acción:', 'estandar-analytics' ); ?>
			<strong><?php echo esc_html( resolve_project_display_name( $recontacts['without_action_top_project'] ?? '', '' ) ?: (string) ( $recontacts['without_action_top_project'] ?? '—' ) ); ?></strong>
			&nbsp;·&nbsp;
			<?php esc_html_e( 'Operador con más recontactos sin acción:', 'estandar-analytics' ); ?>
			<strong><?php echo esc_html( (string) ( $recontacts['without_action_top_operator'] ?? '—' ) ); ?></strong>
		</p>
	<?php endif; ?>
</section>

<!-- ── 2. ACCIONES PRIORITARIAS ───────────────────────────────────────────── -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Acciones prioritarias', 'estandar-analytics' ); ?></h2>
	</header>
	<div style="display:flex;gap:10px;flex-wrap:wrap;padding:16px 24px;">
		<a class="button" href="#op-sin-clasificar">
			🔴 <?php esc_html_e( 'Ver >72h sin clasificar', 'estandar-analytics' ); ?>
			<strong>(<?php echo esc_html( number_format_i18n( (int) ( $op_aging['72_plus'] ?? 0 ) ) ); ?>)</strong>
		</a>
		<a class="button" href="#op-recontactados-sin-accion">
			🟠 <?php esc_html_e( 'Ver recontactados sin acción', 'estandar-analytics' ); ?>
			<strong>(<?php echo esc_html( number_format_i18n( (int) ( $recontacts['without_action_total'] ?? 0 ) ) ); ?>)</strong>
		</a>
		<a class="button" href="#op-estado-comercial">
			🟡 <?php esc_html_e( 'Ver sin estado comercial', 'estandar-analytics' ); ?>
		</a>
		<a class="button" href="#op-sin-clasificar">
			⚪ <?php esc_html_e( 'Ver sin clasificar', 'estandar-analytics' ); ?>
			<strong>(<?php echo esc_html( number_format_i18n( (int) ( $op_totals['unclassified'] ?? 0 ) ) ); ?>)</strong>
		</a>
	</div>
</section>

<!-- ── 3. OPERADORES (Operador → Gestión · Sin Clasificar DESC) ───────────── -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Operador → Gestión', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $ops_by_operator ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Clasificados', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Sin clasificar', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $ops_by_operator as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['operator'] ?? 'Sin asignar' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['classified'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['unclassified'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- ── 4. PROYECTOS (Proyecto → Recontactos · Recontactos DESC) ───────────── -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Proyecto → Recontactos', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $recon_by_project ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'Sin recontactos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Recontactos', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recon_by_project as $row ) : ?>
					<tr>
						<td><?php echo esc_html( resolve_project_display_name( $row['project'] ?? '', '' ) ?: (string) ( $row['project'] ?? '—' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['recontacts'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- ── DETALLE OPERACIONAL (segundo nivel) ────────────────────────────────── -->
<h3 style="margin:24px 0 12px;font-size:16px;font-weight:600;"><?php esc_html_e( 'Detalle operacional', 'estandar-analytics' ); ?></h3>

<!-- Tabla completa de Recontactados sin acción -->
<section class="ea-panel" style="margin-bottom:14px;" id="op-recontactados-sin-accion">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Recontactados sin acción', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $recontacts['without_action_rows'] ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'Sin recontactados sin acción para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Ingresos', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Primer ingreso', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Último ingreso', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Horas desde último ingreso', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Estado actual', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Acción', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recontacts['without_action_rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['name'] ?? '(sin nombre)' ) ); ?></td>
						<td><?php echo esc_html( resolve_project_display_name( $row['project_slug'] ?? '', '' ) ?: (string) ( $row['project_slug'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['operator_name'] ?? 'Sin asignar' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['submission_count'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['first_submitted_at'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['last_submitted_at'] ?? '—' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( (string) ( $row['hours_since_last_label'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['status'] ?? '—' ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . absint( $row['id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'Abrir', 'estandar-analytics' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- Fuente → Gestión -->
<section class="ea-panel" style="margin-bottom:14px;">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Fuente → Gestión', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['by_source'] ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Clasificados', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Sin clasificar', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $operations['by_source'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['source'] ?? '' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['classified'] ?? 0 ) ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['unclassified'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- Estado comercial → Leads -->
<section class="ea-panel" style="margin-bottom:14px;" id="op-estado-comercial">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Estado comercial → Leads', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['by_stage'] ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'Sin datos para los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Estado comercial', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
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
						<td><?php echo esc_html( $stage === 'SIN_ETAPA' ? 'Sin etapa' : ( $stage_labels[ $stage ] ?? $stage ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- Tabla completa de Leads sin clasificar -->
<section class="ea-panel" style="margin-bottom:14px;" id="op-sin-clasificar">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Leads sin clasificar', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $operations['unclassified_leads'] ) ) : ?>
		<p style="padding:20px 24px;color:#646970;"><?php esc_html_e( 'No hay leads sin clasificar en los últimos 30 días.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Canal', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Fecha ingreso', 'estandar-analytics' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Edad', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $operations['unclassified_leads'] as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . absint( $row['id'] ?? 0 ) ) ); ?>">
								<?php echo esc_html( (string) ( $row['name'] ?: '#' . (int) ( $row['id'] ?? 0 ) ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( (string) ( $row['capture_channel'] ?: '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['first_source_plugin'] ?: '—' ) ); ?></td>
						<td><?php echo esc_html( resolve_project_display_name( $row['project_slug'] ?? '', '' ) ?: '—' ); ?></td>
						<td><?php echo esc_html( (string) ( $row['source'] ?: 'direct' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['assigned_operator_name'] ?: 'Sin asignar' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( (string) ( $row['age_label'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['status'] ?: 'NEW' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<!-- ── EXPORTACIÓN ────────────────────────────────────────────────────────── -->
<h3 style="margin:24px 0 12px;font-size:16px;font-weight:600;"><?php esc_html_e( 'Exportación', 'estandar-analytics' ); ?></h3>

<section class="ea-panel">
	<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_analytics_export&format=csv' ), 'estandar_analytics_export' ) ); ?>">📥 <?php esc_html_e( 'Exportar CSV', 'estandar-analytics' ); ?></a>
	<p style="margin:12px 0 0 0;font-size:12px;color:#646970;">
		<?php esc_html_e( 'TODO: Copiar Contexto IA', 'estandar-analytics' ); ?>
	</p>
</section>
