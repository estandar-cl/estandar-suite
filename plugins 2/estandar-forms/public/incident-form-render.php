<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! isset($form_id) || ! is_numeric($form_id) || ! isset($form) || ! is_object($form) ) {
    return;
}

$locations = [];
if ( ! empty($form->incidents_config) ) {
    $config = json_decode((string) $form->incidents_config, true);
    if ( is_array($config) && ! empty($config['locations']) && is_array($config['locations']) ) {
        $locations = array_values(array_filter(array_map('sanitize_text_field', $config['locations'])));
    }
}
$config = isset($config) && is_array($config) ? $config : [];
$min = max(0, (int) ($config['min'] ?? 1));
$max = max(0, (int) ($config['max'] ?? 4));
$conditional_field = sanitize_key((string) ($config['conditional_field'] ?? ''));
$conditional_value = sanitize_text_field((string) ($config['conditional_value'] ?? ''));
if ( empty($locations) ) {
    $locations = ['BAÑO', 'COCINA', 'DORMITORIO', 'LIVING', 'EXTERIOR'];
}

if ( ! function_exists('estandar_forms_render_incident_item') ) {
function estandar_forms_render_incident_item($index, array $locations): void {
    $index_attr = is_numeric($index) ? (string) (int) $index : '__INDEX__';

	    echo '<fieldset class="estandar-form-incident" data-ef-incident data-index="' . esc_attr($index_attr) . '">';
	    echo '<legend>Problema <span data-ef-index-label>' . esc_html(is_numeric($index) ? (string) ((int) $index + 1) : '') . '</span></legend>';

	    echo '<div class="estandar-form-field">';
	    echo '<label for="ef-incident-' . esc_attr($index_attr) . '-location">Ubicación <span class="estandar-required" aria-hidden="true">*</span></label>';
	    echo '<select id="ef-incident-' . esc_attr($index_attr) . '-location" data-ef-field="location" name="incidents[' . esc_attr($index_attr) . '][location]" required>';
	    echo '<option value="">Selecciona ubicación</option>';
	    foreach ( $locations as $location ) {
	        echo '<option value="' . esc_attr($location) . '">' . esc_html($location) . '</option>';
	    }
	    echo '</select>';
	    echo '</div>';

	    echo '<div class="estandar-form-field">';
	    echo '<label for="ef-incident-' . esc_attr($index_attr) . '-description">Descripción <span class="estandar-required" aria-hidden="true">*</span></label>';
	    echo '<textarea id="ef-incident-' . esc_attr($index_attr) . '-description" data-ef-field="description" name="incidents[' . esc_attr($index_attr) . '][description]" required></textarea>';
	    echo '</div>';

	    echo '<div class="estandar-form-field">';
	    echo '<label for="ef-incident-' . esc_attr($index_attr) . '-files">Fotografías</label>';
	    echo '<input id="ef-incident-' . esc_attr($index_attr) . '-files" type="file" data-ef-file name="incident_files[' . esc_attr($index_attr) . '][]" accept="image/*" multiple>';
	    echo '</div>';

    echo '<button type="button" class="estandar-form-remove-incident" data-ef-repeater-remove>Eliminar problema</button>';
    echo '</fieldset>';
}
}

echo '<section class="estandar-form-incidents" data-ef-repeater data-ef-min="' . esc_attr((string) $min) . '" data-ef-max="' . esc_attr((string) $max) . '"';
if ( $conditional_field !== '' ) {
    echo ' data-conditional-field="' . esc_attr($conditional_field) . '" data-conditional-operator="equals" data-conditional-value="' . esc_attr($conditional_value) . '"';
}
echo '>';
echo '<div data-ef-repeater-list>';

estandar_forms_render_incident_item(0, $locations);

echo '</div>';
echo '<button type="button" class="estandar-form-add-incident" data-ef-repeater-add>+ Agregar problema</button>';
echo '<template data-ef-repeater-template>';
estandar_forms_render_incident_item('__INDEX__', $locations);
echo '</template>';
echo '</section>';
