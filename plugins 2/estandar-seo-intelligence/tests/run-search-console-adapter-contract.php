<?php
/** Run: php tests/run-search-console-adapter-contract.php */
define( 'ABSPATH', __DIR__ . '/' );
$options = array( 'estandar_seo_search_console' => array( 'site_url' => 'https://example.test/', 'client_id' => 'client-id', 'client_secret' => 'secret-value', 'refresh_token' => 'refresh-value' ) ); $property_response = 200;
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; } function apply_filters( $hook, $value ) { return $value; }
function get_transient() { return false; } function set_transient() {} function delete_transient() {}
function wp_remote_post( $url ) { return array( 'code' => 200, 'body' => '{"access_token":"test-access","expires_in":3600}' ); }
function wp_remote_get( $url ) { global $property_response; return array( 'code' => $property_response, 'body' => $property_response === 200 ? '{"siteEntry":[{"siteUrl":"https://example.test/","permissionLevel":"siteOwner"}]}' : '{"error":{"message":"not authorized"}}' ); }
function is_wp_error() { return false; } function wp_remote_retrieve_response_code( $response ) { return $response['code']; } function wp_remote_retrieve_body( $response ) { return $response['body']; } function wp_json_encode( $value ) { return json_encode( $value ); }
require dirname(__DIR__) . '/includes/class-estandar-search-console-adapter.php';
$failed = 0; function check( $label, $ok ) { global $failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }
$adapter = new Estandar_Search_Console_Adapter(); $valid = $adapter->validate_selected_property(); check( 'valid OAuth connection and authorized property', $valid['success'] && count( $valid['properties'] ) === 1 );
$options['estandar_seo_search_console']['site_url'] = 'https://absent.test/'; $invalid = $adapter->validate_selected_property(); check( 'absent property is rejected', ! $invalid['success'] && strpos( $invalid['message'], 'not authorized' ) !== false );
$property_response = 500; $remote = $adapter->properties(); check( 'remote error is normalized and temporary', ! $remote['success'] && $remote['temporary'] && $remote['message'] === 'not authorized' );
check( 'responses do not expose OAuth secrets', strpos( json_encode( $remote ), 'secret-value' ) === false && strpos( json_encode( $valid ), 'refresh-value' ) === false );
exit( $failed ? 1 : 0 );
