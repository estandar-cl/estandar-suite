<?php
/**
 * Estandar Suite — Insights · Scanner (sub-tab con sub-sub-tabs).
 * Escaneos técnicos de la instalación legada (estandar_scans).
 * Sub-sub-tabs: Resumen · Escaneos · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$scans      = isset( $feature_view['scans'] ) ? $feature_view['scans'] : array();
$scan_queue = isset( $feature_view['scan_queue'] ) ? (int) $feature_view['scan_queue'] : 0;
$ssubs = array( 'resumen' => 'Resumen', 'escaneos' => 'Escaneos', 'settings' => 'Settings' );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'scanner', '<strong>¿Para qué sirve?</strong> Muestra los <em>escaneos técnicos</em> de la instalación anterior (v1): qué sitios se escanearon, cuándo y el score obtenido. <br><br>Es el <em>chequeo médico del sitio</em>: un diagnóstico técnico con puntaje. Si no hay escaneos, el módulo está en espera. <em>Ejemplo:</em> un escaneo con score 85 sobre <code>ejemplo.cl</code> te dice que el sitio está sano.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-insights', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Escaneos</strong></td><td><?php echo count( $scans ); ?></td></tr>
		<tr><td><strong>Cola de escaneos</strong></td><td><?php echo (int) $scan_queue; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'escaneos' ) : ?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Sitio</th><th>Fecha</th><th>Score</th><th>Resumen</th></tr></thead>
	<tbody>
	<?php foreach ( $scans as $s ) : ?>
		<tr>
			<td><?php echo (int) $s['id']; ?></td>
			<td><code><?php echo esc_html( $s['site_url'] ); ?></code></td>
			<td><?php echo esc_html( $s['scan_date'] ); ?></td>
			<td><strong><?php echo (float) $s['score_total']; ?></strong></td>
			<td><?php echo esc_html( wp_trim_words( (string) $s['summary_text'], 20 ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $scans ) ) : ?><tr><td colspan="5">Sin escaneos legados.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
