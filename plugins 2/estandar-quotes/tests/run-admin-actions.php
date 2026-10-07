<?php
/**
 * Tests for admin actions — source-level and structural checks.
 */

define( 'ABSPATH', __DIR__ );

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS {$name}\n"; }
	else { ++$fail; echo "FAIL {$name}\n"; }
}

require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin-actions.php';

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin-actions.php' );

check( 'class exists', class_exists( 'Estandar_Quotes_Admin_Actions' ) );
check( 'init() method', method_exists( 'Estandar_Quotes_Admin_Actions', 'init' ) );
check( 'handle_admin_create_quote() exists', method_exists( 'Estandar_Quotes_Admin_Actions', 'handle_admin_create_quote' ) );
check( 'handle_admin_send_notification() exists', method_exists( 'Estandar_Quotes_Admin_Actions', 'handle_admin_send_notification' ) );
check( 'operator_portal_actions() exists', method_exists( 'Estandar_Quotes_Admin_Actions', 'operator_portal_actions' ) );

// Security checks
check( 'uses nonce verification', strpos( $src, 'wp_verify_nonce' ) !== false );
check( 'uses capability check', strpos( $src, 'current_user_can' ) !== false );
check( 'uses wp_die on failure', strpos( $src, 'wp_die' ) !== false );

// Create quote flow checks
check( 'create_quote uses HydrationPipeline', strpos( $src, 'Hydration_Pipeline::hydrate_new' ) !== false );
check( 'create_quote uses Repository', strpos( $src, 'Repository::create_draft' ) !== false );
check( 'create_quote uses initialize_draft', strpos( $src, 'initialize_draft' ) !== false );
check( 'create_quote checks existing quote', strpos( $src, 'get_by_submission' ) !== false );
check( 'create_quote detects duplicates', strpos( $src, 'draft_existing' ) !== false );
check( 'create_quote uses snapshot_for_submission', strpos( $src, 'snapshot_for_submission' ) !== false );

// Send notification flow checks
check( 'send_notification checks operator assigned', strpos( $src, 'assigned_operator_id' ) !== false );
check( 'send_notification checks operator email', strpos( $src, 'empty( $operator->email )' ) !== false || strpos( $src, "empty( \$operator->email )" ) !== false );
check( 'send_notification logs override event', strpos( $src, 'operator_notification_manual_admin_override' ) !== false );
check( 'send_notification records admin_user_id', strpos( $src, 'admin_user_id' ) !== false );
check( 'send_notification records reason', strpos( $src, 'manual_admin_override' ) !== false );
check( 'send_notification uses wp_mail directly', strpos( $src, 'wp_mail' ) !== false );
check( 'send_notification uses Leads_Email::render', strpos( $src, 'Leads_Email::render' ) !== false );

// operator_portal_actions checks
check( 'portal actions include send_notification', strpos( $src, 'send_notification' ) !== false );
check( 'portal actions have confirm message', strpos( $src, 'confirm' ) !== false );
check( 'portal actions have quote link', strpos( $src, 'create_quote' ) !== false );
check( 'portal actions check for existing quote', strpos( $src, 'get_by_submission' ) !== false );

// Bootstrap checks
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/estandar-quotes.php' );
check( 'bootstrap loads admin-actions', strpos( $bootstrap, 'class-estandar-quotes-admin-actions.php' ) !== false );
check( 'bootstrap inits Admin_Actions', strpos( $bootstrap, 'Admin_Actions::init()' ) !== false );

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
