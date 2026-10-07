<?php
/**
 * theme-smoke.php — Smoke "todos juntos" por cada tema de producción.
 *
 * Para cada tema objetivo declarado en `tests/release-targets.php`
 * (Divi, Divi-child, astra, astra-child), lo activa en el Playground local
 * (todos los plugins ya activos) y verifica:
 *   1. Cero fatals nuevos en debug.log.
 *   2. La home responde HTTP 200 (requiere el servidor dev en localhost:8080).
 *
 * Atrapa fatals que solo aparecen con el tema real del sitio (hooks
 * específicos de Divi/Astra) y no con twentytwentyfive.
 *
 * Salida de éxito: "THEME_SMOKE_OK temas=N fallos=0"
 * Salida de fallo:  "THEME_SMOKE_FAIL ..." + detalle + exit(1)
 *
 * Uso: php tests/theme-smoke.php   (levanta el servidor si no está arriba)
 */

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );

$suite_root = dirname( __DIR__ );
require $suite_root . '/Playground/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Temas objetivo (deduplicados de todas las instalaciones).
$targets = require dirname( __FILE__ ) . '/release-targets.php';
$themes  = array();
foreach ( $targets['installations'] as $inst ) {
	foreach ( ( $inst['themes'] ?? array() ) as $t ) {
		$themes[ $t ] = true;
	}
}
$themes = array_keys( $themes );
sort( $themes );

// Asegurar que TODOS los plugins estén activos (igual que smoke-all-together).
$dirs = glob( WP_PLUGIN_DIR . '/*' ) ?: array();
foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$slug = basename( $dir );
	if ( $slug === '' || $slug[0] === '.' ) {
		continue;
	}
	$main = '';
	foreach ( glob( $dir . '/*.php' ) ?: array() as $candidate ) {
		$head = (string) file_get_contents( $candidate, false, null, 0, 4000 );
		if ( strpos( $head, 'Plugin Name:' ) !== false ) {
			$main = $candidate;
			break;
		}
	}
	if ( $main === '' ) {
		continue;
	}
	$rel = $slug . '/' . basename( $main );
	if ( ! is_plugin_active( $rel ) ) {
		$result = activate_plugin( $rel );
		if ( is_wp_error( $result ) ) {
			echo "THEME_SMOKE_FAIL activar_plugin {$rel}: " . $result->get_error_message() . "\n";
			exit( 1 );
		}
	}
}

// Contar fatals en debug.log.
$debug_log = $suite_root . '/Playground/wp-content/debug.log';
$count_fatals = static function () use ( $debug_log ) {
	if ( ! is_file( $debug_log ) ) {
		return 0;
	}
	$haystack = (string) file_get_contents( $debug_log );
	preg_match_all( '/Fatal error/', $haystack, $m );
	return count( $m[0] );
};

$base   = 'http://localhost:8080/';
$probe  = wp_remote_get( $base, array( 'timeout' => 60, 'sslverify' => false ) );
if ( is_wp_error( $probe ) ) {
	echo 'THEME_SMOKE_FAIL servidor_dev_no_arriba ' . $probe->get_error_message() . "\n";
	exit( 1 );
}

$original_stylesheet = get_option( 'stylesheet' );
$original_template   = get_option( 'template' );

$failed = array();

foreach ( $themes as $slug ) {
	$theme = wp_get_theme( $slug );
	if ( ! $theme->exists() ) {
		echo "THEME_SMOKE_FAIL tema_no_encontrado {$slug}\n";
		exit( 1 );
	}
	// Tema hijo: el padre debe existir en el Playground.
	$parent = $theme->get( 'Template' );
	if ( $parent && ! wp_get_theme( $parent )->exists() ) {
		echo "THEME_SMOKE_FAIL tema_padre_faltante {$slug}→{$parent}\n";
		exit( 1 );
	}

	$fatals_before = $count_fatals();

	$switched = switch_theme( $slug );
	if ( is_wp_error( $switched ) ) {
		echo "THEME_SMOKE_FAIL switch_theme {$slug}: " . $switched->get_error_message() . "\n";
		exit( 1 );
	}

	$resp   = wp_remote_get( $base, array( 'timeout' => 120, 'sslverify' => false ) );
	$code   = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
	$fatals_after = $count_fatals();
	$new_fatals   = $fatals_after - $fatals_before;

	echo "  theme={$slug} http={$code} fatals_nuevos={$new_fatals}\n";
	if ( $code !== 200 || $new_fatals > 0 ) {
		$failed[] = $slug;
	}
}

// Restaurar el tema original del Playground (no dejar estado modificado).
update_option( 'stylesheet', $original_stylesheet );
update_option( 'template', $original_template );

if ( count( $failed ) > 0 ) {
	echo "THEME_SMOKE_FAIL temas=" . count( $themes ) . ' fallos=' . count( $failed ) . ' → ' . implode( ',', $failed ) . "\n";
	exit( 1 );
}

echo 'THEME_SMOKE_OK temas=' . count( $themes ) . " fallos=0 (" . implode( ',', $themes ) . ")\n";
exit( 0 );
