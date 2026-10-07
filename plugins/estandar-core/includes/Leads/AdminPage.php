<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AdminPage — versión de backend (wp-admin) del Panel de Leads.
 *
 * La vitrina pública /panel-leads/ queda intacta (mismo código, no se toca).
 * Esta página reutiliza los MISMOS datos y semántica del panel (LeadsPanel)
 * dentro del admin, para que un admin vea la información actual de los leads
 * sin salir de wp-admin:
 *   - admin.php?page=estandar-suite-leads           → lista de leads (filtros)
 *   - ...&lead=ID                                   → ficha del pipeline del lead
 *   - ...&lead=ID&contact=whatsapp|email|call       → registra clic y redirige
 *   - ...&lead=ID&feedback=1                        → crea token de calificación
 *   - ...&ai_context=ID                             → export markdown IA Context
 */
class AdminPage {

	const SLUG = 'estandar-suite-leads';

	public static function init(): void {
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		// La vista de leads ahora vive dentro de Core → Config → Leads → Vista
		// (render_embedded). Se registra la página como oculta (sin item en el
		// sidebar) para que las URLs históricas /estandar-suite-leads sigan
		// funcionando sin duplicar la navegación.
		add_submenu_page(
			null,
			'Leads',
			'Leads',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}

		// IA Context: export markdown (misma salida que la vitrina pública).
		$ai_id = (int) ( $_GET['ai_context'] ?? 0 );
		if ( $ai_id > 0 ) {
			self::render_ai_context( $ai_id );
			return;
		}

		$lead_id = (int) ( $_GET['lead'] ?? 0 );

		// Acciones de la ficha: registrar clic de contacto / crear token de calificación.
		if ( isset( $_GET['contact'] ) ) {
			\Estandar\Core\LeadsPanel::handle_contact_action( $lead_id, (string) $_GET['contact'] );
		}
		if ( isset( $_GET['feedback'] ) ) {
			\Estandar\Core\LeadsPanel::handle_feedback_action( $lead_id );
		}

		echo '<div class="wrap">';
		if ( $lead_id > 0 ) {
			echo self::render_ficha( $lead_id );
		} else {
			echo self::render_list();
		}
		echo '</div>';
	}

	/** Base URL de la vista embebida (Core → Config → Leads → Vista). */
	const EMBED_BASE = 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=vista';

	/**
	 * Vista embebida (dentro de Core → Config → Leads). Sin <div class="wrap">
	 * (el contenedor lo da la página Core) y con su propia base de enlaces.
	 */
	public static function render_embedded(): string {
		// IA Context: en la vista embebida se muestra inline (markdown para
		// copiar). La exportación .md como archivo vive en la página standalone.
		$ai_id = (int) ( $_GET['ai_context'] ?? 0 );
		if ( $ai_id > 0 ) {
			return self::render_ai_context_embedded( $ai_id );
		}
		$lead_id = (int) ( $_GET['lead'] ?? 0 );
		if ( isset( $_GET['contact'] ) ) {
			\Estandar\Core\LeadsPanel::handle_contact_action( $lead_id, (string) $_GET['contact'] );
		}
		if ( isset( $_GET['feedback'] ) ) {
			\Estandar\Core\LeadsPanel::handle_feedback_action( $lead_id );
		}
		return $lead_id > 0 ? self::render_ficha( $lead_id ) : self::render_list();
	}

	/** Export markdown del IA Context (text/markdown). */
	private static function render_ai_context( int $lead_id ): void {
		$md = AiContext::build_for_lead( $lead_id );
		if ( $md === '' ) {
			wp_die( 'Lead no encontrado.' );
		}
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="lead-' . $lead_id . '-context.md"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $md;
		exit;
	}

	/**
	 * IA Context inline para la vista embebida (Core → Config → Leads → Vista):
	 * el markdown se muestra en un <textarea> readonly para copiar, con botón
	 * de descarga .md a la página standalone (que emite el archivo real).
	 */
	private static function render_ai_context_embedded( int $lead_id ): string {
		$md = AiContext::build_for_lead( $lead_id );
		if ( $md === '' ) {
			return '<div class="notice notice-error"><p>Lead #' . (int) $lead_id . ' no encontrado.</p></div>';
		}
		$download = admin_url( 'admin.php?page=' . self::SLUG . '&ai_context=' . (int) $lead_id );
		return '<h1>IA Context — Lead #' . (int) $lead_id . '</h1>'
			. '<p><a class="button" href="' . esc_url( admin_url( self::EMBED_BASE ) ) . '">← Volver a la lista</a> '
			. '<a class="button button-secondary" download href="' . esc_url( $download ) . '">Descargar .md</a></p>'
			. '<textarea readonly class="large-text" style="min-height:420px;font-family:Consolas,Monaco,monospace;font-size:12px;" onclick="this.select()">'
			. esc_textarea( $md )
			. '</textarea>';
	}

	/** Lista de leads (mismos datos que la vitrina pública). */
	public static function render_list(): string {
		$filters = array(
			'from'     => sanitize_text_field( (string) ( $_GET['from'] ?? '' ) ),
			'to'       => sanitize_text_field( (string) ( $_GET['to'] ?? '' ) ),
			'project'  => sanitize_text_field( (string) ( $_GET['project'] ?? '' ) ),
			'operator' => sanitize_text_field( (string) ( $_GET['operator'] ?? '' ) ),
			'name'     => sanitize_text_field( (string) ( $_GET['name'] ?? '' ) ),
			'orderby'  => sanitize_key( (string) ( $_GET['orderby'] ?? 'created_at' ) ),
			'order'    => strtolower( (string) ( $_GET['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
		);
		$rows  = \Estandar\Core\LeadsPanel::operator_leads( $filters, 200, 0 );
		$total = \Estandar\Core\LeadsPanel::count_operator_leads( $filters );

		$self = admin_url( self::EMBED_BASE );
		$fq   = static function ( array $extra = array() ) use ( $self, $filters ) {
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'operator', 'name', 'orderby', 'order' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) {
					$qs[ $k ] = (string) $filters[ $k ];
				}
			}
			return add_query_arg( array_merge( $qs, $extra ), $self );
		};

		$order_link = static function ( string $label, string $key ) use ( $filters, $fq ) {
			$next = ( $filters['orderby'] === $key && $filters['order'] === 'desc' ) ? 'asc' : 'desc';
			$arrow = $filters['orderby'] === $key ? ( $filters['order'] === 'desc' ? ' ↓' : ' ↑' ) : '';
			return '<a href="' . esc_url( $fq( array( 'orderby' => $key, 'order' => $next ) ) ) . '">' . esc_html( $label ) . '<span style="color:#f95b1f;">' . $arrow . '</span></a>';
		};

		$project_opts = '';
		foreach ( \Estandar\Core\LeadsPanel::project_options() as $po ) {
			$project_opts .= '<option value="' . esc_attr( $po ) . '"' . selected( (string) $filters['project'], $po, false ) . '>' . esc_html( $po ) . '</option>';
		}
		$operator_opts = '';
		foreach ( \Estandar\Core\LeadsPanel::operator_options() as $oo ) {
			$operator_opts .= '<option value="' . esc_attr( $oo ) . '"' . selected( (string) $filters['operator'], $oo, false ) . '>' . esc_html( $oo ) . '</option>';
		}

		$rows_html = '';
		foreach ( $rows as $r ) {
			$ficha = $fq( array( 'lead' => (int) $r['id'] ) );
			$ai    = $fq( array( 'ai_context' => (int) $r['id'] ) );
			$rows_html .= '<tr>'
				. '<td><a href="' . esc_url( $ficha ) . '">#' . (int) $r['id'] . '</a></td>'
				. '<td>' . esc_html( (string) $r['created_at'] ) . '</td>'
				. '<td>' . \Estandar\Core\LeadsPanel::recontact_badge( (int) $r['recontacts'] ) . '</td>'
				. '<td><strong><a href="' . esc_url( $ficha ) . '">' . esc_html( (string) $r['name'] ) . '</a></strong></td>'
				. '<td>' . ( ! empty( $r['email'] ) ? '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( (string) $r['email'] ) . '</a>' : '<span class="description">—</span>' ) . '</td>'
				. '<td>' . esc_html( (string) $r['phone'] ) . '</td>'
				. '<td>' . ( ! empty( $r['project'] ) ? esc_html( (string) $r['project'] ) : '<span class="description">—</span>' ) . '</td>'
				. '<td>' . esc_html( (string) $r['operator'] ) . '</td>'
				. '<td>' . \Estandar\Core\LeadsPanel::status_badge( (string) $r['status'] ) . '</td>'
				. '<td class="nowrap"><a class="button button-small" href="' . esc_url( $ficha ) . '">Ficha</a> '
				. '<a class="button button-small" href="' . esc_url( $ai ) . '">IA Context</a></td>'
				. '</tr>';
		}
		if ( $rows_html === '' ) {
			$rows_html = '<tr><td colspan="10">Sin leads para los filtros.</td></tr>';
		}

		$out  = '<h1>Leads <span class="page-title-action" style="background:transparent;border:none;color:#646970;font-weight:400;">' . (int) $total . ' con filtros · vitrina pública intacta en /panel-leads/</span></h1>';
		$out .= '<form method="get" class="leads-filters" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:12px 0;">'
			. '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">'
			. '<label>Nombre<input type="text" name="name" value="' . esc_attr( (string) $filters['name'] ) . '"></label>'
			. '<label>Proyecto<select name="project"><option value="">Todos</option>' . $project_opts . '</select></label>'
			. '<label>Operador<select name="operator"><option value="">Todos</option>' . $operator_opts . '</select></label>'
			. '<label>Desde<input type="date" name="from" value="' . esc_attr( (string) $filters['from'] ) . '"></label>'
			. '<label>Hasta<input type="date" name="to" value="' . esc_attr( (string) $filters['to'] ) . '"></label>'
			. '<button type="submit" class="button button-primary">Filtrar</button> '
			. '<a class="button" href="' . esc_url( $self ) . '">Limpiar</a></form>';

		$out .= '<table class="widefat striped"><thead><tr>'
			. '<th>' . $order_link( 'ID', 'id' ) . '</th>'
			. '<th>' . $order_link( 'Fecha', 'created_at' ) . '</th>'
			. '<th>Recontactos</th>'
			. '<th>' . $order_link( 'Nombre', 'name' ) . '</th>'
			. '<th>Email</th>'
			. '<th>Teléfono</th>'
			. '<th>' . $order_link( 'Proyecto', 'project' ) . '</th>'
			. '<th>Operador</th>'
			. '<th>Estado</th>'
			. '<th>Acción</th>'
			. '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
			. '<p class="description">Se muestran hasta 200 leads (los más recientes). Usa los filtros para acotar.</p>';
		return $out;
	}

	/** Ficha del pipeline de un lead (misma información que la vitrina pública). */
	public static function render_ficha( int $lead_id ): string {
		$p = \Estandar\Core\LeadsPanel::lead_pipeline( $lead_id );
		if ( empty( $p ) ) {
			return '<div class="notice notice-error"><p>Lead #' . (int) $lead_id . ' no encontrado.</p></div>';
		}
		$lead = (array) $p['lead'];
		$back = admin_url( self::EMBED_BASE );

		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$dt  = static function ( string $v ): string {
			return $v !== '' ? esc_html( $v ) : '—';
		};

		// Alertas (falta / pendiente).
		$alerts = '';
		foreach ( (array) $p['steps'] as $st ) {
			if ( in_array( $st['status'], array( 'missing', 'pending' ), true ) ) {
				$alerts .= '<li>' . $esc( (string) $st['label'] ) . ' · ' . $esc( (string) $st['detail'] ) . '</li>';
			}
		}
		$alerts_html = $alerts !== ''
			? '<div class="notice notice-warning"><ul style="margin:6px 0;">' . $alerts . '</ul></div>'
			: '<div class="notice notice-success"><p>Todo el pipeline está cubierto.</p></div>';

		// Pasos del pipeline.
		$tags = array( 'done' => 'Hecho', 'pending' => 'Pendiente', 'missing' => 'Falta', 'skip' => 'No usado' );
		$steps_html = '<table class="widefat striped"><tbody>';
		foreach ( (array) $p['steps'] as $st ) {
			$steps_html .= '<tr><td style="width:220px;"><strong>' . $esc( (string) $st['label'] ) . '</strong></td>'
				. '<td>' . $esc( (string) $st['detail'] ) . '</td>'
				. '<td style="width:90px;text-align:right;"><span class="button button-small" style="' . ( $st['status'] === 'done' ? 'background:#00a32a;border-color:#00a32a;color:#fff;' : ( $st['status'] === 'missing' ? 'background:#b32d2e;border-color:#b32d2e;color:#fff;' : '' ) ) . '">' . esc_html( (string) ( $tags[ $st['status'] ] ?? $st['status'] ) ) . '</span></td></tr>';
		}
		$steps_html .= '</tbody></table>';

		// Timeline de eventos.
		$timeline = '';
		foreach ( (array) $p['events'] as $ev ) {
			$label = \Estandar\Core\LeadsPanel::pipeline_event_label( (string) $ev['event_type'] );
			if ( (string) $ev['event_type'] === 'autoresponse_email_failed' ) {
				$rs = \Estandar\Core\LeadsPanel::autoresponse_failure_reason( array( $ev ) );
				if ( $rs !== '' ) {
					$label .= ' · ' . $rs;
				}
			}
			$timeline .= '<li><code>' . $dt( (string) ( $ev['created_at'] ?? '' ) ) . '</code> — ' . $esc( $label ) . '</li>';
		}
		$timeline_html = $timeline !== '' ? '<ul class="ul-square">' . $timeline . '</ul>' : '<p class="description">Sin eventos registrados.</p>';

		// Contactos registrados.
		$contacts_html = '';
		foreach ( (array) $p['contacts'] as $c ) {
			$contacts_html .= '<li>' . $dt( (string) ( $c['contacted_at'] ?? '' ) ) . ' — <strong>' . $esc( (string) ( $c['channel'] ?? '' ) ) . '</strong> · ' . $esc( (string) ( $c['outcome'] ?? '' ) ) . ( (string) ( $c['notes'] ?? '' ) !== '' ? ' · ' . $esc( (string) $c['notes'] ) : '' ) . '</li>';
		}
		$contacts_html = $contacts_html !== '' ? '<ul class="ul-square">' . $contacts_html . '</ul>' : '<p class="description">Sin contactos registrados.</p>';

		// Submissions.
		$subs_html = '';
		foreach ( (array) $p['submissions'] as $s ) {
			$subs_html .= '<li>#' . (int) $s['id'] . ' · ' . $dt( (string) ( $s['submitted_at'] ?? '' ) ) . ' · ' . $esc( (string) ( $s['utm_source'] ?? '' ) ) . ( (string) ( $s['utm_campaign'] ?? '' ) !== '' ? ' · campaña ' . $esc( (string) $s['utm_campaign'] ) : '' ) . '</li>';
		}
		$subs_html = $subs_html !== '' ? '<ul class="ul-square">' . $subs_html . '</ul>' : '<p class="description">Sin submissions.</p>';

		// Meta del lead.
		$meta = array(
			'Estado'           => (string) ( $lead['status'] ?? '' ),
			'Temperatura'      => (string) ( $lead['temperature'] ?? '' ),
			'Prioridad'        => (string) ( $lead['priority'] ?? '' ),
			'Calidad'          => (string) ( $lead['quality'] ?? '' ),
			'Operador'         => (string) ( $lead['assigned_operator_name'] ?? '' ),
			'Proyecto'         => (string) ( $lead['project_slug'] ?? '' ),
			'Intent'           => (string) ( $lead['lead_intent'] ?? '' ),
			'Score'            => (string) ( $lead['behavioral_score'] ?? '' ),
			'Primera captura'  => (string) ( $lead['first_seen_at'] ?? '' ),
			'Última actividad' => (string) ( $lead['last_seen_at'] ?? '' ),
		);
		$meta_html = '<table class="widefat striped"><tbody>';
		foreach ( $meta as $k => $v ) {
			$meta_html .= '<tr><td style="width:220px;"><strong>' . esc_html( $k ) . '</strong></td><td>' . $dt( $v ) . '</td></tr>';
		}
		$meta_html .= '</tbody></table>';

		// Acciones (misma semántica que la vitrina pública; reutiliza los handlers).
		$ficha = admin_url( self::EMBED_BASE . '&lead=' . $lead_id );
		$phone = (string) ( $lead['phone'] ?? '' );
		$email = (string) ( $lead['email'] ?? '' );
		$actions = '<p>';
		if ( $phone !== '' ) {
			$actions .= '<a class="button button-primary" href="' . esc_url( add_query_arg( 'contact', 'whatsapp', $ficha ) ) . '">Contactar (WhatsApp)</a> ';
			$actions .= '<a class="button" href="' . esc_url( add_query_arg( 'contact', 'call', $ficha ) ) . '">Llamar</a> ';
		}
		if ( $email !== '' ) {
			$actions .= '<a class="button" href="' . esc_url( add_query_arg( 'contact', 'email', $ficha ) ) . '">Enviar email</a> ';
		}
		$actions .= '<a class="button" href="' . esc_url( add_query_arg( 'feedback', '1', $ficha ) ) . '">Calificar calidad</a> ';
		$actions .= '<a class="button button-secondary" href="' . esc_url( add_query_arg( 'ai_context', $lead_id, admin_url( self::EMBED_BASE ) ) ) . '">IA Context</a></p>';

		$chat = (array) $p['chatbot'];
		$chat_html = ( (int) ( $chat['messages'] ?? 0 ) > 0 || (int) ( $chat['conversations'] ?? 0 ) > 0 )
			? esc_html( (int) $chat['messages'] . ' mensajes · ' . (int) $chat['conversations'] . ' conversación(es)' )
			: 'No usó el chatbot';

		return '<h1>Lead #' . (int) $lead_id . ' — ' . $esc( (string) ( $lead['name'] ?? '' ) ) . '</h1>'
			. '<p><a class="button" href="' . esc_url( $back ) . '">← Volver a la lista</a> ' . $actions . '</p>'
			. $alerts_html
			. '<h2 class="title">Pipeline</h2>' . $steps_html
			. '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">'
			. '<div><h2 class="title">Datos del lead</h2>' . $meta_html . '</div>'
			. '<div><h2 class="title">Timeline de eventos</h2>' . $timeline_html
			. '<h2 class="title">Contactos</h2>' . $contacts_html
			. '<h2 class="title">Chatbot</h2><p>' . $chat_html . '</p></div>'
			. '</div>'
			. '<h2 class="title">Formularios enviados</h2>' . $subs_html
			. '<p class="description">Email: ' . $dt( (string) ( $lead['email'] ?? '' ) ) . ' · Teléfono: ' . $dt( (string) ( $lead['phone'] ?? '' ) ) . '</p>';
	}
}
