<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Estandar_Quotes_Hydration_Stage {

	public function priority(): int;

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft;

	public function fields(): array;

	public function overwrite_policy(): string;

	public function supported_profiles(): array;
}
