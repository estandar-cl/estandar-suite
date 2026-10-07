<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Generic, tenant-agnostic real estate profile. It defines the same document
 * contract as acacios_real_estate@1 (client, project, property, pricing, UF
 * rate, financing, conditions, editorial blocks) but with neutral defaults
 * and no client-specific branding. This profile is the canonical default for
 * new installations; named profiles (acacios_real_estate@1, etc.) are versioned
 * aliases with client-specific defaults.
 */
class Estandar_Quotes_Real_Estate_Profile {
	const KEY = 'real_estate@1';

	public static function init(): void {
		add_filter( 'estandar_quotes_profiles', array( __CLASS__, 'register' ) );
	}

	public static function register( array $profiles ): array {
		$profiles[ self::KEY ] = array(
			'version'    => '1',
			'label'      => 'Real Estate',
			'schema'     => array(
				'required' => array(
					'document_version', 'client', 'project', 'property',
					'pricing', 'uf_rate', 'financing', 'validity',
					'issuer', 'conditions', 'editorial_blocks',
				),
			),
			'normalizer' => array( __CLASS__, 'normalize' ),
		);
		return $profiles;
	}

	/**
	 * Returns the declared editorial blocks. The document engine uses
	 * 'enabled' flags to decide whether a block is rendered; the definition
	 * file (Document_Definitions) is the source of truth for enabled/disabled.
	 * This profile only declares the valid block ids.
	 */
	public static function editorial_blocks(): array {
		return array(
			array( 'id' => 'client',      'version' => 1, 'type' => 'client' ),
			array( 'id' => 'property',    'version' => 1, 'type' => 'property' ),
			array( 'id' => 'investment',  'version' => 1, 'type' => 'investment' ),
			array( 'id' => 'conditions',  'version' => 1, 'type' => 'conditions' ),
			array( 'id' => 'sales_room',  'version' => 1, 'type' => 'sales_room' ),
			array( 'id' => 'financing',   'version' => 1, 'type' => 'financing' ),
			array( 'id' => 'images',      'version' => 1, 'type' => 'images' ),
		);
	}

	/** Declares valid asset references for this profile's document definition. */
	public static function document_assets(): array {
		return array( 'reference_plan_ground', 'reference_plan_upper' );
	}

	/**
	 * Tenant-agnostic defaults. All client-specific values (company name,
	 * sales room info, signature, conditions) come from Quotes Settings
	 * (wp_option estandar_quotes_settings) and are applied by the caller.
	 */
	public static function defaults( array $lead_snapshot = array() ): array {
		$lead       = (array) ( $lead_snapshot['lead'] ?? array() );
		$submission = (array) ( $lead_snapshot['submission'] ?? array() );
		return array(
			'document_version' => self::KEY,
			'client'           => array(
				'name'   => sanitize_text_field( $lead['name'] ?? '' ),
				'email'  => sanitize_email( $lead['email'] ?? '' ),
				'phone'  => sanitize_text_field( $lead['phone'] ?? '' ),
				'tax_id' => '',
			),
			'project'          => array(
				'name'       => '',
				'stage'      => '',
				'sales_room' => array(
					'name'    => '',
					'address' => '',
					'phone'   => '',
					'email'   => '',
					'hours'   => '',
				),
			),
			'property'         => array(
				'unit_code'      => '',
				'model'          => '',
				'orientation'    => '',
				'area_total_m2'  => null,
				'area_usable_m2' => null,
				'floors'         => null,
				'bedrooms'       => null,
				'bathrooms'      => null,
				'storage'        => false,
				'parking'        => false,
				'images'         => array(),
			),
			'pricing'          => array(
				'total_uf' => self::money( 'UF', 0, 2, 'inventory_snapshot' ),
			),
			'uf_rate'          => array(
				'currency'         => 'CLP',
				'value_clp'        => 38000,
				'precision'        => 2,
				'source'           => 'manual',
				'effective_date'   => gmdate( 'Y-m-d' ),
			),
			'financing'        => array(
				'credit_percent'      => 80,
				'annual_rate_percent' => 4.0,
				'terms_years'         => array( 5, 8, 10, 15, 20, 25, 30 ),
				'income_multiple'     => 4,
			),
			'validity'         => array(
				'issued_at'  => gmdate( 'Y-m-d' ),
				'days'       => 7,
				'expires_at' => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			),
			'issuer'           => array(
				'authority_name' => '',
				'authority_role' => '',
				'authority_id'   => '',
			),
			'conditions'       => array(
				'policy_version' => 'real_estate_conditions@1',
				'items'          => array(),
			),
			'editorial_blocks' => self::editorial_blocks(),
			'capture_context'  => array(
				'message'      => sanitize_textarea_field( $submission['message'] ?? '' ),
				'project_slug' => sanitize_title( $lead['project_slug'] ?? '' ),
				'raw_fields'   => (array) ( $submission['form_fields'] ?? array() ),
			),
		);
	}

	public static function normalize( array $document ): array {
		$base = self::defaults();
		$document = array_replace_recursive( $base, $document );
		$document['document_version'] = self::KEY;

		// Client
		$document['client']['name']  = sanitize_text_field( $document['client']['name'] );
		$document['client']['email'] = sanitize_email( $document['client']['email'] );
		$document['client']['phone'] = sanitize_text_field( $document['client']['phone'] );

		// Project
		foreach ( array( 'name', 'stage' ) as $field ) {
			$document['project'][ $field ] = sanitize_text_field( $document['project'][ $field ] );
		}

		// Property
		foreach ( array( 'unit_code', 'model', 'orientation' ) as $field ) {
			$document['property'][ $field ] = sanitize_text_field( $document['property'][ $field ] );
		}

		// Pricing
		self::assert_money( $document['pricing']['total_uf'], 'UF' );

		// UF rate
		if (
			strtoupper( (string) $document['uf_rate']['currency'] ) !== 'CLP'
			|| ! is_numeric( $document['uf_rate']['value_clp'] )
			|| empty( $document['uf_rate']['effective_date'] )
			|| empty( $document['uf_rate']['source'] )
		) {
			throw new InvalidArgumentException( 'UF rate requires CLP value, date and source.' );
		}

		// Validity
		$document['validity']['days'] = absint( $document['validity']['days'] );
		if ( $document['validity']['days'] < 1 ) {
			throw new InvalidArgumentException( 'Validity must be at least one day.' );
		}
		$issued = strtotime( (string) $document['validity']['issued_at'] );
		if ( ! $issued ) {
			throw new InvalidArgumentException( 'Invalid issued_at.' );
		}
		$document['validity']['expires_at'] = gmdate( 'Y-m-d', strtotime( '+' . $document['validity']['days'] . ' days', $issued ) );

		$document['computed']   = Estandar_Quotes_Calculator::real_estate( $document );
		$document['visibility'] = self::visibility( $document );

		return $document;
	}

	public static function visibility( array $document ): array {
		return array(
			'storage'    => ! empty( $document['property']['storage'] ),
			'parking'    => ! empty( $document['property']['parking'] ),
			'usable_area' => ! empty( $document['property']['area_usable_m2'] ),
			'images'     => ! empty( $document['property']['images'] ),
			'financing'  => ! empty( $document['financing']['terms_years'] ),
			'conditions' => ! empty( $document['conditions']['items'] ),
		);
	}

	private static function money( string $currency, $amount, int $precision, string $source ): array {
		return array(
			'currency'  => $currency,
			'amount'    => $amount,
			'precision' => $precision,
			'source'    => $source,
		);
	}

	private static function assert_money( array $money, string $currency ): void {
		if (
			strtoupper( (string) ( $money['currency'] ?? '' ) ) !== $currency
			|| ! is_numeric( $money['amount'] ?? null )
			|| ! isset( $money['precision'], $money['source'] )
		) {
			throw new InvalidArgumentException( 'Ambiguous monetary value.' );
		}
	}
}
