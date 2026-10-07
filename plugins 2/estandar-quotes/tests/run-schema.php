<?php
define( 'ABSPATH', __DIR__ . '/' ); define( 'ESTANDAR_QUOTES_SCHEMA_VERSION', '0.1.0' );
function get_option( $k ) { return ''; } function update_option() {} function dbDelta( $sql ) { $GLOBALS['sql'][] = $sql; }
class W { public $prefix = 'wp_'; function get_charset_collate() { return ''; } } $GLOBALS['wpdb'] = new W();
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-schema.php';
Estandar_Quotes_Schema::create_tables(); $sql = implode( "\n", $GLOBALS['sql'] ?? array() );
foreach ( array( 'wp_es_quotes', 'draft_document_json', 'wp_es_quote_revisions', 'wp_es_quote_events', 'UNIQUE KEY quote_revision', 'wp_es_quote_deliveries', 'last_attempt_at', 'cancelled_at', 'operator_id bigint(20) unsigned DEFAULT NULL', 'wp_es_quote_operator_sessions', 'session_token_hash', 'revoked_at' ) as $needle ) { if ( strpos( $sql, $needle ) === false ) { fwrite( STDERR, "FAIL $needle\n" ); exit( 1 ); } echo "PASS $needle\n"; }
