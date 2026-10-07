<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Child_Theme {

	const NOTICE_KEY = 'estandar_kickoff_child_theme_notice_';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_estandar_kickoff_create_child_theme', [ $this, 'handle_create' ] );
	}

	private function get_theme_data() {
		$theme    = wp_get_theme();
		$template = sanitize_file_name( $theme->get_template() );
		$parent   = $theme->parent();
		$name     = $parent ? $parent->get( 'Name' ) : $theme->get( 'Name' );
		$name     = trim( str_replace( '*/', '', (string) $name ) );

		if ( '' === $name ) {
			$name = $template;
		}

		return [
			'name'       => $name,
			'template'   => $template,
			'child_slug' => sanitize_file_name( $template . '-child' ),
			'theme_root' => get_theme_root(),
		];
	}

	private function get_child_dir( array $data ) {
		return trailingslashit( $data['theme_root'] ) . $data['child_slug'];
	}

	private function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS
		);
	}

	private function get_notice() {
		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : [];
	}

	private function redirect_to_tab() {
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-kickoff&tab=child-theme' ) );
		exit;
	}

	private function create_files( array $data ) {
		$child_dir = $this->get_child_dir( $data );

		if ( file_exists( $child_dir ) ) {
			return new WP_Error(
				'estandar_child_theme_exists',
				__( 'El child theme ya existe. No se sobrescribió nada.', 'estandar-kickoff' )
			);
		}

		if ( ! is_writable( $data['theme_root'] ) ) {
			return new WP_Error(
				'estandar_child_theme_not_writable',
				__( 'La carpeta de themes no tiene permisos de escritura.', 'estandar-kickoff' )
			);
		}

		if ( ! wp_mkdir_p( $child_dir ) ) {
			return new WP_Error(
				'estandar_child_theme_mkdir_failed',
				__( 'No se pudo crear la carpeta del child theme.', 'estandar-kickoff' )
			);
		}

		$style = sprintf(
			"/*\nTheme Name: %s Child\nTemplate: %s\nVersion: 1.0.0\n*/\n",
			$data['name'],
			$data['template']
		);

		$functions = "<?php\nadd_action('wp_enqueue_scripts', function() {\n    wp_enqueue_style(\n        'parent-style',\n        get_template_directory_uri() . '/style.css'\n    );\n});\n";

		if ( false === file_put_contents( trailingslashit( $child_dir ) . 'style.css', $style ) ) {
			return new WP_Error(
				'estandar_child_theme_style_failed',
				__( 'No se pudo crear style.css.', 'estandar-kickoff' )
			);
		}

		if ( false === file_put_contents( trailingslashit( $child_dir ) . 'functions.php', $functions ) ) {
			return new WP_Error(
				'estandar_child_theme_functions_failed',
				__( 'No se pudo crear functions.php.', 'estandar-kickoff' )
			);
		}

		return true;
	}

	public function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'estandar-kickoff' ) );
		}

		check_admin_referer( 'estandar_kickoff_create_child_theme' );

		$data     = $this->get_theme_data();
		$activate = ! empty( $_POST['activate_child_theme'] );
		$result   = $this->create_files( $data );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			$this->redirect_to_tab();
		}

		if ( $activate ) {
			switch_theme( $data['child_slug'] );
			$this->set_notice(
				'success',
				sprintf(
					/* translators: %s: child theme slug. */
					__( 'Child theme creado y activado: %s', 'estandar-kickoff' ),
					$data['child_slug']
				)
			);
		} else {
			$this->set_notice(
				'success',
				sprintf(
					/* translators: %s: child theme slug. */
					__( 'Child theme creado: %s', 'estandar-kickoff' ),
					$data['child_slug']
				)
			);
		}

		$this->redirect_to_tab();
	}

	public static function render_tab() {
		$instance  = self::get_instance();
		$data      = $instance->get_theme_data();
		$child_dir = $instance->get_child_dir( $data );
		$exists    = file_exists( $child_dir );
		$notice    = $instance->get_notice();
		?>
		<?php if ( ! empty( $notice['message'] ) ) : ?>
			<div class="notice notice-<?php echo 'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success'; ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Tema activo detectado', 'estandar-kickoff' ); ?></th>
				<td>
					<input type="text" class="regular-text" value="<?php echo esc_attr( $data['name'] ); ?>" readonly />
					<p class="description"><?php echo esc_html( sprintf( __( 'Template: %s', 'estandar-kickoff' ), $data['template'] ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Child theme destino', 'estandar-kickoff' ); ?></th>
				<td>
					<input type="text" class="regular-text" value="<?php echo esc_attr( $data['child_slug'] ); ?>" readonly />
					<p class="description"><?php echo esc_html( $child_dir ); ?></p>
					<?php if ( $exists ) : ?>
						<p class="description"><?php esc_html_e( 'Ya existe. KickOff no sobrescribe themes existentes.', 'estandar-kickoff' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php $button_attrs = $exists ? [ 'disabled' => 'disabled' ] : []; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_kickoff_create_child_theme" />
			<?php wp_nonce_field( 'estandar_kickoff_create_child_theme' ); ?>
			<p>
				<label>
					<input type="checkbox" name="activate_child_theme" value="1" />
					<?php esc_html_e( 'Activar después de crear', 'estandar-kickoff' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Create Child Theme', 'estandar-kickoff' ), 'primary', 'submit', true, $button_attrs ); ?>
		</form>
		<?php
	}
}
