<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Security {

	const OPTION_KEY = 'estandar_kickoff_security';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'init', [ $this, 'apply_security' ], 1 );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				$v = is_array( $v ) ? $v : [];
				return [
					'login_limit'       => ! empty( $v['login_limit'] ),
					'disable_xmlrpc'    => ! empty( $v['disable_xmlrpc'] ),
					'secure_headers'    => ! empty( $v['secure_headers'] ),
					'advanced_rate'     => ! empty( $v['advanced_rate'] ),
					'two_factor_hook'   => ! empty( $v['two_factor_hook'] ),
					'hide_version'      => ! empty( $v['hide_version'] ),
					'remove_wp_version' => false,
				];
			},
		] );
	}

	private function get_settings() {
		$defaults = [
			'login_limit'       => true,
			'disable_xmlrpc'    => true,
			'secure_headers'    => true,
			'advanced_rate'     => false,
			'two_factor_hook'   => false,
			'hide_version'      => true,
			'remove_wp_version' => false,
		];
		$s = function_exists( 'kickoff_get_option' ) ? kickoff_get_option( self::OPTION_KEY, $defaults ) : get_option( self::OPTION_KEY, $defaults );
		return wp_parse_args( $s, [
			'login_limit'       => true,
			'disable_xmlrpc'    => true,
			'secure_headers'    => true,
			'advanced_rate'     => false,
			'two_factor_hook'   => false,
			'hide_version'      => true,
			'remove_wp_version' => false,
		] );
	}

	public function apply_security() {
		$s = $this->get_settings();

		if ( $s['disable_xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', [ $this, 'filter_xmlrpc_enabled' ] );
			add_filter( 'wp_headers', [ $this, 'remove_x_pingback' ] );
		}
		if ( $s['login_limit'] ) {
			add_filter( 'authenticate', [ $this, 'limit_login_attempts' ], 30, 3 );
			add_action( 'wp_login_failed', [ $this, 'record_failed_login' ] );
			add_action( 'wp_login', [ $this, 'clear_login_attempts' ], 10, 2 );
		}
		if ( $s['secure_headers'] ) {
			add_action( 'send_headers', [ $this, 'send_secure_headers' ] );
		}
		if ( $s['advanced_rate'] ) {
			add_action( 'login_init', [ $this, 'maybe_rate_limit_login_request' ] );
		}
		if ( $s['two_factor_hook'] ) {
			add_filter( 'authenticate', [ $this, 'apply_two_factor_extension_hook' ], 50, 3 );
		}
		if ( $s['hide_version'] ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	public function filter_xmlrpc_enabled( $enabled ) {
		if ( apply_filters( 'estandar_kickoff_allow_xmlrpc', false ) ) {
			return true;
		}

		return false;
	}

	public function remove_x_pingback( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function remove_version_query( $src ) {
		return $src ? remove_query_arg( 'ver', $src ) : $src;
	}

	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	private function get_login_attempt_key() {
		return 'estandar_kickoff_login_' . hash( 'sha256', $this->get_client_ip() );
	}

	private function get_rate_limit_key() {
		return 'estandar_kickoff_rate_' . hash( 'sha256', $this->get_client_ip() );
	}

	public function limit_login_attempts( $user, $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$attempts = (int) get_transient( $this->get_login_attempt_key() );
		if ( $attempts >= 5 ) {
			return new WP_Error(
				'estandar_kickoff_login_locked',
				__( 'Demasiados intentos fallidos. Intenta nuevamente en 10 minutos.', 'estandar-kickoff' )
			);
		}

		return $user;
	}

	public function record_failed_login( $username ) {
		$key      = $this->get_login_attempt_key();
		$attempts = (int) get_transient( $key );
		set_transient( $key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
	}

	public function clear_login_attempts( $user_login, $user ) {
		delete_transient( $this->get_login_attempt_key() );
	}

	public function maybe_rate_limit_login_request() {
		$key      = $this->get_rate_limit_key();
		$requests = (int) get_transient( $key );

		if ( $requests >= 20 ) {
			wp_die(
				esc_html__( 'Too many requests.', 'estandar-kickoff' ),
				esc_html__( 'Too many requests', 'estandar-kickoff' ),
				[ 'response' => 429 ]
			);
		}

		set_transient( $key, $requests + 1, MINUTE_IN_SECONDS );
	}

	private function has_header( $name ) {
		$name = strtolower( rtrim( $name, ':' ) );
		foreach ( headers_list() as $header ) {
			if ( 0 === strpos( strtolower( $header ), $name . ':' ) ) {
				return true;
			}
		}

		return false;
	}

	public function send_secure_headers() {
		if ( headers_sent() ) {
			return;
		}

		$headers = [
			'X-Frame-Options'           => 'SAMEORIGIN',
			'X-Content-Type-Options'    => 'nosniff',
			'Referrer-Policy'           => 'no-referrer-when-downgrade',
			'X-XSS-Protection'          => '1; mode=block',
		];

		foreach ( $headers as $name => $value ) {
			if ( ! $this->has_header( $name ) ) {
				header( $name . ': ' . $value );
			}
		}
	}

	public function apply_two_factor_extension_hook( $user, $username, $password ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}

		/*
		 * Extension point only: this does not add 2FA by itself.
		 * Future integrations may return WP_Error here to explicitly block login
		 * after running their own challenge flow.
		 */
		$result = apply_filters( 'estandar_kickoff_2fa_user_requires_challenge', false, $user, $username );

		return is_wp_error( $result ) ? $result : $user;
	}

	private static function render_badge( $label, $class = 'estandar-safe' ) {
		echo '<span class="estandar-badge ' . esc_attr( $class ) . '" style="margin-left:8px;">' . esc_html( $label ) . '</span>';
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$s = $instance->get_settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Protección de login', 'estandar-kickoff' ); ?><?php self::render_badge( __( 'Seguro', 'estandar-kickoff' ) ); ?></th>
					<td>
						<label style="display:block;">
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_limit]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_limit]" value="1" <?php checked( $s['login_limit'] ); ?> />
							<?php esc_html_e( 'Limitar intentos fallidos por IP (5 intentos / 10 minutos)', 'estandar-kickoff' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Usa transients y devuelve WP_Error cuando el IP está bloqueado temporalmente.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'XML-RPC', 'estandar-kickoff' ); ?><?php self::render_badge( __( 'Seguro', 'estandar-kickoff' ) ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_xmlrpc]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_xmlrpc]" value="1" <?php checked( $s['disable_xmlrpc'] ); ?> />
							<?php esc_html_e( 'Bloquear XML-RPC (reduce superficie de ataque)', 'estandar-kickoff' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Integraciones pueden permitirlo con el filtro estandar_kickoff_allow_xmlrpc.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Headers seguros', 'estandar-kickoff' ); ?><?php self::render_badge( __( 'Seguro', 'estandar-kickoff' ) ); ?></th>
					<td>
						<label style="display:block;">
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[secure_headers]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[secure_headers]" value="1" <?php checked( $s['secure_headers'] ); ?> />
							<?php esc_html_e( 'Enviar headers conservadores sin CSP', 'estandar-kickoff' ); ?>
						</label>
						<label style="display:block; margin-top:8px;">
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_version]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_version]" value="1" <?php checked( $s['hide_version'] ); ?> />
							<?php esc_html_e( 'Quitar meta generator del head', 'estandar-kickoff' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Rate limit avanzado', 'estandar-kickoff' ); ?><?php self::render_badge( __( 'Cuidado', 'estandar-kickoff' ), 'estandar-warning' ); ?></th>
					<td>
						<label style="display:block;">
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[advanced_rate]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[advanced_rate]" value="1" <?php checked( $s['advanced_rate'] ); ?> />
							<?php esc_html_e( 'Limitar wp-login.php a 20 requests por minuto', 'estandar-kickoff' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Desactivado por defecto. No aplica a REST API, admin-ajax ni formularios frontend.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Integraciones / 2FA', 'estandar-kickoff' ); ?><?php self::render_badge( __( 'Avanzado', 'estandar-kickoff' ), 'estandar-warning' ); ?></th>
					<td>
						<label style="display:block;">
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[two_factor_hook]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[two_factor_hook]" value="1" <?php checked( $s['two_factor_hook'] ); ?> />
							<?php esc_html_e( 'Activar hook futuro para desafíos 2FA', 'estandar-kickoff' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'No implementa 2FA por sí solo; solo permite que una integración futura bloquee explícitamente con WP_Error.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
