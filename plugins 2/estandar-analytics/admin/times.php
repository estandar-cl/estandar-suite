<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$filters = is_array( $filters ?? null ) ? $filters : array();
$options = is_array( $options ?? null ) ? $options : array();
$data    = is_array( $data ?? null ) ? $data : array();

$conversion_source = (string) ( $data['conversion_source'] ?? 'form_submit' );
$conversion_label  = $conversion_source === 'lead_created'
	? __( 'Leads creados', 'estandar-analytics' )
	: __( 'Form submits', 'estandar-analytics' );

$render_rank = static function( string $title, array $rows ): void {
	if ( empty( $rows ) ) {
		echo '<section class="estandar-section"><h2>' . esc_html( $title ) . '</h2>';
		EA_Admin_UI::render_empty( __( 'Sin datos en este rango.', 'estandar-analytics' ) );
		echo '</section>';
		return;
	}

	$table_rows = array();
	foreach ( $rows as $row ) {
		$table_rows[] = array(
			esc_html( (string) ( $row['label'] ?? '' ) ),
			'<strong>' . esc_html( number_format_i18n( (int) ( $row['total'] ?? 0 ) ) ) . '</strong>',
		);
	}

	echo '<section class="estandar-section"><h2>' . esc_html( $title ) . '</h2>';
	if ( function_exists( 'estandar_render_data_table' ) ) {
		estandar_render_data_table(
			array( __( 'Franja', 'estandar-analytics' ), __( 'Total', 'estandar-analytics' ) ),
			$table_rows
		);
	}
	echo '</section>';
};
?>

<section class="ea-panel">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Horarios', 'estandar-analytics' ); ?></h2>
	</header>
	<div style="padding:8px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
		<?php EA_Admin_UI::render_range_shortcuts( $filters, 'ea-times' ); ?>
		<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">
			<input type="hidden" name="page" value="ea-times">
			<input type="date" name="ea_from" value="<?php echo esc_attr( (string) ( $filters['from'] ?? '' ) ); ?>">
			<input type="date" name="ea_to" value="<?php echo esc_attr( (string) ( $filters['to'] ?? '' ) ); ?>">
			<select name="ea_source">
				<option value=""><?php esc_html_e( 'Todas las fuentes', 'estandar-analytics' ); ?></option>
				<?php foreach ( (array) ( $options['sources'] ?? array() ) as $source ) : ?>
					<option value="<?php echo esc_attr( (string) $source ); ?>" <?php selected( (string) ( $filters['source'] ?? '' ), (string) $source ); ?>>
						<?php echo esc_html( (string) $source ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="ea_project">
				<option value=""><?php esc_html_e( 'Todos los proyectos', 'estandar-analytics' ); ?></option>
				<?php foreach ( (array) ( $options['projects'] ?? array() ) as $project ) : ?>
					<option value="<?php echo esc_attr( (string) $project ); ?>" <?php selected( (string) ( $filters['project'] ?? '' ), (string) $project ); ?>>
						<?php echo esc_html( function_exists( 'resolve_project_display_name' ) ? resolve_project_display_name( (string) $project, (string) $project ) : (string) $project ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Filtrar', 'estandar-analytics' ); ?></button>
		</form>
	</div>
</section>

<?php
// TIMES_READABILITY_V1 — lectura en lenguaje simple ANTES de las tablas.
// Deriva del MISMO dataset que las tablas de abajo (regla UX: sin simulación).
$t_lead_slots = (array) ( $data[ $conversion_source ]['slots'] ?? array() );
$t_lead_days  = (array) ( $data[ $conversion_source ]['days'] ?? array() );
$t_comparison = (array) ( $data['comparison'] ?? array() );
$t_top_hour   = $t_lead_slots[0] ?? null;
$t_top_day    = $t_lead_days[0] ?? null;
$t_best_conv  = null;
foreach ( $t_comparison as $t_row ) {
	// Mejor tasa de conversión con muestra mínima (evita 1/1 = 100%).
	if ( (int) ( $t_row['traffic'] ?? 0 ) >= 10 && ( null === $t_best_conv || (float) ( $t_row['rate'] ?? 0 ) > (float) ( $t_best_conv['rate'] ?? 0 ) ) ) {
		$t_best_conv = $t_row;
	}
}
?>
<?php if ( $t_top_hour || $t_top_day || $t_best_conv ) : ?>
<section class="ea-panel" style="margin-bottom:10px;border-left:4px solid var(--estandar-color-primary,#f95b1f);">
	<div style="padding:12px 16px;">
		<h3 style="margin:0 0 8px;font-size:13px;"><?php esc_html_e( 'Lectura rápida — para qué sirve esta sección', 'estandar-analytics' ); ?></h3>
		<ul style="margin:0;padding-left:18px;font-size:13px;color:#374151;line-height:1.7;">
			<?php if ( $t_top_hour ) : ?>
			<li><?php echo esc_html( sprintf( __( 'La franja con más %1$s es %2$s (%3$s en el período). Es la ventana donde más importa que un operador esté disponible para responder de inmediato.', 'estandar-analytics' ), strtolower( $conversion_label ), (string) ( $t_top_hour['label'] ?? '' ), number_format_i18n( (int) ( $t_top_hour['total'] ?? 0 ) ) ) ); ?></li>
			<?php endif; ?>
			<?php if ( $t_top_day ) : ?>
			<li><?php echo esc_html( sprintf( __( 'El día que más %1$s genera es %2$s — útil para planificar turnos y presupuesto diario de campañas.', 'estandar-analytics' ), strtolower( $conversion_label ), (string) ( $t_top_day['label'] ?? '' ) ) ); ?></li>
			<?php endif; ?>
			<?php if ( $t_best_conv ) : ?>
			<li><?php echo esc_html( sprintf( __( 'La hora que mejor convierte visitas en leads es %1$s (%2$s%% con %3$s visitas): si programas anuncios por franja, prioriza esa hora.', 'estandar-analytics' ), (string) ( $t_best_conv['hour'] ?? '' ), number_format_i18n( (float) ( $t_best_conv['rate'] ?? 0 ), 1 ), number_format_i18n( (int) ( $t_best_conv['traffic'] ?? 0 ) ) ) ); ?></li>
			<?php endif; ?>
		</ul>
		<p style="margin:8px 0 0;font-size:11px;color:#787c82;"><?php esc_html_e( 'Las tablas de abajo muestran el detalle: tráfico (visitas) vs conversión (leads) por hora y por día. Hora con mucho tráfico y pocos leads = revisar la página/campaña de esa franja; hora con pocos leads pero alta conversión = candidata a más presupuesto.', 'estandar-analytics' ); ?></p>
	</div>
</section>
<?php endif; ?>

<div class="ea-panels-grid">
	<?php $render_rank( __( 'Top 10 horas de tráfico', 'estandar-analytics' ), (array) ( $data['traffic']['slots'] ?? array() ) ); ?>
	<?php $render_rank( __( 'Top 10 días de tráfico', 'estandar-analytics' ), (array) ( $data['traffic']['days'] ?? array() ) ); ?>
</div>

<div class="ea-panels-grid">
	<?php $render_rank( sprintf( __( 'Top 10 horas de %s', 'estandar-analytics' ), $conversion_label ), (array) ( $data[ $conversion_source ]['slots'] ?? array() ) ); ?>
	<?php $render_rank( sprintf( __( 'Top 10 días de %s', 'estandar-analytics' ), $conversion_label ), (array) ( $data[ $conversion_source ]['days'] ?? array() ) ); ?>
</div>

<section class="ea-panel">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Diferencia tráfico vs leads', 'estandar-analytics' ); ?></h2>
	</header>
	<?php if ( empty( $data['comparison'] ) ) : ?>
		<?php EA_Admin_UI::render_empty( __( 'Sin datos suficientes para comparar.', 'estandar-analytics' ) ); ?>
	<?php else : ?>
		<div class="ea-table-scroll">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hora', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Tráfico', 'estandar-analytics' ); ?></th>
						<th><?php echo esc_html( $conversion_label ); ?></th>
						<th><?php esc_html_e( 'Conv.', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $data['comparison'] as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) ( $row['hour'] ?? '' ) ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $row['traffic'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $row['leads'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) ( $row['rate'] ?? 0 ), 1 ) ); ?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
