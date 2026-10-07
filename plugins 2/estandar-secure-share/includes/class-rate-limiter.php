<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Rate_Limiter {

    /**
     * Check rate limit for a given action. Returns true if allowed, false if blocked.
     */
    public static function check(string $action): bool {
        $ip = self::get_ip_hash();

        $limits = [
            'reveal' => ['max' => 10, 'window' => 60],     // 10 per minute
            'request' => ['max' => 3, 'window' => 3600],    // 3 per hour
        ];

        if (!isset($limits[$action])) return true;

        $limit = $limits[$action];
        $key = 'estandar_ss_rl_' . $action . '_' . $ip;

        $count = get_transient($key);
        if ($count === false) {
            set_transient($key, 1, $limit['window']);
            return true;
        }

        if ($count >= $limit['max']) {
            self::maybe_block($ip);
            return false;
        }

        set_transient($key, $count + 1, $limit['window']);
        return true;
    }

    /**
     * Block an IP after too many failed attempts.
     */
    private static function maybe_block(string $ip_hash): void {
        $block_key = 'estandar_ss_blocked_' . $ip_hash;
        $attempts_key = 'estandar_ss_attempts_' . $ip_hash;

        $attempts = get_transient($attempts_key);
        if ($attempts === false) {
            set_transient($attempts_key, 1, 900); // 15 min window
            return;
        }

        set_transient($attempts_key, $attempts + 1, 900);
        if ($attempts + 1 >= 30) {
            set_transient($block_key, 1, 3600); // 1 hour block
        }
    }

    /**
     * Check if IP is blocked.
     */
    public static function is_blocked(): bool {
        $ip = self::get_ip_hash();
        return get_transient('estandar_ss_blocked_' . $ip) !== false;
    }

    private static function get_ip_hash(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return substr(hash('sha256', $ip . wp_salt('estandar_ss_rl')), 0, 12);
    }
}
