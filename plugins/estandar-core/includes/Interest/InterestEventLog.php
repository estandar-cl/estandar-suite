<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestEventLog — registro idempotente de Interest Evolution Events.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-3/IEE-4, ADR-0044).
 *
 * Almacena cada evidencia de evolución de interés como un evento canónico,
 * con clave de idempotencia para evitar duplicados (event sourcing ligero).
 * El motor (InterestEngine) consume estos eventos como señales.
 *
 * Persistencia: tabla `es_interest_events` (aditiva, opcional). Si la tabla
 * no existe o falla la escritura, el registro se degrada sin romper el flujo.
 */
final class InterestEventLog
{
	const TABLE = 'es_interest_events';

	/** Prefijo de clave de idempotencia por scope (entity_type). */
	const IDEMPOTENCY_PREFIX = 'interest:v1|';

	/**
	 * ¿La tabla de eventos de interés existe?
	 *
	 * @return bool
	 */
	public static function table_exists(): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (bool) $exists;
	}

	/**
	 * Crea la tabla si no existe (aditiva; idempotente).
	 *
	 * @return void
	 */
	public static function ensure_table(): void
	{
		if ( self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table      = $wpdb->prefix . self::TABLE;
		$collate    = $wpdb->get_charset_collate();
		$schema_sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(40) NOT NULL DEFAULT 'lead',
			entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			signal_id VARCHAR(60) NOT NULL,
			channel VARCHAR(40) NOT NULL DEFAULT '',
			weight INT NOT NULL DEFAULT 0,
			occurred_at DATETIME NOT NULL,
			idempotency_key VARCHAR(90) NOT NULL,
			metadata_json TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_interest_idem (idempotency_key),
			KEY idx_interest_entity (entity_type, entity_id)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DDLNotAllowed
		dbDelta( $schema_sql );
	}

	/**
	 * Registra un evento de interés (idempotente).
	 *
	 * @param string $entity_type  'lead' | 'customer' | 'session' | futuro.
	 * @param int    $entity_id    id de la entidad (lead id, customer id…).
	 * @param string $signal_id    id de señal canónica (InterestSignals).
	 * @param array  $context      contexto adicional (channel, weight, occurred_at…).
	 * @return int id del evento insertado (0 si se omitió/duplicó/falló).
	 */
	public static function record( string $entity_type, int $entity_id, string $signal_id, array $context = array() ): int
	{
		if ( $entity_id < 1 || $signal_id === '' ) {
			return 0;
		}
		if ( ! self::table_exists() ) {
			return 0; // Tabla no creada aún: degradar sin romper.
		}

		$channel  = (string) ( $context['channel'] ?? '' );
		$weight   = (int) ( $context['weight'] ?? InterestSignals::weight( $signal_id ) );
		$occurred = (string) ( $context['occurred_at'] ?? current_time( 'mysql' ) );
		$scope    = (string) ( $context['idempotency_scope'] ?? '' ); // ej: submission:123, session:abc
		$idem_key = self::idempotency_key( $entity_type, $entity_id, $signal_id, $scope );

		if ( self::exists( $idem_key ) ) {
			return 0;
		}

		$metadata = array();
		foreach ( array( 'occurred_at', 'weight', 'channel', 'submission_id', 'session_id', 'source_plugin' ) as $k ) {
			if ( array_key_exists( $k, $context ) && $context[ $k ] !== null ) {
				$metadata[ $k ] = $context[ $k ];
			}
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'entity_type'     => sanitize_key( $entity_type ),
				'entity_id'       => absint( $entity_id ),
				'signal_id'       => sanitize_key( $signal_id ),
				'channel'         => sanitize_key( $channel ),
				'weight'          => $weight,
				'occurred_at'     => $occurred,
				'idempotency_key' => $idem_key,
				'metadata_json'   => $metadata ? wp_json_encode( $metadata ) : '',
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->insert_id;
		}
		return 0;
	}

	/**
	 * Señales de interés registradas para una entidad (ids canónicos).
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @param int    $limit
	 * @return array<int,string>
	 */
	public static function signal_ids( string $entity_type, int $entity_id, int $limit = 50 ): array
	{
		if ( $entity_id < 1 || ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT signal_id FROM {$wpdb->prefix}" . self::TABLE .
			' WHERE entity_type = %s AND entity_id = %d ORDER BY id DESC LIMIT %d',
			sanitize_key( $entity_type ),
			absint( $entity_id ),
			absint( $limit )
		) );
		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/** ¿Existe un evento con esa clave de idempotencia? */
	public static function exists( string $idempotency_key ): bool
	{
		if ( $idempotency_key === '' || ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . ' WHERE idempotency_key = %s LIMIT 1',
			$idempotency_key
		) );
		return (bool) $found;
	}

	/** Clave de idempotencia (scope por evento natural). */
	public static function idempotency_key( string $entity_type, int $entity_id, string $signal_id, string $scope = '' ): string
	{
		return self::IDEMPOTENCY_PREFIX . sanitize_key( $entity_type ) . '|' . absint( $entity_id ) . '|' .
			sanitize_key( $signal_id ) . ( $scope !== '' ? '|' . sanitize_key( $scope ) : '' );
	}
}
