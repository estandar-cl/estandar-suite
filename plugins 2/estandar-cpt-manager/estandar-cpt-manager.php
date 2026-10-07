<?php
/**
 * Plugin Name: Estandar CPT Manager
 * Plugin URI: https://www.estandar.cl
 * Description: Crea CPTs simples tipo paginas desde wp-admin + plantillas automaticas (fallback).
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'egm_img_attrs_lazy' ) ) {
    function egm_img_attrs_lazy(): array {
        return [
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];
    }
}

if ( ! function_exists( 'egm_patch_cover_lazy_attrs' ) ) {
    function egm_patch_cover_lazy_attrs( $content ) {
        if ( is_admin() ) {
            return $content;
        }

        $is_producto          = is_singular( 'producto' );
        $is_producto_archive  = is_post_type_archive( 'producto' );
        $is_producto_category = is_category() && 'producto' === sanitize_key( (string) get_query_var( 'post_type' ) );

        if ( ! $is_producto && ! $is_producto_archive && ! $is_producto_category ) {
            return $content;
        }

        if ( ! is_string( $content ) || false === strpos( $content, 'wp-block-cover__image-background' ) ) {
            return $content;
        }

        $pattern = '/<img\\b[^>]*\\bclass=(\"|\\\')([^\"\\\']*\\bwp-block-cover__image-background\\b[^\"\\\']*)\\1[^>]*>/i';
        return preg_replace_callback(
            $pattern,
            static function ( $matches ) {
                $img = $matches[0];
                $attrs_to_add = [];

                if ( false === stripos( $img, 'loading=' ) ) {
                    $attrs_to_add[] = 'loading="lazy"';
                }
                if ( false === stripos( $img, 'decoding=' ) ) {
                    $attrs_to_add[] = 'decoding="async"';
                }

                if ( empty( $attrs_to_add ) ) {
                    return $img;
                }

                return preg_replace( '/<img\\b/i', '<img ' . implode( ' ', $attrs_to_add ), $img, 1 );
            },
            $content
        );
    }
}

		class Estandar_CPT_Manager {

    const VERSION = '13.0.17';
    const OPT_KEY = 'estandar_cpt_manager_defs';
    const CAP     = 'manage_options';
    const TERM_IMAGE_META = '_estandar_category_featured_image_id';
    const PRODUCT_TEMPLATE_OPT_KEY = 'estandar_producto_template_settings';
    const PRODUCT_TEMPLATE_POST_ID_OPT_KEY = 'estandar_producto_template_post_id';
    // `wp_posts.post_type` is VARCHAR(20) in WordPress core schema.
    // Keep this <= 20 chars or wp_insert_post() can fail with DB errors.
    const PRODUCT_TEMPLATE_POST_TYPE = 'egm_prod_tpl';
    const PRODUCT_TEMPLATE_LAST_ERROR_TRANSIENT = 'estandar_producto_template_last_error';
    const PRODUCT_TEMPLATE_META_KEY = '_egm_producto_template_id';
    const TEMPLATE_SCHEMA_META_KEY = '_egm_tpl_schema_v1';
    const PRODUCT_TEMPLATE_FIELDS_META_KEY = '_egm_tpl_fields';
    const PRODUCT_TEMPLATE_ATTRS_META_KEY = '_egm_tpl_attrs';
	    const PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION = 'egm_tpl_preview';
	    // Per-product (post meta) section overrides for internal navigation/titles.
	    // Stored as an array, e.g. [ 'features' => [ 'active' => 1, 'title' => '...', 'anchor' => '...' ], ... ].
	    const PRODUCT_SECTIONS_META_KEY = '_estandar_cpt_sections_v1';

    // Category archive template (for categorias when filtering productos via ?post_type=producto).
    // Keep <= 20 chars for wp_posts.post_type schema.
    const CATEGORY_TEMPLATE_POST_TYPE = 'egm_cat_tpl';
    const CATEGORY_TEMPLATE_POST_ID_OPT_KEY = 'estandar_categoria_template_post_id';
    const CATEGORY_TEMPLATE_LAST_ERROR_TRANSIENT = 'estandar_categoria_template_last_error';
    const CATEGORY_TEMPLATE_TERM_META_KEY = '_egm_categoria_template_id';
    const CATEGORY_TEMPLATE_TERM_FIELDS_META_KEY = '_egm_cat_tpl_fields';
    const CATEGORY_TEMPLATE_TERM_ATTRS_META_KEY = '_egm_cat_tpl_attrs';

    /** @var array<int,array> */
    private static $preview_field_overrides = [];
    /** @var null|array{type:string,id:int,values:array,attrs:array} */
    private static $dynamic_render_context = null;

		    public static function boot() {
        add_action( 'init', [ __CLASS__, 'register_product_template_post_type' ], 1 );
        add_action( 'init', [ __CLASS__, 'ensure_product_template_post' ], 2 );
        add_action( 'init', [ __CLASS__, 'register_category_template_post_type' ], 1 );
        add_action( 'init', [ __CLASS__, 'ensure_category_template_post' ], 2 );
        add_action( 'init', [ __CLASS__, 'register_product_blocks' ], 11 );
        add_action( 'init', [ __CLASS__, 'register_all_cpts' ], 5 );
        add_action( 'after_setup_theme', [ __CLASS__, 'setup_media_support' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'maybe_invert_managed_cpt_archive_order' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_assets' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_gallery_assets' ], 12 );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_product_editor_canvas_ui' ] );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_template_editor_guide_ui' ] );
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_notices', [ __CLASS__, 'render_admin_notices' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_category_image_admin' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_estandar_admin_ui' ] );
        add_action( 'wp_ajax_egm_tpl_preview_render', [ __CLASS__, 'ajax_render_product_template_preview' ] );
        add_action( 'wp_ajax_egm_tpl_schema_get', [ __CLASS__, 'ajax_get_product_template_schema' ] );
        add_action( 'wp_ajax_egm_tpl_templates_list', [ __CLASS__, 'ajax_list_product_templates' ] );
        add_filter( 'rest_pre_insert_producto', [ __CLASS__, 'protect_tpl_fields_from_null_rest_wipe' ], 10, 2 );
        // Canonical dynamic binding happens at parsed-block (attrs/content) level.
        add_action( 'admin_post_estandar_cpt_save', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_estandar_cpt_delete', [ __CLASS__, 'handle_delete' ] );
        add_action( 'admin_post_estandar_producto_template_save', [ __CLASS__, 'handle_product_template_save' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_product_meta_box' ] );
        add_action( 'edit_form_after_title', [ __CLASS__, 'render_product_editor_notice' ] );
        add_action( 'save_post_producto', [ __CLASS__, 'save_product_meta_box' ] );
        add_action( 'category_add_form_fields', [ __CLASS__, 'render_category_image_add_field' ] );
        add_action( 'category_edit_form_fields', [ __CLASS__, 'render_category_image_edit_field' ] );
        add_action( 'created_category', [ __CLASS__, 'save_category_image_meta' ] );
        add_action( 'edited_category', [ __CLASS__, 'save_category_image_meta' ] );
        add_action( 'category_add_form_fields', [ __CLASS__, 'render_category_template_add_fields' ], 20 );
        add_action( 'category_edit_form_fields', [ __CLASS__, 'render_category_template_edit_fields' ], 20 );
        add_action( 'created_category', [ __CLASS__, 'save_category_template_meta' ], 20 );
        add_action( 'edited_category', [ __CLASS__, 'save_category_template_meta' ], 20 );
        add_action( 'init', [ __CLASS__, 'register_category_gallery_block' ], 20 );

        // Ensure the singleton "Plantilla Productos" is edited with Gutenberg even on classic themes.
        add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'force_block_editor_for_template_post_type' ], 20, 2 );
        add_action( 'save_post_' . self::PRODUCT_TEMPLATE_POST_TYPE, [ __CLASS__, 'save_product_template_schema' ], 10, 3 );
        add_action( 'save_post_' . self::CATEGORY_TEMPLATE_POST_TYPE, [ __CLASS__, 'save_product_template_schema' ], 10, 3 );
	        add_filter( 'render_block_data', [ __CLASS__, 'filter_render_block_data_with_template_fields' ], 10, 2 );
	        add_filter( 'render_block', [ __CLASS__, 'filter_render_block_product_anchor_nav' ], 10, 2 );
	        add_filter( 'default_hidden_meta_boxes', [ __CLASS__, 'hide_legacy_product_metaboxes_by_default' ], 10, 2 );

        add_filter( 'template_include', [ __CLASS__, 'template_router' ] );
	        add_shortcode( 'estandar_product_categories', [ __CLASS__, 'render_product_categories_shortcode' ] );
	        add_shortcode( 'estandar_gallery', [ __CLASS__, 'render_gallery_shortcode' ] );
	        add_filter( 'the_content', 'egm_patch_cover_lazy_attrs', 20 );

	        register_activation_hook( __FILE__, [ __CLASS__, 'on_activate' ] );
	        register_deactivation_hook( __FILE__, [ __CLASS__, 'on_deactivate' ] );
	    }

    public static function render_product_editor_notice( $post ) {
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }
        if ( 'producto' !== $post->post_type ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $template_id = self::get_selected_product_template_id( (int) $post->ID );
        $edit_url    = $template_id ? admin_url( 'post.php?post=' . (int) $template_id . '&action=edit' ) : '';

        echo '<div class="notice notice-info" style="margin:12px 0 0;">';
        echo '<p style="margin:8px 0;">El <strong>diseño</strong> de esta ficha se controla desde la <strong>Plantilla de producto</strong>. En este editor cargas los <strong>datos</strong> (formulario) y el <strong>contenido adicional</strong> (bloques) que la plantilla insertará.</p>';
        if ( $edit_url ) {
            echo '<p style="margin:8px 0;"><a class="button" href="' . esc_url( $edit_url ) . '">Editar plantilla de este producto</a></p>';
        }
        echo '</div>';
    }

    public static function hide_legacy_product_metaboxes_by_default( array $hidden, WP_Screen $screen ): array {
        if ( isset( $screen->post_type ) && 'producto' === $screen->post_type ) {
            // Hide the legacy big metabox by default to reduce confusion; data remains intact.
            if ( ! in_array( 'estandar_producto_layout', $hidden, true ) ) {
                $hidden[] = 'estandar_producto_layout';
            }
        }
        return $hidden;
    }

    public static function on_activate() {
        if ( get_option( self::OPT_KEY, null ) === null ) {
            add_option( self::OPT_KEY, [] );
        }
        // Activation runs before normal init; register the CPT so we can create the singleton template safely.
        self::register_product_template_post_type();
        self::ensure_product_template_post();
        self::register_product_template_meta();

        self::register_all_cpts();
        flush_rewrite_rules();
    }

    public static function on_deactivate() {
        flush_rewrite_rules();
    }

    public static function setup_media_support() {
        add_theme_support( 'post-thumbnails' );
        add_theme_support( 'align-wide' );
        add_image_size( 'producto-thumb', 600, 400, true );
        add_image_size( 'producto-large', 1200, 800, true );
        add_image_size( 'categoria-thumb', 800, 600, true );
    }

    public static function register_product_template_post_type() {
        register_post_type(
            self::PRODUCT_TEMPLATE_POST_TYPE,
            [
                'labels' => [
                    'name'          => 'Plantilla Productos',
                    'singular_name' => 'Plantilla Producto',
                ],
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => true, // enables block editor in classic themes
                'supports'            => [ 'title', 'editor' ],
                'capability_type'     => 'page',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                'show_in_admin_bar'   => false,
            ]
        );
    }

    public static function register_category_template_post_type() {
        register_post_type(
            self::CATEGORY_TEMPLATE_POST_TYPE,
            [
                'labels' => [
                    'name'          => 'Plantilla Categorias',
                    'singular_name' => 'Plantilla Categoria',
                ],
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_rest'        => true,
                'supports'            => [ 'title', 'editor' ],
                'capability_type'     => 'page',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                'show_in_admin_bar'   => false,
            ]
        );
    }

	    public static function register_product_template_meta() {
	        // Allow future JS sidebar controls; also useful for REST previews.
	        register_post_meta(
	            'producto',
            self::PRODUCT_TEMPLATE_META_KEY,
            [
                'type'              => 'integer',
                'single'            => true,
                'show_in_rest'      => [
                    'schema' => [
                        'type' => 'integer',
                    ],
                ],
                'sanitize_callback' => 'absint',
                // IMPORTANT: auth_callback receives multiple args from core; keep signature compatible (avoid TypeError).
                'auth_callback'     => static function ( $allowed, $meta_key, $post_id, $user_id = 0, $cap = '', $caps = [] ) {
                    $post_id = absint( $post_id );
                    return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
                },
            ]
        );

        register_post_meta(
            'producto',
            self::PRODUCT_TEMPLATE_FIELDS_META_KEY,
            [
                'type'          => 'object',
                'single'        => true,
                // For object meta, WordPress REST requires an explicit schema or it may be ignored on save.
                'show_in_rest'  => [
                    'schema' => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                    ],
                ],
                'sanitize_callback' => [ __CLASS__, 'sanitize_tpl_fields_meta' ],
                // IMPORTANT: auth_callback receives multiple args from core; keep signature compatible (avoid TypeError).
                'auth_callback' => static function ( $allowed, $meta_key, $post_id, $user_id = 0, $cap = '', $caps = [] ) {
                    $post_id = absint( $post_id );
                    return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
                },
            ]
        );

        // Per-instance block attribute overrides (design tweaks per CPT).
	        register_post_meta(
	            'producto',
	            self::PRODUCT_TEMPLATE_ATTRS_META_KEY,
            [
                'type'          => 'object',
                'single'        => true,
                'show_in_rest'  => [
                    'schema' => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                    ],
                ],
                'sanitize_callback' => [ __CLASS__, 'sanitize_tpl_attrs_meta' ],
                'auth_callback' => static function ( $allowed, $meta_key, $post_id, $user_id = 0, $cap = '', $caps = [] ) {
                    $post_id = absint( $post_id );
                    return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
                },
            ]
        );
    }

    private static function sanitize_tpl_fields_value( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $looks_like_table_html = static function ( string $s ): bool {
            $s = strtolower( $s );
            if ( false === strpos( $s, '<' ) ) {
                return false;
            }
            // Very conservative: only treat as HTML when it clearly contains table markup.
            return ( false !== strpos( $s, '<table' ) )
                || ( false !== strpos( $s, '<thead' ) )
                || ( false !== strpos( $s, '<tbody' ) )
                || ( false !== strpos( $s, '<tr' ) )
                || ( false !== strpos( $s, '<td' ) )
                || ( false !== strpos( $s, '<th' ) )
                || ( false !== strpos( $s, 'wp-block-table' ) );
        };

        // Shallow sanitize: keys must be sanitize_key; values are scalar or small arrays (button).
        $clean = [];
        foreach ( $value as $k => $v ) {
            $k = sanitize_key( (string) $k );
            if ( '' === $k ) {
                continue;
            }
            if ( is_array( $v ) ) {
                // Button object
                if ( array_key_exists( 'text', $v ) || array_key_exists( 'url', $v ) || array_key_exists( 'opensInNewTab', $v ) || array_key_exists( 'rel', $v ) ) {
                    $btn = [
                        'text' => sanitize_text_field( (string) ( $v['text'] ?? '' ) ),
                        'url'  => esc_url_raw( (string) ( $v['url'] ?? '' ) ),
                        'opensInNewTab' => ! empty( $v['opensInNewTab'] ) ? 1 : 0,
                        'rel'  => sanitize_text_field( (string) ( $v['rel'] ?? '' ) ),
                    ];
                    if ( array_filter( $btn ) ) {
                        $clean[ $k ] = $btn;
                    }
                    continue;
                }

                // Gallery-like list: keep only numeric sequential arrays.
                $keys = array_keys( $v );
                $is_sequential = ( $keys === range( 0, count( $keys ) - 1 ) );
                if ( $is_sequential ) {
                    $ids = array_values( array_unique( array_filter( array_map( 'absint', $v ) ) ) );
                    if ( ! empty( $ids ) ) {
                        $clean[ $k ] = $ids;
                    }
                }
            } elseif ( is_scalar( $v ) || null === $v ) {
                if ( is_numeric( $v ) ) {
                    $clean[ $k ] = absint( $v );
                    continue;
                }

                $s = (string) $v;
                if ( $looks_like_table_html( $s ) ) {
                    // Allow safe table HTML to be pasted into CPT fields without being flattened to text.
                    $clean[ $k ] = wp_kses_post( $s );
                } else {
                    $clean[ $k ] = sanitize_text_field( $s );
                }
            }
        }
        return $clean;
    }

    public static function sanitize_tpl_fields_meta( $value, $meta_key, $object_type ) {
        return self::sanitize_tpl_fields_value( $value );
    }

    public static function sanitize_tpl_attrs_meta( $value, $meta_key, $object_type ) {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $clean = [];
        foreach ( $value as $k => $v ) {
            $k = sanitize_key( (string) $k );
            if ( '' === $k || ! is_array( $v ) ) {
                continue;
            }

            $item = [];
            if ( array_key_exists( 'visible', $v ) ) {
                $item['visible'] = ! empty( $v['visible'] ) ? 1 : 0;
            }
            if ( array_key_exists( 'hideIfEmpty', $v ) ) {
                $item['hideIfEmpty'] = ! empty( $v['hideIfEmpty'] ) ? 1 : 0;
            }
            if ( array_key_exists( 'moreText', $v ) ) {
                $item['moreText'] = sanitize_text_field( (string) $v['moreText'] );
            }
            if ( array_key_exists( 'lessText', $v ) ) {
                $item['lessText'] = sanitize_text_field( (string) $v['lessText'] );
            }
            if ( isset( $v['align'] ) ) {
                $align = sanitize_key( (string) $v['align'] );
                if ( in_array( $align, [ '', 'wide', 'full', 'left', 'right', 'center' ], true ) ) {
                    $item['align'] = $align;
                }
            }
            if ( isset( $v['className'] ) ) {
                $item['className'] = sanitize_text_field( (string) $v['className'] );
            }
            if ( isset( $v['style'] ) && is_array( $v['style'] ) ) {
                // Keep raw style array; WP will sanitize at render time via style engine.
                $item['style'] = $v['style'];
            }
            if ( isset( $v['dimRatio'] ) ) {
                $item['dimRatio'] = max( 0, min( 100, absint( $v['dimRatio'] ) ) );
            }
            if ( isset( $v['minHeight'] ) ) {
                $item['minHeight'] = max( 0, absint( $v['minHeight'] ) );
            }
            if ( isset( $v['contentPosition'] ) ) {
                $item['contentPosition'] = sanitize_text_field( (string) $v['contentPosition'] );
            }
            if ( isset( $v['focalPoint'] ) && is_array( $v['focalPoint'] ) ) {
                $x = isset( $v['focalPoint']['x'] ) ? (float) $v['focalPoint']['x'] : null;
                $y = isset( $v['focalPoint']['y'] ) ? (float) $v['focalPoint']['y'] : null;
                if ( null !== $x && null !== $y ) {
                    $item['focalPoint'] = [
                        'x' => max( 0, min( 1, $x ) ),
                        'y' => max( 0, min( 1, $y ) ),
                    ];
                }
            }
            $filtered = array_filter(
                $item,
                static function ( $val, $key ) {
                    if ( in_array( (string) $key, [ 'visible', 'hideIfEmpty' ], true ) ) {
                        return true;
                    }
                    return ! empty( $val );
                },
                ARRAY_FILTER_USE_BOTH
            );
            if ( ! empty( $filtered ) ) {
                $clean[ $k ] = $item;
            }
        }
        return $clean;
    }

    private static function parsed_block_is_empty_value( string $block_name, $value ): bool {
        if ( is_array( $value ) ) {
            // Button meta or any structured value: treat as empty if all scalars are empty.
            foreach ( $value as $v ) {
                if ( is_string( $v ) && '' !== trim( $v ) ) {
                    return false;
                }
                if ( is_numeric( $v ) && 0 !== (int) $v ) {
                    return false;
                }
                if ( is_bool( $v ) && true === $v ) {
                    return false;
                }
            }
            return true;
        }

        if ( is_string( $value ) ) {
            $raw = (string) $value;
            $raw = trim( $raw );
            if ( '' === $raw ) {
                return true;
            }

            // For rich HTML-capable fields (tables/html), treat as empty when it has no meaningful text.
            if ( false !== strpos( $raw, '<' ) ) {
                $text = wp_strip_all_tags( $raw );
            } else {
                $text = $raw;
            }

            $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
            // Normalize non-breaking spaces.
            $text = str_replace( [ "\xc2\xa0", '&nbsp;' ], ' ', (string) $text );
            $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

            // For video URLs, treat invalid URLs as empty too.
            if ( 'core/video' === $block_name ) {
                return '' === esc_url_raw( $raw );
            }

            return '' === $text;
        }

        if ( is_numeric( $value ) ) {
            // For media IDs (image/cover), 0 means "empty".
            return 0 === absint( $value );
        }

        return empty( $value );
    }

    /** @return array<int,int> */
    private static function parse_gallery_ids_from_value( $value ): array {
        if ( is_array( $value ) ) {
            $ids = array_map( 'absint', $value );
            $ids = array_values( array_unique( array_filter( $ids ) ) );
            return $ids;
        }

        $raw = trim( (string) $value );
        if ( '' === $raw ) {
            return [];
        }

        $ids = array_map(
            'absint',
            preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: []
        );
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        return $ids;
    }

    private static function empty_parsed_block( array $parsed_block ): array {
        $parsed_block['blockName']     = null;
        $parsed_block['attrs']         = [];
        $parsed_block['innerBlocks']   = [];
        $parsed_block['innerHTML']     = '';
        $parsed_block['innerContent']  = [ '' ];
        return $parsed_block;
    }

    public static function force_block_editor_for_template_post_type( bool $use_block_editor, string $post_type ): bool {
        if ( self::PRODUCT_TEMPLATE_POST_TYPE === $post_type || self::CATEGORY_TEMPLATE_POST_TYPE === $post_type ) {
            return true;
        }
        return $use_block_editor;
    }

    public static function ensure_category_template_post(): int {
        $existing_id = absint( get_option( self::CATEGORY_TEMPLATE_POST_ID_OPT_KEY, 0 ) );
        if ( $existing_id ) {
            $existing = get_post( $existing_id );
            if ( $existing && self::CATEGORY_TEMPLATE_POST_TYPE === $existing->post_type ) {
                return $existing_id;
            }
            delete_option( self::CATEGORY_TEMPLATE_POST_ID_OPT_KEY );
        }

        $posts = get_posts(
            [
                'post_type'      => self::CATEGORY_TEMPLATE_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]
        );

        if ( ! empty( $posts ) ) {
            $id = absint( $posts[0] );
            update_option( self::CATEGORY_TEMPLATE_POST_ID_OPT_KEY, $id, false );
            return $id;
        }

        $id = wp_insert_post(
            [
                'post_type'    => self::CATEGORY_TEMPLATE_POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => 'Plantilla global de categorias (productos)',
                'post_content' => self::default_category_template_post_content(),
            ],
            true
        );

        if ( is_wp_error( $id ) ) {
            $msg  = $id->get_error_message();
            $data = $id->get_error_data( 'db_insert_error' );
            if ( is_string( $data ) && '' !== trim( $data ) ) {
                $msg .= ' (' . trim( $data ) . ')';
            }
            set_transient( self::CATEGORY_TEMPLATE_LAST_ERROR_TRANSIENT, $msg, 60 );
            return 0;
        }

        $id = absint( $id );
        update_option( self::CATEGORY_TEMPLATE_POST_ID_OPT_KEY, $id, false );
        return $id;
    }

    public static function ensure_product_template_post(): int {
        $existing_id = absint( get_option( self::PRODUCT_TEMPLATE_POST_ID_OPT_KEY, 0 ) );
        if ( $existing_id ) {
            $existing = get_post( $existing_id );
            if ( $existing && self::PRODUCT_TEMPLATE_POST_TYPE === $existing->post_type ) {
                return $existing_id;
            }
            // Option points to a non-existent or mismatched post; repair.
            delete_option( self::PRODUCT_TEMPLATE_POST_ID_OPT_KEY );
        }

        $posts = get_posts(
            [
                'post_type'      => self::PRODUCT_TEMPLATE_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]
        );

        if ( ! empty( $posts ) ) {
            $id = absint( $posts[0] );
            update_option( self::PRODUCT_TEMPLATE_POST_ID_OPT_KEY, $id, false );
            return $id;
        }

        $id = wp_insert_post(
            [
                'post_type'    => self::PRODUCT_TEMPLATE_POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => 'Plantilla global de productos',
                'post_content' => self::default_product_template_post_content(),
            ],
            true
        );

        if ( is_wp_error( $id ) ) {
            $msg  = $id->get_error_message();
            $data = $id->get_error_data( 'db_insert_error' );
            if ( is_string( $data ) && '' !== trim( $data ) ) {
                $msg .= ' (' . trim( $data ) . ')';
            }
            set_transient( self::PRODUCT_TEMPLATE_LAST_ERROR_TRANSIENT, $msg, 60 );
            return 0;
        }

        $id = absint( $id );
        update_option( self::PRODUCT_TEMPLATE_POST_ID_OPT_KEY, $id, false );
        return $id;
    }

    private static function class_has_token( string $class_name, string $token ): bool {
        $class_name = trim( $class_name );
        if ( '' === $class_name ) {
            return false;
        }
        $parts = preg_split( '/\s+/', $class_name ) ?: [];
        return in_array( $token, $parts, true );
    }

    private static function merge_block_style_arrays( array $base, array $override ): array {
        foreach ( $override as $k => $v ) {
            if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
                $base[ $k ] = self::merge_block_style_arrays( $base[ $k ], $v );
            } else {
                $base[ $k ] = $v;
            }
        }
        return $base;
    }

    private static function extract_field_key_from_class( string $class_name ): string {
        if ( preg_match( '/\begm-key-([a-z0-9_-]+)\b/i', $class_name, $m ) ) {
            return sanitize_key( (string) $m[1] );
        }
        return '';
    }

    private static function ensure_field_key_in_class( string $class_name, string $key ): string {
        $class_name = trim( (string) $class_name );
        $key        = sanitize_key( $key );
        if ( '' === $key ) {
            return $class_name;
        }

        // Remove any existing key token and re-add.
        $class_name = preg_replace( '/\begm-key-[a-z0-9_-]+\b/i', '', $class_name );
        $class_name = trim( preg_replace( '/\s+/', ' ', (string) $class_name ) );
        $class_name = trim( $class_name . ' egm-key-' . $key );
        return trim( preg_replace( '/\s+/', ' ', $class_name ) );
    }

    private static function guess_field_type_for_block( string $block_name ): string {
        // Minimal mapping: extend later if needed.
        if ( 'core/image' === $block_name || 'core/cover' === $block_name ) {
            return 'image';
        }
        if ( 'estandar/gallery' === $block_name ) {
            return 'gallery';
        }
        if ( 'core/video' === $block_name ) {
            // URL that can be either direct mp4/webm/ogg or a YouTube link.
            return 'video';
        }
        if ( 'core/table' === $block_name || 'core/html' === $block_name ) {
            // Allows copy/paste of rich HTML (tables, lists) from the CPT editor.
            return 'html';
        }
        if ( 'core/button' === $block_name ) {
            return 'button';
        }
        if ( false !== strpos( $block_name, 'button' ) ) {
            return 'button';
        }
        if ( 'core/paragraph' === $block_name ) {
            return 'textarea';
        }
        // headings, list items, etc.
        return 'text';
    }

    private static function human_label_from_block( array $block ): string {
        $label = '';

        if ( isset( $block['innerHTML'] ) ) {
            $label = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );
        }

        if ( '' !== $label ) {
            // Keep it short.
            $label = preg_replace( '/\s+/', ' ', (string) $label );
            if ( strlen( $label ) > 80 ) {
                $label = substr( $label, 0, 77 ) . '...';
            }
            return $label;
        }

        $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
        if ( $block_name ) {
            $parts = explode( '/', $block_name );
            return ucfirst( end( $parts ) ?: 'Campo' );
        }

        return 'Campo';
    }

    private static function generate_field_key(): string {
        // Short stable-ish key.
        $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
        return 'f' . substr( preg_replace( '/[^a-f0-9]/i', '', (string) $uuid ), 0, 10 );
    }

    private static function build_template_schema_and_patch_blocks( array &$blocks, array &$schema_fields, array $prev_key_by_path, array $path = [] ): void {
        foreach ( $blocks as $i => &$block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            $attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            $class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';

            $current_path = array_merge( $path, [ $i ] );

            if ( $block_name && self::class_has_token( $class_name, 'egm-field' ) ) {
                $key = self::extract_field_key_from_class( $class_name );
                if ( '' === $key ) {
                    $path_key = implode( '.', $current_path );
                    if ( isset( $prev_key_by_path[ $path_key ] ) ) {
                        $key = sanitize_key( (string) $prev_key_by_path[ $path_key ] );
                    }
                    if ( '' === $key ) {
                        $key = self::generate_field_key();
                    }
                }

                $attrs['className'] = self::ensure_field_key_in_class( $class_name, $key );
                $block['attrs']     = $attrs;

                $type  = self::guess_field_type_for_block( $block_name );
                $label = self::human_label_from_block( $block );

                $schema_fields[] = [
                    'key'       => $key,
                    'type'      => $type,
                    'blockName' => $block_name,
                    'label'     => $label,
                    'path'      => $current_path,
                ];
            }

            if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
                self::build_template_schema_and_patch_blocks( $block['innerBlocks'], $schema_fields, $prev_key_by_path, $current_path );
            }
        }
    }

    public static function save_product_template_schema( int $post_id, WP_Post $post, bool $update ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        static $in_save = false;
        if ( $in_save ) {
            return;
        }

        $content = isset( $post->post_content ) ? (string) $post->post_content : '';
        $blocks  = parse_blocks( $content );

        $prev_schema = get_post_meta( $post_id, self::TEMPLATE_SCHEMA_META_KEY, true );
        $prev_schema = is_array( $prev_schema ) ? $prev_schema : [];
        $prev_fields = isset( $prev_schema['fields'] ) && is_array( $prev_schema['fields'] ) ? $prev_schema['fields'] : [];
        $prev_key_by_path = [];
        foreach ( $prev_fields as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $p = $f['path'] ?? null;
            $k = $f['key'] ?? '';
            if ( is_array( $p ) && '' !== (string) $k ) {
                $prev_key_by_path[ implode( '.', array_map( 'intval', $p ) ) ] = sanitize_key( (string) $k );
            }
        }

        $schema_fields = [];
        self::build_template_schema_and_patch_blocks( $blocks, $schema_fields, $prev_key_by_path, [] );

        // Save schema in meta (array is fine; WP will serialize). Also keep it JSON-friendly.
        $schema = [
            'version' => 1,
            'fields'  => array_values( $schema_fields ),
            'savedAt' => time(),
        ];
        update_post_meta( $post_id, self::TEMPLATE_SCHEMA_META_KEY, $schema );

        // If we injected keys, persist the patched content.
        $patched = serialize_blocks( $blocks );
        if ( is_string( $patched ) && $patched !== $content ) {
            $in_save = true;
            // Avoid infinite loop.
            remove_action( 'save_post_' . self::PRODUCT_TEMPLATE_POST_TYPE, [ __CLASS__, 'save_product_template_schema' ], 10 );
            wp_update_post(
                [
                    'ID'           => $post_id,
                    'post_content' => $patched,
                ]
            );
            add_action( 'save_post_' . self::PRODUCT_TEMPLATE_POST_TYPE, [ __CLASS__, 'save_product_template_schema' ], 10, 3 );
            $in_save = false;
        }
    }

    private static function default_product_template_post_content(): string {
        // Uses dynamic blocks that pull from the current "producto" post.
        return trim(
            <<<HTML
<!-- wp:group {"align":"full","className":"egm-product-hero"} -->
<div class="wp-block-group alignfull egm-product-hero"><!-- wp:columns {"verticalAlignment":"center"} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:estandar/product-title /-->

<!-- wp:estandar/product-excerpt /-->

<!-- wp:estandar/product-ctas /--></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:estandar/product-featured-image {"sizeSlug":"large"} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"egm-product-anchor-nav"} -->
<div class="wp-block-group alignfull egm-product-anchor-nav"><!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"url":"#caracteristicas"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#caracteristicas">Caracteristicas</a></div>
<!-- /wp:button -->

<!-- wp:button {"url":"#modos"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#modos">Modos</a></div>
<!-- /wp:button -->

<!-- wp:button {"url":"#metricas"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#metricas">Metricas</a></div>
<!-- /wp:button -->

<!-- wp:button {"url":"#especificaciones"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#especificaciones">Especificaciones</a></div>
<!-- /wp:button -->

<!-- wp:button {"url":"#aplicaciones"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#aplicaciones">Aplicaciones</a></div>
<!-- /wp:button -->

<!-- wp:button {"url":"#formulario"} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#formulario">Cotiza aqui</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:estandar/product-content /-->

<!-- wp:estandar/product-features /-->
<!-- wp:estandar/product-modes /-->
<!-- wp:estandar/product-metrics /-->
<!-- wp:estandar/product-showcase /-->
<!-- wp:estandar/product-specs /-->
<!-- wp:estandar/product-applications /-->

<!-- wp:group {"className":"egm-product-section egm-form","anchor":"formulario"} -->
<div class="wp-block-group egm-product-section egm-form" id="formulario"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Formulario</h2>
<!-- /wp:heading -->

<!-- wp:shortcode -->
[tu_form_shortcode]
<!-- /wp:shortcode --></div>
<!-- /wp:group -->
HTML
        );
    }

    private static function default_category_template_post_content(): string {
        // Default: full-width hero cover + inherited query loop (category archive already filtered by ?post_type=producto).
        return trim(
            <<<HTML
<!-- wp:cover {"dimRatio":45,"minHeight":420,"minHeightUnit":"px","align":"full","className":"egm-field egm-key-cat_hero_bg egm-cat-hero"} -->
<div class="wp-block-cover alignfull egm-field egm-key-cat_hero_bg egm-cat-hero"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-45 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":1,"className":"egm-field egm-key-cat_title"} -->
<h1 class="wp-block-heading has-text-align-center egm-field egm-key-cat_title">Nombre de la categoria</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","className":"egm-field egm-key-cat_lead"} -->
<p class="has-text-align-center egm-field egm-key-cat_lead">Descripcion breve de la categoria</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->

<!-- wp:group {"className":"egm-cat-products","layout":{"type":"constrained"}} -->
<div class="wp-block-group egm-cat-products"><!-- wp:query {"query":{"perPage":12,"postType":"producto","inherit":true},"displayLayout":{"type":"flex","columns":3}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"16px","bottom":"16px"}}},"className":"egm-cat-card"} -->
<div class="wp-block-group egm-cat-card" style="padding-top:16px;padding-bottom:16px"><!-- wp:post-featured-image {"isLink":true,"height":"240px"} /-->

<!-- wp:post-title {"isLink":true,"level":3} /-->

<!-- wp:post-excerpt {"moreText":"Ver mas"} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination --></div>
<!-- /wp:query --></div>
<!-- /wp:group -->
HTML
        );
    }

    public static function register_product_blocks() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type(
            'estandar/product-title',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Titulo',
                'category'        => 'widgets',
                'icon'            => 'heading',
                'render_callback' => [ __CLASS__, 'render_block_product_title' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-excerpt',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Resumen',
                'category'        => 'widgets',
                'icon'            => 'text',
                'render_callback' => [ __CLASS__, 'render_block_product_excerpt' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-featured-image',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Imagen destacada',
                'category'        => 'widgets',
                'icon'            => 'format-image',
                'attributes'      => [
                    'sizeSlug' => [ 'type' => 'string', 'default' => 'producto-large' ],
                ],
                'render_callback' => [ __CLASS__, 'render_block_product_featured_image' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-ctas',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Botones (CTA)',
                'category'        => 'widgets',
                'icon'            => 'megaphone',
                'render_callback' => [ __CLASS__, 'render_block_product_ctas' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-content',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Contenido del editor',
                'category'        => 'widgets',
                'icon'            => 'editor-alignleft',
                'render_callback' => [ __CLASS__, 'render_block_product_content' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-features',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Features',
                'category'        => 'widgets',
                'icon'            => 'grid-view',
                'render_callback' => [ __CLASS__, 'render_block_product_features' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-modes',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Modos (SLAM/RTK)',
                'category'        => 'widgets',
                'icon'            => 'controls-repeat',
                'render_callback' => [ __CLASS__, 'render_block_product_modes' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-metrics',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Metricas',
                'category'        => 'widgets',
                'icon'            => 'chart-bar',
                'render_callback' => [ __CLASS__, 'render_block_product_metrics' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-showcase',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Showcase',
                'category'        => 'widgets',
                'icon'            => 'format-gallery',
                'render_callback' => [ __CLASS__, 'render_block_product_showcase' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-specs',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Especificaciones',
                'category'        => 'widgets',
                'icon'            => 'list-view',
                'render_callback' => [ __CLASS__, 'render_block_product_specs' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        register_block_type(
            'estandar/product-applications',
            [
                'api_version'     => 2,
                'title'           => 'Producto: Aplicaciones',
                'category'        => 'widgets',
                'icon'            => 'admin-site-alt3',
                'render_callback' => [ __CLASS__, 'render_block_product_applications' ],
                'supports'        => [ 'html' => false ],
            ]
        );

        wp_register_script(
            'estandar-cpt-gallery-block',
            false,
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ],
            '1.0.0',
            true
        );
        wp_add_inline_script( 'estandar-cpt-gallery-block', self::gallery_block_script() );

        register_block_type(
	            'estandar/gallery',
	            [
	                'api_version'      => 2,
	                'title'            => 'Estandar: Galería (CPT)',
	                'category'         => 'widgets',
	                'icon'             => 'format-gallery',
	                'description'      => 'Galería simple para plantillas dinámicas (2 visibles + Ver más). Para volverla variable por CPT, agrega egm-field egm-key-* en Advanced.',
	                'editor_script'    => 'estandar-cpt-gallery-block',
	                'attributes'       => [
	                    // `ids` se llena automáticamente desde el CPT cuando el bloque tiene egm-field + egm-key-*.
	                    'ids'  => [ 'type' => 'string', 'default' => '' ],
	                    'size' => [ 'type' => 'string', 'default' => 'medium' ],
	                    'align'=> [ 'type' => 'string', 'default' => '' ],
	                    'alignButton' => [ 'type' => 'string', 'default' => 'center' ],
	                    'animation' => [ 'type' => 'string', 'default' => 'slide' ],
	                    'moreText' => [ 'type' => 'string', 'default' => 'Ver más' ],
	                    'lessText' => [ 'type' => 'string', 'default' => 'Ver menos' ],
	                ],
	                'render_callback'  => [ __CLASS__, 'render_block_estandar_gallery' ],
	                'supports'         => [ 'html' => false ],
	            ]
	        );

	        // Per-product overrides for body sections and internal nav (optional; non-destructive).
	        register_post_meta(
	            'producto',
	            self::PRODUCT_SECTIONS_META_KEY,
	            [
	                'type'         => 'object',
	                'single'       => true,
	                'show_in_rest' => [
	                    'schema' => [
	                        'type'                 => 'object',
	                        'additionalProperties' => true,
	                    ],
	                ],
	                'sanitize_callback' => [ __CLASS__, 'sanitize_product_sections_meta' ],
	                'auth_callback' => static function ( $allowed, $meta_key, $post_id, $user_id = 0, $cap = '', $caps = [] ) {
	                    $post_id = absint( $post_id );
	                    return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
	                },
	            ]
	        );
	    }

	    public static function sanitize_product_sections_meta( $value ): array {
	        if ( ! is_array( $value ) ) {
	            return [];
	        }

	        $allowed_sections = [
	            'features' => true,
	            'modes' => true,
	            'metrics' => true,
	            'showcase' => true,
	            'specs' => true,
	            'applications' => true,
	        ];

	        $clean = [];
	        foreach ( $value as $id => $row ) {
	            $id = sanitize_key( (string) $id );
	            if ( '' === $id || ! isset( $allowed_sections[ $id ] ) || ! is_array( $row ) ) {
	                continue;
	            }

	            $active = isset( $row['active'] ) ? absint( $row['active'] ) : 1;
	            $title  = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
	            $anchor = isset( $row['anchor'] ) ? sanitize_key( (string) $row['anchor'] ) : '';

	            $clean[ $id ] = [
	                'active' => $active ? 1 : 0,
	                'title'  => $title,
	                'anchor' => $anchor,
	            ];
	        }

	        return $clean;
	    }

	    public static function render_block_estandar_gallery( array $attributes ): string {
	        $ids = isset( $attributes['ids'] ) ? sanitize_text_field( (string) $attributes['ids'] ) : '';
	        if ( '' === trim( $ids ) ) {
	            return '';
	        }

	        $size  = isset( $attributes['size'] ) ? sanitize_text_field( (string) $attributes['size'] ) : 'medium';
	        $align = isset( $attributes['align'] ) ? sanitize_key( (string) $attributes['align'] ) : '';
	        $align_button = isset( $attributes['alignButton'] ) ? sanitize_key( (string) $attributes['alignButton'] ) : 'center';
	        if ( ! in_array( $align_button, [ 'left', 'center', 'right' ], true ) ) {
	            $align_button = 'center';
	        }
	        $animation = isset( $attributes['animation'] ) ? sanitize_key( (string) $attributes['animation'] ) : 'slide';
	        if ( ! in_array( $animation, [ 'slide', 'none' ], true ) ) {
	            $animation = 'slide';
	        }
	        $more_text = isset( $attributes['moreText'] ) ? sanitize_text_field( (string) $attributes['moreText'] ) : '';
	        $less_text = isset( $attributes['lessText'] ) ? sanitize_text_field( (string) $attributes['lessText'] ) : '';

	        return self::render_gallery_shortcode(
	            [
	                'ids'   => $ids,
	                'size'  => $size,
	                'align' => $align,
	                'align_button' => $align_button,
	                'animation' => $animation,
	                'more_text' => $more_text,
	                'less_text' => $less_text,
	            ]
	        );
	    }

    private static function gallery_block_script(): string {
        return <<<'JS'
(function(blocks, element, components, blockEditor) {
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var TextControl = components.TextControl;
    var Notice = components.Notice;

	    blocks.registerBlockType('estandar/gallery', {
	        title: 'Estandar: Galería (CPT)',
	        icon: 'format-gallery',
	        category: 'widgets',
	        description: 'Galería simple (2 visibles + Ver más). Para hacerla variable por CPT, agrega egm-field egm-key-* en Advanced.',
	        attributes: {
	            ids: { type: 'string', default: '' },
	            size: { type: 'string', default: 'medium' },
	            align: { type: 'string', default: '' },
	            alignButton: { type: 'string', default: 'center' },
	            animation: { type: 'string', default: 'slide' },
	            moreText: { type: 'string', default: 'Ver más' },
	            lessText: { type: 'string', default: 'Ver menos' }
	        },
	        edit: function(props) {
	            var attrs = props.attributes || {};
	            var setAttributes = props.setAttributes;

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: 'Ajustes', initialOpen: true },
                        el(SelectControl, {
                            label: 'Tamaño de imagen (size)',
                            value: attrs.size || 'medium',
                            options: [
                                { label: 'thumbnail', value: 'thumbnail' },
                                { label: 'medium', value: 'medium' },
                                { label: 'large', value: 'large' },
                                { label: 'full', value: 'full' }
                            ],
                            onChange: function(v){ setAttributes({ size: v }); }
                        }),
                        el(SelectControl, {
                            label: 'Alineación (solo si hay 1 imagen)',
                            value: attrs.align || '',
                            options: [
                                { label: 'Heredar', value: '' },
                                { label: 'left', value: 'left' },
                                { label: 'center', value: 'center' },
                                { label: 'right', value: 'right' }
                            ],
                            onChange: function(v){ setAttributes({ align: v }); }
                        }),
                        el(TextControl, {
                            label: 'Texto botón (Ver más)',
                            value: attrs.moreText || 'Ver más',
                            onChange: function(v){ setAttributes({ moreText: v }); }
                        }),
	                        el(TextControl, {
	                            label: 'Texto botón (Ver menos)',
	                            value: attrs.lessText || 'Ver menos',
	                            onChange: function(v){ setAttributes({ lessText: v }); }
	                        })
	                        ,
	                        el(SelectControl, {
	                            label: 'Alineación del botón',
	                            value: attrs.alignButton || 'center',
	                            options: [
	                                { label: 'left', value: 'left' },
	                                { label: 'center', value: 'center' },
	                                { label: 'right', value: 'right' }
	                            ],
	                            onChange: function(v){ setAttributes({ alignButton: v }); }
	                        }),
	                        el(SelectControl, {
	                            label: 'Animación',
	                            value: attrs.animation || 'slide',
	                            options: [
	                                { label: 'slide', value: 'slide' },
	                                { label: 'none', value: 'none' }
	                            ],
	                            onChange: function(v){ setAttributes({ animation: v }); }
	                        })
	                    )
	                ),
	                el(Notice, { status: 'info', isDismissible: false },
	                    el('p', null, 'Este bloque se llena desde el CPT cuando lo marcas como campo dinámico:'),
	                    el('p', null, el('code', null, 'egm-field egm-key-mi_galeria')),
                    el('p', null, 'Luego, en el editor del Producto, seleccionas las imágenes para esa key.')
                )
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
JS;
    }

    private static function current_product_id(): int {
        $id = get_the_ID();
        return $id ? absint( $id ) : 0;
    }

    private static function is_product_context(): bool {
        $id = self::current_product_id();
        return $id && 'producto' === get_post_type( $id );
    }

    private static function replace_tag_inner_text( string $html, string $new_text ): string {
        $new_text = esc_html( $new_text );
        // Replace the first tag's inner text, keeping attributes.
        return (string) preg_replace( '/>(.*?)<\//s', '>' . $new_text . '</', $html, 1 );
    }

    private static function replace_anchor_text_and_url( string $html, string $text, string $url ): string {
        $text = esc_html( $text );
        $url  = esc_url( $url );

        // Replace href if present.
        if ( '' !== $url ) {
            $html = (string) preg_replace( '/href="[^"]*"/', 'href="' . $url . '"', $html, 1 );
        }

        // Replace anchor inner text (first <a ...>...</a>).
        $html = (string) preg_replace( '/(<a\b[^>]*>)(.*?)(<\/a>)/s', '$1' . $text . '$3', $html, 1 );
        return $html;
    }

    private static function replace_image_refs_in_html( string $html, int $new_id, string $new_url, string $context ): string {
        $new_id  = absint( $new_id );
        $new_url = (string) $new_url;
        if ( '' === $html ) {
            return $html;
        }

        if ( $new_id ) {
            $html = (string) preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $new_id, $html );
        }

        if ( 'cover' === $context ) {
            // Cover background can be span style or an img tag depending on version.
            if ( '' !== $new_url ) {
                $html = (string) preg_replace(
                    '/background-image\s*:\s*url\(([^)]+)\)\s*;?/i',
                    'background-image:url(' . esc_url_raw( $new_url ) . ');',
                    $html
                );
            }
            // Replace src on the cover background img if present.
            if ( '' !== $new_url ) {
                $html = (string) preg_replace(
                    '/(<img\b[^>]*class="[^"]*wp-block-cover__image-background[^"]*"[^>]*\bsrc=")[^"]*(")/i',
                    '$1' . esc_url_raw( $new_url ) . '$2',
                    $html,
                    1
                );
            }
        } else {
            // core/image: replace the first <img ... src="...">
            if ( '' !== $new_url ) {
                $html = (string) preg_replace(
                    '/(<img\b[^>]*\bsrc=")[^"]*(")/i',
                    '$1' . esc_url_raw( $new_url ) . '$2',
                    $html,
                    1
                );
            }
        }

        // Drop stale srcset/sizes so the browser doesn't keep old image variants.
        $html = (string) preg_replace( '/\s+srcset="[^"]*"/i', '', $html );
        $html = (string) preg_replace( '/\s+sizes="[^"]*"/i', '', $html );

        return $html;
    }

    private static function replace_image_refs_in_inner_content( $inner_content, int $new_id, string $new_url, string $context ) {
        if ( ! is_array( $inner_content ) ) {
            return $inner_content;
        }
        $out = [];
        foreach ( $inner_content as $part ) {
            if ( is_string( $part ) && '' !== $part ) {
                $out[] = self::replace_image_refs_in_html( $part, $new_id, $new_url, $context );
            } else {
                $out[] = $part;
            }
        }
        return $out;
    }

    private static function inner_html_looks_static_for_image_swap( array $parsed_block, string $context ): bool {
        $haystack = '';
        if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) ) {
            $haystack .= $parsed_block['innerHTML'];
        }
        if ( isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
            foreach ( $parsed_block['innerContent'] as $part ) {
                if ( is_string( $part ) ) {
                    $haystack .= $part;
                }
            }
        }
        if ( '' === $haystack ) {
            return false;
        }

        if ( 'cover' === $context ) {
            return ( false !== stripos( $haystack, 'background-image' ) ) || ( false !== stripos( $haystack, 'wp-block-cover__image-background' ) );
        }

        return ( false !== stripos( $haystack, '<img' ) ) && ( false !== stripos( $haystack, 'src=' ) );
    }

    private static function block_tree_has_meaningful_dynamic_content( array $blocks, array $values, array $overrides ): bool {
        foreach ( $blocks as $b ) {
            if ( ! is_array( $b ) ) {
                continue;
            }

            $block_name = isset( $b['blockName'] ) ? (string) $b['blockName'] : '';
            $attrs      = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : [];
            $class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';

            if ( '' !== $class_name && self::class_has_token( $class_name, 'egm-field' ) ) {
                $key = self::extract_field_key_from_class( $class_name );
                if ( '' !== $key ) {
                    $ov = ( isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) ? $overrides[ $key ] : [];
                    if ( array_key_exists( 'visible', $ov ) && empty( $ov['visible'] ) ) {
                        // Manually hidden counts as "no content" for container decisions.
                    } else {
                        $has_value = array_key_exists( $key, $values );
                        $val       = $has_value ? $values[ $key ] : null;
                        $is_empty  = ( ! $has_value ) ? true : self::parsed_block_is_empty_value( $block_name ?: 'text', $val );
                        if ( ! $is_empty ) {
                            return true;
                        }
                    }
                }
            }

            if ( isset( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) && ! empty( $b['innerBlocks'] ) ) {
                if ( self::block_tree_has_meaningful_dynamic_content( $b['innerBlocks'], $values, $overrides ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function filter_render_block_data_with_template_fields( array $parsed_block, array $source_block ): array {
        if ( empty( $parsed_block['blockName'] ) ) {
            return $parsed_block;
        }

        $block_name = (string) $parsed_block['blockName'];
        $attrs      = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : [];
        $class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';

        // Optional container guard: hide the whole container if none of its dynamic children have meaningful CPT data.
        // Use this by adding `egm-hide-if-empty-children` to a Group/Columns/etc in the template.
        if ( '' !== $class_name && self::class_has_token( $class_name, 'egm-hide-if-empty-children' ) ) {
            $values    = [];
            $overrides = [];

            if ( is_array( self::$dynamic_render_context ) ) {
                $values    = is_array( self::$dynamic_render_context['values'] ?? null ) ? self::$dynamic_render_context['values'] : [];
                $overrides = is_array( self::$dynamic_render_context['attrs'] ?? null ) ? self::$dynamic_render_context['attrs'] : [];
            } else {
                if ( self::is_product_context() ) {
                    $ctx_id    = self::current_product_id();
                    $values    = self::get_product_template_field_values( $ctx_id );
                    $overrides = self::get_product_template_attr_overrides( $ctx_id );
                } else {
                    return $parsed_block;
                }
            }

            $inner = isset( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] ) ? $parsed_block['innerBlocks'] : [];
            if ( ! empty( $inner ) ) {
                if ( ! self::block_tree_has_meaningful_dynamic_content( $inner, $values, $overrides ) ) {
                    return self::empty_parsed_block( $parsed_block );
                }
            }
        }

        if ( '' === $class_name || ! self::class_has_token( $class_name, 'egm-field' ) ) {
            return $parsed_block;
        }

        $key = self::extract_field_key_from_class( $class_name );
        if ( '' === $key ) {
            return $parsed_block;
        }

        $ctx_type  = '';
        $ctx_id    = 0;
        $values    = null;
        $overrides = null;

        if ( is_array( self::$dynamic_render_context ) ) {
            $ctx_type  = sanitize_key( (string) ( self::$dynamic_render_context['type'] ?? '' ) );
            $ctx_id    = absint( self::$dynamic_render_context['id'] ?? 0 );
            $values    = is_array( self::$dynamic_render_context['values'] ?? null ) ? self::$dynamic_render_context['values'] : [];
            $overrides = is_array( self::$dynamic_render_context['attrs'] ?? null ) ? self::$dynamic_render_context['attrs'] : [];
        } else {
            // Only bind fields when we're rendering within a Producto context.
            if ( ! self::is_product_context() ) {
                return $parsed_block;
            }
            $ctx_type  = 'producto';
            $ctx_id    = self::current_product_id();
            $values    = self::get_product_template_field_values( $ctx_id );
            $overrides = self::get_product_template_attr_overrides( $ctx_id );
        }

        // Apply per-instance design overrides (attrs) before content overrides.
        if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) {
            $ov = $overrides[ $key ];
            if ( isset( $ov['align'] ) ) {
                $attrs['align'] = sanitize_key( (string) $ov['align'] );
            }
            if ( isset( $ov['className'] ) ) {
                // Merge without losing the key tokens.
                $extra = trim( (string) $ov['className'] );
                if ( '' !== $extra ) {
                    $attrs['className'] = trim( $attrs['className'] . ' ' . $extra );
                }
            }
            if ( isset( $ov['style'] ) && is_array( $ov['style'] ) ) {
                $existing_style = isset( $attrs['style'] ) && is_array( $attrs['style'] ) ? $attrs['style'] : [];
                // Deep merge to preserve template block supports (e.g. sticky position) while applying overrides.
                $attrs['style'] = self::merge_block_style_arrays( $existing_style, $ov['style'] );
            }
            if ( isset( $ov['dimRatio'] ) ) {
                $attrs['dimRatio'] = max( 0, min( 100, absint( $ov['dimRatio'] ) ) );
            }
            if ( isset( $ov['minHeight'] ) ) {
                $attrs['minHeight'] = max( 0, absint( $ov['minHeight'] ) );
            }
            if ( isset( $ov['contentPosition'] ) ) {
                $attrs['contentPosition'] = sanitize_text_field( (string) $ov['contentPosition'] );
            }
            if ( isset( $ov['focalPoint'] ) && is_array( $ov['focalPoint'] ) ) {
                $attrs['focalPoint'] = $ov['focalPoint'];
            }
            if ( isset( $ov['moreText'] ) ) {
                $attrs['moreText'] = sanitize_text_field( (string) $ov['moreText'] );
            }
            if ( isset( $ov['lessText'] ) ) {
                $attrs['lessText'] = sanitize_text_field( (string) $ov['lessText'] );
            }
            $parsed_block['attrs'] = $attrs;
        }

        if ( ! is_array( $values ) || ! array_key_exists( $key, $values ) ) {
            // Visibility can be controlled even when the value doesn't exist (template fallback).
            if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) {
                $ov = $overrides[ $key ];
                if ( array_key_exists( 'visible', $ov ) && empty( $ov['visible'] ) ) {
                    return self::empty_parsed_block( $parsed_block );
                }
                if ( ! empty( $ov['hideIfEmpty'] ) ) {
                    return self::empty_parsed_block( $parsed_block );
                }
            }

            return $parsed_block;
        }

        $value = $values[ $key ];

        if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) {
            $ov = $overrides[ $key ];
            if ( array_key_exists( 'visible', $ov ) && empty( $ov['visible'] ) ) {
                return self::empty_parsed_block( $parsed_block );
            }
            if ( ! empty( $ov['hideIfEmpty'] ) && self::parsed_block_is_empty_value( $block_name, $value ) ) {
                return self::empty_parsed_block( $parsed_block );
            }
        }

        if ( 'estandar/gallery' === $block_name ) {
            $ids = self::parse_gallery_ids_from_value( $value );
            if ( empty( $ids ) ) {
                return self::empty_parsed_block( $parsed_block );
            }

            $attrs['ids'] = implode( ',', $ids );
            $parsed_block['attrs'] = $attrs;
            return $parsed_block;
        }

        if ( 'core/video' === $block_name && is_string( $value ) ) {
            $url = trim( (string) $value );
            if ( '' === $url ) {
                return self::empty_parsed_block( $parsed_block );
            }

            $url = esc_url_raw( $url );
            if ( '' === $url ) {
                return self::empty_parsed_block( $parsed_block );
            }

            $is_youtube = ( false !== stripos( $url, 'youtube.com' ) ) || ( false !== stripos( $url, 'youtu.be' ) );
            if ( $is_youtube ) {
                $oembed_url = $url;
                // Normalize youtu.be short links and strip tracking query args (e.g. ?si=...).
                $host = (string) wp_parse_url( $oembed_url, PHP_URL_HOST );
                $path = (string) wp_parse_url( $oembed_url, PHP_URL_PATH );
                if ( '' !== $host && false !== stripos( $host, 'youtu.be' ) ) {
                    $video_id = trim( $path, '/' );
                    if ( '' !== $video_id ) {
                        $oembed_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
                    }
                } else {
                    // Strip query to reduce provider mismatches (keep canonical watch URL).
                    $scheme = (string) wp_parse_url( $oembed_url, PHP_URL_SCHEME );
                    $host2  = (string) wp_parse_url( $oembed_url, PHP_URL_HOST );
                    $path2  = (string) wp_parse_url( $oembed_url, PHP_URL_PATH );
                    if ( '' !== $scheme && '' !== $host2 && '' !== $path2 ) {
                        $oembed_url = $scheme . '://' . $host2 . $path2;
                    }
                }

                $embed = wp_oembed_get( $oembed_url );
                if ( ! is_string( $embed ) || '' === trim( $embed ) ) {
                    return self::empty_parsed_block( $parsed_block );
                }

                // Allow a minimal iframe subset for trusted oEmbed output (YouTube).
                $allowed = [
                    'iframe' => [
                        'src'             => true,
                        'width'           => true,
                        'height'          => true,
                        'frameborder'     => true,
                        'allow'           => true,
                        'allowfullscreen' => true,
                        'title'           => true,
                        'loading'         => true,
                        'referrerpolicy'  => true,
                    ],
                    'div' => [
                        'class' => true,
                        'style' => true,
                    ],
                    'p' => [
                        'class' => true,
                    ],
                ];
                $embed = wp_kses( $embed, $allowed );
                if ( '' === trim( $embed ) ) {
                    return self::empty_parsed_block( $parsed_block );
                }

                $parsed_block['innerHTML']    = $embed;
                $parsed_block['innerContent'] = [ $embed ];
                return $parsed_block;
            }

            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, [ 'mp4', 'webm', 'ogg', 'ogv' ], true ) ) {
                return self::empty_parsed_block( $parsed_block );
            }

            $mime = 'video/mp4';
            if ( 'webm' === $ext ) {
                $mime = 'video/webm';
            } elseif ( in_array( $ext, [ 'ogg', 'ogv' ], true ) ) {
                $mime = 'video/ogg';
            }

            $html = '<figure class="wp-block-video"><video controls playsinline preload="metadata"><source src="' . esc_url( $url ) . '" type="' . esc_attr( $mime ) . '"/></video></figure>';
            $parsed_block['innerHTML']    = $html;
            $parsed_block['innerContent'] = [ $html ];
            return $parsed_block;
        }

        // Media/button blocks: override attrs/content BEFORE render (no HTML hacks).
        if ( 'core/image' === $block_name ) {
            $img_id = absint( $value );
            if ( ! $img_id ) {
                // Explicit clear if key exists.
                $attrs['id']  = 0;
                $attrs['url'] = '';
                $parsed_block['attrs']        = $attrs;
                $parsed_block['innerHTML']    = '';
                $parsed_block['innerContent'] = [ '' ];
                return $parsed_block;
            }

            $attrs['id']  = $img_id;
            $attrs['url'] = wp_get_attachment_url( $img_id );
            $parsed_block['attrs'] = $attrs;
            // Prefer attrs-only. Fallback to fragment updates only when the block carries static markup.
            if ( self::inner_html_looks_static_for_image_swap( $parsed_block, 'image' ) ) {
                if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) ) {
                    $parsed_block['innerHTML'] = self::replace_image_refs_in_html( (string) $parsed_block['innerHTML'], $img_id, (string) $attrs['url'], 'image' );
                }
                if ( isset( $parsed_block['innerContent'] ) ) {
                    $parsed_block['innerContent'] = self::replace_image_refs_in_inner_content( $parsed_block['innerContent'], $img_id, (string) $attrs['url'], 'image' );
                }
            }
            return $parsed_block;
        }

        if ( 'core/cover' === $block_name ) {
            $img_id = absint( $value );
            if ( ! $img_id ) {
                $attrs['id']  = 0;
                $attrs['url'] = '';
                $parsed_block['attrs'] = $attrs;
                // Keep inner blocks intact; don't wipe markup here.
                return $parsed_block;
            }

            $attrs['id']  = $img_id;
            $attrs['url'] = wp_get_attachment_url( $img_id );
            if ( empty( $attrs['backgroundType'] ) ) {
                $attrs['backgroundType'] = 'image';
            }
            $parsed_block['attrs'] = $attrs;
            // Prefer attrs-only. Fallback to fragment updates only when static markup exists.
            if ( self::inner_html_looks_static_for_image_swap( $parsed_block, 'cover' ) ) {
                if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) ) {
                    $parsed_block['innerHTML'] = self::replace_image_refs_in_html( (string) $parsed_block['innerHTML'], $img_id, (string) $attrs['url'], 'cover' );
                }
                if ( isset( $parsed_block['innerContent'] ) ) {
                    $parsed_block['innerContent'] = self::replace_image_refs_in_inner_content( $parsed_block['innerContent'], $img_id, (string) $attrs['url'], 'cover' );
                }
            }
            return $parsed_block;
        }

        if ( 'core/button' === $block_name ) {
            $text  = '';
            $url   = '';
            $opens = 0;
            $rel   = '';
            if ( is_array( $value ) ) {
                $text  = (string) ( $value['text'] ?? '' );
                $url   = (string) ( $value['url'] ?? '' );
                $opens = ! empty( $value['opensInNewTab'] ) ? 1 : 0;
                $rel   = (string) ( $value['rel'] ?? '' );
            } else {
                $text = (string) $value;
            }

            // If CPT didn't set URL, keep template default URL.
            if ( '' !== trim( $url ) ) {
                $attrs['url'] = $url;
            }
            if ( $opens ) {
                $attrs['linkTarget'] = '_blank';
            } else {
                unset( $attrs['linkTarget'] );
            }
            if ( '' !== trim( $rel ) ) {
                $attrs['rel'] = $rel;
            } elseif ( $opens ) {
                $attrs['rel'] = 'noopener noreferrer';
            } else {
                unset( $attrs['rel'] );
            }
            $parsed_block['attrs'] = $attrs;

            if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) ) {
                $new_html = self::replace_anchor_text_and_url( (string) $parsed_block['innerHTML'], $text, $url );
                $parsed_block['innerHTML']    = $new_html;
                $parsed_block['innerContent'] = [ $new_html ];
            }
            return $parsed_block;
        }

        // Rich HTML blocks: allow pasting table HTML in a single textarea field.
        if ( ( 'core/html' === $block_name || 'core/table' === $block_name ) && is_string( $value ) ) {
            $html = trim( (string) $value );
            if ( '' === $html ) {
                return self::empty_parsed_block( $parsed_block );
            }

            // Be conservative: only allow post-safe HTML. Tables are allowed by default kses.
            $html = wp_kses_post( $html );

            if ( 'core/table' === $block_name ) {
                // Ensure expected wrapper exists so theme/editor styles apply.
                if ( false === stripos( $html, '<table' ) ) {
                    // If it's not table markup, treat as plain text to avoid odd output.
                    $html = esc_html( wp_strip_all_tags( $html ) );
                } else {
                    // If the user pasted a raw <table>, wrap it. If they pasted a full wp-block-table figure, keep it.
                    if ( false === stripos( $html, 'wp-block-table' ) ) {
                        $html = '<figure class="wp-block-table">' . $html . '</figure>';
                    }
                }
            }

            $parsed_block['innerHTML']    = $html;
            $parsed_block['innerContent'] = [ $html ];
            return $parsed_block;
        }

        // Text-like blocks: heading, paragraph, list item, etc.
        if ( is_string( $value ) ) {
            if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) ) {
                $new_html = self::replace_tag_inner_text( (string) $parsed_block['innerHTML'], (string) $value );
                $parsed_block['innerHTML']    = $new_html;
                $parsed_block['innerContent'] = [ $new_html ];
            }
        }

        return $parsed_block;
    }

    private static function block_placeholder( string $text ): string {
        return '<div style="padding:14px 16px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#334155;font-size:13px;">' . esc_html( $text ) . '</div>';
    }

    public static function render_block_product_title(): string {
        if ( ! self::is_product_context() ) {
            return self::block_placeholder( 'Titulo del producto' );
        }

        return '<h1 class="egm-product-title">' . esc_html( get_the_title() ) . '</h1>';
    }

    public static function render_block_product_excerpt(): string {
        if ( ! self::is_product_context() ) {
            return self::block_placeholder( 'Resumen del producto (excerpt o descripcion corta)' );
        }

        $meta  = self::get_product_meta( self::current_product_id() );
        $short = isset( $meta['short_description'] ) ? trim( (string) $meta['short_description'] ) : '';

        if ( $short ) {
            return '<div class="egm-product-lead">' . wp_kses_post( wpautop( esc_html( $short ) ) ) . '</div>';
        }

        $excerpt = has_excerpt() ? get_the_excerpt() : '';
        if ( $excerpt ) {
            return '<div class="egm-product-lead">' . wp_kses_post( wpautop( $excerpt ) ) . '</div>';
        }

        return '';
    }

    public static function render_block_product_featured_image( array $attributes ): string {
        if ( ! self::is_product_context() ) {
            return self::block_placeholder( 'Imagen destacada del producto' );
        }

        $size = isset( $attributes['sizeSlug'] ) ? sanitize_key( $attributes['sizeSlug'] ) : 'producto-large';
        $html = get_the_post_thumbnail(
            self::current_product_id(),
            $size,
            [
                'loading'  => 'lazy',
                'decoding' => 'async',
            ]
        );

        if ( $html ) {
            return '<figure class="wp-block-image">' . $html . '</figure>';
        }

        return '<div style="min-height:280px;display:flex;align-items:center;justify-content:center;background:#eef2f6;border-radius:18px;color:#667085;">Sin imagen destacada</div>';
    }

    public static function render_block_product_ctas(): string {
        if ( ! self::is_product_context() ) {
            return self::block_placeholder( 'Botones CTA (desde metabox)' );
        }

        $meta = self::get_product_meta( self::current_product_id() );

        $primary_text = trim( (string) ( $meta['primary_cta_text'] ?? 'Cotizar ahora' ) );
        $primary_url  = trim( (string) ( $meta['primary_cta_url'] ?? '' ) );
        $download_text = trim( (string) ( $meta['download_text'] ?? 'Descargar ficha' ) );
        $download_url  = trim( (string) ( $meta['download_url'] ?? '' ) );

        if ( ! $primary_url && ! $download_url ) {
            return '';
        }

        $out = '<div class="wp-block-buttons">';

        if ( $primary_url ) {
            $out .= '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $primary_url ) . '">' . esc_html( $primary_text ) . '</a></div>';
        }

        if ( $download_url ) {
            $out .= '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $download_url ) . '">' . esc_html( $download_text ) . '</a></div>';
        }

        $out .= '</div>';
        return $out;
    }

    public static function render_block_product_content(): string {
        if ( ! self::is_product_context() ) {
            return self::block_placeholder( 'Contenido del producto (bloques del editor)' );
        }

        $raw = (string) get_post_field( 'post_content', self::current_product_id() );
        $raw = trim( $raw );

        if ( '' === $raw ) {
            return '';
        }

        static $rendering = false;
        if ( $rendering ) {
            return '';
        }

        $rendering = true;
        $html      = apply_filters( 'the_content', $raw );
        $rendering = false;

        return '<div class="egm-product-section egm-product-content">' . $html . '</div>';
    }

	    public static function render_block_product_features(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Features (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'features' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'features' );
	        $anchor = self::get_body_section_anchor( 'features', 'caracteristicas' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta     = self::get_product_meta( self::current_product_id() );
	        $features = isset( $meta['features'] ) && is_array( $meta['features'] ) ? array_values( $meta['features'] ) : [];
	        if ( empty( $features ) ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-features estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $out = '<section class="egm-product-section egm-features estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3><div class="wp-block-columns">';
	        foreach ( $features as $feature ) {
	            $title = trim( (string) ( $feature['title'] ?? '' ) );
	            $desc  = trim( (string) ( $feature['desc'] ?? '' ) );
	            $img   = absint( $feature['image_id'] ?? 0 );
            if ( '' === $title && '' === $desc && ! $img ) {
                continue;
            }

            $out .= '<div class="wp-block-column">';
            if ( $img ) {
	                $out .= '<figure class="wp-block-image">' . wp_get_attachment_image( $img, 'producto-thumb', false, egm_img_attrs_lazy() ) . '</figure>';
	            }
            if ( $title ) {
                $out .= '<h3>' . esc_html( $title ) . '</h3>';
            }
            if ( $desc ) {
                $out .= '<p>' . wp_kses_post( wpautop( esc_html( $desc ) ) ) . '</p>';
            }
            $out .= '</div>';
        }
	        $out .= '</div></section>';
	        return $out;
	    }

	    public static function render_block_product_modes(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Modos SLAM/RTK (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'modes' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'modes' );
	        $anchor = self::get_body_section_anchor( 'modes', 'modos' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta     = self::get_product_meta( self::current_product_id() );
	        $mode_rtk = trim( (string) ( $meta['mode_rtk'] ?? '' ) );
	        $mode_slam = trim( (string) ( $meta['mode_slam'] ?? '' ) );

	        if ( '' === $mode_rtk && '' === $mode_slam ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-modes estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $out = '<section class="egm-product-section egm-modes estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3><div class="wp-block-columns">';
	        if ( $mode_rtk ) {
	            $out .= '<div class="wp-block-column egm-product-dark-card"><h3>RTK Mode</h3><p>' . wp_kses_post( wpautop( esc_html( $mode_rtk ) ) ) . '</p></div>';
	        }
	        if ( $mode_slam ) {
            $out .= '<div class="wp-block-column egm-product-dark-card"><h3>SLAM Mode</h3><p>' . wp_kses_post( wpautop( esc_html( $mode_slam ) ) ) . '</p></div>';
        }
        $out .= '</div></section>';
        return $out;
    }

	    public static function render_block_product_metrics(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Metricas (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'metrics' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'metrics' );
	        $anchor = self::get_body_section_anchor( 'metrics', 'metricas' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta    = self::get_product_meta( self::current_product_id() );
	        $metrics = isset( $meta['metrics'] ) && is_array( $meta['metrics'] ) ? array_values( $meta['metrics'] ) : [];
	        if ( empty( $metrics ) ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-metrics estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $out = '<section class="egm-product-section egm-metrics estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3><table><tbody>';
	        foreach ( $metrics as $metric ) {
	            $label = trim( (string) ( $metric['label'] ?? '' ) );
	            $value = trim( (string) ( $metric['value'] ?? '' ) );
	            $note  = trim( (string) ( $metric['note'] ?? '' ) );
            if ( '' === $label && '' === $value && '' === $note ) {
                continue;
            }
            $out .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( trim( $value . ' ' . $note ) ) . '</td></tr>';
        }
        $out .= '</tbody></table></section>';
        return $out;
    }

	    public static function render_block_product_showcase(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Showcase (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'showcase' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'showcase' );
	        $anchor = self::get_body_section_anchor( 'showcase', 'showcase' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta     = self::get_product_meta( self::current_product_id() );
	        $showcase = isset( $meta['showcase'] ) && is_array( $meta['showcase'] ) ? array_values( $meta['showcase'] ) : [];
	        if ( empty( $showcase ) ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-showcase estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $out = '<section class="egm-product-section egm-showcase estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3><div class="wp-block-gallery columns-3">';
	        foreach ( $showcase as $item ) {
	            $img = absint( $item['image_id'] ?? 0 );
	            if ( ! $img ) {
	                continue;
            }
            $title = trim( (string) ( $item['title'] ?? '' ) );
	            $out .= '<figure class="wp-block-image">' . wp_get_attachment_image( $img, 'producto-thumb', false, egm_img_attrs_lazy() );
            if ( $title ) {
                $out .= '<figcaption>' . esc_html( $title ) . '</figcaption>';
            }
            $out .= '</figure>';
        }
        $out .= '</div></section>';
        return $out;
    }

	    public static function render_block_product_specs(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Especificaciones (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'specs' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'specs' );
	        $anchor = self::get_body_section_anchor( 'specs', 'especificaciones' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta  = self::get_product_meta( self::current_product_id() );
	        $specs = isset( $meta['specs'] ) && is_array( $meta['specs'] ) ? array_values( $meta['specs'] ) : [];
	        if ( empty( $specs ) ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-specs estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $groups = [];
	        foreach ( $specs as $spec ) {
            if ( ! is_array( $spec ) ) {
                continue;
            }
            $group = trim( (string) ( $spec['group'] ?? '' ) );
            $group = $group ?: 'General';
            $groups[ $group ][] = $spec;
        }

	        $out = '<section class="egm-product-section egm-specs estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3>';
	        foreach ( $groups as $group => $rows ) {
	            $out .= '<h4>' . esc_html( $group ) . '</h4><table><tbody>';
	            foreach ( $rows as $row ) {
	                $label = trim( (string) ( $row['label'] ?? '' ) );
	                $value = trim( (string) ( $row['value'] ?? '' ) );
	                if ( '' === $label && '' === $value ) {
                    continue;
                }
                $out .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
            }
            $out .= '</tbody></table>';
        }
        $out .= '</section>';
        return $out;
    }

	    public static function render_block_product_applications(): string {
	        if ( ! self::is_product_context() ) {
	            return self::block_placeholder( 'Aplicaciones (desde metabox)' );
	        }

	        if ( ! self::is_body_section_active( 'applications' ) ) {
	            return '';
	        }

	        $section_title  = self::get_body_section_title( 'applications' );
	        $anchor = self::get_body_section_anchor( 'applications', 'aplicaciones' );
	        $overrides_enabled = self::is_product_context() && ! empty( self::get_product_section_overrides( self::current_product_id() ) );

	        $meta         = self::get_product_meta( self::current_product_id() );
	        $applications = isset( $meta['applications'] ) && is_array( $meta['applications'] ) ? array_values( $meta['applications'] ) : [];
	        if ( empty( $applications ) ) {
	            return $overrides_enabled
	                ? '<section class="egm-product-section egm-applications estandar-cpt-section is-empty" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3></section>'
	                : '';
	        }

	        $out = '<section class="egm-product-section egm-applications estandar-cpt-section" id="' . esc_attr( $anchor ) . '"><h3>' . esc_html( $section_title ) . '</h3><div class="wp-block-columns">';
	        foreach ( $applications as $app ) {
	            $title = trim( (string) ( $app['title'] ?? '' ) );
	            $img   = absint( $app['image_id'] ?? 0 );
	            if ( '' === $title && ! $img ) {
                continue;
            }
            $out .= '<div class="wp-block-column">';
            if ( $img ) {
	                $out .= '<figure class="wp-block-image">' . wp_get_attachment_image( $img, 'producto-thumb', false, egm_img_attrs_lazy() ) . '</figure>';
	            }
            if ( $title ) {
                $out .= '<p>' . esc_html( $title ) . '</p>';
            }
            $out .= '</div>';
        }
        $out .= '</div></section>';
        return $out;
    }

	    public static function enqueue_frontend_assets() {
        $is_producto = is_singular( 'producto' );
        $is_producto_archive  = is_post_type_archive( 'producto' );
        $is_producto_category = is_category() && 'producto' === sanitize_key( (string) get_query_var( 'post_type' ) );

        if ( ! $is_producto && ! $is_producto_archive && ! $is_producto_category ) {
            return;
        }

	        wp_register_style( 'estandar-cpt-manager-producto-template', false, [], self::VERSION );
        wp_enqueue_style( 'estandar-cpt-manager-producto-template' );
        wp_add_inline_style(
            'estandar-cpt-manager-producto-template',
            self::product_template_css() . "\n" .
            self::category_template_css() . "\n" .
            self::archive_zoom_effect_css()
        );

		        wp_register_script( 'estandar-cpt-manager-archive-zoom', false, [], self::VERSION, true );
	        wp_enqueue_script( 'estandar-cpt-manager-archive-zoom' );
	        wp_add_inline_script( 'estandar-cpt-manager-archive-zoom', self::archive_zoom_effect_js() . "\n" . self::product_section_nav_js() );
	    }

	    private static function product_section_nav_js(): string {
	        return <<<'JS'
(function(){
    var KEY = '__estandarCptNavInit__';
    try {
        if (window && window[KEY]) return;
        if (window) window[KEY] = true;
    } catch (_) {}

    // Estandar UI Module: Section Navigation
    // - class: estandar-cpt-section-nav
    // - behavior: sticky + active tracking + underline + optional horizontal centering
    // - dependencies: none
    var DEBUG = false;
    function dbg(){
        try {
            if (!DEBUG || !window.console || !console.log) return;
            console.log.apply(console, arguments);
        } catch (_) {}
    }

    function prefersReducedMotion(){
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (_) {
            return false;
        }
    }

    function getAdminBarHeight(){
        // WP admin bar: 32 desktop, 46 mobile. Prefer computed height if present.
        var bar = document.getElementById('wpadminbar');
        if (!bar) return 0;
        var rect = bar.getBoundingClientRect();
        return rect && rect.height ? rect.height : 0;
    }

    function smoothScrollToId(id){
        if (!id) return;
        var el = document.getElementById(id);
        if (!el) return;
        var nav = document.querySelector('.estandar-cpt-section-nav');
        var navH = nav ? nav.getBoundingClientRect().height : 0;
        var offset = getAdminBarHeight() + navH + 12;
        var top = window.pageYOffset + el.getBoundingClientRect().top - offset;
        window.scrollTo({ top: Math.max(0, top), behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    }

    function init(){
        var nav = document.querySelector('.estandar-cpt-section-nav');
        if (!nav) return;

        var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^=\"#\"]'));
        var sections = [];
	        links.forEach(function(a){
	            var href = a.getAttribute('href') || '';
	            if (href.length < 2) return;
	            var id = href.slice(1);
	            var el = document.getElementById(id);
	            if (!el) return;
	            // Do not track empty/placeholder sections (anchors still exist, but we avoid "ghost" active states).
	            if (el.classList && el.classList.contains('is-empty')) return;
	            sections.push({ id: id, el: el, link: a });
	        });

	        function clearActiveState(){
	            links.forEach(function(a){
	                a.classList.remove('is-active');
	                a.removeAttribute('aria-current');
	            });
	            try {
	                nav.style.setProperty('--indicator-left', '0px');
	                nav.style.setProperty('--indicator-width', '0px');
	            } catch (_) {}
	        }

        if (!sections.length) {
            clearActiveState();
            return;
        }

        var currentActiveId = '';
        var lastIndicatorId = '';

        function updateIndicator(activeLink){
            try {
                if (!activeLink) {
                    nav.style.setProperty('--indicator-left', '0px');
                    nav.style.setProperty('--indicator-width', '0px');
                    lastIndicatorId = '';
                    return;
                }
                var rect = activeLink.getBoundingClientRect();
                var navRect = nav.getBoundingClientRect();
                nav.style.setProperty('--indicator-left', (rect.left - navRect.left) + 'px');
                nav.style.setProperty('--indicator-width', rect.width + 'px');
            } catch (_) {}
        }

        function updateIndicatorForActive(){
            if (!currentActiveId) return;
            var a = nav.querySelector('a.is-active');
            if (!a) return;
            updateIndicator(a);
        }

        function setActive(id){
            var found = false;
            sections.forEach(function(s){
                var on = (s.id === id);
	                if (on) {
	                    found = true;
	                    s.link.classList.add('is-active');
	                    s.link.setAttribute('aria-current', 'true');
	                } else {
	                    s.link.classList.remove('is-active');
	                    s.link.removeAttribute('aria-current');
	                }
	            });
	            // If the target isn't trackable (e.g. empty section), clear active state to avoid misleading highlights.
	            if (!found) {
	                clearActiveState();
	                currentActiveId = '';
                return;
            }
            // Animated underline indicator (Phase 1): update only when active section changes.
            var activeLink = nav.querySelector('a.is-active');
            if (activeLink && id !== lastIndicatorId) {
                updateIndicator(activeLink);
                lastIndicatorId = id;
            } else if (!activeLink) {
                updateIndicator(null);
            }

            // Phase 2: keep the active item visible/centered when the nav overflows horizontally.
            try {
                var activeLink2 = nav.querySelector('a.is-active');
                if (activeLink2) {
                    var linkRect = activeLink2.getBoundingClientRect();
                    var navRect2 = nav.getBoundingClientRect();
                    var fullyVisible = linkRect.left >= navRect2.left && linkRect.right <= navRect2.right;
                    if (!fullyVisible && activeLink2.scrollIntoView) {
                        activeLink2.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', inline: 'center', block: 'nearest' });
                        // Recompute underline after the nav scrolls.
                        window.requestAnimationFrame(function(){
                            updateIndicator(activeLink2);
                        });
                    }
                }
            } catch (_) {}
            currentActiveId = id;
        }

	        function getOffset(){
	            var navH = nav ? nav.getBoundingClientRect().height : 0;
	            return getAdminBarHeight() + navH + 12;
	        }

	        function updateActiveByScroll(){
	            if (!sections.length) return;

	            var scrollBottom = (window.innerHeight + window.pageYOffset) >= (document.documentElement.scrollHeight - 2);
	            if (scrollBottom) {
	                var lastId = sections[sections.length - 1].id;
	                if (lastId && lastId !== currentActiveId) {
	                    setActive(lastId);
	                    try {
	                        if (window.history && window.history.replaceState) {
	                            window.history.replaceState(null, '', '#' + lastId);
	                        }
	                    } catch (_) {}
	                }
	                return;
	            }

	            var offset = getOffset();
	            var best = sections[0].id;
	            for (var i = 0; i < sections.length; i++) {
	                var rect = sections[i].el.getBoundingClientRect();
	                if (!rect) continue;
	                if ((rect.top - offset) <= 1) {
	                    best = sections[i].id;
	                } else {
	                    break;
	                }
	            }
	            if (best && best !== currentActiveId) {
	                setActive(best);
	                try {
	                    if (window.history && window.history.replaceState) {
	                        window.history.replaceState(null, '', '#' + best);
	                    }
	                } catch (_) {}
	            }
	        }

        var ticking = false;
        function onScroll(){
            if (ticking) return;
            ticking = true;
	            window.requestAnimationFrame(function(){
	                ticking = false;
	                updateActiveByScroll();
	            });
	        }

        nav.addEventListener('click', function(e){
	            var t = e.target;
	            if (!t || !t.closest) return;
	            var a = t.closest('a[href^="#"]');
	            if (!a) return;
	            var href = a.getAttribute('href') || '';
	            if (href.length < 2) return;
	            e.preventDefault();
	            var id = href.slice(1);
	            setActive(id);
	            try {
	                if (window.history && window.history.replaceState) {
	                    window.history.replaceState(null, '', '#' + id);
	                }
	            } catch (_) {}
            smoothScrollToId(id);
        }

        nav.addEventListener('keydown', function(e){
            if (!e) return;
            var key = e.key || '';
            if (key !== 'ArrowRight' && key !== 'ArrowLeft') return;
            var linksAll = Array.prototype.slice.call(nav.querySelectorAll('a[href^=\"#\"]'));
            if (!linksAll.length) return;

            var i = -1;
            // Prefer focused link if inside nav; fallback to active.
            var focused = document.activeElement && nav.contains(document.activeElement) ? document.activeElement : null;
            if (focused && focused.tagName && focused.tagName.toLowerCase() === 'a') {
                i = linksAll.indexOf(focused);
            }
            if (i === -1) {
                for (var k = 0; k < linksAll.length; k++) {
                    if (linksAll[k].classList && linksAll[k].classList.contains('is-active')) { i = k; break; }
                }
            }
            if (i === -1) return;

            if (key === 'ArrowRight') {
                e.preventDefault();
                linksAll[Math.min(i + 1, linksAll.length - 1)].focus();
            }
            if (key === 'ArrowLeft') {
                e.preventDefault();
                linksAll[Math.max(i - 1, 0)].focus();
            }
        });

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        window.addEventListener('resize', function(){ updateIndicatorForActive(); }, { passive: true });
        window.addEventListener('orientationchange', function(){ updateIndicatorForActive(); }, { passive: true });
        window.addEventListener('hashchange', onScroll, { passive: true });

	        // Initial state.
	        if (window.location && window.location.hash && window.location.hash.length > 1) {
	            setActive(window.location.hash.slice(1));
	        }
	        onScroll();

        // If we loaded with a hash or the active link starts out off-screen, center it once without animation.
        window.requestAnimationFrame(function(){
            try {
                var a0 = nav.querySelector('a.is-active');
                if (a0 && a0.scrollIntoView) {
                    a0.scrollIntoView({ behavior: 'auto', inline: 'center', block: 'nearest' });
                    updateIndicator(a0);
                }
            } catch (_) {}
        });

	        try {
	            if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
	                document.fonts.ready.then(function(){ updateIndicatorForActive(); });
	            }
	        } catch (_) {}

	        // Dynamic content safety: builders/lazy-loaders can insert content after load.
	        // Keep this observer minimal and throttle work with requestAnimationFrame.
	        try {
	            if (window.MutationObserver && document.body) {
	                var moTicking = false;
	                var mo = new MutationObserver(function(){
	                    if (!currentActiveId) return;
	                    if (moTicking) return;
	                    moTicking = true;
	                    window.requestAnimationFrame(function(){
	                        moTicking = false;
	                        updateIndicatorForActive();
	                    });
	                });
	                mo.observe(document.body, { childList: true, subtree: true });
	                window.addEventListener('beforeunload', function(){
	                    try { mo.disconnect(); } catch (_) {}
	                }, { passive: true });
	            }
	        } catch (_) {}
	    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
	    }

	    private static function product_template_css(): string {
	        return <<<'CSS'
body.single-producto {
    background: #fff;
}

body.single-producto .site,
body.single-producto .site-main,
body.single-producto .entry-content {
    background: #fff;
}

.egm-product-page-wrapper :where(.alignfull) {
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    max-width: 100vw;
    width: 100vw;
}

/* Internal sticky nav (scoped to producto pages only). */
body.single-producto .estandar-cpt-section-nav {
    position: sticky;
    top: 0;
    z-index: 50;
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(15,23,42,0.08);
    padding: 10px 12px;
    display: flex;
    flex-wrap: wrap;
    justify-content: center !important;
    gap: 10px;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 auto !important;
    box-sizing: border-box;
    will-change: transform;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable both-edges;
    scrollbar-width: none;
    --indicator-left: 0px;
    --indicator-width: 0px;
}
body.single-producto .estandar-cpt-section-nav::-webkit-scrollbar {
    display: none;
}
body.single-producto .estandar-cpt-section-nav::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    height: 2px;
    width: var(--indicator-width, 0);
    background: #0f172a;
    transform: translateX(var(--indicator-left, 0));
    transition: transform 0.35s ease, width 0.35s ease;
    pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
    body.single-producto .estandar-cpt-section-nav::after {
        transition: none;
    }
    body.single-producto .estandar-cpt-section-nav {
        scroll-behavior: auto;
    }
}
body.admin-bar.single-producto .estandar-cpt-section-nav {
    top: 32px;
}
@media (max-width: 782px) {
    body.admin-bar.single-producto .estandar-cpt-section-nav { top: 46px; }
}
body.single-producto .estandar-cpt-section-nav a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 12px;
    border: 1px solid rgba(15,23,42,0.12);
    border-radius: 9999px;
    background: #fff;
    color: #0f172a;
    font-weight: 600;
    text-decoration: none;
    line-height: 1;
    font-size: 13px;
}
body.single-producto .estandar-cpt-section-nav a:hover {
    border-color: rgba(15,23,42,0.22);
}
body.single-producto .estandar-cpt-section-nav a.is-active,
body.single-producto .estandar-cpt-section-nav a[aria-current="true"] {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
}

body.single-producto .egm-product-section {
    scroll-margin-top: 120px;
}
body.admin-bar.single-producto .egm-product-section {
    scroll-margin-top: 160px;
}

/* Keep anchor targets without adding visual noise when a section is enabled but has no content yet.
   We keep the <section id="..."> so the nav remains functional, but hide the heading/spacing. */
body.single-producto .estandar-cpt-section,
body.post-type-producto .estandar-cpt-section {
    scroll-margin-top: 120px;
}
body.admin-bar.single-producto .estandar-cpt-section,
body.admin-bar.post-type-producto .estandar-cpt-section {
    scroll-margin-top: 160px;
}

body.single-producto .estandar-cpt-section.is-empty,
body.post-type-producto .estandar-cpt-section.is-empty {
    min-height: 40px;
    padding: 6px 0;
    margin: 0;
}
body.single-producto .estandar-cpt-section.is-empty h3,
body.post-type-producto .estandar-cpt-section.is-empty h3 {
    opacity: 0.45;
    font-size: 14px;
    margin: 0;
}
CSS;
	    }

	    public static function filter_render_block_product_anchor_nav( string $block_content, array $block ): string {
	        if ( is_admin() ) {
	            return $block_content;
	        }
	        if ( ! self::is_product_context() ) {
	            return $block_content;
	        }
	        if ( ! isset( $block['blockName'] ) || 'core/group' !== (string) $block['blockName'] ) {
	            return $block_content;
	        }
	        $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
	        $class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';
	        if ( '' === $class_name || ! self::class_has_token( $class_name, 'egm-product-anchor-nav' ) ) {
	            return $block_content;
	        }

	        $settings = self::get_product_template_settings();
	        if ( empty( $settings['show_anchor_nav'] ) ) {
	            return '';
	        }

	        $items = self::get_active_body_section_nav_items();
	        if ( count( $items ) < 2 ) {
	            return '';
	        }

	        $nav_class = apply_filters( 'estandar_cpt_nav_class', 'estandar-cpt-section-nav', get_post_type() );
	        $nav_class = is_string( $nav_class ) ? trim( $nav_class ) : 'estandar-cpt-section-nav';
	        if ( '' === $nav_class ) {
	            $nav_class = 'estandar-cpt-section-nav';
	        }
	        // Safety: keep the canonical base class so CSS/JS continues to work even if filters add custom classes.
	        if ( false === strpos( ' ' . $nav_class . ' ', ' estandar-cpt-section-nav ' ) ) {
	            $nav_class = 'estandar-cpt-section-nav ' . $nav_class;
	            $nav_class = trim( preg_replace( '/\s+/', ' ', $nav_class ) );
	        }

	        $out = '<nav class="' . esc_attr( $nav_class ) . '" aria-label="Navegación interna">';
	        foreach ( $items as $it ) {
	            $out .= '<a href="#' . esc_attr( $it['anchor'] ) . '">' . esc_html( $it['label'] ) . '</a>';
	        }
	        $out .= '</nav>';
	        return $out;
	    }

	    private static function get_active_body_section_nav_items(): array {
	        $settings = self::get_product_template_settings();
	        $post_id  = self::current_product_id();
	        $meta     = self::get_product_meta( $post_id );
	        $overrides_enabled = ! empty( self::get_product_section_overrides( $post_id ) );

	        $items = [];
	        $defs = [
	            'features' => [ 'default_anchor' => 'caracteristicas', 'nav_key' => 'nav_features' ],
	            'modes' => [ 'default_anchor' => 'modos', 'nav_key' => 'nav_modes' ],
	            'metrics' => [ 'default_anchor' => 'metricas', 'nav_key' => 'nav_metrics' ],
	            'showcase' => [ 'default_anchor' => 'showcase', 'nav_key' => 'nav_showcase' ],
	            'specs' => [ 'default_anchor' => 'especificaciones', 'nav_key' => 'nav_specs' ],
	            'applications' => [ 'default_anchor' => 'aplicaciones', 'nav_key' => 'nav_applications' ],
	        ];

	        foreach ( $defs as $id => $d ) {
	            if ( ! self::is_body_section_active( $id ) ) {
	                continue;
	            }
	            // If a product has explicit overrides enabled, trust the user's selection and avoid auto-hiding
	            // nav items based on legacy meta arrays (the section may be present via template blocks).
	            if ( ! $overrides_enabled && ! self::body_section_has_content( $id, $meta ) ) {
	                continue;
	            }
	            $ov = self::get_section_override( $id );
	            $label = ( isset( $ov['title'] ) && '' !== trim( (string) $ov['title'] ) ) ? sanitize_text_field( (string) $ov['title'] ) : '';
	            if ( '' === $label ) {
	                $label = trim( (string) ( $settings[ $d['nav_key'] ] ?? '' ) );
	            }
	            $label = $label ?: self::get_body_section_title( $id );
	            $anchor = self::get_body_section_anchor( $id, (string) $d['default_anchor'] );
	            if ( '' === $label || '' === $anchor ) {
	                continue;
	            }
	            $items[] = [ 'anchor' => $anchor, 'label' => $label ];
	        }

	        return $items;
	    }

	    private static function body_section_has_content( string $id, array $meta ): bool {
	        if ( 'features' === $id ) {
	            $rows = isset( $meta['features'] ) && is_array( $meta['features'] ) ? array_filter( $meta['features'] ) : [];
	            return ! empty( $rows );
	        }
	        if ( 'modes' === $id ) {
	            return '' !== trim( (string) ( $meta['mode_rtk'] ?? '' ) ) || '' !== trim( (string) ( $meta['mode_slam'] ?? '' ) );
	        }
	        if ( 'metrics' === $id ) {
	            $rows = isset( $meta['metrics'] ) && is_array( $meta['metrics'] ) ? array_filter( $meta['metrics'] ) : [];
	            return ! empty( $rows );
	        }
	        if ( 'showcase' === $id ) {
	            $rows = isset( $meta['showcase'] ) && is_array( $meta['showcase'] ) ? array_filter( $meta['showcase'] ) : [];
	            return ! empty( $rows );
	        }
	        if ( 'specs' === $id ) {
	            $rows = isset( $meta['specs'] ) && is_array( $meta['specs'] ) ? array_filter( $meta['specs'] ) : [];
	            return ! empty( $rows );
	        }
	        if ( 'applications' === $id ) {
	            $rows = isset( $meta['applications'] ) && is_array( $meta['applications'] ) ? array_filter( $meta['applications'] ) : [];
	            return ! empty( $rows );
	        }
	        return true;
	    }

	    private static function get_product_section_overrides( int $post_id ): array {
	        $raw = get_post_meta( $post_id, self::PRODUCT_SECTIONS_META_KEY, true );
	        return is_array( $raw ) ? $raw : [];
	    }

	    private static function get_section_override( string $id ): array {
	        if ( ! self::is_product_context() ) {
	            return [];
	        }
	        $all = self::get_product_section_overrides( self::current_product_id() );
	        $row = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : [];
	        return $row;
	    }

	    private static function is_body_section_active( string $id ): bool {
	        $ov = self::get_section_override( $id );
	        if ( array_key_exists( 'active', $ov ) ) {
	            return (bool) absint( $ov['active'] );
	        }
	        $settings = self::get_product_template_settings();
	        $map = [
	            'content' => 'show_content',
	            'features' => 'show_features',
	            'modes' => 'show_modes',
	            'metrics' => 'show_metrics',
	            'showcase' => 'show_showcase',
	            'specs' => 'show_specs',
	            'applications' => 'show_applications',
	            'form' => 'show_form',
	        ];
	        $k = $map[ $id ] ?? '';
	        return '' === $k ? true : ! empty( $settings[ $k ] );
	    }

	    private static function get_body_section_title( string $id ): string {
	        $ov = self::get_section_override( $id );
	        if ( isset( $ov['title'] ) && '' !== trim( (string) $ov['title'] ) ) {
	            return sanitize_text_field( (string) $ov['title'] );
	        }
	        $settings = self::get_product_template_settings();
	        $map = [
	            'features' => 'title_features',
	            'modes' => 'title_modes',
	            'metrics' => 'title_metrics',
	            'showcase' => 'title_showcase',
	            'specs' => 'title_specs',
	            'applications' => 'title_applications',
	            'form' => 'title_form',
	        ];
	        $k = $map[ $id ] ?? '';
	        $t = $k ? (string) ( $settings[ $k ] ?? '' ) : '';
	        $t = trim( $t );
	        if ( '' !== $t ) {
	            return $t;
	        }
	        // Hard fallback (legacy).
	        $fallbacks = [
	            'features' => 'Caracteristicas',
	            'modes' => 'Modos de operacion',
	            'metrics' => 'Metricas',
	            'showcase' => 'Showcase',
	            'specs' => 'Especificaciones',
	            'applications' => 'Aplicaciones',
	            'form' => 'Formulario',
	        ];
	        return $fallbacks[ $id ] ?? 'Sección';
	    }

	    private static function get_body_section_anchor( string $id, string $default ): string {
	        $ov = self::get_section_override( $id );
	        if ( isset( $ov['anchor'] ) ) {
	            $a = sanitize_key( (string) $ov['anchor'] );
	            if ( '' !== $a ) {
	                return $a;
	            }
	        }
	        return sanitize_key( $default );
	    }

    private static function category_template_css(): string {
        return <<<'CSS'
/* Astra often constrains archives inside a fixed-width container.
   Only override when rendering our plugin category template. */
body.egm-cat-producto-template .ast-container,
body.egm-cat-producto-template .site-content .ast-container,
body.egm-cat-producto-template .site-main,
body.egm-cat-producto-template #primary {
    max-width: 100% !important;
    width: 100% !important;
}
body.egm-cat-producto-template .ast-container {
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* Wrapper breaks out to true full width */
body.egm-cat-producto-template .egm-category-page-wrapper {
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    max-width: 100vw;
    width: 100vw;
}

/* Still support alignfull blocks inside the wrapper (hero cover, full-width sections). */
body.egm-cat-producto-template .egm-category-page-wrapper :where(.alignfull) {
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    max-width: 100vw;
    width: 100vw;
}
CSS;
    }

    private static function archive_zoom_effect_css(): string {
        return <<<'CSS'
/* Hover + scroll zoom effect (used by product category gallery; reused for product archives). */
.egm-zoom-scope .egm-zoom-image,
.egm-zoom-scope .wp-block-post-featured-image img {
    --egm-scroll-scale: 1;
    transform: scale(var(--egm-scroll-scale));
    transition: transform 520ms ease;
    will-change: transform;
}

.egm-zoom-scope .egm-zoom-hover:hover .egm-zoom-image,
.egm-zoom-scope .wp-block-post-featured-image:hover img {
    transform: scale(calc(var(--egm-scroll-scale) * var(--egm-hover-scale, 1.08)));
}
CSS;
    }

    private static function archive_zoom_effect_js(): string {
        return <<<'JS'
(function(){
    function toNum(v, fallback){
        var n = parseFloat(v);
        return isFinite(n) ? n : fallback;
    }

    function initScope(scope){
        if (!scope || scope.__egmZoomInit) return;
        scope.__egmZoomInit = true;

        var hoverPercent = toNum(scope.getAttribute('data-egm-hover-zoom') || scope.getAttribute('data-hover-zoom') || '8', 8);
        var hoverScale = 1 + (hoverPercent / 100);

        // Store on hover wrappers so CSS can pick it up.
        var hoverWrappers = scope.querySelectorAll('.egm-zoom-hover, .wp-block-post-featured-image');
        hoverWrappers.forEach(function(w){
            w.style.setProperty('--egm-hover-scale', String(hoverScale));
        });

        var scrollEnabled = (scope.getAttribute('data-egm-scroll-zoom') || scope.getAttribute('data-scroll-zoom') || '0') === '1';
        if (!scrollEnabled) return;

        var maxZoomPercent = toNum(scope.getAttribute('data-egm-scroll-zoom-percent') || scope.getAttribute('data-scroll-zoom-percent') || '10', 10);
        var maxZoom = maxZoomPercent / 100;

        var images = [];
        scope.querySelectorAll('.egm-zoom-image, .wp-block-post-featured-image img').forEach(function(img){
            images.push(img);
        });
        if (!images.length) return;

        var ticking = false;
        function update(){
            ticking = false;
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            if (!viewportHeight) return;

            images.forEach(function(img){
                var rect = img.getBoundingClientRect();
                if (!rect || rect.height === 0) return;
                var center = rect.top + (rect.height / 2);
                var distance = Math.abs((viewportHeight / 2) - center);
                var progress = Math.max(0, 1 - (distance / viewportHeight));
                var scale = 1 + (maxZoom * progress);
                img.style.setProperty('--egm-scroll-scale', scale.toFixed(3));
            });
        }

        function onScroll(){
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        onScroll();
    }

    function initAll(){
        document.querySelectorAll('.egm-zoom-scope').forEach(initScope);
        // Also enable for our category template wrapper (query loop of productos).
        document.querySelectorAll('.egm-category-page-wrapper').forEach(function(w){
            if (!w.classList.contains('egm-zoom-scope')) w.classList.add('egm-zoom-scope');
            if (!w.getAttribute('data-egm-hover-zoom')) w.setAttribute('data-egm-hover-zoom', '8');
            if (!w.getAttribute('data-egm-scroll-zoom')) w.setAttribute('data-egm-scroll-zoom', '1');
            if (!w.getAttribute('data-egm-scroll-zoom-percent')) w.setAttribute('data-egm-scroll-zoom-percent', '10');
            initScope(w);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
JS;
    }

    private static function product_template_admin_css(): string {
        return <<<'CSS'
.postbox .egm-product-page-wrapper :where(.alignfull) {
    margin-left: 0 !important;
    margin-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}
.postbox #egm-tpl-preview {
    min-height: 120px;
}
.postbox .egm-product-hero {
    padding: 24px 0;
}
.postbox .egm-product-anchor-nav {
    position: static;
}
CSS;
    }

    private static function product_template_editor_canvas_css(): string {
        // Minimal, editor-safe styles for the injected preview container.
        return <<<'CSS'
/* Canvas container */
.egm-editor-template-canvas {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 14px;
    margin: 16px 0 18px;
    background: #fff;
}
.egm-editor-template-canvas__top {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.egm-editor-template-canvas__title {
    font-weight: 650;
    color: #0f172a;
    font-size: 14px;
}
.egm-editor-template-canvas__hint {
    color: #64748b;
    font-size: 12px;
}
.egm-editor-template-preview {
    border: 1px solid #eef2f7;
    border-radius: 14px;
    padding: 12px;
    background: #fff;
    overflow: hidden;
}
.egm-editor-template-preview :where(.alignfull) {
    margin-left: 0 !important;
    margin-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}
.egm-editor-template-fields {
    margin-top: 12px;
    display: grid;
    gap: 10px;
}
.egm-editor-template-field {
    border: 1px solid #eef2f7;
    border-radius: 12px;
    padding: 10px;
    background: #fff;
}
.egm-editor-template-field label {
    display:block;
    font-weight: 600;
    margin-bottom: 6px;
}
.egm-editor-template-field__key {
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
}
.egm-editor-template-field summary {
    cursor: pointer;
    list-style: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-weight: 650;
    color: #0f172a;
    padding: 2px 0;
}
.egm-editor-template-field summary::-webkit-details-marker { display: none; }
.egm-editor-template-field[open] summary { margin-bottom: 10px; }
.egm-editor-template-field__summary-key {
    color: #64748b;
    font-weight: 600;
    font-size: 12px;
}
.egm-editor-template-canvas__collapsed {
    display: none;
}
CSS;
    }

    private static function product_template_preview_css_shared(): string {
        // Editor + preview: reuse parts of frontend CSS that affect structure.
        return <<<'CSS'
.egm-product-page-wrapper :where(.alignfull) {
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    max-width: 100vw;
    width: 100vw;
}
CSS;
    }

    public static function get_defs(): array {
        $defs = get_option( self::OPT_KEY, [] );

        if ( ! is_array( $defs ) ) {
            return [];
        }

        foreach ( $defs as $key => $def ) {
            if ( ! is_array( $def ) ) {
                unset( $defs[ $key ] );
                continue;
            }

            $defs[ $key ] = self::normalize_def( $key, $def );
        }

        return $defs;
    }

    public static function maybe_invert_managed_cpt_archive_order( $query ) {
        if ( is_admin() ) {
            return;
        }
        if ( ! ( $query instanceof WP_Query ) ) {
            return;
        }
        if ( ! $query->is_main_query() ) {
            return;
        }
        if ( ! $query->is_post_type_archive() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( is_array( $post_type ) ) {
            $post_type = reset( $post_type );
        }
        $post_type = is_string( $post_type ) ? sanitize_key( $post_type ) : '';
        if ( '' === $post_type ) {
            return;
        }

        $defs = self::get_defs();
        if ( ! isset( $defs[ $post_type ] ) ) {
            return;
        }

        $order = strtoupper( (string) $query->get( 'order' ) );
        if ( 'DESC' !== $order ) {
            // Only invert direction; keep existing orderby/criteria intact.
            $query->set( 'order', 'DESC' );
        }
    }

    public static function save_defs( array $defs ): bool {
        return update_option( self::OPT_KEY, $defs, false );
    }

    public static function register_all_cpts() {
        $defs = self::get_defs();

        foreach ( $defs as $key => $def ) {
            self::register_one_cpt( $key, $def );
        }

        // Ensure meta is registered on normal init too (activation hook may be skipped in some envs).
        self::register_product_template_meta();
    }

    private static function register_one_cpt( string $key, array $def ) {
        $post_type = sanitize_key( $key );

        if ( empty( $post_type ) ) {
            return;
        }

        $def = self::normalize_def( $post_type, $def );

        $labels = [
            'name'               => $def['plural'],
            'singular_name'      => $def['singular'],
            'menu_name'          => $def['plural'],
            'name_admin_bar'     => $def['singular'],
            'add_new'            => 'Agregar nuevo',
            'add_new_item'       => "Agregar nuevo {$def['singular']}",
            'new_item'           => "Nuevo {$def['singular']}",
            'edit_item'          => "Editar {$def['singular']}",
            'view_item'          => "Ver {$def['singular']}",
            'all_items'          => "Todos {$def['plural']}",
            'search_items'       => "Buscar {$def['plural']}",
            'not_found'          => "No se encontraron {$def['plural']}",
            'not_found_in_trash' => "No hay {$def['plural']} en la papelera",
            'featured_image'     => "Imagen principal de {$def['singular']}",
            'set_featured_image' => "Asignar imagen de {$def['singular']}",
            'remove_featured_image' => "Quitar imagen de {$def['singular']}",
            'use_featured_image' => "Usar como imagen de {$def['singular']}",
        ];

        $args = [
                'labels'              => $labels,
                'public'              => true,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_rest'        => true,
                'hierarchical'        => true,
                'menu_position'       => 20,
                'menu_icon'           => 'dashicons-admin-page',
                'supports'            => $def['supports'],
                'taxonomies'          => $def['taxonomies'],
                'rewrite'             => [
                    'slug'       => $def['slug'],
                    'with_front' => false,
                    'pages'      => true,
                ],
                'has_archive'         => $def['has_archive'],
                'exclude_from_search' => false,
                'publicly_queryable'  => true,
                'capability_type'     => 'page',
        ];

        if ( 'producto' === $post_type ) {
            // IMPORTANT: we intentionally do NOT force a per-post block template here.
            // The layout comes from the global "Plantilla Productos" (editable with blocks) and is rendered on the front-end.
            // Products keep their own content + meta (data) without locking them to a duplicated layout.
        }

        register_post_type( $post_type, $args );
    }

    private static function product_block_template(): array {
        return [
            [
                'core/group',
                [ 'align' => 'full', 'className' => 'egm-product-hero' ],
                [
                    [
                        'core/columns',
                        [ 'align' => 'full', 'verticalAlignment' => 'center', 'className' => 'egm-product-hero-columns' ],
                        [
                            [
                                'core/column',
                                [ 'verticalAlignment' => 'center' ],
                                [
                                    [ 'core/paragraph', [ 'className' => 'egm-product-eyebrow', 'placeholder' => 'Categoria o etiqueta superior' ] ],
                                    [ 'core/heading', [ 'level' => 1, 'placeholder' => 'Titulo del producto' ] ],
                                    [ 'core/paragraph', [ 'className' => 'egm-product-lead', 'placeholder' => 'Descripcion corta del producto' ] ],
                                    [
                                        'core/buttons',
                                        [],
                                        [
                                            [ 'core/button', [ 'text' => 'Cotizar ahora' ] ],
                                            [ 'core/button', [ 'className' => 'is-style-outline', 'text' => 'Descargar ficha' ] ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'core/column',
                                [ 'verticalAlignment' => 'center' ],
                                [
                                    [ 'core/image', [ 'sizeSlug' => 'large' ] ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'core/group',
                [ 'align' => 'full', 'className' => 'egm-product-anchor-nav' ],
                [
                    [
                        'core/buttons',
                        [ 'className' => 'egm-product-anchor-buttons' ],
                        [
                            [ 'core/button', [ 'text' => 'Caracteristicas', 'url' => '#caracteristicas' ] ],
                            [ 'core/button', [ 'text' => 'Showcase', 'url' => '#showcase' ] ],
                            [ 'core/button', [ 'text' => 'Especificaciones', 'url' => '#especificaciones' ] ],
                            [ 'core/button', [ 'text' => 'Aplicaciones', 'url' => '#aplicaciones' ] ],
                            [ 'core/button', [ 'text' => 'Cotiza aqui', 'url' => '#formulario' ] ],
                        ],
                    ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-features', 'anchor' => 'caracteristicas' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Caracteristicas' ] ],
                    [
                        'core/columns',
                        [],
                        [
                            [ 'core/column', [], [ [ 'core/image', [] ], [ 'core/heading', [ 'level' => 3, 'placeholder' => 'Caracteristica 1' ] ], [ 'core/paragraph', [ 'placeholder' => 'Descripcion' ] ] ] ],
                            [ 'core/column', [], [ [ 'core/image', [] ], [ 'core/heading', [ 'level' => 3, 'placeholder' => 'Caracteristica 2' ] ], [ 'core/paragraph', [ 'placeholder' => 'Descripcion' ] ] ] ],
                        ],
                    ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-modes', 'anchor' => 'modos' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Modos de operacion' ] ],
                    [
                        'core/columns',
                        [],
                        [
                            [ 'core/column', [ 'className' => 'egm-product-dark-card' ], [ [ 'core/heading', [ 'level' => 3, 'placeholder' => 'RTK Mode' ] ], [ 'core/paragraph', [ 'placeholder' => 'Contenido del modo RTK' ] ] ] ],
                            [ 'core/column', [ 'className' => 'egm-product-dark-card' ], [ [ 'core/heading', [ 'level' => 3, 'placeholder' => 'SLAM Mode' ] ], [ 'core/paragraph', [ 'placeholder' => 'Contenido del modo SLAM' ] ] ] ],
                        ],
                    ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-metrics', 'anchor' => 'metricas' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Metricas destacadas' ] ],
                    [ 'core/table', [] ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-showcase', 'anchor' => 'showcase' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Product Showcase' ] ],
                    [ 'core/gallery', [ 'columns' => 3 ] ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-specs', 'anchor' => 'especificaciones' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Especificaciones' ] ],
                    [ 'core/table', [] ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-applications', 'anchor' => 'aplicaciones' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Aplicaciones' ] ],
                    [
                        'core/columns',
                        [],
                        [
                            [ 'core/column', [], [ [ 'core/image', [] ], [ 'core/paragraph', [ 'placeholder' => 'Aplicacion 1' ] ] ] ],
                            [ 'core/column', [], [ [ 'core/image', [] ], [ 'core/paragraph', [ 'placeholder' => 'Aplicacion 2' ] ] ] ],
                            [ 'core/column', [], [ [ 'core/image', [] ], [ 'core/paragraph', [ 'placeholder' => 'Aplicacion 3' ] ] ] ],
                        ],
                    ],
                ],
            ],
            [
                'core/group',
                [ 'className' => 'egm-product-section egm-form', 'anchor' => 'formulario' ],
                [
                    [ 'core/heading', [ 'level' => 2, 'placeholder' => 'Formulario' ] ],
                    [ 'core/shortcode', [ 'text' => '[tu_form_shortcode]' ] ],
                ],
            ],
        ];
    }

    private static function normalize_def( string $key, array $def ): array {
        $post_type = sanitize_key( $key );
        $singular  = isset( $def['singular'] ) ? sanitize_text_field( $def['singular'] ) : ucfirst( $post_type );
        $plural    = isset( $def['plural'] ) ? sanitize_text_field( $def['plural'] ) : $singular . 's';
        $slug      = isset( $def['slug'] ) ? sanitize_title( $def['slug'] ) : $post_type;
        $supports  = self::ensure_required_supports( self::sanitize_supports( $def['supports'] ?? [] ) );
        $taxes     = self::sanitize_taxonomies( $def['taxonomies'] ?? [] );

        if ( empty( $supports ) ) {
            $supports = self::default_supports();
        }

        if ( empty( $taxes ) ) {
            $taxes = self::default_taxonomies();
        }

        return [
            'singular'    => $singular ?: ucfirst( $post_type ),
            'plural'      => $plural ?: ( ucfirst( $post_type ) . 's' ),
            'slug'        => $slug ?: $post_type,
            'has_archive' => ! empty( $def['has_archive'] ) ? 1 : 0,
            'supports'    => $supports,
            'taxonomies'  => $taxes,
        ];
    }

    private static function default_supports(): array {
        return [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ];
    }

    private static function required_supports(): array {
        return [ 'thumbnail' ];
    }

    private static function supports_allowed(): array {
        return [
            'title',
            'editor',
            'thumbnail',
            'excerpt',
            'page-attributes',
            'custom-fields',
            'revisions',
            'author',
        ];
    }

    private static function default_taxonomies(): array {
        return [ 'category', 'post_tag' ];
    }

    private static function taxonomies_allowed(): array {
        return [ 'category', 'post_tag' ];
    }

    private static function sanitize_supports( $supports ): array {
        if ( ! is_array( $supports ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_intersect(
                    array_map( 'sanitize_text_field', $supports ),
                    self::supports_allowed()
                )
            )
        );
    }

    private static function ensure_required_supports( array $supports ): array {
        return array_values( array_unique( array_merge( $supports, self::required_supports() ) ) );
    }

    private static function sanitize_taxonomies( $taxonomies ): array {
        if ( ! is_array( $taxonomies ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_intersect(
                    array_map( 'sanitize_key', $taxonomies ),
                    self::taxonomies_allowed()
                )
            )
        );
    }

    private static function admin_page_url( array $args = [] ): string {
        return add_query_arg( $args, admin_url( 'admin.php?page=estandar-cpt-manager' ) );
    }

    private static function product_template_page_url( array $args = [] ): string {
        return add_query_arg( $args, admin_url( 'admin.php?page=estandar-product-template' ) );
    }

    private static function redirect_with_notice( string $code, array $args = [] ): void {
        $args['notice'] = $code;
        wp_safe_redirect( self::admin_page_url( $args ) );
        exit;
    }

    private static function get_admin_notice(): string {
        return isset( $_GET['notice'] ) ? sanitize_key( $_GET['notice'] ) : '';
    }

    public static function admin_menu() {
        // Prefer Estandar Core (MU-plugin). Fallback to standalone menu if Core is absent.
        if ( function_exists( 'estandar_register_submenu' ) ) {
            estandar_register_submenu(
                [
                    'slug'       => 'estandar-cpt-manager',
                    'title'      => 'CPT Manager',
                    'menu_title' => 'CPT Manager',
                    'capability' => self::CAP,
                    'callback'   => [ __CLASS__, 'render_admin' ],
                ]
            );

            // Keep legacy admin pages accessible (same slugs/callbacks/URLs), but hide them from the sidebar menu.
            add_submenu_page(
                'estandar_main_menu',
                'Plantilla Productos',
                'Plantilla Productos',
                self::CAP,
                'estandar-product-template',
                [ __CLASS__, 'render_product_template_admin' ]
            );
            remove_submenu_page( 'estandar_main_menu', 'estandar-product-template' );

            return;
        }

        add_menu_page(
            'CPT Manager',
            'CPT Manager',
            self::CAP,
            'estandar-cpt-manager',
            [ __CLASS__, 'render_admin' ],
            'dashicons-admin-page',
            58
        );

        // Keep legacy admin pages accessible (same slugs/callbacks/URLs), but hide them from the sidebar menu.
        add_submenu_page(
            'estandar-cpt-manager',
            'Plantilla Productos',
            'Plantilla Productos',
            self::CAP,
            'estandar-product-template',
            [ __CLASS__, 'render_product_template_admin' ]
        );
        remove_submenu_page( 'estandar-cpt-manager', 'estandar-product-template' );

        // Manage additional product templates (block-editable posts).
        add_submenu_page(
            'estandar-cpt-manager',
            'Todas las plantillas',
            'Todas las plantillas',
            self::CAP,
            'edit.php?post_type=' . self::PRODUCT_TEMPLATE_POST_TYPE
        );
        remove_submenu_page( 'estandar-cpt-manager', 'edit.php?post_type=' . self::PRODUCT_TEMPLATE_POST_TYPE );

        add_submenu_page(
            'estandar-cpt-manager',
            'Nueva plantilla',
            'Nueva plantilla',
            self::CAP,
            'post-new.php?post_type=' . self::PRODUCT_TEMPLATE_POST_TYPE
        );
        remove_submenu_page( 'estandar-cpt-manager', 'post-new.php?post_type=' . self::PRODUCT_TEMPLATE_POST_TYPE );
    }

    public static function render_admin_notices() {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        if ( ! isset( $_GET['page'] ) || ! in_array( sanitize_key( $_GET['page'] ), [ 'estandar-cpt-manager', 'estandar-product-template' ], true ) ) {
            return;
        }

        $notice = self::get_admin_notice();

        if ( empty( $notice ) ) {
            return;
        }

        $messages = [
            'created'            => [ 'success', 'CPT creado correctamente.' ],
            'updated'            => [ 'success', 'CPT actualizado correctamente.' ],
            'deleted'            => [ 'success', 'CPT eliminado correctamente. Los contenidos existentes no se borraron.' ],
            'missing_key'        => [ 'error', 'Debes indicar una key valida para el CPT.' ],
            'invalid_key_length' => [ 'error', 'La key del CPT no puede superar 20 caracteres.' ],
            'reserved_key'       => [ 'error', 'La key indicada esta reservada o ya existe en WordPress.' ],
            'empty_slug'         => [ 'error', 'Debes indicar un slug valido para la URL.' ],
            'reserved_slug'      => [ 'error', 'El slug indicado ya esta en uso por otro contenido o CPT administrado.' ],
            'product_template_updated' => [ 'success', 'Plantilla global de productos actualizada correctamente.' ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        list( $type, $message ) = $messages[ $notice ];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    public static function default_product_template_settings(): array {
        return [
            'page_bg'              => '#ffffff',
            'hero_bg'              => '#f6f8fb',
            'text_color'           => '#111827',
            'muted_color'          => '#667085',
            'accent_color'         => '#f2b705',
            'dark_color'           => '#111827',
            'content_width'        => 1220,
            'hero_full_width'      => 1,
            'show_anchor_nav'      => 1,
            'show_content'         => 1,
            'show_features'        => 1,
            'show_modes'           => 1,
            'show_metrics'         => 1,
            'show_showcase'        => 1,
            'show_specs'           => 1,
            'show_applications'    => 1,
            'show_form'            => 1,
            'hero_badge_default'   => 'Producto',
            'primary_cta_text'     => 'Cotizar ahora',
            'download_cta_text'    => 'Descargar ficha',
            'nav_features'         => 'Caracteristicas',
            'nav_modes'            => 'Modos',
            'nav_metrics'          => 'Datos clave',
            'nav_showcase'         => 'Showcase',
            'nav_specs'            => 'Especificaciones',
            'nav_applications'     => 'Aplicaciones',
            'nav_form'             => 'Cotiza aqui',
            'title_features'       => 'Caracteristicas',
            'title_modes'          => 'Modos de operacion',
            'title_metrics'        => 'Datos tecnicos destacados',
            'title_showcase'       => 'Product Showcase',
            'title_specs'          => 'Especificaciones',
            'title_applications'   => 'Aplicaciones',
            'title_form'           => 'Cotiza este producto',
            'form_shortcode'       => '',
            'final_cta_title'      => 'Get a free quote',
            'final_cta_text'       => '',
            'custom_css'           => '',
        ];
    }

    public static function get_product_template_settings(): array {
        $settings = get_option( self::PRODUCT_TEMPLATE_OPT_KEY, [] );
        $settings = is_array( $settings ) ? $settings : [];

        return array_merge( self::default_product_template_settings(), $settings );
    }

    private static function get_admin_active_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        if ( '' === $tab ) {
            $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
            if ( 'estandar-product-template' === $page ) {
                return 'product-template';
            }
            return 'cpts';
        }

        $allowed = [ 'cpts', 'product-template', 'plantillas', 'como-usar' ];
        return in_array( $tab, $allowed, true ) ? $tab : 'cpts';
    }

    private static function render_admin_tabs_nav( string $active_tab ): void {
        $tabs = [
            'cpts' => [
                'label' => 'CPTs',
                'url'   => self::admin_page_url( [ 'tab' => 'cpts' ] ),
            ],
            'product-template' => [
                'label' => 'Plantilla Productos',
                'url'   => self::admin_page_url( [ 'tab' => 'product-template' ] ),
            ],
            'plantillas' => [
                'label' => 'Plantillas',
                'url'   => self::admin_page_url( [ 'tab' => 'plantillas' ] ),
            ],
            'como-usar' => [
                'label' => 'Cómo usar',
                'url'   => self::admin_page_url( [ 'tab' => 'como-usar' ] ),
            ],
        ];

        // CORE_OPTIONALITY_V1: cpt-manager tiene menú standalone; esta ruta corre sin core.
        if ( function_exists( 'estandar_render_tabs' ) ) {
            estandar_render_tabs( $tabs, $active_tab );
            return;
        }
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $tab ) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( $tab['url'] ?? '' ),
                $slug === $active_tab ? ' nav-tab-active' : '',
                esc_html( $tab['label'] ?? '' )
            );
        }
        echo '</h2>';
    }

    private static function render_how_to_use_content(): void {
        echo '<h2>Cómo usar</h2>';
        echo '<p>Esta pantalla explica el flujo completo del plugin sin tecnicismos.</p>';

        echo '<h3>Flujo simple</h3>';
        echo '<ol>';
        echo '<li><strong>Contenido</strong>: creas entradas (por ejemplo, Productos) y completas sus datos.</li>';
        echo '<li><strong>Plantilla</strong>: diseñas el layout visual una vez (con bloques).</li>';
        echo '<li><strong>Resultado</strong>: WordPress muestra la ficha final usando tu plantilla + los datos del contenido.</li>';
        echo '</ol>';

        echo '<h3>Paso a paso</h3>';
        echo '<ol>';
        echo '<li>Ve a la pestaña <strong>CPTs</strong> y crea (o revisa) el tipo de contenido que necesitas.</li>';
        echo '<li>Luego, en el menú de WordPress, entra al nuevo tipo de contenido y crea una entrada (ej: un Producto).</li>';
        echo '<li>En <strong>Plantilla Productos</strong>, edita el diseño global con bloques para definir el “look” de la ficha.</li>';
        echo '<li>Publica y revisa el resultado en el front-end (vista pública).</li>';
        echo '</ol>';

        echo '<h3>Ejemplos reales (Shortcode)</h3>';
        echo '<p>Para mostrar una galería simple con botón <strong>Ver más</strong>:</p>';
        echo '<p><code>[estandar_gallery ids="1,2,3" size="medium"]</code></p>';
        echo '<p>Si hay <strong>1 sola</strong> imagen, puedes alinear (solo aplica en ese caso):</p>';
        echo '<p><code>[estandar_gallery ids="1" align="center" size="large"]</code></p>';

        echo '<h3>¿Cómo obtengo los IDs?</h3>';
        echo '<ol>';
        echo '<li>Ve a <strong>Medios → Biblioteca</strong> y haz clic en una imagen.</li>';
        echo '<li>En la URL del navegador verás un número (por ejemplo: <code>post=123</code>). Ese es el ID.</li>';
        echo '<li>Repite para cada imagen y arma la lista separada por comas: <code>123,124,130</code>.</li>';
        echo '</ol>';

        echo '<h3>Dónde pegar el shortcode (sin código)</h3>';
        echo '<ol>';
        echo '<li>En el editor de bloques, agrega un bloque <strong>Shortcode</strong>.</li>';
        echo '<li>Pega el shortcode y actualiza/publica.</li>';
        echo '</ol>';

        echo '<p style="color:#64748b;margin-top:14px;">Nota: este plugin no usa lightbox ni sliders. Es una galería simple de visibilidad progresiva (2 visibles + Ver más).</p>';
    }

    private static function render_product_template_admin_content(): void {
        // Defensive: make sure CPT exists even if init order changes.
        self::register_product_template_post_type();
        $template_id = self::ensure_product_template_post();
        $edit_url    = $template_id ? admin_url( 'post.php?post=' . $template_id . '&action=edit' ) : '';

        // NOTE: Don't redirect from here. At this point WP admin likely already sent headers,
        // so redirects can fail and leave a blank content area.
        $err = get_transient( self::PRODUCT_TEMPLATE_LAST_ERROR_TRANSIENT );

        echo '<h2>Plantilla Productos</h2>';

        if ( $template_id && $edit_url ) {
            echo '<p>Esta es la plantilla global (editable con bloques) que se usa como base visual para todos los <strong>Productos</strong>.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( $edit_url ) . '">Editar plantilla (bloques)</a></p>';
            echo '<p style="color:#64748b;">ID: ' . esc_html( (string) $template_id ) . ' | post_type: <code>' . esc_html( self::PRODUCT_TEMPLATE_POST_TYPE ) . '</code></p>';
        } else {
            echo '<p>No se pudo crear/encontrar la plantilla global.</p>';
            if ( $err ) {
                echo '<p><strong>Error:</strong> ' . esc_html( (string) $err ) . '</p>';
            } else {
                echo '<p>Si esto persiste, revisa permisos del usuario actual y el log de PHP/WordPress para ver el error exacto.</p>';
            }
        }
    }

    private static function render_templates_links_content(): void {
        $list_url = admin_url( 'edit.php?post_type=' . rawurlencode( self::PRODUCT_TEMPLATE_POST_TYPE ) );
        $new_url  = admin_url( 'post-new.php?post_type=' . rawurlencode( self::PRODUCT_TEMPLATE_POST_TYPE ) );

        echo '<h2>Plantillas (posts)</h2>';
        echo '<p>Accesos directos a las pantallas nativas de WordPress para administrar las plantillas (post_type: <code>' . esc_html( self::PRODUCT_TEMPLATE_POST_TYPE ) . '</code>).</p>';
        echo '<p>';
        echo '<a class="button" href="' . esc_url( $list_url ) . '">Todas las plantillas</a> ';
        echo '<a class="button button-primary" href="' . esc_url( $new_url ) . '">Nueva plantilla</a>';
        echo '</p>';
    }

    public static function render_product_template_admin() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'No autorizado.' );
        }

        // Legacy URL compatibility: keep `admin.php?page=estandar-product-template` working,
        // but render the same content under the single-entry CPT Manager shell.
        $_GET['tab'] = 'product-template';
        self::render_admin();
    }

    private static function render_template_color_field( string $key, string $label, array $settings ) {
        self::render_template_text_field( $key, $label, $settings, 'color' );
    }

    private static function render_template_text_field( string $key, string $label, array $settings, string $type = 'text' ) {
        echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<input class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? '' ) ) . '" />';
        echo '</td></tr>';
    }

    private static function render_template_textarea_field( string $key, string $label, array $settings, int $rows ) {
        echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<textarea class="large-text code" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $rows ) . '">' . esc_textarea( (string) ( $settings[ $key ] ?? '' ) ) . '</textarea>';
        echo '</td></tr>';
    }

    private static function render_template_checkbox_field( string $key, string $label, array $settings ) {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
        echo '<label><input name="' . esc_attr( $key ) . '" type="checkbox" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . ' /> Activado</label>';
        echo '</td></tr>';
    }

    public static function enqueue_category_image_admin( $hook ) {
        $is_category_screen = in_array( $hook, [ 'edit-tags.php', 'term.php' ], true )
            && isset( $_GET['taxonomy'] )
            && 'category' === sanitize_key( $_GET['taxonomy'] );

        $is_product_screen = in_array( $hook, [ 'post.php', 'post-new.php' ], true )
            && self::is_product_admin_screen();

        if ( ! $is_category_screen && ! $is_product_screen ) {
            return;
        }

        wp_enqueue_media();
        wp_register_script( 'estandar-cpt-manager-category-image', false, [ 'jquery' ], '1.6.0', true );
        wp_enqueue_script( 'estandar-cpt-manager-category-image' );
        wp_add_inline_script(
            'estandar-cpt-manager-category-image',
            "
            jQuery(function($) {
                var frame;
                var previewXhr = null;
                var previewTimer = null;

                $(document).on('click', '.estandar-category-image-select', function(event) {
                    event.preventDefault();

                    var button = $(this);
                    var wrapper = button.closest('.estandar-category-image-field');

                    if (frame) {
                        frame.off('select');
                    }

                    frame = wp.media({
                        title: 'Seleccionar imagen de categoria',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    });

                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        wrapper.find('.estandar-category-image-id').val(attachment.id);
                        wrapper.find('.estandar-category-image-preview').html('<img src=\"' + attachment.url + '\" style=\"max-width:160px;height:auto;border-radius:8px;display:block;\" alt=\"\" />');
                        wrapper.find('.estandar-category-image-remove').show();
                    });

                    frame.open();
                });

                $(document).on('click', '.estandar-category-image-remove', function(event) {
                    event.preventDefault();

                    var wrapper = $(this).closest('.estandar-category-image-field');
                    wrapper.find('.estandar-category-image-id').val('');
                    wrapper.find('.estandar-category-image-preview').empty();
                    $(this).hide();
                });

                // Generic image selector for template-field metabox.
                $(document).on('click', '.egm-tpl-image-select', function(event) {
                    event.preventDefault();

                    var key = $(this).data('target');
                    var idInput = $('#egm_tpl_field_' + key);
                    var preview = $('.egm-tpl-image-preview[data-key=\"' + key + '\"]');
                    var removeBtn = $('.egm-tpl-image-remove[data-target=\"' + key + '\"]');

                    if (frame) {
                        frame.off('select');
                    }

                    frame = wp.media({
                        title: 'Seleccionar imagen',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    });

                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        idInput.val(attachment.id);
                        preview.html('<img src=\"' + attachment.url + '\" alt=\"\" />');
                        removeBtn.show();
                    });

                    frame.open();
                });

                $(document).on('click', '.egm-tpl-image-remove', function(event) {
                    event.preventDefault();
                    var key = $(this).data('target');
                    $('#egm_tpl_field_' + key).val('');
                    $('.egm-tpl-image-preview[data-key=\"' + key + '\"]').empty();
                    $(this).hide();
                });

                function scheduleTemplatePreviewUpdate() {
                    if (previewTimer) {
                        clearTimeout(previewTimer);
                    }
                    previewTimer = setTimeout(updateTemplatePreview, 250);
                }

                function collectTemplateFieldValues() {
                    var out = {};
                    // Text + textarea.
                    $('input[name^=\"egm_tpl_field[\"][type!=\"hidden\"], textarea[name^=\"egm_tpl_field[\"]').each(function() {
                        var name = $(this).attr('name') || '';
                        // egm_tpl_field[key] or egm_tpl_field[key][sub]
                        var m = name.match(/^egm_tpl_field\\[([^\\]]+)\\](?:\\[([^\\]]+)\\])?$/);
                        if (!m) return;
                        var key = m[1];
                        var sub = m[2] || '';
                        if (sub) {
                            out[key] = out[key] || {};
                            out[key][sub] = $(this).val();
                        } else {
                            out[key] = $(this).val();
                        }
                    });
                    // Hidden image ids.
                    $('input.egm-tpl-image-id[name^=\"egm_tpl_field[\"]').each(function() {
                        var name = $(this).attr('name') || '';
                        var m = name.match(/^egm_tpl_field\\[([^\\]]+)\\]$/);
                        if (!m) return;
                        out[m[1]] = $(this).val();
                    });
                    return out;
                }

                function updateTemplatePreview() {
                    var previewEl = $('#egm-tpl-preview');
                    if (!previewEl.length) return;

                    var postId = previewEl.data('post-id');
                    var templateId = previewEl.data('template-id');
                    var nonce = previewEl.data('nonce');
                    if (!postId || !templateId || !nonce) return;

                    if (previewXhr && previewXhr.readyState !== 4) {
                        previewXhr.abort();
                    }

                    var payload = {
                        action: 'egm_tpl_preview_render',
                        post_id: postId,
                        template_id: templateId,
                        nonce: nonce,
                        fields: JSON.stringify(collectTemplateFieldValues())
                    };

                    previewEl.css('opacity', '0.6');
                    previewXhr = $.post(ajaxurl, payload)
                        .done(function(resp) {
                            if (resp && resp.success && resp.data && resp.data.html !== undefined) {
                                previewEl.html(resp.data.html);
                            }
                        })
                        .always(function() {
                            previewEl.css('opacity', '1');
                        });
                }

                $(document).on('input change', 'input[name^=\"egm_tpl_field[\"], textarea[name^=\"egm_tpl_field[\"], .egm-tpl-image-id', function() {
                    scheduleTemplatePreviewUpdate();
                });

                // Category-template term fields image selector.
                $(document).on('click', '.egm-cat-tpl-image-select', function(event) {
                    event.preventDefault();

                    var key = $(this).data('target');
                    var idInput = $('#egm_cat_tpl_field_' + key);
                    var preview = $('.egm-cat-tpl-image-preview[data-key=\"' + key + '\"]');
                    var removeBtn = $('.egm-cat-tpl-image-remove[data-target=\"' + key + '\"]');

                    if (!idInput.length) return;

                    if (frame) {
                        frame.off('select');
                    }

                    frame = wp.media({
                        title: 'Seleccionar imagen',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    });

                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        idInput.val(attachment.id);
                        if (preview.length) {
                            preview.html('<img src=\"' + attachment.url + '\" alt=\"\" style=\"max-width:180px;height:auto;border-radius:10px;display:block;\" />');
                        }
                        if (removeBtn.length) removeBtn.show();
                    });

                    frame.open();
                });

                $(document).on('click', '.egm-cat-tpl-image-remove', function(event) {
                    event.preventDefault();
                    var key = $(this).data('target');
                    $('#egm_cat_tpl_field_' + key).val('');
                    $('.egm-cat-tpl-image-preview[data-key=\"' + key + '\"]').empty();
                    $(this).hide();
                });
            });
            "
        );

        if ( $is_product_screen ) {
            wp_register_style( 'estandar-cpt-manager-producto-template-admin', false, [], '1.10.2' );
            wp_enqueue_style( 'estandar-cpt-manager-producto-template-admin' );
            wp_add_inline_style( 'estandar-cpt-manager-producto-template-admin', self::product_template_admin_css() );
        }
    }

    public static function enqueue_estandar_admin_ui( $hook ): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, [ 'estandar-cpt-manager', 'estandar-product-template' ], true ) ) {
            return;
        }

        if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
            estandar_core_enqueue_admin_ui();
        }
    }

    private static function is_product_admin_screen(): bool {
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();

            if ( $screen && 'producto' === $screen->post_type ) {
                return true;
            }
        }

        if ( isset( $_GET['post_type'] ) && 'producto' === sanitize_key( $_GET['post_type'] ) ) {
            return true;
        }

        if ( isset( $_GET['post'] ) ) {
            return 'producto' === get_post_type( absint( $_GET['post'] ) );
        }

        return false;
    }

    private static function get_default_category_template_id(): int {
        return absint( get_option( self::CATEGORY_TEMPLATE_POST_ID_OPT_KEY, 0 ) );
    }

    /** @return array<int,string> */
    private static function list_category_templates(): array {
        self::register_category_template_post_type();
        $posts = get_posts(
            [
                'post_type'      => self::CATEGORY_TEMPLATE_POST_TYPE,
                'post_status'    => [ 'publish', 'draft', 'private' ],
                'posts_per_page' => 50,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]
        );
        $out = [];
        foreach ( $posts as $id ) {
            $id = absint( $id );
            if ( ! $id ) {
                continue;
            }
            $out[ $id ] = get_the_title( $id ) ?: ( 'Template #' . $id );
        }
        return $out;
    }

    private static function get_category_term_field_values( int $term_id ): array {
        $v = get_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_FIELDS_META_KEY, true );
        return is_array( $v ) ? $v : [];
    }

    private static function get_category_term_attr_overrides( int $term_id ): array {
        $v = get_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_ATTRS_META_KEY, true );
        return is_array( $v ) ? $v : [];
    }

    /** @param callable $fn */
    private static function with_dynamic_render_context( array $ctx, $fn ) {
        $prev = self::$dynamic_render_context;
        self::$dynamic_render_context = $ctx;
        try {
            return $fn();
        } finally {
            self::$dynamic_render_context = $prev;
        }
    }

    public static function render_category_archive_page(): void {
        $term = get_queried_object();
        if ( ! ( $term instanceof WP_Term ) ) {
            echo '<div class="egm-category-page-wrapper"><p>No se encontro la categoria.</p></div>';
            return;
        }
        $term_id = absint( $term->term_id );

        $assigned = absint( get_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_META_KEY, true ) );
        $tpl_id = $assigned ? $assigned : self::get_default_category_template_id();
        if ( ! $tpl_id ) {
            $tpl_id = self::ensure_category_template_post();
        }

        $tpl = $tpl_id ? get_post( $tpl_id ) : null;
        if ( ! ( $tpl && self::CATEGORY_TEMPLATE_POST_TYPE === $tpl->post_type ) ) {
            echo '<div class="egm-category-page-wrapper"><p>No se pudo cargar la plantilla de categorias.</p></div>';
            return;
        }

        $values = self::get_category_term_field_values( $term_id );
        $attrs  = self::get_category_term_attr_overrides( $term_id );

        $html = self::with_dynamic_render_context(
            [
                'type'   => 'term',
                'id'     => $term_id,
                'values' => $values,
                'attrs'  => $attrs,
            ],
            static function () use ( $tpl ) {
                return do_blocks( (string) $tpl->post_content );
            }
        );

        echo '<div class="egm-category-page-wrapper">';
        echo $html; // do_blocks returns safe HTML for registered blocks.
        echo '</div>';
    }

    public static function render_category_image_add_field() {
        ?>
        <div class="form-field term-group estandar-category-image-field">
            <label for="estandar_category_image_id">Imagen destacada</label>
            <input type="hidden" class="estandar-category-image-id" id="estandar_category_image_id" name="estandar_category_image_id" value="" />
            <div class="estandar-category-image-preview" style="margin:0 0 10px;"></div>
            <button type="button" class="button estandar-category-image-select">Asignar imagen</button>
            <button type="button" class="button estandar-category-image-remove" style="display:none;">Quitar imagen</button>
            <p>Usa esta imagen para galerias visuales de categorias de productos.</p>
        </div>
        <?php
    }

    public static function render_category_image_edit_field( $term ) {
        $image_id  = self::get_category_image_id( $term->term_id );
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'categoria-thumb' ) : '';
        ?>
        <tr class="form-field term-group-wrap estandar-category-image-field">
            <th scope="row"><label for="estandar_category_image_id">Imagen destacada</label></th>
            <td>
                <input type="hidden" class="estandar-category-image-id" id="estandar_category_image_id" name="estandar_category_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
                <div class="estandar-category-image-preview" style="margin:0 0 10px;">
                    <?php if ( $image_url ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" style="max-width:160px;height:auto;border-radius:8px;display:block;" alt="" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button estandar-category-image-select">Asignar imagen</button>
                <button type="button" class="button estandar-category-image-remove" style="<?php echo $image_url ? '' : 'display:none;'; ?>">Quitar imagen</button>
                <p class="description">Usa esta imagen para galerias visuales de categorias de productos.</p>
            </td>
        </tr>
        <?php
    }

    public static function render_category_template_add_fields() {
        $tpl_id = self::get_default_category_template_id();
        $templates = self::list_category_templates();
        ?>
        <div class="form-field term-group">
            <label for="egm_categoria_template_id">Plantilla de categoria (productos)</label>
            <select id="egm_categoria_template_id" name="egm_categoria_template_id">
                <option value="0">Usar plantilla global</option>
                <?php foreach ( $templates as $t_id => $t_title ) : ?>
                    <option value="<?php echo esc_attr( (string) $t_id ); ?>" <?php selected( $tpl_id, $t_id ); ?>>
                        <?php echo esc_html( $t_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">Se aplica solo cuando visitas la categoria con <code>?post_type=producto</code>.</p>
            <p class="description">Los campos dinamicos (egm-field egm-key-*) se editan por categoria aqui, sin tocar la plantilla.</p>
        </div>
        <?php
    }

    public static function render_category_template_edit_fields( $term ) {
        $term_id = absint( $term->term_id );
        $assigned = absint( get_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_META_KEY, true ) );
        $tpl_id = $assigned ? $assigned : self::get_default_category_template_id();
        $templates = self::list_category_templates();

        $fields = self::get_category_term_field_values( $term_id );
        $schema = $tpl_id ? get_post_meta( $tpl_id, self::TEMPLATE_SCHEMA_META_KEY, true ) : [];
        $schema = is_array( $schema ) ? $schema : [];
        $schema_fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];
        ?>
        <tr class="form-field term-group-wrap">
            <th scope="row"><label for="egm_categoria_template_id">Plantilla de categoria (productos)</label></th>
            <td>
                <select id="egm_categoria_template_id" name="egm_categoria_template_id">
                    <option value="0">Usar plantilla global</option>
                    <?php foreach ( $templates as $t_id => $t_title ) : ?>
                        <option value="<?php echo esc_attr( (string) $t_id ); ?>" <?php selected( $assigned, $t_id ); ?>>
                            <?php echo esc_html( $t_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Se aplica solo en <code>/category/slug/?post_type=producto</code> (no afecta el blog).</p>
                <?php if ( $tpl_id ) : ?>
                    <p><a class="button" href="<?php echo esc_url( admin_url( 'post.php?post=' . $tpl_id . '&action=edit' ) ); ?>">Editar plantilla (bloques)</a></p>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ( ! empty( $schema_fields ) ) : ?>
            <tr class="form-field term-group-wrap">
                <th scope="row">Campos dinamicos</th>
                <td>
                    <p class="description">Estos valores se guardan por categoria. La plantilla solo define estructura/estilo.</p>
                    <div style="display:grid;grid-template-columns:1fr;gap:12px;max-width:680px;">
                        <?php foreach ( $schema_fields as $f ) : ?>
                            <?php
                            if ( ! is_array( $f ) ) {
                                continue;
                            }
                            $key   = isset( $f['key'] ) ? sanitize_key( (string) $f['key'] ) : '';
                            $type  = isset( $f['type'] ) ? sanitize_key( (string) $f['type'] ) : 'text';
                            $label = isset( $f['label'] ) ? (string) $f['label'] : $key;
                            if ( '' === $key ) {
                                continue;
                            }

                            $val = $fields[ $key ] ?? '';
                            ?>
                            <div style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;">
                                <div style="font-weight:700;margin:0 0 6px;"><?php echo esc_html( $label ); ?> <code style="font-size:12px;"><?php echo esc_html( $key ); ?></code></div>
                                <?php if ( in_array( $type, [ 'image', 'cover' ], true ) ) : ?>
                                    <?php
                                    $img_id = absint( $val );
                                    $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'categoria-thumb' ) : '';
                                    ?>
                                    <input type="hidden" class="egm-cat-tpl-image-id" id="egm_cat_tpl_field_<?php echo esc_attr( $key ); ?>" name="egm_cat_tpl_field[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $img_id ); ?>" />
                                    <div class="egm-cat-tpl-image-preview" data-key="<?php echo esc_attr( $key ); ?>" style="margin:0 0 8px;">
                                        <?php if ( $img_url ) : ?>
                                            <img src="<?php echo esc_url( $img_url ); ?>" alt="" style="max-width:180px;height:auto;border-radius:10px;display:block;" />
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="button egm-cat-tpl-image-select" data-target="<?php echo esc_attr( $key ); ?>">Asignar imagen</button>
                                    <button type="button" class="button egm-cat-tpl-image-remove" data-target="<?php echo esc_attr( $key ); ?>" style="<?php echo $img_url ? '' : 'display:none;'; ?>">Quitar</button>
                                <?php elseif ( 'button' === $type ) : ?>
                                    <?php
                                    $btn = is_array( $val ) ? $val : [];
                                    $text = isset( $btn['text'] ) ? (string) $btn['text'] : '';
                                    $url  = isset( $btn['url'] ) ? (string) $btn['url'] : '';
                                    $opens = ! empty( $btn['opensInNewTab'] ) ? 1 : 0;
                                    ?>
                                    <p style="margin:0 0 6px;"><label>Texto<br/>
                                        <input type="text" class="regular-text" name="egm_cat_tpl_field[<?php echo esc_attr( $key ); ?>][text]" value="<?php echo esc_attr( $text ); ?>" />
                                    </label></p>
                                    <p style="margin:0 0 6px;"><label>URL<br/>
                                        <input type="url" class="regular-text" name="egm_cat_tpl_field[<?php echo esc_attr( $key ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://..." />
                                    </label></p>
                                    <label><input type="checkbox" name="egm_cat_tpl_field[<?php echo esc_attr( $key ); ?>][opensInNewTab]" value="1" <?php checked( $opens, 1 ); ?> /> Abrir en nueva pestaña</label>
                                <?php else : ?>
                                    <textarea class="large-text" rows="2" name="egm_cat_tpl_field[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( is_string( $val ) ? $val : '' ); ?></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
        <?php
    }

    public static function save_category_template_meta( int $term_id ) {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        $tpl_id = isset( $_POST['egm_categoria_template_id'] ) ? absint( $_POST['egm_categoria_template_id'] ) : 0;
        if ( $tpl_id ) {
            $p = get_post( $tpl_id );
            if ( ! ( $p && self::CATEGORY_TEMPLATE_POST_TYPE === $p->post_type ) ) {
                $tpl_id = 0;
            }
        }

        if ( $tpl_id ) {
            update_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_META_KEY, $tpl_id );
        } else {
            delete_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_META_KEY );
        }

        $raw_fields = isset( $_POST['egm_cat_tpl_field'] ) ? wp_unslash( $_POST['egm_cat_tpl_field'] ) : [];
        $raw_fields = is_array( $raw_fields ) ? $raw_fields : [];
        $clean_fields = self::sanitize_tpl_fields_value( $raw_fields );

        if ( ! empty( $clean_fields ) ) {
            update_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_FIELDS_META_KEY, $clean_fields );
        } else {
            delete_term_meta( $term_id, self::CATEGORY_TEMPLATE_TERM_FIELDS_META_KEY );
        }
    }

    public static function save_category_image_meta( $term_id ) {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        $image_id = isset( $_POST['estandar_category_image_id'] ) ? absint( wp_unslash( $_POST['estandar_category_image_id'] ) ) : 0;

        if ( $image_id ) {
            update_term_meta( $term_id, self::TERM_IMAGE_META, $image_id );
            return;
        }

        delete_term_meta( $term_id, self::TERM_IMAGE_META );
    }

    public static function get_category_image_id( int $term_id ): int {
        return absint( get_term_meta( $term_id, self::TERM_IMAGE_META, true ) );
    }

    public static function get_category_image_url( int $term_id, string $size = 'categoria-thumb' ): string {
        $image_id = self::get_category_image_id( $term_id );

        if ( ! $image_id ) {
            return '';
        }

        $url = wp_get_attachment_image_url( $image_id, $size );

        return $url ? $url : '';
    }

    public static function register_category_gallery_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        wp_register_script(
            'estandar-cpt-category-gallery-block',
            false,
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ],
            '1.5.0',
            true
        );

        wp_add_inline_script( 'estandar-cpt-category-gallery-block', self::category_gallery_block_script() );

        register_block_type(
            'estandar/product-categories-gallery',
            [
                'api_version'   => 2,
                'title'         => 'Galeria de categorias de productos',
                'category'      => 'widgets',
                'icon'          => 'screenoptions',
                'description'   => 'Muestra categorias con imagen destacada, overlay, titulo centrado y boton configurable.',
                'editor_script' => 'estandar-cpt-category-gallery-block',
                'attributes'    => self::category_gallery_block_attributes(),
                'render_callback' => [ __CLASS__, 'render_product_categories_block' ],
                'supports'      => [
                    'html' => false,
                ],
            ]
        );
    }

    private static function category_gallery_block_attributes(): array {
        return [
            'postType'     => [
                'type'    => 'string',
                'default' => 'producto',
            ],
            'columns'      => [
                'type'    => 'number',
                'default' => 3,
            ],
            'limit'        => [
                'type'    => 'number',
                'default' => 0,
            ],
            'hideEmpty'    => [
                'type'    => 'boolean',
                'default' => true,
            ],
            'overlayColor' => [
                'type'    => 'string',
                'default' => '#000000',
            ],
            'overlayOpacity' => [
                'type'    => 'number',
                'default' => 45,
            ],
            'fontSize'     => [
                'type'    => 'number',
                'default' => 28,
            ],
            'buttonText'   => [
                'type'    => 'string',
                'default' => 'Ver mas',
            ],
            'showButton'   => [
                'type'    => 'boolean',
                'default' => true,
            ],
            'buttonTextColor' => [
                'type'    => 'string',
                'default' => '#ffffff',
            ],
            'buttonBackgroundColor' => [
                'type'    => 'string',
                'default' => 'transparent',
            ],
            'buttonBorderColor' => [
                'type'    => 'string',
                'default' => '#ffffff',
            ],
            'buttonFontSize' => [
                'type'    => 'number',
                'default' => 14,
            ],
            'buttonPaddingY' => [
                'type'    => 'number',
                'default' => 10,
            ],
            'buttonPaddingX' => [
                'type'    => 'number',
                'default' => 18,
            ],
            'buttonMarginTop' => [
                'type'    => 'number',
                'default' => 18,
            ],
            'hoverZoomPercent' => [
                'type'    => 'number',
                'default' => 8,
            ],
            'scrollZoomEnabled' => [
                'type'    => 'boolean',
                'default' => false,
            ],
            'scrollZoomPercent' => [
                'type'    => 'number',
                'default' => 10,
            ],
        ];
    }

    private static function category_gallery_block_script(): string {
        return <<<'JS'
(function(blocks, element, components, blockEditor, serverSideRender) {
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var ServerSideRender = serverSideRender.default || serverSideRender;
    var PanelBody = components.PanelBody;
    var RangeControl = components.RangeControl;
    var TextControl = components.TextControl;
    var ToggleControl = components.ToggleControl;
    var ColorPalette = components.ColorPalette;

    blocks.registerBlockType('estandar/product-categories-gallery', {
        title: 'Galeria de categorias de productos',
        icon: 'screenoptions',
        category: 'widgets',
        description: 'Categorias visuales con imagen destacada, overlay, titulo centrado y boton.',
        attributes: {
            postType: { type: 'string', default: 'producto' },
            columns: { type: 'number', default: 3 },
            limit: { type: 'number', default: 0 },
            hideEmpty: { type: 'boolean', default: true },
            overlayColor: { type: 'string', default: '#000000' },
            overlayOpacity: { type: 'number', default: 45 },
            fontSize: { type: 'number', default: 28 },
            buttonText: { type: 'string', default: 'Ver mas' },
            showButton: { type: 'boolean', default: true },
            buttonTextColor: { type: 'string', default: '#ffffff' },
            buttonBackgroundColor: { type: 'string', default: 'transparent' },
            buttonBorderColor: { type: 'string', default: '#ffffff' },
            buttonFontSize: { type: 'number', default: 14 },
            buttonPaddingY: { type: 'number', default: 10 },
            buttonPaddingX: { type: 'number', default: 18 },
            buttonMarginTop: { type: 'number', default: 18 },
            hoverZoomPercent: { type: 'number', default: 8 },
            scrollZoomEnabled: { type: 'boolean', default: false },
            scrollZoomPercent: { type: 'number', default: 10 }
        },
        edit: function(props) {
            var attrs = props.attributes;
            var setAttributes = props.setAttributes;

            return el(
                'div',
                {},
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: 'Contenido', initialOpen: true },
                        el(TextControl, {
                            label: 'Post type de productos',
                            value: attrs.postType,
                            onChange: function(value) { setAttributes({ postType: value }); },
                            help: 'Ejemplo: producto'
                        }),
                        el(RangeControl, {
                            label: 'Columnas',
                            value: attrs.columns,
                            min: 1,
                            max: 6,
                            onChange: function(value) { setAttributes({ columns: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Limite de categorias',
                            value: attrs.limit,
                            min: 0,
                            max: 24,
                            onChange: function(value) { setAttributes({ limit: value }); },
                            help: '0 muestra todas.'
                        }),
                        el(ToggleControl, {
                            label: 'Ocultar categorias sin productos',
                            checked: attrs.hideEmpty,
                            onChange: function(value) { setAttributes({ hideEmpty: value }); }
                        })
                    ),
                    el(
                        PanelBody,
                        { title: 'Diseno visual', initialOpen: true },
                        el('p', {}, 'Color overlay'),
                        el(ColorPalette, {
                            value: attrs.overlayColor,
                            onChange: function(value) { setAttributes({ overlayColor: value || '#000000' }); }
                        }),
                        el(RangeControl, {
                            label: 'Oscurecer overlay',
                            value: attrs.overlayOpacity,
                            min: 0,
                            max: 90,
                            onChange: function(value) { setAttributes({ overlayOpacity: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Tamano de titulo',
                            value: attrs.fontSize,
                            min: 14,
                            max: 72,
                            onChange: function(value) { setAttributes({ fontSize: value }); }
                        })
                    ),
                    el(
                        PanelBody,
                        { title: 'Boton', initialOpen: true },
                        el(ToggleControl, {
                            label: 'Mostrar boton',
                            checked: attrs.showButton,
                            onChange: function(value) { setAttributes({ showButton: value }); }
                        }),
                        el(TextControl, {
                            label: 'Texto del boton',
                            value: attrs.buttonText,
                            onChange: function(value) { setAttributes({ buttonText: value }); }
                        }),
                        el('p', {}, 'Color texto del boton'),
                        el(ColorPalette, {
                            value: attrs.buttonTextColor,
                            onChange: function(value) { setAttributes({ buttonTextColor: value || '#ffffff' }); }
                        }),
                        el('p', {}, 'Color fondo del boton'),
                        el(ColorPalette, {
                            value: attrs.buttonBackgroundColor === 'transparent' ? undefined : attrs.buttonBackgroundColor,
                            onChange: function(value) { setAttributes({ buttonBackgroundColor: value || 'transparent' }); }
                        }),
                        el('p', {}, 'Color borde del boton'),
                        el(ColorPalette, {
                            value: attrs.buttonBorderColor,
                            onChange: function(value) { setAttributes({ buttonBorderColor: value || '#ffffff' }); }
                        }),
                        el(RangeControl, {
                            label: 'Tamano fuente boton',
                            value: attrs.buttonFontSize,
                            min: 10,
                            max: 32,
                            onChange: function(value) { setAttributes({ buttonFontSize: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Padding vertical boton',
                            value: attrs.buttonPaddingY,
                            min: 0,
                            max: 32,
                            onChange: function(value) { setAttributes({ buttonPaddingY: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Padding horizontal boton',
                            value: attrs.buttonPaddingX,
                            min: 0,
                            max: 48,
                            onChange: function(value) { setAttributes({ buttonPaddingX: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Margen superior boton',
                            value: attrs.buttonMarginTop,
                            min: 0,
                            max: 60,
                            onChange: function(value) { setAttributes({ buttonMarginTop: value }); }
                        })
                    ),
                    el(
                        PanelBody,
                        { title: 'Efectos de zoom', initialOpen: false },
                        el(RangeControl, {
                            label: 'Zoom al pasar mouse',
                            value: attrs.hoverZoomPercent,
                            min: 0,
                            max: 40,
                            onChange: function(value) { setAttributes({ hoverZoomPercent: value }); },
                            help: 'Porcentaje de crecimiento de la imagen al hacer hover.'
                        }),
                        el(ToggleControl, {
                            label: 'Activar zoom automatico al hacer scroll',
                            checked: attrs.scrollZoomEnabled,
                            onChange: function(value) { setAttributes({ scrollZoomEnabled: value }); }
                        }),
                        el(RangeControl, {
                            label: 'Zoom maximo por scroll',
                            value: attrs.scrollZoomPercent,
                            min: 0,
                            max: 40,
                            onChange: function(value) { setAttributes({ scrollZoomPercent: value }); }
                        })
                    )
                ),
                el(ServerSideRender, {
                    block: 'estandar/product-categories-gallery',
                    attributes: attrs
                })
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender);
JS;
    }

    public static function render_product_categories_shortcode( $atts ): string {
        $atts = shortcode_atts(
            [
                'post_type'       => 'producto',
                'hide_empty'      => '1',
                'columns'         => '3',
                'limit'           => '0',
                'overlay_color'   => '#000000',
                'overlay_opacity' => '45',
                'font_size'       => '28',
                'button_text'     => 'Ver mas',
                'show_button'     => '1',
                'button_text_color' => '#ffffff',
                'button_background_color' => 'transparent',
                'button_border_color' => '#ffffff',
                'button_font_size' => '14',
                'button_padding_y' => '10',
                'button_padding_x' => '18',
                'button_margin_top' => '18',
                'hover_zoom_percent' => '8',
                'scroll_zoom_enabled' => '0',
                'scroll_zoom_percent' => '10',
            ],
            $atts,
            'estandar_product_categories'
        );

        return self::render_category_gallery(
            [
                'post_type'       => $atts['post_type'],
                'hide_empty'      => '0' !== (string) $atts['hide_empty'],
                'columns'         => $atts['columns'],
                'limit'           => $atts['limit'],
                'overlay_color'   => $atts['overlay_color'],
                'overlay_opacity' => $atts['overlay_opacity'],
                'font_size'       => $atts['font_size'],
                'button_text'     => $atts['button_text'],
                'show_button'     => '0' !== (string) $atts['show_button'],
                'button_text_color' => $atts['button_text_color'] ?? '#ffffff',
                'button_background_color' => $atts['button_background_color'] ?? 'transparent',
                'button_border_color' => $atts['button_border_color'] ?? '#ffffff',
                'button_font_size' => $atts['button_font_size'] ?? 14,
                'button_padding_y' => $atts['button_padding_y'] ?? 10,
                'button_padding_x' => $atts['button_padding_x'] ?? 18,
                'button_margin_top' => $atts['button_margin_top'] ?? 18,
                'hover_zoom_percent' => $atts['hover_zoom_percent'] ?? 8,
                'scroll_zoom_enabled' => '1' === (string) ( $atts['scroll_zoom_enabled'] ?? '0' ),
                'scroll_zoom_percent' => $atts['scroll_zoom_percent'] ?? 10,
            ]
        );
    }

	    public static function render_gallery_shortcode( $atts ): string {
	        $atts = shortcode_atts(
	            [
	                'ids'   => '',
	                'align' => '',
	                'align_button' => 'center',
	                'animation' => 'slide',
	                'size'  => 'medium',
	                'more_text' => 'Ver más',
	                'less_text' => 'Ver menos',
	            ],
	            $atts,
	            'estandar_gallery'
	        );

        $ids_raw = trim( (string) $atts['ids'] );
        if ( '' === $ids_raw ) {
            return '';
        }

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'absint',
                        preg_split( '/\s*,\s*/', $ids_raw, -1, PREG_SPLIT_NO_EMPTY ) ?: []
                    )
                )
            )
        );

        if ( empty( $ids ) ) {
            return '';
        }

        $size = self::parse_gallery_size( (string) $atts['size'] );

        $images = [];
        foreach ( $ids as $id ) {
            $img = wp_get_attachment_image(
                $id,
                $size,
                false,
                [
                    'class'   => 'estandar-gallery-img',
                    'loading' => 'lazy',
                    'decoding'=> 'async',
                ]
            );
            if ( '' === $img ) {
                continue;
            }
            $images[] = '<div class="estandar-gallery-item">' . $img . '</div>';
        }

        $count = count( $images );
        if ( 0 === $count ) {
            return '';
        }

        self::enqueue_gallery_assets();

        $more_text = sanitize_text_field( (string) $atts['more_text'] );
        $less_text = sanitize_text_field( (string) $atts['less_text'] );
        if ( '' === $more_text ) {
            $more_text = 'Ver más';
        }
        if ( '' === $less_text ) {
            $less_text = 'Ver menos';
        }

        $classes = [ 'estandar-gallery' ];
        $align   = sanitize_key( (string) $atts['align'] );
        if ( 1 === $count ) {
            $classes[] = 'estandar-gallery-single';
            if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
                $classes[] = 'estandar-gallery-align-' . $align;
            }
        }

	        $grid   = array_slice( $images, 0, 2 );
	        $hidden = array_slice( $images, 2 );

	        $align_button = sanitize_key( (string) $atts['align_button'] );
	        if ( ! in_array( $align_button, [ 'left', 'center', 'right' ], true ) ) {
	            $align_button = 'center';
	        }

	        $animation = sanitize_key( (string) $atts['animation'] );
	        if ( ! in_array( $animation, [ 'slide', 'none' ], true ) ) {
	            $animation = 'slide';
	        }

	        $out  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-enhanced="0" data-expanded="false" data-anim="' . esc_attr( $animation ) . '">';
	        $out .= '<div class="estandar-gallery-grid">' . implode( '', $grid ) . '</div>';

	        if ( ! empty( $hidden ) ) {
	            $out .= '<div class="estandar-gallery-hidden">' . implode( '', $hidden ) . '</div>';
            // Inline button styling as a hard fallback: some sites aggressively cache/minify CSS and can keep old
            // rules that make the toggle effectively invisible. Keep minimal and scoped to this element only.
	            $btn_style = 'display:flex;width:fit-content;align-items:center;justify-content:center;pointer-events:auto;'
	                . 'margin:12px auto 0;padding:10px 16px;border:1px solid #bb0000;background:#ffffff;'
	                . 'color:#bb0000;border-radius:9999px;box-shadow:none;'
	                . 'font:inherit;font-size:14px;line-height:1;cursor:pointer;position:relative;z-index:2;';
	            $out .= '<div class="estandar-gallery-button-wrap align-' . esc_attr( $align_button ) . '">';
	            $out .= '<button type="button" class="estandar-gallery-toggle" aria-expanded="false" data-more="' . esc_attr( $more_text ) . '" data-less="' . esc_attr( $less_text ) . '" style="' . esc_attr( $btn_style ) . '">' . esc_html( $more_text ) . '</button>';
	            $out .= '</div>';
	        }

        $out .= '</div>';
        return $out;
    }

    private static function parse_gallery_size( string $size ) {
        $size = trim( $size );
        if ( '' === $size ) {
            return 'medium';
        }

        $size_key = sanitize_key( $size );
        if ( in_array( $size_key, [ 'thumbnail', 'medium', 'large', 'full' ], true ) ) {
            return $size_key;
        }

        if ( preg_match( '/^(\d+)x(\d+)$/', $size, $m ) ) {
            return [ max( 1, (int) $m[1] ), max( 1, (int) $m[2] ) ];
        }

        if ( preg_match( '/^\d+$/', $size ) ) {
            $w = max( 1, (int) $size );
            return [ $w, 9999 ];
        }

        return 'medium';
    }

	    private static function enqueue_gallery_assets(): void {
	        $style_handle  = 'estandar-cpt-manager-gallery';
	        $script_handle = 'estandar-cpt-manager-gallery';

	        if ( ! wp_style_is( $style_handle, 'registered' ) ) {
	            $style_rel = 'assets/css/estandar-gallery.css';
	            $style_path = plugin_dir_path( __FILE__ ) . $style_rel;
	            $style_ver  = ( is_string( $style_path ) && file_exists( $style_path ) ) ? (string) filemtime( $style_path ) : self::VERSION;
	            wp_register_style(
	                $style_handle,
	                plugins_url( $style_rel, __FILE__ ),
	                [],
	                $style_ver
	            );
	        }

	        if ( ! wp_script_is( $script_handle, 'registered' ) ) {
	            $script_rel = 'assets/js/estandar-gallery.js';
	            $script_path = plugin_dir_path( __FILE__ ) . $script_rel;
	            $script_ver  = ( is_string( $script_path ) && file_exists( $script_path ) ) ? (string) filemtime( $script_path ) : self::VERSION;
	            wp_register_script(
	                $script_handle,
	                plugins_url( $script_rel, __FILE__ ),
	                [],
	                $script_ver,
	                false
	            );
	        }

        wp_enqueue_style( $style_handle );
        wp_enqueue_script( $script_handle );
    }

    public static function maybe_enqueue_gallery_assets(): void {
        if ( is_admin() ) {
            return;
        }

        // Product pages are the primary target for this component and often render content from a separate
        // template post. Enqueue assets unconditionally to avoid late-enqueue or detection edge cases.
        if ( is_singular( 'producto' ) ) {
            self::enqueue_gallery_assets();
            return;
        }

        $contents = [];

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && isset( $post->post_content ) ) {
                $contents[] = (string) $post->post_content;
            }
        }

        // Producto templates are rendered from a separate template post; detect shortcodes there too.
        if ( is_singular( 'producto' ) ) {
            $product_id = absint( get_queried_object_id() );
            if ( $product_id ) {
                $tpl_id = self::get_selected_product_template_id( $product_id );
                $tpl    = $tpl_id ? get_post( (int) $tpl_id ) : null;
                if ( $tpl && isset( $tpl->post_content ) ) {
                    $contents[] = (string) $tpl->post_content;
                }
            }
        }

        foreach ( $contents as $content ) {
            if ( '' === trim( $content ) ) {
                continue;
            }

            if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'estandar_gallery' ) ) {
                self::enqueue_gallery_assets();
                return;
            }

            // Also support the Gutenberg block wrapper.
            if ( false !== strpos( $content, '<!-- wp:estandar/gallery' ) ) {
                self::enqueue_gallery_assets();
                return;
            }
        }
    }

    public static function render_product_categories_block( array $attributes ): string {
        return self::render_category_gallery(
            [
                'post_type'       => $attributes['postType'] ?? 'producto',
                'hide_empty'      => $attributes['hideEmpty'] ?? true,
                'columns'         => $attributes['columns'] ?? 3,
                'limit'           => $attributes['limit'] ?? 0,
                'overlay_color'   => $attributes['overlayColor'] ?? '#000000',
                'overlay_opacity' => $attributes['overlayOpacity'] ?? 45,
                'font_size'       => $attributes['fontSize'] ?? 28,
                'button_text'     => $attributes['buttonText'] ?? 'Ver mas',
                'show_button'     => $attributes['showButton'] ?? true,
                'button_text_color' => $attributes['buttonTextColor'] ?? '#ffffff',
                'button_background_color' => $attributes['buttonBackgroundColor'] ?? 'transparent',
                'button_border_color' => $attributes['buttonBorderColor'] ?? '#ffffff',
                'button_font_size' => $attributes['buttonFontSize'] ?? 14,
                'button_padding_y' => $attributes['buttonPaddingY'] ?? 10,
                'button_padding_x' => $attributes['buttonPaddingX'] ?? 18,
                'button_margin_top' => $attributes['buttonMarginTop'] ?? 18,
                'hover_zoom_percent' => $attributes['hoverZoomPercent'] ?? 8,
                'scroll_zoom_enabled' => $attributes['scrollZoomEnabled'] ?? false,
                'scroll_zoom_percent' => $attributes['scrollZoomPercent'] ?? 10,
            ]
        );
    }

    private static function render_category_gallery( array $args ): string {
        $post_type       = sanitize_key( $args['post_type'] ?? 'producto' );
        $hide_empty      = ! empty( $args['hide_empty'] );
        $columns         = max( 1, min( 6, absint( $args['columns'] ?? 3 ) ) );
        $limit           = absint( $args['limit'] ?? 0 );
        $overlay_color   = sanitize_hex_color( $args['overlay_color'] ?? '#000000' );
        $overlay_color   = $overlay_color ?: '#000000';
        $overlay_opacity = max( 0, min( 90, absint( $args['overlay_opacity'] ?? 45 ) ) );
        $font_size       = max( 14, min( 72, absint( $args['font_size'] ?? 28 ) ) );
        $button_text     = sanitize_text_field( $args['button_text'] ?? 'Ver mas' );
        $show_button     = ! empty( $args['show_button'] ) && '' !== $button_text;
        $button_text_color = sanitize_hex_color( $args['button_text_color'] ?? '#ffffff' ) ?: '#ffffff';
        $button_background_color = self::sanitize_color_or_transparent( $args['button_background_color'] ?? 'transparent', 'transparent' );
        $button_border_color = sanitize_hex_color( $args['button_border_color'] ?? '#ffffff' ) ?: '#ffffff';
        $button_font_size = max( 10, min( 32, absint( $args['button_font_size'] ?? 14 ) ) );
        $button_padding_y = max( 0, min( 32, absint( $args['button_padding_y'] ?? 10 ) ) );
        $button_padding_x = max( 0, min( 48, absint( $args['button_padding_x'] ?? 18 ) ) );
        $button_margin_top = max( 0, min( 60, absint( $args['button_margin_top'] ?? 18 ) ) );
        $hover_zoom_percent = max( 0, min( 40, absint( $args['hover_zoom_percent'] ?? 8 ) ) );
        $scroll_zoom_enabled = ! empty( $args['scroll_zoom_enabled'] );
        $scroll_zoom_percent = max( 0, min( 40, absint( $args['scroll_zoom_percent'] ?? 10 ) ) );
        $post_type       = self::resolve_gallery_post_type( $post_type );
        $overlay_rgba    = self::hex_to_rgba( $overlay_color, $overlay_opacity / 100 );
        $gallery_id      = 'estandar-product-categories-gallery-' . wp_rand( 1000, 999999 );
        $hover_scale     = 1 + ( $hover_zoom_percent / 100 );

        if ( empty( $post_type ) ) {
            return '';
        }

        $term_args = [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( $limit > 0 ) {
            $term_args['number'] = $limit;
        }

        if ( $hide_empty ) {
            $product_ids = get_posts(
                [
                    'post_type'              => $post_type,
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => -1,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );

            if ( empty( $product_ids ) ) {
                return '';
            }

            $term_args['object_ids'] = $product_ids;
        }

        $terms = get_terms( $term_args );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }

        ob_start();
        ?>
        <style>
            #<?php echo esc_attr( $gallery_id ); ?> .estandar-product-category-image {
                --estandar-scroll-scale: 1;
                transform: scale(var(--estandar-scroll-scale));
                transition: transform 520ms ease;
                will-change: transform;
            }
            #<?php echo esc_attr( $gallery_id ); ?> .estandar-product-category-card:hover .estandar-product-category-image {
                transform: scale(calc(var(--estandar-scroll-scale) * <?php echo esc_attr( (string) $hover_scale ); ?>));
            }
        </style>
        <div id="<?php echo esc_attr( $gallery_id ); ?>" class="estandar-product-categories-gallery" data-scroll-zoom="<?php echo $scroll_zoom_enabled ? '1' : '0'; ?>" data-scroll-zoom-percent="<?php echo esc_attr( (string) $scroll_zoom_percent ); ?>" style="display:grid;grid-template-columns:repeat(<?php echo esc_attr( $columns ); ?>,minmax(0,1fr));gap:20px;">
            <?php foreach ( $terms as $term ) : ?>
                <?php
                $image_id = self::get_category_image_id( $term->term_id );
                $term_url = get_term_link( $term );

                if ( is_wp_error( $term_url ) ) {
                    continue;
                }

                $url = add_query_arg( 'post_type', $post_type, $term_url );
                ?>
                <a class="estandar-product-category-card" href="<?php echo esc_url( $url ); ?>" style="position:relative;display:block;text-decoration:none;border-radius:18px;overflow:hidden;background:#222;color:#fff;min-height:260px;">
                    <span class="estandar-product-category-image" style="position:absolute;inset:0;display:block;background:#e9e9e9;overflow:hidden;">
                        <?php if ( $image_id ) : ?>
                            <?php echo wp_get_attachment_image( $image_id, 'categoria-thumb', false, [ 'style' => 'width:100%;height:100%;object-fit:cover;display:block;', 'loading' => 'lazy', 'decoding' => 'async' ] ); ?>
                        <?php else : ?>
                            <span aria-hidden="true" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.68);background:#555;">Sin imagen</span>
                        <?php endif; ?>
                    </span>
                    <span aria-hidden="true" style="position:absolute;inset:0;display:block;background:<?php echo esc_attr( $overlay_rgba ); ?>;"></span>
                    <span style="position:relative;z-index:1;min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;">
                        <span style="display:block;font-size:<?php echo esc_attr( $font_size ); ?>px;line-height:1.05;font-weight:800;">
                            <?php echo esc_html( $term->name ); ?>
                        </span>
                        <?php if ( $show_button ) : ?>
                            <span style="display:inline-flex;margin-top:<?php echo esc_attr( (string) $button_margin_top ); ?>px;padding:<?php echo esc_attr( (string) $button_padding_y ); ?>px <?php echo esc_attr( (string) $button_padding_x ); ?>px;border:1px solid <?php echo esc_attr( $button_border_color ); ?>;border-radius:999px;font-size:<?php echo esc_attr( (string) $button_font_size ); ?>px;font-weight:700;color:<?php echo esc_attr( $button_text_color ); ?>;background:<?php echo esc_attr( $button_background_color ); ?>;">
                                <?php echo esc_html( $button_text ); ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ( $scroll_zoom_enabled && $scroll_zoom_percent > 0 ) : ?>
            <script>
                (function() {
                    var gallery = document.getElementById('<?php echo esc_js( $gallery_id ); ?>');
                    if (!gallery || gallery.dataset.scrollZoom !== '1') {
                        return;
                    }

                    var images = gallery.querySelectorAll('.estandar-product-category-image');
                    var maxZoom = parseFloat(gallery.dataset.scrollZoomPercent || '0') / 100;
                    var ticking = false;

                    function updateZoom() {
                        var viewportHeight = window.innerHeight || document.documentElement.clientHeight;

                        images.forEach(function(image) {
                            var rect = image.getBoundingClientRect();
                            var center = rect.top + (rect.height / 2);
                            var distance = Math.abs((viewportHeight / 2) - center);
                            var progress = Math.max(0, 1 - (distance / viewportHeight));
                            var scale = 1 + (maxZoom * progress);

                            image.style.setProperty('--estandar-scroll-scale', scale.toFixed(3));
                        });

                        ticking = false;
                    }

                    function requestUpdate() {
                        if (!ticking) {
                            window.requestAnimationFrame(updateZoom);
                            ticking = true;
                        }
                    }

                    updateZoom();
                    window.addEventListener('scroll', requestUpdate, { passive: true });
                    window.addEventListener('resize', requestUpdate);
                })();
            </script>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private static function sanitize_color_or_transparent( string $color, string $fallback ): string {
        if ( 'transparent' === strtolower( trim( $color ) ) ) {
            return 'transparent';
        }

        return sanitize_hex_color( $color ) ?: $fallback;
    }

    private static function hex_to_rgba( string $hex, float $alpha ): string {
        $hex = ltrim( $hex, '#' );

        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( 6 !== strlen( $hex ) ) {
            return 'rgba(0,0,0,' . esc_attr( (string) $alpha ) . ')';
        }

        $red   = hexdec( substr( $hex, 0, 2 ) );
        $green = hexdec( substr( $hex, 2, 2 ) );
        $blue  = hexdec( substr( $hex, 4, 2 ) );

        return sprintf( 'rgba(%d,%d,%d,%.2F)', $red, $green, $blue, $alpha );
    }

    private static function resolve_gallery_post_type( string $post_type ): string {
        $defs = self::get_defs();

        if ( isset( $defs[ $post_type ] ) || post_type_exists( $post_type ) ) {
            return $post_type;
        }

        if ( isset( $defs['producto'] ) || post_type_exists( 'producto' ) ) {
            return 'producto';
        }

        $first = array_key_first( $defs );

        return is_string( $first ) ? $first : '';
    }

    public static function register_product_meta_box() {
        // Canonical UI lives in the Gutenberg canvas. Keep legacy metabox only when block editor is not active.
        if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'producto' ) ) {
            return;
        }

        add_meta_box(
            'estandar_producto_layout',
            'Datos legacy: ficha tecnica (metabox)',
            [ __CLASS__, 'render_product_meta_box' ],
            'producto',
            'advanced',
            'low'
        );
    }

    // Legacy (metabox-based) template UI was removed in favor of a single canonical Gutenberg-canvas UI.
    // Kept as a no-op for backward compatibility with older hooks/versions.
    public static function register_product_template_meta_boxes() {}

    private static function list_product_templates(): array {
        $ids = get_posts(
            [
                'post_type'      => self::PRODUCT_TEMPLATE_POST_TYPE,
                'post_status'    => [ 'publish', 'draft', 'private' ],
                'posts_per_page' => 200,
                'fields'         => 'ids',
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]
        );

        $out = [];
        foreach ( $ids as $id ) {
            $p = get_post( (int) $id );
            if ( ! $p ) {
                continue;
            }
            $out[ (int) $id ] = $p->post_title ? (string) $p->post_title : ( 'Plantilla #' . (int) $id );
        }
        return $out;
    }

    private static function get_default_product_template_id(): int {
        // Ensure exists and return the singleton "global" template.
        return self::ensure_product_template_post();
    }

    public static function get_selected_product_template_id( int $product_id ): int {
        $chosen = absint( get_post_meta( $product_id, self::PRODUCT_TEMPLATE_META_KEY, true ) );
        if ( $chosen ) {
            $p = get_post( $chosen );
            if ( $p && self::PRODUCT_TEMPLATE_POST_TYPE === $p->post_type ) {
                return $chosen;
            }
        }
        return self::get_default_product_template_id();
    }

    public static function render_product_template_selector_meta_box( $post ) {
        wp_nonce_field( 'estandar_producto_template_save', 'estandar_producto_template_nonce' );

        $templates  = self::list_product_templates();
        $default_id = self::get_default_product_template_id();
        $current_id = absint( get_post_meta( $post->ID, self::PRODUCT_TEMPLATE_META_KEY, true ) );

        echo '<p style="margin-top:0;">Define qué plantilla (layout) se usa para este producto en el frontend.</p>';
        echo '<p><label for="egm_producto_template_id" style="font-weight:600;display:block;margin:0 0 6px;">Plantilla</label>';
        echo '<select id="egm_producto_template_id" name="egm_producto_template_id" style="width:100%;">';
        echo '<option value="0"' . selected( 0, $current_id, false ) . '>Global (por defecto)</option>';
        foreach ( $templates as $id => $title ) {
            $label = $title;
            if ( (int) $id === (int) $default_id ) {
                $label .= ' (Global)';
            }
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( (int) $id, $current_id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';

        $effective_id = self::get_selected_product_template_id( (int) $post->ID );
        $edit_url     = $effective_id ? admin_url( 'post.php?post=' . (int) $effective_id . '&action=edit' ) : '';

        if ( $edit_url ) {
            echo '<p><a class="button" href="' . esc_url( $edit_url ) . '">Editar esta plantilla</a></p>';
        }

        echo '<p style="color:#64748b;margin:0;">Tip: crea nuevas plantillas en <a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::PRODUCT_TEMPLATE_POST_TYPE ) ) . '">Todas las plantillas</a>.</p>';
    }

    public static function render_product_template_preview_meta_box( $post ) {
        $product_post  = $post;
        $template_id   = self::get_selected_product_template_id( (int) $product_post->ID );
        $template_post = $template_id ? get_post( $template_id ) : null;
        $content       = ( $template_post && isset( $template_post->post_content ) ) ? trim( (string) $template_post->post_content ) : '';

        $nonce = wp_create_nonce( self::PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION );

        if ( '' === $content ) {
            echo '<p style="margin:0;color:#64748b;">No hay contenido en la plantilla seleccionada.</p>';
            return;
        }

        // Render the template blocks as a preview using this product as the global post context.
        global $post;
        $prev_global_post = $post ?? null;
        $post             = get_post( (int) $product_post->ID );
        if ( $post ) {
            setup_postdata( $post );
        }

        echo '<div id="egm-tpl-preview" class="egm-product-page-wrapper" data-post-id="' . esc_attr( (string) $product_post->ID ) . '" data-template-id="' . esc_attr( (string) $template_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">';
        echo wp_kses_post( apply_filters( 'the_content', $content ) );
        echo '</div>';

        if ( $post ) {
            wp_reset_postdata();
        }
        $post = $prev_global_post;
    }

    private static function get_template_schema( int $template_id ): array {
        $schema = get_post_meta( $template_id, self::TEMPLATE_SCHEMA_META_KEY, true );
        return is_array( $schema ) ? $schema : [];
    }

    private static function get_product_template_field_values( int $product_id ): array {
        $values = get_post_meta( $product_id, self::PRODUCT_TEMPLATE_FIELDS_META_KEY, true );
        $values = is_array( $values ) ? $values : [];

        if ( isset( self::$preview_field_overrides[ $product_id ] ) && is_array( self::$preview_field_overrides[ $product_id ] ) ) {
            // Override only the provided keys for live preview rendering.
            $values = array_merge( $values, self::$preview_field_overrides[ $product_id ] );
        }

        return $values;
    }

    private static function get_product_template_attr_overrides( int $product_id ): array {
        $attrs = get_post_meta( $product_id, self::PRODUCT_TEMPLATE_ATTRS_META_KEY, true );
        return is_array( $attrs ) ? $attrs : [];
    }

    public static function render_product_template_fields_meta_box( $post ) {
        wp_nonce_field( 'egm_tpl_fields_save', 'egm_tpl_fields_nonce' );

        $template_id = self::get_selected_product_template_id( (int) $post->ID );
        $schema      = $template_id ? self::get_template_schema( $template_id ) : [];
        $fields      = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];
        $values      = self::get_product_template_field_values( (int) $post->ID );

        if ( empty( $fields ) ) {
            echo '<p style="margin:0 0 8px;color:#64748b;">No se detectaron campos automáticos en la plantilla seleccionada.</p>';
            echo '<p style="margin:0;">En la plantilla, marca cualquier bloque que quieras convertir en campo editable con la clase CSS <code>egm-field</code> (Advanced > Additional CSS class).</p>';
            echo '<p style="margin:8px 0 0;color:#64748b;">Ejemplos típicos: <code>core/heading</code>, <code>core/paragraph</code>, <code>core/image</code>, <code>core/button</code>.</p>';
            return;
        }

        echo '<style>
            .egm-tpl-fields { display:grid; gap:14px; }
            .egm-tpl-field { border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#fff; }
            .egm-tpl-field label { display:block; font-weight:600; margin:0 0 6px; }
            .egm-tpl-help { color:#64748b; font-size:12px; margin-top:6px; }
            .egm-tpl-image-preview img { max-width:180px; height:auto; border-radius:10px; display:block; margin:0 0 10px; }
            .egm-tpl-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        </style>';

        echo '<div class="egm-tpl-fields">';
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key  = isset( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '';
            $type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
            $label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $key;
            if ( '' === $key ) {
                continue;
            }

            echo '<div class="egm-tpl-field">';
            echo '<label for="egm_tpl_field_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';

            if ( 'image' === $type ) {
                $img_id  = absint( $values[ $key ] ?? 0 );
                $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'categoria-thumb' ) : '';
                echo '<input type="hidden" class="egm-tpl-image-id" id="egm_tpl_field_' . esc_attr( $key ) . '" name="egm_tpl_field[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $img_id ) . '"/>';
                echo '<div class="egm-tpl-image-preview" data-key="' . esc_attr( $key ) . '">';
                if ( $img_url ) {
                    echo '<img src="' . esc_url( $img_url ) . '" alt="" />';
                }
                echo '</div>';
                echo '<p style="margin:0;"><button type="button" class="button egm-tpl-image-select" data-target="' . esc_attr( $key ) . '">Seleccionar imagen</button> ';
                echo '<button type="button" class="button egm-tpl-image-remove" data-target="' . esc_attr( $key ) . '"' . ( $img_url ? '' : ' style="display:none;"' ) . '>Quitar</button></p>';
                echo '<div class="egm-tpl-help">Key: <code>' . esc_html( $key ) . '</code></div>';
            } elseif ( 'gallery' === $type ) {
                $val = $values[ $key ] ?? [];
                $ids = [];
                if ( is_array( $val ) ) {
                    $ids = array_values( array_unique( array_filter( array_map( 'absint', $val ) ) ) );
                } else {
                    $ids = array_values(
                        array_unique(
                            array_filter(
                                array_map(
                                    'absint',
                                    preg_split( '/\s*,\s*/', (string) $val, -1, PREG_SPLIT_NO_EMPTY ) ?: []
                                )
                            )
                        )
                    );
                }
                echo '<input class="regular-text" id="egm_tpl_field_' . esc_attr( $key ) . '" name="egm_tpl_field[' . esc_attr( $key ) . ']" type="text" value="' . esc_attr( implode( ',', $ids ) ) . '" />';
                echo '<div class="egm-tpl-help">IDs separados por comas. Ej: <code>123,124,130</code> | Key: <code>' . esc_html( $key ) . '</code></div>';
            } elseif ( 'button' === $type ) {
                $val = $values[ $key ] ?? [];
                $text = is_array( $val ) ? (string) ( $val['text'] ?? '' ) : '';
                $url  = is_array( $val ) ? (string) ( $val['url'] ?? '' ) : '';
                echo '<div class="egm-tpl-row">';
                echo '<div><input class="widefat" type="text" name="egm_tpl_field[' . esc_attr( $key ) . '][text]" value="' . esc_attr( $text ) . '" placeholder="Texto del boton" /></div>';
                echo '<div><input class="widefat" type="url" name="egm_tpl_field[' . esc_attr( $key ) . '][url]" value="' . esc_attr( $url ) . '" placeholder="URL" /></div>';
                echo '</div>';
                echo '<div class="egm-tpl-help">Key: <code>' . esc_html( $key ) . '</code></div>';
            } elseif ( 'textarea' === $type ) {
                $val = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
                echo '<textarea class="widefat" id="egm_tpl_field_' . esc_attr( $key ) . '" name="egm_tpl_field[' . esc_attr( $key ) . ']" rows="4">' . esc_textarea( $val ) . '</textarea>';
                echo '<div class="egm-tpl-help">Key: <code>' . esc_html( $key ) . '</code></div>';
            } else {
                $val = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
                echo '<input class="widefat" type="text" id="egm_tpl_field_' . esc_attr( $key ) . '" name="egm_tpl_field[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" />';
                echo '<div class="egm-tpl-help">Key: <code>' . esc_html( $key ) . '</code></div>';
            }

            echo '</div>';
        }
        echo '</div>';
    }

    public static function save_product_template_meta_boxes( int $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['estandar_producto_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $_POST['estandar_producto_template_nonce'] ), 'estandar_producto_template_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $raw = isset( $_POST['egm_producto_template_id'] ) ? absint( $_POST['egm_producto_template_id'] ) : 0;
        if ( $raw ) {
            $p = get_post( $raw );
            if ( $p && self::PRODUCT_TEMPLATE_POST_TYPE === $p->post_type ) {
                update_post_meta( $post_id, self::PRODUCT_TEMPLATE_META_KEY, $raw );
                return;
            }
        }

        delete_post_meta( $post_id, self::PRODUCT_TEMPLATE_META_KEY );
    }

    public static function save_product_template_fields_meta_box( int $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['egm_tpl_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $_POST['egm_tpl_fields_nonce'] ), 'egm_tpl_fields_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $posted_template_id = isset( $_POST['egm_producto_template_id'] ) ? absint( $_POST['egm_producto_template_id'] ) : 0;
        if ( $posted_template_id ) {
            $p = get_post( $posted_template_id );
            if ( ! ( $p && self::PRODUCT_TEMPLATE_POST_TYPE === $p->post_type ) ) {
                $posted_template_id = 0;
            }
        }

        $template_id = $posted_template_id ?: self::get_default_product_template_id();
        $schema      = $template_id ? self::get_template_schema( (int) $template_id ) : [];
        $fields      = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];

        if ( empty( $fields ) ) {
            // Nothing to save.
            return;
        }

        $raw = isset( $_POST['egm_tpl_field'] ) ? (array) $_POST['egm_tpl_field'] : [];

        $clean = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key  = isset( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '';
            $type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
            if ( '' === $key ) {
                continue;
            }

            $val = $raw[ $key ] ?? null;
            if ( 'image' === $type ) {
                $clean[ $key ] = absint( $val );
            } elseif ( 'gallery' === $type ) {
                if ( is_array( $val ) ) {
                    $ids = array_values( array_unique( array_filter( array_map( 'absint', $val ) ) ) );
                    $clean[ $key ] = $ids;
                } else {
                    $ids = array_values(
                        array_unique(
                            array_filter(
                                array_map(
                                    'absint',
                                    preg_split( '/\s*,\s*/', (string) $val, -1, PREG_SPLIT_NO_EMPTY ) ?: []
                                )
                            )
                        )
                    );
                    $clean[ $key ] = $ids;
                }
            } elseif ( 'button' === $type ) {
                $val = is_array( $val ) ? $val : [];
                $clean[ $key ] = [
                    'text' => sanitize_text_field( (string) ( $val['text'] ?? '' ) ),
                    'url'  => esc_url_raw( (string) ( $val['url'] ?? '' ) ),
                ];
            } elseif ( 'textarea' === $type ) {
                $clean[ $key ] = sanitize_textarea_field( (string) $val );
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $val );
            }
        }

        update_post_meta( $post_id, self::PRODUCT_TEMPLATE_FIELDS_META_KEY, $clean );
    }

    public static function ajax_render_product_template_preview() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( (string) $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, self::PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $post_id || ! $template_id ) {
            wp_send_json_error( [ 'message' => 'Missing ids' ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $product = get_post( $post_id );
        $tpl     = get_post( $template_id );
        if ( ! ( $product && 'producto' === $product->post_type ) ) {
            wp_send_json_error( [ 'message' => 'Invalid product' ], 400 );
        }
        if ( ! ( $tpl && self::PRODUCT_TEMPLATE_POST_TYPE === $tpl->post_type ) ) {
            wp_send_json_error( [ 'message' => 'Invalid template' ], 400 );
        }

        $fields_json = isset( $_POST['fields'] ) ? (string) wp_unslash( $_POST['fields'] ) : '';
        $fields      = [];
        if ( '' !== $fields_json ) {
            $decoded = json_decode( $fields_json, true );
            if ( is_array( $decoded ) ) {
                $fields = $decoded;
            }
        }

        // Sanitize using schema.
        $schema = self::get_template_schema( (int) $template_id );
        $sfields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];
        $clean = [];
        foreach ( $sfields as $f ) {
            if ( ! is_array( $f ) ) continue;
            $key  = isset( $f['key'] ) ? sanitize_key( (string) $f['key'] ) : '';
            $type = isset( $f['type'] ) ? sanitize_key( (string) $f['type'] ) : 'text';
            if ( '' === $key || ! array_key_exists( $key, $fields ) ) continue;

            $val = $fields[ $key ];
            if ( 'image' === $type ) {
                $clean[ $key ] = absint( $val );
            } elseif ( 'gallery' === $type ) {
                if ( is_array( $val ) ) {
                    $clean[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $val ) ) ) );
                } else {
                    $clean[ $key ] = array_values(
                        array_unique(
                            array_filter(
                                array_map(
                                    'absint',
                                    preg_split( '/\s*,\s*/', (string) $val, -1, PREG_SPLIT_NO_EMPTY ) ?: []
                                )
                            )
                        )
                    );
                }
            } elseif ( 'button' === $type ) {
                $val = is_array( $val ) ? $val : [];
                $clean[ $key ] = [
                    'text' => sanitize_text_field( (string) ( $val['text'] ?? '' ) ),
                    'url'  => esc_url_raw( (string) ( $val['url'] ?? '' ) ),
                ];
            } elseif ( 'textarea' === $type ) {
                $clean[ $key ] = sanitize_textarea_field( (string) $val );
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $val );
            }
        }

        // Render in the context of this product.
        self::$preview_field_overrides[ $post_id ] = $clean;

        global $post;
        $prev = $post ?? null;
        $post = $product;
        setup_postdata( $post );

        $content = (string) ( $tpl->post_content ?? '' );
        $html    = apply_filters( 'the_content', $content );

        wp_reset_postdata();
        $post = $prev;

        unset( self::$preview_field_overrides[ $post_id ] );

        wp_send_json_success( [ 'html' => $html ] );
    }

    public static function ajax_get_product_template_schema() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( (string) $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, self::PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        if ( ! $template_id ) {
            wp_send_json_success( [ 'schema' => [ 'version' => 1, 'fields' => [] ] ] );
        }

        $tpl = get_post( $template_id );
        if ( ! ( $tpl && self::PRODUCT_TEMPLATE_POST_TYPE === $tpl->post_type ) ) {
            wp_send_json_error( [ 'message' => 'Invalid template' ], 400 );
        }

        $schema = self::get_template_schema( (int) $template_id );
        $schema = is_array( $schema ) ? $schema : [ 'version' => 1, 'fields' => [] ];
        $fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];

        // Build a non-destructive map of the current template block tree by path to help UI nesting.
        $blocks_by_path = [];
        $tpl_content    = isset( $tpl->post_content ) ? (string) $tpl->post_content : '';
        if ( '' !== trim( $tpl_content ) ) {
            $blocks = parse_blocks( $tpl_content );
            self::build_blocks_by_path_for_ui( $blocks, $blocks_by_path, [] );
        }

        // Return only safe/needed info.
        $safe_fields = [];
        foreach ( $fields as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $path = isset( $f['path'] ) && is_array( $f['path'] ) ? array_map( 'absint', $f['path'] ) : [];
            $safe_fields[] = [
                'key'   => sanitize_key( (string) ( $f['key'] ?? '' ) ),
                'type'  => sanitize_key( (string) ( $f['type'] ?? 'text' ) ),
                'label' => sanitize_text_field( (string) ( $f['label'] ?? '' ) ),
                'blockName' => sanitize_text_field( (string) ( $f['blockName'] ?? '' ) ),
                'path'  => $path,
            ];
        }

        wp_send_json_success(
            [
                'schema' => [
                    'version' => (int) ( $schema['version'] ?? 1 ),
                    'fields'  => $safe_fields,
                    'blocksByPath' => $blocks_by_path,
                ],
            ]
        );
    }

    private static function build_blocks_by_path_for_ui( array $blocks, array &$out, array $path ): void {
        foreach ( $blocks as $i => $b ) {
            if ( ! is_array( $b ) ) {
                continue;
            }
            $block_name = isset( $b['blockName'] ) ? (string) $b['blockName'] : '';
            if ( '' === $block_name ) {
                continue;
            }
            $attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : [];
            $class_name = isset( $attrs['className'] ) ? sanitize_text_field( (string) $attrs['className'] ) : '';

            $current_path = array_merge( $path, [ (int) $i ] );
            $path_key     = implode( '.', array_map( 'intval', $current_path ) );

            $label = '';
            if ( isset( $b['innerHTML'] ) && is_string( $b['innerHTML'] ) ) {
                $label = trim( wp_strip_all_tags( (string) $b['innerHTML'] ) );
            }
            if ( '' !== $label ) {
                $label = preg_replace( '/\s+/', ' ', (string) $label );
                if ( strlen( $label ) > 60 ) {
                    $label = substr( $label, 0, 57 ) . '...';
                }
            } else {
                $parts = explode( '/', $block_name );
                $label = ucfirst( end( $parts ) ?: 'Bloque' );
            }

            $out[ $path_key ] = [
                'blockName' => sanitize_text_field( $block_name ),
                'label'     => sanitize_text_field( $label ),
                'className' => $class_name,
            ];

            if ( isset( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) && ! empty( $b['innerBlocks'] ) ) {
                self::build_blocks_by_path_for_ui( $b['innerBlocks'], $out, $current_path );
            }
        }
    }

    public static function ajax_list_product_templates() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( (string) $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, self::PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $default_id = self::get_default_product_template_id();
        $templates  = self::list_product_templates();

        $out = [];
        foreach ( $templates as $id => $title ) {
            $out[] = [
                'id'       => (int) $id,
                'title'    => (string) $title,
                'isGlobal' => ( (int) $id === (int) $default_id ),
                'editUrl'  => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
            ];
        }

        wp_send_json_success(
            [
                'defaultId' => (int) $default_id,
                'templates' => $out,
            ]
        );
    }

    /**
     * Prevents accidental wiping of canonical template fields when Gutenberg sends null/empty meta
     * due to hydration issues (common when reopening editor and saving without touching fields).
     */
    public static function protect_tpl_fields_from_null_rest_wipe( $prepared_post, WP_REST_Request $request ) {
        $meta = $request->get_param( 'meta' );
        if ( ! is_array( $meta ) ) {
            return $prepared_post;
        }

        $changed = false;

        if ( array_key_exists( self::PRODUCT_TEMPLATE_FIELDS_META_KEY, $meta ) ) {
            $v = $meta[ self::PRODUCT_TEMPLATE_FIELDS_META_KEY ];
            // Null / empty string are never meaningful for our canonical object; treat as "not provided".
            if ( null === $v || '' === $v ) {
                unset( $meta[ self::PRODUCT_TEMPLATE_FIELDS_META_KEY ] );
                $changed = true;
            }
        }

        if ( array_key_exists( self::PRODUCT_TEMPLATE_META_KEY, $meta ) ) {
            $v = $meta[ self::PRODUCT_TEMPLATE_META_KEY ];
            if ( null === $v || '' === $v ) {
                unset( $meta[ self::PRODUCT_TEMPLATE_META_KEY ] );
                $changed = true;
            }
        }

        if ( array_key_exists( self::PRODUCT_TEMPLATE_ATTRS_META_KEY, $meta ) ) {
            $v = $meta[ self::PRODUCT_TEMPLATE_ATTRS_META_KEY ];
            if ( null === $v || '' === $v ) {
                unset( $meta[ self::PRODUCT_TEMPLATE_ATTRS_META_KEY ] );
                $changed = true;
            }
        }

        if ( $changed ) {
            $request->set_param( 'meta', $meta );
        }

        return $prepared_post;
    }

    public static function enqueue_product_editor_canvas_ui() {
        // Only in block editor for Producto. Avoid relying on get_current_screen() here because
        // Astra/Spectra + newer WP can load editor canvas in iframe and screen detection can vary.
        if ( ! is_admin() ) {
            return;
        }

        $post_type = '';
        if ( isset( $_GET['post'] ) ) {
            $post_type = get_post_type( absint( $_GET['post'] ) );
        } elseif ( isset( $_GET['post_type'] ) ) {
            $post_type = sanitize_key( (string) $_GET['post_type'] );
        }

        if ( 'producto' !== $post_type ) {
            return;
        }

        wp_enqueue_media();

        // Styles for canvas preview + fields.
        wp_register_style( 'estandar-cpt-manager-product-editor-canvas', false, [], '1.13.5' );
        wp_enqueue_style( 'estandar-cpt-manager-product-editor-canvas' );
        // Include editor canvas chrome + a safe subset of the frontend styles.
        wp_add_inline_style(
            'estandar-cpt-manager-product-editor-canvas',
            self::product_template_editor_canvas_css() . "\n" .
            self::product_template_preview_css_shared() . "\n" .
            self::product_template_css()
        );

        wp_register_script(
            'estandar-cpt-manager-product-editor-canvas',
            false,
            [ 'wp-element', 'wp-data', 'wp-components', 'wp-block-editor', 'wp-editor', 'wp-api-fetch', 'wp-dom-ready' ],
            '1.13.5',
            true
        );
        wp_enqueue_script( 'estandar-cpt-manager-product-editor-canvas' );

        $default_tpl = self::get_default_product_template_id();
        $nonce       = wp_create_nonce( self::PRODUCT_TEMPLATE_PREVIEW_NONCE_ACTION );

	        $config = [
	            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
	            'nonce'            => $nonce,
	            'defaultTemplateId'=> (int) $default_tpl,
	            'metaKeyTemplate'  => self::PRODUCT_TEMPLATE_META_KEY,
	            'metaKeyFields'    => self::PRODUCT_TEMPLATE_FIELDS_META_KEY,
	            'metaKeyAttrs'     => self::PRODUCT_TEMPLATE_ATTRS_META_KEY,
	            'metaKeySections'  => self::PRODUCT_SECTIONS_META_KEY,
	        ];

        wp_add_inline_script(
            'estandar-cpt-manager-product-editor-canvas',
            'window.EGM_PRODUCT_EDITOR_CANVAS=' . wp_json_encode( $config ) . ';',
            'before'
        );
        wp_add_inline_script( 'estandar-cpt-manager-product-editor-canvas', self::product_editor_canvas_js() );
    }

    public static function enqueue_template_editor_guide_ui() {
        if ( ! is_admin() ) {
            return;
        }
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! ( $screen && 'post' === $screen->base && in_array( $screen->post_type, [ self::PRODUCT_TEMPLATE_POST_TYPE, self::CATEGORY_TEMPLATE_POST_TYPE ], true ) ) ) {
            return;
        }

        wp_register_script(
            'estandar-cpt-manager-template-editor-guide',
            false,
            [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-block-editor' ],
            '1.13.5',
            true
        );
        wp_enqueue_script( 'estandar-cpt-manager-template-editor-guide' );

        wp_register_style( 'estandar-cpt-manager-template-editor-guide', false, [], '1.13.5' );
        wp_enqueue_style( 'estandar-cpt-manager-template-editor-guide' );
        wp_add_inline_style(
            'estandar-cpt-manager-template-editor-guide',
            '.egm-guide code{background:#f1f5f9;padding:2px 6px;border-radius:8px;font-size:12px;}
             .egm-guide p{margin:8px 0;}
             .egm-guide ul{margin:8px 0 8px 18px;}
             /* Optional: highlight dynamic blocks in the canvas */
             .block-editor-block-list__block .egm-field{outline:1px dashed rgba(245,158,11,.65);outline-offset:4px;border-radius:10px;}'
        );

        wp_add_inline_script( 'estandar-cpt-manager-template-editor-guide', self::template_editor_guide_js() );
    }

    private static function template_editor_guide_js(): string {
        $guide = [
            'title' => 'Guia: Bloques dinamicos',
            'intro' => 'Un bloque sera editable por CPT solo si tiene clases CSS en "Advanced > Additional CSS class(es)":',
            'signature' => 'egm-field egm-key-[nombre_unico]',
            'examplesTitle' => 'Ejemplos por bloque (Core)',
            'examples' => [
                [ 'Heading (H1/H2/...)', 'egm-field egm-key-hero_title' ],
                [ 'Paragraph', 'egm-field egm-key-hero_lead' ],
                [ 'Cover (Fondo)', 'egm-field egm-key-hero_bg' ],
                [ 'Image', 'egm-field egm-key-feature_img_1' ],
                [ 'Button', 'egm-field egm-key-cta_main' ],
            ],
            'rulesTitle' => 'Reglas criticas',
            'rules' => [
                'Si un bloque tiene egm-field, su contenido debe ser 100% dinamico. Nada de sufijos/prefijos dentro del mismo bloque.',
                'Si necesitas texto fijo, usa otro bloque separado.',
                'La egm-key es el contrato de datos: debe ser unica y estable. No la cambies despues de usarla.',
                'El template solo define estructura y fallback. El CPT siempre tiene prioridad cuando existe un valor guardado.',
                'Cambiar diseno (ancho, estilos, layout) no debe borrar contenido ni romper keys.',
            ],
            'validationTitle' => 'Checklist antes de publicar',
            'validation' => [
                'CPT antiguo sigue igual.',
                'Editar CPT no borra datos.',
                'Frontend coincide con editor.',
                'Cambiar template no rompe contenido existente si las keys se mantienen.',
            ],
        ];

        return '(() => {
            const wp = window.wp;
            if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.components || !wp.data) return;

            const el = wp.element.createElement;
            const PanelBody = wp.components.PanelBody;
            const Notice = wp.components.Notice;
            const Badge = wp.components.Badge || function(props){
                // Fallback: some WP versions do not expose Badge in @wordpress/components.
                const style = Object.assign({
                    display: "inline-flex",
                    alignItems: "center",
                    padding: "2px 8px",
                    borderRadius: 999,
                    fontSize: 12,
                    fontWeight: 700,
                    background: "#e5e7eb",
                    color: "#111827"
                }, (props && props.style) ? props.style : {});
                return el("span", { style: style }, props && props.children ? props.children : null);
            };
            const ExternalLink = wp.components.ExternalLink;
            const PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
            const useSelect = wp.data.useSelect;

            const GUIDE = ' . wp_json_encode( $guide ) . ';

            function hasToken(className, token){
                if (!className) return false;
                return String(className).split(/\\s+/).indexOf(token) >= 0;
            }
            function extractKey(className){
                if (!className) return "";
                const m = String(className).match(/\\begm-key-([a-z0-9_-]+)\\b/i);
                return m ? m[1] : "";
            }
            function walkBlocks(blocks, out){
                (blocks || []).forEach((b) => {
                    if (!b) return;
                    const attrs = b.attributes || {};
                    const cls = attrs.className || "";
                    if (hasToken(cls, "egm-field")) {
                        out.push({
                            clientId: b.clientId,
                            name: b.name || "",
                            className: cls,
                            key: extractKey(cls),
                            content: (typeof attrs.content === "string") ? attrs.content : (typeof attrs.text === "string" ? attrs.text : "")
                        });
                    }
                    if (b.innerBlocks && b.innerBlocks.length) {
                        walkBlocks(b.innerBlocks, out);
                    }
                });
                return out;
            }

            function computeReport(items){
                const counts = {};
                items.forEach((it) => {
                    if (it.key) counts[it.key] = (counts[it.key] || 0) + 1;
                });

                const report = items.map((it) => {
                    const errs = [];
                    const warns = [];
                    if (!it.key) {
                        errs.push("Tiene egm-field pero no tiene egm-key-*");
                    } else {
                        if (counts[it.key] > 1) errs.push("egm-key duplicada (" + counts[it.key] + " veces)");
                        if (!/^[a-z0-9_-]+$/.test(it.key)) warns.push("Formato de key no recomendado");
                    }
                    // Heuristica suave: detectar sufijos tipo "en H1" dentro de un bloque dinamico.
                    if (it.content && /\\ben\\s*h[1-6]\\b/i.test(it.content)) {
                        warns.push("Parece haber texto fijo en el mismo bloque (ej: en H1)");
                    }
                    return Object.assign({}, it, { errors: errs, warnings: warns });
                });

                const errorCount = report.reduce((n, r) => n + (r.errors.length ? 1 : 0), 0);
                const warnCount  = report.reduce((n, r) => n + (r.warnings.length ? 1 : 0), 0);
                return { report, errorCount, warnCount };
            }

            function GuidePanel(){
                const dynamicBlocks = useSelect((select) => {
                    const store = select("core/block-editor");
                    const blocks = (store && store.getBlocks) ? store.getBlocks() : [];
                    return walkBlocks(blocks, []);
                }, []);

                const { report, errorCount, warnCount } = computeReport(dynamicBlocks);
                const status = errorCount ? "error" : (warnCount ? "warning" : "success");
                const statusLabel = errorCount ? "Errores" : (warnCount ? "Advertencias" : "Valido");

                return el(
                    PluginDocumentSettingPanel,
                    { name: "egm-template-guide", title: GUIDE.title, className: "egm-guide" },
                    el(Notice, { status: status === "success" ? "success" : (status === "warning" ? "warning" : "error"), isDismissible: false },
                        el("p", null, GUIDE.intro),
                        el("p", null, el("code", null, GUIDE.signature)),
                        el("p", null, "Estado del template: ", el(Badge, { style: { marginLeft: 6 } }, statusLabel),
                            errorCount ? el("span", null, " (", errorCount, ")") : null,
                            warnCount ? el("span", null, " (", warnCount, ")") : null
                        )
                    ),
                    el(PanelBody, { title: "Bloques dinamicos detectados", initialOpen: true },
                        (report.length === 0)
                            ? el("p", { style: { color: "#64748b" } }, "No se detectaron bloques con ", el("code", null, "egm-field"), ".")
                            : el("ul", null,
                                ...report.map((it) => {
                                    const keyTxt = it.key ? it.key : "(sin key)";
                                    const label = keyTxt + "  \u2192  " + (it.name || "bloque");
                                    const badge = it.errors.length ? "ERROR" : (it.warnings.length ? "WARN" : "OK");
                                    const badgeStyle = it.errors.length
                                        ? { background: "#fee2e2", color: "#991b1b" }
                                        : (it.warnings.length ? { background: "#ffedd5", color: "#9a3412" } : { background: "#dcfce7", color: "#166534" });
                                    return el("li", { key: it.clientId },
                                        el(Badge, { style: Object.assign({ marginRight: 8 }, badgeStyle) }, badge),
                                        label,
                                        it.errors.length ? el("div", { style: { marginTop: 6, color: "#991b1b" } }, it.errors.join(" | ")) : null,
                                        it.warnings.length ? el("div", { style: { marginTop: 6, color: "#9a3412" } }, it.warnings.join(" | ")) : null
                                    );
                                })
                            )
                    ),
                    el(PanelBody, { title: GUIDE.examplesTitle, initialOpen: true },
                        el("ul", null,
                            ...(GUIDE.examples || []).map(([label, code]) =>
                                el("li", { key: code }, label, ": ", el("code", null, code))
                            )
                        )
                    ),
                    el(PanelBody, { title: "Shortcodes utiles", initialOpen: false },
                        el("p", null, "Galeria simple (2 visibles + Ver mas):"),
                        el("p", null, el("code", null, "[estandar_gallery ids=\"1,2,3\" size=\"medium\"]")),
                        el("p", null, "Si hay 1 sola imagen, puedes alinear (solo aplica en ese caso):"),
                        el("p", null, el("code", null, "[estandar_gallery ids=\"1\" align=\"center\" size=\"large\"]")),
                        el("p", { style: { color: "#64748b" } }, "Tip IDs: en Medios > Biblioteca, abre una imagen y mira el numero en la URL (post=123).")
                    ),
                    el(PanelBody, { title: GUIDE.rulesTitle, initialOpen: true },
                        el("ul", null,
                            ...(GUIDE.rules || []).map((t, i) => el("li", { key: i }, t))
                        )
                    ),
                    el(PanelBody, { title: GUIDE.validationTitle, initialOpen: false },
                        el("ul", null,
                            ...(GUIDE.validation || []).map((t, i) => el("li", { key: i }, t))
                        )
                    )
                );
            }

            wp.plugins.registerPlugin("egm-template-guide", { render: GuidePanel });
        })();';
    }

    private static function product_editor_canvas_js(): string {
        return <<<'JS'
(function(wp){
    if (!wp || !window.EGM_PRODUCT_EDITOR_CANVAS) return;
    var cfg = window.EGM_PRODUCT_EDITOR_CANVAS;

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var hasCreateRoot = !!(wp.element && wp.element.createRoot);
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var Button = wp.components.Button;
    var SelectControl = wp.components.SelectControl;
    var Spinner = wp.components.Spinner;
    var ToggleControl = wp.components.ToggleControl || null;
    var CheckboxControl = wp.components.CheckboxControl || null;
    var MediaUpload = (wp.blockEditor && wp.blockEditor.MediaUpload) ? wp.blockEditor.MediaUpload : (wp.editor ? wp.editor.MediaUpload : null);
    var MediaUploadCheck = (wp.blockEditor && wp.blockEditor.MediaUploadCheck) ? wp.blockEditor.MediaUploadCheck : (wp.editor ? wp.editor.MediaUploadCheck : null);

    function lsGet(key){
        try { return window.localStorage ? window.localStorage.getItem(key) : null; } catch (e) { return null; }
    }
    function lsSet(key, value){
        try { if (window.localStorage) window.localStorage.setItem(key, value); } catch (e) {}
    }
    function lsGetBool(key){ return lsGet(key) === '1'; }
    function lsSetBool(key, v){ lsSet(key, v ? '1' : '0'); }

    function ajaxPost(data){
        return window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams(data).toString()
        }).then(function(r){ return r.json(); });
    }

    function apiFetch(opts){
        if (wp.apiFetch) return wp.apiFetch(opts);
        // Fallback: no apiFetch available (should not happen in Gutenberg).
        return Promise.reject(new Error('apiFetch not available'));
    }

    function CanvasApp(){
        var postId = useSelect(function(s){ return s('core/editor').getCurrentPostId(); }, []);
        var postType = useSelect(function(s){ return s('core/editor').getCurrentPostType && s('core/editor').getCurrentPostType(); }, []);
        var meta = useSelect(function(s){ return s('core/editor').getEditedPostAttribute('meta') || {}; }, []);
	        var editedTemplateId = meta[cfg.metaKeyTemplate] || 0;
	        var templateId = editedTemplateId || cfg.defaultTemplateId;
	        var fieldsMeta = meta[cfg.metaKeyFields] || {};
	        var attrsMeta = meta[cfg.metaKeyAttrs] || {};
	        var sectionsMeta = meta[cfg.metaKeySections] || {};
	        var editPost = useDispatch('core/editor').editPost;

        var [schema, setSchema] = useState({fields:[], blocksByPath:{}});
        var [templates, setTemplates] = useState([]);
        var [loadingTemplates, setLoadingTemplates] = useState(false);
        var [loadingSchema, setLoadingSchema] = useState(false);
        var [previewHtml, setPreviewHtml] = useState(null);
        var [loadingPreview, setLoadingPreview] = useState(false);
        var timerRef = useRef(null);
        var [hydrating, setHydrating] = useState(false);
        var previewRestoreRef = useRef({ pending:false, y:0 });
        var previewRequestKindRef = useRef('init'); // init|typing|manual
	        var uiPrefix = 'egm_tpl_ui:' + String(postType || '') + ':' + String(postId || 0) + ':' + String(templateId || 0) + ':';
	        var [canvasCollapsed, setCanvasCollapsed] = useState(function(){
	            return lsGetBool(uiPrefix + 'canvas_collapsed');
	        });
	        var [fieldQuery, setFieldQuery] = useState('');

	        useEffect(function(){
	            // UI-only state: keep per-post/per-template collapse state stable when switching templates.
	            setCanvasCollapsed(lsGetBool(uiPrefix + 'canvas_collapsed'));
	        }, [uiPrefix]);

	        function FieldAccordion(props){
	            var fKey = props.fieldKey || '';
	            var title = props.title || '';
	            var storageKey = uiPrefix + 'field_collapsed:' + String(fKey);
	            var [open, setOpen] = useState(function(){
	                return !lsGetBool(storageKey);
	            });
	            useEffect(function(){
	                lsSetBool(storageKey, !open);
	            }, [open, storageKey]);

	            return el('details', {
	                className: 'egm-editor-template-field',
	                open: open,
	                onToggle: function(e){
	                    try {
	                        var nextOpen = !!(e && e.target && e.target.open);
	                        setOpen(nextOpen);
	                    } catch (_) {}
	                }
	            },
	                el('summary', null,
	                    el('span', null, title),
	                    el('span', { className:'egm-editor-template-field__summary-key' }, 'Key: ', fKey)
	                ),
	                el('div', null, props.children)
	            );
	        }

	        function ensureHydratedFromRest(){
	            if (!postId || postType !== 'producto') return;

	            // If meta keys are missing entirely, fetch the canonical record and inject.
	            var hasFieldsKey = Object.prototype.hasOwnProperty.call(meta, cfg.metaKeyFields);
	            var hasTplKey = Object.prototype.hasOwnProperty.call(meta, cfg.metaKeyTemplate);
	            var hasSectionsKey = Object.prototype.hasOwnProperty.call(meta, cfg.metaKeySections);
	            if (hasFieldsKey && hasTplKey && hasSectionsKey) return;

            setHydrating(true);
	            apiFetch({ path: '/wp/v2/producto/' + postId + '?context=edit' })
	                .then(function(rec){
	                    var rmeta = (rec && rec.meta) ? rec.meta : {};
	                    var merged = Object.assign({}, meta);
	                    if (!hasFieldsKey && rmeta && rmeta[cfg.metaKeyFields] !== undefined) {
	                        merged[cfg.metaKeyFields] = rmeta[cfg.metaKeyFields] || {};
	                    }
	                    if (!hasTplKey && rmeta && rmeta[cfg.metaKeyTemplate] !== undefined) {
	                        merged[cfg.metaKeyTemplate] = rmeta[cfg.metaKeyTemplate] || 0;
	                    }
	                    if (!hasSectionsKey && rmeta && rmeta[cfg.metaKeySections] !== undefined) {
	                        merged[cfg.metaKeySections] = rmeta[cfg.metaKeySections] || {};
	                    }
	                    // Only write if we actually got something; avoid clobbering with undefined.
	                    editPost({ meta: merged });
	                })
                .catch(function(){ /* ignore */ })
                .finally(function(){ setHydrating(false); });
        }

        function setTemplateChoice(id){
            id = parseInt(id, 10) || 0;
            var merged = Object.assign({}, meta);
            merged[cfg.metaKeyTemplate] = id;
            editPost({ meta: merged });
        }

        function setField(key, value){
            var next = Object.assign({}, fieldsMeta);
            next[key] = value;
            // Merge with existing meta to avoid clobbering other meta keys (Astra/Spectra can add their own).
            var merged = Object.assign({}, meta);
            merged[cfg.metaKeyFields] = next;
            editPost({ meta: merged });
        }

	        function setAttrOverride(key, partial){
	            var next = Object.assign({}, attrsMeta);
	            next[key] = Object.assign({}, (next[key] || {}), partial);
	            var merged = Object.assign({}, meta);
	            merged[cfg.metaKeyAttrs] = next;
	            editPost({ meta: merged });
	        }

	        function setSectionsMeta(nextObj){
	            nextObj = nextObj && typeof nextObj === 'object' ? nextObj : {};
	            var merged = Object.assign({}, meta);
	            merged[cfg.metaKeySections] = nextObj;
	            editPost({ meta: merged });
	        }

	        function ensureSectionsEnabled(enabled){
	            enabled = !!enabled;
	            var keys = (sectionsMeta && typeof sectionsMeta === 'object') ? Object.keys(sectionsMeta) : [];
	            if (enabled && keys.length) return;
	            if (!enabled && !keys.length) return;

	            if (!enabled) {
	                setSectionsMeta({});
	                return;
	            }

	            setSectionsMeta({
	                features: { active: 1, title: '', anchor: '' },
	                modes: { active: 1, title: '', anchor: '' },
	                metrics: { active: 1, title: '', anchor: '' },
	                showcase: { active: 1, title: '', anchor: '' },
	                specs: { active: 1, title: '', anchor: '' },
	                applications: { active: 1, title: '', anchor: '' }
	            });
	        }

	        function updateSection(id, partial){
	            var cur = (sectionsMeta && typeof sectionsMeta === 'object') ? sectionsMeta : {};
	            var next = Object.assign({}, cur);
	            var row = next[id] && typeof next[id] === 'object' ? next[id] : { active: 1, title: '', anchor: '' };
	            next[id] = Object.assign({}, row, partial);
	            setSectionsMeta(next);
	        }

		        function renderSectionsControls(){
		            var Control = ToggleControl || CheckboxControl;
		            if (!Control) return null;

		            var enabled = (sectionsMeta && typeof sectionsMeta === 'object') ? Object.keys(sectionsMeta).length > 0 : false;
		            var hasNavPlaceholder = !!(previewHtml && String(previewHtml).indexOf('egm-product-anchor-nav') !== -1);
		            var activeCount = 0;
		            if (enabled) {
		                Object.keys(sectionsMeta || {}).forEach(function(k){
		                    var r = sectionsMeta[k];
		                    var on = (r && typeof r === 'object' && r.active !== undefined) ? !!r.active : true;
		                    if (on) activeCount++;
		                });
		            }

		            var defs = [
		                { id:'features', label:'Características', placeholderTitle:'Características', placeholderAnchor:'caracteristicas' },
		                { id:'modes', label:'Modos', placeholderTitle:'Modos de operación', placeholderAnchor:'modos' },
	                { id:'metrics', label:'Métricas', placeholderTitle:'Métricas', placeholderAnchor:'metricas' },
	                { id:'showcase', label:'Showcase', placeholderTitle:'Showcase', placeholderAnchor:'showcase' },
	                { id:'specs', label:'Especificaciones', placeholderTitle:'Especificaciones', placeholderAnchor:'especificaciones' },
	                { id:'applications', label:'Aplicaciones', placeholderTitle:'Aplicaciones', placeholderAnchor:'aplicaciones' }
	            ];

		            return el('details', { style: { marginBottom: 12, padding: 12, border: '1px solid #e5e7eb', borderRadius: 12, background: '#fff' } },
		                el('summary', { style: { cursor: 'pointer', fontWeight: 700 } }, 'Menú interno (sticky)'),
		                el('div', { style: { marginTop: 10, color: '#334155' } },
		                    el('p', { style:{ marginTop: 0 } }, 'Esto controla el menú de navegación interno que aparece arriba de la ficha del producto (solo en el sitio público).'),
		                    el('ol', { style:{ margin:'8px 0 0 18px' } },
		                        el('li', null, 'Primero: en la Plantilla de producto debe existir un Grupo con clase ', el('code', null, 'egm-product-anchor-nav'), '.'),
		                        el('li', null, 'Segundo: aquí puedes activar/desactivar secciones y cambiar su título/anchor para este producto.'),
		                        el('li', null, 'Por último: presiona Actualizar/Publicar para guardar.')
		                    )
		                ),
		                (!hasNavPlaceholder) ? el('div', { style:{ marginTop: 10, padding:'10px 12px', border:'1px solid #fde68a', background:'#fffbeb', borderRadius: 12, color:'#92400e' } },
		                    el('strong', null, 'Falta el bloque de navegación en la plantilla. '),
		                    el('span', null, 'Agrega un bloque Grupo con la clase '),
		                    el('code', null, 'egm-product-anchor-nav'),
		                    el('span', null, ' (Plantilla Productos).')
		                ) : null,
		                el(Control, {
		                    label: 'Personalizar secciones para este producto (si no, usa lo global)',
		                    checked: enabled,
		                    onChange: function(v){ ensureSectionsEnabled(!!v); }
		                }),
		                enabled ? el('div', { style:{ marginTop: 8, color:'#64748b', fontSize: 12 } },
		                    'Estado: ' + activeCount + ' secciones activas (el menú suele mostrarse cuando hay al menos 2 secciones activas con contenido).'
		                ) : el('div', { style:{ marginTop: 8, color:'#64748b', fontSize: 12 } },
		                    'Estado: usando configuración global (no hay overrides guardados para este producto).'
		                ),
		                enabled ? el('div', { style:{ marginTop: 10, display:'grid', gap: 10 } },
		                    defs.map(function(d){
		                        var row = (sectionsMeta && sectionsMeta[d.id]) ? sectionsMeta[d.id] : {};
		                        var isActive = (row.active === undefined) ? true : !!row.active;
	                        return el('div', { key:d.id, style:{ border:'1px solid #eef2f7', borderRadius: 12, padding: 10, background:'#fff' } },
	                            el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', alignItems:'end' } },
	                                el(Control, {
	                                    label: d.label,
	                                    checked: isActive,
	                                    onChange: function(v){ updateSection(d.id, { active: v ? 1 : 0 }); }
	                                }),
	                                el(TextControl, {
	                                    label: 'Anchor (#id)',
	                                    value: row.anchor || '',
	                                    placeholder: d.placeholderAnchor,
	                                    onChange: function(v){ updateSection(d.id, { anchor: v }); }
	                                })
	                            ),
	                            el(TextControl, {
	                                label: 'Título (H3 / menú)',
	                                value: row.title || '',
	                                placeholder: d.placeholderTitle,
	                                onChange: function(v){ updateSection(d.id, { title: v }); }
	                            })
	                        );
	                    })
	                ) : null
	            );
	        }

        function renderVisibilityControls(key, aov){
            aov = aov && typeof aov === 'object' ? aov : {};
            var isVisible = (aov.visible === undefined) ? true : !!aov.visible;
            var hideIfEmpty = !!aov.hideIfEmpty;

            var Control = ToggleControl || CheckboxControl;
            if (!Control) return null;

            return el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
                el(Control, {
                    label: 'Mostrar este bloque',
                    checked: isVisible,
                    onChange: function(v){ setAttrOverride(key, { visible: v ? 1 : 0 }); }
                }),
                el(Control, {
                    label: 'Auto-ocultar si está vacío',
                    checked: hideIfEmpty,
                    onChange: function(v){ setAttrOverride(key, { hideIfEmpty: v ? 1 : 0 }); }
                })
            );
        }

        function requestTemplates(){
            if (!postId || postType !== 'producto') return;
            setLoadingTemplates(true);
            ajaxPost({ action:'egm_tpl_templates_list', nonce: cfg.nonce, post_id: String(postId) })
                .then(function(resp){
                    if (resp && resp.success && resp.data && Array.isArray(resp.data.templates)) {
                        setTemplates(resp.data.templates);
                    }
                })
                .finally(function(){ setLoadingTemplates(false); });
        }

        function requestSchema(){
            if (!postId || !templateId || postType !== 'producto') return;
            setLoadingSchema(true);
            ajaxPost({ action:'egm_tpl_schema_get', nonce: cfg.nonce, template_id: String(templateId), post_id: String(postId) })
                .then(function(resp){
                    if (resp && resp.success && resp.data && resp.data.schema) {
                        var next = resp.data.schema || {};
                        if (!Array.isArray(next.fields)) next.fields = [];
                        if (!next.blocksByPath) next.blocksByPath = {};
                        setSchema(next);
                    }
                })
                .finally(function(){ setLoadingSchema(false); });
        }

        function requestPreview(){
            if (!postId || !templateId || postType !== 'producto') return;
            setLoadingPreview(true);
            ajaxPost({
                action:'egm_tpl_preview_render',
                nonce: cfg.nonce,
                post_id: String(postId),
                template_id: String(templateId),
                fields: JSON.stringify(fieldsMeta || {})
            })
            .then(function(resp){
                if (resp && resp.success && resp.data && typeof resp.data.html === 'string') {
                    // Prevent scroll-jump while typing: preview updates change height above inputs.
                    // If the user is currently editing a field, restore scroll position after preview re-renders.
                    try {
                        var ae = document.activeElement;
                        var isEditing = !!(ae && ae.tagName && (String(ae.tagName).toLowerCase() === 'input' || String(ae.tagName).toLowerCase() === 'textarea'));
                        var inCanvas = !!(ae && ae.closest && ae.closest('.egm-editor-template-canvas'));
                        if (previewRequestKindRef.current === 'typing' && isEditing && inCanvas) {
                            previewRestoreRef.current = { pending:true, y: window.scrollY || 0 };
                        } else {
                            previewRestoreRef.current = { pending:false, y: 0 };
                        }
                    } catch (e) {
                        previewRestoreRef.current = { pending:false, y: 0 };
                    }
                    setPreviewHtml(resp.data.html);
                }
            })
            .finally(function(){ setLoadingPreview(false); });
        }

        useEffect(function(){
            ensureHydratedFromRest();
            requestTemplates();
            requestSchema();
        }, [templateId, postId]);

        useEffect(function(){
            if (timerRef.current) clearTimeout(timerRef.current);
            // Debounce preview updates so typing stays stable.
            previewRequestKindRef.current = 'typing';
            timerRef.current = setTimeout(requestPreview, 450);
            return function(){ if (timerRef.current) clearTimeout(timerRef.current); };
        }, [templateId, postId, JSON.stringify(fieldsMeta)]);

        useEffect(function(){
            // After preview HTML updates, restore scroll position if the update happened while typing.
            if (!previewRestoreRef.current || !previewRestoreRef.current.pending) return;
            var y = previewRestoreRef.current.y || 0;
            previewRestoreRef.current.pending = false;
            window.requestAnimationFrame(function(){
                try { window.scrollTo(0, y); } catch (_) {}
            });
        }, [previewHtml]);

	        var q = String(fieldQuery || '').trim().toLowerCase();
	        var schemaFields = (schema.fields || []).filter(function(f){
                if (!f || typeof f !== 'object') return false;
	            if (!q) return true;
	            var key = (f && f.key) ? String(f.key) : '';
	            var label = (f && f.label) ? String(f.label) : '';
	            return key.toLowerCase().indexOf(q) !== -1 || label.toLowerCase().indexOf(q) !== -1;
	        });

        function renderFieldNode(f){
            var key = f.key;
            var type = f.type || 'text';
            var label = f.label || key;
            var blockName = f.blockName || '';
            if (!key) return null;

            if (type === 'video') {
                var aovVid = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};
                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el(TextControl, {
                        label: label + ' (URL)',
                        help: 'Acepta YouTube (youtube.com / youtu.be) o video directo (.mp4/.webm/.ogg).',
                        value: fieldsMeta[key] || '',
                        onChange: function(v){ setField(key, v); }
                    }),
                    renderVisibilityControls(key, aovVid),
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            if (type === 'html') {
                var aovHtml = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};
                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el(TextareaControl, {
                        label: label + ' (HTML)',
                        help: 'Pega aquí HTML (por ejemplo una <table>...</table>). Se renderiza en el frontend.',
                        value: fieldsMeta[key] || '',
                        onChange: function(v){ setField(key, v); }
                    }),
                    renderVisibilityControls(key, aovHtml),
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            if (type === 'textarea') {
                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el(TextareaControl, {
                        label: label,
                        value: fieldsMeta[key] || '',
                        onChange: function(v){ setField(key, v); }
                    }),
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            if (type === 'image' && MediaUpload && MediaUploadCheck) {
                var idVal = fieldsMeta[key] || 0;
                var aovImg = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};
                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el(MediaUploadCheck, null,
                        el(MediaUpload, {
                            onSelect: function(media){ setField(key, media && media.id ? media.id : 0); },
                            allowedTypes: ['image'],
                            value: idVal,
                            render: function(obj){
                                return el('div', null,
                                    el(Button, { variant:'secondary', onClick: obj.open }, idVal ? 'Cambiar imagen' : 'Seleccionar imagen'),
                                    idVal ? el(Button, { variant:'tertiary', onClick: function(){ setField(key, 0); }, style:{ marginLeft: 8 } }, 'Quitar') : null
                                );
                            }
                        })
                    ),
                    el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
                        el(SelectControl, {
                            label: 'Ancho/alineacion',
                            value: (aovImg.align !== undefined ? aovImg.align : ''),
                            options: [
                                { label: 'Heredar (plantilla)', value: '' },
                                { label: 'Ancho completo (full)', value: 'full' },
                                { label: 'Ancho amplio (wide)', value: 'wide' }
                            ],
                            onChange: function(v){ setAttrOverride(key, { align: v }); }
                        }),
                        el(TextControl, { label: 'Clases extra', value: aovImg.className || '', onChange: function(v){ setAttrOverride(key, { className: v }); } })
                    ),
                    renderVisibilityControls(key, aovImg),
                    (blockName === 'core/cover') ? el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
                        el(TextControl, {
                            label: 'Overlay (dimRatio 0-100)',
                            type: 'number',
                            value: (aovImg.dimRatio !== undefined ? aovImg.dimRatio : ''),
                            onChange: function(v){ setAttrOverride(key, { dimRatio: parseInt(v,10) || 0 }); }
                        }),
                        el(TextControl, {
                            label: 'Min height (px)',
                            type: 'number',
                            value: (aovImg.minHeight !== undefined ? aovImg.minHeight : ''),
                            onChange: function(v){ setAttrOverride(key, { minHeight: parseInt(v,10) || 0 }); }
                        })
                    ) : null,
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            if (type === 'gallery' && MediaUpload && MediaUploadCheck) {
                var idsVal = fieldsMeta[key];
                var idsArr = Array.isArray(idsVal) ? idsVal : (typeof idsVal === 'string' ? idsVal.split(',').map(function(x){ return parseInt(x,10) || 0; }).filter(Boolean) : []);
                var aovGal = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};

                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el(MediaUploadCheck, null,
                        el(MediaUpload, {
                            onSelect: function(media){
                                var nextIds = [];
                                if (Array.isArray(media)) {
                                    media.forEach(function(m){ if (m && m.id) nextIds.push(m.id); });
                                } else if (media && media.id) {
                                    nextIds = [media.id];
                                }
                                setField(key, nextIds);
                            },
                            allowedTypes: ['image'],
                            multiple: true,
                            value: idsArr,
                            render: function(obj){
                                return el('div', null,
                                    el(Button, { variant:'secondary', onClick: obj.open }, idsArr.length ? 'Cambiar imágenes' : 'Seleccionar imágenes'),
                                    idsArr.length ? el(Button, { variant:'tertiary', onClick: function(){ setField(key, []); }, style:{ marginLeft: 8 } }, 'Quitar') : null
                                );
                            }
                        })
                    ),
                    el('div', { style:{ marginTop: 8, color:'#64748b', fontSize: 12 } },
                        idsArr.length ? ('Seleccionadas: ' + idsArr.length + ' (IDs: ' + idsArr.join(', ') + ')') : 'Sin imágenes seleccionadas.'
                    ),
	                    el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
	                        el(TextControl, {
	                            label: 'Texto botón (Ver más)',
	                            value: (aovGal.moreText !== undefined ? aovGal.moreText : ''),
	                            placeholder: 'Ver más',
	                            onChange: function(v){ setAttrOverride(key, { moreText: v }); }
	                        }),
	                        el(TextControl, {
	                            label: 'Texto botón (Ver menos)',
	                            value: (aovGal.lessText !== undefined ? aovGal.lessText : ''),
	                            placeholder: 'Ver menos',
	                            onChange: function(v){ setAttrOverride(key, { lessText: v }); }
	                        })
	                    ),
	                    el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
	                        el(SelectControl, {
	                            label: 'Alineación del botón',
	                            value: (aovGal.alignButton !== undefined ? aovGal.alignButton : ''),
	                            options: [
	                                { label: 'Default (centro)', value: '' },
	                                { label: 'left', value: 'left' },
	                                { label: 'center', value: 'center' },
	                                { label: 'right', value: 'right' }
	                            ],
	                            onChange: function(v){ setAttrOverride(key, { alignButton: v }); }
	                        }),
	                        el(SelectControl, {
	                            label: 'Animación',
	                            value: (aovGal.animation !== undefined ? aovGal.animation : ''),
	                            options: [
	                                { label: 'Default (slide)', value: '' },
	                                { label: 'slide', value: 'slide' },
	                                { label: 'none', value: 'none' }
	                            ],
	                            onChange: function(v){ setAttrOverride(key, { animation: v }); }
	                        })
	                    ),
                    renderVisibilityControls(key, aovGal),
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            if (type === 'button') {
                var val = fieldsMeta[key] && typeof fieldsMeta[key] === 'object' ? fieldsMeta[key] : {text:'',url:'',opensInNewTab:0,rel:''};
                var aov = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};
                return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                    el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px' } },
                        el(TextControl, { label: label + ' (texto)', value: val.text || '', onChange: function(v){ setField(key, Object.assign({}, val, {text:v})); } }),
                        el(TextControl, { label: label + ' (url)', value: val.url || '', onChange: function(v){ setField(key, Object.assign({}, val, {url:v})); } })
                    ),
                    el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px', marginTop: 8 } },
                        el(SelectControl, {
                            label: 'Ancho/alineacion',
                            value: (aov.align !== undefined ? aov.align : ''),
                            options: [
                                { label: 'Heredar (plantilla)', value: '' },
                                { label: 'Ancho completo (full)', value: 'full' },
                                { label: 'Ancho amplio (wide)', value: 'wide' }
                            ],
                            onChange: function(v){ setAttrOverride(key, { align: v }); }
                        }),
                        el(TextControl, { label: 'Clases extra', value: aov.className || '', onChange: function(v){ setAttrOverride(key, { className: v }); } })
                    ),
                    renderVisibilityControls(key, aov),
                    el(SelectControl, {
                        label: 'Abrir en nueva pestana',
                        value: val.opensInNewTab ? '1' : '0',
                        options: [ {label:'No', value:'0'}, {label:'Si', value:'1'} ],
                        onChange: function(v){ setField(key, Object.assign({}, val, { opensInNewTab: v === '1' ? 1 : 0 })); }
                    }),
                    el(TextControl, { label: 'rel (opcional)', value: val.rel || '', onChange: function(v){ setField(key, Object.assign({}, val, { rel: v })); } }),
                    el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
                );
            }

            var aov2 = attrsMeta[key] && typeof attrsMeta[key] === 'object' ? attrsMeta[key] : {};
            return el(FieldAccordion, { fieldKey:key, title: label, key: (key + ':' + String(templateId || 0)) },
                el(TextControl, { label: label, value: fieldsMeta[key] || '', onChange: function(v){ setField(key, v); } }),
                el('div', { style:{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'10px' } },
                    el(SelectControl, {
                        label: 'Ancho/alineacion',
                        value: (aov2.align !== undefined ? aov2.align : ''),
                        options: [
                            { label: 'Heredar (plantilla)', value: '' },
                            { label: 'Ancho completo (full)', value: 'full' },
                            { label: 'Ancho amplio (wide)', value: 'wide' }
                        ],
                        onChange: function(v){ setAttrOverride(key, { align: v }); }
                    }),
                    el(TextControl, { label: 'Clases extra', value: aov2.className || '', onChange: function(v){ setAttrOverride(key, { className: v }); } })
                ),
                renderVisibilityControls(key, aov2),
                el('div', { className:'egm-editor-template-field__key' }, 'Key: ', el('code', null, key))
            );
        }

        var fieldNodes = schemaFields.map(renderFieldNode);

        function pickContainerTag(className){
            className = String(className || '').trim();
            if (!className) return '';
            var tokens = className.split(/\s+/).filter(Boolean);
            var stop = {
                'egm-field':1,'alignfull':1,'alignwide':1,'wp-block-group':1,'wp-block-columns':1,'wp-block-column':1
            };
            for (var i=0;i<tokens.length;i++){
                var t = tokens[i];
                if (stop[t]) continue;
                if (t.indexOf('wp-') === 0) continue;
                if (t.indexOf('is-layout-') === 0) continue;
                if (t.indexOf('egm-key-') === 0) continue;
                if (t.indexOf('egm-') === 0) continue;
                if (/^[a-z0-9_-]{3,}$/i.test(t)) return t;
            }
            return '';
        }

        function renderNestedFields(){
            try {
                var blocksByPath = (schema && schema.blocksByPath) ? schema.blocksByPath : {};
                var hasPaths = schemaFields.some(function(f){ return Array.isArray(f.path) && f.path.length; });
                if (!hasPaths) {
                    return el('div', { className:'egm-editor-template-fields' }, fieldNodes);
                }

                var fieldsByParent = {};
                schemaFields.forEach(function(f){
                    var p = Array.isArray(f.path) ? f.path : [];
                    var parentKey = p.length > 1 ? p.slice(0, p.length - 1).join('.') : '';
                    if (!fieldsByParent[parentKey]) fieldsByParent[parentKey] = [];
                    fieldsByParent[parentKey].push(f);
                });

                var containerSet = { '': true };
                Object.keys(fieldsByParent).forEach(function(k){
                    if (!k) return;
                    containerSet[k] = true;
                    var parts = k.split('.');
                    while (parts.length > 1) {
                        parts.pop();
                        containerSet[parts.join('.')] = true;
                    }
                });

                var children = {};
                Object.keys(containerSet).forEach(function(k){
                    if (!k) return;
                    var parts = k.split('.');
                    parts.pop();
                    var parent = parts.join('.');
                    if (!children[parent]) children[parent] = [];
                    children[parent].push(k);
                });

                function sortByLastIndex(a,b){
                    var aa = a.split('.');
                    var bb = b.split('.');
                    var ai = parseInt(aa[aa.length-1],10) || 0;
                    var bi = parseInt(bb[bb.length-1],10) || 0;
                    return ai - bi;
                }
                Object.keys(children).forEach(function(k){ children[k].sort(sortByLastIndex); });

                function containerLabel(pathKey){
                    var info = blocksByPath && blocksByPath[pathKey] ? blocksByPath[pathKey] : null;
                    var label = info && info.label ? String(info.label) : '';
                    var bn = info && info.blockName ? String(info.blockName) : '';
                    if (!label && bn) {
                        var parts = bn.split('/');
                        label = parts[parts.length-1] || 'Bloque';
                    }
                    if (!label) label = 'Bloque';
                    var low = label.toLowerCase();
                    if (low === 'group') label = 'Grupo';
                    if (low === 'columns') label = 'Columnas';
                    if (low === 'column') label = 'Columna';
                    if (low === 'cover') label = 'Fondo';
                    return label;
                }

                function containerTag(pathKey){
                    var info = blocksByPath && blocksByPath[pathKey] ? blocksByPath[pathKey] : null;
                    return pickContainerTag(info && info.className ? info.className : '');
                }

                function renderContainer(pathKey, depth){
                    var storageKey = uiPrefix + 'node_collapsed:' + String(pathKey || 'root');
                    var open = !lsGetBool(storageKey);
                    if (depth >= 2 && lsGet(storageKey) === null) {
                        open = false;
                    }
                    var tag = containerTag(pathKey);

                    var content = [];
                    var fieldsHere = fieldsByParent[pathKey] || [];
                    fieldsHere.sort(function(a,b){
                        var pa = Array.isArray(a.path) ? a.path : [];
                        var pb = Array.isArray(b.path) ? b.path : [];
                        var ai = pa.length ? (parseInt(pa[pa.length-1],10) || 0) : 0;
                        var bi = pb.length ? (parseInt(pb[pb.length-1],10) || 0) : 0;
                        return ai - bi;
                    });
                    fieldsHere.forEach(function(f){ content.push(renderFieldNode(f)); });
                    var kids = children[pathKey] || [];
                    kids.forEach(function(k){ content.push(renderContainer(k, depth + 1)); });
                    if (!content.length) return null;

                    return el('details', {
                            key: 'c:' + String(pathKey || 'root'),
                            open: open,
                            onToggle: function(e){
                                try { lsSetBool(storageKey, !(e && e.target && e.target.open)); } catch (_) {}
                            },
                            style: {
                                marginLeft: depth ? (depth * 12) : 0,
                                border: '1px solid #eef2f7',
                                borderRadius: 14,
                                background: '#fff',
                                padding: '8px 10px',
                                marginTop: 8
                            }
                        },
                        el('summary', { style:{ cursor:'pointer', fontWeight: 700, listStyle:'none' } },
                            el('span', null,
                                containerLabel(pathKey),
                                tag ? el('span', { style:{ marginLeft: 8, fontSize: 12, background:'#e2e8f0', color:'#0f172a', padding:'2px 8px', borderRadius: 9999 } }, tag) : null
                            )
                        ),
                        el('div', { style:{ marginTop: 10, display:'grid', gap: 10 } }, content)
                    );
                }

                var rootContent = [];
                (fieldsByParent[''] || []).forEach(function(f){ rootContent.push(renderFieldNode(f)); });
                (children[''] || []).forEach(function(k){ rootContent.push(renderContainer(k, 0)); });
                return el('div', null, rootContent);
            } catch (e) {
                try { console.error('[EGM] nested fields render failed, falling back to flat list', e); } catch(_) {}
                return el('div', { className:'egm-editor-template-fields' }, fieldNodes);
            }
        }

        var templateOptions = [{ label: 'Global (por defecto)', value: 0 }];
        templates.forEach(function(t){
            var label = t.title + (t.isGlobal ? ' (Global)' : '');
            templateOptions.push({ label: label, value: t.id });
        });

        var currentEditUrl = '';
        templates.forEach(function(t){
            if (parseInt(templateId,10) === parseInt(t.id,10)) currentEditUrl = t.editUrl || '';
        });

	        return el('div', { className:'egm-editor-template-canvas' },
	            el('div', { className:'egm-editor-template-canvas__top' },
                el('div', null,
                    el('div', { className:'egm-editor-template-canvas__title' }, 'Plantilla (preview + campos)'),
                    el('div', { className:'egm-editor-template-canvas__hint' }, 'Esto se guarda como meta del producto al presionar Actualizar/Publicar.')
                ),
                el('div', null,
                    hydrating ? el(Spinner, null) : null,
                    loadingTemplates ? el(Spinner, null) : null,
                    loadingSchema ? el(Spinner, null) : null,
                    loadingPreview ? el(Spinner, null) : null,
                    el(Button, { variant:'secondary', onClick: function(){
                        var next = !canvasCollapsed;
                        setCanvasCollapsed(next);
                        lsSetBool(uiPrefix + 'canvas_collapsed', next);
                    } }, canvasCollapsed ? 'Expandir' : 'Contraer'),
                    el(Button, { variant:'secondary', onClick: function(){
                        previewRequestKindRef.current = 'manual';
                        requestPreview();
                    } }, 'Refrescar')
                )
            ),
            el('div', { style: { marginBottom: 10, display:'grid', gridTemplateColumns:'1fr auto', gap:'10px', alignItems:'end' } },
                el(SelectControl, { label: 'Plantilla', value: editedTemplateId || 0, options: templateOptions, onChange: setTemplateChoice }),
                currentEditUrl ? el(Button, { variant:'secondary', href: currentEditUrl, target:'_blank' }, 'Editar plantilla') : null
            ),
	            el('div', { className: canvasCollapsed ? 'egm-editor-template-canvas__collapsed' : '' },
	            el('details', { style: { marginBottom: 12, padding: 12, border: '1px solid #e5e7eb', borderRadius: 12, background: '#fff' } },
	                el('summary', { style: { cursor: 'pointer', fontWeight: 700 } }, 'Ayuda rapida: contenido \u2192 plantilla \u2192 resultado'),
	                el('div', { style: { marginTop: 10, color: '#334155' } },
	                    el('ol', null,
	                        el('li', null, 'Contenido: completa datos del producto (campos).'),
	                        el('li', null, 'Plantilla: define el layout una vez (bloques).'),
	                        el('li', null, 'Resultado: el front-end usa tu plantilla + los datos.')
	                    ),
	                    el('p', null, 'Ejemplo shortcode galeria:'),
	                    el('p', null, el('code', null, '[estandar_gallery ids="1,2,3" size="medium"]'))
	                )
	            ),
	            renderSectionsControls(),
	            el('details', { open: true, style: { marginBottom: 12, padding: 0, border: '1px solid #eef2f7', borderRadius: 14, background: '#fff' } },
	                el('summary', { style: { cursor:'pointer', fontWeight: 700, padding: '10px 12px' } }, 'Preview'),
	                el('div', { className:'egm-editor-template-preview', style:{ border: '0', borderTop: '1px solid #eef2f7', borderRadius: 0 } },
	                    previewHtml ? el('div', { dangerouslySetInnerHTML: { __html: previewHtml } }) : el('div', null, 'Cargando preview...')
	                )
	            ),
	            el('details', { open: true, style: { padding: 0, border: '1px solid #eef2f7', borderRadius: 14, background: '#fff' } },
	                el('summary', { style: { cursor:'pointer', fontWeight: 700, padding: '10px 12px' } }, 'Campos (', String(schemaFields.length || 0), ')'),
	                el('div', { style:{ padding: '10px 12px', borderTop: '1px solid #eef2f7' } },
	                    el(TextControl, { label: 'Buscar campo', value: fieldQuery || '', onChange: function(v){ setFieldQuery(v || ''); } }),
	                    renderNestedFields()
	                )
	            )
	            )
	        );
    }

    function mountInto(doc){
        if (!doc) return false;
        if (doc.getElementById('egm-editor-template-canvas-root')) return true;

        var root = doc.querySelector('.block-editor-writing-flow') || doc.querySelector('.edit-post-visual-editor__content-area');
        if (!root) return false;

        var mount = doc.createElement('div');
        mount.id = 'egm-editor-template-canvas-root';
        root.insertBefore(mount, root.firstChild);

        try {
            if (hasCreateRoot) {
                wp.element.createRoot(mount).render(el(CanvasApp, null));
            } else if (wp.element.render) {
                wp.element.render(el(CanvasApp, null), mount);
            } else {
                // Hard fallback: no render API.
                mount.textContent = 'Estandar: no se pudo montar el UI de plantilla (render API no disponible).';
            }
        } catch (e) {
            // Avoid breaking the editor if something goes wrong.
            try { console.error('[EGM] Canvas mount error', e); } catch(_) {}
            return false;
        }

        return true;
    }

    function tryMount(){
        // Parent document.
        if (mountInto(document)) return true;

        // Iframe editor canvas.
        var frame = document.querySelector('iframe[name=\"editor-canvas\"]');
        if (frame && frame.contentDocument) {
            if (mountInto(frame.contentDocument)) return true;
        }

        return false;
    }

    wp.domReady(function(){
        // Try immediately, then retry a few times (iframe loads async).
        var attempts = 0;
        var t = setInterval(function(){
            attempts++;
            if (tryMount() || attempts > 25) {
                clearInterval(t);
            }
        }, 200);
    });
})(window.wp);
JS;
    }

	    public static function render_product_meta_box( $post ) {
	        wp_nonce_field( 'estandar_producto_meta_save', 'estandar_producto_meta_nonce' );

	        $meta = self::get_product_meta( $post->ID );
	        $section_overrides = self::get_product_section_overrides( (int) $post->ID );
	        $sections_enabled  = ! empty( $section_overrides );
	        ?>
        <style>
            .egm-product-fields { display:grid; gap:22px; }
            .egm-product-section { border:1px solid #dcdcde; border-radius:12px; padding:18px; background:#fff; }
            .egm-product-section h3 { margin:0 0 14px; }
            .egm-product-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
            .egm-product-grid-3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
            .egm-product-field label { display:block; font-weight:600; margin:0 0 6px; }
            .egm-product-field input[type="text"],
            .egm-product-field input[type="url"],
            .egm-product-field textarea { width:100%; }
            .egm-product-repeat { border-top:1px solid #eee; padding-top:14px; margin-top:14px; }
            .egm-product-image-preview img { max-width:120px; height:auto; border-radius:8px; display:block; margin:0 0 8px; }
        </style>
	        <div class="egm-product-fields">
            <section class="egm-product-section">
                <h3>Hero y llamados a la accion</h3>
                <div class="egm-product-grid">
                    <?php self::render_product_text_field( 'hero_badge', 'Etiqueta superior', $meta ); ?>
                    <?php self::render_product_text_field( 'subtitle', 'Subtitulo', $meta ); ?>
                    <?php self::render_product_textarea_field( 'short_description', 'Descripcion corta', $meta, 5 ); ?>
                    <?php self::render_product_textarea_field( 'intro_note', 'Nota bajo descripcion', $meta, 5 ); ?>
                    <?php self::render_product_text_field( 'primary_cta_text', 'Texto boton principal', $meta ); ?>
                    <?php self::render_product_url_field( 'primary_cta_url', 'URL boton principal', $meta ); ?>
                    <?php self::render_product_text_field( 'download_text', 'Texto descarga', $meta ); ?>
                    <?php self::render_product_url_field( 'download_url', 'URL descarga', $meta ); ?>
                    <?php self::render_product_text_field( 'expert_cta_text', 'Texto experto/contacto', $meta ); ?>
                    <?php self::render_product_url_field( 'expert_cta_url', 'URL experto/contacto', $meta ); ?>
                </div>
            </section>

            <section class="egm-product-section">
                <h3>Modos de operacion</h3>
                <div class="egm-product-grid">
                    <?php self::render_product_textarea_field( 'mode_slam', 'Modo SLAM', $meta, 5 ); ?>
                    <?php self::render_product_textarea_field( 'mode_rtk', 'Modo RTK', $meta, 5 ); ?>
                </div>
            </section>

            <section class="egm-product-section">
                <h3>Features principales</h3>
                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                    <div class="egm-product-repeat egm-product-grid-3">
                        <?php self::render_product_array_text_field( 'features', $i, 'title', 'Titulo feature ' . ( $i + 1 ), $meta ); ?>
                        <?php self::render_product_array_textarea_field( 'features', $i, 'desc', 'Descripcion', $meta, 4 ); ?>
                        <?php self::render_product_array_image_field( 'features', $i, 'image_id', 'Imagen', $meta ); ?>
                    </div>
                <?php endfor; ?>
            </section>

            <section class="egm-product-section">
                <h3>Metricas destacadas</h3>
                <?php for ( $i = 0; $i < 8; $i++ ) : ?>
                    <div class="egm-product-repeat egm-product-grid-3">
                        <?php self::render_product_array_text_field( 'metrics', $i, 'value', 'Valor ' . ( $i + 1 ), $meta ); ?>
                        <?php self::render_product_array_text_field( 'metrics', $i, 'label', 'Etiqueta', $meta ); ?>
                        <?php self::render_product_array_text_field( 'metrics', $i, 'note', 'Nota', $meta ); ?>
                    </div>
                <?php endfor; ?>
            </section>

            <section class="egm-product-section">
                <h3>Product showcase</h3>
                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                    <div class="egm-product-repeat egm-product-grid">
                        <?php self::render_product_array_text_field( 'showcase', $i, 'title', 'Titulo imagen ' . ( $i + 1 ), $meta ); ?>
                        <?php self::render_product_array_image_field( 'showcase', $i, 'image_id', 'Imagen', $meta ); ?>
                    </div>
                <?php endfor; ?>
            </section>

            <section class="egm-product-section">
                <h3>Especificaciones tecnicas</h3>
                <p class="description">Usa el campo Grupo para separar bloques como GNSS Performance, SLAM Performance, Communications, Physical, etc.</p>
                <?php for ( $i = 0; $i < 20; $i++ ) : ?>
                    <div class="egm-product-repeat egm-product-grid-3">
                        <?php self::render_product_array_text_field( 'specs', $i, 'group', 'Grupo', $meta ); ?>
                        <?php self::render_product_array_text_field( 'specs', $i, 'label', 'Dato', $meta ); ?>
                        <?php self::render_product_array_text_field( 'specs', $i, 'value', 'Valor', $meta ); ?>
                    </div>
                <?php endfor; ?>
            </section>

	            <section class="egm-product-section">
	                <h3>Aplicaciones</h3>
	                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
	                    <div class="egm-product-repeat egm-product-grid">
	                        <?php self::render_product_array_text_field( 'applications', $i, 'title', 'Aplicacion ' . ( $i + 1 ), $meta ); ?>
	                        <?php self::render_product_array_image_field( 'applications', $i, 'image_id', 'Imagen', $meta ); ?>
	                    </div>
	                <?php endfor; ?>
	            </section>

	            <section class="egm-product-section">
	                <h3>Secciones y navegación interna</h3>
	                <p class="description">Opcional: personaliza para este producto qué secciones aparecen y cómo se llaman en el menú interno. Si no activas esto, se usan los ajustes globales de “Plantilla Productos”.</p>
	                <p class="egm-product-field">
	                    <label>
	                        <input type="checkbox" name="estandar_cpt_sections_enable" value="1" <?php checked( $sections_enabled ); ?> />
	                        Personalizar secciones para este producto
	                    </label>
	                </p>
	                <div class="egm-product-grid-3">
	                    <div class="egm-product-field"><label>Sección</label></div>
	                    <div class="egm-product-field"><label>Título (H3 / menú)</label></div>
	                    <div class="egm-product-field"><label>Anchor (#id)</label></div>
	                </div>
	                <?php
	                $section_defs = [
	                    'features' => [ 'label' => 'Características', 'default_anchor' => 'caracteristicas' ],
	                    'modes' => [ 'label' => 'Modos', 'default_anchor' => 'modos' ],
	                    'metrics' => [ 'label' => 'Métricas', 'default_anchor' => 'metricas' ],
	                    'showcase' => [ 'label' => 'Showcase', 'default_anchor' => 'showcase' ],
	                    'specs' => [ 'label' => 'Especificaciones', 'default_anchor' => 'especificaciones' ],
	                    'applications' => [ 'label' => 'Aplicaciones', 'default_anchor' => 'aplicaciones' ],
	                ];
	                foreach ( $section_defs as $id => $def ) :
	                    $row = isset( $section_overrides[ $id ] ) && is_array( $section_overrides[ $id ] ) ? $section_overrides[ $id ] : [];
	                    $active = isset( $row['active'] ) ? (bool) absint( $row['active'] ) : true;
	                    $title  = isset( $row['title'] ) ? (string) $row['title'] : '';
	                    $anchor = isset( $row['anchor'] ) ? (string) $row['anchor'] : '';
	                    ?>
	                    <div class="egm-product-repeat egm-product-grid-3">
	                        <div class="egm-product-field">
	                            <label style="display:flex;gap:8px;align-items:center;margin:0;">
	                                <input type="checkbox" name="estandar_cpt_sections[<?php echo esc_attr( $id ); ?>][active]" value="1" <?php checked( $active ); ?> />
	                                <?php echo esc_html( (string) $def['label'] ); ?>
	                            </label>
	                        </div>
	                        <div class="egm-product-field">
	                            <input type="text" name="estandar_cpt_sections[<?php echo esc_attr( $id ); ?>][title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( self::get_body_section_title( $id ) ); ?>" />
	                        </div>
	                        <div class="egm-product-field">
	                            <input type="text" name="estandar_cpt_sections[<?php echo esc_attr( $id ); ?>][anchor]" value="<?php echo esc_attr( $anchor ); ?>" placeholder="<?php echo esc_attr( (string) $def['default_anchor'] ); ?>" />
	                        </div>
	                    </div>
	                <?php endforeach; ?>
	            </section>
	        </div>
	        <?php
	    }

    private static function get_product_meta( int $post_id ): array {
        $legacy = get_post_meta( $post_id, '_estandar_producto_layout', true );
        $legacy = is_array( $legacy ) ? $legacy : [];

        // Canonical: template fields meta overrides legacy when keys match.
        $fields = get_post_meta( $post_id, self::PRODUCT_TEMPLATE_FIELDS_META_KEY, true );
        $fields = is_array( $fields ) ? $fields : [];

        // Overlay only scalar keys that are relevant to legacy blocks, leaving repeater arrays intact.
        foreach ( $fields as $k => $v ) {
            if ( is_scalar( $v ) || null === $v ) {
                $legacy[ $k ] = $v;
            }
        }

        return $legacy;
    }

    private static function render_product_text_field( string $key, string $label, array $meta ) {
        $value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
        echo '<p class="egm-product-field"><label>' . esc_html( $label ) . '</label><input type="text" name="estandar_producto[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" /></p>';
    }

    private static function render_product_url_field( string $key, string $label, array $meta ) {
        $value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
        echo '<p class="egm-product-field"><label>' . esc_html( $label ) . '</label><input type="url" name="estandar_producto[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" /></p>';
    }

    private static function render_product_textarea_field( string $key, string $label, array $meta, int $rows = 3 ) {
        $value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
        echo '<p class="egm-product-field"><label>' . esc_html( $label ) . '</label><textarea rows="' . esc_attr( (string) $rows ) . '" name="estandar_producto[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea></p>';
    }

    private static function render_product_array_text_field( string $group, int $index, string $key, string $label, array $meta ) {
        $value = isset( $meta[ $group ][ $index ][ $key ] ) ? (string) $meta[ $group ][ $index ][ $key ] : '';
        echo '<p class="egm-product-field"><label>' . esc_html( $label ) . '</label><input type="text" name="estandar_producto[' . esc_attr( $group ) . '][' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" /></p>';
    }

    private static function render_product_array_textarea_field( string $group, int $index, string $key, string $label, array $meta, int $rows = 3 ) {
        $value = isset( $meta[ $group ][ $index ][ $key ] ) ? (string) $meta[ $group ][ $index ][ $key ] : '';
        echo '<p class="egm-product-field"><label>' . esc_html( $label ) . '</label><textarea rows="' . esc_attr( (string) $rows ) . '" name="estandar_producto[' . esc_attr( $group ) . '][' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea></p>';
    }

    private static function render_product_array_image_field( string $group, int $index, string $key, string $label, array $meta ) {
        $value = isset( $meta[ $group ][ $index ][ $key ] ) ? absint( $meta[ $group ][ $index ][ $key ] ) : 0;
        $url   = $value ? wp_get_attachment_image_url( $value, 'thumbnail' ) : '';
        echo '<div class="egm-product-field estandar-category-image-field"><label>' . esc_html( $label ) . '</label>';
        echo '<input type="hidden" class="estandar-category-image-id" name="estandar_producto[' . esc_attr( $group ) . '][' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
        echo '<div class="estandar-category-image-preview egm-product-image-preview">';
        if ( $url ) {
            echo '<img src="' . esc_url( $url ) . '" alt="" />';
        }
        echo '</div>';
        echo '<button type="button" class="button estandar-category-image-select">Asignar imagen</button> ';
        echo '<button type="button" class="button estandar-category-image-remove" style="' . ( $url ? '' : 'display:none;' ) . '">Quitar imagen</button>';
        echo '</div>';
    }

	    public static function save_product_meta_box( int $post_id ) {
        if ( ! isset( $_POST['estandar_producto_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['estandar_producto_meta_nonce'] ), 'estandar_producto_meta_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $raw  = isset( $_POST['estandar_producto'] ) && is_array( $_POST['estandar_producto'] ) ? wp_unslash( $_POST['estandar_producto'] ) : [];
        $meta = [
            'hero_badge'       => sanitize_text_field( $raw['hero_badge'] ?? '' ),
            'subtitle'         => sanitize_text_field( $raw['subtitle'] ?? '' ),
            'short_description'=> sanitize_textarea_field( $raw['short_description'] ?? '' ),
            'intro_note'       => sanitize_textarea_field( $raw['intro_note'] ?? '' ),
            'primary_cta_text' => sanitize_text_field( $raw['primary_cta_text'] ?? '' ),
            'primary_cta_url'  => esc_url_raw( $raw['primary_cta_url'] ?? '' ),
            'download_text'    => sanitize_text_field( $raw['download_text'] ?? '' ),
            'download_url'     => esc_url_raw( $raw['download_url'] ?? '' ),
            'expert_cta_text'  => sanitize_text_field( $raw['expert_cta_text'] ?? '' ),
            'expert_cta_url'   => esc_url_raw( $raw['expert_cta_url'] ?? '' ),
            'mode_slam'        => sanitize_textarea_field( $raw['mode_slam'] ?? '' ),
            'mode_rtk'         => sanitize_textarea_field( $raw['mode_rtk'] ?? '' ),
            'features'         => self::sanitize_product_rows( $raw['features'] ?? [], [ 'title' => 'text', 'desc' => 'textarea', 'image_id' => 'image' ] ),
            'metrics'          => self::sanitize_product_rows( $raw['metrics'] ?? [], [ 'value' => 'text', 'label' => 'text', 'note' => 'text' ] ),
            'showcase'         => self::sanitize_product_rows( $raw['showcase'] ?? [], [ 'title' => 'text', 'image_id' => 'image' ] ),
            'specs'            => self::sanitize_product_rows( $raw['specs'] ?? [], [ 'group' => 'text', 'label' => 'text', 'value' => 'text' ] ),
            'applications'     => self::sanitize_product_rows( $raw['applications'] ?? [], [ 'title' => 'text', 'image_id' => 'image' ] ),
        ];

	        update_post_meta( $post_id, '_estandar_producto_layout', $meta );

	        // Optional per-product overrides for body sections + internal nav.
	        $enable_sections = isset( $_POST['estandar_cpt_sections_enable'] ) && '1' === (string) wp_unslash( $_POST['estandar_cpt_sections_enable'] );
	        if ( ! $enable_sections ) {
	            delete_post_meta( $post_id, self::PRODUCT_SECTIONS_META_KEY );
	            return;
	        }

	        $raw_sections = isset( $_POST['estandar_cpt_sections'] ) && is_array( $_POST['estandar_cpt_sections'] ) ? wp_unslash( $_POST['estandar_cpt_sections'] ) : [];
	        $clean_sections = [];
	        foreach ( $raw_sections as $id => $row ) {
	            $id = sanitize_key( (string) $id );
	            if ( '' === $id || ! is_array( $row ) ) {
	                continue;
	            }
	            $active = isset( $row['active'] ) ? 1 : 0;
	            $title  = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
	            $anchor = isset( $row['anchor'] ) ? sanitize_key( (string) $row['anchor'] ) : '';
	            $clean_sections[ $id ] = [
	                'active' => $active,
	                'title'  => $title,
	                'anchor' => $anchor,
	            ];
	        }

	        update_post_meta( $post_id, self::PRODUCT_SECTIONS_META_KEY, $clean_sections );
	    }

    private static function sanitize_product_rows( $rows, array $schema ): array {
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $clean = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $item = [];

            foreach ( $schema as $key => $type ) {
                $value = $row[ $key ] ?? '';

                if ( 'image' === $type ) {
                    $item[ $key ] = absint( $value );
                } elseif ( 'textarea' === $type ) {
                    $item[ $key ] = sanitize_textarea_field( $value );
                } else {
                    $item[ $key ] = sanitize_text_field( $value );
                }
            }

            if ( array_filter( $item ) ) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    public static function render_admin() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'No autorizado.' );
        }

        $tab = self::get_admin_active_tab();
        $use_core_ui = function_exists( 'primer_is_active' ) && primer_is_active();

        if ( function_exists( 'estandar_render_layout_start' ) ) {
            estandar_render_layout_start();
        } else {
            echo '<div class="wrap estandar-admin">';
        }

        if ( function_exists( 'estandar_render_header' ) ) {
            estandar_render_header(
                [
                    'title'    => 'CPT Manager',
                    'subtitle' => 'Gestión de CPTs y plantillas del sistema.',
                ]
            );
        } else {
            echo '<h1>CPT Manager</h1>';
            echo '<p>Crea CPTs simples tipo paginas (Gutenberg + jerarquia), con categorias y etiquetas nativas, y aplica plantillas automaticas como fallback si el theme no provee templates.</p>';
        }
        self::render_admin_tabs_nav( $tab );

        if ( $use_core_ui ) {
            echo '<div class="estandar-surface">';
        }

        if ( 'product-template' === $tab ) {
            self::render_product_template_admin_content();
            if ( $use_core_ui ) {
                echo '</div>';
            }
            if ( function_exists( 'estandar_render_layout_end' ) ) {
                estandar_render_layout_end();
            } else {
                echo '</div>';
            }
            return;
        }

        if ( 'plantillas' === $tab ) {
            self::render_templates_links_content();
            if ( $use_core_ui ) {
                echo '</div>';
            }
            if ( function_exists( 'estandar_render_layout_end' ) ) {
                estandar_render_layout_end();
            } else {
                echo '</div>';
            }
            return;
        }

        if ( 'como-usar' === $tab ) {
            self::render_how_to_use_content();
            if ( $use_core_ui ) {
                echo '</div>';
            }
            if ( function_exists( 'estandar_render_layout_end' ) ) {
                estandar_render_layout_end();
            } else {
                echo '</div>';
            }
            return;
        }

        $defs             = self::get_defs();
        $edit             = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
        $current          = $edit && isset( $defs[ $edit ] ) ? self::normalize_def( $edit, $defs[ $edit ] ) : null;
        $nonce_save       = wp_create_nonce( 'estandar_cpt_save' );
        $nonce_delete     = wp_create_nonce( 'estandar_cpt_delete' );
        $supports_allowed = self::supports_allowed();
        $taxes_allowed    = self::taxonomies_allowed();

        echo '<h2>CPTs existentes</h2>';

        if ( empty( $defs ) ) {
            echo '<p><em>No hay CPTs creados aun.</em></p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Key</th><th>Singular</th><th>Plural</th><th>Slug</th><th>Archive</th><th>Taxonomias</th><th>Acciones</th>';
            echo '</tr></thead><tbody>';

            foreach ( $defs as $key => $def ) {
                $def      = self::normalize_def( $key, $def );
                $key_e    = esc_html( $key );
                $sing     = esc_html( $def['singular'] );
                $plur     = esc_html( $def['plural'] );
                $slug     = esc_html( $def['slug'] );
                $arch     = $def['has_archive'] ? 'Si' : 'No';
                $taxes    = array_map( 'esc_html', $def['taxonomies'] );
                $taxes_ui = empty( $taxes ) ? '<em>Ninguna</em>' : implode( ', ', $taxes );
                $edit_url = esc_url( self::admin_page_url( [ 'edit' => $key ] ) );
                $del_url  = esc_url(
                    admin_url(
                        'admin-post.php?action=estandar_cpt_delete&key=' . rawurlencode( $key ) . '&_wpnonce=' . $nonce_delete
                    )
                );

                echo "<tr>
                        <td><code>{$key_e}</code></td>
                        <td>{$sing}</td>
                        <td>{$plur}</td>
                        <td><code>{$slug}</code></td>
                        <td>{$arch}</td>
                        <td>{$taxes_ui}</td>
                        <td>
                            <a class='button button-small' href='{$edit_url}'>Editar</a>
                            <a class='button button-small button-link-delete' href='{$del_url}' onclick='return confirm(\"Eliminar CPT {$key_e}? Sus contenidos no se borraran.\")'>Eliminar</a>
                        </td>
                    </tr>";
            }

            echo '</tbody></table>';
        }

        echo '<hr />';
        echo $current ? '<h2>Editar CPT</h2>' : '<h2>Crear nuevo CPT</h2>';

        $val_key        = $edit ? $edit : '';
        $val_singular   = $current['singular'] ?? '';
        $val_plural     = $current['plural'] ?? '';
        $val_slug       = $current['slug'] ?? '';
        $val_archive    = ! empty( $current['has_archive'] ) ? '1' : '';
        $val_supports   = $current['supports'] ?? self::default_supports();
        $val_taxonomies = $current['taxonomies'] ?? self::default_taxonomies();

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="estandar_cpt_save" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce_save ) . '" />';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="cpt_key">Key (post_type)</label></th><td>';
        echo '<input name="cpt_key" id="cpt_key" type="text" class="regular-text" value="' . esc_attr( $val_key ) . '" ' . ( $edit ? 'readonly' : '' ) . ' />';
        echo '<p class="description">Solo minusculas, numeros y guiones bajos. Maximo 20 caracteres. Ej: <code>proyecto</code>.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="singular">Singular</label></th><td>';
        echo '<input name="singular" id="singular" type="text" class="regular-text" value="' . esc_attr( $val_singular ) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="plural">Plural</label></th><td>';
        echo '<input name="plural" id="plural" type="text" class="regular-text" value="' . esc_attr( $val_plural ) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="slug">Slug (URL)</label></th><td>';
        echo '<input name="slug" id="slug" type="text" class="regular-text" value="' . esc_attr( $val_slug ) . '" />';
        echo '<p class="description">Ej: <code>proyectos</code>. No uses tildes ni caracteres especiales.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Archive</th><td>';
        echo '<label><input type="checkbox" name="has_archive" value="1" ' . checked( $val_archive, '1', false ) . ' /> Habilitar archivo (archive)</label>';
        echo '<p class="description">Si lo activas, tendras una URL tipo <code>tusitio.cl/slug/</code> listando items.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Soportes</th><td>';
        foreach ( $supports_allowed as $support ) {
            $is_required = in_array( $support, self::required_supports(), true );
            $checked     = $is_required || in_array( $support, $val_supports, true ) ? 'checked' : '';
            echo '<label style="display:inline-block; margin:0 18px 8px 0;">';
            if ( $is_required ) {
                echo '<input type="hidden" name="supports[]" value="' . esc_attr( $support ) . '" />';
            }
            echo '<input type="checkbox" name="supports[]" value="' . esc_attr( $support ) . '" ' . $checked . ( $is_required ? ' disabled' : '' ) . ' /> ' . esc_html( $support );
            echo $is_required ? ' <span class="description">(obligatorio)</span>' : '';
            echo '</label>';
        }
        echo '<p class="description">La imagen destacada usa el sistema nativo de WordPress. Recomendado minimo: title, editor, page-attributes.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Taxonomias</th><td>';
        foreach ( $taxes_allowed as $taxonomy ) {
            $checked = in_array( $taxonomy, $val_taxonomies, true ) ? 'checked' : '';
            $label   = 'category' === $taxonomy ? 'Categorias' : 'Etiquetas';
            echo '<label style="display:inline-block; margin:0 18px 8px 0;">';
            echo '<input type="checkbox" name="taxonomies[]" value="' . esc_attr( $taxonomy ) . '" ' . $checked . ' /> ' . esc_html( $label );
            echo '</label>';
        }
        echo '<p class="description">Por defecto los nuevos CPT usan las taxonomias nativas de WordPress: categorias y etiquetas.</p>';
        echo '</td></tr>';

        echo '</table>';
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . ( $current ? 'Guardar cambios' : 'Crear CPT' ) . '</button>';

        if ( $current ) {
            echo ' <a class="button" href="' . esc_url( self::admin_page_url() ) . '">Cancelar</a>';
        }

        echo '</p>';
        echo '</form>';

        if ( $use_core_ui ) {
            echo '</div>';
        }

        if ( function_exists( 'estandar_render_layout_end' ) ) {
            estandar_render_layout_end();
        } else {
            echo '</div>';
        }
    }

    public static function handle_save() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'No autorizado.' );
        }

        check_admin_referer( 'estandar_cpt_save' );

        $defs = self::get_defs();
        $key  = isset( $_POST['cpt_key'] ) ? sanitize_key( wp_unslash( $_POST['cpt_key'] ) ) : '';

        if ( empty( $key ) ) {
            self::redirect_with_notice( 'missing_key' );
        }

        if ( strlen( $key ) > 20 ) {
            self::redirect_with_notice( 'invalid_key_length', [ 'edit' => $key ] );
        }

        if ( self::is_reserved_post_type_key( $key, $defs ) ) {
            self::redirect_with_notice( 'reserved_key', [ 'edit' => $key ] );
        }

        $singular = isset( $_POST['singular'] ) ? sanitize_text_field( wp_unslash( $_POST['singular'] ) ) : ucfirst( $key );
        $plural   = isset( $_POST['plural'] ) ? sanitize_text_field( wp_unslash( $_POST['plural'] ) ) : ( $singular . 's' );
        $slug     = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : $key;

        if ( empty( $slug ) ) {
            self::redirect_with_notice( 'empty_slug', [ 'edit' => $key ] );
        }

        if ( self::is_reserved_slug( $slug, $key, $defs ) ) {
            self::redirect_with_notice( 'reserved_slug', [ 'edit' => $key ] );
        }

        $supports = isset( $_POST['supports'] ) ? self::ensure_required_supports( self::sanitize_supports( wp_unslash( $_POST['supports'] ) ) ) : self::default_supports();
        $taxes    = isset( $_POST['taxonomies'] ) ? self::sanitize_taxonomies( wp_unslash( $_POST['taxonomies'] ) ) : self::default_taxonomies();

        if ( empty( $supports ) ) {
            $supports = self::default_supports();
        }

        if ( empty( $taxes ) ) {
            $taxes = self::default_taxonomies();
        }

        $is_update  = isset( $defs[ $key ] );
        $defs[ $key ] = self::normalize_def(
            $key,
            [
                'singular'    => $singular,
                'plural'      => $plural,
                'slug'        => $slug,
                'has_archive' => ! empty( $_POST['has_archive'] ) ? 1 : 0,
                'supports'    => $supports,
                'taxonomies'  => $taxes,
            ]
        );

        self::save_defs( $defs );
        self::register_all_cpts();
        flush_rewrite_rules();

        self::redirect_with_notice( $is_update ? 'updated' : 'created', [ 'edit' => $key ] );
    }

    public static function handle_delete() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'No autorizado.' );
        }

        check_admin_referer( 'estandar_cpt_delete' );

        $key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';

        if ( empty( $key ) ) {
            self::redirect_with_notice( 'missing_key' );
        }

        $defs = self::get_defs();

        if ( isset( $defs[ $key ] ) ) {
            unset( $defs[ $key ] );
            self::save_defs( $defs );
            flush_rewrite_rules();
        }

        self::redirect_with_notice( 'deleted' );
    }

    public static function handle_product_template_save() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'No autorizado.' );
        }

        check_admin_referer( 'estandar_producto_template_save' );

        $defaults = self::default_product_template_settings();
        $settings = [];

        foreach ( [ 'page_bg', 'hero_bg', 'text_color', 'muted_color', 'accent_color', 'dark_color' ] as $key ) {
            $settings[ $key ] = sanitize_hex_color( wp_unslash( $_POST[ $key ] ?? $defaults[ $key ] ) ) ?: $defaults[ $key ];
        }

        $settings['content_width'] = max( 960, min( 1800, absint( $_POST['content_width'] ?? $defaults['content_width'] ) ) );

        foreach ( [ 'hero_full_width', 'show_anchor_nav', 'show_content', 'show_features', 'show_modes', 'show_metrics', 'show_showcase', 'show_specs', 'show_applications', 'show_form' ] as $key ) {
            $settings[ $key ] = ! empty( $_POST[ $key ] ) ? 1 : 0;
        }

        foreach ( [
            'hero_badge_default',
            'primary_cta_text',
            'download_cta_text',
            'nav_features',
            'nav_modes',
            'nav_metrics',
            'nav_showcase',
            'nav_specs',
            'nav_applications',
            'nav_form',
            'title_features',
            'title_modes',
            'title_metrics',
            'title_showcase',
            'title_specs',
            'title_applications',
            'title_form',
            'final_cta_title',
        ] as $key ) {
            $settings[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $defaults[ $key ] ) );
        }

        $settings['form_shortcode'] = sanitize_textarea_field( wp_unslash( $_POST['form_shortcode'] ?? '' ) );
        $settings['final_cta_text'] = sanitize_textarea_field( wp_unslash( $_POST['final_cta_text'] ?? '' ) );
        $settings['custom_css']     = wp_strip_all_tags( wp_unslash( $_POST['custom_css'] ?? '' ) );

        update_option( self::PRODUCT_TEMPLATE_OPT_KEY, array_merge( $defaults, $settings ), false );

        wp_safe_redirect( self::product_template_page_url( [ 'notice' => 'product_template_updated' ] ) );
        exit;
    }

    public static function template_router( $template ) {
        if ( is_singular() ) {
            $post_type = get_post_type();

            if ( is_string( $post_type ) && self::is_managed_cpt( $post_type ) ) {
                $theme_single = locate_template( "single-{$post_type}.php" );

                if ( ! empty( $theme_single ) ) {
                    return $template;
                }

                $plugin_specific_single = plugin_dir_path( __FILE__ ) . "templates/single-{$post_type}.php";

                if ( file_exists( $plugin_specific_single ) ) {
                    return $plugin_specific_single;
                }

                $plugin_single = plugin_dir_path( __FILE__ ) . 'templates/single-cpt.php';

                if ( file_exists( $plugin_single ) ) {
                    return $plugin_single;
                }
            }
        }

        // Categoria archive for productos: /category/slug/?post_type=producto
        // We only override when explicitly filtering productos to avoid affecting blog categories.
        if ( is_category() && 'producto' === sanitize_key( (string) get_query_var( 'post_type' ) ) ) {
            // Allow themes to provide an explicit category-producto.php override.
            $theme_cat = locate_template( 'category-producto.php' );
            if ( ! empty( $theme_cat ) ) {
                return $template;
            }

            $plugin_cat = plugin_dir_path( __FILE__ ) . 'templates/category-producto.php';
            if ( file_exists( $plugin_cat ) ) {
                return $plugin_cat;
            }
        }

        if ( is_post_type_archive() ) {
            $post_type = get_query_var( 'post_type' );

            if ( is_array( $post_type ) ) {
                $post_type = reset( $post_type );
            }

            $post_type = is_string( $post_type ) ? $post_type : '';

            if ( $post_type && self::is_managed_cpt( $post_type ) ) {
                $theme_archive = locate_template( "archive-{$post_type}.php" );

                if ( ! empty( $theme_archive ) ) {
                    return $template;
                }

                $plugin_archive = plugin_dir_path( __FILE__ ) . 'templates/archive-cpt.php';

                if ( file_exists( $plugin_archive ) ) {
                    return $plugin_archive;
                }
            }
        }

        return $template;
    }

    private static function is_managed_cpt( string $post_type ): bool {
        $defs = self::get_defs();
        return isset( $defs[ $post_type ] );
    }

    private static function is_reserved_post_type_key( string $key, array $defs ): bool {
        $reserved = [
            'post',
            'page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'wp_global_styles',
            'wp_pattern',
            'wp_theme',
        ];

        if ( in_array( $key, $reserved, true ) ) {
            return true;
        }

        $registered = get_post_types( [], 'names' );

        if ( in_array( $key, $registered, true ) && ! isset( $defs[ $key ] ) ) {
            return true;
        }

        return false;
    }

    private static function is_reserved_slug( string $slug, string $current_key, array $defs ): bool {
        foreach ( $defs as $key => $def ) {
            $existing = self::normalize_def( $key, $def );

            if ( $key !== $current_key && $existing['slug'] === $slug ) {
                return true;
            }
        }

        $public_post_types = get_post_types( [ 'public' => true ], 'objects' );

        foreach ( $public_post_types as $name => $object ) {
            if ( $name === $current_key || isset( $defs[ $name ] ) ) {
                continue;
            }

            if ( isset( $object->rewrite['slug'] ) && $object->rewrite['slug'] === $slug ) {
                return true;
            }
        }

        if ( get_page_by_path( $slug ) ) {
            return true;
        }

        return false;
    }
}

Estandar_CPT_Manager::boot();
