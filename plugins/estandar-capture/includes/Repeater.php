<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repeater — parsing de incidents[] / incident_files[] (F3).
 *
 * Puerto del Estandar_Forms_Repeater legacy: convierte el POST del repeater
 * en arrays compactos y validados. Puro (sin DB).
 */
class Repeater
{
	/** @return array{incidents:array, incident_files:array, errors:array} */
	public static function parse( array $post, array $files = array() ): array
	{
		$raw_incidents = isset( $post['incidents'] ) && is_array( $post['incidents'] ) ? $post['incidents'] : array();
		$raw_files     = isset( $files['incident_files'] ) && is_array( $files['incident_files'] ) ? $files['incident_files'] : array();

		$incidents      = array();
		$incident_files = array();
		$errors         = array();

		foreach ( $raw_incidents as $source_index => $incident ) {
			if ( ! is_array( $incident ) ) {
				continue;
			}
			$source_index = (int) $source_index;
			$row = array(
				'location'    => sanitize_text_field( (string) ( $incident['location'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $incident['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $incident['description'] ?? '' ) ),
			);
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

		return array(
			'incidents'      => $incidents,
			'incident_files' => $incident_files,
			'errors'         => $errors,
		);
	}

	public static function validate( array $incident ): array
	{
		$errors = array();
		if ( trim( (string) ( $incident['location'] ?? '' ) ) === '' ) {
			$errors['location'] = 'La ubicación de la incidencia es obligatoria.';
		}
		if ( trim( (string) ( $incident['description'] ?? '' ) ) === '' ) {
			$errors['description'] = 'La descripción del problema es obligatoria.';
		}
		return $errors;
	}

	public static function get_files_for_incident( array $files, int $incident_index ): array
	{
		$names = $files['name'][ $incident_index ] ?? array();
		if ( ! is_array( $names ) ) {
			$names = $names !== '' ? array( $names ) : array();
		}

		$normalized = array();
		foreach ( $names as $file_index => $name ) {
			$error = (int) ( $files['error'][ $incident_index ][ $file_index ] ?? UPLOAD_ERR_NO_FILE );
			$name  = sanitize_file_name( (string) $name );

			if ( $error === UPLOAD_ERR_NO_FILE || $name === '' ) {
				continue;
			}

			$normalized[] = array(
				'name'     => $name,
				'type'     => sanitize_mime_type( (string) ( $files['type'][ $incident_index ][ $file_index ] ?? '' ) ),
				'tmp_name' => (string) ( $files['tmp_name'][ $incident_index ][ $file_index ] ?? '' ),
				'error'    => $error,
				'size'     => (int) ( $files['size'][ $incident_index ][ $file_index ] ?? 0 ),
			);
		}
		return $normalized;
	}
}
