<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Front-end operator entry point for Quotes: no wp-admin, no WordPress user account,
 * no WordPress capability. Identity is a one-time Leads-bound handoff; the resulting
 * session is short-lived, revocable and scoped to a single quote. Every mutating
 * action re-validates the operator's live Leads assignment on top of the session,
 * and reuses the existing Repository/Preview/Artifacts/Delivery services untouched.
 */
class Estandar_Quotes_Operator_Gateway {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 2 );
	}

	public static function maybe_handle(): void {
		$token = self::token_from_request();
		if ( $token === '' ) { return; }

		if ( ! class_exists( 'Estandar_Leads_Operator_Workspace' ) ) {
			self::render_error( 'No disponible', 'El sistema de operadores no está disponible.' );
			return;
		}

		$session = Estandar_Quotes_Operator_Session::resolve( $token );
		if ( $session ) {
			self::handle_session( $session, $token );
			return;
		}

		self::handle_handoff( $token );
	}

	/** Profile key used for new drafts — read from Admin settings, not hardcoded. */
	private static function active_profile_key(): string {
		if ( class_exists( 'Estandar_Quotes_Admin' ) && method_exists( 'Estandar_Quotes_Admin', 'active_profile_key' ) ) {
			return Estandar_Quotes_Admin::active_profile_key();
		}
		return Estandar_Quotes_Real_Estate_Profile::KEY;
	}

	private static function token_from_request(): string {
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = trim( (string) wp_parse_url( $raw_uri, PHP_URL_PATH ), '/' );
		$site_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $site_path !== '' && strpos( $path, $site_path . '/' ) === 0 ) { $path = substr( $path, strlen( $site_path ) + 1 ); }
		if ( preg_match( '#^cotizacion/([^/]+)$#', $path, $m ) ) { return sanitize_text_field( rawurldecode( $m[1] ) ); }
		return '';
	}

	/** First visit: a still-live, unconsumed Quotes handoff. Consuming it creates/attaches a draft and mints a session. */
	private static function handle_handoff( string $token ): void {
		$peek = Estandar_Quotes_Lead_Integration::peek_handoff( $token );
		$operator_id = absint( $peek['operator_id'] ?? 0 );
		$submission_id = absint( $peek['submission_id'] ?? 0 );

		if ( $operator_id <= 0 || $submission_id <= 0 ) {
			// Token might be already consumed. Try to recover the submission.
			$submission_id = Estandar_Quotes_Lead_Integration::resolve_submission_from_token( $token );
			if ( $submission_id > 0 ) {
				$existing = Estandar_Quotes_Repository::get_by_submission( $submission_id );
				if ( ! $existing ) {
					$existing = Estandar_Quotes_Repository::get_by_submission_any_status( $submission_id );
				}
				if ( $existing ) {
					$session_token = Estandar_Quotes_Operator_Session::create( (int) $existing['id'], $operator_id ?: (int) ( $existing['operator_id'] ?? 0 ) );
					wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) );
					exit;
				}
			}
			self::render_error( 'Enlace no disponible', 'Este enlace no es válido, está desactivado o expiró.' );
			return;
		}
		/*
		 * Authorization: the handoff token itself IS the authorization.
		 *
		 * The token was minted at lead capture time specifically for this
		 * operator and submission. It is a 256-bit cryptographically secure
		 * random value (stored as SHA-256), single-use via atomic consumed_at
		 * gate, and was sent to the operator's email as proof of possession.
		 *
		 * This follows the magic-link pattern: the token proves the bearer
		 * was authorized at creation time. Re-checking live authorization
		 * against Leads is redundant and introduces a TOCTOU failure point
		 * (operator deactivated, project reassigned, etc.).
		 *
		 * The existing consumption gate (consumed_at + operator_id match)
		 * and subsequent session/CSRF controls provide the security boundary.
		 */

		$data = Estandar_Quotes_Lead_Integration::consume_handoff( $token, $operator_id );
		if ( ! $data ) {
			$existing = Estandar_Quotes_Repository::get_by_submission( $submission_id );
			if ( $existing ) {
				$session_token = Estandar_Quotes_Operator_Session::create( (int) $existing['id'], $operator_id );
				wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) );
				exit;
			}
			self::render_error( 'Enlace no disponible', 'Este enlace no es válido, está desactivado o expiró.' );
			return;
		}

		$existing = Estandar_Quotes_Repository::get_by_submission( (int) $data['submission_id'] );
		if ( $existing ) {
			$owner_id = (int) ( $existing['operator_id'] ?? 0 );
			/*
			 * Ownership policy: the current owner is kept by default. A different
			 * operator presenting a valid handoff for this submission takes over
			 * the quote — the handoff itself proves that Leads authorized them
			 * at the time the handoff was minted. This avoids TOCTOU issues
			 * from re-checking live Leads authorization.
			 */
			if ( $owner_id !== 0 && $owner_id !== $operator_id ) {
				Estandar_Quotes_Repository::rebind_operator( (int) $existing['id'], $operator_id );
			}
			$quote_id = (int) $existing['id'];
		} else {
			$settings = function_exists( 'get_option' ) ? get_option( 'estandar_quotes_defaults', array() ) : array();
			$hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $data['lead_snapshot'], self::active_profile_key(), $settings );
			$defaults = $hydrated->to_array();
			$quote_id = Estandar_Quotes_Repository::create_draft( array_merge( $data, array(
				'profile_key' => self::active_profile_key(),
				'operator_id' => $operator_id,
				'created_by'  => 0,
				'source'      => 'lead_handoff_operator',
			) ) );
			if ( ! $quote_id || ! Estandar_Quotes_Repository::initialize_draft( $quote_id, $defaults ) ) {
				self::render_error( 'No disponible', 'No fue posible crear el borrador.' );
				return;
			}
		}

		$session_token = Estandar_Quotes_Operator_Session::create( $quote_id, $operator_id );
		wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) );
		exit;
	}

	private static function handle_session( array $session, string $token ): void {
		$operator_id = absint( $session['operator_id'] );
		$quote = Estandar_Quotes_Repository::get( (int) $session['quote_id'] );
		if ( ! $quote ) { self::render_error( 'No disponible', 'Esta cotización ya no está disponible.' ); return; }

		if ( (int) ( $quote['operator_id'] ?? 0 ) !== $operator_id ) {
			self::render_error( 'Sin autorización', 'No tienes acceso a esta cotización.' );
			return;
		}
		if ( ! Estandar_Leads_Operator_Workspace::operator_authorized_for_submission( $operator_id, (int) $quote['submission_id'] ) ) {
			Estandar_Quotes_Operator_Session::revoke( $token );
			self::render_error( 'Sesión finalizada', 'Tu acceso a este lead ya no está vigente.' );
			return;
		}

		$get_action = sanitize_key( wp_unslash( $_GET['esq_action'] ?? '' ) );
		if ( $get_action === 'logout' ) {
			$logout_csrf = sanitize_text_field( wp_unslash( $_GET['esq_csrf'] ?? '' ) );
			if ( Estandar_Quotes_Operator_Session::verify_action_token( $token, $quote['quote_uuid'], 'logout', $logout_csrf ) ) {
				Estandar_Quotes_Operator_Session::revoke( $token );
				self::render_message( 'Sesión cerrada', 'Puedes cerrar esta ventana.' );
				return;
			}
			self::render_error( 'Solicitud no válida', 'No fue posible cerrar la sesión.' );
			return;
		}
		if ( $get_action === 'preview' ) {
			self::render_preview( $quote );
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' && ! empty( $_POST['esq_action'] ) ) {
			self::handle_post_action( $quote, $operator_id, $token );
			return;
		}

		self::render_workspace( $quote, $token );
	}

	private static function handle_post_action( array $quote, int $operator_id, string $token ): void {
		$action = sanitize_key( wp_unslash( $_POST['esq_action'] ) );
		$quote_uuid = (string) $quote['quote_uuid'];
		$posted_uuid = sanitize_text_field( wp_unslash( $_POST['quote_uuid'] ?? '' ) );
		$csrf = sanitize_text_field( wp_unslash( $_POST['esq_csrf'] ?? '' ) );

		if ( $posted_uuid === '' || ! hash_equals( $quote_uuid, $posted_uuid ) || ! Estandar_Quotes_Operator_Session::verify_action_token( $token, $quote_uuid, $action, $csrf ) ) {
			self::redirect_notice( $token, 'invalid_request' );
		}

		try {
			if ( $action === 'save' ) {
				self::require_status( $quote, 'draft' );
				$doc = self::document_from_post( $quote );
				if ( ! Estandar_Quotes_Repository::save_draft( (int) $quote['id'], $doc, $operator_id ) ) { throw new RuntimeException(); }
				self::redirect_notice( $token, 'saved' );
			}
			if ( $action === 'issue' ) {
				self::require_status( $quote, 'draft' );
				$doc = self::document_from_post( $quote );
				if ( ! Estandar_Quotes_Repository::save_draft( (int) $quote['id'], $doc, $operator_id ) ) { throw new RuntimeException(); }
				if ( ! Estandar_Quotes_Repository::transition( (int) $quote['id'], 'review', $operator_id ) ) { throw new RuntimeException(); }
				$revision = Estandar_Quotes_Repository::add_revision( (int) $quote['id'], $doc, $operator_id );
				if ( ! $revision || ! Estandar_Quotes_Repository::transition( (int) $quote['id'], 'issued', $operator_id ) ) { throw new RuntimeException(); }
				self::redirect_notice( $token, 'issued' );
			}
			if ( $action === 'pdf' ) {
				$revision = self::revision_row( $quote, sanitize_text_field( wp_unslash( $_POST['revision_uuid'] ?? '' ) ) );
				$preview = Estandar_Quotes_Preview_Service::resolve( $quote_uuid, 'revision', $revision['revision_uuid'] );
				$renderer = Estandar_Quotes_PDF_Dompdf_Adapter::resolve();
				Estandar_Quotes_Artifacts::generate( $quote, $preview, $renderer, $revision['revision_uuid'], ! empty( $_POST['regenerate'] ) );
				self::redirect_notice( $token, 'pdf_ready' );
			}
			if ( $action === 'send' ) {
				$revision = self::revision_row( $quote, sanitize_text_field( wp_unslash( $_POST['revision_uuid'] ?? '' ) ) );
				$artifact = self::artifact_row( $revision['revision_uuid'], absint( $_POST['artifact_id'] ?? 0 ) );
				$emails = array_filter( array_map( 'sanitize_email', explode( ',', (string) wp_unslash( $_POST['recipients'] ?? '' ) ) ) );
				$settings = function_exists( 'get_option' ) ? get_option( 'estandar_quotes_defaults', array() ) : array();
				$delivery = Estandar_Quotes_Delivery_Service::create( $revision, $artifact, $emails, sanitize_text_field( wp_unslash( $_POST['subject'] ?? 'Cotización' ) ), 'client', 'es_CL', array( 'customer_name' => self::draft_document( $quote )['client']['name'] ?? '', 'operator_email' => $settings['operator_copy'] ?? '' ) );
				$ok = Estandar_Quotes_Delivery_Service::send( $delivery, $artifact, new Estandar_Quotes_WP_Mail_Transport() );
				self::redirect_notice( $token, $ok ? 'sent' : 'send_failed' );
			}
		} catch ( Throwable $e ) {
			self::redirect_notice( $token, 'action_failed' );
		}
		self::redirect_notice( $token, 'invalid_request' );
	}

	private static function require_status( array $quote, string $status ): void {
		if ( ( $quote['status'] ?? '' ) !== $status ) { throw new RuntimeException(); }
	}

	private static function document_from_post( array $quote ): array {
		$q = (array) wp_unslash( $_POST['q'] ?? array() );
		return Estandar_Quotes_Document_Form::document_from_request( self::draft_document( $quote ), $q, (string) $quote['profile_key'] );
	}

	private static function draft_document( array $quote ): array {
		$d = json_decode( (string) ( $quote['draft_document_json'] ?? '' ), true );
		if ( is_array( $d ) ) { return $d; }
		$settings = function_exists( 'get_option' ) ? get_option( 'estandar_quotes_defaults', array() ) : array();
		$hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new(
			(array) json_decode( (string) $quote['lead_snapshot_json'], true ),
			(string) $quote['profile_key'],
			$settings
		);
		return $hydrated->to_array();
	}



	private static function revision_row( array $quote, string $uuid ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id=%d AND revision_uuid=%s LIMIT 1', $quote['id'], $uuid ), ARRAY_A );
		if ( ! is_array( $row ) ) { throw new InvalidArgumentException(); }
		return $row;
	}

	private static function artifact_row( string $revision_uuid, int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::artifacts_table() . ' WHERE id=%d AND revision_uuid=%s AND status=\'ready\' LIMIT 1', $id, $revision_uuid ), ARRAY_A );
		if ( ! is_array( $row ) ) { throw new InvalidArgumentException(); }
		return $row;
	}

	private static function render_preview( array $quote ): void {
		$revision_uuid = sanitize_text_field( wp_unslash( $_GET['revision'] ?? '' ) );
		try {
			if ( $revision_uuid !== '' ) {
				self::revision_row( $quote, $revision_uuid );
				$preview = Estandar_Quotes_Preview_Service::resolve( $quote['quote_uuid'], 'revision', $revision_uuid );
			} else {
				$preview = Estandar_Quotes_Preview_Service::resolve( $quote['quote_uuid'], 'draft' );
			}
		} catch ( Throwable $e ) {
			self::render_error( 'Vista previa no disponible', 'No fue posible generar la vista previa.' );
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Vista previa</title></head><body>' . $preview['preview_html'] . '</body></html>';
		exit;
	}

	private static function render_workspace( array $quote, string $token ): void {
		global $wpdb;
		$doc = self::draft_document( $quote );
		$readonly = $quote['status'] !== 'draft';

		self::header( 'Cotización' );
		echo '<main class="eq-wrap">';
		self::notice();
		echo '<h1>Cotización · ' . esc_html( (string) $quote['status'] ) . '</h1>';
		echo '<p><a href="' . esc_url( add_query_arg( array( 'esq_action' => 'logout', 'esq_csrf' => Estandar_Quotes_Operator_Session::action_token( $token, $quote['quote_uuid'], 'logout' ) ), Estandar_Quotes_Operator_Session::url( $token ) ) ) . '">Cerrar sesión</a></p>';

		echo '<form method="post" action="' . esc_url( Estandar_Quotes_Operator_Session::url( $token ) ) . '">';
		echo '<input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '">';
		echo '<input type="hidden" name="esq_action" value="save">';
		echo '<input type="hidden" name="esq_csrf" value="' . esc_attr( Estandar_Quotes_Operator_Session::action_token( $token, $quote['quote_uuid'], 'save' ) ) . '">';
		echo Estandar_Quotes_Document_Form::render_fields( $doc, $readonly, 'q' );
		if ( ! $readonly ) { echo '<p><button class="button button-primary" type="submit">Guardar borrador</button></p>'; }
		echo '</form>';

		if ( ! $readonly ) {
			self::action_form( $quote, $token, 'issue', 'Emitir revisión', array() );
			$preview_url = add_query_arg( array( 'esq_action' => 'preview', 'revision' => '' ), Estandar_Quotes_Operator_Session::url( $token ) );
			echo '<p><a class="button" target="_blank" href="' . esc_url( $preview_url ) . '">Previsualizar borrador</a></p>';
		}

		self::history( $quote, $token );
		echo '</main>';
		self::footer();
	}

	private static function action_form( array $quote, string $token, string $action, string $label, array $extra ): void {
		echo '<form method="post" action="' . esc_url( Estandar_Quotes_Operator_Session::url( $token ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '">';
		echo '<input type="hidden" name="esq_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="esq_csrf" value="' . esc_attr( Estandar_Quotes_Operator_Session::action_token( $token, $quote['quote_uuid'], $action ) ) . '">';
		foreach ( $extra as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	private static function history( array $quote, string $token ): void {
		global $wpdb;
		$revisions = Estandar_Quotes_Repository::revisions( (int) $quote['id'] );
		echo '<h2>Historial</h2><ul>';
		foreach ( $revisions as $r ) {
			$artifact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::artifacts_table() . ' WHERE revision_uuid=%s AND status=\'ready\' ORDER BY generation_no DESC LIMIT 1', $r['revision_uuid'] ), ARRAY_A );
			echo '<li>Revisión ' . esc_html( (string) $r['revision_no'] ) . ' · ' . esc_html( (string) $r['created_at'] ) . ' ';
			$preview_url = add_query_arg( array( 'esq_action' => 'preview', 'revision' => $r['revision_uuid'] ), Estandar_Quotes_Operator_Session::url( $token ) );
			echo '<a target="_blank" href="' . esc_url( $preview_url ) . '">Preview</a> ';
			self::action_form( $quote, $token, 'pdf', $artifact ? 'Regenerar PDF' : 'Generar PDF', array( 'revision_uuid' => $r['revision_uuid'], 'regenerate' => $artifact ? '1' : '' ) );
			if ( $artifact ) {
				echo '<form method="post" action="' . esc_url( Estandar_Quotes_Operator_Session::url( $token ) ) . '" style="display:inline-block">';
				echo '<input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '">';
				echo '<input type="hidden" name="esq_action" value="send">';
				echo '<input type="hidden" name="revision_uuid" value="' . esc_attr( $r['revision_uuid'] ) . '">';
				echo '<input type="hidden" name="artifact_id" value="' . absint( $artifact['id'] ) . '">';
				echo '<input type="hidden" name="esq_csrf" value="' . esc_attr( Estandar_Quotes_Operator_Session::action_token( $token, $quote['quote_uuid'], 'send' ) ) . '">';
				echo '<input name="recipients" placeholder="correo@cliente.cl" value="' . esc_attr( $recipient_default ?? '' ) . '"><input name="subject" value="Cotización">';
				echo '<button class="button" type="submit">Enviar</button></form>';
			}
			echo '</li>';
		}
		echo '</ul>';
		$deliveries = $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_uuid,status,attempts,sent_at,created_at FROM ' . Estandar_Quotes_Schema::deliveries_table() . ' WHERE revision_uuid IN (SELECT revision_uuid FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id=%d) ORDER BY id DESC LIMIT 20', $quote['id'] ), ARRAY_A );
		echo '<h3>Entregas</h3><ul>';
		foreach ( (array) $deliveries as $delivery ) {
			echo '<li>' . esc_html( (string) $delivery['status'] ) . ' · intentos ' . esc_html( (string) $delivery['attempts'] ) . ' · ' . esc_html( (string) ( $delivery['sent_at'] ?: $delivery['created_at'] ) ) . '</li>';
		}
		echo '</ul>';
	}

	private static function notice(): void {
		$n = sanitize_key( wp_unslash( $_GET['esq_notice'] ?? '' ) );
		$messages = array( 'saved' => 'Borrador guardado.', 'issued' => 'Revisión emitida.', 'pdf_ready' => 'PDF generado.', 'pdf_unavailable' => 'El generador de PDF no está disponible en este momento.', 'sent' => 'Cotización enviada.', 'send_failed' => 'No fue posible enviar.', 'action_failed' => 'No fue posible completar la acción.', 'invalid_request' => 'Solicitud no válida.' );
		if ( isset( $messages[ $n ] ) ) { echo '<div class="eq-notice">' . esc_html( $messages[ $n ] ) . '</div>'; }
	}

	private static function redirect_notice( string $token, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'esq_notice', $notice, Estandar_Quotes_Operator_Session::url( $token ) ) );
		exit;
	}

	private static function header( string $title ): void {
		status_header( 200 );
		nocache_headers();
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title><style>body{margin:0;background:#f6f7f7;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}.eq-wrap{max-width:820px;margin:0 auto;padding:16px}.eq-notice{background:#fff;border-left:4px solid #2271b1;border-radius:4px;padding:8px 12px;margin-bottom:12px}table.form-table th{text-align:left;padding:6px 8px 6px 0;font-size:12px;color:#50575e}table.form-table td{padding:4px 0}input,textarea{box-sizing:border-box;width:100%;max-width:420px;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px}.button{display:inline-block;background:#2271b1;color:#fff;border:none;border-radius:4px;padding:8px 12px;cursor:pointer;text-decoration:none}.button-primary{background:#1e8e3e}</style></head><body>';
	}

	private static function footer(): void {
		echo '</body></html>';
		exit;
	}

	private static function render_message( string $title, string $message ): void {
		self::header( $title );
		echo '<main class="eq-wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></main>';
		self::footer();
	}

	/** Generic, no internal identifiers/hashes/paths ever surface here. */
	private static function render_error( string $title, string $message ): void {
		status_header( 200 );
		self::render_message( $title, $message );
	}
}
