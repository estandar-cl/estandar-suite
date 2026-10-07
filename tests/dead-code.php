<?php
/**
 * dead-code.php — Detección de código muerto (candidatos), ADVISORY.
 *
 * NO es un gate: reporta candidatos y sale 0 siempre. WordPress "llama" código
 * por hooks (add_action/add_filter/shortcodes) y por reflexión, así que un
 * símbolo sin referencia textual NO es prueba de muerte. Este script sirve de
 * pista, no de veredicto.
 *
 * Sólo reporta como candidato:
 *   - funciones globales y métodos private/protected
 * con CERO referencias textuales dentro del propio plugin y que NO estén
 * registrados como hook. Nunca reporta métodos públicos.
 *
 * Uso: php tests/dead-code.php [--scope=a,b] [--limit=N] [--json]
 */

require __DIR__ . '/gate-scope.php';

$GATE_ROOT = $GATE_ROOT;

$scope_arg = '';
$limit     = 50;
$as_json   = in_array( '--json', $argv, true );
foreach ( $argv as $a ) {
	if ( strpos( $a, '--scope=' ) === 0 ) {
		$scope_arg = substr( $a, 8 );
	}
	if ( strpos( $a, '--limit=' ) === 0 ) {
		$limit = max( 0, (int) substr( $a, 8 ) );
	}
}

function dc_files( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$rel = str_replace( $root . '/', '', $f->getPathname() );
		if ( strpos( $rel, 'tests/' ) === 0 || strpos( $rel, 'vendor/' ) === 0
			|| strpos( $rel, 'node_modules/' ) === 0 ) {
			continue;
		}
		if ( strtolower( $f->getExtension() ) === 'php' ) {
			$out[] = $f->getPathname();
		}
	}
	sort( $out );
	return $out;
}

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
	if ( $scope_arg !== '' ) {
		$in = false;
		foreach ( explode( ',', $scope_arg ) as $s ) {
			if ( trim( $s ) === $slug ) {
				$in = true;
				break;
			}
		}
		if ( ! $in ) {
			continue;
		}
	}
	$plugins[ $slug ] = $dir->getPathname();
}
ksort( $plugins );

$magic = array( '__construct', '__destruct', '__get', '__set', '__call', '__callStatic',
	'__isset', '__unset', '__toString', '__invoke', '__clone', '__debugInfo',
	'__serialize', '__unserialize', '__sleep', '__wakeup', '__set_state' );

$result = array();
$total  = 0;

foreach ( $plugins as $slug => $dir ) {
	$files = dc_files( $dir );
	$src_all = '';
	$decls   = array(); // name => "file:line (vis)"
	$hooked  = array(); // nombres registrados como hook / callback string

	foreach ( $files as $file ) {
		$src = (string) file_get_contents( $file );
		$src_all .= "\n" . $src;

		if ( preg_match_all(
			'/\b(?:add_action|add_filter|add_shortcode|add_menu_page|add_submenu_page|register_activation_hook|register_deactivation_hook|wp_schedule_event|add_rewrite_rule)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/',
			$src, $m
		) ) {
			foreach ( $m[1] as $h ) {
				$hooked[ $h ] = true;
			}
		}
		// Callbacks string tipo array( 'Clase', 'metodo' ) o 'Clase::metodo'
		if ( preg_match_all( '/[\'"]((?:[A-Za-z_][A-Za-z0-9_]*::)?[a-z_][A-Za-z0-9_]*)[\'"]\s*[\),]/', $src, $m ) ) {
			foreach ( $m[1] as $h ) {
				$hooked[ $h ] = true;
				$parts = explode( '::', $h );
				$hooked[ end( $parts ) ] = true;
			}
		}
	}

	foreach ( $files as $file ) {
		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$n      = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || $t[0] !== T_FUNCTION ) {
				continue;
			}
			// nombre de la función
			$name = null;
			for ( $j = $i + 1; $j < $n && $j < $i + 6; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_STRING ) {
					$name = $tokens[ $j ][1];
					break;
				}
			}
			if ( $name === null || in_array( $name, $magic, true ) ) {
				continue;
			}
			// visibilidad hacia atrás
			$vis = 'global';
			for ( $k = $i - 1; $k >= 0 && $k > $i - 8; $k-- ) {
				if ( is_array( $tokens[ $k ] ) ) {
					if ( $tokens[ $k ][0] === T_PRIVATE || $tokens[ $k ][0] === T_PROTECTED ) {
						$vis = 'privado';
						break;
					}
					if ( $tokens[ $k ][0] === T_PUBLIC ) {
						$vis = 'publico';
						break;
					}
					if ( $tokens[ $k ][0] === T_STATIC || $tokens[ $k ][0] === T_FINAL || $tokens[ $k ][0] === T_ABSTRACT ) {
						continue;
					}
				}
				if ( $tokens[ $k ] === '}' || $tokens[ $k ] === ';' || $tokens[ $k ] === '{' ) {
					break;
				}
			}
			if ( $vis === 'publico' ) {
				continue; // puede ser llamada por WP/otro plugin/JS
			}
			$rel = str_replace( $dir . '/', '', $file );
			$key = $slug . '::' . $name;
			$decls[ $key ] = $rel;
		}
	}

	$cands = array();
	foreach ( $decls as $key => $rel ) {
		$name = substr( $key, strlen( $slug ) + 2 );
		if ( isset( $hooked[ $name ] ) ) {
			continue;
		}
		// contar referencias textuales (excluye la propia declaración)
		$count = preg_match_all( '/\b' . preg_quote( $name, '/' ) . '\b/', $src_all );
		if ( $count <= 1 ) {
			$cands[] = $name . '  (' . $rel . ')';
		}
	}
	sort( $cands );
	if ( $cands ) {
		$result[ $slug ] = array_slice( $cands, 0, $limit );
		$total += count( $cands );
	}
}

if ( $as_json ) {
	echo json_encode( array( 'total' => $total, 'plugins' => $result ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

echo "── Dead code (candidatos, ADVISORY — no prueba de muerte) ──\n";
if ( $total === 0 ) {
	echo "  sin candidatos\n";
} else {
	foreach ( $result as $slug => $cands ) {
		echo "  ● {$slug}: " . count( $cands ) . " candidatos\n";
		foreach ( $cands as $c ) {
			echo "      ? {$c}\n";
		}
	}
	echo "\n  Total: {$total}. Revisar manualmente: un símbolo puede ser llamado\n";
	echo "  por hook, reflexión o método dinámico no detectables estáticamente.\n";
}
exit( 0 );
