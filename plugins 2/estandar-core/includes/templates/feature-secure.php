<?php
/**
 * Estandar Suite — Core · Secure Share (sub-tab con sub-sub-tabs).
 * Entregas seguras de la instalación legada (v1) + settings del puente.
 * Sub-sub-tabs: Entregas · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$feature_data = isset( $feature_data ) ? $feature_data : array();
$ssubs = array( 'entregas' => 'Entregas', 'settings' => 'Settings' );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'entregas' );
?>
<?php echo \Estandar\Core\Admin::help_html( 'secure', '<strong>¿Para qué sirve?</strong> Muestra las <em>entregas seguras</em> de la instalación anterior (v1): secretos de un solo uso cifrados, con token, estado (disponible/usado/revocado) y caducidad. <br><br>Es el <em>registro de la caja fuerte</em>: cada entrega se abre una sola vez y se elimina. La suite v2 ya gestiona esto en el sub-tab <strong>Secretos</strong>; esta vista informa del ecosistema legado.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>

<?php if ( $ss === 'entregas' ) : ?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Token</th><th>Estado</th><th>Destinatario</th><th>Creada</th><th>Expira</th><th>Consumida</th></tr></thead>
	<tbody>
	<?php foreach ( $feature_data as $d ) : ?>
		<tr>
			<td><?php echo (int) $d['id']; ?></td>
			<td><code><?php echo esc_html( $d['token_public'] ); ?></code></td>
			<td><?php echo esc_html( $d['status'] ); ?></td>
			<td><?php echo esc_html( $d['requester_email'] ); ?></td>
			<td><?php echo esc_html( $d['created_at'] ); ?></td>
			<td><?php echo esc_html( $d['expires_at'] ); ?></td>
			<td><?php echo esc_html( $d['consumed_at'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( empty( $feature_data ) ) : ?><tr><td colspan="7">Sin entregas seguras legadas.</td></tr><?php endif; ?>
	</tbody>
</table>

<?php else : ?>
<h4>Settings del puente</h4>
<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_core_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
