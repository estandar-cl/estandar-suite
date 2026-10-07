<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ANALYTICS_NORMALIZATION_V1 — canonical commercial stage taxonomy.
 *
 * Single source of truth for mapping lead statuses → commercial funnel stages.
 * Eliminates the 9+ duplicated hardcoded status lists across class-dashboard.php.
 *
 * Canonical stages (taxonomy):
 *   lead        — any lead created (no status filter)
 *   qualified   — operator has classified / engaged
 *   meeting     — meeting or visit scheduled
 *   reserved    — reservation committed (real estate: RESERVED)
 *   sold        — sale closed (real estate: SALE, DEED_SIGNED)
 *   lost        — definitively lost
 *
 * Resolution order (per stage):
 *   1. Filter 'estandar_analytics_commercial_stage_map'
 *   2. Per-installation wp_option 'estandar_analytics_stage_map'
 *   3. Generic defaults (vendor-neutral)
 *
 * No client-specific hardcodes. No Acacios, no AK, no Altacruz.
 */
class Estandar_Analytics_Commercial_Stage_Map {

	const OPTION = 'estandar_analytics_stage_map';

	/**
	 * Canonical defaults — generic, vendor-neutral.
	 * Installations customize via filter or wp_option.
	 */
	public static function defaults(): array {
		return array(
			'qualified'        => array( 'CONTACTED', 'QUALIFIED', 'PROPOSAL_SENT', 'NEGOTIATION', 'BANK_EVALUATION' ),
			'meeting'          => array( 'MEETING_BOOKED', 'MEETING', 'VISIT_SCHEDULED', 'VISITED' ),
			'reserved'         => array( 'RESERVED' ),
			'sold'             => array( 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE' ),
			'lost'             => array( 'LOST' ),
			'proposal_sent'    => array( 'PROPOSAL_SENT' ),
			'proposal_accepted'=> array( 'NEGOTIATION', 'BANK_EVALUATION' ),
		);
	}

	/**
	 * Resolved map: stage → statuses (after filter + option merge).
	 */
	public static function map(): array {
		static $map = null;
		if ( $map !== null ) { return $map; }

		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		// Merge stored over defaults (per-stage arrays are replaced, not merged per-status)
		$merged = $defaults;
		foreach ( $stored as $stage => $statuses ) {
			if ( is_array( $statuses ) && ! empty( $statuses ) ) {
				$merged[ $stage ] = array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );
			}
		}

		$map = apply_filters( 'estandar_analytics_commercial_stage_map', $merged );
		return is_array( $map ) ? $map : $defaults;
	}

	/**
	 * Get statuses for a given stage as a string for SQL IN clause.
	 * Returns e.g. "'CONTACTED','QUALIFIED','PROPOSAL_SENT'"
	 */
	public static function sql_list( string $stage ): string {
		$statuses = self::get_statuses( $stage );
		if ( empty( $statuses ) ) { return "''"; }
		return "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
	}

	/**
	 * Get statuses for a given stage as a flat array.
	 */
	public static function get_statuses( string $stage ): array {
		$map = self::map();
		return $map[ $stage ] ?? array();
	}

	/**
	 * Get the stage for a given status, or null if unknown.
	 */
	public static function get_stage( string $status ): ?string {
		$status = sanitize_key( $status );
		if ( $status === '' ) { return null; }
		foreach ( self::map() as $stage => $statuses ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $stage;
			}
		}
		return null;
	}

	/**
	 * Returns a diagnostic breakdown: status → count of unmapped leads.
	 * Used for reconciliation / system health.
	 */
	public static function unmapped_statuses(): array {
		global $wpdb;
		$leads_table = $wpdb->prefix . 'es_leads';
		if ( ! self::table_exists( $leads_table ) ) { return array(); }

		$all_statuses = $wpdb->get_col( "SELECT DISTINCT status FROM {$leads_table} WHERE status <> '' AND status IS NOT NULL" );
		if ( ! is_array( $all_statuses ) ) { return array(); }

		$unmapped = array();
		foreach ( $all_statuses as $status ) {
			if ( self::get_stage( (string) $status ) === null ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$leads_table} WHERE status = %s", $status
				) );
				if ( $count > 0 ) {
					$unmapped[ $status ] = $count;
				}
			}
		}
		return $unmapped;
	}

	/**
	 * Diagnostic: key metrics for reconciliation.
	 */
	public static function diagnostics(): array {
		global $wpdb;
		$leads_table = $wpdb->prefix . 'es_leads';
		if ( ! self::table_exists( $leads_table ) ) {
			return array( 'total' => 0, 'with_session' => 0, 'without_session' => 0,
				'reserved' => 0, 'sold' => 0, 'unmapped' => 0, 'unmapped_statuses' => array() );
		}

		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" );
		$with    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE first_session_id <> '' AND first_session_id IS NOT NULL" );
		$without = $total - $with;

		$reserved_sql = self::sql_list( 'reserved' );
		$sold_sql     = self::sql_list( 'sold' );

		$reserved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE status IN ({$reserved_sql})" );
		$sold     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE status IN ({$sold_sql})" );

		$unmapped_statuses = self::unmapped_statuses();
		$unmapped_count    = array_sum( $unmapped_statuses );

		return array(
			'total'              => $total,
			'with_session'       => $with,
			'without_session'    => $without,
			'reserved'           => $reserved,
			'sold'               => $sold,
			'unmapped'           => $unmapped_count,
			'unmapped_statuses'  => $unmapped_statuses,
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $result === $table;
	}
}
