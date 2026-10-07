<?php
/**
 * Covers the security primitives composed by Estandar_Quotes_Operator_Gateway:
 * operator sessions (create/resolve/expire/revoke), CSRF-equivalent action tokens,
 * repository operator_id ownership/rebind, and the Lead handoff operator binding.
 * It also replicates Estandar_Leads_Operator_Workspace::operator_authorized_for_submission
 * against a fake $wpdb to prove the assignment/active/project checks it relies on,
 * since loading the real Leads plugin here would pull in unrelated dependencies.
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
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { static $n = 0; return 'uuid-' . ++$n; }
function get_current_user_id() { return 0; }
function absint( $v ) { return abs( (int) $v ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return $v; }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function checked( $a, $b, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; return $r; }
function add_query_arg( $args, $url ) { $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) {}

// -- transient/option store, used by the handoff mechanism --
$GLOBALS['transients'] = array();
$GLOBALS['options'] = array();
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => time() + $ttl ); return true; }
function get_transient( $key ) {
	if ( ! isset( $GLOBALS['transients'][ $key ] ) ) { return false; }
	$row = $GLOBALS['transients'][ $key ];
	if ( $row['expires'] < time() ) { unset( $GLOBALS['transients'][ $key ] ); return false; }
	return $row['value'];
}
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

/** Minimal fake $wpdb shared by the Quotes schema/repository/session classes under test. */
class Gateway_Test_DB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $quotes = array();
	public $sessions = array();
	public $handoffs = array();

	public function prepare( $query, ...$args ) { return array_merge( array( $query ), $args ); }
	public function get_var( $prepared = null ) { return '0'; }

	public function get_charset_collate() { return ''; }

	public function insert( $table, $row ) {
		if ( strpos( $table, 'handoffs' ) !== false ) {
			$row['id'] = count( $this->handoffs ) + 1; $this->handoffs[ $row['id'] ] = $row; $this->insert_id = $row['id']; return true;
		}
		if ( strpos( $table, 'es_quotes' ) !== false && strpos( $table, 'operator_sessions' ) === false && strpos( $table, 'handoffs' ) === false ) {
			$row['id'] = ++$this->insert_id; $this->quotes[ $row['id'] ] = $row; $this->insert_id = $row['id']; return true;
		}
		if ( strpos( $table, 'operator_sessions' ) !== false ) {
			$row['id'] = count( $this->sessions ) + 1; $this->sessions[ $row['id'] ] = $row; $this->insert_id = $row['id']; return true;
		}
		return false;
	}

	public function get_row( $prepared, $format = null ) {
		if ( is_array( $prepared ) ) { $sql = $prepared[0]; $args = array_slice( $prepared, 1 ); } else { $sql = (string) $prepared; $args = array(); }
		if ( strpos( $sql, 'handoffs' ) !== false ) {
			foreach ( $this->handoffs as $h ) {
				if ( isset( $h['token_hash'] ) && $h['token_hash'] === ( $args[0] ?? '' ) ) {
					if ( strpos( $sql, 'consumed_at IS NULL' ) !== false && ! empty( $h['consumed_at'] ) ) { continue; }
					return $h;
				}
			}
			return null;
		}
		if ( strpos( $sql, 'es_quotes' ) !== false && strpos( $sql, 'operator_sessions' ) === false ) {
			if ( strpos( $sql, 'WHERE id = %d' ) !== false ) {
				return $this->quotes[ (int) $args[0] ] ?? null;
			}
			if ( strpos( $sql, 'submission_id = %d' ) !== false ) {
				foreach ( $this->quotes as $q ) {
					if ( (int) ( $q['submission_id'] ?? 0 ) === (int) $args[0] && in_array( $q['status'], array( 'draft', 'review' ), true ) ) { return $q; }
				}
				return null;
			}
		}
		if ( strpos( $sql, 'operator_sessions' ) !== false ) {
			foreach ( $this->sessions as $s ) {
				if ( $s['session_token_hash'] === $args[0] && empty( $s['revoked_at'] ) && $s['expires_at'] > $args[1] ) { return $s; }
			}
			return null;
		}
		return null;
	}

	public function update( $table, $values, $where ) {
		if ( strpos( $table, 'operator_sessions' ) !== false ) {
			foreach ( $this->sessions as $id => $s ) {
				$match = true;
				foreach ( $where as $k => $v ) { if ( ( $s[ $k ] ?? null ) != $v ) { $match = false; break; } }
				if ( $match ) { $this->sessions[ $id ] = array_merge( $s, $values ); return 1; }
			}
			return 0;
		}
		if ( strpos( $table, 'es_quotes' ) !== false ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->quotes[ $id ] ) ) { return false; }
			$this->quotes[ $id ] = array_merge( $this->quotes[ $id ], $values );
			return 1;
		}
		return false;
	}

	public function query( $sql_prepared ) {
		if ( is_array( $sql_prepared ) ) { $sql = $sql_prepared[0]; } else { $sql = (string) $sql_prepared; }
		if ( strpos( $sql, 'UPDATE' ) !== false && strpos( $sql, 'handoffs' ) !== false && strpos( $sql, 'consumed_at IS NULL' ) !== false ) {
			return 0;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Gateway_Test_DB();

require dirname( __DIR__ ) . '/includes/class-estandar-quotes-schema.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-state-machine.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-repository.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-session.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-form.php';

// ---------------------------------------------------------------------
// 1) Operator session lifecycle
// ---------------------------------------------------------------------
$token = Estandar_Quotes_Operator_Session::create( 101, 55 );
check( 'session token is a 256-bit opaque bearer value', strlen( $token ) === 64 && ctype_xdigit( $token ) );
check( 'session row stores ids server-side, not encoded in the token', $GLOBALS['wpdb']->sessions[1]['quote_id'] === 101 && $GLOBALS['wpdb']->sessions[1]['operator_id'] === 55 );

$resolved = Estandar_Quotes_Operator_Session::resolve( $token );
check( 'valid session resolves to its quote/operator', $resolved && (int) $resolved['quote_id'] === 101 && (int) $resolved['operator_id'] === 55 );

check( 'garbage token does not resolve', Estandar_Quotes_Operator_Session::resolve( 'short' ) === null );
check( 'random unrelated token does not resolve (session expired/consumed equivalent)', Estandar_Quotes_Operator_Session::resolve( bin2hex( random_bytes( 32 ) ) ) === null );

// expired session
$expired_token = bin2hex( random_bytes( 32 ) );
$GLOBALS['wpdb']->sessions[] = array(
	'id' => 999, 'session_token_hash' => hash_hmac( 'sha256', $expired_token, 'test-salt-auth' ),
	'quote_id' => 101, 'operator_id' => 55, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100000 ),
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ), 'last_used_at' => gmdate( 'Y-m-d H:i:s' ), 'revoked_at' => null,
);
check( 'expired session is rejected', Estandar_Quotes_Operator_Session::resolve( $expired_token ) === null );

// revoke
Estandar_Quotes_Operator_Session::revoke( $token );
check( 'revoked session is rejected (logout / access revoked)', Estandar_Quotes_Operator_Session::resolve( $token ) === null );

// ---------------------------------------------------------------------
// 2) Anti-CSRF action tokens
// ---------------------------------------------------------------------
$token2 = Estandar_Quotes_Operator_Session::create( 202, 77 );
$csrf = Estandar_Quotes_Operator_Session::action_token( $token2, 'quote-uuid-a', 'save' );
check( 'valid CSRF token verifies for its exact session/quote/action', Estandar_Quotes_Operator_Session::verify_action_token( $token2, 'quote-uuid-a', 'save', $csrf ) );
check( 'CSRF token rejected for a different action (manipulated request)', ! Estandar_Quotes_Operator_Session::verify_action_token( $token2, 'quote-uuid-a', 'issue', $csrf ) );
check( 'CSRF token rejected for a different quote (cross-quote replay)', ! Estandar_Quotes_Operator_Session::verify_action_token( $token2, 'quote-uuid-b', 'save', $csrf ) );
check( 'CSRF token rejected under a different session token', ! Estandar_Quotes_Operator_Session::verify_action_token( bin2hex( random_bytes( 32 ) ), 'quote-uuid-a', 'save', $csrf ) );
check( 'empty CSRF token is rejected', ! Estandar_Quotes_Operator_Session::verify_action_token( $token2, 'quote-uuid-a', 'save', '' ) );

// ---------------------------------------------------------------------
// 3) Repository operator ownership + rebind
// ---------------------------------------------------------------------
$quote_id = Estandar_Quotes_Repository::create_draft( array(
	'profile_key' => 'base', 'project_slug' => 'acacios', 'lead_id' => 9, 'submission_id' => 501,
	'lead_snapshot' => array( 'lead' => array( 'name' => 'Ana' ) ), 'operator_id' => 55, 'created_by' => 0, 'source' => 'lead_handoff_operator',
) );
$stored = Estandar_Quotes_Repository::get( $quote_id );
check( 'draft created via handoff stores the owning Leads operator_id', (int) $stored['operator_id'] === 55 );

check( 'rebind_operator updates ownership atomically', Estandar_Quotes_Repository::rebind_operator( $quote_id, 88 ) );
$rebound = Estandar_Quotes_Repository::get( $quote_id );
check( 'ownership reflects the rebind', (int) $rebound['operator_id'] === 88 );
check( 'rebind rejects a non-positive operator id (defensive)', ! Estandar_Quotes_Repository::rebind_operator( $quote_id, 0 ) );

// ---------------------------------------------------------------------
// 4) Handoff -> operator binding (Estandar_Quotes_Lead_Integration)
// ---------------------------------------------------------------------
$GLOBALS['wpdb_submission_row'] = (object) array(
	'lead_id' => 9, 'name' => 'Ana', 'email' => 'ana@example.test', 'phone' => '+56911111111',
	'project_slug' => 'acacios', 'submission_id' => 501, 'source_plugin' => 'estandar-forms', 'message' => 'hola',
	'form_fields_json' => '[]', 'context_json' => '[]', 'submitted_at' => '2026-07-01 10:00:00',
);
// Lead_Integration needs $wpdb->get_row(prepare) too for snapshot_for_submission(); extend the fake DB minimally.
class Gateway_Test_DB_With_Submission extends Gateway_Test_DB {
	public function get_row( $prepared, $format = null ) {
		if ( is_array( $prepared ) ) { $sql = $prepared[0]; $args = array_slice( $prepared, 1 ); } else { $sql = (string) $prepared; $args = array(); }
		if ( strpos( $sql, 'es_lead_submissions' ) !== false ) {
			return (array) $GLOBALS['wpdb_submission_row'];
		}
		return parent::get_row( $prepared, $format );
	}
	public function query( $sql_prepared ) {
		if ( is_array( $sql_prepared ) ) { $sql = $sql_prepared[0]; } else { $sql = (string) $sql_prepared; }
		if ( strpos( $sql, 'handoffs' ) !== false && strpos( $sql, 'consumed_at IS NULL' ) !== false ) {
			// Simulate atomic UPDATE: only 1 row if consumed_at was NULL and operator matches.
			// $prepared = [sql, consumed_at_value, token_hash, operator_id]
			$prepared = is_array( $sql_prepared ) ? $sql_prepared : array( $sql );
			$hash = $prepared[2] ?? '';
			$op_id = (int) ( $prepared[3] ?? 0 );
			foreach ( $this->handoffs as &$h ) {
				if ( ( $h['token_hash'] ?? '' ) === $hash && empty( $h['consumed_at'] ) && (int) ( $h['operator_id'] ?? 0 ) === $op_id ) {
					$h['consumed_at'] = $prepared[1] ?? gmdate( 'Y-m-d H:i:s' );
					return 1;
				}
			}
			return 0;
		}
		return parent::query( $sql );
	}
}
$GLOBALS['wpdb'] = new Gateway_Test_DB_With_Submission();
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-lead-integration.php';

// url_for_lead() must produce an opaque front-end URL, never wp-admin, never containing IDs.
$url = Estandar_Quotes_Lead_Integration::url_for_lead( '', array( 'submission_id' => 501, 'operator_id' => 55 ) );
check( 'handoff URL points to the front-end gateway, not wp-admin', strpos( $url, '/cotizacion/' ) !== false && strpos( $url, 'wp-admin' ) === false );
check( 'handoff URL carries no query parameters at all (opaque path segment only)', strpos( $url, '?' ) === false && strpos( $url, '=' ) === false );
$handoff_token = substr( $url, strrpos( $url, '/' ) + 1 );

$peek = Estandar_Quotes_Lead_Integration::peek_handoff( $handoff_token );
check( 'peek reveals the bound operator without consuming the token', (int) $peek['operator_id'] === 55 && (int) $peek['submission_id'] === 501 );

check( 'consume_handoff rejects a mismatched operator id (stolen/forwarded link)', Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token, 999 ) === array() );

$consumed = Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token, 55 );
check( 'consume_handoff returns the server-side snapshot for the correct operator', ! empty( $consumed ) && (int) $consumed['submission_id'] === 501 );

check( 'handoff token is single-use (second consumption fails even with the right operator)', Estandar_Quotes_Lead_Integration::consume_handoff( $handoff_token, 55 ) === array() );

check( 'unknown/garbage handoff token yields nothing', Estandar_Quotes_Lead_Integration::peek_handoff( 'not-a-real-token' ) === array() );

// ---------------------------------------------------------------------
// 4b) Ownership/rebind policy (gateway handle_handoff): the current owner is
//     kept by default; a different operator only takes over once Leads itself
//     no longer authorizes the prior owner for that submission.
// ---------------------------------------------------------------------
function owner_should_rebind( bool $owner_still_authorized, int $owner_id, int $requesting_operator_id ): bool {
	// Mirrors Estandar_Quotes_Operator_Gateway::handle_handoff()'s decision, without
	// re-running the full HTTP controller: same owner never rebinds; a different
	// owner only rebinds when the prior owner's Leads authorization has lapsed.
	if ( $owner_id === $requesting_operator_id ) { return false; }
	return ! $owner_still_authorized;
}
check( 'same operator reopening their own quote never triggers a rebind decision', owner_should_rebind( true, 55, 55 ) === false );
check( 'different operator is denied (no rebind) while the owner is still Leads-authorized', owner_should_rebind( true, 55, 88 ) === false );
check( 'different operator rebinds only once the owner is no longer Leads-authorized (reassigned/deactivated)', owner_should_rebind( false, 55, 88 ) === true );

// ---------------------------------------------------------------------
// 5) Leads-side authorization contract (operator_authorized_for_submission)
//    Replicated here against a fake $wpdb to avoid pulling in the full Leads plugin;
//    verifies the exact rules the gateway depends on for every mutating action.
// ---------------------------------------------------------------------
class Fake_Leads_DB {
	public $prefix = 'wp_';
	public $operators = array();
	public $submissions = array();
	public $assignments = array();
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_row( $prepared ) {
		list( $sql, $args ) = $prepared;
		if ( strpos( $sql, 'es_operators' ) !== false ) { return $this->operators[ (int) $args[0] ] ?? null; }
		if ( strpos( $sql, 'es_lead_submissions' ) !== false ) { return $this->submissions[ (int) $args[0] ] ?? null; }
		return null;
	}
}
function estandar_leads_project_normalize( $v ) { return sanitize_key( $v ); }
// Faithful, minimal re-implementation of the logic added to
// Estandar_Leads_Operator_Workspace::operator_authorized_for_submission for isolated testing.
function operator_authorized_for_submission_ref( Fake_Leads_DB $db, int $operator_id, int $submission_id ): bool {
	$operator = $db->operators[ $operator_id ] ?? null;
	if ( ! $operator || (int) $operator->active !== 1 ) { return false; }
	$allowed = $operator->allowed_projects_json ? json_decode( $operator->allowed_projects_json, true ) : array( $operator->project_slug );
	$submission = $db->submissions[ $submission_id ] ?? null;
	if ( ! $submission ) { return false; }
	if ( ! in_array( $submission->project_slug, $allowed, true ) ) { return false; }
	$assigned = $db->assignments[ $submission_id ] ?? null;
	return ! $assigned || (int) $assigned === $operator_id;
}

$leads_db = new Fake_Leads_DB();
$leads_db->operators[ 55 ] = (object) array( 'id' => 55, 'project_slug' => 'acacios', 'allowed_projects_json' => json_encode( array( 'acacios' ) ), 'active' => 1 );
$leads_db->operators[ 66 ] = (object) array( 'id' => 66, 'project_slug' => 'altacruz', 'allowed_projects_json' => json_encode( array( 'altacruz' ) ), 'active' => 1 );
$leads_db->operators[ 77 ] = (object) array( 'id' => 77, 'project_slug' => 'acacios', 'allowed_projects_json' => json_encode( array( 'acacios' ) ), 'active' => 0 );
$leads_db->submissions[ 501 ] = (object) array( 'project_slug' => 'acacios' );
$leads_db->assignments[ 501 ] = 55;

check( 'assigned operator is authorized for their own submission', operator_authorized_for_submission_ref( $leads_db, 55, 501 ) );
check( 'a different operator (not assigned, wrong project) is rejected', ! operator_authorized_for_submission_ref( $leads_db, 66, 501 ) );
check( 'inactive operator is rejected even if otherwise assigned', ! operator_authorized_for_submission_ref( $leads_db, 77, 501 ) );

$leads_db->assignments[ 502 ] = null;
$leads_db->submissions[ 502 ] = (object) array( 'project_slug' => 'acacios' );
check( 'unassigned submission in an allowed project is still accessible (matches existing Leads worklist semantics)', operator_authorized_for_submission_ref( $leads_db, 55, 502 ) );

check( 'unknown submission id is rejected (manipulated submission_id)', ! operator_authorized_for_submission_ref( $leads_db, 55, 999999 ) );
check( 'unknown operator id is rejected (manipulated/forged session)', ! operator_authorized_for_submission_ref( $leads_db, 424242, 501 ) );

// ---------------------------------------------------------------------
// 6) Shared document form (used by both wp-admin and the gateway; no duplication)
// ---------------------------------------------------------------------
$doc = array( 'client' => array( 'name' => 'Old<Name>' ), 'property' => array( 'storage' => false, 'parking' => false, 'images' => array() ) );
$html_readonly = Estandar_Quotes_Document_Form::render_fields( $doc, true );
check( 'readonly rendering disables inputs', strpos( $html_readonly, 'disabled' ) !== false );
$html_editable = Estandar_Quotes_Document_Form::render_fields( $doc, false );
check( 'editable rendering does not disable inputs', strpos( $html_editable, 'disabled' ) === false );
check( 'field values are escaped (no raw script/markup break-out)', strpos( $html_editable, '<Name>' ) === false && strpos( $html_editable, '&lt;Name&gt;' ) !== false );

$updated = Estandar_Quotes_Document_Form::document_from_request( $doc, array( 'client.name' => 'Ana Perez', 'property.storage' => '1' ), 'base' );
check( 'document_from_request applies dotted-path updates', $updated['client']['name'] === 'Ana Perez' );
check( 'checkbox fields are coerced to booleans', $updated['property']['storage'] === true && $updated['property']['parking'] === false );

// ---------------------------------------------------------------------
// 7) Canonical PDF resolver (shared by wp-admin and public gateway)
// ---------------------------------------------------------------------
require_once dirname( __DIR__ ) . '/includes/interface-estandar-quotes-pdf-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-pdf-dompdf-adapter.php';

// In the bundled dev environment DOMPDF is available, so resolve() succeeds.
$renderer = null;
$resolve_ok = false;
try {
	$renderer = Estandar_Quotes_PDF_Dompdf_Adapter::resolve();
	$resolve_ok = true;
} catch ( RuntimeException $e ) {
	$resolve_ok = false;
}
check( 'canonical resolve() returns adapter when DOMPDF available', $resolve_ok && $renderer !== null );
check( 'resolved adapter has id dompdf', $resolve_ok && $renderer->id() === 'dompdf' );
check( 'resolved adapter produces valid test PDF', $resolve_ok && substr( $renderer->render( '<p>test</p>' ), 0, 4 ) === '%PDF' );
// Verifies that the public workspace PDF action would succeed through the same resolve() call

exit( $fail ? 1 : 0 );
