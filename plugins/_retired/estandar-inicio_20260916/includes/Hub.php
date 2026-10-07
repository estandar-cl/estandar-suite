<?php
/**
 * Integración de Estandar Inicio con el hub Estandar Suite.
 *
 * @package Estandar\Inicio
 */

namespace Estandar\Inicio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra el plugin (y sus capacidades como tabs) en el hub; si el hub no
 * está presente, crea su propio menú mínimo.
 */
class Hub {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( function_exists( 'estandar_suite_register_plugin' ) ) {
			self::register_with_hub();
			return;
		}

		// Sin hub: menú propio mínimo (el plugin sigue siendo útil).
		add_action( 'admin_menu', array( __CLASS__, 'fallback_menu' ) );
	}

	/**
	 * Definición del plugin ante el hub.
	 *
	 * @return array
	 */
	public static function plan(): array {
		return array(
			'id'          => ESTANDAR_INICIO_SLUG,
			'name'        => 'Estandar Inicio',
			'version'     => ESTANDAR_INICIO_VERSION,
			'description' => 'Capacidades base: generar child theme y permitir SVG.',
			'icon'        => 'dashicons-admin-home',
			'position'    => 10,
			'page_slug'   => ESTANDAR_INICIO_SLUG,
			'tabs'        => array(
				array(
					'id'       => 'child-theme',
					'label'    => __( 'Child theme', 'estandar-inicio' ),
					'order'    => 10,
					'callback' => array( ChildTheme::class, 'render' ),
				),
				array(
					'id'       => 'svg',
					'label'    => __( 'SVG', 'estandar-inicio' ),
					'order'    => 20,
					'callback' => array( SvgUploads::class, 'render' ),
				),
			),
		);
	}

	/**
	 * Registro en el hub.
	 *
	 * @return void
	 */
	private static function register_with_hub(): void {
		estandar_suite_register_plugin( self::plan() );
	}

	/**
	 * Menú propio cuando el hub no está activo.
	 *
	 * @return void
	 */
	public static function fallback_menu(): void {
		add_menu_page(
			__( 'Estandar Inicio', 'estandar-inicio' ),
			__( 'Estandar Inicio', 'estandar-inicio' ),
			'manage_options',
			ESTANDAR_INICIO_SLUG,
			array( __CLASS__, 'render_fallback' ),
			'dashicons-admin-home',
			58
		);
	}

	/**
	 * Página mínima de respaldo (sin hub).
	 *
	 * @return void
	 */
	public static function render_fallback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Estandar Inicio', 'estandar-inicio' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Estandar Suite no está activo: estas son las capacidades de Estandar Inicio.', 'estandar-inicio' ); ?>
			</p>

			<h2><?php esc_html_e( 'Generar child theme', 'estandar-inicio' ); ?></h2>
			<?php ChildTheme::render(); ?>

			<h2><?php esc_html_e( 'SVG en la biblioteca', 'estandar-inicio' ); ?></h2>
			<?php SvgUploads::render(); ?>
		</div>
		<?php
	}
}
