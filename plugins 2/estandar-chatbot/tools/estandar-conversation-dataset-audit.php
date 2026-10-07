<?php
/**
 * CONVERSATION_DATASET_AUDIT_V1
 * Pregunta: ¿puede wp_ecb_conversations ser la fuente canónica de inteligencia conversacional?
 *
 * READ-ONLY estricto: solo SELECT. Cero escrituras, cero deletes, cero migraciones.
 * Correr por sitio (SALADEPRUEBAS → Acacios → AK); la cobertura es por instalación.
 *
 * Uso:
 *   php wp-content/plugins/estandar-chatbot/tools/estandar-conversation-dataset-audit.php
 *   wp eval-file wp-content/plugins/estandar-chatbot/tools/estandar-conversation-dataset-audit.php
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
$conv_table  = $wpdb->prefix . 'ecb_conversations';
$leads_table = $wpdb->prefix . 'ecb_leads';

function ecda_pct( $n, $d ) { return $d > 0 ? round( $n / $d * 100, 1 ) . '%' : 'n/a'; }
function ecda_norm_text( $t ) {
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $t ) : strtolower( (string) $t );
	$t = remove_accents( $t );
	$t = preg_replace( '/[^a-z0-9ñ\s]/', ' ', $t );
	return trim( preg_replace( '/\s+/', ' ', $t ) );
}
function ecda_top( array $counts, $n = 10 ) {
	arsort( $counts );
	return array_slice( $counts, 0, $n, true );
}

echo "═══ CONVERSATION_DATASET_AUDIT_V1 (read-only) · sitio: " . home_url() . " ═══\n\n";

if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) ) ) {
	echo "wp_ecb_conversations no existe en esta instalación. FIN.\n";
	exit( 0 );
}

// ── 1-5: sesiones y cobertura ────────────────────────────────────────────────
$sessions_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" );
$sessions_lead  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table} WHERE (lead_email IS NOT NULL AND lead_email <> '') OR (lead_phone IS NOT NULL AND lead_phone <> '')" );
$sessions_nolead = $sessions_total - $sessions_lead;
$leads_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" );
$range = $wpdb->get_row( "SELECT MIN(first_message_at) AS oldest, MAX(last_message_at) AS newest, AVG(message_count) AS avg_msgs, SUM(message_count) AS total_msgs, SUM(LENGTH(messages)) AS bytes FROM {$conv_table}", ARRAY_A );

echo "Sesiones totales:        {$sessions_total}\n";
echo "Sesiones con lead:       {$sessions_lead} (" . ecda_pct( $sessions_lead, $sessions_total ) . ")\n";
echo "Sesiones sin lead:       {$sessions_nolead} (" . ecda_pct( $sessions_nolead, $sessions_total ) . ") ← señal de abandono/no-conversión\n";
echo "Leads en ecb_leads:      {$leads_total}\n";
echo "Conversión sesión→lead:  " . ecda_pct( $sessions_lead, $sessions_total ) . "\n";
echo "Mensajes promedio:       " . round( (float) ( $range['avg_msgs'] ?? 0 ), 1 ) . " · total " . (int) ( $range['total_msgs'] ?? 0 ) . " · corpus " . size_format( (int) ( $range['bytes'] ?? 0 ) ) . "\n";
echo "Rango histórico:         " . ( $range['oldest'] ?? '-' ) . " → " . ( $range['newest'] ?? '-' ) . "\n\n";

// ── 7-8: top intenciones / proyectos (desde ecb_leads.data) ─────────────────
$intents = []; $projects = [];
$lead_rows = $wpdb->get_results( "SELECT data FROM {$leads_table}", ARRAY_A );
foreach ( (array) $lead_rows as $r ) {
	$d = json_decode( (string) ( $r['data'] ?? '{}' ), true );
	if ( ! is_array( $d ) ) continue;
	$i = trim( (string) ( $d['lead_intent'] ?? $d['intent'] ?? '' ) );
	$p = trim( (string) ( $d['project_area'] ?? $d['project_slug'] ?? '' ) );
	if ( $i !== '' ) { $intents[ $i ] = ( $intents[ $i ] ?? 0 ) + 1; }
	if ( $p !== '' ) { $projects[ $p ] = ( $projects[ $p ] ?? 0 ) + 1; }
}
echo "Top intenciones (leads):\n";
foreach ( ecda_top( $intents ) as $k => $n ) { echo "  {$n} × {$k}\n"; }
echo "Top proyectos (leads):\n";
foreach ( ecda_top( $projects ) as $k => $n ) { echo "  {$n} × {$k}\n"; }

// ── 9-10: abandono y preguntas repetidas (batch sobre messages JSON) ────────
$questions = [];        // mensajes user normalizados (todas las sesiones)
$abandon_last = [];     // último mensaje user en sesiones sin lead
$abandon_msgcount = []; // distribución de largo de sesiones sin lead
$batch = 500; $offset = 0;
while ( true ) {
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT lead_email, lead_phone, message_count, messages FROM {$conv_table} ORDER BY id ASC LIMIT %d OFFSET %d",
		$batch, $offset
	), ARRAY_A );
	if ( empty( $rows ) ) break;
	foreach ( $rows as $row ) {
		$msgs = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		if ( ! is_array( $msgs ) ) continue;
		$has_lead = ( ! empty( $row['lead_email'] ) || ! empty( $row['lead_phone'] ) );
		$last_user = '';
		foreach ( $msgs as $m ) {
			if ( ( $m['role'] ?? '' ) !== 'user' ) continue;
			$txt = ecda_norm_text( $m['content'] ?? '' );
			// descartar respuestas de formulario: muy cortas o casi-solo dígitos
			$digits = preg_replace( '/[^0-9]/', '', $txt );
			if ( strlen( $txt ) < 12 || ( strlen( $txt ) > 0 && strlen( $digits ) / strlen( $txt ) > 0.5 ) ) continue;
			$questions[ $txt ] = ( $questions[ $txt ] ?? 0 ) + 1;
			$last_user = $txt;
		}
		if ( ! $has_lead ) {
			$mc = (int) ( $row['message_count'] ?? 0 );
			$bucket = $mc <= 2 ? '1-2 msgs' : ( $mc <= 5 ? '3-5 msgs' : ( $mc <= 10 ? '6-10 msgs' : '11+ msgs' ) );
			$abandon_msgcount[ $bucket ] = ( $abandon_msgcount[ $bucket ] ?? 0 ) + 1;
			if ( $last_user !== '' ) {
				$abandon_last[ $last_user ] = ( $abandon_last[ $last_user ] ?? 0 ) + 1;
			}
		}
	}
	$offset += $batch;
}

// solo repetidas (n>=2) para preguntas
$repeated = array_filter( $questions, fn( $n ) => $n >= 2 );
echo "\nTop preguntas repetidas (user, n≥2, normalizadas):\n";
$shown = 0;
foreach ( ecda_top( $repeated, 15 ) as $k => $n ) {
	echo "  {$n} × " . ( strlen( $k ) > 90 ? substr( $k, 0, 90 ) . '…' : $k ) . "\n";
	$shown++;
}
if ( $shown === 0 ) { echo "  (ninguna repetida aún — corpus chico)\n"; }

echo "\nDistribución de sesiones SIN lead por largo:\n";
foreach ( [ '1-2 msgs', '3-5 msgs', '6-10 msgs', '11+ msgs' ] as $b ) {
	if ( isset( $abandon_msgcount[ $b ] ) ) { echo "  {$b}: {$abandon_msgcount[$b]}\n"; }
}
echo "Top últimos mensajes antes de abandonar (sesiones sin lead):\n";
$shown = 0;
foreach ( ecda_top( $abandon_last, 10 ) as $k => $n ) {
	echo "  {$n} × " . ( strlen( $k ) > 90 ? substr( $k, 0, 90 ) . '…' : $k ) . "\n";
	$shown++;
}
if ( $shown === 0 ) { echo "  (sin datos suficientes)\n"; }

echo "\n═══ FIN — cero escrituras ejecutadas ═══\n";
