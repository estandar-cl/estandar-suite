<?php
if ( ! defined('ABSPATH') ) exit;

class Estandar_Forms_Shortcode {
    private const CACHE_GROUP = 'estandar_forms';
    private static int $render_instance = 0;

    public static function init() {
        add_shortcode('estandar_form', [__CLASS__, 'render']);
    }

    public static function get_form(array $atts) {
        global $wpdb;

        $table = $wpdb->prefix . 'estandar_forms';
        $slug  = ! empty($atts['slug']) ? sanitize_title((string) $atts['slug']) : '';
        $id    = ! empty($atts['id']) ? (int) $atts['id'] : 0;

        if ( $slug !== '' ) {
            $cache_key = 'form_slug_' . $slug;
            $form = wp_cache_get($cache_key, self::CACHE_GROUP);
            if ( false !== $form ) {
                return $form ?: null;
            }

            $form = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE slug = %s",
                    $slug
                )
            );

            // Solo cachear hits: un miss cacheado (0) en object cache persistente
            // (Memcached/SiteGround) deja el form invisible hasta 1 hora.
            if ( $form ) {
                wp_cache_set($cache_key, $form, self::CACHE_GROUP, HOUR_IN_SECONDS);
                wp_cache_set('form_id_' . (int) $form->id, $form, self::CACHE_GROUP, HOUR_IN_SECONDS);
            }

            return $form ?: null;
        }

        if ( $id > 0 ) {
            $cache_key = 'form_id_' . $id;
            $form = wp_cache_get($cache_key, self::CACHE_GROUP);
            if ( false !== $form ) {
                return $form ?: null;
            }

            $form = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    $id
                )
            );

            // Solo cachear hits (ver comentario en la rama slug).
            if ( $form ) {
                wp_cache_set($cache_key, $form, self::CACHE_GROUP, HOUR_IN_SECONDS);
                if ( ! empty($form->slug) ) {
                    wp_cache_set('form_slug_' . sanitize_title((string) $form->slug), $form, self::CACHE_GROUP, HOUR_IN_SECONDS);
                }
            }

            return $form ?: null;
        }

        return null;
    }

    public static function clear_form_cache($form): void {
        if ( is_object($form) ) {
            $form_id = (int) ($form->id ?? 0);
            $slug = ! empty($form->slug) ? sanitize_title((string) $form->slug) : '';
        } elseif ( is_array($form) ) {
            $form_id = (int) ($form['id'] ?? 0);
            $slug = ! empty($form['slug']) ? sanitize_title((string) $form['slug']) : '';
        } else {
            $form_id = (int) $form;
            $slug = '';
        }

        if ( $form_id > 0 ) {
            wp_cache_delete('form_id_' . $form_id, self::CACHE_GROUP);
        }

        if ( $slug !== '' ) {
            wp_cache_delete('form_slug_' . $slug, self::CACHE_GROUP);
        }
    }

    private static function form_requires_rut_script($fields_json): bool {
        $fields = function_exists('estandar_forms_decode_fields')
            ? estandar_forms_decode_fields($fields_json)
            : json_decode((string) $fields_json, true);

        if ( is_array($fields) ) {
            foreach ( $fields as $field ) {
                if ( ! is_array($field) ) {
                    continue;
                }

                if ( self::field_matches_rut($field) ) {
                    return true;
                }
            }
        }

        return is_string($fields_json) && preg_match('/\brut\b/i', $fields_json) === 1;
    }

    private static function field_matches_rut(array $field): bool {
        foreach ( ['type', 'field_type', 'input_type', 'name', 'id', 'class'] as $key ) {
            if ( isset($field[$key]) && is_scalar($field[$key]) && stripos((string) $field[$key], 'rut') !== false ) {
                return true;
            }
        }

        foreach ( $field as $value ) {
            if ( is_array($value) && self::field_matches_rut($value) ) {
                return true;
            }
        }

        return false;
    }

    private static function form_has_conditional_logic($fields_json): bool {
        $fields = function_exists('estandar_forms_decode_fields')
            ? estandar_forms_decode_fields($fields_json)
            : json_decode((string) $fields_json, true);

        if ( ! is_array($fields) ) {
            return false;
        }

        foreach ( $fields as $field ) {
            if (
                is_array($field) &&
                ! empty($field['conditional_logic']) &&
                is_array($field['conditional_logic']) &&
                ! empty($field['conditional_logic']['field'])
            ) {
                return true;
            }
        }

        return false;
    }

    public static function render( $atts ) {
	        $atts = shortcode_atts([
	            'id'   => '',
	            'slug' => '',
	            'theme' => '',
	            'project_slug' => '',
	            'project_name' => '',
	        ], $atts, 'estandar_form');

        $form = self::get_form($atts);

        if ( ! $form || empty($form->fields_json) ) {
            return '';
        }

        $form_id = (int) $form->id;
        $submit_label = ! empty($form->submit_label)
            ? $form->submit_label
            : 'Enviar';

        $after_submit_text = ! empty($form->after_submit_text)
             ? $form->after_submit_text
              : '';
	        $visual_theme = ! empty($atts['theme'])
	            ? sanitize_key((string) $atts['theme'])
	            : sanitize_key((string) ($form->visual_theme ?? ''));
	        $form_project_slug = sanitize_title(remove_accents((string) $atts['project_slug']));
	        $form_project_name = sanitize_text_field((string) $atts['project_name']);
	        $form_project_area = '';
	        $form_intent = '';
	        $routing_context = function_exists('estandar_forms_routing_context')
	            ? estandar_forms_routing_context($form->context_schema ?? '')
	            : array('mode' => 'none', 'project_slug' => '', 'project_name' => '', 'project_area' => '', 'intent' => '');
	        if (($routing_context['mode'] ?? '') === 'fixed_project' && ! empty($routing_context['project_slug'])) {
	            $form_project_slug = sanitize_title(remove_accents((string) ($routing_context['project_slug'] ?? '')));
	        }
	        if (($routing_context['mode'] ?? '') === 'fixed_project' && ! empty($routing_context['project_name'])) {
	            $form_project_name = sanitize_text_field((string) ($routing_context['project_name'] ?? ''));
	        }
	        if (($routing_context['mode'] ?? '') === 'fixed_project' && ! empty($routing_context['project_area'])) {
	            $form_project_area = sanitize_text_field((string) ($routing_context['project_area'] ?? ''));
	        }
	        if (($routing_context['mode'] ?? '') === 'fixed_project' && ! empty($routing_context['intent'])) {
	            $form_intent = sanitize_key((string) ($routing_context['intent'] ?? ''));
	        }

        // Cargar assets solo cuando existe un formulario renderizado.
        $css_file = ESTANDAR_FORMS_PATH . 'assets/css/estandar-forms.css';
        $public_js_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-forms-public.js';
        $attribution_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-attribution.js';
        $js_file  = ESTANDAR_FORMS_PATH . 'assets/js/estandar-rut.js';
        $conditional_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-conditional.js';
        $recaptcha_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-recaptcha.js';
        $repeater_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-repeater.js';
        $requires_rut_script = self::form_requires_rut_script($form->fields_json);
        $has_conditional_logic = self::form_has_conditional_logic($form->fields_json);
        $has_dependent_select  = class_exists('Estandar_Forms_Dependent_Select')
            && Estandar_Forms_Dependent_Select::has_dependent_fields((string) $form->fields_json);
        $is_incident_form = isset($form->form_type) && (string) $form->form_type === 'incident';
        self::$render_instance++;
        $form_instance_id = 'form-' . $form_id . '-' . self::$render_instance;

        wp_enqueue_style(
            'estandar-forms-public',
            ESTANDAR_FORMS_URL . 'assets/css/estandar-forms.css',
            [],
            file_exists($css_file) ? (string) filemtime($css_file) : ESTANDAR_FORMS_VERSION
        );

        // Form Engine (flujo progresivo + condiciones desde actions_json).
        // Debe encolarse ANTES de estandar-forms-public.js para que su guardia
        // de submit en fase de captura se registre primero.
        $has_engine = function_exists('estandar_forms_engine_config')
            && ! empty(estandar_forms_engine_config($form->actions_json ?? ''));
        if ( $has_engine ) {
            $engine_js_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-form-engine.js';
            wp_enqueue_script(
                'estandar-forms-engine',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-form-engine.js',
                [],
                file_exists($engine_js_file) ? (string) filemtime($engine_js_file) : ESTANDAR_FORMS_VERSION,
                true
            );
        }

        // Attribution Layer — siempre activo en formularios Estandar Forms
        wp_enqueue_script(
            'estandar-forms-attribution',
            ESTANDAR_FORMS_URL . 'assets/js/estandar-attribution.js',
            [],
            file_exists($attribution_file) ? (string) filemtime($attribution_file) : ESTANDAR_FORMS_VERSION,
            false // en cabecera para capturar UTMs antes de cualquier interacción
        );

        wp_enqueue_script(
            'estandar-forms-public',
            ESTANDAR_FORMS_URL . 'assets/js/estandar-forms-public.js',
            [],
            file_exists($public_js_file) ? (string) filemtime($public_js_file) : ESTANDAR_FORMS_VERSION,
            true
        );

        if ( $requires_rut_script ) {
            wp_enqueue_script(
                'estandar-forms-rut',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-rut.js',
                [],
                file_exists($js_file) ? (string) filemtime($js_file) : ESTANDAR_FORMS_VERSION,
                true
            );
        }

        if ( $has_conditional_logic ) {
            wp_enqueue_script(
                'estandar-forms-conditional',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-conditional.js',
                [],
                file_exists($conditional_file) ? (string) filemtime($conditional_file) : ESTANDAR_FORMS_VERSION,
                true
            );
        }

        if ( $has_dependent_select ) {
            $dep_js_file = ESTANDAR_FORMS_PATH . 'assets/js/estandar-dependent-select.js';
            wp_enqueue_script(
                'estandar-forms-dependent-select',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-dependent-select.js',
                [],
                file_exists($dep_js_file) ? (string) filemtime($dep_js_file) : ESTANDAR_FORMS_VERSION,
                true
            );
            wp_localize_script(
                'estandar-forms-dependent-select',
                'EfDependentSelect',
                [
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'nonce'        => wp_create_nonce('ef_get_streets'),
                    'loadingText'  => 'Cargando…',
                    'emptyText'    => '— Selecciona proyecto primero —',
                    'noStreetsText'=> '— Sin calles registradas —',
                ]
            );
        }

        if ( $is_incident_form ) {
            wp_enqueue_script(
                'estandar-forms-repeater',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-repeater.js',
                [],
                file_exists($repeater_file) ? (string) filemtime($repeater_file) : ESTANDAR_FORMS_VERSION,
                true
            );
        }

        if (
            defined('ESTANDAR_FORMS_RECAPTCHA_SITE_KEY') &&
            defined('ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY') &&
            ESTANDAR_FORMS_RECAPTCHA_SITE_KEY &&
            ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY
        ) {
            wp_enqueue_script(
                'google-recaptcha-v3',
                'https://www.google.com/recaptcha/api.js?render=' . rawurlencode((string) ESTANDAR_FORMS_RECAPTCHA_SITE_KEY),
                [],
                null,
                true
            );

            wp_enqueue_script(
                'estandar-forms-recaptcha',
                ESTANDAR_FORMS_URL . 'assets/js/estandar-recaptcha.js',
                ['google-recaptcha-v3'],
                file_exists($recaptcha_file) ? (string) filemtime($recaptcha_file) : ESTANDAR_FORMS_VERSION,
                true
            );

            wp_localize_script(
                'estandar-forms-recaptcha',
                'EstandarFormsRecaptcha',
                [
                    'siteKey' => (string) ESTANDAR_FORMS_RECAPTCHA_SITE_KEY,
                    'action'  => (string) apply_filters('estandar_forms_recaptcha_action', 'estandar_form_submit'),
                ]
            );
        }

        // Conversational experiences (Survey y futuras: lead_qualification, wizard, …)
        // comparten el mismo render por perfil (MS-5 · ADR 0034). Survey → survey_config;
        // otras → conversation_config. Nunca un runtime paralelo.
        $is_conversational = class_exists( 'Estandar_Conversation_Engine' )
            ? Estandar_Conversation_Engine::is_conversational( (string) ( $form->form_type ?? '' ) )
            : ( isset($form->form_type) && (string) $form->form_type === 'survey' );
        if ( $is_conversational ) {
            ob_start();
            include ESTANDAR_FORMS_PATH . 'public/survey-render.php';
            return ob_get_clean();
        }

        ob_start();
        include ESTANDAR_FORMS_PATH . 'public/form-render.php';
        return ob_get_clean();
    }
}

Estandar_Forms_Shortcode::init();
