<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Context_Stage implements Estandar_Quotes_Hydration_Stage {

	public function priority(): int { return 20; }

	public function apply( Estandar_Quotes_Quote_Draft $draft, array $context ): Estandar_Quotes_Quote_Draft {
		$lead_snapshot = $context['lead_snapshot'] ?? $draft->get_meta( 'lead_snapshot', [] );
		$submission = (array) ( $lead_snapshot['submission'] ?? [] );
		$ctx = (array) ( $submission['context'] ?? [] );

		$project_name = $ctx['project_name'] ?? '';
		if ( $project_name !== '' && empty( $draft->get( 'project.name' ) ) ) {
			$draft->set( 'project.name', sanitize_text_field( $project_name ) );
		}

		$project_area = $ctx['project_area'] ?? '';
		if ( $project_name === '' && $project_area !== '' && empty( $draft->get( 'project.name' ) ) ) {
			$draft->set( 'project.name', sanitize_text_field( $project_area ) );
		}

		if ( $project_area !== '' && empty( $draft->get( 'project.stage' ) ) && $draft->get( 'project.name' ) !== $project_area ) {
			$draft->set( 'project.stage', sanitize_text_field( $project_area ) );
		}

		$owner = $ctx['client_context']['handoff']['owner'] ?? '';
		if ( $owner !== '' && empty( $draft->get( 'issuer.authority_name' ) ) ) {
			$draft->set( 'issuer.authority_name', sanitize_text_field( $owner ) );
		}

		return $draft;
	}

	public function fields(): array {
		return [ 'project.name', 'project.stage', 'issuer.authority_name' ];
	}

	public function overwrite_policy(): string {
		return 'merge_if_empty';
	}

	public function supported_profiles(): array {
		return [];
	}
}
