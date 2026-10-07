<?php
/**
 * ESTANDAR → Comunicaciones.
 *
 * Centro operativo de CONFIGURACIÓN de correos. CORE administra settings;
 * los plugins siguen siendo dueños del envío. No envía, no mueve plantillas,
 * no cambia triggers ni cron. Edita LAS MISMAS keys que ya consumen los plugins
 * (vía sus propios get/save → sin duplicados, con su validación). CERO options
 * nuevas: cada sección consolida sobre una key/estructura ya existente.
 *
 * Keys reutilizadas (consumidas hoy):
 *   - Identidad:     estandar_sender_email / estandar_sender_name (core)
 *   - Reply-To/CC:   estandar_leads_email_settings[reply_to_email|notification_cc_email]
 *   - Compliance:    estandar_leads_compliance_settings[enable_compliance_engine|supervisor_email|compliance_cc_email]
 *   - Digest:        gobernado por Compliance(enable) + filtro estandar_compliance_digest_dispatch_days (read-only aquí)
 *   - Reportes:      estandar_leads_weekly_report_recipients (key real del Weekly Report)
 *   - Autorespuesta: estandar_leads_autoresponse_settings['default'][enabled|subject]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Communications_Admin' ) ) :

class Estandar_Communications_Admin {

	const PAGE_SLUG              = 'estandar_communications';
	const CAP                   = 'manage_options';
	const OPTION_WEEKLY          = 'estandar_leads_weekly_report_recipients'; // key REAL del Weekly Report (destinatarios)
	const OPTION_DIGEST          = 'estandar_compliance_digest_settings';     // nueva: enabled/days[]/hour
	const OPTION_WEEKLY_SETTINGS = 'estandar_leads_weekly_report_settings';   // nueva: enabled/day/hour

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_sidebar_link' ) );
		add_action( 'admin_post_estandar_comms_identity',     array( __CLASS__, 'save_identity' ) );
		add_action( 'admin_post_estandar_comms_leads',        array( __CLASS__, 'save_leads' ) );
		add_action( 'admin_post_estandar_comms_compliance',   array( __CLASS__, 'save_compliance' ) );
		add_action( 'admin_post_estandar_comms_digest',       array( __CLASS__, 'save_digest' ) );
		add_action( 'admin_post_estandar_comms_weekly',       array( __CLASS__, 'save_weekly' ) );
		add_action( 'admin_post_estandar_comms_autoresponse', array( __CLASS__, 'save_autoresponse' ) );
	}

	/**
	 * La página se registra bajo el menú de Core (deja la fila en $submenu → la
	 * capability se resuelve y la URL es accesible). El enlace lateral se oculta
	 * solo con CSS (no se borra de $submenu), por lo que la página sigue accesible
	 * por URL y como tab interna de Core (global nav).
	 */
	public static function register_menu(): void {
		if ( ! defined( 'ESTANDAR_CORE_MENU_SLUG' ) ) {
			return;
		}
		if ( function_exists( 'estandar_register_navigation_page' ) ) {
			estandar_register_navigation_page(
				array(
					'slug'        => self::PAGE_SLUG,
					'capability'  => self::CAP,
					'callback'    => array( __CLASS__, 'render_page' ),
					// Sin esto, el submenú "Configuración compartida / Comunicaciones /
					// Estado del ecosistema" solo aparecía en el Dashboard — al entrar
					// directo a Comunicaciones el segundo nivel desaparecía.
					'header_slot' => static function (): void {
						if ( function_exists( 'estandar_core_render_subtabs' ) ) {
							estandar_core_render_subtabs( 'dashboard_tabs', 'comunicaciones' );
						}
					},
				)
			);
		}
	}

	/** Oculta solo el enlace del sidebar (sin tocar $submenu ni la capability). */
	public static function hide_sidebar_link(): void {
		echo '<style>#adminmenu a[href*="page=' . esc_attr( self::PAGE_SLUG ) . '"]{display:none!important;}</style>';
	}

	private static function leads_email_active(): bool { return class_exists( 'Estandar_Leads_Email' ); }
	private static function compliance_active(): bool { return class_exists( 'Estandar_Leads_Compliance' ); }

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'No autorizado.' ); }
		check_admin_referer( $action );
	}

	private static function back( string $tag ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=' . $tag ) );
		exit;
	}

	private static function badge( string $text ): string {
		return '<span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;">' . esc_html( $text ) . '</span>';
	}

	// ─── Save handlers (edit the real keys via each plugin's own save) ────────

	public static function save_identity(): void {
		self::guard( 'estandar_comms_identity' );
		$email = sanitize_email( (string) ( $_POST['sender_email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $_POST['sender_name'] ?? '' ) );
		$reply = sanitize_email( (string) ( $_POST['reply_to_email'] ?? '' ) );
		$logo  = esc_url_raw( (string) ( $_POST['logo_url'] ?? '' ) );

		if ( defined( 'ESTANDAR_SENDER_EMAIL' ) ) { update_option( ESTANDAR_SENDER_EMAIL, is_email( $email ) ? $email : '' ); }
		if ( defined( 'ESTANDAR_SENDER_NAME' ) )  { update_option( ESTANDAR_SENDER_NAME, $name ); }

		if ( self::leads_email_active() ) {
			$cur = (array) Estandar_Leads_Email::get_settings();
			$cur['reply_to_email'] = is_email( $reply ) ? $reply : '';
			// Logo: misma key que ya consume Estandar_Leads_Email::render() para el
			// branding del email — cero opciones nuevas, mismo principio que Reply-To.
			$cur['logo_url'] = $logo;
			Estandar_Leads_Email::save_settings( $cur );
		}
		self::back( 'identity' );
	}

	public static function save_leads(): void {
		self::guard( 'estandar_comms_leads' );
		if ( self::leads_email_active() ) {
			$cur = (array) Estandar_Leads_Email::get_settings();
			$cur['notification_cc_email'] = (string) ( $_POST['notification_cc_email'] ?? '' );
			Estandar_Leads_Email::save_settings( $cur );
		}
		self::back( 'leads' );
	}

	public static function save_compliance(): void {
		self::guard( 'estandar_comms_compliance' );
		if ( self::compliance_active() ) {
			$cur = (array) Estandar_Leads_Compliance::get_settings();
			$cur['enable_compliance_engine'] = ! empty( $_POST['enable_compliance_engine'] ) ? 1 : 0;
			$cur['supervisor_email']         = sanitize_email( (string) ( $_POST['supervisor_email'] ?? '' ) );
			$cur['compliance_cc_email']      = sanitize_email( (string) ( $_POST['compliance_cc_email'] ?? '' ) );
			Estandar_Leads_Compliance::save_settings( $cur );
		}
		self::back( 'compliance' );
	}

	/** Digest operadores → option nueva estandar_compliance_digest_settings (la lee Compliance). */
	public static function save_digest(): void {
		self::guard( 'estandar_comms_digest' );
		$days = array();
		foreach ( (array) ( $_POST['digest_days'] ?? array() ) as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) { $days[] = $d; }
		}
		update_option( self::OPTION_DIGEST, array(
			'enabled' => ! empty( $_POST['digest_enabled'] ) ? 1 : 0,
			'days'    => array_values( array_unique( $days ) ),
			'hour'    => max( 0, min( 23, (int) ( $_POST['digest_hour'] ?? 0 ) ) ),
		) );
		self::back( 'digest' );
	}

	/** Reportes cliente → destinatarios en la key REAL + agenda en option nueva. */
	public static function save_weekly(): void {
		self::guard( 'estandar_comms_weekly' );
		$out  = array();
		$rows = isset( $_POST['report'] ) && is_array( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : array();
		foreach ( $rows as $slug => $email ) {
			$slug  = sanitize_title( (string) $slug );
			$email = sanitize_email( (string) $email );
			if ( $slug !== '' && is_email( $email ) ) {
				$out[ $slug ] = $email; // estructura real consumida: [slug => email]
			}
		}
		update_option( self::OPTION_WEEKLY, $out );
		update_option( self::OPTION_WEEKLY_SETTINGS, array(
			'enabled' => ! empty( $_POST['weekly_enabled'] ) ? 1 : 0,
			'day'     => max( 1, min( 7, (int) ( $_POST['weekly_day'] ?? 5 ) ) ),
			'hour'    => max( 0, min( 23, (int) ( $_POST['weekly_hour'] ?? 0 ) ) ),
		) );
		self::back( 'weekly' );
	}

	public static function save_autoresponse(): void {
		self::guard( 'estandar_comms_autoresponse' );
		if ( self::leads_email_active() && method_exists( 'Estandar_Leads_Email', 'save_autoresponse_settings' ) ) {
			$cur = (array) Estandar_Leads_Email::get_autoresponse_settings( 'default' );
			$cur['enabled'] = ! empty( $_POST['ar_enabled'] ) ? 1 : 0;
			$cur['subject'] = sanitize_text_field( (string) ( $_POST['ar_subject'] ?? ( $cur['subject'] ?? '' ) ) );
			Estandar_Leads_Email::save_autoresponse_settings( $cur, 'default' );
		}
		self::back( 'autoresponse' );
	}

	// ─── Render ───────────────────────────────────────────────────────────────

	/**
	 * Se invoca SOLO como callback registrado vía estandar_register_navigation_page()
	 * (register_menu() arriba), que ya envuelve esta función en
	 * estandar_core_render_admin_shell() con el header_slot de subtabs. Envolverla
	 * OTRA VEZ aquí duplicaba el shell: estandar_core_render_admin_shell() detecta
	 * el shell anidado (estandar_core_output_has_admin_shell) y hace bail-out ANTES
	 * de llegar al header_slot — por eso el submenú nunca se pintaba.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'No autorizado.' ); }
		self::render_content();
	}

	public static function render_content(): void {
		$post = admin_url( 'admin-post.php' );
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
		}

		$sender  = defined( 'ESTANDAR_SENDER_EMAIL' ) ? (string) get_option( ESTANDAR_SENDER_EMAIL, '' ) : '';
		$name    = defined( 'ESTANDAR_SENDER_NAME' ) ? (string) get_option( ESTANDAR_SENDER_NAME, '' ) : '';
		$email_s = self::leads_email_active() ? (array) Estandar_Leads_Email::get_settings() : array();
		$comp_s  = self::compliance_active() ? (array) Estandar_Leads_Compliance::get_settings() : array();
		$ar_s    = ( self::leads_email_active() && method_exists( 'Estandar_Leads_Email', 'get_autoresponse_settings' ) )
			? (array) Estandar_Leads_Email::get_autoresponse_settings( 'default' ) : array();
		$weekly   = (array) get_option( self::OPTION_WEEKLY, array() );
		$projects = class_exists( 'Estandar_Project_Registry' ) ? Estandar_Project_Registry::get_all() : array();

		$digest = ( self::compliance_active() && method_exists( 'Estandar_Leads_Compliance', 'digest_settings' ) )
			? (array) Estandar_Leads_Compliance::digest_settings()
			: (array) get_option( self::OPTION_DIGEST, array( 'enabled' => 1, 'days' => array( 1, 4 ), 'hour' => 0 ) );
		$wset = ( self::compliance_active() && method_exists( 'Estandar_Leads_Compliance', 'weekly_settings' ) )
			? (array) Estandar_Leads_Compliance::weekly_settings()
			: (array) get_option( self::OPTION_WEEKLY_SETTINGS, array( 'enabled' => 1, 'day' => 5, 'hour' => 0 ) );
		$dow_names = array( 1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom' );

		$inactive = '<p class="description" style="color:#b91c1c;">estandar-leads no está activo: sección no editable.</p>';

		// CARDS_UNIFY_V1 — mismo patrón visual tarjeta→panel que Configuración
		// compartida (estandar-core.php, tab 'configuracion'): grilla de tarjetas
		// ✓/⚠ arriba, cada una hace toggle a su panel. Antes esta página era 7
		// secciones "1 · Identidad" ... "7 · Inventario" en fila plana sin forma
		// de saber de un vistazo qué faltaba configurar.
		// ORDEN LÓGICO DE IMPLEMENTACIÓN: 1 Identidad (base de todo email) →
		// 2 Notificaciones de Leads (a quién avisamos) → 3 Autorespuestas (qué
		// recibe el prospecto de inmediato) → 4 Compliance (escala si nadie
		// clasifica) → 5 Digest Operadores (depende de Compliance activo) →
		// 6 Reportes Cliente (reporte recurrente, menos urgente) → 7 Inventario
		// (resumen de solo lectura, siempre al final).
		$comm_health = array(
			array( 'key' => 'identidad',    'label' => 'Identidad',              'ok' => $sender !== '' && $name !== '',
				'desc' => 'Remitente, reply-to y logo de todos los emails que envía Leads. Base de la que dependen las demás tarjetas.' ),
			array( 'key' => 'leads',        'label' => 'Notificaciones de Leads', 'ok' => self::leads_email_active(),
				'desc' => 'CC y visibilidad del supervisor en el email que recibe el operador cuando entra un lead nuevo.' ),
			array( 'key' => 'autoresponse', 'label' => 'Autorespuestas',         'ok' => ! empty( $ar_s['enabled'] ),
				'desc' => 'Respuesta automática al prospecto apenas deja sus datos, antes de que el operador lo contacte.' ),
			array( 'key' => 'compliance',   'label' => 'Compliance',             'ok' => ! empty( $comp_s['enable_compliance_engine'] ),
				'desc' => 'Si un lead no se clasifica, recuerda al operador y después escala al supervisor.' ),
			array( 'key' => 'digest',       'label' => 'Digest Operadores',      'ok' => ! empty( $digest['enabled'] ),
				'desc' => 'Resumen diario a cada operador de sus leads pendientes de clasificar. Requiere Compliance activo.' ),
			array( 'key' => 'weekly',       'label' => 'Reportes Cliente',       'ok' => ! empty( $wset['enabled'] ),
				'desc' => 'Reporte semanal por proyecto con los leads de la semana, enviado al contacto del cliente.' ),
			array( 'key' => 'inventario',   'label' => 'Inventario',             'ok' => true,
				'desc' => 'Vista de solo lectura: qué correo se dispara, con qué trigger y a quién — para auditar todo el sistema.' ),
		);
		$comm_ok = array();
		foreach ( $comm_health as $ch ) {
			$comm_ok[ $ch['key'] ] = (bool) $ch['ok'];
		}

		echo '<style>.es-cfg-icon{font-size:28px;width:28px;height:28px;flex-shrink:0;opacity:.65;}@media(max-width:782px){.es-cfg-icon{font-size:24px;width:24px;height:24px;}}.es-cfg-card[data-es-cfg-card]:hover{filter:brightness(0.98);}.es-cfg-card[data-es-cfg-card]:focus-visible{outline:2px solid #2271b1;outline-offset:1px;}[data-es-cfg-panel].es-cfg-opening{animation:esCfgSlide .18s ease-out;}@keyframes esCfgSlide{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:none;}}</style>';

		echo '<p class="description" style="margin:0 0 8px;">Todas las tarjetas de esta página configuran el mismo plugin: <strong>Estandar Leads</strong> — el resto del ecosistema (Chatbot, Forms, WhatsApp) captura leads, pero es Leads quien decide cómo se notifican, escalan y reportan.</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px;">';
		foreach ( $comm_health as $ch ) {
			$ok = (bool) $ch['ok'];
			printf(
				'<div class="es-cfg-card" data-es-cfg-card="%1$s" role="button" tabindex="0" aria-expanded="%2$s" aria-controls="es-cfg-panel-%1$s" style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:%3$s;border:1px solid %4$s;border-radius:6px;cursor:pointer;user-select:none;">'
				. '<span class="dashicons %12$s es-cfg-icon" aria-hidden="true" style="color:%6$s;"></span>'
				. '<div style="flex:1;min-width:0;"><span style="font-size:12px;font-weight:600;color:%6$s;">%7$s</span>'
				. '<span style="font-size:11px;color:#6b7280;display:block;margin-top:1px;line-height:1.3;">%8$s</span>'
				. '<span style="font-size:11px;color:%9$s;display:block;margin-top:2px;">%10$s</span></div>'
				. '<span class="es-cfg-chev" aria-hidden="true" style="font-size:10px;color:%9$s;">%11$s</span>'
				. '</div>',
				esc_attr( $ch['key'] ),
				$ok ? 'false' : 'true',
				$ok ? '#f0fdf4' : '#fffbeb',
				$ok ? '#bbf7d0' : '#fde68a',
				$ok ? '✓' : '⚠',
				$ok ? '#166534' : '#92400e',
				esc_html( $ch['label'] ),
				esc_html( (string) ( $ch['desc'] ?? '' ) ),
				$ok ? '#15803d' : '#b45309',
				$ok ? 'Configurado · Click para editar' : 'Incompleta · Click para configurar',
				$ok ? '▸' : '▾',
				esc_attr( function_exists( 'estandar_diag_icon' ) ? estandar_diag_icon( $ch['key'] ) : 'dashicons-admin-generic' )
			);
		}
		echo '</div>';

		// ─── 1) Identidad ───────────────────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-identidad" data-es-cfg-panel="identidad" style="margin-bottom:12px;%s">', $comm_ok['identidad'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Identidad</div><div class="es-diag-section-body">';
		echo '<form method="post" action="' . esc_url( $post ) . '">';
		wp_nonce_field( 'estandar_comms_identity' );
		echo '<input type="hidden" name="action" value="estandar_comms_identity">';
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Remitente (email)</th><td><input type="email" class="regular-text" name="sender_email" value="%s" placeholder="noreply@dominio.cl"></td></tr>', esc_attr( $sender ) );
		printf( '<tr><th>Nombre remitente</th><td><input type="text" class="regular-text" name="sender_name" value="%s"></td></tr>', esc_attr( $name ) );
		if ( self::leads_email_active() ) {
			printf( '<tr><th>Reply-To</th><td><input type="email" class="regular-text" name="reply_to_email" value="%s"><br><span class="description">estandar_leads_email_settings[reply_to_email]</span></td></tr>', esc_attr( (string) ( $email_s['reply_to_email'] ?? '' ) ) );
			printf( '<tr><th>Logo</th><td><input type="url" class="regular-text" name="logo_url" value="%s" placeholder="https://.../logo.png"><br><span class="description">estandar_leads_email_settings[logo_url] — branding de las notificaciones por email.</span></td></tr>', esc_attr( (string) ( $email_s['logo_url'] ?? '' ) ) );
		} else {
			echo '<tr><th>Reply-To</th><td><input type="email" class="regular-text" disabled> ' . self::badge( 'Requiere Leads' ) . '</td></tr>';
			echo '<tr><th>Logo</th><td><input type="url" class="regular-text" disabled> ' . self::badge( 'Requiere Leads' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( 'Guardar identidad' );
		echo '</form></div></div>';

		// ─── 2) Notificaciones de Leads ─────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-leads" data-es-cfg-panel="leads" style="margin-bottom:12px;%s">', $comm_ok['leads'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Notificaciones de Leads</div><div class="es-diag-section-body">';
		if ( ! self::leads_email_active() ) {
			echo $inactive;
		} else {
			echo '<form method="post" action="' . esc_url( $post ) . '">';
			wp_nonce_field( 'estandar_comms_leads' );
			echo '<input type="hidden" name="action" value="estandar_comms_leads">';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>Lead nuevo</th><td><span class="description">Notificación activa en captura (gestionada por routing).</span></td></tr>';
			echo '<tr><th>Destinatario principal</th><td><span class="description">Operador asignado por routing/Registry (dinámico).</span></td></tr>';
			printf( '<tr><th>CC</th><td><input type="text" class="regular-text" name="notification_cc_email" value="%s" placeholder="correo1, correo2"><br><span class="description">estandar_leads_email_settings[notification_cc_email]</span></td></tr>', esc_attr( (string) ( $email_s['notification_cc_email'] ?? '' ) ) );
			printf( '<tr><th>Supervisor</th><td><code>%s</code> <span class="description">(se edita en Compliance — misma key)</span></td></tr>', esc_html( (string) ( $comp_s['supervisor_email'] ?? '—' ) ) );
			echo '</tbody></table>';
			submit_button( 'Guardar notificaciones' );
			echo '</form>';
		}
		echo '</div></div>';

		// ─── 3) Compliance ──────────────────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-compliance" data-es-cfg-panel="compliance" style="margin-bottom:12px;%s">', $comm_ok['compliance'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Compliance</div><div class="es-diag-section-body">';
		if ( ! self::compliance_active() ) {
			echo $inactive;
		} else {
			echo '<form method="post" action="' . esc_url( $post ) . '">';
			wp_nonce_field( 'estandar_comms_compliance' );
			echo '<input type="hidden" name="action" value="estandar_comms_compliance">';
			echo '<table class="form-table"><tbody>';
			printf( '<tr><th>Activar compliance</th><td><label><input type="checkbox" name="enable_compliance_engine" value="1" %s> Activo</label></td></tr>', checked( ! empty( $comp_s['enable_compliance_engine'] ), true, false ) );
			echo '<tr><th>Recordatorios</th><td><span class="description">48h · 96h (operador) → 7d · 14d (supervisor). Tiempos fijos del motor.</span></td></tr>';
			printf( '<tr><th>Supervisor</th><td><input type="email" class="regular-text" name="supervisor_email" value="%s"></td></tr>', esc_attr( (string) ( $comp_s['supervisor_email'] ?? '' ) ) );
			printf( '<tr><th>CC compliance</th><td><input type="email" class="regular-text" name="compliance_cc_email" value="%s"></td></tr>', esc_attr( (string) ( $comp_s['compliance_cc_email'] ?? '' ) ) );
			echo '</tbody></table>';
			submit_button( 'Guardar compliance' );
			echo '</form>';
		}
		echo '</div></div>';

		// ─── 4) Digest Operadores ───────────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-digest" data-es-cfg-panel="digest" style="margin-bottom:12px;%s">', $comm_ok['digest'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Digest Operadores</div><div class="es-diag-section-body">';
		echo '<p class="description" style="margin-top:0;">Configura el digest del operador (lo consume Compliance). Requiere "Activar compliance". Hora = despacho más temprano (0 = sin restricción).</p>';
		echo '<form method="post" action="' . esc_url( $post ) . '">';
		wp_nonce_field( 'estandar_comms_digest' );
		echo '<input type="hidden" name="action" value="estandar_comms_digest">';
		$d_days = array_map( 'intval', (array) ( $digest['days'] ?? array( 1, 4 ) ) );
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Activar digest</th><td><label><input type="checkbox" name="digest_enabled" value="1" %s> Activo</label></td></tr>', checked( ! empty( $digest['enabled'] ), true, false ) );
		echo '<tr><th>Días</th><td>';
		foreach ( $dow_names as $n => $lbl ) {
			printf( '<label style="margin-right:10px;"><input type="checkbox" name="digest_days[]" value="%d" %s> %s</label>', $n, checked( in_array( $n, $d_days, true ), true, false ), esc_html( $lbl ) );
		}
		echo '</td></tr>';
		printf( '<tr><th>Hora</th><td><input type="number" min="0" max="23" name="digest_hour" value="%d" style="width:70px;"> :00</td></tr>', (int) ( $digest['hour'] ?? 0 ) );
		echo '<tr><th>Destinatarios</th><td><span class="description">Operadores asignados (dinámico, por routing).</span></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Guardar digest' );
		echo '</form></div></div>';

		// ─── 5) Reportes Cliente ────────────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-weekly" data-es-cfg-panel="weekly" style="margin-bottom:12px;%s">', $comm_ok['weekly'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Reportes Cliente</div><div class="es-diag-section-body">';
		echo '<p class="description" style="margin-top:0;">Destinatarios en <code>estandar_leads_weekly_report_recipients</code>; agenda en <code>estandar_leads_weekly_report_settings</code> (ambas las consume el Weekly Report). Hora = despacho más temprano (0 = sin restricción).</p>';
		echo '<form method="post" action="' . esc_url( $post ) . '">';
		wp_nonce_field( 'estandar_comms_weekly' );
		echo '<input type="hidden" name="action" value="estandar_comms_weekly">';
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Activar reportes</th><td><label><input type="checkbox" name="weekly_enabled" value="1" %s> Activo</label></td></tr>', checked( ! empty( $wset['enabled'] ), true, false ) );
		echo '<tr><th>Día</th><td><select name="weekly_day">';
		foreach ( $dow_names as $n => $lbl ) {
			printf( '<option value="%d" %s>%s</option>', $n, selected( (int) ( $wset['day'] ?? 5 ), $n, false ), esc_html( $lbl ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th>Hora</th><td><input type="number" min="0" max="23" name="weekly_hour" value="%d" style="width:70px;"> :00</td></tr>', (int) ( $wset['hour'] ?? 0 ) );
		echo '</tbody></table>';
		echo '<table class="widefat striped"><thead><tr><th>Proyecto</th><th>Destinatario</th><th>Activo</th></tr></thead><tbody>';
		if ( empty( $projects ) ) {
			echo '<tr><td colspan="3"><em>Sin proyectos en el Registry.</em></td></tr>';
		} else {
			foreach ( $projects as $p ) {
				$slug = sanitize_title( (string) ( $p['project_id'] ?? '' ) );
				if ( $slug === '' ) { continue; }
				$label = (string) ( $p['label'] ?? $slug );
				$to    = sanitize_email( (string) ( $weekly[ $slug ] ?? '' ) );
				printf(
					'<tr><td><strong>%s</strong><br><code>%s</code></td>'
					. '<td><input type="email" name="report[%s]" value="%s" class="regular-text" placeholder="correo cliente"></td>'
					. '<td>%s</td></tr>',
					esc_html( $label ), esc_html( $slug ),
					esc_attr( $slug ), esc_attr( $to ),
					$to !== '' ? '✓' : '—'
				);
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">Activo por proyecto = con destinatario. Vaciar el campo desactiva ese proyecto.</p>';
		submit_button( 'Guardar reportes cliente' );
		echo '</form></div></div>';

		// ─── 6) Autorespuestas ──────────────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-autoresponse" data-es-cfg-panel="autoresponse" style="margin-bottom:12px;%s">', $comm_ok['autoresponse'] ? 'display:none;' : '' );
		echo '<div class="es-diag-section-header">Autorespuestas</div><div class="es-diag-section-body">';
		if ( ! self::leads_email_active() ) {
			echo $inactive;
		} else {
			echo '<form method="post" action="' . esc_url( $post ) . '">';
			wp_nonce_field( 'estandar_comms_autoresponse' );
			echo '<input type="hidden" name="action" value="estandar_comms_autoresponse">';
			echo '<table class="form-table"><tbody>';
			printf( '<tr><th>Activar</th><td><label><input type="checkbox" name="ar_enabled" value="1" %s> Activo (perfil default)</label></td></tr>', checked( ! empty( $ar_s['enabled'] ), true, false ) );
			printf( '<tr><th>Asunto</th><td><input type="text" class="regular-text" name="ar_subject" value="%s"></td></tr>', esc_attr( (string) ( $ar_s['subject'] ?? '' ) ) );
			echo '<tr><th>Plantilla</th><td><code>templates/email/autoresponse.php</code> <span class="description">(read-only)</span></td></tr>';
			echo '</tbody></table>';
			submit_button( 'Guardar autorespuestas' );
			echo '</form>';
		}
		echo '</div></div>';

		// ─── 7) Inventario (read-only) ──────────────────────────────────────
		printf( '<div class="es-diag-section" id="es-cfg-panel-inventario" data-es-cfg-panel="inventario" style="margin-bottom:12px;display:none;">' );
		echo '<div class="es-diag-section-header">Inventario <span class="description">(read-only)</span></div><div class="es-diag-section-body">';
		$inv = array(
			array( 'Lead Notification', 'estandar-leads', 'lead ingresado',      'operador (+CC)',        'lead-notification.php' ),
			array( 'Compliance Digest', 'estandar-leads', 'cron diario (Lun/Jue)','operador',             'compliance-bootstrap-digest.php' ),
			array( 'Escalation',        'estandar-leads', 'cron 7d / 14d',        'supervisor',            'compliance-escalation.php' ),
			array( 'Weekly Report',     'estandar-leads', 'cron viernes',         'destinatario proyecto', 'compliance-weekly-report.php' ),
			array( 'Recovery',          'estandar-leads', 'cron diario',          'operador',              '(integrado en digest)' ),
			array( 'Autoresponse',      'estandar-leads', 'lead nuevo',           'prospecto',             'autoresponse.php' ),
		);
		echo '<table class="widefat striped"><thead><tr><th>Correo</th><th>Plugin</th><th>Trigger</th><th>Destinatario</th><th>Plantilla</th></tr></thead><tbody>';
		foreach ( $inv as $r ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( $r[0] ), esc_html( $r[1] ), esc_html( $r[2] ), esc_html( $r[3] ), esc_html( $r[4] )
			);
		}
		echo '</tbody></table></div></div>';

		// ─── Toggle cards ↔ panels (mismo script que Configuración compartida) ─
		echo '<script>(function(){var cards=[].slice.call(document.querySelectorAll("[data-es-cfg-card]"));if(!cards.length){return;}function toggle(card){var key=card.getAttribute("data-es-cfg-card");var panel=document.querySelector(\'[data-es-cfg-panel="\'+key+\'"]\');if(!panel){return;}var isOpen=panel.style.display!=="none";if(isOpen){panel.style.display="none";panel.classList.remove("es-cfg-opening");}else{panel.style.display="";panel.classList.add("es-cfg-opening");panel.scrollIntoView({behavior:"smooth",block:"nearest"});}card.setAttribute("aria-expanded",isOpen?"false":"true");var chev=card.querySelector(".es-cfg-chev");if(chev){chev.textContent=isOpen?"▸":"▾";}}cards.forEach(function(card){card.addEventListener("click",function(){toggle(card);});card.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" "||e.key==="Spacebar"){e.preventDefault();toggle(card);}});});})();</script>';
	}
}

endif;
