<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestSignals — registro canónico de señales de interés comercial.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * - Catálogo de señales que el Interest Evolution Engine reconoce.
 * - Cada señal tiene un peso por defecto (SUGERENCIA, nunca ley) y una
 *   etiqueta legible para render (correos, UI).
 * - Los pesos son configurables por instalación vía el filtro
 *   `estandar_interest_signal_weight` (reemplaza el mapa completo).
 * - Sin WordPress: la aplicación de filtros es opcional (guard function_exists),
 *   por lo que la clase es testeable como función pura.
 * - Ausencia de señal = no contribuye al score (nunca fabrica evidencia).
 *
 * Contrato del método weight():
 *   - unknown signal id => 0 (no contribuye, nunca error).
 */
final class InterestSignals
{
	const VERSION = 'IEE_V1';

	/** Peso por defecto por señal (sugerencia, no ley). */
	public static function defaults(): array
	{
		return array(
			// Captura: la fundación del interés (no suma, es el punto de partida).
			'first_form'              => 0,
			// Evolución por formularios adicionales.
			'second_form'             => 10,
			'third_form'              => 15,
			// Evolución por re-visita sin formulario (IEE-3).
			'return_visit_after_capture' => 25,
			// Nueva sesión.
			'new_session'             => 5,
			// Canal de regreso (refuerzo de reinterés).
			'direct_return'           => 10,
			'organic_return'          => 8,
			'ads_return'              => 12,
			// Comportamiento en correo.
			'email_sent'              => 0,
			'email_opened'            => 15,
			'email_click'             => 20,
			// Comportamiento en mensajería.
			'whatsapp_opened'         => 20,
			'chatbot_start'           => 15,
			// Comportamiento comercial explícito.
			'quote_created'           => 30,
			// Navegación / profundidad (intensidad).
			'page_depth_low'          => 5,
			'page_depth_high'         => 15,
		);
	}

	/** Etiquetas legibles por señal (es-CL). */
	public static function labels(): array
	{
		return array(
			'first_form'              => 'Primer formulario recibido',
			'second_form'             => 'Segundo formulario recibido',
			'third_form'              => 'Tercer formulario recibido',
			'return_visit_after_capture' => 'Volvió al sitio después de la captura',
			'new_session'             => 'Nueva sesión detectada',
			'direct_return'           => 'Regreso por tráfico directo',
			'organic_return'          => 'Regreso por búsqueda orgánica',
			'ads_return'              => 'Regreso por campaña pagada',
			'email_sent'              => 'Email automático enviado',
			'email_opened'            => 'Email abierto',
			'email_click'             => 'Clic en el email',
			'whatsapp_opened'         => 'WhatsApp abierto',
			'chatbot_start'           => 'Chatbot iniciado',
			'quote_created'           => 'Cotización creada',
			'page_depth_low'          => 'Navegación breve',
			'page_depth_high'         => 'Navegación profunda',
		);
	}

	/** Peso de una señal (filterable). Desconocida => 0. */
	public static function weight( string $id ): int
	{
		$weights = self::weights();
		return isset( $weights[ $id ] ) ? (int) $weights[ $id ] : 0;
	}

	/** Mapa completo id => peso, reemplazable por filtro por instalación. */
	public static function weights(): array
	{
		$defaults = self::defaults();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_interest_signal_weight', $defaults );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		return $defaults;
	}

	/** Etiqueta de una señal. Desconocida => id. */
	public static function label( string $id ): string
	{
		$labels = self::labels();
		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/** Devuelve la lista de señales activadas con su peso y etiqueta. */
	public static function resolved( array $signal_ids ): array
	{
		$signals = array();
		foreach ( array_values( array_unique( $signal_ids ) ) as $id ) {
			$weight = self::weight( (string) $id );
			if ( $weight <= 0 ) {
				continue; // Las señales con peso 0 no aportan ni se listan.
			}
			$signals[] = array(
				'id'     => (string) $id,
				'weight' => $weight,
				'label'  => self::label( (string) $id ),
			);
		}
		return $signals;
	}
}
