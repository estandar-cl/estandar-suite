<?php
/**
 * SURVEY_JSON_LIFECYCLE_V1 — regresión del ciclo JSON canónico aplicado a Surveys.
 * Run: php tests/run-survey-json-lifecycle.php
 *
 * Un survey ES una fila de wp_estandar_forms (form_type=survey): reutiliza el
 * MISMO envelope/apply/lint/diff/history del ciclo canónico. Esta suite cubre
 * solo el delta survey-específico (no duplica run-json-lifecycle.php):
 * catálogo nps · reglas survey_config · warning de tipos no-survey · reorden
 * destructivo de preguntas · respuestas históricas jamás tocadas (guard de
 * tabla) · duplicate sin respuestas · round-trip/merge de survey_config.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'ESTANDAR_FORMS_VERSION', '13.0.58-test' );

/* ── WP stubs (idénticos a run-json-lifecycle.php) ─────────────────────── */
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
function current_time( $type ) { return '2026-07-12 12:00:00'; }
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
	public static function clear_form_cache( $form ): void {}
}

/* ── wpdb in-memory con GUARD de respuestas ────────────────────────────── */
class Survey_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $forms = [];
	public $logs  = [];
	public $response_table_writes = 0;   // GARANTÍA: debe permanecer en 0 siempre
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
		if ( strpos( $sql, 'COUNT(*) FROM wp_estandar_form_entries' ) !== false ) { return 12; }
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
		if ( strpos( $table, 'survey_responses' ) !== false ) { $this->response_table_writes++; return false; }
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
		if ( strpos( $table, 'survey_responses' ) !== false ) { $this->response_table_writes++; return false; }
		if ( $table === 'wp_estandar_forms' && isset( $where['id'], $this->forms[ $where['id'] ] ) ) {
			$this->forms[ $where['id'] ] = array_merge( $this->forms[ $where['id'] ], $data );
			return 1;
		}
		return false;
	}

	public function delete( $table, $where, $format = null ) {
		if ( strpos( $table, 'survey_responses' ) !== false ) { $this->response_table_writes++; }
		return false;
	}

	public function query( $sql ) {
		if ( strpos( $sql, 'survey_responses' ) !== false && preg_match( '/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|DROP|ALTER|RENAME)/i', $sql ) ) {
			$this->response_table_writes++;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Survey_Test_DB();

require dirname( __DIR__ ) . '/includes/helpers.php';
require dirname( __DIR__ ) . '/includes/class-template.php';

/* ── harness ───────────────────────────────────────────────────────────── */
$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
function has_issue( array $issues, string $code, string $path_fragment = '' ): bool {
	foreach ( $issues as $issue ) {
		if ( ( $issue['code'] ?? '' ) === $code && ( $path_fragment === '' || strpos( (string) $issue['path'], $path_fragment ) !== false ) ) {
			return true;
		}
	}
	return false;
}

/* ── seed: encuesta completa (preguntas del runtime survey) ────────────── */
$wpdb = $GLOBALS['wpdb'];
$survey_fields = [
	[ 'type' => 'nps',    'name' => 'recommend_score', 'label' => '¿Nos recomendarías?', 'required' => true ],
	[ 'type' => 'rating', 'name' => 'atencion',        'label' => 'Atención recibida' ],
	[ 'type' => 'emoji',  'name' => 'animo',           'label' => '¿Cómo te sentiste?', 'options' => [
		[ 'value' => '1', 'label' => 'Muy malo', 'emoji' => '😞' ],
		[ 'value' => '4', 'label' => 'Excelente', 'emoji' => '😁' ],
	] ],
	[ 'type' => 'text',   'name' => 'comentario',      'label' => 'Comentario' ],
	[ 'type' => 'matrix', 'name' => 'evaluacion',     'label' => 'Evalúa tu experiencia', 'scale' => 7,
	  'label_min' => 'Muy malo', 'label_max' => 'Excelente',
	  'rows' => [
		  [ 'id' => 'row_atencion',       'label' => 'Atención del equipo' ],
		  [ 'id' => 'row_rapidez',         'label' => 'Rapidez en la respuesta' ],
		  [ 'id' => 'row_solucion',        'label' => 'Solución del problema' ],
	  ],
	],
];
$survey_config = [
	'identification_mode' => 'optional',
	'hero_title'          => 'Cuéntanos tu experiencia',
	'hero_subtitle'       => 'Tu opinión nos ayuda a mejorar',
	'hero_cta'            => 'Comenzar',
	'hero_image_url'      => 'https://cdn.cliente.cl/hero.jpg',
	'completion_title'    => '¡Gracias!',
	'completion_message'  => 'Tu respuesta fue registrada.',
];
$wpdb->insert( 'wp_estandar_forms', [
	'name' => 'Encuesta Postventa', 'slug' => 'encuesta-postventa',
	'success_message' => 'Gracias', 'submit_label' => 'Enviar', 'after_submit_text' => '',
	'notify_emails' => 'postventa@cliente.cl', 'form_country' => 'CL',
	'store_entries' => 1, 'send_email' => 0, 'send_webhook' => 0, 'require_recaptcha' => 0,
	'mail_subject' => '', 'mail_body' => '', 'mail_sender_name' => '', 'mail_reply_to_field' => '',
	'visual_theme' => '', 'fields_json' => wp_json_encode( $survey_fields ),
	'webhook_url' => '', 'webhook_payload_mode' => 'estandar',
	'form_type' => 'survey', 'form_context' => 'generic', 'request_type' => '',
	'context_schema' => null, 'actions_json' => null,
	'incidents_config' => null, 'survey_config' => wp_json_encode( $survey_config ), 'experience_config_json' => null,
] );
$SURVEY_ID = $wpdb->insert_id;

/* ═══ 1 · VALIDACIÓN — catálogo survey (nps) ════════════════════════════ */
$env = Estandar_Forms_Template::export( $SURVEY_ID );
check( 'export: survey exporta por el envelope canónico', ! is_wp_error( $env ) && $env['form']['form_type'] === 'survey' && $env['form']['survey_config']['identification_mode'] === 'optional' );

$lint = Estandar_Forms_Template::lint( $env );
check( 'lint: survey con nps/rating/emoji válida sin errores', empty( $lint['errors'] ) );

$non_survey = $env;
$non_survey['form']['form_type'] = 'lead_capture';
$lint = Estandar_Forms_Template::lint( $non_survey );
check( 'lint: nps sigue siendo unknown_field_type fuera de survey (catálogo no relajado)', has_issue( $lint['errors'], 'unknown_field_type', 'fields_json[0].type' ) );

/* ═══ 2 · VALIDACIÓN — reglas survey_config ═════════════════════════════ */
$bad = $env;
$bad['form']['survey_config']['identification_mode'] = 'biometrica';
$bad['form']['survey_config']['hero_image_url'] = 'ftp://x';
$lint = Estandar_Forms_Template::lint( $bad );
check( 'lint: identification_mode inválido con ruta exacta', has_issue( $lint['errors'], 'invalid_identification_mode', 'form.survey_config.identification_mode' ) );
$allowed_listed = false;
foreach ( $lint['errors'] as $e ) { if ( $e['code'] === 'invalid_identification_mode' && in_array( 'anonymous', $e['allowed'] ?? [], true ) ) { $allowed_listed = true; } }
check( 'lint: valores permitidos de identificación listados', $allowed_listed );
check( 'lint: hero_image_url inválida detectada', has_issue( $lint['errors'], 'invalid_url', 'form.survey_config.hero_image_url' ) );

$mixed = $env;
$mixed['form']['fields_json'][] = [ 'type' => 'file', 'name' => 'adjunto', 'label' => 'Adjunto' ];
$lint = Estandar_Forms_Template::lint( $mixed );
check( 'lint: tipo no-survey en encuesta avisa sin bloquear', empty( $lint['errors'] ) && has_issue( $lint['warnings'], 'non_survey_question_type', '.type' ) );

/* ═══ 3 · ROUND-TRIP + MERGE — survey_config sobrevive ══════════════════ */
$before = wp_json_encode( Estandar_Forms_Template::canonical_form( $env ) );
$result = Estandar_Forms_Template::apply( $env, [ 'mode' => 'update', 'form_id' => $SURVEY_ID ] );
check( 'roundtrip: apply del export sin cambios semánticos', ! is_wp_error( $result ) && count( $result['changes'] ) === 0 );
check( 'roundtrip: segundo export equivalente', wp_json_encode( Estandar_Forms_Template::canonical_form( Estandar_Forms_Template::export( $SURVEY_ID ) ) ) === $before );

/* ── matrix: verificar persistencia de filas ───────────────── */
$matrix_export = Estandar_Forms_Template::export( $SURVEY_ID );
$matrix_found  = false;
if ( ! is_wp_error( $matrix_export ) ) {
	$matrix_raw = $matrix_export['form']['fields_json'] ?? [];
	foreach ( $matrix_raw as $mf ) {
		if ( ( $mf['type'] ?? '' ) === 'matrix' && $mf['name'] === 'evaluacion' ) {
			$matrix_found = true;
			$rows_ok = isset( $mf['rows'] ) && count( $mf['rows'] ) === 3;
			$rows_ok = $rows_ok && $mf['rows'][0]['id'] === 'row_atencion';
			$rows_ok = $rows_ok && $mf['rows'][1]['label'] === 'Rapidez en la respuesta';
			$rows_ok = $rows_ok && ( $mf['scale'] ?? 0 ) === 7;
			$rows_ok = $rows_ok && $mf['label_min'] === 'Muy malo';
			$rows_ok = $rows_ok && $mf['label_max'] === 'Excelente';
			check( 'matrix: filas, escala y etiquetas sobreviven al roundtrip', $rows_ok );
			break;
		}
	}
	check( 'matrix: pregunta encontrada en export', $matrix_found );

	// También verificar via canonical_form (decode_fields path)
	$canonical  = Estandar_Forms_Template::canonical_form( $matrix_export );
	$c_fields   = $canonical['fields_json'] ?? [];
	$c_found    = false;
	foreach ( $c_fields as $cf ) {
		if ( ( $cf['type'] ?? '' ) === 'matrix' && $cf['name'] === 'evaluacion' ) {
			$c_found = true;
			$c_ok = isset( $cf['rows'] ) && count( $cf['rows'] ) === 3;
			$c_ok = $c_ok && $cf['scale'] === 7;
			$c_ok = $c_ok && $cf['label_min'] === 'Muy malo';
			check( 'matrix: canonical_form preserva filas y escala', $c_ok );
			break;
		}
	}
	check( 'matrix: canonical_form contiene la pregunta', $c_found );
}

$partial = [ '$schema' => Estandar_Forms_Template::SCHEMA_URI, 'version' => Estandar_Forms_Template::SCHEMA_VERSION, 'form' => [ 'name' => 'Encuesta Renombrada' ] ];
$result = Estandar_Forms_Template::apply( $partial, [ 'mode' => 'update', 'form_id' => $SURVEY_ID, 'strategy' => 'merge' ] );
$row = $wpdb->forms[ $SURVEY_ID ];
check( 'merge: solo name cambió', ! is_wp_error( $result ) && $row['name'] === 'Encuesta Renombrada' );
check( 'merge: survey_config intacto sin declararlo', strpos( (string) $row['survey_config'], 'hero.jpg' ) !== false && strpos( (string) $row['survey_config'], '"identification_mode":"optional"' ) !== false );
check( 'merge: preguntas y form_type intactos', strpos( (string) $row['fields_json'], 'recommend_score' ) !== false && $row['form_type'] === 'survey' );
check( 'merge: slug/shortcode preservados', $row['slug'] === 'encuesta-postventa' );

/* ═══ 4 · REPLACE — identidad protegida ═════════════════════════════════ */
$replace_doc = Estandar_Forms_Template::export( $SURVEY_ID );
$replace_doc['form']['survey_config']['completion_title'] = 'Listo';
$result = Estandar_Forms_Template::apply( $replace_doc, [ 'mode' => 'update', 'form_id' => $SURVEY_ID, 'strategy' => 'replace' ] );
$row = $wpdb->forms[ $SURVEY_ID ];
check( 'replace: aplicado con id/created_at inmutables', ! is_wp_error( $result ) && $row['id'] === $SURVEY_ID && $row['created_at'] === '2026-07-01 00:00:00' );
check( 'replace: cambio explícito de survey_config aplica', strpos( (string) $row['survey_config'], 'Listo' ) !== false );

/* ═══ 5 · DUPLICATE — nunca copia respuestas ════════════════════════════ */
$dup = Estandar_Forms_Template::apply( Estandar_Forms_Template::export( $SURVEY_ID ), [ 'mode' => 'duplicate' ] );
$dup_row = $wpdb->forms[ $dup['form_id'] ];
check( 'duplicate: survey nuevo independiente con slug único', ! is_wp_error( $dup ) && $dup['form_id'] !== $SURVEY_ID && $dup_row['slug'] !== 'encuesta-postventa' && $dup_row['form_type'] === 'survey' );
check( 'duplicate: definición copiada (preguntas + survey_config)', strpos( (string) $dup_row['fields_json'], 'recommend_score' ) !== false && strpos( (string) $dup_row['survey_config'], 'identification_mode' ) !== false );

/* ═══ 6 · DIFF — reorden de preguntas destructivo ═══════════════════════ */
$cur = Estandar_Forms_Template::export( $SURVEY_ID );
$reordered = $cur;
$fields = $reordered['form']['fields_json'];
$reordered['form']['fields_json'] = array_values( array_merge( [ $fields[1] ], [ $fields[0] ], array_slice( $fields, 2 ) ) );
$changes = Estandar_Forms_Template::semantic_diff( $cur, $reordered );
$reorder_flagged = false;
foreach ( $changes as $c ) {
	if ( $c['path'] === 'form.fields_json' && ! empty( $c['destructive'] ) && strpos( $c['label'], 'Orden de preguntas' ) !== false ) { $reorder_flagged = true; }
}
check( 'diff: reorden de preguntas marcado destructivo (respuestas por índice)', $reorder_flagged );

$cur_form = $cur; $cur_form['form']['form_type'] = 'lead_capture';
$reordered_form = $reordered; $reordered_form['form']['form_type'] = 'lead_capture';
$changes = Estandar_Forms_Template::semantic_diff( $cur_form, $reordered_form );
$reorder_flagged = false;
foreach ( $changes as $c ) {
	if ( $c['path'] === 'form.fields_json' && strpos( (string) $c['label'], 'Orden de preguntas' ) !== false ) { $reorder_flagged = true; }
}
check( 'diff: el reorden NO se marca en formularios no-survey', ! $reorder_flagged );

/* ═══ 7 · QUESTION IDS — duplicados detectados, IDs preservados ═════════ */
$dup_q = Estandar_Forms_Template::export( $SURVEY_ID );
$dup_q['form']['fields_json'][] = [ 'type' => 'nps', 'name' => 'recommend_score', 'label' => 'Duplicada' ];
$lint = Estandar_Forms_Template::lint( $dup_q );
check( 'question ids: nombre de pregunta duplicado detectado', has_issue( $lint['errors'], 'duplicate_name' ) );
$names = array_column( Estandar_Forms_Template::export( $SURVEY_ID )['form']['fields_json'], 'name' );
check( 'question ids: nombres estables tras N ciclos (no regenerados)', $names === [ 'recommend_score', 'atencion', 'animo', 'comentario', 'evaluacion' ] );

/* ═══ 8 · GARANTÍA — cero escrituras a respuestas en todo el ciclo ══════ */
check( 'respuestas: NINGUNA escritura a estandar_survey_responses durante export/apply/merge/replace/duplicate/diff', $wpdb->response_table_writes === 0 );

echo "\n" . ( $failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n" );
exit( $failed === 0 ? 0 : 1 );
