<?php
/**
 * Plugin Name: Estandar WP Exporter
 * Plugin URI: https://www.estandar.cl
 * Description: Exporta plugins y themes instalados como ZIP descargable desde wp-admin, por lotes (AJAX) y con ZIP temporal seguro.
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * License: GPL2+
 * Text Domain: estandar-wp-exporter
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined('ABSPATH') ) exit;

define('ESTANDAR_WPE_VERSION', '13.0.173');
define('ESTANDAR_WPE_SLUG', 'estandar-wp-exporter');

define('ESTANDAR_WPE_PATH', plugin_dir_path(__FILE__));
define('ESTANDAR_WPE_URL', plugin_dir_url(__FILE__));

define('ESTANDAR_WPE_INC', ESTANDAR_WPE_PATH . 'includes/');
define('ESTANDAR_WPE_ADMIN', ESTANDAR_WPE_PATH . 'admin/');
define('ESTANDAR_WPE_ASSETS', ESTANDAR_WPE_URL . 'assets/');

require_once ESTANDAR_WPE_INC . 'bootstrap.php';

register_activation_hook(__FILE__, [ 'Estandar_WPE_Bootstrap', 'activate' ]);
register_deactivation_hook(__FILE__, [ 'Estandar_WPE_Bootstrap', 'deactivate' ]);

add_action('plugins_loaded', function () {
	if ( is_admin() ) {
		Estandar_WPE_Bootstrap::init_admin();
	}
});
