<?php
/**
 * CANONICAL_REDIRECT_V1 — MU fallback loader.
 *
 * Deploy target: wp-content/mu-plugins/estandar-canonical-redirect-loader.php
 * (copy this single file; do NOT copy the whole plugin).
 *
 * Purpose: guarantee Estandar_Canonical_Redirect is loaded from the MU layer
 * (before any normal plugin and long before redirect_canonical) even if
 * Primer's normal plugin load were too late or the plugin were deactivated.
 *
 * Zero logic here: it only locates the existing class file and boots it.
 * If Primer later requires the same file, the class_exists guard makes it a
 * no-op, and boot() re-registering the same static callback is deduplicated
 * by WordPress (identical hook id) — no double execution.
 *
 * @package Estandar_Core_MU
 * @since   CANONICAL_REDIRECT_V1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Canonical_Redirect' ) ) {
	$estandar_canonical_redirect_candidates = array(
		// Primer as a normal plugin (current fleet layout, folder = estandar-primer).
		( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/estandar-primer/includes/class-canonical-redirect.php',
		// Primer copied into mu-plugins (nested legacy layout, new folder name).
		__DIR__ . '/estandar-primer/includes/class-canonical-redirect.php',
		// Legacy layout — folder still named estandar-core-mu (sites not yet redeployed).
		( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/estandar-core-mu/includes/class-canonical-redirect.php',
		__DIR__ . '/estandar-core-mu/includes/class-canonical-redirect.php',
		// Primer flattened into mu-plugins root.
		__DIR__ . '/includes/class-canonical-redirect.php',
	);

	foreach ( $estandar_canonical_redirect_candidates as $estandar_canonical_redirect_file ) {
		if ( file_exists( $estandar_canonical_redirect_file ) ) {
			require_once $estandar_canonical_redirect_file;
			break;
		}
	}

	unset( $estandar_canonical_redirect_candidates, $estandar_canonical_redirect_file );
}

if ( class_exists( 'Estandar_Canonical_Redirect' ) ) {
	Estandar_Canonical_Redirect::boot();
}
