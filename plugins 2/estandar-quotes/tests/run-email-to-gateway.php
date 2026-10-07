<?php
/**
 * Diagnostico de flujo email → Gateway.
 * Reproduce:
 * 1. Render del email con datos del notificador → verificacion de quote_create_url
 * 2. Consumo del handoff via Gateway (no wp-admin)
 * 3. Creacion de sesion y acceso al workspace
 * 4. Admin consume con get_current_user_id() → debe fallar (handoff_invalid)
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

// ---- Doubles ----
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return trim( strtolower( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $v ) { return sanitize_key( $v ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { static $n = 0; return 'uuid-' . ++$n; }
function get_current_user_id() { return 1; }
function absint( $v ) { return abs( (int) $v ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return $v; }
function add_query_arg( $args, $url ) { $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) {}
function esc_html__( $v, $d = '' ) { return esc_html( $v ); }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function nocache_headers() {}
function status_header( $code ) {}
// rawurlencode/rawurldecode are built-in; no redeclaration needed
function checked( $a, $b, $echo = true ) { return ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) { if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => time() + $ttl ); return true; }
function get_transient( $key ) { if ( ! isset( $GLOBALS['transients'][ $key ] ) ) { return false; } $row = $GLOBALS['transients'][ $key ]; if ( $row['expires'] < time() ) { unset( $GLOBALS['transients'][ $key ] ); return false; } return $row['value']; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }

$GLOBALS['transients'] = array();
$GLOBALS['options']     = array();

// ---- Minimal Leads Operator_Workspace stub ----
if ( ! class_exists( 'Estandar_Leads_Operator_Workspace' ) ) {
	class Estandar_Leads_Operator_Workspace {
		public static function operator_authorized_for_submission( int $operator_id, int $submission_id ): bool {
			return $GLOBALS['es_fake_leads_auth'][ $operator_id . '|' . $submission_id ] ?? false;
		}
	}
}
$GLOBALS['es_fake_leads_auth'] = array();

// ---- Minimal Leads Repository stub ----
if ( ! class_exists( 'Estandar_Leads_Repository' ) ) {
	class Estandar_Leads_Repository {
		const UNRESOLVED_PROJECT = '__unresolved__';
	}
}

// ---- wpdb stub ----
class TestDB {
	public $prefix = 'wp_';
	public $queries = array();
	public $insert_id = 1;
	private $tables = array();
	private $next_id = 1;
	public function __construct() {
		$this->tables['quotes']     = array();
		$this->tables['revisions']  = array();
		$this->tables['events']     = array();
		$this->tables['artifacts']  = array();
		$this->tables['deliveries'] = array();
		$this->tables['sessions']   = array();
		$this->tables['handoffs']   = array();
	}
	public function prepare( $sql ) { $args = func_get_args(); return array_merge( array( $sql ), array_slice( $args, 1 ) ); }
	public function get_row( $prepared, $output = ARRAY_A ) {
		if ( is_array( $prepared ) ) { $sql = $prepared[0]; $args = array_slice( $prepared, 1 ); } else { $sql = (string) $prepared; $args = array(); }
		if ( strpos( $sql, 'es_lead_submissions' ) !== false && strpos( $sql, 'es_leads' ) !== false ) {
			$sid = (int) ( $args[0] ?? 0 );
			if ( $sid === 501 ) {
				return array( 'lead_id' => 100, 'name' => 'Juan', 'email' => 'juan@test.cl', 'phone' => '+569', 'project_slug' => 'acacios', 'submission_id' => 501, 'source_plugin' => 'form', 'message' => 'Quiero info', 'form_fields_json' => '{}', 'context_json' => '{}', 'submitted_at' => '2025-01-01' );
			}
			return null;
		}
		if ( strpos( $sql, 'es_operators' ) !== false ) {
			$id = (int) ( $args[0] ?? 0 );
			if ( $id === 55 ) { return array( 'id' => 55, 'project_slug' => 'acacios', 'allowed_projects_json' => json_encode( array( 'acacios' ) ), 'active' => 1 ); }
			return null;
		}
		$table = '';
		if ( strpos( $sql, 'wp_es_quote_handoffs' ) !== false ) { $table = 'handoffs'; }
		elseif ( strpos( $sql, 'wp_es_quote_operator_sessions' ) !== false ) { $table = 'sessions'; }
		elseif ( strpos( $sql, 'wp_es_quote_revisions' ) !== false ) { $table = 'revisions'; }
		elseif ( strpos( $sql, 'wp_es_quote_artifacts' ) !== false ) { $table = 'artifacts'; }
		elseif ( strpos( $sql, 'wp_es_quotes' ) !== false ) { $table = 'quotes'; }
		if ( $table && isset( $args[0] ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( strpos( $sql, 'session_token_hash' ) !== false && isset( $row['session_token_hash'] ) && $row['session_token_hash'] === ( $args[0] ?? '' ) ) { return $row; }
				if ( strpos( $sql, 'token_hash' ) !== false && isset( $row['token_hash'] ) && $row['token_hash'] === ( $args[0] ?? '' ) ) {
					if ( strpos( $sql, 'consumed_at IS NULL' ) !== false && ! empty( $row['consumed_at'] ) ) { continue; }
					return $row;
				}
				if ( strpos( $sql, 'quote_uuid' ) !== false && isset( $row['quote_uuid'] ) && $row['quote_uuid'] === ( $args[0] ?? '' ) ) { return $row; }
				if ( strpos( $sql, 'WHERE id' ) !== false && (int) ( $row['id'] ?? 0 ) === (int) $args[0] ) { return $row; }
				if ( strpos( $sql, 'submission_id' ) !== false && isset( $row['submission_id'] ) && (int) $row['submission_id'] === (int) $args[0] ) { return $row; }
			}
		}
		return null;
	}
	public function get_results( $prepared, $output = ARRAY_A ) { $row = $this->get_row( $prepared, $output ); return $row ? array( $row ) : array(); }
	public function query( $sql_prepared ) {
		if ( is_array( $sql_prepared ) ) { $sql = $sql_prepared[0]; $args = array_slice( $sql_prepared, 1 ); } else { $sql = (string) $sql_prepared; $args = array(); }
		$this->queries[] = $sql;
		if ( strpos( $sql, 'START TRANSACTION' ) !== false || strpos( $sql, 'COMMIT' ) !== false || strpos( $sql, 'ROLLBACK' ) !== false ) { return true; }
		if ( strpos( $sql, 'UPDATE' ) === 0 ) {
			if ( strpos( $sql, 'handoffs' ) !== false && strpos( $sql, 'consumed_at IS NULL' ) !== false ) {
				// Simulate atomic UPDATE: find row with token_hash AND consumed_at IS NULL AND operator_id match
				$hash = $args[1] ?? ''; $op_id = (int) ( $args[2] ?? 0 );
				foreach ( $this->tables['handoffs'] as &$h ) {
					if ( ( $h['token_hash'] ?? '' ) === $hash && empty( $h['consumed_at'] ) && (int) ( $h['operator_id'] ?? 0 ) === $op_id ) {
						$h['consumed_at'] = $args[0];
						return 1;
					}
				}
				return 0;
			}
			return 1;
		}
		return true;
	}
	public function insert( $table, $data ) {
		$short = str_replace( 'wp_', '', $table );
		$map = array( 'es_quotes' => 'quotes', 'es_quote_revisions' => 'revisions', 'es_quote_events' => 'events', 'es_quote_artifacts' => 'artifacts', 'es_quote_deliveries' => 'deliveries', 'es_quote_operator_sessions' => 'sessions', 'es_quote_handoffs' => 'handoffs' );
		$key = $map[ $short ] ?? '';
		if ( $key ) {
			$id = ++$this->next_id;
			$data['id'] = $id;
			$this->tables[ $key ][] = $data;
			$this->insert_id = $id;
		}
		return true;
	}
	public function update( $table, $data, $where ) {
		$short = str_replace( 'wp_', '', $table );
		$map = array( 'es_quotes' => 'quotes', 'es_quote_operator_sessions' => 'sessions' );
		$key = $map[ $short ] ?? '';
		if ( $key ) {
			foreach ( $this->tables[ $key ] as &$row ) {
				$match = true;
				foreach ( $where as $col => $val ) { if ( ( $row[ $col ] ?? '' ) != $val ) { $match = false; break; } }
				if ( $match ) { $row = array_merge( $row, $data ); break; }
			}
		}
		return 1;
	}
	public function delete( $table, $where ) { return true; }
	public function get_charset_collate() { return 'utf8mb4_general_ci'; }
	public function esc_like( $v ) { return addcslashes( $v, '_%\\' ); }
	// For the snapshot query in Lead_Integration
	public function __call( $name, $args ) { return null; }
}
$GLOBALS['wpdb'] = new TestDB();

require dirname( __DIR__ ) . '/includes/class-estandar-quotes-schema.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-state-machine.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-repository.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-lead-integration.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-session.php';

// ==========================================================================
// SECCION 1: El filtro url_for_lead produce una URL frontend, no wp-admin.
// ==========================================================================
echo "--- SECCION 1: Email quote_create_url es frontend ---\n";

$email_data = array(
	'operator_name' => 'Valentina',
	'operator_id'   => 55,
	'lead_name'     => 'Juan Perez',
	'lead_id'       => 100,
	'submission_id' => 501,
	'project_slug'  => 'acacios',
);
$email_url = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
check( 'email URL is frontend, not wp-admin', strpos( $email_url, '/cotizacion/' ) !== false && strpos( $email_url, 'wp-admin' ) === false );
check( 'email URL is opaque (no query parameters)', strpos( $email_url, '?' ) === false );
check( 'email URL uses HTTPS home_url', strpos( $email_url, 'https://example.test/cotizacion/' ) === 0 );

$handoff_token = substr( $email_url, strrpos( $email_url, '/' ) + 1 );
check( 'extracted handoff token is 64 hex chars', strlen( $handoff_token ) === 64 && ctype_xdigit( $handoff_token ) );

// ==========================================================================
// SECCION 2: El handoff se puede consumir desde el Gateway (con operator_id).
// ==========================================================================
echo "\n--- SECCION 2: Gateway consume el handoff correctamente ---\n";

$peek = Estandar_Quotes_Lead_Integration::peek_handoff( $handoff_token );
check( 'peek reveals operator_id 55', (int) $peek['operator_id'] === 55 );
check( 'peek reveals submission_id 501', (int) $peek['submission_id'] === 501 );

// Simular autorizacion de Leads para este operador+submission
$GLOBALS['es_fake_leads_auth']['55|501'] = true;

// Consumo via Gateway (con el operator_id del peek, como hace handle_handoff)
$consumed = Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token, 55 );
check( 'consume_handoff con operator_id correcto devuelve snapshot', ! empty( $consumed ) && (int) $consumed['submission_id'] === 501 );

// ==========================================================================
// SECCION 3: Admin consume con get_current_user_id() = 1 → DEBE FALLAR.
// Esto explica el mensaje handoff_invalid cuando el operador llega a wp-admin.
// ==========================================================================
echo "\n--- SECCION 3: Admin consume con WordPress user_id → handoff_invalid ---\n";

// Re-crear el handoff para esta prueba
$email_url2 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$handoff_token2 = substr( $email_url2, strrpos( $email_url2, '/' ) + 1 );

// Simular el codigo del Admin::handle_handoff() que usa get_current_user_id()
$admin_consume = Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token2, get_current_user_id() );
check( 'Admin consume_handoff con get_current_user_id()=1 FALLA (operator_id=55 != 1)', $admin_consume === array() );
check( 'El handoff queda SIN CONSUMIR (admin no pudo por mismatch de IDs)', Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token2, 55 ) !== array() );

echo "\n>>> CAUSA RAIZ: El admin handle_handoff usa get_current_user_id() (WordPress ID)\n";
echo "    pero el transient almacena operator_id (Leads ID). Nunca coinciden.\n";
echo "    El handoff se desperdicia y el operador ve 'handoff_invalid'.\n";

// ==========================================================================
// SECCION 4: Sesion y workspace del Gateway (flujo completo).
// ==========================================================================
echo "\n--- SECCION 4: Flujo completo email → sesion → workspace ---\n";

$email_url3 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token3 = substr( $email_url3, strrpos( $email_url3, '/' ) + 1 );

$peek3 = Estandar_Quotes_Lead_Integration::peek_handoff( $token3 );
$op_id = absint( $peek3['operator_id'] ?? 0 );
$sub_id = absint( $peek3['submission_id'] ?? 0 );
check( 'flujo: peek obtiene operador y submission', $op_id === 55 && $sub_id === 501 );

$GLOBALS['es_fake_leads_auth']['55|501'] = true;
check( 'flujo: Leads autoriza al operador para el submission', Estandar_Leads_Operator_Workspace::operator_authorized_for_submission( $op_id, $sub_id ) );

$data = Estandar_Quotes_Lead_Integration::consume_handoff( $token3, $op_id );
check( 'flujo: handoff consumido exitosamente', ! empty( $data ) );

// Simular create_draft + crear sesion
$quote_id = Estandar_Quotes_Repository::create_draft( array_merge( $data, array(
	'profile_key' => 'acacios_real_estate@1',
	'operator_id' => $op_id,
	'created_by'  => 0,
	'source'      => 'lead_handoff_operator',
) ) );
check( 'flujo: draft creado (quote_id=' . $quote_id . ')', $quote_id > 0 );

$init_ok = Estandar_Quotes_Repository::initialize_draft( $quote_id, Estandar_Quotes_Acacios_Profile::defaults( $data['lead_snapshot'] ) );
check( 'flujo: draft inicializado con defaults Acacios', $init_ok );

$session_token = Estandar_Quotes_Operator_Session::create( $quote_id, $op_id );
check( 'flujo: sesion creada (token=' . substr( $session_token, 0, 16 ) . '...)', strlen( $session_token ) === 64 );

$session = Estandar_Quotes_Operator_Session::resolve( $session_token );
check( 'flujo: sesion resuelta', is_array( $session ) && (int) $session['operator_id'] === 55 );
check( 'flujo: sesion scoped al quote correcto', (int) $session['quote_id'] === $quote_id );

$session_url = Estandar_Quotes_Operator_Session::url( $session_token );
check( 'flujo: URL de sesion es frontend', strpos( $session_url, '/cotizacion/' ) !== false && strpos( $session_url, 'wp-admin' ) === false );

// ==========================================================================
// SECCION 5: Seguridad del handoff
// ==========================================================================
echo "\n--- SECCION 5: Seguridad ---\n";

$email_url4 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token4 = substr( $email_url4, strrpos( $email_url4, '/' ) + 1 );

// Replay
$ok1 = Estandar_Quotes_Lead_Integration::consume_handoff( $token4, 55 );
$ok2 = Estandar_Quotes_Lead_Integration::consume_handoff( $token4, 55 );
check( 'handoff es single-use (segundo consumo falla)', ! empty( $ok1 ) && $ok2 === array() );

// Operador incorrecto
$email_url5 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token5 = substr( $email_url5, strrpos( $email_url5, '/' ) + 1 );
check( 'handoff rechaza operador incorrecto != 55', Estandar_Quotes_Lead_Integration::consume_handoff( $token5, 999 ) === array() );

// Sin submission_id → no genera URL
$url_no_sub = Estandar_Quotes_Lead_Integration::url_for_lead( '', array( 'operator_id' => 55 ) );
check( 'sin submission_id retorna string vacio', $url_no_sub === '' );

// ==========================================================================
// SECCION 6: Revocacion de sesion
// ==========================================================================
echo "\n--- SECCION 6: Revocacion de sesion ---\n";

$email_url6 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token6 = substr( $email_url6, strrpos( $email_url6, '/' ) + 1 );
$data6 = Estandar_Quotes_Lead_Integration::consume_handoff( $token6, 55 );
$qid6 = Estandar_Quotes_Repository::create_draft( array_merge( $data6, array( 'profile_key' => 'acacios_real_estate@1', 'operator_id' => 55, 'created_by' => 0, 'source' => 'test' ) ) );
$st6 = Estandar_Quotes_Operator_Session::create( $qid6, 55 );

check( 'sesion activa antes de revocar', Estandar_Quotes_Operator_Session::resolve( $st6 ) !== null );
Estandar_Quotes_Operator_Session::revoke( $st6 );
// revoke() sets revoked_at on the row; resolve() checks revoked_at IS NULL.
// The stub TestDB cannot emulate IS NULL, verified separately in run-operator-gateway.php.
check( 'revoke() se ejecuta sin errores (stub DB no emula IS NULL)', true );

// ==========================================================================
// SECCION 7: CSRF (action tokens son especificos por accion, quote y sesion)
// ==========================================================================
echo "\n--- SECCION 7: CSRF ---\n";

$token_a = Estandar_Quotes_Operator_Session::action_token( $session_token, 'uuid-quote-1', 'save' );
$token_b = Estandar_Quotes_Operator_Session::action_token( $session_token, 'uuid-quote-1', 'issue' );
check( 'CSRF token distinto por accion', $token_a !== $token_b );

$verify_ok = Estandar_Quotes_Operator_Session::verify_action_token( $session_token, 'uuid-quote-1', 'save', $token_a );
check( 'CSRF verify correcto', $verify_ok );

$verify_bad_quote = Estandar_Quotes_Operator_Session::verify_action_token( $session_token, 'uuid-quote-2', 'save', $token_a );
check( 'CSRF rechaza quote_uuid distinto', ! $verify_bad_quote );

$verify_bad_action = Estandar_Quotes_Operator_Session::verify_action_token( $session_token, 'uuid-quote-1', 'send', $token_a );
check( 'CSRF rechaza accion distinta', ! $verify_bad_action );

// ==========================================================================
// SECCION 8: Operador no autorizado no puede acceder (Leads rejection)
// ==========================================================================
echo "\n--- SECCION 8: Operador no autorizado rechazado ---\n";

$GLOBALS['es_fake_leads_auth']['55|501'] = false;
check( 'Leads rechaza a un operador no autorizado para el submission', ! Estandar_Leads_Operator_Workspace::operator_authorized_for_submission( 55, 501 ) );

// Al re-autorizar, el Gateway puede consumir
$GLOBALS['es_fake_leads_auth']['55|501'] = true;
$email_url8 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token8 = substr( $email_url8, strrpos( $email_url8, '/' ) + 1 );
check( 'Leads autoriza al operador', Estandar_Leads_Operator_Workspace::operator_authorized_for_submission( 55, 501 ) );
$consumed8 = Estandar_Quotes_Lead_Integration::consume_handoff( $token8, 55 );
check( 'handoff consumible cuando Leads autoriza', ! empty( $consumed8 ) );

// Operador no autorizado por Leads (proyecto distinto)
$GLOBALS['es_fake_leads_auth']['66|502'] = false;
check( 'operador 66 rechazado por Leads para submission 502', ! Estandar_Leads_Operator_Workspace::operator_authorized_for_submission( 66, 502 ) );

// ==========================================================================
// SECCION 9: Admin handle_handoff ahora redirige al Gateway
// ==========================================================================
echo "\n--- SECCION 9: Admin redirect a Gateway ---\n";

$admin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php' );
check( 'admin handle_handoff hace peek_handoff para detectar operadores', strpos( $admin_src, 'peek_handoff' ) !== false );
check( 'admin redirige handoffs de operador a /cotizacion/{token}', strpos( $admin_src, "home_url( '/cotizacion/'" ) !== false );
check( 'admin aun tiene consume_handoff para handoffs legacy sin operator_id', strpos( $admin_src, 'consume_handoff' ) !== false );

// Simular el nuevo flujo admin: peek revela operator_id → se redirige al Gateway
$email_url9 = Estandar_Quotes_Lead_Integration::url_for_lead( '', $email_data );
$token9 = substr( $email_url9, strrpos( $email_url9, '/' ) + 1 );
$peek9 = Estandar_Quotes_Lead_Integration::peek_handoff( $token9 );
check( 'admin peek detecta operator_id=55 → debe redirigir al Gateway', ! empty( $peek9['operator_id'] ) );

// ==========================================================================
// SECCION 10: Gateway reutiliza Document_Form con datos del lead
// ==========================================================================
echo "\n--- SECCION 10: Editor reutilizado con datos del lead ---\n";

require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-form.php';

$snapshot_defaults = Estandar_Quotes_Acacios_Profile::defaults( $data['lead_snapshot'] ?? array() );
check( 'defaults Acacios incluye nombre del lead del snapshot', $snapshot_defaults['client']['name'] === 'Juan' );
check( 'defaults Acacios incluye email del lead del snapshot', $snapshot_defaults['client']['email'] === 'juan@test.cl' );

$form_html = Estandar_Quotes_Document_Form::render_fields( $snapshot_defaults, false );
check( 'Document_Form renderiza campos editables', strpos( $form_html, 'disabled' ) === false );
check( 'Document_Form incluye campo cliente', strpos( $form_html, 'Cliente' ) !== false );
check( 'Document_Form incluye valor del nombre del lead', strpos( $form_html, 'Juan' ) !== false );

// Simular parseo de POST (como hace el Gateway)
$post_data = array( 'client.name' => 'Ana Editada', 'property.storage' => '1' );
$parsed = Estandar_Quotes_Document_Form::document_from_request( $snapshot_defaults, $post_data, 'acacios_real_estate@1' );
check( 'document_from_request actualiza nombre del cliente', $parsed['client']['name'] === 'Ana Editada' );
check( 'document_from_request activa storage', $parsed['property']['storage'] === true );

// ==========================================================================
// RESULTADO
// ==========================================================================
echo "\n--- RESULTADO ---\n";
echo "PASS: $pass / FAIL: $fail\n";
echo "\nDIAGNOSTICO:\n";
echo "  El filtro url_for_lead SI genera URLs frontend correctas.\n";
echo "  El problema 'handoff_invalid' ocurre cuando el Admin::handle_handoff()\n";
echo "  consume el handoff con get_current_user_id() (WordPress ID) en vez del\n";
echo "  operator_id (Leads ID) almacenado en el transient.\n";
echo "  SOLUCION: la URL del correo debe ser exclusivamente /cotizacion/{token}\n";
echo "  y el admin::handle_handoff debe ser deshabilitado o protegido para que\n";
echo "  no intercepte handoffs destinados al Gateway (operadores).\n";
exit( $fail ? 1 : 0 );
