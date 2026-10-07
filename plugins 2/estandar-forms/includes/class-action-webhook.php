<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Forms_Action_Webhook' ) ) :

class Estandar_Forms_Action_Webhook implements Estandar_Form_Action {
	public function type(): string {
		return 'webhook';
	}

	public function run( array $payload, array $config, int $form_id ) {
		$url = esc_url_raw( (string) ( $config['url'] ?? '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'action_webhook_missing_url', 'Falta URL del webhook.' );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => wp_json_encode( Estandar_Forms_Action_Engine::build_event_payload( $payload ) ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'action_webhook_http_error', 'Webhook respondió con HTTP ' . $code . '.' );
		}

		return true;
	}
}

endif;
