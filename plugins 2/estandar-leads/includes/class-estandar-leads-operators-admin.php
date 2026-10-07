<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Leads_Operators_Admin' ) ) :

class Estandar_Leads_Operators_Admin {
	public static function render(): void {
		if ( ! class_exists( 'Estandar_Leads_Admin' ) ) {
			return;
		}

		$context = Estandar_Leads_Admin::operator_screen_context();
		extract( $context, EXTR_SKIP );

		require ESTANDAR_LEADS_PATH . 'templates/admin/operators.php';
	}

	public static function allowed_projects( $operator ): array {
		$decoded = json_decode( (string) ( $operator->allowed_projects_json ?? '' ), true );
		$projects = Estandar_Leads_Operator_Workspace::sanitize_projects( is_array( $decoded ) ? $decoded : array() );
		if ( empty( $projects ) && ! empty( $operator->project_slug ) ) {
			$projects = Estandar_Leads_Operator_Workspace::sanitize_projects( array( $operator->project_slug ) );
		}

		return $projects;
	}
}

endif;
