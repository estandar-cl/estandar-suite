<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Schema {

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $deliveries = $prefix . 'estandar_secure_deliveries';
        $audit = $prefix . 'estandar_secure_audit_log';
        $requests = $prefix . 'estandar_secure_requests';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$deliveries} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            token_public VARCHAR(64) NOT NULL,
            secret_cipher LONGTEXT DEFAULT NULL,
            secret_iv VARCHAR(24) DEFAULT NULL,
            secret_tag VARCHAR(24) DEFAULT NULL,
            status ENUM('available','consumed','expired','revoked') NOT NULL DEFAULT 'available',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            revoked_by BIGINT UNSIGNED DEFAULT NULL,
            delivery_count INT UNSIGNED DEFAULT 0,
            requester_email VARCHAR(255) DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_expires (expires_at),
            INDEX idx_created_by (created_by)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_delivery (delivery_id),
            INDEX idx_event (event)
        ) {$charset};");

        dbDelta("CREATE TABLE {$requests} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id BIGINT UNSIGNED DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            status ENUM('pending','fulfilled','rejected','expired') NOT NULL DEFAULT 'pending',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            resolved_by BIGINT UNSIGNED DEFAULT NULL,
            new_delivery_id BIGINT UNSIGNED DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_email (email)
        ) {$charset};");
    }

    public static function drop_tables(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_deliveries");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_audit_log");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}estandar_secure_requests");
    }

    public static function register_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('estandar_secure_share_create');
            $admin->add_cap('estandar_secure_share_manage');
            $admin->add_cap('estandar_secure_share_audit');
        }
    }

    public static function ensure_key_constant(): void {
        if (defined('ESTANDAR_SECURE_SHARE_KEY')) return;
        $key = base64_encode(random_bytes(32));
        add_action('admin_notices', function () use ($key) {
            echo '<div class="notice notice-warning"><p><strong>Estandar Secure Share:</strong> '
                . 'Agrega esta línea a tu <code>wp-config.php</code> para completar la activación:<br>'
                . '<code>define(\'ESTANDAR_SECURE_SHARE_KEY\', \'' . esc_html($key) . '\');</code></p>'
                . '<p>Sin esta clave, los secretos no podrán cifrarse correctamente.</p></div>';
        });
    }

    public static function uninstall(): void {
        $settings = get_option('estandar_secure_share_settings', []);
        $delete_data = $settings['delete_on_uninstall'] ?? true;
        if ($delete_data) {
            self::drop_tables();
            delete_option('estandar_secure_share_settings');
            delete_option('estandar_secure_share_templates');
        }
        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap('estandar_secure_share_create');
            $admin->remove_cap('estandar_secure_share_manage');
            $admin->remove_cap('estandar_secure_share_audit');
        }
        wp_clear_scheduled_hook('estandar_ss_hourly_cleanup');
        flush_rewrite_rules();
    }
}

// Uninstall hook
register_uninstall_hook(__FILE__, ['Estandar_Secure_Share_Schema', 'uninstall']);

// We need to register the uninstall hook from the main plugin file
// This file is included, so we need to ensure the hook fires.
// It's registered here because the schema class is loaded early.
