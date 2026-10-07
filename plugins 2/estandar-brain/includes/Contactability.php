<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CONTACTABILITY_V1 — Adapter Suite v2 (estandar-brain) al ContactabilityEngine.
 *
 * Reutiliza el MISMO motor de core (Estandar\Core\Quality\ContactabilityEngine)
 * que la Suite actual. No duplica lógica de validación: solo expone el contrato
 * en el namespace de brain y añade un fallback syntax-only cuando core no está
 * actualizado (ausencia = default).
 *
 * Contrato de salida (idéntico en v1 y v2):
 *   syntax_valid · contactability_status · validation_reason
 */
class Contactability
{
	const VERSION = 'CONTACTABILITY_V1';

	const PHONE_VALID      = 'valid';
	const PHONE_PLAUSIBLE  = 'plausible';
	const PHONE_SUSPICIOUS = 'suspicious';
	const PHONE_INVALID    = 'invalid';
	const EMAIL_VALID      = 'valid';
	const EMAIL_INVALID    = 'invalid';

	/** Evalúa contactabilidad de un teléfono (delega al motor de core). */
	public static function phone( string $phone ): array
	{
		if ( class_exists( 'Estandar\\Core\\Quality\\ContactabilityEngine' ) ) {
			$r = \Estandar\Core\Quality\ContactabilityEngine::phone( $phone );
			return self::map( $r );
		}
		// Fallback syntax-only (sin core actualizado).
		$digits = preg_replace( '/\D+/', '', $phone );
		return array(
			'syntax_valid'          => $digits !== '',
			'contactability_status' => $digits !== '' ? self::PHONE_PLAUSIBLE : self::PHONE_INVALID,
			'validation_reason'     => $digits !== '' ? 'syntax_only_fallback' : 'empty',
		);
	}

	/** Evalúa contactabilidad de un email (delega al motor de core). */
	public static function email( string $email ): array
	{
		if ( class_exists( 'Estandar\\Core\\Quality\\ContactabilityEngine' ) ) {
			$r = \Estandar\Core\Quality\ContactabilityEngine::email( $email );
			return self::map( $r );
		}
		$valid = filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
		return array(
			'syntax_valid'          => $valid,
			'contactability_status' => $valid ? self::EMAIL_VALID : self::EMAIL_INVALID,
			'validation_reason'     => $valid ? 'syntax_only_fallback' : 'invalid_format',
		);
	}

	/** Traduce el contrato del motor (quita campos internos). */
	private static function map( array $r ): array
	{
		return array(
			'syntax_valid'          => (bool) ( $r['syntax_valid'] ?? false ),
			'contactability_status' => (string) ( $r['contactability_status'] ?? self::PHONE_INVALID ),
			'validation_reason'     => (string) ( $r['validation_reason'] ?? 'empty' ),
		);
	}
}
