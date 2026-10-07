<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Popups v2 (create / update / delete / list). Fuente única del sistema de
 * popups; vive en su propia tabla estandar_capture_popups.
 */
class Popups
{
	const TABLE   = 'estandar_capture_popups';
	const OPTION  = 'estandar_suite_capture_popups_schema_version';
	const VERSION = '2.0.0';

	/** POPUP_MIGRATE_V2: espacio de IDs sintéticos para el frontend. */
	const V2_ID_OFFSET = 1000000;

	/** POPUP_BLOCKS_V1: el CONTENIDO de cada popup vive en un post del CPT
	 *  `estandar_pop` (el mismo que usaba el módulo legacy), editable con el
	 *  editor de bloques (Gutenberg). La config (triggers/segmentación/diseño)
	 *  sigue en la tabla v2; el post se referencia por config.content_post_id o
	 *  config.legacy_post_id. Solo se registra el CPT (superficie de edición);
	 *  NO se re-activa el frontend/admin legacy. */
	const CONTENT_CPT = 'estandar_pop';

	/** Hooks del modelo de popups. */
	public static function init(): void
	{
		add_action( 'init', array( __CLASS__, 'register_content_cpt' ) );
	}

	/** POPUP_BLOCKS_V1: registra el CPT del contenido (editable con Gutenberg). */
	public static function register_content_cpt(): void
	{
		register_post_type( self::CONTENT_CPT, array(
			'labels'          => array(
				'name'          => __( 'Popups', 'estandar-suite-capture' ),
				'singular_name' => __( 'Popup', 'estandar-suite-capture' ),
				'add_new_item'  => __( 'Nuevo Popup', 'estandar-suite-capture' ),
				'edit_item'     => __( 'Editar Popup', 'estandar-suite-capture' ),
			),
			'public'          => false,
			'show_ui'         => true,
			// POPUP_EDITOR_MENU_V1: menú propio (top-level), como cuando el editor
			// funcionaba. Anidarlo bajo `estandar-suite` (POPUPS_IN_MENU_V1)
			// coincidió con la pérdida del editor de bloques.
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-layout',
			'menu_position'   => 30,
			'show_in_rest'    => true, // habilita el editor de bloques
			'rest_base'       => 'estandar-popups',
			'supports'        => array( 'title', 'editor', 'revisions', 'custom-fields' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'rewrite'         => false,
			'exclude_from_search' => true,
		) );
	}

	const TRIGGER_DEFAULTS = array( 'time' => 0, 'scroll' => 0, 'inactivity' => 0, 'click_selector' => '' );
	const SEG_DEFAULTS     = array( 'post_ids' => array(), 'url_contains' => array(), 'date_start' => '', 'date_end' => '' );
	const FREQ_DEFAULTS    = array( 'mode' => '7days', 'days' => 0 );
	const DESIGN_DEFAULTS  = array( 'size' => 'medium', 'custom_width' => '', 'css_mode' => 'inherit', 'overlay' => true, 'overlay_color' => '#000000', 'overlay_opacity' => 0.6, 'animation' => 'fade', 'exit_animation' => 'same', 'duration' => 0.4, 'z_index' => 99999 );

	/**
	 * POPUP_ANIM_LIB_V1 (2026-09-21): catálogo de efectos de entrada/salida.
	 * Una sola fuente de verdad para el admin y para el render (clases CSS).
	 */
	public static function animations(): array
	{
		return array(
			'fade'        => 'Fade (aparecer)',
			'slide-up'    => 'Deslizar desde abajo',
			'slide-down'  => 'Deslizar desde arriba',
			'slide-left'  => 'Deslizar desde la derecha',
			'slide-right' => 'Deslizar desde la izquierda',
			'zoom'        => 'Zoom',
			'spin'        => 'Girar (spin)',
			'flip-x'      => 'Voltear horizontal (X)',
			'flip-y'      => 'Voltear vertical (Y)',
			'bounce'      => 'Rebote (bounce)',
			'none'        => 'Ninguno',
		);
	}

	/** Normaliza un valor de animación (acepta alias legacy: slide/zoom). */
	public static function normalize_animation( $value, string $default = 'fade' ): string
	{
		$v = strtolower( trim( (string) $value ) );
		if ( $v === 'slide' ) {
			$v = 'slide-up';
		}
		if ( $v === 'same' || $v === '' ) {
			$v = $default;
		}
		return array_key_exists( $v, self::animations() ) ? $v : $default;
	}

	/** Duración permitida del efecto (segundos), acotada por seguridad. */
	public static function normalize_duration( $value ): float
	{
		$d = (float) $value;
		if ( $d < 0.1 || $d > 2.0 ) {
			$d = 0.4;
		}
		return round( $d, 2 );
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT 'draft',
			config_json longtext,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			opens bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			submits bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY status (status)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	public static function sanitize( array $raw ): array
	{
		$name   = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$slug   = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $name );
		}
		$status = sanitize_key( (string) ( $raw['status'] ?? 'draft' ) );
		$status = in_array( $status, array( 'active', 'draft', 'archived' ), true ) ? $status : 'draft';
		$config = trim( (string) ( $raw['config_json'] ?? '' ) );
		if ( $config === '' ) {
			$config = '{}';
		}
		json_decode( $config );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$config = '{}';
		}
		return array( 'name' => $name, 'slug' => $slug, 'status' => $status, 'config_json' => $config );
	}

	public static function list(): array
	{
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY id DESC', ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( int $id ): ?array
	{
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $raw ): int
	{
		global $wpdb;
		$data = self::sanitize( $raw );
		if ( $data['name'] === '' ) {
			return 0;
		}
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = $data['created_at'];
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $raw ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$data = self::sanitize( $raw );
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . self::TABLE, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		return true;
	}

	public static function delete( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	// ── POPUP_MIGRATE_V2 · frontend + migración ─────────────────────────────

	public static function encode_frontend_id( int $row_id ): int
	{
		return self::V2_ID_OFFSET + $row_id;
	}

	public static function is_frontend_id( int $id ): bool
	{
		return $id >= self::V2_ID_OFFSET;
	}

	public static function frontend_row_id( int $id ): int
	{
		return $id - self::V2_ID_OFFSET;
	}

	/** Incrementa un contador del popup v2 (views/opens/clicks/submits). */
	public static function increment_stat( int $row_id, string $key ): void
	{
		if ( $row_id < 1 || ! in_array( $key, array( 'views', 'opens', 'clicks', 'submits' ), true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . $wpdb->prefix . self::TABLE . " SET `{$key}` = `{$key}` + 1 WHERE id = %d",
			$row_id
		) );
	}

	/** POPUP_MIGRATE_V2: popups v2 activos normalizados para el frontend. */
	public static function frontend_items(): array
	{
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->prefix . self::TABLE . " WHERE status = 'active'",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$cfg = json_decode( (string) ( $row['config_json'] ?? '' ), true );
			$cfg = is_array( $cfg ) ? $cfg : array();
			if ( isset( $cfg['active'] ) && ! $cfg['active'] ) {
				continue; // activo por config además del status
			}
			$out[] = array(
				'id'              => self::encode_frontend_id( (int) $row['id'] ),
				'source'          => 'v2',
				'row_id'          => (int) $row['id'],
				'legacy_post_id'  => (int) ( $cfg['legacy_post_id'] ?? 0 ),
				'title'           => (string) $row['name'],
				'content'         => self::resolve_content( $cfg ),
				'triggers'        => array_merge( self::TRIGGER_DEFAULTS, is_array( $cfg['triggers'] ?? null ) ? $cfg['triggers'] : array() ),
				'segmentation'    => array_merge( self::SEG_DEFAULTS, is_array( $cfg['segmentation'] ?? null ) ? $cfg['segmentation'] : array() ),
				'frequency'       => array_merge( self::FREQ_DEFAULTS, is_array( $cfg['frequency'] ?? null ) ? $cfg['frequency'] : array() ),
				'design'          => array_merge( self::DESIGN_DEFAULTS, is_array( $cfg['design'] ?? null ) ? $cfg['design'] : array() ),
				'hide_if_submitted' => ! empty( $cfg['hide_if_submitted'] ),
				'project_slug'    => sanitize_title( (string) ( $cfg['project_slug'] ?? '' ) ),
				'custom_css'      => (string) ( $cfg['custom_css'] ?? '' ),
				'custom_css_scoped' => (string) ( $cfg['custom_css_scoped'] ?? '' ),
			);
		}
		return $out;
	}

	// ── POPUP_BLOCKS_V1 · contenido editable con el editor de bloques ────────

	/** ID del post de contenido vinculado (0 si no hay). */
	public static function content_post_id( array $cfg ): int
	{
		return (int) ( $cfg['content_post_id'] ?? 0 );
	}

	/**
	 * Contenido efectivo del popup: el post vinculado (editor de bloques) si
	 * existe y no está en papelera; si no, el `content` de la config (fallback).
	 */
	public static function resolve_content( array $cfg ): string
	{
		$cpid = self::content_post_id( $cfg );
		if ( $cpid <= 0 ) {
			$cpid = (int) ( $cfg['legacy_post_id'] ?? 0 );
		}
		if ( $cpid > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $cpid );
			if ( $post && (string) $post->post_type === self::CONTENT_CPT && (string) $post->post_status !== 'trash' ) {
				return (string) $post->post_content;
			}
		}
		return (string) ( $cfg['content'] ?? '' );
	}

	/**
	 * POPUP_BLOCKS_V1: garantiza un post de contenido para el popup (lo crea
	 * sembrado con el `content` actual si no existe) y devuelve su ID.
	 */
	public static function ensure_content_post( int $popup_id ): int
	{
		$row = self::get( $popup_id );
		if ( ! $row ) {
			return 0;
		}
		$cfg = json_decode( (string) ( $row['config_json'] ?? '' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();

		$cpid = self::content_post_id( $cfg );
		if ( $cpid > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $cpid );
			if ( $post && (string) $post->post_type === self::CONTENT_CPT && (string) $post->post_status !== 'trash' ) {
				return $cpid;
			}
		}

		// Reutiliza el post legacy migrado (mismo CPT) como contenido editable.
		$legacy = (int) ( $cfg['legacy_post_id'] ?? 0 );
		if ( $legacy > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $legacy );
			if ( $post && (string) $post->post_type === self::CONTENT_CPT && (string) $post->post_status !== 'trash' ) {
				$cfg['content_post_id'] = $legacy;
				self::update( $popup_id, array(
					'name'        => (string) $row['name'],
					'slug'        => (string) $row['slug'],
					'status'      => (string) $row['status'],
					'config_json' => (string) wp_json_encode( $cfg ),
				) );
				return $legacy;
			}
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			return 0;
		}
		$new = wp_insert_post( array(
			'post_type'    => self::CONTENT_CPT,
			'post_status'  => 'publish',
			'post_title'   => (string) $row['name'],
			'post_content' => (string) ( $cfg['content'] ?? '' ),
		), true );
		if ( is_wp_error( $new ) || ! $new ) {
			return 0;
		}
		$cfg['content_post_id'] = (int) $new;

		self::update( $popup_id, array(
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'status'      => (string) $row['status'],
			'config_json' => (string) wp_json_encode( $cfg ),
		) );

		return (int) $new;
	}

	/**
	 * POPUP_CSS_SCOPE: prefija un CSS con `#ep-popup-<frontend_id>` para
	 * aislarlo del resto del sitio. Puro. `$id` es el id del frontend
	 * (encode_frontend_id).
	 */
	public static function scope_css( string $css, int $id ): string
	{
		$css = trim( $css );
		if ( $css === '' ) {
			return '';
		}
		$prefix = "#ep-popup-{$id}";

		// Ignora at-rules no soportadas (keyframes/font-face).
		$css = (string) preg_replace( '/@(keyframes|font-face)[^{]*\{(?:[^{}]*\{[^}]*\})*\s*\}/s', '', $css );

		$scope_selector = static function ( string $selector ) use ( $prefix ): string {
			$parts  = array_map( 'trim', explode( ',', $selector ) );
			$scoped = array_map( static fn( $s ) => $prefix . ' ' . $s, $parts );
			return implode( ', ', $scoped );
		};

		$placeholders = array();
		$css = (string) preg_replace_callback(
			'/@media\s([^{]+)\{((?:[^{}]*\{[^}]*\}\s*)*)\}/s',
			static function ( $m ) use ( $scope_selector, &$placeholders ): string {
				$condition = trim( $m[1] );
				$inner = (string) preg_replace_callback(
					'/([^{}]+)\{([^}]*)\}/s',
					static fn( $r ) => $scope_selector( trim( $r[1] ) ) . ' {' . $r[2] . '}',
					$m[2]
				);
				$key = '__POPUP_MEDIA_' . count( $placeholders ) . '__';
				$placeholders[ $key ] = "@media {$condition} {{$inner}}";
				return $key;
			},
			$css
		);

		$css = (string) preg_replace_callback(
			'/([^@{}\s][^{}]*)\{([^}]*)\}/s',
			static fn( $m ) => $scope_selector( trim( $m[1] ) ) . ' {' . $m[2] . '}',
			$css
		);

		$css = strtr( $css, $placeholders );

		return trim( $css );
	}
}
