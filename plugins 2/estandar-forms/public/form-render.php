<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Render del formulario público
 * - PHP 8.1+ safe
 * - Extensible por type
 * - name SIEMPRE = sanitize_key(field['name'])
 */

/* =====================
   VALIDACIONES INICIALES
===================== */
if ( ! isset($form_id) || ! is_numeric($form_id) || ! isset($form) || ! is_object($form) ) {
    return;
}

if ( ! $form || ! is_string($form->fields_json) ) {
    return;
}

$fields = function_exists('estandar_forms_decode_fields')
    ? estandar_forms_decode_fields($form->fields_json)
    : json_decode($form->fields_json, true);
if ( ! is_array($fields) || empty($fields) ) {
    return;
}

if ( ! isset($form_instance_id) || ! is_string($form_instance_id) || $form_instance_id === '' ) {
    static $estandar_forms_render_fallback = 0;
    $estandar_forms_render_fallback++;
    $form_instance_id = 'form-' . (int) $form_id . '-' . $estandar_forms_render_fallback;
}

$form_instance_id = sanitize_html_class($form_instance_id);

$form_country = function_exists('estandar_forms_get_form_country')
    ? estandar_forms_get_form_country($form)
    : 'CL';
$visual_theme = isset($visual_theme)
    ? sanitize_key((string) $visual_theme)
    : sanitize_key((string) ($form->visual_theme ?? ''));
$form_project_slug = isset($form_project_slug) ? sanitize_title(remove_accents((string) $form_project_slug)) : '';
$form_project_name = isset($form_project_name) ? sanitize_text_field((string) $form_project_name) : '';
$form_project_area = isset($form_project_area) ? sanitize_text_field((string) $form_project_area) : '';

// Routing context (Routing V2)
$form_routing_ctx       = function_exists('estandar_forms_routing_context')
    ? estandar_forms_routing_context($form->context_schema ?? '')
    : [];
$form_routing_mode      = (string) ($form_routing_ctx['mode']               ?? 'none');
$form_selector_projects = (array)  ($form_routing_ctx['selector_projects']  ?? []);
// In project_selector mode, project_slug is resolved client-side; hidden field starts empty.
if ( $form_routing_mode === 'project_selector' ) {
    $form_project_slug = '';
}
$form_intent = isset($form_intent) ? sanitize_key((string) $form_intent) : '';
$client_slug = function_exists('estandar_core_get_client_slug') ? estandar_core_get_client_slug() : '';
$client_name = function_exists('estandar_core_get_client_name') ? estandar_core_get_client_name() : '';
$form_classes = ['estandar-form'];
if ( $visual_theme !== '' ) {
    $form_classes[] = 'estandar-form-theme-' . sanitize_html_class($visual_theme);
}
$form_type = sanitize_key((string) ($form->form_type ?? 'lead_capture'));
$meta_lead_event = in_array($form_type, ['lead', 'lead_capture'], true);
$success_message_attr = is_string($form->success_message ?? null) && (string) $form->success_message !== ''
    ? wp_strip_all_tags((string) $form->success_message)
    : 'Gracias. Hemos recibido tu información correctamente. Pronto nos pondremos en contacto contigo.';
$phone_spec = function_exists('estandar_forms_get_phone_spec')
    ? estandar_forms_get_phone_spec($form_country)
    : [
        'placeholder' => '+56 9 1234 5678',
        'minlength'   => 8,
        'maxlength'   => 15,
        'message'     => 'Ingresa un telefono valido.',
    ];

$feedback = null;
if ( class_exists('Estandar_Forms_Handler') && method_exists('Estandar_Forms_Handler', 'get_feedback_from_request') ) {
    $feedback = Estandar_Forms_Handler::get_feedback_from_request((int) $form_id);
}

$feedback_status = '';
$feedback_messages = [];
$old_values = [];
$field_errors = [];

if ( is_array($feedback) ) {
    $feedback_status = sanitize_text_field((string) ($feedback['status'] ?? ''));
    $feedback_messages = isset($feedback['messages']) && is_array($feedback['messages']) ? $feedback['messages'] : [];
    $old_values = isset($feedback['old_input']) && is_array($feedback['old_input']) ? $feedback['old_input'] : [];
    $field_errors = isset($feedback['field_errors']) && is_array($feedback['field_errors']) ? $feedback['field_errors'] : [];
}

if ( $feedback_status === 'success' ) {
    $message = 'Gracias. Hemos recibido tu información correctamente. Pronto nos pondremos en contacto contigo.';
    if ( is_string($form->success_message) && $form->success_message !== '' ) {
        $message = wp_kses_post($form->success_message);
    }

    if ( ! empty($feedback_messages) ) {
        $message = wp_kses_post((string) $feedback_messages[0]);
    }

    echo '<div class="estandar-form-success estandar-form-confirmation" role="status" aria-live="polite" tabindex="-1">';
    echo '<p><strong>Solicitud enviada correctamente</strong></p>';
    echo '<p>' . $message . '</p>';
    echo '</div>';
    return;
}

if ( $feedback_status === 'error' && ! empty($feedback_messages) ) {
    $error_count = ! empty($field_errors) ? count($field_errors) : count($feedback_messages);
    echo '<div class="estandar-form-error estandar-form-error-summary" role="alert" aria-live="polite">';
    echo '<p><strong>' . esc_html($error_count === 1 ? 'Se encontró 1 error:' : sprintf('Se encontraron %d errores:', $error_count)) . '</strong></p>';
    echo '<ul>';
    foreach ( $field_errors as $field_name => $error_message ) {
        echo '<li><button type="button" class="estandar-error-link" data-estandar-error-field="' . esc_attr((string) $field_name) . '">' . esc_html((string) $error_message) . '</button></li>';
    }
    if ( empty($field_errors) ) {
        foreach ( $feedback_messages as $error_message ) {
            echo '<li>' . esc_html((string) $error_message) . '</li>';
        }
    }
    echo '</ul>';
    echo '</div>';
}

/* =====================
   REDIRECT URL
===================== */
$redirect_url = is_singular() ? get_permalink() : home_url('/');

/* =====================
   FORMULARIO
===================== */
$form_routing_attrs = '';
if ( $form_routing_mode === 'project_selector' && ! empty($form_selector_projects) ) {
    $form_routing_attrs = ' data-routing-mode="project_selector"';
}

// Form Engine (flujo progresivo + condiciones): config canónica en actions_json.
$form_engine_config = function_exists('estandar_forms_engine_config')
    ? estandar_forms_engine_config($form->actions_json ?? '')
    : [];
if ( ! empty($form_engine_config) ) {
    $form_routing_attrs .= ' data-estandar-engine="1"';
}

echo '<form method="post"
      action="' . esc_url( admin_url('admin-post.php') ) . '"
      class="' . esc_attr(implode(' ', $form_classes)) . '"
      data-visual-theme="' . esc_attr($visual_theme) . '"
      data-form-country="' . esc_attr($form_country) . '"
      data-meta-lead-event="' . esc_attr($meta_lead_event ? '1' : '0') . '"
      data-success-message="' . esc_attr($success_message_attr) . '"'
      . $form_routing_attrs . '
      enctype="multipart/form-data">';

echo '<input type="hidden" id="' . esc_attr('estandar_form_nonce-' . $form_instance_id) . '" name="estandar_form_nonce" value="' . esc_attr(wp_create_nonce('estandar_form_submit')) . '">';
wp_referer_field();

echo '<input type="hidden" name="action" value="estandar_form_submit">';
echo '<input type="hidden" name="estandar_ajax" value="0">';
echo '<input type="hidden" name="estandar_form_id" value="' . esc_attr((string)$form_id) . '">';
echo '<input type="hidden" name="_redirect" value="' . esc_url($redirect_url) . '">';
echo '<input type="hidden" name="_estandar_token" value="' . esc_attr(wp_generate_uuid4()) . '">';
echo '<input type="hidden" name="estandar_ts" value="' . esc_attr((string) time()) . '">';
echo '<input type="hidden" name="lead_uuid" value="">';/* UUID populated client-side by estandar-forms-public.js:bindForms() — never baked into cached HTML */
echo '<input type="hidden" name="lead_status" value="NEW">';
echo '<input type="hidden" name="client_slug" value="' . esc_attr($client_slug) . '">';
echo '<input type="hidden" name="client_name" value="' . esc_attr($client_name) . '">';
echo '<input type="hidden" name="project_slug" value="' . esc_attr($form_project_slug) . '">';
echo '<input type="hidden" name="project_name" value="' . esc_attr($form_project_name) . '">';
echo '<input type="hidden" name="project_area" value="' . esc_attr($form_project_area) . '">';
echo '<input type="hidden" name="intent" value="' . esc_attr($form_intent) . '">';
echo '<input type="hidden" name="created_at" value="' . esc_attr(gmdate('c')) . '">';
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'meta_fbc', 'meta_fbp', 'ttclid', 'msclkid', 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type', 'landing_url', 'referrer', 'first_touch_timestamp', 'last_touch_timestamp', 'session_id', 'current_page_url'] as $attribution_field) {
    echo '<input type="hidden" name="' . esc_attr($attribution_field) . '" value="">';
}
echo '<input type="text" name="estandar_website" value="" tabindex="-1" autocomplete="off" class="estandar-form-hp" aria-hidden="true">';
echo '<input type="hidden" name="estandar_recaptcha_token" value="" class="estandar-recaptcha-token">';
if ( ! empty($form_engine_config) ) {
    echo '<script type="application/json" class="estandar-form-engine-config">'
        . wp_json_encode($form_engine_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
        . '</script>';
}
echo '<div class="estandar-form-grid">';

// project_selector: render system-generated selector at top of form
if ( $form_routing_mode === 'project_selector' && ! empty($form_selector_projects) ) {
    echo '<div class="estandar-form-field estandar-form-field--project-selector">';
    echo '<label class="estandar-form-label">';
    echo esc_html( apply_filters('estandar_forms_project_selector_label', 'Empresa', $form_id) );
    echo '</label>';
    echo '<select name="__project_routing" class="estandar-form-select estandar-form-select--project-routing" required>';
    echo '<option value="">' . esc_html( apply_filters('estandar_forms_project_selector_placeholder', '— Seleccionar —', $form_id) ) . '</option>';
    foreach ( $form_selector_projects as $sp ) {
        $sp_slug  = (string) ($sp['slug']  ?? '');
        $sp_label = (string) ($sp['label'] ?? $sp_slug);
        if ( $sp_slug === '' ) continue;
        echo '<option value="' . esc_attr($sp_slug) . '">' . esc_html($sp_label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
}

// Load DatasetProvider for fields that reference named datasets.
if ( ! class_exists( 'Estandar_Forms_Dataset_Provider' ) && defined( 'ESTANDAR_FORMS_PATH' ) ) {
    require_once ESTANDAR_FORMS_PATH . 'datasets/provider.php';
}

/* =====================
   CAMPOS
===================== */
foreach ( $fields as $field ) {

    if ( ! is_array($field) || empty($field['type']) ) {
        continue;
    }

    $type = (string) $field['type'];

/* =====================
   HEADING / TÍTULO
===================== */
if ( $type === 'heading' ) {

    $text  = isset($field['text']) ? wp_kses_post($field['text']) : '';
    $allowed_levels = ['h1', 'h2', 'h3', 'h4'];
    $allowed_aligns = ['left', 'center', 'right'];
    $level = isset($field['level']) && in_array($field['level'], $allowed_levels, true) ? $field['level'] : 'h3';
    $align = isset($field['align']) && in_array($field['align'], $allowed_aligns, true) ? $field['align'] : 'left';

    echo "<{$level} class=\"estandar-form-heading\" style=\"text-align:{$align};margin:16px 0 8px;\">";
    echo $text;
    echo "</{$level}>";

    continue;
}

/* =====================
   BLOQUE HTML
===================== */
if ( $type === 'html' ) {

    echo '<div class="estandar-form-html">';
    echo wp_kses_post( $field['html'] ?? '' );
    echo '</div>';

    continue;
}


    // Campos VISUALES no requieren name
    $has_name = ! empty($field['name']);

    $name_raw = $has_name ? (string) $field['name'] : '';
    $name     = $has_name ? sanitize_key($name_raw) : '';

    $label = isset($field['label']) && is_string($field['label']) && $field['label'] !== ''
        ? $field['label']
        : $name_raw;

    $required = ! empty($field['required']);
    $label_html = esc_html($label) . ( $required ? ' <span class="estandar-required" aria-hidden="true">*</span>' : '' );
    $old_value = isset($old_values[$name]) ? $old_values[$name] : '';
    $field_error = isset($field_errors[$name]) ? sanitize_text_field((string) $field_errors[$name]) : '';
    $has_error = $field_error !== '';
    $column_width = isset($field['column_width']) && in_array((string) $field['column_width'], ['1', '2', '3'], true)
        ? (string) $field['column_width']
        : '3';
    $conditional_logic = function_exists('estandar_forms_normalize_conditional_logic')
        ? estandar_forms_normalize_conditional_logic($field['conditional_logic'] ?? [])
        : [];
    $conditional_attr = '';
    if ( ! empty($conditional_logic['field']) ) {
        $conditional_attr .= ' data-conditional-field="' . esc_attr((string) $conditional_logic['field']) . '"';
        $conditional_attr .= ' data-conditional-operator="' . esc_attr((string) $conditional_logic['operator']) . '"';
        $conditional_attr .= ' data-conditional-value="' . esc_attr((string) ($conditional_logic['value'] ?? '')) . '"';
    }
    $field_id = $name . '-' . $form_instance_id;
    $error_id = $name . '-error-' . $form_instance_id;
    $describedby_id = $has_error ? $error_id : '';
    $error_markup = $has_error
        ? '<p class="estandar-field-error" id="' . esc_attr($error_id) . '" role="alert" aria-live="polite">' . esc_html($field_error) . '</p>'
        : '';

    if ( ! $has_name ) {
        continue;
    }

    echo '<div class="estandar-form-field estandar-type-' . esc_attr($type) . ' estandar-col-span-' . esc_attr($column_width) . ( $has_error ? ' has-error' : '' ) . '"' . $conditional_attr . '>';

    /* =====================
       CHECKBOX (simple)
    ====================== */
    if ( $type === 'checkbox' ) {

        echo '<label>';
        echo '<input type="checkbox"
            name="' . esc_attr($name) . '"
            value="1"
            ' . checked((string) $old_value, '1', false) . '
            ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
            ' . ( $required ? 'required' : '' ) . '
        > ';
        echo esc_html($label);
        echo '</label>';
        echo $error_markup;

    /* =====================
       RADIO BUTTONS
    ====================== */
    } elseif ( $type === 'radio' && ! empty($field['options']) && is_array($field['options']) ) {

        echo '<span class="estandar-radio-label">' . $label_html . '</span>';

        foreach ( $field['options'] as $i => $opt ) {

            if ( is_array($opt) ) {
                $opt_value = $opt['value'] ?? '';
                $opt_label = $opt['label'] ?? $opt_value;
            } elseif ( is_string($opt) ) {
                $opt_value = $opt;
                $opt_label = $opt;
            } else {
                continue;
            }

            $id = $name . '_' . $i . '-' . $form_instance_id;

            echo '<label for="' . esc_attr($id) . '">';
            echo '<input type="radio"
                id="' . esc_attr($id) . '"
                name="' . esc_attr($name) . '"
                value="' . esc_attr($opt_value) . '"
                ' . checked((string) $old_value, (string) $opt_value, false) . '
                ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
                ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
                ' . ( $required ? 'required' : '' ) . '
            > ';
            echo esc_html($opt_label);
            echo '</label>';
        }
        echo $error_markup;

    /* =====================
       TEXTAREA
    ====================== */
    } elseif ( $type === 'textarea' ) {

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($name) . '" ' .
             ( $has_error ? 'aria-invalid="true" ' : '' ) .
             ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '" ' : '' ) .
             ( $required ? 'required' : '' ) . '>' . esc_textarea((string) $old_value) . '</textarea>';
        echo $error_markup;

    /* =====================
       SELECT
       Options come from inline 'options' array or from a named 'dataset'.
    ====================== */
    } elseif ( $type === 'select' && (
        ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) ||
        ! empty( $field['dataset'] )
    ) ) {
        // Resolve options: named dataset takes precedence when present.
        if ( ! empty( $field['dataset'] ) && class_exists( 'Estandar_Forms_Dataset_Provider' ) ) {
            $select_options = Estandar_Forms_Dataset_Provider::get( (string) $field['dataset'] );
        } else {
            $select_options = is_array( $field['options'] ) ? $field['options'] : [];
        }

        // Key mapping — allows datasets with arbitrary field names.
        $vk = ! empty( $field['value_key'] ) ? (string) $field['value_key'] : 'value';
        $lk = ! empty( $field['label_key'] ) ? (string) $field['label_key'] : 'label';

        $select_placeholder = ! empty( $field['placeholder'] ) ? (string) $field['placeholder'] : '— Selecciona —';

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($name) . '" ' .
             ( $has_error ? 'aria-invalid="true" ' : '' ) .
             ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '" ' : '' ) .
             ( $required ? 'required' : '' ) . '>';

        echo '<option value="">' . esc_html( $select_placeholder ) . '</option>';

        foreach ( $select_options as $opt ) {

            if ( is_array($opt) ) {
                $opt_value = (string) ( $opt[ $vk ] ?? $opt['value'] ?? '' );
                $opt_label = (string) ( $opt[ $lk ] ?? $opt['label'] ?? $opt_value );
            } elseif ( is_string($opt) ) {
                $opt_value = $opt;
                $opt_label = $opt;
            } else {
                continue;
            }

            echo '<option value="' . esc_attr($opt_value) . '" ' . selected((string) $old_value, $opt_value, false) . '>' . esc_html($opt_label) . '</option>';
        }

        echo '</select>';
        echo $error_markup;

    /* =====================
       DATE
    ====================== */
    } elseif ( $type === 'date' ) {

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<input type="date"
            id="' . esc_attr($field_id) . '"
            name="' . esc_attr($name) . '"
            value="' . esc_attr((string) $old_value) . '"
            ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
            ' . ( $required ? 'required' : '' ) . '
        >';
        echo $error_markup;

    /* =====================
       RUT
    ====================== */
    } elseif ( $type === 'rut' ) {

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<input type="text"
            id="' . esc_attr($field_id) . '"
            name="' . esc_attr($name) . '"
            class="estandar-rut-field"
            placeholder="12.345.678-5"
            autocomplete="off"
            value="' . esc_attr((string) $old_value) . '"
            ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
            ' . ( $required ? 'required' : '' ) . '
        >';
        echo $error_markup;

    /* =====================
       TELEFONO
    ====================== */
    } elseif ( $type === 'tel' ) {

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<input type="tel"
            id="' . esc_attr($field_id) . '"
            name="' . esc_attr($name) . '"
            class="estandar-phone-field"
            inputmode="tel"
            autocomplete="tel"
            placeholder="' . esc_attr((string) $phone_spec['placeholder']) . '"
            data-country="' . esc_attr($form_country) . '"
            data-phone-message="' . esc_attr((string) $phone_spec['message']) . '"
            value="' . esc_attr((string) $old_value) . '"
            ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
            ' . ( $required ? 'required' : '' ) . '
        >';
        echo $error_markup;

    /* =====================
       FILE
    ====================== */
    } elseif ( $type === 'file' ) {

        echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
        echo '<input type="file"
            id="' . esc_attr($field_id) . '"
            name="' . esc_attr($name) . '"
            accept=".jpg,.jpeg,.png,.webp,.pdf"
            ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
            ' . ( $required ? 'required' : '' ) . '
        >';
        echo $error_markup;

    /* =====================
       PROJECT SELECT
       Poblado con proyectos del Core Project Registry.
       data-ef-project-select: nombre del campo (para que el JS
       lo vincule con el dependent_select correspondiente).
    ====================== */
    } elseif ( $type === 'project_select' ) {

        $projects = class_exists( 'Estandar_Forms_Dependent_Select' )
            ? Estandar_Forms_Dependent_Select::get_projects_for_select()
            : [];

        $placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '— Selecciona proyecto —';

        echo '<label for="' . esc_attr( $field_id ) . '">' . $label_html . '</label>';
        echo '<select
            id="'          . esc_attr( $field_id ) . '"
            name="'        . esc_attr( $name )     . '"
            data-ef-project-select="' . esc_attr( $name ) . '"
            '              . ( $has_error ? 'aria-invalid="true"' : '' ) . '
            '              . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr( $describedby_id ) . '"' : '' ) . '
            '              . ( $required ? 'required' : '' ) . '
        >';
        echo '<option value="">' . esc_html( $placeholder ) . '</option>';
        foreach ( $projects as $proj ) {
            $pval   = (string) ( $proj['value'] ?? '' );
            $plabel = (string) ( $proj['label'] ?? $pval );
            echo '<option value="' . esc_attr( $pval ) . '" ' . selected( (string) $old_value, $pval, false ) . '>'
                . esc_html( $plabel )
                . '</option>';
        }
        echo '</select>';
        echo $error_markup;

    /* =====================
       DEPENDENT SELECT
       Two modes:
       A) dataset + depends_on — all options embedded server-side with data-parent;
          client-side JS filters by parent field value. No AJAX required.
       B) project_select (legacy) — options loaded by JS via AJAX from project registry.
    ====================== */
    } elseif ( $type === 'dependent_select' ) {

        $depends_on   = isset( $field['depends_on'] ) ? sanitize_key( (string) $field['depends_on'] ) : '';
        $dataset_name = ! empty( $field['dataset'] ) ? sanitize_key( (string) $field['dataset'] ) : '';

        if ( $dataset_name !== '' && class_exists( 'Estandar_Forms_Dataset_Provider' ) ) {

            // ── Mode A: dataset-driven ────────────────────────────────────
            $all_opts    = Estandar_Forms_Dataset_Provider::get( $dataset_name );
            $placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '— Selecciona —';

            // Key mapping — allows datasets with arbitrary field names.
            $vk = ! empty( $field['value_key'] )  ? (string) $field['value_key']  : 'value';
            $lk = ! empty( $field['label_key'] )  ? (string) $field['label_key']  : 'label';
            $pk = ! empty( $field['parent_key'] ) ? (string) $field['parent_key'] : 'parent';

            // Disabled until the parent field has a value. JS (bindDatasetDependent) manages state.
            $dep_parent_has_value = $depends_on !== '' && ! empty( $old_values[ $depends_on ] );

            echo '<label for="' . esc_attr( $field_id ) . '">' . $label_html . '</label>';
            echo '<select'
                . ' id="' . esc_attr( $field_id ) . '"'
                . ' name="' . esc_attr( $name ) . '"'
                . ' data-ef-dataset-depends-on="' . esc_attr( $depends_on ) . '"'
                . ( $has_error ? ' aria-invalid="true"' : '' )
                . ( $describedby_id !== '' ? ' aria-describedby="' . esc_attr( $describedby_id ) . '"' : '' )
                . ( $required ? ' required' : '' )
                . ( $dep_parent_has_value ? '' : ' disabled' )
                . '>';
            echo '<option value="">' . esc_html( $placeholder ) . '</option>';

            foreach ( $all_opts as $opt ) {
                if ( ! is_array( $opt ) ) {
                    continue;
                }
                $ov = (string) ( $opt[ $vk ] ?? $opt['value'] ?? '' );
                $ol = (string) ( $opt[ $lk ] ?? $opt['label'] ?? $ov );
                $op = isset( $opt[ $pk ] ) ? (string) $opt[ $pk ] : '';
                echo '<option value="' . esc_attr( $ov ) . '"'
                    . ( $op !== '' ? ' data-parent="' . esc_attr( $op ) . '"' : '' )
                    . ' ' . selected( (string) $old_value, $ov, false )
                    . '>' . esc_html( $ol ) . '</option>';
            }

            echo '</select>';
            echo $error_markup;

        } else {

            // ── Mode B: project_select (legacy AJAX) ──────────────────────
            $placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '— Selecciona calle —';

            // Pre-poblar si venimos de un error de validación con old_values
            $streets = [];
            if ( $old_value !== '' && $depends_on !== '' && isset( $old_values[ $depends_on ] ) ) {
                $streets = class_exists( 'Estandar_Forms_Dependent_Select' )
                    ? Estandar_Forms_Dependent_Select::get_streets_for_project( (string) $old_values[ $depends_on ] )
                    : [];
            }

            echo '<label for="' . esc_attr( $field_id ) . '">' . $label_html . '</label>';
            echo '<select
                id="'              . esc_attr( $field_id ) . '"
                name="'            . esc_attr( $name )     . '"
                data-ef-depends-on="' . esc_attr( $depends_on ) . '"
                data-ef-restore-value="' . esc_attr( (string) $old_value ) . '"
                '                  . ( $has_error ? 'aria-invalid="true"' : '' ) . '
                '                  . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr( $describedby_id ) . '"' : '' ) . '
                '                  . ( $required ? 'required' : '' ) . '
            >';

            if ( ! empty( $streets ) ) {
                echo '<option value="">' . esc_html( $placeholder ) . '</option>';
                foreach ( $streets as $street ) {
                    echo '<option value="' . esc_attr( $street ) . '" ' . selected( (string) $old_value, $street, false ) . '>'
                        . esc_html( $street )
                        . '</option>';
                }
            } else {
                echo '<option value="">' . esc_html( $placeholder ) . '</option>';
            }

            echo '</select>';
            echo $error_markup;
        }

    /* =====================
       HIDDEN
    ====================== */
    } elseif ( $type === 'hidden' ) {

        $hidden_value = $old_value !== ''
            ? $old_value
            : sanitize_text_field( (string) ( $field['value'] ?? $field['default_value'] ?? '' ) );
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($hidden_value) . '">';

    /* =====================
       DEFAULT INPUT
    ====================== */
    } else {

        if ( $has_name ) {
            echo '<label for="' . esc_attr($field_id) . '">' . $label_html . '</label>';
            echo '<input type="' . esc_attr($type) . '"
                id="' . esc_attr($field_id) . '"
                name="' . esc_attr($name) . '"
                value="' . esc_attr((string) $old_value) . '"
                ' . ( $has_error ? 'aria-invalid="true"' : '' ) . '
                ' . ( $describedby_id !== '' ? 'aria-describedby="' . esc_attr($describedby_id) . '"' : '' ) . '
                ' . ( $required ? 'required' : '' ) . '
            >';
            echo $error_markup;
        }
    }

    echo '</div>';
}
echo '</div>';

if ( isset($is_incident_form) && $is_incident_form ) {
    include ESTANDAR_FORMS_PATH . 'public/incident-form-render.php';
}

echo '<div class="estandar-form-actions">';

echo '<button type="submit" class="estandar-form-submit">';
echo esc_html( $submit_label ?? 'Enviar' );
echo '</button>';

if ( ! empty($after_submit_text) ) {
    echo '<div class="estandar-form-after-submit">';
    echo wp_kses_post( $after_submit_text );
    echo '</div>';
}

echo '</div>';
echo '</form>';
