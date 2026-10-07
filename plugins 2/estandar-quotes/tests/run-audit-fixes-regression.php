<?php
/**
 * Regression tests for bugs found during audit Pass 1-3.
 * Covers: B2 (conditions rendering), B3 (logout CSRF), B6 (return after errors),
 * Gateway operator_email, preview catch return, admin handoff operator_id=0,
 * Document_Definitions profile-level assets, hardcode cleanup.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );

function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return trim( strtolower( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $v ) { return sanitize_key( $v ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
function wp_generate_uuid4() { static $n = 0; return 'uuid-' . ++$n; }
function get_current_user_id() { return 0; }
function absint( $v ) { return abs( (int) $v ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return $v; }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function add_query_arg( $args, $url ) { $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function apply_filters( $hook, $value ) { return $value; }
function add_filter( $h, $cb, $prio = 10, $args = 1 ) {}
function do_action( $hook, ...$args ) {}
function checked( $a, $b, $echo = true ) { return ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; }
function number_format_i18n( $n, $d ) { return number_format( $n, $d, ',', '.' ); }
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['options'][ $k ] = $v; }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function wp_mkdir_p( $v ) { return true; }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }

// Minimal $wpdb for Repository/Session classes
class Audit_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $quotes = array();
	public $sessions = array();
	public $handoffs = array();
	private $next_id = 1;
	public function prepare( $query, ...$args ) { return array_merge( array( $query ), $args ); }
	public function get_charset_collate() { return ''; }
	public function insert( $table, $row ) {
		$row['id'] = ++$this->next_id;
		if ( strpos( $table, 'es_quotes' ) !== false && strpos( $table, 'operator_sessions' ) === false && strpos( $table, 'handoffs' ) === false ) { $this->quotes[ $row['id'] ] = $row; $this->insert_id = $row['id']; }
		elseif ( strpos( $table, 'operator_sessions' ) !== false ) { $this->sessions[ $row['id'] ] = $row; $this->insert_id = $row['id']; }
		elseif ( strpos( $table, 'handoffs' ) !== false ) { $this->handoffs[ $row['id'] ] = $row; $this->insert_id = $row['id']; }
		return true;
	}
	public function get_row( $prepared, $format = null ) {
		if ( is_array( $prepared ) ) { $sql = $prepared[0]; $args = array_slice( $prepared, 1 ); } else { $sql = (string) $prepared; $args = array(); }
		if ( strpos( $sql, 'es_quotes' ) !== false && strpos( $sql, 'WHERE id' ) !== false ) { return $this->quotes[ (int) $args[0] ] ?? null; }
		if ( strpos( $sql, 'operator_sessions' ) !== false ) { foreach ( $this->sessions as $s ) { if ( $s['session_token_hash'] === $args[0] && empty( $s['revoked_at'] ) && $s['expires_at'] > $args[1] ) { return $s; } } }
		if ( strpos( $sql, 'handoffs' ) !== false ) { foreach ( $this->handoffs as $h ) { if ( $h['token_hash'] === ( $args[0] ?? '' ) ) { if ( strpos( $sql, 'consumed_at IS NULL' ) !== false && ! empty( $h['consumed_at'] ) ) { continue; } return $h; } } }
		return null;
	}
	public function get_var( $prepared = null ) { return '0'; }
	public function get_results( $prepared, $format = null ) { $r = $this->get_row( $prepared, $format ); return $r ? array( $r ) : array(); }
	public function update( $table, $values, $where ) {
		$store = null;
		if ( strpos( $table, 'operator_sessions' ) !== false ) { $store =& $this->sessions; }
		if ( strpos( $table, 'es_quotes' ) !== false && strpos( $table, 'operator_sessions' ) === false ) { $store =& $this->quotes; }
		if ( $store ) { foreach ( $store as $id => &$r ) { $match = true; foreach ( $where as $k => $v ) { if ( ( $r[ $k ] ?? null ) != $v ) { $match = false; break; } } if ( $match ) { $store[ $id ] = array_merge( $r, $values ); return 1; } } }
		return 0;
	}
	public function query( $sql_prepared ) {
		if ( is_array( $sql_prepared ) ) { $sql = $sql_prepared[0]; $args = array_slice( $sql_prepared, 1 ); } else { $sql = (string) $sql_prepared; $args = array(); }
		if ( strpos( $sql, 'START TRANSACTION' ) !== false || strpos( $sql, 'COMMIT' ) !== false || strpos( $sql, 'ROLLBACK' ) !== false ) { return true; }
		if ( strpos( $sql, 'UPDATE' ) !== false ) { return 1; }
		return true;
	}
}
$GLOBALS['wpdb'] = new Audit_DB();

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

require dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-calculator.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-real-estate-profile.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-view-model.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-engine.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-form.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-schema.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-state-machine.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-session.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-repository.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-lead-integration.php';

Estandar_Quotes_Real_Estate_Profile::init();
Estandar_Quotes_Acacios_Profile::init();

// ---------------------------------------------------------------------
// B2: Conditions rendering with flat strings
// ---------------------------------------------------------------------
echo "--- B2: Conditions rendering ---\n";

$doc = Estandar_Quotes_Real_Estate_Profile::defaults();
$doc['pricing']['total_uf']['amount'] = 1000;
$doc['uf_rate']['value_clp'] = 40000;
$doc['uf_rate']['effective_date'] = '2026-07-01';
$doc['uf_rate']['source'] = 'manual';
$doc['financing']['annual_rate_percent'] = 4;
$doc['conditions']['items'] = array( 'Linea uno', 'Linea dos', '' );
$normalized = Estandar_Quotes_Real_Estate_Profile::normalize( $doc );
$vm = Estandar_Quotes_View_Model::from_document( Estandar_Quotes_Real_Estate_Profile::KEY, $normalized );
$def = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Real_Estate_Profile::KEY );
$html = Estandar_Quotes_Document_Engine::render_preview( $vm, $def );

check( 'B2: flat-string conditions render without crash', strpos( $html, 'Linea uno' ) !== false && strpos( $html, 'Linea dos' ) !== false );
check( 'B2: empty string skipped, exactly 2 items', substr_count( $html, '<li>' ) === 2 );

$doc2 = Estandar_Quotes_Acacios_Profile::defaults();
$doc2['pricing']['total_uf']['amount'] = 2000;
$doc2['uf_rate']['value_clp'] = 40000;
$doc2['uf_rate']['effective_date'] = '2026-07-01';
$doc2['uf_rate']['source'] = 'manual';
$doc2['financing']['annual_rate_percent'] = 4;
$doc2['conditions']['items'] = array( array( 'text' => 'Estructurada A' ), array( 'text' => 'Estructurada B' ) );
$normalized2 = Estandar_Quotes_Acacios_Profile::normalize( $doc2 );
$vm2 = Estandar_Quotes_View_Model::from_document( Estandar_Quotes_Acacios_Profile::KEY, $normalized2 );
$def2 = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Acacios_Profile::KEY );
$html2 = Estandar_Quotes_Document_Engine::render_preview( $vm2, $def2 );
check( 'B2: structured conditions render correctly', strpos( $html2, 'Estructurada A' ) !== false );

// ---------------------------------------------------------------------
// B3: Logout CSRF
// ---------------------------------------------------------------------
echo "\n--- B3: Logout CSRF ---\n";

$qid = Estandar_Quotes_Repository::create_draft( array( 'profile_key' => 'real_estate@1', 'project_slug' => 'test', 'lead_id' => 1, 'submission_id' => 501, 'lead_snapshot' => array( 'lead' => array( 'name' => 'T' ) ), 'operator_id' => 55, 'created_by' => 0, 'source' => 'test' ) );
$st = Estandar_Quotes_Operator_Session::create( $qid, 55 );
$quote = Estandar_Quotes_Repository::get( $qid );

$valid_csrf = Estandar_Quotes_Operator_Session::action_token( $st, $quote['quote_uuid'], 'logout' );
check( 'B3: valid CSRF verifies', Estandar_Quotes_Operator_Session::verify_action_token( $st, $quote['quote_uuid'], 'logout', $valid_csrf ) );
check( 'B3: garbage rejected', ! Estandar_Quotes_Operator_Session::verify_action_token( $st, $quote['quote_uuid'], 'logout', 'garbage' ) );
check( 'B3: wrong action rejected', ! Estandar_Quotes_Operator_Session::verify_action_token( $st, $quote['quote_uuid'], 'save', $valid_csrf ) );
check( 'B3: wrong quote rejected', ! Estandar_Quotes_Operator_Session::verify_action_token( $st, 'wrong-uuid', 'logout', $valid_csrf ) );

// ---------------------------------------------------------------------
// Source-level assertions
// ---------------------------------------------------------------------
echo "\n--- Source-level checks ---\n";

$gw_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-gateway.php' );
$admin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php' );
$li_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-lead-integration.php' );

// B1: consume_handoff no longer rejects operator_id=0
check( 'B1: operator_id<=0 guard absent from consume_handoff', strpos( $li_src, 'if ( $operator_id <= 0 ) { return array(); }' ) === false );

// B6: maybe_handle() has return after render_error
check( 'B6: maybe_handle block has return after render_error', strpos( $gw_src, "render_error( 'No disponible', 'El sistema de operadores no está disponible.' );\n\t\t\treturn;" ) !== false );

// Gateway send has operator_email
check( 'Gateway send passes operator_email', strpos( $gw_src, "'operator_email'" ) !== false );
check( 'Gateway send reads settings option', strpos( $gw_src, "get_option( 'estandar_quotes_defaults'" ) !== false );

// Preview catch has return
check( 'preview catch block has return', strpos( $gw_src, "return;\n\t\t}" ) !== false );

// Admin handoff uses peeked operator_id
check( 'admin handoff reads peeked op_id', strpos( $admin_src, '$op_id = absint( $peek' ) !== false );
check( 'admin passes op_id to consume_handoff', strpos( $admin_src, 'consume_handoff( $token, $op_id' ) !== false );

// Document_Definitions uses profile-level assets
$dd_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php' );
check( 'DD uses discover_assets', strpos( $dd_src, 'discover_assets' ) !== false );

$aca_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php' );
$re_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-real-estate-profile.php' );

check( 'Acacios profile has document_assets', strpos( $aca_src, 'document_assets' ) !== false );
check( 'Real_Estate profile has document_assets', strpos( $re_src, 'document_assets' ) !== false );
check( 'Acacios assets match expected', Estandar_Quotes_Acacios_Profile::document_assets() === array( 'acacios_reference_plan_ground', 'acacios_reference_plan_upper' ) );
check( 'Real_Estate assets match expected', Estandar_Quotes_Real_Estate_Profile::document_assets() === array( 'reference_plan_ground', 'reference_plan_upper' ) );

$dd_aca = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Acacios_Profile::KEY );
$dd_re = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Real_Estate_Profile::KEY );
check( 'DD Acacios assets from profile', $dd_aca['assets'] === Estandar_Quotes_Acacios_Profile::document_assets() );
check( 'DD Real_Estate assets from profile', $dd_re['assets'] === Estandar_Quotes_Real_Estate_Profile::document_assets() );

// Settings generic
check( 'No "Defaults Acacios" in admin', strpos( $admin_src, 'Defaults Acacios' ) === false );
check( '"Valores predeterminados" present', strpos( $admin_src, 'Valores predeterminados' ) !== false );

// Import sanitization
check( 'import_settings uses sanitize_defaults', strpos( $admin_src, 'sanitize_defaults( $merged' ) !== false );
check( 'import_settings has file size limit', strpos( $admin_src, '512' ) !== false );

echo "\nPASS: $pass / FAIL: $fail\n";
exit( $fail ? 1 : 0 );
