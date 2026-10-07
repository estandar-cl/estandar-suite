<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway — link público del cliente (M8).
 *
 * Expone `/cotizacion/{token}` (rewrite propio + `template_redirect`) para que
 * el cliente vea la cotización y acepte/rechace sin depender del tema ni de
 * wp-admin. Página **autocontenida** (DOCTYPE propio + `Template::css()` +
 * `Renderer::render_print()`), sin `get_header()`/`get_footer()` y sin CSS/JS
 * globales: aislada de la plantilla del tema (§6 del runbook).
 *
 * Seguridad: token aleatorio 256-bit (`public_token`), validación de formato,
 * nonce `esq_public_{token}` para la decisión y `esc_*` en toda la salida.
 *
 * Aislamiento: `add_rewrite_rule` + flush **solo en activación**; front-end por
 * `template_redirect` + `exit`. Todo aditivo y con fallback: sin WP/DB no
 * fataliza.
 */
class Gateway
{
	/** Query var de la ruta pública. */
	const QV = 'estandar_quote_token';

	/** Slug base de la ruta pública. */
	const SLUG = 'cotizacion';

	/** Prefijo del nonce de decisión pública. */
	const NONCE_PREFIX = 'esq_public_';

	/**
	 * Wiring en runtime (se llama desde `plugins_loaded`).
	 * La regla de rewrite se añade en `init` (WP aún no ha parseado la request).
	 */
	public static function init(): void
	{
		add_action( 'init', static function () {
			self::register_rule();
		}, 5 );

		add_filter( 'query_vars', static function ( array $vars ) {
			$vars[] = self::QV;
			return $vars;
		} );

		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 6 );
	}

	/** Añade la regla de rewrite (PURE respecto a DB; requiere WP). */
	private static function register_rule(): void
	{
		if ( ! function_exists( 'add_rewrite_rule' ) ) {
			return;
		}
		add_rewrite_rule(
			'^' . self::SLUG . '/([^/]+)/?$',
			'index.php?' . self::QV . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Activación: registra la regla y hace flush UNA vez.
	 * Se invoca SOLO desde `register_activation_hook`.
	 */
	public static function activate(): void
	{
		self::register_rule();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
	}

	// ── PURE ────────────────────────────────────────────────────────────────

	/**
	 * ¿La ruta es pública de cotización con token no vacío? (PURE)
	 * `trim($path,'/')` debe empezar por `cotizacion/` y traer token.
	 */
	public static function is_public_path( string $path ): bool
	{
		$path = trim( $path, "/ \t\n\r\0\x0B" );
		if ( '' === $path ) {
			return false;
		}
		$prefix = self::SLUG . '/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			return false;
		}
		return '' !== trim( substr( $path, strlen( $prefix ) ), '/' );
	}

	/** ¿Formato de token válido? 32 bytes → 64 hex. (PURE) */
	public static function is_valid_token( string $token ): bool
	{
		return 1 === preg_match( '/^[a-f0-9]{16,64}$/', $token );
	}

	// ── Token y URL ─────────────────────────────────────────────────────────

	/**
	 * Token público de una cotización: reusa `public_token` si existe; si no,
	 * genera 256-bit (`bin2hex(random_bytes(32))`) y lo persiste.
	 * No-op seguro sin DB/cotización → ''.
	 */
	public static function token_for( int $id ): string
	{
		if ( $id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return '';
		}
		$quote = \Estandar\Flow\Quotes::get( $id );
		if ( ! is_array( $quote ) ) {
			return '';
		}
		$existing = strtolower( trim( (string) ( $quote['public_token'] ?? '' ) ) );
		if ( '' !== $existing ) {
			return $existing;
		}

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( ! \Estandar\Flow\Quotes::update( $id, array( 'public_token' => $token ) ) ) {
			return '';
		}
		return $token;
	}

	/** URL pública de una cotización ('' si no hay token). */
	public static function public_url( int $id ): string
	{
		return self::public_url_token( self::token_for( $id ) );
	}

	/** URL pública a partir de un token ya conocido. */
	public static function public_url_token( string $token ): string
	{
		if ( '' === $token ) {
			return '';
		}
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		return rtrim( $base, '/' ) . '/' . self::SLUG . '/' . rawurlencode( $token );
	}

	/**
	 * Busca una cotización por `public_token` (validando formato).
	 * Sin DB / token inválido / no encontrado → null.
	 */
	public static function find_by_token( string $token ): ?array
	{
		$token = strtolower( trim( $token ) );
		if ( ! self::is_valid_token( $token ) ) {
			return null;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return null;
		}
		$table = $wpdb->prefix . \Estandar\Flow\Quotes::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE public_token = %s LIMIT 1", $token
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['items'] = \Estandar\Flow\Quotes::decode_items( (string) ( $row['items_json'] ?? '' ) );
		return $row;
	}

	// ── Front-end ───────────────────────────────────────────────────────────

	/**
	 * Punto de entrada público (`template_redirect`): localiza el quote por
	 * token, expira si venció, registra `quote.viewed`, aplica la decisión
	 * (nonce + token) y renderiza la página autocontenida, terminando con `exit`.
	 */
	public static function maybe_render(): void
	{
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}

		$token = function_exists( 'get_query_var' ) ? trim( (string) get_query_var( self::QV ) ) : '';
		if ( '' === $token ) {
			return;
		}

		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) && ! Settings::enabled() ) {
			return;
		}

		$quote = self::find_by_token( $token );
		if ( ! is_array( $quote ) ) {
			self::render_not_found();
			return;
		}
		$id = (int) ( $quote['id'] ?? 0 );

		// Expiración perezosa: `sent` vencida → `expired` (una sola vez).
		if ( 'sent' === sanitize_key( (string) ( $quote['status'] ?? '' ) ) && self::is_expired( $quote ) ) {
			if ( class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
				\Estandar\Flow\Quotes\Lifecycle::transition( $id, 'expired' );
			}
			$fresh = self::find_by_token( $token );
			if ( is_array( $fresh ) ) {
				$quote = $fresh;
			}
		}

		// Tracking de vista (siempre que se abra el link).
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			\Estandar\Flow\Quotes\Repository::log_event( $id, 'quote.viewed', array(), 'cliente' );
		}

		$notice = self::apply_decision( $quote, $token );
		if ( '' !== $notice ) {
			$fresh = self::find_by_token( $token );
			if ( is_array( $fresh ) ) {
				$quote = $fresh;
			}
		}

		$doc_html = '';
		if ( class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' )
			&& class_exists( 'Estandar\\Flow\\Quotes\\Document\\Renderer' ) ) {
			$doc      = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id );
			$doc_html = (string) \Estandar\Flow\Quotes\Document\Renderer::render_print( is_array( $doc ) ? $doc : array() );
		}

		if ( function_exists( 'status_header' ) ) {
			status_header( 200 );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		echo self::render_public_page( $quote, $token, $doc_html, $notice ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Aplica la decisión POST (accept|reject) con nonce + token. Devuelve el
	 * mensaje a mostrar ('' si no había decisión válida).
	 */
	private static function apply_decision( array $quote, string $token ): string
	{
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( 'POST' !== $method ) {
			return '';
		}
		$decision = sanitize_key( (string) ( $_POST['quote_decision'] ?? '' ) );
		if ( ! in_array( $decision, array( 'accept', 'reject' ), true ) ) {
			return '';
		}

		$status = sanitize_key( (string) ( $quote['status'] ?? '' ) );
		if ( 'sent' !== $status ) {
			return 'La cotización ya tiene una decisión registrada.';
		}

		$posted_token = (string) ( $_POST['_token'] ?? '' );
		$token_ok     = function_exists( 'hash_equals' )
			? hash_equals( $token, $posted_token )
			: ( $token === $posted_token );
		$nonce        = (string) ( $_POST['_wpnonce'] ?? '' );
		$nonce_ok     = function_exists( 'wp_verify_nonce' ) && (bool) wp_verify_nonce( $nonce, self::NONCE_PREFIX . $token );

		if ( ! $token_ok || ! $nonce_ok ) {
			return 'No se pudo validar la decisión.';
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
			return 'El módulo de cotizaciones no está disponible.';
		}

		$id = (int) ( $quote['id'] ?? 0 );
		$to = 'accept' === $decision ? 'accepted' : 'rejected';
		$ok = \Estandar\Flow\Quotes\Lifecycle::transition(
			$id,
			$to,
			array( 'reject_reason' => sanitize_text_field( (string) ( $_POST['reject_reason'] ?? '' ) ) ),
			'cliente'
		);
		if ( ! $ok ) {
			return 'No se pudo registrar la decisión.';
		}
		return 'accept' === $decision ? 'Cotización aceptada. Gracias.' : 'Cotización rechazada. Gracias por avisar.';
	}

	/** True si `valid_until` ya pasó. */
	private static function is_expired( array $quote ): bool
	{
		$until = (string) ( $quote['valid_until'] ?? '' );
		if ( '' === $until ) {
			return false;
		}
		$ts = strtotime( $until );
		if ( false === $ts ) {
			return false;
		}
		$now = function_exists( 'current_time' ) ? strtotime( (string) current_time( 'mysql' ) ) : time();
		if ( false === $now ) {
			$now = time();
		}
		return $ts < $now;
	}

	// ── Render ──────────────────────────────────────────────────────────────

	/**
	 * Página pública autocontenida (PURE respecto a plantilla del tema).
	 * Acepta estados `accepted`/`rejected`/`expired` mostrando el estado y los
	 * botones deshabilitados. `$notice` es un aviso de decisión.
	 */
	public static function render_public_page( array $quote, string $token, string $doc_html = '', string $notice = '' ): string
	{
		$status = sanitize_key( (string) ( $quote['status'] ?? '' ) );
		$labels = array(
			'draft'    => 'Borrador',
			'sent'     => 'Pendiente de respuesta',
			'accepted' => 'Aceptada',
			'rejected' => 'Rechazada',
			'expired'  => 'Vencida',
		);
		$label  = $labels[ $status ] ?? ( '' !== $status ? $status : 'Sin estado' );
		$number = (string) ( $quote['quote_number'] ?? '' );
		if ( '' === $number ) {
			$number = 'COT-' . (int) ( $quote['id'] ?? 0 );
		}
		$title = 'Cotización ' . $number;

		$template_css = class_exists( 'Estandar\\Flow\\Quotes\\Document\\Template' )
			? (string) \Estandar\Flow\Quotes\Document\Template::css()
			: '';

		$page_width = class_exists( 'Estandar\\Flow\\Quotes\\Document\\Template' )
			? (string) \Estandar\Flow\Quotes\Document\Template::PAGE_WIDTH
			: '8.5in';
		$base_css = 'body.esq-public{background:#f6f7f7;color:#1d2327;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:24px 16px;}'
			. '.esq-wrap{max-width:' . $page_width . ';margin:0 auto;}'
			. '.esq-wrap .q-doc{background:#fff;border:1px solid #e2e4e7;border-radius:8px;}'
			. '.esq-notice{background:#edf7ed;border:1px solid #b8e0b8;color:#1e6b1e;border-radius:8px;padding:10px 12px;margin:0 0 14px;font-size:14px;}'
			. '.esq-status{text-align:center;font-size:15px;margin:18px 0 0;}'
			. '.esq-decision{margin:18px 0 8px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;}'
			. '.esq-decision button{border:0;border-radius:8px;padding:12px 22px;font-size:15px;font-weight:700;cursor:pointer;}'
			. '.esq-accept{background:#1e7b34;color:#fff;}'
			. '.esq-reject{background:#b32d2e;color:#fff;}'
			. '.esq-decision button[disabled]{background:#dcdcde;color:#787c82;cursor:not-allowed;}';

		$notice_html = '' !== $notice ? '<div class="esq-notice">' . esc_html( $notice ) . '</div>' : '';

		$decision = self::render_decision_block( $quote, $token, $status, $label );

		return '<!DOCTYPE html><html lang="es"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>' . $base_css . $template_css . '</style>'
			. '</head><body class="esq-public">'
			. '<div class="esq-wrap">'
			. $notice_html
			. $doc_html
			. $decision
			. '</div>'
			. '</body></html>';
	}

	/** Bloque de decisión: formulario (sent) o estado + botones deshabilitados. */
	private static function render_decision_block( array $quote, string $token, string $status, string $label ): string
	{
		$accept_btn = '<button type="submit" name="quote_decision" value="accept" class="esq-accept">Aceptar cotización</button>';
		$reject_btn = '<button type="submit" name="quote_decision" value="reject" class="esq-reject">Rechazar cotización</button>';

		if ( 'sent' !== $status ) {
			$state = '<p class="esq-status">Estado: <strong>' . esc_html( $label ) . '</strong></p>';
			$disabled = '<div class="esq-decision">'
				. '<button type="button" class="esq-accept" disabled>Aceptar cotización</button>'
				. '<button type="button" class="esq-reject" disabled>Rechazar cotización</button>'
				. '</div>';
			return $state . $disabled;
		}

		$action = self::public_url_token( $token );
		$nonce  = function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( self::NONCE_PREFIX . $token ) : '';

		return '<form method="post" action="' . esc_url( $action ) . '" class="esq-decision">'
			. '<input type="hidden" name="_token" value="' . esc_attr( $token ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
			. $accept_btn . $reject_btn
			. '</form>';
	}

	/** 404 autocontenido para token inexistente. Termina con `exit`. */
	private static function render_not_found(): void
	{
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>Cotización no encontrada</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:48px 16px;text-align:center;}'
			. 'h1{font-size:22px;}p{color:#646970;}</style></head><body>'
			. '<h1>Cotización no encontrada</h1>'
			. '<p>Este enlace no es válido, fue desactivado o expiró.</p>'
			. '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
