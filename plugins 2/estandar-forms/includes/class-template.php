<?php
/**
 * Estandar Forms — Template System
 *
 * Export, import y duplicate de formularios como archivos .estandar-template.json.
 *
 * @package Estandar_Forms
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Forms_Template {

	const SCHEMA_URI     = 'https://estandar.cl/schemas/forms/template/v1';
	const SCHEMA_VERSION = '1.1';

	/**
	 * JSON_LIFECYCLE_V1 — sentinel para valores sensibles redactados.
	 * Un export nunca expone el valor real; al importar, el sentinel significa
	 * "preservar el valor configurado actual" (update) o vacío (create).
	 * Reemplazar un secreto exige escribir el valor literal (intención explícita).
	 */
	const SENTINEL_REDACTED = '__ESTANDAR_REDACTED__';

	/** Columnas almacenadas como JSON string en la tabla. */
	private const JSON_FIELDS = [
		'fields_json',
		'actions_json',
		'context_schema',
		'incidents_config',
		'survey_config',
		'experience_config_json',
		'conversation_config',
	];

	/** Claves que se consideran secretas dentro de estructuras JSON (actions, etc.). */
	private const SECRET_KEY_PATTERN = '/(secret|token|password|api_?key|authorization|signature)/i';

	/**
	 * Campos que se exportan — portables entre instancias.
	 */
	private const PORTABLE = [
		'name',
		'slug',
		'submit_label',
		'success_message',
		'after_submit_text',
		'form_country',
		'store_entries',
		'send_email',
		'send_webhook',
		'notify_emails',
		'webhook_payload_mode',
		'require_recaptcha',
		'mail_subject',
		'mail_sender_name',
		'mail_reply_to_field',
		'visual_theme',
		'form_type',
		'form_context',
		'request_type',
		'fields_json',
		'actions_json',
		'context_schema',
		'incidents_config',
		'survey_config',           // P2
		'experience_config_json',  // P2
		'conversation_config',     // MS-5/MS-6 (ADR 0034) — config de experiencias nuevas
		'webhook_url',             // JSON_LIFECYCLE_V1 — exportado REDACTADO (sentinel)
		'mail_body',               // JSON_LIFECYCLE_V1 — completa la definición lógica
	];

	/**
	 * Campos excluidos de la exportación: id, created_at (runtime inmutable,
	 * expuestos solo en el bloque informativo `readonly` del envelope).
	 * webhook_url se exporta redactado: el sentinel preserva el valor al
	 * actualizar y nunca instala endpoints de otra instancia al crear.
	 */

	/* ══════════════════════════════════════════════
	   API PÚBLICA
	══════════════════════════════════════════════ */

	/**
	 * Exportar un formulario existente como envelope array.
	 *
	 * @param int $form_id
	 * @return array|WP_Error
	 */
	public static function export( int $form_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
				$form_id
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'template_not_found', sprintf( 'Formulario ID %d no encontrado.', $form_id ) );
		}

		$envelope = self::build_envelope( self::row_to_portable( $row ) );

		// JSON_LIFECYCLE_V1 — bloque informativo de datos runtime inmutables.
		// Se muestra pero JAMÁS se importa: create/update lo ignoran siempre.
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}estandar_form_entries WHERE form_id = %d",
				$form_id
			)
		);
		$envelope['readonly'] = [
			'id'          => (int) $row->id,
			'created_at'  => (string) ( $row->created_at ?? '' ),
			'entry_count' => $entry_count,
			'shortcodes'  => [
				'[estandar_form id="' . (int) $row->id . '"]',
				'[estandar_form slug="' . (string) $row->slug . '"]',
			],
		];

		return $envelope;
	}

	/**
	 * Serializar envelope a string JSON descargable.
	 *
	 * @param array $envelope
	 * @return string
	 */
	public static function to_json( array $envelope ): string {
		return (string) wp_json_encode(
			$envelope,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Deserializar string JSON a array envelope.
	 *
	 * @param string $raw
	 * @return array|WP_Error
	 */
	public static function from_json( string $raw ) {
		if ( trim( $raw ) === '' ) {
			return new WP_Error( 'template_empty', 'El archivo está vacío.' );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'template_invalid_json', 'El archivo no contiene JSON válido.' );
		}

		return $decoded;
	}

	/**
	 * Validar estructura mínima de un envelope.
	 *
	 * @param array $envelope
	 * @return true|WP_Error
	 */
	public static function validate( array $envelope ) {
		if ( empty( $envelope['$schema'] ) || strpos( (string) $envelope['$schema'], 'estandar.cl/schemas/forms/template' ) === false ) {
			return new WP_Error( 'template_invalid_schema', 'El archivo no es una plantilla de Estandar Forms válida ($schema incorrecto).' );
		}

		if ( empty( $envelope['version'] ) ) {
			return new WP_Error( 'template_invalid_version', 'La plantilla no especifica versión.' );
		}

		if ( empty( $envelope['form'] ) || ! is_array( $envelope['form'] ) ) {
			return new WP_Error( 'template_missing_form', 'La plantilla no contiene definición de formulario.' );
		}

		$form = $envelope['form'];

		if ( empty( $form['name'] ) || ! is_string( $form['name'] ) || trim( $form['name'] ) === '' ) {
			return new WP_Error( 'template_missing_name', 'La plantilla no contiene nombre de formulario.' );
		}

		// fields_json puede ser array (en el JSON) o string JSON (legado)
		$fields = $form['fields_json'] ?? null;
		if ( is_string( $fields ) ) {
			$fields = json_decode( $fields, true );
		}
		if ( ! is_array( $fields ) || count( $fields ) === 0 ) {
			return new WP_Error( 'template_empty_fields', 'La plantilla no contiene campos de formulario.' );
		}

		return true;
	}

	/**
	 * Importar un envelope validado como nuevo formulario.
	 *
	 * @param array $envelope
	 * @return int|WP_Error  Nuevo form_id
	 */
	public static function import( array $envelope ) {
		$valid = self::validate( $envelope );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$row = self::envelope_to_row( $envelope['form'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		// Create: un sentinel redactado nunca instala valores — queda vacío.
		self::resolve_sentinels( $row, null );

		$row['slug'] = self::unique_slug( $row['slug'] );

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'estandar_forms',
			$row,
			self::row_format( $row )
		);

		if ( $inserted === false ) {
			return new WP_Error( 'template_insert_failed', 'No fue posible crear el formulario desde la plantilla.' );
		}

		$new_id = (int) $wpdb->insert_id;

		if ( class_exists( 'Estandar_Forms_Shortcode' ) ) {
			Estandar_Forms_Shortcode::clear_form_cache( [ 'id' => $new_id, 'slug' => $row['slug'] ] );
		}

		return $new_id;
	}

	/**
	 * Duplicar un formulario existente.
	 *
	 * @param int $form_id
	 * @return int|WP_Error  Nuevo form_id
	 */
	public static function duplicate( int $form_id ) {
		$envelope = self::export( $form_id );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		$envelope['form']['name'] = $envelope['form']['name'] . ' (copia)';
		$envelope['form']['slug'] = self::unique_slug( (string) ( $envelope['form']['slug'] ?? '' ) );

		return self::import( $envelope );
	}

	/* ══════════════════════════════════════════════
	   HANDLERS HTTP (admin_post_*)
	══════════════════════════════════════════════ */

	/**
	 * GET admin-post.php?action=estandar_forms_export&form_id={id}&_wpnonce={nonce}
	 */
	public static function handle_export(): void {
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;

		check_admin_referer( 'estandar_forms_export_' . $form_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		if ( $form_id <= 0 ) {
			wp_die( 'ID de formulario inválido.' );
		}

		$envelope = self::export( $form_id );
		if ( is_wp_error( $envelope ) ) {
			wp_die( esc_html( $envelope->get_error_message() ) );
		}

		$json     = self::to_json( $envelope );
		$slug     = sanitize_file_name( (string) ( $envelope['form']['slug'] ?? 'formulario' ) );
		$filename = 'form-' . $slug . '.estandar-template.json';

		// Sin output previo — admin_post_ se ejecuta antes de cualquier render.
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * POST admin-post.php action=estandar_forms_import _wpnonce={nonce} template_json={file}
	 */
	public static function handle_import(): void {
		check_admin_referer( 'estandar_forms_import' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$redirect = admin_url( 'admin.php?page=estandar-forms-forms' );

		// Verificar archivo
		if (
			empty( $_FILES['template_json'] ) ||
			! is_array( $_FILES['template_json'] ) ||
			(int) ( $_FILES['template_json']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK
		) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$tmp_path = (string) ( $_FILES['template_json']['tmp_name'] ?? '' );
		if ( ! is_uploaded_file( $tmp_path ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// Validar extensión
		$original_name = sanitize_file_name( (string) ( $_FILES['template_json']['name'] ?? '' ) );
		if ( ! str_ends_with( strtolower( $original_name ), '.json' ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$raw = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $raw === false ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$envelope = self::from_json( $raw );
		if ( is_wp_error( $envelope ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// P0: notify_emails — clear before import (file imports never activate production recipients).
		$envelope['form']['notify_emails'] = '';

		// P0: Project Registry — if fixed project_slug is unknown, show warning + options (user decides).
		$project_slug = self::context_fixed_project_slug( $envelope );
		if ( $project_slug !== '' && ! self::slug_in_registry( $project_slug ) ) {
			$transient_key = 'efimport_' . wp_generate_password( 12, false );
			set_transient( $transient_key, $envelope, 30 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( [
				'estandar_forms_resolve'         => $transient_key,
				'estandar_forms_unresolved_slug' => rawurlencode( $project_slug ),
			], $redirect ) );
			exit;
		}

		$new_id = self::import( $envelope );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// P1: Store actions_json warnings and redirect to list with warning notice.
		$warnings = self::detect_actions_warnings( $envelope );
		if ( ! empty( $warnings ) ) {
			set_transient( 'efimport_warnings_' . $new_id, $warnings, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( [
				'estandar_forms_notice'  => 'imported_with_warnings',
				'estandar_forms_new_id'  => $new_id,
			], $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'estandar-forms', 'edit' => $new_id, 'estandar_forms_notice' => 'imported' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * GET admin-post.php?action=estandar_forms_duplicate&form_id={id}&_wpnonce={nonce}
	 */
	public static function handle_duplicate(): void {
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;

		check_admin_referer( 'estandar_forms_duplicate_' . $form_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$redirect = admin_url( 'admin.php?page=estandar-forms-forms' );

		if ( $form_id <= 0 ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'template_error', $redirect ) );
			exit;
		}

		$new_id = self::duplicate( $form_id );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'template_error', $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'estandar-forms', 'edit' => $new_id, 'estandar_forms_notice' => 'created_from_template' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ══════════════════════════════════════════════
	   MÉTODOS PRIVADOS
	══════════════════════════════════════════════ */

	/**
	 * Construir el envelope completo a partir de los campos portables.
	 */
	private static function build_envelope( array $portable ): array {
		return [
			'$schema'     => self::SCHEMA_URI,
			'version'     => self::SCHEMA_VERSION,
			'exported_at' => gmdate( 'c' ),
			'source'      => [
				'plugin'         => 'estandar-forms',
				'plugin_version' => defined( 'ESTANDAR_FORMS_VERSION' ) ? ESTANDAR_FORMS_VERSION : '4.0.0',
			],
			'form'        => $portable,
		];
	}

	/**
	 * Extraer solo los campos portables de una fila DB.
	 * fields_json se decodifica a array para legibilidad en el JSON exportado.
	 */
	private static function row_to_portable( object $row ): array {
		$out = [];

		foreach ( self::PORTABLE as $field ) {
			$value = $row->{$field} ?? null;

			// Decodificar campos JSON almacenados como string
			if ( in_array( $field, self::JSON_FIELDS, true ) ) {
				if ( is_string( $value ) && $value !== '' ) {
					$decoded = json_decode( $value, true );
					$value   = is_array( $decoded ) ? $decoded : null;
				} else {
					$value = null;
				}
			}

			$out[ $field ] = $value;
		}

		// JSON_LIFECYCLE_V1 — secretos: nunca exponer valores sensibles.
		// webhook_url y claves secret-like dentro de actions_json salen redactados;
		// el sentinel significa "preservar el valor actual" al importar.
		if ( ! empty( $out['webhook_url'] ) && is_string( $out['webhook_url'] ) ) {
			$out['webhook_url'] = self::SENTINEL_REDACTED;
		}
		if ( is_array( $out['actions_json'] ?? null ) ) {
			$out['actions_json'] = self::redact_secrets( $out['actions_json'] );
		}

		// JSON_LIFECYCLE_V1 — contenedor de extensión: propiedades desconocidas
		// persisten en context_schema.extensions y se exponen como form.extensions.
		if ( is_array( $out['context_schema'] ?? null ) && isset( $out['context_schema']['extensions'] ) ) {
			if ( is_array( $out['context_schema']['extensions'] ) && ! empty( $out['context_schema']['extensions'] ) ) {
				$out['extensions'] = $out['context_schema']['extensions'];
			}
			unset( $out['context_schema']['extensions'] );
			if ( $out['context_schema'] === [] ) {
				$out['context_schema'] = null;
			}
		}

		return $out;
	}

	/**
	 * Convertir los campos del envelope a una fila lista para INSERT.
	 * Re-serializa campos JSON de array a string.
	 *
	 * @return array|WP_Error
	 */
	private static function envelope_to_row( array $form ) {
		$row = [];

		// JSON_LIFECYCLE_V1 — forward compatibility: las propiedades desconocidas
		// del objeto form NUNCA se destruyen; migran al contenedor extensions
		// (persistido dentro de context_schema.extensions). `readonly` es
		// informativo y se ignora siempre.
		$extensions = is_array( $form['extensions'] ?? null ) ? $form['extensions'] : [];
		foreach ( $form as $key => $value ) {
			if ( $key === 'extensions' || $key === 'readonly' ) {
				continue;
			}
			if ( ! in_array( $key, self::PORTABLE, true ) ) {
				$extensions[ (string) $key ] = $value;
			}
		}

		foreach ( self::PORTABLE as $field ) {
			$value = $form[ $field ] ?? null;

			switch ( $field ) {
				case 'fields_json':
				case 'actions_json':
				case 'context_schema':
				case 'incidents_config':
				case 'survey_config':
				case 'experience_config_json':
				case 'conversation_config':
					if ( is_array( $value ) ) {
						$value = wp_json_encode( $value );
					} elseif ( ! is_string( $value ) || $value === '' ) {
						$value = null;
					}
					break;

				case 'name':
					$value = sanitize_text_field( (string) ( $value ?? '' ) );
					break;

				case 'slug':
					$value = sanitize_title( (string) ( $value ?? '' ) );
					if ( $value === '' ) {
						$value = sanitize_title( (string) ( $form['name'] ?? 'formulario' ) );
					}
					break;

				case 'submit_label':
				case 'mail_reply_to_field':
				case 'mail_sender_name':
				case 'visual_theme':
				case 'webhook_payload_mode':
				case 'form_country':
				case 'form_type':
				case 'form_context':
				case 'request_type':
					$value = sanitize_text_field( (string) ( $value ?? '' ) );
					break;

				case 'notify_emails':
				case 'success_message':
				case 'after_submit_text':
				case 'mail_subject':
				case 'mail_body':
					$value = wp_kses_post( (string) ( $value ?? '' ) );
					break;

				case 'webhook_url':
					// El sentinel sobrevive la sanitización: resolve_sentinels()
					// decide preservar (update) o vaciar (create) más adelante.
					$value = (string) ( $value ?? '' );
					if ( $value !== self::SENTINEL_REDACTED ) {
						$value = esc_url_raw( $value );
					}
					break;

				case 'store_entries':
				case 'send_email':
				case 'send_webhook':
				case 'require_recaptcha':
					$value = (int) ( $value ?? 0 );
					break;
			}

			$row[ $field ] = $value;
		}

		// fields_json es obligatorio
		if ( empty( $row['fields_json'] ) ) {
			return new WP_Error( 'template_missing_fields_json', 'La plantilla no contiene campos válidos.' );
		}

		// Defaults seguros
		$row['submit_label'] = $row['submit_label'] ?: 'Enviar';
		$row['form_country'] = $row['form_country'] ?: 'CL';
		$row['form_type']    = $row['form_type'] ?: 'lead';
		$row['visual_theme'] = sanitize_key( (string) ( $row['visual_theme'] ?? '' ) );
		$row['webhook_payload_mode'] = in_array( (string) ( $row['webhook_payload_mode'] ?? '' ), [ 'estandar', 'cf7_compat' ], true )
			? (string) $row['webhook_payload_mode']
			: 'estandar';

		// Persistir el contenedor de extensiones dentro de context_schema.
		if ( ! empty( $extensions ) ) {
			$schema = is_string( $row['context_schema'] ?? null ) && $row['context_schema'] !== ''
				? json_decode( (string) $row['context_schema'], true )
				: [];
			$schema = is_array( $schema ) ? $schema : [];
			$schema['extensions'] = $extensions;
			$row['context_schema'] = wp_json_encode( $schema );
		}

		return $row;
	}

	/**
	 * Generar un slug único en la tabla estandar_forms.
	 */
	private static function unique_slug( string $base ): string {
		global $wpdb;

		$base = sanitize_title( $base !== '' ? $base : 'formulario' );
		$table = $wpdb->prefix . 'estandar_forms';

		// Verificar base directamente
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $base )
		);

		if ( $exists === 0 ) {
			return $base;
		}

		// Probar sufijos numéricos
		for ( $i = 2; $i <= 99; $i++ ) {
			$candidate = $base . '-' . $i;
			$exists    = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $candidate )
			);
			if ( $exists === 0 ) {
				return $candidate;
			}
		}

		// Fallback con timestamp
		return $base . '-' . time();
	}

	/**
	 * Generar array de formatos para $wpdb->insert().
	 */
	private static function row_format( array $row ): array {
		$int_fields = [ 'store_entries', 'send_email', 'send_webhook', 'require_recaptcha' ];
		$formats    = [];

		foreach ( array_keys( $row ) as $field ) {
			$formats[] = in_array( $field, $int_fields, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/* ══════════════════════════════════════════════
	   PREFLIGHT VALIDATOR
	══════════════════════════════════════════════ */

	/**
	 * Inspect an envelope and return a structured validation report.
	 * Read-only: never modifies the envelope.
	 *
	 * @return array{
	 *   meta: array,
	 *   summary: array,
	 *   checks: array<string, array{status: string, label: string, detail: string}>
	 * }
	 */
	public static function preflight( array $envelope ): array {
		$form = $envelope['form'] ?? [];

		// ── Meta ──────────────────────────────────────────────────────────────
		$meta = [
			'name'                  => (string) ( $form['name'] ?? '' ),
			'slug'                  => (string) ( $form['slug'] ?? '' ),
			'schema_version'        => (string) ( $envelope['version'] ?? '' ),
			'source_plugin'         => (string) ( $envelope['source']['plugin'] ?? '' ),
			'source_plugin_version' => (string) ( $envelope['source']['plugin_version'] ?? '' ),
			'exported_at'           => (string) ( $envelope['exported_at'] ?? '' ),
		];

		// ── Summary ───────────────────────────────────────────────────────────
		$fields_raw  = $form['fields_json'] ?? null;
		$fields      = is_array( $fields_raw ) ? $fields_raw : ( json_decode( (string) $fields_raw, true ) ?: [] );

		$actions_raw = $form['actions_json'] ?? null;
		$actions     = is_array( $actions_raw ) ? $actions_raw : ( json_decode( (string) $actions_raw, true ) ?: [] );

		$incidents_raw  = $form['incidents_config'] ?? null;
		$incidents_data = is_array( $incidents_raw ) ? $incidents_raw : ( json_decode( (string) $incidents_raw, true ) ?: [] );

		$ctx_raw  = $form['context_schema'] ?? null;
		$ctx_data = is_array( $ctx_raw ) ? $ctx_raw : ( json_decode( (string) $ctx_raw, true ) ?: [] );
		$routing  = isset( $ctx_data['routing'] ) && is_array( $ctx_data['routing'] ) ? $ctx_data['routing'] : $ctx_data;
		$ctx_mode = sanitize_key( (string) ( $routing['mode'] ?? 'none' ) );

		$summary = [
			'field_count'    => count( (array) $fields ),
			'action_count'   => count( (array) $actions ),
			'context_mode'   => $ctx_mode,
			'has_incidents'  => ! empty( $incidents_data ),
			'has_survey'     => ! empty( $form['survey_config'] ) && $form['survey_config'] !== 'null',
			'has_experience' => ! empty( $form['experience_config_json'] ) && $form['experience_config_json'] !== 'null',
		];

		// ── Checks ────────────────────────────────────────────────────────────
		$checks = [];

		// 1. Schema version
		$sv = $meta['schema_version'];
		if ( $sv === self::SCHEMA_VERSION ) {
			$checks['schema_version'] = [ 'status' => 'pass', 'label' => 'Versión de schema', 'detail' => "v{$sv}" ];
		} else {
			$checks['schema_version'] = [ 'status' => 'warning', 'label' => 'Versión de schema', 'detail' => "Origen v{$sv} · Esta instalación usa v" . self::SCHEMA_VERSION ];
		}

		// 2. Plugin version
		$src_v  = $meta['source_plugin_version'];
		$dest_v = defined( 'ESTANDAR_FORMS_VERSION' ) ? (string) ESTANDAR_FORMS_VERSION : '?';
		if ( $src_v === $dest_v ) {
			$checks['source_version'] = [
				'status'       => 'pass',
				'label'        => 'Versión del plugin',
				'detail'       => "Origen v{$src_v} · Destino v{$dest_v} — Compatible",
				'src_version'  => $src_v,
				'dest_version' => $dest_v,
				'compat'       => 'compatible',
			];
		} else {
			$checks['source_version'] = [
				'status'       => 'warning',
				'label'        => 'Versión del plugin',
				'detail'       => "Origen v{$src_v} · Destino v{$dest_v} — Revisar",
				'src_version'  => $src_v,
				'dest_version' => $dest_v,
				'compat'       => 'revisar',
			];
		}

		// 3. Project
		$project_slug = self::context_fixed_project_slug( $envelope );
		if ( $project_slug === '' ) {
			$mode_label = $ctx_mode === 'project_selector' ? 'Selector de proyecto' : 'Sin proyecto fijo';
			$checks['project'] = [ 'status' => 'pass', 'label' => 'Proyecto', 'detail' => $mode_label ];
		} elseif ( self::slug_in_registry( $project_slug ) ) {
			$checks['project'] = [ 'status' => 'pass', 'label' => 'Proyecto', 'detail' => "Encontrado: {$project_slug}" ];
		} else {
			$checks['project'] = [ 'status' => 'warning', 'label' => 'Proyecto', 'detail' => "No existe en esta instalación: {$project_slug} — se pedirá resolución al importar" ];
		}

		// 4. notify_emails
		$notify = trim( (string) ( $form['notify_emails'] ?? '' ) );
		if ( $notify === '' ) {
			$checks['notify_emails'] = [ 'status' => 'pass', 'label' => 'Destinatarios de email', 'detail' => 'Vacío — se importará sin destinatarios activos' ];
		} else {
			$checks['notify_emails'] = [ 'status' => 'warning', 'label' => 'Destinatarios de email', 'detail' => "Contiene: {$notify} — se limpiará automáticamente al importar" ];
		}

		// 5. actions_json
		$action_warnings = self::detect_actions_warnings( $envelope );
		if ( empty( $action_warnings ) ) {
			$n = count( (array) $actions );
			$checks['actions_json'] = [ 'status' => 'pass', 'label' => 'Actions (emails / URLs)', 'detail' => $n > 0 ? "{$n} acción(es) sin referencias externas detectadas" : 'Sin acciones' ];
		} else {
			$checks['actions_json'] = [ 'status' => 'warning', 'label' => 'Actions (emails / URLs)', 'detail' => implode( ' · ', $action_warnings ) ];
		}

		// 6. survey_config
		if ( $summary['has_survey'] ) {
			$checks['survey_config'] = [ 'status' => 'warning', 'label' => 'Survey config', 'detail' => 'Contiene configuración de survey — verificar compatibilidad' ];
		} else {
			$checks['survey_config'] = [ 'status' => 'pass', 'label' => 'Survey config', 'detail' => 'Sin configuración de survey' ];
		}

		// 7. experience_config_json
		if ( $summary['has_experience'] ) {
			$checks['experience_config_json'] = [ 'status' => 'warning', 'label' => 'Experience config', 'detail' => 'Contiene configuración de experience engine — verificar compatibilidad' ];
		} else {
			$checks['experience_config_json'] = [ 'status' => 'pass', 'label' => 'Experience config', 'detail' => 'Sin configuración de experience' ];
		}

		// ── Global status ─────────────────────────────────────────────────────
		$pass_count    = 0;
		$warning_count = 0;
		$fail_count    = 0;
		foreach ( $checks as $check ) {
			if ( $check['status'] === 'pass' )    { $pass_count++; }
			if ( $check['status'] === 'warning' ) { $warning_count++; }
			if ( $check['status'] === 'fail' )    { $fail_count++; }
		}
		$global_status = $fail_count > 0 ? 'fail' : ( $warning_count > 0 ? 'warning' : 'pass' );

		return [
			'meta'    => $meta,
			'summary' => $summary,
			'checks'  => $checks,
			'global'  => [
				'status'        => $global_status,
				'total_checks'  => count( $checks ),
				'pass_count'    => $pass_count,
				'warning_count' => $warning_count,
				'fail_count'    => $fail_count,
			],
		];
	}

	/**
	 * POST admin-post.php action=estandar_forms_import_preflight
	 * Reads and validates the uploaded file, then redirects to the preflight report page.
	 * Does NOT insert anything.
	 */
	public static function handle_import_preflight(): void {
		check_admin_referer( 'estandar_forms_import' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$redirect = admin_url( 'admin.php?page=estandar-forms-forms' );

		if (
			empty( $_FILES['template_json'] ) ||
			! is_array( $_FILES['template_json'] ) ||
			(int) ( $_FILES['template_json']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK
		) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$tmp_path = (string) ( $_FILES['template_json']['tmp_name'] ?? '' );
		if ( ! is_uploaded_file( $tmp_path ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$original_name = sanitize_file_name( (string) ( $_FILES['template_json']['name'] ?? '' ) );
		if ( ! str_ends_with( strtolower( $original_name ), '.json' ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$raw = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $raw === false ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$envelope = self::from_json( $raw );
		if ( is_wp_error( $envelope ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$valid = self::validate( $envelope );
		if ( is_wp_error( $valid ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$key = 'efpf_' . wp_generate_password( 12, false );
		set_transient( $key, [
			'envelope' => $envelope,
			'sha256'   => hash( 'sha256', $raw ),
		], 30 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'ef_preflight', $key, $redirect ) );
		exit;
	}

	/**
	 * POST admin-post.php action=estandar_forms_import_confirmed
	 * Reads the preflight transient and executes the actual import.
	 */
	public static function handle_import_confirmed(): void {
		check_admin_referer( 'estandar_forms_import_confirmed' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$redirect = admin_url( 'admin.php?page=estandar-forms-forms' );

		$key = sanitize_key( (string) ( $_POST['ef_preflight_key'] ?? '' ) );
		if ( $key === '' ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$data = get_transient( $key );
		delete_transient( $key );

		// Support both new format ['envelope'=>..., 'sha256'=>...] and legacy bare envelope.
		$envelope = ( is_array( $data ) && isset( $data['envelope'] ) ) ? $data['envelope'] : $data;

		if ( ! is_array( $envelope ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// Clear notify_emails — file imports never activate production recipients.
		$envelope['form']['notify_emails'] = '';

		// Project Registry check — if unknown, show resolution screen.
		$project_slug = self::context_fixed_project_slug( $envelope );
		if ( $project_slug !== '' && ! self::slug_in_registry( $project_slug ) ) {
			$transient_key = 'efimport_' . wp_generate_password( 12, false );
			set_transient( $transient_key, $envelope, 30 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( [
				'estandar_forms_resolve'         => $transient_key,
				'estandar_forms_unresolved_slug' => rawurlencode( $project_slug ),
			], $redirect ) );
			exit;
		}

		$new_id = self::import( $envelope );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$warnings = self::detect_actions_warnings( $envelope );
		if ( ! empty( $warnings ) ) {
			set_transient( 'efimport_warnings_' . $new_id, $warnings, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( [
				'estandar_forms_notice' => 'imported_with_warnings',
				'estandar_forms_new_id' => $new_id,
			], $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'estandar-forms', 'edit' => $new_id, 'estandar_forms_notice' => 'imported' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ══════════════════════════════════════════════
	   IMPORT RESOLVE HANDLER (P0 — step 2)
	══════════════════════════════════════════════ */

	/**
	 * POST admin-post.php action=estandar_forms_import_resolve
	 * Step 2 of a blocked import: user picks a replacement project (or none), then INSERT proceeds.
	 */
	public static function handle_import_resolve(): void {
		check_admin_referer( 'estandar_forms_import_resolve' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$redirect = admin_url( 'admin.php?page=estandar-forms-forms' );

		$transient_key = sanitize_key( (string) ( $_POST['ef_import_key'] ?? '' ) );
		if ( $transient_key === '' ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		$envelope = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! is_array( $envelope ) ) {
			// Transient expired or invalid key.
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// Apply the project override to context_schema.
		$chosen_slug = sanitize_title( remove_accents(
			sanitize_text_field( (string) ( $_POST['ef_project_slug'] ?? '' ) )
		) );

		$schema = $envelope['form']['context_schema'] ?? null;
		if ( is_array( $schema ) ) {
			$has_routing_key = isset( $schema['routing'] ) && is_array( $schema['routing'] );
			$target          = $has_routing_key ? $schema['routing'] : $schema;

			if ( $chosen_slug !== '' ) {
				$target['project_slug'] = $chosen_slug;
				$target['mode']         = 'fixed_project';
			} else {
				unset( $target['project_slug'] );
				$target['mode'] = 'none';
			}

			$envelope['form']['context_schema'] = $has_routing_key
				? array_merge( $schema, [ 'routing' => $target ] )
				: $target;
		}

		$new_id = self::import( $envelope );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'estandar_forms_notice', 'import_error', $redirect ) );
			exit;
		}

		// P1: Store any actions_json warnings.
		$warnings = self::detect_actions_warnings( $envelope );
		if ( ! empty( $warnings ) ) {
			set_transient( 'efimport_warnings_' . $new_id, $warnings, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( [
				'estandar_forms_notice'  => 'imported_with_warnings',
				'estandar_forms_new_id'  => $new_id,
			], $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'estandar-forms', 'edit' => $new_id, 'estandar_forms_notice' => 'imported' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ══════════════════════════════════════════════
	   HELPERS PRIVADOS — P0 / P1
	══════════════════════════════════════════════ */

	/**
	 * Extract the fixed project_slug from a context_schema envelope field.
	 * Returns '' for mode=none, mode=project_selector, or when context_schema is absent.
	 */
	private static function context_fixed_project_slug( array $envelope ): string {
		$schema = $envelope['form']['context_schema'] ?? null;
		if ( empty( $schema ) ) {
			return '';
		}
		$data    = is_array( $schema ) ? $schema : ( json_decode( (string) $schema, true ) ?: [] );
		$routing = isset( $data['routing'] ) && is_array( $data['routing'] ) ? $data['routing'] : $data;
		$mode    = sanitize_key( (string) ( $routing['mode'] ?? 'none' ) );
		if ( $mode !== 'fixed_project' ) {
			return '';
		}
		return sanitize_title( remove_accents( (string) ( $routing['project_slug'] ?? '' ) ) );
	}

	/**
	 * Check if a project slug exists in the active project registry.
	 * Returns true when the registry is unavailable — can't validate, don't block imports.
	 */
	private static function slug_in_registry( string $slug ): bool {
		if ( $slug === '' ) {
			return true;
		}
		if ( ! function_exists( 'estandar_forms_project_registry' ) ) {
			return true;
		}
		$registry = estandar_forms_project_registry();
		if ( empty( $registry ) ) {
			return true; // registry unavailable — skip validation
		}
		foreach ( $registry as $project ) {
			if ( sanitize_title( remove_accents( (string) ( $project['project_slug'] ?? '' ) ) ) === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan the form's actions_json for URLs and email addresses that likely point to
	 * production endpoints. Returns human-readable warning strings (P1).
	 */
	public static function detect_actions_warnings( array $envelope ): array {
		$warnings = [];
		$actions  = $envelope['form']['actions_json'] ?? null;
		if ( empty( $actions ) ) {
			return $warnings;
		}
		$list = is_array( $actions ) ? $actions : ( json_decode( (string) $actions, true ) ?: [] );
		if ( empty( $list ) ) {
			return $warnings;
		}

		$json_flat = wp_json_encode( $list ) ?: '';

		if ( preg_match_all( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $json_flat, $m_emails ) ) {
			foreach ( array_unique( $m_emails[0] ) as $email ) {
				$warnings[] = 'Email de producción en actions_json: ' . $email;
			}
		}

		if ( preg_match_all( '/https?:\/\/[^\s"\'<>\\\\]+/', $json_flat, $m_urls ) ) {
			foreach ( array_unique( $m_urls[0] ) as $url ) {
				$warnings[] = 'URL en actions_json: ' . $url;
			}
		}

		return $warnings;
	}

	/* ══════════════════════════════════════════════════════════════════════
	   JSON_LIFECYCLE_V1 — ciclo de vida canónico
	   Export → Edit → Validate (L1-L4) → Diff → Apply → Rehydrate → Commit.
	   ÚNICA ruta de escritura para el editor JSON, import y APIs futuras.
	   Nunca crear rutas de serialización paralelas: extender esta clase.
	══════════════════════════════════════════════════════════════════════ */

	/**
	 * Catálogo canónico de tipos de campo soportados por el renderer.
	 * Extensible por configuración (filter) — nunca hardcodear por cliente.
	 *
	 * @return array<int,string>
	 */
	public static function field_type_catalog(): array {
		$types = [
			'text', 'email', 'tel', 'number', 'url', 'date', 'textarea',
			'select', 'radio', 'checkbox', 'file', 'hidden', 'rut',
			'heading', 'html', 'dependent_select', 'project_select',
			'emoji', 'cards', 'rating', 'matrix',
		];
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_forms_field_types', $types );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$types = array_values( array_unique( array_map( 'strval', $filtered ) ) );
			}
		}
		return $types;
	}

	/**
	 * Tipos de pregunta soportados por el runtime de encuestas
	 * (survey-render.php · class-survey-handler.php). Extiende el catálogo
	 * base SOLO cuando form_type = survey — nunca lo reemplaza: un nps en un
	 * formulario no-survey sigue siendo unknown_field_type.
	 *
	 * @return array<int,string>
	 */
	public static function survey_question_type_catalog(): array {
		// MS-4 (ADR 0034): fuente única del catálogo de tipos del motor (T2).
		$types = class_exists( 'Estandar_Conversation_Engine' )
			? Estandar_Conversation_Engine::question_type_catalog()
			: [ 'nps', 'rating', 'emoji', 'cards', 'text', 'select', 'radio', 'textarea', 'matrix', 'checkbox' ];
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_forms_survey_question_types', $types );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$types = array_values( array_unique( array_map( 'strval', $filtered ) ) );
			}
		}
		return $types;
	}

	/**
	 * Capas 2 + 3 — validación de schema y lógica con rutas JSON exactas.
	 * Reporta TODOS los problemas simultáneamente; nunca se detiene en el primero.
	 *
	 * @return array{errors: array<int,array>, warnings: array<int,array>}
	 */
	public static function lint( array $envelope ): array {
		$errors   = [];
		$warnings = [];
		$err  = static function ( string $path, string $code, string $message, array $extra = [] ) use ( &$errors ): void {
			$errors[] = array_merge( [ 'path' => $path, 'code' => $code, 'message' => $message ], $extra );
		};
		$warn = static function ( string $path, string $code, string $message ) use ( &$warnings ): void {
			$warnings[] = [ 'path' => $path, 'code' => $code, 'message' => $message ];
		};

		// ── Capa 2 · envelope ────────────────────────────────────────────────
		if ( empty( $envelope['$schema'] ) || strpos( (string) $envelope['$schema'], 'estandar.cl/schemas/forms/template' ) === false ) {
			$err( '$schema', 'invalid_schema', 'El documento no declara el $schema de Estandar Forms.' );
		}
		$version = (string) ( $envelope['version'] ?? '' );
		if ( $version === '' ) {
			$err( 'version', 'missing_version', 'Falta la versión del schema.' );
		} elseif ( strpos( $version, '1.' ) !== 0 ) {
			$err( 'version', 'unsupported_version', 'Versión de schema no soportada: ' . $version . ' (soportadas: 1.x).' );
		} elseif ( $version !== self::SCHEMA_VERSION ) {
			$warn( 'version', 'version_drift', 'Documento v' . $version . ' — esta instalación usa v' . self::SCHEMA_VERSION . '. Migración automática al aplicar.' );
		}

		$form = $envelope['form'] ?? null;
		if ( ! is_array( $form ) ) {
			$err( 'form', 'missing_form', 'El documento no contiene el objeto form.' );
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		if ( trim( (string) ( $form['name'] ?? '' ) ) === '' ) {
			$err( 'form.name', 'missing_name', 'El formulario requiere un nombre.' );
		}
		$raw_slug = (string) ( $form['slug'] ?? '' );
		if ( $raw_slug !== '' && sanitize_title( $raw_slug ) !== $raw_slug ) {
			$warn( 'form.slug', 'slug_not_canonical', 'El slug será normalizado a "' . sanitize_title( $raw_slug ) . '".' );
		}

		$form_types = [ 'general', 'lead', 'lead_capture', 'feedback', 'postventa', 'soporte', 'email_direct', 'experience', 'survey', 'incident' ];
		if ( function_exists( 'apply_filters' ) ) {
			$ft = apply_filters( 'estandar_forms_supported_form_types', $form_types );
			$form_types = is_array( $ft ) && ! empty( $ft ) ? $ft : $form_types;
		}
		if ( isset( $form['form_type'] ) && $form['form_type'] !== '' && ! in_array( (string) $form['form_type'], $form_types, true ) ) {
			$err( 'form.form_type', 'unknown_form_type', 'Tipo de formulario desconocido: ' . $form['form_type'], [ 'allowed' => $form_types ] );
		}
		if ( isset( $form['webhook_payload_mode'] ) && $form['webhook_payload_mode'] !== '' && ! in_array( (string) $form['webhook_payload_mode'], [ 'estandar', 'cf7_compat' ], true ) ) {
			$err( 'form.webhook_payload_mode', 'invalid_value', 'Modo de payload desconocido: ' . $form['webhook_payload_mode'], [ 'allowed' => [ 'estandar', 'cf7_compat' ] ] );
		}

		// ── Capa 3 · campos ─────────────────────────────────────────────────
		$fields = $form['fields_json'] ?? null;
		if ( is_string( $fields ) ) {
			$fields = json_decode( $fields, true );
		}
		$names = [];
		if ( ! is_array( $fields ) || count( $fields ) === 0 ) {
			$err( 'form.fields_json', 'empty_fields', 'El formulario no contiene campos.' );
		} else {
			$catalog   = self::field_type_catalog();
			$is_survey = (string) ( $form['form_type'] ?? '' ) === 'survey';
			if ( $is_survey ) {
				// Las encuestas comparten tabla y ciclo con Forms; su runtime
				// agrega tipos de pregunta propios (nps) al catálogo base.
				$catalog = array_values( array_unique( array_merge( $catalog, self::survey_question_type_catalog() ) ) );
			}
			foreach ( $fields as $i => $field ) {
				$path = 'form.fields_json[' . $i . ']';
				if ( ! is_array( $field ) ) {
					$err( $path, 'invalid_field', 'El campo debe ser un objeto.' );
					continue;
				}
				$type = (string) ( $field['type'] ?? '' );
				if ( $type === '' ) {
					$err( $path . '.type', 'missing_type', 'El campo no declara type.', [ 'allowed' => $catalog ] );
				} elseif ( ! in_array( $type, $catalog, true ) ) {
					$err( $path . '.type', 'unknown_field_type', 'Tipo de campo desconocido: ' . $type, [ 'allowed' => $catalog ] );
				}
				if ( in_array( $type, [ 'heading', 'html' ], true ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( $name === '' ) {
					$err( $path . '.name', 'missing_field_name', 'El campo requiere name.' );
				} elseif ( isset( $names[ $name ] ) ) {
					$err( $path . '.name', 'duplicate_name', 'Nombre de campo duplicado: "' . $name . '" (también en form.fields_json[' . $names[ $name ] . ']).' );
				} else {
					$names[ $name ] = $i;
				}
				if ( in_array( $type, [ 'select', 'radio', 'emoji', 'cards' ], true ) ) {
					$has_options = ! empty( $field['options'] ) && ( is_array( $field['options'] ) || is_string( $field['options'] ) );
					if ( ! $has_options && empty( $field['dataset'] ) ) {
						$err( $path . '.options', 'missing_options', 'Los campos ' . $type . ' requieren options o dataset.' );
					}
				}
				if ( $type === 'dependent_select' && trim( (string) ( $field['depends_on'] ?? '' ) ) === '' ) {
					$err( $path . '.depends_on', 'missing_depends_on', 'dependent_select requiere depends_on.' );
				}
				if ( $is_survey && $type !== '' && in_array( $type, $catalog, true )
					&& ! in_array( $type, self::survey_question_type_catalog(), true )
					&& ! in_array( $type, [ 'heading', 'html', 'hidden' ], true ) ) {
					$warn( $path . '.type', 'non_survey_question_type', 'El tipo "' . $type . '" no es un tipo de pregunta del runtime de encuestas (' . implode( ', ', self::survey_question_type_catalog() ) . ').' );
				}
			}

			// Referencias de lógica condicional (segunda pasada: refs hacia adelante son válidas).
			foreach ( $fields as $i => $field ) {
				if ( ! is_array( $field ) || empty( $field['conditional_logic'] ) ) {
					continue;
				}
				$logic = $field['conditional_logic'];
				$path  = 'form.fields_json[' . $i . '].conditional_logic';
				if ( ! is_array( $logic ) ) {
					$err( $path, 'invalid_condition', 'conditional_logic debe ser un objeto {field, operator, value}.' );
					continue;
				}
				$ref = (string) ( $logic['field'] ?? '' );
				$op  = (string) ( $logic['operator'] ?? 'equals' );
				if ( $ref === '' ) {
					$err( $path . '.field', 'missing_condition_field', 'La condición no indica campo de referencia.' );
				} elseif ( ! isset( $names[ $ref ] ) ) {
					$err( $path . '.field', 'broken_condition_reference', 'La condición referencia un campo inexistente: "' . $ref . '".' );
				}
				$allowed_ops = [ 'equals', 'not_equals', 'contains', 'not_empty', 'empty' ];
				if ( ! in_array( $op, $allowed_ops, true ) ) {
					$err( $path . '.operator', 'unknown_condition_operator', 'Operador desconocido: ' . $op, [ 'allowed' => $allowed_ops ] );
				}
			}
		}

		// ── Capa 3 · notificaciones ─────────────────────────────────────────
		$notify = trim( (string) ( $form['notify_emails'] ?? '' ) );
		if ( $notify !== '' ) {
			foreach ( (array) preg_split( '/[\s,;]+/', $notify ) as $email ) {
				if ( $email !== '' && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
					$err( 'form.notify_emails', 'invalid_email', 'Email de notificación inválido: ' . $email );
				}
			}
		}

		// ── Capa 3 · webhook ────────────────────────────────────────────────
		$webhook = (string) ( $form['webhook_url'] ?? '' );
		if ( $webhook !== '' && $webhook !== self::SENTINEL_REDACTED
			&& ( ! filter_var( $webhook, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $webhook ) ) ) {
			$err( 'form.webhook_url', 'invalid_url', 'URL de webhook inválida (se requiere http/https).' );
		}
		if ( ! empty( $form['send_webhook'] ) && $webhook === '' ) {
			$warn( 'form.webhook_url', 'webhook_enabled_without_url', 'send_webhook está activo pero webhook_url está vacío.' );
		}

		// ── Capa 3 · routing / contexto ─────────────────────────────────────
		$ctx = $form['context_schema'] ?? null;
		if ( is_string( $ctx ) && $ctx !== '' ) {
			$ctx = json_decode( $ctx, true );
			if ( ! is_array( $ctx ) ) {
				$err( 'form.context_schema', 'invalid_context', 'context_schema no es JSON válido.' );
				$ctx = null;
			}
		}
		if ( $ctx !== null && ! is_array( $ctx ) ) {
			$err( 'form.context_schema', 'invalid_context', 'context_schema debe ser un objeto.' );
		} elseif ( is_array( $ctx ) ) {
			$routing = isset( $ctx['routing'] ) && is_array( $ctx['routing'] ) ? $ctx['routing'] : [];
			$mode    = (string) ( $routing['mode'] ?? 'none' );
			if ( ! in_array( $mode, [ 'none', 'fixed_project', 'project_selector', 'user_selector' ], true ) ) {
				$err( 'form.context_schema.routing.mode', 'invalid_routing_mode', 'Modo de routing desconocido: ' . $mode, [ 'allowed' => [ 'none', 'fixed_project', 'project_selector' ] ] );
			}
			if ( $mode === 'fixed_project' ) {
				$pslug = sanitize_title( remove_accents( (string) ( $routing['project_slug'] ?? '' ) ) );
				if ( $pslug === '' ) {
					$err( 'form.context_schema.routing.project_slug', 'missing_project', 'fixed_project requiere project_slug.' );
				} elseif ( ! self::slug_in_registry( $pslug ) ) {
					$warn( 'form.context_schema.routing.project_slug', 'project_not_in_registry', 'El proyecto "' . $pslug . '" no existe en esta instalación.' );
				}
			}
		}

		// ── Capa 3 · acciones ───────────────────────────────────────────────
		$actions = $form['actions_json'] ?? null;
		if ( is_string( $actions ) && $actions !== '' ) {
			$actions = json_decode( $actions, true );
			if ( ! is_array( $actions ) ) {
				$err( 'form.actions_json', 'invalid_actions', 'actions_json no es JSON válido.' );
			}
		} elseif ( $actions !== null && ! is_array( $actions ) ) {
			$err( 'form.actions_json', 'invalid_actions', 'actions_json debe ser una lista.' );
		}

		// ── Capa 3 · survey_config ──────────────────────────────────────────
		$survey_cfg = $form['survey_config'] ?? null;
		if ( is_string( $survey_cfg ) && $survey_cfg !== '' && $survey_cfg !== 'null' ) {
			$survey_cfg = json_decode( $survey_cfg, true );
			if ( ! is_array( $survey_cfg ) ) {
				$err( 'form.survey_config', 'invalid_survey_config', 'survey_config no es JSON válido.' );
				$survey_cfg = null;
			}
		}
		if ( is_array( $survey_cfg ) && ! empty( $survey_cfg ) ) {
			$allowed_id_modes = [ 'anonymous', 'identified', 'optional' ];
			$id_mode = (string) ( $survey_cfg['identification_mode'] ?? 'anonymous' );
			if ( ! in_array( $id_mode, $allowed_id_modes, true ) ) {
				$err( 'form.survey_config.identification_mode', 'invalid_identification_mode', 'Modo de identificación desconocido: ' . $id_mode, [ 'allowed' => $allowed_id_modes ] );
			}
			$hero_url = (string) ( $survey_cfg['hero_image_url'] ?? '' );
			if ( $hero_url !== '' && ( ! filter_var( $hero_url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $hero_url ) ) ) {
				$err( 'form.survey_config.hero_image_url', 'invalid_url', 'URL de imagen del hero inválida (se requiere http/https).' );
			}
		}

		// ── Capa 3 · conversation_config (MS-5/MS-6 · ADR 0034) ─────────────
		$conv_cfg = $form['conversation_config'] ?? null;
		if ( is_string( $conv_cfg ) && $conv_cfg !== '' && $conv_cfg !== 'null' ) {
			$conv_cfg = json_decode( $conv_cfg, true );
			if ( ! is_array( $conv_cfg ) ) {
				$err( 'form.conversation_config', 'invalid_conversation_config', 'conversation_config no es JSON válido.' );
			}
		}

		// ── Forward compatibility ───────────────────────────────────────────
		if ( isset( $form['extensions'] ) && ! is_array( $form['extensions'] ) ) {
			$err( 'form.extensions', 'invalid_extensions', 'extensions debe ser un objeto.' );
		}
		foreach ( $form as $key => $value ) {
			if ( $key === 'extensions' || $key === 'readonly' ) {
				continue;
			}
			if ( ! in_array( (string) $key, self::PORTABLE, true ) ) {
				$warn( 'form.' . $key, 'unknown_property', 'Propiedad desconocida — se preservará en form.extensions al aplicar.' );
			}
		}
		if ( isset( $envelope['readonly'] ) ) {
			$warn( 'readonly', 'readonly_ignored', 'El bloque readonly es informativo (id, created_at, estadísticas) y nunca se importa.' );
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/**
	 * Capa 4 — validación runtime SIN persistir: hidrata, normaliza, compila
	 * condiciones y resuelve routing usando exactamente el mismo código que
	 * ejecuta el render en producción. Solo formularios ejecutables se guardan.
	 *
	 * @return array<int,array{path:string, code:string, message:string}>
	 */
	public static function dry_run( array $envelope ): array {
		$issues = [];
		$form   = is_array( $envelope['form'] ?? null ) ? $envelope['form'] : [];

		$row = self::envelope_to_row( $form );
		if ( is_wp_error( $row ) ) {
			return [ [ 'path' => 'form', 'code' => 'hydration_failed', 'message' => $row->get_error_message() ] ];
		}

		// Compilación de campos con el normalizador real del pipeline.
		$normalized = function_exists( 'estandar_forms_decode_fields' )
			? estandar_forms_decode_fields( (string) $row['fields_json'] )
			: ( json_decode( (string) $row['fields_json'], true ) ?: [] );
		if ( empty( $normalized ) ) {
			$issues[] = [ 'path' => 'form.fields_json', 'code' => 'not_renderable', 'message' => 'Ningún campo sobrevive la normalización — el formulario no es ejecutable.' ];
		}

		// Las condiciones declaradas deben sobrevivir la compilación.
		$doc_fields = is_array( $form['fields_json'] ?? null ) ? $form['fields_json'] : ( json_decode( (string) ( $form['fields_json'] ?? '' ), true ) ?: [] );
		$normalized_by_name = [];
		foreach ( $normalized as $nf ) {
			if ( ! empty( $nf['name'] ) ) {
				$normalized_by_name[ (string) $nf['name'] ] = $nf;
			}
		}
		foreach ( $doc_fields as $i => $df ) {
			if ( ! is_array( $df ) || empty( $df['conditional_logic'] ) || empty( $df['name'] ) ) {
				continue;
			}
			$name = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $df['name'] ) : strtolower( (string) $df['name'] );
			if ( isset( $normalized_by_name[ $name ] ) && empty( $normalized_by_name[ $name ]['conditional_logic'] ) ) {
				$issues[] = [ 'path' => 'form.fields_json[' . $i . '].conditional_logic', 'code' => 'condition_not_compilable', 'message' => 'La condición no compila (campo u operador inválido) y sería descartada.' ];
			}
		}

		// Resolución de routing con el resolver real.
		if ( function_exists( 'estandar_forms_routing_context' ) ) {
			$ctx = estandar_forms_routing_context( (string) ( $row['context_schema'] ?? '' ) );
			if ( ( $ctx['mode'] ?? 'none' ) === 'fixed_project' && (string) ( $ctx['project_slug'] ?? '' ) === '' ) {
				$issues[] = [ 'path' => 'form.context_schema.routing', 'code' => 'routing_unresolvable', 'message' => 'El routing fixed_project no resuelve a un proyecto.' ];
			}
		}

		// Identidad de shortcode.
		if ( trim( (string) ( $row['slug'] ?? '' ) ) === '' ) {
			$issues[] = [ 'path' => 'form.slug', 'code' => 'no_shortcode_identity', 'message' => 'Sin slug no existe identidad estable para el shortcode.' ];
		}

		// Columnas JSON re-decodificables (round-trip interno).
		foreach ( self::JSON_FIELDS as $jf ) {
			$val = $row[ $jf ] ?? null;
			if ( is_string( $val ) && $val !== '' && json_decode( $val, true ) === null && strtolower( $val ) !== 'null' ) {
				$issues[] = [ 'path' => 'form.' . $jf, 'code' => 'json_column_corrupt', 'message' => 'La columna ' . $jf . ' no re-decodifica como JSON.' ];
			}
		}

		return $issues;
	}

	/**
	 * Representación canónica comparable de un envelope (para diff y
	 * equivalencia round-trip). Determinista: mismo documento lógico →
	 * misma forma canónica, independiente del orden de claves.
	 */
	public static function canonical_form( array $envelope ): array {
		$form = is_array( $envelope['form'] ?? null ) ? $envelope['form'] : [];
		$row  = self::envelope_to_row( $form );
		if ( is_wp_error( $row ) ) {
			return [];
		}

		$out = [];
		foreach ( $row as $key => $value ) {
			if ( in_array( $key, self::JSON_FIELDS, true ) ) {
				$value = is_string( $value ) && $value !== '' ? json_decode( $value, true ) : null;
			}
			$out[ $key ] = $value;
		}

		// Campos: comparar la forma NORMALIZADA (la que ejecuta el render).
		if ( function_exists( 'estandar_forms_decode_fields' ) ) {
			$out['fields_json'] = estandar_forms_decode_fields( (string) ( $row['fields_json'] ?? '[]' ) );
		}

		// Extensiones al nivel superior (simétrico con row_to_portable).
		if ( is_array( $out['context_schema'] ?? null ) && isset( $out['context_schema']['extensions'] ) ) {
			$out['extensions'] = is_array( $out['context_schema']['extensions'] ) ? $out['context_schema']['extensions'] : [];
			unset( $out['context_schema']['extensions'] );
			if ( $out['context_schema'] === [] ) {
				$out['context_schema'] = null;
			}
		}

		self::ksort_recursive( $out );
		return $out;
	}

	/**
	 * Diff semántico entre el formulario actual y el documento entrante.
	 * Cada item: {path, kind: added|removed|changed|renamed, label, destructive, before, after}.
	 * Las operaciones destructivas (campo eliminado, slug cambiado, webhook
	 * removido, routing cambiado) quedan marcadas explícitamente.
	 *
	 * @return array<int,array>
	 */
	public static function semantic_diff( array $current_envelope, array $target_envelope ): array {
		$a = self::canonical_form( $current_envelope );
		$b = self::canonical_form( $target_envelope );
		if ( empty( $a ) && empty( $b ) ) {
			return [];
		}

		$labels = [
			'name' => 'Nombre', 'slug' => 'Slug', 'form_type' => 'Tipo de formulario',
			'form_context' => 'Contexto de negocio', 'request_type' => 'Tipo de solicitud',
			'webhook_url' => 'Webhook URL', 'send_webhook' => 'Webhook activo',
			'webhook_payload_mode' => 'Formato de payload webhook',
			'notify_emails' => 'Destinatarios de notificación',
			'mail_subject' => 'Asunto de email (autoresponse)', 'mail_body' => 'Cuerpo de email (autoresponse)',
			'mail_sender_name' => 'Remitente de email', 'mail_reply_to_field' => 'Campo reply-to',
			'send_email' => 'Email directo activo', 'context_schema' => 'Routing / contexto',
			'actions_json' => 'Acciones post-submit', 'incidents_config' => 'Configuración de incidentes',
			'survey_config' => 'Configuración de survey', 'experience_config_json' => 'Configuración de experience',
			'success_message' => 'Mensaje de éxito', 'submit_label' => 'Etiqueta del botón',
			'after_submit_text' => 'Texto post-envío', 'visual_theme' => 'Tema visual',
			'require_recaptcha' => 'reCAPTCHA', 'store_entries' => 'Almacenar entradas',
			'form_country' => 'País', 'extensions' => 'Extensiones (metadata)',
		];
		$destructive_change = [ 'slug', 'form_type', 'webhook_url', 'send_webhook', 'notify_emails', 'context_schema', 'actions_json' ];

		$items = [];

		// ── Campos por nombre ───────────────────────────────────────────────
		$index = static function ( $fields ): array {
			$out = [];
			foreach ( is_array( $fields ) ? $fields : [] as $i => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$key = (string) ( $field['name'] ?? ( '#' . $i . ':' . ( $field['type'] ?? '' ) ) );
				$out[ $key ] = $field;
			}
			return $out;
		};
		$fa = $index( $a['fields_json'] ?? [] );
		$fb = $index( $b['fields_json'] ?? [] );

		$added   = array_diff_key( $fb, $fa );
		$removed = array_diff_key( $fa, $fb );

		// Heurística de renombre: campo removido + agregado con mismo type+label.
		foreach ( $removed as $old_name => $old_field ) {
			foreach ( $added as $new_name => $new_field ) {
				if ( ( $old_field['type'] ?? '' ) === ( $new_field['type'] ?? '' )
					&& ( $old_field['label'] ?? '' ) === ( $new_field['label'] ?? '' ) ) {
					$items[] = [
						'path' => 'form.fields_json.' . $old_name, 'kind' => 'renamed',
						'label' => 'Campo renombrado: "' . $old_name . '" → "' . $new_name . '"',
						'destructive' => true, 'before' => $old_name, 'after' => $new_name,
					];
					unset( $removed[ $old_name ], $added[ $new_name ] );
					break;
				}
			}
		}
		foreach ( $removed as $name => $field ) {
			$items[] = [ 'path' => 'form.fields_json.' . $name, 'kind' => 'removed', 'label' => 'Campo eliminado: "' . $name . '" (' . ( $field['type'] ?? '?' ) . ')', 'destructive' => true, 'before' => $field, 'after' => null ];
		}
		foreach ( $added as $name => $field ) {
			$items[] = [ 'path' => 'form.fields_json.' . $name, 'kind' => 'added', 'label' => 'Campo agregado: "' . $name . '" (' . ( $field['type'] ?? '?' ) . ')', 'destructive' => false, 'before' => null, 'after' => $field ];
		}
		foreach ( array_intersect_key( $fa, $fb ) as $name => $field_a ) {
			$field_b = $fb[ $name ];
			if ( wp_json_encode( $field_a ) !== wp_json_encode( $field_b ) ) {
				$type_changed = ( $field_a['type'] ?? '' ) !== ( $field_b['type'] ?? '' );
				$items[] = [
					'path' => 'form.fields_json.' . $name, 'kind' => 'changed',
					'label' => 'Campo modificado: "' . $name . '"' . ( $type_changed ? ' (cambio de tipo: ' . ( $field_a['type'] ?? '?' ) . ' → ' . ( $field_b['type'] ?? '?' ) . ')' : '' ),
					'destructive' => $type_changed, 'before' => $field_a, 'after' => $field_b,
				];
			}
		}

		// Encuestas: las respuestas históricas se alinean por question_index —
		// reordenar preguntas es destructivo para resultados/analytics aunque
		// ningún campo cambie individualmente.
		if ( ( $a['form_type'] ?? '' ) === 'survey' || ( $b['form_type'] ?? '' ) === 'survey' ) {
			$common  = array_keys( array_intersect_key( $fa, $fb ) );
			$order_a = array_values( array_intersect( array_keys( $fa ), $common ) );
			$order_b = array_values( array_intersect( array_keys( $fb ), $common ) );
			if ( $order_a !== $order_b ) {
				$items[] = [
					'path'        => 'form.fields_json',
					'kind'        => 'changed',
					'label'       => 'Orden de preguntas modificado — las respuestas históricas se alinean por índice de pregunta',
					'destructive' => true,
					'before'      => $order_a,
					'after'       => $order_b,
				];
			}
		}

		// ── Resto de propiedades ────────────────────────────────────────────
		$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
		foreach ( $keys as $key ) {
			if ( $key === 'fields_json' ) {
				continue;
			}
			$va = $a[ $key ] ?? null;
			$vb = $b[ $key ] ?? null;
			if ( wp_json_encode( $va ) === wp_json_encode( $vb ) ) {
				continue;
			}
			$label = $labels[ $key ] ?? $key;
			$removed_now = ( $va !== null && $va !== '' && ( $vb === null || $vb === '' ) );
			$items[] = [
				'path'        => 'form.' . $key,
				'kind'        => $va === null ? 'added' : ( $removed_now ? 'removed' : 'changed' ),
				'label'       => $label . ( $removed_now ? ' eliminado' : ' modificado' ),
				'destructive' => in_array( $key, $destructive_change, true ),
				'before'      => $va,
				'after'       => $vb,
			];
		}

		return $items;
	}

	/**
	 * APLICAR — la única ruta de escritura del ciclo JSON.
	 *
	 * Pipeline (SAVE SAFETY): lint → target → dry-run → snapshot → persist →
	 * rehydrate → revalidate → commit | rollback automático.
	 *
	 * @param array $envelope documento completo (envelope).
	 * @param array $opts {
	 *     mode: create|update|duplicate (default update),
	 *     form_id: int (update),
	 *     strategy: merge|replace (update, default merge),
	 *     allow_slug_change: bool (default false — nunca silencioso),
	 *     source: string (auditoría),
	 * }
	 * @return array|WP_Error {form_id, mode, strategy, warnings, changes}
	 */
	public static function apply( array $envelope, array $opts = [] ) {
		global $wpdb;

		$mode              = in_array( (string) ( $opts['mode'] ?? 'update' ), [ 'create', 'update', 'duplicate' ], true ) ? (string) ( $opts['mode'] ?? 'update' ) : 'update';
		$strategy          = ( (string) ( $opts['strategy'] ?? 'merge' ) ) === 'replace' ? 'replace' : 'merge';
		$form_id           = (int) ( $opts['form_id'] ?? 0 );
		$allow_slug_change = ! empty( $opts['allow_slug_change'] );
		$source            = (string) ( $opts['source'] ?? 'json_editor' );
		$table             = $wpdb->prefix . 'estandar_forms';

		$current = null;
		if ( $mode === 'update' ) {
			if ( $form_id <= 0 ) {
				return new WP_Error( 'json_missing_form_id', 'Actualizar requiere form_id.' );
			}
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );
			if ( ! $current ) {
				return new WP_Error( 'json_form_not_found', 'El formulario ' . $form_id . ' no existe.' );
			}
			$target = self::build_update_target( $envelope, $current, $strategy, $allow_slug_change );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$envelope = $target;
		}

		// ── Capas 2-3 sobre el envelope EFECTIVO ────────────────────────────
		// En merge se valida el documento resultante, no el parcial: un merge
		// legítimo puede traer solo las claves que cambia.
		$lint = self::lint( $envelope );
		if ( ! empty( $lint['errors'] ) ) {
			return new WP_Error( 'json_lint_failed', 'El documento no pasa la validación.', [ 'errors' => $lint['errors'], 'warnings' => $lint['warnings'] ] );
		}

		// ── Hidratar + sentinels + Capa 4 (sin persistir) ───────────────────
		$row = self::envelope_to_row( is_array( $envelope['form'] ?? null ) ? $envelope['form'] : [] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		self::resolve_sentinels( $row, $current );

		$dry_envelope = $envelope;
		$dry_envelope['form'] = array_merge( $dry_envelope['form'], [ 'webhook_url' => (string) ( $row['webhook_url'] ?? '' ) ] );
		$dry = self::dry_run( $dry_envelope );
		if ( ! empty( $dry ) ) {
			return new WP_Error( 'json_runtime_validation_failed', 'El formulario no es ejecutable — no se guardó.', [ 'errors' => $dry, 'warnings' => $lint['warnings'] ] );
		}

		// ── CREATE / DUPLICATE — nunca sobrescriben ─────────────────────────
		if ( $mode === 'create' || $mode === 'duplicate' ) {
			if ( $mode === 'duplicate' ) {
				$row['name'] = $row['name'] . ' (copia)';
			}
			$row['slug'] = self::unique_slug( (string) $row['slug'] );

			$inserted = $wpdb->insert( $table, $row, self::row_format( $row ) );
			if ( $inserted === false ) {
				return new WP_Error( 'json_insert_failed', 'No fue posible crear el formulario.' );
			}
			$new_id = (int) $wpdb->insert_id;

			self::log_event( $new_id, 'form_json_applied', 'Formulario creado vía ciclo JSON (' . $mode . ').', [
				'mode' => $mode, 'source' => $source, 'slug' => $row['slug'],
			] );
			if ( class_exists( 'Estandar_Forms_Shortcode' ) ) {
				Estandar_Forms_Shortcode::clear_form_cache( [ 'id' => $new_id, 'slug' => $row['slug'] ] );
			}
			return [ 'form_id' => $new_id, 'mode' => $mode, 'strategy' => null, 'warnings' => $lint['warnings'], 'changes' => [] ];
		}

		// ── UPDATE — snapshot → persist → rehydrate → commit/rollback ──────
		$snapshot_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ), ARRAY_A );
		$current_env  = self::build_envelope( self::row_to_portable( $current ) );
		$changes      = self::semantic_diff( $current_env, $envelope );

		self::log_event( $form_id, 'form_snapshot', 'Snapshot previo a aplicar JSON.', [
			'source' => $source, 'strategy' => $strategy, 'envelope' => $current_env,
		] );

		$updated = $wpdb->update( $table, $row, [ 'id' => $form_id ], self::row_format( $row ), [ '%d' ] );
		if ( $updated === false ) {
			return new WP_Error( 'json_update_failed', 'Error al persistir: ' . $wpdb->last_error );
		}

		// Rehidratar y revalidar con el serializador canónico. Cualquier fallo
		// aquí = rollback automático desde el snapshot — sin estados parciales.
		$reread  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );
		$recheck = $reread ? self::lint( self::build_envelope( self::row_to_portable( $reread ) ) ) : [ 'errors' => [ [ 'path' => 'form', 'code' => 'rehydrate_failed', 'message' => 'No fue posible releer el formulario.' ] ] ];
		$redry   = $reread ? self::dry_run( self::build_envelope( self::row_to_portable( $reread ) ) ) : [];
		// El sentinel del webhook redactado es válido en reposo — no re-flagearlo.
		$recheck_errors = array_values( array_filter(
			array_merge( $recheck['errors'] ?? [], $redry ),
			static function ( $issue ) {
				return ( $issue['code'] ?? '' ) !== 'webhook_enabled_without_url';
			}
		) );

		if ( ! empty( $recheck_errors ) ) {
			if ( is_array( $snapshot_row ) ) {
				$restore = $snapshot_row;
				unset( $restore['id'] );
				$wpdb->update( $table, $restore, [ 'id' => $form_id ] );
			}
			self::log_event( $form_id, 'form_json_rollback', 'Rollback automático: la revalidación post-guardado falló.', [
				'source' => $source, 'errors' => $recheck_errors,
			] );
			return new WP_Error( 'json_rolled_back', 'La revalidación posterior al guardado falló — se restauró el estado anterior.', [ 'errors' => $recheck_errors ] );
		}

		self::log_event( $form_id, 'form_json_applied', 'JSON aplicado (' . $strategy . ') — ' . count( $changes ) . ' cambio(s).', [
			'source' => $source, 'strategy' => $strategy,
			'changes' => array_map( static function ( $c ) {
				return [ 'path' => $c['path'], 'kind' => $c['kind'], 'label' => $c['label'], 'destructive' => ! empty( $c['destructive'] ) ];
			}, $changes ),
		] );

		if ( class_exists( 'Estandar_Forms_Shortcode' ) ) {
			if ( $current ) {
				Estandar_Forms_Shortcode::clear_form_cache( $current );
			}
			Estandar_Forms_Shortcode::clear_form_cache( [ 'id' => $form_id, 'slug' => (string) $row['slug'] ] );
		}

		return [ 'form_id' => $form_id, 'mode' => 'update', 'strategy' => $strategy, 'warnings' => $lint['warnings'], 'changes' => $changes ];
	}

	/**
	 * Construye el envelope efectivo de un update según la estrategia.
	 *
	 * Merge (default): solo las claves presentes en el documento reemplazan a
	 * las actuales; todo lo demás queda intacto. Replace: la definición lógica
	 * completa proviene del documento, protegiendo runtime inmutable (id,
	 * created_at, entries) y slug (nunca cambia sin confirmación explícita).
	 *
	 * @return array|WP_Error envelope efectivo.
	 */
	private static function build_update_target( array $envelope, object $current, string $strategy, bool $allow_slug_change ) {
		$current_env = self::build_envelope( self::row_to_portable( $current ) );
		$incoming    = is_array( $envelope['form'] ?? null ) ? $envelope['form'] : [];

		if ( $strategy === 'merge' ) {
			$merged = $current_env['form'];
			foreach ( $incoming as $key => $value ) {
				if ( $key === 'readonly' ) {
					continue;
				}
				$merged[ $key ] = $value;
			}
			$envelope['form'] = $merged;
		} else {
			unset( $incoming['readonly'] );
			// Replace protege secretos: la AUSENCIA de webhook_url no lo borra
			// (preservar vía sentinel); borrarlo exige '' explícito en el doc.
			if ( ! array_key_exists( 'webhook_url', $incoming ) && (string) ( $current->webhook_url ?? '' ) !== '' ) {
				$incoming['webhook_url'] = self::SENTINEL_REDACTED;
			}
			$envelope['form'] = $incoming;
		}

		// Slug: preservado por defecto; cambiarlo exige confirmación explícita
		// (los embeds por slug dependen de él). Nunca se regenera en silencio.
		$current_slug = (string) $current->slug;
		$doc_slug     = sanitize_title( (string) ( $envelope['form']['slug'] ?? '' ) );
		if ( $doc_slug === '' ) {
			$envelope['form']['slug'] = $current_slug;
		} elseif ( $doc_slug !== $current_slug && ! $allow_slug_change ) {
			return new WP_Error(
				'json_slug_change_blocked',
				'El documento cambia el slug ("' . $current_slug . '" → "' . $doc_slug . '"). Los shortcodes por slug dejarían de funcionar. Confirma el cambio explícitamente (allow_slug_change).'
			);
		}

		return $envelope;
	}

	/**
	 * Resuelve sentinels de secretos: update → restaurar el valor configurado
	 * actual; create → vacío (un secreto redactado jamás borra ni instala).
	 */
	private static function resolve_sentinels( array &$row, $current ): void {
		if ( ( $row['webhook_url'] ?? '' ) === self::SENTINEL_REDACTED ) {
			$row['webhook_url'] = $current ? (string) ( $current->webhook_url ?? '' ) : '';
		}

		foreach ( self::JSON_FIELDS as $field ) {
			$encoded = $row[ $field ] ?? null;
			if ( ! is_string( $encoded ) || strpos( $encoded, self::SENTINEL_REDACTED ) === false ) {
				continue;
			}
			$incoming = json_decode( $encoded, true );
			if ( ! is_array( $incoming ) ) {
				continue;
			}
			$reference = [];
			if ( $current && is_string( $current->{$field} ?? null ) && (string) $current->{$field} !== '' ) {
				$decoded_ref = json_decode( (string) $current->{$field}, true );
				$reference   = is_array( $decoded_ref ) ? $decoded_ref : [];
			}
			$row[ $field ] = wp_json_encode( self::restore_redacted( $incoming, $reference ) );
		}
	}

	/** Reemplaza valores de claves secret-like por el sentinel (export). */
	private static function redact_secrets( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact_secrets( $value );
			} elseif ( is_string( $value ) && $value !== '' && is_string( $key ) && preg_match( self::SECRET_KEY_PATTERN, $key ) ) {
				$data[ $key ] = self::SENTINEL_REDACTED;
			}
		}
		return $data;
	}

	/** Sustituye sentinels por el valor actual en la misma ruta (import). */
	private static function restore_redacted( array $incoming, array $reference ): array {
		foreach ( $incoming as $key => $value ) {
			if ( is_array( $value ) ) {
				$ref = is_array( $reference[ $key ] ?? null ) ? $reference[ $key ] : [];
				$incoming[ $key ] = self::restore_redacted( $value, $ref );
			} elseif ( $value === self::SENTINEL_REDACTED ) {
				$ref_value = $reference[ $key ] ?? '';
				$incoming[ $key ] = is_scalar( $ref_value ) ? $ref_value : '';
			}
		}
		return $incoming;
	}

	/** ksort recursivo — forma canónica independiente del orden de claves. */
	private static function ksort_recursive( array &$data ): void {
		ksort( $data );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) && $value !== array_values( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
		unset( $value );
	}

	/* ══════════════════════════════════════════════
	   HISTORIAL — fundación de revisiones (logs)
	══════════════════════════════════════════════ */

	/**
	 * Historial del ciclo JSON de un formulario (snapshots + aplicaciones +
	 * rollbacks) leyendo estandar_form_logs. Fundación para revisiones futuras.
	 *
	 * @return array<int,array>
	 */
	public static function history( int $form_id, int $limit = 20 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, event_type, level, message, context_json, created_at
			 FROM {$wpdb->prefix}estandar_form_logs
			 WHERE form_id = %d AND event_type IN ('form_snapshot','form_json_applied','form_json_rollback')
			 ORDER BY id DESC LIMIT %d",
			$form_id,
			max( 1, $limit )
		), ARRAY_A );

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$context = json_decode( (string) ( $row['context_json'] ?? '' ), true );
			$out[] = [
				'id'         => (int) $row['id'],
				'event_type' => (string) $row['event_type'],
				'message'    => (string) $row['message'],
				'created_at' => (string) $row['created_at'],
				'context'    => is_array( $context ) ? $context : [],
			];
		}
		return $out;
	}

	/** Registrar evento del ciclo JSON en estandar_form_logs. */
	private static function log_event( int $form_id, string $event_type, string $message, array $context = [] ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'estandar_form_logs',
			[
				'form_id'      => $form_id,
				'event_type'   => $event_type,
				'level'        => 'info',
				'message'      => $message,
				'context_json' => wp_json_encode( $context ),
				'created_at'   => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/* ══════════════════════════════════════════════
	   AJAX — editor JSON (todas las rutas pasan por
	   el serializador/importador canónico)
	══════════════════════════════════════════════ */

	/** Autorización común de los endpoints del editor. */
	private static function ajax_guard(): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'No autorizado.' ], 403 );
		}
		check_ajax_referer( 'estandar_forms_json', 'nonce' );
		return isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
	}

	/** Documento actual (para Restaurar / abrir editor). */
	public static function handle_ajax_export(): void {
		$form_id  = self::ajax_guard();
		$envelope = self::export( $form_id );
		if ( is_wp_error( $envelope ) ) {
			wp_send_json_error( [ 'message' => $envelope->get_error_message() ] );
		}
		wp_send_json_success( [ 'json' => self::to_json( $envelope ) ] );
	}

	/** Validación por capas (1: sintaxis · 2: schema · 3: lógica · 4: runtime). */
	public static function handle_ajax_validate(): void {
		self::ajax_guard();
		$raw = (string) wp_unslash( $_POST['doc'] ?? '' );

		$envelope = self::from_json( $raw );
		if ( is_wp_error( $envelope ) ) {
			wp_send_json_success( [ 'valid' => false, 'errors' => [ [ 'path' => '$', 'code' => 'invalid_json', 'message' => $envelope->get_error_message() ] ], 'warnings' => [] ] );
		}

		$lint   = self::lint( $envelope );
		$issues = $lint['errors'];
		if ( empty( $issues ) ) {
			$issues = array_merge( $issues, self::dry_run( $envelope ) );
		}
		wp_send_json_success( [ 'valid' => empty( $issues ), 'errors' => $issues, 'warnings' => $lint['warnings'] ] );
	}

	/** Diff semántico contra el formulario persistido (sin escribir nada). */
	public static function handle_ajax_diff(): void {
		global $wpdb;
		$form_id = self::ajax_guard();
		$raw     = (string) wp_unslash( $_POST['doc'] ?? '' );
		$strategy = ( (string) ( $_POST['strategy'] ?? 'merge' ) ) === 'replace' ? 'replace' : 'merge';

		$envelope = self::from_json( $raw );
		if ( is_wp_error( $envelope ) ) {
			wp_send_json_error( [ 'message' => $envelope->get_error_message() ] );
		}
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}estandar_forms WHERE id = %d", $form_id ) );
		if ( ! $current ) {
			wp_send_json_error( [ 'message' => 'Formulario no encontrado.' ] );
		}
		$target = self::build_update_target( $envelope, $current, $strategy, true );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( [ 'message' => $target->get_error_message() ] );
		}
		$current_env = self::build_envelope( self::row_to_portable( $current ) );
		wp_send_json_success( [ 'changes' => self::semantic_diff( $current_env, $target ), 'strategy' => $strategy ] );
	}

	/** Aplicar el documento vía la ruta canónica (nunca escribe directo a la DB). */
	public static function handle_ajax_apply(): void {
		$form_id = self::ajax_guard();
		$raw     = (string) wp_unslash( $_POST['doc'] ?? '' );

		$envelope = self::from_json( $raw );
		if ( is_wp_error( $envelope ) ) {
			wp_send_json_error( [ 'message' => $envelope->get_error_message(), 'errors' => [ [ 'path' => '$', 'code' => 'invalid_json', 'message' => $envelope->get_error_message() ] ] ] );
		}

		$result = self::apply( $envelope, [
			'mode'              => 'update',
			'form_id'           => $form_id,
			'strategy'          => ( (string) ( $_POST['strategy'] ?? 'merge' ) ) === 'replace' ? 'replace' : 'merge',
			'allow_slug_change' => ! empty( $_POST['allow_slug_change'] ),
			'source'            => 'json_editor',
		] );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error( [
				'message'  => $result->get_error_message(),
				'code'     => $result->get_error_code(),
				'errors'   => is_array( $data ) ? ( $data['errors'] ?? [] ) : [],
				'warnings' => is_array( $data ) ? ( $data['warnings'] ?? [] ) : [],
			] );
		}
		wp_send_json_success( $result );
	}

	/** Historial del ciclo JSON (pestaña Historial). */
	public static function handle_ajax_history(): void {
		$form_id = self::ajax_guard();
		wp_send_json_success( [ 'history' => self::history( $form_id ) ] );
	}
}
