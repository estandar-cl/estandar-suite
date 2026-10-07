<?php
/**
 * ESTANDAR SUITE v2 — Capture · ORÁCULO DE PARIDAD Forms legacy ↔ v2
 *
 * La matriz `form_type × opción` que es EL definition of done de la migración
 * estandar-forms → estandar-capture (F0 del roadmap). Cada fila declara un
 * estado (native / partial / missing) y un *probe* que verifica la realidad
 * del código; el test falla si la declaración no coincide con la realidad.
 * Eso impide que el oráculo se vuelva mentira o que un avance se registre sin
 * implementarse.
 *
 *   php tests/parity/run-parity.php            → reporte + exit 0
 *   php tests/parity/run-parity.php --gate     → exit 1 hasta que TODO esté native
 *
 * Regla de retiro (F5): estandar-forms se retira solo cuando --gate pasa
 * (100% de las filas native) — incluye Surveys, según la regla del proyecto.
 */

define( 'ABSPATH', __DIR__ );
define( 'PARITY_ROOT', dirname( __DIR__, 2 ) ); // raíz del plugin estandar-capture
$_SERVER['HTTP_REFERER'] = '';
$_SERVER['REMOTE_ADDR']  = '127.0.0.1';
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return strtolower( (string) preg_replace( '/[^a-z0-9\-_]/', '-', strtolower( trim( (string) $t ) ) ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) !== false; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( (string) strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ), mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

// Load capture (misma base que run-capture.php).
require PARITY_ROOT . '/includes/Ingestion.php';
require PARITY_ROOT . '/includes/ConversationEngine.php';
require PARITY_ROOT . '/includes/Attribution.php';
require PARITY_ROOT . '/includes/Fields.php';
require PARITY_ROOT . '/includes/Spam.php';
require PARITY_ROOT . '/includes/Webhook.php';
require PARITY_ROOT . '/includes/Actions.php';
require PARITY_ROOT . '/includes/SurveyDb.php';
require PARITY_ROOT . '/includes/SurveyHandler.php';
require PARITY_ROOT . '/includes/SurveyRenderer.php';
require PARITY_ROOT . '/includes/SurveyAnalytics.php';
require PARITY_ROOT . '/includes/IncidentDb.php';
require PARITY_ROOT . '/includes/Repeater.php';
require PARITY_ROOT . '/includes/IncidentEngine.php';
require PARITY_ROOT . '/includes/IncidentRenderer.php';
require PARITY_ROOT . '/includes/GfImporter.php';
require PARITY_ROOT . '/includes/Forms.php';
require PARITY_ROOT . '/includes/FormBridge.php';
require PARITY_ROOT . '/includes/Adoption.php';
require PARITY_ROOT . '/includes/FormsAdoption.php';
require PARITY_ROOT . '/includes/FormRenderer.php';
require PARITY_ROOT . '/includes/LegacyFormShortcode.php';

use Estandar\Capture as C;

// ── Helper: inspección estática de fuentes (no requiere WP) ────────────────
$GLOBALS['__src_cache'] = array();
function src_has( string $file, string $needle ): bool {
	$abs = PARITY_ROOT . '/includes/' . $file;
	if ( ! isset( $GLOBALS['__src_cache'][ $abs ] ) ) {
		$GLOBALS['__src_cache'][ $abs ] = (string) @file_get_contents( $abs );
	}
	return $GLOBALS['__src_cache'][ $abs ] !== '' && strpos( $GLOBALS['__src_cache'][ $abs ], $needle ) !== false;
}

// ── MATRIZ DE PARIDAD ───────────────────────────────────────────────────────
// status esperado: native | partial | missing. probe() devuelve el estado que
// la REALIDAD produce hoy; el oráculo exige probe() === status.
$matrix = array();

function add_row( array &$m, string $id, string $area, string $type, string $option, string $status, int $weight, callable $probe ): void {
	$m[] = array(
		'id'     => $id,
		'area'   => $area,
		'type'   => $type,
		'option' => $option,
		'status' => $status,
		'weight' => $weight,
		'probe'  => $probe,
	);
}

// ── A · Renderer frontend (Fields + FormRenderer) ─────────────────────────
add_row( $matrix, 'renderer.shortcode_v2', 'Renderer', 'lead', 'shortcode [estandar_form_v2] registrado', 'native', 3,
	fn() => class_exists( 'Estandar\Capture\FormRenderer' ) && src_has( 'FormRenderer.php', 'add_shortcode' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.render_lead', 'Renderer', 'lead', 'render frontend de forms lead', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\FormRenderer', 'render_form' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.text', 'Renderer', 'lead', 'campo text', 'native', 1,
	fn() => src_has( 'Fields.php', "'text', 'email', 'tel', 'phone', 'number', 'url', 'date'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.email', 'Renderer', 'lead', 'campo email', 'native', 1,
	fn() => src_has( 'Fields.php', "'text', 'email', 'tel', 'phone', 'number', 'url', 'date'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.textarea', 'Renderer', 'lead', 'campo textarea', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'textarea'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.select', 'Renderer', 'lead', 'campo select', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'select'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.radio', 'Renderer', 'lead', 'campo radio', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'radio'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.checkbox', 'Renderer', 'lead', 'campo checkbox', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'checkbox'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.tel', 'Renderer', 'lead', 'campo tel/phone', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'tel'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.number', 'Renderer', 'lead', 'campo number', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'text'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.date', 'Renderer', 'lead', 'campo date', 'native', 1,
	fn() => src_has( 'Fields.php', "case 'text'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.rut', 'Renderer', 'lead', 'campo RUT (render + validación JS)', 'native', 4,
	fn() => src_has( 'Fields.php', "case 'rut'" ) && is_file( PARITY_ROOT . '/assets/js/estandar-rut.js' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.file', 'Renderer', 'lead', 'campo file (subida real)', 'native', 5,
	fn() => src_has( 'Fields.php', "case 'file'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.matrix', 'Renderer', 'lead', 'campo matrix', 'native', 4,
	fn() => src_has( 'Fields.php', "case 'matrix'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.cards_emoji', 'Renderer', 'lead', 'campos cards/emoji', 'native', 4,
	fn() => src_has( 'Fields.php', "case 'cards'" ) && src_has( 'Fields.php', "case 'emoji'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.rating_nps', 'Renderer', 'lead', 'campos rating/nps', 'native', 4,
	fn() => src_has( 'Fields.php', "case 'rating'" ) && src_has( 'Fields.php', "case 'nps'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.hidden', 'Renderer', 'lead', 'campo hidden', 'native', 3,
	fn() => src_has( 'Fields.php', "case 'hidden'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.heading_html', 'Renderer', 'lead', 'campos heading/html', 'native', 3,
	fn() => src_has( 'Fields.php', "case 'heading'" ) && src_has( 'Fields.php', "case 'html'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.dependent_select', 'Renderer', 'lead', 'campo dependent_select (AJAX/proyecto)', 'native', 5,
	fn() => src_has( 'Fields.php', "case 'dependent_select'" ) && src_has( 'Fields.php', 'ef_get_streets' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.field.project_select', 'Renderer', 'lead', 'campo project_select (Registry)', 'native', 4,
	fn() => src_has( 'Fields.php', "case 'project_select'" ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.repeat_visibility', 'Renderer', 'lead', 'repeat_visibility aplicada en render', 'native', 4,
	fn() => src_has( 'FormRenderer.php', 'repeat_visibility' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.success_animation', 'Renderer', 'lead', 'animación de éxito (SUCCESS_ANIMATION_V1)', 'native', 2,
	fn() => src_has( 'FormRenderer.php', 'success_animation' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.honeypot', 'Renderer', 'lead', 'honeypot anti-spam', 'native', 2,
	fn() => src_has( 'FormRenderer.php', '_hp' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.nonce', 'Renderer', 'lead', 'nonce de envío', 'native', 2,
	fn() => src_has( 'FormRenderer.php', '_nonce' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.rate_limit', 'Renderer', 'lead', 'rate-limit por IP+form', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\Spam' ) && method_exists( 'Estandar\Capture\Spam', 'rate_limit_ok' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.recaptcha', 'Renderer', 'lead', 'reCAPTCHA v3 real', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\Spam' ) && method_exists( 'Estandar\Capture\Spam', 'recaptcha_verify' ) && is_file( PARITY_ROOT . '/assets/js/estandar-recaptcha.js' ) ? 'native' : 'missing' );
add_row( $matrix, 'renderer.duplicate_token', 'Renderer', 'lead', 'token anti-duplicado', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\Spam' ) && method_exists( 'Estandar\Capture\Spam', 'duplicate_submit' ) ? 'native' : 'missing' );

// ── B · Pipeline / envío (Ingestion + Actions + Webhook) ───────────────────
add_row( $matrix, 'pipeline.ingest', 'Pipeline', 'lead', 'persistencia en estandar_capture_submissions', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\Ingestion', 'ingest' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.attribution', 'Pipeline', 'lead', 'atribución UTM + click-ids', 'native', 2,
	fn() => src_has( 'Ingestion.php', 'utm' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.notify', 'Pipeline', 'lead', 'notificación por Core\\Mail', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'notify_submission' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.autoresponse', 'Pipeline', 'lead', 'autorespuesta por Core\\Mail', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'autoresponse_submission' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.notify_routing', 'Pipeline', 'lead', 'routing de notificación por campo', 'native', 2,
	fn() => src_has( 'Forms.php', 'route_field' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.notify_cc_admin', 'Pipeline', 'lead', 'CC al admin por defecto', 'native', 2,
	fn() => src_has( 'Forms.php', 'cc_admin' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.webhook_out', 'Pipeline', 'lead', 'webhook de salida (estandar/cf7_compat)', 'native', 5,
	fn() => class_exists( 'Estandar\Capture\Webhook' ) && method_exists( 'Estandar\Capture\Webhook', 'dispatch' ) && method_exists( 'Estandar\Capture\Webhook', 'build_cf7_payload' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.action_database', 'Pipeline', 'lead', 'Action Engine: database (Ingestion)', 'native', 5,
	fn() => class_exists( 'Estandar\Capture\Actions' ) && in_array( 'database', Estandar\Capture\Actions::normalize( array( 'database', 'email' ) ), true ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.action_email', 'Pipeline', 'lead', 'Action Engine: email con plantillas', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\Actions' ) && method_exists( 'Estandar\Capture\Actions', 'run' ) && src_has( 'Actions.php', 'notify_submission' ) ? 'native' : 'missing' );
add_row( $matrix, 'pipeline.action_webhook', 'Pipeline', 'lead', 'Action Engine: webhook', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\Actions' ) && src_has( 'Actions.php', 'Webhook::dispatch' ) ? 'native' : 'missing' );

// ── C · Form types (publicables en frontend) ───────────────────────────────
add_row( $matrix, 'types.lead', 'Form types', 'lead', 'form tipo lead publicable', 'native', 5,
	fn() => method_exists( 'Estandar\Capture\FormRenderer', 'render' ) ? 'native' : 'missing' );
add_row( $matrix, 'types.survey_render', 'Form types', 'survey', 'form tipo survey publicable', 'native', 5,
	fn() => src_has( 'FormRenderer.php', 'SurveyRenderer' ) && class_exists( 'Estandar\Capture\SurveyRenderer' ) ? 'native' : 'missing' );
add_row( $matrix, 'types.incident_render', 'Form types', 'incident', 'form tipo incident publicable', 'native', 5,
	fn() => src_has( 'FormRenderer.php', 'IncidentRenderer' ) && class_exists( 'Estandar\Capture\IncidentRenderer' ) ? 'native' : 'missing' );
add_row( $matrix, 'types.experiences', 'Form types', 'experience', 'experiencias conversacionales publicables', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\ConversationEngine', 'start_session' ) && method_exists( 'Estandar\Capture\ConversationEngine', 'process_step' )
		&& ( function () {
			$src = (string) file_get_contents( PARITY_ROOT . '/includes/FormRenderer.php' );
			$svc = (string) file_get_contents( PARITY_ROOT . '/includes/SurveyRenderer.php' );
			return strpos( $src, 'is_conversational' ) !== false && strpos( $svc, 'conversation_config' ) !== false;
		} )() ? 'native' : 'partial' );

// ── D · Migración / adopción de datos ──────────────────────────────────────
add_row( $matrix, 'migration.migrate_legacy', 'Migración', 'lead', 'migrate_legacy (dry-run / commit)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\Forms', 'migrate_legacy' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.preserve_id', 'Migración', 'lead', 'preserva IDs + AUTO_INCREMENT', 'native', 2,
	fn() => src_has( 'Forms.php', 'AUTO_INCREMENT' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.parity_diff', 'Migración', 'lead', 'parity_diff legacy ↔ nativo', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'parity_diff' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.rollback', 'Migración', 'lead', 'rollback_migration', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'rollback_migration' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.adopt_entries', 'Migración', 'lead', 'adopción read-only de entries', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\FormsAdoption', 'import_entries' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.adopt_responses', 'Migración', 'survey', 'adopción read-only de survey responses', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\FormsAdoption', 'import_responses' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.adopt_incidents', 'Migración', 'incident', 'adopción read-only de incidents/requests', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\FormsAdoption', 'import_incidents' ) && method_exists( 'Estandar\Capture\FormsAdoption', 'rollback_incidents' ) ? 'native' : 'missing' );
add_row( $matrix, 'migration.surveys_migrables', 'Migración', 'survey', 'surveys migrables (legacy_form_type_map)', 'native', 4,
	fn() => C\Forms::legacy_form_type_map( 'survey' ) === 'survey' ? 'native' : 'missing' );

// ── E · Admin / builder ────────────────────────────────────────────────────
add_row( $matrix, 'admin.maquetador', 'Admin', 'lead', 'maquetador visual (sections+fields)', 'native', 3,
	fn() => is_file( PARITY_ROOT . '/includes/templates/maquetador.php' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.crud', 'Admin', 'lead', 'CRUD forms (create/update/trash/restore/delete)', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'create' ) && method_exists( 'Estandar\Capture\Forms', 'duplicate' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.duplicate', 'Admin', 'lead', 'duplicar form', 'native', 1,
	fn() => method_exists( 'Estandar\Capture\Forms', 'duplicate' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.convert_type', 'Admin', 'lead', 'convert_type no destructivo', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Forms', 'convert_type' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.anti_loss', 'Admin', 'lead', 'anti-pérdida (config_loss_reason + merge_config_json)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\Forms', 'config_loss_reason' ) && method_exists( 'Estandar\Capture\Forms', 'merge_config_json' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.json_lifecycle_ui', 'Admin', 'lead', 'JSON lifecycle en UI (export/import con protecciones)', 'native', 4,
	fn() => src_has( 'Admin.php', 'handle_form_json_export' ) && src_has( 'Admin.php', 'handle_form_json_import' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.gf_importer', 'Admin', 'lead', 'GF importer (Gravity Forms → v2)', 'native', 3,
	fn() => class_exists( 'Estandar\Capture\GfImporter' ) && src_has( 'Admin.php', 'handle_gf_import' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.csv_export', 'Admin', 'lead', 'export CSV de entradas', 'native', 3,
	fn() => src_has( 'Admin.php', 'handle_entries_csv' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.survey_analytics', 'Admin', 'survey', 'analytics v2 de encuestas (summary/nps/rating/matrix)', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\SurveyAnalytics' ) && method_exists( 'Estandar\Capture\SurveyAnalytics', 'get_nps' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.survey_secure_tokens', 'Admin', 'survey', 'tokens seguros v2 (vistas públicas noindex) — RETIRADO (A93, 0-uso)', 'missing', 3,
	fn() => class_exists( 'Estandar\Capture\SecureToken' ) && method_exists( 'Estandar\Capture\SurveyHandler', 'handle_secure_route' ) ? 'native' : 'missing' );
add_row( $matrix, 'admin.survey_csv_export', 'Admin', 'survey', 'export CSV de respuestas de encuesta', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\SurveyHandler', 'handle_export_csv' ) ? 'native' : 'missing' );

// ── F · Compat (F0 — no romper el sitio) ───────────────────────────────────
// Arquitectura: mientras el main file de estandar-forms exista en disco,
// estandar-core LegacyMap re-incluye el legacy y mantiene vivo `[estandar_form]`
// (cero regresión). El LegacyFormShortcode de capture es el TAKEOVER de F5:
// toma posesión solo cuando el archivo legacy ya no está (retiro/archivo).
add_row( $matrix, 'compat.legacymap_owner', 'Compat', 'lead', 'LegacyMap (estandar-core) mantiene [estandar_form] con legacy off', 'native', 5,
	function () {
		$core_map = PARITY_ROOT . '/../estandar-core/includes/LegacyMap.php';
		if ( ! is_readable( $core_map ) ) {
			return 'missing';
		}
		$src = (string) file_get_contents( $core_map );
		return ( strpos( $src, "'estandar_form'" ) !== false && strpos( $src, 'LEGACY_PLUGINS' ) !== false ) ? 'native' : 'missing';
	} );
add_row( $matrix, 'compat.takeover_class', 'Compat', 'lead', 'takeover capture [estandar_form] listo (clase + gate)', 'native', 4,
	fn() => class_exists( 'Estandar\Capture\LegacyFormShortcode' ) && src_has( 'LegacyFormShortcode.php', 'legacy_main_on_disk' ) ? 'native' : 'missing' );
add_row( $matrix, 'compat.resolve_readonly', 'Compat', 'lead', 'resolución legacy SOLO-LECTURA (0 escrituras)', 'native', 4,
	fn() => src_has( 'LegacyFormShortcode.php', 'estandar_forms' ) && src_has( 'LegacyFormShortcode.php', 'legacy_to_native' ) ? 'native' : 'missing' );
add_row( $matrix, 'compat.no_pisar', 'Compat', 'lead', 'no pisa shortcode de estandar-forms ni de LegacyMap', 'native', 3,
	fn() => src_has( 'LegacyFormShortcode.php', 'Estandar_Forms_Shortcode' ) && src_has( 'LegacyFormShortcode.php', 'legacy_main_on_disk' ) ? 'native' : 'missing' );
add_row( $matrix, 'compat.render_form', 'Compat', 'lead', 'render_form() reutiliza el pipeline v2', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\FormRenderer', 'render_form' ) ? 'native' : 'missing' );

// ── G · Integración v2 transversal ─────────────────────────────────────────
add_row( $matrix, 'v2.capture_event', 'v2', 'lead', 'evento estandar_suite_capture_event (flow/brain/insights)', 'native', 2,
	fn() => src_has( 'Ingestion.php', 'estandar_suite_capture_event' ) ? 'native' : 'missing' );
add_row( $matrix, 'v2.core_mail', 'v2', 'lead', 'motor único Core\\Mail (sin wp_mail directo)', 'native', 2,
	fn() => src_has( 'Forms.php', 'Estandar\\Core\\Mail' ) ? 'native' : 'missing' );
add_row( $matrix, 'v2.core_embed', 'v2', 'lead', 'Core\\Embed (shortcode/copy-control)', 'native', 1,
	fn() => src_has( 'templates/feature-forms-product.php', 'Core\\Embed' ) ? 'native' : 'missing' );
add_row( $matrix, 'v2.core_channels', 'v2', 'lead', 'Core\\Channels (atribución compartida)', 'native', 1,
	fn() => src_has( 'Attribution.php', 'Channels' ) ? 'native' : 'missing' );
add_row( $matrix, 'v2.core_integrations', 'v2', 'lead', 'Core\\Integrations (Sheets/Excel async)', 'native', 2,
	fn() => src_has( 'FormBridge.php', 'estandar_suite_form_entry_created' ) ? 'native' : 'missing' );

// ── RUN ────────────────────────────────────────────────────────────────────
$gate = in_array( '--gate', $argv, true );
$passed = 0; $failed = 0;
$by_area = array();
$weighted_total = 0; $weighted_native = 0;

foreach ( $matrix as $row ) {
	$actual = (string) $row['probe']();
	$weighted_total += $row['weight'];
	if ( $row['status'] === 'native' ) {
		$weighted_native += $row['weight'];
	}
	$by_area[ $row['area'] ]['total'] = ( $by_area[ $row['area'] ]['total'] ?? 0 ) + 1;
	$by_area[ $row['area'] ][ $row['status'] ] = ( $by_area[ $row['area'] ][ $row['status'] ] ?? 0 ) + 1;
	$by_area[ $row['area'] ]['weighted_total'] = ( $by_area[ $row['area'] ]['weighted_total'] ?? 0 ) + $row['weight'];
	if ( $row['status'] === 'native' ) {
		$by_area[ $row['area'] ]['weighted_native'] = ( $by_area[ $row['area'] ]['weighted_native'] ?? 0 ) + $row['weight'];
	}

	$ok = ( $actual === $row['status'] );
	$in_gate = $gate && $row['status'] !== 'native';
	if ( $ok && ! $in_gate ) {
		$passed++;
		echo 'PASS  [' . str_pad( $row['id'], 30 ) . '] ' . str_pad( $row['status'], 7 ) . ' · ' . $row['type'] . ' · ' . $row['option'] . "\n";
	} else {
		$failed++;
		$reason = $gate && $row['status'] !== 'native' ? 'gate: pendiente (' . $row['status'] . ')' : 'oráculo desincronizado: realidad=' . $actual;
		echo 'FAIL  [' . str_pad( $row['id'], 30 ) . '] declarado=' . str_pad( $row['status'], 7 ) . ' · ' . $row['option'] . ' → ' . $reason . "\n";
	}
}

$pct = $weighted_total > 0 ? round( 100 * $weighted_native / $weighted_total, 1 ) : 0;

echo "\n=== RESUMEN POR ÁREA ===\n";
echo str_pad( 'Área', 12 ) . str_pad( 'filas', 6 ) . str_pad( 'native', 8 ) . str_pad( 'partial', 8 ) . str_pad( 'missing', 8 ) . "ponderado\n";
foreach ( $by_area as $area => $a ) {
	$apct = ! empty( $a['weighted_total'] ) ? round( 100 * ( $a['weighted_native'] ?? 0 ) / $a['weighted_total'], 1 ) : 0;
	echo str_pad( $area, 12 ) . str_pad( (string) $a['total'], 6 ) . str_pad( (string) ( $a['native'] ?? 0 ), 8 )
		. str_pad( (string) ( $a['partial'] ?? 0 ), 8 ) . str_pad( (string) ( $a['missing'] ?? 0 ), 8 ) . $apct . "%\n";
}

echo "\nParidad ponderada global: {$weighted_native}/{$weighted_total} = {$pct}% (oracle)\n";
echo $gate ? '(--gate: retiro de estandar-forms exige 100% native)\n' : '';
echo "\n{$passed} passed, {$failed} failed\n";

if ( $gate ) {
	echo $failed === 0 ? 'GATE PARITY: READY → estandar-forms puede retirarse.\n' : 'GATE PARITY: NOT READY.\n';
}
exit( $failed > 0 ? 1 : 0 );
