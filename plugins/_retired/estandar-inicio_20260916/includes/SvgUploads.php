<?php
/**
 * Capacidad: permitir subir SVG a la biblioteca de medios (con sanitización).
 *
 * @package Estandar\Inicio
 */

namespace Estandar\Inicio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Habilita SVG sólo para administradores y sanitiza el archivo al subirlo.
 */
class SvgUploads {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'upload_mimes', array( __CLASS__, 'mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_filetype' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'sanitize_upload' ) );
	}

	/**
	 * Habilita el mime SVG (sólo administradores).
	 *
	 * @param array $mimes Mimes permitidos.
	 * @return array
	 */
	public static function mimes( $mimes ) {
		if ( current_user_can( 'manage_options' ) ) {
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Corrige el tipo detectado para SVG (WP lo bloquea por defecto).
	 *
	 * @param array  $data     Datos de tipo.
	 * @param string $file     Ruta temporal.
	 * @param string $filename Nombre original.
	 * @param array  $mimes    Mimes permitidos.
	 * @return array
	 */
	public static function fix_filetype( $data, $file, $filename, $mimes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $data;
		}

		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}

		return $data;
	}

	/**
	 * Sanitiza el SVG antes de guardarlo; rechaza si queda peligroso.
	 *
	 * @param array $file Datos del archivo.
	 * @return array
	 */
	public static function sanitize_upload( $file ) {
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'svg' !== $ext || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $file;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$file['error'] = __( 'Solo administradores pueden subir SVG.', 'estandar-inicio' );
			return $file;
		}

		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			$file['error'] = __( 'SVG ilegible.', 'estandar-inicio' );
			return $file;
		}

		$clean = self::sanitize( $raw );
		if ( false === $clean ) {
			$file['error'] = __( 'SVG rechazado por seguridad.', 'estandar-inicio' );
			return $file;
		}

		file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $file;
	}

	/**
	 * Limpieza conservadora de SVG. Devuelve false si tras limpiar quedan
	 * patrones peligrosos.
	 *
	 * @param string $svg Contenido SVG.
	 * @return string|false
	 */
	private static function sanitize( string $svg ) {
		// Quita script y foreignObject.
		$svg = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg );
		$svg = preg_replace( '#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg );
		// Quita atributos de evento (onload, onclick, ...).
		$svg = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg );
		// Neutraliza hrefs javascript:.
		$svg = preg_replace( '#(href|xlink:href)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $svg );

		if ( null === $svg || '' === $svg ) {
			return false;
		}

		if ( preg_match( '#<script|javascript:|\son\w+\s*=#i', $svg ) ) {
			return false;
		}

		return $svg;
	}

	/**
	 * Render de estado.
	 *
	 * @param array|null $plugin Plugin del hub (ignorado).
	 * @return void
	 */
	public static function render( $plugin = null ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<p>
			<?php esc_html_e( 'Subida de SVG habilitada para administradores, con sanitización al subir (se eliminan scripts y atributos de evento).', 'estandar-inicio' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Los SVG se validan en la subida; si quedan patrones peligrosos, el archivo se rechaza.', 'estandar-inicio' ); ?>
		</p>
		<?php
	}
}
