<?php
/**
 * Módulo Login Personalizer - Estandar KickOff
 * Personaliza el login nativo de WordPress: fondo, logo, textos, párrafo y shortcode.
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Login_Personalizer {

	const OPTION_KEY = 'estandar_kickoff_login_personalizer';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'output_login_css' ] );
		add_filter( 'login_message', [ $this, 'inject_login_paragraph' ] );
		add_filter( 'login_headerurl', [ $this, 'login_logo_url' ] );
		add_filter( 'login_headertext', [ $this, 'login_logo_title' ] );
		add_filter( 'gettext', [ $this, 'replace_login_texts' ], 20, 3 );
		add_filter( 'gettext_with_context', [ $this, 'replace_login_texts_with_context' ], 20, 4 );
		add_shortcode( 'estandar_login', [ $this, 'render_login_shortcode' ] );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	public function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return $this->defaults();
		}

		$defaults = $this->defaults();
		$texts_default = $defaults['texts'];
		$allowed_keys = array_keys( $texts_default );

		$texts = [];
		foreach ( $allowed_keys as $k ) {
			$texts[ $k ] = isset( $value['texts'][ $k ] ) ? sanitize_text_field( (string) $value['texts'][ $k ] ) : '';
		}

		return [
			'bg_color'      => $this->sanitize_hex( $value['bg_color'] ?? '' ) ?: $defaults['bg_color'],
			'logo_url'      => esc_url_raw( $value['logo_url'] ?? '' ),
			'logo_width'    => max( 1, absint( $value['logo_width'] ?? 160 ) ),
			'bg_image'      => esc_url_raw( $value['bg_image'] ?? '' ),
			'overlay'       => ! empty( $value['overlay'] ) ? '1' : '0',
			'overlay_alpha' => (string) max( 0, min( 1, (float) ( $value['overlay_alpha'] ?? 0.55 ) ) ),
			'panel_bg'      => $this->sanitize_rgba( $value['panel_bg'] ?? '' ) ?: $defaults['panel_bg'],
			'paragraph'     => wp_kses_post( $value['paragraph'] ?? '' ),
			'texts'         => $texts,
		];
	}

	private function sanitize_hex( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) return '';
		if ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $value ) ) return $value;
		return '';
	}

	private function sanitize_rgba( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) return '';
		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(\s*,\s*(0|1|0?\.\d+))?\s*\)$/', $value ) ) return $value;
		if ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $value ) ) return $value;
		return '';
	}

	private function defaults() {
		return [
			'bg_color'      => '#0b1220',
			'logo_url'      => '',
			'logo_width'    => '160',
			'bg_image'      => '',
			'overlay'       => '1',
			'overlay_alpha' => '0.55',
			'panel_bg'      => 'rgba(255,255,255,0.92)',
			'paragraph'     => '',
			'texts'         => [
				'Username or Email Address' => '',
				'Password'                  => '',
				'Remember Me'               => '',
				'Log In'                    => '',
				'Lost your password?'       => '',
				'Back to %s'                => '',
				'Powered by WordPress'      => '',
				'Privacy Policy'            => '',
			],
		];
	}

	private function get_options() {
		$saved = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], $this->defaults() );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'estandar-kickoff' ) === false ) return;
		if ( ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '' ) !== 'login-personalizer' ) return;
		wp_enqueue_media();
	}

	/* ---------------------------
	 * Login: CSS
	 * --------------------------- */

	public function output_login_css() {
		$opts = $this->get_options();
		$bg_color   = $opts['bg_color'] ?: $this->defaults()['bg_color'];
		$bg_image   = $opts['bg_image'] ?? '';
		$overlay    = ( $opts['overlay'] === '1' );
		$alpha      = max( 0, min( 1, (float) ( $opts['overlay_alpha'] ?? 0.55 ) ) );
		$panel_bg   = $opts['panel_bg'] ?: $this->defaults()['panel_bg'];
		$logo_url   = $opts['logo_url'] ?? '';
		$logo_width = (int) ( $opts['logo_width'] ?? 160 );

		$bg_css = "background-color: {$bg_color};";
		if ( ! empty( $bg_image ) ) {
			$bg_css .= " background-image: url('" . esc_url( $bg_image ) . "'); background-size: cover; background-position: center; background-repeat: no-repeat;";
		}
		$overlay_css = $overlay ? "rgba(0,0,0,{$alpha})" : "transparent";
		?>
		<style>
			body.login { <?php echo $bg_css; ?> min-height: 100vh; }
			body.login:before {
				content: ""; position: fixed; inset: 0;
				background: <?php echo esc_html( $overlay_css ); ?>;
				pointer-events: none; z-index: 0;
			}
			body.login #login {
				position: relative; z-index: 1;
				width: min(360px, calc(100% - 32px));
				padding: 24px 0 16px;
			}
			body.login #loginform,
			body.login #lostpasswordform,
			body.login #registerform {
				background: <?php echo esc_html( $panel_bg ); ?>;
				border: 1px solid rgba(0,0,0,0.08);
				border-radius: 14px;
				box-shadow: 0 10px 30px rgba(0,0,0,0.18);
				padding: 22px 22px 18px;
			}
			body.login .message,
			body.login #login_error,
			body.login .kickoff-login-paragraph {
				position: relative; z-index: 1;
				border-radius: 12px;
				box-shadow: 0 10px 30px rgba(0,0,0,0.12);
			}
			body.login .kickoff-login-paragraph {
				background: rgba(255,255,255,0.92);
				border: 1px solid rgba(0,0,0,0.08);
				padding: 14px 16px; margin: 0 0 14px;
				font-size: 13px; line-height: 1.45;
			}
			body.login h1 a {
				background-image: none;
				<?php if ( ! empty( $logo_url ) ) : ?>
				background-image: url('<?php echo esc_url( $logo_url ); ?>');
				background-size: contain; background-repeat: no-repeat;
				width: <?php echo (int) $logo_width; ?>px; height: auto; max-height: none;
				aspect-ratio: 3 / 1; display: block; margin: 0 auto 16px;
				<?php endif; ?>
			}
			@media (max-width: 420px) {
				body.login #login { padding-top: 14px; }
				body.login #loginform,
				body.login #lostpasswordform,
				body.login #registerform {
					padding: 18px 16px 14px; border-radius: 12px;
				}
			}
		</style>
		<?php
	}

	public function inject_login_paragraph( $message ) {
		$opts = $this->get_options();
		$paragraph = trim( (string) ( $opts['paragraph'] ?? '' ) );
		if ( $paragraph === '' ) return $message;

		return '<div class="kickoff-login-paragraph">' . wpautop( $paragraph ) . '</div>' . $message;
	}

	public function login_logo_url( $url ) {
		return home_url( '/' );
	}

	public function login_logo_title( $title ) {
		return get_bloginfo( 'name' );
	}

	/* ---------------------------
	 * Login: text replacement
	 * --------------------------- */

	private function is_login_screen() {
		global $pagenow;
		return isset( $pagenow ) && $pagenow === 'wp-login.php';
	}

	public function replace_login_texts( $translated, $text, $domain ) {
		if ( ! $this->is_login_screen() ) return $translated;
		$opts = $this->get_options();
		$map  = isset( $opts['texts'] ) && is_array( $opts['texts'] ) ? $opts['texts'] : [];
		if ( array_key_exists( $text, $map ) && $map[ $text ] !== '' ) {
			return $map[ $text ];
		}
		return $translated;
	}

	public function replace_login_texts_with_context( $translated, $text, $context, $domain ) {
		return $this->replace_login_texts( $translated, $text, $domain );
	}

	/* ---------------------------
	 * Shortcode [estandar_login]
	 * --------------------------- */

	public function render_login_shortcode( $atts ) {
		if ( is_user_logged_in() ) return '';

		$atts = shortcode_atts( [
			'label' => __( 'Ingresar', 'estandar-kickoff' ),
			'mode'  => 'iframe',
		], (array) $atts, 'estandar_login' );

		$uid = 'kickoff-login-' . wp_generate_password( 8, false, false );
		ob_start();
		?>
		<button type="button" class="kickoff-login-open" data-target="<?php echo esc_attr( $uid ); ?>">
			<?php echo esc_html( $atts['label'] ); ?>
		</button>
		<div id="<?php echo esc_attr( $uid ); ?>" class="kickoff-login-modal" style="display:none;" aria-hidden="true">
			<div class="kickoff-login-backdrop" data-close="1"></div>
			<div class="kickoff-login-frame" role="dialog" aria-modal="true">
				<button type="button" class="kickoff-login-close" data-close="1" aria-label="<?php esc_attr_e( 'Cerrar', 'estandar-kickoff' ); ?>">×</button>
				<?php if ( $atts['mode'] === 'form' ) : ?>
					<div class="kickoff-login-inner">
						<?php
						echo wp_login_form( [
							'echo'            => false,
							'remember'       => true,
							'form_id'         => $uid . '-form',
							'label_username'  => '',
							'label_password'  => '',
							'label_remember'  => '',
							'label_log_in'    => '',
							'redirect'        => home_url( '/' ),
						] );
						?>
					</div>
				<?php else : ?>
					<iframe src="<?php echo esc_url( wp_login_url() ); ?>" frameborder="0" loading="lazy"></iframe>
				<?php endif; ?>
			</div>
		</div>
		<style>
			#<?php echo esc_attr( $uid ); ?>.kickoff-login-modal { position: fixed; inset: 0; z-index: 9999; }
			#<?php echo esc_attr( $uid ); ?> .kickoff-login-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
			#<?php echo esc_attr( $uid ); ?> .kickoff-login-frame {
				position: relative; max-width: 420px; height: 520px; margin: 5vh auto;
				background: #fff; border-radius: 12px; overflow: hidden; z-index: 1;
			}
			#<?php echo esc_attr( $uid ); ?> .kickoff-login-frame iframe { width: 100%; height: 100%; }
			#<?php echo esc_attr( $uid ); ?> .kickoff-login-inner { padding: 18px; }
			#<?php echo esc_attr( $uid ); ?> .kickoff-login-close {
				position: absolute; top: 8px; right: 10px; z-index: 2;
				background: none; border: none; font-size: 20px; cursor: pointer;
			}
			@media (max-width: 480px) {
				#<?php echo esc_attr( $uid ); ?> .kickoff-login-frame {
					max-width: calc(100% - 24px); height: min(560px, 85vh); margin: 6vh auto;
				}
			}
		</style>
		<script>
		(function(){
			var uid = <?php echo json_encode( $uid ); ?>;
			var modal = document.getElementById(uid);
			if (!modal) return;
			var openBtn = document.querySelector('.kickoff-login-open[data-target="'+uid+'"]');
			var closeEls = modal.querySelectorAll('[data-close="1"]');
			function openModal() { modal.style.display = 'block'; modal.setAttribute('aria-hidden','false'); }
			function closeModal() { modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); }
			if (openBtn) openBtn.addEventListener('click', openModal);
			closeEls.forEach(function(el){ el.addEventListener('click', closeModal); });
			document.addEventListener('keydown', function(e){
				if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------
	 * Admin: render tab
	 * --------------------------- */

	public static function render_tab() {
		$instance = self::get_instance();
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ajustes guardados.', 'estandar-kickoff' ) . '</p></div>';
		}
		$opts = $instance->get_options();
		$texts = isset( $opts['texts'] ) && is_array( $opts['texts'] ) ? $opts['texts'] : [];
		$defaults = $instance->defaults();
		?>
		<p class="description" style="margin-bottom: 20px;">
			<?php esc_html_e( 'Personaliza el login nativo de WordPress (wp-login.php): fondo, logo, textos y párrafo. Shortcode [estandar_login] para modal en frontend.', 'estandar-kickoff' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo del login', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="text" id="kickoff_logo_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logo_url]"
							value="<?php echo esc_attr( $opts['logo_url'] ); ?>" class="regular-text" placeholder="https://...">
						<button type="button" class="button" id="kickoff_logo_pick"><?php esc_html_e( 'Seleccionar logo', 'estandar-kickoff' ); ?></button>
						<button type="button" class="button" id="kickoff_logo_clear"><?php esc_html_e( 'Quitar', 'estandar-kickoff' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ancho del logo (px)', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logo_width]"
							value="<?php echo esc_attr( $opts['logo_width'] ); ?>" class="small-text"> px
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kickoff_bg_color"><?php esc_html_e( 'Color de fondo', 'estandar-kickoff' ); ?></label></th>
					<td>
						<input type="text" id="kickoff_bg_color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bg_color]"
							value="<?php echo esc_attr( $opts['bg_color'] ); ?>" class="regular-text" placeholder="#0b1220">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Imagen de fondo', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="text" id="kickoff_bg_image" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bg_image]"
							value="<?php echo esc_attr( $opts['bg_image'] ); ?>" class="regular-text" placeholder="https://...">
						<button type="button" class="button" id="kickoff_bg_image_pick"><?php esc_html_e( 'Seleccionar imagen', 'estandar-kickoff' ); ?></button>
						<button type="button" class="button" id="kickoff_bg_image_clear"><?php esc_html_e( 'Quitar', 'estandar-kickoff' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Overlay oscuro', 'estandar-kickoff' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overlay]" value="0">
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overlay]" value="1" <?php checked( $opts['overlay'], '1' ); ?>>
							<?php esc_html_e( 'Activar overlay para mejorar legibilidad', 'estandar-kickoff' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kickoff_overlay_alpha"><?php esc_html_e( 'Intensidad overlay', 'estandar-kickoff' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="0" max="1" id="kickoff_overlay_alpha"
							name="<?php echo esc_attr( self::OPTION_KEY ); ?>[overlay_alpha]"
							value="<?php echo esc_attr( $opts['overlay_alpha'] ); ?>" class="small-text"> (0–1)
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kickoff_panel_bg"><?php esc_html_e( 'Fondo panel (formulario)', 'estandar-kickoff' ); ?></label></th>
					<td>
						<input type="text" id="kickoff_panel_bg" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[panel_bg]"
							value="<?php echo esc_attr( $opts['panel_bg'] ); ?>" class="regular-text" placeholder="rgba(255,255,255,0.92)">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kickoff_paragraph"><?php esc_html_e( 'Párrafo explicativo', 'estandar-kickoff' ); ?></label></th>
					<td>
						<textarea id="kickoff_paragraph" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[paragraph]"
							rows="4" class="large-text"><?php echo esc_textarea( $opts['paragraph'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Se muestra sobre el formulario. Permite HTML básico seguro.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
			</table>

			<hr>
			<h2><?php esc_html_e( 'Reemplazo de textos del login', 'estandar-kickoff' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deja vacío para mantener el texto original.', 'estandar-kickoff' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $defaults['texts'] as $original => $_ ) : ?>
					<tr>
						<th scope="row"><label><?php echo esc_html( $original ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[texts][<?php echo esc_attr( $original ); ?>]"
								value="<?php echo esc_attr( $texts[ $original ] ?? '' ); ?>" class="regular-text" placeholder="(sin cambio)">
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button(); ?>
		</form>

		<p class="description" style="margin-top: 20px;">
			<?php esc_html_e( 'Shortcode:', 'estandar-kickoff' ); ?> <code>[estandar_login label="Ingresar" mode="iframe"]</code>
			<?php esc_html_e( '(mode: iframe o form)', 'estandar-kickoff' ); ?>
		</p>

		<script>
		(function(){
			function initMedia(fieldId, pickId, clearId) {
				var field = document.getElementById(fieldId);
				var pick = document.getElementById(pickId);
				var clear = document.getElementById(clearId);
				if (!field || !pick || !window.wp || !wp.media) return;
				var frame = null;
				pick.addEventListener('click', function(){
					if (frame) { frame.open(); return; }
					frame = wp.media({ title: 'Seleccionar', button: { text: 'Usar' }, multiple: false });
					frame.on('select', function(){
						var att = frame.state().get('selection').first().toJSON();
						if (att && att.url) field.value = att.url;
					});
					frame.open();
				});
				if (clear) clear.addEventListener('click', function(){ field.value = ''; });
			}
			initMedia('kickoff_logo_url', 'kickoff_logo_pick', 'kickoff_logo_clear');
			initMedia('kickoff_bg_image', 'kickoff_bg_image_pick', 'kickoff_bg_image_clear');
		})();
		</script>
		<?php
	}
}
