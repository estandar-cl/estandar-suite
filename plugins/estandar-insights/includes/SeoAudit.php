<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SeoAudit — auditoría SEO TÉCNICA del propio sitio — Tier 1.
 *
 * NO usa credenciales ni servicios externos: recorre las páginas/posts publicados
 * y analiza el HTML **renderizado** (lo que ve un buscador) para puntuar cada URL
 * e inventariar incidencias. Funciona en cualquier instalación desde el minuto 1.
 *
 * Regla de honestidad: si no se puede obtener una página —timeout o 404— se registra
 * como incidencia, no se inventa un score.
 *
 * Tier 2 (datos reales de búsqueda vía Search Console) es opcional y vive aparte.
 */
class SeoAudit {

	const TABLE  = 'estandar_insights_seo_audit';
	const RUNS   = 'estandar_insights_seo_audit_runs';
	const OPTION = 'estandar_suite_insights_seo_audit_schema';
	const SITE   = 'estandar_suite_insights_seo_site_checks';
	const LAST   = 'estandar_suite_insights_seo_last_scan';
	const VERSION = '1.0.0';

	const CRON_HOOK = 'estandar_insights_seo_audit_cron';

	public static function activate_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(32) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL DEFAULT '',
			title varchar(300) NOT NULL DEFAULT '',
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			issues_json longtext NULL,
			facts_json longtext NULL,
			scanned_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY score (score)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::RUNS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scanned_at datetime NOT NULL,
			pages int NOT NULL DEFAULT 0,
			avg_score decimal(5,1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY scanned_at (scanned_at)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void {
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** Cron semanal, solo si el escaneo automático está activo. */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function ensure_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$settings = get_option( 'estandar_suite_insights_seo_settings', array() );
		$auto     = is_array( $settings ) ? ! empty( $settings['auto_scan'] ) : false;
		if ( $auto && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 900, 'weekly', self::CRON_HOOK );
		} elseif ( ! $auto && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function cron_run(): void {
		self::scan( self::max_pages() );
	}

	private static function max_pages(): int {
		$s = get_option( 'estandar_suite_insights_seo_settings', array() );
		$n = is_array( $s ) ? (int) ( $s['max_pages'] ?? 50 ) : 50;
		return max( 1, min( 500, $n ? $n : 50 ) );
	}

	// ── PURE: parseo + puntuación — testeables sin HTTP ─────────────────────

	/**
	 * Extrae los hechos SEO de un HTML. PURE.
	 *
	 * @param string $html HTML renderizado.
	 * @return array
	 */
	public static function parse_html( string $html ): array {
		$facts = array(
			'title' => '', 'meta_description' => '', 'canonical' => '', 'robots' => '',
			'h1' => 0, 'h2' => 0, 'img_total' => 0, 'img_alt' => 0, 'words' => 0,
			'jsonld' => 0, 'og_title' => '', 'lang' => '',
		);
		if ( trim( $html ) === '' || false === stripos( $html, '<html' ) ) {
			return $facts;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xp = new \DOMXPath( $doc );

		$t = $xp->query( '//title' );
		if ( $t->length ) {
			$facts['title'] = trim( (string) $t->item( 0 )->textContent );
		}

		foreach ( $xp->query( '//meta' ) as $m ) {
			$name = strtolower( (string) $m->getAttribute( 'name' ) );
			$prop = strtolower( (string) $m->getAttribute( 'property' ) );
			if ( 'description' === $name ) {
				$facts['meta_description'] = trim( (string) $m->getAttribute( 'content' ) );
			} elseif ( 'robots' === $name ) {
				$facts['robots'] = strtolower( trim( (string) $m->getAttribute( 'content' ) ) );
			} elseif ( 'og:title' === $prop ) {
				$facts['og_title'] = trim( (string) $m->getAttribute( 'content' ) );
			}
		}

		foreach ( $xp->query( '//link' ) as $l ) {
			if ( 'canonical' === strtolower( (string) $l->getAttribute( 'rel' ) ) ) {
				$facts['canonical'] = trim( (string) $l->getAttribute( 'href' ) );
				break;
			}
		}

		$facts['h1'] = (int) $xp->query( '//h1' )->length;
		$facts['h2'] = (int) $xp->query( '//h2' )->length;

		$imgs = $xp->query( '//img' );
		$facts['img_total'] = (int) $imgs->length;
		$alt = 0;
		foreach ( $imgs as $img ) {
			if ( trim( (string) $img->getAttribute( 'alt' ) ) !== '' ) {
				$alt++;
			}
		}
		$facts['img_alt'] = $alt;

		foreach ( $xp->query( '//script' ) as $sc ) {
			if ( 'application/ld+json' === strtolower( (string) $sc->getAttribute( 'type' ) ) ) {
				$facts['jsonld']++;
			}
		}

		$h = $xp->query( '//html' );
		if ( $h->length ) {
			$facts['lang'] = strtolower( trim( (string) $h->item( 0 )->getAttribute( 'lang' ) ) );
		}

		$body  = $xp->query( '//body' );
		$text  = $body->length ? (string) $body->item( 0 )->textContent : strip_tags( $html );
		$text  = preg_replace( '/\s+/u', ' ', trim( $text ) );
		$facts['words'] = ( '' === $text ) ? 0 : count( (array) preg_split( '/\s+/u', $text ) );

		return $facts;
	}

	/**
	 * Puntúa una página 0-100 con su lista de incidencias. PURE.
	 *
	 * @param array $facts Hechos de parse_html().
	 * @return array{score:int,issues:array<string>}
	 */
	public static function score_page( array $facts ): array {
		$len   = static function ( string $s ): int {
			return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $s ) : strlen( $s );
		};
		$score  = 100;
		$issues = array();

		$title = (string) ( $facts['title'] ?? '' );
		$tl    = $len( $title );
		if ( '' === $title ) {
			$score -= 20;
			$issues[] = 'Falta <title>';
		} elseif ( $tl < 30 ) {
			$score -= 6;
			$issues[] = "Title corto: {$tl} car.";
		} elseif ( $tl > 60 ) {
			$score -= 6;
			$issues[] = "Title largo: {$tl} car.";
		}

		$md = (string) ( $facts['meta_description'] ?? '' );
		$ml = $len( $md );
		if ( '' === $md ) {
			$score -= 15;
			$issues[] = 'Falta meta description';
		} elseif ( $ml < 70 ) {
			$score -= 4;
			$issues[] = "Meta description corta: {$ml} car.";
		} elseif ( $ml > 160 ) {
			$score -= 4;
			$issues[] = "Meta description larga: {$ml} car.";
		}

		if ( '' === (string) ( $facts['canonical'] ?? '' ) ) {
			$score -= 5;
			$issues[] = 'Sin <link rel="canonical">';
		}

		if ( false !== strpos( (string) ( $facts['robots'] ?? '' ), 'noindex' ) ) {
			$score -= 30;
			$issues[] = 'Marcada como noindex';
		}

		$h1 = (int) ( $facts['h1'] ?? 0 );
		if ( 0 === $h1 ) {
			$score -= 12;
			$issues[] = 'Sin H1';
		} elseif ( $h1 > 1 ) {
			$score -= 6;
			$issues[] = "Varios H1: {$h1}";
		}

		if ( 0 === (int) ( $facts['h2'] ?? 0 ) ) {
			$score -= 4;
			$issues[] = 'Sin H2: falta estructura';
		}

		$it = (int) ( $facts['img_total'] ?? 0 );
		$ia = (int) ( $facts['img_alt'] ?? 0 );
		if ( $it > 0 ) {
			$cov = $ia / $it;
			if ( $cov < 0.6 ) {
				$score -= 8;
				$issues[] = 'Imágenes sin alt: ' . (int) round( $cov * 100 ) . '% con alt';
			} elseif ( $cov < 0.9 ) {
				$score -= 3;
				$issues[] = 'Alt de imágenes mejorable';
			}
		}

		$w = (int) ( $facts['words'] ?? 0 );
		if ( $w < 200 ) {
			$score -= 8;
			$issues[] = "Contenido corto: {$w} palabras";
		}

		if ( 0 === (int) ( $facts['jsonld'] ?? 0 ) ) {
			$score -= 5;
			$issues[] = 'Sin datos estructurados JSON-LD';
		}

		if ( '' === (string) ( $facts['lang'] ?? '' ) ) {
			$score -= 3;
			$issues[] = 'Sin atributo lang';
		}

		return array( 'score' => max( 0, min( 100, $score ) ), 'issues' => $issues );
	}

	// ── Escaneo (HTTP) ──────────────────────────────────────────────────────

	/**
	 * Escanea hasta $limit páginas publicadas del sitio.
	 *
	 * @param int  $limit Máximo de páginas.
	 * @param bool $dry   Solo previsualiza (no escribe).
	 * @return array{pages:int,avg:float,results:array}
	 */
	public static function scan( int $limit = 50, bool $dry = false ): array {
		$limit = max( 1, min( 500, $limit ) );
		$types = array( 'page', 'post' );
		$ids   = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );

		$results = array();
		$sum     = 0;
		foreach ( (array) $ids as $id ) {
			$url = (string) get_permalink( $id );
			if ( '' === $url ) {
				continue;
			}
			$html = self::fetch( $url );
			if ( null === $html ) {
				$results[] = array( 'post_id' => (int) $id, 'title' => get_the_title( $id ), 'url' => $url, 'score' => 0, 'issues' => array( 'No se pudo obtener la página: timeout o error de red' ), 'facts' => array() );
				continue;
			}
			$facts = self::parse_html( $html );
			$res   = self::score_page( $facts );
			$sum  += $res['score'];
			$results[] = array(
				'post_id' => (int) $id,
				'title'   => trim( (string) get_the_title( $id ) ),
				'url'     => $url,
				'score'   => $res['score'],
				'issues'  => $res['issues'],
				'facts'   => $facts,
			);
		}

		$avg = $results ? round( $sum / count( $results ), 1 ) : 0.0;

		$site = self::site_checks();

		if ( ! $dry ) {
			self::store( $results, $avg );
			self::remember( count( $results ), $avg, $site );
		}

		return array( 'pages' => count( $results ), 'avg' => $avg, 'results' => $results, 'site' => $site );
	}

	/** Descarga una URL (guarded). Devuelve HTML o null. */
	private static function fetch( string $url ): ?string {
		$res = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3, 'user-agent' => 'Estandar-Suite-SEO-Audit/1.0' ) );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		return '' === trim( $body ) ? null : $body;
	}

	/** Chequeos del sitio (sitemap/robots). */
	public static function site_checks(): array {
		$out = array();
		foreach ( array( 'sitemap.xml', 'robots.txt' ) as $f ) {
			$res  = wp_remote_get( home_url( '/' . $f ), array( 'timeout' => 8 ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$out[ $f ] = array( 'ok' => ( $code >= 200 && $code < 300 ), 'code' => $code );
		}
		return $out;
	}

	// ── Persistencia ────────────────────────────────────────────────────────

	private static function store( array $results, float $avg ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::TABLE ); // phpcs:ignore WordPress.DB
		$now = current_time( 'mysql' );
		foreach ( $results as $r ) {
			$wpdb->insert(
				$wpdb->prefix . self::TABLE,
				array(
					'post_id'     => (int) $r['post_id'],
					'post_type'   => (string) get_post_type( (int) $r['post_id'] ),
					'url'         => (string) $r['url'],
					'title'       => mb_substr( (string) $r['title'], 0, 280 ),
					'score'       => (int) $r['score'],
					'issues_json' => wp_json_encode( $r['issues'] ),
					'facts_json'  => wp_json_encode( $r['facts'] ),
					'scanned_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}
		$wpdb->insert(
			$wpdb->prefix . self::RUNS,
			array( 'scanned_at' => $now, 'pages' => count( $results ), 'avg_score' => $avg ),
			array( '%s', '%d', '%f' )
		);
	}

	private static function remember( int $pages, float $avg, array $site ): void {
		update_option( self::LAST, array( 'at' => current_time( 'mysql' ), 'pages' => $pages, 'avg' => $avg ), false );
		update_option( self::SITE, $site, false );
	}

	/** Última corrida (o valores neutros). */
	public static function last_run(): array {
		$last = get_option( self::LAST, array() );
		return is_array( $last ) && $last ? $last : array( 'at' => '', 'pages' => 0, 'avg' => 0.0 );
	}

	/** Filas auditadas, peor primero. */
	public static function rows( int $limit = 100 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY score ASC LIMIT %d',
			max( 1, min( 500, $limit ) )
		), ARRAY_A );
	}

	public static function count(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . self::TABLE ); // phpcs:ignore WordPress.DB
	}

	/** Tendencia: últimas corridas (más reciente primero). */
	public static function runs( int $limit = 12 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT scanned_at, pages, avg_score FROM ' . $wpdb->prefix . self::RUNS . ' ORDER BY id DESC LIMIT %d',
			max( 1, min( 60, $limit ) )
		), ARRAY_A );
	}

	/** Incidencias agregadas (para el resumen). */
	public static function top_issues(): array {
		$out = array();
		foreach ( self::rows( 500 ) as $r ) {
			$issues = json_decode( (string) ( $r['issues_json'] ?? '' ), true );
			foreach ( (array) $issues as $i ) {
				$key         = (string) preg_replace( '/\s*\(.*$/', '', (string) $i );
				$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
			}
		}
		arsort( $out );
		return array_slice( $out, 0, 10, true );
	}
}
