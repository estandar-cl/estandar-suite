<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Hydration_Pipeline {

	private static array $stages = [];
	private static bool $sorted = false;

	public static function register( Estandar_Quotes_Hydration_Stage $stage ): void {
		self::$stages[] = $stage;
		self::$sorted = false;
	}

	public static function stages(): array {
		if ( ! self::$sorted ) {
			usort( self::$stages, fn( $a, $b ) => $a->priority() <=> $b->priority() );
			self::$sorted = true;
		}
		return self::$stages;
	}

	public static function reset(): void {
		self::$stages = [];
		self::$sorted = false;
	}

	public static function execute( Estandar_Quotes_Quote_Draft $draft, array $context = [] ): Estandar_Quotes_Quote_Draft {
		$result = $draft->clone();
		foreach ( self::stages() as $stage ) {
			$profile_key = $context['profile_key'] ?? $result->get_meta( 'profile_key', '' );
			$supported = $stage->supported_profiles();
			if ( ! empty( $supported ) && ! in_array( $profile_key, $supported, true ) ) {
				continue;
			}
			$result = $stage->apply( $result, $context );
		}
		return $result;
	}

	public static function hydrate_new( array $lead_snapshot, string $profile_key, array $settings = [] ): Estandar_Quotes_Quote_Draft {
		$draft = new Estandar_Quotes_Quote_Draft();
		$draft->set_meta( 'profile_key', $profile_key );
		$draft->set_meta( 'lead_snapshot', $lead_snapshot );
		$context = [
			'profile_key'   => $profile_key,
			'lead_snapshot'  => $lead_snapshot,
			'settings'      => $settings,
		];
		return self::execute( $draft, $context );
	}

	public static function hydrate_from_quote( array $quote, array $settings = [] ): Estandar_Quotes_Quote_Draft {
		$draft = Estandar_Quotes_Quote_Draft::from_quote_row( $quote );
		$lead_snapshot = json_decode( $quote['lead_snapshot_json'] ?? '{}', true ) ?: [];
		$context = [
			'profile_key'   => $quote['profile_key'] ?? '',
			'lead_snapshot'  => $lead_snapshot,
			'settings'      => $settings,
		];
		return self::execute( $draft, $context );
	}
}
