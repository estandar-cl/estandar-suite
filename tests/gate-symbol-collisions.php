<?php
/**
 * gate-symbol-collisions.php — Gate de colisiones de símbolos globales.
 *
 * Escanea los 26 plugins y detecta clases/interfaces/traits globales, funciones
 * globales y constantes (define) que se declaran en MÁS DE UN plugin, o que
 * colisionan con símbolos de WordPress core. Usa token_get_all con una pila de
 * scopes (namespace/clase/función) para distinguir métodos de funciones globales.
 *
 * Previene fatals "Cannot redeclare class/function" que solo aparecen al activar
 * todos los plugins juntos en producción.
 *
 * Salida de éxito: "COLLISIONS_OK globales=N colisiones=0"
 * Salida de fallo:  "COLLISIONS_FAIL ..." + listado + exit(1)
 *
 * Uso: php tests/gate-symbol-collisions.php [--scope=plugin1,plugin2]
 */

require __DIR__ . '/gate-scope.php';
$plugins_root = $GATE_ROOT;

$WP_CORE = array(
	'WP', 'WP_Query', 'WP_Error', 'WP_Http', 'wpdb', 'WP_Roles', 'WP_User',
	'WP_User_Query', 'WP_Scripts', 'WP_Styles', 'WP_Widget', 'Walker',
	'WP_REST_Response', 'WP_REST_Request', 'WP_REST_Server', 'wp_tinymce',
	'wp_xmlrpc_server_class', 'WP_MatchesMapRegex', 'WP_Theme', 'WP_Customize_Manager',
	'WP_Theme_JSON', 'WP_Block_Type', 'WP_Block_Type_Registry', 'WP_Site',
	'WP_Site_Query', 'WP_Term', 'WP_Term_Query', 'WP_Comment', 'WP_Comment_Query',
	'WP_Meta_Query', 'WP_Date_Query', 'WP_Post_Type', 'WP_Taxonomy',
);

// Devuelve symbol-name => tipo ('class'|'interface'|'trait'|'function'|'const')
function gate_scan_file( string $path ): array {
	$src = @file_get_contents( $path );
	if ( $src === false ) {
		return array();
	}
	$guarded = array();
	if ( preg_match_all( '/\b(?:function_exists|class_exists|interface_exists|trait_exists)\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)/', $src, $m ) ) {
		foreach ( $m[1] as $gn ) {
			$guarded[ $gn ] = true;
		}
	}
	$tokens = @token_get_all( $src );
	if ( ! is_array( $tokens ) ) {
		return array();
	}

	$out          = array();
	$namespace    = '';     // '' = global
	$scope        = array(); // pila: 'top' | 'class' | 'function'
	$brace        = 0;      // profundidad actual de llaves
	$scope_brace  = array(); // brace_depth en que cada scope se abrió
	$interp       = array(); // pila de interpolación de strings (T_CURLY_OPEN / ${)
	$in_ns_decl   = false;
	$last_was_use = false;

	$n = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		$tok = $tokens[ $i ];

		// namespace X;  → cambia el contenedor. namespace X { ... } → cambia y abre scope.
		if ( is_array( $tok ) && $tok[0] === T_NAMESPACE ) {
			$ns = '';
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$t2 = $tokens[ $j ];
				if ( is_array( $t2 ) && in_array( $t2[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $t2 ) && in_array( $t2[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$ns .= ( $ns === '' ? '' : '\\' ) . $t2[1];
					continue;
				}
				if ( is_array( $t2 ) && $t2[0] === T_NS_SEPARATOR ) {
					$ns .= '\\';
					continue;
				}
				if ( $t2 === '{' ) {
					$namespace = $ns;
					array_push( $scope, 'ns' );
					array_push( $scope_brace, $brace + 1 );
					break;
				}
				if ( $t2 === ';' ) {
					$namespace = $ns;
					break;
				}
				break;
			}
			continue;
		}

		// Interpolación de string: "{$var}" emite T_CURLY_OPEN + ... + '}' literal
		// que NO es cierre de scope. También "${var}".
		if ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			array_push( $interp, $brace );
			continue;
		}

		// use ...;  — no afecta definiciones (solo aliasing)
		if ( is_array( $tok ) && $tok[0] === T_USE ) {
			$last_was_use = true;
			continue;
		}

		if ( $tok === '{' ) {
			$brace++;
			continue;
		}
		if ( $tok === '}' ) {
			// ¿cierre de interpolación? (no es brace de scope)
			if ( count( $interp ) > 0 ) {
				array_pop( $interp );
				continue;
			}
			if ( count( $scope ) > 0 && end( $scope_brace ) === $brace ) {
				$t = array_pop( $scope );
				array_pop( $scope_brace );
				if ( $t === 'ns' ) {
					$namespace = '';
				}
			}
			$brace--;
			continue;
		}

		if ( ! is_array( $tok ) ) {
			continue;
		}

		// ¿Estamos dentro de una clase o función? scope superior
		$top = end( $scope );
		$top_brace = end( $scope_brace );

		if ( in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			// class Foo {  (nombre directo) — ignorar anónimas y ::class
			if ( $top === 'class' || $top === 'function' ) {
				continue; // clase anidada / clase anónima dentro de función
			}
			// detectar nombre
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$t2 = $tokens[ $j ];
				if ( is_array( $t2 ) && in_array( $t2[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $t2 ) && $t2[0] === T_STRING ) {
					$cname = $t2[1];
					if ( $namespace === '' && ! isset( $guarded[ $cname ] ) ) {
						$kind = ( $tok[0] === T_CLASS ) ? 'class' : ( ( $tok[0] === T_INTERFACE ) ? 'interface' : 'trait' );
						$out[ $cname ] = $kind;
					}
					break;
				}
				break; // clase anónima
			}
			// abrir scope de clase en el PRÓXIMO '{' al nivel actual
			array_push( $scope, 'class' );
			array_push( $scope_brace, $brace + 1 );
			continue;
		}

		if ( $tok[0] === T_FUNCTION ) {
			// ¿método (dentro de clase) o función global?
			$is_method = ( $top === 'class' );
			// ¿tiene cuerpo (llaves) o es abstract/interfaz (termina en ;)?
			$has_body = false;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$t2 = $tokens[ $j ];
				if ( $t2 === '{' ) {
					$has_body = true;
					break;
				}
				if ( $t2 === ';' ) {
					break;
				}
			}
			// closures/funciones anónimas no tienen nombre
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$t2 = $tokens[ $j ];
				if ( is_array( $t2 ) && in_array( $t2[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $t2 ) && $t2[0] === T_STRING ) {
					if ( ! $is_method && $namespace === '' && ! isset( $guarded[ $t2[1] ] ) ) {
						$out[ $t2[1] ] = 'function';
					}
					break;
				}
				break; // función anónima
			}
			// abrir scope de función solo si tiene cuerpo ('{'); los abstractos de
			// interfaces terminan en ';' y no crean scope (evita scope colgado).
			if ( $has_body ) {
				array_push( $scope, 'function' );
				array_push( $scope_brace, $brace + 1 );
			}
			continue;
		}

		if ( $tok[0] === T_STRING && $tok[1] === 'define' ) {
			if ( $top === 'class' || $top === 'function' || $namespace !== '' || isset( $guarded['define'] ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$t2 = $tokens[ $j ];
				if ( is_array( $t2 ) && $t2[0] === T_CONSTANT_ENCAPSED_STRING ) {
					$out[ trim( $t2[1], "\"'" ) ] = 'const';
					break;
				}
				if ( is_array( $t2 ) && in_array( $t2[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( $t2 === '(' ) {
					continue;
				}
				break;
			}
			continue;
		}
	}

	return $out;
}

$index = array(); // "type:name" => array(file)
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugins_root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $it as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$path = $file->getPathname();
	$rel  = str_replace( '\\', '/', $path );
	$rel_root = substr( $rel, strlen( rtrim( $plugins_root, '/' ) ) + 1 );
	if ( ! gate_in_scope( $rel_root ) ) {
		continue;
	}
	if ( preg_match( '#(^|/)(\.[^/]*/|tests?/|vendor/|node_modules/|fixtures?/|acceptance/)#i', '/' . $rel ) ) {
		continue;
	}
	foreach ( gate_scan_file( $path ) as $name => $kind ) {
		$key = $kind . ':' . $name;
		$index[ $key ][] = $rel;
	}
}

$bad   = array();
$total = 0;
foreach ( $index as $key => $files ) {
	list( $kind, $name ) = explode( ':', $key, 2 );
	$uniq = array_values( array_unique( $files ) );
	$total += count( $uniq );
	if ( count( $uniq ) > 1 ) {
		$bad[] = sprintf( '%s %s definido en %d archivos: %s',
			$kind, $name, count( $uniq ), implode( ', ', array_slice( $uniq, 0, 6 ) ) );
		continue;
	}
	if ( in_array( $name, $WP_CORE, true ) ) {
		$bad[] = sprintf( '%s %s colisiona con símbolo de WordPress core (%s)',
			$kind, $name, $uniq[0] );
	}
}

if ( count( $bad ) > 0 ) {
	echo "COLLISIONS_FAIL declaraciones_globales={$total} colisiones=" . count( $bad ) . "\n";
	foreach ( array_slice( $bad, 0, 40 ) as $line ) {
		echo "  ✗ {$line}\n";
	}
	exit( 1 );
}

echo "COLLISIONS_OK declaraciones_globales={$total} colisiones=0\n";
exit( 0 );
