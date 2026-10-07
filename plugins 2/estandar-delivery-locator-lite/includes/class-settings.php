<?php
/**
 * Ajustes del plugin (Settings API).
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Settings {

	const OPTION_KEY = 'estandar_dl_settings';

	/**
	 * Registra menú y ajustes.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Delivery Locator', 'estandar-delivery-locator-lite' ),
			__( 'Delivery Locator', 'estandar-delivery-locator-lite' ),
			'manage_options',
			'estandar-dl-settings',
			array( $this, 'render_page' )
		);

		if ( function_exists( 'estandar_admin_menu_add_link' ) ) {
			\estandar_admin_menu_add_link( 'Delivery Locator', 'Delivery Locator', 'manage_options', 'admin.php?page=estandar-dl-settings' );
		}
		remove_submenu_page( 'options-general.php', 'estandar-dl-settings' );
	}

	public function register_settings() {
		register_setting(
			'estandar_dl_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitiza opciones guardadas.
	 *
	 * @param array $input Input crudo.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$old   = self::get();
		$clean = array();

		$clean['whatsapp'] = isset( $input['whatsapp'] ) ? preg_replace( '/[^0-9+]/', '', (string) $input['whatsapp'] ) : '';
		$clean['email']    = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

		if ( empty( $clean['email'] ) && ! empty( $old['email'] ) ) {
			$clean['email'] = $old['email'];
		}

		$radius = isset( $input['radius_km'] ) ? floatval( $input['radius_km'] ) : 25;
		if ( $radius < 0.5 ) {
			$radius = 0.5;
		}
		if ( $radius > 500 ) {
			$radius = 500;
		}
		$clean['radius_km'] = $radius;

		$lat = isset( $input['center_lat'] ) ? floatval( $input['center_lat'] ) : -34.586;
		$lng = isset( $input['center_lng'] ) ? floatval( $input['center_lng'] ) : -70.986;
		if ( $lat < -90 || $lat > 90 ) {
			$lat = -34.586;
		}
		if ( $lng < -180 || $lng > 180 ) {
			$lng = -70.986;
		}
		$clean['center_lat'] = $lat;
		$clean['center_lng'] = $lng;

		$n8n_url = isset( $input['n8n_webhook_url'] ) ? esc_url_raw( trim( (string) $input['n8n_webhook_url'] ) ) : '';
		if ( $n8n_url && ! wp_http_validate_url( $n8n_url ) ) {
			$n8n_url = isset( $old['n8n_webhook_url'] ) ? $old['n8n_webhook_url'] : '';
		}
		$clean['n8n_webhook_url'] = $n8n_url;

		$sec_in = isset( $input['n8n_webhook_secret'] ) ? sanitize_text_field( (string) $input['n8n_webhook_secret'] ) : '';
		if ( '' === $sec_in && ! empty( $old['n8n_webhook_secret'] ) ) {
			$clean['n8n_webhook_secret'] = $old['n8n_webhook_secret'];
		} else {
			$clean['n8n_webhook_secret'] = $sec_in;
			if ( strlen( $clean['n8n_webhook_secret'] ) > 200 ) {
				$clean['n8n_webhook_secret'] = substr( $clean['n8n_webhook_secret'], 0, 200 );
			}
		}

		$clean['n8n_notify_confirm'] = ! empty( $input['n8n_notify_confirm'] ) ? 1 : 0;
		$clean['n8n_notify_email']   = ! empty( $input['n8n_notify_email'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Opciones fusionadas con defectos.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = array(
			'whatsapp'           => '',
			'email'              => get_option( 'admin_email' ),
			'radius_km'          => 25,
			'center_lat'         => -34.586,
			'center_lng'         => -70.986,
			'n8n_webhook_url'    => '',
			'n8n_webhook_secret' => '',
			'n8n_notify_confirm' => 1,
			'n8n_notify_email'   => 0,
		);
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $defaults, $stored );
	}

	/**
	 * Datos públicos para el shortcode (sin secretos).
	 *
	 * @return array
	 */
	public static function get_public_config() {
		$s = self::get();
		return array(
			'radiusKm'  => (float) $s['radius_km'],
			'centerLat' => (float) $s['center_lat'],
			'centerLng' => (float) $s['center_lng'],
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = self::get();
		$opt = self::OPTION_KEY;
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active_tab = 'orders' === $tab ? 'orders' : 'settings';
		$inside_core_shell = function_exists( 'estandar_core_is_rendering_admin_shell' ) && estandar_core_is_rendering_admin_shell();
		?>
		<?php if ( ! $inside_core_shell ) : ?>
			<div class="wrap estandar-admin">
			<h1><?php echo esc_html( 'orders' === $active_tab ? __( 'ESTANDAR Delivery Locator — Pedidos', 'estandar-delivery-locator-lite' ) : __( 'ESTANDAR Delivery Locator — Ajustes', 'estandar-delivery-locator-lite' ) ); ?></h1>
		<?php endif; ?>
			<?php self::render_tabs( $active_tab ); ?>
			<?php if ( 'orders' === $active_tab ) : ?>
				<?php if ( class_exists( 'Estandar_Delivery_Locator_Admin_Orders' ) ) : ?>
					<?php Estandar_Delivery_Locator_Admin_Orders::render_orders_table( admin_url( 'admin.php?page=estandar-dl-settings&tab=orders' ) ); ?>
				<?php endif; ?>
			<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estandar_dl_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="estandar_dl_whatsapp"><?php esc_html_e( 'Número WhatsApp', 'estandar-delivery-locator-lite' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[whatsapp]" type="text" id="estandar_dl_whatsapp" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" class="regular-text" placeholder="56912345678" />
							<p class="description"><?php esc_html_e( 'Con código de país, sin espacios ni símbolos (ej. 56912345678).', 'estandar-delivery-locator-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="estandar_dl_email"><?php esc_html_e( 'Email receptor', 'estandar-delivery-locator-lite' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[email]" type="email" id="estandar_dl_email" value="<?php echo esc_attr( $s['email'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="estandar_dl_radius"><?php esc_html_e( 'Radio de despacho (km)', 'estandar-delivery-locator-lite' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[radius_km]" type="number" id="estandar_dl_radius" value="<?php echo esc_attr( $s['radius_km'] ); ?>" step="0.1" min="0.5" max="500" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coordenadas del centro', 'estandar-delivery-locator-lite' ); ?></th>
						<td>
							<label for="estandar_dl_lat"><?php esc_html_e( 'Latitud', 'estandar-delivery-locator-lite' ); ?></label>
							<input name="<?php echo esc_attr( $opt ); ?>[center_lat]" type="text" id="estandar_dl_lat" value="<?php echo esc_attr( $s['center_lat'] ); ?>" class="regular-text" />
							<br /><br />
							<label for="estandar_dl_lng"><?php esc_html_e( 'Longitud', 'estandar-delivery-locator-lite' ); ?></label>
							<input name="<?php echo esc_attr( $opt ); ?>[center_lng]" type="text" id="estandar_dl_lng" value="<?php echo esc_attr( $s['center_lng'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Por defecto: San Fernando, Chile.', 'estandar-delivery-locator-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="estandar_dl_n8n_url"><?php esc_html_e( 'Webhook n8n (URL)', 'estandar-delivery-locator-lite' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[n8n_webhook_url]" type="url" id="estandar_dl_n8n_url" value="<?php echo esc_attr( $s['n8n_webhook_url'] ); ?>" class="large-text code" placeholder="https://…/webhook/…" />
							<p class="description"><?php esc_html_e( 'URL del nodo Webhook en n8n (POST, cuerpo JSON). Opcional.', 'estandar-delivery-locator-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="estandar_dl_n8n_secret"><?php esc_html_e( 'Secreto webhook (opcional)', 'estandar-delivery-locator-lite' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[n8n_webhook_secret]" type="password" id="estandar_dl_n8n_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $s['n8n_webhook_secret'] ) ? esc_attr__( 'Secreto guardado (escribe uno nuevo para cambiar)', 'estandar-delivery-locator-lite' ) : ''; ?>" />
							<p class="description"><?php esc_html_e( 'Si lo defines, se envía en la cabecera X-Estandar-DL-Secret (valida en n8n con un nodo IF o Header Auth).', 'estandar-delivery-locator-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Eventos hacia n8n', 'estandar-delivery-locator-lite' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[n8n_notify_confirm]" type="checkbox" value="1" <?php checked( ! empty( $s['n8n_notify_confirm'] ) ); ?> />
								<?php esc_html_e( 'Notificar al confirmar ubicación (pedido guardado)', 'estandar-delivery-locator-lite' ); ?>
							</label>
							<br />
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[n8n_notify_email]" type="checkbox" value="1" <?php checked( ! empty( $s['n8n_notify_email'] ) ); ?> />
								<?php esc_html_e( 'Notificar al enviar solicitud por correo (desde el formulario)', 'estandar-delivery-locator-lite' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p>
				<strong><?php esc_html_e( 'Shortcode:', 'estandar-delivery-locator-lite' ); ?></strong>
				<code>[estandar_delivery_locator]</code>
			</p>
			<p class="description">
				<?php esc_html_e( 'Tras cargar HTML por AJAX, ejecuta en consola o desde tu script: window.estandarDeliveryLocator.init()', 'estandar-delivery-locator-lite' ); ?>
			</p>
			<?php endif; ?>
		<?php if ( ! $inside_core_shell ) : ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_tabs( $active_tab ) {
		$tabs = array(
			'settings' => array(
				'label' => __( 'Ajustes', 'estandar-delivery-locator-lite' ),
				'url'   => admin_url( 'admin.php?page=estandar-dl-settings' ),
			),
			'orders'   => array(
				'label' => __( 'Pedidos', 'estandar-delivery-locator-lite' ),
				'url'   => admin_url( 'admin.php?page=estandar-dl-settings&tab=orders' ),
			),
		);

		if ( function_exists( 'estandar_render_tabs' ) ) {
			estandar_render_tabs( $tabs, (string) $active_tab );
			return;
		}

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Secciones', 'estandar-delivery-locator-lite' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$class = 'nav-tab';
			if ( (string) $active_tab === (string) $key ) {
				$class .= ' nav-tab-active';
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab['url'] ) . '">' . esc_html( $tab['label'] ) . '</a>';
		}
		echo '</nav>';
	}
}
