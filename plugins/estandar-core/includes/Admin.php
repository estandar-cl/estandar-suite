<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI — top-level "Estandar Suite" menu + Core page (health, destinations,
 * secrets, adoption). Product pages (Capture/Brain/Flow/Insights) register as
 * submenus via the `estandar_suite_admin_register` hook (Core owns the menu).
 */
class Admin
{
	/**
	 * Backend ordenado — productos ya revisados. La línea 01 de tabs, el submenú
	 * lateral e Inicio solo muestran lo revisado. Aprobados para exponer:
	 * Core, Capture, Brain, Flow e Insights (revisados; ROADMAP S08/S09, 07/09).
	 */
	private const ORDER_PRODUCTS_REVIEWED = array( 'inicio', 'core', 'capture', 'brain', 'flow', 'insights', 'otros' );

	/**
	 * DYNAMIC_TABS_V1: plugin principal de cada producto de la línea 01. Un
	 * producto solo se muestra si su plugin está activo (evita tabs "fantasma"
	 * que abren una página cuyo submenú nunca se registró). `inicio`, `core` y
	 * `otros` no dependen de plugin (core = este plugin; siempre visibles).
	 */
	private const PRODUCT_PLUGINS = array(
		'capture'  => 'estandar-capture/estandar-capture.php',
		'brain'    => 'estandar-brain/estandar-brain.php',
		'flow'     => 'estandar-flow/estandar-flow.php',
		'insights' => 'estandar-insights/estandar-insights.php',
	);

	/**
	 * Backend ordenado — módulos de la línea 02 del Core ya revisados. Vacío =
	 * ninguno a la vista todavía (Enlaces seguros / Correo / Claves IA / Proyectos
	 * / Integraciones siguen operativos por URL directa mientras se revisan). Al
	 * revisar uno, se agrega su clave aquí.
	 */
	private const ORDER_CORE_MODULES_REVIEWED = array();

	public static function init(): void
	{
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'cleanup_sidebar' ), 999 );
		add_action( 'admin_post_estandar_suite_leads_regenerate', array( __CLASS__, 'handle_leads_regenerate' ) );
		add_action( 'admin_post_estandar_suite_core_operators_save', array( __CLASS__, 'handle_operators_save' ) );
		add_action( 'admin_post_estandar_suite_core_operators_add', array( __CLASS__, 'handle_operators_add' ) );
		// Resumen periódico de leads (LEAD_DIGEST_V1): opt-in + CC por proyecto.
		add_action( 'admin_post_estandar_suite_core_leads_digest_save', array( __CLASS__, 'handle_save_leads_digest' ) );
		add_action( 'admin_post_estandar_suite_core_leads_digest_test', array( __CLASS__, 'handle_test_leads_digest' ) );
		// PROYECTOS (manager en Core).
		add_action( 'admin_post_estandar_suite_core_projects_save', array( __CLASS__, 'handle_save_project' ) );
		add_action( 'admin_post_estandar_suite_core_projects_archive', array( __CLASS__, 'handle_project_archive' ) );
		add_action( 'admin_post_estandar_suite_core_projects_bulk', array( __CLASS__, 'handle_projects_bulk' ) );
		add_action( 'admin_post_estandar_suite_core_projects_delete', array( __CLASS__, 'handle_projects_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_project_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_nav_assets' ) );
		add_action( 'admin_post_estandar_suite_leads_operator', array( __CLASS__, 'handle_leads_operator' ) );
		add_action( 'admin_post_estandar_suite_leads_notifier', array( __CLASS__, 'handle_leads_notifier' ) );
		add_action( 'admin_post_estandar_suite_leads_gate_methods', array( __CLASS__, 'handle_leads_gate_methods' ) );
		add_action( 'admin_post_estandar_suite_save_telemetry_settings', array( __CLASS__, 'handle_save_telemetry_settings' ) );
		add_action( 'admin_post_estandar_suite_save_menu_icon', array( __CLASS__, 'handle_save_menu_icon' ) );
		add_action( 'admin_post_estandar_suite_core_save_identity', array( __CLASS__, 'handle_save_identity' ) );
		add_action( 'admin_post_estandar_suite_save_visibility', array( __CLASS__, 'handle_save_visibility' ) );
		add_action( 'admin_post_estandar_suite_reset_visibility', array( __CLASS__, 'handle_reset_visibility' ) );
		add_action( 'admin_post_estandar_suite_core_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		// Correo — SMTP nativo de la Suite.
		add_action( 'admin_post_estandar_suite_core_save_mail', array( __CLASS__, 'handle_save_mail' ) );
		add_action( 'admin_post_estandar_suite_core_save_mail_monitor', array( __CLASS__, 'handle_save_mail_monitor' ) );
		add_action( 'admin_post_estandar_suite_core_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		// Correo — Plantillas de correo (MAIL_TEMPLATES_V1, Fase 1 registro/editor).
		add_action( 'admin_post_estandar_suite_core_save_mail_templates', array( __CLASS__, 'handle_save_mail_templates' ) );
		add_action( 'admin_post_estandar_suite_core_set_default_template', array( __CLASS__, 'handle_set_default_template' ) );
		add_action( 'admin_post_estandar_suite_core_delete_template', array( __CLASS__, 'handle_delete_template' ) );
		// Branding por proyecto (PROJECT_BRANDING_V1): skins del mismo motor.
		add_action( 'admin_post_estandar_suite_core_save_project_branding', array( __CLASS__, 'handle_save_project_branding' ) );
		// Claves API compartidas (vault v2).
		add_action( 'admin_post_estandar_suite_core_save_ai_keys', array( __CLASS__, 'handle_save_ai_keys' ) );
		add_action( 'wp_ajax_estandar_suite_reveal_ai_key', array( __CLASS__, 'handle_reveal_ai_key' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_adopt_ai_keys' ) );
		// Actualizaciones de la suite (equivalente a update-core.php).
		add_action( 'admin_post_estandar_suite_updates', array( __CLASS__, 'handle_updates' ) );
		// Acceso rápido en la barra superior (frontend + backend). Se registra en
		// init() (no en menu() que es admin-only) para que el menú "Estandar Suite"
		// aparezca también en la admin bar del frontend.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 40 );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			// QUALIFY_CONFIG_V1: criterio Frío/Tibio/Caliente del correo al
			// operador (formularios con plazo de compra + financiamiento).
			'qualification' => array(
				'plazo_asap'      => array( 'type' => 'text', 'label' => 'Plazo · lo antes posible', 'default' => '3' ),
				'plazo_m1_3'      => array( 'type' => 'text', 'label' => 'Plazo · 1-3 meses', 'default' => '2' ),
				'plazo_m3_6'      => array( 'type' => 'text', 'label' => 'Plazo · 3-6 meses', 'default' => '1' ),
				'plazo_exploring' => array( 'type' => 'text', 'label' => 'Plazo · solo averiguando', 'default' => '0' ),
				'fin_contado'     => array( 'type' => 'text', 'label' => 'Financiamiento · contado/ahorro', 'default' => '3' ),
				'fin_subsidio'    => array( 'type' => 'text', 'label' => 'Financiamiento · subsidio', 'default' => '2' ),
				'fin_credito'     => array( 'type' => 'text', 'label' => 'Financiamiento · crédito hipotecario', 'default' => '1' ),
				'fin_nose'        => array( 'type' => 'text', 'label' => 'Financiamiento · aún no sé', 'default' => '0' ),
				'hot'             => array( 'type' => 'text', 'label' => 'Umbral Caliente (suma ≥)', 'default' => '5' ),
				'warm'            => array( 'type' => 'text', 'label' => 'Umbral Tibio (suma ≥)', 'default' => '2' ),
			),
		);
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_core_' . $feature . '_settings', self::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
	}

	/**
	 * Correo — SMTP nativo de la Suite (F·estandar.cl).
	 * Guarda en las opciones planas que lee SmtpTransport; con estas opciones
	 * TODOS los wp_mail del sitio (incluidos plugins legacy) pasan por SMTP.
	 */
	public static function handle_save_mail(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_mail' );
		update_option( 'estandar_suite_core_smtp_host', sanitize_text_field( (string) ( $_POST['smtp_host'] ?? '' ) ), false );
		update_option( 'estandar_suite_core_smtp_port', (int) ( $_POST['smtp_port'] ?? 587 ), false );
		update_option( 'estandar_suite_core_smtp_user', sanitize_text_field( (string) ( $_POST['smtp_user'] ?? '' ) ), false );
		update_option( 'estandar_suite_core_smtp_secure', in_array( (string) ( $_POST['smtp_secure'] ?? 'tls' ), array( 'tls', 'ssl', '' ), true ) ? (string) $_POST['smtp_secure'] : 'tls', false );
		update_option( 'estandar_suite_core_smtp_auth', empty( $_POST['smtp_auth'] ) ? 0 : 1, false );
		// La contraseña solo se actualiza si viene (no se pisa con vacío).
		if ( isset( $_POST['smtp_pass'] ) && (string) $_POST['smtp_pass'] !== '' ) {
			update_option( 'estandar_suite_core_smtp_pass', (string) $_POST['smtp_pass'], false );
		}
		if ( isset( $_POST['smtp_clear_pass'] ) && $_POST['smtp_clear_pass'] === '1' ) {
			update_option( 'estandar_suite_core_smtp_pass', '', false );
		}
		// El logo por defecto se gestiona en Correo → Identidad (handle_save_identity),
		// no en el formulario de transporte, para tener un único editor.
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&cc=avanzado&mail_saved=1' ) );
		exit;
	}

	/** Guarda SOLO las copias de monitoreo (BCC) — vista "Configuración básica". */
	public static function handle_save_mail_monitor(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_mail_monitor' );
		update_option( 'estandar_suite_core_mail_monitor_bcc', sanitize_text_field( (string) ( $_POST['monitor_bcc'] ?? '' ) ), false );
		update_option( \Estandar\Core\Mail::MONITOR_ENABLED_OPTION, empty( $_POST['monitor_enabled'] ) ? '0' : '1', false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&cc=basica&monitor_saved=1' ) );
		exit;
	}

	/** Correo de prueba: envía a la dirección indicada usando Core\Mail (SMTP). */
	public static function handle_test_mail(): void
	{		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_test_mail' );
		$to = sanitize_email( (string) ( $_POST['test_to'] ?? '' ) );
		$r = array( 'ok' => false, 'error' => 'Dirección inválida.' );
		if ( is_email( $to ) ) {
			$r = \Estandar\Core\Mail::send( array(
				'to'      => $to,
				'subject' => 'Prueba de correo — Estandar Suite',
				'html'    => '<p>Este es un correo de prueba del motor de la Suite Estandar. Si lo recibes, el transporte SMTP está funcionando.</p>',
				'alt'     => 'Este es un correo de prueba del motor de la Suite Estandar. Si lo recibes, el transporte SMTP está funcionando.',
			) );
		}
		$ok = ! empty( $r['ok'] );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&mail_test=' . ( $ok ? '1' : '0' ) . '&mail_test_err=' . rawurlencode( (string) ( $r['error'] ?? '' ) ) . '&mail_test_transport=' . rawurlencode( (string) ( $r['transport'] ?? '' ) ) ) );
		exit;
	}

	/** admin_post handler: guarda el branding (skin) por proyecto. */
	public static function handle_save_project_branding(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_project_branding' );
		$raw = isset( $_POST['skins'] ) && is_array( $_POST['skins'] ) ? $_POST['skins'] : array();
		$all = array();
		foreach ( $raw as $slug => $skin ) {
			$slug = sanitize_title( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$skin = is_array( $skin ) ? $skin : array();
			$all[ $slug ] = array(
				'brand_color' => sanitize_hex_color( (string) ( $skin['brand_color'] ?? '' ) ),
				'logo_url'    => esc_url_raw( (string) ( $skin['logo_url'] ?? '' ) ),
				'header_text' => sanitize_text_field( (string) ( $skin['header_text'] ?? '' ) ),
				'footer_text' => wp_kses_post( (string) ( $skin['footer_text'] ?? '' ) ),
			);
		}
		update_option( 'estandar_suite_core_project_branding', $all );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_branding_saved=1' ) );
		exit;
	}

	/** One-time adoption of legacy AI keys into the v2 vault (admin only). */
	public static function maybe_adopt_ai_keys(): void
	{
		AiKeys::adopt_legacy_once();
	}

	/** Persist the shared AI key vault (whitelisted providers only). */
	public static function handle_save_ai_keys(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_ai_keys' );

		$providers = AiKeys::providers();
		$raw       = isset( $_POST['ai_keys'] ) && is_array( $_POST['ai_keys'] ) ? $_POST['ai_keys'] : array();
		$settings  = array();

		foreach ( $providers as $provider => $label ) {
			// Password inputs are masked; an empty field means "keep current".
			$incoming = (string) ( $raw[ $provider ] ?? '' );
			$incoming = trim( str_replace( array( "\r", "\n", "\t" ), '', $incoming ) );
			if ( $incoming !== '' ) {
				$settings[ $provider ] = $incoming;
			}
		}

		$current = AiKeys::get_settings();
		foreach ( $providers as $provider => $label ) {
			$settings[ $provider ] = isset( $settings[ $provider ] ) ? $settings[ $provider ] : (string) ( $current[ $provider ] ?? '' );
		}
		$settings['adopted'] = empty( $current['adopted'] ) ? 0 : 1;

		// Clear flags: empty value + explicit clear request.
		if ( ! empty( $_POST['ai_clear'] ) && is_array( $_POST['ai_clear'] ) ) {
			foreach ( array_keys( $providers ) as $provider ) {
				if ( ! empty( $_POST['ai_clear'][ $provider ] ) ) {
					$settings[ $provider ] = '';
				}
			}
		}

		$active = sanitize_key( (string) ( $_POST['ai_active_provider'] ?? 'deepseek' ) );
		if ( isset( $providers[ $active ] ) ) {
			$settings['active_provider'] = $active;
		}
		$settings['default_model'] = sanitize_text_field( (string) ( $_POST['ai_default_model'] ?? '' ) );

		AiKeys::update_settings( $settings );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=claves&ai_saved=1' ) );
		exit;
	}

	/** AJAX: reveal a provider key to an authenticated admin (manage_options).
	 *  The key never ships in the initial HTML; it is fetched on demand with a
	 *  fresh nonce + capability check, so it is only reachable by the operator. */
	public static function handle_reveal_ai_key(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}
		check_ajax_referer( 'estandar_suite_reveal_ai_key', 'nonce' );
		$provider = sanitize_key( (string) ( $_POST['provider'] ?? '' ) );
		$providers = AiKeys::providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			wp_send_json_error( array( 'message' => 'Proveedor desconocido.' ), 400 );
		}
		$key = AiKeys::get( $provider );
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => 'Sin clave guardada.' ), 404 );
		}
		wp_send_json_success( array( 'key' => $key ) );
	}

	/**
	 * admin_post handler del panel de Actualizaciones: comprobar de nuevo o
	 * actualizar uno/varios plugins de la suite desde updates.estandar.cl.
	 */
	public static function handle_updates(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_updates' );
		$base = admin_url( 'admin.php?page=estandar-suite&tab=avanzado&sub=actualizaciones' );
		$action = sanitize_key( (string) ( $_POST['es_upd_action'] ?? '' ) );

		if ( $action === 'check' ) {
			Updates::refresh();
			wp_safe_redirect( $base . '&checked=1' );
			exit;
		}

		if ( $action === 'policy' ) {
			$raw = sanitize_text_field( (string) ( $_POST['es_upd_policy'] ?? 'auto' ) );
			Updates::set_policy( $raw );
			wp_safe_redirect( $base . '&policy=1' );
			exit;
		}

		if ( $action === 'update' ) {
			$raw = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? $_POST['slugs'] : array();
			$slugs = array();
			foreach ( $raw as $s ) {
				$s = sanitize_key( (string) $s );
				if ( $s !== '' ) {
					$slugs[] = $s;
				}
			}
			$ok  = array();
			$bad = array();
			foreach ( array_unique( $slugs ) as $slug ) {
				// UPDATE_PRESERVE_STATE_V1: conserva el estado activo del plugin
				// tras actualizar (el upgrader lo desactiva y no lo reactiva solo).
				$r = Updates::update_plugin_preserving_state( $slug );
				if ( $r['ok'] ) {
					$ok[] = $slug;
				} else {
					$bad[] = $slug . ' · ' . $r['message'];
				}
			}
			$url = $base;
			if ( $ok ) {
				$url .= '&updated=' . rawurlencode( implode( ',', $ok ) );
			}
			if ( $bad ) {
				$url .= '&error=' . rawurlencode( implode( ',', $bad ) );
			}
			wp_safe_redirect( $url );
			exit;
		}

		wp_safe_redirect( $base );
		exit;
	}

	public static function menu(): void
	{
		add_menu_page(
			'Estandar Suite',
			'Estandar Suite',
			'manage_options',
			'estandar-suite',
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			3
		);

		// Primer submenú / pestaña transversal: "Inicio" (portada de la suite).
		add_submenu_page(
			'estandar-suite',
			'Inicio',
			'Inicio',
			'manage_options',
			'estandar-suite-inicio',
			array( __CLASS__, 'render_inicio' )
		);

		// Rename the implicit first item to "Core" (it is the Core product page).
		add_submenu_page(
			'estandar-suite',
			'Core',
			'Core',
			'manage_options',
			'estandar-suite',
			array( __CLASS__, 'render' )
		);

		// Submenus registered by the products (filter for extensibility).
		do_action( 'estandar_suite_admin_register' );

		// "Otros plugins" (fuera de la suite): se agrega al final para espejar el
		// orden de la línea 01 de tabs (Core…Insights, luego Otros plugins).
		add_submenu_page(
			'estandar-suite',
			'Otros plugins',
			'Otros plugins',
			'manage_options',
			'estandar-suite-otros',
			array( __CLASS__, 'render_otros' )
		);
	}

	/** The configured emoji (single source of truth for sidebar + top bar). */
	public static function menu_emoji(): string
	{
		$emoji = (string) get_option( 'estandar_suite_core_menu_icon', '🐲' );
		if ( $emoji === '' ) {
			$emoji = '🐲';
		}
		return $emoji;
	}

	/** Configurable emoji icon (option `estandar_suite_core_menu_icon`). */
	public static function menu_icon(): string
	{
		$emoji = self::menu_emoji();
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><text y="15" font-size="15">' . $emoji . '</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/** Top admin bar: "Estandar Suite" quick access with per-product links. */
	public static function admin_bar( \WP_Admin_Bar $bar ): void
	{
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$emoji = self::menu_emoji();
		$bar->add_node( array(
			'id'     => 'estandar-suite',
			'title'  => '<span style="font-size:14px;">' . esc_html( $emoji ) . '</span> <span class="ab-label">Estandar Suite</span>',
			'href'   => admin_url( 'admin.php?page=estandar-suite' ),
			'meta'   => array( 'title' => 'Estandar Suite' ),
		) );
		// Espejo de la línea 01 de tabs: mismos productos, mismo orden (incluye
		// "Otros plugins" para los plugins Estandar fuera de la suite).
		foreach ( self::products() as $id => $l ) {
			$bar->add_node( array(
				'id'     => 'estandar-suite-' . $id,
				'parent' => 'estandar-suite',
				'title'  => $l['label'],
				'href'   => admin_url( 'admin.php?page=' . $l['page'] ),
			) );
		}

		// CAPTURE_ACTIVE_V1: al pasar el ratón sobre "Capture" en una página del
		// frontend, se despliegan los forms de la suite que están activos en esa
		// página (shortcode [estandar_form_v2 id="N"]), con enlace directo al
		// editor del maquetador.
		self::admin_bar_capture_forms( $bar );
	}

	/** CAPTURE_ACTIVE_V1: submenú "Capture" con los forms activos de la página
	 *  (shortcodes estandar_form_v2 / estandar_form_gate / estandar_camara_registros). */
	private static function admin_bar_capture_forms( \WP_Admin_Bar $bar ): void
	{
		if ( is_admin() || ! is_admin_bar_showing() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post || empty( $post->post_content ) || ! is_string( $post->post_content ) ) {
			return;
		}
		if ( ! class_exists( '\Estandar\Capture\Forms' ) ) {
			return;
		}
		$ids = array();
		// CAPTURE_ACTIVE_V1 (cualquier shortcode de form): se detectan los forms
		// activos en la página actual ya sea con `[estandar_form_v2 id="N"]`,
		// `[estandar_form_gate form="N"]` (gate de operador) o
		// `[estandar_camara_registros form="N"]` (visor de registros).
		$form_shortcodes = array( 'estandar_form_v2', 'estandar_form_gate', 'estandar_camara_registros' );
		$sc_pattern      = '/\[(?:' . implode( '|', array_map( 'preg_quote', $form_shortcodes, array_fill( 0, count( $form_shortcodes ), '/' ) ) ) . ')[^\]]*\]/';
		if ( preg_match_all( $sc_pattern, $post->post_content, $m ) ) {
			foreach ( $m[0] as $sc ) {
				$fid = 0;
				if ( preg_match( '/(?:id|form)=["\'](\d+)["\']/', $sc, $im ) ) {
					$fid = (int) $im[1];
				}
				if ( $fid > 0 ) {
					$ids[] = $fid;
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			$form  = \Estandar\Capture\Forms::get( $id );
			$title = $form ? $form['name'] : ( 'Form #' . $id );
			$bar->add_node( array(
				'id'     => 'estandar-suite-capture-form-' . $id,
				'parent' => 'estandar-suite-capture',
				'title'  => esc_html( $title ),
				'href'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&editar=' . $id ),
			) );
		}
	}

	/**
	 * Render the shared 4-tab navigation for an Estandar Suite page.
	 * The 4 visible tabs group the 7 internal functional sets:
	 * Estado (esquema+eventos) · Configuración (dominio+UI) · Datos (adopción) · Avanzado (REST+CLI).
	 *
	 * @param string $page   Menu/slug of the current page (admin.php?page=...).
	 * @param string $active Current tab key.
	 * @param array  $tabs   Map of tab key => label.
	 */
	public static function render_tabs( string $page, string $active, array $tabs ): void
	{
		if ( ! $tabs ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secciones de ' . esc_attr( $page ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url  = admin_url( 'admin.php?page=' . $page . '&tab=' . $key );
			$aria = ( $key === $active ) ? ' aria-current="page"' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $key === $active ? ' nav-tab-active' : '' ) . '"' . $aria . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * DYNAMIC_TABS_V1: ¿está activo el plugin `$file` (ruta relativa, ej.
	 * `estandar-capture/estandar-capture.php`)? Robusto: usa `is_plugin_active`
	 * si existe (wp-admin); si no, lee las options de plugins activos. Si NO se
	 * puede determinar, devuelve `true` (visible: nunca romper front/CLI). Una
	 * ruta vacía/desconocida también se considera activa (no ocultar por error).
	 */
	public static function plugin_active( string $file ): bool
	{
		$file = trim( $file );
		if ( '' === $file ) {
			return true;
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return (bool) is_plugin_active( $file );
		}

		$active = get_option( 'active_plugins', null );
		if ( ! is_array( $active ) ) {
			if ( function_exists( 'get_site_option' ) ) {
				$network = get_site_option( 'active_sitewide_plugins', null );
				if ( is_array( $network ) ) {
					return isset( $network[ $file ] );
				}
			}
			return true; // No se puede determinar → no ocultar.
		}

		if ( in_array( $file, $active, true ) ) {
			return true;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) && isset( $network[ $file ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Lista única de productos/páginas de la Suite (mismo orden en la línea 01
	 *  de tabs, en la barra superior y en el submenú lateral). Solo expone los
	 *  productos revisados (ORDER_PRODUCTS_REVIEWED); los demás quedan ocultos de
	 *  la navegación hasta revisarse (URL directa sigue operativa).
	 *  DYNAMIC_TABS_V1: además, cada producto con plugin propio (PRODUCT_PLUGINS)
	 *  solo aparece si ese plugin está activo. Filtro `estandar_suite_products`
	 *  para extensibilidad (recibe key=>meta, debe devolver el mismo formato). */
	public static function products(): array
	{
		$all = array(
			'inicio'   => array( 'page' => 'estandar-suite-inicio', 'label' => 'Inicio', 'desc' => 'Portada de la Suite' ),
			'core'     => array( 'page' => 'estandar-suite',         'label' => 'Core',     'desc' => 'Núcleo, correo y comunicaciones' ),
			'capture'  => array( 'page' => 'estandar-suite-capture', 'label' => 'Capture',  'desc' => 'Captación omnicanal (forms, chatbot, popups)' ),
			'brain'    => array( 'page' => 'estandar-suite-brain',   'label' => 'Brain',    'desc' => 'Intención y decisión comercial' ),
			'flow'     => array( 'page' => 'estandar-suite-flow',    'label' => 'Flow',     'desc' => 'Flujos comerciales' ),
			'insights' => array( 'page' => 'estandar-suite-insights','label' => 'Insights', 'desc' => 'Análisis y métricas' ),
			'otros'    => array( 'page' => 'estandar-suite-otros',   'label' => 'Otros plugins', 'desc' => 'Plugins Estandar fuera de la suite' ),
		);
		$flags = array_flip( self::ORDER_PRODUCTS_REVIEWED );
		$out   = array();
		foreach ( $all as $key => $p ) {
			if ( ! isset( $flags[ $key ] ) ) {
				continue;
			}
			if ( isset( self::PRODUCT_PLUGINS[ $key ] ) && ! self::plugin_active( self::PRODUCT_PLUGINS[ $key ] ) ) {
				continue; // Plugin del producto inactivo → sin tab fantasma.
			}
			$out[ $key ] = $p;
		}
		$filtered = apply_filters( 'estandar_suite_products', $out );
		return is_array( $filtered ) ? $filtered : $out;
	}

	/** Módulos candidatos de la línea 02 del Core (backend ordenado). */
	public static function core_modules_orden(): array
	{
		return array(
			'correo'        => 'Correo',
			'claves'        => 'Claves IA',
			'proyectos'     => 'Proyectos',
		);
	}

	/** Módulos de la línea 02 del Core ya revisados (se revelan al revisarse). */
	public static function reviewed_core_modules(): array
	{
		$all  = self::core_modules_orden();
		$keep = array_flip( self::ORDER_CORE_MODULES_REVIEWED );
		$out  = array();
		foreach ( $all as $key => $label ) {
			if ( isset( $keep[ $key ] ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out;
	}

	/** Capa de navegación superior de la Suite: barra de marca naranja + línea
	 *  01 de tabs transversal (Core / Capture / Brain / Flow / Insights /
	 *  Otros plugins), espejo de la barra superior y del submenú lateral. */
	/** Núcleo único de la fila de tabs: misma marca/estilo para TODAS las
	 *  líneas (productos y módulos). Si cambia el aspecto, cambia en todas. */
	public static function render_nav_tabs( array $items ): void
	{
		if ( ! $items ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Navegación de la suite">';
		foreach ( $items as $it ) {
			$aria = ! empty( $it['active'] ) ? ' aria-current="page"' : '';
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( (string) $it['href'] ),
				! empty( $it['active'] ) ? ' nav-tab-active' : '',
				$aria,
				esc_html( (string) $it['label'] )
			);
		}
		echo '</nav>';
	}

	/** Línea "un tab por módulo real" (tab=config&sub): usa render_nav_tabs. */
	public static function render_module_tabs( string $page, string $tab, string $sub, array $modules ): void
	{
		$items = array();
		foreach ( $modules as $key => $label ) {
			$items[] = array(
				'href'   => admin_url( 'admin.php?page=' . $page . '&tab=config&sub=' . $key ),
				'label'  => (string) $label,
				'active' => ( $tab === 'config' && $sub === (string) $key ),
			);
		}
		self::render_nav_tabs( $items );
	}

	public static function render_product_header(): void
	{
		$products = self::products();
		$current = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		$emoji   = self::menu_emoji();
		$bg      = '#f95b1f'; // naranja de la suite legacy (utillity bar).

		// Barra de marca (naranja). Sin radios ni márgenes externos: queda
		// pegada al borde superior/izquierdo del contenido. Los accesos a
		// productos viven en la primera línea de tabs transversal.
		echo '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:' . esc_attr( $bg ) . ';color:#fff;border-radius:0;padding:12px 20px;margin:0 0 0 -20px;box-shadow:0 1px 2px rgba(0,0,0,.08);">';

		// Marca (lista para branding: emoji + nombre + subtítulo).
		echo '<div style="display:flex;align-items:center;gap:10px;min-width:180px;">';
		echo '<span style="font-size:22px;line-height:1;filter:drop-shadow(0 1px 1px rgba(0,0,0,.15));">' . esc_html( $emoji ) . '</span>';
		echo '<span style="display:flex;flex-direction:column;line-height:1.15;">';
		echo '<strong style="font-size:15px;letter-spacing:.02em;">Estandar Suite</strong>';
		echo '<span style="font-size:11px;opacity:.85;">Plataforma comercial</span>';
		echo '</span>';
		echo '</div>';
		echo '</div>';

		// Primera línea de tabs — transversal a TODO el backend (productos).
		// Mismo motor de estilos que la línea de módulos (render_nav_tabs);
		// margin-bottom:-10px compensa el margin-top del .wrap siguiente.
		$items = array();
		foreach ( $products as $p ) {
			$items[] = array(
				'href'   => admin_url( 'admin.php?page=' . $p['page'] ),
				'label'  => $p['label'],
				'active' => ( $current === $p['page'] ),
			);
		}
		// "Panel de Leads" ya NO es un acceso de primer nivel: vive dentro de
		// Core → Config → Leads (sub-pestaña "Panel de Leads").
		echo '<div style="margin:0 0 -10px;">';
		self::render_nav_tabs( $items );
		echo '</div>';
	}

	/** Página "Inicio" de la Suite (primera pestaña transversal). */
	public static function render_inicio(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		\Estandar\Core\Admin::render_product_header();
		echo '<div class="wrap">';
		echo '<h1>Bienvenido a Estandar Suite</h1>';
		echo '<p class="description" style="max-width:820px;">Punto de entrada de la plataforma. Desde aquí navegas entre los productos usando la primera línea de tabs (igual en la barra superior y en el menú lateral).</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-top:16px;max-width:1100px;">';
		foreach ( self::products() as $key => $p ) {
			if ( $key === 'inicio' ) {
				continue;
			}
			printf(
				'<a href="%s" style="display:block;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;text-decoration:none;color:inherit;">'
				. '<strong>%s</strong><br><span class="description">%s</span></a>',
				esc_url( admin_url( 'admin.php?page=' . $p['page'] ) ),
				esc_html( $p['label'] ),
				esc_html( $p['desc'] )
			);
		}
		echo '</div></div>';
	}

	/** Página "Otros plugins" (fuera de la suite) — se cuelgan automáticamente
	 *  todos los plugins activos de Estandar Estudio que NO son los productos de
	 *  la línea 01 (Core/Capture/Brain/Flow/Insights). Solo lectura + enlace. */
	public static function render_otros(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		\Estandar\Core\Admin::render_product_header();

		// Productos que ya tienen pestaña propia en la línea 01.
		$product_dirs = array( 'estandar-core', 'estandar-capture', 'estandar-brain', 'estandar-flow', 'estandar-insights' );
		$others       = array();
		$others_by_dir = array();
		if ( function_exists( 'get_plugins' ) ) {
			$registry = (array) get_plugins();

			// OTHERS_PLUGINS_V1(1): autodetección clásica — plugins Estandar
			// (directorio `estandar-*`) activos y fuera de la línea 01.
			foreach ( $registry as $file => $data ) {
				$dir = strtok( $file, '/' );
				if ( $dir === false || strpos( $dir, 'estandar-' ) !== 0 ) {
					continue;
				}
				if ( in_array( $dir, $product_dirs, true ) ) {
					continue;
				}
				if ( ! self::plugin_active( $file ) ) {
					continue;
				}
				$others[ $dir ] = array(
					'dir'   => $dir,
					'name'  => (string) ( $data['Name'] ?? $dir ),
					'desc'  => (string) ( $data['Description'] ?? '' ),
					'ver'   => (string) ( $data['Version'] ?? '' ),
					'file'  => $file,
				);
			}

			// OTHERS_PLUGINS_V1(2): plugins NO prefijados (p. ej. `veloz`) que se
			// registran vía filtro. Solo si existen en el registro y están activos.
			$extra = apply_filters( 'estandar_suite_otros_plugins', array() );
			foreach ( (array) $extra as $file ) {
				$file = (string) $file;
				if ( '' === $file || ! isset( $registry[ $file ] ) ) {
					continue;
				}
				$dir = strtok( $file, '/' );
				if ( $dir === false || in_array( $dir, $product_dirs, true ) ) {
					continue;
				}
				if ( ! self::plugin_active( $file ) ) {
					continue;
				}
				if ( isset( $others[ $dir ] ) ) {
					continue; // Dedup por directorio.
				}
				$data = $registry[ $file ];
				$others[ $dir ] = array(
					'dir'   => $dir,
					'name'  => (string) ( $data['Name'] ?? $dir ),
					'desc'  => (string) ( $data['Description'] ?? '' ),
					'ver'   => (string) ( $data['Version'] ?? '' ),
					'file'  => $file,
				);
			}
		}

		$others = array_values( $others );
		usort( $others, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		foreach ( $others as $o ) {
			$others_by_dir[ $o['dir'] ] = $o;
		}

		echo '<div class="wrap">';

		if ( ! $others ) {
			echo '<div class="notice notice-info inline" style="max-width:820px;"><p>No hay plugins Estandar activos fuera de la suite por ahora.</p></div>';
			echo '</div>';
			return;
		}

		// Subtab por plugin (mismo motor de tabs que el resto del backend).
		$current_p = sanitize_key( (string) ( $_GET['p'] ?? '' ) );
		if ( ! isset( $others_by_dir[ $current_p ] ) ) {
			$current_p = $others[0]['dir'];
		}
		$items = array();
		foreach ( $others as $o ) {
			$items[] = array(
				'href'   => admin_url( 'admin.php?page=estandar-suite-otros&p=' . $o['dir'] ),
				'label'  => $o['name'],
				'active' => ( $o['dir'] === $current_p ),
			);
		}
		self::render_nav_tabs( $items );

		$sel = $others_by_dir[ $current_p ];

		// OTHERS_PLUGIN_CONTENT_V1: un plugin Estandar fuera de la suite puede
		// renderizar su propia pantalla aquí (en vez de la tarjeta informativa).
		$embed = apply_filters( 'estandar_suite_otros_content', '', $current_p, $sel );
		if ( is_string( $embed ) && $embed !== '' ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-top:14px;">';
			echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- producido por el plugin dueño.
			echo '</div></div>';
			return;
		}

		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:860px;margin-top:14px;">';
		echo '<h2 style="margin-top:0;">' . esc_html( $sel['name'] ) . '</h2>';
		echo '<p class="description">' . esc_html( $sel['desc'] ) . '</p>';
		echo '<p><span class="description">Directorio:</span> <code>' . esc_html( $sel['dir'] ) . '</code><br>';
		echo '<span class="description">Versión:</span> <code>' . esc_html( $sel['ver'] ) . '</code></p>';
		echo '</div></div>';
	}

	/** El submenú lateral de "Estandar Suite" espeja la línea 01 de tabs:
	 *  se descartan submenús extra (duplicate, operator-activity, etc.) que no
	 *  forman parte de la estructura transversal. Solo visual: las páginas
	 *  siguen operativas por su URL. */
	public static function cleanup_sidebar(): void
	{
		if ( empty( $GLOBALS['submenu']['estandar-suite'] ) || ! is_array( $GLOBALS['submenu']['estandar-suite'] ) ) {
			return;
		}
		$allowed = array( 'estandar-suite' );
		foreach ( self::products() as $p ) {
			$allowed[] = $p['page'];
		}
		$by_slug = array();
		foreach ( $GLOBALS['submenu']['estandar-suite'] as $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], $allowed, true ) ) {
				$by_slug[ $item[2] ] = $item;
			}
		}
		// Orden exacto de la línea 01 (Inicio primero; el ítem estandar-suite es
		// el Core, que WP coloca como primero por defecto — aquí se reordena).
		$order = array( 'estandar-suite-inicio', 'estandar-suite' );
		foreach ( self::products() as $p ) {
			if ( $p['page'] !== 'estandar-suite' && $p['page'] !== 'estandar-suite-inicio' ) {
				$order[] = $p['page'];
			}
		}
		$clean = array();
		foreach ( $order as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$clean[] = $by_slug[ $slug ];
			}
		}
		$GLOBALS['submenu']['estandar-suite'] = $clean;
	}

	/** OPERADORES_UI_V1: guarda email/whatsapp/activo de es_operators. */
	public static function handle_operators_save(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_operators_save' );
		$list = isset( $_POST['operators'] ) && is_array( $_POST['operators'] ) ? wp_unslash( $_POST['operators'] ) : array();
		if ( $list ) {
			global $wpdb;
			$table = $wpdb->prefix . 'es_operators';
			foreach ( $list as $id => $fields ) {
				$id = (int) $id;
				if ( $id <= 0 || ! is_array( $fields ) ) {
					continue;
				}
				$set = array(
					'email'           => sanitize_email( (string) ( $fields['email'] ?? '' ) ),
					'whatsapp_number' => sanitize_text_field( (string) ( $fields['whatsapp'] ?? '' ) ),
					'active'          => ! empty( $fields['active'] ) ? 1 : 0,
				);
				if ( isset( $fields['name'] ) ) {
					$set['name'] = sanitize_text_field( (string) $fields['name'] );
				}
				if ( isset( $fields['project_slug'] ) ) {
					$ps = sanitize_title( (string) $fields['project_slug'] );
					if ( $ps !== '' ) {
						$set['project_slug'] = $ps;
					}
				}
				$wpdb->update( $table, $set, array( 'id' => $id ) );
			}
		}
		wp_safe_redirect( self::operators_redirect() );
		exit;
	}

	/** OPERADORES_UI_V1 · alta de un operador (es_operators). */
	public static function handle_operators_add(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_operators_add' );
		$PR = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $PR !== '' ) {
			$PR::create( array(
				'name'            => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
				'email'           => sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ),
				'whatsapp_number' => sanitize_text_field( wp_unslash( (string) ( $_POST['whatsapp'] ?? '' ) ) ),
				'project_slug'    => sanitize_title( wp_unslash( (string) ( $_POST['project_slug'] ?? '' ) ) ),
			) );
		}
		wp_safe_redirect( add_query_arg( 'operator_added', '1', self::operators_redirect() ) );
		exit;
	}

	/** Redirect post-guardado de operadores: respeta vuelta a Proyectos (core). */
	private static function operators_redirect(): string
	{
		if ( (string) ( $_POST['return_to'] ?? '' ) === 'proyectos' ) {
			return admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&operator_saved=1' );
		}
		return admin_url( 'admin.php?page=estandar-suite&tab=config&sub=operadores&operator_saved=1' );
	}

	/* ── PROYECTOS (manager en Core) ─────────────────────────────────────── */

	/** admin_post: alta/edición de un proyecto (registry). */
	public static function handle_save_project(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_projects_save' );
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_error=core_missing' ) );
			exit;
		}
		$slug = sanitize_title( wp_unslash( (string) ( $_POST['project_slug'] ?? '' ) ) );
		$data = array(
			'label'      => sanitize_text_field( wp_unslash( (string) ( $_POST['label'] ?? '' ) ) ),
			'project_id' => sanitize_title( wp_unslash( (string) ( $_POST['new_slug'] ?? '' ) ) ),
			'city'       => sanitize_text_field( wp_unslash( (string) ( $_POST['city'] ?? '' ) ) ),
		);
		if ( $slug !== '' ) {
			$r = $PR::update_project( $slug, $data );
			// Operadores: varios por proyecto (es_operators.project_slug).
			if ( $r['ok'] && isset( $_POST['operator_ids_present'] ) ) {
				self::sync_project_operators( $slug );
			}
		} else {
			$r = $PR::create_project( $data );
		}
		// Branding de correo del proyecto (solo al editar; vive en la misma opción
		// que consumía Core → Proyectos). No borra el resto de skins.
		if ( $r['ok'] && isset( $_POST['brand'] ) && is_array( $_POST['brand'] ) ) {
			$eff = ( $slug !== '' ) ? $slug : (string) ( $r['slug'] ?? '' );
			if ( $eff !== '' ) {
				$b    = wp_unslash( $_POST['brand'] );
				$all  = get_option( 'estandar_suite_core_project_branding', array() );
				$all  = is_array( $all ) ? $all : array();
				$all[ $eff ] = array(
					'brand_color' => sanitize_text_field( (string) ( $b['brand_color'] ?? '' ) ),
					'logo_url'    => esc_url_raw( (string) ( $b['logo_url'] ?? '' ) ),
					'header_text' => sanitize_text_field( (string) ( $b['header_text'] ?? '' ) ),
					'footer_text' => (string) wp_kses_post( (string) ( $b['footer_text'] ?? '' ) ),
				);
				update_option( 'estandar_suite_core_project_branding', $all );
			}
		}
		$flag = $r['ok'] ? 'project_saved=1' : 'project_error=' . rawurlencode( (string) ( $r['error'] ?? 'fail' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&' . $flag ) );
		exit;
	}

	/** LEAD_DIGEST_V1: guarda el mapa proyecto → { activo, email en copia }. */
	public static function handle_save_leads_digest(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_leads_digest_save' );
		$raw = isset( $_POST['digest'] ) && is_array( $_POST['digest'] ) ? wp_unslash( $_POST['digest'] ) : array();
		$map = array();
		foreach ( $raw as $slug => $cfg ) {
			$slug = sanitize_title( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$cfg = is_array( $cfg ) ? $cfg : array();
			$cc_raw = (string) ( $cfg['extra_cc'] ?? '' );
			$parts  = preg_split( '/[\s,;]+/', $cc_raw );
			$cc     = implode( ', ', array_filter( array_map( 'sanitize_email', (array) $parts ) ) );
			$map[ $slug ] = array(
				'enabled'  => ! empty( $cfg['enabled'] ) ? 1 : 0,
				'extra_cc' => $cc,
			);
		}
		\Estandar\Core\Leads\LeadsDigest::save_settings( $map );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=digest&digest_saved=1' ) );
		exit;
	}

	/** LEAD_DIGEST_V1: envía una prueba a un correo (no toca el estado diario). */
	public static function handle_test_leads_digest(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_leads_digest_test' );
		$slug = sanitize_title( (string) ( $_POST['test_project'] ?? '' ) );
		$to   = sanitize_email( (string) ( $_POST['test_to'] ?? '' ) );
		if ( $to === '' ) {
			$to = (string) get_option( 'admin_email', '' );
		}
		if ( $slug !== '' && $to !== '' && class_exists( '\Estandar\Core\Leads\LeadsDigest' ) ) {
			\Estandar\Core\Leads\LeadsDigest::send_test( $slug, $to );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=digest&digest_tested=1' ) );
		exit;
	}

	/** Sincroniza operadores del proyecto (multi) y espeja el primero en registry. */
	private static function sync_project_operators( string $slug ): void
	{
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		$PR  = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $Ops === '' || $PR === '' || $slug === '' ) {
			return;
		}
		$ids = array();
		if ( isset( $_POST['operator_ids'] ) && is_array( $_POST['operator_ids'] ) ) {
			foreach ( wp_unslash( $_POST['operator_ids'] ) as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		$prior = $Ops::assigned( $slug );
		$Ops::reassign_set( $ids, $slug );
		$assigned = $Ops::assigned( $slug );
		// Si no había operadores (es_operators) antes y no se marcó ninguno, se
		// preserva el operator_email legacy del registry (no se borra).
		if ( empty( $assigned ) && empty( $prior ) ) {
			return;
		}
		// ESPEJO_V1: espejar el primer operador ACTIVO (no el primero a secas);
		// si no queda ninguno activo, se limpia el espejo (operator_* = '').
		$first = null;
		foreach ( $assigned as $o ) {
			if ( (int) ( $o['active'] ?? 0 ) === 1 ) {
				$first = $o;
				break;
			}
		}
		$PR::update_project( $slug, array(
			'operator_name'  => $first ? sanitize_text_field( (string) ( $first['name'] ?? '' ) ) : '',
			'operator_email' => $first ? sanitize_email( (string) ( $first['email'] ?? '' ) ) : '',
			'operator_phone' => $first ? sanitize_text_field( (string) ( $first['whatsapp_number'] ?? '' ) ) : '',
		) );
	}

	/** admin_post: archivar / reactivar un proyecto. */
	public static function handle_project_archive(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_projects_archive' );
		$slug   = sanitize_title( wp_unslash( (string) ( $_GET['project'] ?? '' ) ) );
		$active = ! empty( $_GET['activate'] );
		$PR     = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR !== '' && $slug !== '' ) {
			$PR::set_active( $slug, $active );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_archived=1' ) );
		exit;
	}

	/** admin_post: bulk Archivar/Reactivar proyectos (Lista WP). */
	public static function handle_projects_bulk(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_projects_bulk' );
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		$bulk = sanitize_key( (string) ( $_POST['action2'] ?? '' ) );
		if ( $bulk === '-1' || $bulk === '' ) {
			$bulk = sanitize_key( (string) ( $_POST['action'] ?? '' ) );
		}
		if ( $PR !== '' && in_array( $bulk, array( 'archive', 'activate' ), true ) ) {
			$slugs = isset( $_POST['project'] ) && is_array( $_POST['project'] ) ? wp_unslash( $_POST['project'] ) : array();
			foreach ( $slugs as $s ) {
				$slug = sanitize_title( (string) $s );
				if ( $slug !== '' ) {
					$PR::set_active( $slug, $bulk === 'activate' );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_archived=1' ) );
		exit;
	}

	/** admin_post: eliminar proyecto con traslado de sus formularios (estilo WP
	 *  de "eliminar usuario y reasignar contenido"). */
	public static function handle_projects_delete(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_projects_delete' );
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		$slug = sanitize_title( wp_unslash( (string) ( $_POST['project'] ?? '' ) ) );
		$dest = sanitize_title( wp_unslash( (string) ( $_POST['dest'] ?? '' ) ) );
		if ( $PR === '' || $slug === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_error=project_missing' ) );
			exit;
		}
		$Forms = class_exists( '\Estandar\Capture\Forms' ) ? '\Estandar\Capture\Forms' : '';
		$assoc = ( $Forms !== '' ) ? $Forms::forms_of_project( $slug ) : array();
		// Requiere destino si hay formularios asociados (como WP con usuarios).
		if ( $assoc && $dest === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&delete=' . $slug . '&project_error=delete_requires_target' ) );
			exit;
		}
		if ( $assoc && $dest !== '' && $Forms !== '' ) {
			$label = $PR::label_for( $dest );
			$Forms::reassign_project_ownership( $slug, $dest, $label );
		}
		$PR::delete_project( $slug );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos&project_deleted=1' ) );
		exit;
	}

	/** Assets de edición de identidad (color picker + Media Library): se cargan
	 *  en la página Core cuando el sub RENDERIZADO es "proyectos" (branding) o
	 *  "configuracion" (Configuración → Correo → Identidad).
	 *  LANDING_DEFAULT_SUB_V1: el sub efectivo puede no venir en la URL —
	 *  `page=estandar-suite` sin `tab`/`sub` resuelve a config→configuracion
	 *  (ver render()). Sin esto, el color picker no se encolaba en la landing
	 *  por defecto y el "Color de marca" salía como campo de texto plano. */
	public static function enqueue_project_editor_assets(): void
	{
		if ( (string) ( $_GET['page'] ?? '' ) !== 'estandar-suite' ) {
			return;
		}
		$sub = sanitize_key( (string) ( $_GET['sub'] ?? '' ) );
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? '' ) );
		if ( $sub === '' && ( $tab === '' || $tab === 'config' ) ) {
			$sub = 'configuracion';
		}
		if ( ! in_array( $sub, array( 'proyectos', 'configuracion' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();
	}

	/** Navegación de tabs (accesibilidad): realce de teclado (← → Home End) en las
	 *  pantallas de la Suite. Las pestañas son enlaces con `aria-current="page"`;
	 *  NO se usa `role="tab"` (ese rol es para paneles de la MISMA página). */
	public static function enqueue_nav_assets(): void
	{
		if ( 0 !== strpos( (string) ( $_GET['page'] ?? '' ), 'estandar-suite' ) ) {
			return;
		}

		$ver = defined( 'ESTANDAR_SUITE_CORE_VERSION' ) ? ESTANDAR_SUITE_CORE_VERSION : '0';
		wp_register_script(
			'estandar-suite-nav-tabs',
			plugins_url( 'includes/assets/estandar-nav-tabs.js', ESTANDAR_SUITE_CORE_FILE ),
			array(),
			$ver,
			true
		);
		wp_enqueue_script( 'estandar-suite-nav-tabs' );
	}

	/** Resuelve el operador elegido en el dropdown de Proyectos. */
	private static function resolve_project_operator( string $choice, string $project_slug ): array
	{
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $choice === '__new__' ) {
			$name  = sanitize_text_field( wp_unslash( (string) ( $_POST['operator_new_name'] ?? '' ) ) );
			$email = sanitize_email( wp_unslash( (string) ( $_POST['operator_new_email'] ?? '' ) ) );
			$wsp   = sanitize_text_field( wp_unslash( (string) ( $_POST['operator_new_whatsapp'] ?? '' ) ) );
			if ( $Ops !== '' ) {
				$Ops::create( array(
					'name'            => $name,
					'email'           => $email,
					'whatsapp_number' => $wsp,
					'project_slug'    => $project_slug,
				) );
			}
			return array(
				'operator_name'  => $name,
				'operator_email' => $email,
				'operator_phone' => $wsp,
			);
		}
		$email = $choice;
		if ( $email === '' || $email === '__admin__' ) {
			$admin = self::site_admin_operator();
			return array(
				'operator_name'  => (string) $admin['name'],
				'operator_email' => (string) $admin['email'],
				'operator_phone' => '',
			);
		}
		if ( $Ops !== '' ) {
			$row = $Ops::find_by_email( $email );
			if ( $row ) {
				return array(
					'operator_name'  => (string) ( $row['name'] ?? '' ),
					'operator_email' => (string) ( $row['email'] ?? '' ),
					'operator_phone' => (string) ( $row['whatsapp_number'] ?? '' ),
				);
			}
		}
		return array(
			'operator_name'  => $email,
			'operator_email' => $email,
			'operator_phone' => '',
		);
	}

	/** Admin de la web como operador por defecto (operador uno). */
	private static function site_admin_operator(): array
	{
		$email = (string) get_option( 'admin_email', '' );
		$name  = 'Administrador de la web';
		$users = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'display_name', 'user_email' ) ) );
		if ( $users ) {
			$name  = (string) $users[0]->display_name;
			$email = (string) $users[0]->user_email;
		}
		return array( 'name' => $name, 'email' => $email );
	}

	/** Normalize a requested tab against a whitelist. If the requested tab (or
	 *  the default) is not present in the list — e.g. hidden by visibility —
	 *  fall back to the first available tab. */
	public static function resolve_tab( array $tabs, string $default = 'estado' ): string
	{
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? $default ) );
		if ( array_key_exists( $tab, $tabs ) ) {
			return $tab;
		}
		$keys = array_keys( $tabs );
		return $keys ? $keys[0] : $default;
	}

	/**
	 * Render a second-level sub-tab navigation inside a tab.
	 * Each sub-tab is one functional group within the tab.
	 *
	 * @param string $page    Menu/slug of the current page.
	 * @param string $tab     Active top-level tab key.
	 * @param string $active  Active sub-tab key.
	 * @param array  $subs    Map of sub-tab key => label.
	 */
	public static function render_subtabs( string $page, string $tab, ?string $active, array $subs = array() ): void
	{
		if ( count( $subs ) < 2 ) {
			return; // single group: no need for sub-tabs.
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin-top:10px;padding:0 0 0 4px;" aria-label="Grupos de ' . esc_attr( $tab ) . '">';
		foreach ( $subs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $page . '&tab=' . $tab . '&sub=' . $key );
			$cls = $key === $active ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $cls . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . ' style="font-size:13px;padding:6px 12px;">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Normalize a requested sub-tab; falls back to the first key. */
	public static function resolve_sub( array $subs, string $default = '' ): string
	{
		$sub = sanitize_key( (string) ( $_GET['sub'] ?? $default ) );
		if ( $sub === '' || ! array_key_exists( $sub, $subs ) ) {
			$keys = array_keys( $subs );
			$sub  = $keys ? $keys[0] : '';
		}
		return $sub;
	}

	/** Third-level nav inside a feature sub-tab (sub-sub-tabs). Uses ?ss=. */
	public static function render_ssubtabs( string $page, string $tab, string $sub, ?string $active, array $ssubs = array() ): void
	{
		if ( count( $ssubs ) < 2 ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin-top:10px;padding:0 0 0 4px;" aria-label="Secciones de ' . esc_attr( $sub ) . '">';
		foreach ( $ssubs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $page . '&tab=' . $tab . '&sub=' . $sub . '&ss=' . $key );
			$cls = $key === $active ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $cls . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . ' style="font-size:13px;padding:6px 12px;">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Normalize a requested sub-sub-tab; falls back to the first key. */
	public static function resolve_ssub( array $ssubs, string $default = '', string $param = 'ss' ): string
	{
		$param = preg_replace( '/[^a-z_]/i', '', (string) $param );
		$ss = sanitize_key( (string) ( $_GET[ $param ] ?? $default ) );
		if ( $ss === '' || ! array_key_exists( $ss, $ssubs ) ) {
			$keys = array_keys( $ssubs );
			$ss   = $keys ? $keys[0] : '';
		}
		return $ss;
	}

	/**
	 * Nav de sub-tabs (nav-tab-wrapper) reutilizable.
	 *
	 * @param array  $tabs   array( 'key' => array( 'label' => string, 'url' => string ), ... )
	 * @param string $active Clave activa.
	 */
	public static function subtab_nav_html( array $tabs, string $active ): string
	{
		$out  = '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secciones">';
		foreach ( $tabs as $k => $t ) {
			$url   = isset( $t['url'] ) ? esc_url( $t['url'] ) : '#';
			$label = isset( $t['label'] ) ? esc_html( $t['label'] ) : (string) $k;
			$out  .= '<a href="' . $url . '" class="nav-tab' . ( $k === $active ? ' nav-tab-active' : '' ) . '"' . ( $k === $active ? ' aria-current="page"' : '' ) . ' data-st="' . esc_attr( $k ) . '">' . $label . '</a>';
		}
		$out .= '</nav>';
		return $out;
	}

	// ── Inline help (?) for tabs / sub-tabs ──

	/** Render a (?) help toggle + collapsible explanation box (shared by the
	 *  whole suite: same markup for every product).
	 *
	 *  HIDE_HELP_V1: el botón (?) está OCULTO (display:none) hasta nuevo aviso
	 *  (quedó mal ubicado y ensucia el admin). No se elimina: revertir quitando
	 *  `display:none` del estilo inline del botón. El texto de ayuda queda
	 *  intacto y la caja ya nace cerrada. */
	public static function help_html( string $id, string $text ): string
	{
		return '<button type="button" class="button-link estandar-suite-help" data-help="' . esc_attr( $id ) . '" aria-label="Ayuda" style="display:none;margin-left:6px;font-size:16px;text-decoration:none;vertical-align:middle;" title="Ayuda">(?)</button>'
			. '<div id="help-' . esc_attr( $id ) . '" class="estandar-suite-help-box" style="display:none;background:#f0f6ff;border:1px solid #c5d9f2;padding:12px 14px;border-radius:6px;margin:8px 0 16px;max-width:760px;">' . wp_kses_post( $text ) . '</div>';
	}

	/** Toggle script for every .estandar-suite-help button (print once per page). */
	public static function help_script(): string
	{
		return '<script>(function(){document.querySelectorAll(".estandar-suite-help").forEach(function(btn){btn.addEventListener("click",function(){var box=document.getElementById("help-"+btn.getAttribute("data-help"));if(box){box.style.display=box.style.display==="none"?"block":"none";}});});})();</script>';
	}

	// ── Shared read-only helpers over legacy (v1) tables ──────────────────
	// The v2 suite NEVER writes to legacy tables; these helpers only read.

	/** True when a legacy table exists in the current database. */
	public static function legacy_table( string $name ): bool
	{
		global $wpdb;
		$wpdb->get_results( 'SELECT 1 FROM ' . $wpdb->prefix . $name . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Read rows from a legacy table (read-only). */
	public static function legacy_rows( string $name, string $suffix = 'ORDER BY id DESC LIMIT 50' ): array
	{
		if ( ! self::legacy_table( $name ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . $name . ' ' . $suffix, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Count rows in a legacy table (read-only). */
	public static function legacy_count( string $name ): int
	{
		if ( ! self::legacy_table( $name ) ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $name );
	}

	/** Group a legacy table by one column (read-only): key => count. */
	public static function legacy_group( string $name, string $column ): array
	{
		if ( ! self::legacy_table( $name ) ) {
			return array();
		}
		global $wpdb;
		$col  = preg_replace( '/[^a-z_]/i', '', $column );
		$rows = $wpdb->get_results(
			'SELECT ' . $col . ' AS k, COUNT(*) AS n FROM ' . $wpdb->prefix . $name . ' GROUP BY ' . $col . ' ORDER BY n DESC LIMIT 12',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$key = (string) ( $r['k'] ?? '' );
			if ( $key === '' ) { $key = '(sin dato)'; }
			$out[ $key ] = (int) $r['n'];
		}
		return $out;
	}

	// ── Shared feature settings (per functionality sub-tab) ──────────────

	/**
	 * Render the settings form for one feature. Field types: bool | text |
	 * select (options as key => label). POSTs to admin-post with $action.
	 */
	public static function render_feature_settings( string $action, string $option, array $fields, string $feature = '', array $extra = array() ): void
	{
		$current = get_option( $option, array() );
		$current = is_array( $current ) ? $current : array();
		// FEATURE_GROUP_NESTED_V1: cuando el formulario declara un `group`
		// (p. ej. integraciones, meta, email), los valores canónicos viven en
		// `$current[$group][$key]`, con fallback a la raíz (datos legacy escritos
		// planos por versiones anteriores). Así el panel muestra lo mismo que leen
		// los consumidores dedicados (PixelManager, MetaCapi) y no se pisan claves
		// homónimas entre grupos.
		$group  = isset( $extra['group'] ) ? sanitize_key( (string) $extra['group'] ) : '';
		$nested = ( $group !== '' && isset( $current[ $group ] ) && is_array( $current[ $group ] ) ) ? $current[ $group ] : array();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:640px;">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		if ( $feature !== '' ) {
			echo '<input type="hidden" name="feature" value="' . esc_attr( $feature ) . '">';
		}
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $cfg ) {
			if ( array_key_exists( $key, $nested ) ) {
				$val = $nested[ $key ];
			} elseif ( array_key_exists( $key, $current ) ) {
				$val = $current[ $key ];
			} else {
				$val = $cfg['default'] ?? '';
			}
			$type = $cfg['type'] ?? 'text';
			echo '<tr><th scope="row"><label for="es-' . esc_attr( $key ) . '">' . esc_html( $cfg['label'] ) . '</label></th><td>';
			if ( $type === 'bool' ) {
				echo '<label><input type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $val, true, false ) . '> ' . esc_html( $cfg['desc'] ?? '' ) . '</label>';
			} elseif ( $type === 'select' ) {
				echo '<select id="es-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']">';
				foreach ( ( $cfg['options'] ?? array() ) as $ok => $ol ) {
					echo '<option value="' . esc_attr( $ok ) . '" ' . selected( (string) $val, (string) $ok, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				echo '</select> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} elseif ( $type === 'textarea' ) {
				$rows = (int) ( $cfg['rows'] ?? 5 );
				echo '<textarea id="es-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']" rows="' . $rows . '" class="large-text">' . esc_textarea( (string) $val ) . '</textarea> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} elseif ( $type === 'number' ) {
				$min = $cfg['min'] ?? null;
				$max = $cfg['max'] ?? null;
				$step = (string) ( $cfg['step'] ?? '1' );
				echo '<input id="es-' . esc_attr( $key ) . '" type="number" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $val ) . '" class="small-text"' . ( $min !== null ? ' min="' . esc_attr( (string) $min ) . '"' : '' ) . ( $max !== null ? ' max="' . esc_attr( (string) $max ) . '"' : '' ) . ' step="' . esc_attr( $step ) . '"> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} elseif ( $type === 'color' ) {
				echo '<input id="es-' . esc_attr( $key ) . '" type="color" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $val ) . '"> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} elseif ( $type === 'pages' ) {
				$page_ids = array_map( 'intval', (array) $val );
				echo '<select id="es-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . '][]" multiple size="' . (int) ( $cfg['size'] ?? 8 ) . '" style="min-width:320px;display:block;margin-bottom:4px;">';
				foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title' ) ) as $p ) {
					echo '<option value="' . esc_attr( (string) $p->ID ) . '" ' . selected( in_array( (int) $p->ID, $page_ids, true ), true, false ) . '>' . esc_html( $p->post_title . ' (#' . $p->ID . ')' ) . '</option>';
				}
				echo '</select>';
				echo '<span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} else {
				echo '<input id="es-' . esc_attr( $key ) . '" type="text" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $val ) . '" class="regular-text"> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( 'Guardar settings', 'primary', 'save', false );
		echo '</form>';
	}

	/** Sanitize raw POST settings against the feature field descriptors. */
	public static function sanitize_feature_fields( array $fields, array $raw ): array
	{
		$clean = array();
		foreach ( $fields as $key => $cfg ) {
			$type = $cfg['type'] ?? 'text';
			if ( $type === 'bool' ) {
				$clean[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
			} elseif ( $type === 'select' ) {
				$v = sanitize_key( (string) ( $raw[ $key ] ?? '' ) );
				$clean[ $key ] = isset( $cfg['options'][ $v ] ) ? $v : ( $cfg['default'] ?? '' );
			} elseif ( $type === 'textarea' ) {
				$clean[ $key ] = sanitize_textarea_field( (string) ( $raw[ $key ] ?? '' ) );
			} elseif ( $type === 'number' ) {
				$min = $cfg['min'] ?? null;
				$max = $cfg['max'] ?? null;
				$step = (string) ( $cfg['step'] ?? '1' );
				$is_float = strpos( $step, '.' ) !== false;
				$val = $is_float ? (float) ( $raw[ $key ] ?? ( $cfg['default'] ?? 0 ) ) : (int) ( $raw[ $key ] ?? ( $cfg['default'] ?? 0 ) );
				if ( $min !== null ) { $val = max( $min, $val ); }
				if ( $max !== null ) { $val = min( $max, $val ); }
				$clean[ $key ] = $is_float ? (float) $val : (int) $val;
			} elseif ( $type === 'color' ) {
				$v = sanitize_hex_color( (string) ( $raw[ $key ] ?? '' ) );
				$clean[ $key ] = $v ? $v : ( $cfg['default'] ?? '' );
			} elseif ( $type === 'pages' ) {
				$ids = array();
				foreach ( (array) ( $raw[ $key ] ?? array() ) as $v ) {
					$id = (int) $v;
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
				$clean[ $key ] = array_values( array_unique( $ids ) );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
			}
		}
		return $clean;
	}

	/**
	 * FEATURE_GROUP_NESTED_V1: guarda un grupo de settings de forma ANIDADA
	 * (`$option[$group][$key]`) y sanea la raíz moviendo cualquier clave legacy
	 * escrita plana hacia el grupo. Idempotente. Devuelve los valores del grupo.
	 *
	 * El guardado (no solo el render) es quien sanea: aunque un sitio tenga datos
	 * viejos planos por una versión anterior, al guardar quedan migrados y los
	 * consumidores dedicados (que leen anidado) los ven.
	 */
	public static function save_feature_group( string $option, array $fields, string $group, array $raw ): array
	{
		$group = sanitize_key( $group );
		$cur   = get_option( $option, array() );
		$cur   = is_array( $cur ) ? $cur : array();
		$nested = ( isset( $cur[ $group ] ) && is_array( $cur[ $group ] ) ) ? $cur[ $group ] : array();

		$nested = array_merge( $nested, self::sanitize_feature_fields( $fields, $raw ) );

		// Saneo en guardado: elimina de la raíz las claves de este grupo.
		foreach ( array_keys( $fields ) as $k ) {
			if ( array_key_exists( $k, $cur ) ) {
				unset( $cur[ $k ] );
			}
		}
		$cur[ $group ] = $nested;
		update_option( $option, $cur, false );
		return $nested;
	}

	// ── Tab/sub-tab visibility (visual only; never affects functionality) ──

	/** Option key storing the visible sub-tabs per tab for a product. */
	public static function visibility_option( string $product ): string
	{
		return 'estandar_suite_' . $product . '_visibility';
	}

	/**
	 * Read the saved visibility map. Format:
	 *   array( 'estado' => array( 'resumen', 'datos' ), 'config' => array( ... ), ... )
	 * A missing option means "everything visible".
	 */
	public static function get_visibility( string $product ): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::visibility_option( $product ), array() ) : array();
		$map   = is_array( $saved ) ? self::normalize_visibility( $saved ) : array();
		if ( $product === 'core' ) {
			$map = self::migrate_core_visibility( $map );
		}
		return $map;
	}

	/** Core: migrate pre-API-Connect visibility maps. Two legacy shapes:
	 *   A) `config.destinos` was a flat sub-tab with value [] (demo real).
	 *      → api-connect.destinos visible + leads visible (all ss default).
	 *   B) `config.destinos` had ss-tabs [destinos,entregas,panel,...] (old hub).
	 *      → destinos/entregas → api-connect; panel/acceso/actividad/api → leads.
	 * `integraciones` stays as-is. Absent keys = visible by default. */
	public static function migrate_core_visibility( array $map ): array
	{
		$old_config = isset( $map['config'] ) && is_array( $map['config'] ) ? $map['config'] : array();
		$has_dest   = isset( $old_config['destinos'] ) && is_array( $old_config['destinos'] );
		$has_lead   = isset( $old_config['panel'] ) || isset( $old_config['acceso'] ) || isset( $old_config['actividad'] ) || isset( $old_config['api'] );
		if ( ! $has_dest && ! $has_lead ) {
			return $map;
		}
		$hub  = isset( $map['api-connect'] ) && is_array( $map['api-connect'] ) ? $map['api-connect'] : array();
		$leads = isset( $map['leads'] ) && is_array( $map['leads'] ) ? $map['leads'] : array();
		if ( $has_dest ) {
			$old_dest = $old_config['destinos'];
			if ( empty( $old_dest ) ) {
				// Legacy shape A: sub-tab `destinos` visible → hub webhooks visible.
				$hub['destinos'] = array();
			} else {
				// Legacy shape B: ss-tabs del viejo sub-tab destinos.
				foreach ( array( 'destinos', 'entregas' ) as $ss_web ) {
					if ( in_array( $ss_web, $old_dest, true ) ) {
						$hub[ $ss_web ] = array();
					}
				}
				foreach ( array( 'panel', 'acceso', 'actividad', 'api' ) as $ss_lead ) {
					if ( in_array( $ss_lead, $old_dest, true ) ) {
						$leads[ $ss_lead ] = array();
					}
				}
			}
		}
		// Panel de Leads: siempre queda accesible si estaba en el sub-tab destinos.
		if ( $has_dest && empty( $leads ) && empty( $old_config['destinos'] ) ) {
			$leads['panel'] = array();
		}
		if ( $leads ) {
			$map['leads'] = $leads;
		}
		$map['api-connect'] = $hub;
		// Guarantee the new sub-tabs are visible: add them to config so filter_subs
		// (which lists only keys present in the map) keeps them.
		$map['config']['api-connect'] = $hub;
		$map['config']['leads'] = $leads;
		unset( $map['config']['destinos'], $map['config']['panel'], $map['config']['acceso'], $map['config']['actividad'], $map['config']['api'] );
		return $map;
	}

	/** Normalize a visibility map to the canonical 3-level form:
	 *  tab => ( sub => array( visible ss keys ) ). Legacy flat lists
	 *  (tab => array of sub strings) become sub => array() = all sub-sub-tabs
	 *  visible (or the sub has no sub-sub-tabs). Nested ss maps (a sub whose
	 *  value is array of arrays) are kept key-wise. Reserved `__catalog` is
	 *  preserved untouched (it records which subs/ss existed at save time). */
	public static function normalize_visibility( array $map ): array
	{
		$out = array();
		foreach ( $map as $tab => $subs ) {
			if ( $tab === '__catalog' && is_array( $subs ) ) {
				$out['__catalog'] = $subs;
				continue;
			}
			$tab = sanitize_key( (string) $tab );
			if ( $tab === '' || ! is_array( $subs ) ) {
				continue;
			}
			$out[ $tab ] = array();
			foreach ( $subs as $k => $v ) {
				if ( is_int( $k ) ) {
					$sub = sanitize_key( (string) $v );
					if ( $sub !== '' ) { $out[ $tab ][ $sub ] = array(); }
				} elseif ( is_string( $k ) && is_array( $v ) ) {
					$sub = sanitize_key( $k );
					if ( $sub === '' ) { continue; }
					$ss = array();
					foreach ( $v as $ssk => $ssv ) {
						if ( is_int( $ssk ) ) {
							$key = sanitize_key( (string) $ssv );
						} else {
							$key = sanitize_key( (string) $ssk );
						}
						if ( $key !== '' ) { $ss[] = $key; }
					}
					$out[ $tab ][ $sub ] = array_values( array_unique( $ss ) );
				} elseif ( is_string( $k ) ) {
					$sub = sanitize_key( $k );
					if ( $sub !== '' ) { $out[ $tab ][ $sub ] = array(); }
				}
			}
		}
		return $out;
	}

	/**
	 * Catálogo guardado: subs/ss que existían al momento de guardar el mapa.
	 * Los mapas legacy (sin `__catalog`) reportan `null`: se interpreta como
	 * "todo lo que no está explícitamente oculto es visible" (comportamiento
	 * previo), y cualquier sub/ss NUEVO del código (ausente del mapa) sale
	 * visible por defecto — es la protección contra "agregué una sub-pestaña
	 * y no aparece hasta re-guardar visibilidad".
	 *
	 * Forma: array( 'subs' => array( tab => [sub,...] ), 'ss' => array( tab => array( sub => [ss,...] ) ) )
	 */
	private static function catalog_ss( string $product, string $tab, string $sub ): ?array
	{
		$map = self::get_visibility_raw( $product );
		if ( empty( $map['__catalog']['ss'][ $tab ][ $sub ] ) || ! is_array( $map['__catalog']['ss'][ $tab ][ $sub ] ) ) {
			return null;
		}
		return $map['__catalog']['ss'][ $tab ][ $sub ];
	}

	/** Raw stored visibility (no migraciones core), para consultas internas. */
	private static function get_visibility_raw( string $product ): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::visibility_option( $product ), array() ) : array();
		return is_array( $saved ) ? self::normalize_visibility( $saved ) : array();
	}

	/** True when a tab has at least one visible sub-tab (or map is empty = all visible). */
	public static function is_tab_visible( string $product, string $tab, array $subs ): bool
	{
		$map = self::get_visibility( $product );
		if ( empty( $map ) ) {
			return true;
		}
		$visible = isset( $map[ $tab ] ) && is_array( $map[ $tab ] ) ? array_keys( $map[ $tab ] ) : array();
		if ( empty( $visible ) ) {
			return false;
		}
		return true;
	}

	/** Filter the full tab map to only visible tabs. A tab absent from the saved
	 *  map is treated as visible (default). A tab present with an empty list is
	 *  hidden. Si TODAS quedan ocultas el resultado puede ser vacío: el render
	 *  de cada producto muestra el aviso (ver `render_visibility_none`). */
	public static function filter_tabs( string $product, array $tabs, array $subs_by_tab ): array
	{
		$map = self::get_visibility( $product );
		$out = array();
		foreach ( $tabs as $key => $label ) {
			if ( ! isset( $map[ $key ] ) ) {
				$out[ $key ] = $label; // not customized → visible.
			} elseif ( is_array( $map[ $key ] ) && ! empty( $map[ $key ] ) ) {
				$out[ $key ] = $label; // has visible sub-tabs.
			}
			// empty list → hidden.
		}
		return $out; // puede quedar vacío = todas ocultas (respetado).
	}

	/** Aviso cuando TODAS las pestañas de un producto están ocultas por la
	 *  configuración de visibilidad (con enlace al manager central). */
	public static function render_visibility_none( string $product ): void
	{
		echo '<div class="wrap"><h1>' . esc_html( ucfirst( $product ) ) . '</h1>'
			. '<div class="notice notice-info inline"><p>Todas las pestañas de este plugin están ocultas por la configuración de visibilidad. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=visibilidad' ) ) . '">Administrar visibilidad</a></p></div></div>';
	}

	/** Filter a tab's sub-tabs to only the visible ones. A tab absent from the
	 *  saved map returns all sub-tabs (default). Semántica VISIBILITY_NEW_V1:
	 *  el mapa guardado es una whitelist de lo que se marcó visible AL GUARDAR,
	 *  y `__catalog` registra qué sub-tabs existían entonces. Un sub-tab que el
	 *  código agrega DESPUÉS (no está en __catalog) queda visible por defecto;
	 *  solo se oculta lo que existía y no quedó marcado. Mapas legacy sin
	 *  __catalog → todo lo no marcado se trata como nuevo (visible). */
	public static function filter_subs( string $product, string $tab, array $subs ): array
	{
		$map = self::get_visibility( $product );
		if ( ! isset( $map[ $tab ] ) || ! is_array( $map[ $tab ] ) ) {
			return $subs;
		}
		$out = array();
		foreach ( $subs as $key => $label ) {
			if ( ! self::vis_item_hidden( $map, $tab, $key, false ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out ? $out : $subs; // never hide all sub-tabs.
	}

	/** Filter a sub-tab's sub-sub-tabs (ss=) to only the visible ones. A sub-tab
	 *  absent from the saved map, or present with an empty ss list, returns all
	 *  sub-sub-tabs (default). Semántica VISIBILITY_NEW_V1: un ss que el código
	 *  agrega después del guardado (ausente de __catalog) queda visible. */
	public static function filter_ssubs( string $product, string $tab, string $sub, array $ssubs ): array
	{
		$map = self::get_visibility( $product );
		if ( ! isset( $map[ $tab ][ $sub ] ) || ! is_array( $map[ $tab ][ $sub ] ) || empty( $map[ $tab ][ $sub ] ) ) {
			return $ssubs;
		}
		$out = array();
		foreach ( $ssubs as $key => $label ) {
			if ( ! self::vis_item_hidden( $map, $tab, (string) $key, true, (string) $sub ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out ? $out : $ssubs; // never hide all sub-sub-tabs.
	}

	/** VISIBILITY_NEW_V1: ¿un ítem del catálogo actual está OCULTO?
	 *  Niveles: sub (is_ss=false → parent=tab, item=sub) o ss (parent=sub).
	 *  Regla: oculto solo si el ítem EXISTÍA al guardar (está en __catalog) y no
	 *  quedó marcado en la whitelist. Si no hay __catalog (mapa legacy) o el ítem
	 *  no está en __catalog (agregado después), NO se oculta (visible por defecto).
	 */
	private static function vis_item_hidden( array $map, string $tab, string $item, bool $is_ss, string $sub = '' ): bool
	{
		if ( ! $is_ss ) {
			if ( ! isset( $map[ $tab ] ) || ! is_array( $map[ $tab ] ) ) {
				return false;
			}
			if ( array_key_exists( $item, $map[ $tab ] ) ) {
				return false; // marcado visible.
			}
			$known = isset( $map['__catalog']['subs'][ $tab ] ) && is_array( $map['__catalog']['subs'][ $tab ] )
				? $map['__catalog']['subs'][ $tab ] : null;
			// Sin catálogo (legacy) → no se puede distinguir oculto de nuevo.
			return $known !== null && in_array( $item, $known, true );
		}
		$parent = $sub !== '' ? $sub : $item;
		if ( ! isset( $map[ $tab ][ $parent ] ) || ! is_array( $map[ $tab ][ $parent ] ) ) {
			return false;
		}
		$visible = $map[ $tab ][ $parent ];
		if ( empty( $visible ) ) {
			return false; // lista vacía = todos los ss visibles (default).
		}
		if ( in_array( $item, $visible, true ) ) {
			return false;
		}
		$known = isset( $map['__catalog']['ss'][ $tab ][ $parent ] ) && is_array( $map['__catalog']['ss'][ $tab ][ $parent ] )
			? $map['__catalog']['ss'][ $tab ][ $parent ] : null;
		return $known !== null && in_array( $item, $known, true );
	}

	/** Persist the visibility map from a POST payload. The payload must include
	 *  the full list of tabs (so an empty per-tab map = hidden tab). Each sub-tab
	 *  value is an array of visible sub-sub-tab (ss) keys; an empty array means
	 *  "all sub-sub-tabs visible" (or no ss level). Legacy flat payloads
	 *  (sub-tab => array of sub keys) are normalized automatically.
	 *
	 *  $catalog (opcional, VISIBILITY_NEW_V1): catálogo de sub/ss que EXISTÍAN
	 *  al guardar (de la estructura de código del producto). Se persiste bajo
	 *  `__catalog` para distinguir "oculto a propósito" (estaba y quedó
	 *  desmarcado) de "nuevo en código" (visible por defecto). Sin $catalog se
	 *  guarda solo la whitelist (mapa legacy; filtros tratan lo ausente como
	 *  nuevo → visible). */
	public static function save_visibility( string $product, array $payload, ?array $catalog = null ): void
	{
		$clean = array();
		foreach ( $payload as $tab => $subs ) {
			$tab  = sanitize_key( (string) $tab );
			if ( $tab === '' ) {
				continue;
			}
			$clean[ $tab ] = array();
			if ( is_array( $subs ) ) {
				foreach ( $subs as $k => $v ) {
					if ( is_int( $k ) ) {
						// Legacy flat sub key → all its ss visible.
						$sub = sanitize_key( (string) $v );
						if ( $sub !== '' ) { $clean[ $tab ][ $sub ] = array(); }
					} else {
						$sub = sanitize_key( (string) $k );
						if ( $sub === '' ) { continue; }
						$clean[ $tab ][ $sub ] = is_array( $v ) ? array_values( array_filter( array_map( 'sanitize_key', $v ) ) ) : array();
					}
				}
			}
		}
		if ( $catalog !== null ) {
			$catalog = self::sanitize_catalog( $catalog );
			if ( $catalog ) {
				$clean['__catalog'] = $catalog;
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::visibility_option( $product ), $clean, false );
		}
	}

	/** Sanitize a catalog snapshot (subs/ss que existían al guardar).
	 *  Forma: array( 'subs' => tab => [sub,...], 'ss' => tab => sub => [ss,...] ). */
	private static function sanitize_catalog( array $catalog ): array
	{
		$out = array();
		if ( ! empty( $catalog['subs'] ) && is_array( $catalog['subs'] ) ) {
			$subs = array();
			foreach ( $catalog['subs'] as $tab => $list ) {
				$tab = sanitize_key( (string) $tab );
				if ( $tab === '' || ! is_array( $list ) ) { continue; }
				$subs[ $tab ] = array_values( array_filter( array_map( 'sanitize_key', $list ) ) );
			}
			$out['subs'] = $subs;
		}
		if ( ! empty( $catalog['ss'] ) && is_array( $catalog['ss'] ) ) {
			$ss = array();
			foreach ( $catalog['ss'] as $tab => $by_sub ) {
				$tab = sanitize_key( (string) $tab );
				if ( $tab === '' || ! is_array( $by_sub ) ) { continue; }
				foreach ( $by_sub as $sub => $list ) {
					$sub = sanitize_key( (string) $sub );
					if ( $sub === '' || ! is_array( $list ) ) { continue; }
					$ss[ $tab ][ $sub ] = array_values( array_filter( array_map( 'sanitize_key', $list ) ) );
				}
			}
			$out['ss'] = $ss;
		}
		return $out;
	}

	/** Map a product key to its admin page slug (used for redirects). */
	public static function visibility_page( string $product ): string
	{
		$pages = array(
			'core'     => 'estandar-suite',
			'capture'  => 'estandar-suite-capture',
			'brain'    => 'estandar-suite-brain',
			'flow'     => 'estandar-suite-flow',
			'insights' => 'estandar-suite-insights',
		);
		return isset( $pages[ $product ] ) ? $pages[ $product ] : 'estandar-suite';
	}

	/** Estructura de visibilidad del Core (para el manager central). */
	public static function visibility_structure(): array
	{
		return array(
			'product' => 'core',
			'page'    => 'estandar-suite',
			'label'   => 'Core',
			'subs'    => array(
			'inicio'   => array( 'bienvenida' => 'Bienvenida' ),
			'estado'   => array( 'resumen' => 'Resumen', 'autodiagnostico' => 'Autodiagnóstico', 'datos' => 'Datos', 'esquema' => 'Esquema', 'mapa' => 'Mapa del sitio' ),
			'config'   => array( 'configuracion' => 'Configuración', 'telemetria' => 'Telemetría', 'leads' => 'Leads', 'claves' => 'Claves API', 'correo' => 'Correo', 'proyectos' => 'Proyectos', 'qualification' => 'Calificación', 'digest' => 'Resumen de leads' ),
				'datos'    => array( 'adopcion' => 'Adopción', 'heredados' => 'Settings heredados' ),
				'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'actualizaciones' => 'Actualizaciones', 'inventario' => 'Inventario', 'herramientas' => 'Herramientas' ),
			),
			'ss' => array(
				'config' => array(
					'leads'         => array( 'vista' => 'Vista Leads', 'metricas' => 'Métricas de correo', 'panel' => 'Panel de Leads', 'operadores' => 'Operadores' ),
				),
			),
		);
	}

	/**
	 * Renderiza la visibilidad de TODOS los productos de la suite en UNA sola
	 * página y UN solo formulario (centralizado en Core). Cada producto se
	 * autoregistra vía `Admin::visibility_structure()`. Un solo botón de
	 * guardado evita el riesgo de guardar un producto distinto al editado
	 * (antes había un formulario por producto con botones idénticos).
	 */
	public static function render_visibility_central( string $fallback_page, string $self_product ): void
	{
		$structs = array( self::visibility_structure() );
		foreach ( array(
			'capture'  => array( 'Estandar\\Capture\\Admin', 'estandar-suite-capture' ),
			'brain'    => array( 'Estandar\\Brain\\Admin', 'estandar-suite-brain' ),
			'flow'     => array( 'Estandar\\Flow\\Admin', 'estandar-suite-flow' ),
			'insights' => array( 'Estandar\\Insights\\Admin', 'estandar-suite-insights' ),
		) as $product => $cfg ) {
			list( $class, $page ) = $cfg;
			if ( ! class_exists( $class ) || ! method_exists( $class, 'visibility_structure' ) ) {
				continue;
			}
			$s = call_user_func( array( $class, 'visibility_structure' ) );
			if ( is_array( $s ) && ! empty( $s['subs'] ) ) {
				$s['product'] = $product;
				$s['page']    = $page;
				$structs[] = $s;
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="es-visibility-form">';
		wp_nonce_field( 'estandar_suite_save_visibility' );
		echo '<input type="hidden" name="action" value="estandar_suite_save_visibility">';
		foreach ( $structs as $s ) {
			echo '<input type="hidden" name="product_ids[]" value="' . esc_attr( $s['product'] ) . '">';
		}
		foreach ( $structs as $s ) {
			$subs = $s['subs'];
			// El manager de core ya es visible (no se oculta); el resto sí.
			if ( $s['product'] === 'core' && isset( $subs['config'] ) ) {
				$subs['config']['visibilidad'] = 'Visibilidad';
			}
			self::render_visibility_groups( $s['product'], $subs, $s['ss'] );
		}
		echo '<p style="margin-top:14px;">';
		submit_button( 'Guardar visibilidad de todos los plugins', 'primary', 'save_visibility_btn', false );
		echo '</p>';
		echo '</form>';

		self::visibility_js();
	}

	/**
	 * Render the visibility groups of one product: per tab a row of sub-tab
	 * checkboxes; sub-tabs with sub-sub-tabs (ss=) get a full-width row of ss
	 * checkboxes indented under their parent. Los nombres llevan prefijo del
	 * producto (`subs[<product>][<tab>][]`) para convivir en el form único.
	 *
	 * @param string $product     Product key ('core','capture',...).
	 * @param array  $subs_by_tab Map of tab => map(sub => label).
	 * @param array  $ss_by_tab   Optional map of tab => map(sub => map(ss => label)).
	 */
	public static function render_visibility_groups( string $product, array $subs_by_tab, array $ss_by_tab = array() ): void
	{
		$map = self::get_visibility( $product );
		$label = 'Core';
		if ( $product !== 'core' ) {
			$label = ucfirst( $product );
		}
		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;font-size:14px;">' . esc_html( $label ) . '</h2></div>';
		echo '<div class="inside" style="padding:12px;">';
		echo '<div style="margin:0 0 8px;">'
			. '<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;">'
			. '<input type="checkbox" class="es-vis-all" data-product="' . esc_attr( $product ) . '" style="cursor:pointer;"> '
			. 'Seleccionar / deseleccionar todas'
			. '</label>'
			. ' <a class="button button-link" style="margin-left:10px;" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_suite_reset_visibility&product=' . $product ), 'estandar_suite_reset_visibility' ) ) . '">Restablecer a la vista por defecto</a>'
			. '</div>';
		// Always submit the full tab list so an all-unchecked tab is saved hidden.
		foreach ( array_keys( $subs_by_tab ) as $all_tab ) {
			echo '<input type="hidden" name="all_tabs[' . esc_attr( $product ) . '][]" value="' . esc_attr( $all_tab ) . '">';
		}
		foreach ( $subs_by_tab as $tab => $subs ) {
			$active_subs = isset( $map[ $tab ] ) && is_array( $map[ $tab ] ) ? $map[ $tab ] : array();
			// VIS_STATE_V1 + VISIBILITY_NEW_V1: el estado marcado = visibilidad
			// EFECTIVA (lo que filter_subs/filter_ssubs muestran). Una tab ausente
			// del mapa = visible por defecto → todos marcados. Un sub/ss nuevo del
			// código (ausente del __catalog guardado) también sale marcado, para
			// que guardar sin tocarlo no lo oculte. Solo queda sin marcar lo que
			// existía al guardar y se desmarcó (oculto de verdad).
			$tab_absent = ! isset( $map[ $tab ] ) || ! is_array( $map[ $tab ] );
			// Estado de cada sub-tab (para padres y para sus ss).
			$sub_state = array();
			foreach ( $subs as $sub => $s_label ) {
				$sub_hidden = self::vis_item_hidden( $map, $tab, (string) $sub, false );
				$sub_state[ $sub ] = array(
					'locked'  => $sub === 'visibilidad',
					'checked' => ( $sub === 'visibilidad' ) || ( $tab_absent ? true : ! $sub_hidden ),
					'has_ss'  => isset( $ss_by_tab[ $tab ][ $sub ] ) && is_array( $ss_by_tab[ $tab ][ $sub ] ),
				);
			}
			echo '<h4 style="margin:14px 0 6px;">' . esc_html( ucfirst( $tab ) ) . '</h4>';
			// Nivel 2: sub-tabs en filas compactas.
			echo '<div style="display:flex;flex-wrap:wrap;gap:4px 18px;">';
			foreach ( $subs as $sub => $s_label ) {
				$locked  = $sub_state[ $sub ]['locked'];
				$checked = $sub_state[ $sub ]['checked'];
				echo '<div style="min-width:180px;">';
				echo '<label style="white-space:nowrap;"><input type="checkbox" name="subs[' . esc_attr( $product ) . '][' . esc_attr( $tab ) . '][]" value="' . esc_attr( $sub ) . '" data-product="' . esc_attr( $product ) . '" data-ss-group="' . esc_attr( $product . '|' . $tab . '|' . $sub ) . '" ' . checked( $checked, true, false ) . ( $locked ? ' disabled title="Siempre visible (obligatorio)"' : '' ) . '> ' . esc_html( $s_label ) . ( $locked ? ' <span class="description">(fija)</span>' : '' ) . '</label>';
				echo '</div>';
			}
			echo '</div>';
			// Nivel 3: sub-sub-tabs en filas de ancho completo (indentadas bajo
			// su sub-tab). Si el padre está OCULTO, sus ss salen sin marcar y
			// deshabilitados (apagados): antes salían marcados bajo un padre
			// desmarcado ("siempre quedan algunos encendidos" tras deseleccionar).
			foreach ( $subs as $sub => $s_label ) {
				if ( ! $sub_state[ $sub ]['has_ss'] ) {
					continue;
				}
				$ss_enabled = $sub_state[ $sub ]['checked'];
				echo '<div class="es-ss-group" data-ss-group="' . esc_attr( $product . '|' . $tab . '|' . $sub ) . '" style="margin:2px 0 2px 26px;display:flex;flex-wrap:wrap;gap:2px 16px;' . ( $ss_enabled ? '' : 'opacity:.55;' ) . '">';
				foreach ( $ss_by_tab[ $tab ][ $sub ] as $ss => $ss_label ) {
					$ss_visible = ! self::vis_item_hidden( $map, $tab, (string) $ss, true, (string) $sub );
					$ss_checked = $ss_enabled && $ss_visible;
					echo '<label style="white-space:nowrap;font-size:12px;"><input type="checkbox" name="ss[' . esc_attr( $product ) . '][' . esc_attr( $tab ) . '][' . esc_attr( $sub ) . '][]" value="' . esc_attr( $ss ) . '" data-product="' . esc_attr( $product ) . '" data-ss-group="' . esc_attr( $product . '|' . $tab . '|' . $sub ) . '" data-ss-saved="' . ( $ss_visible ? '1' : '0' ) . '" ' . checked( $ss_checked, true, false ) . ( $ss_enabled ? '' : ' disabled' ) . '> ' . esc_html( $ss_label ) . '</label>';
				}
				echo '</div>';
			}
		}
		echo '</div></div>';
	}

	/**
	 * Single-product visibility manager (compat): formulario propio para un
	 * producto. La página central usa `render_visibility_central` (un solo
	 * formulario para todos los productos).
	 */
	public static function render_visibility_manager( string $product, string $page, array $subs_by_tab, array $ss_by_tab = array() ): void
	{
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="es-visibility-form">';
		wp_nonce_field( 'estandar_suite_save_visibility' );
		echo '<input type="hidden" name="action" value="estandar_suite_save_visibility">';
		echo '<input type="hidden" name="product_ids[]" value="' . esc_attr( $product ) . '">';
		self::render_visibility_groups( $product, $subs_by_tab, $ss_by_tab );
		echo '<p style="margin-top:12px;">';
		submit_button( 'Guardar visibilidad', 'primary', 'save_visibility_btn', false );
		echo '</p>';
		echo '</form>';
		self::visibility_js();
	}

	/** JS del manager de visibilidad (emitido una sola vez por request). */
	private static function visibility_js(): void
	{
		static $vis_js_emitted = false;
		if ( $vis_js_emitted ) {
			return;
		}
		$vis_js_emitted = true;
		echo '<script>
(function(){
  function visSyncMaster(form){
    Array.prototype.forEach.call(form.querySelectorAll(".es-vis-all"), function(master){
      var prod = master.getAttribute("data-product");
      if (!prod) return;
      var cbs = Array.prototype.slice.call(form.querySelectorAll("input[type=checkbox][data-product=\\"" + prod + "\\"]")).filter(function(cb){ return !cb.disabled && cb !== master; });
      var on = cbs.length > 0 && cbs.every(function(cb){ return cb.checked; });
      var some = cbs.some(function(cb){ return cb.checked; });
      master.checked = on;
      master.indeterminate = !on && some;
    });
  }
  function visSaved(form){
    Array.prototype.forEach.call(form.querySelectorAll("input[data-ss-saved]"), function(ss){
      if (!ss.disabled) ss.setAttribute("data-ss-saved", ss.checked ? "1" : "0");
    });
  }
  function visSyncGroup(form, parent){
    var g = parent.getAttribute("data-ss-group");
    if (!g) return;
    var on = parent.checked;
    Array.prototype.forEach.call(form.querySelectorAll("input[data-ss-group=\\"" + g + "\\"]"), function(ss){
      if (ss === parent) return;
      ss.disabled = !on;
      ss.checked = on ? (ss.getAttribute("data-ss-saved") === "1") : false;
    });
  }
  function visBind(){
    Array.prototype.forEach.call(document.querySelectorAll("form.es-visibility-form"), function(form){
      Array.prototype.forEach.call(form.querySelectorAll(".es-vis-all"), function(master){
        master.addEventListener("change", function(){
          var prod = master.getAttribute("data-product");
          var on = master.checked;
          Array.prototype.forEach.call(form.querySelectorAll("input[name^=\\"subs[" + prod + "\\"]"), function(p){
            if (p.disabled) return;
            p.checked = on;
            visSyncGroup(form, p);
          });
          // Master intent: "seleccionar todas" → todos los ss marcados;
          // "deseleccionar todas" → ss apagados (data-ss-saved se conserva).
          Array.prototype.forEach.call(form.querySelectorAll("input[data-ss-saved][data-product=\\"" + prod + "\\"]"), function(ss){
            ss.checked = on;
            ss.disabled = !on;
            if (on) ss.setAttribute("data-ss-saved", "1");
          });
        });
      });
      Array.prototype.forEach.call(form.querySelectorAll("input[name^=\\"subs[\\"]"), function(p){
        p.addEventListener("change", function(){ visSyncGroup(form, p); });
      });
      form.addEventListener("change", function(){ visSaved(form); visSyncMaster(form); });
      visSyncMaster(form);
    });
  }
  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", visBind); } else { visBind(); }
})();
</script>';
	}

	/** admin_post handler: persist visibility para TODOS los productos del form. */
	public static function handle_save_visibility(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_visibility' );
		$products = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? $_POST['product_ids'] : array();
		$all_tabs = isset( $_POST['all_tabs'] ) && is_array( $_POST['all_tabs'] ) ? $_POST['all_tabs'] : array();
		$checked  = isset( $_POST['subs'] ) && is_array( $_POST['subs'] ) ? $_POST['subs'] : array();
		$checked_ss = isset( $_POST['ss'] ) && is_array( $_POST['ss'] ) ? $_POST['ss'] : array();

		foreach ( $products as $product ) {
			$product = sanitize_key( (string) $product );
			if ( $product === '' ) {
				continue;
			}
			$all = isset( $all_tabs[ $product ] ) && is_array( $all_tabs[ $product ] ) ? $all_tabs[ $product ] : array();
			$subs = isset( $checked[ $product ] ) && is_array( $checked[ $product ] ) ? $checked[ $product ] : array();
			$ss   = isset( $checked_ss[ $product ] ) && is_array( $checked_ss[ $product ] ) ? $checked_ss[ $product ] : array();
			// Build the full map: every tab is present; tabs with no checked
			// sub-tab become an empty map (= hidden tab). Cada sub-tab guarda
			// sus ss visibles (vacío = "todos sus ss visibles", misma semántica
			// que filter_ssubs).
			$map = array();
			foreach ( $all as $tab ) {
				$tab = sanitize_key( (string) $tab );
				if ( $tab === '' ) {
					continue;
				}
				$map[ $tab ] = array();
				$csubs = isset( $subs[ $tab ] ) && is_array( $subs[ $tab ] ) ? $subs[ $tab ] : array();
				foreach ( $csubs as $sub ) {
					$sub = sanitize_key( (string) $sub );
					if ( $sub === '' ) {
						continue;
					}
					$ssv = array();
					if ( isset( $ss[ $tab ][ $sub ] ) && is_array( $ss[ $tab ][ $sub ] ) ) {
						$ssv = array_values( array_filter( array_map( 'sanitize_key', $ss[ $tab ][ $sub ] ) ) );
					}
					$map[ $tab ][ $sub ] = $ssv;
				}
			}
			// The visibility manager itself can never be hidden (server-side guard).
			if ( $product === 'core' && isset( $map['config'] ) && is_array( $map['config'] ) && ! array_key_exists( 'visibilidad', $map['config'] ) ) {
				$map['config']['visibilidad'] = array();
			}
			// VISIBILITY_NEW_V1: registrar el catálogo completo (subs/ss que
			// existían) para que lo nuevo del código salga visible por defecto.
			self::save_visibility( $product, $map, self::visibility_catalog( $product ) );
		}
		// La visibilidad se administra centralizada en Core: el guardado
		// redirige a la página central.
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=visibilidad&visibility_saved=1' ) );
		exit;
	}

	/** Catálogo actual (subs/ss por tab) de un producto, desde su estructura de
	 *  visibilidad. Se persiste como `__catalog` al guardar. */
	public static function visibility_catalog( string $product ): array
	{
		$struct = self::visibility_structure_for( $product );
		$catalog = array( 'subs' => array(), 'ss' => array() );
		if ( empty( $struct['subs'] ) || ! is_array( $struct['subs'] ) ) {
			return $catalog;
		}
		foreach ( $struct['subs'] as $tab => $subs ) {
			if ( ! is_array( $subs ) ) {
				continue;
			}
			$tab = sanitize_key( (string) $tab );
			$catalog['subs'][ $tab ] = array_map( 'sanitize_key', array_keys( $subs ) );
			if ( ! empty( $struct['ss'][ $tab ] ) && is_array( $struct['ss'][ $tab ] ) ) {
				foreach ( $struct['ss'][ $tab ] as $sub => $ss_map ) {
					if ( ! is_array( $ss_map ) ) {
						continue;
					}
					$subk = sanitize_key( (string) $sub );
					$catalog['ss'][ $tab ][ $subk ] = array_map( 'sanitize_key', array_keys( $ss_map ) );
				}
			}
		}
		return $catalog;
	}

	/** Devuelve la estructura de visibilidad de un producto (subs y ss), o vacío. */
	private static function visibility_structure_for( string $product ): array
	{
		if ( $product === 'core' ) {
			return self::visibility_structure();
		}
		$classes = array(
			'capture'  => 'Estandar\\Capture\\Admin',
			'brain'    => 'Estandar\\Brain\\Admin',
			'flow'     => 'Estandar\\Flow\\Admin',
			'insights' => 'Estandar\\Insights\\Admin',
		);
		if ( ! isset( $classes[ $product ] ) || ! class_exists( $classes[ $product ] ) || ! method_exists( $classes[ $product ], 'visibility_structure' ) ) {
			return array();
		}
		$s = call_user_func( array( $classes[ $product ], 'visibility_structure' ) );
		return is_array( $s ) ? $s : array();
	}

	/** admin_post handler: restablece la visibilidad de un producto a la vista
	 *  por defecto (borra la option). `product=all` borra todos los productos. */
	public static function handle_reset_visibility(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_reset_visibility' );
		$product  = sanitize_key( (string) ( $_GET['product'] ?? '' ) );
		$products = array( 'core', 'capture', 'brain', 'flow', 'insights' );
		if ( in_array( $product, $products, true ) ) {
			delete_option( self::visibility_option( $product ) );
		} elseif ( $product === 'all' ) {
			foreach ( $products as $p ) {
				delete_option( self::visibility_option( $p ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=visibilidad&visibility_saved=1' ) );
		exit;
	}

	/**
	 * Render the complete function inventory for a product: the 7 functional sets
	 * (Esquema · Dominio · Adopción · Eventos · REST · CLI · UI) each as a table.
	 * Guarantees no function is left out of the admin UI. Data source is the
	 * product's own static catalog (single source of truth).
	 *
	 * @param array $sets        Map of set-label => array of entries.
	 *                           Each entry: array( 'fn' => name, 'file' => source, 'desc' => purpose ).
	 * @param array $fn_settings Map of function-name => array of setting keys it uses
	 *                           (to mark which functions have settings).
	 */
	public static function render_inventory( array $sets, array $fn_settings = array() ): void
	{
		foreach ( $sets as $label => $entries ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th style="width:26%;">Función</th><th style="width:20%;">Origen</th><th>Propósito</th><th style="width:12%;">Settings</th></tr></thead><tbody>';
			foreach ( $entries as $e ) {
				$fn    = $e['fn'];
				$has   = ! empty( $fn_settings[ $fn ] ) ? $fn_settings[ $fn ] : array();
				$badge = $has ? '<span class="dashicons dashicons-admin-generic" style="color:#2271b1;" title="Usa settings"></span> ' . esc_html( implode( ', ', $has ) ) : '<span class="description">—</span>';
				echo '<tr>'
					. '<td><code>' . esc_html( $fn ) . '</code></td>'
					. '<td><code>' . esc_html( $e['file'] ) . '</code></td>'
					. '<td>' . esc_html( $e['desc'] ) . '</td>'
					. '<td>' . $badge . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Render a settings panel for a tab. Each entry is a settings descriptor:
	 *   array(
	 *     'name'    => option key,
	 *     'label'   => human label,
	 *     'desc'    => purpose,
	 *     'editable'=> bool (has a mutation surface in this plugin),
	 *     'legacy'  => bool (owned by a legacy plugin),
	 *     'group'   => optional area label (own settings are grouped by it),
	 *   )
	 * Own settings are rendered grouped by 'group'; legacy settings are shown
	 * in a visually separated section below.
	 */
	public static function render_settings_panel( array $settings ): void
	{
		if ( ! $settings ) {
			return;
		}
		$own    = array_filter( $settings, function ( $s ) { return empty( $s['legacy'] ); } );
		$legacy = array_filter( $settings, function ( $s ) { return ! empty( $s['legacy'] ); } );

		$row_html = function ( array $s ) {
			$value = function_exists( 'get_option' ) ? get_option( $s['name'], null ) : null;
			$value = $value === null ? '' : $value;
			// Seguridad: las opciones heredadas con nombre sensible se muestran
			// enmascaradas (solo lectura, nunca se exponen tokens/claves).
			if ( ! empty( $s['legacy'] ) && $value !== '' && preg_match( '/(token|secret|password|private_?key|api[_-]?key|client_?secret)/i', (string) $s['name'] ) ) {
				$value = '•••••• (oculto)';
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			}
			$state = ! empty( $s['editable'] )
				? '<span style="color:#2271b1;">editable</span>'
				: '<span class="description">read-only</span>';
			return '<tr>'
				. '<td><code>' . esc_html( $s['name'] ) . '</code></td>'
				. '<td>' . esc_html( $s['label'] ) . '<br><span class="description">' . esc_html( $s['desc'] ) . '</span></td>'
				. '<td><code style="word-break:break-all;">' . esc_html( (string) $value ) . '</code></td>'
				. '<td>' . $state . '</td>'
				. '</tr>';
		};
		$table_html = function ( array $list, string $title = '' ) use ( $row_html ) {
			if ( $title !== '' ) {
				echo '<h4 style="margin:18px 0 6px;">' . esc_html( $title ) . '</h4>';
			}
			echo '<table class="widefat striped"><thead><tr><th style="width:30%;">Option</th><th>Descripción</th><th style="width:34%;">Valor actual</th><th style="width:8%;">Estado</th></tr></thead><tbody>';
			foreach ( $list as $s ) {
				echo $row_html( $s );
			}
			echo '</tbody></table>';
		};

		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle ui-sortable-handle" style="padding:8px 12px;margin:0;font-size:14px;">Settings</h2></div>';
		echo '<div class="inside" style="padding:12px;">';

		// Group own settings by 'group' label, preserving declaration order.
		$groups = array();
		foreach ( $own as $s ) {
			$groups[ $s['group'] ?? 'General' ][] = $s;
		}
		foreach ( $groups as $g => $items ) {
			$table_html( $items, $g );
		}

		if ( $legacy ) {
			echo '<h4 style="margin:18px 0 6px;">Settings heredados (plugins anteriores)</h4>';
			echo '<p class="description" style="margin:0 0 8px;">Opciones que pertenecen a los plugins legados; el v2 solo las lee, nunca las modifica.</p>';
			$table_html( $legacy );
		}
		echo '</div></div>';
	}

	/** Backend Ordenado: ¿modo "solo estructura" (nativo sin contenido)? */
	public static function native_blank(): bool
	{
		return (int) get_option( 'estandar_suite_core_native_blank', 0 ) === 1;
	}

	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		if ( isset( $_GET['selfcheck'] ) && check_admin_referer( 'estandar_suite_core_selfcheck' ) ) {
			AutoDiag::snapshot( true );
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=estado&sub=autodiagnostico&selfcheck_done=1' ) );
			exit;
		}
		\Estandar\Core\Admin::render_product_header();
		$tabs = array(
			'inicio'   => 'Inicio',
			'estado'   => 'Estado',
			'config'   => 'Configuración',
			'datos'    => 'Datos',
			'avanzado' => 'Avanzado',
		);

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'settings'          => array( 'estandar_suite_core_telemetry_settings' ),
			'save_settings'     => array( 'estandar_suite_core_telemetry_settings' ),
			'map_events'        => array( 'estandar_suite_core_event_map' ),
			'event_map'         => array( 'estandar_suite_core_event_map' ),
			'event_targets'     => array( 'estandar_suite_core_event_map' ),
			'key'               => array( 'estandar_suite_core_secret_key' ),
			'create'            => array( 'estandar_suite_core_secret_key' ),
			'consume'           => array( 'estandar_suite_core_secret_key' ),
			'activate'          => array( 'estandar_suite_core_schema_version' ),
			'maybe_migrate'     => array( 'estandar_suite_core_schema_version' ),
		);

		// ── Settings mostrados en el panel del tab Configuración ────────────
		$settings = array(
			array( 'name' => 'estandar_suite_core_telemetry_settings', 'label' => 'Telemetría', 'desc' => 'Nivel mínimo, retención y habilitación del bus de eventos.', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_event_map', 'label' => 'Mapa de eventos → destinos', 'desc' => 'Qué eventos avisan a cada destino.', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_secret_key', 'label' => 'Clave AES-256-GCM', 'desc' => 'Clave maestra de cifrado de secretos (generada al instalar).', 'editable' => false, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_core_*.', 'editable' => false, 'legacy' => false ),
		);
		$tab        = self::resolve_tab( $tabs, 'inicio' );
		$subs = array(
			'inicio'   => array( 'bienvenida' => 'Bienvenida' ),
			'estado'   => array( 'resumen' => 'Resumen', 'autodiagnostico' => 'Autodiagnóstico', 'datos' => 'Datos', 'esquema' => 'Esquema', 'mapa' => 'Mapa del sitio' ),
			'config'   => array( 'configuracion' => 'Configuración', 'telemetria' => 'Telemetría', 'leads' => 'Leads', 'claves' => 'Claves API', 'correo' => 'Correo', 'proyectos' => 'Proyectos' ),
			'datos'    => array( 'adopcion' => 'Adopción', 'heredados' => 'Settings heredados' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'actualizaciones' => 'Actualizaciones', 'inventario' => 'Inventario', 'herramientas' => 'Herramientas' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = self::filter_tabs( 'core', $tabs, $subs );
		if ( empty( $visible_tabs ) ) {
			self::render_visibility_none( 'core' );
			return;
		}
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = self::filter_subs( 'core', $t, $s_list );
		}
		$tabs = $visible_tabs;
		// Always keep the visibility manager and Emoji (marca) reachable in Configuración.
		if ( isset( $tabs['config'] ) ) {
			// Configuración (primera), Proyectos y Operadores siempre como subs líderes.
			$subs['config'] = array_merge(
				array( 'configuracion' => 'Configuración', 'proyectos' => 'Proyectos', 'operadores' => 'Operadores' ),
				$subs['config']
			);
			$subs['config']['correo']       = 'Correo';
			$subs['config']['qualification'] = 'Calificación';
			$subs['config']['digest']       = 'Resumen de leads';
		}
		// The suite intro tab is always reachable (intro).
		if ( isset( $tabs['inicio'] ) ) {
			$subs['inicio']['bienvenida'] = 'Bienvenida';
		}
		// Actualizaciones siempre alcanzable (mantenimiento crítico).
		if ( isset( $tabs['avanzado'] ) ) {
			$subs['avanzado']['actualizaciones'] = 'Actualizaciones';
			$subs['avanzado']['herramientas']    = 'Herramientas';
		}
		// Sin parámetros → Core aterriza en Configuración → Ajustes
		// generales (primera sub-pestaña); el resto queda por URL.
		$default_tab = isset( $_GET['tab'] ) ? 'estado' : ( isset( $tabs['config'] ) ? 'config' : 'inicio' );
		$tab = self::resolve_tab( $tabs, $default_tab );
		$default_sub = ( $tab === 'config' && ! isset( $_GET['sub'] ) ) ? 'configuracion' : '';
		$sub = isset( $subs[ $tab ] ) ? self::resolve_sub( $subs[ $tab ], $default_sub ) : '';
		$health    = Health::snapshot();
		$auto      = AutoDiag::snapshot();
		$telemetry = Telemetry::recent( 25 );
		$legacy    = AdoptionProbe::probe_legacy();
		$dest_saved = isset( $_GET['dest_saved'] );
		$dest_del   = isset( $_GET['dest_del'] );
		$secret_ok  = isset( $_GET['secret_ok'] );
		$test_id    = isset( $_GET['test_id'] ) ? (int) $_GET['test_id'] : 0;
		$test_ok    = isset( $_GET['test_ok'] );
		$test_http  = isset( $_GET['test_http'] ) ? (int) $_GET['test_http'] : 0;
		$test_err   = isset( $_GET['test_err'] ) ? sanitize_text_field( wp_unslash( $_GET['test_err'] ) ) : '';
		$map_saved  = isset( $_GET['map_saved'] );
		$telemetry_saved = isset( $_GET['telemetry_saved'] );
		$retry_n    = isset( $_GET['retry'] ) ? (int) $_GET['retry'] : 0;
		$leads_tokens  = LeadsPanel::ensure_tokens();
		$leads_panel_url = home_url( '/' . LeadsPanel::PANEL_SLUG . '/' );
		$leads_operators = LeadsPanel::operators();
		$leads_notifier_enabled = Leads\OperatorNotifier::enabled();
		$leads_api_url = rest_url( 'estandar-suite/core/v1/leads' );

		$token_rot     = isset( $_GET['token_rot'] ) ? sanitize_key( (string) $_GET['token_rot'] ) : '';

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate', 'file' => 'Schema.php', 'desc' => 'Crea tablas y opciones propias al activar el plugin.' ),
				array( 'fn' => 'create_tables', 'file' => 'Schema.php', 'desc' => 'Crea las tablas estandar_core_* si no existen.' ),
				array( 'fn' => 'maybe_migrate', 'file' => 'Schema.php', 'desc' => 'Aplica migraciones de esquema según versión guardada.' ),
				array( 'fn' => 'protected_legacy_prefixes', 'file' => 'Schema.php', 'desc' => 'Lista de prefijos legados (es_/ea_/ecb_/estandar_leads_) que nunca se escriben.' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'defaults', 'file' => 'Telemetry.php', 'desc' => 'Valores por defecto de la configuración de telemetría.' ),
				array( 'fn' => 'settings', 'file' => 'Telemetry.php', 'desc' => 'Lee y normaliza la configuración de telemetría.' ),
				array( 'fn' => 'save_settings', 'file' => 'Telemetry.php', 'desc' => 'Persiste la configuración de telemetría.' ),
				array( 'fn' => 'emit', 'file' => 'Telemetry.php', 'desc' => 'Registra un evento técnico sin PII.' ),
				array( 'fn' => 'level_allows', 'file' => 'Telemetry.php', 'desc' => 'Decide si un nivel supera el mínimo configurado.' ),
				array( 'fn' => 'prune', 'file' => 'Telemetry.php', 'desc' => 'Elimina eventos viejos según retención.' ),
				array( 'fn' => 'recent', 'file' => 'Telemetry.php', 'desc' => 'Devuelve los últimos eventos técnicos.' ),
				array( 'fn' => 'sanitize_context', 'file' => 'Telemetry.php', 'desc' => 'Limpia el contexto para no registrar PII.' ),
				array( 'fn' => 'create', 'file' => 'Secrets.php', 'desc' => 'Crea un secreto cifrado de un solo uso.' ),
				array( 'fn' => 'token', 'file' => 'Secrets.php', 'desc' => 'Genera el token del secreto.' ),
				array( 'fn' => 'consume', 'file' => 'Secrets.php', 'desc' => 'Consume un secreto (una sola lectura).' ),
				array( 'fn' => 'consume_link', 'file' => 'Secrets.php', 'desc' => 'Construye el enlace de consumo del secreto.' ),
				array( 'fn' => 'cleanup', 'file' => 'Secrets.php', 'desc' => 'Limpia secretos usados o expirados.' ),
				array( 'fn' => 'key', 'file' => 'Secrets.php', 'desc' => 'Genera/deriva la clave de cifrado del secreto.' ),
				array( 'fn' => 'status', 'file' => 'Secrets.php', 'desc' => 'Estado de un secreto (activo/usado/expirado).' ),
				array( 'fn' => 'register_destination', 'file' => 'Destinations.php', 'desc' => 'Registra un destino (webhook/sheets/CRM).' ),
				array( 'fn' => 'enqueue', 'file' => 'Destinations.php', 'desc' => 'Encola una entrega para un destino.' ),
				array( 'fn' => 'dispatch_event', 'file' => 'Destinations.php', 'desc' => 'Entrega un evento a sus destinos mapeados.' ),
				array( 'fn' => 'drain', 'file' => 'Destinations.php', 'desc' => 'Procesa entregas pendientes.' ),
				array( 'fn' => 'test', 'file' => 'Destinations.php', 'desc' => 'Prueba la conexión con un destino.' ),
				array( 'fn' => 'idempotency_key', 'file' => 'Destinations.php', 'desc' => 'Clave para evitar entregas duplicadas.' ),
				array( 'fn' => 'canonical_events', 'file' => 'Destinations.php', 'desc' => 'Lista de eventos canónicos de la suite.' ),
				array( 'fn' => 'map_events', 'file' => 'Destinations.php', 'desc' => 'Mapea eventos a un destino.' ),
				array( 'fn' => 'event_map', 'file' => 'Destinations.php', 'desc' => 'Devuelve el mapa evento→destino.' ),
				array( 'fn' => 'event_targets', 'file' => 'Destinations.php', 'desc' => 'Devuelve los destinos objetivo de un evento.' ),
				array( 'fn' => 'list', 'file' => 'Destinations.php', 'desc' => 'Lista destinos registrados.' ),
				array( 'fn' => 'delete', 'file' => 'Destinations.php', 'desc' => 'Elimina un destino.' ),
				array( 'fn' => 'resolve', 'file' => 'Channels.php', 'desc' => 'Resuelve el canal de una captura.' ),
				array( 'fn' => 'extract_click_ids', 'file' => 'Channels.php', 'desc' => 'Extrae click IDs de una captura.' ),
				array( 'fn' => 'has_click_evidence', 'file' => 'Channels.php', 'desc' => 'Indica si hay evidencia de click.' ),
				array( 'fn' => 'is_optimal', 'file' => 'Optimal.php', 'desc' => 'Compara un valor contra el óptimo de fábrica.' ),
				array( 'fn' => 'diff', 'file' => 'Optimal.php', 'desc' => 'Describe la diferencia respecto al óptimo.' ),
				array( 'fn' => 'note', 'file' => 'Optimal.php', 'desc' => 'Etiqueta visual de cercanía al óptimo.' ),
				array( 'fn' => 'snapshot', 'file' => 'Health.php', 'desc' => 'Diagnóstico estructurado de salud.' ),
				array( 'fn' => 'plugin_status', 'file' => 'Health.php', 'desc' => 'Estado de salud de un plugin de la suite.' ),
				array( 'fn' => 'render_text', 'file' => 'Health.php', 'desc' => 'Representación textual del diagnóstico.' ),
				array( 'fn' => 'record', 'file' => 'Contracts.php', 'desc' => 'Contrato TelemetryBus: registrar un evento.' ),
				array( 'fn' => 'persist', 'file' => 'Contracts.php', 'desc' => 'Contrato CaptureSink: persistir una captura.' ),
				array( 'fn' => 'status_label', 'file' => 'Contracts.php', 'desc' => 'Contrato CommercialStageMap: etiqueta de etapa.' ),
			),
			'Acceso y auditoría (ACCESS_V1)' => array(
				array( 'fn' => 'ensure_role', 'file' => 'Access.php', 'desc' => 'Crea/actualiza el rol editor_web con capacidades mínimas.' ),
				array( 'fn' => 'screen_allowed', 'file' => 'Access.php', 'desc' => 'Decide si una pantalla de admin está en la lista blanca.' ),
				array( 'fn' => 'guard_screen', 'file' => 'Access.php', 'desc' => 'Corta con 403 la pantalla fuera de la lista blanca (usuarios limitados).' ),
				array( 'fn' => 'insert_log', 'file' => 'Access.php', 'desc' => 'Registra un evento de auditoría en estandar_core_access_log.' ),
				array( 'fn' => 'recent_logs', 'file' => 'Access.php', 'desc' => 'Últimas entradas de auditoría (con filtro por usuario).' ),
				array( 'fn' => 'prune_logs', 'file' => 'Access.php', 'desc' => 'Borra entradas de auditoría vencidas según retención.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'probe_legacy', 'file' => 'AdoptionProbe.php', 'desc' => 'Detecta tablas legadas de la instalación original.' ),
				array( 'fn' => 'is_legacy_table', 'file' => 'AdoptionProbe.php', 'desc' => 'Comprueba si un prefijo de tabla es legado protegido.' ),
				array( 'fn' => 'check_option', 'file' => 'Health.php', 'desc' => 'Valida el estado de una opción de la suite.' ),
				array( 'fn' => 'init', 'file' => 'LegacyMap.php', 'desc' => 'Puente de migración: re-registra shortcodes y REST legados cuando su plugin queda inactivo.' ),
				array( 'fn' => 'legacy_option', 'file' => 'LegacyMap.php', 'desc' => 'Lee settings/tokens legados sin tocarlos.' ),
				array( 'fn' => 'has_legacy_install', 'file' => 'LegacyMap.php', 'desc' => 'True si existen tablas legadas (producción-like).' ),
				array( 'fn' => 'verify_coverage', 'file' => 'LegacyMap.php', 'desc' => 'Reporte de cobertura de migración (read-only).' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'legacy_table', 'file' => 'Admin.php', 'desc' => 'Verifica que exista una tabla legada.' ),
				array( 'fn' => 'legacy_rows', 'file' => 'Admin.php', 'desc' => 'Lee filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_count', 'file' => 'Admin.php', 'desc' => 'Cuenta filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_group', 'file' => 'Admin.php', 'desc' => 'Agrupa por columna una tabla legada (read-only).' ),
				array( 'fn' => 'render_feature_settings', 'file' => 'Admin.php', 'desc' => 'Renderiza el formulario de settings de una feature.' ),
				array( 'fn' => 'sanitize_feature_fields', 'file' => 'Admin.php', 'desc' => 'Sanitiza los settings de una feature contra su whitelist.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra menú, hooks admin_post y el evento estandar_suite_admin_register.' ),
				array( 'fn' => 'init_frontend', 'file' => 'Secrets.php', 'desc' => 'Registra la ruta pública de consumo de secretos.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Define las rutas REST del Core (health/telemetry/secrets).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra las rutas REST en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'doctor', 'file' => 'Cli.php', 'desc' => 'Diagnóstico de la suite.' ),
				array( 'fn' => 'telemetry', 'file' => 'Cli.php', 'desc' => 'Ver eventos técnicos.' ),
				array( 'fn' => 'telemetry_status', 'file' => 'Cli.php', 'desc' => 'Estado de la telemetría.' ),
				array( 'fn' => 'telemetry_settings', 'file' => 'Cli.php', 'desc' => 'Configuración de telemetría vía CLI.' ),
				array( 'fn' => 'purge_secrets', 'file' => 'Cli.php', 'desc' => 'Eliminar secretos usados/expirados.' ),
				array( 'fn' => 'secrets_list', 'file' => 'Cli.php', 'desc' => 'Listar secretos.' ),
				array( 'fn' => 'secrets_create', 'file' => 'Cli.php', 'desc' => 'Crear un secreto.' ),
				array( 'fn' => 'destinations_list', 'file' => 'Cli.php', 'desc' => 'Listar destinos.' ),
				array( 'fn' => 'destinations_add', 'file' => 'Cli.php', 'desc' => 'Registrar un destino.' ),
				array( 'fn' => 'destinations_delete', 'file' => 'Cli.php', 'desc' => 'Eliminar un destino.' ),
				array( 'fn' => 'destinations_test', 'file' => 'Cli.php', 'desc' => 'Probar un destino.' ),
				array( 'fn' => 'drain', 'file' => 'Cli.php', 'desc' => 'Enviar entregas pendientes.' ),
				array( 'fn' => 'probe', 'file' => 'Cli.php', 'desc' => 'Detectar instalación legada.' ),
				array( 'fn' => 'option_get', 'file' => 'Cli.php', 'desc' => 'Leer una opción de la suite.' ),
				array( 'fn' => 'option_set', 'file' => 'Cli.php', 'desc' => 'Escribir una opción de la suite.' ),
				array( 'fn' => 'option_delete', 'file' => 'Cli.php', 'desc' => 'Eliminar una opción de la suite.' ),
				array( 'fn' => 'is_settable_option', 'file' => 'Cli.php', 'desc' => 'Valida que una opción sea modificable.' ),
				array( 'fn' => 'feature_settings_show', 'file' => 'Cli.php', 'desc' => 'Muestra los settings de una feature (CLI, compartido).' ),
				array( 'fn' => 'feature_settings_set', 'file' => 'Cli.php', 'desc' => 'Establece un setting de feature (CLI, compartido).' ),
				array( 'fn' => 'secure_list', 'file' => 'Cli.php', 'desc' => 'Lista entregas seguras legadas.' ),
				array( 'fn' => 'secure_settings_get', 'file' => 'Cli.php', 'desc' => 'Muestra settings del puente Secure Share.' ),
				array( 'fn' => 'secure_settings_set', 'file' => 'Cli.php', 'desc' => 'Establece un setting del puente Secure Share.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Crea el menú raíz "Estandar Suite" y dispara el registro de submenús.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Core con sus 4 tabs.' ),
				array( 'fn' => 'render_tabs', 'file' => 'Admin.php', 'desc' => 'Nav compartida de 4 tabs (Estado/Configuración/Datos/Avanzado).' ),
				array( 'fn' => 'render_inventory', 'file' => 'Admin.php', 'desc' => 'Renderiza el inventario completo de funciones por conjunto.' ),
				array( 'fn' => 'render_ssubtabs', 'file' => 'Admin.php', 'desc' => 'Nav de tercer nivel (sub-sub-tabs) dentro de un feature.' ),
				array( 'fn' => 'resolve_ssub', 'file' => 'Admin.php', 'desc' => 'Normaliza el sub-sub-tab solicitado (?ss=).' ),
				array( 'fn' => 'resolve_tab', 'file' => 'Admin.php', 'desc' => 'Valida el tab solicitado contra la whitelist.' ),
				array( 'fn' => 'handle_add_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para registrar destino.' ),
				array( 'fn' => 'handle_delete_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar destino.' ),
				array( 'fn' => 'handle_test_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para probar destino.' ),
				array( 'fn' => 'handle_save_event_map', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar mapa de eventos.' ),
				array( 'fn' => 'handle_create_secret', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear secreto.' ),
				array( 'fn' => 'handle_save_telemetry_settings', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar telemetría.' ),
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature (secure).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'maybe_render', 'file' => 'Secrets.php', 'desc' => 'Render condicional del consumo de secreto en frontend.' ),
				array( 'fn' => 'render_page', 'file' => 'Secrets.php', 'desc' => 'Renderiza la página de consumo de un secreto.' ),
			),
		);

		include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/core-admin.php';
	}

	/** Emoji catalog grouped by category (label for search). */
	public static function emoji_catalog(): array
	{
		return array(
			'Emojis' => array(
				array( 'emoji' => '😀', 'label' => 'cara sonriente' ), array( 'emoji' => '😁', 'label' => 'cara feliz' ),
				array( 'emoji' => '😂', 'label' => 'cara llorando de risa' ), array( 'emoji' => '🤣', 'label' => 'rodando de risa' ),
				array( 'emoji' => '😊', 'label' => 'cara sonriente con ojos' ), array( 'emoji' => '😍', 'label' => 'ojos de corazon' ),
				array( 'emoji' => '😎', 'label' => 'gafas de sol' ), array( 'emoji' => '🤔', 'label' => 'pensando' ),
				array( 'emoji' => '😴', 'label' => 'dormido' ), array( 'emoji' => '🤯', 'label' => 'cabeza explotando' ),
				array( 'emoji' => '🥳', 'label' => 'fiesta' ), array( 'emoji' => '😭', 'label' => 'llorando' ),
				array( 'emoji' => '😡', 'label' => 'enojado' ), array( 'emoji' => '👍', 'label' => 'pulgar arriba' ),
				array( 'emoji' => '👎', 'label' => 'pulgar abajo' ), array( 'emoji' => '👏', 'label' => 'aplausos' ),
				array( 'emoji' => '🙏', 'label' => 'gracias' ), array( 'emoji' => '🤝', 'label' => 'apreton de manos' ),
				array( 'emoji' => '✌️', 'label' => 'victoria' ), array( 'emoji' => '🤞', 'label' => 'cruzar dedos' ),
				array( 'emoji' => '💪', 'label' => 'musculo' ), array( 'emoji' => '👀', 'label' => 'ojos' ),
				array( 'emoji' => '🗣️', 'label' => 'hablando' ), array( 'emoji' => '💬', 'label' => 'burbuja de dialogo' ),
			),
			'Animales y naturaleza' => array(
				array( 'emoji' => '🐲', 'label' => 'dragon' ), array( 'emoji' => '🐉', 'label' => 'dragon chino' ),
				array( 'emoji' => '🦖', 'label' => 'dinosaurio' ), array( 'emoji' => '🐺', 'label' => 'lobo' ),
				array( 'emoji' => '🦊', 'label' => 'zorro' ), array( 'emoji' => '🐱', 'label' => 'gato' ),
				array( 'emoji' => '🐶', 'label' => 'perro' ), array( 'emoji' => '🦁', 'label' => 'leon' ),
				array( 'emoji' => '🐯', 'label' => 'tigre' ), array( 'emoji' => '🐼', 'label' => 'panda' ),
				array( 'emoji' => '🐧', 'label' => 'pinguino' ), array( 'emoji' => '🦅', 'label' => 'aguila' ),
				array( 'emoji' => '🦉', 'label' => 'buho' ), array( 'emoji' => '🐢', 'label' => 'tortuga' ),
				array( 'emoji' => '🐳', 'label' => 'ballena' ), array( 'emoji' => '🦋', 'label' => 'mariposa' ),
				array( 'emoji' => '🌵', 'label' => 'cactus' ), array( 'emoji' => '🌴', 'label' => 'palmera' ),
				array( 'emoji' => '🌸', 'label' => 'flor' ), array( 'emoji' => '🌺', 'label' => 'hibisco' ),
				array( 'emoji' => '🍀', 'label' => 'trébol' ), array( 'emoji' => '🌍', 'label' => 'tierra' ),
				array( 'emoji' => '🔥', 'label' => 'fuego' ), array( 'emoji' => '⚡', 'label' => 'rayo' ),
				array( 'emoji' => '🌧️', 'label' => 'lluvia' ), array( 'emoji' => '❄️', 'label' => 'nieve' ),
				array( 'emoji' => '☀️', 'label' => 'sol' ), array( 'emoji' => '🌙', 'label' => 'luna' ),
				array( 'emoji' => '⭐', 'label' => 'estrella' ), array( 'emoji' => '🌈', 'label' => 'arcoiris' ),
			),
			'Objetos' => array(
				array( 'emoji' => '🚀', 'label' => 'cohete' ), array( 'emoji' => '🛠️', 'label' => 'herramientas' ),
				array( 'emoji' => '⚙️', 'label' => 'engranaje' ), array( 'emoji' => '🧰', 'label' => 'caja de herramientas' ),
				array( 'emoji' => '💻', 'label' => 'computadora' ), array( 'emoji' => '🖥️', 'label' => 'monitor' ),
				array( 'emoji' => '📱', 'label' => 'telefono' ), array( 'emoji' => '🧠', 'label' => 'cerebro' ),
				array( 'emoji' => '💡', 'label' => 'bombilla' ), array( 'emoji' => '🔧', 'label' => 'llave inglesa' ),
				array( 'emoji' => '🔩', 'label' => 'tornillo' ), array( 'emoji' => '🧩', 'label' => 'pieza de puzzle' ),
				array( 'emoji' => '📦', 'label' => 'paquete' ), array( 'emoji' => '📊', 'label' => 'grafico de barras' ),
				array( 'emoji' => '📈', 'label' => 'grafico ascendente' ), array( 'emoji' => '📉', 'label' => 'grafico descendente' ),
				array( 'emoji' => '🗂️', 'label' => 'archivador' ), array( 'emoji' => '📁', 'label' => 'carpeta' ),
				array( 'emoji' => '📎', 'label' => 'clip' ), array( 'emoji' => '🔑', 'label' => 'llave' ),
				array( 'emoji' => '🔒', 'label' => 'candado' ), array( 'emoji' => '🔓', 'label' => 'candado abierto' ),
				array( 'emoji' => '🖇️', 'label' => 'clips' ), array( 'emoji' => '📋', 'label' => 'portapapeles' ),
				array( 'emoji' => '📌', 'label' => 'chincheta' ), array( 'emoji' => '🗑️', 'label' => 'papelera' ),
			),
			'Símbolos' => array(
				array( 'emoji' => '✅', 'label' => 'check' ), array( 'emoji' => '❌', 'label' => 'equis' ),
				array( 'emoji' => '⚠️', 'label' => 'advertencia' ), array( 'emoji' => '🚨', 'label' => 'alerta' ),
				array( 'emoji' => '💯', 'label' => 'cien' ), array( 'emoji' => '🎯', 'label' => 'objetivo' ),
				array( 'emoji' => '🎯', 'label' => 'diana' ), array( 'emoji' => '💼', 'label' => 'maletin' ),
				array( 'emoji' => '🏗️', 'label' => 'construccion' ), array( 'emoji' => '🚧', 'label' => 'obras' ),
				array( 'emoji' => '🔍', 'label' => 'lupa' ), array( 'emoji' => '🔎', 'label' => 'lupa ampliar' ),
				array( 'emoji' => '🔄', 'label' => 'recargar' ), array( 'emoji' => '🔁', 'label' => 'repetir' ),
				array( 'emoji' => '⚡', 'label' => 'energia' ), array( 'emoji' => '💎', 'label' => 'diamante' ),
				array( 'emoji' => '🏆', 'label' => 'trofeo' ), array( 'emoji' => '🥇', 'label' => 'medalla oro' ),
				array( 'emoji' => '🏅', 'label' => 'medalla' ), array( 'emoji' => '📢', 'label' => 'altavoz' ),
				array( 'emoji' => '🔔', 'label' => 'campana' ), array( 'emoji' => '🎉', 'label' => 'celebracion' ),
				array( 'emoji' => '🎊', 'label' => 'confeti' ), array( 'emoji' => '✨', 'label' => 'destello' ),
				array( 'emoji' => '🌟', 'label' => 'brillante' ), array( 'emoji' => '🚦', 'label' => 'semáforo' ),
				array( 'emoji' => '🛡️', 'label' => 'escudo' ), array( 'emoji' => '🧪', 'label' => 'laboratorio' ),
			),
		);
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			// ── Infraestructura (bus técnico) ──────────────────────────────
			array( 'name' => 'estandar_suite_core_telemetry_settings', 'label' => 'Telemetría', 'desc' => 'Nivel mínimo, retención y habilitación del bus de eventos.', 'editable' => true, 'legacy' => false, 'group' => 'Infraestructura' ),
			array( 'name' => 'estandar_suite_core_secret_key', 'label' => 'Clave AES-256-GCM', 'desc' => 'Clave maestra de cifrado de secretos (generada al instalar).', 'editable' => false, 'legacy' => false, 'group' => 'Infraestructura' ),
			// ── Operación (destinos y exportación) ─────────────────────────
			array( 'name' => 'estandar_suite_core_event_map', 'label' => 'Mapa de eventos → destinos', 'desc' => 'Qué eventos avisan a cada destino.', 'editable' => true, 'legacy' => false, 'group' => 'Operación' ),
			// ── Esquema ────────────────────────────────────────────────────
			array( 'name' => 'estandar_suite_core_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_core_*.', 'editable' => false, 'legacy' => false, 'group' => 'Esquema' ),
			// ── UI (identidad del menú) ────────────────────────────────────
			array( 'name' => 'estandar_suite_core_menu_icon', 'label' => 'Emoji del menú', 'desc' => 'Emoji que identifica a Estandar Suite en el panel y la barra superior.', 'editable' => true, 'legacy' => false, 'group' => 'UI' ),
		);
	}

	/** Settings inherited from the legacy plugins (read-only bridge). */
	public static function get_legacy_settings(): array
	{
		return array(
			array( 'name' => 'estandar_kickoff_security', 'label' => 'KickOff · Seguridad', 'desc' => 'Login, XML-RPC, headers y rate-limiting del sitio.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_kickoff_maintenance', 'label' => 'KickOff · Mantenimiento', 'desc' => 'Modo mantenimiento: mensaje, título e IPs permitidas.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_kickoff_modules_enabled', 'label' => 'KickOff · Módulos activos', 'desc' => 'Qué módulos de KickOff están habilitados.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_core_admin_bar_items', 'label' => 'Primer · Barra admin', 'desc' => 'Items personalizados de la barra de administración.', 'editable' => false, 'legacy' => true ),
		);
	}

	// ── admin-post handlers (capability + nonce + sanitize) ─────────────────

	public static function handle_leads_regenerate(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_leads_regenerate' );
		$which = sanitize_key( (string) ( $_POST['which'] ?? '' ) );
		if ( in_array( $which, array( 'viewer', 'api_key' ), true ) ) {
			LeadsPanel::regenerate( $which );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=api&token_rot=' . ( $which === 'api_key' ? 'key' : 'viewer' ) ) );
		exit;
	}

	/** Alta/baja de operadores del panel de leads (nombre + PIN 4 letras). */
	public static function handle_leads_operator(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_leads_operator' );
		$op   = sanitize_key( (string) ( $_POST['op_action'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
		if ( $op === 'remove' && $name !== '' ) {
			LeadsPanel::remove_operator( $name );
		} elseif ( $op === 'add' ) {
			$pin = (string) ( $_POST['pin'] ?? '' );
			LeadsPanel::save_operator( $name, $pin );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=panel' ) );
		exit;
	}

	/**
	 * GATE_METHODS_V1 (2026-09-21): métodos de acceso del gate de operador
	 * (shortcodes estandar_form_gate / estandar_camara_registros). Acepta
	 * 'both' (default) | 'pin' | 'magic'. No borra ni migra operadores.
	 */
	public static function handle_leads_gate_methods(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_leads_gate_methods' );
		LeadsPanel::set_gate_methods( (string) ( $_POST['method'] ?? 'both' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=panel&gate_saved=1' ) );
		exit;
	}

	/** Toggle del notificador v2 al operador (email enriquecido). */
	public static function handle_leads_notifier(): void	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_leads_notifier' );
		Leads\OperatorNotifier::set_enabled( ! empty( $_POST['enabled'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=panel' ) );
		exit;
	}

	/** Save the telemetry settings (Operación tab). */
	public static function handle_save_telemetry_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_telemetry_settings' );
		Telemetry::save_settings( is_array( $_POST ) ? $_POST : array() );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=telemetria&telemetry_saved=1' ) );
		exit;
	}

	/** Save the menu emoji (UI group). */
	public static function handle_save_menu_icon(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_menu_icon' );
		$custom = trim( (string) ( $_POST['menu_icon_custom'] ?? '' ) );
		$emoji  = $custom !== '' ? $custom : trim( (string) ( $_POST['menu_icon'] ?? '' ) );
		if ( $emoji === '' ) {
			$emoji = '🐲';
		}
		// Keep only a single emoji-ish token (allow multi-codepoint emoji).
		$emoji = mb_substr( $emoji, 0, 8 );
		update_option( 'estandar_suite_core_menu_icon', $emoji, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=configuracion&cfg=emoji&icon_saved=1' ) );
		exit;
	}

	/** Guarda la identidad de la marca (fallback global de los correos):
	 *  solo `default_logo` + `brand_color`. Vive en Configuración → Correo → Identidad. */
	public static function handle_save_identity(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_identity' );
		$logo  = esc_url_raw( (string) ( $_POST['default_logo'] ?? '' ) );
		$color = (string) ( $_POST['brand_color'] ?? '' );
		if ( $color !== '' ) {
			$color = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $color ) : preg_replace( '/[^#0-9a-fA-F]/', '', $color );
		}
		update_option( 'estandar_suite_core_default_logo', $logo, false );
		update_option( 'estandar_suite_core_brand_color', $color, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=configuracion&cfg=correo&cc=identidad&identity_saved=1' ) );
		exit;
	}

	/** MAIL_TEMPLATES_V1 (Fase 1): guarda el registro de plantillas de correo.
	 *  `edit[slug]` = { name, header_html, content_style, footer_html };
	 *  `order` mantiene el orden; `make_default` define la global ('' = ninguna). */
	public static function handle_save_mail_templates(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_mail_templates' );
		$order = ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) )
			? array_values( array_filter( array_map( 'sanitize_key', (array) $_POST['order'] ) ) )
			: array();
		$edit  = ( isset( $_POST['edit'] ) && is_array( $_POST['edit'] ) ) ? (array) $_POST['edit'] : array();
		$raw   = array();
		foreach ( $order as $slug ) {
			if ( isset( $edit[ $slug ] ) && is_array( $edit[ $slug ] ) ) {
				$raw[ $slug ] = $edit[ $slug ];
			}
		}
		foreach ( $edit as $slug => $t ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || isset( $raw[ $slug ] ) ) {
				continue;
			}
			$raw[ $slug ] = is_array( $t ) ? $t : array();
		}
		\Estandar\Core\Mail::save_mail_templates( $raw );
		if ( isset( $_POST['make_default'] ) && (string) $_POST['make_default'] !== '' ) {
			$def = sanitize_key( (string) $_POST['make_default'] );
			update_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, $def, false );
		} elseif ( isset( $_POST['clear_default'] ) ) {
			update_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, '', false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&cc=plantillas&templates_saved=1' ) );
		exit;
	}

	/** MAIL_TEMPLATES_V1: define la plantilla por defecto a nivel global. */
	public static function handle_set_default_template(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_set_default_template' );
		$slug = sanitize_key( (string) ( $_POST['template_slug'] ?? '' ) );
		update_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, $slug, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&cc=plantillas&templates_saved=1' ) );
		exit;
	}

	/** MAIL_TEMPLATES_V1: elimina una plantilla (nunca la única base). */
	public static function handle_delete_template(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_delete_template' );
		$slug = sanitize_key( (string) ( $_POST['template_slug'] ?? '' ) );
		$all  = \Estandar\Core\Mail::mail_templates();
		if ( $slug !== '' && $slug !== 'base' && isset( $all[ $slug ] ) ) {
			unset( $all[ $slug ] );
			\Estandar\Core\Mail::save_mail_templates( $all );
		}
		if ( (string) get_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, '' ) === $slug ) {
			update_option( \Estandar\Core\Mail::TEMPLATE_DEFAULT_OPTION, '', false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo&cc=plantillas&templates_saved=1' ) );
		exit;
	}
}
