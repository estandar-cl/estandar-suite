<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Token {

    /**
     * Generate a cryptographically secure public token (256-bit entropy).
     */
    public static function generate(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Hash a token for storage in DB.
     */
    public static function hash(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Validate token format.
     */
    public static function validate(string $token): bool {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{32,64}$/', $token);
    }
}
