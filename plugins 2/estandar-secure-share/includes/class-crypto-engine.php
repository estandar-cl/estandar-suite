<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Crypto {

    /**
     * Encrypt plaintext using AES-256-GCM.
     * Returns array with ciphertext (base64), iv (base64), tag (base64).
     */
    public static function encrypt(string $plaintext): array|false {
        $key = self::get_key();
        if (!$key || strlen($key) !== 32) return false;

        $iv = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // 128-bit auth tag
        );

        if ($ciphertext === false) return false;

        return [
            'cipher' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Decrypt ciphertext using AES-256-GCM.
     */
    public static function decrypt(string $cipher_b64, string $iv_b64, string $tag_b64): string|false {
        $key = self::get_key();
        if (!$key || strlen($key) !== 32) return false;

        $ciphertext = base64_decode($cipher_b64, true);
        $iv = base64_decode($iv_b64, true);
        $tag = base64_decode($tag_b64, true);

        if ($ciphertext === false || $iv === false || $tag === false) return false;

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext;
    }

    /**
     * Get encryption key. Priority: 1) constant, 2) external file, 3) generate warning.
     */
    private static function get_key(): string|false {
        if (defined('ESTANDAR_SECURE_SHARE_KEY') && !empty(ESTANDAR_SECURE_SHARE_KEY)) {
            $key = base64_decode(ESTANDAR_SECURE_SHARE_KEY, true);
            if ($key && strlen($key) === 32) return $key;
        }

        if (defined('ESTANDAR_SECURE_SHARE_KEY_FILE') && file_exists(ESTANDAR_SECURE_SHARE_KEY_FILE)) {
            $key = base64_decode(trim(file_get_contents(ESTANDAR_SECURE_SHARE_KEY_FILE)), true);
            if ($key && strlen($key) === 32) return $key;
        }

        return false;
    }

    /**
     * Check if encryption key is configured.
     */
    public static function is_ready(): bool {
        return self::get_key() !== false;
    }
}
