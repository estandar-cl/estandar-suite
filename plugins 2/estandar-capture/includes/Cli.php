<?php

namespace Estandar\Capture;

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
		\WP_CLI::add_command( 'estandar-suite capture ingest', array( __CLASS__, 'ingest' ) );
		\WP_CLI::add_command( 'estandar-suite capture adoption status', array( __CLASS__, 'adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite capture adoption import', array( __CLASS__, 'adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite capture adoption rollback', array( __CLASS__, 'adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms list', array( __CLASS__, 'forms_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms legacy', array( __CLASS__, 'forms_legacy' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms adopt', array( __CLASS__, 'forms_adopt' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms create', array( __CLASS__, 'forms_create' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms update', array( __CLASS__, 'forms_update' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms delete', array( __CLASS__, 'forms_delete' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms restore', array( __CLASS__, 'forms_restore' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms duplicate', array( __CLASS__, 'forms_duplicate' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms purge', array( __CLASS__, 'forms_purge' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms trash list', array( __CLASS__, 'forms_trash_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms summary', array( __CLASS__, 'forms_summary' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms entries', array( __CLASS__, 'forms_entries' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms responses', array( __CLASS__, 'forms_responses' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms adoption status', array( __CLASS__, 'forms_adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms migrate', array( __CLASS__, 'forms_migrate' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms migrate-rollback', array( __CLASS__, 'forms_migrate_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite capture form inspect', array( __CLASS__, 'form_inspect' ) );
		\WP_CLI::add_command( 'estandar-suite capture form validate', array( __CLASS__, 'form_validate' ) );
		\WP_CLI::add_command( 'estandar-suite capture form snapshot', array( __CLASS__, 'form_snapshot' ) );
		\WP_CLI::add_command( 'estandar-suite capture form diff', array( __CLASS__, 'form_diff' ) );
		\WP_CLI::add_command( 'estandar-suite capture form restore', array( __CLASS__, 'form_restore' ) );
		\WP_CLI::add_command( 'estandar-suite capture form reset', array( __CLASS__, 'form_reset' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms migrate-usages', array( __CLASS__, 'forms_migrate_usages' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms entries-import', array( __CLASS__, 'forms_entries_import' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms entries-rollback', array( __CLASS__, 'forms_entries_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms responses-import', array( __CLASS__, 'forms_responses_import' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms responses-rollback', array( __CLASS__, 'forms_responses_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms settings get', array( __CLASS__, 'forms_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms settings set', array( __CLASS__, 'forms_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot list', array( __CLASS__, 'chatbot_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot summary', array( __CLASS__, 'chatbot_summary' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot leads', array( __CLASS__, 'chatbot_leads' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot logs', array( __CLASS__, 'chatbot_logs' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot settings get', array( __CLASS__, 'chatbot_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture chatbot settings set', array( __CLASS__, 'chatbot_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups list', array( __CLASS__, 'popups_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups create', array( __CLASS__, 'popups_create' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups update', array( __CLASS__, 'popups_update' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups delete', array( __CLASS__, 'popups_delete' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups settings get', array( __CLASS__, 'popups_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture popups settings set', array( __CLASS__, 'popups_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture leads list', array( __CLASS__, 'leads_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture leads summary', array( __CLASS__, 'leads_summary' ) );
		\WP_CLI::add_command( 'estandar-suite capture leads settings get', array( __CLASS__, 'leads_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture leads settings set', array( __CLASS__, 'leads_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture leads credentials adopt', array( __CLASS__, 'leads_credentials_adopt' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys list', array( __CLASS__, 'surveys_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys create', array( __CLASS__, 'surveys_create' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys update', array( __CLASS__, 'surveys_update' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys delete', array( __CLASS__, 'surveys_delete' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys restore', array( __CLASS__, 'surveys_restore' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys duplicate', array( __CLASS__, 'surveys_duplicate' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys purge', array( __CLASS__, 'surveys_purge' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys responses', array( __CLASS__, 'surveys_responses' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys summary', array( __CLASS__, 'surveys_summary' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys settings get', array( __CLASS__, 'surveys_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture surveys settings set', array( __CLASS__, 'surveys_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa list', array( __CLASS__, 'postventa_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa create', array( __CLASS__, 'postventa_create' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa update', array( __CLASS__, 'postventa_update' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa delete', array( __CLASS__, 'postventa_delete' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa restore', array( __CLASS__, 'postventa_restore' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa duplicate', array( __CLASS__, 'postventa_duplicate' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa purge', array( __CLASS__, 'postventa_purge' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa entries', array( __CLASS__, 'postventa_entries' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa summary', array( __CLASS__, 'postventa_summary' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa settings get', array( __CLASS__, 'postventa_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture postventa settings set', array( __CLASS__, 'postventa_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture products caps get', array( __CLASS__, 'caps_get' ) );
		\WP_CLI::add_command( 'estandar-suite capture products caps set', array( __CLASS__, 'caps_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture visibility get', array( __CLASS__, 'visibility_get' ) );		\WP_CLI::add_command( 'estandar-suite capture visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite capture visibility reset', array( __CLASS__, 'visibility_reset' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms connect-sheet', array( __CLASS__, 'forms_connect_sheet' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms integrations list', array( __CLASS__, 'forms_integrations_list' ) );
		\WP_CLI::add_command( 'estandar-suite capture forms integrations disconnect', array( __CLASS__, 'forms_integrations_disconnect' ) );
		\WP_CLI::add_command( 'estandar-suite capture webhook key', array( __CLASS__, 'webhook_key' ) );
	}

	public static function ingest( array $args, array $assoc ): void
	{
		if ( empty( $assoc['source'] ) || empty( $assoc['name'] ) ) {
			\WP_CLI::error( 'Requiere --source y --name.' );
		}
		$result = Ingestion::ingest( array(
			'source'    => $assoc['source'],
			'contact'   => array( 'name' => $assoc['name'], 'email' => $assoc['email'] ?? '', 'phone' => $assoc['phone'] ?? '' ),
			'content'   => array( 'message' => $assoc['message'] ?? '' ),
			'attribution' => array( 'utm_source' => $assoc['utm_source'] ?? '' ),
			'spam'      => array(),
		) );
		\WP_CLI::success( empty( $result['id'] ) ? 'No persistido (identidad ausente).' : "Captura #{$result['id']}" );
	}

	public static function adoption_status(): void
	{
		\WP_CLI::log( Adoption::legacy_present() ? 'Instalación legada: leads históricos disponibles.' : 'Sin tablas legadas de submissions.' );
	}

	public static function adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r   = Adoption::import( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( $dry ? 'DRY-RUN (usa --commit para importar).' : 'IMPORTADO.' );
		\WP_CLI::log( "importados={$r['imported']} ya_existentes={$r['existing']} skipped={$r['skipped']}" );
	}

	public static function adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes (borra solo los registros adoptados).' );
		}
		$n = Adoption::rollback_import();
		\WP_CLI::success( "Registros adoptados eliminados: {$n}" );
	}

	public static function forms_list(): void
	{
		$rows = \Estandar\Capture\Forms::list();
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin formularios v2.' );
			return;
		}
		foreach ( $rows as $f ) {
			\WP_CLI::log( sprintf( '#%d %s (%s) [%s] %s', (int) $f['id'], $f['name'], $f['slug'], $f['form_type'], $f['status'] ) );
		}
	}

	public static function forms_legacy(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id DESC LIMIT 200' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin formularios legados.' );
			return;
		}
		foreach ( $rows as $f ) {
			\WP_CLI::log( sprintf( '#%d %s (%s) [%s]', (int) $f['id'], $f['name'], $f['slug'], $f['form_type'] ) );
		}
	}

	public static function forms_adopt( array $args, array $assoc ): void
	{
		$dry = ! empty( $assoc['dry-run'] );
		$r = \Estandar\Capture\Forms::adopt_legacy( $dry );
		\WP_CLI::log( $dry ? 'DRY-RUN (usa sin --dry-run para adoptar).' : 'ADOPTADO.' );
		\WP_CLI::log( sprintf( 'adoptados=%d ya_existentes=%d total_legado=%d', $r['adopted'], $r['skipped'], $r['total'] ) );
	}

	public static function forms_create( array $args, array $assoc ): void
	{
		if ( empty( $assoc['name'] ) ) {
			\WP_CLI::error( 'Requiere --name.' );
		}
		$id = \Estandar\Capture\Forms::create( array(
			'name'         => $assoc['name'],
			'slug'         => $assoc['slug'] ?? '',
			'form_type'    => $assoc['type'] ?? 'lead',
			'form_context' => $assoc['contexto'] ?? 'generic',
			'config_json'  => $assoc['config'] ?? '{}',
			'status'       => $assoc['status'] ?? 'active',
		) );
		$id ? \WP_CLI::success( "Formulario creado #{$id}" ) : \WP_CLI::error( 'No se pudo crear (nombre requerido).' );
	}

	public static function forms_update( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id || ! \Estandar\Capture\Forms::get( $id ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture forms update <id> --name=... [--type=...]' );
		}
		$cur = \Estandar\Capture\Forms::get( $id );
		$raw = array(
			'name'         => $assoc['name'] ?? $cur['name'],
			'slug'         => $assoc['slug'] ?? $cur['slug'],
			'form_type'    => $assoc['type'] ?? $cur['form_type'],
			'form_context' => $assoc['contexto'] ?? $cur['form_context'],
			'config_json'  => $assoc['config'] ?? $cur['config_json'],
			'status'       => $assoc['status'] ?? $cur['status'],
		);
		\Estandar\Capture\Forms::update( $id, $raw );
		\WP_CLI::success( "Formulario #{$id} actualizado." );
	}

	public static function forms_delete( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\Estandar\Capture\Forms::trash( $id )
			? \WP_CLI::success( "Formulario #{$id} movido a la papelera." )
			: \WP_CLI::error( "No existe el formulario #{$id}." );
	}

	public static function forms_restore( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		\Estandar\Capture\Forms::restore( $id )
			? \WP_CLI::success( "Formulario #{$id} restaurado." )
			: \WP_CLI::error( "No existe el formulario #{$id}." );
	}

	public static function forms_duplicate( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$new = \Estandar\Capture\Forms::duplicate( $id );
		$new ? \WP_CLI::success( "Formulario duplicado → #{$new}" ) : \WP_CLI::error( "No existe el formulario #{$id}." );
	}

	public static function forms_purge( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes (eliminación definitiva).' );
		}
		\Estandar\Capture\Forms::delete( $id )
			? \WP_CLI::success( "Formulario #{$id} eliminado definitivamente." )
			: \WP_CLI::error( "No existe el formulario #{$id}." );
	}

	public static function forms_trash( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		\Estandar\Capture\Forms::trash( $id )
			? \WP_CLI::success( "Formulario #{$id} movido a la papelera." )
			: \WP_CLI::error( "No existe el formulario #{$id}." );
	}

	public static function forms_trash_list(): void
	{
		$rows = \Estandar\Capture\Forms::list_trashed();
		if ( ! $rows ) {
			\WP_CLI::log( 'La papelera está vacía.' );
			return;
		}
		foreach ( $rows as $f ) {
			\WP_CLI::log( sprintf( '#%d %s (%s) [%s] eliminado %s', (int) $f['id'], $f['name'], $f['slug'], $f['form_type'], $f['updated_at'] ) );
		}
	}

	public static function forms_summary(): void
	{
		\WP_CLI::log( sprintf( 'forms_v2=%d forms_legado=%d entradas=%d encuestas=%d incidentes=%d',
			count( \Estandar\Capture\Forms::list() ),
			\Estandar\Core\Admin::legacy_count( 'estandar_forms' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_form_entries' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_survey_responses' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_incidents' )
		) );
	}

	/** wp estandar-suite capture form inspect <id> — inspección completa. */
	public static function form_inspect( array $args ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$d  = \Estandar\Capture\Forms::inspect( $id );
		if ( empty( $d['ok'] ) ) {
			\WP_CLI::error( 'Formulario ' . $id . ': ' . ( $d['error'] ?? 'inexistente' ) );
		}
		\WP_CLI::log( sprintf( '#%d %s [%s/%s] status=%s', $d['id'], $d['name'], $d['form_type'], $d['form_context'], $d['status'] ) );
		\WP_CLI::log( 'config_keys: ' . implode( ', ', $d['config_keys'] ) );
		\WP_CLI::log( sprintf( 'sections=%d fields=%d has_content=%s has_fields=%s config_len=%d',
			$d['sections'], $d['fields_total'], $d['has_content'] ? 'si' : 'no', $d['has_fields'] ? 'si' : 'no', $d['config_len'] ) );
		$rv = $d['repeat_visibility'];
		\WP_CLI::log( sprintf( 'repeat_visibility: mode=%s duration=%d %s (enabled=%s)',
			$rv['mode'], $rv['duration'], $rv['unit'], $rv['enabled'] ? 'si' : 'no' ) );
		if ( $d['has_fields'] ) {
			foreach ( $d['fields'] as $f ) {
				\WP_CLI::log( '  field: ' . ( $f['name'] ?? '' ) . ' [' . ( $f['type'] ?? '' ) . '] ' . ( $f['label'] ?? '' ) );
			}
		} else {
			\WP_CLI::log( '  (sin campos renderizables — config puede estar vacía o dañada)' );
		}
	}

	/** wp estandar-suite capture form validate <id> — validación del documento. */
	public static function form_validate( array $args ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$v  = \Estandar\Capture\Forms::validate( $id );
		if ( $v['valid'] ) {
			\WP_CLI::success( 'Formulario ' . $id . ' válido.' );
			return;
		}
		foreach ( (array) $v['errors'] as $e ) {
			\WP_CLI::warning( $e );
		}
		\WP_CLI::error( 'Formulario ' . $id . ' inválido.' );
	}

	/** wp estandar-suite capture form snapshot <id> — guarda snapshot en option. */
	public static function form_snapshot( array $args ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$s  = \Estandar\Capture\Forms::snapshot_config( $id );
		if ( ! $s ) {
			\WP_CLI::error( 'Formulario ' . $id . ' inexistente.' );
		}
		update_option( 'estandar_suite_capture_form_snapshot_' . $id, $s, false );
		\WP_CLI::success( 'Snapshot #' . $id . ' guardado (' . strlen( $s['config_json'] ) . ' bytes).' );
	}

	/**
	 * wp estandar-suite capture form diff <id> [--against=<snapshot|file>]
	 * Compara el config actual contra un snapshot previo (option o archivo JSON).
	 */
	public static function form_diff( array $args, array $assoc ): void
	{
		$id   = isset( $args[0] ) ? (int) $args[0] : 0;
		$cur  = \Estandar\Capture\Forms::snapshot_config( $id );
		if ( ! $cur ) {
			\WP_CLI::error( 'Formulario ' . $id . ' inexistente.' );
		}
		$against = $assoc['against'] ?? '';
		$snap = array();
		if ( $against !== '' ) {
			if ( is_file( $against ) ) {
				$data = file_get_contents( $against );
				$snap = json_decode( (string) $data, true );
				$snap = is_array( $snap ) ? $snap : array( 'config_json' => (string) $data );
			} else {
				$s = get_option( 'estandar_suite_capture_form_snapshot_' . $id, array() );
				$snap = is_array( $s ) ? $s : array();
			}
		}
		$base = isset( $snap['config_json'] ) ? (string) $snap['config_json'] : '{}';
		$cur_cfg = json_decode( (string) $cur['config_json'], true );
		$old_cfg = json_decode( $base, true );
		$cur_cfg = is_array( $cur_cfg ) ? $cur_cfg : array();
		$old_cfg = is_array( $old_cfg ) ? $old_cfg : array();
		if ( $cur_cfg === $old_cfg ) {
			\WP_CLI::success( 'Sin diferencias (config idéntica al snapshot).' );
			return;
		}
		$keys = array_unique( array_merge( array_keys( $cur_cfg ), array_keys( $old_cfg ) ) );
		foreach ( $keys as $k ) {
			$cv = wp_json_encode( $cur_cfg[ $k ] ?? null, JSON_UNESCAPED_UNICODE );
			$ov = wp_json_encode( $old_cfg[ $k ] ?? null, JSON_UNESCAPED_UNICODE );
			if ( $cv !== $ov ) {
				\WP_CLI::log( $k . ': ' . ( array_key_exists( $k, $old_cfg ) ? 'cambió' : 'NUEVA' ) );
			}
		}
		\WP_CLI::error( 'Hay diferencias (ver arriba).' );
	}

	/**
	 * wp estandar-suite capture form restore <id> [--dry-run] [--confirm=yes]
	 *
	 * Por defecto es DRY-RUN: calcula el merge no destructivo desde legacy y
	 * reporta origen, diferencias, campos, secciones, config y riesgos SIN
	 * escribir. Solo con --confirm=yes persiste.
	 */
	public static function form_restore( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$dry = ( $assoc['confirm'] ?? '' ) !== 'yes';
		if ( isset( $assoc['dry-run'] ) ) {
			$dry = true;
		}
		$v = \Estandar\Capture\Forms::validate( $id );
		if ( $v['valid'] ) {
			\WP_CLI::success( 'Formulario ' . $id . ' ya es válido; no se requiere recuperación.' );
			return;
		}
		$r = \Estandar\Capture\Forms::restore_legacy_config( $id, $dry );
		if ( empty( $r['ok'] ) ) {
			\WP_CLI::error( 'No se pudo recuperar: ' . implode( ', ', $r['reasons'] ) );
		}
		\WP_CLI::log( 'Origen utilizado: ' . $r['origin'] );
		\WP_CLI::log( sprintf( 'Campos a restaurar: %d · Secciones: %d', $r['fields_count'], $r['sections'] ) );
		foreach ( $r['fields'] as $f ) {
			\WP_CLI::log( '  campo: ' . ( $f['name'] ?? '' ) . ' [' . ( $f['type'] ?? '' ) . '] ' . ( $f['label'] ?? '' ) );
		}
		if ( $r['restored'] ) {
			$cur = json_decode( (string) $r['diff']['before'], true );
			$new = json_decode( (string) $r['diff']['after'], true );
			\WP_CLI::log( 'Diferencias (claves que cambian):' );
			foreach ( array_unique( array_merge( array_keys( $cur ), array_keys( $new ) ) ) as $k ) {
				if ( ( $cur[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) {
					\WP_CLI::log( '  ' . $k . ': ' . ( array_key_exists( $k, $cur ) ? 'cambia' : 'NUEVA' ) );
				}
			}
			\WP_CLI::log( 'Riesgos: merge no destructivo; conserva claves v2 no-legacy; no pisa campos existentes.' );
			if ( $dry ) {
				\WP_CLI::warning( 'DRY-RUN: no se escribió nada. Revisa el diff y ejecuta con --confirm=yes.' );
			} else {
				\WP_CLI::success( 'Restauración #' . $id . ' aplicada (' . ( $r['wrote'] ? 'escritura' : 'sin cambios, ya completo' ) . ').' );
			}
			return;
		}
		\WP_CLI::log( 'Sin diferencias: el config actual ya contiene el contenido legacy.' );
	}

	/**
	 * wp estandar-suite capture form reset <id> --confirm=yes
	 *
	 * Acción EXPLÍCITA de "Vaciar Formulario": única vía que permite degradar
	 * config_json a {}. Protegida por confirm + no se ejecuta si hay contenido
	 * recuperable desde legacy sin confirmación adicional.
	 */
	public static function form_reset( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ( $assoc['confirm'] ?? '' ) !== 'yes' ) {
			\WP_CLI::warning( 'Acción destructiva. Ejecuta con --confirm=yes para vaciar config_json a {}.' );
			return;
		}
		if ( ! \Estandar\Capture\Forms::get( $id ) ) {
			\WP_CLI::error( 'Formulario ' . $id . ' inexistente.' );
		}
		$ok = \Estandar\Capture\Forms::update( $id, array(
			'name'         => \Estandar\Capture\Forms::get( $id )['name'],
			'form_type'    => 'lead',
			'form_context' => 'generic',
			'status'       => 'active',
			'config_json'  => '{}',
		), true );
		if ( $ok ) {
			\WP_CLI::success( 'Formulario ' . $id . ' vaciado explícitamente (config_json={}).' );
		} else {
			\WP_CLI::error( 'No se pudo vaciar el formulario ' . $id . '.' );
		}
	}

	public static function forms_entries( array $args, array $assoc ): void
	{
		$form_id = isset( $assoc['form_id'] ) ? (int) $assoc['form_id'] : 0;
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_form_entries', 'ORDER BY id DESC LIMIT 500' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin entradas legadas.' );
			return;
		}
		$shown = 0;
		foreach ( $rows as $e ) {
			if ( $form_id && (int) $e['form_id'] !== $form_id ) { continue; }
			\WP_CLI::log( sprintf( '#%d form=%d [%s] %s', (int) $e['id'], (int) $e['form_id'], $e['status'], $e['created_at'] ) );
			if ( ++$shown >= 50 ) { break; }
		}
		if ( ! $shown ) { \WP_CLI::log( 'Sin entradas para el filtro.' ); }
	}

	public static function forms_responses( array $args, array $assoc ): void
	{
		$form_id = isset( $assoc['form_id'] ) ? (int) $assoc['form_id'] : 0;
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_survey_responses', 'ORDER BY id DESC LIMIT 500' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin respuestas de encuestas.' );
			return;
		}
		$shown = 0;
		foreach ( $rows as $r ) {
			if ( $form_id && (int) $r['form_id'] !== $form_id ) { continue; }
			\WP_CLI::log( sprintf( '#%d survey=%d | %s → %s (%s)', (int) $r['id'], (int) $r['form_id'], $r['question_label'], $r['answer_value'] !== '' ? $r['answer_value'] : $r['answer_label'], $r['answered_at'] ) );
			if ( ++$shown >= 50 ) { break; }
		}
		if ( ! $shown ) { \WP_CLI::log( 'Sin respuestas para el filtro.' ); }
	}

	public static function forms_adoption_status(): void
	{
		$counts = FormsAdoption::resource_counts();
		\WP_CLI::log( sprintf( 'tablas legacy: %s', $counts['estandar_forms'] ? 'presentes' : 'ausentes' ) );
		\WP_CLI::log( sprintf( 'forms=%d entries=%d survey_responses=%d incidents=%d logs=%d requests=%d attachments=%d tokens=%d', (int) $counts['estandar_forms'], (int) $counts['estandar_form_entries'], (int) $counts['estandar_survey_responses'], (int) $counts['estandar_incidents'], (int) $counts['estandar_form_logs'], (int) $counts['estandar_requests'], (int) $counts['estandar_attachments'], (int) $counts['estandar_tokens'] ) );
		\WP_CLI::log( 'v2 importado: forms=' . count( Forms::list() ) . ' entries_form=' . \Estandar\Core\Admin::legacy_count( 'estandar_capture_submissions' ) );
	}

	public static function forms_entries_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r = FormsAdoption::import_entries( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( $dry ? 'DRY-RUN (usa --commit para importar).' : 'IMPORTADO.' );
		\WP_CLI::log( sprintf( 'importados=%d ya_existentes=%d dry_run=%d', $r['imported'], $r['existing'], (int) $r['dry_run'] ) );
	}

	public static function forms_entries_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\WP_CLI::log( sprintf( 'rollback entries: %d filas eliminadas (solo adoptadas source=form).', FormsAdoption::rollback_entries() ) );
	}

	public static function forms_responses_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r = FormsAdoption::import_responses( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( $dry ? 'DRY-RUN (usa --commit para importar).' : 'IMPORTADO.' );
		\WP_CLI::log( sprintf( 'importadas=%d ya_existentes=%d dry_run=%d', $r['imported'], $r['existing'], (int) $r['dry_run'] ) );
	}

	public static function forms_responses_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\WP_CLI::log( sprintf( 'rollback responses: %d filas eliminadas (solo adoptadas source=survey).', FormsAdoption::rollback_responses() ) );
	}

	/** Migración controlada Forms legacy → nativos. Dry-run por defecto. */
	public static function forms_migrate( array $args, array $assoc ): void
	{
		$dry     = empty( $assoc['commit'] );
		$form_id = isset( $assoc['form-id'] ) ? (int) $assoc['form-id'] : 0;
		$r       = Forms::migrate_legacy( $dry, $form_id, true );

		\WP_CLI::log( $dry ? 'DRY-RUN (usa --commit para migrar).' : 'MIGRADO.' );
		\WP_CLI::log( sprintf(
			'migrados=%d ya_existentes=%d fuera_de_alcance(survey/postventa)=%d total_legacy=%d',
			$r['migrated'], $r['existing'], $r['not_forms'], $r['total']
		) );
		if ( ! empty( $r['forms'] ) ) {
			foreach ( $r['forms'] as $f ) {
				\WP_CLI::log( sprintf( '  #%d %s (tipo %s) parity=%s', (int) $f['id'], $f['name'], $f['type'], $f['parity']['parity'] ? 'OK' : 'DIFF' ) );
				foreach ( $f['parity']['diffs'] as $k => $v ) {
					\WP_CLI::log( '      diff ' . $k . ': ' . $v );
				}
			}
		}
	}

	/** Rollback de migración (solo formularios nativos migrados/adoptados). */
	public static function forms_migrate_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$form_id = isset( $assoc['form-id'] ) ? (int) $assoc['form-id'] : 0;
		\WP_CLI::log( sprintf( 'rollback migración: %d formularios nativos eliminados (legacy intacto).', Forms::rollback_migration( $form_id ) ) );
	}

	/** Usos de un formulario legacy en el frontend (shortcodes en posts). */
	public static function forms_migrate_usages( array $args, array $assoc ): void
	{
		$form_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $form_id <= 0 ) {
			\WP_CLI::error( 'Uso: forms migrate-usages <legacy-form-id>' );
		}
		$uses = Forms::usages( $form_id );
		if ( ! $uses ) {
			\WP_CLI::log( sprintf( 'Form legacy #%d: sin usos detectados en posts.', $form_id ) );
			return;
		}
		\WP_CLI::log( sprintf( 'Form legacy #%d: %d uso(s) de [estandar_form]:', $form_id, count( $uses ) ) );
		foreach ( $uses as $u ) {
			\WP_CLI::log( sprintf( '  post #%d [%s/%s] %s', (int) $u['id'], $u['type'], $u['status'], $u['title'] ) );
		}
	}

	public static function forms_settings_get(): void
	{
		\WP_CLI::log( 'Forms settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_forms_settings', \Estandar\Capture\Admin::feature_fields()['forms'] );
	}

	public static function forms_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_forms_settings', \Estandar\Capture\Admin::feature_fields()['forms'], $assoc );
	}

	public static function chatbot_list(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'ecb_conversations', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin conversaciones legadas.' );
			return;
		}
		foreach ( $rows as $c ) {
			\WP_CLI::log( sprintf( '#%d [%s] %s → %s (%d msgs)', (int) $c['id'], $c['channel'], $c['conversation_status'], $c['lead_email'], (int) $c['message_count'] ) );
		}
	}

	public static function chatbot_summary(): void
	{
		\WP_CLI::log( sprintf( 'conversaciones=%d leads=%d eventos=%d logs=%d',
			\Estandar\Core\Admin::legacy_count( 'ecb_conversations' ),
			\Estandar\Core\Admin::legacy_count( 'ecb_leads' ),
			\Estandar\Core\Admin::legacy_count( 'ecb_events' ),
			\Estandar\Core\Admin::legacy_count( 'ecb_logs' )
		) );
	}

	public static function chatbot_leads(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'ecb_leads', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin leads de chatbot.' );
			return;
		}
		foreach ( $rows as $l ) {
			\WP_CLI::log( sprintf( '#%d %s | %s', (int) $l['id'], $l['email'], $l['phone'] ) );
		}
	}

	public static function chatbot_logs(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'ecb_logs', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin registros de conversación.' );
			return;
		}
		foreach ( $rows as $g ) {
			\WP_CLI::log( sprintf( '#%d %s | Q: %s | A: %s', (int) $g['id'], $g['datetime'], wp_trim_words( (string) $g['question'], 8 ), wp_trim_words( (string) $g['answer'], 8 ) ) );
		}
	}

	public static function chatbot_settings_get(): void
	{
		\WP_CLI::log( 'Chatbot settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_chatbot_settings', \Estandar\Capture\Admin::chatbot_settings_fields() );
	}

	public static function chatbot_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_chatbot_settings', \Estandar\Capture\Admin::chatbot_settings_fields(), $assoc );
	}

	public static function popups_list(): void
	{
		$rows = \Estandar\Capture\Popups::list();
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin popups v2.' );
			return;
		}
		foreach ( $rows as $p ) {
			\WP_CLI::log( sprintf( '#%d %s (%s) [%s]', (int) $p['id'], $p['name'], $p['slug'], $p['status'] ) );
		}
	}

	public static function popups_create( array $args, array $assoc ): void
	{
		if ( empty( $assoc['name'] ) ) {
			\WP_CLI::error( 'Requiere --name.' );
		}
		$id = \Estandar\Capture\Popups::create( array(
			'name'        => $assoc['name'],
			'slug'        => $assoc['slug'] ?? '',
			'status'      => $assoc['status'] ?? 'draft',
			'config_json' => $assoc['config'] ?? '{}',
		) );
		$id ? \WP_CLI::success( "Popup creado #{$id}" ) : \WP_CLI::error( 'No se pudo crear (nombre requerido).' );
	}

	public static function popups_update( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id || ! \Estandar\Capture\Popups::get( $id ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture popups update <id> --name=...' );
		}
		$cur = \Estandar\Capture\Popups::get( $id );
		\Estandar\Capture\Popups::update( $id, array(
			'name'        => $assoc['name'] ?? $cur['name'],
			'slug'        => $assoc['slug'] ?? $cur['slug'],
			'status'      => $assoc['status'] ?? $cur['status'],
			'config_json' => $assoc['config'] ?? $cur['config_json'],
		) );
		\WP_CLI::success( "Popup #{$id} actualizado." );
	}

	public static function popups_delete( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\Estandar\Capture\Popups::delete( $id )
			? \WP_CLI::success( "Popup #{$id} eliminado." )
			: \WP_CLI::error( "No existe el popup #{$id}." );
	}

	public static function popups_settings_get(): void
	{
		\WP_CLI::log( 'Popups settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_popups_settings', \Estandar\Capture\Admin::feature_fields()['popups'] );
	}

	public static function popups_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_popups_settings', \Estandar\Capture\Admin::feature_fields()['popups'], $assoc );
	}

	public static function leads_list(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'es_leads', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin leads legados.' );
			return;
		}
		foreach ( $rows as $l ) {
			\WP_CLI::log( sprintf( '#%d %s | %s | %s [%s]', (int) $l['id'], $l['name'], $l['email_normalized'] ? $l['email_normalized'] : $l['email'], $l['first_source_plugin'], $l['status'] ) );
		}
	}

	public static function leads_summary(): void
	{
		\WP_CLI::log( sprintf( 'leads=%d submissions=%d eventos=%d capturas_v2=%d',
			\Estandar\Core\Admin::legacy_count( 'es_leads' ),
			\Estandar\Core\Admin::legacy_count( 'es_lead_submissions' ),
			\Estandar\Core\Admin::legacy_count( 'es_lead_events' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_capture_submissions' )
		) );
	}

	public static function leads_settings_get(): void
	{
		\WP_CLI::log( 'Leads settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_leads_settings', \Estandar\Capture\Admin::feature_fields()['leads'] );
	}

	public static function leads_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_leads_settings', \Estandar\Capture\Admin::leads_settings_fields(), $assoc );
	}

	public static function leads_credentials_adopt( array $args, array $assoc ): void
	{
		$r = \Estandar\Capture\Admin::adopt_leads_credentials( ! empty( $assoc['force'] ) );
		\WP_CLI::log( sprintf( 'adoptados=%d conservados(v2 ya tenía)=%d legados_leidos=%d (nunca se sobreescriben ni borran legados)', $r['adopted'], $r['kept'], $r['legacy_read'] ) );
	}

	// ── Surveys / Postventa (productos sobre Forms::list por tipo) ───────

	private static function product_rows( string $type ): array
	{
		$out = array();
		foreach ( \Estandar\Capture\Forms::list() as $f ) {
			if ( (string) $f['form_type'] === $type ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	private static function product_write( string $type, array $assoc ): void
	{
		if ( empty( $assoc['name'] ) ) {
			\WP_CLI::error( 'Requiere --name.' );
		}
		$id = \Estandar\Capture\Forms::create( array(
			'name'         => $assoc['name'],
			'slug'         => $assoc['slug'] ?? '',
			'form_type'    => $type,
			'form_context' => $assoc['contexto'] ?? 'generic',
			'config_json'  => $assoc['config'] ?? '{}',
			'status'       => $assoc['status'] ?? 'active',
		) );
		$id ? \WP_CLI::success( "Creado #{$id} ({$type})" ) : \WP_CLI::error( 'No se pudo crear (nombre requerido).' );
	}

	private static function product_update( string $type, array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id || ! \Estandar\Capture\Forms::get( $id ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture <producto> update <id> --name=...' );
		}
		$cur = \Estandar\Capture\Forms::get( $id );
		\Estandar\Capture\Forms::update( $id, array(
			'name'         => $assoc['name'] ?? $cur['name'],
			'slug'         => $assoc['slug'] ?? $cur['slug'],
			'form_type'    => $cur['form_type'],
			'form_context' => $assoc['contexto'] ?? $cur['form_context'],
			'config_json'  => $assoc['config'] ?? $cur['config_json'],
			'status'       => $assoc['status'] ?? $cur['status'],
		) );
		\WP_CLI::success( "#{$id} actualizado." );
	}

	private static function product_delete( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\Estandar\Capture\Forms::trash( $id )
			? \WP_CLI::success( "#{$id} movido a la papelera." )
			: \WP_CLI::error( "No existe #{$id}." );
	}

	private static function product_restore( array $args ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		\Estandar\Capture\Forms::restore( $id )
			? \WP_CLI::success( "#{$id} restaurado." )
			: \WP_CLI::error( "No existe #{$id}." );
	}

	private static function product_duplicate( array $args ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		$new = \Estandar\Capture\Forms::duplicate( $id );
		$new ? \WP_CLI::success( "Duplicado → #{$new}" ) : \WP_CLI::error( "No existe #{$id}." );
	}

	private static function product_purge( array $args, array $assoc ): void
	{
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes (eliminación definitiva).' );
		}
		\Estandar\Capture\Forms::delete( $id )
			? \WP_CLI::success( "#{$id} eliminado definitivamente." )
			: \WP_CLI::error( "No existe #{$id}." );
	}

	private static function product_entries( string $type, array $assoc ): void
	{
		$form_id = isset( $assoc['form_id'] ) ? (int) $assoc['form_id'] : 0;
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_form_entries', 'ORDER BY id DESC LIMIT 500' );
		$shown = 0;
		foreach ( $rows as $e ) {
			if ( $form_id && (int) $e['form_id'] !== $form_id ) { continue; }
			\WP_CLI::log( sprintf( '#%d form=%d [%s] %s', (int) $e['id'], (int) $e['form_id'], $e['status'], $e['created_at'] ) );
			if ( ++$shown >= 50 ) { break; }
		}
		if ( ! $shown ) { \WP_CLI::log( 'Sin entradas para el filtro.' ); }
	}

	private static function product_responses( string $type, array $assoc ): void
	{
		$form_id = isset( $assoc['form_id'] ) ? (int) $assoc['form_id'] : 0;
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_survey_responses', 'ORDER BY id DESC LIMIT 500' );
		$shown = 0;
		foreach ( $rows as $r ) {
			if ( $form_id && (int) $r['form_id'] !== $form_id ) { continue; }
			\WP_CLI::log( sprintf( '#%d survey=%d | %s → %s', (int) $r['id'], (int) $r['form_id'], $r['question_label'], $r['answer_value'] !== '' ? $r['answer_value'] : $r['answer_label'] ) );
			if ( ++$shown >= 50 ) { break; }
		}
		if ( ! $shown ) { \WP_CLI::log( 'Sin respuestas para el filtro.' ); }
	}

	private static function product_summary( string $type, string $label ): void
	{
		\WP_CLI::log( sprintf( '%s=%d', $label, count( self::product_rows( $type ) ) ) );
	}

	public static function surveys_list(): void
	{
		$rows = self::product_rows( 'survey' );
		if ( ! $rows ) { \WP_CLI::log( 'Sin encuestas.' ); return; }
		foreach ( $rows as $f ) { \WP_CLI::log( sprintf( '#%d %s (%s) [%s]', (int) $f['id'], $f['name'], $f['slug'], $f['status'] ) ); }
	}
	public static function surveys_create( array $a, array $as ): void { self::product_write( 'survey', $as ); }
	public static function surveys_update( array $a, array $as ): void { self::product_update( 'survey', $a, $as ); }
	public static function surveys_delete( array $a, array $as ): void { self::product_delete( $a, $as ); }
	public static function surveys_restore( array $a, array $as ): void { self::product_restore( $a ); }
	public static function surveys_duplicate( array $a, array $as ): void { self::product_duplicate( $a ); }
	public static function surveys_purge( array $a, array $as ): void { self::product_purge( $a, $as ); }
	public static function surveys_responses( array $a, array $as ): void { self::product_responses( 'survey', $as ); }
	public static function surveys_summary(): void { self::product_summary( 'survey', 'encuestas' ); }
	public static function surveys_settings_get(): void { \WP_CLI::log( 'Surveys settings:' ); \Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_surveys_settings', \Estandar\Capture\Admin::feature_fields()['surveys'] ); }
	public static function surveys_settings_set( array $a, array $as ): void { \Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_surveys_settings', \Estandar\Capture\Admin::feature_fields()['surveys'], $as ); }

	public static function postventa_list(): void
	{
		$rows = self::product_rows( 'incident' );
		if ( ! $rows ) { \WP_CLI::log( 'Sin casos de postventa.' ); return; }
		foreach ( $rows as $f ) { \WP_CLI::log( sprintf( '#%d %s (%s) [%s]', (int) $f['id'], $f['name'], $f['slug'], $f['status'] ) ); }
	}
	public static function postventa_create( array $a, array $as ): void { self::product_write( 'incident', $as ); }
	public static function postventa_update( array $a, array $as ): void { self::product_update( 'incident', $a, $as ); }
	public static function postventa_delete( array $a, array $as ): void { self::product_delete( $a, $as ); }
	public static function postventa_restore( array $a, array $as ): void { self::product_restore( $a ); }
	public static function postventa_duplicate( array $a, array $as ): void { self::product_duplicate( $a ); }
	public static function postventa_purge( array $a, array $as ): void { self::product_purge( $a, $as ); }
	public static function postventa_entries( array $a, array $as ): void { self::product_entries( 'incident', $as ); }
	public static function postventa_summary(): void { self::product_summary( 'incident', 'postventa' ); }
	public static function postventa_settings_get(): void { \WP_CLI::log( 'Postventa settings:' ); \Estandar\Core\Cli::feature_settings_show( 'estandar_suite_capture_postventa_settings', \Estandar\Capture\Admin::feature_fields()['postventa'] ); }
	public static function postventa_settings_set( array $a, array $as ): void { \Estandar\Core\Cli::feature_settings_set( 'estandar_suite_capture_postventa_settings', \Estandar\Capture\Admin::feature_fields()['postventa'], $as ); }

	public static function caps_get( array $args ): void
	{
		$type = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$type = $type === 'forms' ? 'lead' : ( $type === 'postventa' ? 'incident' : $type );
		if ( ! isset( \Estandar\Capture\Forms::types()[ $type ] ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture products caps get <lead|survey|incident>' );
		}
		$catalog = \Estandar\Capture\Forms::product_capabilities( $type );
		foreach ( $catalog as $cap => $cfg ) {
			$on = \Estandar\Capture\Forms::is_capability_enabled( $type, $cap ) ? '1' : '0';
			\WP_CLI::log( sprintf( '%s = %s  (%s)', $cap, $on, $cfg['label'] ) );
		}
	}

	public static function caps_set( array $args, array $assoc ): void
	{
		$type = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$type = $type === 'forms' ? 'lead' : ( $type === 'postventa' ? 'incident' : $type );
		$cap  = isset( $assoc['cap'] ) ? sanitize_key( $assoc['cap'] ) : '';
		if ( ! isset( \Estandar\Capture\Forms::product_capabilities( $type )[ $cap ] ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture products caps set <tipo> --cap=<cap> --value=0|1' );
		}
		$on = in_array( strtolower( (string) ( $assoc['value'] ?? '' ) ), array( '1', 'true', 'yes', 'si', 'on' ), true );
		\Estandar\Capture\Forms::set_capability( $type, $cap, $on );
		\WP_CLI::success( sprintf( '%s > %s = %d', $type, $cap, $on ? 1 : 0 ) );
	}

	public static function visibility_get( array $args, array $assoc ): void
	{
		$map = \Estandar\Core\Admin::get_visibility( 'capture' );
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
			\WP_CLI::error( 'Uso: wp estandar-suite capture visibility set <tab> <sub1> [<sub2> ...]' );
		}
		$subs = array_map( 'sanitize_key', $subs );
		$map = \Estandar\Core\Admin::get_visibility( 'capture' );
		$map[ $tab ] = $subs;
		\Estandar\Core\Admin::save_visibility( 'capture', $map );
		\WP_CLI::success( sprintf( 'capture > %s: visibles = %s', $tab, implode( ', ', $subs ) ) );
	}

	public static function visibility_reset( array $args, array $assoc ): void
	{
		delete_option( \Estandar\Core\Admin::visibility_option( 'capture' ) );
		\WP_CLI::success( 'capture: visibilidad restaurada (todo visible).' );
	}

	// ── Integrations (form → Google Sheets / Excel Online) ──────────────────

	/**
	 * wp estandar-suite capture forms connect-sheet <form_id>
	 *   --create="Leads AltaCruz"  → creates a new Google spreadsheet
	 *   --connection-id=N          → which Google connection to use (first if omitted)
	 *   [--sheet-title=...] [--columns="Col1|Col2"] [--system-fields=Entry ID|Created At]
	 */
	public static function forms_connect_sheet( array $args, array $assoc ): void
	{
		$form_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $form_id ) {
			\WP_CLI::error( 'Uso: wp estandar-suite capture forms connect-sheet <form_id> [--create="Título"] [--connection-id=N]' );
		}
		$conns = \Estandar\Core\Connections::list( 'google' );
		$cid   = (int) ( $assoc['connection-id'] ?? 0 );
		if ( $cid <= 0 ) {
			foreach ( $conns as $c ) {
				if ( $c['status'] === 'active' ) { $cid = (int) $c['id']; break; }
			}
		}
		if ( $cid <= 0 ) {
			\WP_CLI::error( 'Sin conexión Google. Conecta una cuenta primero: wp estandar-suite integrations connect --provider=google --adapter=google_sheets' );
		}
		$conn = \Estandar\Core\Connections::get( $cid );
		$adapter = \Estandar\Core\Adapters::get( 'google_sheets' );
		if ( ! $conn || ! $adapter ) {
			\WP_CLI::error( 'Conexión o adapter no disponibles.' );
		}
		$title = trim( (string) ( $assoc['create'] ?? '' ) );
		$config = array( 'sheet_title' => (string) ( $assoc['sheet-title'] ?? 'Datos' ) );
		$columns = array();
		if ( ! empty( $assoc['columns'] ) ) {
			$columns = array_values( array_filter( array_map( 'trim', explode( '|', (string) $assoc['columns'] ) ) ) );
		}
		$system = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $assoc['system-fields'] ?? '' ) ) ) ) );
		if ( $title !== '' ) {
			$config['title'] = $title;
			$created = $adapter->create( $conn, array_merge( $config, $columns ? array( 'columns' => $columns ) : array() ) );
			if ( empty( $created['ok'] ) ) {
				\WP_CLI::error( 'No se pudo crear la planilla: ' . $created['error'] );
			}
			$config = $created['config'];
		} else {
			if ( $columns ) { $config['columns'] = $columns; }
			else { \WP_CLI::error( 'Para conectar a una planilla existente usa --columns="Col1|Col2" (o --create para crear una nueva).' ); }
		}
		if ( $system ) {
			$config['system_fields'] = $system;
		}
		$slug = 'form-' . $form_id . '-sheets';
		$dest_id = \Estandar\Core\Destinations::register_adapter( $slug, 'google_sheets', $cid, $config );
		if ( $dest_id <= 0 ) {
			\WP_CLI::error( 'No se pudo registrar el destino.' );
		}
		$fields = FormBridge::fields( 'v2', $form_id );
		$headers = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
		$mapping = \Estandar\Core\FieldMap::auto_map( $fields, $headers );
		$bind_id = \Estandar\Core\Integrations::bind( 'v2', $form_id, $dest_id, $mapping, true );
		\WP_CLI::success( sprintf( 'Formulario #%d → planilla (destino #%d, binding #%d) activo.', $form_id, $dest_id, $bind_id ) );
		\WP_CLI::log( 'Mapeo automático:' );
		foreach ( $mapping as $field => $col ) {
			\WP_CLI::log( sprintf( '  %s → %s', $field, $col ) );
		}
	}

	/** wp estandar-suite capture forms integrations list <form_id> */
	public static function forms_integrations_list( array $args, array $assoc ): void
	{
		$form_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$rows = \Estandar\Core\Integrations::list( 'v2', $form_id );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin integraciones para el formulario.' );
			return;
		}
		foreach ( $rows as $b ) {
			\WP_CLI::log( sprintf( '#%d → destino %s (%s) [%s] mapeo=%d',
				(int) $b['id'], $b['dest_slug'] ?? $b['destination_id'], $b['dest_type'] ?? '-',
				(int) $b['enabled'] === 1 ? 'activo' : 'inactivo', count( $b['mapping'] ) ) );
		}
	}

	/** wp estandar-suite capture forms integrations disconnect <form_id> --integration-id=N --confirm=yes */
	public static function forms_integrations_disconnect( array $args, array $assoc ): void
	{
		if ( (string) ( $assoc['confirm'] ?? '' ) !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$n = \Estandar\Core\Integrations::unbind( (int) ( $assoc['integration-id'] ?? 0 ) );
		\WP_CLI::success( "Integraciones desvinculadas: {$n}" );
	}

	/** wp estandar-suite capture webhook key */
	public static function webhook_key(): void
	{
		\WP_CLI::log( 'Clave webhook JotForm (header X-Estandar-Webhook-Key): ' . FormBridge::webhook_key() );
		\WP_CLI::log( 'Endpoint: ' . ( function_exists( 'rest_url' ) ? rest_url( 'estandar-suite/capture/v1/forms/jotform' ) : '' ) );
	}
}
