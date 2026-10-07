<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Form_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 30; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$lead_snapshot = $context['lead_snapshot'] ?? $draft->get_meta( 'lead_snapshot', array() );
		$submission = (array) ( $lead_snapshot['submission'] ?? array() );
		$form = (array) ( $submission['form_fields'] ?? array() );

		if ( empty( $form ) ) {
			return $draft;
		}

		$profile_key = $context['profile_key'] ?? $draft->get_meta( 'profile_key', '' );
		$mappings = Estandar_Quotes_Field_Mapping_Registry::for_profile( $profile_key );

		foreach ( $mappings as $mapping ) {
			$this->apply_mapping( $draft, $form, $mapping );
		}

		$this->apply_images( $draft, $form, $profile_key );

		return $draft;
	}

	public function fields(): array {
		return array( 'client.tax_id', 'project.name', 'project.stage', 'property.*', 'pricing.total_uf', 'uf_rate.value_clp', 'uf_rate.effective_date' );
	}

	public function overwrite_policy(): string {
		return 'overwrite';
	}

	public function supported_profiles(): array {
		return array();
	}

	private function apply_mapping( Estandar_Quotes_Quote_Draft $draft, array $form, array $mapping ): void {
		$target = $mapping['target'] ?? '';
		$sources = (array) ( $mapping['sources'] ?? array() );
		$transform = $mapping['transform'] ?? 'text';
		$default = array_key_exists( 'default', $mapping ) ? $mapping['default'] : null;
		$condition = $mapping['condition'] ?? null;
		$overwrite = $mapping['overwrite'] ?? 'always';
		$validate = $mapping['validate'] ?? null;
		$profiles = (array) ( $mapping['profiles'] ?? array() );

		if ( $target === '' || empty( $sources ) ) {
			return;
		}

		if ( ! empty( $profiles ) ) {
			$current = $draft->get_meta( 'profile_key', '' );
			if ( ! in_array( $current, $profiles, true ) ) {
				return;
			}
		}

		$raw = self::first_available( $form, $sources, $default );

		if ( $raw === null ) {
			return;
		}

		if ( $condition !== null && ! Estandar_Quotes_Field_Transformer::evaluate_condition( $condition, $form ) ) {
			return;
		}

		$current_value = $draft->get( $target );
		switch ( $overwrite ) {
			case 'if_empty':
				if ( ! self::is_empty( $current_value ) ) { return; }
				break;
			case 'if_null':
				if ( $current_value !== null ) { return; }
				break;
			case 'never':
				return;
		}

		$value = Estandar_Quotes_Field_Transformer::transform( (string) $raw, $transform );
		if ( $value === null ) {
			return;
		}

		if ( $validate !== null && ! Estandar_Quotes_Field_Transformer::validate( $value, $validate ) ) {
			return;
		}

		$draft->set( $target, $value );
	}

	private function apply_images( Estandar_Quotes_Quote_Draft $draft, array $form, string $profile_key ): void {
		$raw = self::first_available( $form, array( 'imagenes', 'fotos', 'images', 'planos', 'asset_refs' ), null );
		if ( $raw === null || $raw === '' ) {
			return;
		}
		$allowed = array_flip( Estandar_Quotes_Document_Definitions::get( $profile_key )['assets'] ?? array() );
		$refs = array_map( 'trim', explode( ',', (string) $raw ) );
		$list = array();
		foreach ( $refs as $ref ) {
			$ref = sanitize_key( $ref );
			if ( isset( $allowed[ $ref ] ) ) {
				$list[] = array( 'kind' => 'floor_plan', 'asset_ref' => $ref );
			}
		}
		if ( ! empty( $list ) ) {
			$draft->set( 'property.images', $list );
		}
	}

	private static function first_available( array $form, array $sources, $default = null ) {
		foreach ( $sources as $key ) {
			if ( isset( $form[ $key ] ) && $form[ $key ] !== '' ) {
				return $form[ $key ];
			}
		}
		return $default;
	}

	private static function is_empty( $value ): bool {
		return $value === null || $value === '' || $value === array() || $value === false;
	}
}
