<?php
/**
 * CONVERSATION_EFFECTIVENESS_EXPORT_V1
 * Insumo para CONVERSATION_EFFECTIVENESS_AUDIT_V1 (ver ESTANDAR_CONVERSATION_EFFECTIVENESS_AUDIT_V1.md,
 * Refinamiento 2).
 *
 * READ-ONLY estricto: solo SELECT sobre wp_ecb_conversations / wp_ecb_leads.
 * Cero escrituras en base de datos. Cero migraciones. Cero cambios de esquema.
 * Cero cambios de comportamiento del chatbot (este script no es llamado por el plugin).
 *
 * La lógica de selección/redacción/lookup vive en
 * estandar_chatbot_build_conversation_effectiveness_export() (includes/conversations-handler.php)
 * — misma función que usa el botón "Exportar conversaciones (audit)" en
 * Herramientas → Operación segura. Este script es un wrapper CLI: llama a esa
 * función y escribe el resultado a un archivo JSON (el botón de admin, en cambio,
 * lo envía como descarga sin persistir nada).
 *
 * La única escritura de este script es el ARCHIVO DE EXPORT (JSON) en el filesystem —
 * ese archivo ES el entregable. Contiene transcripts con PII real (nombres, emails,
 * teléfonos, intención del usuario). Tratar como dato sensible:
 *   - no subir a repos públicos
 *   - transferir solo por canal que Rodrigo ya use para datos de leads
 *   - borrar al cerrar el análisis (CONVERSATION_RETENTION_POLICY_V1, recomendación
 *     operativa no bloqueante)
 *
 * Uso (local, requiere WP vivo):
 *   php wp-content/plugins/estandar-chatbot/tools/estandar-conversation-effectiveness-export.php
 * o vía WP-CLI:
 *   wp eval-file wp-content/plugins/estandar-chatbot/tools/estandar-conversation-effectiveness-export.php
 *
 * Parámetros (opcionales, vía variables de entorno — sin tocar el archivo):
 *   ECEE_LIMIT=100          (default 100, últimas N sesiones por last_message_at DESC)
 *   ECEE_MIN_MESSAGES=2     (default 2, sesiones de 1 mensaje = bounce, se cuentan aparte)
 *   ECEE_OUTPUT_DIR=        (default: {uploads}/estandar-exports/)
 *
 * Salida: un archivo JSON, estructura documentada en
 * ESTANDAR_CONVERSATION_EFFECTIVENESS_EXPORT_V1.md (sección "Ejemplo de output").
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

$conv_table = $wpdb->prefix . 'ecb_conversations';

$LIMIT        = max( 1, (int) ( getenv( 'ECEE_LIMIT' ) ?: 100 ) );
$MIN_MESSAGES = max( 1, (int) ( getenv( 'ECEE_MIN_MESSAGES' ) ?: 2 ) );

echo "═══ CONVERSATION_EFFECTIVENESS_EXPORT_V1 (read-only) · sitio: " . home_url() . " ═══\n\n";

if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) ) ) {
	echo "wp_ecb_conversations no existe en esta instalación. FIN — nada exportado.\n";
	exit( 0 );
}

if ( ! function_exists( 'estandar_chatbot_build_conversation_effectiveness_export' ) ) {
	fwrite( STDERR, "Función estandar_chatbot_build_conversation_effectiveness_export() no disponible. ¿Plugin estandar-chatbot activo?\n" );
	exit( 1 );
}

// ── Selección/redacción/lookup: fuente única de verdad (conversations-handler.php) ──
$result = estandar_chatbot_build_conversation_effectiveness_export( $LIMIT, $MIN_MESSAGES );

if ( ! $result['ok'] ) {
	echo $result['message'] . " FIN — nada exportado.\n";
	exit( 0 );
}

$export = $result['export'];
$meta   = $export['export_meta'];

// ── Escritura del archivo (única escritura de este script — filesystem, no DB) ──
$output_dir = getenv( 'ECEE_OUTPUT_DIR' );
if ( ! $output_dir ) {
	$uploads    = wp_upload_dir();
	$output_dir = trailingslashit( $uploads['basedir'] ) . 'estandar-exports';
}
$output_dir = rtrim( (string) $output_dir, '/' );

if ( ! is_dir( $output_dir ) ) {
	wp_mkdir_p( $output_dir );
}

$filename = 'conversation-effectiveness-export-' . gmdate( 'Ymd-His' ) . '.json';
$filepath = $output_dir . '/' . $filename;

$json  = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
$bytes = file_put_contents( $filepath, $json );

if ( $bytes === false ) {
	fwrite( STDERR, "No se pudo escribir el archivo de export en {$filepath}\n" );
	exit( 1 );
}

// ── Resumen en pantalla ───────────────────────────────────────────────────
echo "Sesiones en wp_ecb_conversations:        {$meta['sessions_total_db']}\n";
echo "Excluidas (message_count < {$meta['criteria']['min_messages']}):        {$meta['sessions_excluded_short']}\n";
echo "Exportadas (message_count >= {$meta['criteria']['min_messages']}, últimas {$meta['criteria']['limit']}): {$meta['sessions_exported']}\n";
echo "  - con lead vinculado:    {$meta['sessions_with_lead']}\n";
echo "  - sin lead vinculado:    {$meta['sessions_without_lead']}\n";
echo "\nArchivo de export:\n  {$filepath}\n";
echo "  (" . size_format( (int) $bytes ) . ")\n";

echo "\n═══ FIN — cero escrituras en base de datos. 1 archivo JSON escrito (arriba). ═══\n";
