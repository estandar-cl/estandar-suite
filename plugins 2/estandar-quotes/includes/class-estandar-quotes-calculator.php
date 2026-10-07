<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Calculator {
	public static function real_estate( array $document ): array {
		$price_uf = self::number( $document['pricing']['total_uf']['amount'] ?? null, 'total_uf' );
		$uf = $document['uf_rate']; $uf_value = self::number( $uf['value_clp'] ?? null, 'uf_rate.value_clp' );
		$finance = $document['financing']; $percentage = self::number( $finance['credit_percent'] ?? null, 'credit_percent' );
		if ( $percentage < 0 || $percentage > 100 ) { throw new InvalidArgumentException( 'credit_percent must be between 0 and 100.' ); }
		$annual = self::number( $finance['annual_rate_percent'] ?? null, 'annual_rate_percent' ); if ( $annual < 0 ) { throw new InvalidArgumentException( 'annual_rate_percent cannot be negative.' ); }
		$credit_uf = self::round( $price_uf * $percentage / 100, 2 ); $down_uf = self::round( $price_uf - $credit_uf, 2 );
		$simulations = array(); foreach ( (array) ( $finance['terms_years'] ?? array() ) as $term ) { $years = absint( $term ); if ( $years < 1 || $years > 40 ) { throw new InvalidArgumentException( 'Invalid financing term.' ); } $payment_uf = self::monthly_payment( $credit_uf, $annual, $years * 12 ); $payment_clp = self::round( $payment_uf * $uf_value, 0 ); $income = self::round( $payment_clp * self::number( $finance['income_multiple'] ?? 4, 'income_multiple' ), 0 ); $simulations[] = array( 'term_years' => $years, 'monthly_payment_uf' => self::money( 'UF', $payment_uf, 2, 'deterministic_calculation' ), 'monthly_payment_clp' => self::money( 'CLP', $payment_clp, 0, 'deterministic_calculation' ), 'minimum_income_clp' => self::money( 'CLP', $income, 0, 'deterministic_calculation' ) ); }
		return array( 'total_uf' => self::money( 'UF', $price_uf, 2, 'quote_snapshot' ), 'total_clp' => self::money( 'CLP', self::round( $price_uf * $uf_value, 0 ), 0, 'uf_conversion' ), 'credit_uf' => self::money( 'UF', $credit_uf, 2, 'deterministic_calculation' ), 'credit_clp' => self::money( 'CLP', self::round( $credit_uf * $uf_value, 0 ), 0, 'uf_conversion' ), 'down_payment_uf' => self::money( 'UF', $down_uf, 2, 'deterministic_calculation' ), 'down_payment_clp' => self::money( 'CLP', self::round( $down_uf * $uf_value, 0 ), 0, 'uf_conversion' ), 'simulations' => $simulations );
	}
	private static function monthly_payment( float $principal, float $annual_percent, int $months ): float { if ( $principal === 0.0 ) { return 0.0; } $rate = $annual_percent / 100 / 12; return self::round( $rate === 0.0 ? $principal / $months : $principal * $rate * pow( 1 + $rate, $months ) / ( pow( 1 + $rate, $months ) - 1 ), 2 ); }
	private static function money( string $currency, float $amount, int $precision, string $source ): array { return array( 'currency' => $currency, 'amount' => $amount, 'precision' => $precision, 'source' => $source ); }
	private static function number( $value, string $field ): float { if ( ! is_numeric( $value ) ) { throw new InvalidArgumentException( "Numeric value required: {$field}" ); } return (float) $value; }
	private static function round( float $value, int $precision ): float { $scale = pow( 10, $precision ); return floor( ( $value * $scale ) + 0.500001 ) / $scale; }
}
