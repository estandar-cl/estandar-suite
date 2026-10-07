<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Lead_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 10; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$lead_snapshot = $context['lead_snapshot'] ?? $draft->get_meta( 'lead_snapshot', [] );
		$lead = (array) ( $lead_snapshot['lead'] ?? [] );
		$submission = (array) ( $lead_snapshot['submission'] ?? [] );

		$profile_key = $context['profile_key'] ?? $draft->get_meta( 'profile_key', '' );
		if ( $profile_key === Estandar_Quotes_Acacios_Profile::KEY ) {
			$profile_defaults = Estandar_Quotes_Acacios_Profile::defaults( $lead_snapshot );
		} else {
			$profile_defaults = Estandar_Quotes_Real_Estate_Profile::defaults( $lead_snapshot );
		}

		$draft->merge( $profile_defaults );
		$draft->set_meta( 'profile_key', $profile_key );

		return $draft;
	}

	public function fields(): array {
		return [ 'client', 'project', 'property', 'pricing', 'uf_rate', 'financing', 'validity', 'issuer', 'conditions', 'editorial_blocks', 'capture_context' ];
	}

	public function overwrite_policy(): string {
		return 'merge_recursive';
	}

	public function supported_profiles(): array {
		return [];
	}
}
