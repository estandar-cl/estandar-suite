<?php
/**
 * Estandar Suite — Core · Ajustes básicos: IDENTIDAD DE LA MARCA.
 * Fallback global de los correos de la Suite (color + logo). Si no se define,
 * se usa el logo del sitio (Custom Logo) y el color por defecto #1a73e8.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$id_logo  = (string) get_option( 'estandar_suite_core_default_logo', '' );
$id_color = (string) get_option( 'estandar_suite_core_brand_color', '' );
if ( isset( $_GET['identity_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Identidad de la marca guardada.</p></div><?php endif;
?>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:820px;margin:12px 0;">
	<h3 style="margin:0 0 4px;">Identidad de la marca en los correos</h3>
	<p class="description" style="margin:0 0 12px;">Fallback global de la Suite: se usa en los correos cuando no hay un branding más específico (proyecto, formulario, leads). Vacío = logo del sitio (Custom Logo de WordPress) y color por defecto.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'estandar_suite_core_save_identity' ); ?>
		<input type="hidden" name="action" value="estandar_suite_core_save_identity">
		<table class="form-table" role="presentation" style="margin:0;">
			<tr><th scope="row"><label for="es-identity-color">Color de marca</label></th><td><input id="es-identity-color" class="es-color-field" type="text" name="brand_color" value="<?php echo esc_attr( $id_color ); ?>" placeholder="#1a73e8" style="width:180px;" data-default-color="#1a73e8"></td></tr>
			<tr><th scope="row"><label for="es-identity-logo">Logo por defecto</label></th><td>
				<input id="es-identity-logo" type="hidden" name="default_logo" value="<?php echo esc_attr( $id_logo ); ?>">
				<div style="display:flex;gap:10px;align-items:flex-start;">
					<img id="es-identity-logo-prev" src="<?php echo esc_url( $id_logo ); ?>" style="max-width:140px;max-height:48px;<?php echo $id_logo === '' ? 'display:none;' : ''; ?>">
					<div>
						<button type="button" class="button" id="es-identity-logo-btn">Elegir de la biblioteca de medios</button>
						<button type="button" class="button-link" id="es-identity-logo-clear" style="<?php echo $id_logo === '' ? 'display:none;' : ''; ?>">Quitar logo</button>
					</div>
				</div>
				<p class="description" style="margin:6px 0 0;">Logo general de la Suite para los correos.</p>
			</td></tr>
		</table>
		<p style="margin:10px 0 0;"><?php submit_button( 'Guardar identidad de la marca', 'primary', 'save_identity', false ); ?></p>
	</form>
</div>
<script>
(function () {
	function setup() {
		if ( window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker ) {
			jQuery( '.es-color-field' ).wpColorPicker();
		}
		var inp = document.getElementById( 'es-identity-logo' );
		var btn = document.getElementById( 'es-identity-logo-btn' );
		var clr = document.getElementById( 'es-identity-logo-clear' );
		var prv = document.getElementById( 'es-identity-logo-prev' );
		if ( ! inp || ! btn || ! window.wp || ! wp.media ) { return; }
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var frame = wp.media( { title: 'Elegir logo', button: { text: 'Usar este logo' }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				inp.value = att.url; prv.src = att.url; prv.style.display = '';
				if ( clr ) { clr.style.display = ''; }
			} );
			frame.open();
		} );
		if ( clr ) {
			clr.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				inp.value = ''; prv.src = ''; prv.style.display = 'none'; clr.style.display = 'none';
			} );
		}
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', setup ); }
	else { setup(); }
})();
</script>
