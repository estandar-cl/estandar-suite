<?php
/**
 * EVENT_BUS v2 (S15) — tests del bus de eventos canónicos (Destinations):
 * catálogo ampliado (survey_completed/quote_issued), mapa evento→destino y
 * comportamiento inerte sin mapeo (cero enqueue/red).
 *
 *   php tests/run-event-bus.php   → expect "0 failed"
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

define( 'ABSPATH', __DIR__ );
$GLOBALS['__test_options'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; }
}

require dirname( __DIR__ ) . '/includes/Destinations.php';

use Estandar\Core\Destinations as D;

$passed = 0; $failed = 0;
function check( $label, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

// ── 1. Catálogo canónico ampliado (S15) ───────────────────────────────────
$canon = D::canonical_events();
check( '1a incluye capture', in_array( 'capture', $canon, true ) );
check( '1b incluye signal', in_array( 'signal', $canon, true ) );
check( '1c incluye survey_completed (nuevo)', in_array( 'survey_completed', $canon, true ) );
check( '1d incluye quote_issued (nuevo)', in_array( 'quote_issued', $canon, true ) );
check( '1e incluye señales comerciales (lead_qualified/sale_closed)', in_array( 'lead_qualified', $canon, true ) && in_array( 'sale_closed', $canon, true ) );
check( '1f catálogo sin duplicados', count( $canon ) === count( array_unique( $canon ) ) );

// ── 2. Mapa evento→destino: alta, validación y limpieza ───────────────────
$GLOBALS['__test_options'] = array(); // parte limpio
check( '2a event_targets vacío por defecto', D::event_targets( 'quote_issued' ) === array() );

D::map_events( 'hoja_quotes', array( 'quote_issued', 'capture' ) );
check( '2b quote_issued → hoja_quotes', in_array( 'hoja_quotes', D::event_targets( 'quote_issued' ), true ) );
check( '2c capture → hoja_quotes', in_array( 'hoja_quotes', D::event_targets( 'capture' ), true ) );
check( '2d evento no mapeado vacío', D::event_targets( 'survey_completed' ) === array() );

// El allow-list ignora nombres no canónicos (p.ej. legacy dot-notation).
D::map_events( 'x', array( 'submission.created', 'quote.issued', 'nope' ) );
check( '2e no-canónico se ignora', D::event_targets( 'submission.created' ) === array() && D::event_targets( 'quote.issued' ) === array() );
check( '2f captura previa no se borró', in_array( 'hoja_quotes', D::event_targets( 'capture' ), true ) );

// Re-mapear solo otro evento retira el destino del evento anterior.
D::map_events( 'hoja_quotes', array( 'survey_completed' ) );
check( '2g re-mapeo retira del evento anterior', D::event_targets( 'quote_issued' ) === array() );
check( '2h survey_completed queda mapeado', in_array( 'hoja_quotes', D::event_targets( 'survey_completed' ), true ) );

// ── 3. Inercia sin mapeo / sin DB (cero enqueue y cero red) ───────────────
$GLOBALS['__test_options'] = array();
check( '3a dispatch sin mapa → 0 targets, 0 queued', D::dispatch_event( 'survey_completed', 'survey', 1, array() ) === array( 'targets' => 0, 'queued' => 0 ) );
D::map_events( 'webhook_n8n', array( 'survey_completed' ) );
// Con destino mapeado pero sin DB ($wpdb ausente), enqueue no persiste → queued 0.
check( '3b dispatch mapeado sin DB → queued 0 (inerte)', D::dispatch_event( 'survey_completed', 'survey', 1, array( 'session_id' => 1 ) ) === array( 'targets' => 1, 'queued' => 0 ) );
D::map_events( 'webhook_n8n', array( 'quote_issued' ) );
check( '3c quote_issued idem inerte sin DB', D::dispatch_event( 'quote_issued', 'quote', 9, array( 'id' => 9 ) ) === array( 'targets' => 1, 'queued' => 0 ) );

// ── 4. Idempotencia (PURE) ────────────────────────────────────────────────
check( '4a idempotency_key determinista', D::idempotency_key( 'quote_issued', 'quote', 9, 0 ) === D::idempotency_key( 'quote_issued', 'quote', 9, 0 ) );
check( '4b key distinta por evento', D::idempotency_key( 'quote_issued', 'quote', 9, 0 ) !== D::idempotency_key( 'capture', 'quote', 9, 0 ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
