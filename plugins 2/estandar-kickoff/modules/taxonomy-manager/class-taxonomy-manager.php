<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Taxonomy_Manager {

	const OPT_KEY = 'estandar_kickoff_taxonomy_defs';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_all_taxonomies' ], 5 );
		add_action( 'admin_post_estandar_tax_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_estandar_tax_delete', [ $this, 'handle_delete' ] );
	}

	public static function get_defs(): array {
		$defs = get_option( self::OPT_KEY, [] );
		return is_array( $defs ) ? $defs : [];
	}

	public static function save_defs( array $defs ): void {
		update_option( self::OPT_KEY, $defs, false );
		flush_rewrite_rules();
	}

	public function register_all_taxonomies() {
		$defs = self::get_defs();
		foreach ( $defs as $key => $def ) {
			$this->register_one_taxonomy( $key, $def );
		}
	}

	private function register_one_taxonomy( string $key, array $def ) {
		$tax = sanitize_key( $key );
		if ( empty( $tax ) ) return;

		$singular = sanitize_text_field( $def['singular'] ?? ucfirst( $tax ) );
		$plural   = sanitize_text_field( $def['plural'] ?? $singular . 's' );
		$slug     = sanitize_title( $def['slug'] ?? $tax );
		$post_types = isset( $def['post_types'] ) && is_array( $def['post_types'] ) ? $def['post_types'] : [ 'post' ];
		$hierarchical = ! empty( $def['hierarchical'] );

		$labels = [
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			'add_new_item'  => sprintf( __( 'Agregar nuevo %s', 'estandar-kickoff' ), $singular ),
			'edit_item'     => sprintf( __( 'Editar %s', 'estandar-kickoff' ), $singular ),
			'view_item'     => sprintf( __( 'Ver %s', 'estandar-kickoff' ), $singular ),
			'all_items'     => sprintf( __( 'Todos %s', 'estandar-kickoff' ), $plural ),
			'search_items'  => sprintf( __( 'Buscar %s', 'estandar-kickoff' ), $plural ),
			'not_found'     => sprintf( __( 'No se encontraron %s', 'estandar-kickoff' ), $plural ),
		];

		register_taxonomy( $tax, $post_types, [
			'labels'            => $labels,
			'public'            => true,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'hierarchical'      => $hierarchical,
			'rewrite'           => [ 'slug' => $slug ],
			'show_admin_column' => true,
		] );
	}

	private function get_tab_url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => 'estandar-kickoff', 'tab' => 'taxonomy-manager' ], $args ), admin_url( 'admin.php' ) );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$instance->render_admin();
	}

	private function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$defs = self::get_defs();
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$current = $edit && isset( $defs[ $edit ] ) ? $defs[ $edit ] : null;
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$nonce_save = wp_create_nonce( 'estandar_tax_save' );
		$nonce_delete = wp_create_nonce( 'estandar_tax_delete' );

		if ( isset( $_GET['updated'] ) || isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>' . ( isset( $_GET['deleted'] ) ? esc_html__( 'Taxonomía eliminada.', 'estandar-kickoff' ) : esc_html__( 'Cambios guardados.', 'estandar-kickoff' ) ) . '</p></div>';
		}
		?>

		<h2><?php esc_html_e( 'Taxonomías existentes', 'estandar-kickoff' ); ?></h2>
		<?php if ( empty( $defs ) ) : ?>
			<p><em><?php esc_html_e( 'No hay taxonomías creadas.', 'estandar-kickoff' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Key</th><th>Singular</th><th>Plural</th><th>Post types</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $defs as $key => $d ) :
						$edit_url = esc_url( $this->get_tab_url( [ 'edit' => $key ] ) );
						$del_url = esc_url( admin_url( 'admin-post.php?action=estandar_tax_delete&key=' . $key . '&_wpnonce=' . $nonce_delete ) );
						?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( $d['singular'] ?? '' ); ?></td>
							<td><?php echo esc_html( $d['plural'] ?? '' ); ?></td>
							<td><?php echo esc_html( implode( ', ', $d['post_types'] ?? [] ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo $edit_url; ?>"><?php esc_html_e( 'Editar', 'estandar-kickoff' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo $del_url; ?>" onclick="return confirm('<?php echo esc_attr( __( '¿Eliminar?', 'estandar-kickoff' ) ); ?>')"><?php esc_html_e( 'Eliminar', 'estandar-kickoff' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr />
		<h2><?php echo $current ? esc_html__( 'Editar taxonomía', 'estandar-kickoff' ) : esc_html__( 'Crear taxonomía', 'estandar-kickoff' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_tax_save" />
			<?php wp_nonce_field( 'estandar_tax_save' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="tax_key"><?php esc_html_e( 'Key', 'estandar-kickoff' ); ?></label></th>
					<td><input name="tax_key" id="tax_key" type="text" value="<?php echo esc_attr( $edit ?: '' ); ?>" <?php echo $edit ? 'readonly' : ''; ?> class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="singular"><?php esc_html_e( 'Singular', 'estandar-kickoff' ); ?></label></th>
					<td><input name="singular" id="singular" type="text" value="<?php echo esc_attr( $current['singular'] ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="plural"><?php esc_html_e( 'Plural', 'estandar-kickoff' ); ?></label></th>
					<td><input name="plural" id="plural" type="text" value="<?php echo esc_attr( $current['plural'] ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="slug"><?php esc_html_e( 'Slug', 'estandar-kickoff' ); ?></label></th>
					<td><input name="slug" id="slug" type="text" value="<?php echo esc_attr( $current['slug'] ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Post types', 'estandar-kickoff' ); ?></th>
					<td>
						<?php foreach ( $post_types as $pt ) : ?>
							<label style="display:block;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $current['post_types'] ?? [ 'post' ], true ) ); ?> />
								<?php echo esc_html( $pt->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Jerárquica', 'estandar-kickoff' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="hierarchical" value="1" <?php checked( ! empty( $current['hierarchical'] ) ); ?> />
							<?php esc_html_e( 'Como categorías (con subcategorías)', 'estandar-kickoff' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( $current ? __( 'Guardar', 'estandar-kickoff' ) : __( 'Crear', 'estandar-kickoff' ) ); ?>
			<?php if ( $current ) : ?><a class="button" href="<?php echo esc_url( $this->get_tab_url() ); ?>"><?php esc_html_e( 'Cancelar', 'estandar-kickoff' ); ?></a><?php endif; ?>
		</form>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado' );
		check_admin_referer( 'estandar_tax_save' );

		$key = sanitize_key( $_POST['tax_key'] ?? '' );
		if ( empty( $key ) ) {
			wp_safe_redirect( $this->get_tab_url() );
			exit;
		}

		$defs = self::get_defs();
		$defs[ $key ] = [
			'singular'    => sanitize_text_field( $_POST['singular'] ?? '' ),
			'plural'      => sanitize_text_field( $_POST['plural'] ?? '' ),
			'slug'        => sanitize_title( $_POST['slug'] ?? $key ),
			'post_types'  => isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [ 'post' ],
			'hierarchical'=> ! empty( $_POST['hierarchical'] ),
		];
		self::save_defs( $defs );
		wp_safe_redirect( $this->get_tab_url( [ 'edit' => $key, 'updated' => 1 ] ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado' );
		check_admin_referer( 'estandar_tax_delete' );

		$key = sanitize_key( $_GET['key'] ?? '' );
		if ( $key ) {
			$defs = self::get_defs();
			unset( $defs[ $key ] );
			self::save_defs( $defs );
		}
		wp_safe_redirect( $this->get_tab_url( [ 'deleted' => 1 ] ) );
		exit;
	}
}
