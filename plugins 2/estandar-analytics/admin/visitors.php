<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	?>

	<section class="ea-panel">
		<header class="ea-panel__head">
			<h2><?php esc_html_e( 'Sesiones recientes', 'estandar-analytics' ); ?></h2>
			<button type="button" class="ea-help" data-ea-tip="<?php echo esc_attr( EA_Admin_UI::metric_help( 'sessions' ) ); ?>" aria-label="<?php esc_attr_e( 'Ayuda', 'estandar-analytics' ); ?>">?</button>
		</header>
		<?php if ( empty( $sessions ) ) : ?>
			<?php EA_Admin_UI::render_empty( __( 'No hay sesiones en este rango de fechas.', 'estandar-analytics' ) ); ?>
		<?php else : ?>
			<div class="ea-table-scroll">
				<table class="ea-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sesión', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Inicio', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Fuente', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Landing', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Duración', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Páginas', 'estandar-analytics' ); ?></th>
							<th><?php esc_html_e( 'Dispositivo', 'estandar-analytics' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sessions as $row ) : ?>
						<tr>
							<td><code class="ea-code"><?php echo esc_html( substr( (string) $row['session_id'], 0, 12 ) ); ?>…</code></td>
							<td><?php echo esc_html( (string) $row['first_seen'] ); ?></td>
							<td><span class="ea-tag"><?php echo esc_html( (string) $row['source'] ); ?></span></td>
							<td><?php echo esc_html( wp_html_excerpt( (string) $row['landing_page'], 56, '…' ) ); ?></td>
							<td><?php echo esc_html( EA_Admin_UI::format_duration( (int) $row['duration'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['page_count'] ); ?></td>
							<td><?php echo esc_html( (string) $row['device_type'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<p class="ea-export-row">
		<a class="ea-btn ea-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ea_export&type=sessions' ), 'ea_export' ) ); ?>">
			<?php esc_html_e( 'Exportar sesiones CSV', 'estandar-analytics' ); ?>
		</a>
	</p>
