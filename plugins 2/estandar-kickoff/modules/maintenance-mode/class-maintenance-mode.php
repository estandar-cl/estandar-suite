<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Maintenance_Mode {

	const OPTION_KEY = 'estandar_kickoff_maintenance';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'template_redirect', [ $this, 'maybe_show_maintenance' ], 1 );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_notice' ], 999 );
	}

	public function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return [
					'enabled'   => ! empty( $v['enabled'] ),
					'message'   => sanitize_textarea_field( $v['message'] ?? '' ),
					'title'     => sanitize_text_field( $v['title'] ?? '' ),
					'allow_ips' => sanitize_textarea_field( $v['allow_ips'] ?? '' ),
				];
			},
		] );
	}

	private function get_settings() {
		$s = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( $s, [
			'enabled'   => false,
			'message'   => __( 'Estamos realizando mejoras. Volvemos pronto.', 'estandar-kickoff' ),
			'title'     => __( 'Sitio en mantenimiento', 'estandar-kickoff' ),
			'allow_ips' => '',
		] );
	}

	public function maybe_show_maintenance() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

		$s = $this->get_settings();
		if ( ! $s['enabled'] ) return;

		if ( current_user_can( 'manage_options' ) ) return;

		$allowed = array_filter( array_map( 'trim', explode( "\n", $s['allow_ips'] ) ) );
		if ( ! empty( $allowed ) ) {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
			if ( in_array( $ip, $allowed, true ) ) return;
		}

		status_header( 503 );
		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $s['title'] ?: get_bloginfo( 'name' ) ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
				.box { text-align: center; padding: 48px; max-width: 480px; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
				h1 { margin: 0 0 16px; font-size: 24px; color: #1d2327; }
				p { margin: 0; color: #646970; line-height: 1.6; }
			</style>
		</head>
		<body>
			<div class="box">
				<h1><?php echo esc_html( $s['title'] ?: __( 'Sitio en mantenimiento', 'estandar-kickoff' ) ); ?></h1>
				<p><?php echo esc_html( $s['message'] ?: __( 'Estamos realizando mejoras. Volvemos pronto.', 'estandar-kickoff' ) ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	public function admin_bar_notice( $wp_admin_bar ) {
		$s = $this->get_settings();
		if ( ! $s['enabled'] || ! current_user_can( 'manage_options' ) ) return;

		$wp_admin_bar->add_node( [
			'id'    => 'kickoff-maintenance',
			'title' => '🔧 ' . __( 'Modo mantenimiento activo', 'estandar-kickoff' ),
			'href'  => admin_url( 'admin.php?page=estandar-kickoff&tab=maintenance-mode' ),
		] );
	}

	public static function render_tab() {
		$instance = self::get_instance();
		$s = $instance->get_settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_KEY ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Activar', 'estandar-kickoff' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> />
							<?php esc_html_e( 'Mostrar página de mantenimiento', 'estandar-kickoff' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Los administradores siempre pueden ver el sitio.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="mm_title"><?php esc_html_e( 'Título', 'estandar-kickoff' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[title]" id="mm_title" value="<?php echo esc_attr( $s['title'] ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th><label for="mm_message"><?php esc_html_e( 'Mensaje', 'estandar-kickoff' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[message]" id="mm_message" rows="4" class="large-text"><?php echo esc_textarea( $s['message'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th><label for="mm_ips"><?php esc_html_e( 'IPs permitidas', 'estandar-kickoff' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allow_ips]" id="mm_ips" rows="3" class="large-text" placeholder="192.168.1.1"><?php echo esc_textarea( $s['allow_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Una IP pública por línea. Dejar vacío para bloquear a todos excepto admins.', 'estandar-kickoff' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
