<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Settings_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 40; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$v = $context['settings'] ?? [];

		$draft->set( 'validity.days', max( 1, absint( $v['validity_days'] ?? 7 ) ) );
		$draft->set( 'uf_rate.source', sanitize_text_field( (string) ( $v['uf_source'] ?? 'manual' ) ) );

		$uf_value = $v['uf_value_clp'] ?? null;
		if ( is_numeric( $uf_value ) ) {
			$draft->set( 'uf_rate.value_clp', (float) $uf_value );
		}

		$annual = $v['annual_rate_percent'] ?? null;
		if ( is_numeric( $annual ) ) {
			$draft->set( 'financing.annual_rate_percent', (float) $annual );
		}

		$draft->set( 'financing.credit_percent', min( 100, max( 0, (float) ( $v['credit_percent'] ?? 80 ) ) ) );
		$draft->set( 'financing.income_multiple', max( 1, (float) ( $v['income_multiple'] ?? 4 ) ) );

		$terms = $v['terms_years'] ?? null;
		if ( is_string( $terms ) && $terms !== '' ) {
			$parsed = array_map( 'absint', explode( ',', $terms ) );
			$parsed = array_filter( $parsed, fn( $y ) => $y >= 1 && $y <= 40 );
			if ( ! empty( $parsed ) ) {
				$draft->set( 'financing.terms_years', array_values( $parsed ) );
			}
		}
		$draft->set( 'project.sales_room.name', sanitize_text_field( (string) ( $v['sales_room_name'] ?? '' ) ) );
		$draft->set( 'project.sales_room.address', sanitize_text_field( (string) ( $v['sales_room_address'] ?? '' ) ) );
		$draft->set( 'project.sales_room.phone', sanitize_text_field( (string) ( $v['sales_room_phone'] ?? '' ) ) );
		$draft->set( 'project.sales_room.email', sanitize_email( (string) ( $v['sales_room_email'] ?? '' ) ) );
		$draft->set( 'project.sales_room.hours', sanitize_text_field( (string) ( $v['sales_room_hours'] ?? '' ) ) );
		$draft->set( 'issuer.authority_name', sanitize_text_field( (string) ( $v['authority_name'] ?? '' ) ) );
		$draft->set( 'issuer.authority_role', sanitize_text_field( (string) ( $v['authority_role'] ?? '' ) ) );
		$draft->set( 'issuer.authority_id', sanitize_text_field( (string) ( $v['authority_id'] ?? '' ) ) );

		$profile_key = $context['profile_key'] ?? $draft->get_meta( 'profile_key', '' );
		$conditions = (string) ( $v['conditions'] ?? '' );
		if ( $conditions !== '' && $profile_key !== Estandar_Quotes_Acacios_Profile::KEY ) {
			$draft->set( 'conditions.items', preg_split( '/\r?\n/', $conditions ) );
		}

		return $draft;
	}

	public function fields(): array {
		return [ 'validity.days', 'uf_rate.*', 'financing.*', 'project.sales_room.*', 'issuer.*', 'conditions.items' ];
	}

	public function overwrite_policy(): string {
		return 'overwrite';
	}

	public function supported_profiles(): array {
		return [];
	}
}
