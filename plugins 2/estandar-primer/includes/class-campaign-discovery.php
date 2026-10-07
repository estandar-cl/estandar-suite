<?php
/**
 * Automatic catalog of observed campaign, ad set and ad identifiers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Campaign_Discovery' ) ) :

class Estandar_Campaign_Discovery {

	const TABLE_NAME     = 'estandar_campaign_discovery';
	const SCHEMA_VERSION = '1.0';
	const VERSION_OPTION = 'estandar_campaign_discovery_version';
	const BACKFILL_OPTION = 'estandar_campaign_discovery_registry_backfill_v1';

	public static function init(): void {
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'record_ingested_lead' ), 30, 4 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_backfill_registry' ), 20 );
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT '',
			external_id varchar(255) NOT NULL DEFAULT '',
			project_slug varchar(120) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			lead_count bigint(20) unsigned NOT NULL DEFAULT 0,
			lead_ids longtext,
			campaign_name varchar(255) NOT NULL DEFAULT '',
			adset_name varchar(255) NOT NULL DEFAULT '',
			ad_name varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY discovery_key (type, external_id, project_slug),
			KEY type_last_seen (type, last_seen),
			KEY project_slug (project_slug)
		) {$charset};" );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function record_ingested_lead( $lead_id, $submission_id, $is_new_lead, $payload ): void {
		unset( $submission_id, $is_new_lead );

		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 || ! is_array( $payload ) ) {
			return;
		}

		$attribution = is_array( $payload['attribution'] ?? null ) ? $payload['attribution'] : array();
		$context     = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();
		$project     = sanitize_key( (string) ( $context['project_slug'] ?? '' ) );
		if ( $project === '' ) {
			global $wpdb;
			$project = sanitize_key( (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT project_slug FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
					$lead_id
				)
			) );
		}
		$observed_at = sanitize_text_field( (string) ( $payload['submitted_at'] ?? '' ) );
		$timestamp   = strtotime( $observed_at );
		$observed_at = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d H:i:s' );

		$values = array(
			'campaigns' => $attribution['utm_campaign'] ?? '',
			'adsets'    => $attribution['utm_term'] ?? '',
			'ads'       => $attribution['utm_content'] ?? '',
		);

		foreach ( $values as $type => $external_id ) {
			self::record( $type, $external_id, $project, $lead_id, $observed_at );
			self::maybe_auto_register( $type, $external_id, $project );
		}
	}

	public static function get_by_type( string $type ): array {
		global $wpdb;

		$type = sanitize_key( $type );
		if ( ! in_array( $type, Estandar_Campaign_Label_Resolver::TYPES, true ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT external_id, project_slug, first_seen, last_seen, lead_count
				 FROM ' . self::table() . '
				 WHERE type = %s
				 ORDER BY last_seen DESC, external_id ASC',
				$type
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function maybe_backfill_registry(): void {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		foreach ( Estandar_Campaign_Label_Resolver::TYPES as $type ) {
			foreach ( self::get_by_type( $type ) as $row ) {
				self::maybe_auto_register( $type, (string) ( $row['external_id'] ?? '' ), (string) ( $row['project_slug'] ?? '' ) );
			}
		}
		update_option( self::BACKFILL_OPTION, 1, false );
	}

	public static function maybe_auto_register( string $type, $external_id, string $project_slug = '' ): bool {
		$type        = sanitize_key( $type );
		$external_id  = trim( sanitize_text_field( (string) $external_id ) );
		$project_slug = sanitize_key( $project_slug );
		if ( $external_id === '' || ! in_array( $type, Estandar_Campaign_Label_Resolver::TYPES, true ) ) {
			return false;
		}

		$store = Estandar_Campaign_Label_Resolver::get_store();
		$entry = $store[ $type ][ $external_id ] ?? null;
		if ( is_array( $entry ) && trim( (string) ( $entry['label'] ?? '' ) ) !== '' && ! empty( $entry['active'] ) ) {
			return false;
		}

		$analytics = self::lookup_analytics_label( $type, $external_id );
		$label     = trim( (string) ( $analytics['label'] ?? '' ) );
		if ( $label === '' && ! self::looks_like_platform_id( $external_id ) ) {
			$label = $external_id;
		}
		if ( $label === '' ) {
			return false;
		}

		$store[ $type ][ $external_id ] = array(
			'label'    => $label,
			'platform' => sanitize_key( (string) ( $analytics['platform'] ?? '' ) ),
			'project'  => ! empty( $analytics['project'] ) ? sanitize_key( (string) $analytics['project'] ) : $project_slug,
			'active'   => true,
		);

		return Estandar_Campaign_Label_Resolver::save_store( $store );
	}

	private static function record( string $type, $external_id, string $project_slug, int $lead_id, string $observed_at ): void {
		global $wpdb;

		$external_id = trim( sanitize_text_field( (string) $external_id ) );
		if ( $external_id === '' ) {
			return;
		}

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, first_seen, last_seen, lead_ids FROM {$table}
				 WHERE type = %s AND external_id = %s AND project_slug = %s
				 LIMIT 1",
				$type,
				$external_id,
				$project_slug
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'type'         => $type,
					'external_id'  => $external_id,
					'project_slug' => $project_slug,
					'first_seen'   => $observed_at,
					'last_seen'    => $observed_at,
					'lead_count'   => 1,
					'lead_ids'     => wp_json_encode( array( $lead_id ) ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			return;
		}

		$lead_ids = json_decode( (string) ( $row['lead_ids'] ?? '' ), true );
		$lead_ids = is_array( $lead_ids ) ? array_map( 'absint', $lead_ids ) : array();
		if ( ! in_array( $lead_id, $lead_ids, true ) ) {
			$lead_ids[] = $lead_id;
		}

		$first_seen = (string) ( $row['first_seen'] ?? $observed_at );
		if ( $observed_at < $first_seen ) {
			$first_seen = $observed_at;
		}
		$last_seen = (string) ( $row['last_seen'] ?? $observed_at );
		if ( $observed_at > $last_seen ) {
			$last_seen = $observed_at;
		}

		$wpdb->update(
			$table,
			array(
				'first_seen' => $first_seen,
				'last_seen'  => $last_seen,
				'lead_count' => count( $lead_ids ),
				'lead_ids'   => wp_json_encode( array_values( $lead_ids ) ),
			),
			array( 'id' => absint( $row['id'] ) ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function lookup_analytics_label( string $type, string $external_id ): array {
		global $wpdb;

		$columns = array(
			'campaigns' => array( 'id' => 'campaign_id', 'label' => 'campaign_name' ),
			'adsets'    => array( 'id' => 'adset_id', 'label' => 'adset_name' ),
			'ads'       => array( 'id' => 'ad_id', 'label' => 'ad_name' ),
		);
		if ( ! isset( $columns[ $type ] ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'ea_ads_daily';
		$id    = $columns[ $type ]['id'];
		$label = $columns[ $type ]['label'];
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT channel AS platform, project_slug AS project, {$label} AS label
				 FROM {$table}
				 WHERE {$id} = %s AND {$label} != ''
				 ORDER BY stat_date DESC, id DESC
				 LIMIT 1",
				$external_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	private static function looks_like_platform_id( string $value ): bool {
		return $value !== '' && (bool) preg_match( '/^[0-9]+$/', $value );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}
}

endif;
