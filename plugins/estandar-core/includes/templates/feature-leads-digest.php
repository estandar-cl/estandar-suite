<?php
/**
 * Estandar Suite — Core · Configuración: RESUMEN PERIÓDICO DE LEADS (LEAD_DIGEST_V1).
 * Sub-pestaña "Resumen de leads": opt-in por proyecto + email adicional en copia.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$LD  = 'Estandar\Core\Leads\LeadsDigest';
$has = class_exists( $LD );
$settings = $has ? $LD::settings() : array();

$projects = array();
if ( $has ) {
	foreach ( $LD::projects_with_leads() as $s ) {
		$projects[ $s ] = true;
	}
}
foreach ( array_keys( $settings ) as $s ) {
	$projects[ $s ] = true;
}
if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
	foreach ( \Estandar\Core\Leads\ProjectRegistry::registry() as $p ) {
		$slug = (string) ( $p['project_slug'] ?? '' );
		if ( $slug !== '' ) {
			$projects[ $slug ] = true;
		}
	}
}
$projects = array_keys( $projects );
sort( $projects );

$global = function_exists( 'get_option' ) ? (string) get_option( 'estandar_suite_test_notify_email', '' ) : '';
?>
		<?php if ( isset( $_GET['digest_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuración del resumen guardada.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['digest_tested'] ) ) : ?><div class="notice notice-info is-dismissible"><p>Prueba de envío solicitada (revisa la entrega en Correo → Entregas).</p></div><?php endif; ?>

		<p class="description" style="max-width:760px;">
			Correo <strong>diario por proyecto</strong> con los leads del <strong>día anterior</strong>. Los
			<strong>lunes</strong> suma la semana recién acabada; el <strong>día 1</strong> suma el mes anterior.
			Cada lead se marca <span style="color:#059669;">✔ contactado</span> /
			<span style="color:#d97706;">○ pendiente</span> e indica cuántas veces dejó sus datos.
			Se envía a los <strong>operadores del proyecto</strong> (+ el email en copia de abajo). Sin leads, no envía.
		</p>
		<?php if ( $global !== '' ) : ?>
			<div class="notice notice-warning inline" style="max-width:760px;"><p>Modo pruebas activo: <strong>todo</strong> el correo saliente se redirige a <code><?php echo esc_html( $global ); ?></code> (Correo → Configuración). Desactívalo para envíos reales.</p></div>
		<?php endif; ?>

		<?php if ( ! $has ) : ?>
			<div class="notice notice-error inline"><p>La clase <code>LeadsDigest</code> no está cargada.</p></div>
		<?php elseif ( empty( $projects ) ) : ?>
			<p class="description">No hay proyectos con leads todavía.</p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_core_leads_digest_save' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_leads_digest_save">
			<table class="widefat striped" style="max-width:860px;">
				<thead>
					<tr>
						<th style="width:160px;">Proyecto</th>
						<th style="width:120px;">Activo</th>
						<th>Email adicional en copia (opcional)</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $projects as $slug ) :
					$cfg = $settings[ $slug ] ?? array( 'enabled' => 0, 'extra_cc' => '' );
					$lbl = $has ? $LD::project_label( $slug ) : $slug;
				?>
					<tr>
						<td><strong><?php echo esc_html( $lbl ); ?></strong><br><code style="font-size:11px;color:#646970;"><?php echo esc_html( $slug ); ?></code></td>
						<td><label><input type="checkbox" name="digest[<?php echo esc_attr( $slug ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>> Enviar</label></td>
						<td><input type="text" class="regular-text" name="digest[<?php echo esc_attr( $slug ); ?>][extra_cc]" value="<?php echo esc_attr( (string) ( $cfg['extra_cc'] ?? '' ) ); ?>" placeholder="jefe@empresa.cl, otro@empresa.cl"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Guardar resumen de leads' ); ?>
		</form>

		<h4>Enviar una prueba</h4>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;max-width:860px;">
			<?php wp_nonce_field( 'estandar_suite_core_leads_digest_test' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_leads_digest_test">
			<select name="test_project">
				<?php foreach ( $projects as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $has ? $LD::project_label( $slug ) : $slug ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="email" name="test_to" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>" placeholder="tu@correo.cl">
			<?php submit_button( 'Enviar prueba', 'secondary', 'test_digest', false ); ?>
			<span class="description">La prueba usa las ventanas reales (ayer/semana/mes) y no altera el envío diario.</span>
		</form>
		<?php endif; ?>
