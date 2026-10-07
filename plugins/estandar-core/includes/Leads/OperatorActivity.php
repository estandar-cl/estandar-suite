<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OperatorActivity — Panel de trazabilidad y uso por operador (OPERATOR_ACTIVITY_V1).
 *
 * Consolida la actividad de cada operador registrada en es_lead_events
 * (operator_notified / operator_email_opened / operator_feedback_* /
 * lead_list_opened / workspace_opened / lead_viewed / lead_whatsapp_opened…),
 * es_lead_contacts (canales de contacto) y es_leads (asignación), en una vista
 * de gestión accesible solo para administradores (wp-admin, manage_options).
 *
 * Uso: menú Estandar Suite → "Actividad de operadores".
 */
class OperatorActivity
{
	const SLUG = 'estandar-suite-operator-activity';
	const DEFAULT_RANGE_DAYS = 30;

	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'register_admin' ), 20 );
	}

	public static function register_admin(): void
	{
		\add_submenu_page(
			'estandar-suite',
			'Actividad de operadores',
			'Actividad de operadores',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/** Ventana de tiempo en segundos (0 = todo). */
	private static function range_seconds(): int
	{
		$days = (int) ( $_GET['range'] ?? self::DEFAULT_RANGE_DAYS );
		$days = in_array( $days, array( 7, 30, 90, 0 ), true ) ? $days : self::DEFAULT_RANGE_DAYS;
		return $days > 0 ? $days * DAY_IN_SECONDS : 0;
	}

	/** Desde (zona WP) según rango. */
	private static function since(): string
	{
		$secs = self::range_seconds();
		return $secs > 0 ? \Estandar\Core\Time::from_epoch( time() - $secs ) : '1970-01-01 00:00:00';
	}

	/**
	 * Estadísticas agregadas por operador.
	 * @return array[] clave operator_id → fila con métricas.
	 */
	public static function per_operator(): array
	{
		global $wpdb;
		$since = self::since();

		$ops = (array) $wpdb->get_results(
			"SELECT id, name, email, project_slug, whatsapp_number, active
			 FROM {$wpdb->prefix}es_operators ORDER BY name ASC",
			ARRAY_A
		);

		$out = array();
		foreach ( $ops as $op ) {
			$out[ (int) $op['id'] ] = array(
				'id'                 => (int) $op['id'],
				'name'               => (string) $op['name'],
				'email'              => (string) $op['email'],
				'project'            => (string) $op['project_slug'],
				'whatsapp'           => (string) $op['whatsapp_number'],
				'active'             => (int) $op['active'],
				'assigned'           => 0,
				'notified'           => 0,
				'opened'             => 0,
				'deferred'           => 0,
				'digest'             => 0,
				'feedback_started'   => 0,
				'feedback_completed' => 0,
				'contacts'           => array( 'whatsapp' => 0, 'call' => 0, 'email' => 0, 'other' => 0 ),
				'whatsapp_opens'     => 0,
				'panel_views'        => 0,
				'ficha_views'        => 0,
				'last_activity'      => '',
			);
		}

		// 1) Asignación de leads.
		$assign = (array) $wpdb->get_results(
			"SELECT assigned_operator_id AS oid, COUNT(*) AS n FROM {$wpdb->prefix}es_leads
			 WHERE assigned_operator_id IS NOT NULL AND assigned_operator_id <> 0 GROUP BY assigned_operator_id",
			ARRAY_A
		);
		foreach ( $assign as $a ) {
			if ( isset( $out[ (int) $a['oid'] ] ) ) {
				$out[ (int) $a['oid'] ]['assigned'] = (int) $a['n'];
			}
		}

		// 2) Eventos por operador + tipo (agregados en SQL).
		$events = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT operator_id AS oid, event_type AS et, COUNT(*) AS n, MAX(created_at) AS last
			 FROM {$wpdb->prefix}es_lead_events
			 WHERE operator_id IS NOT NULL AND operator_id <> 0 AND created_at >= %s
			 GROUP BY operator_id, event_type",
			$since
		), ARRAY_A );

		$panel_kinds = array( 'workspace_opened', 'lead_list_opened' );
		foreach ( $events as $e ) {
			$oid = (int) $e['oid'];
			if ( ! isset( $out[ $oid ] ) ) {
				continue;
			}
			$et = (string) $e['et'];
			$n  = (int) $e['n'];
			switch ( $et ) {
				case 'operator_notified':
					$out[ $oid ]['notified'] += $n;
					break;
				case 'operator_email_opened':
					$out[ $oid ]['opened'] += $n;
					break;
				case 'operator_notification_deferred':
					$out[ $oid ]['deferred'] += $n;
					break;
				case 'operator_daily_digest_sent':
					$out[ $oid ]['digest'] += $n;
					break;
				case 'operator_feedback_started':
					$out[ $oid ]['feedback_started'] += $n;
					break;
				case 'operator_feedback_completed':
					$out[ $oid ]['feedback_completed'] += $n;
					break;
				case 'lead_whatsapp_opened':
				case 'operator_clicked_whatsapp':
					$out[ $oid ]['whatsapp_opens'] += $n;
					break;
				case 'lead_viewed':
					$out[ $oid ]['ficha_views'] += $n;
					break;
			}
			if ( in_array( $et, $panel_kinds, true ) ) {
				$out[ $oid ]['panel_views'] += $n;
			}
			if ( ! empty( $e['last'] ) && $e['last'] > $out[ $oid ]['last_activity'] ) {
				$out[ $oid ]['last_activity'] = (string) $e['last'];
			}
		}

		// 3) Contactos por operador + canal.
		$contacts = (array) $wpdb->get_results(
			"SELECT operator_id AS oid, channel AS ch, COUNT(*) AS n FROM {$wpdb->prefix}es_lead_contacts
			 WHERE operator_id IS NOT NULL AND operator_id <> 0
			 GROUP BY operator_id, channel",
			ARRAY_A
		);
		foreach ( $contacts as $c ) {
			$oid = (int) $c['oid'];
			if ( ! isset( $out[ $oid ] ) ) {
				continue;
			}
			$ch = strtolower( (string) $c['ch'] );
			if ( ! isset( $out[ $oid ]['contacts'][ $ch ] ) ) {
				$ch = 'other';
			}
			$out[ $oid ]['contacts'][ $ch ] += (int) $c['n'];
		}

		return $out;
	}

	/** Eventos recientes de un operador (drill-down). */
	public static function operator_events( int $operator_id, int $limit = 60 ): array
	{
		global $wpdb;
		if ( $operator_id <= 0 ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_type, e.to_status, e.reason, e.created_at, l.name AS lead_name, l.email AS lead_email
			 FROM {$wpdb->prefix}es_lead_events e
			 LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = e.lead_id
			 WHERE e.operator_id = %d AND e.created_at >= %s
			 ORDER BY e.created_at DESC, e.id DESC LIMIT %d",
			$operator_id, self::since(), $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Render de la página admin. */
	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos para ver la actividad de operadores.' );
		}
		$stats = self::per_operator();
		$detail_id = (int) ( $_GET['operador'] ?? 0 );

		echo '<div class="wrap"><h1>Actividad de operadores</h1>';

		// Filtro de rango.
		$range = (int) ( $_GET['range'] ?? self::DEFAULT_RANGE_DAYS );
		$q = ( $detail_id > 0 ) ? '&operador=' . $detail_id : '';
		echo '<form method="get" style="margin:10px 0 16px;">'
			. '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">'
			. '<label style="margin-right:8px;">Rango: <select name="range">';
		foreach ( array( 7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 0 => 'Todo' ) as $d => $label ) {
			echo '<option value="' . $d . '"' . selected( $range, $d, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><button class="button">Filtrar</button></form>';

		if ( $detail_id > 0 && isset( $stats[ $detail_id ] ) ) {
			self::render_detail( $stats[ $detail_id ] );
			echo '<p><a class="button" href="?page=' . esc_attr( self::SLUG ) . '&range=' . (int) $range . '">← Volver al resumen</a></p>';
			echo '</div>';
			return;
		}

		// Tabla resumen.
		echo '<table class="widefat striped" style="max-width:1200px;">'
			. '<thead><tr>
				<th>Operador</th><th>Proyecto</th><th>Leads</th><th>Correos recibidos</th><th>Abiertos</th>
				<th>Contactos</th><th>WhatsApp</th><th>Feedback completado</th><th>Vistas panel</th><th>Ficha</th><th>Última actividad</th>
			</tr></thead><tbody>';

		foreach ( $stats as $op ) {
			$contacts_total = array_sum( $op['contacts'] );
			$open_rate = $op['notified'] > 0 ? round( 100 * $op['opened'] / $op['notified'] ) . '%' : '—';
			$fb = $op['feedback_started'] > 0
				? $op['feedback_completed'] . '/' . $op['feedback_started'] . ' (' . round( 100 * $op['feedback_completed'] / $op['feedback_started'] ) . '%)'
				: '—';
			echo '<tr>'
				. '<td><a href="?page=' . esc_attr( self::SLUG ) . '&range=' . (int) $range . '&operador=' . (int) $op['id'] . '">'
				. esc_html( $op['name'] ) . '</a><br><span class="description">' . esc_html( $op['email'] ) . '</span></td>'
				. '<td>' . esc_html( $op['project'] ?: '—' ) . '</td>'
				. '<td>' . (int) $op['assigned'] . '</td>'
				. '<td>' . (int) $op['notified'] . ( $op['deferred'] > 0 ? ' <span class="description">(+' . $op['deferred'] . ' deferidas)</span>' : '' ) . '</td>'
				. '<td>' . (int) $op['opened'] . ' <span class="description">(' . $open_rate . ')</span></td>'
				. '<td>' . (int) $contacts_total . ' <span class="description">(' . implode( ' / ', array_map( static function ( $v ) { return (int) $v; }, $op['contacts'] ) ) . ')</span></td>'
				. '<td>' . (int) $op['whatsapp_opens'] . '</td>'
				. '<td>' . esc_html( $fb ) . '</td>'
				. '<td>' . (int) $op['panel_views'] . '</td>'
				. '<td>' . (int) $op['ficha_views'] . '</td>'
				. '<td>' . esc_html( \Estandar\Core\Time::display( $op['last_activity'] ) ?: '—' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Detalle de un operador (eventos recientes). */
	private static function render_detail( array $op ): void
	{
		$range = (int) ( $_GET['range'] ?? self::DEFAULT_RANGE_DAYS );
		$events = self::operator_events( (int) $op['id'] );
		$labels = self::event_labels();

		echo '<h2 style="margin:6px 0 2px;">' . esc_html( $op['name'] ) . '</h2>'
			. '<p class="description">' . esc_html( $op['email'] ) . ' · ' . esc_html( $op['project'] ?: 'sin proyecto' )
			. ( $op['active'] ? ' · activo' : ' · inactivo' ) . '</p>';

		echo '<div class="notice notice-info" style="max-width:1200px;"><p>'
			. 'Correos: <strong>' . (int) $op['notified'] . '</strong> recibidos · <strong>' . (int) $op['opened'] . '</strong> abiertos · '
			. (int) $op['deferred'] . ' diferidos · ' . (int) $op['digest'] . ' digest. '
			. 'Contactos: <strong>' . (int) array_sum( $op['contacts'] ) . '</strong> (wa ' . (int) $op['contacts']['whatsapp']
			. ' · llamada ' . (int) $op['contacts']['call'] . ' · email ' . (int) $op['contacts']['email'] . '). '
			. 'Feedback: <strong>' . (int) $op['feedback_completed'] . '</strong> completados de ' . (int) $op['feedback_started'] . ' iniciados. '
			. 'Panel: ' . (int) $op['panel_views'] . ' vistas · ' . (int) $op['ficha_views'] . ' fichas. '
			. 'Última actividad: ' . esc_html( \Estandar\Core\Time::display( $op['last_activity'] ) ?: '—' )
			. '</p></div>';

		if ( ! $events ) {
			echo '<p>Sin eventos registrados en el rango seleccionado.</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>'
			. '<th>Fecha</th><th>Evento</th><th>Lead</th><th>Detalle</th></tr></thead><tbody>';
		foreach ( $events as $e ) {
			$lead = ( (string) ( $e['lead_name'] ?? '' ) !== '' ? $e['lead_name'] : ( $e['lead_email'] ?? '' ) );
			$reason = (string) ( $e['reason'] ?? '' );
			echo '<tr>'
				. '<td>' . esc_html( \Estandar\Core\Time::display( (string) $e['created_at'] ) ) . '</td>'
				. '<td>' . esc_html( $labels[ $e['event_type'] ] ?? $e['event_type'] ) . '</td>'
				. '<td>' . esc_html( $lead ?: '—' ) . '</td>'
				. '<td>' . esc_html( $reason ?: '' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	/** Etiquetas legibles de los eventos. */
	private static function event_labels(): array
	{
		return array(
			'operator_notified'           => 'Correo de lead enviado al operador',
			'operator_email_opened'       => 'Abrió el correo de notificación',
			'operator_notification_deferred' => 'Notificación diferida',
			'operator_daily_digest_sent'  => 'Digest diario enviado',
			'operator_feedback_started'   => 'Comenzó a calificar un lead',
			'operator_feedback_completed' => 'Completó la calificación del lead',
			'feedback_status_changed'     => 'Cambió el estado comercial',
			'quality_changed'             => 'Cambió la calidad del lead',
			'status_changed'              => 'Cambió el estado del lead',
			'operator_assigned'           => 'Lead asignado al operador',
			'lead_list_opened'            => 'Abrió la lista de leads',
			'workspace_opened'            => 'Abrió el espacio de trabajo',
			'lead_viewed'                 => 'Revisó la ficha de un lead',
			'contact_logged'              => 'Registró un contacto con el lead',
			'lead_whatsapp_opened'        => 'Abrió WhatsApp del lead',
			'operator_clicked_whatsapp'   => 'Hizo clic en WhatsApp del lead',
			'compliance_escalation'       => 'Escalamiento de cumplimiento',
			'autoresponse_email_sent'     => 'Autorespuesta enviada al lead',
			'autoresponse_email_failed'   => 'Autorespuesta falló',
			'autoresponse_link_click'     => 'Lead hizo clic en la autorespuesta',
		);
	}
}
