<?php
/**
 * Incident Engine persistence layer.
 *
 * Stores Request -> Incident[] -> Attachment[] as one logical unit.
 *
 * @package Estandar_Forms
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Incident_Engine' ) ) :

class Estandar_Incident_Engine {

	public static function store_all( array $request_data, array $incidents, array $incident_files = [] ) {
		global $wpdb;

		$moved_files = [];
		$wpdb->query( 'START TRANSACTION' );

		$request_id = self::create_request( $request_data );
		if ( is_wp_error( $request_id ) ) {
			return self::rollback( $request_id, $moved_files );
		}

		$request_ref = self::request_ref_from_id( $request_id );
		$updated = self::update_request_ref( $request_id, $request_ref );
		if ( is_wp_error( $updated ) ) {
			return self::rollback( $updated, $moved_files );
		}

		$created_incidents = [];
		foreach ( $incidents as $sort_order => $incident ) {
			$incident_id = self::create_incident( $request_id, (int) $sort_order, $incident );
			if ( is_wp_error( $incident_id ) ) {
				return self::rollback( $incident_id, $moved_files );
			}

			$created_incidents[ (int) $sort_order ] = $incident_id;
		}

		foreach ( $created_incidents as $sort_order => $incident_id ) {
			foreach ( (array) ( $incident_files[ $sort_order ] ?? [] ) as $file ) {
				$attachment_id = self::store_attachment( $request_id, $incident_id, $request_ref, $file, $moved_files );
				if ( is_wp_error( $attachment_id ) ) {
					return self::rollback( $attachment_id, $moved_files );
				}
			}
		}

		$wpdb->query( 'COMMIT' );

		return [
			'request_id'  => $request_id,
			'request_ref' => $request_ref,
			'incidents'   => $created_incidents,
		];
	}

	public static function create_request( array $request_data ) {
		global $wpdb;

		$form_id = absint( $request_data['form_id'] ?? 0 );
		if ( $form_id <= 0 ) {
			return new WP_Error( 'request_invalid_form_id', 'El formulario de la solicitud no es válido.' );
		}

		$now = current_time( 'mysql' );
		$ok = $wpdb->insert(
			$wpdb->prefix . 'estandar_requests',
			[
				'request_ref'  => self::temporary_request_ref(),
				'form_id'      => $form_id,
				'form_type'    => sanitize_key( (string) ( $request_data['form_type'] ?? 'incident' ) ),
				'form_context' => sanitize_key( (string) ( $request_data['form_context'] ?? 'generic' ) ),
				'request_type' => sanitize_key( (string) ( $request_data['request_type'] ?? '' ) ),
				'status'       => sanitize_key( (string) ( $request_data['status'] ?? 'open' ) ),
				'owner_json'   => wp_json_encode( (array) ( $request_data['owner'] ?? [] ) ),
				'context_json' => wp_json_encode( (array) ( $request_data['context'] ?? [] ) ),
				'meta_json'    => wp_json_encode( (array) ( $request_data['meta'] ?? [] ) ),
				'source_ip'    => sanitize_text_field( (string) ( $request_data['source_ip'] ?? '' ) ),
				'source_ua'    => sanitize_textarea_field( (string) ( $request_data['source_ua'] ?? '' ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$request_id = absint( $wpdb->insert_id );
		if ( ! $ok || $request_id <= 0 ) {
			return new WP_Error( 'request_insert_failed', 'No se pudo crear la solicitud.', [ 'db_error' => $wpdb->last_error ] );
		}

		return $request_id;
	}

	public static function update_request_ref( int $request_id, string $request_ref ) {
		global $wpdb;

		$ok = $wpdb->update(
			$wpdb->prefix . 'estandar_requests',
			[ 'request_ref' => $request_ref ],
			[ 'id' => $request_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $ok === false ) {
			return new WP_Error( 'request_ref_update_failed', 'No se pudo actualizar el folio de la solicitud.', [ 'db_error' => $wpdb->last_error ] );
		}

		return true;
	}

	public static function create_incident( int $request_id, int $sort_order, array $incident ) {
		global $wpdb;

		$ok = $wpdb->insert(
			$wpdb->prefix . 'estandar_incidents',
			[
				'request_id'   => $request_id,
				'sort_order'   => $sort_order,
				'location'     => sanitize_text_field( (string) ( $incident['location'] ?? '' ) ),
				'title'        => sanitize_text_field( (string) ( $incident['title'] ?? '' ) ),
				'description'  => sanitize_textarea_field( (string) ( $incident['description'] ?? '' ) ),
				'status'       => sanitize_key( (string) ( $incident['status'] ?? 'open' ) ),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		$incident_id = absint( $wpdb->insert_id );
		if ( ! $ok || $incident_id <= 0 ) {
			return new WP_Error( 'incident_insert_failed', 'No se pudo crear la incidencia.', [ 'db_error' => $wpdb->last_error ] );
		}

		return $incident_id;
	}

	public static function store_attachment( int $request_id, int $incident_id, string $request_ref, array $file, array &$moved_files ) {
		global $wpdb;

		$moved = self::move_file( $request_ref, $file );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$moved_files[] = $moved['path'];

		$ok = $wpdb->insert(
			$wpdb->prefix . 'estandar_request_attachments',
			[
				'request_id'  => $request_id,
				'incident_id' => $incident_id,
				'file_path'   => $moved['path'],
				'file_name'   => $moved['name'],
				'file_size'   => $moved['size'],
				'mime_type'   => $moved['type'],
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		$attachment_id = absint( $wpdb->insert_id );
		if ( ! $ok || $attachment_id <= 0 ) {
			return new WP_Error( 'attachment_insert_failed', 'No se pudo registrar el adjunto.', [ 'db_error' => $wpdb->last_error ] );
		}

		return $attachment_id;
	}

	public static function request_ref_from_id( int $request_id ): string {
		return 'REQ-' . str_pad( (string) $request_id, 6, '0', STR_PAD_LEFT );
	}

	private static function temporary_request_ref(): string {
		return 'TMP-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
	}

	private static function move_file( string $request_ref, array $file ) {
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'attachment_upload_error', 'El archivo adjunto tiene un error de subida.' );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! file_exists( $tmp_name ) ) {
			return new WP_Error( 'attachment_tmp_missing', 'No existe el archivo temporal del adjunto.' );
		}

		$max_size = (int) apply_filters( 'estandar_forms_upload_max_size', 5 * 1024 * 1024, $file );
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			return new WP_Error( 'attachment_too_large', 'El archivo excede el tamaño permitido.' );
		}

		$upload = estandar_forms_upload_base();
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$target_dir = trailingslashit( $upload['dir'] ) . 'requests/' . sanitize_file_name( $request_ref ) . '/';
		if ( ! wp_mkdir_p( $target_dir ) || ! is_writable( $target_dir ) ) {
			return new WP_Error( 'attachment_dir_failed', 'No se pudo preparar el directorio de adjuntos.' );
		}

		$original_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$allowed_mimes = apply_filters( 'estandar_forms_allowed_mimes', [
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		], $file );
		$filetype = wp_check_filetype_and_ext( $tmp_name, $original_name, $allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_Error( 'attachment_invalid_type', 'Tipo de archivo no permitido.' );
		}

		$real_mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real_mime = (string) finfo_file( $finfo, $tmp_name );
				finfo_close( $finfo );
			}
		}
		if ( $real_mime !== '' && ! in_array( $real_mime, array_values( $allowed_mimes ), true ) ) {
			return new WP_Error( 'attachment_invalid_mime', 'El contenido real del archivo no está permitido.' );
		}

			if ( PHP_SAPI !== 'cli' && str_starts_with( (string) $filetype['type'], 'image/' ) && ! is_array( @getimagesize( $tmp_name ) ) ) {
				return new WP_Error( 'attachment_invalid_image', 'La imagen no es válida.' );
			}

		$final_name  = wp_unique_filename( $target_dir, $original_name );
		$destination = $target_dir . $final_name;

		$moved = is_uploaded_file( $tmp_name )
			? move_uploaded_file( $tmp_name, $destination )
			: ( PHP_SAPI === 'cli' && @rename( $tmp_name, $destination ) );

		if ( ! $moved ) {
			return new WP_Error( 'attachment_move_failed', 'No se pudo mover el adjunto.' );
		}

		@chmod( $destination, 0644 );

		return [
			'path' => $destination,
			'name' => $final_name,
			'type' => (string) $filetype['type'],
			'size' => (int) ( $file['size'] ?? filesize( $destination ) ),
		];
	}

	private static function rollback( WP_Error $error, array $moved_files ) {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		foreach ( array_reverse( $moved_files ) as $path ) {
			if ( is_string( $path ) && $path !== '' && function_exists( 'estandar_forms_is_safe_upload_path' ) && estandar_forms_is_safe_upload_path( $path ) && file_exists( $path ) ) {
				@unlink( $path );
			}
		}

		return $error;
	}
}

endif;
