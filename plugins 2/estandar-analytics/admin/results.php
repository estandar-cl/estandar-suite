<?php
/**
 * Resultados comerciales — Fuente / Campaña / Keyword → Lead → Resultado.
 *
 * Variables disponibles:
 *   $data    — resultado de EA_Dashboard::get_commercial_results()
 *   $options — filtros disponibles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$totals      = $data['totals'];
$leads_active = (bool) $data['leads_active'];
$operations  = $data['operations'] ?? array();

/**
 * Renderiza una tabla de resultados comerciales.
 *
 * @param string $title     Título de la sección.
 * @param array  $rows      Filas del reporte.
 * @param string $dim_label Encabezado de la columna de dimensión.
 */
function ea_render_results_table( $title, $rows, $dim_label, $resolver_type = '' ) {
	// RESULTS_DESIGN_V1 — barra proporcional por leads (solo presentación).
	$ea_res_max = 1;
	foreach ( (array) $rows as $ea_r ) {
		$ea_res_max = max( $ea_res_max, (int) ( $ea_r['leads'] ?? 0 ) );
	}
	?>
	<section class="ea-panel ea-res-card" style="margin-bottom:0;">
		<header class="ea-panel__head">
			<h2><?php echo esc_html( $title ); ?></h2>
		</header>
		<?php if ( empty( $rows ) ) : ?>
			<p style="padding:10px 16px;color:#646970;">
				<?php esc_html_e( 'Sin datos para el período seleccionado.', 'estandar-analytics' ); ?>
			</p>
		<?php else : ?>
			<div class="ea-table-scroll">
				<table class="ea-table" style="font-size:13px;">
					<thead>
						<tr>
							<th style="padding:6px 12px;"><?php echo esc_html( $dim_label ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Calificados', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reuniones', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reservas', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Ventas', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Cierre %', 'estandar-analytics' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) :
						$leads       = (int) ( $row['leads']       ?? 0 );
						$calificados = (int) ( $row['calificados'] ?? 0 );
						$reuniones   = (int) ( $row['reuniones']   ?? 0 );
						$reservas    = (int) ( $row['reservas']    ?? 0 );
						$ventas      = (int) ( $row['ventas']      ?? 0 );
						$cierre_pct  = $leads > 0 ? round( ( $ventas / $leads ) * 100, 1 ) : 0;
					?>
						<tr>
							<td style="padding:5px 12px;"><?php
								$dimension = (string) ( $row['dimension'] ?? '' );
								if ( $resolver_type !== '' && $resolver_type !== 'project' && class_exists( 'Estandar_Campaign_Label_Resolver' ) ) {
									$dimension = Estandar_Campaign_Label_Resolver::resolve( $resolver_type, $dimension );
								}
								if ( $resolver_type === 'project' ) {
									$dimension = resolve_project_display_name( $dimension, $dimension );
								}
								echo esc_html( $dimension );
							?>
								<span class="ea-res-bar"><i style="width:<?php echo esc_attr( (string) max( 2, round( $leads * 100 / $ea_res_max ) ) ); ?>%;"></i></span>
							</td>
							<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( $leads ) ); ?></td>
							<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( $calificados ) ); ?></td>
							<td style="text-align:right;padding:5px 12px;">
								<?php if ( $reuniones > 0 ) : ?>
									<strong><?php echo esc_html( number_format_i18n( $reuniones ) ); ?></strong>
								<?php else : ?>
									<span style="color:#c3c4c7;">0</span>
								<?php endif; ?>
							</td>
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
							<td style="text-align:right;padding:5px 12px;color:<?php echo $cierre_pct > 0 ? '#00a32a' : '#c3c4c7'; ?>;">
								<?php echo esc_html( number_format_i18n( $cierre_pct, 1 ) . ' %' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
	<?php
}
?>

<?php if ( ! $leads_active ) : ?>
	<div class="notice notice-warning" style="margin:0 0 12px;">
		<p>
			<?php esc_html_e( 'El plugin Estandar Leads no está activo. Los reportes de resultado comercial requieren que Estandar Leads esté instalado y con datos.', 'estandar-analytics' ); ?>
		</p>
	</div>
<?php else : ?>

	<?php
	EA_Admin_UI::render_project_trust_badge( 'exact' );
	$coverage  = $data['coverage'];
	$cov_pct   = (float) $coverage['pct'];
	$cov_status = $cov_pct >= 70 ? 'success' : ( $cov_pct >= 40 ? 'warning' : 'danger' );

	if ( function_exists( 'estandar_render_kpi_cards' ) ) {
		estandar_render_kpi_cards( array(
			array( 'label' => __( 'Leads atribuidos', 'estandar-analytics' ), 'value' => number_format_i18n( $totals['leads'] ),       'status' => 'info' ),
			array( 'label' => __( 'Calificados',      'estandar-analytics' ), 'value' => number_format_i18n( $totals['calificados'] ), 'status' => 'warning' ),
			array( 'label' => __( 'Reuniones',        'estandar-analytics' ), 'value' => number_format_i18n( $totals['reuniones'] ),   'status' => 'info' ),
			array( 'label' => __( 'Reservas',         'estandar-analytics' ), 'value' => number_format_i18n( $totals['reservas'] ?? 0 ), 'status' => 'warning' ),
			array( 'label' => __( 'Ventas',           'estandar-analytics' ), 'value' => number_format_i18n( $totals['ventas'] ),      'status' => 'success' ),
			array(
				'label'  => __( 'Cobertura atribución', 'estandar-analytics' ),
				'value'  => number_format_i18n( $cov_pct, 1 ) . ' %',
				'status' => $cov_status,
				'detail' => $coverage['attributed_leads'] . ' / ' . $coverage['total_leads'],
			),
		) );
	}
	?>

	<?php if ( $coverage['total_leads'] > 0 && $cov_pct < 50 ) : ?>
		<div class="notice notice-warning" style="margin:0 0 10px;">
			<p>
				<?php
				printf(
					/* translators: 1: pct 2: attributed 3: total */
					esc_html__( 'Cobertura de atribución baja: %1$s%% (%2$s de %3$s leads tienen sesión Analytics asociada). Los reportes muestran solo los leads atribuidos. Verifica que ea_sid se está enviando desde los formularios y chatbot.', 'estandar-analytics' ),
					esc_html( number_format_i18n( $cov_pct, 1 ) ),
					esc_html( number_format_i18n( $coverage['attributed_leads'] ) ),
					esc_html( number_format_i18n( $coverage['total_leads'] ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $totals['leads'] === 0 && $coverage['total_leads'] === 0 ) : ?>
		<div class="notice notice-info" style="margin:0 0 10px;">
			<p><?php esc_html_e( 'No se encontraron leads para este período.', 'estandar-analytics' ); ?></p>
		</div>
	<?php endif; ?>

	<style>
		/* RESULTS_DESIGN_V1 — solo presentación: grid, cards, zebra, barras. */
		.ea-res-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:12px;margin-bottom:12px;align-items:start;}
		.ea-res-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
		.ea-res-card .ea-panel__head{border-bottom:1px solid #f0f0f1;background:#f9fafb;}
		.ea-res-card .ea-panel__head h2{font-size:13px;margin:0;padding:9px 14px;}
		.ea-res-card .ea-table tbody tr:nth-child(odd){background:#fafafa;}
		.ea-res-card .ea-table td,.ea-res-card .ea-table th{border-bottom:1px solid #f0f0f1;}
		.ea-res-bar{display:block;height:4px;background:#f0f0f1;border-radius:2px;margin-top:3px;max-width:180px;overflow:hidden;}
		.ea-res-bar i{display:block;height:100%;border-radius:2px;background:var(--estandar-color-primary,#f95b1f);opacity:.7;}
		.ea-res-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#646970;margin:16px 0 8px;}
	</style>

	<p style="color:#646970;font-size:12px;margin:0 0 10px;">
		<?php esc_html_e( 'Modelo: first touch. Fuente = primer contacto del visitante que luego se convirtió en lead. La fila (no atribuido) incluye leads sin ea_sid registrado.', 'estandar-analytics' ); ?>
	</p>

	<p class="ea-res-eyebrow" style="margin-top:0;"><?php esc_html_e( 'Resultado comercial', 'estandar-analytics' ); ?></p>
	<div class="ea-res-grid">
	<?php
	// Proyecto primero — dimensión de mayor valor operativo.
	ea_render_results_table(
		__( 'Proyecto → Resultado', 'estandar-analytics' ),
		$data['by_project'],
		__( 'Proyecto', 'estandar-analytics' ),
		'project'
	);

	ea_render_results_table(
		__( 'Fuente → Resultado', 'estandar-analytics' ),
		$data['by_source'],
		__( 'Fuente', 'estandar-analytics' )
	);
	?>
	</div>

	<?php include __DIR__ . '/_operations.php'; ?>

	<p class="ea-res-eyebrow"><?php esc_html_e( 'Investigación', 'estandar-analytics' ); ?></p>
	<div class="ea-res-grid">
	<?php
	ea_render_results_table(
		__( 'Campaña → Resultado', 'estandar-analytics' ),
		$data['by_campaign'],
		__( 'Campaña', 'estandar-analytics' ),
		'campaigns'
	);

	ea_render_results_table(
		__( 'Keyword → Resultado', 'estandar-analytics' ),
		$data['by_keyword'],
		__( 'Keyword', 'estandar-analytics' )
	);
	?>
	</div>

	<section class="ea-panel" style="margin-bottom:10px;">
		<header class="ea-panel__head">
			<h2><?php esc_html_e( 'Comportamiento → Resultado', 'estandar-analytics' ); ?><span style="font-size:12px;font-weight:400;color:#646970;margin-left:8px;"><?php esc_html_e( '¿Qué acciones predicen una venta?', 'estandar-analytics' ); ?></span></h2>
		</header>
		<?php if ( empty( $behavior_results ) ) : ?>
			<p style="padding:10px 16px;color:#646970;">
				<?php esc_html_e( 'Sin datos aún. Los eventos aparecerán aquí una vez que los visitantes interactúen con chatbot, formularios o WhatsApp y alguno se convierta en lead.', 'estandar-analytics' ); ?>
			</p>
		<?php else : ?>
			<div class="ea-table-scroll">
				<table class="ea-table" style="font-size:13px;">
					<thead>
						<tr>
							<th style="padding:6px 12px;"><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
							<th style="padding:6px 12px;"><?php esc_html_e( 'Evento', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reuniones', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Reservas', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;"><?php esc_html_e( 'Ventas', 'estandar-analytics' ); ?></th>
							<th style="text-align:right;padding:6px 12px;" title="<?php esc_attr_e( 'Ventas / Leads', 'estandar-analytics' ); ?>">
								<?php esc_html_e( 'Cierre %', 'estandar-analytics' ); ?>
							</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $behavior_results as $row ) :
						$leads    = (int) ( $row['leads']    ?? 0 );
						$meeting  = (int) ( $row['meeting']  ?? 0 );
						$reservas = (int) ( $row['reservations'] ?? 0 );
						$conv     = (int) ( $row['converted'] ?? 0 );
						$pct      = (float) ( $row['conversion_pct'] ?? 0 );
					?>
						<tr>
							<td style="padding:5px 12px;"><?php echo esc_html( (string) ( $row['source'] ?? '' ) ); ?></td>
							<td style="padding:5px 12px;"><code style="font-size:11px;background:#f0f0f1;padding:1px 4px;border-radius:3px;"><?php echo esc_html( (string) ( $row['evento'] ?? '' ) ); ?></code></td>
							<td style="text-align:right;padding:5px 12px;"><?php echo esc_html( number_format_i18n( $leads ) ); ?></td>
							<td style="text-align:right;padding:5px 12px;">
								<?php if ( $meeting > 0 ) : ?>
									<strong><?php echo esc_html( number_format_i18n( $meeting ) ); ?></strong>
								<?php else : ?>
									<span style="color:#c3c4c7;">0</span>
								<?php endif; ?>
							</td>
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
							<td style="text-align:right;padding:5px 12px;color:<?php echo $pct > 0 ? '#00a32a' : '#c3c4c7'; ?>;">
								<?php echo esc_html( number_format_i18n( $pct, 1 ) . ' %' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid #f0f0f1;margin-top:4px;">
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_analytics_export&format=csv' ), 'estandar_analytics_export' ) ); ?>">📥 <?php esc_html_e( 'Exportar CSV', 'estandar-analytics' ); ?></a>
		<span style="font-size:12px;color:#646970;"><?php esc_html_e( 'TODO: Copiar Contexto IA', 'estandar-analytics' ); ?></span>
	</div>

<?php endif; ?>
