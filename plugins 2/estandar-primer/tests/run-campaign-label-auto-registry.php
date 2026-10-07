<?php
/**
 * Run: php tests/run-campaign-label-auto-registry.php
 */

define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function get_option( $name, $default = false ) { global $test_options; return array_key_exists( $name, $test_options ) ? $test_options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $test_options; $test_options[ $name ] = $value; return true; }

class Estandar_Test_WPDB {
	public $prefix = 'wp_';
	public $rows = array();
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $query, $mode = null ) {
		$sql  = is_array( $query ) ? $query['sql'] : $query;
		$args = is_array( $query ) ? ( $query['args'] ?? array() ) : array();
		$type = strpos( $sql, 'campaign_id' ) !== false ? 'campaigns' : ( strpos( $sql, 'adset_id' ) !== false ? 'adsets' : ( strpos( $sql, 'ad_id' ) !== false ? 'ads' : '' ) );
		return $type && isset( $this->rows[ $type ] ) && ( $args[0] ?? '' ) === $this->rows[ $type ]['id'] ? $this->rows[ $type ] : null;
	}
}

$GLOBALS['wpdb'] = new Estandar_Test_WPDB();
$GLOBALS['wpdb']->rows = array(
	'campaigns' => array( 'platform' => 'meta', 'project' => 'altacruz', 'label' => 'Altacruz Campaña', 'id' => 'cmp-1' ),
	'adsets'    => array( 'platform' => 'google', 'project' => 'altacruz', 'label' => 'Altacruz Conjunto', 'id' => 'set-1' ),
	'ads'       => array( 'platform' => 'meta', 'project' => 'altacruz', 'label' => 'Altacruz Anuncio', 'id' => 'ad-1' ),
);
$test_options = array();

require __DIR__ . '/../includes/class-campaign-label-resolver.php';
require __DIR__ . '/../includes/class-campaign-discovery.php';

$ok = array();
$ok[] = Estandar_Campaign_Discovery::maybe_auto_register( 'campaigns', 'cmp-1', 'altacruz' );
$ok[] = Estandar_Campaign_Discovery::maybe_auto_register( 'adsets', 'set-1', 'altacruz' );
$ok[] = Estandar_Campaign_Discovery::maybe_auto_register( 'ads', 'ad-1', 'altacruz' );
$store = Estandar_Campaign_Label_Resolver::get_store();
$ok[] = ( ( $store['campaigns']['cmp-1']['label'] ?? '' ) === 'Altacruz Campaña' );
$ok[] = ( ( $store['adsets']['set-1']['label'] ?? '' ) === 'Altacruz Conjunto' );
$ok[] = ( ( $store['ads']['ad-1']['label'] ?? '' ) === 'Altacruz Anuncio' );
$ok[] = ( $store['campaigns']['cmp-1']['platform'] ?? '' ) === 'meta';
$ok[] = ( $store['adsets']['set-1']['platform'] ?? '' ) === 'google';
$ok[] = ! Estandar_Campaign_Discovery::maybe_auto_register( 'campaigns', 'cmp-1', 'altacruz' );

foreach ( $ok as $i => $pass ) {
	echo ( $pass ? 'PASS' : 'FAIL' ) . ": " . ( $i + 1 ) . "\n";
}

exit( in_array( false, $ok, true ) ? 1 : 0 );
