<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormRenderer — render frontend mínimo de formularios nativos v2 (solo Forms).
 *
 * Corrige el defecto descubierto en la validación de "Contacto General"
 * (cliente1.example.com): los formularios migrados no tenían shortcode de render.
 * Alcance mínimo: formularios nativos v2 tipo 'lead' (NO surveys/postventa).
 *
 * Shortcode: [estandar_form_v2 id="<v2-form-id>"]
 *   - Render de campos (text, email, textarea, select, tel, number, date,
 *     radio, checkbox); tipos avanzados se degradan a text con nota.
 *   - Envío self-POST con nonce + honeypot anti-spam.
 *   - Persistencia vía Ingestion::ingest (estandar_capture_submissions) y
 *     confirmación desde success_message del formulario.
 *   - No realiza llamadas externas (reCAPTCHA real queda en backlog).
 */
class FormRenderer
{
	const SHORTCODE = 'estandar_form_v2';

	/** ¿Ya se inyectó el <link> del CSS global de forms en este request? */
	private static $css_injected = false;

	/** DEPENDENT_INLINE_V1: el form pidió el JS de dependent_select (se imprime inline). */
	private static $dependent_needed = false;

	/** FORM_ID_UNIQUE_V1: contador de instancias por request. El mismo form puede
	 * renderizarse 2+ veces (página + popup); sin id único, dos elementos con el
	 * mismo `estandar-form-v2-<id>` rompían la detección de reenvío (el popup
	 * quedaba con el form "fresco" mientras la página mostraba "ya enviaste"). */
	private static $render_seq = 0;

	/** FORM_ID_UNIQUE_V1: resultado del POST por form id, para que las instancias
	 * del MISMO form (página + popup) no procesen el mismo envío dos veces
	 * (duplicados de captura y "error" en el segundo render). */
	private static $post_results = array();

	/** COOKIE_DONE_V1: cookie de "este navegador ya envió" por form. Se marca al
	 * envíar con éxito (server-side, sobrevive sin JS) y la usan los popups para
	 * no renderizarse ni abrirse para quien ya dejó sus datos. */
	private static function mark_done_cookie( int $id ): void
	{
		if ( ! self::submitted_restriction_enabled() ) {
			return;
		}
		if ( headers_sent() || ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}
		$secure = (bool) ( is_ssl() || ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) );
		@setcookie( 'estandar_fv2_done_' . $id, '1', time() + self::submitted_cookie_ttl(), COOKIEPATH, COOKIE_DOMAIN, $secure, true );
	}

	/** COOKIE_DONE_V1: ¿este navegador ya envió algún form de captura v2? */
	public static function done_cookie_set(): bool
	{
		if ( ! self::submitted_restriction_enabled() ) {
			return false;
		}
		if ( empty( $_COOKIE ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( strpos( (string) $name, 'estandar_fv2_done_' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/** UNIQUE_PROJECT_V1 · Prefijos de la marca "ya dejó datos en este proyecto". */
	const PROJECT_DONE_LS  = 'es_fv2_done_project_';     // localStorage
	const PROJECT_DONE_CK  = 'estandar_fv2_done_project_'; // cookie
	const UNIQUE_PROJECT_DEFAULT_MSG = 'Ya nos dejaste tus datos en este proyecto. Si necesitas más información o tienes otra consulta, escríbenos y te ayudamos.';

	/** SUBMITTED_TTL_V1: opción global de popups (Capture → Popups → Configuración).
	 *  Define cuántos días dura la restricción "ya envió" (cookie/localStorage).
	 *  0 = sin restricción (no se marca ni se honra "ya envió"; los popups
	 *  siempre se muestran). Reemplaza el valor fijo de 1 año. */
	const POPUPS_SETTINGS_OPTION     = 'estandar_suite_capture_popups_settings';
	const SUBMITTED_TTL_DEFAULT_DAYS = 365;

	/** SUBMITTED_TTL_V1: días configurados (default 365). */
	public static function submitted_cookie_days(): int
	{
		$opt  = get_option( self::POPUPS_SETTINGS_OPTION, array() );
		$days = ( is_array( $opt ) && isset( $opt['cookie_days'] ) ) ? (int) $opt['cookie_days'] : self::SUBMITTED_TTL_DEFAULT_DAYS;
		return $days > 0 ? $days : 0;
	}

	/** SUBMITTED_TTL_V1: ¿la restricción "ya envió" está activa? */
	public static function submitted_restriction_enabled(): bool
	{
		return self::submitted_cookie_days() > 0;
	}

	/** SUBMITTED_TTL_V1: TTL de la cookie en segundos (0 si desactivada). */
	public static function submitted_cookie_ttl(): int
	{
		$secs = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		return self::submitted_cookie_days() * $secs;
	}

	/**
	 * UNIQUE_PROJECT_V1: alcance de la "respuesta única por proyecto".
	 * Devuelve el project slug cuando la opción está activa y aplica; '' si no.
	 * - Opt-in por form (config.unique_per_project, default off).
	 * - Postventa (incident) SIEMPRE exenta: se puede llenar cuantas veces se quiera.
	 * - Proyecto resuelto de `form_project` o, si está vacío, del routing v2
	 *   (`context_schema.routing.fixed_project.project_slug`, la forma habitual en
	 *   los forms migrados de demo). Los forms con proyecto dinámico (campo
	 *   routing / project_selector) no aplican (scope no conocible al renderizar).
	 */
	public static function unique_project_scope( array $config, string $form_type ): string
	{
		if ( $form_type === 'incident' ) {
			return ''; // postventa: reenvíos ilimitados
		}
		if ( ! empty( $config['always_refillable'] ) ) {
			return ''; // always-refillable: nunca participa del bloqueo por proyecto
		}
		if ( empty( $config['unique_per_project'] ) ) {
			return '';
		}
		$slug = Forms::form_project( $config );
		if ( $slug === '' ) {
			$ctx = (string) ( $config['context_schema'] ?? '' );
			if ( $ctx !== '' ) {
				$schema  = json_decode( $ctx, true );
				$routing = ( is_array( $schema ) && is_array( $schema['routing'] ?? null ) ) ? $schema['routing'] : array();
				if ( (string) ( $routing['mode'] ?? '' ) === 'fixed_project' && ! empty( $routing['project_slug'] ) ) {
					$slug = sanitize_title( remove_accents( (string) $routing['project_slug'] ) );
				}
			}
		}
		return $slug;
	}

	/** UNIQUE_PROJECT_V1: mensaje configurable (o por defecto). PURE. */
	public static function unique_project_message( array $config ): string
	{
		$msg = (string) ( $config['unique_project_message'] ?? '' );
		return $msg !== '' ? $msg : self::UNIQUE_PROJECT_DEFAULT_MSG;
	}

	/** SPAM_MESSAGE_V1: mensaje amigable ante un rechazo de anti-spam (PURE). */
	public static function spam_message( string $reason ): string
	{
		switch ( $reason ) {
			case 'submit_speed':
				return 'El formulario detectó un envío demasiado rápido. Espera unos segundos e inténtalo de nuevo.';
			case 'duplicate_request':
				return 'Tu envío ya está en proceso. Espera unos segundos e inténtalo de nuevo.';
			case 'rate_limited':
				return 'Has hecho demasiados envíos en poco tiempo. Espera unos minutos e inténtalo de nuevo.';
			default:
				return 'No se pudo enviar tu mensaje. Inténtalo de nuevo.';
		}
	}

	/** UNIQUE_PROJECT_V1: ¿este navegador ya dejó datos en el proyecto `$scope`? */
	public static function project_done_cookie_set( string $scope ): bool
	{
		if ( ! self::submitted_restriction_enabled() ) {
			return false;
		}
		if ( $scope === '' || empty( $_COOKIE ) ) {
			return false;
		}
		return ! empty( $_COOKIE[ self::PROJECT_DONE_CK . $scope ] );
	}

	/** UNIQUE_PROJECT_V1: marca la cookie server-side "ya dejó datos en proyecto". */
	public static function mark_done_project( string $scope ): void
	{
		if ( ! self::submitted_restriction_enabled() ) {
			return;
		}
		if ( $scope === '' || headers_sent() || ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}
		$secure = (bool) ( is_ssl() || ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) );
		@setcookie( self::PROJECT_DONE_CK . $scope, '1', time() + self::submitted_cookie_ttl(), COOKIEPATH, COOKIE_DOMAIN, $secure, true );
	}

	/** UNIQUE_PROJECT_V1: vista de bloqueo "ya dejaste tus datos en este proyecto". */
	public static function unique_project_view( array $config ): string
	{
		return '<div class="estandar-form-v2 estandar-form-v2-done es2-project-done-block" style="padding:22px;border:1px solid #d6f0d2;background:#f2fbf1;border-radius:10px;text-align:center;">'
			. '<p style="margin:0;padding:14px 16px;border-left:4px solid #f95b1f;background:#fff7f2;border-radius:6px;color:#1d2327;font-weight:600;">'
			. esc_html( self::unique_project_message( $config ) ) . '</p></div>';
	}

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		// Enqueue del CSS global de forms en el head cuando la página actual
		// contiene un shortcode de form. wp_enqueue_scripts imprime los
		// estilos en wp_head; la detección usa get_queried_object().
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_form_css' ), 5 );
		// Refuerzo: si el shortcode se renderizó vía AJAX/REST sin detección,
		// el footer lo imprime igualmente (idempotente).
		add_action( 'wp_footer', array( __CLASS__, 'print_form_css_footer' ), 99 );
		// DEPENDENT_INLINE_V1: imprime inline el JS de dependent_select (evita el
		// combine/caché de SG que servía un JS viejo y dejaba el select vacío).
		add_action( 'wp_footer', array( __CLASS__, 'print_dependent_script_footer' ), 99 );
	}

	/** Detecta un shortcode de form en el contenido actual y encola el CSS. */
	/** Versión del CSS de forms con cache-busting por mtime (evita CSS viejo). */
	private static function form_css_version(): string {
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';
		$f   = defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ? ESTANDAR_SUITE_CAPTURE_PATH . 'assets/css/estandar-form.css' : '';
		if ( $f && is_readable( $f ) ) { $ver .= '.' . filemtime( $f ); }
		return $ver;
	}

	public static function maybe_enqueue_form_css(): void
	{
		$needle = '[' . self::SHORTCODE;
		$post   = get_queried_object();
		if ( ! is_object( $post ) || ! isset( $post->post_content ) ) {
			$post = get_post();
		}
		if ( ! is_object( $post ) || ! isset( $post->post_content ) || strpos( (string) $post->post_content, $needle ) === false ) {
			return;
		}
		$ver  = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';
		$base = plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE );

		// CSS de forms + survey en wp_head (la detección del shortcode ya
		// funciona aquí). El survey emite su propio JS+config inline en el body.
		if ( function_exists( 'wp_enqueue_style' ) ) {
			if ( ! wp_style_is( 'estandar-form', 'enqueued' ) ) {
				wp_enqueue_style( 'estandar-form', $base . 'assets/css/estandar-form.css', array(), self::form_css_version() );
			}
			if ( ! wp_style_is( 'estandar-capture-survey', 'enqueued' ) ) {
				wp_enqueue_style( 'estandar-capture-survey', $base . 'assets/css/estandar-survey.css', array(), $ver );
			}
		}
	}

	/** Imprime el <link> del CSS global de forms si se usó un form en la página. */
	public static function print_form_css_footer(): void
	{
		if ( ! self::$css_injected ) {
			return;
		}
		self::$css_injected = false; // solo una vez (head o footer, el que corra)
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';
		echo '<link rel="stylesheet" id="estandar-form" href="' . esc_url( plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/css/estandar-form.css' ) . '?ver=' . rawurlencode( self::form_css_version() ) . '">' . "\n";
	}

	/**
	 * DEPENDENT_INLINE_V1: imprime inline el JS de dependent_select una vez si la
	 * página usó un form con ese campo. Evita el combine/minify/caché de SG.
	 */
	public static function print_dependent_script_footer(): void
	{
		if ( ! self::$dependent_needed || ! defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ) {
			return;
		}
		self::$dependent_needed = false;
		$path = ESTANDAR_SUITE_CAPTURE_PATH . 'assets/js/estandar-dependent-select.js';
		if ( ! is_file( $path ) ) {
			return;
		}
		$js = (string) file_get_contents( $path );
		$js = str_replace( '</script', '<\\/script', $js );
		echo "<script id=\"estandar-dependent-select\">\n" . $js . "\n</script>\n";
	}

	/** Marca el flag del CSS global y devuelve el <link> inline (una vez por request). */
	public static function form_css_tag(): string
	{
		if ( self::$css_injected || ! function_exists( 'plugin_dir_url' ) ) {
			return '';
		}
		self::$css_injected = true;
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';
		return '<link rel="stylesheet" id="estandar-form-css" href="' . esc_url( plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/css/estandar-form.css' ) . '?ver=' . rawurlencode( self::form_css_version() ) . '">';
	}

	/**
	 * FORM_STATUS_GATE_V1: ¿este form se publica en el frontend? Solo `active`.
	 * (draft/trashed/archived no se renderizan). Puro.
	 */
	public static function is_publishable( array $form ): bool
	{
		return (string) ( $form['status'] ?? 'active' ) === 'active';
	}

	/**
	 * FORM_VISIBILITY_V1: ¿el form debe mostrarse ahora? Fechas (Y-m-d) y
	 * dispositivo. Vacío = siempre visible (default; sin cambios en producción).
	 */
	public static function visibility_ok( array $config ): bool
	{
		$from = trim( (string) ( $config['vis_from'] ?? '' ) );
		$to   = trim( (string) ( $config['vis_to'] ?? '' ) );
		if ( $from !== '' || $to !== '' ) {
			$now = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			if ( $from !== '' && $now < substr( $from, 0, 10 ) ) {
				return false;
			}
			if ( $to !== '' && $now > substr( $to, 0, 10 ) ) {
				return false;
			}
		}
		$dev = strtolower( trim( (string) ( $config['vis_device'] ?? '' ) ) );
		if ( $dev === 'mobile' || $dev === 'desktop' ) {
			$is_mobile = function_exists( 'wp_is_mobile' ) ? (bool) wp_is_mobile() : false;
			if ( $dev === 'mobile' && ! $is_mobile ) {
				return false;
			}
			if ( $dev === 'desktop' && $is_mobile ) {
				return false;
			}
		}
		return true;
	}

	public static function render( $atts ): string
	{
		$atts = shortcode_atts( array( 'id' => 0, 'slug' => '', 'preset_city' => '' ), (array) $atts, self::SHORTCODE );
		$id   = (int) $atts['id'];
		$slug = sanitize_title( (string) $atts['slug'] );
		// preset_city por atributo, o auto desde la URL (?branch=temuco /
		// ?ciudad=temuco) para una misma página usada por varias sucursales
		// (p. ej. /contacto/?branch=temuco) sin crear páginas duplicadas.
		$preset_city = sanitize_key( (string) $atts['preset_city'] );
		if ( $preset_city === '' ) {
			$preset_city = sanitize_key( (string) ( $_GET['branch'] ?? $_GET['ciudad'] ?? '' ) );
		}
		$form = null;
		if ( $id > 0 ) {
			$form = Forms::get( $id );
		}
		if ( ! $form && $slug !== '' ) {
			$form = Forms::get_by_slug( $slug );
		}
		if ( ! $form ) {
			return '';
		}

		// FORM_STATUS_GATE_V1: un form que no está `active` (draft/trashed/…) NO
		// se publica en el frontend. Antes el shortcode renderizaba cualquier id
		// sin mirar el estado (una página mostraba un form "draft" que el admin
		// listaba como borrador). Para publicarlo, actívalo en Capture → Forms.
		if ( ! self::is_publishable( $form ) ) {
			return '';
		}

		$type = (string) $form['form_type'];
		// Motor único: survey/experiencias renderizan por etapas; y un form
		// incident/postventa con la capacidad step_by_step activa usa el MISMO
		// render conversacional (SurveyRenderer) sin cambiar de tipo.
		// Además, un form puede activar el modo POR FORM con
		// config.survey_config.step_by_step = 1 (no solo por la capability
		// global), de modo que cada formulario decide su propio flujo.
		$form_config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$form_config = is_array( $form_config ) ? $form_config : array();

		// FORM_VISIBILITY_V1: visibilidad del form por fechas y dispositivo.
		if ( ! self::visibility_ok( $form_config ) ) {
			$vis_msg = trim( (string) ( $form_config['vis_message'] ?? '' ) );
			return $vis_msg !== '' ? '<div class="estandar-form-hidden">' . esc_html( $vis_msg ) . '</div>' : '';
		}

		// GATE_PER_FORM_V1: proteger el form con el gate de operador sin shortcode.
		// (El gate re-renderiza vía FormRenderer::render_form, no por aquí: sin bucle.)
		if ( ! empty( $form_config['gate_enabled'] ) && class_exists( __NAMESPACE__ . '\\OperatorGate' ) ) {
			$lp = '\\Estandar\\Core\\LeadsPanel';
			if ( ! class_exists( $lp ) || ! $lp::authorized() ) {
				return OperatorGate::render( array(
					'form'       => (int) ( $form['id'] ?? 0 ),
					'title'      => (string) ( $form_config['gate_title'] ?? 'Acceso de operador' ),
					'mail_label' => (string) ( $form_config['gate_mail_label'] ?? (string) ( $form['name'] ?? 'Formulario' ) ),
					'methods'    => (string) ( $form_config['gate_method'] ?? 'inherit' ),
				) );
			}
		}

		// UNIQUE_PROJECT_V1 · Respuesta única por proyecto (opt-in por form).
		// Si este navegador ya dejó sus datos en OTRO form del mismo proyecto,
		// se muestra "ya dejaste tus datos" en vez del form (salvo postventa).
		// No bloquea el POST del propio form (el envío se procesa y muestra éxito).
		$up_scope = self::unique_project_scope( $form_config, $type );
		if ( $up_scope !== ''
			&& self::project_done_cookie_set( $up_scope )
			&& ! ( isset( $_POST[ self::SHORTCODE . '_id' ] ) && (int) $_POST[ self::SHORTCODE . '_id' ] === (int) $id ) ) {
			return self::unique_project_view( $form_config );
		}

		$fsc = $form_config['survey_config'] ?? array();
		if ( is_string( $fsc ) ) {
			$fsc = json_decode( $fsc, true );
		}
		$fsc = is_array( $fsc ) ? $fsc : array();
		$stepped = Forms::is_capability_enabled( $type, 'step_by_step' ) || ! empty( $fsc['step_by_step'] );
		if ( $stepped || ( $type !== 'lead' && $type !== 'incident' && ConversationEngine::is_conversational( $type ) ) ) {
			return SurveyRenderer::render_form( $form );
		}
		if ( $type !== 'lead' && $type !== 'incident' ) {
			return '';
		}
		return self::render_form( $form, $preset_city );
	}

	/**
	 * ¿Es el campo el nombre del contacto? Por slug exacto/prefix o por label
	 * de persona (evita capturar "nombre de la empresa" como nombre).
	 */
	/** NAME_DETECT_V1: ¿el campo es el NOMBRE de la persona? PURE. */
	public static function is_name_field( string $fname, array $f ): bool
	{
		$exact = array( 'nombre', 'name', 'full_name', 'nombre_propietario', 'nombre_apellido', 'nombre_completo' );
		if ( in_array( $fname, $exact, true ) ) {
			return true;
		}
		$label = function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( (string) ( $f['label'] ?? $fname ) ), 'UTF-8' )
			: strtolower( trim( (string) ( $f['label'] ?? $fname ) ) );
		if ( function_exists( 'remove_accents' ) ) {
			$label = strtolower( remove_accents( $label ) );
		}
		// No es nombre de persona: empresa/compañía/proyecto/comuna/ciudad/etc.
		// Se evalúa ANTES del prefijo de slug (p. ej. `nombre_empresa`).
		if ( preg_match( '/nombre\s+(de|del|de\s+la)\s+(la\s+|el\s+)?(empresa|compañia|compania|proyecto|comuna|ciudad|producto|negocio|marca|mascota|razon)|razon\s+social/i', $label ) ) {
			return false;
		}
		// Nombre de persona: etiquetas que piden el nombre.
		if ( $label === 'name' || strpos( $label, 'full name' ) === 0 || strpos( $label, 'your name' ) !== false ) {
			return true;
		}
		foreach ( array( 'nombre', 'llamas', 'te llamas', 'tu nombre' ) as $kw ) {
			if ( strpos( $label, $kw ) !== false ) {
				return true;
			}
		}
		// Slug con prefijo de nombre.
		if ( strpos( $fname, 'nombre' ) === 0 || strpos( $fname, 'full_name' ) === 0
			|| strpos( $fname, 'como_te_llama' ) === 0 || strpos( $fname, 'tu_nombre' ) === 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * Render de un form a partir de su payload (fila nativa o compat en memoria).
	 * Mantiene el id del form como clave de sesión/seguridad: en compat el id
	 * legacy preservado hace que las submissions linkeen por source_form_id
	 * igual que las entries históricas.
	 */
	public static function render_form( array $form, string $preset_city = '' ): string
	{
		$id = (int) $form['id'];
		if ( $id <= 0 ) {
			return '';
		}
		// FORM_ID_UNIQUE_V1: id único por instancia (página + popup comparten form id).
		self::$render_seq++;
		$el_id   = 'estandar-form-v2-' . $id . '-' . self::$render_seq;
		$wrap_id = 'esfv2-wrap-' . $id . '-' . self::$render_seq;
		// FORM_DOM_IDS_V1: sufijar ids de campo por instancia (evita ids duplicados
		// cuando el mismo form se renderiza dos veces: inline + popup).
		Fields::$instance = $id . '-' . self::$render_seq;
		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();
		// LABELS_IN_PLACEHOLDER_V1: usar el label como placeholder (form compacto).
		Fields::$compact_labels = ! empty( $config['labels_in_placeholder'] );
		$fields = Forms::renderable_fields( $config );
		// PROGRESSIVE_FORM_V1: pasos progresivos desde actions_json.flow.
		$flow_steps = Forms::form_flow_steps( $config );
		$step_map   = Forms::progressive_step_map( $fields, $flow_steps );
		$menu_enabled = Forms::repeat_menu_enabled( $config );
		$animation    = Forms::success_animation( $config );
		$up_scope     = self::unique_project_scope( $config, (string) ( $form['form_type'] ?? 'lead' ) );
		// ALWAYS_REFILLABLE_V1: este form SIEMPRE se puede volver a llenar
		// (sin estado "ya enviaste", sin bloqueo por proyecto, sin repeat_visibility).
		$always = ! empty( $config['always_refillable'] );

		// ── 1) Procesar el MOTIVO de recontacto (segunda respuesta) ─────────
		if ( isset( $_POST[ self::SHORTCODE . '_reason' ] )
			&& isset( $_POST[ self::SHORTCODE . '_submission' ] )
			&& (int) ( $_POST[ self::SHORTCODE . '_id' ] ?? 0 ) === $id
			&& wp_verify_nonce( (string) ( $_POST[ self::SHORTCODE . '_nonce' ] ?? '' ), self::SHORTCODE . '_' . $id )
		) {
			$sid  = (int) $_POST[ self::SHORTCODE . '_submission' ];
			$code = sanitize_key( (string) $_POST[ self::SHORTCODE . '_reason' ] );
			$note = sanitize_textarea_field( (string) ( $_POST[ self::SHORTCODE . '_reason_note' ] ?? '' ) );
			$rr   = Forms::record_repeat_reason( $sid, $code, $note, $config );
			$ok   = ! empty( $rr['ok'] );
			$msg  = $ok
				? 'Gracias, tu motivo fue registrado. Un responsable te atenderá pronto.'
				: ( $rr['error'] === 'ya_registrado' ? 'Tu motivo ya fue registrado.' : 'No se pudo registrar el motivo.' );
			return '<div class="estandar-form-v2 estandar-form-v2-done" style="padding:18px;border:1px solid '
				. ( $ok ? '#d6f0d2;background:#f2fbf1' : '#f0d6d6;background:#fbf1f1' ) . ';border-radius:8px;">'
				. esc_html( $msg ) . '</div>';
		}

		// ── 2) Procesar envío principal self-POST ─────────────────────────
		if ( isset( $_POST[ self::SHORTCODE . '_id' ] ) && (int) $_POST[ self::SHORTCODE . '_id' ] === $id ) {
			// FORM_ID_UNIQUE_V1: si este POST ya fue procesado en el request
			// (página + popup comparten el mismo form id), no re-procesar →
			// devolver la misma vista de éxito/error (evita capturas duplicadas
			// y el "error" del segundo render).
			if ( isset( self::$post_results[ $id ] ) ) {
				$prev = self::$post_results[ $id ];
				if ( ! empty( $prev['capture_id'] ) ) {
					return self::success_view( $id, (int) $prev['capture_id'], $config, $prev['is_repeat'], $menu_enabled, $animation, (string) ( $prev['redirect'] ?? '' ), $up_scope );
				}
				$config['_error'] = (string) ( $prev['error'] ?? '' );
			} else {
				$config['_form_type'] = (string) ( $form['form_type'] ?? 'lead' );
				$result = self::handle_submit( $id, $config, $fields, $_POST );
				self::$post_results[ $id ] = array(
					'capture_id' => (int) ( $result['id'] ?? 0 ),
					'is_repeat'  => ! empty( $_POST[ self::SHORTCODE . '_repeat' ] ),
					'error'      => (string) ( $result['error'] ?? '' ),
					'redirect'   => (string) ( $result['redirect'] ?? '' ),
				);
				if ( ! empty( $result['id'] ) ) {
					// COOKIE_DONE_V1: marcar este navegador como "ya envió" para
					// que los popups no se rendericen ni abran en próximas vistas.
					self::mark_done_cookie( $id );
					// UNIQUE_PROJECT_V1: marcar el proyecto como "ya dejó datos".
					if ( $up_scope !== '' ) {
						self::mark_done_project( $up_scope );
					}
					$is_repeat = ! empty( $_POST[ self::SHORTCODE . '_repeat' ] );
					return self::success_view( $id, (int) $result['id'], $config, $is_repeat, $menu_enabled, $animation, (string) ( $result['redirect'] ?? '' ), $up_scope );
				}
				// Error de validación/spam: mostrar el form con el error visible.
				$error_msg = (string) ( $result['error'] ?? '' );
				$config['_error'] = $error_msg;
			}
		}

		// F1 · repeat_visibility: política de ocultamiento tras respuesta
		// (calculada aquí; el HTML de ocultamiento se inyecta al final).
		$repeat_policy = Forms::repeat_visibility( $config );

		$submit = (string) ( $config['submit_label'] ?? 'Enviar' );
		if ( $submit === '' ) {
			$submit = 'Enviar';
		}

		// F1 · assets JS condicionales según campos (reCAPTCHA, RUT, dependent).
		self::enqueue_field_assets( $fields, $config );
		// PROGRESSIVE_FORM_V1: JS de navegación por pasos cuando aplica.
		if ( $step_map !== null && ! wp_script_is( 'estandar-progressive', 'enqueued' ) ) {
			wp_enqueue_script(
				'estandar-progressive',
				plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/js/estandar-progressive.js',
				array(),
				( defined( 'ESTANDAR_SUITE_CORE_VERSION' ) ? ESTANDAR_SUITE_CORE_VERSION : '13.0.0' ),
				true
			);
		}

		// F1 · enctype multipart cuando hay campos file (sin él el archivo
		// nunca viaja en el POST) o si es un form incident (repeater con fotos).
		$has_file = false;
		foreach ( $fields as $f ) {
			if ( strtolower( (string) ( $f['type'] ?? '' ) ) === 'file' ) {
				$has_file = true;
				break;
			}
		}
		$is_incident = ( (string) ( $form['form_type'] ?? 'lead' ) === 'incident' );

		$html  = self::form_css_tag(); // CSS global de forms (una vez por request).
		$html .= '<div id="' . $wrap_id . '">';
		$html .= '<form id="' . $el_id . '" class="estandar-form-v2" method="post" action="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '" novalidate'
			. ( $has_file || $is_incident ? ' enctype="multipart/form-data"' : '' );
		$html .= $repeat_policy['enabled'] ? ' data-es-rv="1"' : '';
		$html .= '>';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_id' ) . '" value="' . (int) $id . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_repeat' ) . '" value="">';
		// F1 · anti-spam: tiempo mínimo + token anti-duplicado + reCAPTCHA.
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_ts' ) . '" value="' . (int) time() . '">';
		$html .= '<input type="hidden" name="_estandar_token" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		// ORIGIN_V1: superficie de origen del lead para el correo/CRM.
		// `web_popup` cuando el form se renderiza dentro de un popup; `web_form`
		// por defecto. Los instant forms de Meta lo sobreescriben (instant_fb/ig).
		$origin_surface = 'web_form';
		if ( class_exists( 'Estandar\\Capture\\PopupsFrontend' ) && ! empty( \Estandar\Capture\PopupsFrontend::$rendering_body ) ) {
			$origin_surface = 'web_popup';
		}
		$html .= '<input type="hidden" name="_origin" value="' . esc_attr( $origin_surface ) . '">';
		if ( ! empty( $config['require_recaptcha'] ) && defined( 'ESTANDAR_FORMS_RECAPTCHA_SITE_KEY' ) && ESTANDAR_FORMS_RECAPTCHA_SITE_KEY ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_recaptcha' ) . '" value="" class="estandar-recaptcha-token">';
		}
		// Contexto de captura (CAPTURE_CONTEXT_V1): se completa por JS al enviar.
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ) as $ctx_key ) {
			$html .= '<input type="hidden" name="' . esc_attr( $ctx_key ) . '" value="">';
		}
		foreach ( array( 'session', 'time', 'landing', 'referrer' ) as $ctx_extra ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_' . $ctx_extra ) . '" value="">';
		}
		$html .= wp_nonce_field( self::SHORTCODE . '_' . $id, self::SHORTCODE . '_nonce', true, false );
		// Honeypot anti-spam (oculto al usuario).
		$html .= '<p style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
			. '<label>No llenar <input type="text" name="' . esc_attr( self::SHORTCODE . '_hp' ) . '" value="" tabindex="-1" autocomplete="off"></label></p>';

		// Error de validación visible (sin revelar detalle de spam).
		if ( isset( $config['_error'] ) && $config['_error'] !== '' ) {
			$html .= '<div class="estandar-form-v2-error" style="padding:12px;border:1px solid #f0d6d6;background:#fbf1f1;border-radius:8px;margin-bottom:12px;">'
				. esc_html( (string) $config['_error'] ) . '</div>';
		}

		if ( $step_map === null ) {
			foreach ( $fields as $f ) {
				$html .= self::render_field( $f );
			}
			// F3 · repeater de incidencias en forms incident/postventa.
			if ( $is_incident ) {
				$html .= IncidentRenderer::render_repeater( $config );
			}
			$html .= '<div style="margin-top:14px;clear:both;"><button type="submit" class="estandar-form-v2-submit" style="padding:10px 22px;cursor:pointer;">'
				. esc_html( $submit ) . '</button></div>';
		} else {
			$html .= self::progressive_steps_html( $config, $fields, $step_map, $flow_steps, $submit, $is_incident );
		}
		$html .= '</form>';
		// Validación inline (nombre/email/teléfono): advertencia visible antes
		// del envío. Usa los atributos data-es-validate/pattern del render.
		$html .= '<script>(function(){var f=document.getElementById("' . $el_id . '");if(!f){return;}'
			. 'var warn=function(input,msg){var w=input.parentElement.querySelector(".es2-inline-warn");if(!w){w=document.createElement("div");w.className="es2-inline-warn";w.style.cssText="color:#b32d2e;font-size:12px;margin-top:2px;";input.parentElement.appendChild(w);}w.textContent=msg;input.style.borderColor="#b32d2e";};'
			. 'var clear=function(input){var w=input.parentElement.querySelector(".es2-inline-warn");if(w){w.remove();}input.style.borderColor="";};'
			. 'var validators={email:/^[^@\s]+@[^@\s]+\.[^@\s]+$/,phone:/^[\d+\s\-()]{8,20}$/,name:/^[A-Za-z\u00C0-\u024F\s\.\'-]{2,120}$/};'
			. 'f.addEventListener("submit",function(e){var ok=true;'
			. 'var inputs=f.querySelectorAll("[data-es-validate]");'
			. 'for(var i=0;i<inputs.length;i++){var inp=inputs[i],kind=inp.getAttribute("data-es-validate");var v=inp.value.trim();'
			. 'if(!v){if(inp.required){warn(inp,"Este campo es obligatorio.");ok=false;}continue;}'
			. 'var re=validators[kind];if(re){if(!re.test(v)){warn(inp,kind==="email"?"Ingresa un correo válido.":(kind==="phone"?"Ingresa un teléfono válido (mínimo 8 dígitos).":"Ingresa un nombre válido (solo letras)."));ok=false;continue;}}'
			. 'clear(inp);}'
			. 'if(!ok){e.preventDefault();e.stopPropagation();}});'
			. 'f.addEventListener("input",function(ev){var t=ev.target;if(t){if(t.getAttribute){if(t.getAttribute("data-es-validate")){clear(t);}}}});'
			. '})();</script>';
		// Contexto de captura + detección de reenvío (client-side, sin dependencias).
		$html .= '<script>(function(){var f=document.getElementById("' . $el_id . '");if(!f){return;}'
			. 'var sid="",t0=0;try{sid=localStorage.getItem("es_fv2_sid_' . (int) $id . '")||"";t0=parseInt(localStorage.getItem("es_fv2_sess_' . (int) $id . '")||"0",10)||0;}catch(e){}'
			. 'if(!sid){try{if(window.crypto){if(crypto.randomUUID){sid=crypto.randomUUID();}else{sid=Date.now().toString(36)+Math.random().toString(36).slice(2);}}else{sid=Date.now().toString(36)+Math.random().toString(36).slice(2);}}catch(e){sid=Date.now().toString(36)+Math.random().toString(36).slice(2);}try{localStorage.setItem("es_fv2_sid_' . (int) $id . '",sid);}catch(e){}}'
			. 'if(!t0){t0=Date.now();try{localStorage.setItem("es_fv2_sess_' . (int) $id . '",String(t0));}catch(e){}}'
			. 'var g=function(n){try{return new URLSearchParams(location.search).get(n)||"";}catch(e){return "";}};'
			. 'var setV=function(n,v){var el=f.querySelector("input[name=\""+n+"\"]");if(el){el.value=v;}};'
			. 'var inp=f.querySelector("input[name=\'' . esc_js( self::SHORTCODE . '_repeat' ) . '\']");'
			. 'f.addEventListener("submit",function(){'
			. 'setV("utm_source",g("utm_source"));setV("utm_medium",g("utm_medium"));setV("utm_campaign",g("utm_campaign"));setV("utm_term",g("utm_term"));setV("utm_content",g("utm_content"));'
			. 'setV("gclid",g("gclid"));setV("gbraid",g("gbraid"));setV("wbraid",g("wbraid"));setV("fbclid",g("fbclid"));setV("msclkid",g("msclkid"));'
			. 'setV("' . esc_js( self::SHORTCODE . '_session' ) . '",sid);'
			. 'setV("' . esc_js( self::SHORTCODE . '_time' ) . '",String(Math.max(0,Math.round((Date.now()-t0)/1000))));'
			. 'setV("' . esc_js( self::SHORTCODE . '_landing' ) . '",location.href);'
			. 'setV("' . esc_js( self::SHORTCODE . '_referrer' ) . '",document.referrer||"");'
			. ( $always
				? 'if(inp){inp.value="";}' // always-refillable: cada envío es "primer envío"
				: 'if(inp){try{inp.value=localStorage.getItem("es_fv2_done_' . (int) $id . '")?"1":"";}catch(e){inp.value="";}}' )
			. '});})();</script>';

		// F1 · repeat_visibility: ocultar el form si respondió dentro de la ventana.
		// El timestamp se guarda en success_view (es_fv2_done_<id>_t) y el mismo
		// script de submit lo rellena en el hidden _repeat. (always-refillable: no).
		if ( ! $always && ! empty( $repeat_policy['enabled'] ) ) {
			$secs = (int) Forms::repeat_visibility_seconds( $repeat_policy );
			$html .= '<div class="estandar-form-v2-hidden" style="display:none;padding:18px;border:1px solid #d6f0d2;background:#f2fbf1;border-radius:8px;">'
				. esc_html( (string) ( $repeat_policy['message'] ?? 'Gracias, ya recibimos tu mensaje.' ) ) . '</div>'
					. '<script>(function(){try{var t=parseInt(localStorage.getItem("es_fv2_done_' . (int) $id . '_t")||"0",10);'
					. 'if(t){if(' . $secs . '>0){if((Date.now()-t)<' . ( $secs * 1000 ) . '){'
					. 'var f=document.getElementById("' . $el_id . '");var h=f?f.parentElement.querySelector(".estandar-form-v2-hidden"):null;'
					. 'if(f){f.style.display="none";}if(h){h.style.display="block";}}}}}}catch(e){}})();</script>';
		}

		// REPEAT_DONE_V1 · Estado "ya enviaste" en este navegador.
		// Sin esto, tras un envío desde el popup la página recarga y el popup
		// vuelve a abrir el MISMO form vacío (parece que no se envió → reenvíos
		// múltiples). Cuando este navegador ya envió (es_fv2_done_<id>), se
		// oculta el form y se muestra el mensaje de éxito + el menú de motivos
		// (si está activado), con el submission id recuperado de localStorage.
		// (always-refillable: NO se oculta el form → siempre se puede llenar.)
		if ( ! $always ) {
		$done_msg = (string) ( $config['success_message'] ?? '' );
		if ( $done_msg === '' ) {
			$done_msg = 'Gracias, tu mensaje fue enviado correctamente.';
		}
		$html .= '<div class="es2-already-sent" data-es-done="' . (int) $id . '" style="display:none;padding:22px;border:1px solid #d6f0d2;background:#fff;border-radius:10px;text-align:center;">'
			. '<p style="margin:0 0 4px;padding:14px 16px;border-left:4px solid #f95b1f;background:#fff7f2;border-radius:6px;color:#1d2327;font-weight:600;">'
			. esc_html( $done_msg ) . '</p></div>';
		if ( $menu_enabled ) {
			$html .= '<div class="es2-already-reason" data-es-reason="' . (int) $id . '" style="display:none;">' . self::reason_panel( $id, 0, $config ) . '</div>';
		}
		$html .= '<script>(function(){try{var f=document.getElementById("' . $el_id . '");if(!f){return;}'
			. 'if(!localStorage.getItem("es_fv2_done_' . (int) $id . '")){return;}'
			. 'var w=document.getElementById("' . $wrap_id . '");'
			. 'var done=w?w.querySelector(".es2-already-sent[data-es-done=\'' . (int) $id . '\']"):null;'
			. 'var reason=w?w.querySelector(".es2-already-reason[data-es-reason=\'' . (int) $id . '\']"):null;'
			. 'f.style.display="none";'
			. 'if(done){done.style.display="block";}'
			. 'if(reason){reason.style.display="block";'
			. 'var last=localStorage.getItem("es_fv2_last_' . (int) $id . '")||"";'
			. 'var sid=reason.querySelector("input[name=\'estandar_form_v2_submission\']");'
			. 'if(sid&&last){sid.value=last;}}'
			. '}catch(e){}})();</script>';
		}

		// UNIQUE_PROJECT_V1 · Bloque client-side "ya dejaste tus datos en este
		// proyecto": si este navegador ya envió otro form del mismo proyecto
		// (localStorage es_fv2_done_project_<scope> — p. ej. tras un envío por
		// popup/AJAX en la misma sesión), se oculta el form y se muestra el aviso.
		if ( $up_scope !== '' ) {
			$up_msg = esc_html( self::unique_project_message( $config ) );
			$html .= '<div class="es2-project-done" data-es-project-done="' . esc_attr( $up_scope ) . '" style="display:none;padding:22px;border:1px solid #d6f0d2;background:#f2fbf1;border-radius:10px;text-align:center;">'
				. '<p style="margin:0;padding:14px 16px;border-left:4px solid #f95b1f;background:#fff7f2;border-radius:6px;color:#1d2327;font-weight:600;">' . $up_msg . '</p></div>'
				. '<script>(function(){try{var f=document.getElementById("' . $el_id . '");if(!f){return;}'
				. 'if(!localStorage.getItem("' . esc_js( self::PROJECT_DONE_LS . $up_scope ) . '")){return;}'
				. 'var w=document.getElementById("' . $wrap_id . '");'
				. 'var d=w?w.querySelector(".es2-project-done[data-es-project-done=\'' . esc_js( $up_scope ) . '\']"):null;'
				. 'f.style.display="none";if(d){d.style.display="block";}'
				. '}catch(e){}})();</script>';
		}

		// Tras un POST con error, volver al form (no quedar arriba).
		if ( isset( $config['_error'] ) && $config['_error'] !== '' ) {
			$html .= self::scroll_script( $el_id );
		}

		// Contexto de rama/sucursal: preseleccionar la ciudad y marcar `_branch`
		// para que la captura distinga la landing (p. ej. Temuco).
		if ( $preset_city !== '' ) {
			$html .= '<script>(function(){try{'
			. 'var f=document.getElementById("' . $el_id . '");if(!f){return;}'
			. 'var sel=f.querySelector("select[name=ciudad]");'
				. 'if(sel){var v="' . esc_js( $preset_city ) . '";for(var i=0;i<sel.options.length;i++){'
				. 'if(sel.options[i].value===v){sel.value=v;break;}}}'
				. 'var b=document.createElement("input");b.type="hidden";b.name="estandar_branch";b.value="' . esc_js( $preset_city ) . '";'
				. 'f.appendChild(b);'
				. '}catch(e){}})();</script>';
		}

		$html .= '</div>'; // cierre wrapper FORM_ID_UNIQUE_V1

		return $html;
	}

	/** Vista de éxito: mensaje + animación GIF; en reenvíos, el menú de motivos. */
	private static function success_view( int $id, int $capture_id, array $config, bool $is_repeat, bool $menu_enabled, array $animation, string $redirect = '', string $up_scope = '' ): string
	{
		$msg = (string) ( $config['success_message'] ?? '' );
		if ( $msg === '' ) {
			$msg = 'Gracias, tu mensaje fue enviado correctamente.';
		}

		$html = '<div class="estandar-form-v2 estandar-form-v2-done" id="es-fv2-' . (int) $id . '" style="padding:22px;border:1px solid #d6f0d2;background:#fff;border-radius:10px;text-align:center;">';

		$show_gif = $animation['mode'] !== 'none' && ! ( $is_repeat && $menu_enabled );
		if ( $show_gif && $animation['url'] !== '' ) {
			$html .= '<img src="' . esc_url( $animation['url'] ) . '" alt="" aria-hidden="true" style="width:110px;height:auto;display:block;margin:0 auto 10px;">';
		}
		// El mensaje de éxito se DESTACA (REQUEST "destacarlo"): caja con fondo
		// tenue + borde izquierdo de marca, legible en el reenvío con menú.
		$html .= '<p style="margin:0;padding:14px 16px;border-left:4px solid #f95b1f;background:#fff7f2;border-radius:6px;color:#1d2327;font-weight:600;">'
			. esc_html( $msg ) . '</p>';
		$html .= '</div>';

		if ( $is_repeat && $menu_enabled ) {
			$html .= self::reason_panel( $id, $capture_id, $config );
		}

		// SUCCESS_REDIRECT_V1: redirigir tras completar el envío con éxito.
		// Muestra el mensaje ~1,2 s y luego navega a la URL configurada
		// (success_redirect). No aplica al reenvío con menú de motivos (el
		// usuario debe elegir el motivo). Vacío = se queda en la página.
		// ROUTING_URL_V1: la opción de routing seleccionada puede definir su
		// propia URL de redirección (no excluyente con el mail); tiene
		// PRIORIDAD sobre el success_redirect general del form.
		$sr_url = esc_url_raw( (string) ( $redirect !== '' ? $redirect : ( $config['success_redirect'] ?? '' ) ) );
		if ( ! ( $is_repeat && $menu_enabled ) && $sr_url !== '' ) {
			$html .= '<script>(function(){try{setTimeout(function(){window.location.href=' . wp_json_encode( $sr_url ) . ';},1200);}catch(e){}})();</script>';
		}

		// Deja constancia en este navegador: el próximo envío será recontacto,
		// y el timestamp alimenta la política repeat_visibility. COOKIE_DONE_V1:
		// también se marca una cookie (server-side los popups se leen en el
		// frontend PHP y no se renderizan para quien ya envió) — la cookie la
		// escribe aquí el JS porque el POST se procesa con los headers ya
		// enviados (cuerpo de página) y setcookie() no aplicaría.
		// SUBMITTED_TTL_V1: la duración es configurable (option global de popups).
		// Si está en 0 (sin restricción) no se marca nada.
		$submitted_days = self::submitted_cookie_days();
		$submitted_maxage = $submitted_days * 86400;
		$html .= '<script>(function(){try{'
			. 'localStorage.setItem("es_fv2_last_' . (int) $id . '",String(' . (int) $capture_id . '));'
			. ( $submitted_days > 0
				? 'localStorage.setItem("es_fv2_done_' . (int) $id . '","1");'
					. 'localStorage.setItem("es_fv2_done_' . (int) $id . '_t",String(Date.now()));'
					. 'try{document.cookie="estandar_fv2_done_' . (int) $id . '=1; path=/; max-age=' . (int) $submitted_maxage . '; SameSite=Lax;";}catch(e){}'
				: '' )
			// UNIQUE_PROJECT_V1: marcar el proyecto como "ya dejó datos" (localStorage
			// + cookie, para que cualquier otro form del mismo proyecto bloquee).
			. ( $up_scope !== '' && $submitted_days > 0
				? 'localStorage.setItem("' . esc_js( self::PROJECT_DONE_LS . $up_scope ) . '","1");'
					. 'try{document.cookie="' . esc_js( self::PROJECT_DONE_CK . $up_scope ) . '=1; path=/; max-age=' . (int) $submitted_maxage . '; SameSite=Lax;";}catch(e){}'
				: '' )
			. '}catch(e){}})();</script>';
		// Scroll hasta la confirmación (evita que el reload deje la vista arriba).
		$html .= self::scroll_script( 'es-fv2-' . (int) $id );
		return $html;
	}

	/** Script que hace scroll suave hasta un elemento tras un POST (éxito/error). */
	private static function scroll_script( string $el_id ): string
	{
		return '<script>(function(){var el=document.getElementById(' . wp_json_encode( $el_id ) . ');if(!el){return;}'
			. 'if(location.hash!==' . wp_json_encode( '#' . $el_id ) . '){try{history.replaceState(null,"",' . wp_json_encode( '#' . $el_id ) . ');}catch(e){}}'
			. 'el.scrollIntoView({behavior:"smooth",block:"start"});})();</script>';
	}

	/** Panel de motivos de recontacto tras un envío aceptado (REPEAT_MENU_V1). */
	private static function reason_panel( int $id, int $capture_id, array $config ): string
	{
		$reasons = Forms::repeat_reasons( $config );
		$title   = (string) ( $config['repeat_title'] ?? '' );
		if ( $title === '' ) {
			$title = 'Vimos que ya habías enviado tus datos antes. ¿Los estás volviendo a enviar porque…?';
		}
		$note_label   = (string) ( $config['repeat_note_label'] ?? 'Detalle (opcional)' );
		$submit_label = (string) ( $config['repeat_submit_label'] ?? 'Enviar motivo' );
		$html  = '<div class="estandar-form-v2-reason" style="margin-top:18px;padding:16px;border:1px solid #c3c4c7;border-radius:10px;background:#f9f9f6;">';
		$html .= '<h4 style="margin:0 0 8px;">' . esc_html( $title ) . '</h4>';
		$html .= '<form method="post" action="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_id' ) . '" value="' . (int) $id . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_submission' ) . '" value="' . (int) $capture_id . '">';
		$html .= wp_nonce_field( self::SHORTCODE . '_' . $id, self::SHORTCODE . '_nonce', true, false );
		foreach ( $reasons as $code => $label ) {
			$html .= '<label style="display:block;margin:4px 0;"><input type="radio" name="' . esc_attr( self::SHORTCODE . '_reason' ) . '" value="' . esc_attr( $code ) . '"> '
				. esc_html( $label ) . '</label>';
		}
		$html .= '<p style="margin:8px 0 0;"><label>' . esc_html( $note_label ) . '<br><textarea name="' . esc_attr( self::SHORTCODE . '_reason_note' ) . '" rows="2" style="width:100%;"></textarea></label></p>';
		$html .= '<p style="margin:8px 0 0;"><button type="submit" class="estandar-form-v2-reason-submit" style="display:inline-block;min-height:44px;padding:10px 22px;font-size:15px;font-weight:600;color:#ffffff;background:#f95b1f;border:none;border-radius:8px;cursor:pointer;">' . esc_html( $submit_label ) . '</button></p>';
		$html .= '</form>';
		$html .= '</div>';
		return $html;
	}

	/** Encola assets JS según los campos del form (por handle, sin repetir). */
	private static function enqueue_field_assets( array $fields, array $config ): void
	{
		if ( ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'wp_script_is' ) ) {
			return;
		}

		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';

		// CSS global de forms v2 (tipografía + placeholders mobile-first).
		// Se encola con cada render del form; wp_enqueue_style deduplica por handle.
		if ( function_exists( 'wp_enqueue_style' ) && ! wp_style_is( 'estandar-form', 'enqueued' ) ) {
			wp_enqueue_style( 'estandar-form', plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/css/estandar-form.css', array(), self::form_css_version() );
		}

		$types = array();
		$has_sw = false;
		foreach ( $fields as $f ) {
			$types[] = strtolower( (string) ( $f['type'] ?? '' ) );
			if ( is_array( $f['show_when'] ?? null ) ) {
				$has_sw = true;
			}
		}
		$has_rut  = in_array( 'rut', $types, true ) || in_array( 'phone', $types, true ) || in_array( 'tel', $types, true );
		$has_dep  = in_array( 'dependent_select', $types, true );
		$has_rec  = ! empty( $config['require_recaptcha'] ) && defined( 'ESTANDAR_FORMS_RECAPTCHA_SITE_KEY' ) && ESTANDAR_FORMS_RECAPTCHA_SITE_KEY;

		if ( $has_sw && ! wp_script_is( 'estandar-conditional', 'enqueued' ) ) {
			wp_enqueue_script( 'estandar-conditional', plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/js/estandar-conditional.js', array(), $ver, true );
		}

		if ( $has_rut && ! wp_script_is( 'estandar-rut', 'enqueued' ) ) {
			wp_enqueue_script( 'estandar-rut', plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/js/estandar-rut.js', array(), $ver, true );
		}
		if ( $has_dep ) {
			// DEPENDENT_INLINE_V1: no encolar (SG lo combina/cachea 1 año); se
			// imprime inline en el footer vía print_dependent_script_footer().
			self::$dependent_needed = true;
		}
		if ( $has_rec && ! wp_script_is( 'estandar-recaptcha', 'enqueued' ) ) {
			wp_enqueue_script( 'google-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( (string) ESTANDAR_FORMS_RECAPTCHA_SITE_KEY ), array(), null, true );
			wp_enqueue_script( 'estandar-recaptcha', plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/js/estandar-recaptcha.js', array( 'google-recaptcha-v3' ), $ver, true );
			wp_localize_script( 'estandar-recaptcha', 'EstandarFormsRecaptcha', array(
				'siteKey' => (string) ESTANDAR_FORMS_RECAPTCHA_SITE_KEY,
				'action'  => (string) apply_filters( 'estandar_forms_recaptcha_action', 'estandar_form_submit' ),
			) );
		}
	}

	/** Render de un campo vía Fields (render nativo de todos los tipos). */
	private static function render_field( array $f ): string
	{
		return Fields::render( $f );
	}

	/**
	 * PROGRESSIVE_FORM_V1 — HTML de los pasos progresivos (wrappers + navegación).
	 * Cada paso envuelve sus campos; el paso inicial es visible y el resto
	 * oculto hasta completar el anterior (JS estandar-progressive.js). El botón
	 * de envío vive en el último paso.
	 */
	private static function progressive_steps_html( array $config, array $fields, array $step_map, array $steps, string $submit, bool $is_incident ): string
	{
		$html = '';
		$exp  = json_decode( (string) ( $config['experience_config_json'] ?? '{}' ), true );
		$exp  = is_array( $exp ) ? $exp : array();

		// Labels del progress bar (experience_config.progress_labels o paso).
		$labels = array();
		$pl = $exp['progress_labels'] ?? array();
		if ( is_array( $pl ) && count( $pl ) >= count( $steps ) ) {
			$labels = array_values( array_map( static function ( $l ) {
				return sanitize_text_field( (string) $l );
			}, array_slice( $pl, 0, count( $steps ) ) ) );
		} else {
			foreach ( $steps as $st ) {
				$labels[] = $st['label'] !== '' ? $st['label'] : $st['id'];
			}
		}
		if ( ! empty( $exp['show_progress'] ) ) {
			$html .= '<div data-es-progress style="display:flex;gap:8px;margin:0 0 16px;font-size:13px;color:#5f6368;flex-wrap:wrap;">';
			foreach ( $labels as $i => $lab ) {
				$html .= '<span data-es-progress-item="' . (int) $i . '" style="padding:4px 12px;border-radius:999px;background:#f1f3f4;border:1px solid #e0e0e0;">' . esc_html( $lab ) . '</span>';
			}
			$html .= '</div>';
		}

		$last = count( $steps ) - 1;
		foreach ( $steps as $i => $st ) {
			$html .= '<div class="es2-step" data-es-step="' . esc_attr( $st['id'] ) . '"'
				. ( ! empty( $st['initial'] ) ? ' data-es-initial="1"' : ' style="display:none;"' ) . '>';
			foreach ( $fields as $f ) {
				if ( ( $step_map[ sanitize_key( (string) ( $f['name'] ?? '' ) ) ] ?? -1 ) === $i ) {
					$html .= self::render_field( $f );
				}
			}
			if ( $i < $last ) {
				$html .= '<div style="margin-top:14px;clear:both;"><button type="button" class="es2-step-next" data-es-step-next="' . esc_attr( $steps[ $i + 1 ]['id'] ) . '" style="padding:10px 22px;cursor:pointer;">'
					. esc_html( $st['next_label'] !== '' ? $st['next_label'] : 'Continuar' ) . '</button></div>';
			} else {
				if ( $is_incident ) {
					$html .= IncidentRenderer::render_repeater( $config );
				}
				$html .= '<div style="margin-top:14px;clear:both;"><button type="submit" class="estandar-form-v2-submit" style="padding:10px 22px;cursor:pointer;">'
					. esc_html( $submit ) . '</button></div>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * Procesa el envío: nonce → honeypot → spam → validación → persistencia
	 * → acciones (email/webhook) + autorespuesta.
	 * @return array{id?:int, error?:string} id de capture si se aceptó.
	 */
	private static function handle_submit( int $form_id, array $config, array $fields, array $post ): array
	{
		if ( ! isset( $post[ self::SHORTCODE . '_nonce' ] ) || ! wp_verify_nonce( (string) $post[ self::SHORTCODE . '_nonce' ], self::SHORTCODE . '_' . $form_id ) ) {
			return array( 'error' => 'Sesión expirada, recarga la página e inténtalo de nuevo.' );
		}
		// Honeypot: si el campo oculto viene lleno, es spam → se descarta en silencio.
		$hp = (string) ( $post[ self::SHORTCODE . '_hp' ] ?? '' );
		if ( $hp !== '' ) {
			return array( 'error' => '' ); // descarte silencioso.
		}

		// F1 · cadena anti-spam: tiempo mínimo, token anti-duplicado, rate-limit.
		// Los forms ALWAYS_REFILLABLE_V1 saltan el rate-limit (aceptan reenvíos
		// ilimitados). El tope/ventana del rate-limit es configurable por form
		// (spam_rate_max / spam_rate_window; vacío = fábrica 5 / 600 s).
		// Los rechazos humanos muestran un mensaje amigable (no un reload
		// silencioso); el honeypot sí se descarta en silencio.
		$spam = Spam::check(
			$form_id,
			(string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			$post,
			! empty( $config['always_refillable'] ),
			absint( (int) ( $config['spam_rate_max'] ?? 0 ) ),
			absint( (int) ( $config['spam_rate_window'] ?? 0 ) )
		);
		if ( ! $spam['ok'] ) {
			return array( 'error' => self::spam_message( (string) $spam['reason'] ) );
		}
		// F1 · reCAPTCHA v3 (solo si el form lo exige y hay config).
		$recaptcha = Spam::recaptcha_verify( $config, $post );
		if ( ! $recaptcha['ok'] ) {
			return array( 'error' => 'No se pudo verificar la seguridad. Recarga la página e inténtalo de nuevo.' );
		}

		// F1 · validación server-side por tipo de campo (paridad con legacy).
		$posted = array();
		$contact = array( 'name' => '', 'email' => '', 'phone' => '' );
		$message = '';
		$validation_errors = array();
		foreach ( $fields as $f ) {
			$fname = sanitize_key( (string) ( $f['name'] ?? '' ) );
			$ftype = strtolower( (string) ( $f['type'] ?? 'text' ) );
			if ( $fname === '' ) {
				continue;
			}
			// MATRIX_ARRAY_V1 (2026-10-02): un campo `matrix` postea un array
			// (`name[fila]=valor`). Antes el cast a string lo convertía en
			// literal "Array" → fallaba la validación y guardaba basura. Se lee
			// el array crudo, se sanea por elemento y se serializa a JSON.
			if ( $ftype === 'matrix' && isset( $post[ $fname ] ) && is_array( $post[ $fname ] ) ) {
				$matrix = array();
				foreach ( (array) $post[ $fname ] as $mk => $mv ) {
					$matrix[ sanitize_key( (string) $mk ) ] = is_scalar( $mv ) ? sanitize_text_field( (string) $mv ) : '';
				}
				$val = wp_json_encode( $matrix );
			} else {
				$val = ( $ftype === 'file' )
					? ( isset( $_FILES[ $fname ] ) ? (array) $_FILES[ $fname ] : array() )
					: sanitize_text_field( (string) ( $post[ $fname ] ?? '' ) );
			}

			$errors = Fields::validate( $f, $val );
			if ( $errors ) {
				$validation_errors[] = (string) $errors[0];
			}

			if ( $ftype === 'file' ) {
				// LEAD_PHOTO_V1: en un form LEAD el campo type=file SÍ sube el
				// archivo (validación + mover a uploads/estandar-capture/survey/)
				// y el campo guarda la URL final (no solo el nombre). Sin archivo
				// cargado (opcional o error) el campo queda vacío.
				$file = is_array( $val ) ? $val : array();
				$has  = isset( $file['tmp_name'] ) && (string) $file['tmp_name'] !== '' && (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK;
				if ( $has ) {
					$up = Forms::store_uploaded_file( $file );
					$posted[ $fname ] = $up['url'] !== '' ? $up['url'] : '';
					if ( $up['url'] === '' && $up['error'] !== '' ) {
						$validation_errors[] = $up['error'];
					}
				} else {
					$posted[ $fname ] = '';
				}
			} elseif ( $ftype === 'matrix' && is_array( $val ) ) {
				$posted[ $fname ] = wp_json_encode( $val );
			} else {
				$posted[ $fname ] = is_scalar( $val ) ? (string) $val : '';
			}

			if ( $ftype === 'email' && $contact['email'] === '' && $posted[ $fname ] !== '' ) {
				$contact['email'] = $posted[ $fname ];
			} elseif ( in_array( $ftype, array( 'tel', 'phone' ), true ) && $contact['phone'] === '' ) {
				$contact['phone'] = $posted[ $fname ];
			} elseif ( $contact['name'] === '' && self::is_name_field( $fname, $f ) ) {
				$contact['name'] = $posted[ $fname ];
			} elseif ( $ftype === 'textarea' && $message === '' ) {
				$message = $posted[ $fname ];
			}
		}

		if ( $validation_errors ) {
			return array( 'error' => 'Revisa los campos marcados: ' . implode( ' ', array_slice( $validation_errors, 0, 3 ) ) );
		}

		// Proyecto del form (routing v2 desde context_schema): fixed_project o
		// project_selector. Sin esto, los forms migrados de legacy pierden el
		// proyecto y el puente leads no puede rutear el lead a su operador
		// (routing_unresolved → sin notificación). Se inyecta en $posted para
		// que LeadsBridge lo proyecte a es_leads.
		$ctx_schema = (string) ( $config['context_schema'] ?? '' );
		if ( $ctx_schema !== '' ) {
			$schema  = json_decode( $ctx_schema, true );
			$routing = ( is_array( $schema ) && is_array( $schema['routing'] ?? null ) ) ? $schema['routing'] : array();
			$mode    = sanitize_key( (string) ( $routing['mode'] ?? '' ) );
			if ( $mode === 'fixed_project' && ! empty( $routing['project_slug'] ) ) {
				$posted['project_slug'] = sanitize_title( remove_accents( (string) $routing['project_slug'] ) );
				$posted['project_area'] = sanitize_text_field( (string) ( $routing['project_area'] ?? ( $routing['project_name'] ?? '' ) ) );
			} elseif ( $mode === 'project_selector' ) {
				$sel = sanitize_title( remove_accents( (string) ( $post['__project_routing'] ?? '' ) ) );
				if ( $sel !== '' ) {
					$posted['project_slug'] = $sel;
					$posted['project_area'] = sanitize_text_field( (string) ( $post['project_area'] ?? '' ) );
				}
			}
		}

		// F5 · Proyecto fijo del form + modo embudo/simple (FUNNEL_V1): se inyecta
		// en la captura para que LeadsBridge proyecte el project_slug y el modo
		// fluya al correo del operador (simple → no se envía el enriquecido).
		$fp = Forms::form_project( $config );
		if ( $fp !== '' ) {
			$posted['project_slug'] = $fp;
			if ( empty( $posted['project_area'] ) ) {
				$posted['project_area'] = sanitize_text_field( (string) ( $config['form_project_area'] ?? $fp ) );
			}
		}
		$posted['_form_funnel_mode'] = Forms::funnel_mode( $config );

		// Rama/sucursal (landing diferenciada, p. ej. Temuco): se guarda con el
		// lead para que el operador sepa de qué anuncio/landing llegó.
		$posted['_branch'] = sanitize_key( (string) ( $post['estandar_branch'] ?? $post['_branch'] ?? '' ) );

		// F5b · ROUTING_FIELD_V1: un campo de tipo 'routing' resuelve, según la
		// opción seleccionada, el proyecto, los correos extra de la notificación
		// y si el lead entra al pipeline (funnel) o es independiente (simple).
		// ROUTING_URL_V1: la opción también puede llevar una URL de redirección
		// (`url`), no excluyente con los correos (mail + redirect a la vez).
		$routing_redirect = '';
		foreach ( $fields as $rf ) {
			if ( strtolower( (string) ( $rf['type'] ?? '' ) ) !== 'routing' ) {
				continue;
			}
			$rname = sanitize_key( (string) ( $rf['name'] ?? '' ) );
			if ( $rname === '' ) {
				continue;
			}
			$sel = (string) ( $posted[ $rname ] ?? '' );
			if ( $sel === '' ) {
				continue;
			}
			$rsel = Forms::routing_option( $rf, $sel );
			if ( ! empty( $rsel ) ) {
				if ( $rsel['project_slug'] !== '' ) {
					$posted['project_slug'] = $rsel['project_slug'];
					if ( empty( $posted['project_area'] ) ) {
						$posted['project_area'] = $rsel['project_area'];
					}
				}
				$posted['_routing_emails'] = $rsel['emails'];
				$posted['_form_funnel_mode'] = $rsel['pipeline'] ? 'funnel' : 'simple';
				if ( $rsel['redirect'] !== '' ) {
					$routing_redirect = $rsel['redirect'];
				}
			}
			break; // un solo campo routing por form
		}

		// REGION_ROUTING_V1: routing por lista de región (campo ciudad, select o
		// texto libre). Si el valor coincide con una localidad de la lista → zona
		// → proyecto (operador) + redirect a la landing de la zona. Precede sobre
		// el fixed_project del form (más específico: la ciudad decide la sucursal).
		$region_routing = Forms::region_routing_resolve( $config, $posted );
		if ( ! empty( $region_routing ) ) {
			if ( $region_routing['project_slug'] !== '' ) {
				$posted['project_slug'] = $region_routing['project_slug'];
				if ( empty( $posted['project_area'] ) ) {
					$posted['project_area'] = $region_routing['project_area'];
				}
			}
			$posted['_region_zone'] = $region_routing['zone'];
			$posted['_form_funnel_mode'] = $region_routing['pipeline'] ? 'funnel' : 'simple';
			if ( $region_routing['redirect'] !== '' ) {
				$routing_redirect = $region_routing['redirect'];
			}
		}

		// F3 · incident/postventa: parsear repeater y persistir transaccional.
		$is_incident = ( (string) ( $config['_form_type'] ?? '' ) === 'incident' );
		$incident_result = null;
		if ( $is_incident ) {
			$parsed = Repeater::parse( $post, $_FILES );
			if ( ! empty( $parsed['errors'] ) ) {
				return array( 'error' => 'Revisa los problemas reportados: cada uno requiere ubicación y descripción.' );
			}
			$incident_result = IncidentEngine::store_all(
				array(
					'form_id'      => $form_id,
					'form_type'    => 'incident',
					'form_context' => sanitize_key( (string) ( $config['form_context'] ?? 'generic' ) ),
					'request_type' => sanitize_key( (string) ( $config['request_type'] ?? '' ) ),
					'status'       => 'open',
					'owner'        => $contact,
					'context'      => $posted,
					'source_ip'    => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
					'source_ua'    => sanitize_textarea_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				),
				$parsed['incidents'],
				$parsed['incident_files']
			);
			if ( is_string( $incident_result ) ) {
				return array( 'error' => 'No se pudo registrar la solicitud. Inténtalo de nuevo.' );
			}
			$posted['request_ref'] = (string) $incident_result['request_ref'];
			$posted['incidents']   = wp_json_encode( $parsed['incidents'] );
		}

		// Contexto de captura (CAPTURE_CONTEXT_V1): atribución, click-ids,
		// sesión, tiempo en página, landing y referrer (completados por JS).
		$attribution = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ) as $ctx_key ) {
			$ctx_val = sanitize_text_field( (string) ( $post[ $ctx_key ] ?? '' ) );
			if ( $ctx_val !== '' ) {
				$attribution[ $ctx_key ] = $ctx_val;
			}
		}
		$attribution['landing_url'] = esc_url_raw( (string) ( $post[ self::SHORTCODE . '_landing' ] ?? '' ) );
		$attribution['referrer']    = esc_url_raw( (string) ( $post[ self::SHORTCODE . '_referrer' ] ?? '' ) );
		// Fallback servidor: si el JS no completó el landing (o hay robots/noscript),
		// la página del form es el HTTP_REFERER del envío (siempre que sea del sitio).
		if ( $attribution['landing_url'] === '' && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$attribution['landing_url'] = esc_url_raw( (string) $_SERVER['HTTP_REFERER'] );
		}

		$capture = array(
			'source'   => 'form',
			'project_slug' => (string) ( $posted['project_slug'] ?? '' ),
			'content'  => array( 'source_form_id' => (string) $form_id, 'message' => $message ),
			'contact'  => $contact,
			'fields'   => $posted,
			'attribution' => $attribution,
			'ip'       => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'session_id'   => sanitize_text_field( (string) ( $post[ self::SHORTCODE . '_session' ] ?? '' ) ),
			'time_on_page' => max( 0, (int) ( $post[ self::SHORTCODE . '_time' ] ?? 0 ) ),
			'spam'     => array( 'honeypot_filled' => false ),
		);

		$result = Ingestion::ingest( $capture );
		if ( ! empty( $result['id'] ) ) {
			$capture['id'] = $result['id'];
			// Token anti-duplicado se marca SOLO ahora (envío consumado): un
			// error de validación previo no bloquea el reintento del usuario.
			Spam::mark_duplicate( $form_id, $post );
			// F1 · Action Engine v2: email (notify+autorespuesta) + webhook de
			// salida, mapeados sobre Core\Mail / Webhook (best-effort).
			Actions::run( $form_id, $config, $capture );
		}
		return ! empty( $result['id'] )
			? array( 'id' => (int) $result['id'], 'redirect' => $routing_redirect )
			: array( 'error' => 'No se pudo procesar tu solicitud.' );
	}
}
