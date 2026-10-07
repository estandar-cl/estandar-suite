<?php
/**
 * Updates (estandar-core) — manifest + panel de actualizaciones (Playground).
 *
 *   php tests/run-updates.php   → expect "0 failed"
 *
 * Covers: manifest estructura, refresh, detección de update, transient de
 * update inyectado y guardas de update_plugin (sin ejecutar upgrades reales).
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Core\Updates' ) ) {
	fwrite( STDERR, "run-updates: clase Updates no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;
function upd_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

use Estandar\Core\Updates;

// ── manifest ────────────────────────────────────────────────────────────────
$m = Updates::manifest();
upd_check( 'manifest: estructura con last_checked', isset( $m['last_checked'] ) && is_array( $m['plugins'] ) );
upd_check( 'manifest: plugins = slugs() del update', count( $m['plugins'] ) === count( Updates::slugs() ) );
$required = array( 'slug', 'file', 'local', 'remote', 'update', 'package' );
$keys_ok = true;
foreach ( $m['plugins'] as $slug => $row ) {
	if ( ! in_array( $slug, Updates::slugs(), true ) ) { $keys_ok = false; }
	foreach ( $required as $k ) {
		if ( ! array_key_exists( $k, $row ) ) { $keys_ok = false; }
	}
}
upd_check( 'manifest: claves completas por plugin', $keys_ok );
upd_check( 'manifest: estandar-core local no vacío', $m['plugins']['estandar-core']['local'] !== '' );
upd_check( 'manifest: paquete apunta al canal v2', strpos( $m['plugins']['estandar-core']['package'], 'updates.estandar.cl/staging/v2/' ) !== false );

// ── slugs() filtrable: plugins externos (veloz) sin acoplar el core ─────────
$add_ext = function ( $s ) { $s[] = 'veloz'; return $s; };
add_filter( 'estandar_suite_update_slugs', $add_ext );
upd_check( 'slugs: filtro agrega plugin externo', in_array( 'veloz', Updates::slugs(), true ) );
remove_filter( 'estandar_suite_update_slugs', $add_ext );
upd_check( 'slugs: sin filtro vuelve a la base (6)', count( Updates::slugs() ) === 6 );

// ── refresh ─────────────────────────────────────────────────────────────────
$r = Updates::refresh();
upd_check( 'refresh: devuelve manifiesto', is_array( $r ) && isset( $r['plugins'] ) );

// ── detección de update simulada ────────────────────────────────────────────
set_transient( Updates::CACHE_KEY, array( 'estandar-core' => '99.0.0' ), HOUR_IN_SECONDS );
$m2 = Updates::manifest();
upd_check( 'detección: update=true con remoto mayor', $m2['plugins']['estandar-core']['update'] === true );
upd_check( 'detección: resto sin update', $m2['plugins']['estandar-capture']['update'] === false );

// ── ensure_update_transient ─────────────────────────────────────────────────
$before = get_site_transient( 'update_plugins' );
Updates::ensure_update_transient();
$after = get_site_transient( 'update_plugins' );
$hit = false;
if ( is_object( $after ) && isset( $after->response ) && is_array( $after->response ) ) {
	foreach ( $after->response as $file => $u ) {
		if ( $file === 'estandar-core/estandar-core.php' && (string) $u->new_version === '99.0.0' ) {
			$hit = true;
		}
	}
}
upd_check( 'transient: estandar-core inyectado con new_version', $hit );
set_site_transient( 'update_plugins', $before );
delete_transient( Updates::CACHE_KEY );

// ── update_plugin guards (sin ejecutar upgrades reales) ────────────────────
$bad = Updates::update_plugin( 'estandar-otro' );
upd_check( 'update_plugin: slug fuera de la suite → error', $bad['ok'] === false && strpos( $bad['message'], 'no soportado' ) !== false );
$noup = Updates::update_plugin( 'estandar-core' );
upd_check( 'update_plugin: sin update disponible → error claro', $noup['ok'] === false && strpos( $noup['message'], 'No hay actualización disponible' ) !== false );

// ── plugin_file ─────────────────────────────────────────────────────────────
upd_check( 'plugin_file: ruta canónica', Updates::plugin_file( 'estandar-core' ) === 'estandar-core/estandar-core.php' );

// ── AUTO-UPDATE propio (2026-09-07, sesión 025) ─────────────────────────────
upd_check( 'auto: política por defecto es auto', Updates::is_auto() === true );
$m0 = Updates::manifest();
upd_check( 'auto: sin release nuevo no hay candidatos', Updates::candidates( $m0['plugins'] ) === array() );

set_transient( Updates::CACHE_KEY, array( 'estandar-core' => '99.0.0' ), HOUR_IN_SECONDS );
$m3 = Updates::manifest();
upd_check( 'auto: candidato con remoto mayor', Updates::candidates( $m3['plugins'] ) === array( 'estandar-core' ) );
delete_transient( Updates::CACHE_KEY );

// Política pin bloquea el auto (off/pin → is_auto false; is_blocked en >pin).
Updates::set_policy( 'pin:99.0.0' );
upd_check( 'auto: pin desactiva is_auto', Updates::is_auto() === false );
upd_check( 'auto: is_blocked para remoto > pin', Updates::is_blocked( '99.0.1' ) === true );
upd_check( 'auto: is_blocked no para remoto ≤ pin', Updates::is_blocked( '99.0.0' ) === false );
Updates::set_policy( 'auto' );
upd_check( 'auto: restore a auto', Updates::is_auto() === true );

// Cron propio agendado (boot → ensure_cron).
upd_check( 'auto: AUTO_HOOK agendado en WP', (int) wp_next_scheduled( Updates::AUTO_HOOK ) > 0 );
upd_check( 'auto: CRON_HOOK (refresh diario) agendado', (int) wp_next_scheduled( Updates::CRON_HOOK ) > 0 );

// Guardas de fuente: auto_run actualiza por update_plugin con candado.
$upd_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Updates.php' );
upd_check( 'auto: auto_run invoca update_plugin', strpos( $upd_src, 'self::update_plugin( $slug )' ) !== false );
upd_check( 'auto: candado anti-concurrencia presente', strpos( $upd_src, 'AUTO_LOCK_OPTION' ) !== false && strpos( $upd_src, 'lock_active' ) !== false );
upd_check( 'auto: cron cada 6 h registrado', strpos( $upd_src, 'AUTO_INTERVAL' ) !== false );
upd_check( 'auto: reactiva si estaba activo tras actualizar', strpos( $upd_src, '$was_active = function_exists( \'is_plugin_active\' ) && is_plugin_active( $file );' ) !== false );

delete_option( Updates::AUTO_LOCK_OPTION );

// ── Interruptor nativo de WP (auto_update_plugins) controlable (2026-09-07) ─
$prior_membership = get_site_option( 'auto_update_plugins', array() );
$prior_seed       = get_option( Updates::AUTO_SEED_OPTION, 0 );
delete_option( Updates::AUTO_SEED_OPTION );
update_site_option( 'auto_update_plugins', array() );
Updates::set_policy( 'auto' );
$item_core = (object) array( 'plugin' => 'estandar-core/estandar-core.php', 'new_version' => '99.0.0' );

upd_check( 'toggle: pre-siembra permite auto (compat)', Updates::auto_update_plugin( false, $item_core ) === true );
update_option( Updates::AUTO_SEED_OPTION, 1, false ); // marca la siembra hecha
update_site_option( 'auto_update_plugins', array( 'estandar-core/estandar-core.php' ) );
upd_check( 'toggle: con interruptor WP on → true', Updates::auto_update_plugin( false, $item_core ) === true );
upd_check( 'toggle: is_auto_updatable true con interruptor on', Updates::is_auto_updatable( 'estandar-core' ) === true );

update_site_option( 'auto_update_plugins', array() ); // usuario apagó el interruptor
upd_check( 'toggle: interruptor off → false (se puede apagar)', Updates::auto_update_plugin( false, $item_core ) === false );
upd_check( 'toggle: is_auto_updatable false con interruptor off', Updates::is_auto_updatable( 'estandar-core' ) === false );
upd_check( 'toggle: la siembra NO re-agrega (marker presente)', Updates::is_auto_updatable( 'estandar-capture' ) === false );

// Política off sigue siendo el freno duro, aunque el interruptor esté on.
Updates::set_policy( 'off' );
update_site_option( 'auto_update_plugins', array( 'estandar-core/estandar-core.php' ) );
upd_check( 'toggle: política off bloquea aunque interruptor on', Updates::auto_update_plugin( false, $item_core ) === false );

// AUTO_REPAIR_SEED: la siembra por firma re-siembra al cambiar el conjunto de slugs.
delete_option( Updates::AUTO_SEED_OPTION );
update_site_option( 'auto_update_plugins', array() );
Updates::set_policy( 'auto' );
Updates::ensure_auto_seed();
$sig1 = (string) get_option( Updates::AUTO_SEED_OPTION, '' );
upd_check( 'seed: guarda firma (no booleano)', $sig1 !== '' && $sig1 !== '1' );
upd_check( 'seed: agrega el core instalado al interruptor', in_array( 'estandar-core/estandar-core.php', Updates::auto_membership(), true ) );
$add_ext2 = function ( $s ) { $s[] = 'plugin-externo'; return $s; };
add_filter( 'estandar_suite_update_slugs', $add_ext2 );
Updates::ensure_auto_seed();
$sig2 = (string) get_option( Updates::AUTO_SEED_OPTION, '' );
remove_filter( 'estandar_suite_update_slugs', $add_ext2 );
upd_check( 'seed: re-siembra al cambiar el conjunto (firma distinta)', $sig1 !== $sig2 );

// restore
Updates::set_policy( 'auto' );
update_site_option( 'auto_update_plugins', $prior_membership );
if ( $prior_seed !== 0 && $prior_seed !== '' ) { update_option( Updates::AUTO_SEED_OPTION, $prior_seed, false ); } else { delete_option( Updates::AUTO_SEED_OPTION ); }

// ── WP_UPGRADER_API_V1 (regresión): no usa la función procedural eliminada ──
$upd_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Updates.php' );
upd_check( 'update_plugin: no llama a upgrade_plugin() (eliminada en WP 7.1)', strpos( $upd_src, '$result = upgrade_plugin(' ) === false );
upd_check( 'update_plugin: usa la clase Plugin_Upgrader', strpos( $upd_src, 'new \\Plugin_Upgrader' ) !== false );
upd_check( 'update_plugin: guard si el upgrader no existe', strpos( $upd_src, 'El upgrader de WordPress no está disponible.' ) !== false );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
