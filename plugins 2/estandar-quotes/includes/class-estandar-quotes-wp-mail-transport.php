<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** WordPress transport adapter; artifact storage remains opaque to callers. */
class Estandar_Quotes_WP_Mail_Transport implements Estandar_Quotes_Mail_Transport {
	public function send( array $delivery, array $metadata, array $artifact, array $recipients ): bool {
		if ( ! function_exists( 'wp_mail' ) || ! $recipients || ( $artifact['status'] ?? '' ) !== 'ready' ) { return false; }
		$key = (string) ( $artifact['storage_key'] ?? '' );
		if ( ! preg_match( '#^estandar-quotes/[a-f0-9-]+\\.pdf$#', $key ) ) { return false; }
		$uploads = wp_upload_dir(); $path = trailingslashit( $uploads['basedir'] ) . $key;
		if ( ! is_readable( $path ) ) { return false; }
		if ( (int) ( $artifact['byte_size'] ?? 0 ) < 100 ) { return false; }
		$meta = $metadata['delivery_meta'] ?? array();
		$project = sanitize_title( $meta['project_slug'] ?? 'cotizacion' );
		$number = $meta['quote_number'] ?? '000';
		$revision_no = $meta['revision_no'] ?? 1;
		$filename = 'Cotizacion-' . $project . '-' . $number . '-' . sprintf( '%02d', $revision_no ) . '.pdf';
		$tmp_dir = wp_upload_dir()['basedir'] . '/estandar-quotes-tmp/';
		wp_mkdir_p( $tmp_dir );
		$tmp = $tmp_dir . $filename;
		if ( ! copy( $path, $tmp ) ) { return false; }
		$subject = ( $metadata['kind'] ?? 'client' ) === 'operator' ? 'Confirmación: ' . $delivery['subject'] : $delivery['subject'];
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$settings = get_option( 'estandar_quotes_defaults', array() );
		$sender_email = sanitize_email( (string) ( $settings['sender_email'] ?? '' ) );
		$sender_name  = sanitize_text_field( (string) ( $settings['sender_name'] ?? '' ) );
		if ( $sender_email !== '' ) {
			$from = $sender_name !== '' ? $sender_name . ' <' . $sender_email . '>' : $sender_email;
			$headers[] = 'From: ' . $from;
		}
		$sent = (bool) wp_mail(
			$recipients,
			sanitize_text_field( $subject ),
			Estandar_Quotes_Delivery_Email::render( $delivery, $metadata, $artifact, $recipients ),
			$headers,
			array( $tmp )
		);
		@unlink( $tmp );
		return $sent;
	}
}
