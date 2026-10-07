<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Core. Safe by default (read-only; mutations require
 * explicit flags + confirmation). Covers EVERY suite setting (specific
 * ergonomic commands + a generic `option` gate for any estandar_suite_*).
 */
class Cli
{
	public static function init(): void
	{
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'estandar-suite doctor', array( __CLASS__, 'doctor' ) );
		\WP_CLI::add_command( 'estandar-suite core selfcheck', array( __CLASS__, 'selfcheck' ) );
		\WP_CLI::add_command( 'estandar-suite telemetry events', array( __CLASS__, 'telemetry' ) );
		\WP_CLI::add_command( 'estandar-suite telemetry status', array( __CLASS__, 'telemetry_status' ) );
		\WP_CLI::add_command( 'estandar-suite telemetry settings', array( __CLASS__, 'telemetry_settings' ) );
		\WP_CLI::add_command( 'estandar-suite adoption probe', array( __CLASS__, 'probe' ) );
		\WP_CLI::add_command( 'estandar-suite adoption verify', array( __CLASS__, 'verify' ) );
		\WP_CLI::add_command( 'estandar-suite option get', array( __CLASS__, 'option_get' ) );
		\WP_CLI::add_command( 'estandar-suite option set', array( __CLASS__, 'option_set' ) );
		\WP_CLI::add_command( 'estandar-suite option delete', array( __CLASS__, 'option_delete' ) );
		\WP_CLI::add_command( 'estandar-suite visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite visibility reset', array( __CLASS__, 'visibility_reset' ) );
		// PROYECTOS (registry) + OPERADORES (es_operators) — parity admin.
		\WP_CLI::add_command( 'estandar-suite projects list', array( __CLASS__, 'projects_list' ) );
		\WP_CLI::add_command( 'estandar-suite projects get', array( __CLASS__, 'projects_get' ) );
		\WP_CLI::add_command( 'estandar-suite projects create', array( __CLASS__, 'projects_create' ) );
		\WP_CLI::add_command( 'estandar-suite projects update', array( __CLASS__, 'projects_update' ) );
		\WP_CLI::add_command( 'estandar-suite projects archive', array( __CLASS__, 'projects_archive' ) );
		\WP_CLI::add_command( 'estandar-suite projects activate', array( __CLASS__, 'projects_activate' ) );
		\WP_CLI::add_command( 'estandar-suite projects auto', array( __CLASS__, 'projects_auto' ) );
		\WP_CLI::add_command( 'estandar-suite projects delete', array( __CLASS__, 'projects_delete' ) );
		\WP_CLI::add_command( 'estandar-suite operators list', array( __CLASS__, 'operators_list' ) );
		\WP_CLI::add_command( 'estandar-suite operators add', array( __CLASS__, 'operators_add' ) );
		\WP_CLI::add_command( 'estandar-suite operators update', array( __CLASS__, 'operators_update' ) );
		\WP_CLI::add_command( 'estandar-suite operators archive', array( __CLASS__, 'operators_archive' ) );
		\WP_CLI::add_command( 'estandar-suite operators activate', array( __CLASS__, 'operators_activate' ) );
		\WP_CLI::add_command( 'estandar-suite core updates policy', array( __CLASS__, 'updates_policy' ) );

		// AJUSTES de Configuración (paridad rápida en todas las webs): marca/
		// identidad, correo, claves IA y mapa de eventos. Todo además es editable
		// genéricamente con `estandar-suite option set`.
		\WP_CLI::add_command( 'estandar-suite brand get', array( __CLASS__, 'brand_get' ) );
		\WP_CLI::add_command( 'estandar-suite brand set', array( __CLASS__, 'brand_set' ) );
		\WP_CLI::add_command( 'estandar-suite mail get', array( __CLASS__, 'mail_get' ) );
		\WP_CLI::add_command( 'estandar-suite mail set', array( __CLASS__, 'mail_set' ) );
		\WP_CLI::add_command( 'estandar-suite ai get', array( __CLASS__, 'ai_get' ) );
		\WP_CLI::add_command( 'estandar-suite ai set', array( __CLASS__, 'ai_set' ) );
		\WP_CLI::add_command( 'estandar-suite ai reveal', array( __CLASS__, 'ai_reveal' ) );
	}

	// ── Shared helpers for feature settings (used by every plugin's CLI) ──

	public static function feature_settings_show( string $option, array $fields, string $group = '' ): void
	{
		$current = get_option( $option, array() );
		$current = is_array( $current ) ? $current : array();
		$nested  = ( $group !== '' && isset( $current[ $group ] ) && is_array( $current[ $group ] ) ) ? $current[ $group ] : array();
		foreach ( $fields as $k => $cfg ) {
			if ( array_key_exists( $k, $nested ) ) {
				$v = $nested[ $k ];
			} elseif ( array_key_exists( $k, $current ) ) {
				$v = $current[ $k ];
			} else {
				$v = $cfg['default'] ?? '';
			}
			\WP_CLI::log( sprintf( '%s = %s  (%s)', $k, is_scalar( $v ) ? $v : wp_json_encode( $v ), $cfg['label'] ) );
		}
	}

	public static function feature_settings_set( string $option, array $fields, array $assoc, string $group = '' ): void
	{
		$key   = sanitize_key( (string) ( $assoc['key'] ?? '' ) );
		$value = (string) ( $assoc['value'] ?? '' );
		if ( ! isset( $fields[ $key ] ) ) {
			\WP_CLI::error( 'Clave inválida. Usa: ' . implode( ', ', array_keys( $fields ) ) );
		}
		$current = get_option( $option, array() );
		$current = is_array( $current ) ? $current : array();
		$cfg     = $fields[ $key ];
		$type    = $cfg['type'] ?? 'text';
		if ( $type === 'bool' ) {
			$val = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'si', 'on' ), true ) ? 1 : 0;
		} elseif ( $type === 'select' ) {
			$v   = sanitize_key( $value );
			$val = isset( $cfg['options'][ $v ] ) ? $v : ( $cfg['default'] ?? '' );
		} else {
			$val = sanitize_text_field( $value );
		}
		if ( $group !== '' ) {
			// FEATURE_GROUP_NESTED_V1: escribe anidado y migra la clave plana legacy.
			$group = sanitize_key( $group );
			if ( ! isset( $current[ $group ] ) || ! is_array( $current[ $group ] ) ) {
				$current[ $group ] = array();
			}
			$current[ $group ][ $key ] = $val;
			unset( $current[ $key ] );
		} else {
			$current[ $key ] = $val;
		}
		update_option( $option, $current );
		\WP_CLI::success( sprintf( '%s = %s', $key, is_scalar( $val ) ? $val : wp_json_encode( $val ) ) );
	}

	public static function updates_policy( array $args, array $assoc ): void
	{
		$set = (string) ( $assoc['set'] ?? '' );
		if ( $set !== '' ) {
			if ( ! Updates::set_policy( $set ) ) {
				\WP_CLI::error( 'Política inválida. Usa auto | off | pin:X.Y.Z' );
			}
			\WP_CLI::success( 'Política de updates de este sitio → ' . $set );
			return;
		}
		$p = Updates::policy();
		\WP_CLI::log( 'mode=' . $p['mode'] . ( $p['pin'] !== '' ? ' pin=' . $p['pin'] : '' ) );
	}

	public static function doctor( array $args, array $assoc ): void
	{
		\WP_CLI::log( Health::render_text( Health::snapshot() ) );
	}

	/** wp estandar-suite core selfcheck [--refresh] — estado honesto (AUTO_DIAG_V1). */
	public static function selfcheck( array $args, array $assoc ): void
	{
		$force = ! empty( $assoc['refresh'] );
		$snap  = AutoDiag::snapshot( $force );
		$over  = $snap['overall'] ?? array( 'state' => 'UNKNOWN', 'level' => 'unknown' );
		if ( ( $over['level'] ?? 'unknown' ) === 'bad' ) {
			\WP_CLI::error( (string) ( $over['state'] ?? 'FALLO' ) . ' — ' . AutoDiag::render_text( $snap ) );
			return;
		}
		\WP_CLI::log( AutoDiag::render_text( $snap ) );
		if ( ( $over['level'] ?? 'unknown' ) === 'warn' ) {
			\WP_CLI::warning( 'Estado general: ' . (string) ( $over['state'] ?? 'ATENCION' ) . ' (revisa los checks anteriores).' );
		} else {
			\WP_CLI::success( 'Estado general: ' . (string) ( $over['state'] ?? 'OK' ) );
		}
	}

	public static function telemetry( array $args, array $assoc ): void
	{
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 25;
		foreach ( Telemetry::recent( $limit ) as $row ) {
			\WP_CLI::log( sprintf( '%s · %-8s · %s', $row['created_at'], $row['severity'], $row['message'] ) );
		}
	}

	public static function telemetry_status( array $args, array $assoc ): void
	{
		\WP_CLI::log( wp_json_encode( Telemetry::settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/** wp estandar-suite telemetry settings [--enabled=0|1] [--retention-days=N] [--min-level=...] */
	public static function telemetry_settings( array $args, array $assoc ): void
	{
		$raw = array();
		if ( array_key_exists( 'enabled', $assoc ) ) {
			$raw['enabled'] = in_array( (string) $assoc['enabled'], array( '1', '0', 'true', 'false', 'on', 'off' ), true ) ? ( in_array( (string) $assoc['enabled'], array( '1', 'true', 'on' ), true ) ? '1' : '0' ) : '1';
		}
		if ( array_key_exists( 'retention-days', $assoc ) ) {
			$raw['retention_days'] = $assoc['retention-days'];
		}
		if ( array_key_exists( 'min-level', $assoc ) ) {
			$raw['min_level'] = $assoc['min-level'];
		}
		if ( empty( $raw ) ) {
			\WP_CLI::error( 'Sin flags. Usa --enabled, --retention-days o --min-level (ver estado con `telemetry status`).' );
		}
		$saved = Telemetry::save_settings( array_merge( Telemetry::settings(), $raw ) );
		\WP_CLI::success( 'Telemetría actualizada:' );
		\WP_CLI::log( wp_json_encode( $saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	public static function probe( array $args, array $assoc ): void
	{
		$present = AdoptionProbe::probe_legacy();
		\WP_CLI::log( $present ? 'Instalación legada detectada (tablas originales presentes).' : 'Instalación limpia (sin tablas legadas).' );
	}

	// ── Integrations (OAuth connections + adapter destinations) ─────────────

	/** wp estandar-suite integrations apps show */
	public static function is_settable_option( string $name ): bool
	{
		if ( ! preg_match( '/^estandar_suite_[a-z0-9_]+$/', $name ) ) {
			return false;
		}
		// Protected: encryption key and schema versions are internal.
		if ( strpos( $name, '_secret_key' ) !== false || strpos( $name, '_schema_version' ) !== false ) {
			return false;
		}
		return true;
	}

	public static function option_get( array $args, array $assoc ): void
	{
		$name = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( ! self::is_settable_option( $name ) ) {
			\WP_CLI::error( "Option no válida o protegida: {$name}. Debe empezar por estandar_suite_." );
		}
		$value = get_option( $name, null );
		\WP_CLI::log( $value === null ? 'null (no definida)' : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	public static function option_set( array $args, array $assoc ): void
	{
		$name = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( ! self::is_settable_option( $name ) ) {
			\WP_CLI::error( "Option no válida o protegida: {$name}." );
		}
		if ( array_key_exists( 'payload', $assoc ) ) {
			$decoded = json_decode( (string) $assoc['payload'], true );
			if ( ! is_array( $decoded ) ) {
				\WP_CLI::error( '--payload no es un array JSON válido.' );
			}
			$value = $decoded;
		} elseif ( array_key_exists( 'value', $assoc ) ) {
			$value = (string) $assoc['value'];
		} else {
			\WP_CLI::error( 'Usa --value=... (escalar) o --payload=... (array).' );
		}
		update_option( $name, $value, false );
		\WP_CLI::success( "{$name} actualizada." );
		\WP_CLI::log( wp_json_encode( get_option( $name ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	public static function option_delete( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$name = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( ! self::is_settable_option( $name ) ) {
			\WP_CLI::error( "Option no válida o protegida: {$name}." );
		}
		delete_option( $name );
		\WP_CLI::success( "{$name} eliminada." );
	}

	/** wp estandar-suite adoption verify — migration coverage report (read-only). */
	public static function verify( array $args, array $assoc ): void
	{
		$report = LegacyMap::verify_coverage();

		\WP_CLI::log( 'Estandar Suite v2 — verificación de cobertura de migración' );
		\WP_CLI::log( '─────────────────────────────────────────────────────────' );
		\WP_CLI::log( sprintf( 'Instalación legada detectada: %s', $report['legacy_install'] ? 'SÍ' : 'NO' ) );

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Plugins legados activos:' );
		if ( empty( $report['legacy_plugins'] ) ) {
			\WP_CLI::log( '  (ninguno)' );
		}
		foreach ( $report['legacy_plugins'] as $p ) {
			\WP_CLI::log( '  - ' . $p );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Shortcodes (cubiertos tras desactivación):' );
		$orphans = 0;
		foreach ( $report['shortcodes'] as $tag => $covered ) {
			\WP_CLI::log( sprintf( '  [%s] %s', $tag, $covered ? 'cubierto' : '⚠ SIN COBERTURA' ) );
			if ( ! $covered ) {
				$orphans++;
			}
		}

		\WP_CLI::log( '' );
		if ( $orphans > 0 ) {
			\WP_CLI::error( sprintf( '%d shortcode(s) sin cobertura — revisa LegacyMap.', $orphans ) );
		} else {
			\WP_CLI::success( 'Cobertura completa: los shortcodes sobreviven la desactivación.' );
		}
	}

	/** wp estandar-suite visibility get <producto> */
	public static function visibility_get( array $args, array $assoc ): void
	{
		$product = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( $product === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite visibility get <core|capture|brain|flow|insights>' );
		}
		$map = Admin::get_visibility( $product );
		if ( empty( $map ) ) {
			\WP_CLI::log( 'Sin configuración guardada → todos los tabs/sub-tabs visibles.' );
			return;
		}
		foreach ( $map as $tab => $subs ) {
			if ( ! is_array( $subs ) || empty( $subs ) ) {
				\WP_CLI::log( sprintf( '%s: (ninguno → tab oculto)', $tab ) );
				continue;
			}
			$parts = array();
			foreach ( $subs as $sub => $ss ) {
				if ( is_array( $ss ) && ! empty( $ss ) ) {
					$parts[] = $sub . ' [' . implode( ', ', $ss ) . ']';
				} else {
					$parts[] = $sub;
				}
			}
			\WP_CLI::log( sprintf( '%s: %s', $tab, implode( ', ', $parts ) ) );
		}
	}

	/** wp estandar-suite visibility set <producto> <tab> <sub1> [<sub2> ...] | <tab> --hide */
	public static function visibility_set( array $args, array $assoc ): void
	{
		$product = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$tab     = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';
		$subs    = array_slice( $args, 2 );
		// --hide → empty list (hide the whole tab).
		if ( ! empty( $assoc['hide'] ) ) {
			$subs = array();
		}
		if ( $product === '' || $tab === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite visibility set <producto> <tab> <sub1> [<sub2> ...] | <tab> --hide' );
		}
		$subs = array_map( 'sanitize_key', $subs );
		// Merge with the existing map so other tabs are untouched.
		$map = Admin::get_visibility( $product );
		// The visibility manager itself can never be hidden.
		if ( $tab === 'config' && ! in_array( 'visibilidad', $subs, true ) ) {
			$subs[] = 'visibilidad';
		}
		$map[ $tab ] = $subs;
		Admin::save_visibility( $product, $map );
		\WP_CLI::success( sprintf( '%s > %s: visibles = %s', $product, $tab, $subs ? implode( ', ', $subs ) : '(ninguno → tab oculto)' ) );
	}

	/** wp estandar-suite visibility reset <producto> */
	public static function visibility_reset( array $args, array $assoc ): void
	{
		$product = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( $product === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite visibility reset <core|capture|brain|flow|insights>' );
		}
		delete_option( Admin::visibility_option( $product ) );
		\WP_CLI::success( sprintf( '%s: visibilidad restaurada (todo visible).', $product ) );
	}

	/* ── PROYECTOS (registry) ───────────────────────────────────────────── */

	/** wp estandar-suite projects list */
	public static function projects_list(): void
	{
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' ) {
			\WP_CLI::error( 'ProjectRegistry no disponible.' );
		}
		foreach ( $PR::manager_list() as $p ) {
			\WP_CLI::log( sprintf( '%s | %s | %s | %s | %s', $p['project_id'], $p['label'], $p['operator_email'] ?? '', (int) $p['active'] === 1 ? 'activo' : 'archivado', $p['client_id'] ?? '' ) );
		}
	}

	/** wp estandar-suite projects get <slug> */
	public static function projects_get( array $args ): void
	{
		$slug = isset( $args[0] ) ? sanitize_title( $args[0] ) : '';
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' || $slug === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite projects get <slug>' );
		}
		foreach ( $PR::manager_list() as $p ) {
			if ( sanitize_title( (string) $p['project_id'] ) === $slug ) {
				foreach ( array( 'project_id', 'label', 'city', 'operator_name', 'operator_email', 'operator_phone', 'active', 'client_id' ) as $k ) {
					\WP_CLI::log( sprintf( '%s = %s', $k, (string) ( $p[ $k ] ?? '' ) ) );
				}
				return;
			}
		}
		\WP_CLI::error( 'Proyecto no encontrado.' );
	}

	/** wp estandar-suite projects create --label=... [--slug=...] [--operator-email=...] */
	public static function projects_create( array $args, array $assoc ): void
	{
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' ) {
			\WP_CLI::error( 'ProjectRegistry no disponible.' );
		}
		$label = sanitize_text_field( (string) ( $assoc['label'] ?? '' ) );
		if ( $label === '' ) {
			\WP_CLI::error( 'Requiere --label.' );
		}
		$data = array(
			'label'      => $label,
			'project_id' => sanitize_title( (string) ( $assoc['slug'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $assoc['city'] ?? '' ) ),
		);
		$r = $PR::create_project( $data );
		if ( ! $r['ok'] ) {
			\WP_CLI::error( 'No se pudo crear: ' . (string) ( $r['error'] ?? '?' ) );
		}
		self::maybe_project_operator( (string) $r['slug'], $assoc );
		\WP_CLI::success( "Proyecto creado: {$r['slug']}" );
	}

	/** wp estandar-suite projects update <slug> [--label=] [--operator-email=] [--city=] */
	public static function projects_update( array $args, array $assoc ): void
	{
		$slug = isset( $args[0] ) ? sanitize_title( $args[0] ) : '';
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' || $slug === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite projects update <slug> [--label=] [--operator-email=]' );
		}
		$data = array();
		// WP-CLI entrega las flags multi-palabra con GUION (`--operator-email`),
		// aunque el destino use guion bajo (`operator_email`). Aceptamos ambos
		// para que `projects update --operator-email=...` realmente aplique.
		$assoc_get = static function ( string $k ) use ( $assoc ) {
			if ( array_key_exists( $k, $assoc ) ) {
				return (string) $assoc[ $k ];
			}
			$dash = str_replace( '_', '-', $k );
			return array_key_exists( $dash, $assoc ) ? (string) $assoc[ $dash ] : null;
		};
		foreach ( array( 'label', 'city', 'operator_name', 'operator_phone' ) as $k ) {
			$v = $assoc_get( $k );
			if ( $v !== null ) {
				$data[ $k ] = sanitize_text_field( $v );
			}
		}
		$email = $assoc_get( 'operator_email' );
		if ( $email !== null ) {
			$data['operator_email'] = sanitize_email( $email );
		}
		$r = $PR::update_project( $slug, $data );
		if ( ! $r['ok'] ) {
			\WP_CLI::error( 'No se pudo actualizar: ' . (string) ( $r['error'] ?? '?' ) );
		}
		\WP_CLI::success( "Proyecto actualizado: {$slug}" );
	}

	/** wp estandar-suite projects archive|activate <slug> */
	public static function projects_archive( array $args ): void
	{
		$slug = isset( $args[0] ) ? sanitize_title( $args[0] ) : '';
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' || $slug === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite projects archive <slug>' );
		}
		$PR::set_active( $slug, false );
		\WP_CLI::success( "Proyecto archivado: {$slug}" );
	}

	public static function projects_activate( array $args ): void
	{
		$slug = isset( $args[0] ) ? sanitize_title( $args[0] ) : '';
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' || $slug === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite projects activate <slug>' );
		}
		$PR::set_active( $slug, true );
		\WP_CLI::success( "Proyecto reactivado: {$slug}" );
	}

	/** wp estandar-suite projects auto → devuelve (creando si falta) el proyecto del sitio. */
	public static function projects_auto(): void
	{
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' ) {
			\WP_CLI::error( 'ProjectRegistry no disponible.' );
		}
		$slug = $PR::auto_project_slug();
		\WP_CLI::success( $slug !== '' ? "Proyecto del sitio: {$slug}" : 'No se pudo resolver el proyecto del sitio.' );
	}

	/** wp estandar-suite projects delete <slug> --to=<slug destino> (requerido si tiene forms). */
	public static function projects_delete( array $args, array $assoc ): void
	{
		$slug = isset( $args[0] ) ? sanitize_title( $args[0] ) : '';
		$dest = sanitize_title( (string) ( $assoc['to'] ?? '' ) );
		$PR   = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' || $slug === '' ) {
			\WP_CLI::error( 'Uso: wp estandar-suite projects delete <slug> [--to=<slug destino>]' );
		}
		$Forms = class_exists( '\Estandar\Capture\Forms' ) ? '\Estandar\Capture\Forms' : '';
		$assoc = ( $Forms !== '' ) ? $Forms::forms_of_project( $slug ) : array();
		if ( $assoc && $dest === '' ) {
			\WP_CLI::error( 'Este proyecto tiene formularios asociados. Usa --to=<slug destino> para trasladarlos.' );
		}
		if ( $assoc && $dest !== '' && $Forms !== '' ) {
			$Forms::reassign_project_ownership( $slug, $dest, $PR::label_for( $dest ) );
			\WP_CLI::log( count( $assoc ) . ' formulario(s) trasladados a ' . $dest );
		}
		$PR::delete_project( $slug );
		\WP_CLI::success( "Proyecto eliminado: {$slug}" );
	}

	/** Si --operator-email viene, lo refleja en el proyecto (búsqueda en es_operators o email directo). */
	private static function maybe_project_operator( string $slug, array $assoc ): void
	{
		$email = sanitize_email( (string) ( $assoc['operator-email'] ?? '' ) );
		if ( $email === '' ) {
			return;
		}
		$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		if ( $PR === '' ) {
			return;
		}
		$Ops  = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		$name = sanitize_text_field( (string) ( $assoc['operator-name'] ?? '' ) );
		$wsp  = '';
		if ( $Ops !== '' ) {
			$row = $Ops::find_by_email( $email );
			if ( $row ) {
				$name = $name !== '' ? $name : (string) ( $row['name'] ?? $email );
				$wsp  = (string) ( $row['whatsapp_number'] ?? '' );
			}
		}
		if ( $name === '' ) {
			$name = $email;
		}
		$PR::update_project( $slug, array(
			'operator_name'  => $name,
			'operator_email' => $email,
			'operator_phone' => $wsp,
		) );
	}

	/* ── OPERADORES (es_operators) ──────────────────────────────────────── */

	/** wp estandar-suite operators list */
	public static function operators_list(): void
	{
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $Ops === '' ) {
			\WP_CLI::error( 'Operators no disponible.' );
		}
		foreach ( $Ops::list() as $o ) {
			\WP_CLI::log( sprintf( '%s | %s | %s | %s | %s', $o['id'], $o['name'], $o['email'], $o['project_slug'], (int) $o['active'] === 1 ? 'activo' : 'archivado' ) );
		}
	}

	/** wp estandar-suite operators add --name= --email= [--project=] [--whatsapp=] */
	public static function operators_add( array $args, array $assoc ): void
	{
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $Ops === '' ) {
			\WP_CLI::error( 'Operators no disponible.' );
		}
		$r = $Ops::create( array(
			'name'            => sanitize_text_field( (string) ( $assoc['name'] ?? '' ) ),
			'email'           => sanitize_email( (string) ( $assoc['email'] ?? '' ) ),
			'whatsapp_number' => sanitize_text_field( (string) ( $assoc['whatsapp'] ?? '' ) ),
			'project_slug'    => sanitize_title( (string) ( $assoc['project'] ?? '' ) ),
		) );
		if ( ! $r['ok'] ) {
			\WP_CLI::error( 'No se pudo crear el operador: ' . (string) ( $r['error'] ?? '?' ) );
		}
		\WP_CLI::success( "Operador #{$r['id']} creado (proyecto: {$r['project_slug']})." );
	}

	/** wp estandar-suite operators update <id> [--email=] [--whatsapp=] [--project=] [--active=1|0] */
	public static function operators_update( array $args, array $assoc ): void
	{
		$id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $Ops === '' || $id <= 0 ) {
			\WP_CLI::error( 'Uso: wp estandar-suite operators update <id> [--email=] [--whatsapp=] [--project=] [--active=1|0]' );
		}
		$data = array();
		foreach ( array( 'name', 'whatsapp' ) as $k ) {
			if ( isset( $assoc[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( (string) $assoc[ $k ] );
			}
		}
		if ( isset( $assoc['email'] ) ) {
			$data['email'] = sanitize_email( (string) $assoc['email'] );
		}
		if ( isset( $assoc['project'] ) ) {
			$data['project_slug'] = sanitize_title( (string) $assoc['project'] );
		}
		if ( isset( $assoc['active'] ) ) {
			$data['active'] = in_array( strtolower( (string) $assoc['active'] ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
		}
		$r = $Ops::update( $id, $data );
		if ( ! $r['ok'] ) {
			\WP_CLI::error( 'No se pudo actualizar: ' . (string) ( $r['error'] ?? '?' ) );
		}
		\WP_CLI::success( "Operador #{$id} actualizado." );
	}

	/** wp estandar-suite operators archive|activate <id> */
	public static function operators_archive( array $args ): void
	{
		$id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $Ops === '' || $id <= 0 ) {
			\WP_CLI::error( 'Uso: wp estandar-suite operators archive <id>' );
		}
		$Ops::update( $id, array( 'active' => 0 ) );
		\WP_CLI::success( "Operador #{$id} archivado." );
	}

	public static function operators_activate( array $args ): void
	{
		$id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$Ops = class_exists( '\Estandar\Core\Leads\Operators' ) ? '\Estandar\Core\Leads\Operators' : '';
		if ( $Ops === '' || $id <= 0 ) {
			\WP_CLI::error( 'Uso: wp estandar-suite operators activate <id>' );
		}
		$Ops::update( $id, array( 'active' => 1 ) );
		\WP_CLI::success( "Operador #{$id} activado." );
	}

	// ── AJUSTES de Configuración: marca/identidad, correo, claves IA, eventos ──

	/** wp estandar-suite brand get — Emoji + identidad (logo/color) por defecto. */
	public static function brand_get( array $args, array $assoc ): void
	{
		\WP_CLI::log( wp_json_encode( array(
			'emoji'        => (string) get_option( 'estandar_suite_core_menu_icon', '🐲' ),
			'default_logo' => (string) get_option( 'estandar_suite_core_default_logo', '' ),
			'brand_color'  => (string) get_option( 'estandar_suite_core_brand_color', '' ),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * wp estandar-suite brand set [--emoji=🚀] [--logo=URL] [--brand-color=#dd3333]
	 * (Corresponde a Configuración → Emoji y Correo → Identidad.)
	 */
	public static function brand_set( array $args, array $assoc ): void
	{
		if ( array_key_exists( 'emoji', $assoc ) ) {
			$emoji = trim( (string) $assoc['emoji'] );
			if ( function_exists( 'mb_substr' ) ) {
				$emoji = mb_substr( $emoji, 0, 8 );
			}
			update_option( 'estandar_suite_core_menu_icon', $emoji !== '' ? $emoji : '🐲', false );
		}
		if ( array_key_exists( 'logo', $assoc ) ) {
			update_option( 'estandar_suite_core_default_logo', esc_url_raw( (string) $assoc['logo'] ), false );
		}
		// WP-CLI reserva el flag global --color; por eso el nuestro es --brand-color.
		if ( array_key_exists( 'brand-color', $assoc ) ) {
			$color = (string) $assoc['brand-color'];
			$color = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $color ) : preg_replace( '/[^#0-9a-fA-F]/', '', $color );
			update_option( 'estandar_suite_core_brand_color', $color, false );
		}
		self::brand_get( $args, $assoc );
	}

	/** wp estandar-suite mail get — transporte, monitor BCC y estado (sin claves). */
	public static function mail_get( array $args, array $assoc ): void
	{
		$pass  = (string) get_option( 'estandar_suite_core_smtp_pass', '' );
		$brevo = (string) get_option( 'estandar_suite_core_brevo_api_key', '' );
		\WP_CLI::log( wp_json_encode( array(
			'transport'       => (string) get_option( 'estandar_suite_core_mail_transport', '' ),
			'smtp_host'       => (string) get_option( 'estandar_suite_core_smtp_host', '' ),
			'smtp_port'       => (int) get_option( 'estandar_suite_core_smtp_port', 587 ),
			'smtp_secure'     => (string) get_option( 'estandar_suite_core_smtp_secure', 'tls' ),
			'smtp_auth'       => (int) get_option( 'estandar_suite_core_smtp_auth', 1 ),
			'smtp_user'       => (string) get_option( 'estandar_suite_core_smtp_user', '' ),
			'smtp_pass_set'   => $pass !== '',
			'brevo_key_set'   => $brevo !== '',
			'monitor_enabled' => (string) get_option( \Estandar\Core\Mail::MONITOR_ENABLED_OPTION, '' ),
			'monitor_bcc'     => (string) get_option( \Estandar\Core\Mail::MONITOR_BCC_OPTION, '' ),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * wp estandar-suite mail set
	 * [--transport=smtp|wp|brevo|] [--smtp-host=] [--smtp-port=587] [--smtp-secure=tls|ssl|]
	 * [--smtp-auth=1|0] [--smtp-user=] [--smtp-pass=] [--clear-smtp-pass=1]
	 * [--brevo-key=] [--monitor-enabled=1|0] [--monitor-bcc=email,...]
	 * (Contraseñas solo se tocan si se pasan explícitamente.)
	 */
	public static function mail_set( array $args, array $assoc ): void
	{
		if ( array_key_exists( 'transport', $assoc ) ) {
			$t = sanitize_key( (string) $assoc['transport'] );
			update_option( 'estandar_suite_core_mail_transport', in_array( $t, array( 'smtp', 'wp', 'brevo', '' ), true ) ? $t : '', false );
		}
		if ( array_key_exists( 'smtp-host', $assoc ) ) {
			update_option( 'estandar_suite_core_smtp_host', sanitize_text_field( (string) $assoc['smtp-host'] ), false );
		}
		if ( array_key_exists( 'smtp-port', $assoc ) ) {
			update_option( 'estandar_suite_core_smtp_port', (int) $assoc['smtp-port'], false );
		}
		if ( array_key_exists( 'smtp-secure', $assoc ) ) {
			$sec = (string) $assoc['smtp-secure'];
			update_option( 'estandar_suite_core_smtp_secure', in_array( $sec, array( 'tls', 'ssl', '' ), true ) ? $sec : 'tls', false );
		}
		if ( array_key_exists( 'smtp-auth', $assoc ) ) {
			update_option( 'estandar_suite_core_smtp_auth', in_array( strtolower( (string) $assoc['smtp-auth'] ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0, false );
		}
		if ( array_key_exists( 'smtp-user', $assoc ) ) {
			update_option( 'estandar_suite_core_smtp_user', sanitize_text_field( (string) $assoc['smtp-user'] ), false );
		}
		if ( array_key_exists( 'smtp-pass', $assoc ) ) {
			update_option( 'estandar_suite_core_smtp_pass', (string) $assoc['smtp-pass'], false );
		}
		if ( ! empty( $assoc['clear-smtp-pass'] ) && $assoc['clear-smtp-pass'] === '1' ) {
			update_option( 'estandar_suite_core_smtp_pass', '', false );
		}
		if ( array_key_exists( 'brevo-key', $assoc ) ) {
			update_option( 'estandar_suite_core_brevo_api_key', (string) $assoc['brevo-key'], false );
		}
		if ( array_key_exists( 'monitor-enabled', $assoc ) ) {
			update_option( \Estandar\Core\Mail::MONITOR_ENABLED_OPTION, in_array( strtolower( (string) $assoc['monitor-enabled'] ), array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0', false );
		}
		if ( array_key_exists( 'monitor-bcc', $assoc ) ) {
			update_option( \Estandar\Core\Mail::MONITOR_BCC_OPTION, sanitize_text_field( (string) $assoc['monitor-bcc'] ), false );
		}
		\WP_CLI::success( 'Configuración de correo actualizada.' );
		self::mail_get( $args, $assoc );
	}

	/** wp estandar-suite ai get — estado del vault de claves (enmascarado). */
	public static function ai_get( array $args, array $assoc ): void
	{
		$settings = AiKeys::get_settings();
		$out      = array();
		foreach ( AiKeys::providers() as $provider => $label ) {
			$key = (string) ( $settings[ $provider ] ?? '' );
			$out[ $provider ] = $key === '' ? '' : ( strlen( $key ) > 8 ? substr( $key, 0, 6 ) . '…' . substr( $key, -4 ) : '…' );
		}
		$out['active_provider'] = AiKeys::active_provider();
		$out['default_model']   = AiKeys::default_model();
		$out['adopted']         = empty( $settings['adopted'] ) ? 0 : 1;
		\WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/** wp estandar-suite ai reveal --provider=deepseek — muestra la clave real. */
	public static function ai_reveal( array $args, array $assoc ): void
	{
		$provider = sanitize_key( (string) ( $assoc['provider'] ?? '' ) );
		if ( ! isset( AiKeys::providers()[ $provider ] ) ) {
			\WP_CLI::error( 'Uso: wp estandar-suite ai reveal --provider=deepseek|openai' );
		}
		$key = AiKeys::get( $provider );
		if ( $key === '' ) {
			\WP_CLI::error( "Sin clave guardada para {$provider}." );
		}
		\WP_CLI::log( $key );
	}

	/**
	 * wp estandar-suite ai set [--provider=deepseek --key=...] (vacío limpia)
	 * [--active-provider=deepseek|openai] [--default-model=modelo]
	 */
	public static function ai_set( array $args, array $assoc ): void
	{
		$providers = AiKeys::providers();
		$settings  = AiKeys::get_settings();
		if ( array_key_exists( 'provider', $assoc ) ) {
			$provider = sanitize_key( (string) $assoc['provider'] );
			if ( ! isset( $providers[ $provider ] ) ) {
				\WP_CLI::error( '--provider debe ser deepseek u openai.' );
			}
			if ( ! array_key_exists( 'key', $assoc ) ) {
				\WP_CLI::error( 'Para fijar clave usa --key; vacío limpia la clave del proveedor.' );
			}
			$key = trim( str_replace( array( "\r", "\n", "\t" ), '', (string) $assoc['key'] ) );
			$settings[ $provider ] = $key;
		}
		if ( array_key_exists( 'active-provider', $assoc ) ) {
			$active = sanitize_key( (string) $assoc['active-provider'] );
			if ( isset( $providers[ $active ] ) ) {
				$settings['active_provider'] = $active;
			}
		}
		if ( array_key_exists( 'default-model', $assoc ) ) {
			$settings['default_model'] = sanitize_text_field( (string) $assoc['default-model'] );
		}
		AiKeys::update_settings( $settings );
		\WP_CLI::success( 'Vault de claves IA actualizado.' );
		self::ai_get( $args, $assoc );
	}

	/** wp estandar-suite event-map get [--event=lead_created] — mapa eventos→destinos. */
}
