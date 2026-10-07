<?php

namespace Estandar\Core\Communications\Transports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrato de transporte de correo de la Suite.
 *
 * Cada transporte encapsula UN mecanismo de entrega (wp_mail, SMTP, Brevo…).
 * Los productos (Capture/Flow/etc.) nunca implementan transporte: solo entregan
 * mensaje, contexto y configuración funcional a Core\Communications.
 *
 * Los adapters de transportes heredados (Brevo/SMTP configurados por terceros)
 * descubren y REUTILIZAN la configuración existente sin romperla ni copiarla.
 */
interface TransportInterface
{
	/** Identificador único (ej. 'wp_mail', 'smtp', 'brevo'). */
	public function name(): string;

	/** ¿La instalación tiene esta configuración operativa? */
	public function is_configured(): bool;

	/** ¿Es una configuración heredada/detectada (reutilizada), no nativa de la Suite? */
	public function is_inherited(): bool;

	/** Descripción corta para la UI (origen / estado). */
	public function describe(): array;

	/** Envía un correo con opciones normalizadas. Devuelve {ok, to, subject, transport, error}. */
	public function send( array $opts ): array;

	/** Health check sin enviar correos reales. Devuelve {ok, status, detail}. */
	public function health_check(): array;

	/** Envío de prueba explícito. */
	public function test( array $opts ): array;
}
