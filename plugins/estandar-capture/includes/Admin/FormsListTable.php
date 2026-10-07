<?php

namespace Estandar\Capture\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormsListTable — "Lista WP" de formularios (motor nativo WP_List_Table).
 * Checkbox + bulk (papelera/eliminar/restaurar), columnas ordenables, vistas
 * Todos/Papelera y paginación. Se integra en feature-forms-product (tipo lead).
 */
class FormsListTable extends \WP_List_Table
{
	private string $feature;
	private string $type;
	private string $view;
	private array $entry_counts;

	public function __construct( string $feature, string $type, string $view, array $entry_counts = array() )
	{
		$this->feature = $feature;
		$this->type    = $type;
		$this->view    = $view;
		$this->entry_counts = $entry_counts;
		parent::__construct( array(
			'singular' => 'form',
			'plural'   => 'forms',
			'ajax'     => false,
		) );
		// Fuerza el esquema de columnas (evita cabeceras vacías en contextos de
		// página custom donde WP no resuelve el screen).
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	public function get_columns(): array
	{
		return array(
			'cb'       => '<input type="checkbox" />',
			'name'     => 'Formulario',
			'project'  => 'Proyecto',
			'shortcode'=> 'Shortcode',
			'entries'  => 'Entradas',
			'status'   => 'Estado',
			'created'  => 'Creado',
		);
	}

	protected function get_sortable_columns(): array
	{
		return array(
			'name'    => array( 'name', false ),
			'status'  => array( 'status', false ),
			'entries' => array( '_entries', true ),
			'created' => array( 'created_at', false ),
		);
	}

	public function get_bulk_actions(): array
	{
		if ( $this->view === 'trash' ) {
			return array( 'restore' => 'Restaurar', 'delete' => 'Eliminar definitivamente' );
		}
		return array( 'trash' => 'Mover a la papelera', 'delete' => 'Eliminar definitivamente' );
	}

	public function prepare_items(): void
	{
		$per_page = 20;
		$total    = 0;
		$rows     = array();

		$list = \Estandar\Capture\Forms::list();
		if ( $this->view === 'trash' ) {
			$list = \Estandar\Capture\Forms::list_trashed( $this->type );
		}
		$search = isset( $_REQUEST['s'] ) ? trim( (string) wp_unslash( $_REQUEST['s'] ) ) : '';
		foreach ( (array) $list as $f ) {
			if ( (string) ( $f['form_type'] ?? '' ) !== $this->type ) {
				continue;
			}
			// En "Publicados/Todos" se excluye la papelera (WP separa las vistas).
			if ( $this->view !== 'trash' && (string) ( $f['status'] ?? '' ) === 'trashed' ) {
				continue;
			}
			if ( $search !== '' && stripos( (string) $f['name'], $search ) === false ) {
				continue;
			}
			$rows[] = $f;
		}
		$total = count( $rows );

		// Entradas por formulario: igual que el detalle (feature entries/legacy)
		// + submissions nativas v2 si la tabla existe.
		if ( $rows ) {
			$by_id = $this->entry_counts;
			global $wpdb;
			$table = $wpdb->prefix . 'estandar_capture_submissions';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $wpdb->esc_like( $table ) ) . "'" ) ) {
				$w = 'source_form_id > 0';
				if ( $this->type === 'survey' ) {
					// Encuestas: 1 fila por pregunta; contar solo completaciones.
					$w .= " AND fields_json NOT LIKE '%question_index%'";
				}
				$counts = (array) $wpdb->get_results(
					"SELECT source_form_id, COUNT(*) AS n FROM {$table} WHERE {$w} GROUP BY source_form_id",
					ARRAY_A
				);
				foreach ( $counts as $c ) {
					$id = (int) $c['source_form_id'];
					$by_id[ $id ] = (int) ( $by_id[ $id ] ?? 0 ) + (int) $c['n'];
				}
			}
			foreach ( $rows as $k => $f ) {
				$rows[ $k ]['_entries'] = (int) ( $by_id[ (int) $f['id'] ] ?? 0 );
			}
		}

		$orderby = sanitize_key( (string) ( $_REQUEST['orderby'] ?? '' ) );
		$order   = strtoupper( (string) ( $_REQUEST['order'] ?? '' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$sort_map = array( 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at', '_entries' => '_entries' );
		$key = isset( $sort_map[ $orderby ] ) ? $sort_map[ $orderby ] : 'id';
		$numeric = ( $key === '_entries' );
		usort( $rows, function ( $a, $b ) use ( $key, $order, $numeric ) {
			if ( $numeric ) {
				$c = (int) ( $a[ $key ] ?? 0 ) - (int) ( $b[ $key ] ?? 0 );
			} else {
				$c = strnatcasecmp( (string) ( $a[ $key ] ?? '' ), (string) ( $b[ $key ] ?? '' ) );
			}
			return $order === 'ASC' ? $c : -$c;
		} );

		$current = $this->get_pagenum();
		$this->items = array_slice( $rows, ( $current - 1 ) * $per_page, $per_page );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );
	}

	public function process_bulk_action(): void
	{
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'trash', 'restore', 'delete' ), true ) ) {
			return;
		}
		$ids = isset( $_REQUEST['form'] ) ? (array) wp_unslash( $_REQUEST['form'] ) : array();
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			if ( $action === 'trash' ) {
				\Estandar\Capture\Forms::trash( $id );
			} elseif ( $action === 'restore' ) {
				\Estandar\Capture\Forms::restore( $id );
			} elseif ( $action === 'delete' ) {
				\Estandar\Capture\Forms::delete( $id );
			}
		}
	}

	/** Acciones por fila vía GET a nuestra propia página (sin forms anidados). */
	public function process_row_actions(): void
	{
		if ( ! isset( $_GET['waction'], $_GET['id'] ) ) {
			return;
		}
		$act = sanitize_key( (string) $_GET['waction'] );
		if ( ! in_array( $act, array( 'trash', 'restore', 'delete', 'duplicate' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'es_wp_list_action' ) ) {
			return;
		}
		$id = (int) $_GET['id'];
		if ( $act === 'trash' ) {
			\Estandar\Capture\Forms::trash( $id );
		} elseif ( $act === 'restore' ) {
			\Estandar\Capture\Forms::restore( $id );
		} elseif ( $act === 'delete' ) {
			\Estandar\Capture\Forms::delete( $id );
		} elseif ( $act === 'duplicate' ) {
			\Estandar\Capture\Forms::duplicate( $id );
		}
	}

	/** URL base de la lista según producto (forms usa su línea interna fm). */
	private function self_base(): string
	{
		$q = 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $this->feature;
		if ( $this->feature === 'forms' ) {
			$q .= '&fm=formularios';
		}
		return admin_url( $q );
	}

	private function self_action_url( string $act, int $id ): string
	{
		$url = $this->self_base() . ( $this->view === 'trash' ? '&v=trash' : '' ) . '&waction=' . $act . '&id=' . $id;
		return wp_nonce_url( $url, 'es_wp_list_action' );
	}

	public function column_cb( $item ): string
	{
		return sprintf( '<input type="checkbox" name="form[]" value="%d" />', (int) $item['id'] );
	}

	public function column_name( $item ): string
	{
		$fid     = (int) $item['id'];
		$feature = $this->feature;
		$url     = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&fm=formularios' );

		if ( $this->view === 'trash' ) {
			$title  = esc_html( (string) $item['name'] );
			$action = array(
				'restore' => '<a href="' . esc_url( $this->self_action_url( 'restore', $fid ) ) . '">Restaurar</a>',
				'migrar'  => '<a href="' . esc_url( $url . '&eliminar=' . $fid ) . '">Consolidar/migrar páginas a otro form</a>',
				'delete'  => '<a href="' . esc_url( $this->self_action_url( 'delete', $fid ) ) . '" class="submitdelete" onclick="return confirm(\'¿Eliminar definitivamente?\')">Eliminar definitivamente</a>',
			);
			return $title . $this->row_actions( $action );
		}

		$title  = '<strong><a href="' . esc_url( $url . '&form=' . $fid ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong>';
		$action = array(
			'edit'      => '<a href="' . esc_url( $url . '&editar=' . $fid ) . '">Editar</a>',
			'view'      => '<a href="' . esc_url( $url . '&form=' . $fid ) . '">Ver</a>',
			'duplicar'  => '<a href="' . esc_url( $this->self_action_url( 'duplicate', $fid ) ) . '">Duplicar</a>',
			'connect'   => '<a href="' . esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=integraciones&form_source=v2&form_id=' . $fid ) ) . '">Conectar a…</a>',
			'trash'     => '<a href="' . esc_url( $url . '&eliminar=' . $fid ) . '" class="submitdelete">Eliminar</a>',
		);
		return $title . $this->row_actions( $action );
	}

	public function column_shortcode( $item ): string
	{
		return \Estandar\Core\Embed::copy_control( \Estandar\Core\Embed::shortcode( 'form', (int) $item['id'] ) );
	}

	/** Proyecto al que pertenece el form (form_project / routing fixed_project). */
	public function column_project( $item ): string
	{
		$config = json_decode( (string) ( $item['config_json'] ?? '' ), true );
		$config = is_array( $config ) ? $config : array();
		$slug   = \Estandar\Capture\Forms::form_project( $config );
		$label  = sanitize_text_field( (string) ( $config['form_project_area'] ?? '' ) );
		if ( $slug === '' ) {
			$ctx = (string) ( $config['context_schema'] ?? '' );
			if ( $ctx !== '' ) {
				$schema  = json_decode( $ctx, true );
				$routing = ( is_array( $schema ) && is_array( $schema['routing'] ?? null ) ) ? $schema['routing'] : array();
				if ( (string) ( $routing['mode'] ?? '' ) === 'fixed_project' ) {
					$slug = sanitize_title( (string) ( $routing['project_slug'] ?? '' ) );
				}
			}
		}
		if ( $slug === '' ) {
			return '<span class="description">—</span>';
		}
		if ( $label === '' && class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' )
			&& method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'label_for' ) ) {
			$label = (string) \Estandar\Core\Leads\ProjectRegistry::label_for( $slug );
		}
		if ( $label !== '' ) {
			return esc_html( $label );
		}
		return '<code>' . esc_html( $slug ) . '</code>';
	}

	public function column_entries( $item ): string
	{
		return (string) (int) ( $item['_entries'] ?? 0 );
	}

	public function column_status( $item ): string
	{
		return esc_html( (string) ( $item['status'] ?? '' ) );
	}

	public function column_created( $item ): string
	{
		return esc_html( (string) ( $item['created_at'] ?? '' ) );
	}

	public function column_default( $item, $column_name ): string
	{
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/** Vistas estilo WordPress: Publicados · Papelera (subsubsub). */
	public function views(): void
	{
		$type = $this->type;
		$pub = count( array_filter( \Estandar\Capture\Forms::list(), static function ( $f ) use ( $type ) {
			return (string) $f['form_type'] === $type && (string) $f['status'] !== 'trashed';
		} ) );
		$trash = count( \Estandar\Capture\Forms::list_trashed( $this->type ) );
		$base  = $this->self_base();

		$items = array(
			'published' => array( 'label' => 'Publicados', 'count' => $pub, 'href' => $base, 'active' => $this->view !== 'trash' ),
			'trash'     => array( 'label' => 'Papelera', 'count' => $trash, 'href' => $base . '&v=trash', 'active' => $this->view === 'trash' ),
		);
		echo '<ul class="subsubsub">';
		$out = array();
		foreach ( $items as $it ) {
			$cls = $it['active'] ? ' class="current"' : '';
			$out[] = '<li><a href="' . esc_url( $it['href'] ) . '"' . $cls . '>' . esc_html( $it['label'] ) . ' <span class="count">(' . (int) $it['count'] . ')</span></a></li>';
		}
		echo implode( ' | ', $out );
		echo '</ul>';
	}
}
