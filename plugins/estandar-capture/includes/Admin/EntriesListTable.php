<?php

namespace Estandar\Capture\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EntriesListTable — Lista WP de cada entrada de Formularios (tipo lead),
 * filtrable por formulario. Solo entries de forms lead (excluye surveys/
 * postventa/chatbot). Fuente: estandar_capture_submissions (source_form_id).
 */
class EntriesListTable extends \WP_List_Table
{
	private array $form_names = array();
	private string $type;
	private string $feature;

	public function __construct( string $type = 'lead', string $feature = 'forms' )
	{
		$this->type    = $type;
		$this->feature = $feature;
		parent::__construct( array(
			'singular' => 'entry',
			'plural'   => 'entries',
			'ajax'     => false,
		) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
		foreach ( (array) \Estandar\Capture\Forms::list() as $f ) {
			if ( (string) ( $f['form_type'] ?? '' ) === $this->type && (string) ( $f['status'] ?? '' ) !== 'trashed' ) {
				$this->form_names[ (int) $f['id'] ] = (string) $f['name'];
			}
		}
	}

	public function get_columns(): array
	{
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => 'Fecha',
			'form'       => 'Formulario',
			'name'       => 'Nombre',
			'email'      => 'Email',
			'phone'      => 'Teléfono',
			'status'     => 'Estado',
		);
	}

	public function get_bulk_actions(): array
	{
		return array(
			'trash'  => 'Mover a la papelera',
			'delete' => 'Eliminar definitivamente',
		);
	}

	public function column_cb( $item ): string
	{
		return sprintf( '<input type="checkbox" name="entry[]" value="%d" />', (int) $item['id'] );
	}

	/** Acciones en masa (papelera / eliminar) — se ejecuta antes de prepare. */
	public function process_bulk_action(): void
	{
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'trash', 'delete' ), true ) ) {
			return;
		}
		$ids = isset( $_POST['entry'] ) ? (array) wp_unslash( $_POST['entry'] ) : array();
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'estandar_capture_submissions';
		foreach ( $ids as $id ) {
			if ( $action === 'delete' ) {
				\Estandar\Capture\Forms::delete_submission( $id );
			} else {
				$wpdb->update( $table, array( 'status' => 'trashed' ), array( 'id' => $id ) );
			}
		}
	}

	protected function get_sortable_columns(): array
	{
		return array(
			'created_at' => array( 'created_at', true ),
			'name'       => array( 'name', false ),
			'email'      => array( 'email_normalized', false ),
		);
	}

	public function prepare_items(): void
	{
		global $wpdb;
		$per_page = 20;
		$table    = $wpdb->prefix . 'estandar_capture_submissions';
		$ids      = array_keys( $this->form_names );
		$where    = array( 'source_form_id > 0', "status <> 'trashed'" );
		if ( $this->type === 'survey' ) {
			// Encuestas: submissions guardan UNA fila por respuesta/pregunta.
			// La "entrada" real es la fila de completación (sin question_index).
			$where[] = "fields_json NOT LIKE '%question_index%'";
		}
		if ( $ids ) {
			$in = implode( ',', array_map( 'absint', $ids ) );
			$where[] = 'source_form_id IN (' . $in . ')';
		} else {
			$where[] = '1=0';
		}
		$filter_form = isset( $_GET['fform'] ) ? (int) $_GET['fform'] : 0;
		if ( $filter_form && isset( $this->form_names[ $filter_form ] ) ) {
			$where[] = 'source_form_id = ' . $filter_form;
		}
		$s = isset( $_REQUEST['s'] ) ? trim( (string) wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( $s !== '' ) {
			$like = '%' . $wpdb->esc_like( $s ) . '%';
			$where[] = $wpdb->prepare( '( name LIKE %s OR email_normalized LIKE %s )', $like, $like );
		}

		$orderby = sanitize_key( (string) ( $_REQUEST['orderby'] ?? '' ) );
		$order   = strtoupper( (string) ( $_REQUEST['order'] ?? '' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$sort_map = array( 'created_at' => 'created_at', 'name' => 'name', 'email_normalized' => 'email_normalized' );
		$col = isset( $sort_map[ $orderby ] ) ? $sort_map[ $orderby ] : 'created_at';

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) );
		$current = $this->get_pagenum();
		$offset  = ( $current - 1 ) * $per_page;
		$rows = (array) $wpdb->get_results(
			'SELECT id, source_form_id, name, email_normalized, phone_normalized, status, created_at, fields_json FROM ' . $table
			. ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY ' . $col . ' ' . $order
			. ' LIMIT ' . $per_page . ' OFFSET ' . $offset,
			ARRAY_A
		);
		$this->items = $rows;
		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );
	}

	private function json_field( array $item, string $kind ): string
	{
		$raw = (string) ( $item['fields_json'] ?? '' );
		if ( $raw === '' ) {
			return '';
		}
		$d = json_decode( $raw, true );
		if ( ! is_array( $d ) ) {
			return '';
		}
		$pools = array(
			'name'  => array( 'Nombre', 'nombre', 'name', 'Nombres', 'nombres' ),
			'email' => array( 'Email', 'email', 'Correo', 'Correo electrónico', 'correo' ),
			'phone' => array( 'Teléfono', 'Telefono', 'teléfono', 'telefono', 'Celular', 'celular', 'phone' ),
		);
		foreach ( $pools[ $kind ] as $key ) {
			if ( isset( $d[ $key ] ) && is_scalar( $d[ $key ] ) && trim( (string) $d[ $key ] ) !== '' ) {
				return trim( (string) $d[ $key ] );
			}
		}
		return '';
	}

	public function column_name( $item ): string
	{
		$title = (string) ( $item['name'] ?? '' );
		if ( $title === '' ) {
			$title = $this->json_field( $item, 'name' );
		}
		$text = esc_html( $title !== '' ? $title : '—' );
		$id   = (int) $item['id'];
		$action = array(
			'trash'  => '<a href="' . esc_url( $this->self_action_url( 'trash', $id ) ) . '" onclick="return confirm(\'¿Mover a la papelera?\')">Papelera</a>',
			'delete' => '<a href="' . esc_url( $this->self_action_url( 'delete', $id ) ) . '" class="submitdelete" onclick="return confirm(\'¿Eliminar definitivamente?\')">Eliminar</a>',
		);
		return $text . $this->row_actions( $action );
	}

	public function column_email( $item ): string
	{
		$e = (string) ( $item['email_normalized'] ?? '' );
		if ( $e === '' ) {
			$e = $this->json_field( $item, 'email' );
		}
		return $e !== '' ? '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>' : '—';
	}

	public function column_phone( $item ): string
	{
		$p = (string) ( $item['phone_normalized'] ?? '' );
		if ( $p === '' ) {
			$p = $this->json_field( $item, 'phone' );
		}
		return esc_html( $p !== '' ? $p : '—' );
	}
	public function process_row_actions(): void
	{
		if ( ! isset( $_GET['waction'], $_GET['id'] ) ) {
			return;
		}
		$act = sanitize_key( (string) $_GET['waction'] );
		if ( ! in_array( $act, array( 'trash', 'delete' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'es_wp_list_action' ) ) {
			return;
		}
		$id = (int) $_GET['id'];
		if ( $act === 'delete' ) {
			\Estandar\Capture\Forms::delete_submission( $id );
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'estandar_capture_submissions', array( 'status' => 'trashed' ), array( 'id' => $id ) );
	}

	private function self_action_url( string $act, int $id ): string
	{
		$url = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $this->feature . '&fm=entradas&waction=' . $act . '&id=' . $id );
		return wp_nonce_url( $url, 'es_wp_list_action' );
	}

	public function column_created_at( $item ): string
	{
		return esc_html( (string) ( $item['created_at'] ?? '' ) );
	}

	public function column_form( $item ): string
	{
		$id = (int) ( $item['source_form_id'] ?? 0 );
		return esc_html( $this->form_names[ $id ] ?? ( '#' . $id ) );
	}

	public function column_status( $item ): string
	{
		return esc_html( (string) ( $item['status'] ?? '' ) );
	}

	public function column_default( $item, $column_name ): string
	{
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
