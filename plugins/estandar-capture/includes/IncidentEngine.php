<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IncidentEngine — persistencia transaccional de Request → Incident[] → Attachment[] (F3).
 *
 * Puerto del Estandar_Incident_Engine legacy al storage v2 (IncidentDb).
 * Misma semántica: transacción + rollback con limpieza de archivos movidos,
 * validación de tipo MIME (extension + finfo + getimagesize), 5MB máx,
 * uploads bajo uploads/estandar-capture/requests/{REQ-xxxxxx}/.
 */
class IncidentEngine
{
	/** @return array|string request_id/request_ref/incidents o código de error. */
	public static function store_all( array $request_data, array $incidents, array $incident_files = array() )
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 'no_db';
		}
		if ( (int) ( $request_data['form_id'] ?? 0 ) <= 0 ) {
			return 'request_invalid_form_id';
		}
		if ( empty( $incidents ) ) {
			return 'no_incidents';
		}

		$moved_files = array();
		$wpdb->query( 'START TRANSACTION' );

		$request_id = IncidentDb::request_create( $request_data );
		if ( $request_id <= 0 ) {
			return self::rollback( 'request_insert_failed', $moved_files );
		}

		$request_ref = IncidentDb::request_ref_from_id( $request_id );
		if ( ! IncidentDb::request_set_ref( $request_id, $request_ref ) ) {
			return self::rollback( 'request_ref_update_failed', $moved_files );
		}

		$created_incidents = array();
		foreach ( $incidents as $sort_order => $incident ) {
			$incident_id = IncidentDb::incident_create( $request_id, (int) $sort_order, $incident );
			if ( $incident_id <= 0 ) {
				return self::rollback( 'incident_insert_failed', $moved_files );
			}
			$created_incidents[ (int) $sort_order ] = $incident_id;
		}

		foreach ( $created_incidents as $sort_order => $incident_id ) {
			foreach ( (array) ( $incident_files[ $sort_order ] ?? array() ) as $file ) {
				$moved = self::move_file( $request_ref, $file );
				if ( is_string( $moved ) ) {
					return self::rollback( $moved, $moved_files );
				}
				$moved_files[] = $moved['path'];
				$attachment_id = IncidentDb::attachment_create( $request_id, $incident_id, $moved );
				if ( $attachment_id <= 0 ) {
					return self::rollback( 'attachment_insert_failed', $moved_files );
				}
			}
		}

		$wpdb->query( 'COMMIT' );

		return array(
			'request_id'  => $request_id,
			'request_ref' => $request_ref,
			'incidents'   => $created_incidents,
		);
	}

	/** @return array|string datos del archivo movido o código de error. */
	private static function move_file( string $request_ref, array $file )
	{
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return 'attachment_upload_error';
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! file_exists( $tmp_name ) ) {
			return 'attachment_tmp_missing';
		}

		$max_size = (int) apply_filters( 'estandar_forms_upload_max_size', 5 * 1024 * 1024, $file );
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			return 'attachment_too_large';
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return 'attachment_dir_failed';
		}
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'estandar-capture/requests/' . sanitize_file_name( $request_ref ) . '/';
		if ( ! wp_mkdir_p( $target_dir ) || ! is_writable( $target_dir ) ) {
			return 'attachment_dir_failed';
		}

		$original_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$allowed_mimes = apply_filters( 'estandar_forms_allowed_mimes', array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		), $file );

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			return 'attachment_no_filetype_api';
		}
		$filetype = wp_check_filetype_and_ext( $tmp_name, $original_name, $allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return 'attachment_invalid_type';
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
			return 'attachment_invalid_mime';
		}

		if ( PHP_SAPI !== 'cli' && str_starts_with( (string) $filetype['type'], 'image/' ) && ! is_array( @getimagesize( $tmp_name ) ) ) {
			return 'attachment_invalid_image';
		}

		$final_name  = wp_unique_filename( $target_dir, $original_name );
		$destination = $target_dir . $final_name;

		$moved = is_uploaded_file( $tmp_name )
			? move_uploaded_file( $tmp_name, $destination )
			: ( PHP_SAPI === 'cli' && @rename( $tmp_name, $destination ) );

		if ( ! $moved ) {
			return 'attachment_move_failed';
		}
		@chmod( $destination, 0644 );

		// Optimización de imágenes referenciales (postventa): redimensiona a
		// calidad de pantalla y re-comprime para que pesen poco. Best-effort:
		// si no hay editor disponible, la imagen queda como subió.
		if ( str_starts_with( (string) $filetype['type'], 'image/' ) && class_exists( 'Estandar\\Capture\\ImageOptimizer' ) ) {
			$opt = ImageOptimizer::optimize_file( $destination, $final_name, (string) $filetype['type'] );
			$destination = (string) $opt['path'];
			$final_name  = (string) $opt['name'];
		}

		return array(
			'path' => $destination,
			'name' => $final_name,
			'type' => (string) $filetype['type'],
			'size' => (int) ( $file['size'] ?? filesize( $destination ) ),
		);
	}

	private static function rollback( string $error, array $moved_files ): string
	{
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		foreach ( array_reverse( $moved_files ) as $path ) {
			if ( is_string( $path ) && $path !== '' && file_exists( $path ) && strpos( $path, 'estandar-capture/requests/' ) !== false ) {
				@unlink( $path );
			}
		}
		return $error;
	}
}
