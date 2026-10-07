<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Validation_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 60; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$document = $draft->to_array();
		$profile_key = $context['profile_key'] ?? $draft->get_meta( 'profile_key', '' );

		if ( empty( $document['client']['name'] ?? '' ) ) {
			$draft->add_warning( 'client.name', 'El nombre del cliente está vacío.' );
		}

		if ( empty( $document['pricing']['total_uf']['amount'] ?? null ) ) {
			$draft->add_warning( 'pricing.total_uf.amount', 'El valor UF de la propiedad no está definido.' );
		}

		if ( empty( $document['uf_rate']['value_clp'] ?? null ) ) {
			$draft->add_warning( 'uf_rate.value_clp', 'El valor de la UF en CLP no está definido.' );
		}

		if ( empty( $document['uf_rate']['effective_date'] ?? '' ) ) {
			$draft->add_warning( 'uf_rate.effective_date', 'La fecha de la UF no está definida.' );
		}

		if ( $profile_key !== '' ) {
			try {
				Estandar_Quotes_Profiles::validate_snapshot( $profile_key, $document );
			} catch ( InvalidArgumentException $e ) {
				$draft->add_error( 'general', $e->getMessage() );
			}
		}

		return $draft;
	}

	public function fields(): array {
		return [];
	}

	public function overwrite_policy(): string {
		return 'read_only';
	}

	public function supported_profiles(): array {
		return [];
	}
}
