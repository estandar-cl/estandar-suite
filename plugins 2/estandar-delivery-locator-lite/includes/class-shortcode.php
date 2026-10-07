<?php
/**
 * Shortcode [estandar_delivery_locator].
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Shortcode {

	const TAG = 'estandar_delivery_locator';

	/**
	 * Evita emitir el bloque <script> de config más de una vez por página.
	 *
	 * @var bool
	 */
	private static $did_localize = false;

	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * ID HTML seguro y único por instancia.
	 *
	 * @return string
	 */
	private static function generate_instance_id() {
		return 'edl-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @param array $atts Atributos shortcode.
	 * @return string
	 */
	public function render( $atts ) {
		$config = Estandar_Delivery_Locator_Settings::get_public_config();

		wp_enqueue_style( 'estandar-dl-leaflet' );
		wp_enqueue_style( 'estandar-delivery-locator-lite-frontend' );
		wp_enqueue_script( 'estandar-delivery-locator-lite-frontend' );

		$iid = self::generate_instance_id();

		ob_start();

		// Emit config inline — more reliable than wp_localize_script which can fail
		// when the shortcode runs before wp_enqueue_scripts or inside AJAX/popup context.
		if ( ! self::$did_localize ) {
			$js_config = array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'estandar_dl_frontend' ),
				'pluginVersion' => ESTANDAR_DL_VERSION,
				'actions'       => array(
					'confirm' => Estandar_Delivery_Locator_Ajax::ACTION_CONFIRM,
					'email'   => Estandar_Delivery_Locator_Ajax::ACTION_EMAIL,
				),
				'map'    => $config,
				'waBody' => "Hola, solicito coordinar despacho.\n\nNombre: {name}\nTeléfono: {phone}\nDirección: {address}\nUbicación (mapa): {map}",
				'i18n'   => array(
					'locating'     => 'Buscando dirección…',
					'geocodeError' => 'No encontramos esa dirección. Ajusta el texto o mueve el pin en el mapa.',
					'geoDenied'    => 'No pudimos acceder a tu ubicación. Activa el permiso o mueve el pin.',
					'geoError'     => 'No se pudo obtener tu ubicación.',
					'inZone'       => 'Zona cubierta para despacho',
					'outZone'      => 'Tu ubicación está fuera del área de despacho actual',
					'saving'       => 'Guardando…',
					'emailSent'    => 'Listo: revisa tu correo de contacto si hace falta confirmación.',
					'whatsappHint' => 'Se abrirá WhatsApp con tu mensaje listo.',
					'needAddress'  => 'Escribe una dirección para ubicarla.',
					'needFields'   => 'Completa nombre, teléfono y dirección.',
					'emailInvalid' => 'Falta el token de correo. Confirma de nuevo la ubicación.',
				),
			);
			echo '<script id="estandar-dl-config">var estandarDl=' . wp_json_encode( $js_config ) . ';</script>' . "\n";
			self::$did_localize = true;
		}
		?>
		<div class="estandar-dl" id="<?php echo esc_attr( $iid ); ?>" data-estandar-dl="1">
			<div class="estandar-dl__card estandar-dl__step" data-step="1">
				<h3 class="estandar-dl__title"><?php esc_html_e( 'Datos de contacto y dirección', 'estandar-delivery-locator-lite' ); ?></h3>
				<label class="estandar-dl__label" for="<?php echo esc_attr( $iid . '-name' ); ?>"><?php esc_html_e( 'Nombre', 'estandar-delivery-locator-lite' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $iid . '-name' ); ?>" class="estandar-dl__input" autocomplete="name" maxlength="190" required />

				<label class="estandar-dl__label" for="<?php echo esc_attr( $iid . '-phone' ); ?>"><?php esc_html_e( 'Teléfono', 'estandar-delivery-locator-lite' ); ?></label>
				<input type="tel" id="<?php echo esc_attr( $iid . '-phone' ); ?>" class="estandar-dl__input" autocomplete="tel" maxlength="100" required />

				<label class="estandar-dl__label" for="<?php echo esc_attr( $iid . '-address' ); ?>"><?php esc_html_e( 'Dirección', 'estandar-delivery-locator-lite' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $iid . '-address' ); ?>" class="estandar-dl__input" autocomplete="street-address" maxlength="2000" required />

				<div class="estandar-dl__suggest" id="<?php echo esc_attr( $iid . '-suggest' ); ?>" hidden></div>

				<button type="button" class="estandar-dl__btn estandar-dl__btn--primary" id="<?php echo esc_attr( $iid . '-locate' ); ?>">
					<?php esc_html_e( 'Ubicar mi dirección', 'estandar-delivery-locator-lite' ); ?>
				</button>
			</div>

			<div class="estandar-dl__card estandar-dl__step estandar-dl__step--hidden" data-step="2" id="<?php echo esc_attr( $iid . '-map-wrap' ); ?>">
				<h3 class="estandar-dl__title"><?php esc_html_e( 'Ajusta tu ubicación en el mapa', 'estandar-delivery-locator-lite' ); ?></h3>
				<p class="estandar-dl__hint"><?php esc_html_e( 'Arrastra el pin si hace falta. Puedes usar tu ubicación actual.', 'estandar-delivery-locator-lite' ); ?></p>

				<div class="estandar-dl__map" id="<?php echo esc_attr( $iid . '-map' ); ?>" role="application" aria-label="<?php esc_attr_e( 'Mapa', 'estandar-delivery-locator-lite' ); ?>"></div>

				<p class="estandar-dl__zone" id="<?php echo esc_attr( $iid . '-zone-msg' ); ?>" aria-live="polite"></p>

				<div class="estandar-dl__row">
					<button type="button" class="estandar-dl__btn" id="<?php echo esc_attr( $iid . '-current' ); ?>">
						<?php esc_html_e( 'Usar mi ubicación actual', 'estandar-delivery-locator-lite' ); ?>
					</button>
					<button type="button" class="estandar-dl__btn estandar-dl__btn--primary" id="<?php echo esc_attr( $iid . '-confirm' ); ?>">
						<?php esc_html_e( 'Confirmar ubicación y continuar', 'estandar-delivery-locator-lite' ); ?>
					</button>
				</div>
			</div>

			<div class="estandar-dl__card estandar-dl__step estandar-dl__step--hidden" data-step="3" id="<?php echo esc_attr( $iid . '-contact' ); ?>">
				<h3 class="estandar-dl__title"><?php esc_html_e( 'Contacto para despacho', 'estandar-delivery-locator-lite' ); ?></h3>
				<p class="estandar-dl__zone" id="<?php echo esc_attr( $iid . '-final-zone' ); ?>"></p>

				<div class="estandar-dl__row estandar-dl__row--stack">
					<a class="estandar-dl__btn estandar-dl__btn--wa" id="<?php echo esc_attr( $iid . '-wa' ); ?>" href="#" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Contactar por WhatsApp', 'estandar-delivery-locator-lite' ); ?>
					</a>
					<button type="button" class="estandar-dl__btn estandar-dl__btn--primary" id="<?php echo esc_attr( $iid . '-send-email' ); ?>">
						<?php esc_html_e( 'Enviar solicitud por correo', 'estandar-delivery-locator-lite' ); ?>
					</button>
				</div>
				<p class="estandar-dl__notice" id="<?php echo esc_attr( $iid . '-email-status' ); ?>" aria-live="polite"></p>
			</div>

			<p class="estandar-dl__error" id="<?php echo esc_attr( $iid . '-error' ); ?>" role="alert" hidden></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
