<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Delivery {

    /**
     * Create a new delivery.
     */
    public static function create(string $secret, int $expires_hours = 24, string $requester_email = ''): array|\WP_Error {
        if (!Estandar_Secure_Share_Crypto::is_ready()) {
            return new \WP_Error('no_key', 'Clave de cifrado no configurada. Agrega ESTANDAR_SECURE_SHARE_KEY a wp-config.php.');
        }

        $token = Estandar_Secure_Share_Token::generate();
        $token_hash = Estandar_Secure_Share_Token::hash($token);
        $encrypted = Estandar_Secure_Share_Crypto::encrypt($secret);

        if (!$encrypted) {
            return new \WP_Error('encrypt_fail', 'Error al cifrar el secreto.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';

        $wpdb->insert($table, [
            'token_hash' => $token_hash,
            'token_public' => $token,
            'secret_cipher' => $encrypted['cipher'],
            'secret_iv' => $encrypted['iv'],
            'secret_tag' => $encrypted['tag'],
            'status' => 'available',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($expires_hours * 3600)),
            'requester_email' => $requester_email ?: null,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        $delivery_id = $wpdb->insert_id;
        self::audit($delivery_id, 'created');

        return [
            'id' => $delivery_id,
            'token' => $token,
            'status' => 'available',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($expires_hours * 3600)),
        ];
    }

    /**
     * Find delivery by public token. Auto-expires if past expiry.
     */
    public static function find_by_token(string $token): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';
        $token_hash = Estandar_Secure_Share_Token::hash($token);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
            $token_hash
        ), ARRAY_A);

        if (!$row) return null;

        // Auto-expire if past time
        if ($row['status'] === 'available' && strtotime($row['expires_at']) < time()) {
            $wpdb->update($table, ['status' => 'expired'], ['id' => $row['id']], ['%s'], ['%d']);
            self::audit($row['id'], 'expired');
            $row['status'] = 'expired';
        }

        // Increment access counter
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET delivery_count = delivery_count + 1 WHERE id = %d",
            $row['id']
        ));

        return $row;
    }

    /**
     * Consume a delivery atomically. Returns decrypted secret or false.
     */
    public static function consume(string $token): string|false {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';
        $token_hash = Estandar_Secure_Share_Token::hash($token);

        // Atomic: update status only if currently 'available'
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'consumed', consumed_at = %s WHERE token_hash = %s AND status = 'available'",
            current_time('mysql'), $token_hash
        ));

        if ($affected === 0 || $affected === false) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT secret_cipher, secret_iv, secret_tag, id FROM {$table} WHERE token_hash = %s",
            $token_hash
        ), ARRAY_A);

        if (!$row) return false;

        self::audit($row['id'], 'consumed');

        $secret = Estandar_Secure_Share_Crypto::decrypt(
            $row['secret_cipher'],
            $row['secret_iv'],
            $row['secret_tag']
        );

        // Destroy ciphertext immediately after reading
        $wpdb->update($table, [
            'secret_cipher' => null,
            'secret_iv' => null,
            'secret_tag' => null,
        ], ['id' => $row['id']], ['%s', '%s', '%s'], ['%d']);

        return $secret !== false ? $secret : false;
    }

    /**
     * Revoke a delivery.
     */
    public static function revoke(int $delivery_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';

        $affected = $wpdb->update($table, [
            'status' => 'revoked',
            'revoked_at' => current_time('mysql'),
            'revoked_by' => get_current_user_id(),
        ], ['id' => $delivery_id], ['%s', '%s', '%d'], ['%d']);

        if ($affected) self::audit($delivery_id, 'revoked');
        return (bool) $affected;
    }

    /**
     * List deliveries with optional filters.
     */
    public static function list(array $filters = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (!empty($filters['created_by'])) {
            $where[] = 'created_by = %d';
            $params[] = $filters['created_by'];
        }

        $sql = "SELECT id, token_public as token, status, created_by, created_at, expires_at, consumed_at, revoked_at, delivery_count, requester_email FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 100";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get pending requests.
     */
    public static function list_requests(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_requests';
        return $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50", ARRAY_A) ?: [];
    }

    /**
     * Create a request for a new delivery.
     */
    public static function create_request(int $delivery_id, string $email): int {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_requests';

        $wpdb->insert($table, [
            'delivery_id' => $delivery_id,
            'email' => $email,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s']);

        self::audit($delivery_id, 'requested');
        return $wpdb->insert_id;
    }

    /**
     * Fulfill a request by creating a new delivery.
     */
    public static function fulfill_request(int $request_id, string $secret, int $expires_hours = 24): void {
        global $wpdb;
        $req_table = $wpdb->prefix . 'estandar_secure_requests';
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$req_table} WHERE id = %d", $request_id), ARRAY_A);

        $delivery = self::create($secret, $expires_hours, $request['email'] ?? '');

        if (!is_wp_error($delivery)) {
            $wpdb->update($req_table, [
                'status' => 'fulfilled',
                'resolved_at' => current_time('mysql'),
                'resolved_by' => get_current_user_id(),
                'new_delivery_id' => $delivery['id'],
            ], ['id' => $request_id], ['%s', '%s', '%d', '%d'], ['%d']);

            if (class_exists('Estandar_Secure_Share_Email')) {
                Estandar_Secure_Share_Email::send_new_delivery($request['email'], $delivery);
            }
        }
    }

    /**
     * Get delivery by ID.
     */
    public static function get(int $id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /**
     * Cleanup expired deliveries (cron).
     */
    public static function cleanup_expired(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_deliveries';
        $settings = get_option('estandar_secure_share_settings', []);
        $retention_days = $settings['retention_days'] ?? 30;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * 86400));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('consumed', 'expired', 'revoked') AND created_at < %s",
            $cutoff
        ));
    }

    /**
     * Record audit event.
     */
    private static function audit(int $delivery_id, string $event): void {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_secure_audit_log';

        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            // Hash IP for privacy
            $ip = substr(hash('sha256', $ip . wp_salt('estandar_ss_ip')), 0, 16);
        }

        $ua = '';
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $ua = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255);
        }

        $wpdb->insert($table, [
            'delivery_id' => $delivery_id,
            'event' => $event,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s']);
    }
}
