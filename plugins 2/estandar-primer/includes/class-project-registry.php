<?php
/**
 * Estandar Project Registry
 *
 * Entidad central de proyectos comerciales del ecosistema Estandar.
 * Fuente única de verdad para identidad, operación comercial y funnel de cada proyecto.
 *
 * Consumidores:
 *   - Estandar Forms    → streets[], form_id
 *   - Estandar Chatbot  → url_patterns[], operator_name, operator_phone, funnel_template
 *   - Estandar Leads    → project_id / label / operator_email
 *   - Estandar Analytics → project_id
 *
 * Schema v1 → v2 (additive, no breaking changes):
 *   v1: project_id, label, city, active, url_patterns, streets_json, meta_json, sort_order
 *   v2: + company, region, commune, price_from, price_to, sale_type,
 *         operator_name, operator_email, operator_phone,
 *         form_id, landing_url, cta_primary, cta_secondary,
 *         whatsapp_msg, funnel_template, hero_image_id
 *
 * @package Estandar_Core_MU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) :

class Estandar_Core_Project_Registry {

	const TABLE_NAME      = 'estandar_project_registry';
	const SCHEMA_VERSION  = '2.2';
	const VERSION_OPTION  = 'estandar_project_registry_version';
	const CLIENT_ID_BACKFILL_OPTION = 'estandar_project_registry_client_id_backfill_v1';

	/**
	 * Tipos de proyecto (qué ES el proyecto).
	 * Independiente de funnel_template (cómo SE VENDE).
	 */
	const PROJECT_TYPES = [
		'real_estate' => 'Inmobiliario',
		'business_unit' => 'Unidad de negocio',
		'event'       => 'Evento',
		'service'     => 'Servicio',
		'other'       => 'Otro',
	];

	/**
	 * Funnel templates disponibles (cómo se vende).
	 * Consumidos por: Chatbot, Landing Builder (futuro), Analytics.
	 */
	const FUNNEL_TEMPLATES = [
		'QUALITY_OF_LIFE' => 'Calidad de Vida',
		'SCARCITY'        => 'Escasez',
		'HOUSING_ACCESS'  => 'Acceso a Vivienda',
	];

	/**
	 * Tipos de venta disponibles.
	 */
	const SALE_TYPES = [
		'immediate' => 'Entrega Inmediata',
		'green'     => 'Venta en Verde',
		'ds19'      => 'DS19',
		'ds49'      => 'DS49',
	];

	// ---------------------------------------------------------------------------
	// Schema install / upgrade
	// ---------------------------------------------------------------------------

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		// dbDelta es aditivo: añade columnas nuevas sin tocar datos existentes.
		// Regla: nunca eliminar ni renombrar columnas en esta declaración.
		// Nota: dbDelta NO cambia tipos de columnas existentes — ver migrate_column_types() abajo.
		dbDelta( "CREATE TABLE {$table} (
			id               bigint(20) unsigned  NOT NULL AUTO_INCREMENT,
				client_id        varchar(120)         NOT NULL DEFAULT '',
				project_id       varchar(64)          NOT NULL DEFAULT '',
			project_uuid     char(36)             NOT NULL DEFAULT '',
			label            varchar(190)         NOT NULL DEFAULT '',
			city             varchar(190)         NOT NULL DEFAULT '',
			active           tinyint(1)           NOT NULL DEFAULT 1,
			url_patterns     longtext,
			streets_json     longtext,
			meta_json        longtext,
			sort_order       smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at       datetime             NOT NULL,
			updated_at       datetime             NOT NULL,
			company_id       varchar(64)          NOT NULL DEFAULT '',
			company          varchar(190)         NOT NULL DEFAULT '',
			project_type     varchar(64)          NOT NULL DEFAULT '',
			region           varchar(190)         NOT NULL DEFAULT '',
			commune          varchar(190)         NOT NULL DEFAULT '',
			price_from       int(10) unsigned     NOT NULL DEFAULT 0,
			price_to         int(10) unsigned     NOT NULL DEFAULT 0,
			sale_type        varchar(64)          NOT NULL DEFAULT '',
			operator_name    varchar(190)         NOT NULL DEFAULT '',
			operator_email   varchar(190)         NOT NULL DEFAULT '',
			operator_phone   varchar(64)          NOT NULL DEFAULT '',
			form_id          varchar(190)         NOT NULL DEFAULT '',
			landing_url      varchar(500)         NOT NULL DEFAULT '',
			cta_primary      varchar(190)         NOT NULL DEFAULT '',
			cta_secondary    varchar(190)         NOT NULL DEFAULT '',
			whatsapp_msg     text,
			funnel_template  varchar(64)          NOT NULL DEFAULT '',
				hero_image_id    bigint(20) unsigned  NOT NULL DEFAULT 0,
				site_domains     longtext,
				PRIMARY KEY  (id),
				UNIQUE KEY   project_id   (project_id),
				KEY          client_project (client_id, project_id),
				KEY          client_active (client_id, active),
				KEY          active        (active),
			KEY          sort_order    (sort_order),
			KEY          company_id    (company_id),
			KEY          project_type  (project_type)
			/* UNIQUE KEY project_uuid se crea en ensure_uuid_index() DESPUÉS del backfill,
			   porque dbDelta añade la columna con DEFAULT '' y múltiples '' romperían el índice único. */
		) {$charset};" );

		// Migración de tipos: dbDelta no cambia tipos existentes.
		// Si price_from/price_to eran varchar (schema 2.0), los convertimos a INT.
			self::migrate_column_types( $table );
			self::backfill_client_ids( $table );

		// Backfill project_uuid para filas existentes sin UUID.
		// DEBE correr antes de crear el UNIQUE INDEX — ver ensure_uuid_index().
		self::backfill_project_uuids( $table );

		// Crear UNIQUE INDEX en project_uuid solo después de que todas las filas tengan UUID.
		self::ensure_uuid_index( $table );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
			}

		public static function maybe_backfill_client_ids(): void {
			global $wpdb;

			$table = $wpdb->prefix . self::TABLE_NAME;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
			if ( ! $table_exists ) {
				return;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing_client_ids = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE client_id = '' OR client_id IS NULL" );
			if ( $missing_client_ids <= 0 ) {
				update_option( self::CLIENT_ID_BACKFILL_OPTION, 'done', false );
				return;
			}

			self::backfill_client_ids( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE client_id = '' OR client_id IS NULL" );
			if ( $remaining <= 0 ) {
				update_option( self::CLIENT_ID_BACKFILL_OPTION, 'done', false );
			}
		}

		// ---------------------------------------------------------------------------
		// Public read API
	// ---------------------------------------------------------------------------

	/**
	 * Todos los proyectos activos, ordenados por sort_order ASC.
	 *
	 * @return array<int, array>  Ver hydrate() para la forma completa del array.
	 */
	public static function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
			$rows  = $wpdb->get_results(
				"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, streets_json, meta_json, sort_order,
				        company_id, company, project_type, region, commune, price_from, price_to, sale_type,
				        operator_name, operator_email, operator_phone,
				        form_id, landing_url, cta_primary, cta_secondary,
				        whatsapp_msg, funnel_template, hero_image_id, site_domains
				 FROM {$table}
				 WHERE active = 1
				 ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

			return array_map( [ __CLASS__, 'hydrate' ], $rows );
		}

		/**
		 * Proyectos activos pertenecientes a un cliente.
		 *
		 * @param string $client_id Identificador canónico del cliente.
		 * @return array<int, array>
		 */
		public static function get_all_for_client( string $client_id ): array {
			global $wpdb;

			$client_id = self::normalize_client_id( $client_id );
			if ( '' === $client_id ) {
				return [];
			}

			$table = $wpdb->prefix . self::TABLE_NAME;
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, streets_json, meta_json, sort_order,
					        company_id, company, project_type, region, commune, price_from, price_to, sale_type,
					        operator_name, operator_email, operator_phone,
					        form_id, landing_url, cta_primary, cta_secondary,
					        whatsapp_msg, funnel_template, hero_image_id, site_domains
					 FROM {$table}
					 WHERE active = 1
					   AND client_id = %s
					 ORDER BY sort_order ASC, label ASC",
					$client_id
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) ) {
				return [];
			}

			return array_map( [ __CLASS__, 'hydrate' ], $rows );
		}

	/**
	 * Un proyecto por su project_id (slug).
	 *
	 * @param string $project_id Slug operativo del proyecto.
	 * @return array|null
	 */
	public static function get( string $project_id ): ?array {
		global $wpdb;

		$project_id = sanitize_key( $project_id );
		if ( '' === $project_id ) {
			return null;
		}

		$table = $wpdb->prefix . self::TABLE_NAME;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
					"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, streets_json, meta_json, sort_order,
					        company_id, company, region, commune, price_from, price_to, sale_type,
					        operator_name, operator_email, operator_phone,
					        form_id, landing_url, cta_primary, cta_secondary,
					        whatsapp_msg, funnel_template, hero_image_id, site_domains
					 FROM {$table}
					 WHERE project_id = %s",
				$project_id
			),
			ARRAY_A
		);

			return $row ? self::hydrate( $row ) : null;
		}

		public static function project_belongs_to_client( string $project_id, string $client_id ): bool {
			global $wpdb;

			$project_id = sanitize_key( $project_id );
			$client_id  = self::normalize_client_id( $client_id );
			if ( '' === $project_id || '' === $client_id ) {
				return false;
			}

			$table = $wpdb->prefix . self::TABLE_NAME;
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE project_id = %s AND client_id = %s LIMIT 1",
					$project_id,
					$client_id
				)
			);
		}

	/**
	 * Calles de un proyecto, listas para un <select>.
	 *
	 * @param string $project_id
	 * @return string[]
	 */
	public static function get_streets( string $project_id ): array {
		$project = self::get( $project_id );
		return $project ? (array) ( $project['streets'] ?? [] ) : [];
	}

	/**
	 * URL patterns de un proyecto (para el chatbot).
	 *
	 * @param string $project_id
	 * @return string[]
	 */
	public static function get_url_patterns( string $project_id ): array {
		$project = self::get( $project_id );
		return $project ? (array) ( $project['url_patterns'] ?? [] ) : [];
	}

	/**
	 * Array plano para <select> de formularios.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_for_select(): array {
		$client_id = self::current_client_id();
		$projects  = $client_id !== '' ? self::get_all_for_client( $client_id ) : self::get_all();
		$result    = [];

		foreach ( $projects as $project ) {
			$result[] = [
				'value' => $project['project_id'],
				'label' => $project['label'],
			];
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Write API (admin only — nunca en flujo automático de upgrade)
	// ---------------------------------------------------------------------------

	/**
	 * Inserta o actualiza un proyecto por project_id.
	 * Seguro para llamar desde seed, admin save, o migraciones.
	 * Los campos v2 son opcionales; si no se pasan, el valor existente se preserva en UPDATE.
	 *
	 * @param array $data
	 * @return bool
	 */
	public static function upsert( array $data ): bool {
		global $wpdb;

		$project_id = sanitize_key( (string) ( $data['project_id'] ?? '' ) );
		if ( '' === $project_id ) {
			return false;
		}

			$now       = current_time( 'mysql', true );
			$table     = $wpdb->prefix . self::TABLE_NAME;
			$client_id = self::normalize_client_id( (string) ( $data['client_id'] ?? self::current_client_id() ) );

		// v1 fields
		$row = [
			'project_id'   => $project_id,
			'label'        => sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
			'city'         => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'active'       => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'url_patterns' => wp_json_encode( array_values( array_filter( (array) ( $data['url_patterns'] ?? [] ) ) ) ),
			'streets_json' => wp_json_encode( array_values( array_filter( (array) ( $data['streets'] ?? [] ) ) ) ),
				'meta_json'    => wp_json_encode( is_array( $data['meta'] ?? null ) ? $data['meta'] : [] ),
				'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
				'updated_at'   => $now,
			];
			if ( '' !== $client_id ) {
				$row['client_id'] = $client_id;
			}
			if ( array_key_exists( 'site_domains', $data ) ) {
				$row['site_domains'] = wp_json_encode( self::normalize_list( (array) $data['site_domains'] ) );
			}

		// v2 string fields — solo se incluyen si están en $data (evita sobrescribir con '' en updates parciales)
		$v2_string_fields = [
			'company_id', 'company', 'project_type', 'region', 'commune', 'sale_type',
			'operator_name', 'operator_email', 'operator_phone',
			'form_id', 'landing_url', 'cta_primary', 'cta_secondary',
			'whatsapp_msg', 'funnel_template',
		];
		foreach ( $v2_string_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$row[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		// v2 int fields
		if ( array_key_exists( 'price_from', $data ) ) {
			$row['price_from'] = absint( $data['price_from'] );
		}
		if ( array_key_exists( 'price_to', $data ) ) {
			$row['price_to'] = absint( $data['price_to'] );
		}
		if ( array_key_exists( 'hero_image_id', $data ) ) {
			$row['hero_image_id'] = absint( $data['hero_image_id'] );
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %s", $project_id )
		);

		if ( $exists ) {
			return false !== $wpdb->update( $table, $row, [ 'project_id' => $project_id ] );
		}

		$row['created_at']    = $now;
		$row['project_uuid']  = wp_generate_uuid4(); // siempre nuevo en insert
		return false !== $wpdb->insert( $table, $row );
	}

	/**
	 * Migra tipos de columnas que dbDelta no puede cambiar por sí solo.
	 * Idempotente: verifica el tipo actual antes de ejecutar ALTER.
	 *
	 * v2.0 → v2.1: price_from/price_to varchar(32) → int(10) unsigned
	 */
		private static function migrate_column_types( string $table ): void {
			global $wpdb;

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return;
		}

		$col_types = [];
		foreach ( $columns as $col ) {
			$col_types[ $col['Field'] ] = strtolower( (string) ( $col['Type'] ?? '' ) );
		}

		// price_from: varchar → int unsigned
		if ( isset( $col_types['price_from'] ) && str_contains( $col_types['price_from'], 'varchar' ) ) {
			if ( self::column_int_castable( $table, 'price_from' ) ) {
					$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN price_from int(10) unsigned NOT NULL DEFAULT 0" );
				} else {
					self::report_unsafe_price_migration( $table, 'price_from' );
				}
		}

		// price_to: varchar → int unsigned
			if ( isset( $col_types['price_to'] ) && str_contains( $col_types['price_to'], 'varchar' ) ) {
				if ( self::column_int_castable( $table, 'price_to' ) ) {
						$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN price_to int(10) unsigned NOT NULL DEFAULT 0" );
					} else {
						self::report_unsafe_price_migration( $table, 'price_to' );
					}
			}
		}

	/**
	 * True when every non-empty value in the column is a non-negative integer literal —
	 * i.e. the varchar→int MODIFY will not silently coerce real data to 0. NULL/empty
	 * values are fine (they map to the column default 0). Guards B5 price coercion.
	 */
	private static function column_int_castable( string $table, string $column ): bool {
		global $wpdb;
		$bad = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE {$column} IS NOT NULL
			   AND TRIM({$column}) <> ''
			   AND {$column} NOT REGEXP '^[0-9]+$'"
		);
		return 0 === $bad;
	}

	/**
	 * Surface a skipped price migration (column left as varchar) so the non-numeric
	 * values can be reconciled manually. Never coerces. Idempotent — re-checked next run.
	 */
	private static function report_unsafe_price_migration( string $table, string $column ): void {
		global $wpdb;
		$samples = (array) $wpdb->get_col(
			"SELECT DISTINCT {$column} FROM {$table}
			 WHERE {$column} IS NOT NULL AND TRIM({$column}) <> '' AND {$column} NOT REGEXP '^[0-9]+$'
			 LIMIT 5"
		);
		if ( function_exists( 'do_action' ) ) {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-core-mu',
				'type'          => 'business_event',
				'severity'      => 'warning',
				'code'          => 'PROJECT_REGISTRY_PRICE_MIGRATION_SKIPPED',
				'message'       => "Skipped {$column} varchar->int migration: non-numeric values present; column left as varchar.",
				'context'       => array( 'table' => $table, 'column' => $column, 'samples' => $samples ),
			) );
		}
	}

	private static function backfill_client_ids( string $table ): void {
		global $wpdb;

		$client_id = self::current_client_id();
		if ( '' === $client_id ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET client_id = %s WHERE client_id = '' OR client_id IS NULL",
				$client_id
			)
		);
	}

	/**
	 * Genera project_uuid para todas las filas que tengan el campo vacío.
	 * Idempotente. UUIDs existentes nunca se sobreescriben.
	 */
	private static function backfill_project_uuids( string $table ): void {
		global $wpdb;

		// Verifica que la columna exista antes de operar.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = %s
			   AND COLUMN_NAME = 'project_uuid'",
			$table
		) );

		if ( ! $exists ) {
			return;
		}

		$ids = $wpdb->get_col(
			"SELECT id FROM {$table} WHERE project_uuid = '' OR project_uuid IS NULL"
		);

		foreach ( (array) $ids as $id ) {
			$wpdb->update(
				$table,
				[ 'project_uuid' => wp_generate_uuid4() ],
				[ 'id' => (int) $id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Crea UNIQUE INDEX en project_uuid solo si todas las filas tienen UUID no vacío.
	 * Se llama DESPUÉS de backfill_project_uuids() para evitar colisión con DEFAULT ''.
	 * Idempotente: verifica si el índice ya existe antes de intentar crearlo.
	 */
	private static function ensure_uuid_index( string $table ): void {
		global $wpdb;

		// Verificar si el índice ya existe.
		$index_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND INDEX_NAME   = 'project_uuid'",
			$table
		) );

		if ( $index_exists ) {
			return;
		}

		// No crear el índice si hay filas con UUID vacío (backfill incompleto).
		$empty_uuids = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE project_uuid = '' OR project_uuid IS NULL"
		);

		if ( $empty_uuids > 0 ) {
			// Backfill incompleto — el índice se intentará en el próximo upgrade.
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY project_uuid (project_uuid)" );
	}

	/**
	 * Elimina un proyecto por project_id.
	 * Solo para uso admin. No llama desde flujos automáticos.
	 *
	 * @param string $project_id
	 * @return bool
	 */
	public static function delete( string $project_id ): bool {
		global $wpdb;

		$project_id = sanitize_key( $project_id );
		if ( '' === $project_id ) {
			return false;
		}

		$table = $wpdb->prefix . self::TABLE_NAME;
		return false !== $wpdb->delete( $table, [ 'project_id' => $project_id ], [ '%s' ] );
	}

	/**
	 * Todos los proyectos incluyendo inactivos. Solo para uso admin.
	 *
	 * @return array<int, array>
	 */
	public static function get_all_admin(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
			$rows  = $wpdb->get_results(
				"SELECT client_id, project_id, project_uuid, label, city, active, url_patterns, streets_json, meta_json, sort_order,
				        company_id, company, project_type, region, commune, price_from, price_to, sale_type,
				        operator_name, operator_email, operator_phone,
				        form_id, landing_url, cta_primary, cta_secondary,
				        whatsapp_msg, funnel_template, hero_image_id, site_domains
				 FROM {$table}
				 ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ __CLASS__, 'hydrate' ], $rows );
	}

	// ---------------------------------------------------------------------------
	// Internal helpers
	// ---------------------------------------------------------------------------

	/**
	 * Convierte una fila DB en el array canónico del proyecto.
	 * Los campos v1 siempre están presentes; los v2 devuelven '' / 0 si la columna
	 * no existe aún (instalación antigua), para que los consumers no fallen.
	 *
	 * @param array<string,mixed> $row  Row from DB (ARRAY_A)
	 * @return array
	 */
	private static function hydrate( array $row ): array {
			return [
				// v1 — siempre presentes
				'client_id'       => (string) ( $row['client_id'] ?? '' ),
				'project_id'      => (string) ( $row['project_id'] ?? '' ),
			'label'           => (string) ( $row['label'] ?? '' ),
			'city'            => (string) ( $row['city'] ?? '' ),
			'active'          => (bool) ( $row['active'] ?? true ),
			'sort_order'      => (int) ( $row['sort_order'] ?? 0 ),
			'url_patterns'    => self::decode_json_array( (string) ( $row['url_patterns'] ?? '' ) ),
			'streets'         => self::decode_json_array( (string) ( $row['streets_json'] ?? '' ) ),
			'meta'            => self::decode_json_object( (string) ( $row['meta_json'] ?? '' ) ),
			// v2 — identidad extendida
			'project_uuid'    => (string) ( $row['project_uuid'] ?? '' ),
			'project_type'    => (string) ( $row['project_type'] ?? '' ),
			'company_id'      => (string) ( $row['company_id'] ?? '' ),
			'company'         => (string) ( $row['company'] ?? '' ),
			'region'          => (string) ( $row['region'] ?? '' ),
			'commune'         => (string) ( $row['commune'] ?? '' ),
			// v2 — comercial (precios como int, 0 = no definido)
			'price_from'      => (int) ( $row['price_from'] ?? 0 ),
			'price_to'        => (int) ( $row['price_to'] ?? 0 ),
			'sale_type'       => (string) ( $row['sale_type'] ?? '' ),
			// v2 — operacional
			'operator_name'   => (string) ( $row['operator_name'] ?? '' ),
			'operator_email'  => (string) ( $row['operator_email'] ?? '' ),
			'operator_phone'  => (string) ( $row['operator_phone'] ?? '' ),
			'whatsapp_msg'    => (string) ( $row['whatsapp_msg'] ?? '' ),
			// v2 — funnel
			'funnel_template' => (string) ( $row['funnel_template'] ?? '' ),
			'cta_primary'     => (string) ( $row['cta_primary'] ?? '' ),
			'cta_secondary'   => (string) ( $row['cta_secondary'] ?? '' ),
				// v2 — integración
				'form_id'         => (string) ( $row['form_id'] ?? '' ),
				'landing_url'     => (string) ( $row['landing_url'] ?? '' ),
				'site_domains'    => self::decode_json_array( (string) ( $row['site_domains'] ?? '' ) ),
				// v2 — assets
				'hero_image_id'   => (int) ( $row['hero_image_id'] ?? 0 ),
			];
		}

		private static function current_client_id(): string {
			if ( function_exists( 'estandar_core_get_client_slug' ) ) {
				return self::normalize_client_id( estandar_core_get_client_slug() );
			}

			if ( defined( 'ESTANDAR_CLIENT_SLUG' ) ) {
				return self::normalize_client_id( (string) ESTANDAR_CLIENT_SLUG );
			}

			return '';
		}

		private static function normalize_client_id( string $client_id ): string {
			return sanitize_key( sanitize_title( remove_accents( $client_id ) ) );
		}

		private static function normalize_list( array $values ): array {
			$normalized = [];
			foreach ( $values as $value ) {
				$value = trim( strtolower( (string) $value ) );
				if ( '' !== $value ) {
					$normalized[] = $value;
				}
			}

			return array_values( array_unique( $normalized ) );
		}

	private static function decode_json_array( string $json ): array {
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? array_values( $decoded ) : [];
	}

	private static function decode_json_object( string $json ): array {
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// ---------------------------------------------------------------------------
	// Default project seed (monoproyecto out-of-the-box)
	// ---------------------------------------------------------------------------

	/**
	 * Si el registro está vacío para el cliente actual, crea el proyecto default.
	 *
	 * Diseñado para instalaciones monoproyecto: la gran mayoría de sitios solo
	 * necesita un proyecto cuyo slug coincide con estandar_client_slug.
	 * Las instalaciones multiproyecto ya tienen proyectos → no hace nada.
	 *
	 * Idempotente:
	 *  - option flag evita la query en cada request una vez seeded.
	 *  - si ya hay ≥1 proyecto para este cliente, marca el flag y sale.
	 *  - si se borra el proyecto default manualmente, el flag impide re-seedear.
	 */
	public static function maybe_seed_default_project(): void {
		$client_id = self::current_client_id();
		if ( '' === $client_id ) {
			return;
		}

		$flag_option = 'estandar_proj_registry_seeded_' . $client_id;
		if ( get_option( $flag_option ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// Verificar que la tabla existe antes de operar.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		if ( ! $table_exists ) {
			return;
		}

		// Verificar si este cliente ya tiene un proyecto.
		// Si existen proyectos de OTRO cliente (ej. Acacios en DB de Guillaume),
		// el seed igual debe ejecutarse para crear el proyecto default de este cliente.
		// La condición por client_id protege contra backfill_client_ids() pendiente
		// en sitios que lleguen con client_id = '' en filas existentes.
		$client_project_exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE client_id = %s OR project_id = %s",
			$client_id,
			$client_id
		) );
		if ( $client_project_exists > 0 ) {
			update_option( $flag_option, '1', false );
			return;
		}

		// Si hay proyectos sin client_id (pre-migración), no podemos determinar
		// si pertenecen a este cliente. Marcar como seeded para evitar duplicados
		// y dejar que el admin decida.
		$rows_without_client = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE client_id = '' OR client_id IS NULL"
		);
		if ( $rows_without_client > 0 ) {
			update_option( $flag_option, '1', false );
			return;
		}

		// Registro vacío → crear proyecto default con slug = client_id.
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		self::upsert( [
			'project_id' => $client_id,
			'label'      => $site_name ?: $client_id,
			'client_id'  => $client_id,
			'active'     => 1,
		] );

		update_option( $flag_option, '1', false );
	}

	}

endif; // class_exists
