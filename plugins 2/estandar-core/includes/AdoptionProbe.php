<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption probe — detects whether the LEGACY original plugins are present by
 * checking for their tables. Read-only. All products gate their adoption
 * features behind this probe so a clean install never touches legacy paths.
 */
class AdoptionProbe implements AdoptionProbeInterface
{
	const LEGACY_TABLES = array( 'es_leads', 'es_lead_submissions', 'es_lead_events', 'ea_sessions', 'ecb_conversations', 'es_quotes', 'es_customers', 'es_operators' );

	public static function probe_legacy(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		foreach ( self::LEGACY_TABLES as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) );
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	/** Pure: filter a table name against protected legacy prefixes. */
	public static function is_legacy_table( string $table ): bool
	{
		$table = strtolower( trim( $table ) );
		foreach ( Schema::protected_legacy_prefixes() as $prefix ) {
			if ( strpos( $table, $prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}
}
