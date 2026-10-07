<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge — índice de contenido del sitio para el agente público.
 *
 * Extrae las páginas, posts y otros contenidos publicados del sitio a una tabla
 * propia (`estandar_chatbot_knowledge`) para que el chatbot público responda
 * usando la información real del sitio como fuente (con cita a la URL).
 * Solo lectura; el índice se construye por WP-CLI (`brain knowledge index`).
 */
class Knowledge
{
	const TABLE = 'estandar_chatbot_knowledge';
	const OPTION = 'estandar_suite_knowledge_schema';
	const VERSION = '1';
	const LAST_INDEXED = 'estandar_suite_knowledge_last_indexed';

	/** Cron de reindexado automático (diario). */
	const CRON_HOOK = 'estandar_brain_knowledge_reindex';

	/**
	 * Programa el reindexado automático. Sin esto, el índice envejece y el
	 * agente público responde con información vieja o vacía (era el caso: 0
	 * fragmentos hasta que se corrió a mano).
	 */
	public static function init(): void
	{
		add_action( self::CRON_HOOK, array( __CLASS__, 'index_all' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/** Programa el cron diario si no existe. */
	public static function ensure_schedule(): void
	{
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON_HOOK );
		}
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	private static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(32) NOT NULL DEFAULT 'page',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title text NOT NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			excerpt text,
			content text NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source_type, source_id),
			KEY title (title(60))
		) {$wpdb->get_charset_collate()};" );
		update_option( self::OPTION, self::VERSION, false );
	}

	/** Post types indexados (extensible por filter). */
	public static function post_types(): array
	{
		return apply_filters( 'estandar_suite_knowledge_post_types', array( 'page', 'post' ) );
	}

	/** Construye el índice desde el contenido publicado del sitio. */
	public static function index_all(): array
	{
		global $wpdb;
		$types = array_map( 'sanitize_key', self::post_types() );
		if ( ! $types ) {
			return array( 'sources' => 0, 'chunks' => 0 );
		}
		$query = new \WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );
		$rows   = array();
		$chunks = 0;
		$now    = current_time( 'mysql' );
		foreach ( (array) $query->posts as $post_id ) {
			$post   = get_post( $post_id );
			$raw    = (string) $post->post_content;
			// Los page-builders (Divi/Elementor) dejan shortcodes en el contenido:
			// sin esto el agente responde con "[et_pb_section ...]" en vez de texto.
			if ( function_exists( 'strip_shortcodes' ) ) {
				$raw = strip_shortcodes( $raw );
			}
			// Residuos de page-builders cuyos shortcodes no están registrados en
			// este contexto (p. ej. Divi): apertura/cierre con atributos largos.
			$raw    = preg_replace( '/\[\/?[a-z0-9_\-]+[^\]]*\]/i', ' ', $raw );
			$text   = wp_strip_all_tags( $raw, true );
			$text   = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			$text   = preg_replace( '/\s+/u', ' ', trim( $text ) );
			$title  = trim( (string) $post->post_title );
			$url    = (string) get_permalink( $post_id );
			$excerpt = mb_substr( $text, 0, 200 );
			if ( $text === '' ) {
				$text = $title;
			}
			foreach ( self::chunks( $text ) as $chunk ) {
				$rows[] = array(
					'source_type' => $post->post_type,
					'source_id'   => (int) $post_id,
					'title'       => $title,
					'url'         => $url,
					'excerpt'     => $excerpt,
					'content'     => $chunk,
					'updated_at'  => $now,
				);
				$chunks++;
			}
		}
		self::clear();
		if ( $rows ) {
			foreach ( array_chunk( $rows, 200 ) as $batch ) {
				foreach ( $batch as $r ) {
					$wpdb->insert( $wpdb->prefix . self::TABLE, $r, array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
				}
			}
		}
		update_option( self::LAST_INDEXED, $now, false );
		return array( 'sources' => count( (array) $query->posts ), 'chunks' => $chunks );
	}

	/** Divide texto en fragmentos de ~600 caracteres (por límite de palabra). */
	private static function chunks( string $text, int $size = 600 ): array
	{
		$words = preg_split( '/\s+/u', trim( $text ) );
		$out   = array();
		$cur   = '';
		foreach ( (array) $words as $w ) {
			$w = trim( $w );
			if ( $w === '' ) {
				continue;
			}
			$next = $cur === '' ? $w : $cur . ' ' . $w;
			if ( mb_strlen( $next ) > $size && $cur !== '' ) {
				$out[] = $cur;
				$cur   = $w;
			} else {
				$cur = $next;
			}
		}
		if ( $cur !== '' ) {
			$out[] = $cur;
		}
		return $out;
	}

	/** Vacía el índice. */
	public static function clear(): void
	{
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . self::TABLE );
	}

	public static function count(): int
	{
		if ( ! self::table_exists() ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE );
	}

	public static function list_sources( int $limit = 30 ): array
	{
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_type, source_id, title, url, updated_at FROM {$wpdb->prefix}" . self::TABLE . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Busca los fragmentos más relevantes para la consulta (con cita). */
	public static function search( string $query, int $limit = 3 ): array
	{
		if ( ! self::table_exists() || trim( $query ) === '' ) {
			return array();
		}
		global $wpdb;
		$tokens = self::tokens( $query );
		if ( ! $tokens ) {
			return array();
		}
		$scores = array();
		foreach ( $tokens as $tok ) {
			$like = '%' . $wpdb->esc_like( $tok ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE content LIKE %s OR title LIKE %s LIMIT 50', $like, $like ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$id = (int) $r['id'];
				$scores[ $id ] = $scores[ $id ] ?? array(
					'id'      => $id,
					'title'   => $r['title'],
					'url'     => $r['url'],
					'excerpt' => $r['excerpt'] ? $r['excerpt'] : mb_substr( (string) $r['content'], 0, 200 ),
					'score'   => 0,
				);
				$scores[ $id ]['score'] += ( strpos( (string) $r['content'], $tok ) !== false ? 2 : 0 ) + ( strpos( (string) $r['title'], $tok ) !== false ? 3 : 0 );
			}
		}
		usort( $scores, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return array_slice( array_values( $scores ), 0, $limit );
	}

	/** Tokeniza la consulta (quita palabras vacías en español). */
	private static function tokens( string $query ): array
	{
		$stop = array( 'de', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'que', 'para', 'y', 'o', 'en', 'del', 'al', 'por', 'con', 'a', 'no', 'es', 'son', 'esta', 'hay', 'como', 'cual', 'cuales', 'cuanto', 'donde' );
		$norm = strtolower( preg_replace( '/[^a-z0-9áéíóúñü ]/iu', ' ', $query ) );
		$out  = array();
		foreach ( preg_split( '/\s+/u', trim( $norm ) ) as $w ) {
			if ( strlen( $w ) > 2 && ! in_array( $w, $stop, true ) && ! in_array( $w, $out, true ) ) {
				$out[] = $w;
			}
		}
		return $out;
	}

	private static function table_exists(): bool
	{
		global $wpdb;
		$t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::TABLE ) );
		return $t === $wpdb->prefix . self::TABLE;
	}
}
