<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadsDigest — resumen periódico de leads por proyecto (v2, estandar-core).
 *
 * LEAD_DIGEST_V1 (2026-09-22): correo diario por proyecto con los leads caídos
 * el día anterior; los lunes suma la semana recién acabada; el día 1 de cada
 * mes suma el mes anterior. Marca contactado/pendiente (discreto) e indica
 * cuántas veces dejó sus datos el lead. Opt-in por proyecto (option con mapa
 * `project_slug => { enabled, extra_cc }`). Reemplaza los envíos periódicos del
 * plugin legacy `estandar-leads` cuando está presente (no duplica).
 *
 * Genérico: corre en cualquier sitio con leads v2 (`es_leads`). Sin leads en la
 * ventana, no envía (no genera ruido).
 */
class LeadsDigest {

	const OPTION_SETTINGS = 'estandar_suite_core_leads_digest_settings';
	const OPTION_LAST_RUN = 'estandar_suite_core_leads_digest_last_run';
	const CRON            = 'estandar_core_leads_digest_daily';
	const EMAIL_TEMPLATE  = 'leads-digest.php';
	const DEFAULT_HOUR    = 7;

	/** Estados que cuentan como "contactado". */
	const CONTACTED_STATUSES = array( 'CONTACTED', 'QUALIFIED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CLIENT' );

	/** Runtime wiring: cron diario a las 07:00 hora del sitio + handler. */
	public static function init(): void
	{
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( self::next_run_epoch(), 'daily', self::CRON );
		}
	}

	/** Epoch de la próxima ocurrencia (hoy 07:00 sitio; si ya pasó, mañana). */
	public static function next_run_epoch( int $hour = self::DEFAULT_HOUR ): int
	{
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now = new \DateTimeImmutable( 'now', $tz );
		$target = $now->setTime( $hour, 0, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	// ── Settings (opt-in por proyecto) ─────────────────────────────────────

	/** @return array<string,array{enabled:int,extra_cc:string}> */
	public static function settings(): array
	{
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION_SETTINGS, array() ) : array();
		return is_array( $raw ) ? $raw : array();
	}

	public static function save_settings( array $raw ): array
	{
		$clean = array();
		foreach ( $raw as $slug => $cfg ) {
			$slug = function_exists( 'sanitize_title' ) ? sanitize_title( (string) $slug ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $slug ) ) );
			if ( $slug === '' ) {
				continue;
			}
			$cfg = is_array( $cfg ) ? $cfg : array();
			$clean[ $slug ] = array(
				'enabled'  => ! empty( $cfg['enabled'] ) ? 1 : 0,
				'extra_cc' => trim( (string) ( $cfg['extra_cc'] ?? '' ) ),
			);
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_SETTINGS, $clean, false );
		}
		return $clean;
	}

	/** @return string[] slugs habilitados (respetando el filtro). */
	public static function enabled_projects(): array
	{
		$slugs = array();
		foreach ( self::settings() as $slug => $cfg ) {
			if ( ! empty( $cfg['enabled'] ) ) {
				$slugs[] = $slug;
			}
		}
		$slugs = (array) apply_filters( 'estandar_suite_leads_digest_projects', $slugs );
		return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
	}

	/** Proyectos con leads reales en `es_leads` (para el panel). */
	public static function projects_with_leads(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = $wpdb->get_col( "SELECT DISTINCT project_slug FROM {$wpdb->prefix}es_leads WHERE project_slug <> '' ORDER BY project_slug ASC" );
		return is_array( $rows ) ? array_values( array_filter( array_map( 'strval', $rows ) ) ) : array();
	}

	// ── Ventanas temporales (PURE, testeable) ──────────────────────────────

	/**
	 * Ventanas según el "hoy" en zona del sitio. PURE.
	 *
	 * @param \DateTimeImmutable $today ahora en la zona del sitio.
	 * @return array<int,array{key:string,label:string,from:string,to:string}>
	 */
	public static function windows( \DateTimeImmutable $today ): array
	{
		$out = array();

		$yday = $today->modify( '-1 day' );
		$out[] = array(
			'key'   => 'daily',
			'label' => 'Ayer',
			'from'  => $yday->format( 'Y-m-d' ) . ' 00:00:00',
			'to'    => $yday->format( 'Y-m-d' ) . ' 23:59:59',
		);

		// Lunes: semana ISO anterior (lun–dom).
		if ( (int) $today->format( 'N' ) === 1 ) {
			$lastMon = $today->modify( 'monday this week' )->modify( '-7 days' );
			$lastSun = $lastMon->modify( '+6 days' );
			$out[] = array(
				'key'   => 'weekly',
				'label' => 'Semana anterior (' . $lastMon->format( 'd/m' ) . '–' . $lastSun->format( 'd/m' ) . ')',
				'from'  => $lastMon->format( 'Y-m-d' ) . ' 00:00:00',
				'to'    => $lastSun->format( 'Y-m-d' ) . ' 23:59:59',
			);
		}

		// Día 1: mes anterior completo.
		if ( (int) $today->format( 'j' ) === 1 ) {
			$firstPrev = $today->modify( 'first day of last month' );
			$lastPrev  = $today->modify( 'last day of last month' );
			$out[] = array(
				'key'   => 'monthly',
				'label' => 'Mes anterior (' . $firstPrev->format( 'm/Y' ) . ')',
				'from'  => $firstPrev->format( 'Y-m-d' ) . ' 00:00:00',
				'to'    => $lastPrev->format( 'Y-m-d' ) . ' 23:59:59',
			);
		}

		return $out;
	}

	// ── Criterio contactado (PURE, testeable) ──────────────────────────────

	public static function is_contacted( array $lead ): bool
	{
		$first = trim( (string) ( $lead['first_contact_at'] ?? '' ) );
		if ( $first !== '' && $first !== '0000-00-00 00:00:00' ) {
			return true;
		}
		if ( (int) ( $lead['contact_attempts'] ?? 0 ) > 0 ) {
			return true;
		}
		$status = strtoupper( trim( (string) ( $lead['status'] ?? '' ) ) );
		return in_array( $status, self::CONTACTED_STATUSES, true );
	}

	// ── Consulta ───────────────────────────────────────────────────────────

	/**
	 * Leads de un proyecto que **dejaron sus datos** en la ventana (por fecha de
	 * submission, no de creación del lead). Cada lead aparece UNA vez con su
	 * total de ingresos (todas sus submissions, igual que el correo individual).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function leads_in_window( string $slug, string $from, string $to ): array
	{
		global $wpdb;
		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : (string) $slug;
		if ( ! isset( $wpdb ) || $slug === '' ) {
			return array();
		}
		$sql = $wpdb->prepare(
			"SELECT l.id, l.name, l.email, l.phone, l.status, l.contact_attempts, l.first_contact_at, l.created_at,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s2 WHERE s2.lead_id = l.id) AS repeat_count,
			        MAX(s.submitted_at) AS last_submitted
			 FROM {$wpdb->prefix}es_lead_submissions s
			 INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
			 WHERE l.project_slug = %s AND s.submitted_at >= %s AND s.submitted_at <= %s
			 GROUP BY l.id
			 ORDER BY last_submitted DESC, l.id DESC",
			$slug, $from, $to
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$r ) {
			$r['contacted']    = self::is_contacted( $r );
			$r['repeat_count'] = (int) ( $r['repeat_count'] ?? 0 );
		}
		unset( $r );
		return $rows;
	}

	// ── Render ─────────────────────────────────────────────────────────────

	public static function render_email( string $slug, array $sections, array $identity ): string
	{
		$es_digest      = array(
			'project_slug'  => $slug,
			'project_label' => self::project_label( $slug ),
			'sections'      => $sections,
			'generated_at'  => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		$es_identity    = $identity;
		$es_header      = ( class_exists( '\Estandar\Core\Mail' ) && method_exists( '\Estandar\Core\Mail', 'header_html' ) ) ? \Estandar\Core\Mail::header_html( $identity ) : '';
		$es_footer      = ( class_exists( '\Estandar\Core\Mail' ) && method_exists( '\Estandar\Core\Mail', 'footer_html' ) ) ? \Estandar\Core\Mail::footer_html( $identity ) : '';
		ob_start();
		include ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/templates/' . self::EMAIL_TEMPLATE;
		return (string) ob_get_clean();
	}

	public static function project_label( string $slug ): string
	{
		if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
			$label = (string) \Estandar\Core\Leads\ProjectRegistry::label_for( $slug );
			if ( $label !== '' ) {
				return $label;
			}
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	// ── Dispatch ───────────────────────────────────────────────────────────

	/**
	 * Corre el digest. Por defecto evita re-enviar el mismo día.
	 *
	 * @return array{ran:bool,reason:string,sent:array<int,array>}
	 */
	public static function run( bool $force = false ): array
	{
		$tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$today = new \DateTimeImmutable( 'now', $tz );
		$stamp = $today->format( 'Y-m-d' );

		if ( ! $force ) {
			$last = function_exists( 'get_option' ) ? (string) get_option( self::OPTION_LAST_RUN, '' ) : '';
			if ( $last === $stamp ) {
				return array( 'ran' => false, 'reason' => 'already_ran_today', 'sent' => array() );
			}
		}

		$windows = self::windows( $today );
		$result  = array( 'ran' => true, 'reason' => 'ok', 'sent' => array(), 'windows' => $windows );

		foreach ( self::enabled_projects() as $slug ) {
			$built = self::build_project( $slug, $windows );
			if ( $built['total'] < 1 ) {
				continue;
			}
			$sent = self::dispatch( $slug, $built );
			$result['sent'][] = array( 'project' => $slug, 'leads' => $built['total'], 'result' => $sent );
		}

		if ( ! $force && function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LAST_RUN, $stamp, false );
		}
		return $result;
	}

	/** Construye secciones + totales de un proyecto. */
	public static function build_project( string $slug, array $windows ): array
	{
		$sections = array();
		$total    = 0;
		foreach ( $windows as $w ) {
			$rows = self::leads_in_window( $slug, (string) $w['from'], (string) $w['to'] );
			$total += count( $rows );
			$sections[] = array(
				'label' => $w['label'],
				'key'   => $w['key'],
				'from'  => (string) $w['from'],
				'to'    => (string) $w['to'],
				'rows'  => $rows,
			);
		}
		return array( 'sections' => $sections, 'total' => $total );
	}

	/** Envía el correo de un proyecto a sus operadores + CC. */
	public static function dispatch( string $slug, array $built ): array
	{
		$operators = class_exists( '\Estandar\Core\Leads\Operators' ) ? \Estandar\Core\Leads\Operators::assigned( $slug ) : array();
		$to = array();
		foreach ( $operators as $op ) {
			$email = is_array( $op ) ? (string) ( $op['email'] ?? '' ) : (string) ( $op->email ?? '' );
			if ( $email !== '' ) {
				$to[] = $email;
			}
		}
		$registry_email = '';
		if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) && method_exists( '\Estandar\Core\Leads\ProjectRegistry', 'operator_for' ) ) {
			$reg = (array) \Estandar\Core\Leads\ProjectRegistry::operator_for( $slug );
			$registry_email = (string) ( $reg['email'] ?? '' );
		}
		if ( $registry_email !== '' ) {
			$to[] = $registry_email;
		}
		if ( class_exists( '\Estandar\Core\Mail' ) ) {
			$to = \Estandar\Core\Mail::normalize_addresses( $to );
		}
		$to = array_values( array_unique( array_filter( $to ) ) );
		if ( empty( $to ) ) {
			return array( 'ok' => false, 'error' => 'no_recipients' );
		}

		$cc = trim( (string) ( self::settings()[ $slug ]['extra_cc'] ?? '' ) );
		$identity = class_exists( '\Estandar\Core\Mail' ) ? \Estandar\Core\Mail::identity( array(), $slug ) : array();
		$html = self::render_email( $slug, $built['sections'], $identity );

		$label   = self::project_label( $slug );
		$subject = sprintf( 'Leads de %s — resumen %s', $label, self::subject_suffix() );

		$opts = array(
			'to'         => $to,
			'subject'    => $subject,
			'html'       => $html,
			'from_name'  => (string) ( $identity['sender_name'] ?? '' ),
			'from_email' => (string) ( $identity['sender_email'] ?? '' ),
		);
		if ( $cc !== '' ) {
			$opts['cc'] = $cc;
		}
		$sent = \Estandar\Core\Mail::send( $opts );
		return $sent;
	}

	/** Sufijo textual del asunto (Ayer / + semana / + mes). PURE. */
	public static function subject_suffix( array $windows = array() ): string
	{
		$keys = array();
		foreach ( $windows as $w ) {
			$keys[ (string) ( $w['key'] ?? '' ) ] = true;
		}
		$parts = array( 'de ayer' );
		if ( isset( $keys['weekly'] ) ) {
			$parts[] = 'semanal';
		}
		if ( isset( $keys['monthly'] ) ) {
			$parts[] = 'mensual';
		}
		return implode( ' + ', $parts );
	}

	/** Envío de prueba a un correo explícito (no toca last_run). */
	public static function send_test( string $slug, string $to ): array
	{
		$tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$windows = self::windows( new \DateTimeImmutable( 'now', $tz ) );
		$built   = self::build_project( $slug, $windows );
		$identity = class_exists( '\Estandar\Core\Mail' ) ? \Estandar\Core\Mail::identity( array(), $slug ) : array();
		$html = self::render_email( $slug, $built['sections'], $identity );
		$subject = '[PRUEBA] Leads de ' . self::project_label( $slug ) . ' — ' . self::subject_suffix( $windows );
		return \Estandar\Core\Mail::send( array(
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html,
		) );
	}
}
