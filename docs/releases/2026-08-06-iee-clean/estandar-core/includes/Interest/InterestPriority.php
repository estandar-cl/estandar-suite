<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestPriority — derivación de prioridad comercial.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * La prioridad es una SALIDA DERIVADA del motor, nunca una entrada hardcodeada.
 * Combina el nivel de interés, la gestión (contacto/clasificación) y el SLA.
 *
 *   low     → captura sin señales adicionales, todo normal.
 *   normal  → reinterés detectado, gestión normal.
 *   high    → interés ENGAGED/HIGH sin contacto, o recontacto sin gestión.
 *   urgent  → COOLING sin gestión, o SLA violado con interés real.
 */
final class InterestPriority
{
	const VERSION = 'IEE_V1';

	const LOW    = 'low';
	const NORMAL = 'normal';
	const HIGH   = 'high';
	const URGENT = 'urgent';

	/**
	 * Deriva la prioridad.
	 *
	 * @param string $interest_level     uno de InterestState::LEVELS|COOLING.
	 * @param bool   $contact_registered contacto comercial registrado.
	 * @param bool   $sla_violated       SLA excedido.
	 * @return string
	 */
	public static function derive( string $interest_level, bool $contact_registered, bool $sla_violated ): string
	{
		if ( $interest_level === 'COOLING' && ! $contact_registered ) {
			return self::URGENT;
		}
		if ( $sla_violated && $interest_level !== 'CAPTURE' ) {
			return self::URGENT;
		}
		if ( $interest_level === 'HIGH' && ! $contact_registered ) {
			return self::HIGH;
		}
		if ( $interest_level === 'ENGAGED' && ! $contact_registered ) {
			return self::HIGH;
		}
		if ( $interest_level === 'REVISITED' && ! $contact_registered ) {
			return self::NORMAL;
		}
		return self::LOW;
	}

	/** Etiqueta legible. */
	public static function label( string $priority ): string
	{
		$labels = array(
			self::LOW    => 'Baja',
			self::NORMAL => 'Normal',
			self::HIGH   => 'Alta',
			self::URGENT => 'Urgente',
		);
		return isset( $labels[ $priority ] ) ? $labels[ $priority ] : $priority;
	}
}
