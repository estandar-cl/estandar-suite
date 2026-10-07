<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Forms_Action_Database' ) ) :

class Estandar_Forms_Action_Database implements Estandar_Form_Action {
	public function type(): string {
		return 'database';
	}

	public function run( array $payload, array $config, int $form_id ) {
		if ( absint( $payload['request_id'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'action_database_missing_request_id', 'Falta request_id persistido.' );
		}

		if ( trim( (string) ( $payload['request_ref'] ?? '' ) ) === '' ) {
			return new WP_Error( 'action_database_missing_request_ref', 'Falta request_ref persistido.' );
		}

		do_action( 'estandar_request_created', $payload, $form_id );

		foreach ( (array) ( $payload['incidents'] ?? [] ) as $incident ) {
			do_action( 'estandar_incident_created', $incident, $payload, $form_id );
		}

		return true;
	}
}

endif;
