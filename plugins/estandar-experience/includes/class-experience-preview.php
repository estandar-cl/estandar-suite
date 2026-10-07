<?php
/**
 * Preview en vivo (liviano) de un bloque de Experience.
 *
 * Abre una página admin mínima que renderiza el bloque con `do_blocks()` y deja
 * que wp_head/wp_footer impriman el CSS/JS del FX. Así el efecto se ejecuta de
 * verdad, en una pestaña aislada, sin cargar JS en el editor.
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página de preview (solo administradores).
 */
class Preview {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_estandar_experience_preview', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * URL base del preview.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin-post.php?action=estandar_experience_preview' );
	}

	/**
	 * Renderiza el preview (solo administradores).
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'estandar-experience' ) );
		}

		$raw = isset( $_GET['markup'] ) ? wp_unslash( $_GET['markup'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$markup = base64_decode( rawurldecode( (string) $raw ), true );
		if ( false === $markup ) {
			$markup = '';
		}

		$markup = self::whitelist_blocks( $markup );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php wp_head(); ?>
<style>html,body{margin:0;padding:0}</style>
</head>
<body class="estandar-experience-preview">
<?php echo do_blocks( $markup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bloques de confianza (admin). ?>
<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Deja solo bloques estandar/* y core/* (defensa en profundidad).
	 *
	 * @param string $markup Contenido en bloques.
	 * @return string
	 */
	private static function whitelist_blocks( string $markup ): string {
		if ( '' === $markup || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks = parse_blocks( $markup );
		return serialize_blocks( self::filter_blocks( $blocks ) );
	}

	/**
	 * Filtra recursivamente los bloques permitidos.
	 *
	 * @param array $blocks Bloques parseados.
	 * @return array
	 */
	private static function filter_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( '' === $name || 0 === strpos( $name, 'estandar/' ) || 0 === strpos( $name, 'core/' ) ) {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = self::filter_blocks( $block['innerBlocks'] );
				}
				$out[] = $block;
			}
		}
		return $out;
	}
}
