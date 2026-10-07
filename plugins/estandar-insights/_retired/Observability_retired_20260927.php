<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observability — read-only structured state resolution (absorbs the
 * ads-feedback observability concept, product-safe). Never invents success:
 * absence of evidence renders UNKNOWN.
 */
class Observability
{
	const STATES = array( 'NOT_CONFIGURED', 'NOT_ELIGIBLE', 'QUEUED', 'SENT', 'ACCEPTED', 'UNKNOWN' );

	/** PURE: resolve an export state from execution evidence. */
	public static function resolve_state( array $evidence ): array
	{
		$state = 'UNKNOWN';
		$basis = 'none';
		if ( empty( $evidence['configured'] ) ) {
			$state = 'NOT_CONFIGURED';
			$basis = 'config';
		} elseif ( array_key_exists( 'eligible', $evidence ) && empty( $evidence['eligible'] ) ) {
			$state = 'NOT_ELIGIBLE'; // explicitly ineligible — not an assumption.
			$basis = 'policy';
		} elseif ( ! empty( $evidence['accepted'] ) ) {
			$state = 'ACCEPTED';
			$basis = 'api_acknowledgement';
		} elseif ( ! empty( $evidence['sent'] ) ) {
			$state = 'SENT';
			$basis = 'local_execution';
		} elseif ( ! empty( $evidence['queued'] ) ) {
			$state = 'QUEUED';
			$basis = 'local_execution';
		}
		return array( 'state' => $state, 'basis' => $basis );
	}

	/** PURE: honest markdown line for a state. */
	public static function render_line( array $resolved ): string
	{
		return sprintf( 'State: `%s` · basis: `%s`', $resolved['state'], $resolved['basis'] );
	}
}
