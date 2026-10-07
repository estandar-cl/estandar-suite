<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE rule evaluator for conditional integrations. A condition is:
 *
 *   ['logic' => 'and'|'or', 'rules' => [
 *       ['field' => 'pais', 'op' => 'eq', 'value' => 'Chile'],
 *       ['field' => 'monto', 'op' => 'gt', 'value' => 1000],
 *   ]]
 *
 * Supported ops: eq, neq, contains, in, not_in, empty, not_empty, gt, gte,
 * lt, lte, regex. Field lookup is case-insensitive (CF7/GF/v2 normalize keys).
 */
class Conditions
{
	const OPS = array( 'eq', 'neq', 'contains', 'in', 'not_in', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'regex' );

	/** Evaluate a condition against an entry. Empty condition → true. */
	public static function evaluate( array $condition, array $entry ): bool
	{
		$rules = (array) ( $condition['rules'] ?? array() );
		if ( ! $rules ) {
			return true;
		}
		$logic = (string) ( $condition['logic'] ?? 'and' );
		$logic = in_array( $logic, array( 'and', 'or' ), true ) ? $logic : 'and';

		$entry_lower = array();
		foreach ( $entry as $k => $v ) {
			$entry_lower[ strtolower( (string) $k ) ] = $v;
		}

		$results = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$results[] = self::rule( $rule, $entry, $entry_lower );
		}
		if ( ! $results ) {
			return true;
		}
		return $logic === 'and' ? ! in_array( false, $results, true ) : in_array( true, $results, true );
	}

	/** Evaluate a single rule. */
	private static function rule( array $rule, array $entry, array $entry_lower ): bool
	{
		$field = (string) ( $rule['field'] ?? '' );
		if ( $field === '' ) {
			return true;
		}
		$op    = (string) ( $rule['op'] ?? 'eq' );
		if ( ! in_array( $op, self::OPS, true ) ) {
			$op = 'eq';
		}
		$value = array_key_exists( $field, $entry ) ? $entry[ $field ] : ( $entry_lower[ strtolower( $field ) ] ?? null );

		switch ( $op ) {
			case 'empty':
				return self::is_empty( $value );
			case 'not_empty':
				return ! self::is_empty( $value );
			case 'contains':
				return ! self::is_empty( $value ) && strpos( (string) $value, (string) ( $rule['value'] ?? '' ) ) !== false;
			case 'in':
				return in_array( (string) $value, self::values( $rule['value'] ?? array() ), true );
			case 'not_in':
				return ! in_array( (string) $value, self::values( $rule['value'] ?? array() ), true );
			case 'regex':
				return (bool) @preg_match( (string) ( $rule['value'] ?? '' ), (string) $value );
			case 'gt':
				return is_numeric( $value ) && (float) $value > (float) ( $rule['value'] ?? 0 );
			case 'gte':
				return is_numeric( $value ) && (float) $value >= (float) ( $rule['value'] ?? 0 );
			case 'lt':
				return is_numeric( $value ) && (float) $value < (float) ( $rule['value'] ?? 0 );
			case 'lte':
				return is_numeric( $value ) && (float) $value <= (float) ( $rule['value'] ?? 0 );
			case 'eq':
				return strtolower( (string) $value ) === strtolower( (string) ( $rule['value'] ?? '' ) );
			case 'neq':
			default:
				return strtolower( (string) $value ) !== strtolower( (string) ( $rule['value'] ?? '' ) );
		}
	}

	private static function is_empty( $value ): bool
	{
		return $value === null || $value === '' || ( is_array( $value ) && ! $value );
	}

	private static function values( $value ): array
	{
		if ( is_array( $value ) ) {
			return array_map( 'strval', $value );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	/** PURE: sanitize a raw condition array into a safe shape. */
	public static function sanitize( array $raw ): array
	{
		$logic = (string) ( $raw['logic'] ?? 'and' );
		$logic = in_array( $logic, array( 'and', 'or' ), true ) ? $logic : 'and';
		$rules = array();
		foreach ( (array) ( $raw['rules'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || trim( (string) ( $r['field'] ?? '' ) ) === '' ) {
				continue;
			}
			$op = (string) ( $r['op'] ?? 'eq' );
			$rules[] = array(
				'field' => sanitize_text_field( (string) $r['field'] ),
				'op'    => in_array( $op, self::OPS, true ) ? $op : 'eq',
				'value' => (string) ( $r['value'] ?? '' ),
			);
		}
		return array( 'logic' => $logic, 'rules' => $rules );
	}
}
