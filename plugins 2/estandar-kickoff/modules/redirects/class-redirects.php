<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Redirects {

	const OPT_KEY = 'estandar_kickoff_redirects';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
		add_action( 'admin_post_estandar_redirect_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_estandar_redirect_delete', [ $this, 'handle_delete' ] );
	}


	public static function get_redirects(): array {
		$r = get_option( self::OPT_KEY, [] );
		return is_array( $r ) ? $r : [];
	}

	public static function save_redirects( array $r ): void {
		update_option( self::OPT_KEY, $r, false );
	}

	public function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

		$redirects = self::get_redirects();
		if ( empty( $redirects ) ) return;

		$request = $_SERVER['REQUEST_URI'] ?? '/';
		$path = strtok( $request, '?' );
		$path = '/' . trim( $path, '/' );
		if ( $path !== '/' ) $path = rtrim( $path, '/' );

		foreach ( $redirects as $r ) {
			$from = $r['from'] ?? '';
			$to   = $r['to'] ?? '';
			$code = (int) ( $r['code'] ?? 301 );
			if ( $from === '' || $to === '' ) continue;

			$from = '/' . trim( $from, '/' );
			if ( $from !== '/' ) $from = rtrim( $from, '/' );

			if ( $path === $from || $path === $from . '/' ) {
				wp_redirect( $to, $code, 'KickOff Redirect' );
				exit;
			}
		}
	}

	private function get_tab_url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => 'estandar-kickoff', 'tab' => 'redirects' ], $args ), admin_url( 'admin.php' ) );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$redirects = self::get_redirects();
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$current = null;
		if ( $edit_id && isset( $redirects[ $edit_id ] ) ) {
			$current = $redirects[ $edit_id ];
		}
		$nonce_del = wp_create_nonce( 'estandar_redirect_delete' );

		if ( isset( $_GET['updated'] ) || isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>' . ( isset( $_GET['deleted'] ) ? esc_html__( 'Redirección eliminada.', 'estandar-kickoff' ) : esc_html__( 'Guardado.', 'estandar-kickoff' ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			$message = 'chain' === $_GET['error']
				? __( 'No se guardó: evita cadenas de redirección hacia otra ruta redirigida.', 'estandar-kickoff' )
				: __( 'No se guardó: la redirección apunta a la misma ruta.', 'estandar-kickoff' );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
		?>

		<h2><?php esc_html_e( 'Redirecciones', 'estandar-kickoff' ); ?></h2>
		<?php if ( ! empty( $redirects ) ) : ?>
			<table class="widefat striped">
				<thead><tr><th>Desde</th><th>Hacia</th><th>Código</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $redirects as $id => $r ) :
						$edit_url = esc_url( $instance->get_tab_url( [ 'edit' => $id ] ) );
						$del_url = esc_url( admin_url( 'admin-post.php?action=estandar_redirect_delete&id=' . $id . '&_wpnonce=' . $nonce_del ) );
						?>
						<tr>
							<td><code><?php echo esc_html( $r['from'] ?? '' ); ?></code></td>
							<td><?php echo esc_html( $r['to'] ?? '' ); ?></td>
							<td><?php echo esc_html( $r['code'] ?? 301 ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo $edit_url; ?>"><?php esc_html_e( 'Editar', 'estandar-kickoff' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo $del_url; ?>" onclick="return confirm('<?php echo esc_attr( __( '¿Eliminar?', 'estandar-kickoff' ) ); ?>')"><?php esc_html_e( 'Eliminar', 'estandar-kickoff' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No hay redirecciones.', 'estandar-kickoff' ); ?></em></p>
		<?php endif; ?>

		<hr />
		<h2><?php echo $current ? esc_html__( 'Editar redirección', 'estandar-kickoff' ) : esc_html__( 'Nueva redirección', 'estandar-kickoff' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_redirect_save" />
			<?php wp_nonce_field( 'estandar_redirect_save' ); ?>
			<?php if ( $edit_id ) : ?><input type="hidden" name="redirect_id" value="<?php echo esc_attr( $edit_id ); ?>" /><?php endif; ?>
			<table class="form-table">
				<tr>
					<th><label for="from"><?php esc_html_e( 'Desde (ruta)', 'estandar-kickoff' ); ?></label></th>
					<td><input name="from" id="from" type="text" value="<?php echo esc_attr( $current['from'] ?? '' ); ?>" placeholder="/pagina-antigua" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="to"><?php esc_html_e( 'Hacia (URL)', 'estandar-kickoff' ); ?></label></th>
					<td><input name="to" id="to" type="url" value="<?php echo esc_attr( $current['to'] ?? '' ); ?>" placeholder="https://..." class="large-text" /></td>
				</tr>
				<tr>
					<th><label for="code"><?php esc_html_e( 'Código', 'estandar-kickoff' ); ?></label></th>
					<td>
						<select name="code" id="code">
							<option value="301" <?php selected( $current['code'] ?? 301, 301 ); ?>>301 - Permanente</option>
							<option value="302" <?php selected( $current['code'] ?? 301, 302 ); ?>>302 - Temporal</option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( $current ? __( 'Guardar', 'estandar-kickoff' ) : __( 'Añadir', 'estandar-kickoff' ) ); ?>
			<?php if ( $current ) : ?><a class="button" href="<?php echo esc_url( $instance->get_tab_url() ); ?>"><?php esc_html_e( 'Cancelar', 'estandar-kickoff' ); ?></a><?php endif; ?>
		</form>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado' );
		check_admin_referer( 'estandar_redirect_save' );

		$from = sanitize_text_field( $_POST['from'] ?? '' );
		$to   = esc_url_raw( $_POST['to'] ?? '' );
		$code = in_array( (int) ( $_POST['code'] ?? 301 ), [ 301, 302 ], true ) ? (int) $_POST['code'] : 301;
		$id   = sanitize_key( $_POST['redirect_id'] ?? '' );

		if ( $from === '' || $to === '' ) {
			wp_safe_redirect( $this->get_tab_url() );
			exit;
		}

		$from = '/' . trim( $from, '/' );
		$redirects = self::get_redirects();

		$to_path = (string) wp_parse_url( $to, PHP_URL_PATH );
		$to_path = '/' . trim( $to_path, '/' );
		if ( $to_path !== '/' ) $to_path = rtrim( $to_path, '/' );

		if ( $to_path === $from ) {
			wp_safe_redirect( $this->get_tab_url( [ 'error' => 'loop' ] ) );
			exit;
		}

		foreach ( $redirects as $existing_id => $redirect ) {
			if ( $id && $existing_id === $id ) {
				continue;
			}

			$existing_from = '/' . trim( (string) ( $redirect['from'] ?? '' ), '/' );
			if ( $existing_from !== '/' ) $existing_from = rtrim( $existing_from, '/' );

			if ( $existing_from === $to_path ) {
				wp_safe_redirect( $this->get_tab_url( [ 'error' => 'chain' ] ) );
				exit;
			}
		}

		$key = $id ?: sanitize_key( $from ) . '_' . wp_rand( 100, 999 );
		$redirects[ $key ] = [ 'from' => $from, 'to' => $to, 'code' => $code ];
		self::save_redirects( $redirects );
		wp_safe_redirect( $this->get_tab_url( [ 'updated' => 1 ] ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado' );
		check_admin_referer( 'estandar_redirect_delete' );

		$id = sanitize_key( $_GET['id'] ?? '' );
		if ( $id ) {
			$redirects = self::get_redirects();
			unset( $redirects[ $id ] );
			self::save_redirects( $redirects );
		}
		wp_safe_redirect( $this->get_tab_url( [ 'deleted' => 1 ] ) );
		exit;
	}
}
