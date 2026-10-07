<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Declarative rendering contract. It contains ordering/labels only, never business calculations. */
class Estandar_Quotes_Document_Definitions {
	/**
	 * Returns the document definition for a profile key. Profiles declare
	 * their own document assets via document_assets(); Document_Definitions
	 * discovers them without hardcoding profile lists or asset names.
	 */
	public static function get( string $profile_key ): array {
		$blocks = array();
		foreach ( array( 'client', 'property', 'investment', 'conditions', 'sales_room', 'financing', 'images' ) as $id ) {
			$blocks[] = array( 'id' => $id, 'type' => $id, 'version' => 1, 'enabled' => true );
		}

		$document_id = 'estandar_quote_document@1';
		$assets = self::discover_assets( $profile_key );

		$definition = array(
			'id'          => $document_id,
			'version'     => 1,
			'profile_key' => $profile_key,
			'blocks'      => $blocks,
			'assets'      => $assets,
		);

		$definition = apply_filters( 'estandar_quotes_document_definitions', array( $profile_key => $definition ) )[ $profile_key ] ?? null;
		if ( ! is_array( $definition ) ) {
			throw new InvalidArgumentException( 'Unknown document definition.' );
		}
		return $definition;
	}

	/** Discovers document assets from the profile class, with backward-compatible fallback. */
	private static function discover_assets( string $profile_key ): array {
		$profile_assets_map = array(
			Estandar_Quotes_Acacios_Profile::KEY   => array( 'Estandar_Quotes_Acacios_Profile', 'document_assets' ),
			Estandar_Quotes_Real_Estate_Profile::KEY => array( 'Estandar_Quotes_Real_Estate_Profile', 'document_assets' ),
		);
		if ( isset( $profile_assets_map[ $profile_key ] ) && is_callable( $profile_assets_map[ $profile_key ] ) ) {
			return (array) call_user_func( $profile_assets_map[ $profile_key ] );
		}
		// Fallback: the filter estandar_quotes_document_definitions allows third-party
		// profiles to inject their own definition with assets, which is consumed above.
		return array();
	}

	public static function validate( array $definition, string $profile_key ): array {
		$allowed = array( 'client', 'property', 'investment', 'conditions', 'sales_room', 'financing', 'images' );
		$valid_ids = array( 'estandar_quote_document@1', 'acacios_quote_document@1' );
		if ( ( $definition['profile_key'] ?? '' ) !== $profile_key
			|| ! in_array( ( $definition['id'] ?? '' ), $valid_ids, true )
			|| (int) ( $definition['version'] ?? 0 ) !== 1
		) {
			throw new InvalidArgumentException( 'Unsupported document definition or version.' );
		}
		$seen = array();
		foreach ( (array) ( $definition['blocks'] ?? array() ) as $block ) {
			if (
				! is_array( $block )
				|| array_diff( array_keys( $block ), array( 'id', 'type', 'version', 'enabled' ) )
				|| ! in_array( $block['id'] ?? '', $allowed, true )
				|| ( $block['type'] ?? '' ) !== ( $block['id'] ?? '' )
				|| (int) ( $block['version'] ?? 0 ) !== 1
				|| ! is_bool( $block['enabled'] ?? null )
				|| isset( $seen[ $block['id'] ] )
			) {
				throw new InvalidArgumentException( 'Invalid document block configuration.' );
			}
			$seen[ $block['id'] ] = true;
		}
		if ( array_keys( $seen ) !== $allowed ) {
			throw new InvalidArgumentException( 'Document block order or coverage is invalid.' );
		}
		return $definition;
	}
}
