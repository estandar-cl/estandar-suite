<?php
/**
 * Estandar Suite — Capture · WhatsApp (sub-tab).
 * Bot de WhatsApp que reutiliza el MISMO motor y comportamiento que el chatbot
 * web: AiChat + AiChatPrompt + ChatConversation + ChatLog + Ingestion.
 * Variables: $feature, $feature_option.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$wa_fields  = \Estandar\Capture\WhatsAppBot::fields();
$wa_settings = \Estandar\Capture\WhatsAppBot::settings();
$wa_health  = \Estandar\Capture\WhatsAppBot::health();
$wa_stats   = \Estandar\Capture\WhatsAppBot::stats();
$wa_url     = \Estandar\Capture\WhatsAppBot::webhook_url();
$wa_diag    = isset( $wa_health['diag'] ) && is_array( $wa_health['diag'] ) ? $wa_health['diag'] : array();
$wa_test    = get_transient( 'estandar_suite_wa_test_result' );
$wa_test    = is_array( $wa_test ) ? $wa_test : array();
$_h_color = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'bad' => '#d63638' );
?>
<?php echo \Estandar\Core\Admin::help_html( $feature, '<strong>¿Para qué sirve?</strong> Conecta el chatbot de IA de la web con <strong>WhatsApp</strong>: las personas escriben al número de negocio y el bot responde con el <strong>mismo comportamiento, prompt, perfil y conocimiento</strong> que defines para la web. Las conversaciones se guardan en las mismas tablas (canal <code>whatsapp</code>) y los leads capturados caen en el mismo pipeline con origen <code>whatsapp</code>.' ); ?>
<?php if ( isset( $_GET['wa_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings del bot de WhatsApp guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['wa_tested'] ) ) : ?>
	<div class="notice <?php echo ! empty( $wa_test['ok'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( (string) ( $wa_test['msg'] ?? '' ) ); ?></p></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-width:760px;margin-bottom:14px;">
	<?php foreach ( $wa_health['checks'] as $_ck => $_cv ) : ?>
		<div style="border:1px solid <?php echo esc_attr( $_h_color[ $_cv['level'] ] ?? '#6b7280' ); ?>;border-left:4px solid <?php echo esc_attr( $_h_color[ $_cv['level'] ] ?? '#6b7280' ); ?>;border-radius:6px;padding:8px 10px;background:#fff;">
			<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $_cv['label'] ); ?></div>
			<div style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $_h_color[ $_cv['level'] ] ?? '#6b7280' ); ?>;"><?php echo esc_html( $_cv['status'] ); ?></div>
			<div style="font-size:11px;color:#6b7280;margin-top:2px;word-break:break-all;"><?php echo esc_html( $_cv['desc'] ); ?></div>
		</div>
	<?php endforeach; ?>
</div>

<h4>Conexión (Meta WhatsApp Cloud API)</h4>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:680px;">
	<?php wp_nonce_field( 'estandar_suite_capture_save_whatsapp_settings' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_save_whatsapp_settings">
	<table class="form-table" role="presentation">
		<?php foreach ( $wa_fields as $key => $cfg ) : $type = $cfg['type'] ?? 'text'; $val = array_key_exists( $key, $wa_settings ) ? $wa_settings[ $key ] : ( $cfg['default'] ?? '' ); ?>
			<tr>
				<th scope="row"><label for="es-wa-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $cfg['label'] ); ?></label></th>
				<td>
					<?php if ( $type === 'bool' ) : ?>
						<label><input type="checkbox" name="settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (bool) $val, true ); ?>> <?php echo esc_html( $cfg['desc'] ?? '' ); ?></label>
					<?php elseif ( $type === 'number' ) : ?>
						<input id="es-wa-<?php echo esc_attr( $key ); ?>" type="number" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $val ); ?>" class="small-text" min="<?php echo esc_attr( (string) ( $cfg['min'] ?? 0 ) ); ?>" max="<?php echo esc_attr( (string) ( $cfg['max'] ?? 1000 ) ); ?>"> <span class="description"><?php echo esc_html( $cfg['desc'] ?? '' ); ?></span>
					<?php elseif ( $type === 'textarea' ) : ?>
						<textarea id="es-wa-<?php echo esc_attr( $key ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" rows="<?php echo (int) ( $cfg['rows'] ?? 3 ); ?>" class="large-text"><?php echo esc_textarea( (string) $val ); ?></textarea> <span class="description"><?php echo esc_html( $cfg['desc'] ?? '' ); ?></span>
					<?php elseif ( $type === 'secret' ) : ?>
						<input id="es-wa-<?php echo esc_attr( $key ); ?>" type="password" name="settings[<?php echo esc_attr( $key ); ?>]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $val !== '' ? '•••••••••• (configurado — déjalo en blanco para conservarlo)' : 'Introduce el valor'; ?>"> <span class="description"><?php echo esc_html( $cfg['desc'] ?? '' ); ?></span>
					<?php else : ?>
						<input id="es-wa-<?php echo esc_attr( $key ); ?>" type="text" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $val ); ?>" class="regular-text"> <span class="description"><?php echo esc_html( $cfg['desc'] ?? '' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php submit_button( 'Guardar settings', 'primary', 'save', false ); ?>
</form>

<h4>Webhook de Meta</h4>
<p class="description">En <strong>Meta for Developers → WhatsApp → Configuration → Webhook</strong>, usa esta URL y el <em>Verify Token</em> que definiste arriba:</p>
<div style="display:flex;gap:8px;align-items:center;max-width:680px;">
	<input id="es-wa-webhook-url" type="text" readonly value="<?php echo esc_attr( $wa_url ); ?>" style="flex:1;font-family:Consolas,Monaco,monospace;font-size:12px;">
	<button type="button" class="button" data-wa-copy-target="es-wa-webhook-url">Copiar</button>
</div>
<p class="description" style="margin-top:6px;">Campos de la webhook: <code>messages</code> (suscríbete a <code>messages</code>). Verificación: elige un <em>Verify Token</em> propio, pégalo en este panel y en Meta. Si configuras el <strong>App Secret</strong>, el bot valida la firma <code>X-Hub-Signature-256</code> de cada entrega.</p>

<h4>Pruebas</h4>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
	<?php wp_nonce_field( 'estandar_suite_capture_whatsapp_test' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_whatsapp_test">
	<?php submit_button( 'Probar conexión con Meta', 'secondary', 'test-connection', false ); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
	<?php wp_nonce_field( 'estandar_suite_capture_whatsapp_test' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_whatsapp_send_test">
	<input type="text" name="test_phone" placeholder="ej. 56912345678" class="regular-text">
	<?php submit_button( 'Enviar mensaje de prueba', 'secondary', 'send-test', false ); ?>
 </form>

<h4>Botón flotante de WhatsApp (widget del sitio)</h4>
<?php if ( isset( $_GET['wa_fwa_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings del botón flotante guardados.</p></div><?php endif; ?>
<?php $fwa = \Estandar\Capture\FloatingWhatsApp::settings(); ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:680px;">
	<?php wp_nonce_field( 'estandar_suite_capture_save_floating_whatsapp_settings' ); ?>
	<input type="hidden" name="action" value="estandar_suite_capture_save_floating_whatsapp_settings">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="es-fwa-enabled">Activar botón flotante</label></th>
			<td>
				<label><input type="checkbox" id="es-fwa-enabled" name="settings[enabled]" value="1" <?php checked( (bool) ( $fwa['enabled'] ?? 0 ), true ); ?>> Muestra la barra inferior de WhatsApp en el sitio.</label>
				<p class="description">Opt-in: sin esta casilla el widget no se imprime (cero impacto).</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-number">Número global (E.164)</label></th>
			<td>
				<input id="es-fwa-number" type="text" name="settings[number]" value="<?php echo esc_attr( (string) ( $fwa['number'] ?? '' ) ); ?>" class="regular-text" placeholder="56942900458">
				<p class="description">Número por defecto (home, contacto, resto). Dígitos sin + ni espacios.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-message">Mensaje prefabricado</label></th>
			<td>
				<input id="es-fwa-message" type="text" name="settings[message]" value="<?php echo esc_attr( (string) ( $fwa['message'] ?? '' ) ); ?>" class="large-text">
			</td>
		</tr>
		<tr>
			<th scope="row">Visibilidad</th>
			<td>
				<label style="margin-right:14px;"><input type="checkbox" name="settings[show_desktop]" value="1" <?php checked( (bool) ( $fwa['show_desktop'] ?? 1 ), true ); ?>> Desktop</label>
				<label><input type="checkbox" name="settings[show_mobile]" value="1" <?php checked( (bool) ( $fwa['show_mobile'] ?? 1 ), true ); ?>> Móvil</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-newtab">Abrir en pestaña nueva</label></th>
			<td>
				<label><input type="checkbox" id="es-fwa-newtab" name="settings[open_new_tab]" value="1" <?php checked( (bool) ( $fwa['open_new_tab'] ?? 1 ), true ); ?>> Abre WhatsApp en una pestaña nueva (marcado por defecto).</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-track">Registrar clicks de WhatsApp</label></th>
			<td>
				<label><input type="checkbox" id="es-fwa-track" name="settings[track_clicks]" value="1" <?php checked( (bool) ( $fwa['track_clicks'] ?? 0 ), true ); ?>> Guarda cada click al botón (gclid, página, dispositivo) en la suite y emite <code>whatsapp_click</code> al dataLayer para GTM/GA4.</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-reserve">Reservar espacio (no tapar el footer)</label></th>
			<td>
				<label><input type="checkbox" id="es-fwa-reserve" name="settings[reserve_space]" value="1" <?php checked( (bool) ( $fwa['reserve_space'] ?? 1 ), true ); ?>> Añade al final de la página el hueco justo de la altura del botón, para que la barra fija no tape el contenido del footer (típico en móvil). Se calcula en vivo según la altura real.</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-selectors">Ocultar botones previos</label></th>
			<td>
				<textarea id="es-fwa-selectors" name="settings[selectors]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $fwa['selectors'] ?? '' ) ); ?></textarea>
				<p class="description">Selectores CSS de barras/botones de WhatsApp del tema a ocultar (uno por línea). Ej: <code>.ak-wa-mobile-sticky</code></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-padding">Contenedor con padding</label></th>
			<td>
				<input id="es-fwa-padding" type="text" name="settings[padding_selector]" value="<?php echo esc_attr( (string) ( $fwa['padding_selector'] ?? '' ) ); ?>" class="regular-text" placeholder="#ak-landing-wrapper">
				<p class="description">Selector del contenedor al que se añade padding-bottom para que el contenido no quede tapado.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="es-fwa-hide">Ocultar en páginas</label></th>
			<td>
				<textarea id="es-fwa-hide" name="settings[hide_paths]" rows="2" class="large-text" placeholder="/temuco_inicio/"><?php echo esc_textarea( (string) ( $fwa['hide_paths'] ?? '' ) ); ?></textarea>
				<p class="description">Paths donde el botón flotante <strong>NO</strong> se muestra (uno por línea). Útil en landings dedicadas con form propio (p. ej. <code>/temuco_inicio/</code>) para no dividir la conversión. En el resto de la web el botón se mantiene.</p>
			</td>
		</tr>
	</table>

	<h5>Reglas por página (número distinto según la página/sucursal)</h5>
	<p class="description">Asigna un número y mensaje propios a una página concreta (p. ej. la landing de Temuco, o la página de un proyecto). Cada regla: match = <code>path</code> (por URL) o <code>slug</code> (por slug de la página).</p>
	<div id="es-fwa-rules">
		<?php
		$rules = (array) ( $fwa['rules'] ?? array() );
		if ( empty( $rules ) ) {
			$rules = array( array( 'match' => 'path', 'path' => '', 'slug' => '', 'number' => '', 'message' => '' ) );
		}
		foreach ( $rules as $ri => $rule ) :
			?>
		<div class="es-fwa-rule" style="border:1px solid #ccd0d4;border-radius:4px;padding:10px;margin-bottom:8px;background:#f7f8fa;">
			<p style="margin:0 0 6px;">
				<select name="settings[rules][<?php echo (int) $ri; ?>][match]" style="width:110px;">
					<option value="path" <?php selected( (string) ( $rule['match'] ?? 'path' ), 'path' ); ?>>Path (URL)</option>
					<option value="slug" <?php selected( (string) ( $rule['match'] ?? '' ), 'slug' ); ?>>Slug (página)</option>
				</select>
				<input type="text" name="settings[rules][<?php echo (int) $ri; ?>][path]" value="<?php echo esc_attr( (string) ( $rule['path'] ?? '' ) ); ?>" placeholder="/temuco_inicio/" style="width:200px;">
				<input type="text" name="settings[rules][<?php echo (int) $ri; ?>][slug]" value="<?php echo esc_attr( (string) ( $rule['slug'] ?? '' ) ); ?>" placeholder="parronales" style="width:140px;">
			</p>
			<p style="margin:0;">
				<input type="text" name="settings[rules][<?php echo (int) $ri; ?>][number]" value="<?php echo esc_attr( (string) ( $rule['number'] ?? '' ) ); ?>" placeholder="56923713488" class="regular-text" style="width:180px;">
				<input type="text" name="settings[rules][<?php echo (int) $ri; ?>][message]" value="<?php echo esc_attr( (string) ( $rule['message'] ?? '' ) ); ?>" placeholder="Mensaje de la sucursal" class="regular-text" style="width:300px;">
				<button type="button" class="button es-fwa-rule-remove">Quitar</button>
			</p>
		</div>
		<?php endforeach; ?>
	</div>
	<p><button type="button" class="button" id="es-fwa-rule-add">+ Añadir regla</button></p>
	<?php submit_button( 'Guardar botón flotante', 'primary', 'save-fwa', false ); ?>
</form>

<h4>Diagnóstico</h4>
<table class="widefat striped" style="max-width:680px;">
	<tbody>
		<tr><td style="width:220px;"><strong>Último webhook</strong></td><td><?php echo esc_html( (string) ( $wa_diag['last_webhook_at'] ?? '—' ) ); ?></td></tr>
		<tr><td><strong>Último mensaje recibido</strong></td><td><?php echo esc_html( (string) ( $wa_diag['last_message_at'] ?? '—' ) ); ?></td></tr>
		<tr><td><strong>Último envío</strong></td><td><?php echo esc_html( (string) ( $wa_diag['last_send_at'] ?? '—' ) ); ?></td></tr>
		<tr><td><strong>Último error</strong></td><td><?php echo esc_html( (string) ( $wa_diag['last_error'] ?? '—' ) ); ?><?php echo ! empty( $wa_diag['last_error_ts'] ) ? ' (' . esc_html( (string) $wa_diag['last_error_ts'] ) . ')' : ''; ?></td></tr>
		<tr><td><strong>Conversaciones WhatsApp</strong></td><td><?php echo count( $wa_stats['conversations'] ?? array() ); ?></td></tr>
	</tbody>
</table>

<script>
(function () {
	var btn = document.querySelector('[data-wa-copy-target]');
	if (btn) {
		btn.addEventListener('click', function () {
			var el = document.getElementById(btn.getAttribute('data-wa-copy-target'));
			if (el) { el.select(); document.execCommand('copy'); }
		});
	}
	// Botón flotante: añadir / quitar reglas por página.
	var addBtn = document.getElementById('es-fwa-rule-add');
	var rules = document.getElementById('es-fwa-rules');
	var idx = rules ? rules.children.length : 0;
	if (addBtn && rules) {
		addBtn.addEventListener('click', function () {
			var div = document.createElement('div');
			div.className = 'es-fwa-rule';
			div.style.cssText = 'border:1px solid #ccd0d4;border-radius:4px;padding:10px;margin-bottom:8px;background:#f7f8fa;';
			div.innerHTML =
				'<p style="margin:0 0 6px;">' +
				'<select name="settings[rules][' + idx + '][match]" style="width:110px;">' +
				'<option value="path">Path (URL)</option><option value="slug">Slug (página)</option></select> ' +
				'<input type="text" name="settings[rules][' + idx + '][path]" placeholder="/temuco_inicio/" style="width:200px;"> ' +
				'<input type="text" name="settings[rules][' + idx + '][slug]" placeholder="parronales" style="width:140px;"></p>' +
				'<p style="margin:0;">' +
				'<input type="text" name="settings[rules][' + idx + '][number]" placeholder="56923713488" class="regular-text" style="width:180px;"> ' +
				'<input type="text" name="settings[rules][' + idx + '][message]" placeholder="Mensaje de la sucursal" class="regular-text" style="width:300px;"> ' +
				'<button type="button" class="button es-fwa-rule-remove">Quitar</button></p>';
			rules.appendChild(div);
			idx++;
			div.querySelector('.es-fwa-rule-remove').addEventListener('click', function () { div.remove(); });
		});
		document.querySelectorAll('.es-fwa-rule-remove').forEach(function (b) {
			b.addEventListener('click', function () { b.closest('.es-fwa-rule').remove(); });
		});
	}
})();
</script>
