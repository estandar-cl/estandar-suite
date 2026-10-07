<?php
/**
 * Commercial Outcomes — CommercialStage value object (domain).
 *
 * A normalized commercial stage code (e.g. RESERVED, VISITED). No infrastructure,
 * no Meta, no WordPress. Adapters consume stages; they do not define them.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Stage {

	/** @var string */
	private $code;

	public function __construct( string $code ) {
		$this->code = strtoupper( trim( $code ) );
	}

	public function code(): string {
		return $this->code;
	}

	public function is_valid(): bool {
		return $this->code !== '';
	}

	public function equals( Estandar_Commercial_Stage $other ): bool {
		return $this->code === $other->code();
	}

	public function __toString(): string {
		return $this->code;
	}
}
