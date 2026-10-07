<?php
/**
 * Commercial Feedback Parser
 * 
 * Parses unstructured operator feedback into categorized entries.
 * Accepts: plain text, phone lists, category headers, CSV.
 * 
 * Domain-generic. No client-specific logic.
 * 
 * Part of Estandar Suite — shared between legacy and v2.
 */

class Estandar_Commercial_Feedback_Parser {

	/** Category labels the parser recognizes (case-insensitive, Spanish/English). */
	const CATEGORY_MARKERS = [
		'VALIDO'     => ['leads válidos', 'leads validos', 'valid leads', 'validos', 'válidos', '✓', '✅', 'buenos', 'contrataron', 'interesados'],
		'BASURA'     => ['leads basura', 'basura', 'spam', '🗑️', '🗑', 'no encajan', 'basura'],
		'NO_CONTESTA'=> ['no contesta', 'no contestan', 'no respondió', 'no respondio', 'no respuesta', 'sin respuesta', '📭', 'no responde', 'no responden'],
		'PROPUESTA'  => ['propuesta enviada', 'propuesta', 'cotización enviada', 'cotizacion enviada', 'presupuesto enviado'],
		'REUNION'    => ['reunión', 'reunion', 'reuniones', 'meeting', 'agendada', 'coordinada'],
		'CLIENTE'    => ['cliente', 'contrató', 'contrato', 'cerrado', 'ganado'],
	];

	/**
	 * Parse raw text into categorized entries.
	 * 
	 * @param string $raw  Unstructured text from operator
	 * @return array  ['entries' => [[phone, name, notes, category], ...], 'warnings' => [...]]
	 */
	public static function parse( string $raw ): array {
		$lines = explode( "\n", trim( $raw ) );
		$entries = [];
		$warnings = [];
		$current_category = 'VALIDO'; // default
		$seen_phones = [];

		foreach ( $lines as $i => $line ) {
			$trimmed = trim( $line );
			if ( $trimmed === '' ) continue;

			// Check for category header change
			$new_cat = self::detect_category( $trimmed );
			if ( $new_cat ) {
				$current_category = $new_cat;
				continue;
			}

			// Skip lines that are clearly not leads (separators, commentary)
			if ( self::is_metadata_line( $trimmed ) ) continue;

			// Extract phone number
			$phone = self::extract_phone( $trimmed );
			if ( ! $phone ) {
				// Line without phone — might be a note/name appended to previous
				if ( ! empty( $entries ) ) {
					$entries[ count( $entries ) - 1 ]['notes'] .= ' ' . $trimmed;
				}
				continue;
			}

			// Normalize phone
			$normalized = self::normalize_phone( $phone );

			// Duplicate detection
			if ( in_array( $normalized, $seen_phones, true ) ) {
				$warnings[] = "Línea $i: teléfono duplicado $phone";
				continue;
			}
			$seen_phones[] = $normalized;

			// Extract name (text before or after phone)
			$name = self::extract_name( $trimmed, $phone );

			// Extract notes (everything after name)
			$notes = trim( str_replace( [ $phone, $name ], '', $trimmed ), " \t\n\r\0\x0B()-–—" );

			$entries[] = [
				'phone'    => $normalized,
				'phone_raw'=> $phone,
				'name'     => $name,
				'notes'    => $notes,
				'category' => $current_category,
				'line'     => $i + 1,
			];
		}

		return [
			'entries'  => $entries,
			'warnings' => $warnings,
			'counts'   => self::count_by_category( $entries ),
		];
	}

	/** Detect if a line is a category header. Returns category key or null. */
	private static function detect_category( string $line ): ?string {
		$lower = mb_strtolower( trim( $line, " \t\n\r\0\x0B:-–—*# " ) );
		foreach ( self::CATEGORY_MARKERS as $cat => $markers ) {
			foreach ( $markers as $marker ) {
				if ( strpos( $lower, $marker ) !== false ) {
					return $cat;
				}
			}
		}
		return null;
	}

	/** Check if a line is metadata (not a lead entry). */
	private static function is_metadata_line( string $line ): bool {
		$skip = [ 'leads', 'lead', 'total', 'resumen', 'contactos', 'contactado' ];
		$lower = mb_strtolower( trim( $line ) );
		// Lines that are ONLY metadata words (no phone)
		if ( ! self::extract_phone( $line ) ) {
			foreach ( $skip as $word ) {
				if ( $lower === $word || strpos( $lower, $word ) === 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Extract phone number from a line. Returns raw match or null. */
	public static function extract_phone( string $line ): ?string {
		// Chilean mobile: +56 9 XXXX XXXX or 9 XXXX XXXX or 569XXXXXXXX
		if ( preg_match( '/(?:\+?56\s?)?9\s?\d{1}\s?\d{3}\s?\d{4}/', $line, $m ) ) {
			return $m[0];
		}
		// Generic: 8-15 digit sequence starting with + or digit
		if ( preg_match( '/(?:\+\d{1,3}\s?)?\d{2,4}\s?\d{3,4}\s?\d{3,4}/', $line, $m ) ) {
			$digits = preg_replace( '/\D/', '', $m[0] );
			if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) {
				return $m[0];
			}
		}
		return null;
	}

	/** Normalize phone to digits only. */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone );
		// Strip +56 prefix for Chilean numbers
		if ( strlen( $digits ) === 11 && strpos( $digits, '56' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		return $digits;
	}

	/** Extract name from a line (text around the phone). */
	private static function extract_name( string $line, string $phone ): string {
		$cleaned = str_replace( $phone, ' ', $line );
		// Look for parenthesized name: " (Name)"
		if ( preg_match( '/\(([^)]+)\)/', $cleaned, $m ) ) {
			return trim( $m[1] );
		}
		// Take first word after phone that looks like a name
		$parts = preg_split( '/\s+/', trim( $cleaned ) );
		$name_parts = [];
		foreach ( $parts as $part ) {
			$part = trim( $part, " \t\n\r\0\x0B-,.;:-–—" );
			if ( $part === '' ) continue;
			if ( preg_match( '/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+$/', $part ) ) {
				$name_parts[] = $part;
			}
		}
		return implode( ' ', array_slice( $name_parts, 0, 2 ) );
	}

	/** Count entries by category. */
	private static function count_by_category( array $entries ): array {
		$counts = [];
		foreach ( $entries as $e ) {
			$cat = $e['category'];
			$counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
		}
		return $counts;
	}
}
