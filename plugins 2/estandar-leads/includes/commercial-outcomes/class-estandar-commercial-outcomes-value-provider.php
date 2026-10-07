<?php
/**
 * Commercial Outcomes — option-backed CommercialValueProvider implementation.
 *
 * Reads the per-installation value map from wp_options (configuration may live in
 * options; logs may not) and returns a Money domain object. Pure read, no Meta.
 *
 * Value map option (`estandar_commercial_values_v1`), one rule per line:
 *   STATUS=VALUE                 e.g.  RESERVED=15000
 *   project_slug|STATUS=VALUE    e.g.  parronales|RESERVED=20000   (takes precedence)
 *
 * Currency option (`estandar_commercial_currency`): ISO code, e.g. CLP.
 * Unconfigured → value_for() returns null (adapter omits value: no regression).
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Outcomes_Value_Provider implements Estandar_Commercial_Value_Provider {

	const OPTION_VALUES   = 'estandar_commercial_values_v1';
	const OPTION_CURRENCY = 'estandar_commercial_currency';

	public function value_for( $lead, $stage ) {
		$code = $stage instanceof Estandar_Commercial_Stage
			? $stage->code()
			: strtoupper( trim( (string) $stage ) );
		if ( $code === '' ) {
			return null;
		}

		$slug = strtolower( trim( (string) $this->field( $lead, 'project_slug' ) ) );
		$map  = $this->parse_map( get_option( self::OPTION_VALUES, '' ) );

		// Project-scoped rule takes precedence over the bare-status rule.
		foreach ( array( $slug . '|' . $code, $code ) as $key ) {
			if ( $key === '|' . $code ) {
				continue; // empty project slug — skip the scoped lookup
			}
			if ( isset( $map[ $key ] ) && is_numeric( $map[ $key ] ) ) {
				return new Estandar_Commercial_Money( (float) $map[ $key ], $this->currency() );
			}
		}
		return null;
	}

	private function currency(): string {
		return (string) get_option( self::OPTION_CURRENCY, '' );
	}

	private function parse_map( $raw ): array {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 || strpos( $line, '=' ) === false ) {
				continue;
			}
			list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( strpos( $key, '|' ) !== false ) {
				list( $slug, $status ) = array_map( 'trim', explode( '|', $key, 2 ) );
				$key = strtolower( $slug ) . '|' . strtoupper( $status );
			} else {
				$key = strtoupper( $key );
			}
			if ( $key !== '' ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private function field( $lead, $key ) {
		if ( is_array( $lead ) ) {
			return $lead[ $key ] ?? '';
		}
		if ( is_object( $lead ) ) {
			return $lead->$key ?? '';
		}
		return '';
	}
}
