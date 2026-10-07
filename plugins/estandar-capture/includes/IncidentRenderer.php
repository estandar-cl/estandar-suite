<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IncidentRenderer — render frontend de forms incident/postventa nativos v2 (F3).
 *
 * Puerto del incident-form-render legacy: repeater de incidencias (ubicación,
 * descripción, fotografías) configurado por incidents_config (locations/min/max/
 * conditional_field). Atributos data-es2-* (propios de capture; el JS legacy
 * data-ef-* no engancha estos campos → sin doble-bind en coexistencia).
 */
class IncidentRenderer
{
	/** Render del repeater de incidencias. */
	public static function render_repeater( array $config ): string
	{
		$incidents_config = $config['incidents_config'] ?? array();
		if ( is_string( $incidents_config ) ) {
			$incidents_config = json_decode( $incidents_config, true );
		}
		$incidents_config = is_array( $incidents_config ) ? $incidents_config : array();

		$locations = array();
		if ( ! empty( $incidents_config['locations'] ) && is_array( $incidents_config['locations'] ) ) {
			$locations = array_values( array_filter( array_map( 'sanitize_text_field', $incidents_config['locations'] ) ) );
		}
		if ( empty( $locations ) ) {
			$locations = array( 'BAÑO', 'COCINA', 'DORMITORIO', 'LIVING', 'EXTERIOR' );
		}

		$min = max( 0, (int) ( $incidents_config['min'] ?? 1 ) );
		$max = max( 0, (int) ( $incidents_config['max'] ?? 4 ) );
		$conditional_field = sanitize_key( (string) ( $incidents_config['conditional_field'] ?? '' ) );
		$conditional_value = sanitize_text_field( (string) ( $incidents_config['conditional_value'] ?? '' ) );

		self::enqueue_assets();

		ob_start();
		?>
		<section class="estandar-form-v2-incidents" data-es2-repeater data-es2-min="<?php echo esc_attr( (string) $min ); ?>" data-es2-max="<?php echo esc_attr( (string) $max ); ?>"<?php
			if ( $conditional_field !== '' ) {
				echo ' data-es2-conditional-field="' . esc_attr( $conditional_field ) . '" data-es2-conditional-value="' . esc_attr( $conditional_value ) . '"';
			}
		?>>
			<div data-es2-repeater-list>
				<?php self::render_incident_item( 0, $locations ); ?>
			</div>
			<button type="button" class="estandar-form-v2-add-incident" data-es2-repeater-add>+ Agregar problema</button>
			<template data-es2-repeater-template>
				<?php self::render_incident_item( '__INDEX__', $locations ); ?>
			</template>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_incident_item( $index, array $locations ): void
	{
		$index_attr = is_numeric( $index ) ? (string) (int) $index : '__INDEX__';
		?>
		<fieldset class="estandar-form-v2-incident" data-es2-incident data-index="<?php echo esc_attr( $index_attr ); ?>">
			<legend>Problema <span data-es2-index-label><?php echo esc_html( is_numeric( $index ) ? (string) ( (int) $index + 1 ) : '' ); ?></span></legend>

			<div class="estandar-form-v2-field">
				<label for="es2-incident-<?php echo esc_attr( $index_attr ); ?>-location">Ubicación <span style="color:#c00;">*</span></label>
				<select id="es2-incident-<?php echo esc_attr( $index_attr ); ?>-location" data-es2-field="location" name="incidents[<?php echo esc_attr( $index_attr ); ?>][location]" required>
					<option value="">Selecciona ubicación</option>
					<?php foreach ( $locations as $location ) : ?>
					<option value="<?php echo esc_attr( $location ); ?>"><?php echo esc_html( $location ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="estandar-form-v2-field">
				<label for="es2-incident-<?php echo esc_attr( $index_attr ); ?>-description">Descripción <span style="color:#c00;">*</span></label>
				<textarea id="es2-incident-<?php echo esc_attr( $index_attr ); ?>-description" data-es2-field="description" name="incidents[<?php echo esc_attr( $index_attr ); ?>][description]" required></textarea>
			</div>

			<div class="estandar-form-v2-field">
				<label for="es2-incident-<?php echo esc_attr( $index_attr ); ?>-files">Fotografías</label>
				<input id="es2-incident-<?php echo esc_attr( $index_attr ); ?>-files" type="file" data-es2-file name="incident_files[<?php echo esc_attr( $index_attr ); ?>][]" accept="image/*" multiple>
			</div>

			<button type="button" class="estandar-form-v2-remove-incident" data-es2-repeater-remove>Eliminar problema</button>
		</fieldset>
		<?php
	}

	private static function enqueue_assets(): void
	{
		if ( ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'wp_script_is' ) ) {
			return;
		}
		if ( wp_script_is( 'estandar-capture-repeater', 'enqueued' ) ) {
			return;
		}
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '1.0';
		wp_enqueue_script( 'estandar-capture-repeater', plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/js/estandar-repeater.js', array(), $ver, true );
	}
}
