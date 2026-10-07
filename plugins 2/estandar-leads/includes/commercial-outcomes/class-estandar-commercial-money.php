<?php
/**
 * Commercial Outcomes — Money value object (domain).
 *
 * Immutable amount + ISO currency. No infrastructure, no Meta, no WordPress.
 * Adapters consume Money; they do not define it.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Money {

	/** @var float */
	private $amount;
	/** @var string */
	private $currency;

	public function __construct( float $amount, string $currency = '' ) {
		$this->amount   = $amount;
		$this->currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) );
	}

	public function amount(): float {
		return $this->amount;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function has_currency(): bool {
		return $this->currency !== '';
	}
}
