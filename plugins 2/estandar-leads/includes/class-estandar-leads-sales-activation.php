<?php
/**
 * SALES_ACTIVATION_ENGINE_V1 — channel-independent recommendations, email phase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Sales_Activation {
	const OPTION_KEY = 'estandar_leads_sales_activation_settings';
	const STATE_KEY  = 'estandar_leads_sales_activation_state';
	const RUN_STATE_KEY = 'estandar_leads_sales_activation_run_state';
	const CRON_HOOK  = 'estandar_leads_sales_activation_dispatch';
	const MAX_SCAN   = 500;

	private const TERMINAL_STATUSES = array( 'CONVERTED', 'LOST', 'INVALID', 'RESERVED', 'DEED_SIGNED', 'DELIVERED' );
	private const PROSPECT_EVENTS = array(
		'lead_recontacted', 'autoresponse_engaged', 'autoresponse_link_click',
		'autoresponse_email_open_signal', 'lead_whatsapp_opened',
	);
	private const CONTACT_EVENTS = array( 'contact_attempt', 'contact_logged', 'contact_registered', 'lead_contacted' );

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_dispatch' ) );
		add_action( 'admin_post_estandar_leads_save_sales_activation', array( __CLASS__, 'save_settings_handler' ) );
		add_action( 'admin_post_estandar_leads_test_sales_activation', array( __CLASS__, 'test_send_handler' ) );
		add_filter( 'estandar_settings_sections', array( __CLASS__, 'register_settings_section' ) );
		self::schedule_next();
		register_deactivation_hook( ESTANDAR_LEADS_FILE, array( __CLASS__, 'deactivate' ) );
	}

	public static function register_settings_section( array $sections ): array {
		$sections['sales-activation'] = array(
			'title'    => 'Sales Activation Engine',
			'priority' => 40,
			'callback' => array( __CLASS__, 'render_settings_section' ),
		);
		return $sections;
	}

	public static function render_settings_section(): void {
		require ESTANDAR_LEADS_PATH . 'templates/admin/sales-activation-settings.php';
	}

	public static function settings_url(): string {
		return admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion#estandar-settings-sales-activation' );
	}

	public static function defaults(): array {
		return array(
			'enabled'            => 0,
			'send_days'          => array( 1, 2, 3, 4, 5 ),
			'send_time'          => '09:00',
			'max_recommendations'=> 5,
			'min_score'          => 4,
			'recipients'         => '',
			'cc'                 => '',
			'tone'               => 'disabled',
			'custom_intro'       => '',
			'custom_closing'     => '',
		);
	}

	public static function get_settings(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$s = array_merge( self::defaults(), $raw );
		$s['enabled'] = empty( $s['enabled'] ) ? 0 : 1;
		$s['send_days'] = self::sanitize_days( $s['send_days'] );
		$s['send_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $s['send_time'] ) ? (string) $s['send_time'] : '09:00';
		$s['max_recommendations'] = max( 1, min( 5, absint( $s['max_recommendations'] ) ) );
		$s['min_score'] = max( 1, min( 100, absint( $s['min_score'] ) ) );
		$s['recipients'] = Estandar_Leads_Email::sanitize_email_list( $s['recipients'] );
		$s['cc'] = Estandar_Leads_Email::sanitize_email_list( $s['cc'] );
		$s['tone'] = in_array( $s['tone'], array( 'disabled', 'professional', 'close', 'inspiring', 'custom' ), true ) ? $s['tone'] : 'disabled';
		return $s;
	}

	public static function save_settings( array $raw ): void {
		$settings = array(
			'enabled'             => empty( $raw['enabled'] ) ? 0 : 1,
			'send_days'           => self::sanitize_days( $raw['send_days'] ?? array() ),
			'send_time'           => sanitize_text_field( $raw['send_time'] ?? '09:00' ),
			'max_recommendations' => max( 1, min( 5, absint( $raw['max_recommendations'] ?? 5 ) ) ),
			'min_score'           => max( 1, min( 100, absint( $raw['min_score'] ?? 4 ) ) ),
			'recipients'          => Estandar_Leads_Email::sanitize_email_list( $raw['recipients'] ?? '' ),
			'cc'                  => Estandar_Leads_Email::sanitize_email_list( $raw['cc'] ?? '' ),
			'tone'                => sanitize_key( $raw['tone'] ?? 'disabled' ),
			'custom_intro'        => sanitize_textarea_field( $raw['custom_intro'] ?? '' ),
			'custom_closing'      => sanitize_textarea_field( $raw['custom_closing'] ?? '' ),
		);
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $settings['send_time'] ) ) {
			$settings['send_time'] = '09:00';
		}
		if ( ! in_array( $settings['tone'], array( 'disabled', 'professional', 'close', 'inspiring', 'custom' ), true ) ) {
			$settings['tone'] = 'disabled';
		}
		update_option( self::OPTION_KEY, $settings, false );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::schedule_next();
	}

	private static function sanitize_days( $days ): array {
		$days = is_array( $days ) ? $days : explode( ',', (string) $days );
		$out = array();
		foreach ( $days as $day ) {
			$day = absint( $day );
			if ( $day >= 1 && $day <= 7 ) {
				$out[] = $day;
			}
		}
		sort( $out );
		return array_values( array_unique( $out ) );
	}

	public static function save_settings_handler(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'estandar_leads_sales_activation_settings' );
		self::save_settings( $_POST );
		wp_safe_redirect( add_query_arg( 'activation_saved', 1, self::settings_url() ) );
		exit;
	}

	public static function test_send_handler(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'estandar_leads_test_sales_activation' );
		$to = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$result = is_email( $to )
			? self::dispatch( $to )
			: self::result( 'error', 'El destinatario de prueba no es válido.' );
		set_transient( 'estandar_leads_sales_activation_test_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'activation_result', 1, self::settings_url() ) );
		exit;
	}

	public static function get_run_state(): array {
		$state = get_option( self::RUN_STATE_KEY, array() );
		return array_merge( array(
			'last_run_at' => '', 'last_send_at' => '', 'status' => '', 'message' => '',
			'recommendation_count' => 0, 'recipients' => array(), 'renderer' => '',
		), is_array( $state ) ? $state : array() );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function next_send_timestamp( ?array $settings = null, $from = null ): int {
		$settings = $settings ?: self::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['send_days'] ) ) {
			return 0;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now = $from instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface( $from ) : new DateTimeImmutable( 'now', $tz );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['send_time'] ) );
		for ( $offset = 0; $offset <= 14; $offset++ ) {
			$candidate = $now->setTime( $hour, $minute )->modify( '+' . $offset . ' days' );
			if ( in_array( (int) $candidate->format( 'N' ), $settings['send_days'], true ) && $candidate->getTimestamp() > $now->getTimestamp() ) {
				return $candidate->getTimestamp();
			}
		}
		return 0;
	}

	public static function schedule_next(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$next = self::next_send_timestamp();
		if ( $next > 0 ) {
			wp_schedule_single_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Pure recommendation engine. Renderers must consume this contract only.
	 *
	 * @param array $candidate Persisted lead/submission facts and events.
	 * @param int   $min_score Activation threshold.
	 * @param int   $now_ts    Deterministic clock for tests.
	 */
	public static function evaluate( array $candidate, int $min_score = 4, int $now_ts = 0 ): ?array {
		$now_ts = $now_ts > 0 ? $now_ts : time();
		if ( empty( $candidate['correct_operator'] ) || empty( $candidate['project_valid'] ) || ! empty( $candidate['is_qa'] ) || empty( $candidate['policy_allowed'] ) ) {
			return null;
		}
		$phone = preg_replace( '/\D+/', '', (string) ( $candidate['phone'] ?? '' ) );
		if ( strlen( $phone ) < 8 || strlen( $phone ) > 15 ) {
			return null;
		}
		$status = strtoupper( (string) ( $candidate['status'] ?? '' ) );
		if ( in_array( $status, self::TERMINAL_STATUSES, true ) && empty( $candidate['terminal_reopened'] ) ) {
			return null;
		}
		if ( ! empty( $candidate['effective_contact'] ) && empty( $candidate['new_activity_after_contact'] ) ) {
			return null;
		}

		$events = is_array( $candidate['events'] ?? null ) ? $candidate['events'] : array();
		$signals = array();
		$intent_count = 0;
		$latest_activity_ts = self::to_timestamp( $candidate['latest_activity_at'] ?? '' );
		$submission_count = max( 1, absint( $candidate['submission_count'] ?? 1 ) );
		$event_counts = array();
		$event_latest = array();
		$channels = array();

		foreach ( $events as $event ) {
			$type = sanitize_key( $event['type'] ?? '' );
			$event_counts[ $type ] = ( $event_counts[ $type ] ?? 0 ) + 1;
			$event_ts = self::to_timestamp( $event['created_at'] ?? '' );
			$event_latest[ $type ] = max( $event_latest[ $type ] ?? 0, $event_ts );
			if ( in_array( $type, self::PROSPECT_EVENTS, true ) ) {
				$latest_activity_ts = max( $latest_activity_ts, $event_ts );
			}
			$meta = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			if ( in_array( $type, array( 'autoresponse_email_open_signal', 'autoresponse_link_click' ), true ) ) {
				$channels['email'] = true;
			}
			if ( $type === 'lead_whatsapp_opened' || ( $type === 'autoresponse_engaged' && in_array( (string) ( $meta['channel'] ?? $meta['resource_type'] ?? '' ), array( 'whatsapp' ), true ) ) ) {
				$channels['whatsapp'] = true;
			}
			if ( $type === 'autoresponse_link_click' && in_array( (string) ( $meta['resource_type'] ?? '' ), array( 'landing', 'pdf' ), true ) ) {
				$channels['web'] = true;
			}
		}

		$activity_age = self::age_days( $latest_activity_ts, $now_ts );
		$recontact = $submission_count > 1 || ! empty( $event_counts['lead_recontacted'] );
		if ( $recontact ) {
			$age = self::age_days( max( self::to_timestamp( $candidate['latest_activity_at'] ?? '' ), $event_latest['lead_recontacted'] ?? 0 ), $now_ts );
			self::add_signal( $signals, 'recontact', 24 * self::decay( $age ), $submission_count > 1 ? $submission_count . ' solicitudes registradas' : 'Recontactó al equipo' );
			$intent_count++;
		}
		if ( count( $channels ) >= 2 ) {
			self::add_signal( $signals, 'multi_channel', 12 * self::decay( $activity_age ), 'Interactuó por ' . implode( ' y ', array_keys( $channels ) ) );
			$intent_count++;
		}
		if ( ! empty( $event_counts['autoresponse_link_click'] ) ) {
			self::add_signal( $signals, 'email_click', 18 * self::decay( self::age_days( $event_latest['autoresponse_link_click'], $now_ts ) ), 'Hizo clic en un enlace del email' );
			$intent_count++;
		}
		if ( ! empty( $event_counts['lead_whatsapp_opened'] ) || isset( $channels['whatsapp'] ) ) {
			$whatsapp_ts = max( $event_latest['lead_whatsapp_opened'] ?? 0, $event_latest['autoresponse_engaged'] ?? 0 );
			self::add_signal( $signals, 'whatsapp_open', 16 * self::decay( self::age_days( $whatsapp_ts, $now_ts ) ), 'Abrió la conversación de WhatsApp' );
			$intent_count++;
		}
		$opens = absint( $event_counts['autoresponse_email_open_signal'] ?? 0 );
		if ( $opens > 0 ) {
			$open_points = $opens >= 3 ? 10 : ( $opens === 2 ? 7 : 4 );
			self::add_signal( $signals, 'email_open', $open_points * self::decay( self::age_days( $event_latest['autoresponse_email_open_signal'], $now_ts ) ), $opens === 1 ? 'Abrió el email' : 'Abrió el email ' . $opens . ' veces' );
			$intent_count++;
		}

		$facts = is_array( $candidate['commercial_facts'] ?? null ) ? $candidate['commercial_facts'] : array();
		if ( ! empty( $facts['high_value'] ) ) {
			self::add_signal( $signals, 'high_value', 16 * self::decay( $activity_age, 0.5 ), 'Declaró un perfil comercial de alto valor' );
			$intent_count++;
		}
		if ( ! empty( $facts['immediate_start'] ) ) {
			self::add_signal( $signals, 'immediate_start', 14 * self::decay( $activity_age, 0.5 ), 'Indicó inicio inmediato' );
			$intent_count++;
		}
		if ( ! empty( $facts['operating_company'] ) ) {
			self::add_signal( $signals, 'operating_company', 10 * self::decay( $activity_age, 0.5 ), 'Indicó que su empresa está operando' );
			$intent_count++;
		}
		if ( ! empty( $facts['provider_change'] ) ) {
			self::add_signal( $signals, 'provider_change', 12 * self::decay( $activity_age, 0.5 ), 'Está evaluando cambiar de proveedor' );
			$intent_count++;
		}

		$feedback_status = strtolower( (string) ( $candidate['feedback_status'] ?? '' ) );
		if ( $feedback_status === 'escalated' ) {
			self::add_signal( $signals, 'sla_escalated', 18, 'SLA comercial escalado' );
			$intent_count++;
		} elseif ( $feedback_status === 'overdue' ) {
			self::add_signal( $signals, 'sla_overdue', 10, 'SLA comercial vencido' );
			$intent_count++;
		}
		if ( empty( $candidate['effective_contact'] ) ) {
			self::add_signal( $signals, 'unmanaged', 10, 'Aún no registra contacto comercial efectivo' );
		}
		if ( $latest_activity_ts > 0 && $activity_age <= 30 ) {
			self::add_signal( $signals, 'recent_activity', 10 * self::decay( $activity_age ), 'Actividad reciente: ' . self::activity_label( $activity_age ) );
		}
		$completeness = 0;
		$completeness += trim( (string) ( $candidate['name'] ?? '' ) ) !== '' ? 1 : 0;
		$completeness += is_email( (string) ( $candidate['email'] ?? '' ) ) ? 1 : 0;
		$completeness += $phone !== '' ? 1 : 0;
		if ( $completeness >= 2 ) {
			self::add_signal( $signals, 'contact_complete', $completeness === 3 ? 6 : 3, $completeness === 3 ? 'Datos de contacto completos' : 'Teléfono utilizable disponible' );
		}

		if ( $intent_count === 0 ) {
			return null;
		}
		usort( $signals, static function ( array $a, array $b ): int {
			return ( $b['points'] <=> $a['points'] ) ?: strcmp( $a['code'], $b['code'] );
		} );
		$score = min( 100, (int) round( array_sum( array_column( $signals, 'points' ) ) ) );
		if ( $score < $min_score ) {
			return null;
		}
		$priority = $score >= 60 ? 'ALTA' : ( $score >= 35 ? 'MEDIA' : 'BAJA' );
		$reasons = array_slice( array_column( $signals, 'reason' ), 0, 5 );
		return array(
			'lead_id'          => absint( $candidate['lead_id'] ?? 0 ),
			'submission_id'    => absint( $candidate['submission_id'] ?? 0 ),
			'name'             => sanitize_text_field( $candidate['name'] ?? '' ),
			'email'            => sanitize_email( $candidate['email'] ?? '' ),
			'phone'            => $phone,
			'project_slug'     => sanitize_key( $candidate['project_slug'] ?? '' ),
			'project_name'     => sanitize_text_field( $candidate['project_name'] ?? $candidate['project_slug'] ?? '' ),
			'activation_score' => $score,
			'priority'         => $priority,
			'reasons'          => array_values( $reasons ),
			'reason_codes'     => array_slice( array_column( $signals, 'code' ), 0, 5 ),
			'latest_activity'  => $latest_activity_ts > 0 ? gmdate( 'Y-m-d H:i:s', $latest_activity_ts ) : '',
			'journey_summary'  => self::journey_summary( $submission_count, $channels, $facts ),
		);
	}

	private static function add_signal( array &$signals, string $code, float $points, string $reason ): void {
		if ( $points <= 0 ) {
			return;
		}
		$signals[] = array( 'code' => $code, 'points' => round( $points, 2 ), 'reason' => $reason );
	}

	private static function decay( float $age_days, float $floor = 0.0 ): float {
		if ( $age_days <= 3 ) { return 1.0; }
		if ( $age_days <= 7 ) { return 0.85; }
		if ( $age_days <= 14 ) { return 0.60; }
		if ( $age_days <= 30 ) { return max( $floor, 0.35 ); }
		return $floor;
	}

	private static function age_days( int $timestamp, int $now ): float {
		return $timestamp > 0 ? max( 0, ( $now - $timestamp ) / DAY_IN_SECONDS ) : 9999;
	}

	private static function to_timestamp( $value ): int {
		if ( is_int( $value ) ) { return $value; }
		$ts = strtotime( (string) $value );
		return $ts ? $ts : 0;
	}

	private static function activity_label( float $age_days ): string {
		if ( $age_days < 1 ) { return 'hoy'; }
		$days = max( 1, (int) floor( $age_days ) );
		return 'hace ' . $days . ( $days === 1 ? ' día' : ' días' );
	}

	private static function journey_summary( int $submissions, array $channels, array $facts ): string {
		$parts = array( $submissions === 1 ? '1 solicitud' : $submissions . ' solicitudes' );
		if ( ! empty( $channels ) ) {
			$parts[] = 'interacción por ' . implode( ', ', array_keys( $channels ) );
		}
		if ( ! empty( $facts['immediate_start'] ) ) {
			$parts[] = 'inicio inmediato declarado';
		}
		return implode( ' · ', $parts );
	}

	/** Cron-compatible entrypoint. */
	public static function run_dispatch( string $test_to = '' ): bool {
		return self::dispatch( $test_to )['status'] === 'success';
	}

	/** Immediate/cron execution with an explicit, persistable result. */
	public static function dispatch( string $test_to = '' ): array {
		try {
			return self::execute_dispatch( $test_to );
		} catch ( Throwable $error ) {
			$result = self::result( 'error', 'El motor no pudo completar la generación o el envío.' );
			self::record_run_state( $result );
			if ( $test_to === '' ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
				self::schedule_next();
			}
			return $result;
		}
	}

	private static function execute_dispatch( string $test_to = '' ): array {
		$settings = self::get_settings();
		$is_test = $test_to !== '';
		if ( ! $is_test && empty( $settings['enabled'] ) ) {
			$result = self::result( 'skipped', 'Sales Activation Engine está deshabilitado.' );
			self::record_run_state( $result );
			return $result;
		}
		$diagnostics = array();
		$batches = self::build_recommendations( $settings, $diagnostics );
		if ( empty( $batches ) ) {
			$result = self::result( 'skipped', self::empty_reason( $diagnostics ) );
			$result['diagnostics'] = $diagnostics;
			self::finish_dispatch( $result, $is_test );
			return $result;
		}
		$sent_any = false;
		$sent_count = 0;
		$effective_recipients = array();
		$rendered = false;
		$skipped_duplicates = 0;
		$missing_recipients = 0;
		foreach ( $batches as $operator_id => $batch ) {
			if ( empty( $batch['recommendations'] ) ) { continue; }
			$fingerprint = self::fingerprint( $batch['recommendations'] );
			if ( ! $is_test && self::is_duplicate( (int) $operator_id, $fingerprint ) ) { $skipped_duplicates++; continue; }
			$to_list = $is_test ? array( $test_to ) : self::recipients_for( $batch['operator_email'], $settings['recipients'] );
			if ( empty( $to_list ) ) { $missing_recipients++; continue; }
			$primary_to = array_shift( $to_list );
			$cc = array_merge( $to_list, self::email_list( $settings['cc'] ) );
			self::attach_action_urls( $batch['recommendations'], (int) $operator_id );
			$batch_id = wp_generate_uuid4();
			self::audit( $batch, $batch_id, $primary_to, 'sales_activation_recommendation_generated', $is_test, null );
			$html = Estandar_Leads_Email::render_template( 'sales-activation.php', array(
				'operator_name' => $batch['operator_name'],
				'recommendations' => $batch['recommendations'],
				'tone' => self::tone_copy( $settings ),
				'email_settings' => Estandar_Leads_Email::get_settings(),
			) );
			if ( trim( $html ) === '' ) {
				self::audit( $batch, $batch_id, $primary_to, 'sales_activation_email_failed', $is_test, false );
				continue;
			}
			$rendered = true;
			$subject = 'Oportunidades comerciales para activar hoy';
			$headers = empty( $cc ) ? array() : array( 'Cc: ' . implode( ',', array_unique( $cc ) ) );
			$ok = Estandar_Leads_Email::send( $primary_to, $subject, $html, null, $headers );
			self::audit( $batch, $batch_id, $primary_to, $ok ? 'sales_activation_email_sent' : 'sales_activation_email_failed', $is_test, $ok );
			if ( $ok && ! $is_test ) {
				self::remember( (int) $operator_id, $fingerprint );
			}
			if ( $ok ) {
				$sent_count += count( $batch['recommendations'] );
				$effective_recipients = array_merge( $effective_recipients, array( $primary_to ), $cc );
			}
			$sent_any = $sent_any || $ok;
			if ( $is_test ) { break; }
		}
		if ( $sent_any ) {
			$result = self::result( 'success', sprintf( 'Correo generado y enviado con %d recomendación(es).', $sent_count ) );
			$result['recommendation_count'] = $sent_count;
			$result['recipients'] = array_values( array_unique( array_filter( $effective_recipients ) ) );
			$result['renderer'] = 'templates/email/sales-activation.php';
			$result['rendered'] = $rendered;
		} elseif ( $skipped_duplicates > 0 ) {
			$result = self::result( 'skipped', 'El conjunto de recomendaciones no presenta cambios relevantes desde el último envío.' );
		} elseif ( $missing_recipients > 0 ) {
			$result = self::result( 'error', 'Existen recomendaciones, pero no hay destinatarios válidos configurados.' );
		} else {
			$result = self::result( 'error', $rendered ? 'El renderer generó el correo, pero wp_mail() no confirmó el envío.' : 'El renderer definitivo no pudo generar el correo.' );
		}
		self::finish_dispatch( $result, $is_test );
		return $result;
	}

	public static function build_recommendations( ?array $settings = null, ?array &$diagnostics = null ): array {
		global $wpdb;
		$settings = $settings ?: self::get_settings();
		$diagnostics = array( 'operators' => 0, 'scanned' => 0, 'below_min_score' => 0, 'excluded' => 0, 'recommendations' => 0 );
		$operators = self::operators_by_id();
		$diagnostics['operators'] = count( $operators );
		if ( empty( $operators ) ) { return array(); }
		$rows = $wpdb->get_results(
			"SELECT l.*, s.id AS submission_id, s.context_json, s.form_fields_json, s.chatbot_json, s.scoring_json, s.submitted_at,
			 (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions sx WHERE sx.lead_id=l.id) AS submission_count,
			 (SELECT MAX(c.contacted_at) FROM {$wpdb->prefix}es_lead_contacts c WHERE c.lead_id=l.id) AS last_contact_at,
			 (SELECT e.operator_id FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id=l.id AND e.submission_id=s.id AND e.event_type='operator_assigned' ORDER BY e.created_at DESC,e.id DESC LIMIT 1) AS submission_operator_id
			 FROM {$wpdb->prefix}es_leads l
			 INNER JOIN {$wpdb->prefix}es_lead_submissions s ON s.id=(SELECT MAX(s2.id) FROM {$wpdb->prefix}es_lead_submissions s2 WHERE s2.lead_id=l.id)
			 WHERE l.assigned_operator_id IS NOT NULL
			 ORDER BY l.last_seen_at DESC,l.id ASC LIMIT " . self::MAX_SCAN,
			ARRAY_A
		);
		if ( empty( $rows ) ) { return array(); }
		$diagnostics['scanned'] = count( $rows );
		$lead_ids = array_values( array_unique( array_map( 'absint', array_column( $rows, 'id' ) ) ) );
		$events = self::events_by_lead( $lead_ids );
		$projects = self::project_names();
		$out = array();
		foreach ( $rows as $row ) {
			$lead_id = absint( $row['id'] );
			$submission_id = absint( $row['submission_id'] );
			$operator_id = absint( $row['submission_operator_id'] ?: $row['assigned_operator_id'] );
			if ( empty( $operators[ $operator_id ] ) ) { continue; }
			$submission = (object) $row;
			$project_slug = Estandar_Leads_Repository::project_for_submission( $submission );
			$decision = Estandar_Commercial_Qualification_Engine::decision_for( $lead_id, $submission_id );
			$lead_events = $events[ $lead_id ] ?? array();
			$last_prospect = self::latest_event_time( $lead_events, self::PROSPECT_EVENTS );
			$last_contact = max( self::to_timestamp( $row['last_contact_at'] ?? '' ), self::to_timestamp( $row['first_contact_at'] ?? '' ), self::latest_event_time( $lead_events, self::CONTACT_EVENTS ) );
			$latest_activity = max( self::to_timestamp( $row['submitted_at'] ?? '' ), self::to_timestamp( $row['last_seen_at'] ?? '' ), $last_prospect );
			$candidate = array(
				'lead_id' => $lead_id, 'submission_id' => $submission_id,
				'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'],
				'project_slug' => $project_slug, 'project_name' => $projects[ $project_slug ] ?? $project_slug,
				'status' => $row['status'], 'feedback_status' => $row['feedback_status'],
				'submission_count' => absint( $row['submission_count'] ), 'events' => $lead_events,
				'commercial_facts' => self::commercial_facts( $row ), 'latest_activity_at' => $latest_activity,
				'correct_operator' => $operator_id > 0 && $operator_id === absint( $operators[ $operator_id ]->id ),
				'project_valid' => $project_slug !== '' && $project_slug !== Estandar_Leads_Repository::UNRESOLVED_PROJECT,
				'is_qa' => Estandar_Leads_QA_Policy::is_test_lead( $lead_id ),
				'policy_allowed' => ( $decision['routing_policy'] ?? 'route' ) !== 'skip' && ( $decision['notification_policy'] ?? 'notify' ) !== 'skip',
				'terminal_reopened' => self::terminal_reopened( $row, $lead_events ),
				'effective_contact' => $last_contact > 0 || absint( $row['contact_attempts'] ?? 0 ) > 0,
				'new_activity_after_contact' => $last_contact > 0 && $latest_activity > $last_contact,
			);
			$recommendation = self::evaluate( $candidate, 1 );
			if ( ! $recommendation ) { $diagnostics['excluded']++; continue; }
			if ( $recommendation['activation_score'] < (int) $settings['min_score'] ) { $diagnostics['below_min_score']++; continue; }
			if ( ! isset( $out[ $operator_id ] ) ) {
				$out[ $operator_id ] = array( 'operator_name' => $operators[ $operator_id ]->name, 'operator_email' => $operators[ $operator_id ]->email, 'recommendations' => array() );
			}
			$out[ $operator_id ]['recommendations'][] = $recommendation;
		}
		foreach ( $out as &$batch ) {
			usort( $batch['recommendations'], array( __CLASS__, 'compare_recommendations' ) );
			$batch['recommendations'] = array_slice( $batch['recommendations'], 0, (int) $settings['max_recommendations'] );
		}
		unset( $batch );
		$diagnostics['recommendations'] = array_sum( array_map( static function ( array $batch ): int { return count( $batch['recommendations'] ); }, $out ) );
		return $out;
	}

	private static function result( string $status, string $message ): array {
		return array(
			'status' => $status,
			'message' => $message,
			'recommendation_count' => 0,
			'recipients' => array(),
			'renderer' => '',
			'rendered' => false,
			'run_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function empty_reason( array $diagnostics ): string {
		if ( empty( $diagnostics['operators'] ) ) {
			return 'No existen operadores activos con email configurado.';
		}
		if ( empty( $diagnostics['scanned'] ) ) {
			return 'No existen leads pendientes asignados.';
		}
		if ( ! empty( $diagnostics['below_min_score'] ) ) {
			return 'No existen leads sobre el score mínimo configurado.';
		}
		return 'No existen leads pendientes que cumplan las reglas de elegibilidad.';
	}

	private static function finish_dispatch( array $result, bool $is_test ): void {
		self::record_run_state( $result );
		if ( ! $is_test ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			self::schedule_next();
		}
	}

	private static function record_run_state( array $result ): void {
		$previous = self::get_run_state();
		$sent = ( $result['status'] ?? '' ) === 'success';
		$state = array(
			'last_run_at' => (string) ( $result['run_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'last_send_at' => $sent ? (string) ( $result['run_at'] ?? gmdate( 'Y-m-d H:i:s' ) ) : (string) $previous['last_send_at'],
			'status' => sanitize_key( $result['status'] ?? 'error' ),
			'message' => sanitize_text_field( $result['message'] ?? '' ),
			'recommendation_count' => $sent ? absint( $result['recommendation_count'] ?? 0 ) : absint( $previous['recommendation_count'] ),
			'recipients' => $sent ? array_values( array_filter( array_map( 'sanitize_email', (array) ( $result['recipients'] ?? array() ) ) ) ) : (array) $previous['recipients'],
			'renderer' => $sent ? sanitize_text_field( $result['renderer'] ?? '' ) : (string) $previous['renderer'],
		);
		update_option( self::RUN_STATE_KEY, $state, false );
	}

	public static function compare_recommendations( array $a, array $b ): int {
		return ( $b['activation_score'] <=> $a['activation_score'] )
			?: strcmp( $b['latest_activity'], $a['latest_activity'] )
			?: ( $a['lead_id'] <=> $b['lead_id'] );
	}

	private static function events_by_lead( array $lead_ids ): array {
		global $wpdb;
		if ( empty( $lead_ids ) ) { return array(); }
		$ids = implode( ',', array_map( 'absint', $lead_ids ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT lead_id,event_type,to_status,metadata,created_at FROM {$wpdb->prefix}es_lead_events WHERE lead_id IN ({$ids}) AND created_at >= %s ORDER BY created_at ASC,id ASC", $since ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['type'] = $row['event_type'];
			$row['metadata'] = json_decode( (string) $row['metadata'], true ) ?: array();
			$out[ absint( $row['lead_id'] ) ][] = $row;
		}
		return $out;
	}

	private static function operators_by_id(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id,name,email FROM {$wpdb->prefix}es_operators WHERE active=1 AND email<>'' ORDER BY id ASC" );
		$out = array();
		foreach ( (array) $rows as $row ) { $out[ absint( $row->id ) ] = $row; }
		return $out;
	}

	private static function project_names(): array {
		$out = array();
		foreach ( Estandar_Leads_Projects::registry() as $project ) {
			$out[ (string) $project['project_slug'] ] = (string) $project['name'];
		}
		return $out;
	}

	private static function commercial_facts( array $row ): array {
		$flat = array();
		foreach ( array( 'context_json', 'form_fields_json', 'chatbot_json', 'scoring_json' ) as $column ) {
			$data = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			if ( is_array( $data ) ) { self::flatten( $data, $flat ); }
		}
		return array(
			'high_value' => self::fact_matches( $flat, array( 'commercial_fit', 'qualification', 'perfil_comercial', 'tipo_cliente' ), array( 'high_value', 'premium', 'strategic', 'alto_valor', 'empresa_constituida' ) ),
			'immediate_start' => self::fact_matches( $flat, array( 'inicio', 'fecha_inicio', 'cuando_comenzar', 'start_timeline' ), array( 'inmediato', 'ahora', 'lo_antes_posible', 'este_mes' ) ),
			'operating_company' => self::fact_matches( $flat, array( 'empresa_operando', 'business_status', 'company_status' ), array( '1', 'si', 'operando', 'activa', 'constituida' ) ),
			'provider_change' => self::fact_matches( $flat, array( 'cambio_proveedor', 'evaluar_cambio_proveedor', 'provider_change' ), array( '1', 'si', 'evaluando', 'cambiar', 'cotizando_alternativas' ) ),
		);
	}

	private static function flatten( array $data, array &$out ): void {
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) { self::flatten( $value, $out ); }
			elseif ( is_scalar( $value ) ) { $out[ $key ] = self::normalize_fact_value( $value ); }
		}
	}

	private static function normalize_fact_value( $value ): string {
		$value = strtolower( trim( remove_accents( (string) $value ) ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );
	}

	private static function fact_matches( array $flat, array $keys, array $values ): bool {
		foreach ( $keys as $key ) {
			if ( isset( $flat[ $key ] ) && in_array( $flat[ $key ], $values, true ) ) { return true; }
		}
		return false;
	}

	private static function latest_event_time( array $events, array $types ): int {
		$latest = 0;
		foreach ( $events as $event ) {
			if ( in_array( $event['type'] ?? '', $types, true ) ) { $latest = max( $latest, self::to_timestamp( $event['created_at'] ?? '' ) ); }
		}
		return $latest;
	}

	private static function has_event( array $events, array $types ): bool {
		return self::latest_event_time( $events, $types ) > 0;
	}

	private static function terminal_reopened( array $lead, array $events ): bool {
		$reopened_at = self::latest_event_time( $events, array( 'lead_recontacted', 'lead_operational_reopened' ) );
		if ( $reopened_at <= 0 ) { return false; }
		$terminal_at = 0;
		foreach ( $events as $event ) {
			if ( in_array( strtoupper( (string) ( $event['to_status'] ?? '' ) ), self::TERMINAL_STATUSES, true ) ) {
				$terminal_at = max( $terminal_at, self::to_timestamp( $event['created_at'] ?? '' ) );
			}
		}
		if ( $terminal_at <= 0 ) { $terminal_at = self::to_timestamp( $lead['updated_at'] ?? '' ); }
		return $reopened_at > $terminal_at;
	}

	private static function attach_action_urls( array &$recommendations, int $operator_id ): void {
		$workspace_token = Estandar_Leads_Operator_Workspace::create_token( $operator_id );
		$workspace = $workspace_token ? Estandar_Leads_Operator_Workspace::workspace_url( $workspace_token ) : '';
		foreach ( $recommendations as &$recommendation ) {
			$lead_id = (int) $recommendation['lead_id'];
			$submission_id = (int) $recommendation['submission_id'];
			$project = (string) $recommendation['project_slug'];
			$detail = add_query_arg( array( 'lead_id' => $lead_id, 'submission_id' => $submission_id ), $workspace );
			$feedback_token = Estandar_Leads_Tokens::create( $lead_id, $operator_id );
			$feedback = $feedback_token ? Estandar_Leads_Tokens::url( $feedback_token ) : $detail;
			$targets = array(
				'whatsapp_url' => 'https://wa.me/' . $recommendation['phone'],
				'call_url' => 'tel:+' . $recommendation['phone'],
				'classify_url' => $feedback,
				'detail_url' => $detail,
			);
			$types = array( 'whatsapp_url' => 'operator_clicked_whatsapp', 'call_url' => 'operator_clicked_call', 'classify_url' => 'operator_feedback_started', 'detail_url' => 'operator_clicked_lead' );
			foreach ( $targets as $key => $target ) {
				$token = Estandar_Leads_Tokens::sign_link( $lead_id, $submission_id, $types[ $key ], $target, 30, $project, $operator_id );
				$recommendation[ $key ] = rest_url( 'estandar-leads/v1/go/' . rawurlencode( $token ) );
			}
		}
		unset( $recommendation );
	}

	private static function fingerprint( array $recommendations ): string {
		$stable = array();
		foreach ( $recommendations as $item ) {
			$stable[] = array( $item['lead_id'], $item['priority'], $item['latest_activity'], $item['reason_codes'] );
		}
		return hash( 'sha256', wp_json_encode( $stable ) );
	}

	private static function is_duplicate( int $operator_id, string $fingerprint ): bool {
		$state = get_option( self::STATE_KEY, array() );
		return is_array( $state ) && ( $state[ $operator_id ]['fingerprint'] ?? '' ) === $fingerprint;
	}

	private static function remember( int $operator_id, string $fingerprint ): void {
		$state = get_option( self::STATE_KEY, array() );
		$state = is_array( $state ) ? $state : array();
		$state[ $operator_id ] = array( 'fingerprint' => $fingerprint, 'sent_at' => gmdate( 'Y-m-d H:i:s' ) );
		update_option( self::STATE_KEY, $state, false );
	}

	private static function recipients_for( string $operator_email, string $configured ): array {
		$list = self::email_list( $configured );
		if ( empty( $list ) && is_email( $operator_email ) ) { $list[] = sanitize_email( $operator_email ); }
		return $list;
	}

	private static function email_list( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', Estandar_Leads_Email::sanitize_email_list( $value ) ) ) ) );
	}

	private static function tone_copy( array $settings ): array {
		$copy = array(
			'disabled' => array( '', '' ),
			'professional' => array( 'Estas oportunidades muestran señales concretas para retomar una conversación comercial.', 'Prioriza el contacto oportuno y registra el resultado.' ),
			'close' => array( 'Hay personas que volvieron a mostrar interés. Este puede ser un buen momento para conversar.', 'Un contacto claro y oportuno puede destrabar la siguiente acción.' ),
			'inspiring' => array( 'Cada señal es una oportunidad para crear una conversación relevante.', 'Convierte la intención observada en una acción comercial concreta.' ),
			'custom' => array( (string) $settings['custom_intro'], (string) $settings['custom_closing'] ),
		);
		list( $intro, $closing ) = $copy[ $settings['tone'] ] ?? $copy['disabled'];
		return array( 'intro' => $intro, 'closing' => $closing );
	}

	private static function audit( array $batch, string $batch_id, string $recipient, string $event_type, bool $is_test, ?bool $result ): void {
		$lead_ids = array_map( 'absint', array_column( $batch['recommendations'], 'lead_id' ) );
		foreach ( $batch['recommendations'] as $recommendation ) {
			Estandar_Leads_Repository::insert_event( (int) $recommendation['lead_id'], $event_type, array(
				'submission_id' => (int) $recommendation['submission_id'],
				'metadata' => array(
					'batch_id' => $batch_id, 'recipient' => sanitize_email( $recipient ), 'lead_ids' => $lead_ids,
					'reasons' => $recommendation['reasons'], 'activation_priority' => $recommendation['priority'],
					'result' => $result, 'is_test' => $is_test, 'engine' => 'SALES_ACTIVATION_ENGINE_V1',
				),
			) );
		}
	}
}
