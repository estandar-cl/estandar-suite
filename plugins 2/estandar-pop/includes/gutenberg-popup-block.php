<?php
/**
 * Estandar Pop - Popup Picker Block
 * Bloque Gutenberg para seleccionar y previsualizar popups directamente en la página
 *
 * @package EstandarPop
 * @version 10.5.1
 */

defined('ABSPATH') || exit;

/**
 * Registra scripts ANTES de registrar el bloque
 */
function ep_register_block_assets() {
	// Registrar editor script
	wp_register_script(
		'estandar-pop-block-editor',
		EP_URL . 'assets/js/popup-block-editor.js',
		array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data'),
		EP_VERSION,
		true
	);

	// Registrar editor style
	wp_register_style(
		'estandar-pop-block-editor',
		EP_URL . 'assets/css/popup-block-editor.css',
		array(),
		EP_VERSION
	);

	// ← NUEVO: Registrar frontend script (engine que abre popups)
	wp_register_script(
		'ep-engine',
		EP_URL . 'assets/js/engine.js',
		array(),
		EP_VERSION,
		true
	);

	// ← NUEVO: Registrar frontend style
	wp_register_style(
		'ep-popup-style',
		EP_URL . 'assets/css/popup.css',
		array(),
		EP_VERSION
	);

	// Pasar datos a JavaScript editor
	wp_localize_script(
		'estandar-pop-block-editor',
		'epBlockData',
		array(
			'popups'      => ep_get_popups_for_select(),
			'restUrl'     => rest_url('estandar-pop/v1/'),
			'nonce'       => wp_create_nonce('wp_rest'),
		)
	);
}
add_action('init', 'ep_register_block_assets');

/**
 * Registra el bloque "Popup Picker" en Gutenberg
 */
function ep_register_popup_block() {
	register_block_type(
		'estandar-pop/popup-picker',
		array(
			'apiVersion'       => 3,
			'editor_script'    => 'estandar-pop-block-editor',
			'editor_style'     => 'estandar-pop-block-editor',
			'script'           => 'ep-engine',          // ← NUEVO: Frontend
			'style'            => 'ep-popup-style',     // ← NUEVO: Frontend
			'render_callback'  => 'ep_popup_block_render',
			'attributes'       => array(
				'selectedPopupId' => array(
					'type'    => 'number',
					'default' => 0,
				),
				'displayMode'     => array(
					'type'    => 'string',
					'default' => 'button',
				),
				'buttonText'      => array(
					'type'    => 'string',
					'default' => 'Abrir Popup',
				),
				'showPreview'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);
}
add_action('init', 'ep_register_popup_block');

/**
 * Renderiza el bloque "Popup Picker" en el frontend
 */
function ep_popup_block_render($attributes) {
	$popup_id = intval($attributes['selectedPopupId'] ?? 0);
	$mode = sanitize_text_field($attributes['displayMode'] ?? 'button');
	$button_text = sanitize_text_field($attributes['buttonText'] ?? 'Abrir Popup');

	if (! $popup_id) {
		return '';
	}

	// Verificar que el popup existe
	$popup = get_post($popup_id);
	if (! $popup || 'estandar_pop' !== $popup->post_type) {
		return '';
	}

	// HTML simple y funcional
	if ('button' === $mode) {
		return '<button class="ep-block-button" data-ep-open="' . esc_attr($popup_id) . '" style="padding:10px 20px;background:#007bff;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;">' . esc_html($button_text) . '</button>';
	} else {
		return '<div class="ep-block-banner" data-ep-open="' . esc_attr($popup_id) . '" style="padding:16px;background:#fff3cd;border:2px solid #ffc107;border-radius:4px;text-align:center;cursor:pointer;font-weight:bold;font-size:14px;">' . esc_html($button_text) . '</div>';
	}
}

/**
 * Obtiene listado de popups disponibles para el selector
 */
function ep_get_popups_for_select() {
	$popups = get_posts(
		array(
			'post_type'      => 'estandar_pop',
			'posts_per_page' => -1,
			'post_status'    => array('publish', 'draft'),
			'orderby'        => 'post_title',
			'order'          => 'ASC',
		)
	);

	$options = array();
	foreach ($popups as $popup) {
		$open_method = get_post_meta($popup->ID, '_ep_open_method', true) ?? 'click';
		$options[] = array(
			'value' => $popup->ID,
			'label' => sprintf('%s (ID: %d) — Método: %s', $popup->post_title, $popup->ID, ucfirst($open_method)),
		);
	}

	return $options;
}

/**
 * REST API endpoint para previsualizar popup
 */
function ep_register_popup_preview_route() {
	register_rest_route(
		'estandar-pop/v1',
		'/preview/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'ep_popup_preview_callback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action('rest_api_init', 'ep_register_popup_preview_route');

/**
 * Callback para la ruta REST de previsualización
 */
function ep_popup_preview_callback($request) {
	$popup_id = $request['id'];
	$popup = get_post($popup_id);

	if (! $popup || 'estandar_pop' !== $popup->post_type) {
		return new WP_Error('not_found', 'Popup no encontrado', array('status' => 404));
	}

	return array(
		'id'         => $popup->ID,
		'title'      => $popup->post_title,
		'content'    => apply_filters('the_content', $popup->post_content),
		'openMethod' => get_post_meta($popup->ID, '_ep_open_method', true),
		'selector'   => get_post_meta($popup->ID, '_ep_selector', true),
		'delay'      => get_post_meta($popup->ID, '_ep_delay', true),
		'status'     => $popup->post_status,
	);
}
