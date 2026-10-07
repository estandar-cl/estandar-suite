<?php
/**
 * Repeater parsing for incident forms.
 *
 * Converts incidents[] and incident_files[] into compact, validated arrays.
 *
 * @package Estandar_Forms
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Forms_Repeater' ) ) :

class Estandar_Forms_Repeater {

	public static function parse( array $post, array $files = [] ): array {
		$raw_incidents = isset( $post['incidents'] ) && is_array( $post['incidents'] )
			? $post['incidents']
			: [];
		$raw_files = isset( $files['incident_files'] ) && is_array( $files['incident_files'] )
			? $files['incident_files']
			: [];

		$incidents      = [];
		$incident_files = [];
		$errors         = [];

		foreach ( $raw_incidents as $source_index => $incident ) {
			if ( ! is_array( $incident ) ) {
				continue;
			}

			$source_index = (int) $source_index;
			$row = [
				'location'    => sanitize_text_field( (string) ( $incident['location'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $incident['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $incident['description'] ?? '' ) ),
			];
			if ( $row['title'] === '' ) {
				$row['title'] = mb_substr( $row['description'] !== '' ? $row['description'] : $row['location'], 0, 120 );
			}

			$files_for_row = self::get_files_for_incident( $raw_files, $source_index );
			$has_content   = $row['location'] !== '' || $row['title'] !== '' || $row['description'] !== '' || ! empty( $files_for_row );

			if ( ! $has_content ) {
				continue;
			}

			$row_errors = self::validate( $row );
			if ( ! empty( $row_errors ) ) {
				$errors[ $source_index ] = $row_errors;
				continue;
			}

			$target_index = count( $incidents );
			$incidents[ $target_index ] = $row;
			$incident_files[ $target_index ] = $files_for_row;
		}

		return [
			'incidents'      => $incidents,
			'incident_files' => $incident_files,
			'errors'         => $errors,
		];
	}

	public static function validate( array $incident ): array {
		$errors = [];

		if ( trim( (string) ( $incident['location'] ?? '' ) ) === '' ) {
			$errors['location'] = 'La ubicación de la incidencia es obligatoria.';
		}

		if ( trim( (string) ( $incident['description'] ?? '' ) ) === '' ) {
			$errors['description'] = 'La descripción del problema es obligatoria.';
		}

		return $errors;
	}

	public static function get_files_for_incident( array $files, int $incident_index ): array {
		$names = $files['name'][ $incident_index ] ?? [];
		if ( ! is_array( $names ) ) {
			$names = $names !== '' ? [ $names ] : [];
		}

		$normalized = [];
		foreach ( $names as $file_index => $name ) {
			$error = (int) ( $files['error'][ $incident_index ][ $file_index ] ?? UPLOAD_ERR_NO_FILE );
			$name  = sanitize_file_name( (string) $name );

			if ( $error === UPLOAD_ERR_NO_FILE || $name === '' ) {
				continue;
			}

			$normalized[] = [
				'name'     => $name,
				'type'     => sanitize_mime_type( (string) ( $files['type'][ $incident_index ][ $file_index ] ?? '' ) ),
				'tmp_name' => (string) ( $files['tmp_name'][ $incident_index ][ $file_index ] ?? '' ),
				'error'    => $error,
				'size'     => (int) ( $files['size'][ $incident_index ][ $file_index ] ?? 0 ),
			];
		}

		return $normalized;
	}
}

endif;
