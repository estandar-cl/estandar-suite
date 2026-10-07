<?php
/**
 * Validates the generic real_estate@1 profile, profile-key agnosticism,
 * settings defaults, and import/export integrity.
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
function absint( $v ) { return abs( (int) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $v, $d = '' ) { return esc_html( $v ); }
function esc_url( $v ) { return $v; }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $v ) { return $v; }
function number_format_i18n( $n, $d ) { return number_format( $n, $d, ',', '.' ); }
function apply_filters( $h, $v ) { return $v; }
function do_action( $h, ...$a ) {}
function add_filter( $h, $cb, $prio = 10, $args = 1 ) {}
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['options'][ $k ] = $v; }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function wp_generate_uuid4() { static $n = 0; return 'uuid-' . ++$n; }
function get_current_user_id() { return 0; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function wp_verify_nonce( $n, $a ) { return $n === 'nonce-' . $a; }
function checked( $a, $b, $echo = true ) { return ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; }
function selected( $a, $b, $echo = true ) { return ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; }
function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true, $other = array() ) { echo '<button type="submit" class="button button-' . $type . '">' . esc_html( $t ) . '</button>'; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '">'; echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $g . '-options' ) ) . '">'; }
function do_settings_sections( $p ) {}
function register_setting( ...$a ) {}
function add_settings_section( ...$a ) {}
function add_settings_field( ...$a ) {}
function add_action( $h, $cb, $prio = 10, $args = 1 ) {}

$GLOBALS['options'] = array();
$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

// Load the relevant classes
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-calculator.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-real-estate-profile.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-view-model.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php';

// ---------------------------------------------------------------------
// 1) Generic real estate profile registration
// ---------------------------------------------------------------------
Estandar_Quotes_Real_Estate_Profile::init();
Estandar_Quotes_Acacios_Profile::init();
// Our test mock for apply_filters returns input unchanged;
// verify registration directly by calling register().
$profiles = array( 'base' => array( 'version' => '1', 'label' => 'Base' ) );
$profiles = Estandar_Quotes_Real_Estate_Profile::register( $profiles );
$profiles = Estandar_Quotes_Acacios_Profile::register( $profiles );
check( 'generic real_estate@1 is registered', isset( $profiles[ Estandar_Quotes_Real_Estate_Profile::KEY ] ) );
check( 'acacios real estate profile is still registered', isset( $profiles[ Estandar_Quotes_Acacios_Profile::KEY ] ) );
check( 'base profile is still registered', isset( $profiles['base'] ) );

// ---------------------------------------------------------------------
// 2) Generic defaults are tenant-agnostic
// ---------------------------------------------------------------------
$defaults = Estandar_Quotes_Real_Estate_Profile::defaults();
check( 'generic defaults have empty company fields', $defaults['project']['name'] === '' && $defaults['issuer']['authority_name'] === '' );
check( 'generic defaults include empty client', $defaults['client']['name'] === '' && $defaults['client']['email'] === '' );
check( 'generic defaults have document_version', $defaults['document_version'] === Estandar_Quotes_Real_Estate_Profile::KEY );

// ---------------------------------------------------------------------
// 3) Generic defaults with lead snapshot populate client
// ---------------------------------------------------------------------
$lead_snapshot = array(
	'lead' => array( 'name' => 'María García', 'email' => 'maria@example.test', 'phone' => '+56912345678' ),
	'submission' => array( 'message' => 'Quiero una casa' ),
);
$with_lead = Estandar_Quotes_Real_Estate_Profile::defaults( $lead_snapshot );
check( 'generic profile copies lead name into client', $with_lead['client']['name'] === 'María García' );
check( 'generic profile copies lead email into client', $with_lead['client']['email'] === 'maria@example.test' );
check( 'generic profile copies lead phone into client', $with_lead['client']['phone'] === '+56912345678' );
check( 'generic profile captures submission message', $with_lead['capture_context']['message'] === 'Quiero una casa' );

// ---------------------------------------------------------------------
// 4) Generic normalizer validates and computes
// ---------------------------------------------------------------------
$valid_doc = $with_lead;
$valid_doc['pricing']['total_uf']['amount'] = 3000;
$valid_doc['uf_rate']['value_clp'] = 40000;
$valid_doc['uf_rate']['effective_date'] = '2026-07-01';
$valid_doc['uf_rate']['source'] = 'manual';
$valid_doc['validity']['issued_at'] = '2026-07-01';
$valid_doc['financing']['annual_rate_percent'] = 4.5;
$normalized = Estandar_Quotes_Real_Estate_Profile::normalize( $valid_doc );
check( 'generic normalize computes total CLP (3000 UF * 40000 CLP)', $normalized['computed']['total_clp']['amount'] === 120000000.0 );
check( 'generic normalize sets expiry (7 days from issued)', $normalized['validity']['expires_at'] === '2026-07-08' );
check( 'generic normalize produces financing simulations', count( $normalized['computed']['simulations'] ) === 7 );
check( 'generic normalize sets visibility flags', isset( $normalized['visibility']['storage'], $normalized['visibility']['financing'] ) );

// Missing UF rate → defaults apply, validation passes
$bad = $with_lead;
$bad['pricing']['total_uf']['amount'] = 1000;
// Remove UF rate to test defaults kick in
unset( $bad['uf_rate'] );
$normalized2 = Estandar_Quotes_Real_Estate_Profile::normalize( $bad );
check( 'generic normalize uses default UF rate when missing', is_numeric( $normalized2['uf_rate']['value_clp'] ) && $normalized2['uf_rate']['value_clp'] > 0 );
check( 'generic normalize computes with default UF rate', $normalized2['computed']['total_clp']['amount'] > 0 );

// ---------------------------------------------------------------------
// 5) ViewModel supports both profiles
// ---------------------------------------------------------------------
$fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/acacios-real-estate-v1.json' ), true );
$vm_aca = Estandar_Quotes_View_Model::from_document( Estandar_Quotes_Acacios_Profile::KEY, $fixture, array( 'quote_number' => 'Q-A' ) );
check( 'ViewModel works with acacios profile', isset( $vm_aca['contract'], $vm_aca['investment']['total_uf'] ) );

$generic_fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/real-estate-v1.json' ), true );
$vm_re = Estandar_Quotes_View_Model::from_document( Estandar_Quotes_Real_Estate_Profile::KEY, $generic_fixture, array( 'quote_number' => 'Q-G' ) );
check( 'ViewModel works with generic profile', isset( $vm_re['contract'], $vm_re['investment']['total_uf'] ) );

// Unknown profile → rejected
try {
	Estandar_Quotes_View_Model::from_document( 'unknown@1', $generic_fixture );
	$unknown_rejected = false;
} catch ( InvalidArgumentException $e ) {
	$unknown_rejected = true;
}
check( 'ViewModel rejects unknown profile key', $unknown_rejected );

// ---------------------------------------------------------------------
// 6) Document Definitions support both profiles
// ---------------------------------------------------------------------
$def_aca = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Acacios_Profile::KEY );
check( 'DocumentDefinition for acacios has correct id', $def_aca['id'] === 'estandar_quote_document@1' );
check( 'DocumentDefinition for acacios has 7 blocks', count( $def_aca['blocks'] ) === 7 );

$def_re = Estandar_Quotes_Document_Definitions::get( Estandar_Quotes_Real_Estate_Profile::KEY );
check( 'DocumentDefinition for generic has correct id', $def_re['id'] === 'estandar_quote_document@1' );
check( 'DocumentDefinition for generic has 7 blocks', count( $def_re['blocks'] ) === 7 );
check( 'DocumentDefinition for generic has generic asset refs', $def_re['assets'] === array( 'reference_plan_ground', 'reference_plan_upper' ) );

// ---------------------------------------------------------------------
// 7) Settings sanitization
// ---------------------------------------------------------------------
$admin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php' );
check( 'Admin sanitize_defaults includes active_profile', strpos( $admin_src, 'active_profile' ) !== false );
check( 'Admin sanitize_defaults includes company_name', strpos( $admin_src, 'company_name' ) !== false );
check( 'Admin sanitize_defaults includes footer_legal', strpos( $admin_src, 'footer_legal' ) !== false );
check( 'Admin sanitize_defaults includes sender_email', strpos( $admin_src, 'sender_email' ) !== false );
check( 'Admin has active_profile_key() method', strpos( $admin_src, 'active_profile_key' ) !== false );
check( 'Admin uses HydrationPipeline for drafts', strpos( $admin_src, 'Hydration_Pipeline::hydrate_new' ) !== false );

// ---------------------------------------------------------------------
// 8) Fixture for generic profile validates
// ---------------------------------------------------------------------
$fixture_re = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/real-estate-v1.json' ), true );
check( 'generic fixture has real_estate@1 version', $fixture_re['document_version'] === 'real_estate@1' );
check( 'generic fixture uses neutral client data', $fixture_re['client']['email'] === 'cliente@example.test' );
check( 'generic fixture uses neutral asset refs', $fixture_re['property']['images'][0]['asset_ref'] === 'reference_plan_ground' );

// ---------------------------------------------------------------------
// 9) Operator Gateway has configurable profile
// ---------------------------------------------------------------------
$gateway_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-gateway.php' );
check( 'Gateway has active_profile_key() method', strpos( $gateway_src, 'active_profile_key' ) !== false );
check( 'Gateway uses HydrationPipeline for draft hydration', strpos( $gateway_src, 'Hydration_Pipeline::hydrate_new' ) !== false );
check( 'Gateway create_draft uses active_profile_key()', strpos( $gateway_src, 'self::active_profile_key()' ) !== false );

// ---------------------------------------------------------------------
// 10) Bootstrap loads both profiles
// ---------------------------------------------------------------------
$bootstrap_src = file_get_contents( dirname( __DIR__ ) . '/estandar-quotes.php' );
check( 'Bootstrap loads real_estate_profile class', strpos( $bootstrap_src, 'class-estandar-quotes-real-estate-profile.php' ) !== false );
check( 'Bootstrap inits Real_Estate_Profile', strpos( $bootstrap_src, 'Real_Estate_Profile::init()' ) !== false );
check( 'Bootstrap still inits Acacios_Profile', strpos( $bootstrap_src, 'Acacios_Profile::init()' ) !== false );

// ---------------------------------------------------------------------
// 11) Admin export and import hooks
// ---------------------------------------------------------------------
check( 'Admin hooks export_settings', strpos( $admin_src, 'export_settings' ) !== false );
check( 'Admin hooks import_settings', strpos( $admin_src, 'import_settings' ) !== false );
check( 'Admin render_settings shows import/export forms', strpos( $admin_src, 'Exportar / Importar configuración' ) !== false );

echo "\nPASS: $pass / FAIL: $fail\n";
exit( $fail ? 1 : 0 );
