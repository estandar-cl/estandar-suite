<?php
/**
 * mail identity canonical (estandar-capture + core) — MAIL_IDENTITY_CANONICAL_V1.
 *
 *   php tests/run-mail-identity.php   → expect "0 failed"
 *
 * Cubre:
 *   - Admin::adopt_leads_email_identity(): absorbe el branding legacy → moderno,
 *     sin pisar lo moderno y sin tocar la opción legacy.
 *   - Mail::identity(): usa Leads→Email; IGNORA el legacy y el Core "Identidad";
 *     default negro cuando no hay nada; el skin del proyecto gana.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Core\Mail' ) || ! class_exists( '\Estandar\Capture\Admin' ) ) {
	fwrite( STDERR, "run-mail-identity: faltan clases Mail/Admin.\n" );
	exit( 1 );
}

use Estandar\Core\Mail;
use Estandar\Capture\Admin;

global $wpdb;
$passed = 0;
$failed = 0;
function mi_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$wpdb->query( 'START TRANSACTION' );

$LEGACY = 'estandar_leads_email_settings';
$MODERN = 'estandar_suite_capture_leads_settings';
foreach ( array( $LEGACY, $MODERN, 'estandar_suite_core_brand_color', 'estandar_suite_core_default_logo', 'estandar_suite_core_project_branding', Admin::LEADS_EMAIL_ADOPT_FLAG ) as $o ) {
	delete_option( $o );
}

// ── 1. Adopción legacy → moderno ────────────────────────────────────────
update_option( $LEGACY, array(
	'brand_color'  => '#004876',
	'logo_url'     => 'https://acacios.cl/logo.png',
	'sender_name'  => 'Acacios',
	'sender_email' => 'noreply@acacios.cl',
	'subject'      => 'Nuevo Lead Recibido',
) );
$stats = Admin::adopt_leads_email_identity( true );
$cap   = get_option( $MODERN, array() );
mi_check( 'adopt: adopted>=3', (int) ( $stats['adopted'] ?? 0 ) >= 3 );
mi_check( 'adopt: modern tiene brand_color', (string) ( $cap['brand_color'] ?? '' ) === '#004876' );
mi_check( 'adopt: modern tiene logo', (string) ( $cap['logo_url'] ?? '' ) === 'https://acacios.cl/logo.png' );
mi_check( 'adopt: modern tiene sender_email', (string) ( $cap['sender_email'] ?? '' ) === 'noreply@acacios.cl' );
$leg = get_option( $LEGACY, array() );
mi_check( 'adopt: legacy intacto', (string) ( $leg['brand_color'] ?? '' ) === '#004876' );

// ── 2. identity usa Leads→Email ─────────────────────────────────────────
$id = Mail::identity();
mi_check( 'identity: brand desde Leads→Email', (string) $id['brand_color'] === '#004876' );
mi_check( 'identity: logo desde Leads→Email', (string) $id['logo_url'] === 'https://acacios.cl/logo.png' );

// ── 3. Core "Identidad" IGNORADO (retirado) ─────────────────────────────
update_option( 'estandar_suite_core_brand_color', '#3affdb' );
update_option( 'estandar_suite_core_default_logo', 'https://x/core.png' );
$id = Mail::identity();
mi_check( 'identity: ignora core brand_color', (string) $id['brand_color'] !== '#3affdb' );
mi_check( 'identity: ignora core default_logo', (string) $id['logo_url'] !== 'https://x/core.png' );

// Legacy sigue presente pero ya NO se lee: si vaciamos lo moderno → negro.
delete_option( $MODERN );
$id = Mail::identity();
mi_check( 'identity: sin config → default negro', (string) $id['brand_color'] === '#000000' );
mi_check( 'identity: legacy ya no provee color', (string) $id['brand_color'] !== '#004876' );

// ── 4. skin del proyecto gana ───────────────────────────────────────────
update_option( 'estandar_suite_core_project_branding', array( 'p1' => array( 'brand_color' => '#123456' ) ) );
$id = Mail::identity( array(), 'p1' );
mi_check( 'identity: skin de proyecto gana', (string) $id['brand_color'] === '#123456' );

// ── 5. overrides ganan ──────────────────────────────────────────────────
$id = Mail::identity( array( 'brand_color' => '#abcdef' ), 'p1' );
mi_check( 'identity: override gana', (string) $id['brand_color'] === '#abcdef' );

// ── 6. Fase 3: selector de plantilla en el maquetador (source-level) ─────
$maq = (string) file_get_contents( dirname( __DIR__ ) . '/includes/templates/maquetador.php' );
mi_check( 'maquetador: selector mail_template', strpos( $maq, 'data-key="mail_template"' ) !== false );
mi_check( 'maquetador: opción "none"', strpos( $maq, '"none"' ) !== false && strpos( $maq, 'Ninguna (sin plantilla)' ) !== false );
mi_check( 'maquetador: preview aplica plantilla (ES_MAIL_TPL)', strpos( $maq, 'ES_MAIL_TPL' ) !== false && strpos( $maq, 'buildBody' ) !== false );

$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
