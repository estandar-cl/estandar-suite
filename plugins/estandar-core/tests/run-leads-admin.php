<?php
/**
 * Leads AdminPage (estandar-core) — tests funcionales de la página admin de leads.
 *
 *   php tests/run-leads-admin.php   → expect "0 failed"
 *
 * Covers:
 *   - render_list(): tabla con leads, enlaces Ficha / IA Context, filtros.
 *   - render_ficha(): pipeline, meta, timeline, acciones (Contactar / IA Context).
 * Rollbacks all fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Core\Leads\AdminPage' ) ) {
	fwrite( STDERR, "run-leads-admin: AdminPage no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Core\Leads\AdminPage as AP;

global $wpdb;
$passed = 0;
$failed = 0;

function admin_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$leads_table = $wpdb->prefix . 'es_leads';
$subs_table  = $wpdb->prefix . 'es_lead_submissions';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) !== $leads_table ) {
	fwrite( STDERR, "run-leads-admin: tabla es_leads no existe\n" );
	exit( 1 );
}

$wpdb->query( 'START TRANSACTION' );

$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( $leads_table, array(
	'lead_uuid'              => wp_generate_uuid4(),
	'status'                 => 'NEW',
	'name'                   => 'Admin Test Lead',
	'email'                  => 'admin@test.cl',
	'phone'                  => '+56 9 1234 5678',
	'project_slug'           => 'demo',
	'lead_intent'            => 'compra',
	'assigned_operator_name' => 'Marta',
	'temperature'            => 'tibio',
	'priority'               => 'media',
	'first_seen_at'          => $now,
	'last_seen_at'           => $now,
	'created_at'             => $now,
	'updated_at'             => $now,
) );
$lead_id = absint( $wpdb->insert_id );

$_GET = array( 'page' => AP::SLUG );

$list = AP::render_list();
admin_check( 'render_list no vacía', $list !== '' );
admin_check( 'render_list muestra lead id', strpos( $list, '#' . $lead_id ) !== false );
admin_check( 'render_list muestra nombre', strpos( $list, 'Admin Test Lead' ) !== false );
admin_check( 'render_list enlace Ficha', strpos( $list, 'lead=' . $lead_id ) !== false );
admin_check( 'render_list enlace IA Context', strpos( $list, 'ai_context=' . $lead_id ) !== false );
admin_check( 'render_list filtros presentes', strpos( $list, 'leads-filters' ) !== false );

$ficha = AP::render_ficha( $lead_id );
admin_check( 'render_ficha no vacía', $ficha !== '' );
admin_check( 'render_ficha título lead', strpos( $ficha, 'Admin Test Lead' ) !== false );
admin_check( 'render_ficha Pipeline', strpos( $ficha, 'Pipeline' ) !== false );
admin_check( 'render_ficha IA Context', strpos( $ficha, 'ai_context=' . $lead_id ) !== false );
admin_check( 'render_ficha Contactar WhatsApp', strpos( $ficha, 'contact=whatsapp' ) !== false );
admin_check( 'render_ficha Calificar', strpos( $ficha, 'feedback=1' ) !== false );
admin_check( 'render_ficha meta score/temp', strpos( $ficha, 'Temperatura' ) !== false );
admin_check( 'render_ficha volver a lista', strpos( $ficha, 'page=estandar-suite' ) !== false );

admin_check( 'render_ficha id inexistente → aviso', strpos( AP::render_ficha( 999999 ), 'no encontrado' ) !== false );

// IA Context en la vista embebida (Core → Config → Leads → Vista): el botón
// apunta a ...&ai_context=ID y debe mostrar el markdown inline (no re-lista).
$_GET = array( 'page' => 'estandar-suite', 'tab' => 'config', 'sub' => 'leads', 'ss' => 'vista', 'ai_context' => $lead_id );
$ai_embedded = AP::render_embedded();
admin_check( 'embedded ai_context → renderiza IA Context', strpos( $ai_embedded, 'IA Context — Lead #' . $lead_id ) !== false );
admin_check( 'embedded ai_context → markdown en textarea', strpos( $ai_embedded, '# IA Context' ) !== false );
admin_check( 'embedded ai_context → botón descargar .md', strpos( $ai_embedded, 'Descargar .md' ) !== false );
admin_check( 'embedded ai_context → volver a lista', strpos( $ai_embedded, 'Volver a la lista' ) !== false );
admin_check( 'embedded ai_context no renderiza la lista', strpos( $ai_embedded, 'leads-filters' ) === false );

// Sin ai_context, la vista embebida sigue mostrando la lista.
$_GET = array( 'page' => 'estandar-suite', 'tab' => 'config', 'sub' => 'leads', 'ss' => 'vista' );
$list_embedded = AP::render_embedded();
admin_check( 'embedded sin ai_context → lista', strpos( $list_embedded, 'leads-filters' ) !== false );

$_GET = array();

$wpdb->query( 'ROLLBACK' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
