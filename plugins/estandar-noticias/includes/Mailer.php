<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mailer {

	public static function send( array $data, array $ed ): array {
		$to = array_values( array_filter( array_map( 'strval', (array) ( $ed['destinatarios'] ?? array() ) ), 'is_email' ) );
		if ( ! $to ) {
			$admin = (string) get_option( 'admin_email' );
			if ( is_email( $admin ) ) {
				$to = array( $admin );
			}
		}
		if ( ! $to ) {
			return array( 'ok' => false, 'error' => 'sin destinatarios' );
		}

		$subject = trim( (string) ( $ed['asunto'] ?? '' ) );
		if ( $subject === '' ) {
			$subject = Render::titulo( $data );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = trim( (string) Settings::get( 'general', 'remitente', '' ) );
		if ( $from !== '' && is_email( $from ) ) {
			$headers[] = 'From: ' . get_bloginfo( 'name' ) . ' <' . $from . '>';
		}

		$ok = wp_mail( $to, $subject, Render::html( $data, true ), $headers );

		return array( 'ok' => (bool) $ok, 'to' => $to, 'subject' => $subject );
	}
}
