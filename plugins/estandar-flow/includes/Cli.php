<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli
{
	public static function init(): void
	{
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'estandar-suite flow pipeline', array( __CLASS__, 'pipeline' ) );
		\WP_CLI::add_command( 'estandar-suite flow transition', array( __CLASS__, 'transition' ) );
		\WP_CLI::add_command( 'estandar-suite flow journey', array( __CLASS__, 'journey_cmd' ) );
		\WP_CLI::add_command( 'estandar-suite flow adoption import', array( __CLASS__, 'adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite flow adoption rollback', array( __CLASS__, 'adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes list', array( __CLASS__, 'quotes_list' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes summary', array( __CLASS__, 'quotes_summary' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes adoption status', array( __CLASS__, 'quotes_adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes adoption import', array( __CLASS__, 'quotes_adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes adoption rollback', array( __CLASS__, 'quotes_adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes settings get', array( __CLASS__, 'quotes_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes settings set', array( __CLASS__, 'quotes_settings_set' ) );
		// M10: CRUD operativo de cotizaciones v2 (create/get/status/send/expire/pdf/revisions/events/duplicate).
		\WP_CLI::add_command( 'estandar-suite flow quote', array( __CLASS__, 'quote_cmd' ) );
		\WP_CLI::add_command( 'estandar-suite flow visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite flow visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite flow visibility reset', array( __CLASS__, 'visibility_reset' ) );
	}

	public static function pipeline( array $args, array $assoc ): void
	{
		foreach ( Pipeline::list( (string) ( $assoc['stage'] ?? '' ), (string) ( $assoc['project'] ?? '' ) ) as $row ) {
			\WP_CLI::log( sprintf( '#%d · %s · %s%s', $row['id'], $row['stage'], $row['entity_type'], $row['entity_id'] ? '#' . $row['entity_id'] : '' ) );
		}
	}

	public static function transition( array $args, array $assoc ): void
	{
		$id = (int) ( $assoc['pipeline_id'] ?? 0 );
		$stage = (string) ( $assoc['stage'] ?? '' );
		if ( $id <= 0 || $stage === '' ) {
			\WP_CLI::error( 'Requiere --pipeline_id y --stage.' );
		}
		$r = Pipeline::transition( $id, $stage, (string) ( $assoc['reason'] ?? '' ) );
		$r ? \WP_CLI::success( "{$r['from']} → {$r['stage']}" ) : \WP_CLI::error( 'Transición no aplicable.' );
	}

	public static function journey_cmd( array $args, array $assoc ): void
	{
		$action = (string) ( $args[0] ?? 'list' );
		switch ( $action ) {
			case 'list':
				$state = sanitize_key( (string) ( $assoc['state'] ?? '' ) );
				foreach ( Pipeline::list_journey( $state ) as $row ) {
					\WP_CLI::log( sprintf( '#%d · %-14s · %s %s',
						$row['id'], $row['journey_state'],
						$row['priority'] ? "[{$row['priority']}]" : '',
						$row['temperature'] ? "{$row['temperature']}" : ''
					) );
				}
				break;
			case 'get':
				$id = (int) ( $assoc['id'] ?? 0 );
				if ( $id <= 0 ) \WP_CLI::error( 'Requiere --id=<pipeline_id>.' );
				$row = Pipeline::get( $id );
				if ( ! $row ) \WP_CLI::error( 'Pipeline no encontrado.' );
				\WP_CLI::log( wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
				break;
			case 'transition':
				$id    = (int) ( $assoc['id'] ?? 0 );
				$state = (string) ( $assoc['state'] ?? '' );
				if ( $id <= 0 || $state === '' ) \WP_CLI::error( 'Requiere --id y --state.' );
				$r = Pipeline::journey_transition( $id, $state, (string) ( $assoc['reason'] ?? '' ) );
				$r ? \WP_CLI::success( "Journey: {$r['from']} → {$r['journey_state']}" ) : \WP_CLI::error( 'Transición inválida.' );
				break;
			case 'priority':
				$id = (int) ( $assoc['id'] ?? 0 );
				$p  = (string) ( $assoc['priority'] ?? '' );
				if ( $id <= 0 || $p === '' ) \WP_CLI::error( 'Requiere --id y --priority (HIGH|MEDIUM|LOW).' );
				Pipeline::set_priority( $id, $p ) ? \WP_CLI::success( "Priority: {$p}" ) : \WP_CLI::error( 'No aplicable.' );
				break;
			case 'validate':
				$from = (string) ( $assoc['from'] ?? '' );
				$to   = (string) ( $assoc['to'] ?? '' );
				if ( $from === '' || $to === '' ) \WP_CLI::error( 'Requiere --from y --to.' );
				$valid = \Estandar\Flow\Journey\StateMachine::validate_transition( $from, $to );
				\WP_CLI::log( $valid ? 'Valid transition.' : 'Invalid transition.' );
				break;
			default:
				\WP_CLI::log( 'Subcomandos: list, get, transition, priority, validate' );
		}
	}

	public static function adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r   = Adoption::import_pipeline( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( ( $dry ? 'DRY-RUN' : 'IMPORTADO' ) . " → importados={$r['imported']} ya_existentes={$r['existing']}" );
	}

	public static function adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$n = Adoption::rollback_pipeline();
		\WP_CLI::success( "Pipeline adoptado eliminado: {$n}" );
	}

	public static function quotes_adoption_status( array $args, array $assoc ): void
	{
		$c = QuotesAdoption::counts();
		\WP_CLI::log( QuotesAdoption::legacy_present()
			? sprintf( 'es_quotes legacy=%d adoptado=%d v2_total=%d', (int) $c['legacy_total'], (int) $c['adopted'], (int) $c['v2_total'] )
			: 'Sin tabla es_quotes (nada que adoptar).' );
	}

	public static function quotes_adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r   = QuotesAdoption::import_all( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( ( $dry ? 'DRY-RUN' : 'IMPORTADO' ) . " → importados={$r['imported']} ya_existentes={$r['existing']}" );
	}

	public static function quotes_adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\WP_CLI::success( 'Cotizaciones adoptadas eliminadas: ' . QuotesAdoption::rollback() );
	}

	public static function visibility_get( array $args, array $assoc ): void
	{
		$map = \Estandar\Core\Admin::get_visibility( 'flow' );
		if ( empty( $map ) ) {
			\WP_CLI::log( 'Sin configuración guardada → todos los tabs/sub-tabs visibles.' );
			return;
		}
		foreach ( $map as $tab => $subs ) {
			\WP_CLI::log( sprintf( '%s: %s', $tab, $subs ? implode( ', ', $subs ) : '(ninguno)' ) );
		}
	}

	public static function visibility_set( array $args, array $assoc ): void
	{
		$tab  = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$subs = array_slice( $args, 1 );
		if ( $tab === '' || ! $subs ) {
			\WP_CLI::error( 'Uso: wp estandar-suite flow visibility set <tab> <sub1> [<sub2> ...]' );
		}
		$subs = array_map( 'sanitize_key', $subs );
		$map = \Estandar\Core\Admin::get_visibility( 'flow' );
		$map[ $tab ] = $subs;
		\Estandar\Core\Admin::save_visibility( 'flow', $map );
		\WP_CLI::success( sprintf( 'flow > %s: visibles = %s', $tab, implode( ', ', $subs ) ) );
	}

	public static function visibility_reset( array $args, array $assoc ): void
	{
		delete_option( \Estandar\Core\Admin::visibility_option( 'flow' ) );
		\WP_CLI::success( 'flow: visibilidad restaurada (todo visible).' );
	}

	public static function quotes_list(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'es_quotes', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin cotizaciones legadas.' );
			return;
		}
		foreach ( $rows as $q ) {
			\WP_CLI::log( sprintf( '#%d %s [%s] proyecto=%s lead=%s', (int) $q['id'], $q['quote_uuid'], $q['status'], $q['project_slug'], $q['lead_id'] ? (int) $q['lead_id'] : '-' ) );
		}
	}

	public static function quotes_summary(): void
	{
		\WP_CLI::log( sprintf( 'cotizaciones_legadas=%d cotizaciones_v2=%d',
			\Estandar\Core\Admin::legacy_count( 'es_quotes' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_flow_quotes' )
		) );
	}

	public static function quotes_settings_get(): void
	{
		\WP_CLI::log( 'Quotes settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_flow_quotes_settings', \Estandar\Flow\Admin::quotes_settings_fields() );
	}

	public static function quotes_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_flow_quotes_settings', \Estandar\Flow\Admin::quotes_settings_fields(), $assoc );
	}

	// ── M10: CRUD operativo de cotizaciones v2 ──────────────────────────────

	/**
	 * Dispatcher: `wp estandar-suite flow quote <create|get|status|send|expire|
	 * pdf|revisions|events|duplicate>`.
	 */
	public static function quote_cmd( array $args, array $assoc ): void
	{
		$action = (string) ( $args[0] ?? 'help' );

		switch ( $action ) {
			case 'create':
				self::quote_create( $args, $assoc );
				break;
			case 'get':
				self::quote_get( $args, $assoc );
				break;
			case 'status':
				self::quote_status( $args, $assoc );
				break;
			case 'send':
				self::quote_send( $args, $assoc );
				break;
			case 'expire':
				self::quote_expire( $args, $assoc );
				break;
			case 'pdf':
				self::quote_pdf( $args, $assoc );
				break;
			case 'revisions':
				self::quote_revisions( $args, $assoc );
				break;
			case 'events':
				self::quote_events( $args, $assoc );
				break;
			case 'duplicate':
				self::quote_duplicate( $args, $assoc );
				break;
			default:
				\WP_CLI::log( 'Subcomandos: create, get, status, send, expire, pdf, revisions, events, duplicate' );
		}
	}

	/** Id de cotización: `--id`/`--quote_id` o primer argumento posicional. */
	private static function quote_id_arg( array $args, array $assoc ): int
	{
		if ( isset( $assoc['id'] ) ) {
			return (int) $assoc['id'];
		}
		if ( isset( $assoc['quote_id'] ) ) {
			return (int) $assoc['quote_id'];
		}
		return (int) ( $args[1] ?? 0 );
	}

	/** Parsea `--items="desc|amount;desc2|amount2"` a filas de `Quotes`. */
	private static function quote_parse_items( string $raw ): array
	{
		$out = array();
		foreach ( preg_split( '/\s*;\s*/', trim( $raw ) ) as $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $chunk ) );
			$label = (string) ( $parts[0] ?? '' );
			$amount = ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) ? (float) $parts[1] : 0.0;
			if ( '' === $label && $amount <= 0 ) {
				continue;
			}
			$out[] = array(
				'label'  => $label,
				'amount' => $amount,
				'qty'    => ( isset( $parts[2] ) && is_numeric( $parts[2] ) ) ? (float) $parts[2] : 1,
				'unit'   => (string) ( $parts[3] ?? '' ),
			);
		}
		return $out;
	}

	private static function quote_create( array $args, array $assoc ): void
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			\WP_CLI::error( 'Módulo de cotizaciones no disponible.' );
		}
		$lead_id = (int) ( $args[1] ?? ( $assoc['lead_id'] ?? 0 ) );
		if ( $lead_id <= 0 ) {
			\WP_CLI::error( 'Uso: wp estandar-suite flow quote create <lead_id> [--title=] [--items="desc|amount;..."] [--currency=CLP] [--validity=15] [--notes=]' );
		}
		$id = \Estandar\Flow\Quotes::create( array(
			'lead_id'       => $lead_id,
			'title'         => (string) ( $assoc['title'] ?? '' ),
			'items'         => self::quote_parse_items( (string) ( $assoc['items'] ?? '' ) ),
			'currency'      => (string) ( $assoc['currency'] ?? 'CLP' ),
			'validity_days' => (int) ( $assoc['validity'] ?? 15 ),
			'notes'         => (string) ( $assoc['notes'] ?? '' ),
			'profile_key'   => (string) ( $assoc['profile'] ?? '' ),
			'client_tax_id' => (string) ( $assoc['tax_id'] ?? '' ),
			'status'        => 'draft',
		) );
		if ( $id <= 0 ) {
			\WP_CLI::error( 'No se pudo crear la cotización.' );
		}
		\WP_CLI::success( "Cotización creada: #{$id}" );
	}

	private static function quote_get( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 ) {
			\WP_CLI::error( 'Requiere <id> (o --id).' );
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' ) ) {
			$doc = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id );
		} elseif ( class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$doc = \Estandar\Flow\Quotes::document( $id );
		} else {
			\WP_CLI::error( 'Módulo de cotizaciones no disponible.' );
		}
		$meta = (array) ( $doc['meta'] ?? array() );
		\WP_CLI::log( sprintf( 'Cotización #%d · %s · %s · %s',
			$id,
			(string) ( $meta['quote_number'] ?? '' ),
			(string) ( $meta['title'] ?? '' ),
			(string) ( $meta['currency'] ?? 'CLP' )
		) );
		$client = (array) ( $doc['client'] ?? array() );
		if ( ! empty( $client['name'] ) ) {
			\WP_CLI::log( 'Cliente: ' . (string) $client['name'] );
		}
		foreach ( (array) ( $doc['items'] ?? array() ) as $i => $item ) {
			\WP_CLI::log( sprintf( '  %d) %s x%s = %s', (int) $i + 1,
				(string) ( $item['description'] ?? '' ),
				(string) ( $item['qty'] ?? 1 ),
				(string) ( $item['line_total'] ?? 0 )
			) );
		}
		$totals = (array) ( $doc['totals'] ?? array() );
		if ( $totals ) {
			\WP_CLI::log( 'Totales: ' . wp_json_encode( $totals, JSON_UNESCAPED_UNICODE ) );
		}
	}

	private static function quote_status( array $args, array $assoc ): void
	{
		$id     = self::quote_id_arg( $args, $assoc );
		$status = sanitize_key( (string) ( $args[2] ?? ( $assoc['status'] ?? '' ) ) );
		if ( $id <= 0 || '' === $status ) {
			\WP_CLI::error( 'Uso: wp estandar-suite flow quote status <id> <draft|sent|accepted|rejected|expired>' );
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
			\WP_CLI::error( 'Ciclo de vida no disponible.' );
		}
		$ok = \Estandar\Flow\Quotes\Lifecycle::transition( $id, $status, array( 'reject_reason' => (string) ( $assoc['reason'] ?? '' ) ) );
		$ok ? \WP_CLI::success( "Cotización #{$id} → {$status}" ) : \WP_CLI::error( 'Transición inválida para el estado actual.' );
	}

	private static function quote_send( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 ) {
			\WP_CLI::error( 'Requiere <id> (o --id).' );
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			\WP_CLI::error( 'Módulo de cotizaciones no disponible.' );
		}
		$r = \Estandar\Flow\Quotes::send( $id, (string) ( $assoc['to'] ?? '' ) );
		if ( empty( $r['ok'] ) ) {
			\WP_CLI::error( 'Envío fallido: ' . (string) ( $r['error'] ?? 'desconocido' ) );
		}
		\WP_CLI::success( 'Cotización enviada' . ( ! empty( $r['transport'] ) ? " ({$r['transport']})" : '' ) . '.' );
	}

	private static function quote_expire( array $args, array $assoc ): void
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
			\WP_CLI::error( 'Ciclo de vida no disponible.' );
		}
		$n = \Estandar\Flow\Quotes\Lifecycle::expire_due( (int) ( $assoc['limit'] ?? 50 ) );
		\WP_CLI::success( "Cotizaciones expiradas: {$n}" );
	}

	private static function quote_pdf( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 ) {
			\WP_CLI::error( 'Requiere <id> (o --id).' );
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Document\\Pdf' ) ) {
			\WP_CLI::error( 'Motor PDF no disponible.' );
		}
		$doc = class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' )
			? \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id )
			: array();
		$pdf = \Estandar\Flow\Quotes\Document\Pdf::render( $doc );
		if ( empty( $pdf['ok'] ) || '' === (string) $pdf['bytes'] ) {
			\WP_CLI::error( 'No se pudo generar el PDF: ' . (string) ( $pdf['error'] ?? 'desconocido' ) );
		}
		$out = (string) ( $assoc['out'] ?? '' );
		if ( '' === $out && class_exists( 'Estandar\\Flow\\Quotes\\Document\\PdfStore' ) ) {
			$out = \Estandar\Flow\Quotes\Document\PdfStore::path( \Estandar\Flow\Quotes\Document\Pdf::filename( $doc ) );
		}
		if ( '' === $out ) {
			\WP_CLI::error( 'Requiere --out=/ruta.pdf (sin uploads disponibles).' );
		}
		if ( false === @file_put_contents( $out, (string) $pdf['bytes'] ) ) {
			\WP_CLI::error( "No se pudo escribir el PDF en: {$out}" );
		}
		$bytes    = strlen( (string) $pdf['bytes'] );
		$fallback = ! empty( $pdf['fallback'] ) ? 'sí' : 'no';
		\WP_CLI::success( "PDF generado: {$out} ({$bytes} bytes; fallback={$fallback})" );
	}

	private static function quote_revisions( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			\WP_CLI::error( 'Requiere <id> y el repositorio disponible.' );
		}
		$rows = \Estandar\Flow\Quotes\Repository::list_revisions( $id );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin revisiones.' );
			return;
		}
		foreach ( $rows as $r ) {
			\WP_CLI::log( sprintf( 'rev#%d %s %s', (int) ( $r['revision_no'] ?? 0 ), (string) ( $r['revision_uuid'] ?? '' ), (string) ( $r['created_at'] ?? '' ) ) );
		}
	}

	private static function quote_events( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			\WP_CLI::error( 'Requiere <id> y el repositorio disponible.' );
		}
		$rows = \Estandar\Flow\Quotes\Repository::list_events( $id );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin eventos.' );
			return;
		}
		foreach ( $rows as $r ) {
			\WP_CLI::log( sprintf( '#%d %s %s %s', (int) ( $r['id'] ?? 0 ), (string) ( $r['event_type'] ?? '' ), (string) ( $r['actor'] ?? '' ), (string) ( $r['created_at'] ?? '' ) ) );
		}
	}

	private static function quote_duplicate( array $args, array $assoc ): void
	{
		$id = self::quote_id_arg( $args, $assoc );
		if ( $id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			\WP_CLI::error( 'Requiere <id> y el módulo de cotizaciones.' );
		}
		$new_id = \Estandar\Flow\Quotes::duplicate( $id );
		$new_id > 0 ? \WP_CLI::success( "Cotización #{$id} duplicada como #{$new_id}" ) : \WP_CLI::error( 'No se pudo duplicar.' );
	}
}
