<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Unit_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 25; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		return $draft;
	}

	public function fields(): array {
		return [ 'property.*', 'pricing.total_uf.amount' ];
	}

	public function overwrite_policy(): string {
		return 'overwrite';
	}

	public function supported_profiles(): array {
		return [];
	}
}
