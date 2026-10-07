<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$snapshot = Estandar_KickOff_Site_Audit::get_last_snapshot();

/**
 * E2.1 — helpers de presentación (solo UX, no tocan snapshot ni schema).
 */
if ( ! function_exists( 'estandar_kickoff_site_audit_render_value' ) ) {
	/** Renderiza cualquier valor del snapshot como HTML legible (recursivo). */
	function estandar_kickoff_site_audit_render_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '<span style="color:#008a20;">✓</span>' : '<span style="color:#999;">—</span>';
		}
		if ( null === $value || '' === $value ) {
			return '<span style="color:#999;">—</span>';
		}
		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}
		if ( ! is_array( $value ) || empty( $value ) ) {
			return '<span style="color:#999;">—</span>';
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		// Lista de escalares → texto separado por comas.
		if ( $is_list && ! is_array( reset( $value ) ) ) {
			return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
		}

		// Lista de filas homogéneas → tabla con encabezados.
		if ( $is_list && is_array( reset( $value ) ) ) {
			$headers = array_keys( (array) reset( $value ) );
			$html    = '<table class="widefat striped" style="margin:4px 0;"><thead><tr>';
			foreach ( $headers as $h ) {
				$html .= '<th>' . esc_html( $h ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $value as $row ) {
				$html .= '<tr>';
				foreach ( $headers as $h ) {
					$html .= '<td>' . estandar_kickoff_site_audit_render_value( isset( $row[ $h ] ) ? $row[ $h ] : null ) . '</td>';
				}
				$html .= '</tr>';
			}
			return $html . '</tbody></table>';
		}

		// Array asociativo → tabla clave/valor anidada.
		$html = '<table class="widefat striped" style="margin:4px 0;"><tbody>';
		foreach ( $value as $k => $v ) {
			$html .= '<tr><td style="width:220px;"><code>' . esc_html( $k ) . '</code></td><td>' . estandar_kickoff_site_audit_render_value( $v ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}

if ( ! function_exists( 'estandar_kickoff_site_audit_grouped_risks' ) ) {
	/** Agrupa riesgos de todas las secciones: riesgos (P0/P1) · advertencias (P2) · información (P3), orden P0→P3. */
	function estandar_kickoff_site_audit_grouped_risks( array $sections ) {
		$groups = [ 'riesgos' => [], 'advertencias' => [], 'informacion' => [] ];
		foreach ( $sections as $domain => $section ) {
			foreach ( (array) ( $section['risks'] ?? [] ) as $risk ) {
				$risk['domain'] = $domain;
				$sev            = $risk['severity'] ?? '';
				if ( 'P0' === $sev || 'P1' === $sev ) {
					$groups['riesgos'][] = $risk;
				} elseif ( 'P2' === $sev ) {
					$groups['advertencias'][] = $risk;
				} else {
					$groups['informacion'][] = $risk;
				}
			}
		}
		foreach ( $groups as &$list ) {
			usort( $list, function ( $a, $b ) {
				return strcmp( $a['severity'] ?? 'P9', $b['severity'] ?? 'P9' );
			} );
		}
		return $groups;
	}
}
?>
<div class="wrap">
	<h2>🔍 <?php esc_html_e( 'Site Audit — Estado Inicial', 'estandar-kickoff' ); ?></h2>

	<?php if ( ! empty( $_GET['audit_done'] ) ) : ?>
		<div class="notice notice-success"><p><?php esc_html_e( 'Snapshot generado.', 'estandar-kickoff' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="estandar_kickoff_site_audit_run">
		<?php wp_nonce_field( 'estandar_kickoff_site_audit_run' ); ?>
		<?php submit_button( __( 'Generar snapshot', 'estandar-kickoff' ), 'primary', 'submit', false ); ?>
	</form>

	<?php if ( ! $snapshot ) : ?>
		<p><em><?php esc_html_e( 'Sin snapshot previo. Genera el primero.', 'estandar-kickoff' ); ?></em></p>
	<?php else : ?>
		<?php
		$groups      = estandar_kickoff_site_audit_grouped_risks( (array) $snapshot['sections'] );
		$sev_colors  = [ 'P0' => '#d63638', 'P1' => '#d63638', 'P2' => '#dba617', 'P3' => '#2271b1' ];
		$group_meta  = [
			'riesgos'      => [ 'label' => __( 'Riesgos', 'estandar-kickoff' ),      'icon' => '🔴' ],
			'advertencias' => [ 'label' => __( 'Advertencias', 'estandar-kickoff' ), 'icon' => '🟡' ],
			'informacion'  => [ 'label' => __( 'Información', 'estandar-kickoff' ),  'icon' => 'ℹ️' ],
		];
		?>

		<!-- Resumen Ejecutivo -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin:12px 0;max-width:900px;">
			<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Resumen Ejecutivo', 'estandar-kickoff' ); ?></h3>
			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Último snapshot:', 'estandar-kickoff' ); ?></strong>
				<?php echo esc_html( $snapshot['generated_at'] ); ?> ·
				<code><?php echo esc_html( $snapshot['site_url'] ?? '' ); ?></code>
			</p>
			<p style="margin:0;">
				<?php foreach ( $snapshot['risk_summary'] as $sev => $n ) : ?>
					<span style="display:inline-block;margin-right:8px;padding:2px 10px;border-radius:12px;background:<?php echo 0 === (int) $n ? '#f0f0f1' : esc_attr( ( $sev_colors[ $sev ] ?? '#999' ) . '22' ); ?>;">
						<strong style="color:<?php echo esc_attr( $sev_colors[ $sev ] ?? '#999' ); ?>;"><?php echo esc_html( $sev ); ?></strong>
						<?php echo esc_html( (string) $n ); ?>
					</span>
				<?php endforeach; ?>
				· <?php echo esc_html( count( $groups['riesgos'] ) ); ?> <?php esc_html_e( 'riesgos', 'estandar-kickoff' ); ?>
				· <?php echo esc_html( count( $groups['advertencias'] ) ); ?> <?php esc_html_e( 'advertencias', 'estandar-kickoff' ); ?>
				· <?php echo esc_html( count( $groups['informacion'] ) ); ?> <?php esc_html_e( 'informativos', 'estandar-kickoff' ); ?>
			</p>
		</div>

		<!-- Riesgos primero (P0→P3), separados por grupo -->
		<?php foreach ( $group_meta as $group_key => $meta ) : ?>
			<?php if ( ! empty( $groups[ $group_key ] ) ) : ?>
				<h3><?php echo esc_html( $meta['icon'] . ' ' . $meta['label'] ); ?> (<?php echo esc_html( count( $groups[ $group_key ] ) ); ?>)</h3>
				<table class="widefat striped" style="max-width:900px;margin-bottom:1em;">
					<thead>
						<tr>
							<th style="width:50px;"><?php esc_html_e( 'Sev.', 'estandar-kickoff' ); ?></th>
							<th style="width:130px;"><?php esc_html_e( 'Sección', 'estandar-kickoff' ); ?></th>
							<th><?php esc_html_e( 'Detalle', 'estandar-kickoff' ); ?></th>
							<th><?php esc_html_e( 'Evidencia', 'estandar-kickoff' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $groups[ $group_key ] as $risk ) : ?>
						<tr>
							<td><strong style="color:<?php echo esc_attr( $sev_colors[ $risk['severity'] ] ?? '#999' ); ?>;"><?php echo esc_html( $risk['severity'] ); ?></strong></td>
							<td><code><?php echo esc_html( $risk['domain'] ); ?></code></td>
							<td><?php echo esc_html( $risk['detail'] ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( $risk['evidence'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( empty( $groups['riesgos'] ) && empty( $groups['advertencias'] ) && empty( $groups['informacion'] ) ) : ?>
			<p><em><?php esc_html_e( 'Sin riesgos detectados en el último snapshot.', 'estandar-kickoff' ); ?></em></p>
		<?php endif; ?>

		<!-- Detalle técnico por sección (colapsado, genérico) -->
		<h3><?php esc_html_e( 'Detalle técnico', 'estandar-kickoff' ); ?></h3>
		<?php foreach ( $snapshot['sections'] as $domain => $section ) : ?>
			<details style="max-width:900px;margin-bottom:8px;background:#fff;border:1px solid #c3c4c7;padding:8px 12px;">
				<summary style="cursor:pointer;font-weight:600;">
					<?php echo esc_html( $domain ); ?>
					<?php if ( ! empty( $section['risks'] ) ) : ?>
						<span style="color:#d63638;">(<?php echo esc_html( count( $section['risks'] ) ); ?>)</span>
					<?php endif; ?>
				</summary>
				<table class="widefat striped" style="margin-top:8px;">
					<tbody>
					<?php foreach ( (array) ( $section['data'] ?? [] ) as $key => $value ) : ?>
						<tr>
							<td style="width:220px;vertical-align:top;"><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo estandar_kickoff_site_audit_render_value( $value ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construido con esc_html interno. ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
