<?php

namespace Estandar\Core\Communications\Inbound;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contratos de correo ENTRANTE de la Suite (diseño, no implementación).
 *
 * El correo entrante reutiliza la MISMA infraestructura de Communications:
 * normalización, transporte, logs y health. No se crea un segundo sistema.
 *
 * Fuentes previstas:
 *   - IMAP (polling)
 *   - API de proveedor (webhook de inbound)
 *   - Webhook genérico (HTTP POST)
 */

/**
 * Una fuente de correo entrante (IMAP/API/webhook).
 */
interface InboundSource
{
	/** Identificador único de la fuente. */
	public function name(): string;

	/** Lee mensajes pendientes y devuelve mensajes normalizados. */
	public function poll( array $opts = array() ): array;

	/** Normaliza un payload crudo a un mensaje canónico. */
	public function normalize( array $raw ): array;
}

/**
 * Mensaje entrante canónico (compartido entre fuentes).
 */
interface InboundMessage
{
	/** Id único de la fuente. */
	public function source_id(): string;

	/** Correo del remitente (From). */
	public function from(): string;

	/** Asunto. */
	public function subject(): string;

	/** Cuerpo de texto. */
	public function body_text(): string;

	/** Cuerpo HTML (si existe). */
	public function body_html(): string;

	/** Adjuntos. */
	public function attachments(): array;

	/** Timestamp recibido (Y-m-d H:i:s). */
	public function received_at(): string;
}

/**
 * Normalización de contrato para una acción entrante (crear submission, lead…).
 * Los productos registran handlers; Communications entrega mensajes canónicos.
 */
interface InboundDispatcher
{
	/** Registra un handler para mensajes entrantes de un objeto/contexto. */
	public function register( string $context, callable $handler ): void;

	/** Entrega un mensaje normalizado a los handlers del contexto. */
	public function dispatch( InboundMessage $message, string $context ): array;
}
