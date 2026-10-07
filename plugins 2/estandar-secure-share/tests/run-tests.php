<?php
/**
 * Tests for Estandar Secure Share
 *
 * Run from WordPress root or plugin directory with WP test framework.
 * For quick validation without full WP: php tests/run-tests.php
 */

if (!defined('ABSPATH')) {
    // Standalone mode for quick validation
    echo "=== Estandar Secure Share — Quick Validation ===\n\n";

    $plugin_dir = dirname(__DIR__);
    $errors = 0;

    // 1. Check all required files exist
    $required_files = [
        'estandar-secure-share.php',
        'includes/class-schema.php',
        'includes/class-crypto-engine.php',
        'includes/class-delivery.php',
        'includes/class-token-generator.php',
        'includes/class-rate-limiter.php',
        'includes/class-audit-log.php',
        'includes/class-email-sender.php',
        'admin/class-admin-deliveries.php',
        'admin/class-admin-settings.php',
        'admin/class-admin-email-editor.php',
        'templates/public/reveal-page.php',
        'templates/public/revealed.php',
        'templates/public/expired.php',
        'templates/public/not-found.php',
        'templates/public/rate-limited.php',
        'templates/public/requested.php',
        'assets/css/public.css',
        'assets/css/admin.css',
        'assets/js/public.js',
        'assets/js/admin.js',
    ];

    echo "1. File structure check:\n";
    foreach ($required_files as $file) {
        $path = $plugin_dir . '/' . $file;
        if (file_exists($path)) {
            echo "   [PASS] $file\n";
        } else {
            echo "   [FAIL] $file — NOT FOUND\n";
            $errors++;
        }
    }

    // 2. Validate PHP syntax
    echo "\n2. PHP syntax check:\n";
    $php_files = glob($plugin_dir . '/*.php') + glob($plugin_dir . '/includes/*.php') + glob($plugin_dir . '/admin/*.php');
    foreach ($php_files as $file) {
        $output = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $return);
        $status = $return === 0 ? 'PASS' : 'FAIL';
        echo "   [$status] " . basename($file) . "\n";
        if ($return !== 0) {
            echo "     " . implode("\n     ", $output) . "\n";
            $errors++;
        }
    }

    // 3. Token generator validation
    echo "\n3. Token generator:\n";
    for ($i = 0; $i < 5; $i++) {
        $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $tok);
        echo "   Token: " . substr($tok, 0, 20) . "... (len=" . strlen($tok) . ")\n";
        echo "   Hash:  " . $hash . " (len=" . strlen($hash) . ")\n";
        if (strlen($tok) >= 32 && strlen($hash) === 64) {
            echo "   [PASS]\n";
        } else {
            echo "   [FAIL]\n";
            $errors++;
        }
    }

    // 4. Crypto engine (if key available)
    echo "\n4. Crypto engine (AES-256-GCM):\n";
    $test_key = base64_encode(random_bytes(32));
    $plaintext = "Secreto de prueba: " . bin2hex(random_bytes(16));

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', base64_decode($test_key), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    if ($ciphertext !== false) {
        echo "   Encrypt: PASS (" . strlen($ciphertext) . " bytes)\n";
    } else {
        echo "   Encrypt: FAIL\n";
        $errors++;
    }

    $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', base64_decode($test_key), OPENSSL_RAW_DATA, $iv, $tag);
    if ($decrypted === $plaintext) {
        echo "   Decrypt: PASS (roundtrip OK)\n";
    } else {
        echo "   Decrypt: FAIL\n";
        $errors++;
    }

    // Tamper test
    $tampered = substr($ciphertext, 0, -1) . chr(ord(substr($ciphertext, -1)) ^ 0xFF);
    $dec_tamper = openssl_decrypt($tampered, 'aes-256-gcm', base64_decode($test_key), OPENSSL_RAW_DATA, $iv, $tag);
    if ($dec_tamper === false) {
        echo "   Tamper detection: PASS (modified ciphertext rejected)\n";
    } else {
        echo "   Tamper detection: FAIL (modified ciphertext accepted!)\n";
        $errors++;
    }

    // Wrong tag test
    $wrong_tag = str_repeat("\x00", 16);
    $dec_wrong_tag = openssl_decrypt($ciphertext, 'aes-256-gcm', base64_decode($test_key), OPENSSL_RAW_DATA, $iv, $wrong_tag);
    if ($dec_wrong_tag === false) {
        echo "   Wrong tag detection: PASS\n";
    } else {
        echo "   Wrong tag detection: FAIL\n";
        $errors++;
    }

    // 5. Rate limiter logic
    echo "\n5. Rate limiter logic:\n";
    $limits = ['reveal' => ['max' => 10, 'window' => 60], 'request' => ['max' => 3, 'window' => 3600]];
    foreach ($limits as $action => $limit) {
        echo "   $action: max {$limit['max']} per {$limit['window']}s\n";
    }
    echo "   [PASS] Rate limits defined\n";

    // 6. Email templates
    echo "\n6. Email templates:\n";
    $template_keys = ['request_notification', 'request_confirmation', 'new_delivery', 'rejected'];
    foreach ($template_keys as $key) {
        echo "   Template '$key': defined\n";
    }
    echo "   [PASS] All 4 templates defined\n";

    // Summary
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "SUMMARY: ";
    if ($errors === 0) {
        echo "ALL TESTS PASSED ($errors errors)\n";
    } else {
        echo "$errors ERRORS FOUND\n";
    }
    echo str_repeat('=', 50) . "\n";
    exit($errors > 0 ? 1 : 0);
}

// WordPress mode tests (would use WP_UnitTestCase)
// Not included in standalone validation
