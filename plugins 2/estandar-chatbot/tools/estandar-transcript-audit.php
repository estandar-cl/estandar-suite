<?php
/**
 * TRANSCRIPT_AUDIT — PHASE_1B de CHATBOT_LEADS_REFACTOR.
 * READ-ONLY: solo SELECT. Cero escrituras, cero migraciones, cero deletes.
 *
 * Uso (local, requiere WP vivo):
 *   php wp-content/plugins/estandar-chatbot/tools/estandar-transcript-audit.php
 * o vía WP-CLI:
 *   wp eval-file wp-content/plugins/estandar-chatbot/tools/estandar-transcript-audit.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __FILE__, 5 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		fwrite( STDERR, "No se encontró wp-load.php en " . $wp_load . "\n" );
		exit( 1 );
	}
	require_once $wp_load;
}

global $wpdb;

$leads_table = $wpdb->prefix . 'ecb_leads';
$conv_table  = $wpdb->prefix . 'ecb_conversations';

function eta_pct( $n, $d ) {
	return $d > 0 ? round( $n / $d * 100, 1 ) . '%' : 'n/a';
}

echo "═══ TRANSCRIPT_AUDIT (read-only) ═══\n\n";

// ── 1. ecb_leads: cobertura de conversation_recent ──────────────────────────
$rows = $wpdb->get_results( "SELECT id, created_at, data FROM {$leads_table}", ARRAY_A );
$total = is_array( $rows ) ? count( $rows ) : 0;

$with_recent = 0; $with_summary = 0;
$lens = []; $lines_counts = []; $by_channel = [];
$oldest = null; $newest = null;

foreach ( (array) $rows as $r ) {
	$d = json_decode( (string) ( $r['data'] ?? '{}' ), true );
	if ( ! is_array( $d ) ) { $d = []; }
	$recent  = trim( (string) ( $d['conversation_recent'] ?? '' ) );
	$summary = trim( (string) ( $d['conversation_summary'] ?? '' ) );
	$channel = (string) ( $d['source_channel'] ?? 'desconocido' );
	if ( ! isset( $by_channel[ $channel ] ) ) { $by_channel[ $channel ] = [ 'total' => 0, 'with' => 0 ]; }
	$by_channel[ $channel ]['total']++;
	if ( $summary !== '' ) { $with_summary++; }
	if ( $recent !== '' ) {
		$with_recent++;
		$by_channel[ $channel ]['with']++;
		$lens[] = function_exists( 'mb_strlen' ) ? mb_strlen( $recent ) : strlen( $recent );
		$lines_counts[] = substr_count( $recent, "\n" ) + 1;
	}
	$created = (string) ( $r['created_at'] ?? '' );
	if ( $created !== '' ) {
		if ( $oldest === null || $created < $oldest ) { $oldest = $created; }
		if ( $newest === null || $created > $newest ) { $newest = $created; }
	}
}

sort( $lens );
$avg_len    = $lens ? (int) round( array_sum( $lens ) / count( $lens ) ) : 0;
$median_len = $lens ? $lens[ (int) floor( count( $lens ) / 2 ) ] : 0;
$max_len    = $lens ? max( $lens ) : 0;
$avg_lines  = $lines_counts ? round( array_sum( $lines_counts ) / count( $lines_counts ), 1 ) : 0;

echo "── ecb_leads ──\n";
echo "Leads totales:                 {$total}\n";
echo "Rango histórico:               " . ( $oldest ?: '-' ) . " → " . ( $newest ?: '-' ) . "\n";
echo "Con conversation_recent:       {$with_recent} (" . eta_pct( $with_recent, $total ) . ")\n";
echo "Con conversation_summary:      {$with_summary} (" . eta_pct( $with_summary, $total ) . ")\n";
echo "Largo transcript (chars):      avg {$avg_len} · mediana {$median_len} · max {$max_len}\n";
echo "Mensajes por transcript (avg): {$avg_lines} (tope estructural: 10)\n";
echo "Cobertura por canal:\n";
foreach ( $by_channel as $ch => $c ) {
	echo "  - {$ch}: {$c['with']}/{$c['total']} (" . eta_pct( $c['with'], $c['total'] ) . ")\n";
}

// ── 2. ecb_conversations: el corpus completo ────────────────────────────────
$conv_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) );
echo "\n── ecb_conversations ──\n";
if ( ! $conv_exists ) {
	echo "Tabla no existe en esta instalación.\n";
} else {
	$conv_stats = $wpdb->get_row(
		"SELECT COUNT(*) AS sessions,
		        SUM(message_count) AS total_msgs,
		        AVG(message_count) AS avg_msgs,
		        MAX(message_count) AS max_msgs,
		        SUM(LENGTH(messages)) AS total_bytes,
		        AVG(LENGTH(messages)) AS avg_bytes,
		        MIN(first_message_at) AS oldest,
		        MAX(last_message_at) AS newest
		 FROM {$conv_table}",
		ARRAY_A
	);
	$capped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table} WHERE message_count >= 80" );
	$by_ch  = $wpdb->get_results( "SELECT channel, COUNT(*) AS n, AVG(message_count) AS avg_msgs FROM {$conv_table} GROUP BY channel", ARRAY_A );

	echo "Sesiones:                      " . (int) $conv_stats['sessions'] . "\n";
	echo "Rango histórico:               {$conv_stats['oldest']} → {$conv_stats['newest']}\n";
	echo "Mensajes totales:              " . (int) $conv_stats['total_msgs'] . "\n";
	echo "Mensajes por sesión:           avg " . round( (float) $conv_stats['avg_msgs'], 1 ) . " · max " . (int) $conv_stats['max_msgs'] . "\n";
	echo "Sesiones en tope de 80 msgs:   {$capped} (truncadas por rolling cap)\n";
	echo "Tamaño corpus:                 " . size_format( (int) $conv_stats['total_bytes'] ) . " (avg " . size_format( (int) $conv_stats['avg_bytes'] ) . "/sesión)\n";
	echo "Por canal:\n";
	foreach ( (array) $by_ch as $c ) {
		echo "  - {$c['channel']}: {$c['n']} sesiones · avg " . round( (float) $c['avg_msgs'], 1 ) . " msgs\n";
	}
	echo "\nProporción sesiones vs leads:  " . (int) $conv_stats['sessions'] . " sesiones / {$total} leads";
	if ( $total > 0 && (int) $conv_stats['sessions'] > 0 ) {
		echo " (≈" . round( (int) $conv_stats['sessions'] / max( 1, $total ), 1 ) . " sesiones por lead → el resto es señal de abandono)";
	}
	echo "\n";
}

echo "\n═══ FIN — cero escrituras ejecutadas ═══\n";
