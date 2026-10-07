<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pdf — generación real del PDF de una cotización (M4).
 *
 * Reusa el motor ÚNICO de la Suite (`Estandar\Core\Communications\Documents\Pdf`)
 * sobre el MISMO HTML de `Renderer::render_print()`, de modo que lo que se ve en
 * el canvas editable y lo que se exporta son idénticos. El módulo nunca fataliza:
 * si falta el motor devuelve `ok=false, error='sin_motor'`; si falta dompdf
 * delega el fallback mínimo del Core y lo marca con `fallback=true`.
 *
 * Seguridad: sin recursos remotos; nombres de archivo saneados con `basename()`.
 */
class Pdf
{
	/** Versión de plantilla: invalida el hash de fuente al cambiar el layout. */
	const TEMPLATE_VERSION = '1';

	/** Clase del motor único de la Suite. */
	const CORE_ENGINE = 'Estandar\Core\Communications\Documents\Pdf';

	/** True si el motor único de la Suite está cargado. */
	public static function available(): bool
	{
		return class_exists( self::CORE_ENGINE );
	}

	/**
	 * True si el motor caerá al PDF mínimo (dompdf ausente). Sin motor → true
	 * (no hay render real disponible). Puro y tolerante.
	 */
	public static function engine_fallback(): bool
	{
		if ( ! self::available() ) {
			return true;
		}
		if ( is_callable( array( self::CORE_ENGINE, 'dompdf_available' ) ) ) {
			return ! call_user_func( array( self::CORE_ENGINE, 'dompdf_available' ) );
		}
		return false;
	}

	/** Hash determinista del HTML + versión de plantilla + extra. */
	public static function source_hash( string $html, string $extra = '' ): string
	{
		return hash( 'sha256', $html . '|' . self::TEMPLATE_VERSION . '|' . $extra );
	}

	/**
	 * Nombre de archivo canónico: `Cotizacion-{slug}-{quote_number}-{rev:02d}.pdf`.
	 * Saneado (sin separadores de ruta ni caracteres raros). Si falta el correlativo
	 * o el slug, cae a `Cotizacion-{id}.pdf`.
	 */
	public static function filename( array $doc, int $revision_no = 0 ): string
	{
		$slug         = self::slug( (string) self::first( $doc, array( 'client.name', 'project.name', 'issuer.company_name' ), '' ) );
		$quote_number = self::token( (string) self::first( $doc, array( 'meta.quote_number', 'quote_number' ), '' ) );
		$id           = (int) self::first( $doc, array( 'id', 'quote_id' ), 0 );

		if ( '' === $slug || '' === $quote_number ) {
			$name = $id > 0 ? 'Cotizacion-' . $id . '.pdf' : 'Cotizacion.pdf';
			return self::sanitize_filename( $name );
		}

		$rev  = max( 0, $revision_no );
		$name = sprintf( 'Cotizacion-%s-%s-%02d.pdf', $slug, $quote_number, $rev );
		return self::sanitize_filename( $name );
	}

	/**
	 * Renderiza el PDF a bytes.
	 *
	 * @return array{ok:bool,bytes:string,html:string,fallback:bool,error:string}
	 */
	public static function render( array $doc, array $opts = array() ): array
	{
		$out = array(
			'ok'       => false,
			'bytes'    => '',
			'html'     => '',
			'fallback' => false,
			'error'    => '',
		);

		try {
			$html        = Renderer::render_print( $doc );
			$out['html'] = $html;

			if ( ! self::available() ) {
				$out['error'] = 'sin_motor';
				return $out;
			}

			// Las imágenes del mismo sitio se sirven desde disco: dompdf opera con
			// `isRemoteEnabled=false`, así que una URL remota nunca se embebe.
			$render_html = self::localize_images( $html );

			$engine_opts = array_merge(
				array( 'paper' => 'letter', 'orientation' => 'portrait' ),
				$opts
			);
			// dompdf solo abre archivos locales dentro de su `chroot`; se limita a
			// las raíces locales del sitio (uploads/content/ABSPATH) para habilitar
			// justo lo que localizamos, sin aflojar la política del motor.
			if ( ! array_key_exists( 'chroot', $engine_opts ) ) {
				$chroot = self::chroot_dirs();
				if ( ! empty( $chroot ) ) {
					$engine_opts['chroot'] = $chroot;
				}
			}

			$engine = new \Estandar\Core\Communications\Documents\Pdf();
			$bytes  = (string) $engine->render( $render_html, $engine_opts );

			$out['bytes']    = $bytes;
			$out['ok']       = ( '' !== $bytes );
			$out['fallback'] = self::engine_fallback();
			if ( '' === $bytes ) {
				$out['error'] = 'render_vacio';
			}
			return $out;
		} catch ( \Throwable $e ) {
			$out['ok']    = false;
			$out['error'] = 'excepcion: ' . $e->getMessage();
			return $out;
		}
	}

	/** Renderiza y escribe bytes en disco. Devuelve la ruta o null (nunca fatal). */
	public static function save( array $doc, string $path, array $opts = array() ): ?string
	{
		$result = self::render( $doc, $opts );
		if ( empty( $result['ok'] ) || '' === $result['bytes'] ) {
			return null;
		}
		if ( @file_put_contents( $path, $result['bytes'] ) === false ) {
			return null;
		}
		return $path;
	}

	// ── Localización de imágenes (URL del sitio → ruta local) ───────────────

	/**
	 * Reescribe los `src` de `<img>` que apuntan al MISMO sitio (uploads,
	 * content o site_url) por su ruta local en disco, para que dompdf las
	 * embeba aunque `isRemoteEnabled=false`. Las URLs externas quedan intactas.
	 *
	 * Es tolerante: sin `wp_upload_dir`/`site_url` (harness) devuelve el HTML
	 * sin cambios. Nunca fataliza.
	 */
	public static function localize_images( string $html ): string
	{
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$map = self::local_url_map();
		if ( empty( $map ) ) {
			return $html;
		}

		$result = preg_replace_callback(
			'/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/i',
			static function ( $m ) use ( $map ) {
				$url   = html_entity_decode( (string) $m[3], ENT_QUOTES );
				$local = self::url_to_local_path( $url, $map );
				if ( null === $local ) {
					return $m[0];
				}
				$attr = function_exists( 'esc_attr' ) ? esc_attr( $local ) : htmlspecialchars( $local, ENT_QUOTES );
				return $m[1] . $m[2] . $attr . $m[2];
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Mapa base_url → base_dir de las raíces locales del sitio, ordenado del
	 * prefijo más específico al más general (uploads antes que site_url).
	 *
	 * @return array<int,array{url:string,dir:string}>
	 */
	private static function local_url_map(): array
	{
		$map = array();

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
				$map[] = array( 'url' => (string) $uploads['baseurl'], 'dir' => (string) $uploads['basedir'] );
			}
		}
		if ( function_exists( 'content_url' ) && defined( 'WP_CONTENT_DIR' ) ) {
			$map[] = array( 'url' => (string) content_url(), 'dir' => (string) WP_CONTENT_DIR );
		}
		if ( function_exists( 'site_url' ) && defined( 'ABSPATH' ) ) {
			$map[] = array( 'url' => (string) site_url(), 'dir' => (string) ABSPATH );
		}

		usort( $map, static function ( $a, $b ) {
			return strlen( $b['url'] ) <=> strlen( $a['url'] );
		} );

		return $map;
	}

	/**
	 * Raíces locales permitidas para que dompdf lea las imágenes localizadas.
	 *
	 * @return array<int,string>
	 */
	private static function chroot_dirs(): array
	{
		$dirs = array();
		foreach ( self::local_url_map() as $entry ) {
			$dir = (string) ( $entry['dir'] ?? '' );
			if ( '' !== $dir && ! in_array( $dir, $dirs, true ) ) {
				$dirs[] = $dir;
			}
		}
		return $dirs;
	}

	/**
	 * Traduce una URL del mismo sitio a la ruta local en disco. Devuelve `null`
	 * si la URL no es local, no apunta a un archivo legible o intenta escapar
	 * del directorio base (path traversal).
	 */
	private static function url_to_local_path( string $url, array $map ): ?string
	{
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			return null;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( '' !== $scheme && 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return null;
		}

		$url_host = strtolower( (string) $parts['host'] );
		$url_path = '/' . ltrim( (string) $parts['path'], '/' );

		foreach ( $map as $entry ) {
			$base = parse_url( (string) $entry['url'] );
			if ( ! is_array( $base ) || '' === (string) ( $base['host'] ?? '' ) ) {
				continue;
			}
			$base_host = strtolower( (string) $base['host'] );
			// Mismo host, sin importar http/https (mismo sitio, distinto esquema).
			if ( $url_host !== $base_host ) {
				continue;
			}

			$base_path = rtrim( (string) ( $base['path'] ?? '' ), '/' );
			if ( '' !== $base_path ) {
				if ( 0 !== strpos( $url_path, $base_path . '/' ) ) {
					continue;
				}
				$relative = substr( $url_path, strlen( $base_path ) + 1 );
			} else {
				$relative = ltrim( $url_path, '/' );
			}

			$relative = rawurldecode( $relative );
			$relative = str_replace( '\\', '/', $relative );
			if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
				return null;
			}

			$dir = rtrim( (string) $entry['dir'], '/\\' );
			if ( '' === $dir ) {
				return null;
			}
			$candidate = $dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $relative, '/' ) );

			$base_real = realpath( $dir );
			$real      = realpath( $candidate );
			if ( false === $base_real || false === $real ) {
				return null;
			}
			$prefix = rtrim( $base_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( 0 !== strpos( $real, $prefix ) ) {
				return null;
			}
			if ( ! is_file( $real ) || ! is_readable( $real ) ) {
				return null;
			}

			return $real;
		}

		return null;
	}

	// ── Helpers PURE ────────────────────────────────────────────────────────

	/** Primer valor no vacío de una lista de claves punteadas. */
	private static function first( array $doc, array $keys, $default = '' )
	{
		foreach ( $keys as $key ) {
			$v = self::val( $doc, $key, null );
			if ( null !== $v && '' !== $v && ! ( is_array( $v ) && empty( $v ) ) ) {
				return $v;
			}
		}
		return $default;
	}

	/** Resuelve una clave punteada sobre un array (soporta claves planas). */
	private static function val( array $data, string $key, $default = null )
	{
		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}
		$cur = $data;
		foreach ( explode( '.', $key ) as $part ) {
			if ( is_array( $cur ) && array_key_exists( $part, $cur ) ) {
				$cur = $cur[ $part ];
			} else {
				return $default;
			}
		}
		return $cur;
	}

	/** Slug ASCII en minúsculas (sin acentos ni caracteres raros). */
	private static function slug( string $value ): string
	{
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		} else {
			$value = strtr( $value, array(
				'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
				'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
				'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
				'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
				'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
				'ñ' => 'n', 'ç' => 'c',
				'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
			) );
		}
		$value = (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $value );
		$value = trim( $value, '-' );
		return strtolower( $value );
	}

	/** Token de correlativo saneado (conserva guiones). */
	private static function token( string $value ): string
	{
		$value = trim( $value );
		$value = (string) preg_replace( '/[^A-Za-z0-9\-_]+/', '-', $value );
		return trim( $value, '-' );
	}

	/** Saneo final del nombre de archivo: nunca separadores de ruta ni `..`. */
	private static function sanitize_filename( string $name ): string
	{
		$name = str_replace( array( '/', '\\', "\0" ), '-', $name );
		$name = (string) preg_replace( '/[^A-Za-z0-9\-_.]+/', '-', $name );
		$name = (string) preg_replace( '/\.+/', '.', $name );
		$name = trim( $name, '-.' );
		return '' !== $name ? $name : 'Cotizacion.pdf';
	}
}
