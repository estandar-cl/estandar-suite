<?php
/**
 * gate-scope.php — Alcance opcional de plugins para los gates de release.
 *
 * Permite que un gate escanee SOLO un subconjunto de plugins cuando la release
 * no publica los 26. Uso desde release.command:
 *
 *   php tests/gate-symbol-collisions.php --scope=estandar-core,estandar-capture
 *
 * Sin `--scope` el alcance es "todos los plugins" (comportamiento previo).
 *
 * Este archivo define:
 *   - $GATE_ROOT   : ruta del directorio `plugins` de la Suite.
 *   - $GATE_SCOPE  : map slug => true con los plugins en alcance (vacío = todos).
 *   - gate_in_scope( $rel ): true si `$rel` (ruta relativa a plugins/) cae
 *                            dentro del alcance. P.ej. "estandar-core/includes/X.php".
 *
 * Los gates hacen: require __DIR__ . '/gate-scope.php'; y filtran sus iteraciones
 * con gate_in_scope(). NO escanea claves/secretos: solo decide qué archivos mirar.
 */

$GATE_SCOPE = array();
if ( isset( $argv[1] ) && strpos( $argv[1], '--scope=' ) === 0 ) {
	foreach ( explode( ',', substr( $argv[1], 8 ) ) as $s ) {
		$s = trim( $s );
		if ( $s !== '' ) {
			$GATE_SCOPE[ $s ] = true;
		}
	}
}

$GATE_ROOT = dirname( __DIR__ ) . '/plugins';

if ( ! function_exists( 'gate_in_scope' ) ) {
	function gate_in_scope( string $rel ): bool {
		global $GATE_SCOPE;
		if ( empty( $GATE_SCOPE ) ) {
			return true;
		}
		$slug = strtok( $rel, '/' );
		return $slug !== false && isset( $GATE_SCOPE[ $slug ] );
	}
}
