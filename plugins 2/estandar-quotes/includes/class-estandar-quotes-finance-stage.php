<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Finance_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 50; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$document = $draft->to_array();

		if ( ! isset( $document['validity']['issued_at'] ) ) {
			$draft->set( 'validity.issued_at', gmdate( 'Y-m-d' ) );
		}

		$issued_at = $draft->get( 'validity.issued_at' );
		$days = absint( $draft->get( 'validity.days' ) ?: 7 );
		$issued = strtotime( (string) $issued_at );
		if ( $issued ) {
			$draft->set( 'validity.expires_at', gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', $issued ) ) );
		}

		return $draft;
	}

	public function fields(): array {
		return [ 'validity.expires_at', 'validity.issued_at' ];
	}

	public function overwrite_policy(): string {
		return 'overwrite';
	}

	public function supported_profiles(): array {
		return [];
	}
}
