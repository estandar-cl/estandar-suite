<?php
/**
 * Listado admin "Pedidos con ubicación" y export CSV.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Admin_Orders {

	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
	}

	/**
	 * Exportación CSV con nonce.
	 */
	public function maybe_export_csv() {
		if ( ! isset( $_GET['estandar_dl_export'] ) || 'csv' !== $_GET['estandar_dl_export'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'estandar-delivery-locator-lite' ) );
		}

		check_admin_referer( 'estandar_dl_export_csv' );

		$rows = Estandar_Delivery_Locator_Database::get_all( 2000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=estandar-delivery-locator-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

		fputcsv(
			$out,
			array(
				'ID',
				'Nombre',
				'Teléfono',
				'Dirección',
				'Latitud',
				'Longitud',
				'Fecha',
				'Correo enviado',
				'Google Maps',
			)
		);

		foreach ( $rows as $r ) {
			$gmaps = 'https://www.google.com/maps?q=' . rawurlencode( $r['lat'] . ',' . $r['lng'] );
			fputcsv(
				$out,
				array(
					$r['id'],
					$r['name'],
					$r['phone'],
					$r['address'],
					$r['lat'],
					$r['lng'],
					$r['created_at'],
					! empty( $r['email_sent'] ?? 0 ) ? '1' : '0',
					$gmaps,
				)
			);
		}

		fclose( $out );
		exit;
	}

	public static function render_orders_table( $export_base_url = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rows = Estandar_Delivery_Locator_Database::get_all( 500 );
		$export_base_url = '' !== (string) $export_base_url ? (string) $export_base_url : admin_url( 'admin.php?page=estandar-dl-orders' );
		$export_url = wp_nonce_url(
			add_query_arg( 'estandar_dl_export', 'csv', $export_base_url ),
			'estandar_dl_export_csv'
		);
		?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Pedidos con ubicación', 'estandar-delivery-locator-lite' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Exportar CSV', 'estandar-delivery-locator-lite' ); ?></a>
			<hr class="wp-header-end" />

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'ID', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fecha', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nombre', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Teléfono', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Dirección', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Coordenadas', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Correo', 'estandar-delivery-locator-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Mapa', 'estandar-delivery-locator-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'Aún no hay pedidos registrados.', 'estandar-delivery-locator-lite' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<?php
							$maps = 'https://www.google.com/maps?q=' . rawurlencode( $r['lat'] . ',' . $r['lng'] );
							?>
							<tr>
								<td><?php echo esc_html( $r['id'] ); ?></td>
								<td><?php echo esc_html( $r['created_at'] ); ?></td>
								<td><?php echo esc_html( $r['name'] ); ?></td>
								<td><?php echo esc_html( $r['phone'] ); ?></td>
								<td><?php echo esc_html( wp_trim_words( $r['address'], 20 ) ); ?></td>
								<td><?php echo esc_html( $r['lat'] . ', ' . $r['lng'] ); ?></td>
								<td><?php echo ! empty( $r['email_sent'] ?? 0 ) ? esc_html__( 'Sí', 'estandar-delivery-locator-lite' ) : esc_html__( 'No', 'estandar-delivery-locator-lite' ); ?></td>
								<td><a href="<?php echo esc_url( $maps ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir en Google Maps', 'estandar-delivery-locator-lite' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		<?php
	}
}
