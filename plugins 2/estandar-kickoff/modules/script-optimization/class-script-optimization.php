<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Script_Optimization {

	const OPTION_KEY = 'estandar_kickoff_script_defer_enabled';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		if ( $this->is_enabled() && $this->is_frontend_request() ) {
			add_filter( 'script_loader_tag', [ $this, 'filter_script_loader_tag' ], 20, 3 );
		}
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'string',
			'sanitize_callback' => function ( $value ) {
				return ! empty( $value ) ? '1' : '0';
			},
			'default'           => '0',
		] );
	}

	private function is_enabled() {
		$value = get_option( self::OPTION_KEY, '0' );
		return '1' === $value || true === $value;
	}

	private function is_frontend_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		global $pagenow;
		return ! isset( $pagenow ) || 'wp-login.php' !== $pagenow;
	}

	private function get_allowed_handles() {
		return [
			'estandar-chatbot-loader',
			'estandar-carousel',
			'estandar-forms-rut',
			'estandar-simple-popup',
		];
	}

	private function is_allowed_handle( $handle ) {
		$allowed = array_map( 'strval', (array) $this->get_allowed_handles() );
		return in_array( (string) $handle, $allowed, true );
	}

	private function has_excluded_token( $handle ) {
		$handle = strtolower( (string) $handle );
		foreach ( [ 'jquery', 'wp-', 'core' ] as $token ) {
			if ( false !== strpos( $handle, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds defer only to explicitly allowed frontend scripts.
	 */
	public function filter_script_loader_tag( $tag, $handle, $src ) {
		if ( ! is_string( $tag ) || '' === $tag || ! $this->is_frontend_request() ) {
			return $tag;
		}
		if ( $this->has_excluded_token( $handle ) || ! $this->is_allowed_handle( $handle ) ) {
			return $tag;
		}
		if ( preg_match( '/\s(?:defer|async)(?:\s|=|>)/i', $tag ) ) {
			return $tag;
		}

		$updated = preg_replace( '/^<script\b/i', '<script defer', $tag, 1 );
		return is_string( $updated ) && '' !== $updated ? $updated : $tag;
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$enabled = $instance->is_enabled();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Optimización de scripts (defer)', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" value="0" />
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="1"
								<?php checked( $enabled ); ?>
							/>
							<?php esc_html_e( 'Activar defer en scripts permitidos', 'estandar-kickoff' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Aplica defer solo a scripts no críticos permitidos. No usa async, no cambia orden y no modifica dependencias.', 'estandar-kickoff' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
