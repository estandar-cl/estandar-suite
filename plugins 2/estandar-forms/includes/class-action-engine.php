<?php
/**
 * Action Engine for persisted incident requests.
 *
 * Executes distribution actions after Incident Engine persistence.
 *
 * @package Estandar_Forms
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'Estandar_Form_Action' ) ) :
interface Estandar_Form_Action {
	public function type(): string;
	public function run( array $payload, array $config, int $form_id );
}
endif;

if ( ! class_exists( 'Estandar_Forms_Action_Engine' ) ) :

class Estandar_Forms_Action_Engine {

	public static function run( array $payload, array $actions_json, int $form_id ) {
		$actions = self::normalize_actions( $actions_json );
		$handlers = self::handlers();
		$results = [];

		foreach ( $actions as $action_config ) {
			$type = sanitize_key( (string) ( $action_config['type'] ?? '' ) );
			if ( $type === '' || empty( $handlers[ $type ] ) ) {
				continue;
			}

			$result = $handlers[ $type ]->run( $payload, $action_config, $form_id );
			if ( is_wp_error( $result ) ) {
				self::log_action( $form_id, 'action.failed', 'warning', 'Action failed.', [
					'action'      => $type,
					'request_ref' => (string) ( $payload['request_ref'] ?? '' ),
					'error'       => $result->get_error_message(),
				] );

				if ( $type === 'database' ) {
					return $result;
				}

				$results[ $type ] = false;
				continue;
			}

			self::log_action( $form_id, 'action.executed', 'info', 'Action executed.', [
				'action'      => $type,
				'request_ref' => (string) ( $payload['request_ref'] ?? '' ),
			] );
			$results[ $type ] = true;
		}

		return $results;
	}

	public static function normalize_actions( array $actions ): array {
		$normalized = [];
		$has_database = false;

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $action['type'] ?? '' ) );
			if ( $type === '' ) {
				continue;
			}

			if ( $type === 'database' ) {
				$has_database = true;
			}

			$action['type'] = $type;
			$normalized[] = $action;
		}

		if ( ! $has_database ) {
			array_unshift( $normalized, [ 'type' => 'database' ] );
		}

		return $normalized;
	}

	public static function build_event_payload( array $payload ): array {
		$request = (array) ( $payload['request'] ?? [] );

		return [
			'schema_version' => '1.0',
			'event'          => 'request.created',
			'request_id'     => absint( $payload['request_id'] ?? 0 ),
			'request_ref'    => (string) ( $payload['request_ref'] ?? '' ),
			'timestamp'      => (string) ( $payload['submitted_at'] ?? gmdate( 'c' ) ),
			'form_id'        => absint( $payload['form_id'] ?? $request['form_id'] ?? 0 ),
			'entry_id'       => absint( $payload['entry_id'] ?? $request['meta']['entry_id'] ?? 0 ),
			'request_type'   => (string) ( $request['request_type'] ?? '' ),
			'form_type'      => (string) ( $request['form_type'] ?? 'incident' ),
			'form_context'   => (string) ( $request['form_context'] ?? 'inmobiliaria' ),
			'owner'          => (array) ( $request['owner'] ?? [] ),
			'context'        => (array) ( $request['context'] ?? [] ),
			'incidents'      => self::incidents_for_payload(
				(array) ( $payload['incidents'] ?? [] ),
				(array) ( $payload['attachments'] ?? [] )
			),
			'propietario'    => (array) ( $request['owner'] ?? [] ),
			'vivienda'       => (array) ( $request['context'] ?? [] ),
			'tipo_solicitud' => (string) ( $request['request_type'] ?? '' ),
			'problemas'      => self::incidents_for_payload(
				(array) ( $payload['incidents'] ?? [] ),
				(array) ( $payload['attachments'] ?? [] )
			),
			'archivos'       => self::files_for_payload( (array) ( $payload['attachments'] ?? [] ) ),
		];
	}

	public static function render_template( string $template, array $payload ): string {
		$request = (array) ( $payload['request'] ?? [] );
		$owner = (array) ( $request['owner'] ?? [] );
		$context = (array) ( $request['context'] ?? [] );

		$vars = [
			'{{request_ref}}'           => (string) ( $payload['request_ref'] ?? '' ),
			'{{owner.name}}'            => (string) ( $owner['name'] ?? '' ),
			'{{owner.email}}'           => (string) ( $owner['email'] ?? '' ),
			'{{owner.phone}}'           => (string) ( $owner['phone'] ?? '' ),
			'{{context.project_label}}' => (string) ( $context['project_label'] ?? '' ),
			'{{context.street}}'        => (string) ( $context['street'] ?? '' ),
			'{{context.house_number}}'  => (string) ( $context['house_number'] ?? '' ),
			'{{incidents_summary}}'     => self::incidents_summary( (array) ( $payload['incidents'] ?? [] ) ),
		];

		return str_replace( array_keys( $vars ), array_values( $vars ), $template );
	}

	private static function handlers(): array {
		return [
			'database' => new Estandar_Forms_Action_Database(),
			'email'    => new Estandar_Forms_Action_Email(),
			'webhook'  => new Estandar_Forms_Action_Webhook(),
		];
	}

	private static function incidents_for_payload( array $incidents, array $attachments ): array {
		$grouped = [];
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$key = isset( $attachment['incident_id'] ) ? (string) $attachment['incident_id'] : (string) ( $attachment['incident_index'] ?? '' );
			if ( $key === '' ) {
				continue;
			}
			$grouped[ $key ][] = [
				'attachment_id' => absint( $attachment['attachment_id'] ?? 0 ),
				'file_name'     => sanitize_file_name( (string) ( $attachment['file_name'] ?? '' ) ),
				'mime_type'     => sanitize_mime_type( (string) ( $attachment['mime_type'] ?? '' ) ),
			];
		}

		$out = [];
		foreach ( $incidents as $index => $incident ) {
			if ( ! is_array( $incident ) ) {
				continue;
			}
			$incident_id = absint( $incident['incident_id'] ?? 0 );
			$key = $incident_id > 0 ? (string) $incident_id : (string) $index;
			$out[] = [
				'incident_id'  => $incident_id,
				'location'     => (string) ( $incident['location'] ?? '' ),
				'title'        => (string) ( $incident['title'] ?? '' ),
				'description'  => (string) ( $incident['description'] ?? '' ),
				'attachments'  => (array) ( $incident['attachments'] ?? ( $grouped[ $key ] ?? [] ) ),
			];
		}

		return $out;
	}

	private static function files_for_payload( array $attachments ): array {
		$out = [];
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$out[] = [
				'attachment_id' => absint( $attachment['attachment_id'] ?? 0 ),
				'incident_id'   => absint( $attachment['incident_id'] ?? 0 ),
				'file_name'     => sanitize_file_name( (string) ( $attachment['file_name'] ?? '' ) ),
				'mime_type'     => sanitize_mime_type( (string) ( $attachment['mime_type'] ?? '' ) ),
				'file_size'     => absint( $attachment['file_size'] ?? 0 ),
			];
		}
		return $out;
	}

	private static function incidents_summary( array $incidents ): string {
		$lines = [];
		foreach ( $incidents as $incident ) {
			$location = (string) ( $incident['location'] ?? '' );
			$title = (string) ( $incident['title'] ?? '' );
			if ( $location === '' && $title === '' ) {
				continue;
			}
			$lines[] = trim( $location . ' - ' . $title, ' -' );
		}
		return implode( "\n", $lines );
	}

	private static function log_action( int $form_id, string $event_type, string $level, string $message, array $context ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'estandar_form_logs',
			[
				'form_id'      => $form_id,
				'event_type'   => $event_type,
				'level'        => $level,
				'message'      => $message,
				'context_json' => wp_json_encode( $context ),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}
}

endif;
