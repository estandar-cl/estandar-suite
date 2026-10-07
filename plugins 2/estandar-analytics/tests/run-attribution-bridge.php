<?php
/**
 * Run: php tests/run-attribution-bridge.php
 */

define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
function is_admin() { return false; }
function get_option( $key, $default = false ) { return $default; }
function get_transient() { return false; }
function set_transient() { return true; }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function esc_url_raw( $v ) { return (string) $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

class Attribution_Bridge_DB {
	public $prefix = 'wp_';
	public function suppress_errors( $v ) { return false; }
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function get_results( $q, $mode = null ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'SHOW COLUMNS FROM wp_ea_sessions' ) !== false ) {
			return array_map( static function( $field ) {
				return array( 'Field' => $field );
			}, array( 'session_id', 'source', 'medium', 'campaign', 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'google_device', 'placement', 'campaign_type', 'term', 'content', 'gclid', 'gbraid', 'wbraid', 'landing_page' ) );
		}
		return array();
	}
	public function get_row( $q, $mode = null ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_ea_sessions' ) !== false ) {
			return array(
				'session_id' => 'sid-bridge',
				'source' => 'google',
				'medium' => 'cpc',
				'campaign' => 'fallback-name',
				'campaign_id' => 'cmp-777',
				'adgroup_id' => 'ag-7', 'asset_group_id' => 'asset-8', 'creative_id' => 'cr-9',
				'keyword' => 'casa', 'match_type' => 'e', 'network' => 'g', 'google_device' => 'm',
				'placement' => 'example.test', 'campaign_type' => 'search',
				'term' => 'term-a',
				'content' => 'content-a',
				'gclid' => 'gclid-a',
				'gbraid' => 'gbraid-a',
				'wbraid' => 'wbraid-a',
				'landing_page' => 'https://example.test/a',
			);
		}
		return array();
	}
	public function get_var( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_ea_ads_daily' ) !== false ) {
			return 'Campaign Display Name';
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new Attribution_Bridge_DB();
require dirname( __DIR__ ) . '/estandar-analytics.php';

$attr = estandar_analytics_get_attribution_by_session( 'sid-bridge' );
$checks = array(
	'campaign id' => ( $attr['campaign_id'] ?? '' ) === 'cmp-777',
	'campaign name' => ( $attr['campaign_name'] ?? '' ) === 'Campaign Display Name',
	'gbraid kept' => ( $attr['gbraid'] ?? '' ) === 'gbraid-a',
	'wbraid kept' => ( $attr['wbraid'] ?? '' ) === 'wbraid-a',
	'ValueTrack fields kept' => ( $attr['adgroup_id'] ?? '' ) === 'ag-7' && ( $attr['asset_group_id'] ?? '' ) === 'asset-8' && ( $attr['creative_id'] ?? '' ) === 'cr-9' && ( $attr['device'] ?? '' ) === 'm',
);
$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
exit( $failed === 0 ? 0 : 1 );
