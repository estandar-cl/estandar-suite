<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Forms_Action_Email' ) ) :

class Estandar_Forms_Action_Email implements Estandar_Form_Action {
	public function type(): string {
		return 'email';
	}

	public function run( array $payload, array $config, int $form_id ) {
		$recipients = $config['recipients'] ?? [];
		if ( is_string( $recipients ) ) {
			$recipients = array_filter( array_map( 'trim', explode( ',', $recipients ) ) );
		}
		$recipients = array_values( array_filter( array_map( 'sanitize_email', (array) $recipients ) ) );

		$request = (array) ( $payload['request'] ?? [] );
		$owner = (array) ( $request['owner'] ?? [] );
		$client_email = sanitize_email( (string) ( $owner['email'] ?? '' ) );

		$sent = false;
		if ( ! empty( $recipients ) ) {
			$subject = Estandar_Forms_Action_Engine::render_template( (string) ( $config['admin_subject'] ?? 'Nueva solicitud {{request_ref}}' ), $payload );
			$body = Estandar_Forms_Action_Engine::render_template( (string) ( $config['admin_body'] ?? "Solicitud {{request_ref}}\n\n{{incidents_summary}}" ), $payload );
			$sent = wp_mail( $recipients, $subject, $body );
			if ( ! $sent ) {
				return new WP_Error( 'action_email_admin_failed', 'No se pudo enviar el email interno.' );
			}
		}

		if ( $client_email !== '' && ! empty( $config['send_client'] ) ) {
			$subject = Estandar_Forms_Action_Engine::render_template( (string) ( $config['client_subject'] ?? 'Recibimos tu solicitud {{request_ref}}' ), $payload );
			$body = Estandar_Forms_Action_Engine::render_template( (string) ( $config['client_body'] ?? "Hola {{owner.name}},\n\nRecibimos tu solicitud {{request_ref}}." ), $payload );
			$sent = wp_mail( $client_email, $subject, $body );
			if ( ! $sent ) {
				return new WP_Error( 'action_email_client_failed', 'No se pudo enviar el email al cliente.' );
			}
		}

		return $sent || ! empty( $config['send_client'] );
	}
}

endif;
