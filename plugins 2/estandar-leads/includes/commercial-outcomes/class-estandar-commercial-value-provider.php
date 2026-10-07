<?php
/**
 * Commercial Outcomes — CommercialValueProvider contract (domain).
 *
 * The domain interface for resolving the Money value of a commercial stage.
 * Adapters depend on this contract, never on a concrete provider or on Meta.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Estandar_Commercial_Value_Provider {

	/**
	 * Resolve the commercial value for a lead reaching a stage.
	 *
	 * @param object|array                          $lead
	 * @param Estandar_Commercial_Stage|string      $stage
	 * @return Estandar_Commercial_Money|null  Null when no value is configured.
	 */
	public function value_for( $lead, $stage );
}
