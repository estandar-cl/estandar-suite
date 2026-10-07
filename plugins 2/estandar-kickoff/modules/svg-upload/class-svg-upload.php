<?php
/**
 * Módulo SVG Upload - Estandar KickOff
 * Sanitiza SVG para prevenir XSS (script, event handlers, javascript:).
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_SVG_Upload {

	const OPTION_KEY = 'estandar_kickoff_svg_upload_enabled';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'upload_mimes', [ $this, 'allow_svg_mime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_svg_mime_type' ], 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitize_svg_upload' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return ! empty( $v ) ? '1' : '0';
			},
		] );
	}

	public function is_enabled() {
		$val = function_exists( 'kickoff_get_option' ) ? kickoff_get_option( self::OPTION_KEY, '1' ) : get_option( self::OPTION_KEY, '1' );
		return $val === '1' || $val === true;
	}

	/**
	 * Añade SVG a los tipos MIME permitidos.
	 */
	public function allow_svg_mime( $mimes ) {
		if ( ! $this->is_enabled() ) {
			return $mimes;
		}

		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';

		return apply_filters( 'estandar_kickoff_svg_allowed_mimes', $mimes );
	}

	/**
	 * Corrige la detección de tipo para SVG (WordPress a veces no lo reconoce).
	 */
	public function fix_svg_mime_type( $data, $file, $filename, $mimes ) {
		if ( ! $this->is_enabled() ) {
			return $data;
		}

		$ext = pathinfo( $filename, PATHINFO_EXTENSION );
		if ( strtolower( $ext ) === 'svg' ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
			$data['proper_filename'] = $filename;
		}

		return $data;
	}

	/**
	 * Sanitiza el contenido SVG antes de guardarlo.
	 */
	public function sanitize_svg_upload( $file ) {
		if ( ! $this->is_enabled() ) {
			return $file;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		// svgz (gzipped SVG) cannot be safely sanitized without decompressing.
		// Reject explicitly to prevent unsanitized XSS payloads from passing through.
		if ( $ext === 'svgz' ) {
			$file['error'] = __( 'Los archivos SVGZ no están permitidos por razones de seguridad. Sube el archivo SVG sin comprimir.', 'estandar-kickoff' );
			return $file;
		}

		if ( $ext !== 'svg' ) {
			return $file;
		}

		$content = file_get_contents( $file['tmp_name'] );
		if ( $content === false ) {
			$file['error'] = __( 'No se pudo leer el archivo SVG.', 'estandar-kickoff' );
			return $file;
		}

		$sanitized = $this->sanitize_svg_content( $content );
		if ( $sanitized === false ) {
			$file['error'] = __( 'El archivo SVG contiene contenido no permitido y ha sido rechazado por seguridad.', 'estandar-kickoff' );
			return $file;
		}

		file_put_contents( $file['tmp_name'], $sanitized );

		return $file;
	}

	/**
	 * Sanitiza el contenido SVG para eliminar vectores de XSS.
	 * - Elimina <script> y contenido
	 * - Elimina atributos on* (onclick, onload, etc.)
	 * - Elimina javascript: en href/xlink:href
	 * - Elimina data: con javascript
	 * - Elimina <iframe>, <object>, <embed>
	 */
	private function sanitize_svg_content( $content ) {
		// Verificar que sea XML/SVG válido (sin cargar DTD ni expandir entidades para mitigar XXE)
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$options = defined( 'LIBXML_NOXXE' ) ? LIBXML_NOXXE : 0;
		$dom->loadXML( $content, $options );

		$errors = libxml_get_errors();
		libxml_clear_errors();
		if ( ! empty( $errors ) ) {
			return false;
		}

		$xpath = new DOMXPath( $dom );

		// Eliminar elementos peligrosos
		$dangerous = [ 'script', 'iframe', 'object', 'embed', 'foreignObject' ];
		foreach ( $dangerous as $tag ) {
			$nodes = $xpath->query( "//*[local-name()='$tag']" );
			foreach ( $nodes as $node ) {
				$node->parentNode->removeChild( $node );
			}
		}

		// Eliminar atributos de evento (on*)
		$all = $xpath->query( '//*[@*]' );
		$event_attrs = [];
		foreach ( $all as $node ) {
			/** @var DOMElement $node */
			$to_remove = [];
			foreach ( $node->attributes as $attr ) {
				$name = strtolower( $attr->name );
				if ( strpos( $name, 'on' ) === 0 && strlen( $name ) > 2 ) {
					$to_remove[] = $attr->name;
				}
				$value = $attr->value;
				if ( preg_match( '/^\s*javascript:/i', $value ) || preg_match( '/data:\s*text\/html/i', $value ) ) {
					$to_remove[] = $attr->name;
				}
			}
			foreach ( $to_remove as $name ) {
				$node->removeAttribute( $name );
			}
		}

		// Eliminar atributos href/xlink:href con javascript:
		$links = $xpath->query( "//*[@href or @*[local-name()='href']]" );
		foreach ( $links as $node ) {
			/** @var DOMElement $node */
			$href = $node->getAttribute( 'href' ) ?: $node->getAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
			if ( $href && preg_match( '/^\s*javascript:/i', $href ) ) {
				$node->removeAttribute( 'href' );
				$node->removeAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
			}
		}

		$output = $dom->saveXML();
		if ( $output === false ) {
			return false;
		}

		// Comprobación final: no debe quedar script ni javascript
		if ( preg_match( '/<script|javascript:|on\w+\s*=/i', $output ) ) {
			return false;
		}

		return apply_filters( 'estandar_kickoff_svg_sanitized_content', $output );
	}

	/**
	 * Renderiza el tab de configuración.
	 */
	public static function render_tab() {
		$instance = self::get_instance();
		$instance->render_settings_form();
	}

	private function render_settings_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = $this->is_enabled();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Subida de SVG', 'estandar-kickoff' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" value="0" />
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="1"
								<?php checked( $enabled ); ?>
							/>
							<?php esc_html_e( 'Permitir subir archivos SVG', 'estandar-kickoff' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Los SVG se sanitizan automáticamente para eliminar scripts y prevenir ataques XSS.', 'estandar-kickoff' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<div class="kickoff-svg-info" style="margin-top:24px;padding:16px;background:#f0f6fc;border-left:4px solid #2271b1;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Seguridad', 'estandar-kickoff' ); ?></h3>
			<p><?php esc_html_e( 'Este módulo elimina automáticamente:', 'estandar-kickoff' ); ?></p>
			<ul style="margin:0 0 0 20px;">
				<li><?php esc_html_e( 'Etiquetas &lt;script&gt;', 'estandar-kickoff' ); ?></li>
				<li><?php esc_html_e( 'Atributos de evento (onclick, onload, etc.)', 'estandar-kickoff' ); ?></li>
				<li><?php esc_html_e( 'URLs javascript: en enlaces', 'estandar-kickoff' ); ?></li>
				<li><?php esc_html_e( 'Elementos iframe, object, embed', 'estandar-kickoff' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
