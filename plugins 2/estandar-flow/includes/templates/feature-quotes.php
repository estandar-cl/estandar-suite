<?php
/**
 * Estandar Suite — Flow · Cotizaciones (sub-tab con sub-sub-tabs).
 * Cotizaciones de la instalación legada (v1, es_quotes) + cotizaciones v2 + settings.
 * Sub-sub-tabs: Resumen · Cotizaciones · Documento · Finanzas · Sala de ventas · Autoridad · Email · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$quotes    = isset( $feature_view['quotes'] ) ? $feature_view['quotes'] : array();
$v2_quotes = isset( $feature_view['v2_quotes'] ) ? (int) $feature_view['v2_quotes'] : 0;
$ssubs = array(
	'resumen'     => 'Resumen',
	'cotizaciones' => 'Cotizaciones',
	'documento'   => 'Documento',
	'finanzas'    => 'Finanzas',
	'sala_ventas' => 'Sala de ventas',
	'autoridad'   => 'Autoridad',
	'email'       => 'Email',
	'settings'    => 'Settings',
);
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'quotes', '<strong>¿Para qué sirve?</strong> Muestra las <em>cotizaciones</em> de la instalación anterior (v1): UUID, proyecto, lead, perfil y estado. <br><br>Es el <em>historial de propuestas</em>: ves qué cotizaciones se generaron y en qué estado están. Los sub-sub-tabs <strong>Documento, Finanzas, Sala de ventas, Autoridad y Email</strong> rescatan la configuración de <code>estandar-quotes</code> (v1) para que las nuevas cotizaciones v2 respeten los mismos datos.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-flow', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['quotes_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de cotización guardada.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
<table class="widefat striped" style="max-width:520px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Cotizaciones legadas</strong></td><td><?php echo count( $quotes ); ?></td></tr>
		<tr><td><strong>Cotizaciones v2</strong></td><td><?php echo (int) $v2_quotes; ?></td></tr>
	</tbody>
</table>

<?php elseif ( $ss === 'cotizaciones' ) : ?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>UUID</th><th>Número</th><th>Proyecto</th><th>Lead</th><th>Perfil</th><th>Estado</th><th>Revisión</th><th>Actualizada</th></tr></thead>
	<tbody>
	<?php foreach ( $quotes as $q ) : ?>
		<tr>
			<td><?php echo (int) $q['id']; ?></td>
			<td><code><?php echo esc_html( $q['quote_uuid'] ); ?></code></td>
			<td><?php echo esc_html( $q['quote_number'] ); ?></td>
			<td><?php echo esc_html( $q['project_slug'] ); ?></td>
			<td><?php echo $q['lead_id'] ? (int) $q['lead_id'] : '—'; ?></td>
			<td><?php echo esc_html( $q['profile_key'] ); ?></td>
			<td><?php echo esc_html( $q['status'] ); ?></td>
			<td><?php echo (int) $q['current_revision_no']; ?></td>
			<td><?php echo esc_html( $q['updated_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $quotes ) ) : ?><tr><td colspan="9">Sin cotizaciones legadas.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php elseif ( in_array( $ss, array( 'documento', 'finanzas', 'sala_ventas', 'autoridad', 'email' ), true ) ) : ?>
<?php $qgroups = \Estandar\Flow\Admin::quotes_settings_groups(); ?>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_flow_save_quotes_settings', 'estandar_suite_flow_quotes_settings', $qgroups[ $ss ], '', array( 'group' => $ss ) ); ?>

<?php else : ?>
<h4>Settings del módulo</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_flow_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
