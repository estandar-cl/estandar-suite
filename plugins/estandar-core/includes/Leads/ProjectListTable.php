<?php
/**
 * Estandar Suite — Core · Lista WP de PROYECTOS (Proyectos registrados).
 * Estructura/uso estándar de WordPress igual que Formularios/Entradas.
 * Necesita `WP_List_Table` (se incluye en el template si falta).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Core_ProjectListTable' ) ) {
	class Estandar_Core_ProjectListTable extends WP_List_Table
	{
		private $rows = array();

		public function __construct()
		{
			parent::__construct( array(
				'singular' => 'proyecto',
				'plural'   => 'proyectos',
				'ajax'     => false,
			) );
		}

		public function get_columns(): array
		{
			return array(
				'cb'       => '<input type="checkbox" />',
				'label'    => 'Nombre',
				'slug'     => 'Slug',
				'operator' => 'Operador',
				'estado'   => 'Estado',
				'actions'  => 'Acciones',
			);
		}

		public function get_bulk_actions(): array
		{
			return array(
				'archive'  => 'Archivar',
				'activate' => 'Reactivar',
			);
		}

		public function column_cb( $item ): string
		{
			$slug = sanitize_title( (string) ( $item['project_id'] ?? '' ) );
			return '<label class="screen-reader-text" for="cb-project-' . esc_attr( $slug ) . '">Seleccionar ' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</label>'
				. '<input type="checkbox" id="cb-project-' . esc_attr( $slug ) . '" name="project[]" value="' . esc_attr( $slug ) . '" />';
		}

		public function prepare_items(): void
		{
			$PR = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
			$this->rows = ( $PR !== '' ) ? $PR::manager_list() : array();
			$this->_column_headers = array( $this->get_columns(), array(), array(), 'label' );
			$this->items = $this->rows;
		}

		public function column_label( $item ): string
		{
			return '<strong>' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</strong>';
		}

		public function column_slug( $item ): string
		{
			return '<code>' . esc_html( (string) ( $item['project_id'] ?? '' ) ) . '</code>';
		}

		public function column_operator( $item ): string
		{
			$slug = sanitize_title( (string) ( $item['project_id'] ?? '' ) );
			$names = array();
			if ( class_exists( '\Estandar\Core\Leads\Operators' ) ) {
				foreach ( \Estandar\Core\Leads\Operators::assigned( $slug ) as $ao ) {
					$names[] = trim( (string) ( $ao['name'] ?? '' ) . ( ! empty( $ao['email'] ) ? ' ' . (string) $ao['email'] : '' ) );
				}
			}
			if ( ! $names && ! empty( $item['operator_email'] ) ) {
				$names[] = trim( (string) ( $item['operator_name'] ?? '' ) . ' ' . (string) $item['operator_email'] );
			}
			return esc_html( implode( ' · ', array_filter( $names ) ) );
		}

		public function column_estado( $item ): string
		{
			return ( (int) ( $item['active'] ?? 0 ) === 1 )
				? '<span style="color:#007017;font-weight:600;">Activo</span>'
				: '<span style="color:#8c8f94;">Archivado</span>';
		}

		public function column_actions( $item ): string
		{
			$slug   = sanitize_title( (string) ( $item['project_id'] ?? '' ) );
			$proj   = admin_url( 'admin.php?page=estandar-suite&tab=config&sub=proyectos' );
			$active = ( (int) ( $item['active'] ?? 0 ) === 1 );
			$edit   = '<a href="' . esc_url( add_query_arg( 'project', $slug, $proj ) ) . '">Editar</a>';
			if ( $active ) {
				$toggle = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_suite_core_projects_archive&project=' . $slug ), 'estandar_suite_core_projects_archive' ) ) . '">Archivar</a>';
			} else {
				$toggle = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_suite_core_projects_archive&project=' . $slug . '&activate=1' ), 'estandar_suite_core_projects_archive' ) ) . '">Reactivar</a>';
			}
			$del = '<a href="' . esc_url( add_query_arg( 'delete', $slug, $proj ) ) . '" style="color:#b32d2e;">Eliminar</a>';
			return $edit . ' | ' . $toggle . ' | ' . $del;
		}

		public function no_items(): void
		{
			echo 'Todavía no hay proyectos. Crea el primero con el formulario de la izquierda.';
		}
	}
}
