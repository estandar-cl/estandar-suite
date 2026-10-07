<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'apariencia' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

wp_enqueue_media();

$theme_accent        = get_option( 'chatbot_theme_accent', '#111827' );
$theme_visual_style  = (string) ( get_option( 'chatbot_visual_style', '' ) ?: get_option( 'chatbot_theme_visual_style', 'default' ) );
$theme_shadow_enabled = (int) get_option( 'chatbot_theme_shadow_enabled', 1 );
$theme_user_bubble   = get_option( 'chatbot_theme_user_bubble', '#111827' );
$theme_bot_bubble    = get_option( 'chatbot_theme_bot_bubble', '#ffffff' );
$branding_config     = get_option( 'chatbot_branding_config', [
	'bg_color'         => '#ffffff',
	'bot_bubble_color' => '#f3f4f6',
	'user_bubble_color'=> '#e8f0fe',
	'button_color'     => '#2563eb',
	'text_color'       => '#111827',
	'background_image' => '',
	'bot_avatar'       => '',
	'shadow_enabled'   => 1,
	'shadow_intensity' => 6,
] );
$theme_radius        = (int) get_option( 'chatbot_theme_radius', 22 );
$theme_bubble_shape  = (string) get_option( 'chatbot_theme_bubble_shape', 'rounded' );
$theme_position      = get_option( 'chatbot_theme_position', 'right' );
$bubble_bottom       = (int) get_option( 'chatbot_position_bottom', 22 );
$bubble_left         = (string) get_option( 'chatbot_position_left', '' );
$bubble_right        = (string) get_option( 'chatbot_position_right', '' );
$bubble_image_url    = get_option( 'chatbot_bubble_image_url', '' );
$bubble_size         = (int) get_option( 'chatbot_bubble_size', 58 );
$inline_sidebar_enabled = (int) get_option( 'chatbot_inline_sidebar_enabled', 0 );
$inline_fixed_composer_enabled = (int) get_option( 'chatbot_inline_fixed_composer_enabled', 0 );
$inline_fullpage_enabled = (int) get_option( 'chatbot_inline_fullpage_enabled', 0 );
$nombre              = get_option( 'chatbot_nombre', 'Asistente' );
$pv_float_radius     = $theme_bubble_shape === 'square' ? '0px' : ( $theme_bubble_shape === 'rounded' ? '9999px' : ( (int) $theme_radius . 'px' ) );
?>
<style>
	.ecb-ap-label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; margin-bottom:4px; font-weight:600; }
	.ecb-ap-row { display:flex; align-items:center; gap:8px; }
	.ecb-ap-color { display:flex; flex-direction:column; gap:2px; }
	.ecb-ap-color span { font-size:12px; color:#374151; }
	.ecb-ap-section h3 { margin:0 0 12px; font-size:14px; }
	.ecb-ap-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px 14px; }
	.ecb-ap-section .description { margin:4px 0 0; font-size:12px; }
</style>
<form method="post" action="options.php">
	<?php settings_fields( 'estandar_chatbot_settings' ); ?>

	<?php // ── HEADER compacto: preset · acento · posición ──────────────────── ?>
	<div class="ecb-admin-surface" style="margin-bottom:14px;padding:14px 18px;display:flex;align-items:flex-end;gap:24px;flex-wrap:wrap;">
		<div>
			<span class="ecb-ap-label"><?php esc_html_e( 'Preset visual', 'estandar-chatbot' ); ?></span>
			<select name="chatbot_visual_style">
				<option value="default" <?php selected( $theme_visual_style, 'default' ); ?>><?php esc_html_e( 'Default', 'estandar-chatbot' ); ?></option>
				<option value="corporativo_minimalista" <?php selected( $theme_visual_style, 'corporativo_minimalista' ); ?>><?php esc_html_e( 'Corporativo minimalista', 'estandar-chatbot' ); ?></option>
			</select>
		</div>
		<div>
			<span class="ecb-ap-label"><?php esc_html_e( 'Color acento', 'estandar-chatbot' ); ?></span>
			<input type="color" name="chatbot_theme_accent" value="<?php echo esc_attr( $theme_accent ); ?>" style="height:30px;" />
		</div>
		<div>
			<span class="ecb-ap-label"><?php esc_html_e( 'Posición', 'estandar-chatbot' ); ?></span>
			<select name="chatbot_theme_position" id="chatbot_theme_position">
				<option value="right" <?php selected( $theme_position, 'right' ); ?>><?php esc_html_e( 'Derecha', 'estandar-chatbot' ); ?></option>
				<option value="left" <?php selected( $theme_position, 'left' ); ?>><?php esc_html_e( 'Izquierda', 'estandar-chatbot' ); ?></option>
				<option value="custom" <?php selected( $theme_position, 'custom' ); ?>><?php esc_html_e( 'Personalizada', 'estandar-chatbot' ); ?></option>
			</select>
		</div>
		<p class="description" style="margin:0 0 4px;flex:1;min-width:200px;"><?php esc_html_e( 'Los cambios se reflejan en la vista previa al instante.', 'estandar-chatbot' ); ?></p>
	</div>

	<?php // ── BODY: 2 columnas — configuración (60%) + preview sticky (40%) ── ?>
	<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">

		<div style="flex:3 1 460px;min-width:0;">

			<?php // ── Forma y burbuja flotante ─────────────────────────────── ?>
			<div class="ecb-admin-surface ecb-ap-section" style="margin-bottom:14px;padding:16px 18px;">
				<h3><?php esc_html_e( 'Forma y burbuja flotante', 'estandar-chatbot' ); ?></h3>
				<div class="ecb-ap-grid">
					<div>
						<span class="ecb-ap-label"><?php esc_html_e( 'Forma burbuja', 'estandar-chatbot' ); ?></span>
						<select name="chatbot_theme_bubble_shape">
							<option value="rounded" <?php selected( $theme_bubble_shape, 'rounded' ); ?>><?php esc_html_e( 'Redonda', 'estandar-chatbot' ); ?></option>
							<option value="square" <?php selected( $theme_bubble_shape, 'square' ); ?>><?php esc_html_e( 'Cuadrada', 'estandar-chatbot' ); ?></option>
							<option value="rounded_corners" <?php selected( $theme_bubble_shape, 'rounded_corners' ); ?>><?php esc_html_e( 'Cuadrada con bordes redondeados', 'estandar-chatbot' ); ?></option>
						</select>
					</div>
					<div>
						<span class="ecb-ap-label"><?php esc_html_e( 'Radio bordes', 'estandar-chatbot' ); ?></span>
						<input type="number" min="12" max="28" step="1" name="chatbot_theme_radius" value="<?php echo esc_attr( (string) $theme_radius ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Solo para «cuadrada con bordes redondeados».', 'estandar-chatbot' ); ?></p>
					</div>
					<div>
						<span class="ecb-ap-label"><?php esc_html_e( 'Tamaño burbuja (px)', 'estandar-chatbot' ); ?></span>
						<input type="number" min="40" max="88" step="1" name="chatbot_bubble_size" value="<?php echo esc_attr( (string) $bubble_size ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Recomendado: 52–64.', 'estandar-chatbot' ); ?></p>
					</div>
					<div>
						<span class="ecb-ap-label"><?php esc_html_e( 'Sombras', 'estandar-chatbot' ); ?></span>
						<label style="font-size:13px;">
							<input type="hidden" name="chatbot_theme_shadow_enabled" value="0" />
							<input type="checkbox" name="chatbot_theme_shadow_enabled" value="1" <?php checked( $theme_shadow_enabled, 1 ); ?> />
							<?php esc_html_e( 'Sombras suaves', 'estandar-chatbot' ); ?>
						</label>
					</div>
				</div>

				<div style="margin-top:14px;">
					<span class="ecb-ap-label"><?php esc_html_e( 'Imagen/GIF burbuja', 'estandar-chatbot' ); ?></span>
					<div class="ecb-ap-row" style="flex-wrap:wrap;">
						<input id="chatbot_bubble_image_url" type="url" name="chatbot_bubble_image_url" value="<?php echo esc_attr( $bubble_image_url ); ?>" class="regular-text" placeholder="https://.../burbuja.gif" />
						<button type="button" class="button" id="ecb-bubble-pick-media"><?php esc_html_e( 'Elegir / Subir', 'estandar-chatbot' ); ?></button>
						<button type="button" class="button" id="ecb-bubble-clear-media"><?php esc_html_e( 'Quitar', 'estandar-chatbot' ); ?></button>
					</div>
				</div>

				<div id="ecb-custom-position-fields" style="margin-top:14px;display:<?php echo 'custom' === $theme_position ? 'grid' : 'none'; ?>;grid-template-columns:repeat(3,minmax(100px,1fr));gap:12px;max-width:480px;">
					<label>
						<span class="ecb-ap-label"><?php esc_html_e( 'Bottom (px)', 'estandar-chatbot' ); ?></span>
						<input type="number" min="0" max="240" step="1" name="chatbot_position_bottom" value="<?php echo esc_attr( (string) $bubble_bottom ); ?>" class="small-text" />
					</label>
					<label>
						<span class="ecb-ap-label"><?php esc_html_e( 'Left (px)', 'estandar-chatbot' ); ?></span>
						<input type="number" min="0" max="240" step="1" name="chatbot_position_left" value="<?php echo esc_attr( $bubble_left ); ?>" class="small-text" placeholder="vacío" />
					</label>
					<label>
						<span class="ecb-ap-label"><?php esc_html_e( 'Right (px)', 'estandar-chatbot' ); ?></span>
						<input type="number" min="0" max="240" step="1" name="chatbot_position_right" value="<?php echo esc_attr( $bubble_right ); ?>" class="small-text" placeholder="vacío" />
					</label>
					<p class="description" style="grid-column:1 / -1;margin:0;"><?php esc_html_e( 'En modo personalizado se usa bottom y se prioriza left si ambos lados tienen valor.', 'estandar-chatbot' ); ?></p>
				</div>
			</div>

			<?php // ── Acordeón: Overrides de branding ──────────────────────── ?>
			<details class="ecb-admin-surface ecb-ap-section" style="margin-bottom:14px;padding:14px 18px;">
				<summary style="cursor:pointer;font-weight:600;font-size:14px;">
					<?php esc_html_e( 'Overrides de branding', 'estandar-chatbot' ); ?>
					<span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?php esc_html_e( 'solo si la marca del cliente lo requiere', 'estandar-chatbot' ); ?></span>
				</summary>

				<div class="ecb-ap-grid" style="margin-top:14px;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));">
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Fondo chat', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_branding_config[bg_color]" value="<?php echo esc_attr( (string) ( $branding_config['bg_color'] ?? '#ffffff' ) ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Burbuja bot', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_branding_config[bot_bubble_color]" value="<?php echo esc_attr( (string) ( $branding_config['bot_bubble_color'] ?? '#f3f4f6' ) ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Burbuja usuario', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_branding_config[user_bubble_color]" value="<?php echo esc_attr( (string) ( $branding_config['user_bubble_color'] ?? '#e8f0fe' ) ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Botón', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_branding_config[button_color]" value="<?php echo esc_attr( (string) ( $branding_config['button_color'] ?? '#2563eb' ) ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Texto', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_branding_config[text_color]" value="<?php echo esc_attr( (string) ( $branding_config['text_color'] ?? '#111827' ) ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Burbuja usuario (tema)', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_theme_user_bubble" value="<?php echo esc_attr( $theme_user_bubble ); ?>" />
					</label>
					<label class="ecb-ap-color">
						<span><?php esc_html_e( 'Burbuja bot (tema)', 'estandar-chatbot' ); ?></span>
						<input type="color" name="chatbot_theme_bot_bubble" value="<?php echo esc_attr( $theme_bot_bubble ); ?>" />
					</label>
				</div>

				<div style="margin-top:14px;">
					<span class="ecb-ap-label"><?php esc_html_e( 'Imagen de fondo del chat', 'estandar-chatbot' ); ?></span>
					<div class="ecb-ap-row" style="flex-wrap:wrap;">
						<input id="chatbot_branding_background_image" type="url" name="chatbot_branding_config[background_image]" value="<?php echo esc_attr( (string) ( $branding_config['background_image'] ?? '' ) ); ?>" class="regular-text" placeholder="https://.../fondo.jpg" />
						<button type="button" class="button ecb-pick-media" data-target="chatbot_branding_background_image"><?php esc_html_e( 'Elegir / Subir', 'estandar-chatbot' ); ?></button>
						<button type="button" class="button ecb-clear-media" data-target="chatbot_branding_background_image"><?php esc_html_e( 'Quitar', 'estandar-chatbot' ); ?></button>
					</div>
				</div>

				<div style="margin-top:12px;">
					<span class="ecb-ap-label"><?php esc_html_e( 'Avatar del bot', 'estandar-chatbot' ); ?></span>
					<div class="ecb-ap-row" style="flex-wrap:wrap;">
						<input id="chatbot_branding_bot_avatar" type="url" name="chatbot_branding_config[bot_avatar]" value="<?php echo esc_attr( (string) ( $branding_config['bot_avatar'] ?? '' ) ); ?>" class="regular-text" placeholder="https://.../avatar.png" />
						<button type="button" class="button ecb-pick-media" data-target="chatbot_branding_bot_avatar"><?php esc_html_e( 'Elegir / Subir', 'estandar-chatbot' ); ?></button>
						<button type="button" class="button ecb-clear-media" data-target="chatbot_branding_bot_avatar"><?php esc_html_e( 'Quitar', 'estandar-chatbot' ); ?></button>
					</div>
				</div>

				<div style="margin-top:12px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
					<label style="font-size:13px;">
						<input type="hidden" name="chatbot_branding_config[shadow_enabled]" value="0" />
						<input type="checkbox" name="chatbot_branding_config[shadow_enabled]" value="1" <?php checked( ! empty( $branding_config['shadow_enabled'] ), true ); ?> />
						<?php esc_html_e( 'Sombras del branding', 'estandar-chatbot' ); ?>
					</label>
					<label style="font-size:13px;display:flex;align-items:center;gap:8px;">
						<?php esc_html_e( 'Intensidad', 'estandar-chatbot' ); ?>
						<input type="range" min="0" max="24" step="1" name="chatbot_branding_config[shadow_intensity]" value="<?php echo esc_attr( (string) ( $branding_config['shadow_intensity'] ?? 6 ) ); ?>" />
					</label>
				</div>
			</details>

			<?php // ── Acordeón: Modo página completa (inline) ──────────────── ?>
			<details class="ecb-admin-surface ecb-ap-section" style="margin-bottom:14px;padding:14px 18px;">
				<summary style="cursor:pointer;font-weight:600;font-size:14px;">
					<?php esc_html_e( 'Modo página completa (inline)', 'estandar-chatbot' ); ?>
					<span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?php esc_html_e( 'solo afecta el shortcode', 'estandar-chatbot' ); ?></span>
				</summary>
				<div style="margin-top:12px;">
					<label style="display:block;margin-bottom:6px;">
						<input type="hidden" name="chatbot_inline_sidebar_enabled" value="0" />
						<input type="checkbox" name="chatbot_inline_sidebar_enabled" value="1" <?php checked( $inline_sidebar_enabled, 1 ); ?> />
						<?php esc_html_e( 'Sidebar opcional de historial/acciones (estilo ChatGPT)', 'estandar-chatbot' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="hidden" name="chatbot_inline_fixed_composer_enabled" value="0" />
						<input type="checkbox" name="chatbot_inline_fixed_composer_enabled" value="1" <?php checked( $inline_fixed_composer_enabled, 1 ); ?> />
						<?php esc_html_e( 'Composer fijo abajo después de iniciar conversación', 'estandar-chatbot' ); ?>
					</label>
					<label style="display:block;">
						<input type="hidden" name="chatbot_inline_fullpage_enabled" value="0" />
						<input type="checkbox" name="chatbot_inline_fullpage_enabled" value="1" <?php checked( $inline_fullpage_enabled, 1 ); ?> />
						<?php esc_html_e( 'Modo full page limpio (sin borde/sombra de panel)', 'estandar-chatbot' ); ?>
					</label>
				</div>
			</details>

			<?php submit_button( __( 'Guardar apariencia', 'estandar-chatbot' ) ); ?>
		</div>

		<?php // ── COL_B: preview sticky ────────────────────────────────────── ?>
		<aside style="flex:2 1 320px;min-width:300px;position:sticky;top:46px;">
			<div class="ecb-admin-surface" style="padding:14px 16px;">
				<h3 style="margin:0 0 10px;font-size:14px;"><?php esc_html_e( 'Vista previa', 'estandar-chatbot' ); ?></h3>
				<div id="ecb-theme-preview" style="--pv-accent:<?php echo esc_attr( $theme_accent ); ?>;--pv-user:<?php echo esc_attr( $theme_user_bubble ); ?>;--pv-bot:<?php echo esc_attr( $theme_bot_bubble ); ?>;--pv-radius:<?php echo esc_attr( (string) $theme_radius ); ?>px;--pv-float-radius:<?php echo esc_attr( $pv_float_radius ); ?>;--pv-bubble-size:<?php echo esc_attr( (string) $bubble_size ); ?>px;border:1px solid #d9d9de;border-radius:16px;background:#f7f7f8;padding:14px;position:relative;min-height:420px;">
					<div id="ecb-theme-preview-float" style="position:absolute;bottom:14px;right:14px;width:var(--pv-bubble-size);height:var(--pv-bubble-size);border-radius:var(--pv-float-radius);background:var(--pv-accent);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;box-shadow:<?php echo $theme_shadow_enabled ? '0 4px 12px rgba(0,0,0,0.12)' : 'none'; ?>;">💬</div>
					<div id="ecb-theme-preview-panel" style="position:absolute;bottom:78px;right:14px;left:auto;width:300px;max-width:calc(100% - 28px);border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;overflow:hidden;box-shadow:<?php echo $theme_shadow_enabled ? '0 4px 12px rgba(0,0,0,0.06)' : 'none'; ?>;">
						<div style="padding:12px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;font-size:14px;color:#1a1a1a;display:flex;align-items:center;gap:8px;">
							<span style="width:22px;height:22px;border-radius:50%;background:var(--pv-accent);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:11px;flex-shrink:0;">🤖</span>
							<?php echo esc_html( $nombre ?: 'Asistente' ); ?>
						</div>
						<div style="padding:12px;display:flex;flex-direction:column;gap:8px;background:transparent;">
							<div style="align-self:flex-start;max-width:85%;padding:8px 10px;border-radius:14px;background:var(--pv-bot);border:1px solid #e5e7eb;color:#111827;font-size:12px;"><?php esc_html_e( 'Hola, ¿en qué te ayudo?', 'estandar-chatbot' ); ?></div>
							<div style="align-self:flex-end;max-width:85%;padding:8px 10px;border-radius:14px;background:var(--pv-user);color:#1a1a1a;font-size:12px;border:1px solid rgba(37,99,235,0.08);"><?php esc_html_e( 'Quiero cotizar un servicio', 'estandar-chatbot' ); ?></div>
							<div style="align-self:flex-start;max-width:85%;padding:8px 10px;border-radius:14px;background:var(--pv-bot);border:1px solid #e5e7eb;color:#111827;font-size:12px;"><?php esc_html_e( 'Perfecto 👌 ¿avanzamos?', 'estandar-chatbot' ); ?></div>
							<div style="align-self:flex-start;">
								<span style="display:inline-block;padding:6px 14px;border-radius:16px;background:var(--pv-accent);color:#fff;font-size:12px;font-weight:600;"><?php esc_html_e( 'Cotizar ahora', 'estandar-chatbot' ); ?></span>
							</div>
						</div>
						<div style="padding:10px 12px;border-top:1px solid #e5e7eb;background:#ffffff;">
							<div style="height:40px;border:1px solid #e5e7eb;border-radius:999px;background:#f7f7f8;display:flex;align-items:center;padding:0 14px;color:#9ca3af;font-size:12px;"><?php esc_html_e( 'Escribe un mensaje…', 'estandar-chatbot' ); ?></div>
						</div>
					</div>
				</div>
				<p class="description" style="margin:10px 0 0;"><?php esc_html_e( 'Simulación visual. El comportamiento real se prueba en la página de prueba.', 'estandar-chatbot' ); ?></p>
			</div>
		</aside>
	</div>
</form>

<script>
(function(){
	function bindMediaPicker(pick, clear, input) {
		if (!pick || !clear || !input) return;
		var frame = null;
		pick.addEventListener('click', function(e){
			e.preventDefault();
			if (!frame) {
				frame = window.wp.media({ title: 'Seleccionar imagen', button: { text: 'Usar este archivo' }, multiple: false, library: { type: ['image'] } });
				frame.on('select', function(){
					var sel = frame.state().get('selection').first();
					if (!sel) return;
					var json = sel.toJSON ? sel.toJSON() : {};
					input.value = json.url || (json.sizes && json.sizes.full && json.sizes.full.url) || '';
					input.dispatchEvent(new Event('input'));
				});
			}
			frame.open();
		});
		clear.addEventListener('click', function(){
			input.value = '';
			input.dispatchEvent(new Event('input'));
		});
	}

	function initPicker(){
		if (!window.wp || !window.wp.media) { setTimeout(initPicker, 200); return; }
		document.querySelectorAll('.ecb-pick-media').forEach(function(pick){
			var target = pick.getAttribute('data-target');
			var input = target ? document.getElementById(target) : null;
			var clear = document.querySelector('.ecb-clear-media[data-target="' + target + '"]');
			bindMediaPicker(pick, clear, input);
		});
		bindMediaPicker(
			document.getElementById('ecb-bubble-pick-media'),
			document.getElementById('ecb-bubble-clear-media'),
			document.getElementById('chatbot_bubble_image_url')
		);
	}
	initPicker();
})();

(function(){
	var accentInput = document.querySelector('input[name="chatbot_theme_accent"]');
	var visualStyleInput = document.querySelector('select[name="chatbot_visual_style"]');
	var shadowInput = document.querySelector('input[name="chatbot_theme_shadow_enabled"]');
	var userInput = document.querySelector('input[name="chatbot_theme_user_bubble"]');
	var botInput = document.querySelector('input[name="chatbot_theme_bot_bubble"]');
	var radiusInput = document.querySelector('input[name="chatbot_theme_radius"]');
	var shapeInput = document.querySelector('select[name="chatbot_theme_bubble_shape"]');
	var posInput = document.querySelector('select[name="chatbot_theme_position"]');
	var customFields = document.getElementById('ecb-custom-position-fields');
	var bottomInput = document.querySelector('input[name="chatbot_position_bottom"]');
	var leftInput = document.querySelector('input[name="chatbot_position_left"]');
	var rightInput = document.querySelector('input[name="chatbot_position_right"]');
	var bubbleImgInput = document.querySelector('input[name="chatbot_bubble_image_url"]');
	var brandingBgInput = document.querySelector('input[name="chatbot_branding_config[background_image]"]');
	var brandingAvatarInput = document.querySelector('input[name="chatbot_branding_config[bot_avatar]"]');
	var brandingBgColorInput = document.querySelector('input[name="chatbot_branding_config[bg_color]"]');
	var brandingBotBubbleInput = document.querySelector('input[name="chatbot_branding_config[bot_bubble_color]"]');
	var brandingUserBubbleInput = document.querySelector('input[name="chatbot_branding_config[user_bubble_color]"]');
	var brandingButtonInput = document.querySelector('input[name="chatbot_branding_config[button_color]"]');
	var brandingTextInput = document.querySelector('input[name="chatbot_branding_config[text_color]"]');
	var brandingShadowInput = document.querySelector('input[name="chatbot_branding_config[shadow_enabled]"]');
	var brandingShadowIntensityInput = document.querySelector('input[name="chatbot_branding_config[shadow_intensity]"]');
	var bubbleSizeInput = document.querySelector('input[name="chatbot_bubble_size"]');
	var preview = document.getElementById('ecb-theme-preview');
	var previewPanel = document.getElementById('ecb-theme-preview-panel');
	var previewFloat = document.getElementById('ecb-theme-preview-float');
	if (!preview || !previewPanel || !previewFloat) return;

	function paintPreview() {
		var accent = accentInput ? accentInput.value : '#111827';
		var visualStyle = visualStyleInput ? String(visualStyleInput.value || 'default') : 'default';
		var shadowEnabled = shadowInput ? !!shadowInput.checked : true;
		var user = userInput ? userInput.value : '#111827';
		var bot = botInput ? botInput.value : '#ffffff';
		var radius = Math.max(12, Math.min(28, parseInt(radiusInput ? radiusInput.value : '22', 10) || 22));
		var shape = shapeInput ? String(shapeInput.value || 'rounded') : 'rounded';
		var bubbleSize = Math.max(40, Math.min(88, parseInt(bubbleSizeInput ? bubbleSizeInput.value : '58', 10) || 58));
		var pos = posInput ? posInput.value : 'right';
		var bottom = Math.max(0, Math.min(240, parseInt(bottomInput ? bottomInput.value : '22', 10) || 22));
		var left = leftInput ? leftInput.value.trim() : '';
		var right = rightInput ? rightInput.value.trim() : '';
		var img = bubbleImgInput ? bubbleImgInput.value.trim() : '';
		var brandingBg = brandingBgInput ? brandingBgInput.value.trim() : '';
		var brandingText = brandingTextInput ? brandingTextInput.value : '#111827';
		var brandingBtn = brandingButtonInput ? brandingButtonInput.value : accent;
		var brandingBot = brandingBotBubbleInput ? brandingBotBubbleInput.value : bot;
		var brandingUser = brandingUserBubbleInput ? brandingUserBubbleInput.value : user;
		var brandingBgColor = brandingBgColorInput ? brandingBgColorInput.value : '#ffffff';
		var brandingShadowEnabled = brandingShadowInput ? !!brandingShadowInput.checked : true;
		var brandingShadowIntensity = Math.max(0, Math.min(24, parseInt(brandingShadowIntensityInput ? brandingShadowIntensityInput.value : '6', 10) || 6));

		preview.style.setProperty('--pv-accent', brandingBtn || accent);
		preview.style.setProperty('--pv-user', brandingUser || user);
		preview.style.setProperty('--pv-bot', brandingBot || bot);
		preview.style.setProperty('--pv-radius', radius + 'px');
		preview.style.setProperty('--pv-float-radius', shape === 'square' ? '0px' : (shape === 'rounded' ? '9999px' : radius + 'px'));
		preview.style.setProperty('--pv-bubble-size', bubbleSize + 'px');
		preview.style.background = '#f7f7f8';
		previewPanel.style.background = brandingBgColor || '#ffffff';
		previewPanel.style.borderColor = '#e5e7eb';
		previewPanel.style.boxShadow = shadowEnabled && brandingShadowEnabled ? ('0 4px ' + (brandingShadowIntensity + 6) + 'px rgba(0,0,0,0.06)') : 'none';
		previewFloat.style.boxShadow = shadowEnabled && brandingShadowEnabled ? ('0 4px ' + (brandingShadowIntensity + 8) + 'px rgba(0,0,0,0.12)') : 'none';
		previewPanel.style.color = brandingText || '#111827';
		if (brandingBg) {
			previewPanel.style.backgroundImage = 'url(' + brandingBg.replace(/"/g, '%22') + ')';
			previewPanel.style.backgroundSize = 'cover';
			previewPanel.style.backgroundPosition = 'center';
		} else {
			previewPanel.style.backgroundImage = 'none';
		}
		if (img) {
			previewFloat.style.backgroundImage = 'url(' + img.replace(/"/g, '%22') + ')';
			previewFloat.style.backgroundSize = 'cover';
			previewFloat.style.backgroundPosition = 'center';
			previewFloat.style.color = 'transparent';
			previewFloat.textContent = '';
		} else {
			previewFloat.style.backgroundImage = 'none';
			previewFloat.style.background = 'var(--pv-accent)';
			previewFloat.style.color = '#fff';
			previewFloat.textContent = '💬';
		}
		if (customFields) {
			customFields.style.display = pos === 'custom' ? 'grid' : 'none';
		}
		previewFloat.style.bottom = bottom + 'px';
		previewPanel.style.bottom = (bottom + bubbleSize + 20) + 'px';
		if (pos === 'left') {
			previewPanel.style.left = '14px'; previewPanel.style.right = 'auto';
			previewFloat.style.left = '14px'; previewFloat.style.right = 'auto';
		} else if (pos === 'custom') {
			if (left !== '') {
				previewPanel.style.left = left + 'px'; previewPanel.style.right = 'auto';
				previewFloat.style.left = left + 'px'; previewFloat.style.right = 'auto';
			} else {
				var effectiveRight = right !== '' ? right : '14';
				previewPanel.style.right = effectiveRight + 'px'; previewPanel.style.left = 'auto';
				previewFloat.style.right = effectiveRight + 'px'; previewFloat.style.left = 'auto';
			}
		} else {
			previewPanel.style.right = '14px'; previewPanel.style.left = 'auto';
			previewFloat.style.right = '14px'; previewFloat.style.left = 'auto';
		}
	}
	[accentInput, visualStyleInput, shadowInput, userInput, botInput, radiusInput, shapeInput, posInput, bottomInput, leftInput, rightInput, bubbleImgInput, bubbleSizeInput, brandingBgInput, brandingAvatarInput, brandingBgColorInput, brandingBotBubbleInput, brandingUserBubbleInput, brandingButtonInput, brandingTextInput, brandingShadowInput, brandingShadowIntensityInput].forEach(function(el){
		if (!el) return;
		el.addEventListener('input', paintPreview);
		el.addEventListener('change', paintPreview);
	});
	paintPreview();
})();
</script>
