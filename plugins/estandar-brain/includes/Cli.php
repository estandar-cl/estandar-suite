<?php

namespace Estandar\Brain;

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
		\WP_CLI::add_command( 'estandar-suite brain score', array( __CLASS__, 'score' ) );
		// Nombres hermanos (no anidar) para no romper WP-CLI con subcomandos.
		\WP_CLI::add_command( 'estandar-suite brain signals', array( __CLASS__, 'signals' ) );
		\WP_CLI::add_command( 'estandar-suite brain signals-process', array( __CLASS__, 'signals_process' ) );
		\WP_CLI::add_command( 'estandar-suite brain signals-backfill', array( __CLASS__, 'signals_backfill' ) );
		\WP_CLI::add_command( 'estandar-suite brain adoption import', array( __CLASS__, 'adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite brain signal adoption status', array( __CLASS__, 'signal_adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite brain signal adoption import', array( __CLASS__, 'signal_adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite brain signal adoption rollback', array( __CLASS__, 'signal_adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite brain intents show', array( __CLASS__, 'intents' ) );
		\WP_CLI::add_command( 'estandar-suite brain intents set', array( __CLASS__, 'intents_set' ) );
		\WP_CLI::add_command( 'estandar-suite brain rules show', array( __CLASS__, 'rules' ) );
		\WP_CLI::add_command( 'estandar-suite agent run', array( __CLASS__, 'agent_run' ) );
		\WP_CLI::add_command( 'estandar-suite agent chat', array( __CLASS__, 'agent_chat' ) );
		\WP_CLI::add_command( 'estandar-suite agent history', array( __CLASS__, 'agent_history' ) );
		\WP_CLI::add_command( 'estandar-suite agent tools', array( __CLASS__, 'agent_tools' ) );
		\WP_CLI::add_command( 'estandar-suite brain knowledge index', array( __CLASS__, 'knowledge_index' ) );
		\WP_CLI::add_command( 'estandar-suite brain knowledge list', array( __CLASS__, 'knowledge_list' ) );
		\WP_CLI::add_command( 'estandar-suite brain knowledge clear', array( __CLASS__, 'knowledge_clear' ) );
		\WP_CLI::add_command( 'estandar-suite brain rules set', array( __CLASS__, 'rules_set' ) );
		\WP_CLI::add_command( 'estandar-suite brain exporters settings get', array( __CLASS__, 'exporters_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite brain exporters settings set', array( __CLASS__, 'exporters_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite brain exporters adopt', array( __CLASS__, 'exporters_adopt' ) );
		\WP_CLI::add_command( 'estandar-suite brain visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite brain visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite brain visibility reset', array( __CLASS__, 'visibility_reset' ) );
	}

	public static function score( array $args, array $assoc ): void
	{
		$r = Scoring::calculate( array(
			'capture'  => isset( $assoc['capture'] ) ? (int) $assoc['capture'] : null,
			'intent'   => isset( $assoc['intent'] ) ? (int) $assoc['intent'] : null,
			'behavior_events' => array(),
		) );
		\WP_CLI::log( "total={$r['total']} temperatura={$r['temperature']}" );
	}

	public static function signals( array $args, array $assoc ): void
	{
		foreach ( SignalLedger::recent( (int) ( $assoc['limit'] ?? 25 ) ) as $s ) {
			\WP_CLI::log( sprintf( '%s · %s · %s#%d%s', $s['event_time'], $s['event_name'], $s['entity_type'], $s['entity_id'], $s['project_slug'] !== '' ? ' · ' . $s['project_slug'] : '' ) );
		}
	}

	/** wp estandar-suite brain signals-process [--limit=N] */
	public static function signals_process( array $args, array $assoc ): void
	{
		$stats = SignalQueue::process( (int) ( $assoc['limit'] ?? 50 ) );
		\WP_CLI::log( 'cola: ' . wp_json_encode( $stats ) );
	}

	/** wp estandar-suite brain signals-backfill [--limit=N] */
	public static function signals_backfill( array $args, array $assoc ): void
	{
		$stats = SignalQueue::backfill( (int) ( $assoc['limit'] ?? 2000 ) );
		\WP_CLI::log( 'backfill: ' . wp_json_encode( $stats ) );
	}

	public static function adoption_import( array $args, array $assoc ): void
	{
		$lead_id = (int) ( $assoc['lead_id'] ?? 0 );
		if ( $lead_id <= 0 ) {
			\WP_CLI::error( 'Requiere --lead_id.' );
		}
		$dry = empty( $assoc['commit'] );
		$r   = Adoption::import_signals( $lead_id, $dry );
		\WP_CLI::log( ( $dry ? 'DRY-RUN' : 'IMPORTADO' ) . " → señales={$r['recorded']}" );
	}

	public static function signal_adoption_status( array $args, array $assoc ): void
	{
		foreach ( SignalAdoption::status() as $surface => $st ) {
			$legacy = $st['legacy_exists'] ? (string) $st['legacy'] : '—';
			\WP_CLI::log( sprintf( '%-8s legacy=%s adoptado=%d', $surface, $legacy, (int) $st['adopted'] ) );
		}
	}

	public static function signal_adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		foreach ( SignalAdoption::import( $dry, (int) ( $assoc['limit'] ?? 100 ) ) as $surface => $c ) {
			\WP_CLI::log( sprintf( '%-8s %s → importados=%d ya_existentes=%d',
				$surface, $dry ? 'DRY-RUN' : 'IMPORTADO', $c['imported'], $c['existing'] ) );
		}
	}

	public static function signal_adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\WP_CLI::success( "Filas signal adoptadas eliminadas: " . SignalAdoption::rollback() );
	}

	/** wp estandar-suite brain intents [--json] */
	public static function intents( array $args, array $assoc ): void
	{
		$map = IntentMap::mapping();
		\WP_CLI::log( wp_json_encode( $map, ( isset( $assoc['json'] ) ? JSON_PRETTY_PRINT : 0 ) | JSON_UNESCAPED_UNICODE ) );
	}

	/** wp estandar-suite brain intents set --key=ventas --points=20 (o --payload='{...}') */
	public static function intents_set( array $args, array $assoc ): void
	{
		$map = IntentMap::mapping();
		if ( array_key_exists( 'payload', $assoc ) ) {
			$decoded = json_decode( (string) $assoc['payload'], true );
			if ( ! is_array( $decoded ) ) {
				\WP_CLI::error( '--payload no es un objeto JSON válido.' );
			}
			foreach ( $decoded as $k => $v ) {
				$k = sanitize_key( $k );
				if ( $k !== '' ) {
					$map[ $k ] = max( 0, min( 100, (int) $v ) );
				}
			}
		} else {
			$key = sanitize_key( (string) ( $assoc['key'] ?? '' ) );
			$points = isset( $assoc['points'] ) ? (int) $assoc['points'] : null;
			if ( $key === '' || $points === null ) {
				\WP_CLI::error( 'Requiere --key y --points (o --payload).' );
			}
			$map[ $key ] = max( 0, min( 100, $points ) );
		}
		update_option( IntentMap::OPTION, $map, false );
		\WP_CLI::success( 'Mapa de intents actualizado.' );
		\WP_CLI::log( wp_json_encode( IntentMap::mapping(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/** wp estandar-suite brain rules [--json] */
	public static function rules( array $args, array $assoc ): void
	{
		$rules = Rules::load();
		\WP_CLI::log( wp_json_encode( array_values( $rules ), ( isset( $assoc['json'] ) ? JSON_PRETTY_PRINT : 0 ) | JSON_UNESCAPED_UNICODE ) );
	}

	/** wp estandar-suite brain rules set --payload='[...]' */
	public static function rules_set( array $args, array $assoc ): void
	{
		$decoded = json_decode( (string) ( $assoc['json'] ?? '' ), true );
		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( 'Requiere --json con un array de reglas.' );
		}
		$clean = array();
		foreach ( $decoded as $rule ) {
			if ( is_array( $rule ) && Rules::normalize( $rule ) !== null ) {
				$clean[] = $rule;
			}
		}
		update_option( Rules::OPTION, array( 'global' => $clean ), false );
		\WP_CLI::success( 'Reglas actualizadas (' . count( $clean ) . ' válidas).' );
	}

	public static function visibility_get( array $args, array $assoc ): void
	{
		$map = \Estandar\Core\Admin::get_visibility( 'brain' );
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
			\WP_CLI::error( 'Uso: wp estandar-suite brain visibility set <tab> <sub1> [<sub2> ...]' );
		}
		$subs = array_map( 'sanitize_key', $subs );
		$map = \Estandar\Core\Admin::get_visibility( 'brain' );
		$map[ $tab ] = $subs;
		\Estandar\Core\Admin::save_visibility( 'brain', $map );
		\WP_CLI::success( sprintf( 'brain > %s: visibles = %s', $tab, implode( ', ', $subs ) ) );
	}

	public static function visibility_reset( array $args, array $assoc ): void
	{
		delete_option( \Estandar\Core\Admin::visibility_option( 'brain' ) );
		\WP_CLI::success( 'brain: visibilidad restaurada (todo visible).' );
	}

	public static function exporters_settings_get(): void
	{
		\WP_CLI::log( 'Exportadores (Meta/Google):' );
		// FEATURE_GROUP_NESTED_V1: se listan por grupo para no colisionar `enabled`.
		foreach ( \Estandar\Brain\Admin::exporters_settings_groups() as $g => $fs ) {
			\WP_CLI::log( "[{$g}]" );
			\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_brain_exporters_settings', $fs, $g );
		}
	}

	public static function exporters_settings_set( array $args, array $assoc ): void
	{
		$groups = \Estandar\Brain\Admin::exporters_settings_groups();
		$key    = sanitize_key( (string) ( $assoc['key'] ?? '' ) );
		$group  = sanitize_key( (string) ( $assoc['group'] ?? '' ) );

		if ( $group === '' ) {
			// Inferir el grupo por la clave; `enabled` existe en varios → exige --group.
			$matches = array();
			foreach ( $groups as $g => $fs ) {
				if ( isset( $fs[ $key ] ) ) { $matches[] = $g; }
			}
			if ( count( $matches ) === 1 ) {
				$group = $matches[0];
			} elseif ( count( $matches ) > 1 ) {
				\WP_CLI::error( sprintf( "La clave '%s' existe en varios grupos (%s). Usa --group=<grupo>.", $key, implode( ', ', $matches ) ) );
				return;
			} else {
				$flat = array();
				foreach ( $groups as $fs ) { $flat = array_merge( $flat, array_keys( $fs ) ); }
				\WP_CLI::error( 'Clave inválida. Usa: ' . implode( ', ', array_unique( $flat ) ) );
				return;
			}
		}
		if ( ! isset( $groups[ $group ] ) || ! isset( $groups[ $group ][ $key ] ) ) {
			\WP_CLI::error( sprintf( "Clave '%s' no existe en el grupo '%s'.", $key, $group ) );
			return;
		}
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_brain_exporters_settings', $groups[ $group ], $assoc, $group );
	}

	public static function exporters_adopt( array $args, array $assoc ): void
	{
		$force = ! empty( $assoc['force'] );
		$r = \Estandar\Brain\Admin::adopt_legacy_credentials( $force );
		\WP_CLI::log( sprintf( 'adoptados=%d conservados(v2 ya tenía)=%d legados_leidos=%d (nunca se sobreescriben ni borran legados)', $r['adopted'], $r['kept'], $r['legacy_read'] ) );
	}

	/** wp estandar-suite brain knowledge index */
	public static function knowledge_index(): void
	{
		$r = \Estandar\Brain\Knowledge::index_all();
		\WP_CLI::success( sprintf( 'Índice reconstruido: %d contenidos publicados → %d fragmentos.', $r['sources'], $r['chunks'] ) );
	}

	public static function knowledge_list( array $args, array $assoc ): void
	{
		$rows = \Estandar\Brain\Knowledge::list_sources( isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 30 );
		if ( ! $rows ) {
			\WP_CLI::log( 'Índice vacío. Ejecuta: wp estandar-suite brain knowledge index' );
			return;
		}
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'id'          => $r['id'],
				'tipo'        => $r['source_type'],
				'titulo'      => $r['title'],
				'url'         => $r['url'],
				'actualizado' => $r['updated_at'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'tipo', 'titulo', 'url', 'actualizado' ) );
	}

	public static function knowledge_clear(): void
	{
		\Estandar\Brain\Knowledge::clear();
		\WP_CLI::success( 'Índice de conocimiento vaciado.' );
	}

	/** wp estandar-suite agent run "petición" [--confirm] [--confirm=destructive] */
	public static function agent_run( array $args, array $assoc ): void
	{
		$request = trim( implode( ' ', $args ) );
		if ( $request === '' ) {
			\WP_CLI::error( 'Escribe la petición en lenguaje natural: wp estandar-suite agent run "haz un formulario para la página de contactos…"' );
		}
		$confirm    = isset( $assoc['confirm'] ) && $assoc['confirm'] !== 'no';
		$destructive = isset( $assoc['confirm'] ) && $assoc['confirm'] === 'destructive';
		$result = \Estandar\Brain\Agent::run( $request, array( 'confirm' => $confirm, 'destructive' => $destructive ) );
		$needs  = false;
		foreach ( $result['results'] as $r ) {
			if ( isset( $r['need_confirm'] ) && $r['need_confirm'] ) {
				$needs = true;
			}
		}
		\WP_CLI::log( '' );
		foreach ( $result['results'] as $r ) {
			if ( isset( $r['dry_run'] ) ) {
				\WP_CLI::log( '· (dry-run) ' . $r['summary'] );
			} elseif ( isset( $r['need_confirm'] ) ) {
				\WP_CLI::log( '! Pendiente de confirmación: ' . $r['summary'] );
				\WP_CLI::log( '  → vuelve a ejecutar añadiendo ' . ( ! empty( $r['destructive'] ) ? '--confirm=destructive' : '--confirm' ) );
			} elseif ( ! empty( $r['output'] ) ) {
				\WP_CLI::log( '✓ ' . ( $r['summary'] ?? $r['message'] ?? 'ok' ) );
				\WP_CLI::log( (string) $r['output'] );
			} else {
				\WP_CLI::log( '✓ ' . ( $r['message'] ?? $r['summary'] ?? 'ok' ) );
			}
			if ( ! empty( $r['error'] ) ) {
				\WP_CLI::log( '✗ ' . $r['error'] );
			}
		}
		if ( $needs ) {
			\WP_CLI::warning( 'Revisa las acciones pendientes y confírmalas para ejecutarlas.' );
		}
	}

	/** Sesión de chat interactiva (stdin): misma NLU + confirmaciones inline. */
	public static function agent_chat( array $args, array $assoc ): void
	{
		\WP_CLI::log( 'Chat del agente interno — escribe tu petición (salir/exit/quit para terminar).' );
		while ( true ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'tú > ' );
			$line = trim( (string) fgets( STDIN ) );
			if ( $line === '' ) {
				continue;
			}
			if ( in_array( strtolower( $line ), array( 'salir', 'exit', 'quit', 'q' ), true ) ) {
				break;
			}
			$run = \Estandar\Brain\Agent::run( $line, array() );
			\WP_CLI::log( 'agente > ' . \Estandar\Brain\Agent::respond( $run ) );
			foreach ( $run['results'] as $r ) {
				if ( ! empty( $r['need_confirm'] ) ) {
					\WP_CLI::log( '¿Confirmo? [y/N]: ' );
					$a = strtolower( trim( (string) fgets( STDIN ) ) );
					if ( $a === 'y' ) {
						$res = \Estandar\Brain\Agent::run( $line, array( 'confirm' => true, 'destructive' => ! empty( $r['destructive'] ) ) );
						\WP_CLI::log( 'agente > ' . \Estandar\Brain\Agent::respond( $res ) );
					}
				}
			}
		}
	}

	public static function agent_history( array $args, array $assoc ): void
	{
		$rows = \Estandar\Brain\Agent::history( isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 20 );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin ejecuciones del agente.' );
			return;
		}
		$items = array();
		foreach ( $rows as $r ) {
			$plan = json_decode( (string) $r['plan'], true );
			$sum  = array();
			foreach ( (array) $plan as $a ) {
				$sum[] = \Estandar\Brain\Agent::summarize( $a['tool'] ?? '', $a['params'] ?? array() );
			}
			$items[] = array(
				'id'         => $r['id'],
				'fecha'      => $r['created_at'],
				'usuario'    => $r['user_email'],
				'estado'     => $r['status'],
				'peticion'   => $r['request'],
				'plan'       => $sum ? implode( ' | ', $sum ) : '—',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'fecha', 'usuario', 'estado', 'peticion', 'plan' ) );
	}

	public static function agent_tools(): void
	{
		\WP_CLI::log( 'Tools del agente (NLU por intents/reglas + passthrough WP-CLI):' );
		foreach ( \Estandar\Brain\Agent::tools() as $id => $tool ) {
			\WP_CLI::log( sprintf( '  %-28s [%s] %s', $id, $tool['level'], $tool['help'] ) );
		}
		\WP_CLI::log( '  Tambien ejecuta comandos WP-CLI nativos (plugin list, user list, option get…).' );
		\WP_CLI::log( '  Ejemplos:' );
		\WP_CLI::log( '    wp estandar-suite agent "listar plugins"' );
		\WP_CLI::log( '    wp estandar-suite agent run "haz un formulario para la página de contactos que mande notificaciones al correo del admin"' );
		\WP_CLI::log( '    wp estandar-suite agent "option get siteurl"' );
	}
}

