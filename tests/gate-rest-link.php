<?php
/**
 * gate-rest-link.php — Gate de enlace de rutas HTTP (PHP ↔ JS).
 *
 * Caza la clase de bug "rest_no_route / 404 silencioso": un consumidor (JS o PHP
 * que alimenta al JS) referencia un endpoint REST o una acción admin-ajax que el
 * servidor NO registra. El caso real que motivó este gate: el chatbot emitía la
 * ruta legacy `estandar-leads/v1/whatsapp-redirect`, retirada del plugin → 404.
 *
 * Cómo funciona:
 *   1. Registra las rutas REST de todos los plugins en alcance leyendo las
 *      llamadas `register_rest_route( <ns>, <ruta>, ... )` y resolviendo el
 *      namespace (literal, `self::NS`, `self::REST_NS`, `Clase::CONST`).
 *   2. Recolecta los consumidores: `rest_url('<ns>/<ruta>')` en PHP y literales
 *      `/wp-json/...` o `<ns>/...` en JS.
 *   3. Falla si un consumidor apunta a un namespace desconocido o a una ruta no
 *      registrada en ese namespace. Las referencias construidas dinámicamente
 *      (concatenación) se omiten (no se pueden resolver estáticamente).
 *   4. Además revisa acciones admin-ajax: `add_action('wp_ajax_<x>')` vs
 *      `action=<x>` en JS/PHP. Es ADVISORY (avisa, no aborta) para no producir
 *      falsos positivos que frenen un release.
 *
 * Contrato de release.command: exit 0 + última línea `REST_LINK_OK ...`; en
 * fallo exit 1 + `REST_LINK_FAIL ...`. Acepta `--scope=slug,slug` (gate-scope).
 */

require __DIR__ . '/gate-scope.php';

$GATE_ROOT = $GATE_ROOT; // definido por gate-scope.php
$only_warn = in_array( '--warn-only', $argv, true );

/**
 * Devuelve el fuente sin comentarios (preservando strings). Evita falsos
 * positivos cuando una ruta legacy solo aparece mencionada en un comentario.
 */
function grl_strip_comments( string $file, string $src ): string {
	if ( substr( $file, -4 ) === '.php' && function_exists( 'php_strip_whitespace' ) ) {
		$stripped = @php_strip_whitespace( $file );
		if ( is_string( $stripped ) && $stripped !== '' ) {
			return $stripped;
		}
	}
	$src = preg_replace( '#/\*.*?\*/#s', '', $src );
	// '//' solo como comentario si va precedido de inicio/blanco (no tras ':' de http).
	$src = preg_replace( '#(^|\s)//[^\n]*#', '$1', $src );
	return (string) $src;
}

/**
 * Recolecta archivos .php y .js de un plugin, saltando vendor/node_modules,
 * directorios de test y artefactos de release/backup (no son código publicado).
 */
function grl_files( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$p    = $f->getPathname();
		$rel  = str_replace( $root . '/', '', $p );
		$segs = explode( '/', $rel );
		$skip = false;
		foreach ( $segs as $s ) {
			if ( $s === '' ) {
				continue;
			}
			if ( $s[0] === '.' || in_array( $s, array( 'vendor', 'node_modules', 'tests', 'functional', 'parity', 'src-backup' ), true ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		$ext = strtolower( $f->getExtension() );
		if ( $ext === 'php' || $ext === 'js' ) {
			$out[] = $p;
		}
	}
	sort( $out );
	return $out;
}

/**
 * Mapa de constantes string declaradas en un archivo (const X = '...'; define(...)).
 */
function grl_const_map( string $src ): array {
	$map = array();
	if ( preg_match_all( '/\bconst\s+([A-Z_][A-Z0-9_]*)\s*=\s*([\'"])(.*?)\2\s*;/s', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$map[ $x[1] ] = $x[3];
		}
	}
	if ( preg_match_all( '/\bdefine\s*\(\s*([\'"])([A-Z_][A-Z0-9_]*)\1\s*,\s*([\'"])(.*?)\3\s*\)/s', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$map[ $x[2] ] = $x[4];
		}
	}
	return $map;
}

/**
 * Resuelve el primer argumento de register_rest_route() a un namespace string.
 * Acepta literal, self::CONST, static::CONST, Clase::CONST.
 */
function grl_resolve_ns( string $raw, array $consts ): ?string {
	$raw = trim( $raw );
	if ( $raw === '' ) {
		return null;
	}
	if ( preg_match( '/^([\'"])(.*)\1$/s', $raw, $m ) ) {
		return $m[2];
	}
	if ( preg_match( '/(?:self|static|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Z_][A-Z0-9_]*)/', $raw, $m ) ) {
		return $consts[ $m[1] ] ?? null;
	}
	return null; // concatenación / expresión dinámica
}

/**
 * Convierte una ruta registrada (con regex de WP) en un patrón de match.
 *   '/regions/(?P<region>[a-z0-9_-]+)'  →  '#^/regions/[^/]+$#'
 */
function grl_route_regex( string $ns, string $route ): string {
	$full = rtrim( $ns, '/' ) . '/' . ltrim( $route, '/' );
	$full = preg_replace( '/\(\?P?<[^>]+>[^)]*\)/', '[^/]+', $full ); // grupos nombrados
	$full = preg_replace( '/\([^)]*\)/', '[^/]+', $full );            // grupos anónimos
	return '#^' . preg_quote( $full, '#' ) . '$#';
}

// ── 1. Recolectar rutas registradas ────────────────────────────────────────
$registered   = array(); // full_path => file
$namespaces   = array(); // ns => true
$registered_n = 0;

$plugins = array();
$it = new RecursiveDirectoryIterator( $GATE_ROOT, FilesystemIterator::SKIP_DOTS );
foreach ( $it as $dir ) {
	if ( ! $dir->isDir() ) {
		continue;
	}
	$slug = $dir->getFilename();
	if ( strpos( $slug, 'estandar-' ) !== 0 ) {
		continue;
	}
	if ( ! gate_in_scope( $slug . '/' ) ) {
		continue;
	}
	$plugins[ $slug ] = $dir->getPathname();
}
ksort( $plugins );

foreach ( $plugins as $slug => $root ) {
	foreach ( grl_files( $root ) as $file ) {
		if ( substr( $file, -4 ) !== '.php' ) {
			continue;
		}
		$src    = grl_strip_comments( $file, (string) file_get_contents( $file ) );
		$consts = grl_const_map( $src );
		if ( ! preg_match_all(
			'/register_rest_route\s*\(\s*([^,]+?)\s*,\s*([\'"])(.*?)\2/s',
			$src, $m, PREG_SET_ORDER
		) ) {
			continue;
		}
		foreach ( $m as $x ) {
			$ns = grl_resolve_ns( $x[1], $consts );
			if ( $ns === null ) {
				continue; // namespace dinámico: no verificable estáticamente
			}
			$route = $x[3];
			$full  = rtrim( $ns, '/' ) . '/' . ltrim( $route, '/' );
			$registered[ $full ] = str_replace( $GATE_ROOT . '/', '', $file );
			$namespaces[ $ns ]   = true;
			$registered_n++;
		}
	}
}

if ( $registered_n === 0 ) {
	echo "REST_LINK_FAIL no se detectaron rutas registradas (¿scope/estructura?)\n";
	exit( 1 );
}

// ── 2. Recolectar consumidores REST ────────────────────────────────────────
$refs    = array(); // normalized_path => file:line origin
$unknown = array();

foreach ( $plugins as $slug => $root ) {
	foreach ( grl_files( $root ) as $file ) {
		$rel = str_replace( $GATE_ROOT . '/', '', $file );
		$src = grl_strip_comments( $file, (string) file_get_contents( $file ) );

		// PHP: rest_url('...')
		if ( preg_match_all( '/rest_url\s*\(\s*([\'"])(.*?)\1/s', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$path = trim( $x[2], '/' );
				if ( $path !== '' && strpos( $path, '$' ) === false ) {
					$refs[ $path ] = $rel;
				}
			}
		}
		// JS/PHP: literales con /wp-json/ o namespace estandar-suite|estandar-*
		$patterns = array(
			'#/wp-json/([A-Za-z0-9_\-/\.]+)#',
			'#(estandar-[a-z0-9\-]+/v\d+/[A-Za-z0-9_\-/\.]+)#',
		);
		foreach ( $patterns as $re ) {
			if ( preg_match_all( $re, $src, $m ) ) {
				foreach ( $m[1] as $p ) {
					$p = trim( $p, '/' );
					if ( $p !== '' ) {
						$refs[ $p ] = $rel;
					}
				}
			}
		}
	}
}
ksort( $refs );

// ── 3. Validar cada referencia contra las rutas registradas ────────────────
// Baseline de deuda preexistente: rutas conocidas y aceptadas (p. ej. un
// consumidor legacy aún migrando). Formato: una ruta por línea; '#' comenta.
$baseline = array();
$baseline_file = __DIR__ . '/rest-link-baseline.txt';
if ( is_file( $baseline_file ) ) {
	foreach ( file( $baseline_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $l ) {
		$l = trim( $l );
		if ( $l !== '' && $l[0] !== '#' ) {
			$baseline[ $l ] = true;
		}
	}
}

$violations = array(); // path => descripción
foreach ( $refs as $path => $origin ) {
	// Una referencia que ES exactamente un namespace es una URL base para
	// concatenación dinámica → no verificable estáticamente.
	if ( isset( $namespaces[ $path ] ) ) {
		continue;
	}
	$matched_ns = null;
	foreach ( $namespaces as $ns => $true ) {
		if ( $path === $ns || strpos( $path, $ns . '/' ) === 0 ) {
			$matched_ns = $ns;
			break;
		}
	}
	if ( $matched_ns === null ) {
		// Solo es falta si el path parpadea como ruta de la Suite (namespace estandar-*).
		if ( preg_match( '#^estandar-[a-z0-9\-]+/v\d+/#', $path ) ) {
			$violations[ $path ] = "{$path}  ← {$origin} (namespace no registrado por ningún plugin)";
		}
		continue;
	}
	$ok = false;
	foreach ( $registered as $full => $rfile ) {
		if ( strpos( $full, $matched_ns . '/' ) !== 0 && $full !== $matched_ns ) {
			continue;
		}
		if ( preg_match( grl_route_regex( $matched_ns, substr( $full, strlen( $matched_ns ) ) ), $path ) ) {
			$ok = true;
			break;
		}
	}
	if ( ! $ok ) {
		$violations[ $path ] = "{$path}  ← {$origin} (ruta no registrada en {$matched_ns})";
	}
}

$bad   = array();
$known = array();
foreach ( $violations as $path => $line ) {
	if ( isset( $baseline[ $path ] ) ) {
		$known[] = $line;
	} else {
		$bad[] = $line;
	}
}

// ── 4. admin-ajax (advisory) ───────────────────────────────────────────────
$ajax_handlers = array();
$ajax_refs     = array();
$ajax_re = '#\b(?:wp_ajax|admin_post)(?:_nopriv)?_([A-Za-z0-9_]+)#';
foreach ( $plugins as $slug => $root ) {
	foreach ( grl_files( $root ) as $file ) {
		$rel = str_replace( $GATE_ROOT . '/', '', $file );
		$src = grl_strip_comments( $file, (string) file_get_contents( $file ) );
		if ( preg_match_all( $ajax_re, $src, $m ) ) {
			foreach ( $m[1] as $h ) {
				$ajax_handlers[ $h ] = true;
			}
		}
		// action=<x> en query o action: "<x>"
		if ( preg_match_all( '#\baction=[\'"]?([a-zA-Z0-9_]+)#', $src, $m ) ) {
			foreach ( $m[1] as $a ) {
				if ( preg_match( '#^(ef_|estandar_|es_)#', $a ) ) {
					$ajax_refs[ $a ] = $rel;
				}
			}
		}
	}
}
$ajax_missing = array();
foreach ( $ajax_refs as $a => $origin ) {
	if ( ! isset( $ajax_handlers[ $a ] ) ) {
		$ajax_missing[] = "{$a} ← {$origin}";
	}
}

// ── 5. Salida ──────────────────────────────────────────────────────────────
if ( count( $bad ) > 0 ) {
	echo 'REST_LINK_FAIL rutas=' . $registered_n . ' refs=' . count( $refs ) . ' rotas=' . count( $bad ) . "\n";
	foreach ( $bad as $line ) {
		echo "  ✗ {$line}\n";
	}
	foreach ( $known as $line ) {
		echo "  ▲ (baseline) {$line}\n";
	}
	exit( $only_warn ? 0 : 1 );
}

$extra = count( $known ) > 0 ? ' baseline=' . count( $known ) : '';
$extra .= count( $ajax_missing ) > 0 ? ' ajax_sin_handler=' . count( $ajax_missing ) . ' (advisory)' : '';
echo 'REST_LINK_OK rutas=' . $registered_n . ' refs=' . count( $refs ) . ' rotas=0' . $extra . "\n";
foreach ( $known as $line ) {
	echo "  ▲ (baseline) {$line}\n";
}
if ( count( $ajax_missing ) > 0 ) {
	foreach ( $ajax_missing as $line ) {
		echo "  ▲ admin-ajax sin handler: {$line}\n";
	}
}
exit( 0 );
