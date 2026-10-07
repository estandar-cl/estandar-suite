<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic rule engine (reusable). Config-driven, no business knowledge:
 * rules live in wp_option `estandar_suite_brain_rules` (buckets: global /
 * project / manual). Precedence: data_tier → priority → id; first-writer-wins.
 * Pure evaluate() is unit-testable.
 *
 * Operator parity with legacy engine (estandar-leads): field_exists,
 * field_missing, empty, not_empty, equals, not_equals, contains,
 * not_contains, starts_with, regex, in, gt, gte, lt, lte, min_length,
 * max_length. Regex/length were added 2026-08-04 so v2 can absorb legacy
 * configs that use them (adoption principle); invalid regex never matches.
 */
class Rules
{
	const OPTION = 'estandar_suite_brain_rules';

	/** PURE: classify the data-source tier of a normalized field. */
	public static function field_tier( string $field ): int
	{
		$field = strtolower( trim( $field ) );
		if ( $field === '' ) {
			return 3;
		}
		foreach ( array( 'content.', 'message' ) as $prefix ) {
			if ( strpos( $field, $prefix ) === 0 ) {
				return 4; // free text — fallback only.
			}
		}
		foreach ( array( 'fields.', 'contact.', 'scoring.', 'spam.', 'request.' ) as $prefix ) {
			if ( strpos( $field, $prefix ) === 0 ) {
				return 1;
			}
		}
		foreach ( array( 'context.', 'attribution.', 'chatbot.' ) as $prefix ) {
			if ( strpos( $field, $prefix ) === 0 ) {
				return 2;
			}
		}
		return 3;
	}

	/** PURE: normalize a rule into a comparable shape (null when unusable). */
	public static function normalize( array $rule ): ?array
	{
		$id = trim( (string) ( $rule['id'] ?? '' ) );
		if ( $id === '' ) {
			return null;
		}
		$conditions = array();
		foreach ( (array) ( $rule['conditions'] ?? array() ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$field = trim( (string) ( $c['field'] ?? '' ) );
			$op    = strtolower( trim( (string) ( $c['op'] ?? $c['operator'] ?? '' ) ) );
			if ( $field === '' || $op === '' ) {
				continue;
			}
			$conditions[] = array( 'field' => $field, 'op' => $op, 'value' => $c['value'] ?? '' );
		}
		$tier = 3;
		foreach ( $conditions as $c ) {
			$tier = max( $tier, self::field_tier( $c['field'] ) );
		}
		return array(
			'id'         => $id,
			'name'       => trim( (string) ( $rule['name'] ?? $id ) ),
			'enabled'    => ! array_key_exists( 'enabled', $rule ) || ! empty( $rule['enabled'] ),
			'priority'   => isset( $rule['priority'] ) ? (int) $rule['priority'] : 10,
			'source'     => strtolower( trim( (string) ( $rule['source'] ?? 'global' ) ) ),
			'match'      => strtolower( (string) ( $rule['match'] ?? 'all' ) ) === 'any' ? 'any' : 'all',
			'conditions' => $conditions,
			'action'     => (array) ( $rule['action'] ?? array() ),
			'data_tier'  => $tier,
		);
	}

	/** PURE: deterministic sort (tier → priority → id). */
	public static function sort( array $rules ): array
	{
		usort( $rules, static function ( $a, $b ) {
			$ta = (int) $a['data_tier'];
			$tb = (int) $b['data_tier'];
			if ( $ta !== $tb ) {
				return $ta <=> $tb;
			}
			if ( (int) $a['priority'] !== (int) $b['priority'] ) {
				return (int) $a['priority'] <=> (int) $b['priority'];
			}
			return strcmp( (string) $a['id'], (string) $b['id'] );
		} );
		return $rules;
	}

	/** PURE: does a rule match the input map? */
	public static function matches( array $rule, array $input ): bool
	{
		if ( empty( $rule['conditions'] ) ) {
			return false;
		}
		$any = $rule['match'] === 'any';
		foreach ( $rule['conditions'] as $c ) {
			if ( $any && self::condition( $c, $input ) ) {
				return true;
			}
			if ( ! $any && ! self::condition( $c, $input ) ) {
				return false;
			}
		}
		return ! $any;
	}

	/** PURE: resolve dotted field path from nested input (e.g. fields.situacion_empresa). */
	private static function field_value( string $path, array $input )
	{
		if ( $path === '' ) {
			return null;
		}
		if ( strpos( $path, '.' ) === false ) {
			return array_key_exists( $path, $input ) ? $input[ $path ] : null;
		}
		$parts = explode( '.', $path );
		$value = $input;
		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/** PURE: single condition evaluation. */
	public static function condition( array $c, array $input ): bool
	{
		$field  = (string) $c['field'];
		$op     = (string) $c['op'];
		$exp    = $c['value'] ?? '';
		$actual = self::field_value( $field, $input );
		$exists = $actual !== null;
		$actual_s = self::comparable( $actual );

		switch ( $op ) {
			case 'field_exists':  return $exists && trim( (string) $actual ) !== '';
			case 'field_missing':
			case 'empty':         return ! $exists || trim( (string) $actual ) === '';
			case 'not_empty':     return $exists && trim( (string) $actual ) !== '';
			case 'equals':        return $actual_s === self::comparable( $exp );
			case 'not_equals':    return $actual_s !== self::comparable( $exp );
			case 'contains':
				$needle = self::comparable( $exp );
				return $needle !== '' && strpos( $actual_s, $needle ) !== false;
			case 'not_contains':
				$needle = self::comparable( $exp );
				return $needle === '' || strpos( $actual_s, $needle ) === false;
			case 'starts_with':
				$needle = self::comparable( $exp );
				return $needle !== '' && strpos( $actual_s, $needle ) === 0;
			case 'gt':            return is_numeric( $actual ) && (float) $actual > (float) $exp;
			case 'gte':           return is_numeric( $actual ) && (float) $actual >= (float) $exp;
			case 'lt':            return is_numeric( $actual ) && (float) $actual < (float) $exp;
			case 'lte':           return is_numeric( $actual ) && (float) $actual <= (float) $exp;
			case 'in':            return in_array( $actual_s, array_map( array( __CLASS__, 'comparable' ), array_filter( explode( ',', (string) $exp ) ) ), true );
			case 'regex':
				$pattern = (string) $exp;
				if ( $pattern === '' || @preg_match( $pattern, '' ) === false ) {
					return false; // invalid pattern never matches — deterministic, no notices.
				}
				return (bool) preg_match( $pattern, (string) ( $actual ?? '' ) );
			case 'min_length':
				return mb_strlen( trim( (string) ( $actual ?? '' ) ) ) >= (int) $exp;
			case 'max_length':
				return mb_strlen( trim( (string) ( $actual ?? '' ) ) ) <= (int) $exp;
		}
		return false;
	}

	/** PURE: normalized comparable string (trim, lowercase, accent-free). */
	public static function comparable( $value ): string
	{
		$value = trim( (string) ( $value ?? '' ) );
		$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * PURE: evaluate rules → merged action (first-writer-wins; stop halts).
	 *
	 * @return array { matched:[ids], sources:[id=>source], action:[...] }
	 */
	public static function evaluate( array $input, array $rules ): array
	{
		$matched = array();
		$sources = array();
		$action  = array();
		foreach ( self::sort( array_filter( $rules, static function ( $r ) {
			return ! empty( $r['enabled'] );
		} ) ) as $rule ) {
			if ( ! self::matches( $rule, $input ) ) {
				continue;
			}
			$matched[] = $rule['id'];
			$sources[ $rule['id'] ] = $rule['source'];
			foreach ( (array) $rule['action'] as $k => $v ) {
				if ( $k === 'stop' ) {
					continue;
				}
				if ( ! array_key_exists( $k, $action ) ) {
					$action[ $k ] = $v;
				}
			}
			if ( ! empty( $rule['action']['stop'] ) ) {
				break;
			}
		}
		return array( 'matched' => $matched, 'sources' => $sources, 'action' => $action );
	}

	/** Load normalized enabled rules (global + project buckets from option). */
	public static function load( string $project = '' ): array
	{
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rules  = array();
		foreach ( array( 'global', 'manual' ) as $bucket ) {
			foreach ( (array) ( $stored[ $bucket ] ?? array() ) as $r ) {
				if ( is_array( $r ) ) {
					$rules[] = $r;
				}
			}
		}
		if ( $project !== '' && is_array( $stored[ $project ] ?? null ) ) {
			foreach ( $stored[ $project ] as $r ) {
				if ( is_array( $r ) ) {
					$rules[] = $r;
				}
			}
		}
		$normalized = array();
		foreach ( $rules as $r ) {
			$n = self::normalize( $r );
			if ( $n !== null ) {
				$normalized[] = $n;
			}
		}
		return $normalized;
	}
}
