<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadScorer — LEAD_SCORE_CANONICAL_V1.
 *
 * Hace de Brain (Scoring) la ÚNICA fuente del score del lead: al ingerir un
 * lead (acción `estandar_lead_ingested`, prioridad 20 → ANTES del notificador
 * de operador que la lee en 25) recalcula con `Scoring::calculate()` y escribe
 * las columnas de scoring de `es_leads` (`behavioral_score`, `temperature`,
 * `score_updated_at`, `score_components_json`), con el MISMO contrato que
 * consumía el scorer legado retirado (total/capture/behavior/priority_reason/
 * behavior_detail) para no romper a sus lectores (email al operador, panel).
 *
 * Comportamiento (recontacto/engagement) se toma read-only de `es_lead_events`
 * vía `Adoption::behavior_signals()`; intención vía `IntentMap`.
 *
 * QUALIFICATION_V1 (2026-09-30): la cualificación del formulario (situación de
 * la empresa, contador actual, urgencia, necesidad) se deriva de
 * `es_lead_submissions.form_fields_json` de la submission que originó el lead,
 * y se pasa a `Scoring::calculate()` como `qualification` (peso 0.40). Antes se
 * pasaba `null` (hardcode) → todos los leads quedaban TIBIO (50), nunca HIGH.
 * Soporta los 3 vocabularios de form de AK (sections nuevas + fields_json legacy
 * + variante tipo_cliente/servicio/urgencia). Si no hay campos de cualificación
 * se devuelve `null` (comportamiento previo, sin regresión).
 *
 * Blindado: nunca lanza; si faltan tabla/columnas o Brain, no escribe y sigue.
 */
class LeadScorer
{
	const COLS_OPTION = 'estandar_suite_brain_leadscore_cols';
	const REV_OPTION  = 'estandar_suite_brain_leadscore_rev';

	/** Revisión del cache de columnas: súbela al cambiar `wanted_columns()`. */
	const COLS_REV = 2;

	/** Columnas que este scorer puede escribir en es_leads. */
	public static function wanted_columns(): array
	{
		return array( 'behavioral_score', 'temperature', 'score_updated_at', 'score_components_json', 'lead_intent' );
	}

	public static function register(): void
	{
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'on_lead_ingested' ), 20, 4 );
		// Adopción/reimportación de señales de comportamiento.
		add_action( 'estandar_suite_signal_recorded', array( __CLASS__, 'on_signal_recorded' ), 20, 1 );
	}

	public static function on_lead_ingested( $lead_id, $submission_id = 0, $is_new = false, $payload = array() ): void
	{
		self::score_lead( (int) $lead_id, (int) $submission_id );
	}

	public static function on_signal_recorded( $signal ): void
	{
		if ( ! is_array( $signal ) ) {
			return;
		}
		$type = (string) ( $signal['entity_type'] ?? '' );
		$id   = (int) ( $signal['entity_id'] ?? 0 );
		if ( $type === 'lead' && $id > 0 ) {
			self::score_lead( $id );
		}
	}

	/**
	 * Recalcula y persiste el score del lead. Devuelve el resultado del cálculo
	 * o null si no se pudo (nunca lanza).
	 *
	 * @param int $lead_id       Id en es_leads.
	 * @param int $submission_id Id en es_lead_submissions que originó el lead
	 *                           (0 → se busca la última del lead).
	 */
	public static function score_lead( int $lead_id, int $submission_id = 0 ): ?array
	{
		try {
			if ( $lead_id <= 0 || ! class_exists( __NAMESPACE__ . '\\Scoring' ) ) {
				return null;
			}
			global $wpdb;
			if ( ! isset( $wpdb ) || empty( $wpdb->prefix ) ) {
				return null;
			}
			$cols = self::writable_columns();
			if ( empty( $cols ) ) {
				return null;
			}

			$table = $wpdb->prefix . 'es_leads';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, email, phone, email_normalized, phone_normalized, lead_intent
				 FROM {$table} WHERE id = %d LIMIT 1",
				$lead_id
			), ARRAY_A );
			if ( ! is_array( $row ) ) {
				return null;
			}

			$capture = self::capture_score( $row );
			$events  = array();
			if ( class_exists( __NAMESPACE__ . '\\Adoption' ) ) {
				$events = Adoption::behavior_signals( $lead_id );
			}

			// Cualificación desde la submission (QUALIFICATION_V1).
			$signals       = self::submission_signals( $submission_id, $lead_id );
			$qualification = $signals['qualification'];

			// Intención: la existente manda; si vacía, se deriva del form.
			$intent_value = trim( (string) ( $row['lead_intent'] ?? '' ) );
			if ( $intent_value === '' && $signals['intent'] !== '' ) {
				$intent_value = $signals['intent'];
			}

			$intent = null;
			if ( $intent_value !== '' && class_exists( __NAMESPACE__ . '\\IntentMap' ) ) {
				$d = IntentMap::derive( $intent_value );
				if ( is_array( $d ) ) {
					$intent = (int) ( $d['points'] ?? 0 );
				}
			}

			$result = Scoring::calculate( array(
				'capture'         => $capture,
				'qualification'   => $qualification,
				'behavior_events' => $events,
				'profile'         => null,
				'intent'          => $intent,
			) );

			$components                    = (array) $result['components'];
			$components['priority_reason'] = '';
			$components['behavior_detail'] = array_values( array_map(
				static function ( $e ) { return (string) ( $e['type'] ?? '' ); },
				$events
			) );

			$set = array();
			if ( in_array( 'behavioral_score', $cols, true ) ) { $set['behavioral_score'] = (int) $result['total']; }
			if ( in_array( 'temperature', $cols, true ) ) { $set['temperature'] = (string) $result['temperature']; }
			if ( in_array( 'score_updated_at', $cols, true ) ) { $set['score_updated_at'] = current_time( 'mysql' ); }
			if ( in_array( 'score_components_json', $cols, true ) ) { $set['score_components_json'] = wp_json_encode( $components ); }
			// Poblar lead_intent si estaba vacío (alimenta IntentMap).
			if ( in_array( 'lead_intent', $cols, true )
				&& trim( (string) ( $row['lead_intent'] ?? '' ) ) === ''
				&& $intent_value !== ''
			) {
				$set['lead_intent'] = function_exists( 'sanitize_key' )
					? sanitize_key( $intent_value )
					: $intent_value;
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $table, $set, array( 'id' => $lead_id ) );
			}
			return $result;
		} catch ( \Throwable $e ) {
			// Nunca romper la ingesta por el score.
			return null;
		}
	}

	/** PURE: captura = 50 tel + 30 email + 20 nombre (mismo contrato que SignalLedger). */
	public static function capture_score( array $row ): int
	{
		$phone = trim( (string) ( $row['phone_normalized'] ?? '' ) );
		if ( $phone === '' ) { $phone = trim( (string) ( $row['phone'] ?? '' ) ); }
		$email = trim( (string) ( $row['email_normalized'] ?? '' ) );
		if ( $email === '' ) { $email = trim( (string) ( $row['email'] ?? '' ) ); }
		$name  = trim( (string) ( $row['name'] ?? '' ) );

		$score = 0;
		if ( $phone !== '' ) { $score += 50; }
		if ( $email !== '' ) { $score += 30; }
		if ( $name !== '' )  { $score += 20; }
		return $score;
	}

	// ── QUALIFICATION_V1 ────────────────────────────────────────────────────

	/**
	 * Deriva las señales de la submission que originó el lead.
	 *
	 * @return array{qualification:?int,intent:string}
	 */
	public static function submission_signals( int $submission_id, int $lead_id = 0 ): array
	{
		$out = array( 'qualification' => null, 'intent' => '' );
		try {
			global $wpdb;
			if ( ! isset( $wpdb ) || empty( $wpdb->prefix ) ) {
				return $out;
			}
			$table = $wpdb->prefix . 'es_lead_submissions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return $out;
			}
			$json = null;
			if ( $submission_id > 0 ) {
				$json = $wpdb->get_var( $wpdb->prepare(
					"SELECT form_fields_json FROM {$table} WHERE id = %d LIMIT 1",
					$submission_id
				) );
			} elseif ( $lead_id > 0 ) {
				$json = $wpdb->get_var( $wpdb->prepare(
					"SELECT form_fields_json FROM {$table} WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
					$lead_id
				) );
			}
			if ( ! is_string( $json ) || $json === '' ) {
				return $out;
			}
			$fields = json_decode( $json, true );
			if ( ! is_array( $fields ) ) {
				return $out;
			}
			$out['qualification'] = self::qualification_from_fields( $fields );
			$out['intent']        = self::intent_from_fields( $fields );
		} catch ( \Throwable $e ) {
			// Blindado.
		}
		return $out;
	}

	/**
	 * PURE: qualification 0–100 desde los campos del formulario, o null si no
	 * hay ninguna pregunta de cualificación (preserva el comportamiento previo).
	 *
	 * Soporta los vocabularios de AK:
	 *   - sections nuevas: `que_necesitas_resolver` / `cual_es_la_situacion_actual_de_tu_empresa` / `actualmente_tienes_contador` / `cuando_necesitas_comenzar`
	 *   - fields_json legacy: `necesidad_principal` / `situacion_empresa` / `contador_actual` / `plazo_inicio`
	 *   - variante antigua: `tipo_cliente` / `servicio` / `urgencia`
	 */
	public static function qualification_from_fields( array $fields ): ?int
	{
		// ACACIOS_RE_V1 (2026-10-07): vocabulario inmobiliario (when_buy /
		// how_finance / project_slug). Se evalúa primero; devuelve null si el
		// payload no usa ese vocabulario → el scoring de AK/estandar.cl queda
		// intacto (mismo gate semántico que la rama contable).
		$re = self::real_estate_qualification( $fields );
		if ( $re !== null ) {
			return $re;
		}

		$situ  = self::field_value( $fields, array( 'cual_es_la_situacion_actual_de_tu_empresa', 'situacion_empresa', 'tipo_cliente' ) );
		$cont  = self::field_value( $fields, array( 'actualmente_tienes_contador', 'contador_actual' ) );
		$plazo = self::field_value( $fields, array( 'cuando_necesitas_comenzar', 'plazo_inicio', 'urgencia' ) );
		$nece  = self::field_value( $fields, array( 'que_necesitas_resolver', 'necesidad_principal', 'servicio' ) );

		if ( $situ === '' && $cont === '' && $plazo === '' && $nece === '' ) {
			return null;
		}

		// Normaliza slugs con guion (variante tipo_cliente/servicio/urgencia).
		$situ  = str_replace( '-', '_', $situ );
		$cont  = str_replace( '-', '_', $cont );
		$plazo = str_replace( '-', '_', $plazo );
		$nece  = str_replace( '-', '_', $nece );

		$situ_map = array(
			'operando_facturando'                       => 40,
			'esta_funcionando_y_facturando'             => 40,
			'constituida_sin_operar'                    => 15,
			'esta_constituida_pero_aun_no_opera'        => 15,
			'empresa_constituida'                       => 15,
			'en_creacion'                               => 5,
			'la_estoy_creando'                          => 5,
			'creando_empresa'                           => 5,
			'actividad_independiente'                   => 0,
			'trabajo_como_independiente'                => 0,
			'sin_empresa'                               => 0,
			'aun_no_tengo_empresa'                      => 0,
			'persona_natural'                           => 0,
		);
		$cont_map = array(
			'si_evaluando'                              => 25,
			'si_estoy_evaluando_alternativas'           => 25,
			'si_quiere_cambiar'                         => 25,
			'si_pero_quiero_cambiar'                    => 25,
			'no_necesita'                               => 0,
			'no_necesito_contratar_uno'                 => 0,
			'no_aplica'                                 => 0,
			'todavia_no_aplica'                         => 0,
		);
		$plazo_map = array(
			'inmediato'                                 => 20,
			'lo_antes_posible'                          => 20,
			'esta_semana'                               => 20,
			'este_mes'                                  => 15,
			'durante_este_mes'                          => 15,
			'uno_tres_meses'                            => 5,
			'en_1_a_3_meses'                            => 5,
			'solo_evaluando'                            => 0,
			'solo_estoy_evaluando_opciones'             => 0,
			'solo_cotizando'                            => 0,
		);
		$nece_map = array(
			'contabilidad_mensual'                      => 15,
			'necesito_llevar_la_contabilidad_de_mi_empresa' => 15,
			'cambiar_contador'                          => 15,
			'quiero_cambiar_de_contador'                => 15,
			'asesoria_contable_mensual'                 => 15,
			'asesoria_tributaria_empresa'               => 15,
			'necesito_asesoria_tributaria_para_mi_empresa'  => 15,
			'remuneraciones'                            => 15,
			'necesito_apoyo_con_remuneraciones'         => 15,
			'recursos_humanos_payroll'                  => 15,
			'estrategia_tributaria'                     => 15,
			'estados_financieros'                       => 10,
			'control_de_gestion'                        => 10,
			'crear_empresa_con_contabilidad'            => 5,
			'quiero_crear_una_empresa_y_contratar_contabilidad' => 5,
			'abrir_una_empresa'                         => 5,
			'proyeccion_de_renta'                       => 5,
			'tramite_puntual'                           => 3,
			'necesito_resolver_un_tramite_puntual'      => 3,
			'orientacion_emprender'                     => 0,
			'quiero_emprender_pero_aun_no_se_que_necesito' => 0,
			'financiamiento_subsidio'                   => 0,
			'otro'                                      => 0,
		);

		// Gate de identidad semántica: el mapeo SOLO se aplica cuando la señal
		// primaria (situación de la empresa) es un valor reconocido. Así un sitio
		// cuyo formulario use el mismo nombre de campo con otro vocabulario
		// (p. ej. estandar.cl: `situacion_empresa=operando`) no se ve afectado
		// (se devuelve null → comportamiento previo, sin regresión).
		if ( ! isset( $situ_map[ $situ ] ) ) {
			return null;
		}

		$q = $situ_map[ $situ ];
		if ( isset( $cont_map[ $cont ] ) )   { $q += $cont_map[ $cont ]; }
		if ( isset( $plazo_map[ $plazo ] ) ) { $q += $plazo_map[ $plazo ]; }
		if ( isset( $nece_map[ $nece ] ) )   { $q += $nece_map[ $nece ]; }

		// LOW signals (objectives.yaml L2/L3/L6/L7) sobre el texto libre.
		$msg = self::message_text( $fields );
		if ( self::contains_any( $msg, array( 'falta capital', 'me falta capital', 'necesito capital', 'busco capital', 'capital para', 'capital de trabajo', 'necesito dinero', 'falta dinero', 'falta plata', 'levantar capital', 'necesito inversion', 'inversion para', 'credito', 'crédito', 'financiamiento', 'financiación', 'subsidio', 'prestamo', 'préstamo' ) ) ) {
			$q -= 35;
		}
		if ( self::contains_any( $msg, array( 'apuesta', 'apuestas', 'betano', 'bet365', 'casa de apuesta', 'casino', 'retirar dinero' ) ) ) {
			$q -= 45;
		}
		if ( self::contains_any( $msg, array( 'emprendimiento', 'emprendedor', 'emprender', 'mercaderia', 'mercadería', 'materiales', 'estilista', 'peluqueria', 'peluquería' ) ) ) {
			$q -= 20;
		}
		// H7 (objetivos: ticket alto, no recortar por arriba).
		if ( self::contains_any( $msg, array( 'internacional', 'matriz en el extranjero', 'matriz fuera', 'sede en chile', 'expansion internacional', 'expansión internacional', 'doble tributacion', 'doble tributación', 'inversion extranjera', 'inversión extranjera', 'holding', 'grupo de empresas', 'varias sociedades', 'operar en otro pais', 'operar en otro país' ) ) ) {
			$q += 10;
		}

		return max( 0, min( 100, $q ) );
	}

	/**
	 * PURE: qualification (0–100) para formularios inmobiliarios (Acacios).
	 *
	 * Vocabulario: `when_buy` (urgencia, H3), `how_finance` (intención de
	 * financiar, H2) y `project_slug` (proyecto concreto, H1/H4). Devuelve null
	 * cuando el payload NO usa ese vocabulario (ni when_buy ni how_finance), para
	 * no alterar el scoring de otros sitios.
	 */
	public static function real_estate_qualification( array $fields ): ?int
	{
		$when = str_replace( '-', '_', strtolower( trim( self::field_value( $fields, array( 'when_buy', 'cuando_comprar', 'plazo_compra' ) ) ) ) );
		$how  = str_replace( '-', '_', strtolower( trim( self::field_value( $fields, array( 'how_finance', 'financiamiento', 'como_financiar' ) ) ) ) );

		// Gate de identidad semántica: sin vocabulario inmobiliario → null.
		if ( $when === '' && $how === '' ) {
			return null;
		}

		$when_map = array(
			'asap'            => 40, 'lo_antes_posible' => 40, 'inmediato' => 40,
			'm1_3'            => 25, '1_3_meses' => 25, 'este_mes' => 25,
			'm3_6'            => 15, '3_6_meses' => 15, 'este_semestre' => 15,
			'm6_12'           => 5,  '6_12_meses' => 5,
			'solo_explorando' => 0,  'explorando' => 0, 'no_se' => 0,
		);
		$how_map = array(
			'credito'           => 30, 'credito_hipotecario' => 30, 'hipotecario' => 30,
			'contado'           => 25,
			'subsidio'          => 20,
			'no_lo_tengo_aun'   => 5, 'por_ver' => 5, 'aun_no_se' => 5,
		);

		// Al menos un valor reconocido (evita ruido de vocabularios ajenos).
		if ( ! isset( $when_map[ $when ] ) && ! isset( $how_map[ $how ] ) ) {
			return null;
		}

		$q = 0;
		// H1/H4: proyecto concreto = intención real sobre un desarrollo.
		if ( self::field_value( $fields, array( 'project_slug', 'project', 'proyecto' ) ) !== '' ) {
			$q += 35;
		}
		if ( isset( $when_map[ $when ] ) ) { $q += $when_map[ $when ]; }
		if ( isset( $how_map[ $how ] ) )  { $q += $how_map[ $how ]; }

		return max( 0, min( 100, $q ) );
	}

	/** PURE: primer valor no vacío de la lista de claves. */
	public static function field_value( array $fields, array $keys ): string
	{
		foreach ( $keys as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$v = $fields[ $k ];
			if ( is_array( $v ) ) {
				$v = implode( ' ', array_map( 'strval', $v ) );
			}
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$v = trim( (string) $v );
			if ( $v !== '' ) {
				return $v;
			}
		}
		return '';
	}

	/** PURE: intención del form (para IntentMap), sanitizada. */
	public static function intent_from_fields( array $fields ): string
	{
		$v = self::field_value( $fields, array( 'que_necesitas_resolver', 'necesidad_principal', 'intent', 'lead_intent' ) );
		if ( $v === '' ) {
			return '';
		}
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $v );
		}
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) );
	}

	/** PURE: texto libre concatenado para señales LOW/HIGH. */
	public static function message_text( array $fields ): string
	{
		$parts = array();
		foreach ( array( 'hay_algo_mas_que_debamos_saber', 'mensaje', 'message', 'comments', 'comentario', 'consulta' ) as $k ) {
			if ( isset( $fields[ $k ] ) && is_scalar( $fields[ $k ] ) ) {
				$parts[] = (string) $fields[ $k ];
			}
		}
		$txt = implode( ' ', $parts );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $txt ) : strtolower( $txt );
	}

	/** PURE: true si el texto contiene alguna de las agujas. */
	public static function contains_any( string $haystack, array $needles ): bool
	{
		if ( $haystack === '' ) {
			return false;
		}
		foreach ( $needles as $n ) {
			if ( $n !== '' && strpos( $haystack, $n ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** Columnas de scoring existen en es_leads (cacheado). '' = sin tabla. */
	public static function writable_columns(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $wpdb->prefix ) ) {
			return array();
		}
		$table  = $wpdb->prefix . 'es_leads';
		$cached = get_option( self::COLS_OPTION, null );
		if ( is_array( $cached )
			&& ( $cached['table'] ?? '' ) === $table
			&& (int) ( $cached['rev'] ?? 0 ) === self::COLS_REV
		) {
			return (array) ( $cached['cols'] ?? array() );
		}

		$cols = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$existing = is_array( $existing ) ? $existing : array();
			foreach ( self::wanted_columns() as $c ) {
				if ( in_array( $c, $existing, true ) ) {
					$cols[] = $c;
				}
			}
		}
		update_option( self::COLS_OPTION, array( 'table' => $table, 'cols' => $cols, 'rev' => self::COLS_REV ), false );
		return $cols;
	}

	/** Invalida la caché de columnas (p. ej. tras migraciones). */
	public static function flush_columns_cache(): void
	{
		delete_option( self::COLS_OPTION );
	}
}
