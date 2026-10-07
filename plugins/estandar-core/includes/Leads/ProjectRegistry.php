<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ProjectRegistry — resolución de proyectos (port de Estandar_Leads_Projects).
 *
 * Reemplaza la dependencia del resolver de proyectos de estandar-leads sobre
 * estandar-primer (Estandar_Core_Project_Registry) para que primer se pueda
 * retirar. Lee la MISMA tabla fuente `estandar_project_registry` (la tabla no
 * se borra al retirar primer) con el mismo SQL y la misma política:
 *   - match exacto por slug o nombre → devuelve el slug registrado;
 *   - 1 match fuzzy (match_key) → devuelve el slug;
 *   - >1 match → telemetría PROJECT_SLUG_MULTI_MATCH + '' (fail-safe);
 *   - 0 matches → '' (PROJECT_REGISTRY_ENFORCEMENT_V1: nunca persiste slugs
 *     no registrados).
 */
class ProjectRegistry
{
	const TABLE_NAME = 'estandar_project_registry';

	/** PURE: prefijo de BD. */
	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
	}

	/** PURE: key de match (sin guiones/guiones bajos). */
	public static function match_key( string $value ): string
	{
		$value = self::slugify( $value );
		return str_replace( array( '-', '_' ), '', $value );
	}

	/** PURE: normalizar slug con fallback. */
	public static function normalize_slug( string $slug, string $fallback = '' ): string
	{
		$slug = self::slugify( $slug );
		if ( $slug === '' ) {
			$slug = self::slugify( $fallback );
		}
		return trim( (string) $slug, "- \t\n\r\0\x0B" );
	}

	/** PURE: slugify (sanitize_title con fallback sin WP para tests). */
	private static function slugify( string $value ): string
	{
		$value = trim( (string) $value );
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9\-_]/', '-', $value );
		$value = preg_replace( '/-+/', '-', $value );
		return trim( $value, '-' );
	}

	/** client_id canónico del sitio (misma fuente que primer: opción). */
	public static function client_slug(): string
	{
		if ( function_exists( 'estandar_core_get_client_slug' ) ) {
			// Primer aún presente → usa su función (fuente de verdad).
			return (string) estandar_core_get_client_slug();
		}
		// V2: lee la opción directamente (port de la función de primer).
		$value = get_option( 'estandar_client_slug', '' );
		return trim( (string) $value, '-_ ' );
	}

	/** Proyectos activos del registry (tabla fuente, misma proyección que primer). */
	public static function registry(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE_NAME;
		$client_id = self::client_slug();
		if ( $client_id !== '' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, operator_name, operator_email, operator_phone
				 FROM {$table} WHERE active = 1 AND client_id = %s ORDER BY sort_order ASC, label ASC",
				$client_id
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, operator_name, operator_email, operator_phone
				 FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, label ASC",
				ARRAY_A
			);
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $project ) {
			$slug = self::normalize_slug( (string) ( $project['project_id'] ?? '' ) );
			if ( $slug === '' ) {
				continue;
			}
			$patterns = json_decode( (string) ( $project['url_patterns'] ?? '' ), true );
			$out[ $slug ] = array(
				'project_slug'   => $slug,
				'project_uuid'   => sanitize_text_field( (string) ( $project['project_uuid'] ?? '' ) ),
				'name'           => sanitize_text_field( (string) ( $project['label'] ?? $slug ) ),
				'city'           => sanitize_text_field( (string) ( $project['city'] ?? '' ) ),
				'url_patterns'   => is_array( $patterns ) ? array_values( array_filter( array_map( 'strval', $patterns ) ) ) : array(),
				'operator_name'  => sanitize_text_field( (string) ( $project['operator_name'] ?? '' ) ),
				'operator_email' => sanitize_email( (string) ( $project['operator_email'] ?? '' ) ),
				'operator_phone' => sanitize_text_field( (string) ( $project['operator_phone'] ?? '' ) ),
			);
		}
		return array_values( $out );
	}

	/** Resuelve un slug contra el registry (misma política que el legacy). */
	public static function resolve_slug( string $slug, string $fallback = '' ): string
	{
		$candidate = self::normalize_slug( $slug, $fallback );
		if ( $candidate === '' ) {
			return '';
		}

		$candidate_key = self::match_key( $candidate );
		$matches = array();
		foreach ( self::registry() as $project ) {
			$registered_slug = self::normalize_slug( (string) ( $project['project_slug'] ?? '' ) );
			$registered_name = self::normalize_slug( (string) ( $project['name'] ?? '' ) );

			if ( $candidate === $registered_slug || ( $registered_name !== '' && $candidate === $registered_name ) ) {
				return $registered_slug;
			}
			if (
				$candidate_key !== '' &&
				(
					$candidate_key === self::match_key( $registered_slug ) ||
					( $registered_name !== '' && $candidate_key === self::match_key( $registered_name ) )
				)
			) {
				$matches[ $registered_slug ] = $registered_slug;
			}
		}

		if ( count( $matches ) === 1 ) {
			return (string) array_values( $matches )[0];
		}
		if ( count( $matches ) > 1 ) {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-core',
				'type'          => 'business_event',
				'severity'      => 'warning',
				'code'          => 'PROJECT_SLUG_MULTI_MATCH',
				'message'       => 'Project slug resolve_slug() matched multiple projects; returning empty.',
				'context'       => array( 'candidate' => $candidate, 'original_slug' => $slug, 'fallback' => $fallback, 'matched_slugs' => array_values( $matches ) ),
			) );
		}
		return '';
	}

	/** Lista plana de slugs registrados. */
	public static function get_all_slugs(): array
	{
		$slugs = array();
		foreach ( self::registry() as $entry ) {
			$s = (string) ( $entry['project_slug'] ?? '' );
			if ( $s !== '' ) {
				$slugs[] = $s;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/** Slug más cercano por Levenshtein (distancia ≤ 3), o ''. */
	public static function suggest_slug( string $slug, array $registry_slugs ): string
	{
		if ( empty( $registry_slugs ) || $slug === '' ) {
			return '';
		}
		$best = '';
		$best_score = PHP_INT_MAX;
		foreach ( $registry_slugs as $candidate ) {
			$d = levenshtein( $slug, (string) $candidate );
			if ( $d < $best_score ) {
				$best_score = $d;
				$best = (string) $candidate;
			}
		}
		return ( $best_score <= 3 && $best !== $slug ) ? $best : '';
	}

	/* ── Escritura del registry (v2) ──────────────────────────────────────
	 * En la v2 la tabla era creada por el legacy (estandar-primer). El Core la
	 * adopta: se auto-crea si falta y el registro se administra desde Capture.
	 */

	/** Crea la tabla si no existe (instalaciones nuevas sin legacy primer). */
	public static function maybe_install(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = $wpdb->prefix . self::TABLE_NAME;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(120) NOT NULL DEFAULT '',
			project_id varchar(64) NOT NULL DEFAULT '',
			project_uuid char(36) NOT NULL DEFAULT '',
			label varchar(190) NOT NULL DEFAULT '',
			city varchar(190) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			url_patterns longtext,
			streets_json longtext,
			meta_json longtext,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			company_id varchar(64) NOT NULL DEFAULT '',
			company varchar(190) NOT NULL DEFAULT '',
			project_type varchar(64) NOT NULL DEFAULT '',
			region varchar(190) NOT NULL DEFAULT '',
			commune varchar(190) NOT NULL DEFAULT '',
			price_from int(10) unsigned NOT NULL DEFAULT 0,
			price_to int(10) unsigned NOT NULL DEFAULT 0,
			sale_type varchar(64) NOT NULL DEFAULT '',
			operator_name varchar(190) NOT NULL DEFAULT '',
			operator_email varchar(190) NOT NULL DEFAULT '',
			operator_phone varchar(64) NOT NULL DEFAULT '',
			form_id varchar(190) NOT NULL DEFAULT '',
			landing_url varchar(500) NOT NULL DEFAULT '',
			cta_primary varchar(190) NOT NULL DEFAULT '',
			cta_secondary varchar(190) NOT NULL DEFAULT '',
			whatsapp_msg text,
			funnel_template varchar(64) NOT NULL DEFAULT '',
			hero_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			site_domains longtext,
			PRIMARY KEY  (id),
			UNIQUE KEY project_id (project_id),
			KEY client_project (client_id, project_id),
			KEY active (active),
			KEY sort_order (sort_order)
		) {$charset};" );
		return true;
	}

	/** Filas del manager (incluye inactivos), crudas para la UI. */
	public static function manager_list(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE_NAME;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results(
			"SELECT id, client_id, project_id, project_uuid, label, city, active, sort_order,
			        operator_name, operator_email, operator_phone, created_at, updated_at
			 FROM {$table} ORDER BY active DESC, sort_order ASC, label ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Label (nombre) de un slug registrado. */
	public static function label_for( string $slug ): string
	{
		$slug = self::normalize_slug( $slug );
		if ( $slug === '' ) {
			return '';
		}
		foreach ( self::manager_list() as $row ) {
			if ( self::normalize_slug( (string) $row['project_id'] ) === $slug ) {
				return sanitize_text_field( (string) $row['label'] );
			}
		}
		return '';
	}

	/** Operador del proyecto (nombre/email/phone).
	 *
	 * OPERATOR_SOURCE_OF_TRUTH_V1 (2026-09-27): la fuente PRIMARIA es
	 * `es_operators` (multi-operador real, con `active`): se toma el primer
	 * operador ACTIVO asignado al proyecto. Así el "espejo" del registry
	 * (`operator_name/email/phone`) dejó de ser autoritativo y ya no se
	 * desfasa al activar/desactivar operadores. El espejo del registry queda
	 * solo como FALLBACK para proyectos legacy sin filas en `es_operators`. */
	public static function operator_for( string $slug ): array
	{
		global $wpdb;
		$slug = self::normalize_slug( $slug );
		if ( $slug === '' || ! isset( $wpdb ) ) {
			return array();
		}

		if ( class_exists( '\Estandar\Core\Leads\Operators' ) ) {
			$assigned = \Estandar\Core\Leads\Operators::assigned( $slug );
			if ( ! empty( $assigned ) ) {
				foreach ( $assigned as $o ) {
					if ( (int) ( $o['active'] ?? 0 ) === 1 ) {
						return array(
							'name'  => sanitize_text_field( (string) ( $o['name'] ?? '' ) ),
							'email' => sanitize_email( (string) ( $o['email'] ?? '' ) ),
							'phone' => sanitize_text_field( (string) ( $o['whatsapp_number'] ?? '' ) ),
						);
					}
				}
				// Hay operadores asignados pero TODOS inactivos: NO caer al
				// espejo (obsoleto); devolver vacío para que el consumidor use
				// su default (p. ej. operador del sitio / admin).
				return array();
			}
		}

		// Fallback legacy: espejo del registry.
		$table = $wpdb->prefix . self::TABLE_NAME;
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT operator_name, operator_email, operator_phone FROM {$table} WHERE project_id = %s AND active = 1 LIMIT 1",
			$slug
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'name'  => (string) ( $row['operator_name'] ?? '' ),
			'email' => (string) ( $row['operator_email'] ?? '' ),
			'phone' => (string) ( $row['operator_phone'] ?? '' ),
		);
	}

	/** Crea un proyecto genérico (sin sesgo inmobiliario). */
	public static function create_project( array $data ): array
	{
		self::maybe_install();
		global $wpdb;
		$label = sanitize_text_field( (string) ( $data['label'] ?? '' ) );
		if ( $label === '' ) {
			return array( 'ok' => false, 'error' => 'project_label_required' );
		}
		$slug = trim( (string) ( $data['project_id'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = self::normalize_slug( $label );
		}
		if ( $slug === '' ) {
			$slug = 'proyecto-' . substr( md5( $label . microtime() ), 0, 6 );
		}
		// DUPLICATE_GUARD_V1 (2026-09-22): no crear por error un proyecto
		// duplicado del mismo cliente con el mismo nombre (normalizado). Si ya
		// existe, se REUTILIZA y se reactiva. Un duplicado legítimo requiere
		// `allow_duplicate = 1` explícito.
		$client = self::client_slug();
		if ( empty( $data['allow_duplicate'] ) ) {
			$lkey = self::match_key( $label );
			foreach ( self::manager_list() as $row ) {
				if ( (string) $row['client_id'] === (string) $client
					&& self::match_key( (string) $row['label'] ) === $lkey
					&& self::normalize_slug( (string) $row['project_id'] ) !== $slug ) {
					if ( (int) $row['active'] !== 1 ) {
						self::set_active( (string) $row['project_id'], true );
					}
					return array( 'ok' => true, 'slug' => (string) $row['project_id'], 'label' => (string) $row['label'], 'reused' => true );
				}
			}
		}

		$table = $wpdb->prefix . self::TABLE_NAME;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT project_id FROM {$table} WHERE project_id = %s LIMIT 1", $slug ) ) ) {
			return array( 'ok' => false, 'error' => 'project_slug_exists', 'slug' => $slug );
		}
		$now    = current_time( 'mysql' );
		$uuid   = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,4095)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff) );
		$max    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order),0)+1 FROM {$table}" );
		$ok = $wpdb->insert( $table, array(
			'client_id'      => $client,
			'project_id'     => $slug,
			'project_uuid'   => $uuid,
			'label'          => $label,
			'city'           => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'active'         => 1,
			'sort_order'     => $max,
			'created_at'     => $now,
			'updated_at'     => $now,
			'operator_name'  => sanitize_text_field( (string) ( $data['operator_name'] ?? '' ) ),
			'operator_email' => sanitize_email( (string) ( $data['operator_email'] ?? '' ) ),
			'operator_phone' => sanitize_text_field( (string) ( $data['operator_phone'] ?? '' ) ),
		) );
		if ( ! $ok ) {
			return array( 'ok' => false, 'error' => 'project_insert_failed' );
		}
		return array( 'ok' => true, 'slug' => $slug, 'label' => $label );
	}

	/** Actualiza campos genéricos de un proyecto por slug. */
	public static function update_project( string $slug, array $data ): array
	{
		self::maybe_install();
		global $wpdb;
		$slug = self::normalize_slug( $slug );
		if ( $slug === '' ) {
			return array( 'ok' => false, 'error' => 'project_missing' );
		}
		$set = array();
		foreach ( array( 'label', 'city', 'operator_name', 'operator_phone' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$set[ $k ] = sanitize_text_field( (string) $data[ $k ] );
			}
		}
		if ( isset( $data['operator_email'] ) ) {
			$set['operator_email'] = sanitize_email( (string) $data['operator_email'] );
		}
		if ( empty( $set ) ) {
			return array( 'ok' => false, 'error' => 'project_nothing_to_update' );
		}
		$set['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( $wpdb->prefix . self::TABLE_NAME, $set, array( 'project_id' => $slug ) );
		return ( false === $ok ) ? array( 'ok' => false, 'error' => 'project_update_failed' ) : array( 'ok' => true, 'slug' => $slug );
	}

	/** Activa (true) o archiva (false) un proyecto por slug. */
	public static function set_active( string $slug, bool $active ): array
	{
		self::maybe_install();
		global $wpdb;
		$slug = self::normalize_slug( $slug );
		if ( $slug === '' ) {
			return array( 'ok' => false, 'error' => 'project_missing' );
		}
		$wpdb->update(
			$wpdb->prefix . self::TABLE_NAME,
			array( 'active' => $active ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ),
			array( 'project_id' => $slug )
		);
		return array( 'ok' => true, 'slug' => $slug, 'active' => $active );
	}

	/** Elimina (DELETE) un proyecto del registry. Solo llamar tras reasignar o
	 *  cuando no tiene catchers asociados. Devuelve false si no existía. */
	public static function delete_project( string $slug ): bool
	{
		self::maybe_install();
		global $wpdb;
		$slug = self::normalize_slug( $slug );
		if ( $slug === '' ) {
			return false;
		}
		$table = $wpdb->prefix . self::TABLE_NAME;
		$gone  = (int) $wpdb->delete( $table, array( 'project_id' => $slug ) );
		return $gone > 0;
	}

	/** Proyecto por defecto del sitio (auto). Reusa el que exista con el mismo
	 *  nombre del sitio; si no, lo crea. Devuelve el slug ('' si falla). */
	public static function auto_project_slug(): string
	{
		self::maybe_install();
		$label = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		if ( $label === '' ) {
			$label = 'Mi sitio';
		}

		// DUPLICATE_GUARD_V1 (2026-09-22): si el cliente YA tiene proyecto(s)
		// activo(s), REUSAR el existente. Nunca crear un duplicado "por error"
		// (antes se creaba `cliente-2` cuando el label del proyecto no coincidía
		// con el nombre del sitio). Crear un proyecto nuevo requiere la acción
		// explícita "__new__" del admin.
		$client = self::client_slug();
		$mine   = array();
		foreach ( self::manager_list() as $row ) {
			if ( (int) $row['active'] === 1 && ( $client === '' || (string) $row['client_id'] === $client ) ) {
				$mine[] = $row;
			}
		}
		if ( count( $mine ) === 1 ) {
			return self::normalize_slug( (string) $mine[0]['project_id'] );
		}
		if ( count( $mine ) > 1 ) {
			$lkey = self::match_key( $label );
			foreach ( $mine as $row ) {
				if ( self::match_key( (string) $row['label'] ) === $lkey ) {
					return self::normalize_slug( (string) $row['project_id'] );
				}
			}
			// Varios proyectos y ninguno coincide: NO adivinar (el admin forzará
			// una elección explícita; ver handle_save_form → project_auto_failed).
			return '';
		}

		// Sin proyecto activo del cliente: reusar una fila existente (aunque
		// esté inactiva) o crear una sola vez con el nombre del sitio.
		$base = $client !== '' ? $client : self::normalize_slug( $label );
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT project_id FROM {$table} WHERE project_id = %s LIMIT 1", $base ) ) ) {
			self::set_active( $base, true );
			return self::normalize_slug( (string) $base );
		}
		$r = self::create_project( array( 'project_id' => $base, 'label' => $label ) );
		return ! empty( $r['ok'] ) ? (string) $r['slug'] : '';
	}
}
