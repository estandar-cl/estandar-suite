<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Discovery — motor compartido de descubrimiento para motores IA y SEO IA.
 *
 * Objetivo: que los motores IA (ChatGPT, Perplexity, Gemini, Bing Copilot,
 * AI Overviews) y los crawlers LLM lean y citen el sitio con la mejor
 * especificación posible hoy y en el futuro cercano. Vive en Core como motor
 * compartido (patrón AiKeys/Mail/Destinations): site-wide, consumido por
 * todos los plugins y sitios de la Suite.
 *
 * Contrato NO destructivo:
 * - TODO aditivo: opciones propias (`estandar_suite_core_ai_discovery`),
 *   salidas nuevas (llms.txt, JSON-LD, robots anexado), cero writes sobre
 *   config existente.
 * - robots.txt NUNCA se escribe en disco: se anexan reglas vía el filtro
 *   `robots_txt` (solo aplica al robots virtual; un robots.txt físico queda
 *   intacto por diseño).
 * - Flags de feature todos OFF por defecto: el sitio no cambia hasta que el
 *   usuario los habilita explícitamente.
 * - No crea tablas, no migra datos, no toca otros plugins.
 */
class AIDiscovery
{
	const OPTION = 'estandar_suite_core_ai_discovery';

	/**
	 * Crawlers LLM conocidos (agente → grupo). Grupo determina la regla por
	 * defecto y la capacidad de cache (snapshots).
	 *
	 * @return array<string,array{group:string}>
	 */
	public static function bots(): array
	{
		return array(
			'GPTBot'            => array( 'group' => 'default' ),
			'OAI-SearchBot'     => array( 'group' => 'default' ),
			'ChatGPT-User'      => array( 'group' => 'default' ),
			'ClaudeBot'         => array( 'group' => 'default' ),
			'PerplexityBot'     => array( 'group' => 'default' ),
			'Google-Extended'   => array( 'group' => 'default' ),
			'Applebot-Extended' => array( 'group' => 'default' ),
			'Bytespider'        => array( 'group' => 'default' ),
			'CCBot'             => array( 'group' => 'default' ),
			'Amazonbot'         => array( 'group' => 'default' ),
			'Meta-ExternalAgent'=> array( 'group' => 'default' ),
		);
	}

	/**
	 * Ajustes por defecto. Flags de feature OFF: nada cambia hasta habilitarse.
	 *
	 * @return array
	 */
	public static function defaults(): array
	{
		return array(
			'enable_llms'           => false,
			'allow_ai_bots'         => false,
			'enable_jsonld'         => false,
			'enable_answers'        => false,
			'enable_sitemap_lastmod'=> false,
			'enable_probe'          => false,
			'bots_policy'           => array(), // bot => 'allow'|'deny'|'cache'
			'site_title'            => '',
			'site_description'      => '',
			'org_name'              => '',
			'org_url'               => '',
			'person_name'           => '',
			'person_url'            => '',
		);
	}

	/**
	 * Sanitizar ajustes recibidos (solo claves conocidas, tipos estrictos).
	 *
	 * @param array $raw
	 * @return array
	 */
	public static function sanitize_settings( array $raw ): array
	{
		$d   = self::defaults();
		$out = $d;

		foreach ( array( 'enable_llms', 'allow_ai_bots', 'enable_jsonld', 'enable_answers', 'enable_sitemap_lastmod', 'enable_probe' ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$out[ $flag ] = (bool) $raw[ $flag ];
			}
		}

		foreach ( array( 'site_title', 'site_description', 'org_name', 'org_url', 'person_name', 'person_url' ) as $str ) {
			if ( isset( $raw[ $str ] ) && is_string( $raw[ $str ] ) ) {
				$out[ $str ] = trim( (string) $raw[ $str ] );
			}
		}

		if ( isset( $raw['bots_policy'] ) && is_array( $raw['bots_policy'] ) ) {
			$policy = array();
			foreach ( self::bots() as $bot => $meta ) {
				$p = isset( $raw['bots_policy'][ $bot ] ) ? sanitize_key( (string) $raw['bots_policy'][ $bot ] ) : '';
				if ( in_array( $p, array( 'allow', 'deny', 'cache' ), true ) ) {
					$policy[ $bot ] = $p;
				}
			}
			$out['bots_policy'] = $policy;
		}

		return $out;
	}

	/**
	 * Ajustes vigentes (fusionados con defaults).
	 *
	 * @return array
	 */
	public static function settings(): array
	{
		$opt = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$opt = is_array( $opt ) ? $opt : array();
		return array_merge( self::defaults(), $opt );
	}

	/**
	 * Guardar ajustes sanitizados (aditivo, solo opción propia).
	 */
	public static function update_settings( array $raw ): array
	{
		$clean = self::sanitize_settings( $raw );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $clean, false );
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/*  llms.txt / llms-full.txt — manifiesto LLM                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Generar el manifiesto llms.txt (texto plano) desde datos del sitio.
	 *
	 * @param array  $site  { title, description, home }
	 * @param array  $links list<array{title,url,description}>
	 * @param int    $limit máx de entradas (null = todas)
	 * @return string
	 */
	public static function build_llms( array $site, array $links, ?int $limit = 50 ): string
	{
		$out   = array();
		$title = trim( (string) ( $site['title'] ?? '' ) );
		$desc  = trim( (string) ( $site['description'] ?? '' ) );
		$home  = trim( (string) ( $site['home'] ?? '' ) );

		if ( '' !== $title ) {
			$out[] = '# ' . $title;
		}
		if ( '' !== $desc ) {
			$out[] = '';
			$out[] = '> ' . $desc;
		}
		if ( '' !== $home ) {
			$out[] = '';
			$out[] = '- [' . ( '' !== $title ? $title : 'Home' ) . '](' . $home . ')';
		}
		$out[] = '';

		$n = 0;
		foreach ( $links as $link ) {
			if ( null !== $limit && $n >= $limit ) {
				break;
			}
			$t = trim( (string) ( $link['title'] ?? '' ) );
			$u = trim( (string) ( $link['url'] ?? '' ) );
			if ( '' === $t || '' === $u ) {
				continue;
			}
			$d = trim( (string) ( $link['description'] ?? '' ) );
			$out[] = '## ' . $t;
			$out[] = '- [' . $t . '](' . $u . ')' . ( '' !== $d ? ': ' . $d : '' );
			$n++;
		}

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * llms-full.txt: manifiesto con descripción de cada entrada.
	 *
	 * @param array $site
	 * @param array $links list<array{title,url,description}>
	 * @return string
	 */
	public static function build_llms_full( array $site, array $links ): string
	{
		$full = array();
		foreach ( $links as $link ) {
			$t = trim( (string) ( $link['title'] ?? '' ) );
			$u = trim( (string) ( $link['url'] ?? '' ) );
			$d = trim( (string) ( $link['description'] ?? '' ) );
			if ( '' === $t || '' === $u ) {
				continue;
			}
			$full[] = '## ' . $t;
			$full[] = '- [' . $t . '](' . $u . '): ' . ( '' !== $d ? $d : $t );
			$full[] = '';
		}
		return self::build_llms( $site, array(), null ) . implode( "\n", $full );
	}

	/**
	 * Endpoint público /llms.txt y /llms-full.txt.
	 */
	public static function maybe_render_llms(): void
	{
		$s = self::settings();
		if ( empty( $s['enable_llms'] ) ) {
			return;
		}

		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( ! preg_match( '#/llms(?:-full)?\.txt$#', $uri ) ) {
			return;
		}

		$full = ( strpos( $uri, 'llms-full.txt' ) !== false );

		$site = array(
			'title'       => $s['site_title'] !== '' ? $s['site_title'] : ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' ),
			'description' => $s['site_description'] !== '' ? $s['site_description'] : ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'description' ) : '' ),
			'home'        => function_exists( 'home_url' ) ? home_url( '/' ) : '/',
		);

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo $full ? self::build_llms_full( $site, self::page_links() ) : self::build_llms( $site, self::page_links() );
		exit;
	}

	/**
	 * Links de páginas publicadas (aditivo, para el manifiesto).
	 *
	 * @return array
	 */
	private static function page_links(): array
	{
		$pages = function_exists( 'get_posts' ) ? get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'numberposts'    => 20,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) ) : array();

		$links = array();
		foreach ( $pages as $page ) {
			$links[] = array(
				'title'       => get_the_title( $page ),
				'url'         => get_permalink( $page ),
				'description' => '',
			);
		}
		return $links;
	}

	/* ------------------------------------------------------------------ */
	/*  robots.txt — reglas para bots IA (anexadas, nunca sustituidas)    */
	/* ------------------------------------------------------------------ */

	/**
	 * Reglas de robots para los bots IA según política vigente.
	 *
	 * @param array $bots_policy bot => allow|deny|cache
	 * @return array list<string>
	 */
	public static function robots_rules( array $bots_policy ): array
	{
		$rules = array();
		foreach ( self::bots() as $bot => $meta ) {
			$p = isset( $bots_policy[ $bot ] ) ? $bots_policy[ $bot ] : 'allow';
			if ( 'deny' === $p ) {
				$rules[] = 'User-agent: ' . $bot;
				$rules[] = 'Disallow: /';
			} elseif ( 'cache' === $p ) {
				$rules[] = 'User-agent: ' . $bot;
				$rules[] = 'Disallow:';
				$rules[] = 'Crawl-delay: 10';
			} else {
				$rules[] = 'User-agent: ' . $bot;
				$rules[] = 'Disallow:';
			}
		}
		return $rules;
	}

	/**
	 * Filtro robots_txt: anexa reglas cuando allow_ai_bots está activo.
	 * Solo aplica al robots virtual de WP; un robots.txt físico queda intacto.
	 *
	 * @param string $output
	 * @return string
	 */
	public static function robots_txt( $output ): string
	{
		$s = self::settings();
		if ( empty( $s['allow_ai_bots'] ) ) {
			return $output;
		}

		$block = self::robots_rules( $s['bots_policy'] );
		if ( empty( $block ) ) {
			return $output;
		}

		$out = rtrim( (string) $output );
		$out .= "\n\n# Estandar AI Discovery (aditivo)\n" . implode( "\n", $block ) . "\n";
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  JSON-LD — Organization / Person / FAQ                             */
	/* ------------------------------------------------------------------ */

	/**
	 * JSON-LD de Organization desde ajustes.
	 *
	 * @param array $s
	 * @return string|null
	 */
	public static function jsonld_org( array $s ): ?string
	{
		$name = trim( (string) ( $s['org_name'] ?? '' ) );
		$url  = trim( (string) ( $s['org_url'] ?? '' ) );
		if ( '' === $name || '' === $url ) {
			return null;
		}
		return wp_json_encode( array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $name,
			'url'      => $url,
		) );
	}

	/**
	 * JSON-LD de Person desde ajustes.
	 *
	 * @param array $s
	 * @return string|null
	 */
	public static function jsonld_person( array $s ): ?string
	{
		$name = trim( (string) ( $s['person_name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'name'     => $name,
		);
		$url = trim( (string) ( $s['person_url'] ?? '' ) );
		if ( '' !== $url ) {
			$node['url'] = $url;
		}
		return wp_json_encode( $node );
	}

	/**
	 * JSON-LD FAQPage desde pares pregunta/respuesta.
	 *
	 * @param array $faqs list<array{question,answer}>
	 * @return string|null
	 */
	public static function faq_jsonld( array $faqs ): ?string
	{
		$main = array();
		foreach ( $faqs as $faq ) {
			$q = trim( (string) ( $faq['question'] ?? '' ) );
			$a = trim( (string) ( $faq['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$main[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}
		if ( empty( $main ) ) {
			return null;
		}
		return wp_json_encode( array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main,
		) );
	}

	/**
	 * Inyecta Organization/Person en wp_head cuando enable_jsonld está activo.
	 */
	public static function wp_head_jsonld(): void
	{
		$s = self::settings();
		if ( empty( $s['enable_jsonld'] ) ) {
			return;
		}
		$json = array();
		$org  = self::jsonld_org( $s );
		$per  = self::jsonld_person( $s );
		if ( null !== $org ) { $json[] = $org; }
		if ( null !== $per ) { $json[] = $per; }
		foreach ( $json as $j ) {
			echo "\n<script type=\"application/ld+json\">" . $j . "</script>\n";
		}
	}

	/**
	 * Inyecta FAQPage (acumuladas por el shortcode de respuesta) en wp_footer.
	 */
	public static function wp_footer_faq(): void
	{
		if ( empty( self::$collected_faqs ) ) {
			return;
		}
		$j = self::faq_jsonld( self::$collected_faqs );
		if ( null !== $j ) {
			echo "\n<script type=\"application/ld+json\">" . $j . "</script>\n";
		}
		self::$collected_faqs = array();
	}

	/* ------------------------------------------------------------------ */
	/*  AEO — bloques de respuesta directa                               */
	/* ------------------------------------------------------------------ */

	/** @var array list<array{question,answer}> */
	private static $collected_faqs = array();

	/**
	 * FAQs acumuladas por bloques de respuesta (para el footer JSON-LD).
	 *
	 * @return array
	 */
	public static function faq_collection(): array
	{
		return self::$collected_faqs;
	}

	/**
	 * Marca un bloque de respuesta directa (AEO).
	 *
	 * @param string $question
	 * @param string $answer
	 * @return string HTML semántico.
	 */
	public static function answer_markup( string $question, string $answer ): string
	{
		self::$collected_faqs[] = array( 'question' => $question, 'answer' => $answer );

		return sprintf(
			'<section class="es-answer" aria-label="%s"><h2 class="es-answer-q">%s</h2><p class="es-answer-a">%s</p></section>',
			esc_attr( wp_strip_all_tags( $question ) ),
			esc_html( $question ),
			$answer
		);
	}

	/**
	 * Shortcode [es_answer question="..."]respuesta[/es_answer].
	 */
	public static function answer_shortcode( $atts, $content = null ): string
	{
		$s = self::settings();
		if ( empty( $s['enable_answers'] ) ) {
			return '';
		}
		$q = isset( $atts['question'] ) ? (string) $atts['question'] : '';
		$a = trim( (string) $content );
		if ( '' === $q || '' === $a ) {
			return '';
		}
		return self::answer_markup( $q, $a );
	}

	/* ------------------------------------------------------------------ */
	/*  Sitemap IA — señales de frescura                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Filtro wp_sitemaps_posts_show_lastmod: exponer lastmod (aditivo).
	 *
	 * @param bool $show_lastmod
	 * @return bool
	 */
	public static function sitemap_lastmod( $show_lastmod ): bool
	{
		$s = self::settings();
		return $show_lastmod || ! empty( $s['enable_sitemap_lastmod'] );
	}

	/* ------------------------------------------------------------------ */
	/*  Medición de visibilidad en IA (probe, usa AiKeys)                */
	/* ------------------------------------------------------------------ */

	/**
	 * Registra una medición de visibilidad en el log propio (opción).
	 * Aditivo: append-only; no borra historial.
	 *
	 * @param string $engine    motor IA consultado
	 * @param string $keyword   keyword probada
	 * @param bool   $found     presencia detectada
	 * @param array  $data      detalle libre
	 * @return array log actualizado
	 */
	public static function record_probe( string $engine, string $keyword, bool $found, array $data = array() ): array
	{
		$log = function_exists( 'get_option' ) ? get_option( self::OPTION . '_probes', array() ) : array();
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'engine'   => sanitize_key( $engine ),
			'keyword'  => sanitize_text_field( $keyword ),
			'found'    => (bool) $found,
			'data'     => $data,
			'at'       => gmdate( 'c' ),
		);
		$log = array_slice( $log, -200, 200, true );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION . '_probes', $log, false );
		}
		return $log;
	}

	/**
	 * ¿Hay proveedor IA configurado en el vault (para probes reales)?
	 *
	 * @return bool
	 */
	public static function has_ai_provider(): bool
	{
		if ( ! class_exists( 'Estandar\\Core\\AiKeys' ) ) {
			return false;
		}
		return '' !== (string) AiKeys::get( AiKeys::active_provider() );
	}

	/* ------------------------------------------------------------------ */
	/*  Wiring                                                           */
	/* ------------------------------------------------------------------ */

	public static function init(): void
	{
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_llms' ), 5 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 1 );
		add_action( 'wp_head', array( __CLASS__, 'wp_head_jsonld' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'wp_footer_faq' ), 20 );
		add_shortcode( 'es_answer', array( __CLASS__, 'answer_shortcode' ) );
		add_filter( 'wp_sitemaps_posts_show_lastmod', array( __CLASS__, 'sitemap_lastmod' ), 10, 1 );
	}
}
