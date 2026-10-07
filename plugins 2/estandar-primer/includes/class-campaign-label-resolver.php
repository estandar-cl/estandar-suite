<?php
/**
 * Shared read-only campaign label resolver.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Campaign_Label_Resolver' ) ) :

class Estandar_Campaign_Label_Resolver {

	const OPTION = 'estandar_campaign_labels_v1';
	const TYPES  = array( 'campaigns', 'adsets', 'ads' );

	public static function resolve( string $type, $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		// Empty identifier: no label, no fake "(#)". Consumers render their own dash.
		if ( $value === '' ) {
			return '';
		}

		$type = self::normalize_type( $type );
		// Un-normalizable type: still never expose a raw identifier.
		if ( $type === '' ) {
			return self::fallback_label( '', $value );
		}

		$store = self::get_store();
		$entry = $store[ $type ][ $value ] ?? null;

		if ( is_string( $entry ) ) {
			$label = trim( sanitize_text_field( $entry ) );
		} elseif ( is_array( $entry ) ) {
			// Archived (active=false): treated as unmapped, never the raw id.
			if ( array_key_exists( 'active', $entry ) && empty( $entry['active'] ) ) {
				return self::fallback_label( $type, $value );
			}
			$label = trim( sanitize_text_field( (string) ( $entry['label'] ?? '' ) ) );
		} else {
			$label = '';
		}

		// CAMPAIGN_FALLBACK_FIX_V1: mapped -> human label; unmapped/inactive ->
		// legible fallback ("… no clasificada (#ID)"). Never the raw id.
		return $label !== '' ? $label : self::fallback_label( $type, $value );
	}

	/**
	 * Legible fallback for an identifier without an active mapping.
	 * Type-aware so an unmapped ad set is not mislabelled as "Campaña".
	 * Platform prefix (Meta/Google) deferred to V2 — the goal here is
	 * eliminating raw identifiers, not platform cosmetics.
	 */
	private static function fallback_label( string $type, string $value ): string {
		switch ( $type ) {
			case 'adsets':
				$template = 'Conjunto no clasificado (#%s)';
				break;
			case 'ads':
				$template = 'Anuncio no clasificado (#%s)';
				break;
			default:
				$template = 'Campaña no clasificada (#%s)';
				break;
		}

		return sprintf( $template, $value );
	}

	public static function campaign( $value ): string {
		return self::resolve( 'campaigns', $value );
	}

	public static function adset( $value ): string {
		return self::resolve( 'adsets', $value );
	}

	public static function ad( $value ): string {
		return self::resolve( 'ads', $value );
	}

	public static function get_store(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$store = array();

		foreach ( self::TYPES as $type ) {
			$store[ $type ] = is_array( $raw[ $type ] ?? null ) ? $raw[ $type ] : array();
		}

		return $store;
	}

	public static function save_store( array $store ): bool {
		$normalized = array();

		foreach ( self::TYPES as $type ) {
			$normalized[ $type ] = array();
			$entries = is_array( $store[ $type ] ?? null ) ? $store[ $type ] : array();

			foreach ( $entries as $id => $entry ) {
				$id = trim( sanitize_text_field( (string) $id ) );
				if ( $id === '' ) {
					continue;
				}

				$entry = is_array( $entry ) ? $entry : array( 'label' => (string) $entry );
				$normalized[ $type ][ $id ] = array(
					'label'    => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
					'platform' => sanitize_key( (string) ( $entry['platform'] ?? '' ) ),
					'project'  => sanitize_key( (string) ( $entry['project'] ?? '' ) ),
					'active'   => ! array_key_exists( 'active', $entry ) || ! empty( $entry['active'] ),
				);
			}
		}

		return update_option( self::OPTION, $normalized, false );
	}

	private static function normalize_type( string $type ): string {
		$aliases = array(
			'campaign'  => 'campaigns',
			'campaigns' => 'campaigns',
			'adset'     => 'adsets',
			'adsets'    => 'adsets',
			'ad'        => 'ads',
			'ads'       => 'ads',
		);

		$type = sanitize_key( $type );
		return $aliases[ $type ] ?? '';
	}
}

endif;
