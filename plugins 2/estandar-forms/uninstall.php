<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

global $wpdb;

$forms_table   = $wpdb->prefix . 'estandar_forms';
$entries_table = $wpdb->prefix . 'estandar_form_entries';

$wpdb->query("DROP TABLE IF EXISTS $entries_table");
$wpdb->query("DROP TABLE IF EXISTS $forms_table");

delete_option('estandar_forms_db_version');

$upload_dir = wp_upload_dir();
if ( empty($upload_dir['error']) ) {
    $forms_upload_dir = trailingslashit($upload_dir['basedir']) . 'estandar-forms';

    if ( is_dir($forms_upload_dir) ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($forms_upload_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($forms_upload_dir);
    }
}
