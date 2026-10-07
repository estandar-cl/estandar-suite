<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Notifications_Settings {

	public static function register_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( primer_is_active() && function_exists( 'estandar_register_submenu' ) ) {
			estandar_register_submenu( array(
				'slug'       => 'estandar-notifications',
				'title'      => 'Notifications',
				'menu_title' => 'Notifications',
				'subtitle'   => 'Notification subsystem readiness.',
				'capability' => 'manage_options',
				'callback'   => array( __CLASS__, 'render_content' ),
				'width'      => 'fluid',
				'icon'       => 'dashicons-email-alt2',
				'position'   => 63,
			) );
			return;
		}

		add_menu_page(
			'Notifications',
			'Notifications',
			'manage_options',
			'estandar-notifications',
			array( __CLASS__, 'render_page' ),
			'dashicons-email-alt2',
			63
		);
	}

	public static function render_page(): void {
		echo '<div class="wrap"><h1>Notifications</h1>';
		self::render_content();
		echo '</div>';
	}

	public static function render_content(): void {
		$status = Estandar_Notifications_Health::status();
		require ESTANDAR_NOTIFICATIONS_PATH . 'templates/admin/placeholder.php';
	}
}
