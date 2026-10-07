<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signal ledger — canonical commercial signals (absorbs the Signal Core
 * capability). Own tables; idempotent via idempotency_key; never touches the
 * legacy es_signal_* tables.
 */
class SignalLedger
{
	const TABLE   = 'estandar_brain_signals';
	const QUEUE   = 'estandar_brain_signal_queue';
	const OPTION  = 'estandar_suite_brain_schema_version';
	const VERSION = '1.2.0';

	const EVENT_NAMES = array( 'lead_created', 'lead_qualified', 'meeting_booked', 'sale_closed', 'lead_rejected' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_name varchar(48) NOT NULL,
			event_time datetime NOT NULL,
			entity_type varchar(32) NOT NULL DEFAULT 'lead',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_uuid char(36) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			idempotency_key char(64) NOT NULL,
			value_decimal decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			payload_json longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_signal (idempotency_key),
			KEY event_name (event_name),
			KEY entity (entity_type, entity_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::QUEUE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			signal_id bigint(20) unsigned NOT NULL DEFAULT 0,
			queue_status varchar(24) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text,
			created_at datetime NOT NULL,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT '',
			next_run_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			exporters_json longtext,
			PRIMARY KEY  (id),
			KEY queue_status (queue_status),
			KEY signal_id (signal_id)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** PURE: deterministic idempotency key for a signal. */
	public static function idempotency_key( string $event_name, string $entity_type, int $entity_id, string $occurred_at ): string
	{
		return hash( 'sha256', $event_name . ':' . $entity_type . ':' . $entity_id . ':' . $occurred_at );
	}

	/** Record a canonical signal (idempotent). Returns signal id or 0. */
	public static function record( array $signal ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$name = strtolower( trim( (string) ( $signal['event_name'] ?? '' ) ) );
		if ( ! in_array( $name, self::EVENT_NAMES, true ) ) {
			return 0;
		}
		$entity_id   = (int) ( $signal['entity_id'] ?? 0 );
		$entity_type = sanitize_key( (string) ( $signal['entity_type'] ?? 'lead' ) );
		$occurred_at = (string) ( $signal['event_time'] ?? current_time( 'mysql' ) );
		// Explicit stable key (adoption/import) wins over the time-derived one.
		$key = isset( $signal['idempotency_key'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $signal['idempotency_key'] )
			? (string) $signal['idempotency_key']
			: self::idempotency_key( $name, $entity_type, $entity_id, $occurred_at );

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE idempotency_key = %s LIMIT 1", $key
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$extra_payload = isset( $signal['payload_json'] ) && is_array( $signal['payload_json'] )
			? (array) $signal['payload_json']
			: array();
		$payload = array_merge( array(
			'event_name'   => $name,
			'entity_type'  => $entity_type,
			'entity_id'    => $entity_id,
			'project_slug' => (string) ( $signal['project_slug'] ?? '' ),
		), $extra_payload );

		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'event_name'     => $name,
			'event_time'     => $occurred_at,
			'entity_type'    => $entity_type,
			'entity_id'      => $entity_id,
			'entity_uuid'    => (string) ( $signal['entity_uuid'] ?? '' ),
			'project_slug'   => sanitize_title( (string) ( $signal['project_slug'] ?? '' ) ),
			'idempotency_key' => $key,
			'value_decimal'  => isset( $signal['value'] ) && is_numeric( $signal['value'] ) ? (float) $signal['value'] : null,
			'currency'       => strtoupper( substr( (string) ( $signal['currency'] ?? '' ), 0, 8 ) ),
			'payload_json'   => wp_json_encode( $payload ),
			'created_at'     => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			$wpdb->insert( $wpdb->prefix . self::QUEUE, array(
				'signal_id' => $id, 'queue_status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			), array( '%d', '%s', '%s' ) );
			do_action( 'estandar_suite_signal_recorded', $signal );
		}
		return $id;
	}

	/** Consume the Capture contract: score the capture and record a signal. */
	public static function on_capture( array $capture ): void
	{
		$id   = (int) ( $capture['id'] ?? 0 );
		$uuid = (string) ( $capture['capture_uuid'] ?? '' );
		if ( $id <= 0 ) {
			return;
		}
		// QUALIFICATION_V1: deriva la cualificación del formulario para que el
		// signal de captura (calidad HIGH/MEDIUM/LOW del radar) coincida con el
		// score del lead. Antes se pasaba null → todas las capturas MEDIUM.
		$fields = array();
		if ( isset( $capture['fields'] ) && is_array( $capture['fields'] ) ) {
			$fields = $capture['fields'];
		} elseif ( ! empty( $capture['fields_json'] ) ) {
			$decoded = is_array( $capture['fields_json'] )
				? $capture['fields_json']
				: json_decode( (string) $capture['fields_json'], true );
			$fields = is_array( $decoded ) ? $decoded : array();
		}
		$qualification = null;
		$intent        = (string) ( $capture['intent'] ?? '' );
		if ( $fields && class_exists( __NAMESPACE__ . '\\LeadScorer' ) ) {
			$qualification = LeadScorer::qualification_from_fields( $fields );
			if ( $intent === '' ) {
				$intent = LeadScorer::intent_from_fields( $fields );
			}
		}

		$intent_points = IntentMap::derive( $intent );
		$capture_score = 0;
		if ( trim( (string) ( $capture['phone_normalized'] ?? '' ) ) !== '' ) { $capture_score += 50; }
		if ( trim( (string) ( $capture['email_normalized'] ?? '' ) ) !== '' ) { $capture_score += 30; }
		if ( trim( (string) ( $capture['name'] ?? '' ) ) !== '' )             { $capture_score += 20; }

		$score = Scoring::calculate( array(
			'capture' => $capture_score,
			'qualification' => $qualification,
			'behavior_events' => array(),
			'profile' => null,
			'intent' => $intent_points ? $intent_points['points'] : null,
		) );
		$identity     = self::resolve_lead_for_capture( $capture );
		$project_slug = (string) ( $capture['project_slug'] ?? '' );
		if ( $project_slug === '' && ! empty( $identity['project_slug'] ) ) {
			$project_slug = (string) $identity['project_slug'];
		}

		self::record( array(
			'event_name'  => 'lead_created',
			'entity_type' => 'capture',
			'entity_id'   => $id,
			'entity_uuid' => $uuid,
			'project_slug' => $project_slug,
			'event_time'  => (string) ( $capture['created_at'] ?? current_time( 'mysql' ) ),
			'payload_json' => array_merge(
				array( 'score' => $score['total'], 'temperature' => $score['temperature'] ),
				$identity,
				array( 'click_ids' => self::resolve_click_ids_for_capture( $capture ) )
			),
		) );
	}

	/**
	 * Click ids (gclid/wbraid/gbraid) de una captura, para la exportación OCI a
	 * Google. Los toma del propio evento, de su `click_ids_json` o de la tabla
	 * de submissions. Nunca lanza.
	 *
	 * @param array $capture Captura.
	 * @return array<string,string>
	 */
	public static function resolve_click_ids_for_capture( array $capture ): array
	{
		$ids = array();

		if ( isset( $capture['click_ids'] ) && is_array( $capture['click_ids'] ) ) {
			$ids = $capture['click_ids'];
		} elseif ( ! empty( $capture['click_ids_json'] ) ) {
			$decoded = is_array( $capture['click_ids_json'] ) ? $capture['click_ids_json'] : json_decode( (string) $capture['click_ids_json'], true );
			$ids     = is_array( $decoded ) ? $decoded : array();
		} else {
			global $wpdb;
			$id = (int) ( $capture['id'] ?? 0 );
			if ( isset( $wpdb ) && $id > 0 ) {
				$raw = $wpdb->get_var( $wpdb->prepare(
					"SELECT click_ids_json FROM {$wpdb->prefix}estandar_capture_submissions WHERE id = %d LIMIT 1",
					$id
				) );
				if ( is_string( $raw ) && $raw !== '' ) {
					$decoded = json_decode( $raw, true );
					$ids     = is_array( $decoded ) ? $decoded : array();
				}
			}
		}

		$out = array();
		foreach ( array( 'gclid', 'wbraid', 'gbraid' ) as $k ) {
			if ( ! empty( $ids[ $k ] ) && is_string( $ids[ $k ] ) ) {
				$out[ $k ] = (string) $ids[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Resuelve la identidad de match para CAPI a partir de un capture: lead_id,
	 * project_slug y datos (email_hash/phone_hash/fbc/fbp/external_id). Si no hay
	 * lead o tablas disponibles, devuelve array vacío (nunca lanza).
	 */
	public static function resolve_lead_for_capture( array $capture ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$email_hash = self::identity_hash( (string) ( $capture['email_normalized'] ?? '' ), 'email' );
		$phone_hash = self::identity_hash( (string) ( $capture['phone_normalized'] ?? '' ), 'phone' );
		if ( $email_hash === '' && $phone_hash === '' ) {
			return array();
		}

		$where = array();
		$args  = array();
		if ( $email_hash !== '' ) { $where[] = 'email_hash = %s'; $args[] = $email_hash; }
		if ( $phone_hash !== '' ) { $where[] = 'phone_hash = %s'; $args[] = $phone_hash; }

		$sub = $wpdb->get_row( $wpdb->prepare(
			"SELECT lead_id, email_hash, phone_hash, meta_fbc, meta_fbp, lead_uuid
			 FROM {$wpdb->prefix}es_lead_submissions
			 WHERE (" . implode( ' OR ', $where ) . ")
			 ORDER BY id DESC LIMIT 1",
			$args
		), ARRAY_A );

		$lead_id = $sub ? (int) $sub['lead_id'] : 0;
		$lead    = $lead_id > 0 ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id, project_slug, email_hash, phone_hash, first_meta_fbc, first_meta_fbp, lead_uuid
			 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		), ARRAY_A ) : null;

		return array(
			'lead_id'      => $lead_id,
			'project_slug' => (string) ( $lead['project_slug'] ?? '' ),
			'email_hash'   => (string) ( $lead['email_hash'] ?? ( $sub['email_hash'] ?? $email_hash ) ),
			'phone_hash'   => (string) ( $lead['phone_hash'] ?? ( $sub['phone_hash'] ?? $phone_hash ) ),
			'fbc'          => (string) ( $sub['meta_fbc'] ?? ( $lead['first_meta_fbc'] ?? '' ) ),
			'fbp'          => (string) ( $sub['meta_fbp'] ?? ( $lead['first_meta_fbp'] ?? '' ) ),
			'external_id'  => (string) ( $lead['lead_uuid'] ?? ( $sub['lead_uuid'] ?? '' ) ),
		);
	}

	/** Hash de identidad idéntico al CRM (HMAC con el secret del sitio). */
	public static function identity_hash( string $value, string $type ): string
	{
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' ) ) {
			$normalized = $type === 'email'
				? \Estandar\Core\Leads\CrmIngestion::normalize_email( $value )
				: \Estandar\Core\Leads\CrmIngestion::normalize_phone_storage( $value );
			return (string) \Estandar\Core\Leads\CrmIngestion::hash( $normalized );
		}
		$secret = (string) get_option( 'estandar_leads_secret', '' );
		return hash_hmac( 'sha256', strtolower( $value ), $secret );
	}

	/**
	 * Consume el feedback del operador y registra la señal de calidad resultante
	 * (lead_qualified / lead_rejected / meeting_booked / sale_closed). Idempotente
	 * por (evento, lead, timestamp).
	 */
	public static function on_feedback( $lead_id, $status, $quality, $valid, $reason = '' ): void
	{
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return;
		}
		$status  = strtoupper( trim( (string) $status ) );
		$quality = strtoupper( trim( (string) $quality ) );

		$event = '';
		if ( in_array( $status, array( 'CONVERTED', 'CLIENT' ), true ) ) {
			$event = 'sale_closed';
		} elseif ( $status === 'MEETING_BOOKED' ) {
			$event = 'meeting_booked';
		} elseif ( 0 === (int) $valid || 'INVALID' === $status || 'JUNK' === $quality || 'LOST' === $status ) {
			$event = 'lead_rejected';
		} elseif ( 'QUALIFIED' === $status || in_array( $quality, array( 'HOT', 'WARM' ), true ) ) {
			$event = 'lead_qualified';
		}
		if ( $event === '' ) {
			return;
		}

		global $wpdb;
		$project_slug = isset( $wpdb ) ? (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT project_slug FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) ) : '';

		self::record( array(
			'event_name'   => $event,
			'entity_type'  => 'lead',
			'entity_id'    => $lead_id,
			'project_slug' => $project_slug,
			'event_time'   => current_time( 'mysql' ),
			'payload_json' => array(
				'status'  => $status,
				'quality' => $quality,
				'valid'   => null === $valid ? null : (int) $valid,
				'reason'  => (string) $reason,
			),
		) );
	}

	/** Recent signals (read API). */
	public static function recent( int $limit = 50 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_time, entity_type, entity_id, project_slug, value_decimal, currency
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
