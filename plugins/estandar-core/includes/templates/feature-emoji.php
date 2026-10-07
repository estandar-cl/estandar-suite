<?php
/**
 * Estandar Suite — Core · Configuración: MARCA DE LA SUITE (emoji).
 * Bloque compartido (sub-pestaña "Configuración" y su sub-pestaña alias).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<?php if ( isset( $_GET['icon_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Emoji guardado.</p></div><?php endif; ?>
		<?php $current_icon = (string) get_option( 'estandar_suite_core_menu_icon', '🐲' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_save_menu_icon' ); ?>
			<input type="hidden" name="action" value="estandar_suite_save_menu_icon">
			<div style="margin-top:6px;">
				<div style="position:relative;display:inline-block;">
					<input type="text" id="es-custom-emoji" name="menu_icon_custom" value="<?php echo esc_attr( $current_icon ); ?>" placeholder="Buscar o elegir…" style="width:220px;padding-right:34px;" readonly>
					<button type="button" id="es-emoji-picker-toggle" class="button" style="position:absolute;right:4px;top:1px;border:none;background:transparent;cursor:pointer;font-size:18px;line-height:1;" aria-label="Abrir selector de emojis" title="Abrir selector de emojis">😀</button>
				</div>
				<span id="es-emoji-preview" style="font-size:26px;margin-left:10px;vertical-align:middle;"><?php echo esc_html( $current_icon ); ?></span>
			</div>
			<div id="es-emoji-picker" style="display:none;position:absolute;z-index:99999;margin-top:8px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.18);max-width:340px;width:340px;">
				<div style="padding:8px 10px;border-bottom:1px solid #f0f0f1;">
					<input type="text" id="es-emoji-search" placeholder="Buscar emoji…" style="width:100%;box-sizing:border-box;">
				</div>
				<div style="padding:10px;border-bottom:1px solid #f0f0f1;display:flex;gap:4px;flex-wrap:wrap;" id="es-emoji-tabs"></div>
				<div id="es-emoji-grid" style="max-height:260px;overflow-y:auto;padding:8px;display:grid;grid-template-columns:repeat(8,1fr);gap:2px;"></div>
			</div>
			<?php submit_button( 'Guardar emoji', 'primary', 'save_icon', false ); ?>
		</form>
		<script>
		(function () {
			var EMOJI = <?php echo wp_json_encode( \Estandar\Core\Admin::emoji_catalog(), JSON_UNESCAPED_UNICODE ); ?>;
			var grid = document.getElementById('es-emoji-grid');
			var tabs = document.getElementById('es-emoji-tabs');
			var picker = document.getElementById('es-emoji-picker');
			var search = document.getElementById('es-emoji-search');
			var input = document.getElementById('es-custom-emoji');
			var preview = document.getElementById('es-emoji-preview');
			var toggle = document.getElementById('es-emoji-picker-toggle');
			var current = <?php echo wp_json_encode( $current_icon ); ?>;

			function close() { picker.style.display = 'none'; }
			function renderTab(name) {
				var b = document.createElement('button');
				b.type = 'button';
				b.textContent = name;
				b.style.cssText = 'border:1px solid #dcdcde;background:#fff;border-radius:14px;padding:2px 10px;font-size:13px;cursor:pointer;';
				b.addEventListener('click', function () { renderGrid(name); });
				return b;
			}
			function renderGrid(cat) {
				grid.innerHTML = '';
				var list = cat ? EMOJI[cat] : Object.values(EMOJI).flat();
				if (search.value.trim()) {
					var q = search.value.trim().toLowerCase();
					list = Object.values(EMOJI).flat().filter(function (e) { return e.label.toLowerCase().indexOf(q) !== -1; });
				}
				list.forEach(function (e) {
					var b = document.createElement('button');
					b.type = 'button';
					b.textContent = e.emoji;
					b.title = e.label;
					b.style.cssText = 'font-size:22px;line-height:1;padding:4px;cursor:pointer;background:none;border:none;border-radius:6px;';
					b.addEventListener('click', function () {
						input.value = e.emoji;
						preview.textContent = e.emoji;
						close();
					});
					grid.appendChild(b);
				});
				if (!list.length) { grid.innerHTML = '<span class="description" style="grid-column:1/-1;padding:8px;">Sin resultados.</span>'; }
			}
			toggle.addEventListener('click', function (ev) {
				ev.stopPropagation();
				var hidden = picker.style.display === 'none';
				picker.style.display = hidden ? 'block' : 'none';
				if (hidden) { renderGrid(''); search.value = ''; }
			});
			search.addEventListener('input', function () { renderGrid(''); });
			document.addEventListener('click', function (ev) {
				if (!picker.contains(ev.target) && ev.target !== toggle) { close(); }
			});
			document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
			// Tabs
			Object.keys(EMOJI).forEach(function (k) { tabs.appendChild(renderTab(k)); });
			preview.textContent = current;
		})();
		</script>
