<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operators — gestión de la tabla `es_operators` (operador por proyecto:
 * email de notificación + WhatsApp). La ventana Core→Leads→Operadores y la
 * página de Proyectos comparten esta API. Genérico (no inmobiliario).
 */
class Operators
{
	const TABLE = 'es_operators';

	/** Crea la tabla si falta (instalaciones nuevas sin legacy CRM). */
	public static function maybe_install(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operator_uuid char(36) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			area varchar(190) NOT NULL DEFAULT '',
			whatsapp_number varchar(64) NOT NULL DEFAULT '',
			access_token_hash char(64) NOT NULL DEFAULT '',
			access_token_expires_at datetime NULL,
			allowed_projects_json longtext,
			operational_hours_json longtext,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_slug (project_slug),
			KEY active (active)
		) {$charset};" );
		return true;
	}

	/** Filas del manager (id/nombre/email/proyecto/whatsapp/activo). */
	public static function list(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results(
			"SELECT id, name, email, project_slug, whatsapp_number, active
			 FROM {$table} ORDER BY project_slug ASC, name ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Busca por email exacto ('' si no existe). */
	public static function find_by_email( string $email ): array
	{
		$email = sanitize_email( $email );
		if ( $email === '' ) {
			return array();
		}
		foreach ( self::list() as $row ) {
			if ( sanitize_email( (string) $row['email'] ) === $email ) {
				return $row;
			}
		}
		return array();
	}

	/** Crea un operador (email opcional pero recomendado). Si no recibe proyecto,
	 *  se asigna el proyecto por defecto de la web (auto, siempre existe). */
	public static function create( array $data ): array
	{
		self::maybe_install();
		global $wpdb;
		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( $name === '' && $email === '' ) {
			return array( 'ok' => false, 'error' => 'operator_name_or_email_required' );
		}
		if ( $name === '' ) {
			$name = $email;
		}
		$project_slug = sanitize_title( (string) ( $data['project_slug'] ?? '' ) );
		if ( $project_slug === '' && class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
			$project_slug = (string) \Estandar\Core\Leads\ProjectRegistry::auto_project_slug();
		}
		if ( $email !== '' && self::find_by_email( $email ) ) {
			return array( 'ok' => false, 'error' => 'operator_email_exists', 'email' => $email );
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'name'            => $name,
			'email'           => $email,
			'project_slug'    => $project_slug,
			'area'            => sanitize_text_field( (string) ( $data['area'] ?? '' ) ),
			'whatsapp_number' => sanitize_text_field( (string) ( $data['whatsapp_number'] ?? '' ) ),
			'active'          => 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		if ( ! $ok ) {
			return array( 'ok' => false, 'error' => 'operator_insert_failed' );
		}
		return array( 'ok' => true, 'id' => (int) $wpdb->insert_id, 'email' => $email, 'name' => $name, 'project_slug' => $project_slug );
	}

	/** Actualiza campos de un operador por id (email/whatsapp/proyecto/activo). */
	public static function update( int $id, array $data ): array
	{
		self::maybe_install();
		global $wpdb;
		$found = false;
		foreach ( self::list() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return array( 'ok' => false, 'error' => 'operator_not_found' );
		}
		$set = array();
		if ( isset( $data['name'] ) ) {
			$set['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( isset( $data['email'] ) ) {
			$set['email'] = sanitize_email( (string) $data['email'] );
		}
		if ( isset( $data['whatsapp_number'] ) || isset( $data['whatsapp'] ) ) {
			$set['whatsapp_number'] = sanitize_text_field( (string) ( $data['whatsapp_number'] ?? $data['whatsapp'] ) );
		}
		if ( isset( $data['project_slug'] ) ) {
			$set['project_slug'] = sanitize_title( (string) $data['project_slug'] );
		}
		if ( isset( $data['active'] ) ) {
			$set['active'] = empty( $data['active'] ) ? 0 : 1;
		}
		if ( empty( $set ) ) {
			return array( 'ok' => false, 'error' => 'operator_nothing_to_update' );
		}
		$set['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( $wpdb->prefix . self::TABLE, $set, array( 'id' => $id ) );
		return ( false === $ok ) ? array( 'ok' => false, 'error' => 'operator_update_failed' ) : array( 'ok' => true, 'id' => $id );
	}

	/** Operadores asignados a un proyecto (es_operators.project_slug). */
	public static function assigned( string $slug ): array
	{
		$slug = sanitize_title( $slug );
		if ( $slug === '' ) {
			return array();
		}
		$out = array();
		foreach ( self::list() as $row ) {
			if ( sanitize_title( (string) ( $row['project_slug'] ?? '' ) ) === $slug ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Reasigna el set de operadores de un proyecto: los marcados se mueven a
	 * <slug>; los que estaban en <slug> y ya no están marcados vuelven al
	 * proyecto del sitio (default) — o quedan sin proyecto si el default es el
	 * propio <slug>. Devuelve lista ids resultante en <slug>.
	 */
	public static function reassign_set( array $ids, string $slug ): array
	{
		self::maybe_install();
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( $slug === '' ) {
			return array();
		}
		$selected = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$selected[ $id ] = true;
			}
		}
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$default_slug = '';
		if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
			$default_slug = (string) \Estandar\Core\Leads\ProjectRegistry::auto_project_slug();
		}
		$current = (array) $wpdb->get_results( "SELECT id, project_slug FROM {$table}", ARRAY_A );
		foreach ( $current as $row ) {
			$rid = (int) ( $row['id'] ?? 0 );
			$rs  = sanitize_title( (string) ( $row['project_slug'] ?? '' ) );
			if ( $rid <= 0 ) {
				continue;
			}
			if ( isset( $selected[ $rid ] ) ) {
				if ( $rs !== $slug ) {
					$wpdb->update( $table, array( 'project_slug' => $slug, 'updated_at' => $now ), array( 'id' => $rid ) );
				}
			} elseif ( $rs === $slug ) {
				// Fue removido de este proyecto.
				$move = ( $default_slug !== '' && $default_slug !== $slug ) ? $default_slug : '';
				$wpdb->update( $table, array( 'project_slug' => $move, 'updated_at' => $now ), array( 'id' => $rid ) );
			}
		}
		$out = array();
		foreach ( array_keys( $selected ) as $id ) {
			$out[] = (int) $id;
		}
		return $out;
	}
}
