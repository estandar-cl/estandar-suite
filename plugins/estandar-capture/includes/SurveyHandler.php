<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SurveyHandler — runtime AJAX de encuestas nativas v2 (F2).
 *
 * Puerto del Estandar_Survey_Handler legacy al producto nativo, con acciones
 * AJAX PROPIAS (estandar_capture_survey_*) para no colisionar con el legacy
 * activo en producción (coexistencia). Storage → SurveyDb (tablas v2 propias);
 * validación/scoring → ConversationEngine; correo → Core\Mail;
 * completion_strategy=lead → Ingestion::ingest (evento canónico v2).
 */
class SurveyHandler
{
	const NONCE = 'estandar_capture_survey_nonce';

	public static function init(): void
	{
		add_action( 'wp_ajax_nopriv_estandar_capture_survey_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'wp_ajax_estandar_capture_survey_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'wp_ajax_nopriv_estandar_capture_survey_event', array( __CLASS__, 'handle_event' ) );
		add_action( 'wp_ajax_estandar_capture_survey_event', array( __CLASS__, 'handle_event' ) );
		add_action( 'wp_ajax_nopriv_estandar_capture_survey_answer', array( __CLASS__, 'handle_answer' ) );
		add_action( 'wp_ajax_estandar_capture_survey_answer', array( __CLASS__, 'handle_answer' ) );
		add_action( 'wp_ajax_nopriv_estandar_capture_survey_complete', array( __CLASS__, 'handle_complete' ) );
		add_action( 'wp_ajax_estandar_capture_survey_complete', array( __CLASS__, 'handle_complete' ) );
		add_action( 'wp_ajax_nopriv_estandar_capture_survey_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'wp_ajax_estandar_capture_survey_upload', array( __CLASS__, 'handle_upload' ) );
		// Export CSV de respuestas (admin-post nativo).
		add_action( 'admin_post_estandar_capture_survey_export_csv', array( __CLASS__, 'handle_export_csv' ) );
	}

	/* ─── START ─────────────────────────────────────────────────────────── */

	public static function handle_start(): void
	{
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$form_id       = absint( $_POST['form_id'] ?? 0 );
		$session_token = sanitize_text_field( $_POST['session_token'] ?? '' );
		$total_questions = absint( $_POST['total_questions'] ?? 0 );
		$id_mode       = sanitize_key( $_POST['identification_mode'] ?? 'anonymous' );
		$name          = sanitize_text_field( $_POST['respondent_name'] ?? '' );
		$email         = sanitize_email( $_POST['respondent_email'] ?? '' );
		$phone         = sanitize_text_field( $_POST['respondent_phone'] ?? '' );

		if ( $form_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'form_id requerido.' ), 422 );
		}
		if ( $session_token === '' ) {
			$session_token = wp_generate_uuid4();
		}
		if ( ! in_array( $id_mode, array( 'anonymous', 'identified', 'optional' ), true ) ) {
			$id_mode = 'anonymous';
		}
		// La config del form es autoritativa sobre el payload del cliente.
		$config_mode = self::get_identification_mode( $form_id );
		if ( $config_mode !== '' ) {
			$id_mode = $config_mode;
		}

		$data = array(
			'survey_session'        => true,
			'session_token'         => $session_token,
			'identification_mode'   => $id_mode,
			'respondent_name'       => null,
			'respondent_email'      => null,
			'respondent_phone'      => null,
			'respondent_identity'   => array(),
			'survey_status'         => 'started',
			'total_questions'       => $total_questions,
			'started_at'            => current_time( 'mysql' ),
			'completed_at'          => null,
			'source_ip'             => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'source_ua'             => sanitize_textarea_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		);

		if ( $id_mode !== 'anonymous' ) {
			$identity = array();
			if ( $name !== '' ) {
				$identity['name'] = $name;
				$data['respondent_name'] = $name;
			}
			if ( is_email( $email ) ) {
				$identity['email'] = $email;
				$data['respondent_email'] = $email;
			}
			if ( $phone !== '' ) {
				$identity['phone'] = $phone;
				$data['respondent_phone'] = $phone;
			}
			$data['respondent_identity'] = $identity;
		}

		$session_id = SurveyDb::session_create( $session_token, $form_id, $data, 'started' );
		if ( $session_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'No se pudo iniciar la encuesta.' ), 500 );
		}

		wp_send_json_success( array(
			'session_id'    => $session_id,
			'session_token' => $session_token,
		) );
	}

	/* ─── EVENT ─────────────────────────────────────────────────────────── */

	public static function handle_event(): void
	{
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$form_id       = absint( $_POST['form_id'] ?? 0 );
		$session_id    = absint( $_POST['session_id'] ?? 0 );
		$event_type    = sanitize_key( $_POST['event_type'] ?? '' );

		$allowed = array( 'survey_view', 'survey_start', 'survey_question_view', 'survey_question_answered', 'survey_completed', 'survey_abandoned' );
		if ( $form_id <= 0 || ! in_array( $event_type, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'Evento inválido.' ), 422 );
		}

		if ( $event_type === 'survey_abandoned' && $session_id > 0 ) {
			SurveyDb::session_set_status( $session_id, 'abandoned' );
		}

		wp_send_json_success( array( 'logged' => true ) );
	}

	/* ─── ANSWER ────────────────────────────────────────────────────────── */

	public static function handle_answer(): void
	{
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$form_id       = absint( $_POST['form_id'] ?? 0 );
		$session_token = sanitize_text_field( $_POST['session_token'] ?? '' );
		$q_index       = absint( $_POST['question_index'] ?? 0 );
		$q_label       = sanitize_text_field( $_POST['question_label'] ?? '' );
		$q_type        = sanitize_key( $_POST['question_type'] ?? 'text' );
		$answer_value  = sanitize_text_field( $_POST['answer_value'] ?? '' );
		$answer_label  = sanitize_text_field( $_POST['answer_label'] ?? '' );
		$attachment    = esc_url_raw( (string) ( $_POST['answer_attachment'] ?? '' ) );
		$time_spent    = absint( $_POST['time_spent_seconds'] ?? 0 );

		if ( $form_id <= 0 || $session_token === '' ) {
			wp_send_json_error( array( 'message' => 'form_id y session_token requeridos.' ), 422 );
		}

		$q_type = ConversationEngine::is_supported_question_type( $q_type ) ? $q_type : 'text';

		$answer_field = array();
		if ( $form_id > 0 ) {
			$form = Forms::get( $form_id );
			if ( $form ) {
				$config = json_decode( (string) $form['config_json'], true );
				$fields = Forms::renderable_fields( is_array( $config ) ? $config : array() );
				if ( isset( $fields[ $q_index ] ) && is_array( $fields[ $q_index ] ) ) {
					$answer_field = $fields[ $q_index ];
				}
			}
		}

		// Server-side: campo obligatorio no puede venir vacío (ningún paso avanza
		// sin seleccionar). Refuerza la validación del cliente.
		if ( ! empty( $answer_field['required'] ) && trim( (string) $answer_value ) === '' ) {
			wp_send_json_error( array( 'message' => 'Este campo es obligatorio.' ), 422 );
		}

		// Nombre incompleto (mínimo 2 letras) / RUT / tel / email con formato básico.
		$fname = sanitize_key( (string) ( $answer_field['name'] ?? '' ) );
		if ( trim( (string) $answer_value ) !== '' ) {
			if ( strpos( $fname, 'nombre' ) !== false && strlen( trim( (string) $answer_value ) ) < 2 ) {
				wp_send_json_error( array( 'message' => 'El nombre no puede estar incompleto.' ), 422 );
			}
			if ( $q_type === 'email' && ! is_email( $answer_value ) ) {
				wp_send_json_error( array( 'message' => 'Ingresa un correo válido.' ), 422 );
			}
			if ( in_array( $fname, array( 'telefono', 'phone', 'celular', 'movil' ), true ) ) {
				$tel_ok = class_exists( 'Estandar\\Capture\\Fields' )
					? \Estandar\Capture\Fields::validate_phone( $answer_value, 'CL' )
					: (bool) preg_match( '/^[\d+\s\-()]{9,16}$/', $answer_value );
				if ( ! $tel_ok ) {
					wp_send_json_error( array( 'message' => 'Ingresa un teléfono válido (9 dígitos, ej. 9 1234 5678).' ), 422 );
				}
			}
			// RUT chileno con módulo 11 real (validación canónica del motor).
			if ( strpos( $fname, 'rut' ) !== false
				&& ! ( class_exists( 'Estandar\\Capture\\Fields' ) && \Estandar\Capture\Fields::validate_rut( $answer_value ) ) ) {
				wp_send_json_error( array( 'message' => 'El RUT no es válido. Verifica el dígito verificador.' ), 422 );
			}
		}

		$validation = ConversationEngine::validate_answer_value( $q_type, $answer_value, $answer_field );
		if ( ! $validation['valid'] ) {
			wp_send_json_error( array( 'message' => $validation['error'] ), 422 );
		}
		$answer_value = (string) ( $validation['value'] ?? $answer_value );

		// Sanitización y normalización (motor único, PURE de Ingestion):
		//  - nombre → solo iniciales mayúsculas (ej. "MARÍA JOSÉ" → "María José").
		//  - teléfono → dígitos 56 9 XXXX (sin '+', normalizado E.164).
		$fname_norm = sanitize_key( (string) ( $answer_field['name'] ?? '' ) );
		if ( $fname_norm !== '' ) {
			if ( strpos( $fname_norm, 'nombre' ) !== false && class_exists( 'Estandar\\Capture\\Ingestion' ) ) {
				$answer_value = \Estandar\Capture\Ingestion::normalize_name( $answer_value );
				$answer_label = \Estandar\Capture\Ingestion::normalize_name( $answer_label );
			}
			if ( in_array( $fname_norm, array( 'telefono', 'phone', 'celular', 'movil' ), true ) && class_exists( 'Estandar\\Capture\\Ingestion' ) ) {
				$answer_value = \Estandar\Capture\Ingestion::normalize_phone( $answer_value );
				$answer_value = self::format_chilean_phone( $answer_value );
				$answer_label = $answer_value;
			}
			if ( strpos( $fname, 'rut' ) !== false && class_exists( 'Estandar\\Capture\\Ingestion' ) ) {
				$answer_value = \Estandar\Capture\Ingestion::normalize_rut( $answer_value );
				$answer_label = $answer_value;
			}
		}

		SurveyDb::response_save( array(
			'session_token'      => $session_token,
			'form_id'            => $form_id,
			'question_index'     => $q_index,
			'question_label'     => $q_label,
			'question_type'      => $q_type,
			'answer_value'       => $answer_value,
			'answer_label'       => $answer_label,
			'answer_attachment'  => $attachment,
			'answered_at'        => current_time( 'mysql' ),
			'time_spent_seconds' => $time_spent,
		) );

		wp_send_json_success( array( 'saved' => true, 'value' => $answer_value, 'attachment' => $attachment ) );
	}

	/* ── UPLOAD (foto adjunta a un problema) ───────────────────────────── */

	/** Endpoint AJAX de subida de imagen del survey (postventa por etapas).
	 *  Valida el archivo, lo guarda bajo uploads/estandar-capture/survey/
	 *  y lo optimiza (referencial). Devuelve la URL pública.
	 *
	 *  @return void wp_send_json_* */
	public static function handle_upload(): void
	{
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'No se recibió ninguna imagen.' ), 422 );
		}
		$file = (array) $_FILES['file'];
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'Error al subir la imagen.' ), 422 );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! file_exists( $tmp_name ) ) {
			wp_send_json_error( array( 'message' => 'Archivo temporal no disponible.' ), 422 );
		}

		$max_size = (int) apply_filters( 'estandar_forms_upload_max_size', 5 * 1024 * 1024, $file );
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			wp_send_json_error( array( 'message' => 'La imagen supera el tamaño máximo (5 MB).' ), 422 );
		}

		$allowed_mimes = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		);
		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			wp_send_json_error( array( 'message' => 'Servicio de subida no disponible.' ), 422 );
		}
		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'foto.jpg' ) );
		$filetype      = wp_check_filetype_and_ext( $tmp_name, $original_name, $allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			wp_send_json_error( array( 'message' => 'Solo se aceptan imágenes (JPG, PNG o WebP).' ), 422 );
		}

		$real_mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real_mime = (string) finfo_file( $finfo, $tmp_name );
				finfo_close( $finfo );
			}
		}
		if ( $real_mime !== '' && ! in_array( $real_mime, array_values( $allowed_mimes ), true ) ) {
			wp_send_json_error( array( 'message' => 'El archivo no es una imagen válida.' ), 422 );
		}
		if ( ! is_array( @getimagesize( $tmp_name ) ) ) {
			wp_send_json_error( array( 'message' => 'La imagen no se pudo leer.' ), 422 );
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
			wp_send_json_error( array( 'message' => 'Directorio de subidas no disponible.' ), 422 );
		}
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'estandar-capture/survey/';
		if ( ! wp_mkdir_p( $target_dir ) || ! is_writable( $target_dir ) ) {
			wp_send_json_error( array( 'message' => 'No se pudo crear el directorio de subidas.' ), 422 );
		}

		$final_name  = wp_unique_filename( $target_dir, $original_name );
		$destination = $target_dir . $final_name;
		$moved       = is_uploaded_file( $tmp_name ) ? move_uploaded_file( $tmp_name, $destination ) : false;
		if ( ! $moved ) {
			wp_send_json_error( array( 'message' => 'No se pudo guardar la imagen.' ), 422 );
		}
		@chmod( $destination, 0644 );

		// Optimización referencial (redimensiona/re-comprime; best-effort).
		if ( class_exists( 'Estandar\\Capture\\ImageOptimizer' ) ) {
			$opt = ImageOptimizer::optimize_file( $destination, $final_name, (string) $filetype['type'] );
			$destination = (string) $opt['path'];
			$final_name  = (string) $opt['name'];
		}

		$url = trailingslashit( $upload_dir['baseurl'] ) . 'estandar-capture/survey/' . $final_name;
		wp_send_json_success( array( 'url' => esc_url_raw( $url ) ) );
	}

	/* ─── COMPLETE ──────────────────────────────────────────────────────── */

	public static function handle_complete(): void
	{
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$form_id       = absint( $_POST['form_id'] ?? 0 );
		$session_id    = absint( $_POST['session_id'] ?? 0 );
		$session_token = sanitize_text_field( $_POST['session_token'] ?? '' );

		if ( $form_id <= 0 || $session_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'form_id y session_id requeridos.' ), 422 );
		}

		// SURVEY_SPAM_V1: la cadena F1 del renderer de forms (rate-limit por
		// IP+form + tiempo mínimo) también protege la completación de surveys.
		// El tope/ventana del rate-limit respeta la config del form
		// (spam_rate_max / spam_rate_window; vacío = fábrica 5 / 600 s).
		// (FIX 07/09: survey_rate_limit() devuelve claves 'max'/'window' — un
		// list() numérico leía índices inexistentes → null → TypeError en
		// Spam::rate_limit_ok() → la completación moría sin guardar.)
		$rl_limit = self::survey_rate_limit( $form_id );
		$rl_max   = (int) ( $rl_limit['max'] ?? 0 );
		$rl_win   = (int) ( $rl_limit['window'] ?? 0 );
		if ( class_exists( 'Estandar\\Capture\\Spam' ) && ! \Estandar\Capture\Spam::rate_limit_ok( $form_id, (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), $rl_max, $rl_win ) ) {
			wp_send_json_error( array( 'message' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ), 429 );
		}

		$session = SurveyDb::session_get( $session_token );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Sesión no encontrada.' ), 404 );
		}

		// SURVEY_IDEMPOTENCY_V1: una sesión ya completada no se re-procesa
		// (doble POST / reintento de red → no repetir notificación,
		// autorespuesta, webhook ni ingesta). Respuesta idempotente de éxito.
		if ( (string) ( $session['status'] ?? '' ) === 'completed' ) {
			wp_send_json_success( array( 'completed' => true, 'idempotent' => true ) );
		}

		// Min-time: una sesión completada antes del tiempo mínimo es sospechosa.
		// TIMEZONE_V1: created_at se guarda en zona WP; el epoch real se obtiene
		// con el offset de WordPress.
		$created_ts = class_exists( 'Estandar\\Core\\Time' )
			? \Estandar\Core\Time::epoch( (string) ( $session['created_at'] ?? '' ) )
			: strtotime( (string) ( $session['created_at'] ?? '' ) );
		if ( $created_ts && class_exists( 'Estandar\\Capture\\Spam' ) && ( time() - $created_ts ) < \Estandar\Capture\Spam::MIN_SECONDS ) {
			wp_send_json_error( array( 'message' => 'Solicitud demasiado rápida.' ), 422 );
		}

		$data = (array) ( json_decode( (string) $session['data_json'], true ) ?: array() );
		$data['survey_status'] = 'completed';
		$data['completed_at']  = current_time( 'mysql' );

		SurveyDb::session_set_status( (int) $session['id'], 'completed' );
		SurveyDb::session_update_data( (int) $session['id'], $data );
		SurveyDb::response_link_session( $session_token, (int) $session['id'], $form_id );

		$form = Forms::get( $form_id );
		if ( $form ) {
			$config = json_decode( (string) $form['config_json'], true );
			$config = is_array( $config ) ? $config : array();

			// Bus de eventos canónicos (S15): encuesta completada →
			// estandar_suite_survey_completed. Inerte si ningún destino mapea
			// `survey_completed`; complementa (no reemplaza) la ingesta cuando
			// completion_strategy=lead (esa sigue disparando `capture`).
			self::emit_completed( (int) $session['id'], $form_id, (string) ( $form['form_type'] ?? 'survey' ) );

			// Correo de notificación (motor único Core\Mail, best-effort).
			self::send_completion_email( $form_id, $config, $session_token, $data );

			// Autorespuesta al cliente (confirmación), si está configurada.
			self::send_autoresponse( $form_id, $config, $session_token, $data );

			// Webhook de salida (n8n/externo), si el form lo tiene configurado.
			// Motor único: igual que el form nativo (Actions), el survey y el
			// postventa por etapas también disparan el webhook al completar.
			self::dispatch_webhook( $form_id, $config, $session_token, $data );

			// Engine completion: scoring + completion_strategy (opt-in por config).
			self::maybe_run_engine_completion( $form_id, $config, $session_token, $data );

			// UNIQUE_PROJECT_V1: si el survey pide "respuesta única por proyecto",
			// marcar el proyecto como "ya dejó datos" (cookie server-side) para que
			// otros forms del mismo proyecto bloqueen. Postventa siempre exenta.
			$up_scope = \Estandar\Capture\FormRenderer::unique_project_scope( $config, (string) ( $form['form_type'] ?? 'survey' ) );
			if ( $up_scope !== '' ) {
				\Estandar\Capture\FormRenderer::mark_done_project( $up_scope );
			}
		}

		wp_send_json_success( array( 'completed' => true ) );
	}

	/**
	 * Best-effort canonical survey-completed event for the Destinations bus
	 * (S15). Payload mínimo sin PII; inerte sin destino mapeado a
	 * `survey_completed` (dispatch_event no encola ni hace red).
	 */
	public static function emit_completed( int $session_id, int $form_id, string $form_type = 'survey' ): void
	{
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		do_action( 'estandar_suite_survey_completed', array(
			'session_id' => $session_id,
			'form_id'    => $form_id,
			'source'     => 'survey',
			'form_type'  => $form_type,
			'status'     => 'completed',
		) );
	}

	/** Config de rate-limit del form (spam_rate_max/window; 0 = fábrica). */
	private static function survey_rate_limit( int $form_id ): array
	{
		$form = Forms::get( $form_id );
		$config = is_array( $form )
			? ( json_decode( (string) ( $form['config_json'] ?? '{}' ), true ) ?: array() )
			: array();
		return array(
			'max'    => absint( (int) ( $config['spam_rate_max'] ?? 0 ) ),
			'window' => absint( (int) ( $config['spam_rate_window'] ?? 0 ) ),
		);
	}

	/** Normaliza un teléfono chileno E.164 ("56912345678") a "56 9 1234 5678". */
	private static function format_chilean_phone( string $digits ): string
	{
		$digits = preg_replace( '/\D+/', '', $digits );
		if ( $digits === '' ) {
			return '';
		}
		// Sin el prefijo internacional (56) si ya viene completo.
		if ( strlen( $digits ) === 12 && strpos( $digits, '56' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 9 ) { // 9XXXXXXXX
			$digits = '56' . $digits;
		}
		// 56 9 XXXXXXXX → "56 9 1234 5678"
		if ( strlen( $digits ) === 11 ) {
			return substr( $digits, 0, 2 ) . ' ' . substr( $digits, 2, 1 ) . ' ' . substr( $digits, 3, 4 ) . ' ' . substr( $digits, 7, 4 );
		}
		return $digits;
	}

	/* ── Export CSV nativo ──────────────────────────────────────────────── */

	public static function handle_export_csv(): void
	{
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'estandar_capture_survey_export_csv' );

		global $wpdb;
		$survey_id = absint( $_GET['survey_id'] ?? 0 );
		if ( $survey_id <= 0 ) {
			wp_die( 'survey_id requerido.' );
		}
		$form = Forms::get( $survey_id );
		if ( ! $form || (string) $form['form_type'] !== 'survey' ) {
			wp_die( 'Encuesta no encontrada.' );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT session_token, question_index, question_label, question_type, answer_value, answer_label, time_spent_seconds, answered_at
			 FROM ' . $wpdb->prefix . SurveyDb::RESPONSES_TABLE . '
			 WHERE form_id = %d ORDER BY session_token ASC, question_index ASC',
			$survey_id
		), ARRAY_A );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="survey-' . $survey_id . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'Cache-Control: no-store, no-cache' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'wb' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'survey_id', 'session_token', 'question_index', 'question_label', 'question_type', 'answer_value', 'answer_label', 'time_spent_seconds', 'answered_at' ) );
		$csv_safe = static function ( $val ): string {
			$s = (string) $val;
			return ( $s !== '' && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $s : $s;
		};
		foreach ( (array) $rows as $r ) {
			fputcsv( $out, array(
				$survey_id,
				$csv_safe( (string) $r['session_token'] ),
				(int) $r['question_index'],
				$csv_safe( (string) ( $r['question_label'] ?? '' ) ),
				(string) ( $r['question_type'] ?? 'text' ),
				$csv_safe( (string) ( $r['answer_value'] ?? '' ) ),
				$csv_safe( (string) ( $r['answer_label'] ?? '' ) ),
				(int) ( $r['time_spent_seconds'] ?? 0 ),
				(string) ( $r['answered_at'] ?? '' ),
			) );
		}
		exit;
	}

	/* ── Internos ───────────────────────────────────────────────────────── */

	/** POSTVENTA_PHOTO_ATTACH_V1 (PURE): convierte las URLs de adjuntos de las
	 *  respuestas (fotos subidas bajo uploads) a rutas locales candidatas.
	 *  Recibe base_url/base_dir explícitos para poder testear sin WP. Devuelve
	 *  solo rutas bajo base_dir (sin traversal). El llamador filtra por archivo
	 *  legible antes de adjuntar. */
	public static function resolve_attachment_paths( array $urls, string $base_url, string $base_dir ): array
	{
		$base_url = rtrim( $base_url, '/' );
		$base_dir = rtrim( $base_dir, '/' );
		if ( $base_url === '' || $base_dir === '' ) {
			return array();
		}
		$out = array();
		foreach ( $urls as $u ) {
			$u = trim( (string) $u );
			if ( $u === '' || strpos( $u, $base_url ) !== 0 ) {
				continue;
			}
			$rel = ltrim( substr( $u, strlen( $base_url ) ), '/' );
			$rel = str_replace( array( '\\', '..' ), '', $rel );
			if ( $rel === '' || $rel[0] === '.' ) {
				continue;
			}
			$out[] = $base_dir . '/' . $rel;
		}
		return array_values( array_unique( $out ) );
	}

	/** POSTVENTA_PHOTO_ATTACH_V1: adjuntos reales de un set de respuestas
	 *  (fotos del postventa). URLs → rutas locales legibles bajo uploads. */
	private static function response_attachments( array $responses ): array
	{
		$urls = array();
		foreach ( (array) $responses as $r ) {
			$u = trim( (string) ( $r['answer_attachment'] ?? '' ) );
			if ( $u !== '' ) {
				$urls[] = $u;
			}
		}
		if ( ! $urls ) {
			return array();
		}
		$up = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$base_url = (string) ( $up['baseurl'] ?? '' );
		$base_dir = (string) ( $up['basedir'] ?? '' );
		$paths = self::resolve_attachment_paths( $urls, $base_url, $base_dir );
		$paths = array_values( array_filter( $paths, static function ( $p ) {
			return is_string( $p ) && is_file( $p ) && is_readable( $p );
		} ) );
		return $paths;
	}

	/** Identification mode autoritativo desde la config del form (survey_config). */
	private static function get_identification_mode( int $form_id ): string
	{
		$form = Forms::get( $form_id );
		if ( ! $form ) {
			return '';
		}
		$config = json_decode( (string) $form['config_json'], true );
		$survey_config = $config['survey_config'] ?? array();
		if ( is_string( $survey_config ) ) {
			$survey_config = json_decode( $survey_config, true );
		}
		$survey_config = is_array( $survey_config ) ? $survey_config : array();
		$mode = sanitize_key( (string) ( $survey_config['identification_mode'] ?? '' ) );
		return in_array( $mode, array( 'anonymous', 'identified', 'optional' ), true ) ? $mode : '';
	}

	/**
	 * Correo de finalización al operador (port del legacy, motor Core\Mail).
	 * Sin destinatarios configurados → no-op (mejor que error).
	 */
	/** PURE: formatea una respuesta de encuesta para el correo: etiquetas
	 *  legibles (checkbox/radio/select/emoji/cards) en vez del JSON crudo,
	 *  matriz como pares fila→puntaje y JSON defensivo aplanado. */
	public static function format_answer_display( array $response ): string
	{
		$value = (string) ( $response['answer_value'] ?? '' );
		$label = trim( (string) ( $response['answer_label'] ?? '' ) );
		$type  = (string) ( $response['question_type'] ?? '' );

		if ( $type === 'matrix' ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? implode( ', ', array_map( static function ( $k, $v ) {
				return $k . ': ' . $v;
			}, array_keys( $decoded ), array_values( $decoded ) ) ) : $value;
		} elseif ( $label !== '' && $label !== $value ) {
			// checkbox/radio/select/emoji/cards: etiquetas legibles en vez
			// del JSON crudo (ej. {"checked":["opcion_8"]}).
			$value = $label;
		} elseif ( ( $value[0] ?? '' ) === '{' || ( $value[0] ?? '' ) === '[' ) {
			// Defensivo: JSON sin etiqueta legible → aplanar valores.
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$flat = array();
				array_walk_recursive( $decoded, static function ( $v ) use ( &$flat ) {
					$flat[] = $v;
				} );
				$value = $flat ? implode( ', ', $flat ) : $value;
			}
		}
		return $value;
	}

	private static function send_completion_email( int $form_id, array $config, string $session_token, array $data ): void
	{
		$m = Forms::mail_settings( $config );
		if ( empty( $m['notify']['enabled'] ) || trim( (string) $m['notify']['to'] ) === '' ) {
			return;
		}

		global $wpdb;
		$responses = $wpdb->get_results( $wpdb->prepare(
			'SELECT question_index, question_label, question_type, answer_value, answer_label, answer_attachment
			 FROM ' . $wpdb->prefix . SurveyDb::RESPONSES_TABLE . '
			 WHERE form_id = %d AND session_token = %s
			 ORDER BY question_index ASC',
			$form_id, $session_token
		), ARRAY_A );

		$rows = array();
		foreach ( (array) $responses as $r ) {
			$rows[] = (string) ( $r['question_label'] ?? 'Pregunta' ) . ': ' . self::format_answer_display( $r );
		}

		$respondent = trim( implode( ' ', array_filter( array(
			(string) ( $data['respondent_name'] ?? '' ),
			(string) ( $data['respondent_email'] ?? '' ),
			(string) ( $data['respondent_phone'] ?? '' ),
		) ) ) );

		// CONTRATO GF (postventa por etapas): el correo debe ser byte-idéntico
		// al de Gravity Forms (subject + tabla Label:Value), porque la
		// plataforma externa del cliente lo parsea. mail_contract='gf_postventa'.
		$contract = sanitize_key( (string) ( $config['mail_contract'] ?? '' ) );
		$capture  = null;
		$vars     = array();
		if ( $contract === 'gf_postventa' && class_exists( 'Estandar\\Capture\\GfPostventaBridge' ) ) {
			$capture = self::capture_from_survey_responses( $responses, $data, $form_id, $session_token );
			$c = \Estandar\Capture\GfPostventaBridge::contract_from_capture( $capture, $config );
			$body    = (string) $c['body'];
			$subject = (string) $c['subject'];
		} else {
			// Branding de la Suite (igual que los correos de confirmación de
			// forms) + variables {{form_name}}/{{respondent}}/{{date}}.
			$form_name = (string) ( $config['name'] ?? '' );
			if ( $form_name === '' && $form_id > 0 ) {
				$frow = Forms::get( $form_id );
				$form_name = is_array( $frow ) ? (string) ( $frow['name'] ?? '' ) : '';
			}
			$identity = Forms::mail_identity( $config );
			$vars     = Forms::brand_vars( $identity );
			$vars['form_name']   = $form_name;
			$vars['respondent']  = $respondent;
			$vars['date']        = current_time( 'mysql' );

			$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.5;">'
				. '{{logo}}'
				. '<h2 style="font-size:18px;margin:16px 0 6px;color:#1d2327;">Nueva respuesta de encuesta</h2>'
				. ( $respondent !== '' ? '<p style="margin:0 0 14px;color:#555;">Respondido por: ' . esc_html( $respondent ) . '</p>' : '' )
				. ( $rows ? '<ul style="margin:0 0 14px;"><li>' . implode( '</li><li>', array_map( 'esc_html', $rows ) ) . '</li></ul>' : '<p style="color:#555;">Sin respuestas registradas.</p>' )
				. '<p style="color:#999;font-size:12px;margin-top:10px;">Enviado el {{date}}.</p>'
				. '{{footer_estandar}}'
				. '</div>';
			$subject = $m['notify']['subject'] !== '' ? $m['notify']['subject'] : 'Nueva respuesta de encuesta';
			// NOTIFY_ROUTING_V1: si el form define routing por valor de
			// respuesta, se construye el capture para resolver destinatarios.
			if ( ! empty( $m['notify']['route_field'] ) && ! empty( $m['notify']['route_map'] ) ) {
				$capture = self::capture_from_survey_responses( $responses, $data, $form_id, $session_token );
			}
		}

		// MAIL_TEMPLATES_V1 · Fase 2: si el form/proyecto eligió plantilla,
		// inyectar el body en su slot {{content}} (rama branding; el contrato
		// GF ya devolvió <html> y decorate lo conserva intacto).
		$project_slug = (string) ( is_array( $capture ) ? ( $capture['fields']['project_slug'] ?? '' ) : '' );
		$body = \Estandar\Capture\Forms::decorate_with_template(
			$body,
			$config,
			$project_slug,
			class_exists( 'Estandar\\Capture\\Forms' ) ? Forms::mail_identity( $config, $project_slug ) : array()
		);

		// NOTIFY_ROUTING_V1: el valor capturado decide los destinatarios de la
		// notificación interna (fallback = notify_emails). La ejecución del
		// survey prosigue completa (registro, autorespuesta, webhook); solo
		// cambia el destinatario del aviso.
		$to = (string) $m['notify']['to'];
		if ( $capture !== null ) {
			$to = \Estandar\Capture\Forms::resolve_notify_to( $config, $capture );
		}
		if ( trim( $to ) === '' ) {
			return;
		}

		// NOTIFY_CC_V1: copias SIEMPRE (admin + gestión + cc_always), aunque el
		// routing por campo cambie el To. Comparte la semántica de Forms.
		$cc = class_exists( 'Estandar\\Capture\\Forms' )
			? Forms::notify_cc( $m['notify'], $to )
			: array();

		if ( class_exists( 'Estandar\Core\Mail' ) ) {
			\Estandar\Core\Mail::send( array(
				'to'         => $to,
				'subject'    => $subject,
				'html'       => $body,
				'alt'        => wp_strip_all_tags( $body ),
				'from_name'  => (string) ( $m['notify']['sender'] ?? '' ),
				'from_email' => (string) ( $m['notify']['from_email'] ?? '' ),
				'cc'         => $cc,
				'vars'       => $vars,
				// POSTVENTA_PHOTO_ATTACH_V1: el correo del operador lleva las
				// fotos adjuntas (además de su URL en el body Label:Value). El
				// cuerpo NO cambia (la plataforma externa lo parsea); los
				// archivos van como MIME attachments. Sin fotos → sin adjunto.
				'attachments' => self::response_attachments( (array) $responses ),
			) );
		}
	}

	/** Construye un capture v2 desde las respuestas del survey (misma forma que
	 *  un form nativo) para alimentar el contrato GF de postventa. */
	private static function capture_from_survey_responses( array $responses, array $data, int $form_id, string $session_token ): array
	{
		// Mapa label → clave de campo del contrato postventa.
		$by_label = array();
		foreach ( (array) $responses as $r ) {
			$val = (string) ( $r['answer_value'] ?? '' );
			// Radio "Otra": el texto real va en answer_label (el value es _other).
			if ( $val === '_other' ) {
				$val = (string) ( $r['answer_label'] ?? '' );
			}
			$by_label[ (string) ( $r['question_label'] ?? '' ) ] = $val;
		}

		// Adjuntos por label (foto subida en el problema).
		$attach_by_label = array();
		foreach ( (array) $responses as $r ) {
			$url = trim( (string) ( $r['answer_attachment'] ?? '' ) );
			if ( $url !== '' ) {
				$attach_by_label[ (string) ( $r['question_label'] ?? '' ) ] = $url;
			}
		}

		// Normaliza el label (quita ¿? y mayúsculas) para matchear.
		$norm = static function ( string $s ): string {
			$s = preg_replace( '/[¿?¿:¡!]/u', '', $s );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
		};

		// Matchea por fragmento del label (el label es una pregunta, no el nombre
		// del campo). Busca el label que CONTENGA el patrón del campo.
		$posted = array();
		$label_map = array(
			'proyecto'           => array( 'proyecto pertenece' ),
			'calle'              => array( 'calle de tu vivienda' ),
			'numero'             => array( 'número de tu vivienda', 'numero de tu vivienda', 'número de vivienda', 'numero de vivienda' ),
			'nombre_propietario' => array( 'nombre completo', 'nombre propietario' ),
			'rut'                => array( 'rut' ),
			'telefono'           => array( 'telefono', 'teléfono' ),
			'email'              => array( 'email', 'correo' ),
			'tipo_postventa'     => array( 'tipo de postventa' ),
			'requerimiento'      => array( 'requerimiento' ),
			'solicitud'          => array( 'solicitud de documento' ),
		);
		$label_norms = array();
		foreach ( $by_label as $label => $val ) {
			$label_norms[] = array( 'label' => $label, 'norm' => $norm( $label ), 'val' => $val );
		}
		foreach ( $label_map as $key => $patterns ) {
			foreach ( $patterns as $pat ) {
				$pat_norm = $norm( $pat );
				foreach ( $label_norms as $ln ) {
					if ( strpos( $ln['norm'], $pat_norm ) !== false && ! isset( $posted[ $key ] ) ) {
						$posted[ $key ] = $ln['val'];
					}
				}
			}
		}

		// Problemas #1..#4: lugar + descripción. Fuente PRIMARIA = label (estable
		// entre layouts: el form cambió al agregar 'numero', los índices de las
		// respuestas viejas quedan corridos). El label "¿Dónde está el problema?"
		// / "Describe el problema" no cambia. Índice→nombre solo como refuerzo.
		$field_name_by_index = array();
		$form_cfg = Forms::get( $form_id );
		if ( is_array( $form_cfg ) ) {
			$form_config = json_decode( (string) ( $form_cfg['config_json'] ?? '{}' ), true );
			$form_config = is_array( $form_config ) ? $form_config : array();
			$renderable  = Forms::renderable_fields( $form_config );
			foreach ( $renderable as $i => $rf ) {
				if ( is_array( $rf ) && ! empty( $rf['name'] ) ) {
					$field_name_by_index[ (int) $i ] = sanitize_key( (string) $rf['name'] );
				}
			}
		}
		// Mapa respuestas por índice.
		$resp_by_index = array();
		foreach ( (array) $responses as $r ) {
			$resp_by_index[ (int) ( $r['question_index'] ?? -1 ) ] = $r;
		}

		// Match por label: normalizado, detecta lugar vs descripción.
		$match_problem_label = static function ( int $n, string $norm ): bool {
			if ( strpos( $norm, 'problema #' . $n ) !== false ) {
				return true;
			}
			return $n === 1 && strpos( $norm, 'problema' ) !== false;
		};

		$incidents = array();
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$loc  = '';
			$desc = '';
			$fotos = array();

			// 1) PRIMARIO: por label (semántico y estable entre layouts).
			foreach ( $label_norms as $ln ) {
				$is_lugar = strpos( $ln['norm'], 'dónde' ) !== false || strpos( $ln['norm'], 'donde' ) !== false || strpos( $ln['norm'], 'lugar' ) !== false;
				if ( ! $match_problem_label( $i, $ln['norm'] ) ) {
					continue;
				}
				if ( $is_lugar && $loc === '' ) {
					$loc = $ln['val'];
				} elseif ( ! $is_lugar && $desc === '' ) {
					$desc = $ln['val'];
					$attach = (string) ( $attach_by_label[ $ln['label'] ] ?? '' );
					if ( $attach !== '' ) {
						$fotos[] = $attach;
					}
				}
			}

			// 2) Refuerzo por nombre de campo (índice → name) solo si el label
			//    no resolvió algo (p.ej. labels genéricos sin la palabra problema).
			if ( $loc === '' || $desc === '' ) {
				foreach ( $resp_by_index as $idx => $r ) {
					$name = $field_name_by_index[ $idx ] ?? '';
					if ( $name === 'lugar_problema_' . $i && $loc === '' ) {
						$loc = (string) ( $r['answer_value'] ?? '' );
					}
					if ( $name === 'problema_' . $i && $desc === '' ) {
						$desc = (string) ( $r['answer_value'] ?? '' );
						$attach = trim( (string) ( $r['answer_attachment'] ?? '' ) );
						if ( $attach !== '' ) {
							$fotos[] = $attach;
						}
					}
				}
			}

			if ( $loc !== '' || $desc !== '' ) {
				$incident = array( 'location' => $loc, 'description' => $desc );
				if ( $fotos ) {
					$incident['fotos'] = array_values( array_unique( $fotos ) );
				}
				$incidents[] = $incident;
			}
		}
		if ( $incidents ) {
			$posted['incidents'] = wp_json_encode( $incidents, JSON_UNESCAPED_UNICODE );
		}
		unset( $posted['lugar_problema_1'], $posted['lugar_problema_2'], $posted['lugar_problema_3'], $posted['lugar_problema_4'] );
		unset( $posted['problema_1'], $posted['problema_2'], $posted['problema_3'], $posted['problema_4'] );

		$name = (string) ( $posted['nombre_propietario'] ?? $data['respondent_name'] ?? '' );
		return array(
			'source'   => 'survey',
			'content'  => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
			'contact'  => array(
				'name'  => $name,
				'email' => (string) ( $posted['email'] ?? $data['respondent_email'] ?? '' ),
				'phone' => (string) ( $posted['telefono'] ?? $data['respondent_phone'] ?? '' ),
			),
			'fields'   => $posted,
			'spam'     => array( 'honeypot_filled' => false ),
			'session_id' => $session_token,
			'name'     => $name,
		);
	}

	/** Autorespuesta al cliente (confirmación) del survey por etapas.
	 *  Reutiliza Forms::autoresponse_submission construyendo el capture v2 desde
	 *  las respuestas del survey (mismo payload que el contrato GF). Best-effort:
	 *  sin config autoresponse habilitada → no-op silencioso. */
	private static function send_autoresponse( int $form_id, array $config, string $session_token, array $data ): void
	{
		if ( ! class_exists( 'Estandar\\Capture\\Forms' ) ) {
			return;
		}
		$m = \Estandar\Capture\Forms::mail_settings( $config );
		if ( empty( $m['autoresponse']['enabled'] ) ) {
			return;
		}
		if ( ! class_exists( 'Estandar\\Capture\\GfPostventaBridge' ) ) {
			return;
		}

		global $wpdb;
		$responses = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT question_index, question_label, question_type, answer_value, answer_label, answer_attachment
			 FROM ' . $wpdb->prefix . SurveyDb::RESPONSES_TABLE . '
			 WHERE form_id = %d AND session_token = %s
			 ORDER BY question_index ASC',
			$form_id, $session_token
		), ARRAY_A );

		$capture = self::capture_from_survey_responses( $responses, $data, $form_id, $session_token );
		\Estandar\Capture\Forms::autoresponse_submission( $form_id, $config, $capture );
	}

	/** Webhook de salida del survey/postventa por etapas (mismo motor que el
	 *  form nativo: Actions::run → Webhook::dispatch). Construye el capture v2
	 *  desde las respuestas y lo envía si send_webhook=1 + webhook_url. */
	private static function dispatch_webhook( int $form_id, array $config, string $session_token, array $data ): void
	{
		if ( empty( $config['send_webhook'] ) || trim( (string) ( $config['webhook_url'] ?? '' ) ) === '' ) {
			return;
		}
		if ( ! class_exists( 'Estandar\\Capture\\Webhook' ) || ! class_exists( 'Estandar\\Capture\\GfPostventaBridge' ) ) {
			return;
		}

		global $wpdb;
		$responses = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT question_index, question_label, question_type, answer_value, answer_label, answer_attachment
			 FROM ' . $wpdb->prefix . SurveyDb::RESPONSES_TABLE . '
			 WHERE form_id = %d AND session_token = %s
			 ORDER BY question_index ASC',
			$form_id, $session_token
		), ARRAY_A );

		$capture = self::capture_from_survey_responses( $responses, $data, $form_id, $session_token );
		Webhook::dispatch( $config, $capture );
	}

	/**
	 * Engine completion: scoring + completion_strategy=lead → Ingestion::ingest
	 * (evento canónico v2 estandar_suite_capture_event). Sin config → no-op.
	 */
	private static function maybe_run_engine_completion( int $form_id, array $config, string $session_token, array $data ): void
	{		$raw_profile = $config['survey_config'] ?? array();
		if ( is_string( $raw_profile ) ) {
			$raw_profile = json_decode( $raw_profile, true );
		}
		$raw_profile = is_array( $raw_profile ) ? $raw_profile : array();
		$engine_cfg  = ConversationEngine::normalize_config( $raw_profile );
		if ( $engine_cfg === array() ) {
			return;
		}

		global $wpdb;
		$responses = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT question_index, question_label, question_type, answer_value, answer_label, answer_attachment
			 FROM ' . $wpdb->prefix . SurveyDb::RESPONSES_TABLE
			. ' WHERE form_id = %d AND session_token = %s',
			$form_id, $session_token
		), ARRAY_A );
		$fields = Forms::renderable_fields( $config );
		$completion = ConversationEngine::completion_result(
			$engine_cfg,
			ConversationEngine::build_answers_from_responses( $responses, $fields )
		);

		if ( $completion['completion_type'] === 'lead' ) {
			// Postventa (form incident con step_by_step / mail_contract): la
			// submission se mapea como form nativo para que el Panel Postventa
			// la liste con sus campos (proyecto, calle, rut, etc.).
			$contract = sanitize_key( (string) ( $config['mail_contract'] ?? '' ) );
			if ( $contract === 'gf_postventa' && class_exists( 'Estandar\\Capture\\GfPostventaBridge' ) ) {
				$capture = self::capture_from_survey_responses( $responses, $data, $form_id, $session_token );
				$capture['source'] = 'form';
				Ingestion::ingest( $capture );
				return;
			}
			$contact = array(
				'name'  => sanitize_text_field( (string) ( $data['respondent_name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $data['respondent_email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $data['respondent_phone'] ?? '' ) ),
			);
			$posted = array();
			foreach ( $responses as $r ) {
				$posted[ 'q' . (int) ( $r['question_index'] ?? 0 ) ] = (string) ( $r['answer_value'] ?? '' );
			}
			Ingestion::ingest( array(
				'source'    => 'survey',
				'content'   => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
				'contact'   => $contact,
				'fields'    => $posted,
				'ip'        => (string) ( $data['source_ip'] ?? '' ),
				'session_id' => $session_token,
				'spam'      => array( 'honeypot_filled' => false ),
			) );
		}
	}
}
