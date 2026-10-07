<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Converts an immutable normalized revision snapshot into display-only data. */
class Estandar_Quotes_View_Model {
	/** Supported real estate profile keys share the same ViewModel contract. */
	private static function is_supported_profile( string $key ): bool {
		return in_array( $key, array( Estandar_Quotes_Real_Estate_Profile::KEY, Estandar_Quotes_Acacios_Profile::KEY ), true );
	}

	public static function from_document( string $profile_key, array $document, array $context = array() ): array {
		if ( ! self::is_supported_profile( $profile_key ) ) { throw new InvalidArgumentException( 'No document view model for profile.' ); }
		// Both real_estate@1 and acacios_real_estate@1 use the same normalizer contract.
		// We use the generic profile's normalizer as baseline; the Acacios profile normalizer
		// is a superset with the same output shape.
		$normalizer = ( $profile_key === Estandar_Quotes_Acacios_Profile::KEY )
			? array( 'Estandar_Quotes_Acacios_Profile', 'normalize' )
			: array( 'Estandar_Quotes_Real_Estate_Profile', 'normalize' );
		$document = call_user_func( $normalizer, $document );
		$money = static function ( array $value ): string { return ( $value['currency'] ?? '' ) === 'CLP' ? '$ ' . number_format_i18n( (float) $value['amount'], 0 ) : 'UF ' . number_format_i18n( (float) $value['amount'], 2 ); };
		return array( 'contract' => 'estandar_quote_view_model@1', 'profile_key' => $profile_key, 'quote_number' => sanitize_text_field( $context['quote_number'] ?? '' ), 'issued_at' => $document['validity']['issued_at'], 'expires_at' => $document['validity']['expires_at'], 'uf_rate' => '$ ' . number_format_i18n( (float) $document['uf_rate']['value_clp'], 2 ), 'client' => $document['client'], 'project' => $document['project'], 'property' => $document['property'], 'visibility' => $document['visibility'], 'conditions' => $document['conditions'], 'issuer' => $document['issuer'], 'editorial_blocks' => $document['editorial_blocks'], 'investment' => array( 'total_uf' => $money( $document['computed']['total_uf'] ), 'total_clp' => $money( $document['computed']['total_clp'] ) ), 'financing' => array_map( static function ( array $row ) use ( $money ): array { return array( 'years' => $row['term_years'], 'payment_uf' => $money( $row['monthly_payment_uf'] ), 'payment_clp' => $money( $row['monthly_payment_clp'] ), 'minimum_income' => $money( $row['minimum_income_clp'] ) ); }, $document['computed']['simulations'] ) );
	}
}
