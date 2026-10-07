<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actions — Action Engine v2 (F1).
 *
 * Ejecuta las acciones de `actions_json` de un form mapeadas sobre la infra v2
 * existente (no reimplementa el engine legacy):
 *  - database → la persistencia YA la hace Ingestion (no-op declarativo);
 *  - email    → notificación + autorespuesta por Core\Mail (Forms);
 *  - webhook  → Webhook::dispatch (modos estandar/cf7_compat).
 *
 * Normaliza tanto el formato lista (`["database","email"]`) como el objeto
 * Form Engine (`{flow:{...}, on_submit:[...]}`) del legacy. Idempotente.
 */
class Actions
{
	/** Normaliza actions_json → lista de acciones canónicas. */
	public static function normalize( $raw ): array
	{
		$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || ! $decoded ) {
			return array( 'database' ); // default: persistir (pipeline mínimo).
		}
		if ( isset( $decoded['on_submit'] ) && is_array( $decoded['on_submit'] ) ) {
			$list = $decoded['on_submit'];
		} else {
			$list = $decoded;
		}
		$actions = array();
		foreach ( $list as $action ) {
			$type = is_array( $action ) ? (string) ( $action['type'] ?? '' ) : (string) $action;
			$type = sanitize_key( $type );
			if ( in_array( $type, array( 'database', 'email', 'webhook' ), true ) && ! in_array( $type, $actions, true ) ) {
				$actions[] = $type;
			}
		}
		return $actions ? $actions : array( 'database' );
	}

	/**
	 * Ejecuta las acciones post-submit. database no-op (Ingestion ya persistió).
	 *
	 * Paridad con el pipeline lead legacy: notify/autoresponse (send_email) y
	 * webhook (send_webhook) son DEFAULTS por config — corren siempre con sus
	 * guards internos (disabled si no hay config), como antes de F1. Las
	 * acciones explícitas de actions_json se ejecutan en orden sin duplicar.
	 *
	 * @return array{executed:array, results:array}
	 */
	public static function run( int $form_id, array $config, array $capture ): array
	{
		$explicit = self::normalize( $config['actions_json'] ?? array() );
		$defaults = array( 'database', 'email', 'webhook' );
		$actions  = array_values( array_unique( array_merge( $defaults, $explicit ) ) );
		$results  = array();

		foreach ( $actions as $action ) {
			switch ( $action ) {
				case 'database':
					$results[ $action ] = array( 'ok' => true, 'note' => 'ingested' );
					break;

				case 'email':
					// FUNNEL_V1: form en modo 'funnel' → SOLO el correo del
					// operador enriquecido (se suprime la notificación simple del
					// form). La autorespuesta al lead se mantiene.
					$suppress_notify = ( Forms::funnel_mode( $config ) === 'funnel' );
					$results[ $action ] = array(
						'notify'       => $suppress_notify
							? array( 'ok' => false, 'skipped' => 'funnel_mode' )
							: Forms::notify_submission( $form_id, $config, $capture ),
						'autoresponse' => Forms::autoresponse_submission( $form_id, $config, $capture ),
						// AUTORESPONSE_DELAYED_V1: agenda la instancia marketera
						// (correo diferido firmado por el operador). Best-effort.
						'autoresponse_delayed' => Forms::schedule_delayed_autoresponse( $form_id, $config, $capture ),
					);
					break;

				case 'webhook':
					$results[ $action ] = Webhook::dispatch( $config, $capture );
					break;
			}
		}

		return array( 'executed' => $actions, 'results' => $results );
	}
}
