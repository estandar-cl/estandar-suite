<?php
/**
 * JSON_LIFECYCLE_V1 — regression suite del ciclo de vida canónico JSON.
 * Run: php tests/run-json-lifecycle.php
 *
 * Cubre: export · import (create/update/duplicate) · merge · replace ·
 * round-trip · slug/shortcode preservation · webhook/secrets · autoresponse ·
 * notificaciones · tracking/routing · conditional logic · hidden fields ·
 * unknown metadata (extensions) · backward compat v1.0 · JSON inválido ·
 * schema inválido · runtime validation failure · rollback automático.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'ESTANDAR_FORMS_VERSION', '13.0.56-test' );

/* ── WP stubs ──────────────────────────────────────────────────────────── */
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_textarea_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_title( $v ) {
	$v = strtolower( trim( (string) $v ) );
	$v = preg_replace( '/[^a-z0-9\s\-_]/', '', $v );
	return trim( preg_replace( '/[\s_]+/', '-', $v ), '-' );
}
function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $v ); }
function remove_accents( $v ) { return (string) $v; }
function wp_kses_post( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags | JSON_UNESCAPED_UNICODE ); }
function esc_url_raw( $v ) { $v = trim( (string) $v ); return preg_match( '#^https?://#i', $v ) ? $v : ''; }
function apply_filters( $tag, $value, ...$a ) { return $value; }
function add_action( ...$a ) {}
function do_action( ...$a ) {}
function current_time( $type ) { return '2026-07-11 12:00:00'; }
function get_option( $k, $d = false ) { return $d; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class Estandar_Forms_Shortcode {
	public static $cleared = 0;
	public static function clear_form_cache( $form ): void { self::$cleared++; }
}

/* ── wpdb in-memory ────────────────────────────────────────────────────── */
class Lifecycle_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $forms = [];   // id => array row
	public $logs  = [];
	public $corrupt_next_reads = false;  // armado: la corrupción inicia tras el próximo UPDATE
	private $corruption_active = false;  // activo: las relecturas devuelven fila corrupta
	private $next_id = 1;

	public function prepare( $q, ...$a ) {
		foreach ( $a as $v ) {
			$q = preg_replace( '/%[ds]/', is_int( $v ) || preg_match( '/^\d+$/', (string) $v ) ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'", $q, 1 );
		}
		return $q;
	}

	public function get_row( $sql, $output = OBJECT ) {
		if ( strpos( $sql, 'FROM wp_estandar_forms WHERE id = ' ) !== false && preg_match( '/id = (\d+)/', $sql, $m ) ) {
			$id = (int) $m[1];
			if ( ! isset( $this->forms[ $id ] ) ) { return null; }
			$row = $this->forms[ $id ];
			if ( $this->corruption_active ) { $row['fields_json'] = '[]'; } // simula corrupción post-write
			return $output === ARRAY_A ? $row : (object) $row;
		}
		return null;
	}

	public function get_var( $sql ) {
		if ( strpos( $sql, 'COUNT(*) FROM wp_estandar_forms WHERE slug = ' ) !== false && preg_match( "/slug = '([^']*)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->forms as $row ) { if ( ( $row['slug'] ?? '' ) === $m[1] ) { $n++; } }
			return $n;
		}
		if ( strpos( $sql, 'COUNT(*) FROM wp_estandar_form_entries' ) !== false ) { return 7; }
		if ( strpos( $sql, 'SELECT context_schema FROM' ) !== false && preg_match( '/id = (\d+)/', $sql, $m ) ) {
			return $this->forms[ (int) $m[1] ]['context_schema'] ?? null;
		}
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		if ( strpos( $sql, 'FROM wp_estandar_form_logs' ) !== false && preg_match( '/form_id = (\d+)/', $sql, $m ) ) {
			$out = [];
			foreach ( array_reverse( $this->logs ) as $log ) {
				if ( (int) $log['form_id'] === (int) $m[1] && in_array( $log['event_type'], [ 'form_snapshot', 'form_json_applied', 'form_json_rollback' ], true ) ) {
					$out[] = $log;
				}
			}
			return $out;
		}
		return [];
	}

	public function insert( $table, $data, $format = null ) {
		if ( $table === 'wp_estandar_forms' ) {
			$data['id'] = $this->next_id++;
			$data['created_at'] = $data['created_at'] ?? '2026-07-01 00:00:00';
			$this->forms[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( $table === 'wp_estandar_form_logs' ) {
			$data['id'] = count( $this->logs ) + 1;
			$this->logs[] = $data;
			return 1;
		}
		return false;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $table === 'wp_estandar_forms' && isset( $where['id'], $this->forms[ $where['id'] ] ) ) {
			$this->forms[ $where['id'] ] = array_merge( $this->forms[ $where['id'] ], $data );
			if ( $this->corrupt_next_reads ) {
				// El rollback (segundo update) desactiva la corrupción — igual que
				// una restauración real elimina el estado corrupto.
				$this->corrupt_next_reads = false;
				$this->corruption_active  = true;
			} else {
				$this->corruption_active = false;
			}
			return 1;
		}
		return false;
	}
}

$GLOBALS['wpdb'] = new Lifecycle_Test_DB();

require dirname( __DIR__ ) . '/includes/helpers.php';
require dirname( __DIR__ ) . '/includes/class-template.php';

/* ── harness ───────────────────────────────────────────────────────────── */
$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
function canon( $envelope ) { return wp_json_encode( Estandar_Forms_Template::canonical_form( $envelope ) ); }
function has_issue( array $issues, string $code, string $path_fragment = '' ): bool {
	foreach ( $issues as $issue ) {
		if ( ( $issue['code'] ?? '' ) === $code && ( $path_fragment === '' || strpos( (string) $issue['path'], $path_fragment ) !== false ) ) {
			return true;
		}
	}
	return false;
}

/* ── seed: formulario completo ─────────────────────────────────────────── */
$wpdb = $GLOBALS['wpdb'];
$seed_fields = [
	[ 'type' => 'heading', 'text' => 'Contacto', 'level' => 'h3', 'align' => 'left' ],
	[ 'type' => 'text', 'name' => 'nombre', 'label' => 'Nombre', 'required' => true, 'error_message' => 'Falta', 'column_width' => '2' ],
	[ 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'column_width' => '2' ],
	[ 'type' => 'select', 'name' => 'interes', 'label' => 'Interés', 'options' => [ [ 'value' => 'compra', 'label' => 'Compra' ], [ 'value' => 'arriendo', 'label' => 'Arriendo' ] ] ],
	[ 'type' => 'textarea', 'name' => 'detalle', 'label' => 'Detalle', 'conditional_logic' => [ 'field' => 'interes', 'operator' => 'equals', 'value' => 'compra' ] ],
	[ 'type' => 'hidden', 'name' => 'utm_campaign_snapshot', 'label' => 'Tracking' ],
];
$wpdb->insert( 'wp_estandar_forms', [
	'name' => 'Form Canónico', 'slug' => 'form-canonico',
	'success_message' => 'Gracias', 'submit_label' => 'Enviar', 'after_submit_text' => '',
	'notify_emails' => 'ventas@cliente.cl', 'form_country' => 'CL',
	'store_entries' => 1, 'send_email' => 0, 'send_webhook' => 1, 'require_recaptcha' => 1,
	'mail_subject' => 'Nuevo lead {{form_name}}', 'mail_body' => 'Hola {{nombre}}', 'mail_sender_name' => 'Estandar', 'mail_reply_to_field' => 'email',
	'visual_theme' => 'clean', 'fields_json' => wp_json_encode( $seed_fields ),
	'webhook_url' => 'https://n8n.cliente.cl/webhook/secreto-abc123', 'webhook_payload_mode' => 'cf7_compat',
	'form_type' => 'lead_capture', 'form_context' => 'generic', 'request_type' => '',
	'context_schema' => wp_json_encode( [
		'routing'    => [ 'mode' => 'fixed_project', 'project_slug' => 'parronales', 'project_name' => 'Parronales' ],
		'extensions' => [ 'x_crm_pipeline' => 'ventas-2026', 'x_meta' => [ 'owner' => 'rodrigo' ] ],
	] ),
	'actions_json' => wp_json_encode( [ [ 'type' => 'webhook', 'url' => 'https://hooks.n8n.cl/x', 'headers' => [ 'Authorization' => 'Bearer super-secreto' ], 'timeout' => 8, 'retry' => 2 ] ] ),
	'incidents_config' => null, 'survey_config' => null, 'experience_config_json' => null,
] );
$FORM_ID = $wpdb->insert_id;

/* ═══ 1 · EXPORT — contrato canónico ═══════════════════════════════════ */
$env = Estandar_Forms_Template::export( $FORM_ID );
check( 'export: envelope versionado completo', ! is_wp_error( $env )
	&& strpos( (string) $env['$schema'], 'estandar.cl/schemas/forms/template' ) !== false
	&& $env['version'] === Estandar_Forms_Template::SCHEMA_VERSION
	&& ! empty( $env['exported_at'] )
	&& $env['source']['plugin_version'] === ESTANDAR_FORMS_VERSION
	&& is_array( $env['form'] ) );
check( 'export: readonly expone runtime inmutable', $env['readonly']['id'] === $FORM_ID && $env['readonly']['created_at'] !== '' && $env['readonly']['entry_count'] === 7 );
check( 'export: shortcodes derivados visibles', in_array( '[estandar_form slug="form-canonico"]', $env['readonly']['shortcodes'], true ) );

$flat = wp_json_encode( $env );
check( 'secrets: webhook_url nunca expuesto', strpos( $flat, 'secreto-abc123' ) === false && $env['form']['webhook_url'] === Estandar_Forms_Template::SENTINEL_REDACTED );
check( 'secrets: Authorization redactado en actions', strpos( $flat, 'super-secreto' ) === false && $env['form']['actions_json'][0]['headers']['Authorization'] === Estandar_Forms_Template::SENTINEL_REDACTED );
check( 'webhook: config no-secreta preservada (url acción, timeout, retry)', $env['form']['actions_json'][0]['timeout'] === 8 && $env['form']['actions_json'][0]['retry'] === 2 );
check( 'extensions: metadata desconocida elevada a form.extensions', $env['form']['extensions']['x_crm_pipeline'] === 'ventas-2026' && $env['form']['extensions']['x_meta']['owner'] === 'rodrigo' );
check( 'tracking: routing de proyecto exportado', $env['form']['context_schema']['routing']['project_slug'] === 'parronales' );

/* ═══ 2 · ROUND-TRIP — export → apply(update) → export ═════════════════ */
$before = canon( $env );
$result = Estandar_Forms_Template::apply( $env, [ 'mode' => 'update', 'form_id' => $FORM_ID ] );
check( 'roundtrip: apply del export sin cambios semánticos', ! is_wp_error( $result ) && count( $result['changes'] ) === 0 );
$env2 = Estandar_Forms_Template::export( $FORM_ID );
check( 'roundtrip: segundo export semánticamente equivalente', canon( $env2 ) === $before );
check( 'roundtrip: webhook_url intacto en DB (sentinel preservó)', $wpdb->forms[ $FORM_ID ]['webhook_url'] === 'https://n8n.cliente.cl/webhook/secreto-abc123' );
check( 'roundtrip: secreto de actions intacto en DB', strpos( (string) $wpdb->forms[ $FORM_ID ]['actions_json'], 'super-secreto' ) !== false );
check( 'shortcode: id estable tras update', isset( $wpdb->forms[ $FORM_ID ] ) && $wpdb->forms[ $FORM_ID ]['slug'] === 'form-canonico' );

/* ═══ 3 · MERGE — solo claves presentes ═════════════════════════════════ */
$partial = [ '$schema' => Estandar_Forms_Template::SCHEMA_URI, 'version' => '1.1', 'form' => [ 'name' => 'Form Renombrado' ] ];
$result = Estandar_Forms_Template::apply( $partial, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'strategy' => 'merge' ] );
check( 'merge: documento parcial aceptado', ! is_wp_error( $result ) );
check( 'merge: solo name cambió', $wpdb->forms[ $FORM_ID ]['name'] === 'Form Renombrado' );
check( 'merge: campos intactos', strpos( (string) $wpdb->forms[ $FORM_ID ]['fields_json'], 'utm_campaign_snapshot' ) !== false );
check( 'merge: notificaciones intactas', $wpdb->forms[ $FORM_ID ]['notify_emails'] === 'ventas@cliente.cl' );
check( 'merge: autoresponse intacto (subject/body/sender/reply-to)', $wpdb->forms[ $FORM_ID ]['mail_subject'] === 'Nuevo lead {{form_name}}' && $wpdb->forms[ $FORM_ID ]['mail_body'] === 'Hola {{nombre}}' );
check( 'merge: slug preservado sin declararlo', $wpdb->forms[ $FORM_ID ]['slug'] === 'form-canonico' );
check( 'merge: extensions sobreviven', strpos( (string) $wpdb->forms[ $FORM_ID ]['context_schema'], 'x_crm_pipeline' ) !== false );

/* ═══ 4 · UX cruzada — Visual toca routing, JSON metadata sobrevive ════ */
$routing_only = [ '$schema' => Estandar_Forms_Template::SCHEMA_URI, 'version' => '1.1', 'form' => [
	'context_schema' => [ 'routing' => [ 'mode' => 'fixed_project', 'project_slug' => 'altacruz', 'project_name' => 'Altacruz' ] ],
] ];
$result = Estandar_Forms_Template::apply( $routing_only, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'strategy' => 'merge' ] );
$ctx_db = json_decode( (string) $wpdb->forms[ $FORM_ID ]['context_schema'], true );
check( 'cross-editor: routing actualizado', ! is_wp_error( $result ) && $ctx_db['routing']['project_slug'] === 'altacruz' );
check( 'cross-editor: extensions NO destruidas al tocar routing', $ctx_db['extensions']['x_crm_pipeline'] === 'ventas-2026' );

/* ═══ 5 · SLUG — nunca silencioso ═══════════════════════════════════════ */
$slug_doc = Estandar_Forms_Template::export( $FORM_ID );
$slug_doc['form']['slug'] = 'slug-nuevo';
$blocked = Estandar_Forms_Template::apply( $slug_doc, [ 'mode' => 'update', 'form_id' => $FORM_ID ] );
check( 'slug: cambio bloqueado sin confirmación explícita', is_wp_error( $blocked ) && $blocked->get_error_code() === 'json_slug_change_blocked' );
check( 'slug: DB intacta tras bloqueo', $wpdb->forms[ $FORM_ID ]['slug'] === 'form-canonico' );
$allowed = Estandar_Forms_Template::apply( $slug_doc, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'allow_slug_change' => true ] );
check( 'slug: cambio aplicado con allow_slug_change', ! is_wp_error( $allowed ) && $wpdb->forms[ $FORM_ID ]['slug'] === 'slug-nuevo' );
check( 'slug: diff marcó el cambio como destructivo', has_issue( array_map( static function ( $c ) { return [ 'code' => $c['destructive'] ? 'destructive' : 'safe', 'path' => $c['path'] ]; }, $allowed['changes'] ), 'destructive', 'form.slug' ) );
// restaurar
$back = Estandar_Forms_Template::export( $FORM_ID ); $back['form']['slug'] = 'form-canonico';
Estandar_Forms_Template::apply( $back, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'allow_slug_change' => true ] );

/* ═══ 6 · REPLACE — protege runtime y secretos ══════════════════════════ */
$replace_doc = Estandar_Forms_Template::export( $FORM_ID );
unset( $replace_doc['form']['webhook_url'] );             // omisión ≠ borrado de secreto
$replace_doc['form']['notify_emails'] = 'nuevo@cliente.cl';
$result = Estandar_Forms_Template::apply( $replace_doc, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'strategy' => 'replace' ] );
check( 'replace: aplicado', ! is_wp_error( $result ) );
check( 'replace: id inmutable', isset( $wpdb->forms[ $FORM_ID ] ) && $wpdb->forms[ $FORM_ID ]['id'] === $FORM_ID );
check( 'replace: created_at inmutable', $wpdb->forms[ $FORM_ID ]['created_at'] === '2026-07-01 00:00:00' );
check( 'replace: webhook_url NO destruido por omisión', $wpdb->forms[ $FORM_ID ]['webhook_url'] === 'https://n8n.cliente.cl/webhook/secreto-abc123' );
check( 'replace: cambio explícito sí aplica', $wpdb->forms[ $FORM_ID ]['notify_emails'] === 'nuevo@cliente.cl' );

/* ═══ 7 · CREATE / DUPLICATE — nunca sobrescriben ═══════════════════════ */
$create_doc = Estandar_Forms_Template::export( $FORM_ID );
$created = Estandar_Forms_Template::apply( $create_doc, [ 'mode' => 'create' ] );
check( 'create: nuevo formulario con slug único', ! is_wp_error( $created ) && $created['form_id'] !== $FORM_ID && $wpdb->forms[ $created['form_id'] ]['slug'] === 'form-canonico-2' );
check( 'create: original intacto', $wpdb->forms[ $FORM_ID ]['slug'] === 'form-canonico' );
check( 'create: webhook redactado no se instala', (string) $wpdb->forms[ $created['form_id'] ]['webhook_url'] === '' );

$dup = Estandar_Forms_Template::apply( $create_doc, [ 'mode' => 'duplicate' ] );
$dup_row = $wpdb->forms[ $dup['form_id'] ];
check( 'duplicate: nombre (copia) + slug nuevo seguro', ! is_wp_error( $dup ) && strpos( $dup_row['name'], '(copia)' ) !== false && $dup_row['slug'] === 'form-canonico-3' );
check( 'duplicate: configuración lógica preservada', strpos( (string) $dup_row['fields_json'], 'conditional_logic' ) !== false && $dup_row['mail_subject'] === 'Nuevo lead {{form_name}}' );

/* ═══ 8 · CONDICIONALES + HIDDEN sobreviven el ciclo ════════════════════ */
$cf = Estandar_Forms_Template::canonical_form( Estandar_Forms_Template::export( $FORM_ID ) );
$by_name = [];
foreach ( $cf['fields_json'] as $f ) { if ( ! empty( $f['name'] ) ) { $by_name[ $f['name'] ] = $f; } }
check( 'conditional: lógica intacta tras N ciclos', $by_name['detalle']['conditional_logic']['field'] === 'interes' && $by_name['detalle']['conditional_logic']['operator'] === 'equals' );
check( 'hidden: campo de tracking intacto', $by_name['utm_campaign_snapshot']['type'] === 'hidden' );

/* ═══ 9 · VALIDACIÓN — capas 1-3 con rutas exactas ══════════════════════ */
check( 'L1: JSON inválido detectado', is_wp_error( Estandar_Forms_Template::from_json( '{nope' ) ) );

$bad = [ '$schema' => 'https://otro.com/x', 'version' => '9.0', 'form' => [
	'slug' => 'bad', 'form_type' => 'inventado',
	'notify_emails' => 'no-es-email',
	'webhook_url' => 'ftp://x', 'send_webhook' => 1,
	'context_schema' => [ 'routing' => [ 'mode' => 'modo-raro' ] ],
	'fields_json' => [
		[ 'type' => 'text', 'name' => 'nombre' ],
		[ 'type' => 'telefon', 'name' => 'fono' ],
		[ 'type' => 'text', 'name' => 'nombre' ],
		[ 'type' => 'select', 'name' => 'sin_opciones' ],
		[ 'type' => 'text', 'name' => 'cond', 'conditional_logic' => [ 'field' => 'inexistente', 'operator' => 'igualito' ] ],
	],
] ];
$lint = Estandar_Forms_Template::lint( $bad );
check( 'L2: $schema incorrecto', has_issue( $lint['errors'], 'invalid_schema' ) );
check( 'L2: versión no soportada', has_issue( $lint['errors'], 'unsupported_version' ) );
check( 'L2: form.name requerido', has_issue( $lint['errors'], 'missing_name', 'form.name' ) );
check( 'L3: tipo desconocido con ruta exacta', has_issue( $lint['errors'], 'unknown_field_type', 'form.fields_json[1].type' ) );
$allowed_listed = false;
foreach ( $lint['errors'] as $e ) { if ( $e['code'] === 'unknown_field_type' && in_array( 'text', $e['allowed'] ?? [], true ) && in_array( 'tel', $e['allowed'] ?? [], true ) ) { $allowed_listed = true; } }
check( 'L3: valores permitidos listados', $allowed_listed );
check( 'L3: nombre duplicado con referencia cruzada', has_issue( $lint['errors'], 'duplicate_name', 'form.fields_json[2].name' ) );
check( 'L3: select sin options ni dataset', has_issue( $lint['errors'], 'missing_options', 'form.fields_json[3].options' ) );
check( 'L3: referencia condicional rota', has_issue( $lint['errors'], 'broken_condition_reference', 'form.fields_json[4]' ) );
check( 'L3: operador condicional desconocido', has_issue( $lint['errors'], 'unknown_condition_operator', 'form.fields_json[4]' ) );
check( 'L3: email de notificación inválido', has_issue( $lint['errors'], 'invalid_email', 'form.notify_emails' ) );
check( 'L3: webhook URL inválida', has_issue( $lint['errors'], 'invalid_url', 'form.webhook_url' ) );
check( 'L3: routing mode inválido', has_issue( $lint['errors'], 'invalid_routing_mode', 'form.context_schema.routing.mode' ) );
check( 'L3: form_type desconocido', has_issue( $lint['errors'], 'unknown_form_type', 'form.form_type' ) );
check( 'multi-error: todos reportados simultáneamente', count( $lint['errors'] ) >= 10 );

/* ═══ 10 · RUNTIME VALIDATION (L4) — solo formularios ejecutables ═══════ */
$ghost = [ '$schema' => Estandar_Forms_Template::SCHEMA_URI, 'version' => '1.1', 'form' => [
	'name' => 'Fantasma', 'slug' => 'fantasma',
	'fields_json' => [ [ 'type' => 'text', 'name' => '###', 'label' => 'Sin nombre válido' ] ],
] ];
$before_forms = count( $wpdb->forms );
$result = Estandar_Forms_Template::apply( $ghost, [ 'mode' => 'create' ] );
check( 'L4: formulario no ejecutable rechazado sin persistir', is_wp_error( $result ) && $result->get_error_code() === 'json_runtime_validation_failed' && count( $wpdb->forms ) === $before_forms );

/* ═══ 11 · ROLLBACK automático ══════════════════════════════════════════ */
$rb_doc = Estandar_Forms_Template::export( $FORM_ID );
$rb_doc['form']['name'] = 'Intento con rollback';
$fields_before = $wpdb->forms[ $FORM_ID ]['fields_json'];
$name_before   = $wpdb->forms[ $FORM_ID ]['name'];
$wpdb->corrupt_next_reads = true; // la relectura post-persist devuelve un form vacío → revalidación falla
$result = Estandar_Forms_Template::apply( $rb_doc, [ 'mode' => 'update', 'form_id' => $FORM_ID ] );
$wpdb->corrupt_next_reads = false;
check( 'rollback: fallo de revalidación revierte', is_wp_error( $result ) && $result->get_error_code() === 'json_rolled_back' );
check( 'rollback: fila restaurada exacta', $wpdb->forms[ $FORM_ID ]['name'] === $name_before && $wpdb->forms[ $FORM_ID ]['fields_json'] === $fields_before );
$rollback_logged = false;
foreach ( $wpdb->logs as $log ) { if ( $log['event_type'] === 'form_json_rollback' ) { $rollback_logged = true; } }
check( 'rollback: evento auditable registrado', $rollback_logged );

/* ═══ 12 · HISTORIAL — fundación de revisiones ══════════════════════════ */
$history = Estandar_Forms_Template::history( $FORM_ID );
$types = array_column( $history, 'event_type' );
check( 'history: snapshots + aplicaciones registradas', in_array( 'form_snapshot', $types, true ) && in_array( 'form_json_applied', $types, true ) );
$snapshot_env = null;
foreach ( $history as $ev ) { if ( $ev['event_type'] === 'form_snapshot' && ! empty( $ev['context']['envelope'] ) ) { $snapshot_env = $ev['context']['envelope']; break; } }
check( 'history: snapshot contiene envelope completo re-aplicable', is_array( $snapshot_env ) && ! is_wp_error( Estandar_Forms_Template::apply( $snapshot_env, [ 'mode' => 'update', 'form_id' => $FORM_ID, 'allow_slug_change' => true ] ) ) );

/* ═══ 13 · UNKNOWN METADATA — forward compatibility ═════════════════════ */
$future = Estandar_Forms_Template::export( $FORM_ID );
$future['form']['x_ai_scoring'] = [ 'model' => 'v3', 'threshold' => 0.8 ];   // propiedad de un plugin futuro
$future['form']['extensions']['x_flag'] = true;
$lint = Estandar_Forms_Template::lint( $future );
check( 'unknown: lint avisa sin bloquear', empty( $lint['errors'] ) && has_issue( $lint['warnings'], 'unknown_property', 'form.x_ai_scoring' ) );
$result = Estandar_Forms_Template::apply( $future, [ 'mode' => 'update', 'form_id' => $FORM_ID ] );
$re_export = Estandar_Forms_Template::export( $FORM_ID );
check( 'unknown: propiedad futura sobrevive el ciclo completo', ! is_wp_error( $result ) && $re_export['form']['extensions']['x_ai_scoring']['model'] === 'v3' && $re_export['form']['extensions']['x_flag'] === true );

/* ═══ 14 · BACKWARD COMPAT — envelope legado v1.0 ═══════════════════════ */
$legacy = [
	'$schema' => 'https://estandar.cl/schemas/forms/template/v1',
	'version' => '1.0',
	'exported_at' => '2026-06-01T00:00:00+00:00',
	'source' => [ 'plugin' => 'estandar-forms', 'plugin_version' => '4.1.0' ],
	'form' => [
		'name' => 'Backup Histórico', 'slug' => 'backup-historico',
		'submit_label' => 'Enviar', 'success_message' => 'Gracias', 'form_country' => 'CL',
		'store_entries' => 1, 'send_email' => 0, 'send_webhook' => 0, 'require_recaptcha' => 1,
		'form_type' => 'lead', 'form_context' => 'generic', 'request_type' => '',
		'fields_json' => [ [ 'type' => 'text', 'name' => 'nombre', 'label' => 'Nombre' ] ],
		'actions_json' => null, 'context_schema' => null, 'incidents_config' => null,
		'notify_emails' => '', 'mail_subject' => '', 'mail_sender_name' => '', 'mail_reply_to_field' => '',
		'after_submit_text' => '', 'visual_theme' => '', 'webhook_payload_mode' => 'estandar',
		'survey_config' => null, 'experience_config_json' => null,
	],
];
$lint = Estandar_Forms_Template::lint( $legacy );
check( 'compat: backup v1.0 valida (solo warning de versión)', empty( $lint['errors'] ) && has_issue( $lint['warnings'], 'version_drift' ) );
$legacy_id = Estandar_Forms_Template::import( $legacy );
check( 'compat: backup v1.0 importa por el flujo legado', ! is_wp_error( $legacy_id ) && $wpdb->forms[ $legacy_id ]['slug'] === 'backup-historico' );

/* ═══ 15 · DIFF SEMÁNTICO ═══════════════════════════════════════════════ */
$diff_doc = Estandar_Forms_Template::export( $FORM_ID );
$fields_mod = $diff_doc['form']['fields_json'];
foreach ( $fields_mod as $i => $f ) { if ( ( $f['name'] ?? '' ) === 'interes' ) { unset( $fields_mod[ $i ] ); } }  // eliminar campo
$fields_mod[] = [ 'type' => 'tel', 'name' => 'telefono', 'label' => 'Teléfono' ];                                   // agregar campo
$diff_doc['form']['fields_json'] = array_values( $fields_mod );
$diff_doc['form']['mail_subject'] = 'Asunto nuevo';
$current_env = Estandar_Forms_Template::export( $FORM_ID );
$changes = Estandar_Forms_Template::semantic_diff( $current_env, $diff_doc );
$kinds = [];
foreach ( $changes as $c ) { $kinds[ $c['path'] ] = $c; }
check( 'diff: campo eliminado marcado destructivo', isset( $kinds['form.fields_json.interes'] ) && $kinds['form.fields_json.interes']['kind'] === 'removed' && $kinds['form.fields_json.interes']['destructive'] );
check( 'diff: campo agregado no destructivo', isset( $kinds['form.fields_json.telefono'] ) && $kinds['form.fields_json.telefono']['kind'] === 'added' && ! $kinds['form.fields_json.telefono']['destructive'] );
check( 'diff: autoresponse modificado etiquetado', isset( $kinds['form.mail_subject'] ) && strpos( $kinds['form.mail_subject']['label'], 'Asunto' ) !== false );

echo "\n" . ( $failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n" );
exit( $failed === 0 ? 0 : 1 );
