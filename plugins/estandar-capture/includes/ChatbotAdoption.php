<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatbotAdoption — adopción read-only de los datos del chatbot legacy.
 *
 * Tres movimientos, todos read-only sobre los originales (`ecb_*`):
 *   1. Leads → capturas v2 (`estandar_capture_submissions`, source=chatbot,
 *      marcador origin_lead_id) — el contrato Adoption/FormsAdoption.
 *   2. Conversaciones/logs/eventos → tablas espejo v2
 *      (`estandar_capture_legacy_*`, copia fiel con legacy_id + origin) para
 *      que el v2 siga mostrando el histórico SIN depender de estandar-chatbot.
 *
 * Invariantes:
 *   - nunca modifica los originales (solo SELECT);
 *   - first-writer-wins: cada fila legacy se importa una sola vez;
 *   - idempotente + resumible + reversible (rollback borra SOLO lo adoptado);
 *   - nunca reclama ownership exclusivo (el chatbot sigue escribiendo);
 *   - data safety gate: solo se LEE `ecb_*`.
 */
class ChatbotAdoption
{
	const LEGACY_LEADS = 'ecb_leads';
	const SOURCE       = 'chatbot';

	/** Superficies espejo: sufijo → tabla legacy (sin prefijo). */
	const MIRRORS = array(
		'conversations' => 'ecb_conversations',
		'logs'          => 'ecb_logs',
		'events'        => 'ecb_events',
	);

	/** PURE: prefijo de BD (wpdb o 'wp_' en tests). */
	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
	}

	/** PURE: tabla espejo v2 para un sufijo. */
	public static function mirror_table( string $suffix ): string
	{
		return self::prefix() . 'estandar_capture_legacy_' . sanitize_key( $suffix );
	}

	/** PURE: tabla legacy fuente para un sufijo. */
	public static function legacy_table( string $suffix ): string
	{
		return self::prefix() . ( self::MIRRORS[ $suffix ] ?? '' );
	}

	/** True cuando el sufijo es una superficie espejo conocida. */
	public static function is_mirror( string $suffix ): bool
	{
		return isset( self::MIRRORS[ sanitize_key( $suffix ) ] );
	}

	public static function legacy_present( string $suffix = '' ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		if ( $suffix === '' ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_LEADS ) );
		}
		if ( ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::legacy_table( $suffix ) . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** True cuando la tabla espejo v2 existe. */
	public static function mirror_present( string $suffix ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::mirror_table( $suffix ) . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Columnas reales de la tabla espejo (intersección tolerante al esquema). */
	private static function mirror_columns( string $suffix ): array
	{
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $suffix ] ) ) {
			return $cache[ $suffix ];
		}
		$cols = array();
		if ( isset( $wpdb ) && self::mirror_present( $suffix ) ) {
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::mirror_table( $suffix ) ) as $c ) {
				$cols[] = (string) ( $c->Field ?? $c['Field'] ?? '' );
			}
		}
		$cache[ $suffix ] = $cols;
		return $cols;
	}

	/** PURE: mapea una fila legacy `ecb_leads` a una proyección v2. */
	public static function project_lead( array $row ): array
	{
		$data  = array();
		$raw   = (string) ( $row['data'] ?? '' );
		if ( $raw !== '' && $raw !== 'null' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}

		$channel = sanitize_key( (string) ( $data['source_channel'] ?? 'web' ) );
		$source_form_id = $channel === 'whatsapp' ? 'chatbot_whatsapp' : 'chatbot';

		$session_id = (string) ( $data['analytics_session_id'] ?? $data['session_id'] ?? '' );

		$fields = $data;
		$fields['_adopted_from'] = 'ecb_leads';

		return array(
			'capture_uuid'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : Ingestion::fallback_uuid(),
			'source'           => self::SOURCE,
			'source_form_id'   => $source_form_id,
			'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'email_normalized' => sanitize_text_field( strtolower( (string) ( $row['email'] ?? '' ) ) ),
			'phone_normalized' => Ingestion::normalize_phone( (string) ( $row['phone'] ?? '' ) ),
			'message'          => sanitize_textarea_field( (string) ( $data['requirement'] ?? $data['message'] ?? '' ) ),
			'fields_json'      => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'utm_source'       => sanitize_key( (string) ( $data['utm_source'] ?? '' ) ),
			'utm_medium'       => sanitize_key( (string) ( $data['utm_medium'] ?? '' ) ),
			'utm_campaign'     => sanitize_text_field( (string) ( $data['utm_campaign'] ?? '' ) ),
			'utm_term'         => sanitize_text_field( (string) ( $data['utm_term'] ?? '' ) ),
			'utm_content'      => sanitize_text_field( (string) ( $data['utm_content'] ?? '' ) ),
			'landing_url'      => esc_url_raw( (string) ( $data['source_page_url'] ?? '' ) ),
			'session_id'       => sanitize_text_field( $session_id ),
			'origin_lead_id'   => (int) ( $row['id'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Read-only: pagina sobre leads históricos del chatbot (proyectados). */
	public static function historical_leads( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::LEGACY_LEADS . " ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		return array_map( array( __CLASS__, 'project_lead' ), (array) $rows );
	}

	/**
	 * Import idempotente, resumible y reversible de leads del chatbot.
	 * dry_run=1 → retorna cuántos se importarían, 0 escrituras.
	 *
	 * @return array { imported, skipped, existing, dry_run }
	 */
	public static function import( bool $dry_run = true, int $limit = 100 ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return $counts;
		}
		foreach ( self::historical_leads( $limit, 0 ) as $projection ) {
			$origin = (int) $projection['origin_lead_id'];
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Ingestion::TABLE
				. " WHERE origin_lead_id = %d AND source = %s LIMIT 1", $origin, self::SOURCE
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run ) {
				Ingestion::persist( $projection );
			}
			$counts['imported']++;
		}
		return $counts;
	}

	/** Rollback: borra SOLO los leads del chatbot adoptados (marcador). */
	public static function rollback(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . Ingestion::TABLE . " WHERE origin_lead_id > 0 AND source = %s", self::SOURCE
		) );
	}

	/** Conteos read-only: ecb_leads, adoptados, total source=chatbot. */
	public static function counts(): array
	{
		global $wpdb;
		$out = array( 'legacy_total' => 0, 'adopted' => 0, 'capture_total' => 0 );
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		if ( self::legacy_present() ) {
			$out['legacy_total'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . self::LEGACY_LEADS );
		}
		$table = $wpdb->prefix . Ingestion::TABLE;
		$out['adopted'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE origin_lead_id > 0 AND source = %s", self::SOURCE
		) );
		$out['capture_total'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE source = %s", self::SOURCE
		) );
		return $out;
	}

	// ── Espejos v2 (conversaciones/logs/eventos) ────────────────────────────

	const MIRROR_OPTION  = 'estandar_capture_chatbot_mirror_schema_version';
	const MIRROR_VERSION = '1.0.0';

	/** Crea las tablas espejo v2 (esquema idéntico al legacy + legacy_id/origin). */
	public static function activate_mirror_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$conv = self::mirror_table( 'conversations' );
		dbDelta( "CREATE TABLE {$conv} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			session_key varchar(190) NOT NULL,
			channel varchar(40) NOT NULL,
			user_id bigint(20) unsigned NULL,
			lead_email varchar(190) NULL,
			lead_phone varchar(40) NULL,
			conversation_status varchar(40) NOT NULL DEFAULT 'open',
			message_count int(10) unsigned NOT NULL DEFAULT 0,
			first_message_at datetime NOT NULL,
			last_message_at datetime NOT NULL,
			summary longtext NULL,
			messages longtext NOT NULL,
			meta longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY uniq_session_channel (session_key, channel),
			KEY idx_last_message_at (last_message_at),
			KEY idx_channel (channel),
			KEY idx_lead_email (lead_email)
			) {$charset};" );

		$logs = self::mirror_table( 'logs' );
		dbDelta( "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			datetime datetime NOT NULL,
			question longtext NOT NULL,
			answer longtext NOT NULL,
			ip varchar(45) NULL,
			user_agent text NULL,
			url text NULL,
			meta longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY idx_datetime (datetime)
			) {$charset};" );

		$events = self::mirror_table( 'events' );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			created_at datetime NOT NULL,
			event_name varchar(90) NOT NULL,
			channel varchar(40) NOT NULL,
			session_key varchar(190) NULL,
			conversation_id bigint(20) unsigned NULL,
			lead_id bigint(20) unsigned NULL,
			lead_email varchar(190) NULL,
			lead_phone varchar(40) NULL,
			meta longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY idx_created_at (created_at),
			KEY idx_event_name (event_name),
			KEY idx_channel (channel),
			KEY idx_session_key (session_key)
			) {$charset};" );

		update_option( self::MIRROR_OPTION, self::MIRROR_VERSION, false );
	}

	public static function maybe_migrate_mirror_schema(): void
	{
		if ( get_option( self::MIRROR_OPTION, '' ) !== self::MIRROR_VERSION ) {
			self::activate_mirror_schema();
		}
	}

	private static function count_table( string $table ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
	}

	/** Estado de herencia por superficie espejo: legacy vs adoptado. */
	public static function mirror_status(): array
	{
		$out = array();
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$out[ $suffix ] = array(
				'legacy'        => self::legacy_present( $suffix ) ? self::count_table( self::legacy_table( $suffix ) ) : -1,
				'adopted'       => self::mirror_present( $suffix ) ? self::count_table( self::mirror_table( $suffix ) ) : 0,
				'legacy_exists' => self::legacy_present( $suffix ),
			);
		}
		return $out;
	}

	/** Migra una superficie espejo (idempotente por legacy_id). */
	public static function import_surface( string $suffix, bool $dry_run = true, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) || ! self::legacy_present( $suffix ) ) {
			return $counts;
		}
		$legacy = self::legacy_table( $suffix );
		$mirror = self::mirror_table( $suffix );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$legacy} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		if ( ! $rows ) {
			return $counts;
		}
		foreach ( $rows as $row ) {
			$legacy_id = (int) ( $row['id'] ?? 0 );
			if ( $legacy_id <= 0 ) {
				continue;
			}
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$mirror} WHERE legacy_id = %d LIMIT 1", $legacy_id
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run && self::insert_mirror( $suffix, $row ) ) {
				$counts['imported']++;
			} elseif ( $dry_run ) {
				$counts['imported']++;
			}
		}
		return $counts;
	}

	/** Inserta una fila legacy en su espejo (origin='legacy', legacy_id preservado). */
	private static function insert_mirror( string $suffix, array $row ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$mirror = self::mirror_table( $suffix );
		$cols   = self::mirror_columns( $suffix );
		$data   = array();
		$fmt    = array();
		foreach ( $row as $key => $value ) {
			if ( $key === 'id' ) {
				$data['legacy_id'] = (int) $value;
				$fmt[] = '%d';
				continue;
			}
			if ( ! in_array( $key, $cols, true ) ) {
				continue;
			}
			$data[ $key ] = $value;
			$fmt[] = '%s';
		}
		$data['origin'] = 'legacy';
		$fmt[] = '%s';
		return false !== $wpdb->insert( $mirror, $data, $fmt );
	}

	/**
	 * Migra las 3 superficies espejo + los leads (a submissions). Dry-run default.
	 * @return array{leads:array, mirrors:array<string,array>}
	 */
	public static function import_all( bool $dry_run = true, int $limit = 100 ): array
	{
		$out = array( 'leads' => self::import( $dry_run, $limit ), 'mirrors' => array() );
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$surface = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
			$offset  = 0;
			do {
				$batch = self::import_surface( $suffix, $dry_run, $limit, $offset );
				$surface['imported'] += $batch['imported'];
				$surface['existing'] += $batch['existing'];
				$offset += $limit;
			} while ( $batch['imported'] > 0 || $offset < 100000 );
			$out['mirrors'][ $suffix ] = $surface;
		}
		return $out;
	}

	/** Rollback: elimina los leads adoptados + las filas espejo adoptadas. */
	public static function rollback_all(): int
	{
		global $wpdb;
		$total = self::rollback();
		if ( ! isset( $wpdb ) ) {
			return $total;
		}
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			if ( ! self::mirror_present( $suffix ) ) {
				continue;
			}
			$total += (int) $wpdb->query( 'DELETE FROM ' . self::mirror_table( $suffix ) . " WHERE origin = 'legacy'" );
		}
		return $total;
	}

	/** Fuente preferida: espejo v2 si tiene datos, sino legacy. */
	public static function source_table( string $suffix ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return '';
		}
		if ( self::mirror_present( $suffix ) && self::count_table( self::mirror_table( $suffix ) ) > 0 ) {
			return self::mirror_table( $suffix );
		}
		return self::legacy_present( $suffix ) ? self::legacy_table( $suffix ) : '';
	}

	/** Lee filas de la fuente preferida (espejo o legacy). */
	public static function rows( string $suffix, string $suffix_sql = 'ORDER BY id DESC LIMIT 50' ): array
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ' . $suffix_sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Cuenta filas de la fuente preferida. */
	public static function count( string $suffix ): int
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return self::count_table( $table );
	}
}
