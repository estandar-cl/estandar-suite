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
		\WP_CLI::add_command( 'estandar-suite flow quotes settings get', array( __CLASS__, 'quotes_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite flow quotes settings set', array( __CLASS__, 'quotes_settings_set' ) );
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
}
