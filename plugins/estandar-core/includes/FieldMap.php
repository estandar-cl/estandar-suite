<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field mapping primitives — pure, unit-testable, transport-agnostic.
 * Auto-maps form fields to destination columns by label/id similarity,
 * serializes complex values with a defined format, and builds ordered rows
 * (headers + optional system fields).
 */
class FieldMap
{
	/** System fields every adapter may append (opt-in per integration). */
	const SYSTEM_FIELDS = array( 'entry_id', 'form_id', 'created_at', 'source_url', 'user_ip', 'integration_status' );

	/** PURE: accent/space-insensitive token key for matching. */
	public static function key( string $s ): string
	{
		$s = strtolower( trim( $s ) );
		$s = strtr( $s, array(
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
			'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i',
			'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
		) );
		return preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/** PURE: similarity 0..100 between a form field and a column header. */
	public static function score( string $field, string $header ): int
	{
		$fk = self::key( $field );
		$hk = self::key( $header );
		if ( $fk === '' || $hk === '' ) {
			return 0;
		}
		if ( $fk === $hk ) {
			return 100;
		}
		// token overlap
		$ft = array_filter( explode( ' ', str_replace( array( '_', '-', '.' ), ' ', strtolower( $field ) ) ) );
		$ht = array_filter( explode( ' ', str_replace( array( '_', '-', '.' ), ' ', strtolower( $header ) ) ) );
		$hit = 0;
		foreach ( $ft as $t ) {
			if ( in_array( $t, $ht, true ) || in_array( $t, array( 'your', 'field' ), true ) ) {
				$hit++;
			}
		}
		if ( $hit > 0 ) {
			return 20 + min( 70, 10 * $hit );
		}
		// substring
		if ( $fk !== '' && ( strpos( $hk, $fk ) !== false || strpos( $fk, $hk ) !== false ) ) {
			return 40;
		}
		return 0;
	}

	/**
	 * PURE: best column for each form field.
	 *
	 * @param array $form_fields [field_name => label]
	 * @param array $headers     destination columns in order
	 * @return array field_name => column ('' when no match)
	 */
	public static function auto_map( array $form_fields, array $headers ): array
	{
		$headers = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', (array) $headers ) ) ) ) );
		$map     = array();
		foreach ( $form_fields as $field => $label ) {
			$best = '';
			$best_score = 0;
			foreach ( $headers as $header ) {
				$s = self::score( (string) $label, $header );
				if ( $s > $best_score ) {
					$best_score = $s;
					$best       = $header;
				}
			}
			$map[ $field ] = $best_score >= 20 ? $best : '';
		}
		return $map;
	}

	/** PURE: defined serialization of a value for a spreadsheet cell. */
	public static function serialize_value( $value ): string
	{
		if ( $value === null ) {
			return '';
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $v ) {
				if ( is_array( $v ) ) {
					$flat[] = (string) ( $v['url'] ?? $v['value'] ?? ( isset( $v['name'] ) ? $v['name'] : '' ) );
				} elseif ( $v !== null ) {
					$flat[] = (string) $v;
				}
			}
			$flat = array_values( array_filter( $flat, static function ( $x ) {
				return $x !== '';
			} ) );
			return $flat ? implode( '|', $flat ) : '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return (string) $value;
	}

	/**
	 * PURE: build an ordered row for the destination headers.
	 *
	 * @param array $entry         raw entry (form fields keyed by field name)
	 * @param array $headers       destination columns in order
	 * @param array $mapping       field_name => column (columns not present are ignored)
	 * @param array $system_fields enabled system fields (subset of SYSTEM_FIELDS)
	 * @param array $meta          {entry_id, form_id, created_at, source_url, user_ip, integration_status}
	 * @return array values ordered by $headers
	 */
	public static function build_row( array $entry, array $headers, array $mapping, array $system_fields, array $meta = array() ): array
	{
		$headers = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $headers ) ) ) );
		// Case-insensitive key lookup (CF7/GF/v2 normalization can differ in case).
		$entry_lower = array();
		foreach ( $entry as $k => $v ) {
			$entry_lower[ strtolower( (string) $k ) ] = $v;
		}
		$by_col = array();
		foreach ( $mapping as $field => $column ) {
			$column = trim( (string) $column );
			if ( $column === '' ) {
				continue;
			}
			$value = isset( $entry[ $field ] ) ? $entry[ $field ] : ( $entry_lower[ strtolower( (string) $field ) ] ?? null );
			if ( $value !== null ) {
				$by_col[ $column ] = self::serialize_value( $value );
			}
		}
		$row = array();
		foreach ( $headers as $h ) {
			if ( in_array( $h, $system_fields, true ) ) {
				$row[] = (string) ( $meta[ $h ] ?? '' );
			} else {
				$row[] = isset( $by_col[ $h ] ) ? $by_col[ $h ] : '';
			}
		}
		return $row;
	}

	/** PURE: map a system-field key to a default header name. */
	public static function system_default_header( string $key ): string
	{
		$names = array(
			'entry_id'           => 'Entry ID',
			'form_id'            => 'Form ID',
			'created_at'         => 'Created At',
			'source_url'         => 'Source URL',
			'user_ip'            => 'User IP',
			'integration_status' => 'Integration Status',
		);
		return $names[ $key ] ?? $key;
	}

	/** PURE: anonymize an IP for storage when the system field is opt-in. */
	public static function anonymize_ip( string $ip ): string
	{
		if ( $ip === '' ) {
			return '';
		}
		// Keep the first two octets, drop the rest (privacy-safe default).
		$parts = explode( '.', $ip );
		return count( $parts ) === 4 ? $parts[0] . '.' . $parts[1] . '.0.0' : $ip;
	}
}
