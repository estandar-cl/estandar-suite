<?php
/**
 * Estandar Suite — Insights · SEO (sub-tab con sub-sub-tabs).
 * Landings, métricas y queries de la instalación legada (es_seo_*).
 * Sub-sub-tabs: Resumen · Landings · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$landings = isset( $feature_view['landings'] ) ? $feature_view['landings'] : array();
$metrics  = isset( $feature_view['metrics'] ) ? (int) $feature_view['metrics'] : 0;
$queries  = isset( $feature_view['queries'] ) ? (int) $feature_view['queries'] : 0;
$ssubs = array( 'resumen' => 'Resumen', 'landings' => 'Landings', 'settings' => 'Settings' );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'seo', '<strong>¿Para qué sirve?</strong> Muestra las <em>landings orgánicas</em> registradas por la instalación anterior (v1) y cuántas métricas de búsqueda y queries se sincronizaron. <br><br>Es el <em>mapa del SEO</em>: ves qué páginas se monitorean para posicionamiento orgánico. La sincronización la hace el adaptador del plugin legado; aquí se informa el estado.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-insights', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:560px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Landings monitoreadas</strong></td><td><?php echo count( $landings ); ?></td></tr>
		<tr><td><strong>Métricas sincronizadas</strong></td><td><?php echo (int) $metrics; ?></td></tr>
		<tr><td><strong>Queries registradas</strong></td><td><?php echo (int) $queries; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'landings' ) : ?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>URL</th><th>Última sincronización</th><th>Creada</th></tr></thead>
	<tbody>
	<?php foreach ( $landings as $l ) : ?>
		<tr>
			<td><?php echo (int) $l['id']; ?></td>
			<td><code><?php echo esc_html( $l['normalized_url'] ? $l['normalized_url'] : $l['url'] ); ?></code></td>
			<td><?php echo esc_html( $l['last_sync'] ); ?></td>
			<td><?php echo esc_html( $l['created_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $landings ) ) : ?><tr><td colspan="4">Sin landings legadas.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
