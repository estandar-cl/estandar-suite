<?php
/**
 * Estandar Suite — Core · Correo → Plantillas (MAIL_TEMPLATES_V1).
 *
 * Registro de plantillas de correo (header + estilos del body + footer con el
 * slot {{content}}). Fase 2 ACTIVA: la notificación (Forms::notify_submission),
 * la autorespuesta y las encuestas ya envuelven el body en la plantilla
 * resuelta (form → proyecto → default global). El contrato GF queda intacto.
 *
 * MAIL_TEMPLATES_PREVIEW_V2: editor a la izquierda y vista previa STICKY a la
 * derecha, SIEMPRE visible y en VIVO (re-render al escribir). La vista previa
 * sustituye los tokens ({{site_name}}, {{brand_color}}, {{logo}},
 * {{footer_text}}) por la identidad real del sitio.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Estandar\Core\Mail;

$mail_templates = Mail::mail_templates();
$default_slug   = (string) get_option( Mail::TEMPLATE_DEFAULT_OPTION, '' );

// Nueva plantilla en creación (?new=slug): se muestra un editor vacío al final;
// se persiste solo al guardar (el handler acepta slugs que no estaban en order).
$new_slug = sanitize_key( (string) ( $_GET['new'] ?? '' ) );
if ( $new_slug !== '' && ! isset( $mail_templates[ $new_slug ] ) && $new_slug !== 'base' ) {
	$mail_templates[ $new_slug ] = array(
		'name'          => ucwords( str_replace( array( '-', '_' ), ' ', $new_slug ) ),
		'header_html'   => Mail::mail_template_seed()['header_html'],
		'content_style' => Mail::mail_template_seed()['content_style'],
		'footer_html'   => Mail::mail_template_seed()['footer_html'],
	);
}

// Identidad real para la vista previa (tokens resueltos).
$tpl_id = Mail::identity();
$tpl_tokens = array(
	'site'   => (string) ( $tpl_id['site_name'] ?? '' ),
	'color'  => (string) ( $tpl_id['brand_color'] ?? Mail::DEFAULT_BRAND_COLOR ),
	'footer' => (string) ( $tpl_id['footer_text'] ?? '' ),
	'logo'   => (string) ( $tpl_id['logo_url'] ?? '' ),
);

if ( isset( $_GET['templates_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Plantillas de correo guardadas.</p></div><?php endif; ?>

<div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;margin:12px 0;">

	<!-- ── Editor (izquierda) ─────────────────────────────────────────── -->
	<div style="flex:1 1 560px;min-width:320px;">
	<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;">
		<h3 style="margin:0 0 4px;">Plantillas de correo</h3>
		<p class="description" style="margin:0 0 10px;">Define la <strong>plantilla base</strong> de los correos: cabecera (header), estilos del body y pie (footer). El contenido de cada form/notificación se inyecta en el slot <code>{{content}}</code>. <strong>Estado: Fase 2 activa</strong> — la notificación, la autorespuesta y las encuestas ya consumen plantillas cuando un form o el proyecto elige una, o cuando defines un default global. El selector por formulario está en el maquetador (Correo → Identidad → «Plantilla de correo»); el <strong>contrato GF (postventa) queda siempre sin plantilla</strong>.

		<?php if ( $default_slug !== '' && isset( $mail_templates[ $default_slug ] ) ) : ?>
			<p><span class="description">Plantilla por defecto (global):</span> <strong><?php echo esc_html( (string) ( $mail_templates[ $default_slug ]['name'] ?? $default_slug ) ); ?></strong></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_core_save_mail_templates' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_save_mail_templates">

			<?php foreach ( $mail_templates as $slug => $t ) : $t = is_array( $t ) ? $t : array(); $is_new = ( $slug === $new_slug ); ?>
			<input type="hidden" name="order[]" value="<?php echo esc_attr( $slug ); ?>">
			<div class="es-tpl-card" data-slug="<?php echo esc_attr( $slug ); ?>" style="border:1px solid <?php echo $is_new ? '#d6e6c2;background:#f9fbf5;' : '#e2e4e7;'; ?>border-radius:8px;padding:12px 14px;margin:10px 0;">
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<strong><?php echo esc_html( (string) ( $t['name'] ?? $slug ) ); ?></strong>
					<code style="background:#f0f0f1;padding:1px 8px;border-radius:4px;"><?php echo esc_html( $slug ); ?></code>
					<?php if ( $slug === $default_slug ) : ?><span style="background:#d6f0d2;color:#007017;border-radius:10px;padding:1px 10px;font-size:11px;">POR DEFECTO</span>
					<?php elseif ( ! $is_new ) : ?>
						<span style="margin-left:auto;"></span>
						<button type="button" class="button button-small es-set-default-btn" data-slug="<?php echo esc_attr( $slug ); ?>">Usar como default</button>
					<?php endif; ?>
				</div>
				<table class="form-table" role="presentation" style="margin:8px 0 0;">
					<tr><th scope="row" style="width:150px;"><label for="es-tpl-name-<?php echo esc_attr( $slug ); ?>">Nombre</label></th><td><input id="es-tpl-name-<?php echo esc_attr( $slug ); ?>" type="text" name="edit[<?php echo esc_attr( $slug ); ?>][name]" class="regular-text es-tpl-field" data-slug="<?php echo esc_attr( $slug ); ?>" value="<?php echo esc_attr( (string) ( $t['name'] ?? '' ) ); ?>"></td></tr>
					<tr><th scope="row"><label for="es-tpl-wrapper-<?php echo esc_attr( $slug ); ?>">Contenedor (estilos)</label></th><td><input id="es-tpl-wrapper-<?php echo esc_attr( $slug ); ?>" type="text" name="edit[<?php echo esc_attr( $slug ); ?>][wrapper_style]" class="large-text code es-tpl-field" data-slug="<?php echo esc_attr( $slug ); ?>" value="<?php echo esc_attr( (string) ( $t['wrapper_style'] ?? '' ) ); ?>"><span class="description">CSS inline del contenedor de 600px (fondo, radio, overflow, ancho). Vacío = blanco neutro.</span></td></tr>
					<tr><th scope="row"><label for="es-tpl-header-<?php echo esc_attr( $slug ); ?>">Header (HTML)</label></th><td><textarea id="es-tpl-header-<?php echo esc_attr( $slug ); ?>" name="edit[<?php echo esc_attr( $slug ); ?>][header_html]" rows="4" class="large-text code es-tpl-field" data-slug="<?php echo esc_attr( $slug ); ?>"><?php echo esc_textarea( (string) ( $t['header_html'] ?? '' ) ); ?></textarea><span class="description">Tokens: <code>{{site_name}}</code> <code>{{brand_color}}</code> <code>{{logo}}</code></span></td></tr>
					<tr><th scope="row"><label for="es-tpl-style-<?php echo esc_attr( $slug ); ?>">Body (estilos)</label></th><td><input id="es-tpl-style-<?php echo esc_attr( $slug ); ?>" type="text" name="edit[<?php echo esc_attr( $slug ); ?>][content_style]" class="large-text code es-tpl-field" data-slug="<?php echo esc_attr( $slug ); ?>" value="<?php echo esc_attr( (string) ( $t['content_style'] ?? '' ) ); ?>"><span class="description">CSS inline del contenedor del body (padding, fuente, color…).</span></td></tr>
					<tr><th scope="row"><label for="es-tpl-footer-<?php echo esc_attr( $slug ); ?>">Footer (HTML)</label></th><td><textarea id="es-tpl-footer-<?php echo esc_attr( $slug ); ?>" name="edit[<?php echo esc_attr( $slug ); ?>][footer_html]" rows="3" class="large-text code es-tpl-field" data-slug="<?php echo esc_attr( $slug ); ?>"><?php echo esc_textarea( (string) ( $t['footer_html'] ?? '' ) ); ?></textarea><span class="description">Token: <code>{{footer_text}}</code></span></td></tr>
				</table>
				<?php if ( $slug !== 'base' && ! $is_new ) : ?>
				<div style="margin-top:4px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<?php wp_nonce_field( 'estandar_suite_core_delete_template' ); ?>
						<input type="hidden" name="action" value="estandar_suite_core_delete_template">
						<input type="hidden" name="template_slug" value="<?php echo esc_attr( $slug ); ?>">
						<button type="submit" class="button-link delete" onclick="return confirm('¿Eliminar la plantilla «<?php echo esc_js( (string) ( $t['name'] ?? $slug ) ); ?>»?');">Eliminar plantilla</button>
					</form>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<p style="margin:12px 0;"><?php submit_button( 'Guardar plantillas', 'primary', 'save_templates', false ); ?>
			<?php if ( $default_slug !== '' ) : ?><button type="button" class="button es-clear-default-btn">Quitar default global</button><?php endif; ?></p>
		</form>

		<form id="es-tpl-default" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
			<?php wp_nonce_field( 'estandar_suite_core_set_default_template' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_set_default_template">
			<input type="hidden" id="es-set-default" name="template_slug" value="">
		</form>

		<hr>
		<p><a class="button" href="#" id="es-add-template">＋ Añadir plantilla nueva</a></p>
	</div>
	</div>

	<!-- ── Vista previa (derecha, sticky, en vivo) ────────────────────── -->
	<div style="flex:0 0 420px;min-width:280px;position:sticky;top:40px;align-self:flex-start;">
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">
			<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin:0 0 8px;flex-wrap:wrap;">
				<strong style="font-size:13px;">Vista previa</strong>
				<select id="es-tpl-preview-select" style="max-width:60%;">
					<?php foreach ( $mail_templates as $slug => $t ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $default_slug !== '' ? $default_slug : ( $new_slug !== '' ? $new_slug : 'base' ) ); ?>><?php echo esc_html( (string) ( $t['name'] ?? $slug ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<iframe id="es-tpl-preview" title="Vista previa de la plantilla" style="width:100%;height:520px;border:1px solid #e2e4e7;border-radius:6px;background:#f0f0f1;"></iframe>
			<p class="description" style="margin:8px 0 0;">Se actualiza en vivo al editar. Los tokens se muestran con la identidad real del sitio ({{site_name}} = <strong><?php echo esc_html( $tpl_tokens['site'] ); ?></strong>, color <code><?php echo esc_html( $tpl_tokens['color'] ); ?></code>).</p>
		</div>
	</div>

</div>

<script>
window.ES_TPL_TOKENS = <?php echo wp_json_encode( $tpl_tokens ); ?>;
(function () {
	var T = window.ES_TPL_TOKENS || { site: '', color: '#000000', footer: '', logo: '' };

	function subst( s ) {
		s = ( s == null ) ? '' : String( s );
		var logoHtml = T.logo
			? '<img src="' + T.logo + '" alt="" style="max-height:40px;display:block;">'
			: '<span style="font-size:17px;font-weight:700;color:#ffffff;">' + ( T.site || '' ) + '</span>';
		return s.replace( /\{\{site_name\}\}/g, T.site || '' )
			.replace( /\{\{brand_color\}\}/g, T.color || '#000000' )
			.replace( /\{\{logo\}\}/g, logoHtml )
			.replace( /\{\{footer_text\}\}/g, T.footer || '' );
	}

	function field( slug, key ) {
		var el = document.getElementById( 'es-tpl-' + key + '-' + slug );
		return el ? el.value : '';
	}

	function setup() {
		var sel = document.getElementById( 'es-tpl-preview-select' );
		var ifr = document.getElementById( 'es-tpl-preview' );
		var active = sel ? sel.value : '';

		function highlight() {
			document.querySelectorAll( '.es-tpl-card' ).forEach( function ( c ) {
				c.style.boxShadow = ( c.getAttribute( 'data-slug' ) === active ) ? '0 0 0 2px #2271b1 inset' : '';
			} );
		}

		function render() {
			if ( ! ifr || ! active ) { return; }
			var header  = field( active, 'header' );
			var style   = field( active, 'style' );
			var footer  = field( active, 'footer' );
			var wrapper = field( active, 'wrapper' );
			var sample = '<h2 style="margin:0 0 10px;font-size:18px;color:#1d2327;">Asunto del correo</h2>'
				+ '<p style="margin:0 0 12px;">Contenido que inyecta el formulario o la notificación. Aquí va el <strong>body</strong> real.</p>'
				+ '<p style="margin:0;"><a href="#" style="display:inline-block;padding:9px 16px;border-radius:6px;background:' + ( T.color || '#000000' ) + ';color:#ffffff;text-decoration:none;">Botón de acción</a></p>';
			var inner = '<div style="' + String( style ).replace( /"/g, '&quot;' ) + '">' + sample + '</div>';
			var wrapStyle = String( wrapper ).replace( /"/g, '&quot;' ) || 'max-width:600px;margin:0 auto;background:#ffffff;';
			var html = '<div style="' + wrapStyle + '">'
				+ subst( header ) + inner + subst( footer ) + '</div>';
			ifr.setAttribute( 'srcdoc', '<!doctype html><html><head><meta charset="utf-8"></head>'
				+ '<body style="margin:0;background:#ffffff;padding:16px;">' + html + '</body></html>' );
		}

		function setActive( slug ) {
			if ( ! slug ) { return; }
			active = slug;
			if ( sel ) { sel.value = slug; }
			highlight();
		}

		document.querySelectorAll( '.es-tpl-field' ).forEach( function ( el ) {
			el.addEventListener( 'input', function () { setActive( el.getAttribute( 'data-slug' ) ); render(); } );
			el.addEventListener( 'focus', function () { setActive( el.getAttribute( 'data-slug' ) ); render(); } );
		} );
		if ( sel ) { sel.addEventListener( 'change', function () { active = sel.value; highlight(); render(); } ); }

		// Añadir plantilla nueva.
		var add = document.getElementById( 'es-add-template' );
		if ( add ) {
			add.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var slug = prompt( 'Slug de la nueva plantilla (ej. corporativa):' );
				if ( ! slug ) { return; }
				slug = slug.toLowerCase().replace( /[^a-z0-9-_]+/g, '-' ).replace( /^-+|-+$/g, '' );
				if ( ! slug ) { return; }
				var url = new URL( window.location.href );
				url.searchParams.set( 'new', slug );
				window.location = url.toString();
			} );
		}

		// Default global / quitar default.
		var defForm = document.getElementById( 'es-tpl-default' );
		var defInput = document.getElementById( 'es-set-default' );
		if ( defForm && defInput ) {
			document.querySelectorAll( '.es-set-default-btn' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					defInput.value = b.getAttribute( 'data-slug' ) || '';
					defForm.submit();
				} );
			} );
			document.querySelectorAll( '.es-clear-default-btn' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					defInput.value = '';
					defForm.submit();
				} );
			} );
		}

		highlight();
		render();
	}

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', setup ); }
	else { setup(); }
})();
</script>
