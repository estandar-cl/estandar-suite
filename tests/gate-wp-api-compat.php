<?php
/**
 * gate-wp-api-compat.php — Gate de compatibilidad de API de WordPress.
 *
 * Previene la clase de bug de acacios.cl (2026-08-29): `upgrade_plugin()` fue
 * eliminada en WordPress 7.1 y `Updates::update_plugin()` la llamaba → fatal
 * en producción.
 *
 * Escanea el código de los plugins del scope y detecta llamadas a funciones
 * globales que NO existen en:
 *   - PHP (builtins del runtime local),
 *   - WP core (whitelist generada desde el Playground, MISMA versión de
 *     producción), o
 *   - las funciones definidas por la propia Suite (mismo scope).
 * Las llamadas protegidas por `function_exists(...)`/`method_exists(...)`/
 * `class_exists(...)`/`is_callable(...)` se consideran seguras (guard).
 * Llamadas a métodos (`->`, `::`) y variables (`$var(...)`) se ignoran.
 *
 * Salida de éxito: "WP_API_OK llamadas=N sin_reconocer=0"
 * Salida de fallo:  "WP_API_FAIL ..." + listado + exit(1)
 *
 * Uso: php tests/gate-wp-api-compat.php [--scope=plugin1,plugin2]
 */

// PCRE JIT (default en PHP >= 7.3) agota su stack al escanear en global archivos
// grandes con cientos de comillas (p. ej. includes/SiteMap.php, 46KB → error
// "JIT stack limit exhausted"). Desactivar el JIT usa el intérprete PCRE: el
// resultado del regex es idéntico y el scan completo termina sin error.
ini_set( 'pcre.jit', '0' );

require __DIR__ . '/gate-scope.php';
$plugins_root = $GATE_ROOT;

$whitelist_file = __DIR__ . '/wp-functions-whitelist.php';
if ( ! file_exists( $whitelist_file ) ) {
	fwrite( STDERR, "falta tests/wp-functions-whitelist.php — correr tests/generate-wp-functions-whitelist.php\n" );
	exit( 1 );
}
$wl = require $whitelist_file;
if ( ! is_array( $wl ) ) {
	fwrite( STDERR, "whitelist inválida\n" );
	exit( 1 );
}
$core  = isset( $wl['core'] ) ? array_flip( $wl['core'] ) : array();
$suite = isset( $wl['suite'] ) ? array_flip( $wl['suite'] ) : array();

$php_builtins = array_flip( get_defined_functions()['internal'] ?? array() );

// Funciones de WP-CLI (namespace WP_CLI\Utils) — existen solo cuando corre wp-cli.
$wp_cli_utils = array_flip( array( 'format_items', 'launch', 'get_flag_value', 'parse_shell_arrays', 'esc_sql_ident' ) );

// Funciones definidas por la propia Suite (dentro del scope escaneado).
$defined = array();

// Recolecta archivos del scope (lógica PHP; las plantillas HTML/JS y los
// assets generan ruido de strings que no son llamadas reales).
$files = array();
$scanned = empty( $GATE_SCOPE ) ? array_filter( scandir( $plugins_root ), function ( $d ) use ( $plugins_root ) {
	return is_dir( $plugins_root . '/' . $d ) && strpos( $d, 'estandar-' ) === 0;
} ) : array_keys( $GATE_SCOPE );
foreach ( $scanned as $slug ) {
	$dir = $plugins_root . '/' . $slug;
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->getExtension() !== 'php' ) {
			continue;
		}
		$rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $plugins_root ) + 1 ) );
		if ( strpos( $rel, '/templates/' ) !== false || strpos( $rel, '/assets/' ) !== false || strpos( $rel, '/tests/' ) !== false ) {
			continue;
		}
		// Código de terceros vendido (dompdf S16) no pasa por gates propios.
		if ( strpos( $rel, '/lib/dompdf/' ) !== false ) {
			continue;
		}
		$files[] = $f->getPathname();
	}
}
sort( $files );

// Pase 1: definiciones de funciones.
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( preg_match_all( '/\bfunction\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/', $src, $m ) ) {
		foreach ( $m[1] as $fn ) {
			$defined[ $fn ] = true;
		}
	}
}

// Funciones "externas" invocadas SOLO dentro de un guard function_exists():
// no existen en el código de la suite pero su ausencia no produce fatal
// (el guard las hace opcionales).
$guarded = array();
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( preg_match_all( '/function_exists\s*\(\s*[\'"]([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)[\'"]/', $src, $m ) ) {
		foreach ( $m[1] as $fn ) {
			$guarded[ $fn ] = true;
		}
	}
}

// Pase 2: llamadas de función sin reconocer.
$unknown = array();
$total_calls = 0;
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	// Limpieza: primero comentarios y heredocs (pueden contener apóstrofes que
	// desbalancean los strings), luego strings. No se detectan nombres dentro
	// de texto/SQL/comentarios.
	$clean = $src;
	$clean = (string) ( preg_replace( '/<<<\s*[\'"]?(\w+)[\'"]?\s*\n.*?\n\s*\1\s*;/s', ' ', $clean ) ?? $clean );
	$clean = (string) ( preg_replace( '/\/\*.*?\*\//s', ' ', $clean ) ?? $clean );
	$clean = (string) ( preg_replace( '/^\s*\/\/[^\n]*/m', ' ', $clean ) ?? $clean );
	// Comentarios inline (después de código); no tocar `://` (sin strings aún).
	$clean = (string) ( preg_replace( '/(?<!:)\/\/[^\n]*/', ' ', $clean ) ?? $clean );
	// Strings: dobles primero (pueden contener apóstrofes que desbalancean las
	// simples), luego simples (pueden contener comillas dobles).
	$clean = (string) ( preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', ' ', $clean ) ?? $clean );
	$clean = (string) ( preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'/', ' ', $clean ) ?? $clean );
	// Modo HTML embebido (fuera de bloques de código PHP): texto/HTML/JS
	// literal de plantilla que genera falsos positivos ("correo (…)").
	$html_mode = '/\?' . '>[\s\S]*?(?:<\?' . 'php|<\?' . '=|$)/';
	$clean = (string) ( preg_replace( $html_mode, ' ', $clean ) ?? $clean );

	if ( ! preg_match_all( '/(?<![a-zA-Z0-9_\x80-\xff$>:])([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/', $clean, $m ) ) {
		continue;
	}
	foreach ( $m[1] as $fn ) {
		if ( in_array( $fn, array( 'function', 'if', 'elseif', 'else', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'catch', 'isset', 'empty', 'unset', 'array', 'list', 			'print', 'echo', 'exit', 'die', 'return', 'match', 'fn', 'clone', 'new', 'include', 'include_once', 'require', 'require_once', 'eval', 'assert', 'defined', 'sprintf', 'count', 'use', 'self', 'static', 'parent', 'stdClass' ), true ) ) {
			continue;
		}
		// Clases (instanciación new Foo(…)) → nombres empiezan en mayúscula; las
		// funciones de WP y de la Suite son minúsculas.
		if ( preg_match( '/^[A-Z]/', $fn ) ) {
			continue;
		}
		// Llamada protegida por function_exists(…) → ausencia no fatal.
		if ( isset( $guarded[ $fn ] ) ) {
			continue;
		}
		$total_calls++;
		if ( isset( $php_builtins[ $fn ] ) || isset( $wp_cli_utils[ $fn ] ) || isset( $core[ $fn ] ) || isset( $suite[ $fn ] ) || isset( $defined[ $fn ] ) ) {
			continue;
		}
		$unknown[ $file ][ $fn ] = ( $unknown[ $file ][ $fn ] ?? 0 ) + 1;
	}
}

if ( $unknown ) {
	echo "WP_API_FAIL llamadas=" . $total_calls . " sin_reconocer=" . array_sum( array_map( 'array_sum', $unknown ) ) . "\n";
	foreach ( $unknown as $file => $fns ) {
		foreach ( $fns as $fn => $n ) {
			echo "  " . str_replace( $plugins_root . '/', '', $file ) . " → {$fn}() x{$n}\n";
		}
	}
	exit( 1 );
}

echo "WP_API_OK llamadas=" . $total_calls . " sin_reconocer=0\n";
