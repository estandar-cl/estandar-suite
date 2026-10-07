<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculator — aritmética PURE de la cotización: líneas, subtotal, descuento,
 * impuestos, total, conversión UF↔CLP y tabla de financiamiento.
 *
 * Sin BD, sin opciones, sin side-effects. Tolerante a datos faltantes: todo
 * campo ausente cae a un valor neutro (0 / array vacío) sin fatalizar.
 */
class Calculator
{
	/** Total de una línea: `qty * unit_price`. `qty` default 1, `unit_price` default 0. */
	public static function line_total( array $item ): float
	{
		$qty   = isset( $item['qty'] ) && is_numeric( $item['qty'] ) ? (float) $item['qty'] : 1.0;
		$price = 0.0;
		if ( isset( $item['unit_price'] ) && is_numeric( $item['unit_price'] ) ) {
			$price = (float) $item['unit_price'];
		} elseif ( isset( $item['amount'] ) && is_numeric( $item['amount'] ) ) {
			// Compatibilidad con el modelo legacy de `Quotes` (amount por línea).
			$price = (float) $item['amount'];
		}
		return $qty * $price;
	}

	/** Suma de los totales de línea. Sin ítems → 0.0. */
	public static function subtotal( array $items ): float
	{
		$sum = 0.0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$sum += self::line_total( $item );
			}
		}
		return $sum;
	}

	/**
	 * Monto de descuento. `mode` = `amount` (monto fijo) o `percent` (% del subtotal).
	 * Negativos → 0.
	 */
	public static function discount_amount( float $subtotal, $discount, string $mode = 'amount' ): float
	{
		$d = is_numeric( $discount ) ? (float) $discount : 0.0;
		if ( $d <= 0 ) {
			return 0.0;
		}
		if ( 'percent' === strtolower( trim( $mode ) ) ) {
			return $subtotal * ( $d / 100.0 );
		}
		return $d;
	}

	/**
	 * Impuesto sobre `$net`. `$tax_rate` en porcentaje (19) o fracción (0,19).
	 * Con `$tax_included = true`, `$net` ya incluye el impuesto y se despeja.
	 */
	public static function tax_amount( float $net, $tax_rate, bool $tax_included = false ): float
	{
		$rate = self::normalize_rate( $tax_rate );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		if ( $tax_included ) {
			return $net - ( $net / ( 1.0 + $rate ) );
		}
		return $net * $rate;
	}

	/**
	 * Compone los totales del documento.
	 *
	 * Opciones: `discount`, `discount_mode` (amount|percent), `tax_rate`,
	 * `tax_included` (bool) y `currency`.
	 *
	 * @return array{subtotal:float,discount:float,net:float,tax_rate:float,tax_amount:float,total:float,currency:string}
	 */
	public static function compose_totals( array $items, array $opts = array() ): array
	{
		$subtotal = self::subtotal( $items );

		$mode     = (string) ( $opts['discount_mode'] ?? 'amount' );
		$discount = self::discount_amount( $subtotal, $opts['discount'] ?? 0, $mode );

		$net          = $subtotal - $discount;
		$tax_rate_in  = $opts['tax_rate'] ?? 0;
		$tax_included = ! empty( $opts['tax_included'] );
		$tax_amount   = self::tax_amount( $net, $tax_rate_in, $tax_included );
		$total        = $tax_included ? $net : ( $net + $tax_amount );

		return array(
			'subtotal'    => $subtotal,
			'discount'    => $discount,
			'net'         => $net,
			'tax_rate'    => self::normalize_rate( $tax_rate_in ) * 100.0,
			'tax_amount'  => $tax_amount,
			'total'       => $total,
			'currency'    => (string) ( $opts['currency'] ?? 'CLP' ),
		);
	}

	/** Convierte UF a CLP. `$value_clp <= 0` → 0.0. */
	public static function uf_to_clp( float $uf, float $value_clp ): float
	{
		return $value_clp > 0 ? $uf * $value_clp : 0.0;
	}

	/** Convierte CLP a UF. `$value_clp <= 0` → 0.0. */
	public static function clp_to_uf( float $clp, float $value_clp ): float
	{
		return $value_clp > 0 ? $clp / $value_clp : 0.0;
	}

	/** Formatea un monto según moneda: CLP `$ 1.234.567`, UF `UF 1.234,56`, otros `1.234,56 XXX`. */
	public static function format_amount( float $amount, string $currency = 'CLP' ): string
	{
		$currency = strtoupper( trim( $currency ) );
		if ( 'CLP' === $currency ) {
			return '$ ' . number_format( $amount, 0, ',', '.' );
		}
		if ( 'UF' === $currency ) {
			return 'UF ' . number_format( $amount, 2, ',', '.' );
		}
		return number_format( $amount, 2, ',', '.' ) . ' ' . $currency;
	}

	/**
	 * Filas de la tabla de financiamiento (una por plazo).
	 *
	 * Fórmula de anualidad estándar; datos incompletos → `array()` (sin fatal).
	 *
	 * @return array<int,array{years:int,credit_uf:float,dividend_uf:float,dividend_clp:float,min_income_clp:float}>
	 */
	public static function financing_rows( array $financing, float $total_uf, float $uf_value_clp, float $property_value_uf = 0.0 ): array
	{
		$credit_percent = isset( $financing['credit_percent'] ) && is_numeric( $financing['credit_percent'] ) ? (float) $financing['credit_percent'] : 0.0;
		$annual_rate    = isset( $financing['annual_rate_percent'] ) && is_numeric( $financing['annual_rate_percent'] ) ? (float) $financing['annual_rate_percent'] : 0.0;
		$multiple       = isset( $financing['income_multiple'] ) && is_numeric( $financing['income_multiple'] ) ? (float) $financing['income_multiple'] : 0.0;

		// Base del crédito: "Valor de la propiedad (UF)" si viene; si no, el total
		// (retrocompatible con cotizaciones sin ese campo).
		$base_uf = $property_value_uf > 0 ? $property_value_uf : $total_uf;

		if ( $base_uf <= 0 || $credit_percent <= 0 ) {
			return array();
		}

		$principal = $base_uf * ( $credit_percent / 100.0 );
		$years_all = self::years_list( $financing['terms_years'] ?? null );
		if ( empty( $years_all ) ) {
			return array();
		}

		$rate_monthly = $annual_rate > 0 ? ( $annual_rate / 100.0 ) / 12.0 : 0.0;
		$rows         = array();

		foreach ( $years_all as $years ) {
			$months = max( 1, $years * 12 );
			if ( $rate_monthly > 0 ) {
				$dividend = $principal * $rate_monthly / ( 1.0 - pow( 1.0 + $rate_monthly, -$months ) );
			} else {
				$dividend = $principal / $months;
			}
			$dividend_clp = self::uf_to_clp( (float) $dividend, $uf_value_clp );
			$min_income   = $multiple > 0 ? $dividend_clp * $multiple : 0.0;

			$rows[] = array(
				'years'          => (int) $years,
				'credit_uf'      => round( $principal, 2 ),
				'dividend_uf'    => round( (float) $dividend, 2 ),
				'dividend_clp'   => round( $dividend_clp, 0 ),
				'min_income_clp' => round( $min_income, 0 ),
			);
		}

		return $rows;
	}

	// ── Helpers PURE ─────────────────────────────────────────────────────────

	/** Normaliza una tasa a fracción (19 → 0,19; 0,19 → 0,19). */
	private static function normalize_rate( $rate ): float
	{
		$r = is_numeric( $rate ) ? (float) $rate : 0.0;
		if ( $r > 1 ) {
			$r = $r / 100.0;
		}
		return $r;
	}

	/** Normaliza `terms_years` a lista de enteros positivos. */
	private static function years_list( $terms ): array
	{
		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( is_numeric( $t ) && (int) $t > 0 ) {
					$out[] = (int) $t;
				}
			}
		} elseif ( is_numeric( $terms ) && (int) $terms > 0 ) {
			$out[] = (int) $terms;
		}
		return $out;
	}
}
