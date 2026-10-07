<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AiContext — equivalente v2 del "IA Context" del legacy estandar-leads.
 *
 * Genera un export en Markdown con la inteligencia comercial consolidada de un
 * lead para análisis por IA, correos y reportes, y para mejorar el embudo.
 * Lee SOLO las tablas es_* del CRM (misma capa de datos que escribe la v2 por
 * el bridge) y reutiliza la semántica del panel (LeadsPanel::lead_pipeline)
 * para que el artifact coincida con lo que las operadoras ven en la ficha.
 *
 * Diferencias con el legacy (decisión 2026-08-23): equivalente nativo más
 * liviano, sin dependencias de clases legacy; no incluye state machine ni
 * raw sources completos del motor — incluye snapshot comercial, origen/
 * atribución, contexto de negocio, calidad de datos, pipeline (10 pasos),
 * timeline, chatbot/analytics y submissions.
 */
class AiContext {

	const EXPORT_TIMEZONE = 'America/Santiago';

	/**
	 * @param int    $lead_id
	 * @param string $view  'commercial' (default) para operación; 'technical'
	 *                      incluye metadata de eventos en el timeline.
	 */
	public static function build_for_lead( $lead_id, $view = 'commercial' ): string {
		global $wpdb;
		$view    = $view === 'technical' ? 'technical' : 'commercial';
		$lead_id = absint( $lead_id );
		if ( ! $lead_id || ! isset( $wpdb ) ) {
			return '';
		}

		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id ), ARRAY_A );
		if ( empty( $lead ) ) {
			return '';
		}

		$p = \Estandar\Core\LeadsPanel::lead_pipeline( $lead_id );
		if ( empty( $p ) ) {
			return '';
		}
		$subs   = (array) $p['submissions'];
		$events = (array) $p['events'];
		$steps  = (array) $p['steps'];

		// Mensaje del último submission (no incluido en lead_pipeline) para
		// la sección de calidad de datos.
		$message = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT message FROM {$wpdb->prefix}es_lead_submissions
			 WHERE lead_id = %d AND message IS NOT NULL AND message != ''
			 ORDER BY submitted_at DESC, id DESC LIMIT 1", $lead_id ) );

		$md  = "# IA Context — Lead #" . $lead_id . "\n\n";
		$md .= "_Export de inteligencia comercial (estandar-core v2) · lead_id " . $lead_id
			. " · " . self::ts_display( \Estandar\Core\Time::now() ) . "_\n\n";
		$md .= self::section( 'Snapshot comercial', self::build_snapshot( $lead, $steps ) );
		$md .= self::section( 'Lead', self::build_lead( $lead ) );
		$md .= self::section( 'Origen y atribución', self::build_origin( $lead, $subs ) );
		$md .= self::section( 'Contexto de negocio', self::build_business_context( $subs ) );
		$md .= self::section( 'Calidad de datos', self::build_data_quality( $lead, $message ) );
		$md .= self::section( 'Pipeline', self::build_pipeline( $steps ) );
		$md .= self::section( 'Timeline de eventos', self::build_timeline( $events, $view ) );
		$md .= self::section( 'Chatbot y analytics', self::build_engagement( $p ) );
		$md .= self::section( 'Submissions', self::build_submissions( $subs ) );

		return $md;
	}

	private static function section( string $title, string $body ): string {
		return "\n## " . $title . "\n\n" . ( $body !== '' ? $body : "Sin datos.\n" );
	}

	private static function build_snapshot( array $lead, array $steps ): string {
		$created = (string) ( $lead['created_at'] ?? ( $lead['first_seen_at'] ?? '' ) );
		$age_h   = self::hours_since( $created );
		$status  = \Estandar\Core\LeadsPanel::status_label(
			(int) ( $lead['feedback_completed'] ?? 0 ),
			(string) ( $lead['last_classified_at'] ?? '' ),
			(string) ( $lead['first_contact_at'] ?? '' ),
			(int) ( $lead['contact_attempts'] ?? 0 )
		);
		$recontacts = 0;
		try {
			$rc = \Estandar\Core\LeadsPanel::recontact_counts( array( (int) $lead['id'] ) );
			$recontacts = (int) ( $rc[ (int) $lead['id'] ] ?? 0 );
		} catch ( \Exception $e ) {
			$recontacts = 0;
		}

		$rows = array(
			'Lead'         => self::display( (string) ( $lead['name'] ?? '' ) ),
			'Email'        => self::display( (string) ( $lead['email'] ?? '' ) ),
			'Phone'        => self::display( self::phone_display( (string) ( $lead['phone'] ?? '' ) ) ),
			'Status'       => self::display( (string) ( $lead['status'] ?? '' ) ),
			'Estado panel' => $status,
			'Quality'      => self::display( (string) ( $lead['quality'] ?? '' ) ),
			'Project'      => self::display( (string) ( $lead['project_slug'] ?? '' ) ),
			'Operator'     => self::display( (string) ( $lead['assigned_operator_name'] ?? '' ) ),
			'Temperature'  => self::display( (string) ( $lead['temperature'] ?? '' ) ),
			'Priority'     => self::display( (string) ( $lead['priority'] ?? '' ) ),
			'Behavioral score' => self::display( (string) ( $lead['behavioral_score'] ?? '' ) ),
			'Recontactos'  => (string) $recontacts,
			'Lead age'     => $age_h . 'h desde captura',
			'SLA'          => $age_h >= 48 ? 'due_now · vencido' : 'within_sla · ' . max( 0, 48 - $age_h ) . 'h restantes',
			'Sales stage'  => self::display( (string) ( $lead['sales_stage'] ?? '' ) ),
			'Intent'       => self::display( (string) ( $lead['lead_intent'] ?? '' ) ),
			'UUID'         => self::display( (string) ( $lead['lead_uuid'] ?? '' ) ),
		);
		$out = '';
		foreach ( $rows as $k => $v ) {
			$out .= "- **" . $k . ":** " . $v . "\n";
		}
		if ( (string) ( $lead['commercial_fit'] ?? '' ) !== '' ) {
			$out .= "- **Fit (assessment):** " . self::display( (string) $lead['commercial_fit'] )
				. ' · ' . self::display( (string) ( $lead['fit_reason'] ?? '' ) )
				. ' · conf ' . ( (int) ( $lead['fit_confidence'] ?? 0 ) ?: 'n/a' ) . "\n";
		}
		return $out;
	}

	private static function build_lead( array $lead ): string {
		$rows = array(
			'Name'            => self::display( (string) ( $lead['name'] ?? '' ) ),
			'Email'           => self::display( (string) ( $lead['email'] ?? '' ) ),
			'Phone'           => self::display( self::phone_display( (string) ( $lead['phone'] ?? '' ) ) ),
			'First seen'      => self::ts_display( (string) ( $lead['first_seen_at'] ?? '' ) ),
			'Last seen'       => self::ts_display( (string) ( $lead['last_seen_at'] ?? '' ) ),
			'First source'    => self::display( (string) ( $lead['first_source_plugin'] ?? '' ) ),
			'Assigned at'     => self::ts_display( (string) ( $lead['assigned_at'] ?? '' ) ),
			'First contact'   => self::ts_display( (string) ( $lead['first_contact_at'] ?? '' ) ),
			'Contact attempts'=> (string) ( $lead['contact_attempts'] ?? 0 ),
			'Last classified' => self::ts_display( (string) ( $lead['last_classified_at'] ?? '' ) ),
		);
		$out = '';
		foreach ( $rows as $k => $v ) {
			$out .= "- **" . $k . ":** " . $v . "\n";
		}
		return $out;
	}

	private static function build_origin( array $lead, array $subs ): string {
		$first = $subs[0] ?? array();
		$landing = (string) ( $first['landing_url'] ?? ( $lead['first_landing_url'] ?? '' ) );
		$referrer = (string) ( $first['referrer'] ?? ( $lead['first_referrer'] ?? '' ) );
		$session = (string) ( $first['session_id'] ?? ( $lead['first_session_id'] ?? '' ) );
		$click = array_filter( array(
			'gclid'   => (string) ( $lead['first_gclid'] ?? '' ),
			'gbraid'  => (string) ( $lead['first_gbraid'] ?? '' ),
			'wbraid'  => (string) ( $lead['first_wbraid'] ?? '' ),
			'fbclid'  => (string) ( $lead['first_fbclid'] ?? '' ),
			'msclkid' => (string) ( $lead['first_msclkid'] ?? '' ),
		) );

		$rows = array(
			'Landing'   => $landing !== '' ? $landing : 'No disponible',
			'Source'    => self::display( (string) ( $first['utm_source'] ?? ( $lead['first_utm_source'] ?? '' ) ) ),
			'Medium'    => self::display( (string) ( $first['utm_medium'] ?? ( $lead['first_utm_medium'] ?? '' ) ) ),
			'Campaign'  => self::display( (string) ( $first['utm_campaign'] ?? ( $lead['first_utm_campaign'] ?? '' ) ) ),
			'Referrer'  => $referrer !== '' ? $referrer : 'No disponible',
			'Session'   => $session !== '' ? $session : 'NULL',
			'Click IDs' => $click ? implode( ', ', $click ) : 'NULL',
			'IP'        => self::display( (string) ( $lead['first_ip_address'] ?? '' ) ),
		);
		$out = '';
		foreach ( $rows as $k => $v ) {
			$out .= "- **" . $k . ":** " . $v . "\n";
		}
		return $out;
	}

	private static function build_business_context( array $subs ): string {
		$exclude = array(
			'name', 'nombre', 'lastname', 'apellido', 'email', 'phone', 'telefono',
			'project', 'proyecto', 'project_slug', 'project_area',
			'region', 'región', 'city', 'ciudad', 'comuna', 'commune', 'location', 'ubicacion', 'ubicación',
			'uuid', 'session_id', 'submission_id', 'entry_id', 'created_at', 'updated_at',
			'gclid', 'fbclid', 'wbraid', 'gbraid', 'ip', 'user_agent', 'nonce', 'referer',
			'source_channel', 'intent', 'lead_intent', 'conversation_summary', 'scoring',
		);
		$lines = array();
		$sub = end( $subs );
		if ( is_array( $sub ) ) {
			$fields = json_decode( (string) ( $sub['form_fields_json'] ?? '' ), true );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $fk => $fv ) {
					if ( ! is_scalar( $fv ) ) { continue; }
					$norm = strtolower( trim( (string) $fk ) );
					if ( $norm === '' || $norm[0] === '_' || strpos( $norm, 'utm_' ) === 0 || in_array( $norm, $exclude, true ) ) {
						continue;
					}
					$val = trim( str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $fv ) );
					if ( $val === '' ) { continue; }
					if ( mb_strlen( $val ) > 300 ) { $val = mb_substr( $val, 0, 300 ) . '…'; }
					$label = ucfirst( trim( str_replace( array( '_', '-' ), ' ', (string) $fk ) ) );
					$lines[] = "- **" . $label . ":** " . $val;
				}
			}
		}
		return $lines ? implode( "\n", array_unique( $lines ) ) . "\n" : "NONE\n";
	}

	private static function build_data_quality( array $lead, string $message ): string {
		$email = (string) ( $lead['email'] ?? '' );
		$phone = (string) ( $lead['phone'] ?? '' );
		$email_typo = $email !== '' ? self::email_typo_reason( $email ) : '';
		$email_valid = function_exists( 'is_email' ) ? (bool) is_email( $email ) : (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
		$email_ok = $email !== '' && $email_valid && $email_typo === '';
		$phone_ok = $phone !== '' && (bool) preg_match( '/^56\d{9}$/', (string) preg_replace( '/\D+/', '', $phone ) );
		$message_ok = $message !== '' && ! self::message_looks_noisy( $message );

		$rows = array(
			'Email'   => array( $email_ok, self::display( $email ), $email === '' ? 'missing' : ( $email_ok ? '' : ( $email_typo !== '' ? $email_typo : 'invalid syntax' ) ) ),
			'Phone'   => array( $phone_ok, self::display( self::phone_display( $phone ) ), $phone === '' ? 'missing' : ( $phone_ok ? '' : 'malformed / no móvil chileno' ) ),
			'Message' => array( $message_ok, self::short_text( $message, 160 ), $message === '' ? 'optional · not provided' : ( $message_ok ? '' : 'probable test/noise' ) ),
		);
		$out = '';
		foreach ( $rows as $label => $r ) {
			$out .= "- **" . $label . ":** " . ( $r[0] ? 'PASS' : 'WARNING' ) . ' · ' . $r[1] . ( $r[2] !== '' ? ' · ' . $r[2] : '' ) . "\n";
		}
		return $out;
	}

	private static function build_pipeline( array $steps ): string {
		if ( empty( $steps ) ) {
			return "Sin pasos.\n";
		}
		$tag = array( 'done' => 'Hecho', 'pending' => 'Pendiente', 'missing' => 'Falta', 'skip' => 'No usado' );
		$out = '';
		foreach ( $steps as $st ) {
			$out .= "- [" . ( $tag[ $st['status'] ] ?? $st['status'] ) . "] **" . $st['label'] . ":** " . $st['detail'] . "\n";
		}
		return $out;
	}

	private static function build_timeline( array $events, string $view ): string {
		if ( empty( $events ) ) {
			return "No events recorded.\n";
		}
		$out = '';
		foreach ( $events as $ev ) {
			$label = \Estandar\Core\LeadsPanel::pipeline_event_label( (string) $ev['event_type'] );
			$extra = '';
			if ( $view === 'technical' && ! empty( $ev['metadata'] ) ) {
				$meta = json_decode( (string) $ev['metadata'], true );
				if ( is_array( $meta ) ) {
					$kvs = array();
					foreach ( $meta as $k => $v ) {
						if ( is_scalar( $v ) && (string) $v !== '' && ! in_array( $k, array( 'email', 'phone', 'ip_address' ), true ) ) {
							$kvs[] = $k . '=' . self::short_text( (string) $v, 80 );
						}
					}
					if ( $kvs ) {
						$extra = ' · ' . implode( ' ', $kvs );
					}
				}
			}
			$out .= "- " . self::ts_display( (string) ( $ev['created_at'] ?? '' ), '' ) . " — " . $label . $extra . "\n";
		}
		return $out;
	}

	private static function build_engagement( array $p ): string {
		$chat = (array) $p['chatbot'];
		$an   = (array) $p['analytics'];
		$out  = '';
		$out .= "- **Chatbot:** " . ( ( (int) ( $chat['messages'] ?? 0 ) > 0 || (int) ( $chat['conversations'] ?? 0 ) > 0 )
			? (int) ( $chat['messages'] ?? 0 ) . ' mensajes · ' . (int) ( $chat['conversations'] ?? 0 ) . ' conversación(es)'
			: 'No usó el chatbot' ) . "\n";
		if ( ! empty( $an ) && (int) ( $an['pageviews'] ?? 0 ) > 0 ) {
			$out .= "- **Sesión:** " . (int) $an['pageviews'] . ' páginas · ' . (int) ( $an['duration_sec'] ?? 0 ) . 's\n';
			$out .= "- **Primera visita:** " . self::ts_display( (string) ( $an['first_seen'] ?? '' ), '' ) . "\n";
			$out .= "- **Última actividad:** " . self::ts_display( (string) ( $an['last_activity'] ?? '' ), '' ) . "\n";
		} else {
			$out .= "- **Analytics de sesión:** sin datos\n";
		}
		return $out;
	}

	private static function build_submissions( array $subs ): string {
		if ( empty( $subs ) ) {
			return "Sin submissions.\n";
		}
		$out = '';
		foreach ( $subs as $i => $s ) {
			$out .= "### Submission " . ( $i + 1 ) . " · ID " . (int) $s['id'] . "\n";
			$out .= "- **Date:** " . self::ts_display( (string) ( $s['submitted_at'] ?? '' ), 'NULL' ) . "\n";
			$out .= "- **Form:** " . self::display( (string) ( $s['source_form_id'] ?? '' ) ) . "\n";
			$out .= "- **Origin:** " . self::display( (string) ( $s['source_plugin'] ?? '' ) ) . "\n";
			$out .= "- **Channel:** " . self::display( (string) ( $s['utm_source'] ?? '' ) )
				. ( (string) ( $s['utm_medium'] ?? '' ) !== '' ? '/' . (string) $s['utm_medium'] : '' ) . "\n";
			$out .= "- **Campaign:** " . self::display( (string) ( $s['utm_campaign'] ?? '' ) ) . "\n";
			$out .= "- **Landing:** " . ( (string) ( $s['landing_url'] ?? '' ) !== '' ? (string) $s['landing_url'] : 'NULL' ) . "\n";
			$fields = json_decode( (string) ( $s['form_fields_json'] ?? '' ), true );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $fk => $fv ) {
					if ( ! is_scalar( $fv ) ) { continue; }
					$val = trim( (string) $fv );
					if ( $val === '' ) { continue; }
					$out .= "  - " . ucwords( str_replace( array( '_', '-' ), ' ', trim( (string) $fk ) ) ) . ": "
						. self::short_text( $val, 200 ) . "\n";
				}
			}
		}
		return $out;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function ts_display( $value, string $empty = 'No disponible' ): string {
		$value = trim( (string) $value );
		if ( $value === '' || strtoupper( $value ) === 'NULL' || $value === '—' ) {
			return $empty;
		}
		// TIMEZONE_V1: los valores guardados viven en la zona de WordPress.
		return \Estandar\Core\Time::display( $value, 'Y-m-d H:i:s' );
	}

	private static function display( string $value, string $empty = 'No disponible' ): string {
		$value = trim( $value );
		return $value === '' || strtoupper( $value ) === 'NULL' || $value === '—' ? $empty : $value;
	}

	private static function short_text( string $value, int $limit ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return mb_strlen( $value ) > $limit ? mb_substr( $value, 0, $limit ) . '…' : $value;
	}

	private static function phone_display( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( strlen( $digits ) === 11 && strpos( $digits, '56' ) === 0 ) {
			return '+56 ' . substr( $digits, 2, 1 ) . ' ' . substr( $digits, 3, 4 ) . ' ' . substr( $digits, 7 );
		}
		return $phone;
	}

	private static function email_typo_reason( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $email === '' || strpos( $email, '@' ) === false ) {
			return '';
		}
		$domain = substr( strrchr( $email, '@' ), 1 );
		$typos = array(
			'gmail.con' => 'probable typo: gmail.com',
			'gmai.com' => 'probable typo: gmail.com',
			'gmial.com' => 'probable typo: gmail.com',
			'hotmai.com' => 'probable typo: hotmail.com',
			'hotmail.con' => 'probable typo: hotmail.com',
			'outlok.com' => 'probable typo: outlook.com',
			'outlook.con' => 'probable typo: outlook.com',
			'yaho.com' => 'probable typo: yahoo.com',
			'yahoo.con' => 'probable typo: yahoo.com',
			'hoail.com' => 'probable typo: gmail.com/hotmail.com',
		);
		if ( isset( $typos[ $domain ] ) ) {
			return $typos[ $domain ];
		}
		// TYPO_GENERIC_V1 (2026-08-23): sufijos claramente erróneos de .com
		// sin importar el dominio (cubre casos reales como miguel_lyon02@hoail.c0m).
		if ( substr( $domain, -4 ) === '.con' || substr( $domain, -4 ) === '.c0m' ) {
			return 'probable typo: .com';
		}
		return '';
	}

	private static function message_looks_noisy( string $message ): bool {
		$m = strtolower( trim( $message ) );
		if ( $m === '' ) {
			return false;
		}
		if ( mb_strlen( $m ) < 4 ) {
			return true;
		}
		return (bool) preg_match( '/^(test|testing|prueba|asdf|qwerty|na|n\/a|demo|hola|1234+|xxx+)$/u', $m );
	}

	private static function hours_since( string $datetime ): int {
		if ( $datetime === '' ) {
			return 0;
		}
		$ts = \Estandar\Core\Time::epoch( $datetime );
		if ( ! $ts ) {
			return 0;
		}
		return (int) floor( max( 0, time() - $ts ) / HOUR_IN_SECONDS );
	}
}
