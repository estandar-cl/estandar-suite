<?php
/**
 * smoke-update-preservation.php — Gate de PRESERVACIÓN en la actualización.
 *
 * Simula el ciclo de actualización de WordPress (los archivos se reemplazan y
 * los plugins se re-activan) y verifica que NADA se pierda ni se formatee:
 *   - conteo de filas de todas las tablas de datos Estandar (entries, leads,
 *     submissions, quotes, events…) intacto;
 *   - valores de opciones (tokens, API keys, settings) byte-idénticos;
 *   - conjunto de plugins activos idéntico;
 *   - cero fatals nuevos en debug.log.
 *
 * Solo opera sobre el Playground local. Nunca producción.
 * Salida: "PRESERVE_OK tablas=N opciones=M plugins=P fatals_nuevos=0" o
 *         "PRESERVE_FAIL <detalle>" con exit(1).
 */

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );

$suite_root = dirname( __DIR__ );
require $suite_root . '/Playground/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$debug_log = $suite_root . '/Playground/wp-content/debug.log';
$fatals_before = 0;
if ( is_file( $debug_log ) ) {
	preg_match_all( '/Fatal error/', (string) file_get_contents( $debug_log ), $m );
	$fatals_before = count( $m[0] );
}

// Opciones que pueden cambiar legítimamente durante el ciclo (mecánica de WP,
// no datos del negocio) — no se comparan byte a byte.
$option_skip_patterns = array(
	'/^active_plugins$/',
	'/^cron$/',
	'/^_transient_/',
	'/^_site_transient_/',
	'/^recently_activated$/',
	'/^uninstall_plugins$/',
	'/^db_version$/',
	'/^auto_update_plugins$/',
	'/^sticky_/',
	// Locks transitorios del upgrader de WP Core (runtime, expiran solos,
	// autoload=off): se crean/liberan por el auto-updater durante el ciclo.
	'/^auto_updater\.lock$/',
	'/^core_updater\.lock$/',
	// rewrite_rules es una opción DERIVADA: WP la regenera por flush_rewrite_rules()
	// en cada ciclo de (des)activación de plugins. No es configuración ni dato
	// del negocio (misma naturaleza que cron/transients ya excluidos).
	'/^rewrite_rules$/',
	// Diagnósticos runtime con timestamp propio (no son configuración ni datos):
	'/^estandar_chatbot_env_status$/', // env-checker: checked_at = current_time()
);

$is_skipped_option = static function ( string $name ) use ( $option_skip_patterns ): bool {
	foreach ( $option_skip_patterns as $re ) {
		if ( preg_match( $re, $name ) ) {
			return true;
		}
	}
	return false;
};

// ── Descubrir plugins instalados (mismo algoritmo que smoke-all-together) ──
$discovered = array();
foreach ( glob( WP_PLUGIN_DIR . '/*' ) ?: array() as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$slug = basename( $dir );
	if ( $slug === '' || $slug[0] === '.' ) {
		continue;
	}
	foreach ( glob( $dir . '/*.php' ) ?: array() as $candidate ) {
		$head = (string) file_get_contents( $candidate, false, null, 0, 4000 );
		if ( strpos( $head, 'Plugin Name:' ) !== false ) {
			$discovered[] = $slug . '/' . basename( $candidate );
			break;
		}
	}
}

// ── Snapshot A ─────────────────────────────────────────────────────────────
$table_prefixes = array( $wpdb->prefix . 'es_', $wpdb->prefix . 'estandar_', $wpdb->prefix . 'ecb_' );
$tables_before = array();
foreach ( $table_prefixes as $prefix ) {
	$like = str_replace( '_', '\_', $prefix );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $like . '%' ) ) as $t ) {
		$tables_before[ $t ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );
	}
}

$options_before = array();
foreach ( (array) $wpdb->get_results( 'SELECT option_name, option_value FROM ' . $wpdb->options, ARRAY_A ) as $row ) {
	if ( $is_skipped_option( $row['option_name'] ) ) {
		continue;
	}
	$options_before[ $row['option_name'] ] = (string) $row['option_value'];
}

$active_before = array_values( get_option( 'active_plugins', array() ) );
sort( $active_before );

// ── Ciclo de actualización: desactivar todo y reactivar todo ────────────────
$deactivated = deactivate_plugins( $active_before );
$reactivated_ok = 0;
$reactivated_fail = 0;
foreach ( $discovered as $rel ) {
	$r = activate_plugin( $rel );
	if ( is_wp_error( $r ) ) {
		$reactivated_fail++;
		echo "PRESERVE_REACTIVATE_FAIL {$rel}: " . $r->get_error_message() . "\n";
	} else {
		$reactivated_ok++;
	}
}

// ── Snapshot B ─────────────────────────────────────────────────────────────
$tables_after = array();
foreach ( $table_prefixes as $prefix ) {
	$like = str_replace( '_', '\_', $prefix );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $like . '%' ) ) as $t ) {
		$tables_after[ $t ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );
	}
}

$options_after = array();
foreach ( (array) $wpdb->get_results( 'SELECT option_name, option_value FROM ' . $wpdb->options, ARRAY_A ) as $row ) {
	if ( $is_skipped_option( $row['option_name'] ) ) {
		continue;
	}
	$options_after[ $row['option_name'] ] = (string) $row['option_value'];
}

$active_after = array_values( get_option( 'active_plugins', array() ) );
sort( $active_after );

$fatals_after = 0;
if ( is_file( $debug_log ) ) {
	preg_match_all( '/Fatal error/', (string) file_get_contents( $debug_log ), $m );
	$fatals_after = count( $m[0] );
}

// ── Comparación ────────────────────────────────────────────────────────────
$failures = array();

foreach ( $tables_before as $t => $count ) {
	if ( ! array_key_exists( $t, $tables_after ) ) {
		$failures[] = "tabla perdida: {$t}";
	} elseif ( $tables_after[ $t ] !== $count ) {
		$failures[] = "tabla con conteo distinto: {$t} ({$count} → {$tables_after[$t]})";
	}
}

foreach ( $options_before as $name => $value ) {
	if ( ! array_key_exists( $name, $options_after ) ) {
		$failures[] = "opción perdida: {$name}";
	} elseif ( $options_after[ $name ] !== $value ) {
		$failures[] = "opción modificada/formateada: {$name}";
	}
}

if ( $active_before !== $active_after ) {
	$failures[] = 'conjunto de plugins activos distinto: ' . implode( ',', array_diff( $active_before, $active_after ) ) . ' | ' . implode( ',', array_diff( $active_after, $active_before ) );
}

$fatals_new = $fatals_after - $fatals_before;
if ( $fatals_new > 0 ) {
	$failures[] = "fatals nuevos: {$fatals_new}";
}

if ( $reactivated_fail > 0 ) {
	$failures[] = "reactivaciones fallidas: {$reactivated_fail}";
}

// Tablas/opciones NUEVAS tras el ciclo (no es pérdida, es aviso).
$new_tables = array_diff_key( $tables_after, $tables_before );
$new_options = array_diff_key( $options_after, $options_before );

if ( ! empty( $failures ) ) {
	echo "PRESERVE_FAIL " . implode( ' | ', array_slice( $failures, 0, 15 ) ) . "\n";
	exit( 1 );
}

echo 'PRESERVE_OK tablas=' . count( $tables_before )
	. ' opciones=' . count( $options_before )
	. ' plugins=' . count( $active_after )
	. ' fatals_nuevos=' . $fatals_new
	. ' nuevas_tablas=' . count( $new_tables )
	. ' nuevas_opciones=' . count( $new_options )
	. "\n";
exit( 0 );
