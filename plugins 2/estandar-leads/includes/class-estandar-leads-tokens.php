<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Tokens
{
    public static function create($lead_id, $operator_id = 0)
    {
        global $wpdb;

        $raw = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + (21 * DAY_IN_SECONDS));
        $token_hash = self::hash($raw);

        $ok = $wpdb->insert($wpdb->prefix . 'es_feedback_tokens', array(
            'lead_id' => absint($lead_id),
            'operator_id' => $operator_id ? absint($operator_id) : null,
            'token_hash' => $token_hash,
            'expires_at' => $expires,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        error_log(
            '[LOG_CREATE] lead_id=' . absint($lead_id) .
            ' | operator_id=' . ( $operator_id ? absint($operator_id) : 'NULL' ) .
            ' | token_prefix=' . substr( $raw, 0, 12 ) .
            ' | hash_prefix=' . substr( $token_hash, 0, 12 ) .
            ' | insert_ok=' . ( $ok ? 'YES' : 'NO' ) .
            ' | insert_id=' . ( $ok ? $wpdb->insert_id : 'N/A' ) .
            ' | expires_at=[' . $expires . ']'
        );

        return $ok ? $raw : '';
    }

    public static function hash($token)
    {
        return hash_hmac('sha256', (string) $token, Estandar_Leads_Schema::secret());
    }

    public static function url($token)
    {
        $url = add_query_arg(array(
            'estandar_feedback' => '1',
            'token' => $token,
        ), home_url('/'));

        error_log(
            '[LOG_REDIRECT] token_prefix=' . substr( $token, 0, 12 ) .
            ' | url=[' . $url . ']'
        );

        return $url;
    }

    // -------------------------------------------------------------------------
    // LEAD_RESPONSE_ACCELERATION_V1 — stateless link tokens (Fase 2)
    //
    // No DB row: HMAC-signed payload (lead_id, submission_id, resource_type,
    // target_url, expiry) encoded as base64url. Verified with the same
    // Estandar_Leads_Schema::secret() used by hash(). Avoids adding columns
    // to es_feedback_tokens (precedente INTEREST_ASSIGNMENT_V1: cero columnas
    // nuevas).
    // -------------------------------------------------------------------------

    /**
     * Sign a stateless redirect link payload.
     *
     * @param int    $lead_id
     * @param int    $submission_id
     * @param string $resource_type  'pdf' | 'landing' | 'pixel' | 'whatsapp' (sanitize_key'd)
     * @param string $target_url
     * @param int    $ttl_days       Link expiry in days (default 30).
     * @param string $project_slug   Optional, carried through for event metadata
     *                                (autoresponse_project_id) without an extra lookup.
     * @param int    $operator_id    Optional operator id for operator-notification events.
     * @return string  token in the form "<data>.<signature>" (base64url, dot-separated)
     */
    public static function sign_link($lead_id, $submission_id, $resource_type, $target_url, $ttl_days = 30, $project_slug = '', $operator_id = 0)
    {
        $payload = array(
            'lid' => absint($lead_id),
            'sid' => absint($submission_id),
            'rt'  => sanitize_key($resource_type),
            'url' => esc_url_raw((string) $target_url),
            'exp' => time() + (absint($ttl_days) * DAY_IN_SECONDS),
            'ps'  => sanitize_key($project_slug),
            'oid' => absint($operator_id),
        );

        $data = self::base64url_encode((string) wp_json_encode($payload));
        $sig  = self::base64url_encode(hash_hmac('sha256', $data, Estandar_Leads_Schema::secret(), true));

        return $data . '.' . $sig;
    }

    /**
     * Verify a stateless redirect link token.
     *
     * @param string $token
     * @return array{lead_id:int, submission_id:int, resource_type:string, target_url:string}|false
     */
    public static function verify_link($token)
    {
        $token = (string) $token;
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        list($data, $sig) = $parts;
        $expected = self::base64url_encode(hash_hmac('sha256', $data, Estandar_Leads_Schema::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return false;
        }

        $json = self::base64url_decode($data);
        $payload = json_decode((string) $json, true);
        if (!is_array($payload)) {
            return false;
        }

        if (empty($payload['exp']) || time() > (int) $payload['exp']) {
            return false;
        }

        $target_url = esc_url_raw((string) ($payload['url'] ?? ''));
        if ($target_url === '') {
            return false;
        }

        return array(
            'lead_id'       => absint($payload['lid'] ?? 0),
            'submission_id' => absint($payload['sid'] ?? 0),
            'resource_type' => sanitize_key($payload['rt'] ?? ''),
            'target_url'    => $target_url,
            'project_slug'  => sanitize_key($payload['ps'] ?? ''),
            'operator_id'   => absint($payload['oid'] ?? 0),
        );
    }

    public static function verify_whatsapp_tracking($token, $lead_id, $submission_id, $project_slug, $source)
    {
        $verified = self::verify_link($token);

        return is_array($verified)
            && sanitize_key($source) === 'email_autoresponse'
            && ($verified['resource_type'] ?? '') === 'whatsapp'
            && absint($verified['lead_id'] ?? 0) === absint($lead_id)
            && absint($verified['submission_id'] ?? 0) === absint($submission_id)
            && sanitize_key($verified['project_slug'] ?? '') === sanitize_key($project_slug);
    }

    private static function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    private static function base64url_decode($data)
    {
        $data = (string) $data;
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
