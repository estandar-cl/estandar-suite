<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Image_Delivery {

	const OPTION_KEY = 'estandar_kickoff_image_delivery_enabled';

	private static $instance = null;
	private $eager_assigned = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		if ( $this->is_enabled() && $this->is_frontend_request() ) {
			add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_attachment_image_attributes' ], 20, 3 );
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
		$value = function_exists( 'kickoff_get_option' ) ? kickoff_get_option( self::OPTION_KEY, '1' ) : get_option( self::OPTION_KEY, '1' );
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

	private function has_carousel_loading_logic( array $attr ) {
		$class = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		return false !== strpos( $class, 'estandar-carousel__image' ) && ! empty( $attr['loading'] );
	}

	private function is_meaningful_content_image( array $attr ) {
		$min_dimension = 150;
		$width         = isset( $attr['width'] ) ? absint( $attr['width'] ) : 0;
		$height        = isset( $attr['height'] ) ? absint( $attr['height'] ) : 0;

		if ( $width > 0 && $width < $min_dimension ) {
			return false;
		}

		if ( $height > 0 && $height < $min_dimension ) {
			return false;
		}

		return true;
	}

	private function set_lazy_delivery_attributes( array $attr ) {
		$attr['loading'] = 'lazy';

		if ( isset( $attr['fetchpriority'] ) && 'high' === strtolower( (string) $attr['fetchpriority'] ) ) {
			unset( $attr['fetchpriority'] );
		}

		return $attr;
	}

	/**
	 * Adjust only attributes produced by wp_get_attachment_image().
	 *
	 * @param array<string,mixed> $attr
	 * @param WP_Post            $attachment
	 * @param string|int[]       $size
	 * @return array<string,mixed>
	 */
	public function filter_attachment_image_attributes( $attr, $attachment, $size ) {
		if ( ! is_array( $attr ) || ! $this->is_frontend_request() ) {
			return $attr;
		}
		if ( $this->has_carousel_loading_logic( $attr ) ) {
			return $attr;
		}

		if ( empty( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}

		if ( ! $this->is_meaningful_content_image( $attr ) ) {
			return $this->set_lazy_delivery_attributes( $attr );
		}

		if ( ! $this->eager_assigned ) {
			$this->eager_assigned = true;
			$attr['loading'] = 'eager';
			$attr['fetchpriority'] = 'high';
			return $attr;
		}

		return $this->set_lazy_delivery_attributes( $attr );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$enabled = $instance->is_enabled();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Optimización de entrega de imágenes', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" value="0" />
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="1"
								<?php checked( $enabled ); ?>
							/>
							<?php esc_html_e( 'Activar optimización de entrega de imágenes', 'estandar-kickoff' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Mejora atributos de carga de imágenes en frontend sin modificar archivos ni contenido.', 'estandar-kickoff' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
