<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Field_Transformer {

	public static function transform( string $raw, string $transformer ) {
		switch ( $transformer ) {
			case 'text':
				return sanitize_text_field( $raw );
			case 'chilean_float':
				return self::chilean_float( $raw );
			case 'chilean_bool':
				return self::chilean_bool( $raw );
			case 'int':
				return self::parse_int( $raw );
			case 'rut':
				return self::sanitize_rut( $raw );
			case 'date':
				return self::parse_date( $raw );
			default:
				return sanitize_text_field( $raw );
		}
	}

	public static function validate( $value, string $validator ): bool {
		switch ( $validator ) {
			case 'rut':
				return self::is_valid_rut( (string) $value );
			case 'positive':
				return is_numeric( $value ) && (float) $value > 0;
			case 'date':
				return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
			case 'not_empty':
				return $value !== null && $value !== '' && $value !== false;
			default:
				return true;
		}
	}

	private static function chilean_float( string $value ): ?float {
		$value = trim( $value );
		if ( $value === '' ) { return null; }
		if ( preg_match( '/^\d{1,3}(\.\d{3})*,\d+$/', $value ) ) {
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		} elseif ( preg_match( '/^\d+,\d+$/', $value ) ) {
			$value = str_replace( ',', '.', $value );
		}
		return is_numeric( $value ) ? (float) $value : null;
	}

	private static function chilean_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'si', 'sí', 'yes', '1', 'true', 'on' ), true );
	}

	private static function parse_int( string $value ): ?int {
		$value = trim( $value );
		if ( $value === '' ) { return null; }
		return is_numeric( $value ) ? (int) $value : null;
	}

	private static function parse_date( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		$ts = strtotime( $value );
		return $ts !== false ? gmdate( 'Y-m-d', $ts ) : null;
	}

	private static function sanitize_rut( string $value ): ?string {
		$rut = preg_replace( '/[^0-9kK-]/', '', $value );
		return $rut !== '' ? $rut : null;
	}

	public static function is_valid_rut( string $rut ): bool {
		$rut = preg_replace( '/[^0-9kK]/', '', $rut );
		if ( strlen( $rut ) < 2 ) { return false; }
		$digits = substr( $rut, 0, -1 );
		$check = strtoupper( substr( $rut, -1 ) );
		$sum = 0;
		$mul = 2;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$sum += (int) $digits[ $i ] * $mul;
			$mul = $mul < 7 ? $mul + 1 : 2;
		}
		$expected = 11 - ( $sum % 11 );
		if ( $expected === 11 ) { $expected = '0'; }
		elseif ( $expected === 10 ) { $expected = 'K'; }
		else { $expected = (string) $expected; }
		return strtoupper( $check ) === strtoupper( $expected );
	}

	public static function evaluate_condition( array $condition, array $form ): bool {
		$field = $condition['field'] ?? '';
		$operator = $condition['operator'] ?? 'not_empty';
		$value = $condition['value'] ?? null;
		$source = $form[ $field ] ?? null;

		switch ( $operator ) {
			case 'not_empty':
				return $source !== null && $source !== '';
			case 'empty':
				return $source === null || $source === '';
			case 'equals':
				return (string) $source === (string) $value;
			case 'not_equals':
				return (string) $source !== (string) $value;
			case 'in':
				return is_array( $value ) && in_array( (string) $source, $value, true );
			case 'matches':
				return is_string( $value ) && preg_match( $value, (string) $source );
			default:
				return true;
		}
	}
}
