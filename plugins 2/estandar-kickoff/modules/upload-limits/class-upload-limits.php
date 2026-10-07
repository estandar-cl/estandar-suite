<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Upload_Limits {

	const OPTION_KEY = 'estandar_kickoff_upload_limits';
	const USER_INI_BEGIN = '; BEGIN Estandar KickOff Upload Limits';
	const USER_INI_END   = '; END Estandar KickOff Upload Limits';
	const HTACCESS_BEGIN = '# BEGIN Estandar KickOff Upload Limits';
	const HTACCESS_END   = '# END Estandar KickOff Upload Limits';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'sync_user_ini' ], 20 );
		add_filter( 'upload_size_limit', [ $this, 'filter_upload_limit' ], 999 );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
		] );
	}

	public function sanitize_settings( $v ) {
		$mb = absint( $v['max_mb'] ?? 64 );
		$mb = min( 512, max( 1, $mb ) );

		$settings = [
			'enabled' => ! empty( $v['enabled'] ),
			'max_mb'  => $mb,
		];

		$this->write_server_config( $settings );

		return $settings;
	}

	private function get_settings() {
		$s = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( $s, [
			'enabled' => false,
			'max_mb'  => 64,
		] );
	}

	private function get_desired_bytes() {
		$s = $this->get_settings();
		return absint( $s['max_mb'] ) * MB_IN_BYTES;
	}

	private function get_php_ceiling_bytes() {
		$limits = array_filter( [
			wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ),
			wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
		] );

		return empty( $limits ) ? PHP_INT_MAX : min( $limits );
	}

	private function get_user_ini_path() {
		return trailingslashit( ABSPATH ) . '.user.ini';
	}

	private function get_user_ini_block( array $settings ) {
		$upload = absint( $settings['max_mb'] ) . 'M';
		$post   = max( absint( $settings['max_mb'] ) + 8, absint( $settings['max_mb'] ) ) . 'M';

		return implode( PHP_EOL, [
			self::USER_INI_BEGIN,
			'upload_max_filesize = ' . $upload,
			'post_max_size = ' . $post,
			self::USER_INI_END,
		] );
	}

	private function get_htaccess_path() {
		return trailingslashit( ABSPATH ) . '.htaccess';
	}

	private function get_htaccess_block( array $settings ) {
		$upload = absint( $settings['max_mb'] ) . 'M';
		$post   = max( absint( $settings['max_mb'] ) + 8, absint( $settings['max_mb'] ) ) . 'M';

		return implode( PHP_EOL, [
			self::HTACCESS_BEGIN,
			'<IfModule mod_php.c>',
			'php_value upload_max_filesize ' . $upload,
			'php_value post_max_size ' . $post,
			'</IfModule>',
			self::HTACCESS_END,
		] );
	}

	private function replace_marked_block( $contents, $begin, $end, $block ) {
		$pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\\R?/s';
		$contents = preg_replace( $pattern, '', (string) $contents );
		$contents = rtrim( $contents );

		if ( '' === $block ) {
			return '' === $contents ? '' : $contents . PHP_EOL;
		}

		return ( '' === $contents ? '' : $contents . PHP_EOL . PHP_EOL ) . $block . PHP_EOL;
	}

	private function write_marked_file( $path, $begin, $end, $block ) {
		$old  = file_exists( $path ) ? file_get_contents( $path ) : '';
		$new  = $this->replace_marked_block( $old, $begin, $end, $block );

		if ( '' === $new && file_exists( $path ) ) {
			return is_writable( $path ) && unlink( $path );
		}

		if ( ! file_exists( $path ) && ! is_writable( ABSPATH ) ) {
			return false;
		}

		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return false;
		}

		return false !== file_put_contents( $path, $new, LOCK_EX );
	}

	private function write_server_config( array $settings ) {
		$enabled = ! empty( $settings['enabled'] );

		$this->write_marked_file(
			$this->get_user_ini_path(),
			self::USER_INI_BEGIN,
			self::USER_INI_END,
			$enabled ? $this->get_user_ini_block( $settings ) : ''
		);

		if ( 'apache2handler' === php_sapi_name() ) {
			$this->write_marked_file(
				$this->get_htaccess_path(),
				self::HTACCESS_BEGIN,
				self::HTACCESS_END,
				$enabled ? $this->get_htaccess_block( $settings ) : ''
			);
		}
	}

	public function sync_user_ini() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->write_server_config( $this->get_settings() );
	}

	public function filter_upload_limit( $size ) {
		$s = $this->get_settings();
		if ( ! $s['enabled'] ) return $size;

		$desired = $this->get_desired_bytes();
		$ceiling = $this->get_php_ceiling_bytes();

		return min( $size, $desired, $ceiling );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$s = $instance->get_settings();
		$current = wp_max_upload_size();
		$desired = $instance->get_desired_bytes();
		$ceiling = $instance->get_php_ceiling_bytes();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Activar', 'estandar-kickoff' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> />
								<?php esc_html_e( 'Aumentar límite de subida', 'estandar-kickoff' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="ul_mb"><?php esc_html_e( 'Máximo (MB)', 'estandar-kickoff' ); ?></label></th>
						<td>
							<input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_mb]" id="ul_mb" value="<?php echo esc_attr( $s['max_mb'] ); ?>" min="1" max="512" />
							<p class="description"><?php printf( esc_html__( 'Límite efectivo de WordPress: %s', 'estandar-kickoff' ), esc_html( size_format( $current ) ) ); ?></p>
							<p class="description"><?php printf( esc_html__( 'Techo PHP/hosting detectado: %s', 'estandar-kickoff' ), esc_html( size_format( $ceiling ) ) ); ?></p>
							<?php if ( $s['enabled'] && $desired > $ceiling ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'El valor configurado supera el techo real del hosting. KickOff no forzará un límite falso para evitar errores de subida.', 'estandar-kickoff' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<p class="description"><?php esc_html_e( 'Nota: algunos hostings bloquean estos límites a nivel PHP/FPM/panel. En ese caso el módulo sólo puede aplicar el máximo real disponible.', 'estandar-kickoff' ); ?></p>
						</td>
					</tr>
				</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
