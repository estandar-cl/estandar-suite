<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commercial pipeline — canonical stages, transitions and pipeline records
 * (absorbs the legacy status/sales_stage/journey capability). Own tables.
 * Implements the CommercialStageMap contract.
 */
class Pipeline
{
	const TABLE   = 'estandar_flow_pipeline';
	const OPTION  = 'estandar_suite_flow_schema_version';
	const VERSION = '1.0.2';

	/** Último motivo por el que una transición fue bloqueada (para UI/REST/CLI). */
	public static $last_error = '';

	const STAGES = array( 'NEW', 'CONTACTED', 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CLIENT', 'LOST', 'INVALID' );

	/** Commercial Journey — 15 canonical states (MS-1). */
	const JOURNEY_STAGES = array(
		'NEW', 'CAPTURED', 'CLASSIFIED', 'CONTACTED', 'ENGAGED',
		'QUALIFIED', 'MEETING', 'PROPOSAL', 'NEGOTIATING', 'CLOSED_WON',
		'POST_SALE', 'ACTIVE_CLIENT', 'CHURNING', 'RECOVERY', 'CHURNED',
		'LOST', 'INVALID',
	);

	const JOURNEY_LABELS = array(
		'NEW' => 'Nuevo', 'CAPTURED' => 'Capturado', 'CLASSIFIED' => 'Clasificado',
		'CONTACTED' => 'Contactado', 'ENGAGED' => 'Interactuando', 'QUALIFIED' => 'Calificado',
		'MEETING' => 'Reunión', 'PROPOSAL' => 'Propuesta', 'NEGOTIATING' => 'Negociación',
		'CLOSED_WON' => 'Ganado', 'POST_SALE' => 'Postventa', 'ACTIVE_CLIENT' => 'Cliente activo',
		'CHURNING' => 'Abandonando', 'RECOVERY' => 'Recuperación', 'CHURNED' => 'Perdido',
		'LOST' => 'Perdido', 'INVALID' => 'Descartado',
	);

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(32) NOT NULL DEFAULT 'lead',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_uuid char(36) NOT NULL DEFAULT '',
			stage varchar(32) NOT NULL DEFAULT 'NEW',
			journey_state varchar(32) NOT NULL DEFAULT 'NEW',
			priority varchar(12) NOT NULL DEFAULT '',
			temperature varchar(12) NOT NULL DEFAULT '',
			commercial_fit varchar(24) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			amount decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			assigned_operator varchar(190) NOT NULL DEFAULT '',
			origin_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			metadata_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entity (entity_type, entity_id),
			KEY stage (stage),
			KEY journey_state (journey_state),
			KEY priority (priority),
			KEY project (project_slug),
			KEY origin (origin_lead_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_flow_stage_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			from_stage varchar(32) NOT NULL DEFAULT '',
			to_stage varchar(32) NOT NULL DEFAULT '',
			reason text,
			operator varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pipeline_id (pipeline_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_flow_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			fingerprint char(64) NOT NULL,
			origin_customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY origin (origin_customer_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_flow_quotes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quote_uuid char(36) NOT NULL DEFAULT '',
			pipeline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(200) NOT NULL DEFAULT '',
			items_json longtext NULL,
			validity_days int NOT NULL DEFAULT 15,
			amount decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'draft',
			notes text NULL,
			origin_quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pipeline_id (pipeline_id),
			KEY origin (origin_quote_id),
			KEY lead_id (lead_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}estandar_flow_tasks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(190) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'open',
			due_at datetime DEFAULT NULL,
			assigned_operator varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pipeline_id (pipeline_id),
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

	/** Implements Core\CommercialStageMap. */
	public static function status_label( string $stage ): string
	{
		$labels = array(
			'NEW' => 'Nuevo', 'CONTACTED' => 'Contactado', 'QUALIFIED' => 'Calificado',
			'MEETING_BOOKED' => 'Reunión agendada', 'PROPOSAL_SENT' => 'Propuesta enviada',
			'NEGOTIATION' => 'Negociación', 'CLIENT' => 'Cliente', 'LOST' => 'Perdido', 'INVALID' => 'Descartado',
		);
		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : $stage;
	}

	public static function is_valid_stage( string $stage ): bool
	{
		return in_array( strtoupper( $stage ), self::STAGES, true );
	}

	/** Open a pipeline record from a capture (commercial continuity). */
	public static function on_capture( array $capture ): void
	{
		$id = (int) ( $capture['id'] ?? 0 );
		if ( $id <= 0 ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE entity_type = 'capture' AND entity_id = %d LIMIT 1", $id
		) );
		if ( $exists ) {
			return; // first-writer-wins.
		}
		$slug     = sanitize_title( (string) ( $capture['project_slug'] ?? '' ) );
		$operator = '';
		if ( $slug !== '' && class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) && method_exists( '\Estandar\Core\Leads\ProjectRegistry', 'operator_for' ) ) {
			$op       = \Estandar\Core\Leads\ProjectRegistry::operator_for( $slug );
			$operator = (string) ( $op['name'] ?? '' );
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'entity_type' => 'capture', 'entity_id' => $id,
			'entity_uuid' => (string) ( $capture['capture_uuid'] ?? '' ),
			'stage'       => 'NEW',
			'journey_state' => 'NEW',
			'project_slug' => $slug,
			'assigned_operator' => $operator,
			'origin_lead_id' => (int) ( $capture['origin_lead_id'] ?? 0 ),
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );

		// El registro nace en NEW; la captura es evidencia de CAPTURED. Se avanza
		// aquí (y no solo por señal) para no depender del orden de los hooks.
		$new_id = (int) $wpdb->insert_id;
		if ( $new_id > 0 ) {
			self::advance_to( $new_id, 'CAPTURED', 'captura registrada' );
		}
	}

	/** Transition a record stage (audited). Returns array{pipeline_id, stage} or []. */
	public static function transition( int $pipeline_id, string $to_stage, string $reason = '', string $operator = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_valid_stage( $to_stage ) ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, stage FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $pipeline_id
		), ARRAY_A );
		if ( ! is_array( $row ) || $row['stage'] === $to_stage ) {
			return array();
		}
		// El journey_state es CANÓNICO: no se copia el nombre legacy tal cual
		// (antes escribía p. ej. 'MEETING_BOOKED' en journey_state y el registro
		// quedaba atascado sin transiciones válidas).
		$journey = \Estandar\Flow\Journey\StateMachine::legacy_to_journey( $to_stage );
		$wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'stage' => $to_stage, 'journey_state' => $journey, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $pipeline_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->insert( $wpdb->prefix . 'estandar_flow_stage_events', array(
			'pipeline_id' => $pipeline_id, 'from_stage' => $row['stage'], 'to_stage' => $to_stage,
			'reason' => sanitize_textarea_field( $reason ), 'operator' => sanitize_text_field( $operator ),
			'created_at' => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
		return array( 'pipeline_id' => $pipeline_id, 'stage' => $to_stage, 'from' => $row['stage'] );
	}

	/** Read API: pipeline records by stage/project. */
	public static function list( string $stage = '', string $project = '', int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$where = array( '1=1' );
		$args  = array();
		if ( $stage !== '' ) { $where[] = 'stage = %s'; $args[] = strtoupper( $stage ); }
		if ( $project !== '' ) { $where[] = 'project_slug = %s'; $args[] = sanitize_title( $project ); }
		$where_sql = implode( ' AND ', $where );
		$args[] = max( 1, min( 500, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, entity_type, entity_id, entity_uuid, stage, journey_state, priority, temperature, commercial_fit, project_slug, amount, currency, assigned_operator, origin_lead_id, metadata_json, updated_at
			 FROM {$wpdb->prefix}" . self::TABLE . " WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d", $args
		), ARRAY_A );
	}

	/** Conteo por etapa (agregado en SQL; sin el tope de `list()`). */
	public static function counts_by_stage(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			'SELECT stage, COUNT(*) AS c FROM ' . $wpdb->prefix . self::TABLE . ' GROUP BY stage',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ strtoupper( (string) $r['stage'] ) ] = (int) $r['c'];
		}
		return $out;
	}

	/** Conteo por estado del journey (canónico). */
	public static function counts_by_journey(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			'SELECT journey_state, COUNT(*) AS c FROM ' . $wpdb->prefix . self::TABLE . ' GROUP BY journey_state',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ strtoupper( (string) $r['journey_state'] ) ] = (int) $r['c'];
		}
		return $out;
	}

	// ── Commercial Journey (MS-1) ───────────────────────────────────────────

	public static function is_valid_journey_state( string $state ): bool
	{
		return in_array( strtoupper( $state ), self::JOURNEY_STAGES, true );
	}

	public static function journey_label( string $state ): string
	{
		return self::JOURNEY_LABELS[ strtoupper( $state ) ] ?? $state;
	}

	public static function journey_transition( int $pipeline_id, string $to_state, string $reason = '', string $operator = '', array $metadata = array() ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		$to_state = strtoupper( $to_state );
		if ( ! self::is_valid_journey_state( $to_state ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, journey_state, stage, commercial_fit, assigned_operator, metadata_json
			 FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $pipeline_id
		), ARRAY_A );
		if ( ! is_array( $row ) || $row['journey_state'] === $to_state ) {
			return null;
		}
		$from = $row['journey_state'];

		$valid = \Estandar\Flow\Journey\StateMachine::validate_transition( $from, $to_state );
		if ( ! $valid ) {
			self::$last_error = "Transición no permitida: {$from} → {$to_state}.";
			return null;
		}

		// Invariantes del journey (operador antes de CONTACTED, assessment antes
		// de QUALIFIED, cotización antes de CLOSED_WON, …). Estaban definidas
		// pero NO se aplicaban.
		$blocked = \Estandar\Flow\Journey\StateMachine::validate_invariants( $row, $to_state );
		if ( null !== $blocked ) {
			self::$last_error = (string) $blocked;
			return null;
		}
		self::$last_error = '';

		$update = array(
			'journey_state' => $to_state,
			'stage'         => \Estandar\Flow\Journey\StateMachine::journey_to_legacy( $to_state ),
			'updated_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s' );
		if ( $metadata ) {
			$existing_meta = ! empty( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : array();
			$existing_meta = is_array( $existing_meta ) ? $existing_meta : array();
			$update['metadata_json'] = wp_json_encode( array_merge( $existing_meta, $metadata ) );
			$formats[] = '%s';
		}
		$wpdb->update( $wpdb->prefix . self::TABLE, $update, array( 'id' => $pipeline_id ), $formats, array( '%d' ) );

		$wpdb->insert( $wpdb->prefix . 'estandar_flow_stage_events', array(
			'pipeline_id' => $pipeline_id, 'from_stage' => $from, 'to_stage' => $to_state,
			'reason' => sanitize_textarea_field( $reason ), 'operator' => sanitize_text_field( $operator ),
			'created_at' => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

		do_action( 'estandar_suite_journey_transition', $pipeline_id, $from, $to_state, $reason );

		return array( 'pipeline_id' => $pipeline_id, 'journey_state' => $to_state, 'from' => $from );
	}

	// ── Puente señales → journey (operación real del CRM) ───────────────────

	/** PURE: journey destino que evidencia un evento canónico de señal. */
	public static function signal_target( string $event_name ): string
	{
		$map = array(
			'lead_created'   => 'CAPTURED',
			'lead_qualified' => 'QUALIFIED',
			'meeting_booked' => 'MEETING',
			'sale_closed'    => 'CLOSED_WON',
			'lead_rejected'  => 'INVALID',
			// M9: eventos del ciclo de vida de la cotización.
			'quote_issued'   => 'PROPOSAL',
			'quote_accepted' => 'CLOSED_WON',
			'quote_rejected' => 'LOST',
		);
		return $map[ strtolower( trim( $event_name ) ) ] ?? '';
	}

	/** PURE: camino más corto (BFS) entre dos estados del journey. */
	public static function path_to( string $from, string $to ): array
	{
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		if ( $from === $to ) {
			return array();
		}
		$queue = array( array( $from ) );
		$seen  = array( $from => true );
		while ( $queue ) {
			$path = array_shift( $queue );
			$last = end( $path );
			foreach ( \Estandar\Flow\Journey\StateMachine::allowed_transitions( (string) $last ) as $next ) {
				if ( isset( $seen[ $next ] ) ) {
					continue;
				}
				$new   = $path;
				$new[] = $next;
				if ( $next === $to ) {
					return array_slice( $new, 1 );
				}
				$seen[ $next ] = true;
				$queue[]       = $new;
			}
		}
		return array();
	}

	/** Resuelve el pipeline de una señal (por capture_id o por lead_id). */
	public static function resolve_pipeline_id( array $signal ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$type = sanitize_key( (string) ( $signal['entity_type'] ?? 'lead' ) );
		$id   = (int) ( $signal['entity_id'] ?? 0 );
		if ( $id <= 0 ) {
			return 0;
		}
		if ( 'capture' === $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE entity_type = 'capture' AND entity_id = %d LIMIT 1",
				$id
			) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE origin_lead_id = %d ORDER BY id ASC LIMIT 1",
			$id
		) );
	}

	/**
	 * Avanza el journey hasta $target recorriendo el camino válido (best-effort):
	 * aplica una transición por paso y se detiene en la primera que el grafo o un
	 * invariante no permita. Nunca deja el registro en un estado inválido.
	 *
	 * @return array{pipeline_id:int,steps:array,blocked:string,from:string,to:string}
	 */
	public static function advance_to( int $pipeline_id, string $target, string $reason = '', array $metadata = array() ): array
	{
		global $wpdb;
		$target = strtoupper( $target );
		if ( ! isset( $wpdb ) || $pipeline_id <= 0 || ! self::is_valid_journey_state( $target ) ) {
			return array( 'pipeline_id' => $pipeline_id, 'steps' => array(), 'blocked' => self::$last_error, 'from' => '', 'to' => '' );
		}
		$from = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT journey_state FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1",
			$pipeline_id
		) );
		if ( '' === $from ) {
			return array( 'pipeline_id' => $pipeline_id, 'steps' => array(), 'blocked' => '', 'from' => '', 'to' => '' );
		}

		self::$last_error = '';
		$steps = array();
		foreach ( self::path_to( $from, $target ) as $state ) {
			$r = self::journey_transition( $pipeline_id, $state, $reason, '', $metadata );
			if ( null === $r ) {
				break;
			}
			$steps[]  = $state;
			$metadata = array(); // el metadata se aplica una sola vez (primer paso).
		}
		return array(
			'pipeline_id' => $pipeline_id,
			'steps'       => $steps,
			'blocked'     => self::$last_error,
			'from'        => $from,
			'to'          => $steps ? end( $steps ) : $from,
		);
	}

	/**
	 * Consume `estandar_suite_signal_recorded`: mueve el journey según el evento
	 * canónico. Aditivo y best-effort: si no hay pipeline, o el grafo/invariante
	 * lo impide, no hace nada (nunca rompe el flujo de señales).
	 *
	 * @param mixed $signal Señal del ledger.
	 */
	public static function on_signal( $signal ): void
	{
		if ( ! is_array( $signal ) ) {
			return;
		}
		$event  = (string) ( $signal['event_name'] ?? '' );
		$target = self::signal_target( $event );
		if ( '' === $target ) {
			return;
		}
		$pid = self::resolve_pipeline_id( $signal );
		if ( $pid <= 0 ) {
			return;
		}
		self::advance_to( $pid, $target, 'señal ' . $event );
	}

	public static function set_priority( int $pipeline_id, string $priority ): bool	{
		global $wpdb;
		$priority = strtoupper( $priority );
		if ( ! isset( $wpdb ) || ! in_array( $priority, array( 'HIGH', 'MEDIUM', 'LOW' ), true ) ) {
			return false;
		}
		return (bool) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'priority' => $priority, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $pipeline_id ), array( '%s', '%s' ), array( '%d' ) );
	}

	public static function set_assessment( int $pipeline_id, string $fit, string $temperature ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'commercial_fit' => $fit, 'temperature' => $temperature, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $pipeline_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	public static function get( int $pipeline_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $pipeline_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function list_journey( string $state = '', int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$where = array( '1=1' );
		$args  = array();
		if ( $state !== '' ) { $where[] = 'journey_state = %s'; $args[] = strtoupper( $state ); }
		$where_sql = implode( ' AND ', $where );
		$args[] = max( 1, min( 500, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, entity_type, entity_id, entity_uuid, journey_state, priority, temperature, commercial_fit, project_slug, assigned_operator, metadata_json, updated_at
			 FROM {$wpdb->prefix}" . self::TABLE . " WHERE {$where_sql} ORDER BY
			 CASE priority WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END, updated_at DESC LIMIT %d", $args
		), ARRAY_A );
	}
}
