<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ContactabilityEngine — validación de contactabilidad de datos de contacto.
 *
 * CONTACTABILITY_V1 — motor REUTILIZABLE compartido por la Suite actual (v1)
 * y la Suite v2 (Brain/Capture/Catch/Experience/Core).
 *
 * Separación explícita (caso AK #94):
 *   - syntax_valid          : el dato "parece" un teléfono/email válido (formato).
 *   - contactability_status : si el dato es COMERCIALMENTE UTILIZABLE.
 *   - validation_reason     : por qué se llegó al estado.
 *
 * Un dato puede tener syntax_valid = true y aun así no ser utilizable
 * (ej. "dio el numero mal", número de otro país, incompleto, dominio no
 * enrutable). La validación NO confunde "formato aceptado" con "dato
 * comercialmente utilizable".
 *
 * Teléfono — estados:
 *   VALID      → estructura nacional válida (Chile +56, móvil 9XXXXXXXX,
 *                fijo 2/3/4...XXXXXXXX) y plausible de contacto.
 *   PLAUSIBLE  → sintaxis aceptable pero con ambigüedad (longitud atípica,
 *                país desconocido, sin código de país inferible).
 *   SUSPICIOUS → patrón riesgoso (repetidos, dígitos de relleno,
 *                secuenciales, longitud anómala) sin romper el dato.
 *   INVALID    → no es utilizable (vacío, demasiado corto, sin dígitos,
 *                obviamente corrupto).
 *
 * SIN heurísticas destructivas: nunca modifica ni degrada datos existentes;
 * solo clasifica y expone reason. La normalización de almacenamiento sigue
 * siendo responsabilidad del consumidor (v1: Estandar_Leads_Normalizer).
 *
 * Puro: sin WordPress, sin DB, testeable standalone.
 */
final class ContactabilityEngine
{
	const VERSION = 'CONTACTABILITY_V1';

	// Estados de contactabilidad de teléfono.
	const PHONE_VALID      = 'valid';
	const PHONE_PLAUSIBLE  = 'plausible';
	const PHONE_SUSPICIOUS = 'suspicious';
	const PHONE_INVALID    = 'invalid';

	// Estados de contactabilidad de email.
	const EMAIL_VALID   = 'valid';
	const EMAIL_INVALID = 'invalid';

	// Razones de teléfono (estructuradas, extensibles).
	const REASON_PHONE_OK                = 'valid_structure';
	const REASON_PHONE_MOBILE_CL         = 'valid_chile_mobile';
	const REASON_PHONE_LANDLINE_CL       = 'valid_chile_landline';
	const REASON_PHONE_EMPTY             = 'empty';
	const REASON_PHONE_TOO_SHORT         = 'too_short';
	const REASON_PHONE_TOO_LONG          = 'too_long';
	const REASON_PHONE_REPEATED          = 'repeated_digits';
	const REASON_PHONE_SEQUENTIAL        = 'sequential_digits';
	const REASON_PHONE_ATYPICAL_LENGTH   = 'atypical_length';
	const REASON_PHONE_UNKNOWN_COUNTRY   = 'unknown_country';
	const REASON_PHONE_NO_COUNTRY_INFERABLE = 'no_country_inferrable';

	// Razones de email (estructuradas).
	const REASON_EMAIL_OK          = 'valid_format';
	const REASON_EMAIL_EMPTY       = 'empty';
	const REASON_EMAIL_INVALID     = 'invalid_format';
	const REASON_EMAIL_DISPOSABLE  = 'disposable_domain';
	const REASON_EMAIL_NO_DOMAIN   = 'no_domain';

	/** Móvil chileno +56 9 XXXX XXXX. */
	private const CHILE_MOBILE_RE = '/^569\d{8}$/';

	/** Fijo chileno +56 2/3/4... + 8 dígitos. */
	private const CHILE_LANDLINE_RE = '/^56(2|3|4|5|6|7)\d{8}$/';

	/** Longitudes razonables de un número nacional (sin código país). */
	private const MIN_NATIONAL_LENGTH = 8;
	private const MAX_NATIONAL_LENGTH = 11;

	/**
	 * Evalúa contactabilidad de un teléfono.
	 *
	 * Acepta el número en formato crudo o ya normalizado (storage). La
	 * normalización previa es responsabilidad del consumidor; aquí se aplica
	 * una limpieza mínima de dígitos para clasificar (nunca se muta el dato).
	 *
	 * @param string $phone valor crudo o normalizado.
	 * @return array { syntax_valid, contactability_status, validation_reason, digits }
	 */
	public static function phone( string $phone ): array
	{
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( $digits === '' ) {
			return self::phone_result( false, self::PHONE_INVALID, self::REASON_PHONE_EMPTY, '' );
		}

		// Longitud cruda con/sin código de país.
		$len = strlen( $digits );
		if ( $len < 8 ) {
			return self::phone_result( true, self::PHONE_INVALID, self::REASON_PHONE_TOO_SHORT, $digits );
		}
		if ( $len > 14 ) {
			return self::phone_result( true, self::PHONE_INVALID, self::REASON_PHONE_TOO_LONG, $digits );
		}

		// Número nacional (sin código país) con longitud razonable.
		$national = $digits;
		if ( strpos( $digits, '56' ) === 0 && strlen( $digits ) > 2 ) {
			$national = substr( $digits, 2 );
		}
		$national_len = strlen( $national );
		$has_country  = strpos( $digits, '56' ) === 0;

		// Patrones de sospecha con PRECEDENCIA sobre la validación de país:
		// un run de repetición delata relleno aunque el formato sea "técnicamente"
		// un móvil (56911111111). Señala, nunca borra ni degrada el dato.
		if ( preg_match( '/(\d)\1{4,}/', $national ) ) {
			return self::phone_result( true, self::PHONE_SUSPICIOUS, self::REASON_PHONE_REPEATED, $digits );
		}

		// Chile: móvil +56 9XXXXXXXX.
		if ( preg_match( self::CHILE_MOBILE_RE, $digits ) ) {
			return self::phone_result( true, self::PHONE_VALID, self::REASON_PHONE_MOBILE_CL, $digits );
		}
		// Chile: fijo +56 2/3/4/5/6/7 + 8 dígitos.
		if ( preg_match( self::CHILE_LANDLINE_RE, $digits ) ) {
			return self::phone_result( true, self::PHONE_VALID, self::REASON_PHONE_LANDLINE_CL, $digits );
		}

		$status = self::PHONE_PLAUSIBLE;
		$why    = $has_country ? self::REASON_PHONE_ATYPICAL_LENGTH : self::REASON_PHONE_NO_COUNTRY_INFERABLE;

		// Secuencial: SOLO en números nacionales cortos (sin prefijo internacional).
		// En un número internacional la sub-secuencia 23456 puede aparecer de
		// forma natural (ej. 51912345678 es un número real plausible de Perú).
		if ( $national_len <= 9 && preg_match( '/(012345|123456|234567|345678|456789|987654|876543|765432|654321)/', $national ) ) {
			$status = self::PHONE_SUSPICIOUS;
			$why    = self::REASON_PHONE_SEQUENTIAL;
		} elseif ( $national_len < self::MIN_NATIONAL_LENGTH || $national_len > self::MAX_NATIONAL_LENGTH ) {
			$status = self::PHONE_SUSPICIOUS;
			$why    = self::REASON_PHONE_ATYPICAL_LENGTH;
		} elseif ( $has_country && ! self::is_known_country( $digits ) ) {
			$status = self::PHONE_PLAUSIBLE;
			$why    = self::REASON_PHONE_UNKNOWN_COUNTRY;
		}

		return self::phone_result( true, $status, $why, $digits );
	}

	/**
	 * Evalúa contactabilidad de un email.
	 *
	 * @param string $email valor crudo o normalizado.
	 * @return array { syntax_valid, contactability_status, validation_reason, normalized }
	 */
	public static function email( string $email ): array
	{
		$normalized = strtolower( trim( (string) $email ) );
		if ( $normalized === '' ) {
			return self::email_result( false, self::EMAIL_INVALID, self::REASON_EMAIL_EMPTY, '' );
		}
		if ( ! filter_var( $normalized, FILTER_VALIDATE_EMAIL ) ) {
			return self::email_result( true, self::EMAIL_INVALID, self::REASON_EMAIL_INVALID, $normalized );
		}
		$at = strrpos( $normalized, '@' );
		if ( $at === false || $at === 0 || $at === strlen( $normalized ) - 1 ) {
			return self::email_result( true, self::EMAIL_INVALID, self::REASON_EMAIL_NO_DOMAIN, $normalized );
		}
		$domain = substr( $normalized, $at + 1 );
		if ( strpos( $domain, '.' ) === false ) {
			return self::email_result( true, self::EMAIL_INVALID, self::REASON_EMAIL_NO_DOMAIN, $normalized );
		}
		return self::email_result( true, self::EMAIL_VALID, self::REASON_EMAIL_OK, $normalized );
	}

	/** Prefijos de país conocidos (extensible). */
	private static function is_known_country( string $digits ): bool
	{
		// Prefijos internacionales comunes (sin garantía de enrutabilidad).
		$prefixes = array(
			'1', '7', '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41',
			'43', '44', '45', '46', '47', '48', '49', '51', '52', '53', '54', '55', '56',
			'57', '58', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90',
			'91', '92', '93', '94', '95', '98', '211', '212', '213', '218', '220', '221',
			'222', '230', '231', '232', '233', '234', '235', '236', '237', '238', '239',
			'241', '242', '243', '244', '245', '248', '249', '250', '251', '252', '253',
			'254', '255', '256', '257', '258', '260', '261', '262', '263', '264', '265',
			'266', '267', '268', '269', '290', '291', '297', '298', '299',
		);
		foreach ( $prefixes as $p ) {
			if ( strpos( $digits, $p ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Resultado de teléfono con contrato estable. */
	private static function phone_result( bool $syntax, string $status, string $reason, string $digits ): array
	{
		return array(
			'syntax_valid'          => $syntax,
			'contactability_status' => $status,
			'validation_reason'     => $reason,
			'digits'                => $digits,
		);
	}

	/** Resultado de email con contrato estable. */
	private static function email_result( bool $syntax, string $status, string $reason, string $normalized ): array
	{
		return array(
			'syntax_valid'          => $syntax,
			'contactability_status' => $status,
			'validation_reason'     => $reason,
			'normalized'            => $normalized,
		);
	}
}
