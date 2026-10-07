<?php
/** Isolated WordPress/MySQL bootstrap for the Journey deployment gate. */

$wp_root = dirname(__DIR__, 4);
$gate_content = (string) getenv('ESTANDAR_MYSQL_GATE_CONTENT');
if ($gate_content === '' || is_file($gate_content . '/db.php')) {
    echo "SKIP: MySQL gate requires ESTANDAR_MYSQL_GATE_CONTENT without a SQLite db.php drop-in.\n";
    exit(0);
}
$_SERVER['HTTP_HOST'] = 'journey-gate.test';
$_SERVER['SERVER_NAME'] = 'journey-gate.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('ABSPATH', $wp_root . '/');
define('DB_NAME', (string) getenv('ESTANDAR_MYSQL_GATE_DB'));
define('DB_USER', (string) (getenv('ESTANDAR_MYSQL_GATE_USER') ?: 'root'));
define('DB_PASSWORD', (string) getenv('ESTANDAR_MYSQL_GATE_PASSWORD'));
define('DB_HOST', (string) (getenv('ESTANDAR_MYSQL_GATE_HOST') ?: '127.0.0.1'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_CONTENT_DIR', $gate_content);
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_INSTALLING', true);
$table_prefix = 'wp_';

require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (!is_blog_installed()) {
    wp_install('Journey MySQL Gate', 'gate_admin', 'gate@example.test', false, '', 'gate-password');
}

require_once WP_PLUGIN_DIR . '/estandar-leads/estandar-leads.php';
Estandar_Leads_Schema::activate();
if (class_exists('Estandar_Leads_Commercial_Journey')) {
    Estandar_Leads_Commercial_Journey::init();
}
