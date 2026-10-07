<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Clean_Head {

	const OPTION_KEY = 'estandar_kickoff_clean_head';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'init', [ $this, 'apply_cleanups' ], 1 );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return [
					'enabled'         => ! empty( $v['enabled'] ),
					'remove_version' => ! empty( $v['remove_version'] ),
					'remove_rsd'      => ! empty( $v['remove_rsd'] ),
					'remove_wlw'      => ! empty( $v['remove_wlw'] ),
					'remove_oembed'   => ! empty( $v['remove_oembed'] ),
					'remove_shortlink'=> ! empty( $v['remove_shortlink'] ),
					'disable_emojis'  => ! empty( $v['disable_emojis'] ),
					'remove_rest_link' => false,
				];
			},
		] );
	}

	private function get_settings() {
		$defaults = [
			'enabled'          => true,
			'remove_version'   => true,
			'remove_rsd'       => true,
			'remove_wlw'       => true,
			'remove_oembed'    => true,
			'remove_shortlink' => true,
			'disable_emojis'   => true,
			'remove_rest_link' => false,
		];
		$s = function_exists( 'kickoff_get_option' ) ? kickoff_get_option( self::OPTION_KEY, $defaults ) : get_option( self::OPTION_KEY, $defaults );
		return wp_parse_args( $s, [
			'enabled'          => true,
			'remove_version'   => true,
			'remove_rsd'       => true,
			'remove_wlw'       => true,
			'remove_oembed'    => true,
			'remove_shortlink' => true,
			'disable_emojis'   => true,
			'remove_rest_link' => false,
		] );
	}

	public function apply_cleanups() {
		$s = $this->get_settings();
		if ( ! $s['enabled'] ) return;

		if ( $s['remove_version'] ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $s['remove_rsd'] ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $s['remove_wlw'] ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( $s['remove_oembed'] ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
		if ( $s['remove_shortlink'] ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}
		if ( $s['disable_emojis'] ) {
			$this->disable_emojis();
		}
	}

	private function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', [ $this, 'disable_emojis_tinymce' ] );
		add_filter( 'wp_resource_hints', [ $this, 'remove_emoji_dns_prefetch' ], 10, 2 );
	}

	public function disable_emojis_tinymce( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
	}

	public function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( $relation_type === 'dns-prefetch' ) {
			$urls = array_filter( $urls, function ( $u ) {
				return strpos( $u, 'https://s.w.org/images/core/emoji' ) === false;
			} );
		}
		return $urls;
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$s = $instance->get_settings();
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
							<?php esc_html_e( 'Aplicar limpieza del head', 'estandar-kickoff' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Elementos a quitar', 'estandar-kickoff' ); ?></th>
					<td>
						<?php
						$opts = [
							'remove_version'   => __( 'Meta versión de WordPress', 'estandar-kickoff' ),
							'remove_rsd'      => __( 'RSD link', 'estandar-kickoff' ),
							'remove_wlw'       => __( 'WLW manifest', 'estandar-kickoff' ),
							'remove_oembed'    => __( 'oEmbed discovery', 'estandar-kickoff' ),
							'remove_shortlink' => __( 'Shortlink', 'estandar-kickoff' ),
							'disable_emojis'  => __( 'Desactivar emojis (scripts y estilos)', 'estandar-kickoff' ),
						];
						foreach ( $opts as $key => $label ) :
							?>
							<label style="display:block;">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
