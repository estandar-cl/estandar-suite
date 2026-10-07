<?php
/**
 * Módulo Snippets - Estandar KickOff
 * Inyecta fragmentos de código (HTML, CSS, JS) en secciones de la página.
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Snippets {

	const OPTION_KEY = 'estandar_kickoff_snippets';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_head', [ $this, 'output_head' ], 999 );
		add_action( 'wp_body_open', [ $this, 'output_body_open' ], 1 );
		add_action( 'wp_footer', [ $this, 'output_footer' ], 999 );
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

		$can_unfiltered = current_user_can( 'unfiltered_html' );
		$sections = [ 'head', 'body_open', 'footer' ];

		$out = [];
		foreach ( $sections as $section ) {
			$content = isset( $value[ $section ] ) ? (string) $value[ $section ] : '';
			$content = wp_unslash( $content );
			if ( $can_unfiltered ) {
				$out[ $section ] = $content;
			} else {
				$out[ $section ] = wp_kses_post( $content );
			}
		}

		return wp_parse_args( $out, $this->defaults() );
	}

	private function defaults() {
		return [
			'head'      => '',
			'body_open' => '',
			'footer'    => '',
		];
	}

	private function get_snippets() {
		$saved = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], $this->defaults() );
	}

	private function output_snippet( $content ) {
		$content = trim( $content );
		if ( $content === '' ) return;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Snippets are admin-defined code
		echo "\n" . $content . "\n";
	}

	public function output_head() {
		$snippets = $this->get_snippets();
		$this->output_snippet( $snippets['head'] );
	}

	public function output_body_open() {
		$snippets = $this->get_snippets();
		$this->output_snippet( $snippets['body_open'] );
	}

	public function output_footer() {
		$snippets = $this->get_snippets();
		$this->output_snippet( $snippets['footer'] );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$snippets = $instance->get_snippets();

		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Snippets guardados.', 'estandar-kickoff' ) . '</p></div>';
		}
		?>
		<p class="description" style="margin-bottom: 20px;">
			<?php esc_html_e( 'Añade HTML, CSS o JavaScript en distintas secciones de la página. Útil para analytics, meta tags, scripts de terceros, etc.', 'estandar-kickoff' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="snippet_head"><?php esc_html_e( 'Head (wp_head)', 'estandar-kickoff' ); ?></label>
					</th>
					<td>
						<p class="description" style="margin-bottom: 8px;">
							<?php esc_html_e( 'Se inserta dentro de &lt;head&gt;, antes de &lt;/head&gt;. Ideal para meta tags, CSS, scripts de carga temprana.', 'estandar-kickoff' ); ?>
						</p>
						<textarea id="snippet_head" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[head]"
							rows="8" class="large-text code" placeholder="<!-- Analytics, meta tags, etc. -->"><?php echo esc_textarea( $snippets['head'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="snippet_body_open"><?php esc_html_e( 'Inicio del body (wp_body_open)', 'estandar-kickoff' ); ?></label>
					</th>
					<td>
						<p class="description" style="margin-bottom: 8px;">
							<?php esc_html_e( 'Se inserta justo después de &lt;body&gt;. Requiere que el tema use wp_body_open().', 'estandar-kickoff' ); ?>
						</p>
						<textarea id="snippet_body_open" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[body_open]"
							rows="6" class="large-text code" placeholder="<!-- Ej: Google Tag Manager noscript -->"><?php echo esc_textarea( $snippets['body_open'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="snippet_footer"><?php esc_html_e( 'Footer (wp_footer)', 'estandar-kickoff' ); ?></label>
					</th>
					<td>
						<p class="description" style="margin-bottom: 8px;">
							<?php esc_html_e( 'Se inserta antes de &lt;/body&gt;. Ideal para analytics, chat widgets, scripts de terceros.', 'estandar-kickoff' ); ?>
						</p>
						<textarea id="snippet_footer" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[footer]"
							rows="8" class="large-text code" placeholder="<!-- Google Analytics, Facebook Pixel, etc. -->"><?php echo esc_textarea( $snippets['footer'] ); ?></textarea>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<div class="kickoff-snippets-info" style="margin-top: 24px; padding: 16px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Ejemplos', 'estandar-kickoff' ); ?></h3>
			<ul style="margin: 0 0 0 20px;">
				<li><strong>Head:</strong> <code>&lt;meta name="theme-color" content="#0b1220"&gt;</code></li>
				<li><strong>Head:</strong> <code>&lt;link rel="preconnect" href="https://fonts.googleapis.com"&gt;</code></li>
				<li><strong>Footer:</strong> Google Analytics (gtag.js o gtag), Facebook Pixel, Hotjar, etc.</li>
				<li><strong>Body open:</strong> Google Tag Manager noscript fallback</li>
			</ul>
		</div>
		<?php
	}
}
