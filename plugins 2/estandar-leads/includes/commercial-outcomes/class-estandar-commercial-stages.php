<?php
/**
 * Commercial Outcomes — configurable CommercialStage registry.
 *
 * Single source of truth for which commercial stages count as exportable
 * outcomes. Configurable per installation (QUALIFIED_MODEL) via the option below;
 * when unset, returns the canonical defaults — IDENTICAL to
 * Estandar_Leads_Meta_Adapter::EXPORT_STATUSES, so an unconfigured install
 * behaves exactly as before (verified by tests/run-commercial-outcomes.php).
 *
 * Configuration may live in wp_options. No Meta logic here.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Stages {

	const OPTION = 'estandar_commercial_stages_v1';

	/** Canonical defaults — must match Meta_Adapter::EXPORT_STATUSES exactly. */
	const DEFAULTS = array(
		'QUALIFIED',
		'MEETING_BOOKED',
		'CONVERTED',
		'VISIT_SCHEDULED',
		'VISITED',
		'RESERVED',
		'CLIENT',
		'CLIENT_SIGNED',
		'DEED_SIGNED',
		'DELIVERED',
	);

	/**
	 * Active stage codes — configured override, or canonical defaults.
	 *
	 * @return string[]
	 */
	public function all(): array {
		$parsed = $this->parse( get_option( self::OPTION, '' ) );
		return empty( $parsed ) ? self::DEFAULTS : $parsed;
	}

	/**
	 * Active stages as CommercialStage value objects.
	 *
	 * @return Estandar_Commercial_Stage[]
	 */
	public function stages(): array {
		return array_map(
			static function ( $code ) {
				return new Estandar_Commercial_Stage( $code );
			},
			$this->all()
		);
	}

	/**
	 * @param Estandar_Commercial_Stage|string $stage
	 */
	public function is_exportable( $stage ): bool {
		$code = $stage instanceof Estandar_Commercial_Stage
			? $stage->code()
			: strtoupper( trim( (string) $stage ) );
		// Legacy alias: WON was never a distinct commercial stage — read as CONVERTED.
		if ( $code === 'WON' ) {
			$code = 'CONVERTED';
		}
		return in_array( $code, $this->all(), true );
	}

	public static function defaults(): array {
		return self::DEFAULTS;
	}

	// ── Internal ─────────────────────────────────────────────────────────────────

	private function parse( $raw ): array {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
			$list = preg_split( '/[\s,]+/', $raw );
		} else {
			return array();
		}
		$out = array();
		foreach ( (array) $list as $s ) {
			$s = strtoupper( trim( (string) $s ) );
			if ( $s !== '' ) {
				$out[] = $s;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
