<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$delete_data = get_option('estandar_secure_share_settings', [])['delete_on_uninstall'] ?? true;
if ($delete_data) {
    $prefix = $wpdb->prefix;
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_deliveries");
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_audit_log");
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_requests");
}

delete_option('estandar_secure_share_settings');
delete_option('estandar_secure_share_templates');

$admin = get_role('administrator');
if ($admin) {
    $admin->remove_cap('estandar_secure_share_create');
    $admin->remove_cap('estandar_secure_share_manage');
    $admin->remove_cap('estandar_secure_share_audit');
}

wp_clear_scheduled_hook('estandar_ss_hourly_cleanup');
flush_rewrite_rules();
