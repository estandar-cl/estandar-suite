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
		\WP_CLI::add_command( 'estandar-suite telemetry events', array( __CLASS__, 'telemetry' ) );
		\WP_CLI::add_command( 'estandar-suite telemetry status', array( __CLASS__, 'telemetry_status' ) );
		\WP_CLI::add_command( 'estandar-suite telemetry settings', array( __CLASS__, 'telemetry_settings' ) );
		\WP_CLI::add_command( 'estandar-suite secrets purge', array( __CLASS__, 'purge_secrets' ) );
		\WP_CLI::add_command( 'estandar-suite secrets list', array( __CLASS__, 'secrets_list' ) );
		\WP_CLI::add_command( 'estandar-suite secrets create', array( __CLASS__, 'secrets_create' ) );
		\WP_CLI::add_command( 'estandar-suite destinations drain', array( __CLASS__, 'drain' ) );
		\WP_CLI::add_command( 'estandar-suite destinations list', array( __CLASS__, 'destinations_list' ) );
		\WP_CLI::add_command( 'estandar-suite destinations add', array( __CLASS__, 'destinations_add' ) );
		\WP_CLI::add_command( 'estandar-suite destinations delete', array( __CLASS__, 'destinations_delete' ) );
		\WP_CLI::add_command( 'estandar-suite destinations test', array( __CLASS__, 'destinations_test' ) );
		\WP_CLI::add_command( 'estandar-suite adoption probe', array( __CLASS__, 'probe' ) );
		\WP_CLI::add_command( 'estandar-suite adoption verify', array( __CLASS__, 'verify' ) );
		\WP_CLI::add_command( 'estandar-suite option get', array( __CLASS__, 'option_get' ) );
		\WP_CLI::add_command( 'estandar-suite option set', array( __CLASS__, 'option_set' ) );
		\WP_CLI::add_command( 'estandar-suite option delete', array( __CLASS__, 'option_delete' ) );
		\WP_CLI::add_command( 'estandar-suite visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite visibility reset', array( __CLASS__, 'visibility_reset' ) );
		\WP_CLI::add_command( 'estandar-suite core secure list', array( __CLASS__, 'secure_list' ) );
		\WP_CLI::add_command( 'estandar-suite core secure settings get', array( __CLASS__, 'secure_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite core secure settings set', array( __CLASS__, 'secure_settings_set' ) );

		\WP_CLI::add_command( 'estandar-suite integrations apps show', array( __CLASS__, 'integrations_apps_show' ) );
		\WP_CLI::add_command( 'estandar-suite integrations apps set', array( __CLASS__, 'integrations_apps_set' ) );
		\WP_CLI::add_command( 'estandar-suite integrations connect', array( __CLASS__, 'integrations_connect' ) );
		\WP_CLI::add_command( 'estandar-suite integrations list', array( __CLASS__, 'integrations_list' ) );
		\WP_CLI::add_command( 'estandar-suite integrations status', array( __CLASS__, 'integrations_status' ) );
		\WP_CLI::add_command( 'estandar-suite integrations disconnect', array( __CLASS__, 'integrations_disconnect' ) );
		\WP_CLI::add_command( 'estandar-suite integrations destination add', array( __CLASS__, 'integrations_destination_add' ) );
		\WP_CLI::add_command( 'estandar-suite integrations destination test', array( __CLASS__, 'integrations_destination_test' ) );
		\WP_CLI::add_command( 'estandar-suite integrations sandbox connect', array( __CLASS__, 'integrations_sandbox_connect' ) );
		\WP_CLI::add_command( 'estandar-suite integrations sandbox run', array( __CLASS__, 'integrations_sandbox_run' ) );
		\WP_CLI::add_command( 'estandar-suite integrations sandbox multi', array( __CLASS__, 'integrations_sandbox_multi' ) );
		\WP_CLI::add_command( 'estandar-suite integrations sandbox status', array( __CLASS__, 'integrations_sandbox_status' ) );
	}

	// ── Shared helpers for feature settings (used by every plugin's CLI) ──

	public static function feature_settings_show( string $option, array $fields ): void
	{
		$current = get_option( $option, array() );
		$current = is_array( $current ) ? $current : array();
		foreach ( $fields as $k => $cfg ) {
			$v = isset( $current[ $k ] ) ? $current[ $k ] : ( $cfg['default'] ?? '' );
			\WP_CLI::log( sprintf( '%s = %s  (%s)', $k, is_scalar( $v ) ? $v : wp_json_encode( $v ), $cfg['label'] ) );
		}
	}

	public static function feature_settings_set( string $option, array $fields, array $assoc ): void
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
			$current[ $key ] = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'si', 'on' ), true ) ? 1 : 0;
		} elseif ( $type === 'select' ) {
			$v = sanitize_key( $value );
			$current[ $key ] = isset( $cfg['options'][ $v ] ) ? $v : ( $cfg['default'] ?? '' );
		} else {
			$current[ $key ] = sanitize_text_field( $value );
		}
		update_option( $option, $current );
		\WP_CLI::success( sprintf( '%s = %s', $key, is_scalar( $current[ $key ] ) ? $current[ $key ] : wp_json_encode( $current[ $key ] ) ) );
	}

	public static function secure_list( array $args, array $assoc ): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_secure_deliveries', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin entregas seguras legadas.' );
			return;
		}
		foreach ( $rows as $d ) {
			\WP_CLI::log( sprintf( '#%d [%s] %s → %s (expira %s)', (int) $d['id'], $d['status'], $d['token_public'], $d['requester_email'], $d['expires_at'] ) );
		}
	}

	public static function secure_settings_get( array $args, array $assoc ): void
	{
		\WP_CLI::log( 'Secure Share settings:' );
		self::feature_settings_show( 'estandar_suite_core_secure_settings', \Estandar\Core\Admin::feature_fields()['secure'] );
	}

	public static function secure_settings_set( array $args, array $assoc ): void
	{
		self::feature_settings_set( 'estandar_suite_core_secure_settings', \Estandar\Core\Admin::feature_fields()['secure'], $assoc );
	}

	public static function doctor( array $args, array $assoc ): void
	{
		\WP_CLI::log( Health::render_text( Health::snapshot() ) );
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

	public static function purge_secrets( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes (borra secretos usados/expirados).' );
		}
		$n = Secrets::cleanup();
		\WP_CLI::success( "Secretos purgados: {$n}" );
	}

	public static function secrets_list( array $args, array $assoc ): void
	{
		foreach ( Secrets::recent( (int) ( $assoc['limit'] ?? 25 ) ) as $row ) {
			\WP_CLI::log( sprintf( '#%d · %s · expira %s · usado=%s', $row['id'], $row['purpose'], $row['expires_at'], ! empty( $row['used_at'] ) ? 'sí' : 'no' ) );
		}
	}

	/** wp estandar-suite secrets create --payload=... --purpose=... [--ttl-hours=24] */
	public static function secrets_create( array $args, array $assoc ): void
	{
		$payload = (string) ( $assoc['payload'] ?? '' );
		if ( $payload === '' ) {
			\WP_CLI::error( 'Requiere --payload.' );
		}
		$purpose = sanitize_key( (string) ( $assoc['purpose'] ?? 'share' ) );
		$ttl     = isset( $assoc['ttl-hours'] ) ? max( 1, min( 720, (int) $assoc['ttl-hours'] ) ) : 24;
		$created = Secrets::create( $payload, $purpose, $ttl * HOUR_IN_SECONDS );
		if ( empty( $created['token'] ) ) {
			\WP_CLI::error( 'No se pudo crear el secreto.' );
		}
		\WP_CLI::log( 'Token (una sola vez): ' . $created['token'] );
		\WP_CLI::log( 'Enlace destinatario: ' . Secrets::consume_link( $created['token'] ) );
		\WP_CLI::log( 'Expira: ' . $created['expires_at'] );
	}

	public static function drain( array $args, array $assoc ): void
	{
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 25;
		list( $sent, $failed, $skipped ) = Destinations::drain( $limit );
		\WP_CLI::success( "Drain: enviados={$sent} fallidos={$failed} restantes={$skipped}" );
	}

	public static function destinations_list( array $args, array $assoc ): void
	{
		foreach ( Destinations::list() as $row ) {
			\WP_CLI::log( sprintf( '#%d · %s · %s · %s · eventos=%s', $row['id'], $row['slug'], $row['type'], $row['target_url'], implode( ',', Destinations::event_targets( $row['slug'] ) ) ) );
		}
	}

	/** wp estandar-suite destinations add --slug=... --type=webhook --url=... */
	public static function destinations_add( array $args, array $assoc ): void
	{
		$slug = sanitize_key( (string) ( $assoc['slug'] ?? '' ) );
		$type = sanitize_key( (string) ( $assoc['type'] ?? 'webhook' ) );
		$url  = esc_url_raw( (string) ( $assoc['url'] ?? '' ) );
		if ( $slug === '' || $url === '' ) {
			\WP_CLI::error( 'Requiere --slug y --url.' );
		}
		$id = Destinations::register_destination( $slug, $type, $url );
		\WP_CLI::success( $id ? "Destino #{$id} registrado." : 'No se pudo registrar.' );
	}

	public static function destinations_delete( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$n = Destinations::delete( (int) ( $assoc['id'] ?? 0 ) );
		\WP_CLI::success( "Destinos eliminados: {$n}" );
	}

	public static function destinations_test( array $args, array $assoc ): void
	{
		$r = Destinations::test( (int) ( $assoc['id'] ?? 0 ) );
		$r['ok'] ? \WP_CLI::success( "Prueba OK (HTTP {$r['http']})." ) : \WP_CLI::error( "Prueba falló (HTTP {$r['http']}): {$r['error']}" );
	}

	public static function probe( array $args, array $assoc ): void
	{
		$present = AdoptionProbe::probe_legacy();
		\WP_CLI::log( $present ? 'Instalación legada detectada (tablas originales presentes).' : 'Instalación limpia (sin tablas legadas).' );
	}

	// ── Integrations (OAuth connections + adapter destinations) ─────────────

	/** wp estandar-suite integrations apps show */
	public static function integrations_apps_show( array $args, array $assoc ): void
	{
		$providers = OAuth::providers();
		if ( ! $providers ) {
			\WP_CLI::log( 'Sin proveedores OAuth registrados.' );
			return;
		}
		foreach ( $providers as $p ) {
			$app = OAuth::app_config( $p );
			\WP_CLI::log( sprintf( '%s · client_id=%s · redirect=%s · %s',
				$p, ! empty( $app['client_id'] ) ? Connections::mask_token( $app['client_id'] ) : '(no configurado)',
				$app['redirect_uri'] ?? '', ! empty( $app['client_secret'] ) ? 'secret ok' : 'secret vacío' ) );
		}
	}

	/** wp estandar-suite integrations apps set --provider=google --client-id=... [--client-secret=... --redirect-uri=...] */
	public static function integrations_apps_set( array $args, array $assoc ): void
	{
		$provider = sanitize_key( (string) ( $assoc['provider'] ?? '' ) );
		if ( ! OAuth::def( $provider ) ) {
			\WP_CLI::error( 'Proveedor inválido. Usa: ' . implode( '|', OAuth::providers() ) );
		}
		$client_id = (string) ( $assoc['client-id'] ?? '' );
		if ( $client_id === '' ) {
			\WP_CLI::error( 'Requiere --client-id.' );
		}
		$cfg = array(
			'client_id'     => $client_id,
			'client_secret' => (string) ( $assoc['client-secret'] ?? ( OAuth::app_config( $provider )['client_secret'] ?? '' ) ),
			'redirect_uri'  => (string) ( $assoc['redirect-uri'] ?? ( OAuth::app_config( $provider )['redirect_uri'] ?? '' ) ),
		);
		OAuth::save_app_config( $provider, $cfg );
		\WP_CLI::success( "App {$provider} configurada." );
	}

	/** wp estandar-suite integrations connect --provider=google [--adapter=google_sheets] [--label=...] [--redirect-uri=...] */
	public static function integrations_connect( array $args, array $assoc ): void
	{
		$provider = sanitize_key( (string) ( $assoc['provider'] ?? '' ) );
		if ( ! OAuth::def( $provider ) ) {
			\WP_CLI::error( 'Proveedor inválido. Usa: ' . implode( '|', OAuth::providers() ) );
		}
		// Sandbox: no OAuth needed — registers an active simulated connection.
		if ( $provider === 'sandbox' ) {
			self::integrations_sandbox_connect( $args, $assoc );
			return;
		}
		$app = OAuth::app_config( $provider );
		if ( empty( $app['client_id'] ) ) {
			\WP_CLI::error( "App {$provider} sin client_id. Configúrala con: estandar-suite integrations apps set --provider={$provider} --client-id=..." );
		}
		$adapter = sanitize_key( (string) ( $assoc['adapter'] ?? '' ) );
		$scopes  = array();
		if ( $adapter !== '' && Adapters::get( $adapter ) ) {
			$scopes = Adapters::get( $adapter )->scopes();
		} elseif ( ! empty( $assoc['scopes'] ) ) {
			$scopes = preg_split( '/[\s,]+/', (string) $assoc['scopes'] );
		}
		$redirect = (string) ( $assoc['redirect-uri'] ?? ( $app['redirect_uri'] ?? OAuth::callback_url() ) );
		$label    = (string) ( $assoc['label'] ?? ( $provider === 'google' ? 'Google' : 'Microsoft' ) );
		$extra    = array();
		if ( ! empty( $assoc['tenant'] ) ) {
			$extra['tenant'] = (string) $assoc['tenant'];
		}
		if ( $provider === 'microsoft' && empty( $extra['tenant'] ) ) {
			$extra['tenant'] = (string) ( $assoc['tenant'] ?? 'common' );
		}
		$state = OAuth::begin_state( $provider, array( 'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0, 'label' => $label, 'scopes' => $scopes, 'tenant' => $extra['tenant'] ?? '', 'adapter' => $adapter ) );
		if ( $state === '' ) {
			\WP_CLI::error( 'No se pudo iniciar el flujo OAuth.' );
		}
		$url = OAuth::authorize_url( $provider, array( 'client_id' => $app['client_id'], 'redirect_uri' => $redirect ), $state, $scopes, $extra );
		\WP_CLI::log( 'Abre esta URL en el navegador y autoriza la cuenta:' );
		\WP_CLI::log( $url );
		\WP_CLI::log( 'La conexión quedará registrada al volver del proveedor.' );
	}

	/** wp estandar-suite integrations list */
	public static function integrations_list( array $args, array $assoc ): void
	{
		$rows = Connections::list();
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin conexiones OAuth. Usa `integrations connect --provider=...`.' );
			return;
		}
		foreach ( $rows as $r ) {
			\WP_CLI::log( sprintf( '#%d · %s · %s · %s · expira=%s · scopes=%d',
				(int) $r['id'], $r['provider'], $r['label'], $r['status'], $r['expires_at'] ?? '-', count( (array) json_decode( (string) ( $r['scopes_json'] ?? '[]' ), true ) ) ) );
		}
	}

	/** wp estandar-suite integrations status --id=N */
	public static function integrations_status( array $args, array $assoc ): void
	{
		$id = (int) ( $assoc['id'] ?? 0 );
		$row = Connections::get( $id );
		if ( ! $row ) {
			\WP_CLI::error( 'Conexión no encontrada.' );
		}
		\WP_CLI::log( sprintf( '#%d · %s · %s · estado=%s', $id, $row['provider'], $row['label'], Connections::status( $id ) ) );
		\WP_CLI::log( 'Tenant/org: ' . ( $row['provider_config']['tenant'] ?? ( $row['provider_config']['drive_path'] ?? '-' ) ) );
		\WP_CLI::log( 'Expira: ' . ( $row['expires_at'] ?? '-' ) );
	}

	/** wp estandar-suite integrations disconnect --id=N --confirm=yes */
	public static function integrations_disconnect( array $args, array $assoc ): void
	{
		if ( (string) ( $assoc['confirm'] ?? '' ) !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes (revoca la conexión).' );
		}
		$n = Connections::revoke( (int) ( $assoc['id'] ?? 0 ) );
		\WP_CLI::success( "Conexiones revocadas: {$n}" );
	}

	/** wp estandar-suite integrations destination add --slug=... --type=google_sheets --connection-id=N [--config='{...}'] */
	public static function integrations_destination_add( array $args, array $assoc ): void
	{
		$slug = sanitize_key( (string) ( $assoc['slug'] ?? '' ) );
		$type = sanitize_key( (string) ( $assoc['type'] ?? '' ) );
		$cid  = (int) ( $assoc['connection-id'] ?? 0 );
		if ( $slug === '' || $type === '' || $cid <= 0 ) {
			\WP_CLI::error( 'Requiere --slug, --type y --connection-id.' );
		}
		$config = array();
		if ( ! empty( $assoc['config'] ) ) {
			$dec = json_decode( (string) $assoc['config'], true );
			if ( ! is_array( $dec ) ) {
				\WP_CLI::error( '--config debe ser JSON válido.' );
			}
			$config = $dec;
		}
		$id = Destinations::register_adapter( $slug, $type, $cid, $config );
		\WP_CLI::success( $id ? "Destino adapter #{$id} registrado." : 'No se pudo registrar (tipo o conexión inválidos).' );
	}

	/** wp estandar-suite integrations destination test --id=N */
	public static function integrations_destination_test( array $args, array $assoc ): void
	{
		$r = Destinations::test( (int) ( $assoc['id'] ?? 0 ) );
		$r['ok'] ? \WP_CLI::success( "Prueba OK (HTTP {$r['http']})." ) : \WP_CLI::error( "Prueba falló (HTTP {$r['http']}): {$r['error']}" );
	}

	// ── OAuth Pending (sandbox): valida el pipeline sin credenciales ───────

	/** wp estandar-suite integrations sandbox connect [--label=...] */
	public static function integrations_sandbox_connect( array $args, array $assoc ): void
	{
		$label = sanitize_text_field( (string) ( $assoc['label'] ?? 'Simulación' ) );
		foreach ( Connections::list( 'sandbox' ) as $c ) {
			if ( $c['status'] === 'active' ) {
				\WP_CLI::success( "Conexión sandbox activa #{$c['id']} (reutilizada)." );
				return;
			}
		}
		$id = Connections::register( 'sandbox', $label, array( 'access_token' => 'sandbox' ), array(), array() );
		$id ? \WP_CLI::success( "Conexión sandbox #{$id} creada (sin OAuth)." ) : \WP_CLI::error( 'No se pudo crear la conexión sandbox.' );
	}

	/** wp estandar-suite integrations sandbox status */
	public static function integrations_sandbox_status( array $args, array $assoc ): void
	{
		$conns = Connections::list( 'sandbox' );
		\WP_CLI::log( sprintf( 'conexiones_sandbox=%d', count( $conns ) ) );
		foreach ( $conns as $c ) {
			\WP_CLI::log( sprintf( '  #%d · %s · %s', (int) $c['id'], $c['label'], $c['status'] ) );
		}
		foreach ( Sandbox::files() as $f ) {
			$rows = Sandbox::get( $f['id'] );
			\WP_CLI::log( sprintf( '  archivo %s · %s · filas=%d', $f['id'], $f['name'], count( $rows['rows'] ?? array() ) ) );
		}
	}

	/**
	 * wp estandar-suite integrations sandbox run [--form-id=N] [--columns="Nombre|Email"]
	 *   [--log=/path] — validates the full pipeline: connect → create → columns →
	 *   bind → entry → drain → verify 1 row → idempotency → retries/backoff.
	 */
	public static function integrations_sandbox_run( array $args, array $assoc ): void
	{
		$start = microtime( true );
		$steps = array();
		$form_id = (int) ( $assoc['form-id'] ?? 90001 );

		// Reset validation artifacts (repeatable run).
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_deliveries" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_sandbox_files" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_destinations WHERE slug IN ('sandbox-validacion','sandbox-fail')" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}estandar_core_integrations WHERE form_source = %s AND form_id = %d", 'v2', $form_id ) );

		// 1. Connection (sandbox).
		$t = microtime( true );
		$conn_id = 0;
		foreach ( Connections::list( 'sandbox' ) as $c ) {
			if ( $c['status'] === 'active' ) { $conn_id = (int) $c['id']; break; }
		}
		if ( ! $conn_id ) {
			$conn_id = Connections::register( 'sandbox', 'Simulación', array( 'access_token' => 'sandbox' ), array(), array() );
		}
		$steps[] = array( 'step' => '1. connection', 'ok' => $conn_id > 0, 'detail' => "connection_id={$conn_id}", 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );
		if ( ! $conn_id ) {
			\WP_CLI::error( 'Fallo en la conexión sandbox.' );
		}

		// 2-3-4-5. Create virtual file + sheet + columns from a test form.
		$t = microtime( true );
		$columns = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $assoc['columns'] ?? 'Nombre|Email|Mensaje' ) ) ) ) );
		$fields  = array();
		foreach ( $columns as $c ) {
			$fields[ strtolower( $c ) ] = $c; // field key (entry key) → column label
		}
		$adapter = Adapters::get( 'simulated' );
		$created = $adapter->create( Connections::get( $conn_id ), array( 'title' => 'Simulación Validación', 'sheet_title' => 'Datos', 'columns' => $columns ) );
		$steps[] = array( 'step' => '2. create file', 'ok' => $created['ok'], 'detail' => ( $created['config']['file_id'] ?? '' ) . ' · columns=' . implode( ',', $columns ), 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );
		if ( empty( $created['ok'] ) ) {
			\WP_CLI::error( 'Fallo al crear el archivo simulado: ' . ( $created['error'] ?? '' ) );
		}
		$config = $created['config'];

		$t = microtime( true );
		$slug = 'sandbox-validacion';
		$dest_id = Destinations::register_adapter( $slug, 'simulated', $conn_id, $config );
		$steps[] = array( 'step' => '3. destination', 'ok' => $dest_id > 0, 'detail' => "destination_id={$dest_id}", 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		$t = microtime( true );
		$mapping = FieldMap::auto_map( $fields, $columns );
		$bind_id = Integrations::bind( 'v2', $form_id, $dest_id, $mapping, true );
		$steps[] = array( 'step' => '4. bind+mapping', 'ok' => $bind_id > 0, 'detail' => 'mapping=' . wp_json_encode( $mapping ), 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		// 6. Send a test entry → dispatch → drain.
		$t = microtime( true );
		$capture = array(
			'id'             => 1,
			'source_form_id' => (string) $form_id,
			'nombre'         => 'Ana',
			'email'          => 'ana@test.cl',
			'mensaje'        => 'Hola sandbox',
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'ip_address'     => '190.12.3.4',
			'landing_url'    => 'https://test.local/formulario',
		);
		$dispatched = Integrations::dispatch_for_form( 'v2', $form_id, $capture );
		list( $sent, $failed, $left ) = Destinations::drain( 10 );
		$steps[] = array( 'step' => '5. entry+drain', 'ok' => $sent === 1 && $failed === 0, 'detail' => "dispatched=" . wp_json_encode( $dispatched ) . " sent={$sent} failed={$failed}", 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		// 7. Verify exactly one row.
		$t = microtime( true );
		$file = Sandbox::get( (string) $config['file_id'] );
		$rows = is_array( $file ) ? ( $file['rows'] ?? array() ) : array();
		$steps[] = array( 'step' => '6. verify 1 row', 'ok' => count( $rows ) === 1, 'detail' => 'filas=' . count( $rows ) . ' · row=' . wp_json_encode( $rows[0] ?? array() ), 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		// 8a. Idempotency: re-dispatch the SAME entry → still 1 row.
		$t = microtime( true );
		Integrations::dispatch_for_form( 'v2', $form_id, $capture );
		Destinations::drain( 10 );
		$file = Sandbox::get( (string) $config['file_id'] );
		$rows_after = count( is_array( $file ) ? ( $file['rows'] ?? array() ) : array() );
		$steps[] = array( 'step' => '7. idempotency', 'ok' => $rows_after === 1, 'detail' => 'filas_tras_reenvio=' . $rows_after, 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		// 8b. Retries/backoff: failing destination → retry + next_attempt_at, then dead.
		$t = microtime( true );
		$fail_slug = 'sandbox-fail';
		$fail_dest = Destinations::register_adapter( $fail_slug, 'simulated', $conn_id, array( 'file_id' => 'missing-file' ) );
		Destinations::enqueue( $fail_slug, 'capture', 'capture', 999, array( 'id' => 999 ) );
		Destinations::drain( 10 );
		global $wpdb;
		$retry = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status, attempts, next_attempt_at FROM {$wpdb->prefix}estandar_core_deliveries WHERE destination_id = %d AND entity_id = 999 LIMIT 1",
			$fail_dest
		), ARRAY_A );
		$backoff_ok = is_array( $retry ) && $retry['status'] === 'retry' && ! empty( $retry['next_attempt_at'] ) && strtotime( (string) $retry['next_attempt_at'] ) > time();
		$steps[] = array( 'step' => '8. backoff retry', 'ok' => $backoff_ok, 'detail' => wp_json_encode( $retry ), 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );
		// Force 4 more failures → dead.
		$t = microtime( true );
		$dead = false;
		for ( $i = 0; $i < 4; $i++ ) {
			$wpdb->update( $wpdb->prefix . 'estandar_core_deliveries', array( 'next_attempt_at' => null ), array( 'id' => (int) $retry['id'] ), array( '%s' ), array( '%d' ) );
			Destinations::drain( 10 );
			$retry = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, status, attempts FROM {$wpdb->prefix}estandar_core_deliveries WHERE id = %d LIMIT 1",
				(int) $retry['id']
			), ARRAY_A );
			if ( is_array( $retry ) && $retry['status'] === 'dead' ) { $dead = true; break; }
		}
		$steps[] = array( 'step' => '9. dead after retries', 'ok' => $dead, 'detail' => wp_json_encode( $retry ), 'ms' => round( ( microtime( true ) - $t ) * 1000 ) );

		// Report.
		$total_ms = round( ( microtime( true ) - $start ) * 1000 );
		$all_ok = true;
		foreach ( $steps as $s ) {
			$all_ok = $all_ok && $s['ok'];
			\WP_CLI::log( sprintf( '%s [%s] %dms · %s', $s['step'], $s['ok'] ? 'OK' : 'FAIL', $s['ms'], $s['detail'] ) );
		}
		$log_path = (string) ( $assoc['log'] ?? '' );
		if ( $log_path !== '' ) {
			file_put_contents( $log_path, wp_json_encode( array(
				'ts' => gmdate( 'c' ), 'provider' => 'sandbox', 'total_ms' => $total_ms, 'steps' => $steps,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
		}
		$all_ok ? \WP_CLI::success( "Sandbox OK · {$total_ms}ms · 9 pasos." ) : \WP_CLI::error( "Sandbox con fallos · {$total_ms}ms." );
	}

	/**
	 * Cardinality evidence: ONE form → TWO destinations on TWO different
	 * accounts (connections). Proves: multiple bindings, distinct connections,
	 * distinct files, per-integration deliveries, idempotency per integration,
	 * conditional skip and failure isolation (parallel).
	 */
	public static function integrations_sandbox_multi( array $args, array $assoc ): void
	{
		global $wpdb;
		$start  = microtime( true );
		$form_id = (int) ( $assoc['form-id'] ?? 90002 );
		$steps  = array();

		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_deliveries" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_sandbox_files" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}estandar_core_destinations WHERE slug LIKE 'sandbox-multi%'" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}estandar_core_integrations WHERE form_source = %s AND form_id = %d", 'v2', $form_id ) );

		// Two connections = two independent accounts.
		$conn_a = Connections::register( 'sandbox', 'Cuenta A', array( 'access_token' => 'sandbox' ), array(), array() );
		$conn_b = Connections::register( 'sandbox', 'Cuenta B', array( 'access_token' => 'sandbox' ), array(), array() );
		$steps[] = array( 'check' => 'dos conexiones (cuentas distintas)', 'ok' => $conn_a > 0 && $conn_b > 0 && $conn_a !== $conn_b, 'detail' => "conn_a={$conn_a} conn_b={$conn_b}" );

		$adapter = Adapters::get( 'simulated' );
		$file_a  = $adapter->create( Connections::get( $conn_a ), array( 'title' => 'Archivo X', 'columns' => array( 'Nombre', 'Email' ) ) );
		$file_b  = $adapter->create( Connections::get( $conn_b ), array( 'title' => 'Archivo Y', 'columns' => array( 'Nombre', 'Email', 'Mensaje' ) ) );
		$steps[] = array( 'check' => 'archivos independientes (X en A, Y en B)', 'ok' => ! empty( $file_a['config']['file_id'] ) && ! empty( $file_b['config']['file_id'] ) && $file_a['config']['file_id'] !== $file_b['config']['file_id'], 'detail' => "X={$file_a['config']['file_id']} Y={$file_b['config']['file_id']}" );

		$dest_a = Destinations::register_adapter( 'sandbox-multi-a', 'simulated', $conn_a, $file_a['config'] );
		$dest_b = Destinations::register_adapter( 'sandbox-multi-b', 'simulated', $conn_b, $file_b['config'] );
		$steps[] = array( 'check' => 'dos destinos en cuentas distintas', 'ok' => $dest_a > 0 && $dest_b > 0 && $dest_a !== $dest_b, 'detail' => "dest_a={$dest_a}(conn={$conn_a}) dest_b={$dest_b}(conn={$conn_b})" );

		// Bind the SAME form to both destinations (different mappings).
		$bind_a = Integrations::bind( 'v2', $form_id, $dest_a, array( 'nombre' => 'Nombre', 'email' => 'Email' ), true );
		$bind_b = Integrations::bind( 'v2', $form_id, $dest_b, array( 'nombre' => 'Nombre', 'email' => 'Email', 'mensaje' => 'Mensaje' ), true );
		$steps[] = array( 'check' => 'un form → dos bindings', 'ok' => $bind_a > 0 && $bind_b > 0 && $bind_a !== $bind_b, 'detail' => "bind_a={$bind_a} bind_b={$bind_b}" );
		$count = count( Integrations::list( 'v2', $form_id ) );
		$steps[] = array( 'check' => 'bindings registrados = 2', 'ok' => $count === 2, 'detail' => "bindings={$count}" );

		// One entry → fan-out to both (per-integration deliveries).
		$entry = array( 'id' => 7, 'nombre' => 'Ana', 'email' => 'ana@test.cl', 'mensaje' => 'Hola doble' );
		$d = Integrations::dispatch_for_form( 'v2', $form_id, $entry );
		$steps[] = array( 'check' => 'una entry → deliveries por integración', 'ok' => ( $d['queued'] ?? 0 ) === 2, 'detail' => wp_json_encode( $d ) );
		Destinations::drain( 10 );
		$rows_a = count( Sandbox::get( $file_a['config']['file_id'] )['rows'] ?? array() );
		$rows_b = count( Sandbox::get( $file_b['config']['file_id'] )['rows'] ?? array() );
		$steps[] = array( 'check' => 'ambos archivos reciben exactamente 1 fila', 'ok' => $rows_a === 1 && $rows_b === 1, 'detail' => "X filas={$rows_a} Y filas={$rows_b}" );

		// Idempotency per integration: re-dispatch the SAME entry.
		Integrations::dispatch_for_form( 'v2', $form_id, $entry );
		Destinations::drain( 10 );
		$rows_a2 = count( Sandbox::get( $file_a['config']['file_id'] )['rows'] ?? array() );
		$rows_b2 = count( Sandbox::get( $file_b['config']['file_id'] )['rows'] ?? array() );
		$steps[] = array( 'check' => 'idempotencia por integración (sin duplicar)', 'ok' => $rows_a2 === 1 && $rows_b2 === 1, 'detail' => "X={$rows_a2} Y={$rows_b2}" );

		// Conditional: 3rd destination runs only when country=Chile.
		$file_c = $adapter->create( Connections::get( $conn_a ), array( 'title' => 'Archivo Cond', 'columns' => array( 'Nombre' ) ) );
		$dest_c = Destinations::register_adapter( 'sandbox-multi-cond', 'simulated', $conn_a, $file_c['config'] );
		Integrations::bind( 'v2', $form_id, $dest_c, array( 'nombre' => 'Nombre' ), true, array( 'mode' => 'conditional', 'condition' => array( 'logic' => 'and', 'rules' => array( array( 'field' => 'pais', 'op' => 'eq', 'value' => 'Chile' ) ) ) ) );
		$entry_cl = array( 'id' => 8, 'nombre' => 'Benja', 'pais' => 'Chile' );
		$entry_pe = array( 'id' => 9, 'nombre' => 'Luis', 'pais' => 'Perú' );
		Integrations::dispatch_for_form( 'v2', $form_id, $entry_cl );
		Integrations::dispatch_for_form( 'v2', $form_id, $entry_pe );
		Destinations::drain( 10 );
		$rows_c = count( Sandbox::get( $file_c['config']['file_id'] )['rows'] ?? array() );
		$steps[] = array( 'check' => 'condicional: solo Chile entra', 'ok' => $rows_c === 1, 'detail' => "filas_cond={$rows_c} (esperado 1)" );

		// Failure isolation (parallel): a failing destination does not block the others.
		$rows_a_before = count( Sandbox::get( $file_a['config']['file_id'] )['rows'] ?? array() );
		$dest_d = Destinations::register_adapter( 'sandbox-multi-fail', 'simulated', $conn_b, array( 'file_id' => 'missing-file' ) );
		Integrations::bind( 'v2', $form_id, $dest_d, array( 'nombre' => 'Nombre' ), true );
		$entry_iso = array( 'id' => 10, 'nombre' => 'Aislado' );
		Integrations::dispatch_for_form( 'v2', $form_id, $entry_iso );
		Destinations::drain( 10 );
		$rows_a_after = count( Sandbox::get( $file_a['config']['file_id'] )['rows'] ?? array() );
		$fail_retry = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}estandar_core_deliveries WHERE destination_id = %d AND status = 'retry'", $dest_d
		) ) === 1;
		$steps[] = array( 'check' => 'aislamiento de fallos (paralelo)', 'ok' => $rows_a_after === $rows_a_before + 1 && $fail_retry, 'detail' => "X antes={$rows_a_before} despues={$rows_a_after} dest_fail_retry=" . ( $fail_retry ? 'sí' : 'no' ) );

		$total_ms = round( ( microtime( true ) - $start ) * 1000 );
		$all_ok = true;
		foreach ( $steps as $s ) {
			$all_ok = $all_ok && $s['ok'];
			\WP_CLI::log( sprintf( '%s [%s] · %s', $s['check'], $s['ok'] ? 'OK' : 'FAIL', $s['detail'] ) );
		}
		$log_path = (string) ( $assoc['log'] ?? '' );
		if ( $log_path !== '' ) {
			file_put_contents( $log_path, wp_json_encode( array(
				'ts' => gmdate( 'c' ), 'kind' => 'sandbox-multi', 'form_id' => $form_id, 'total_ms' => $total_ms, 'checks' => $steps,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
		}
		$all_ok ? \WP_CLI::success( "Cardinalidad OK · {$total_ms}ms · " . count( $steps ) . ' checks.' ) : \WP_CLI::error( "Cardinalidad con fallos · {$total_ms}ms." );
	}

	// ── Generic settings gate: any estandar_suite_* option via CLI ─────────

	/** PURE: is an option name a settable suite setting? */
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
			\WP_CLI::log( sprintf( '%s: %s', $tab, $subs ? implode( ', ', $subs ) : '(ninguno)' ) );
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
}
