<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Audit {

    /**
     * Get audit log entries for a delivery.
     */
    public static function get_for_delivery(int $delivery_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_audit_log';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT event, ip_address, user_agent, created_at FROM {$table} WHERE delivery_id = %d ORDER BY created_at ASC",
            $delivery_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get recent audit entries.
     */
    public static function recent(int $limit = 50): array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_audit_log';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT delivery_id, event, ip_address, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Get audit summary stats.
     */
    public static function stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_audit_log';
        return [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'by_event' => $wpdb->get_results("SELECT event, COUNT(*) as count FROM {$table} GROUP BY event", ARRAY_A),
            'last_24h' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at > %s", gmdate('Y-m-d H:i:s', time() - 86400))),
        ];
    }
}
