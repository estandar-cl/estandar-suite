<?php
/**
 * Estandar Studio — Render: sirve el bundle code-first dentro de WordPress.
 *
 * WP pone el head/footer (SEO, canonical, assets de plugins, caché) y el bundle
 * code-first va en el cuerpo. Los marcadores {{...}} se resuelven ANTES de wp_head
 * para que las features (form del Suite, chatbot) alcancen a registrar sus assets.
 *
 * Marcadores soportados:
 *   {{form:ID}}            → [estandar_form_v2 id="ID"]
 *   {{form:ID k="v"}}      → [estandar_form_v2 id="ID" k="v"]
 *   {{chatbot}}            → [estandar_chatbot]
 *   {{shortcode:[ ... ]}}  → shortcode arbitrario
 *   {{wa:56912345678}}     → enlace https://wa.me/<dígitos>
 *   {{wa:569|texto}}       → enlace wa.me con texto
 *   {{home}}               → URL del sitio
 *
 * @package Estandar\Studio
 */

namespace Estandar\Studio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Render {

	public static function init(): void {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		// FRONT_PAGE_ROOT_V1 (2026-10-06): la portada Studio debe servirse en
		// `/`. WP no soporta un CPT como portada estática (redirige `/` a su
		// permalink, o 404 si se anula el redirect), así que se sirve el
		// documento directamente en la petición raíz, ANTES de redirect_canonical.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_front' ), 0 );
	}

	public static function template( $template ) {
		if ( is_singular( Pages::POST_TYPE ) ) {
			$own = ESTANDAR_STUDIO_PATH . 'templates/page.php';
			if ( is_file( $own ) ) {
				return $own;
			}
		}
		return $template;
	}

	/**
	 * FRONT_PAGE_ROOT_V1 (2026-10-06): si la portada estática (`page_on_front`)
	 * es una página Studio y la petición es la raíz, sirve su documento aquí y
	 * termina — antes de que WordPress intente redirigir/404. No afecta a otros
	 * CPT, páginas ni rutas.
	 */
	public static function maybe_serve_front(): void {
		if ( ! self::is_studio_front_page() ) {
			return;
		}
		$id = (int) get_option( 'page_on_front', 0 );
		if ( $id <= 0 ) {
			return;
		}
		// Raíz: servir el documento Studio (200) y terminar.
		if ( self::is_root_request() ) {
			status_header( 200 );
			if ( isset( $GLOBALS['wp_query'] ) ) {
				$GLOBALS['wp_query']->is_404 = false;
			}
			echo self::document( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		// Canonical: el permalink propio de la portada Studio redirige 301 a `/`.
		if ( self::is_front_permalink_request( $id ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/** ¿La petición actual es el permalink propio de la portada Studio? */
	public static function is_front_permalink_request( int $id ): bool {
		$req  = rtrim( self::url_path( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), '/' );
		$link = function_exists( 'get_permalink' ) ? get_permalink( $id ) : '';
		$link = rtrim( self::url_path( (string) $link ), '/' );
		return $req !== '' && $link !== '' && $req === $link;
	}

	/** ¿La portada estática (`page_on_front`) es una página Studio? */
	public static function is_studio_front_page(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$front_id = (int) get_option( 'page_on_front', 0 );
		if ( $front_id <= 0 ) {
			return false;
		}
		$front = get_post( $front_id );
		return $front instanceof \WP_Post && $front->post_type === Pages::POST_TYPE;
	}

	/** ¿La petición actual es la raíz del sitio (home)? */
	public static function is_root_request(): bool {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = self::url_path( $uri );
		$home = self::url_path( function_exists( 'home_url' ) ? home_url( '/' ) : '/' );
		return rtrim( $path, '/' ) === rtrim( $home, '/' );
	}

	/** Path de una URL ('' si no se puede determinar). */
	private static function url_path( string $url ): string {
		$p = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
		return is_string( $p ) ? $p : '';
	}

	/**
	 * Resuelve los marcadores. PURO: recibe los callables en $ctx para poder testear
	 * sin WordPress. Default: do_shortcode()/home_url() reales.
	 */
	public static function resolve( string $html, array $ctx = array() ): string {
		$do_sc = $ctx['do_shortcode'] ?? function ( $sc ) { return do_shortcode( $sc ); };
		$home  = $ctx['home'] ?? ( function_exists( 'home_url' ) ? home_url( '/' ) : '/' );
		$burl  = (string) ( $ctx['bundle_url'] ?? '' );

		// {{form:ID ...args}}
		$html = preg_replace_callback(
			'/\{\{form:(\d+)([^}]*)\}\}/',
			function ( $m ) use ( $do_sc ) {
				$sc = '[estandar_form_v2 id="' . (int) $m[1] . '"';
				$extra = trim( (string) $m[2] );
				if ( $extra !== '' ) {
					foreach ( preg_split( '/\s+/', $extra ) as $pair ) {
						if ( strpos( $pair, '=' ) !== false ) {
							list( $k, $v ) = explode( '=', $pair, 2 );
							$sc .= ' ' . sanitize_key( $k ) . '="' . esc_attr( trim( $v, '"' ) ) . '"';
						}
					}
				}
				$sc .= ']';
				return $do_sc( $sc );
			},
			$html
		);

		// {{chatbot}}
		$html = str_replace( '{{chatbot}}', $do_sc( '[estandar_chatbot]' ), $html );

		// {{shortcode:[ ... ]}}
		$html = preg_replace_callback(
			'/\{\{shortcode:(.*?)\}\}/s',
			function ( $m ) use ( $do_sc ) {
				$inner = trim( (string) $m[1] );
				if ( $inner === '' ) {
					return '';
				}
				if ( $inner[0] !== '[' ) {
					$inner = '[' . $inner . ']';
				}
				return $do_sc( $inner );
			},
			$html
		);

		// {{wa:NUMERO|texto}}
		$html = preg_replace_callback(
			'/\{\{wa:([0-9+\s\-]+)(?:\|([^}]*))?\}\}/',
			function ( $m ) {
				$num  = preg_replace( '/\D/', '', (string) $m[1] );
				$text = isset( $m[2] ) && $m[2] !== '' ? '?text=' . rawurlencode( (string) $m[2] ) : '';
				return esc_url( 'https://wa.me/' . $num . $text );
			},
			$html
		);

		$html = str_replace( '{{home}}', esc_url( $home ), $html );
		$html = str_replace( '{{bundle}}', esc_url( $burl ), $html );

		return $html;
	}

	/**
	 * Documento completo para el CPT (usado por templates/page.php y por la
	 * portada raíz). `$post_id` permite renderizar una página explícita cuando
	 * no hay objeto de query (p. ej. la portada).
	 */
	public static function document( ?int $post_id = null ): string {
		$post = $post_id && $post_id > 0 ? get_post( $post_id ) : get_queried_object();
		$slug = $post instanceof \WP_Post ? (string) $post->post_name : '';
		$b    = Pages::read( $slug );

		// Resolver primero (registra/enqueuea assets de las features).
		$up   = wp_upload_dir();
		$burl = rtrim( $up['baseurl'], '/' ) . '/' . Pages::DIR . '/' . $slug . '/';
		// URL raíz-relativa: funciona en cualquier host (localhost/IP/dominio).
		$burl = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $burl ) : $burl;
		$body = self::resolve( $b['html'], array( 'bundle_url' => $burl ) );

		if ( trim( $b['html'] ) === '' ) {
			$body = '<div style="max-width:640px;margin:15vh auto;font-family:system-ui;color:#333">'
				. '<h1>Página sin bundle</h1><p>No hay <code>index.html</code> para <b>' . esc_html( $slug ) . '</b>. '
				. 'Escribe el bundle con <code>wp estandar-studio page set-bundle ' . esc_html( $slug ) . '</code> o desde el admin.</p></div>';
		}

		ob_start();
		wp_head();
		$head = (string) ob_get_clean();
		ob_start();
		wp_footer();
		$footer = (string) ob_get_clean();

		$doc  = "<!doctype html>\n<html " . get_language_attributes() . ">\n<head>\n";
		$doc .= '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">' . "\n";
		$doc .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		$doc .= $head . "\n";
		if ( trim( $b['css'] ) !== '' ) {
			$doc .= "<style id=\"estandar-studio-css\">\n" . $b['css'] . "\n</style>\n";
		}
		$doc .= "</head>\n<body class=\"estandar-studio\">\n";
		$doc .= $body . "\n";
		if ( trim( $b['js'] ) !== '' ) {
			$doc .= "<script id=\"estandar-studio-js\">\n" . $b['js'] . "\n</script>\n";
		}
		$doc .= $footer . "\n</body>\n</html>";
		return $doc;
	}
}
