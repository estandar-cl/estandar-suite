<?php
/**
 * Capacidad: generar un child theme.
 *
 * @package Estandar\Inicio
 */

namespace Estandar\Inicio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera un child theme básico (style.css + functions.php) a partir de un tema
 * padre instalado.
 */
class ChildTheme {

	/**
	 * Acción admin-post del formulario.
	 */
	public const ACTION = 'estandar_inicio_child_theme';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Render del formulario.
	 *
	 * @param array|null $plugin Plugin del hub (ignorado).
	 * @return void
	 */
	public static function render( $plugin = null ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = isset( $_GET['es_inicio_notice'] ) ? sanitize_key( wp_unslash( $_GET['es_inicio_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<?php if ( '' !== $notice ) : ?>
			<div class="notice <?php echo ( 'ok' === $notice ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
					<?php
					echo esc_html(
						'ok' === $notice
							? __( 'Child theme generado.', 'estandar-inicio' )
							: __( 'No se pudo generar el child theme (revisa nombre/slug y permisos).', 'estandar-inicio' )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="es-inicio-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="es_parent"><?php esc_html_e( 'Tema padre', 'estandar-inicio' ); ?></label></th>
					<td>
						<select name="parent" id="es_parent" required>
							<?php foreach ( wp_get_themes() as $slug => $theme ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $theme->get( 'Name' ) . ' (' . $slug . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="es_name"><?php esc_html_e( 'Nombre del child', 'estandar-inicio' ); ?></label></th>
					<td><input name="name" id="es_name" type="text" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="es_slug"><?php esc_html_e( 'Slug (carpeta)', 'estandar-inicio' ); ?></label></th>
					<td><input name="slug" id="es_slug" type="text" class="regular-text" placeholder="mi-child" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="es_author"><?php esc_html_e( 'Autor', 'estandar-inicio' ); ?></label></th>
					<td><input name="author" id="es_author" type="text" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="es_desc"><?php esc_html_e( 'Descripción', 'estandar-inicio' ); ?></label></th>
					<td><input name="description" id="es_desc" type="text" class="large-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="es_shot"><?php esc_html_e( 'Imagen (screenshot)', 'estandar-inicio' ); ?></label></th>
					<td>
						<input name="screenshot" id="es_shot" type="file" accept="image/png,image/jpeg,image/gif" />
						<p class="description">
							<?php esc_html_e( 'Recomendado 1200×900 (PNG/JPG/GIF). Si no subes una, se usará una imagen por defecto.', 'estandar-inicio' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Generar child theme', 'estandar-inicio' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Procesa el formulario y crea el child theme.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'estandar-inicio' ) );
		}
		check_admin_referer( self::ACTION );

		$parent      = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug        = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$author      = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		$shot = ( isset( $_FILES['screenshot'] ) && is_array( $_FILES['screenshot'] ) && ! empty( $_FILES['screenshot']['name'] ) )
			? $_FILES['screenshot'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: null;

		$themes = wp_get_themes();
		$ok     = ( '' !== $name && '' !== $slug && isset( $themes[ $parent ] ) );

		if ( $ok ) {
			$ok = self::create( $slug, $name, $parent, $author, $description, $shot );
		}

		wp_safe_redirect( self::return_url( $ok ? 'ok' : 'err' ) );
		exit;
	}

	/**
	 * Crea los archivos del child theme.
	 *
	 * @param string $slug        Slug/carpeta.
	 * @param string $name        Nombre.
	 * @param string $parent      Slug del padre.
	 * @param string $author      Autor.
	 * @param string $description Descripción.
	 * @return bool
	 */
	private static function create( string $slug, string $name, string $parent, string $author, string $description, $shot = null ): bool {
		$dir = trailingslashit( get_theme_root() ) . $slug;

		if ( is_dir( $dir ) ) {
			return false;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return false;
		}

		$style = "/*\n"
			. 'Theme Name: ' . $name . "\n"
			. 'Template: ' . $parent . "\n"
			. ( '' !== $author ? 'Author: ' . $author . "\n" : '' )
			. ( '' !== $description ? 'Description: ' . $description . "\n" : '' )
			. 'Version: 0.1.0' . "\n"
			. 'Text Domain: ' . $slug . "\n"
			. "*/\n";

		$functions = "<?php\n"
			. "// Estandar Inicio — child theme de {$parent}.\n"
			. "add_action( 'wp_enqueue_scripts', function () {\n"
			. "    wp_enqueue_style( '{$slug}-parent', get_template_directory_uri() . '/style.css', array(), wp_get_theme( get_template() )->get( 'Version' ) );\n"
			. "} );\n";

		if ( ! $wp_filesystem->mkdir( $dir ) ) {
			return false;
		}

		$ok = $wp_filesystem->put_contents( $dir . '/style.css', $style )
			&& $wp_filesystem->put_contents( $dir . '/functions.php', $functions );

		if ( ! $ok ) {
			return false;
		}

		// Imagen: la subida, o la por defecto (nunca queda sin screenshot).
		$saved = ( is_array( $shot ) && ! empty( $shot['tmp_name'] ) && empty( $shot['error'] ) && is_uploaded_file( $shot['tmp_name'] ) )
			? self::save_screenshot( $shot, $dir )
			: false;

		if ( ! $saved ) {
			$default = ESTANDAR_INICIO_PATH . 'assets/screenshot-default.png';
			if ( file_exists( $default ) ) {
				$wp_filesystem->copy( $default, $dir . '/screenshot.png' );
			}
		}

		return true;
	}

	/**
	 * Guarda el screenshot subido como screenshot.<ext> (png/jpg/gif).
	 *
	 * @param array  $shot Datos del archivo subido.
	 * @param string $dir  Directorio del child theme.
	 * @return bool
	 */
	private static function save_screenshot( array $shot, string $dir ): bool {
		$info = @getimagesize( $shot['tmp_name'] );
		if ( ! $info ) {
			return false;
		}

		$map = array(
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_GIF  => 'gif',
		);
		if ( ! isset( $map[ $info[2] ] ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->copy( $shot['tmp_name'], $dir . '/screenshot.' . $map[ $info[2] ] );
	}

	/**
	 * URL de retorno (hub si está, propia si no).
	 *
	 * @param string $notice ok|err.
	 * @return string
	 */
	private static function return_url( string $notice ): string {
		if ( function_exists( 'estandar_suite_registered_plugins' ) ) {
			return add_query_arg(
				array(
					'page'            => 'estandar-suite',
					'path'            => ESTANDAR_INICIO_SLUG . '/child-theme',
					'es_inicio_notice' => $notice,
				),
				admin_url( 'admin.php' )
			);
		}

		return add_query_arg(
			array(
				'page'             => ESTANDAR_INICIO_SLUG,
				'es_inicio_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
	}
}
