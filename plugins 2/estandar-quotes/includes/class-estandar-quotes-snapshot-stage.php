<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Snapshot_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 70; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$document = $draft->to_array();
		$profile_key = $context['profile_key'] ?? $draft->get_meta( 'profile_key', '' );

		try {
			$normalized = $this->normalize_document( $document, $profile_key );
			$draft->merge( $normalized );
			$draft->set_meta( 'snapshot_hash', Estandar_Quotes_Canonical_Json::hash( $normalized ) );
			$draft->set_meta( 'snapshot_json', Estandar_Quotes_Canonical_Json::encode( $normalized ) );
		} catch ( InvalidArgumentException $e ) {
			$draft->add_warning( 'snapshot', 'No se pudo generar el snapshot: ' . $e->getMessage() );
		}

		return $draft;
	}

	public function fields(): array {
		return [ 'computed', 'visibility' ];
	}

	public function overwrite_policy(): string {
		return 'overwrite';
	}

	public function supported_profiles(): array {
		return [];
	}

	private function normalize_document( array $document, string $profile_key ): array {
		if ( $profile_key === Estandar_Quotes_Acacios_Profile::KEY ) {
			$base = Estandar_Quotes_Acacios_Profile::defaults();
		} else {
			$base = Estandar_Quotes_Real_Estate_Profile::defaults();
		}
		$doc = array_replace_recursive( $base, $document );

		$doc['client']['name'] = sanitize_text_field( $doc['client']['name'] ?? '' );
		$doc['client']['email'] = sanitize_email( $doc['client']['email'] ?? '' );
		$doc['client']['phone'] = sanitize_text_field( $doc['client']['phone'] ?? '' );

		if ( $profile_key === Estandar_Quotes_Acacios_Profile::KEY ) {
			return Estandar_Quotes_Acacios_Profile::normalize( $doc );
		}
		return Estandar_Quotes_Real_Estate_Profile::normalize( $doc );
	}
}
