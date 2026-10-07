<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2-managed forms (create / update / delete / list). Owns its own table
 * estandar_capture_forms; NEVER writes to the legacy wp_estandar_forms.
 * Types are extensible: lead, incident, survey (+ filter). config_json holds
 * the type-specific configuration (e.g. survey_config).
 */
class Forms
{
	const TABLE   = 'estandar_capture_forms';
	const OPTION  = 'estandar_suite_capture_forms_schema_version';
	const VERSION = '1.0.0';

	/** Extensible form types. */
	public static function types(): array
	{
		$types = array( 'lead' => 'Lead', 'incident' => 'Incidente', 'survey' => 'Encuesta' );
		$filtered = apply_filters( 'estandar_suite_capture_form_types', $types );
		return is_array( $filtered ) && $filtered ? $filtered : $types;
	}

	/** Closed list of business contexts (max 5 + "otros"). */
	public static function contexts(): array
	{
		$contexts = array(
			'generic'            => 'General',
			'inmobiliaria'       => 'Inmobiliaria',
			'accounting_services' => 'Servicios contables',
			'legal'              => 'Legal',
			'retail'             => 'Comercio / Retail',
			'otros'              => 'Otros',
		);
		$filtered = apply_filters( 'estandar_suite_capture_form_contexts', $contexts );
		return is_array( $filtered ) && $filtered ? $filtered : $contexts;
	}

	/** Human label for a context key (falls back to "Otros"). */
	public static function context_label( string $key ): string
	{
		$ctx = self::contexts();
		return isset( $ctx[ $key ] ) ? $ctx[ $key ] : ( $ctx['otros'] ?? $key );
	}

	const CAP_OPTION = 'estandar_suite_capture_product_caps';

	/**
	 * Capability catalog per product type. All products share the same engine
	 * (fields/config); capabilities toggle which features each type exposes.
	 * Enabled by default from the catalog; per-type overrides in CAP_OPTION.
	 */
	public static function product_capabilities( string $type ): array
	{
		$common = array(
			'fields_builder' => array( 'label' => 'Constructor de campos', 'desc' => 'Editor de campos/preguntas del producto.', 'default' => 1 ),
			'submit'         => array( 'label' => 'Botón de envío', 'desc' => 'Botón para enviar/terminar.', 'default' => 1 ),
			'webhook'        => array( 'label' => 'Webhook de salida', 'desc' => 'Enviar la captura a un destino externo.', 'default' => 0 ),
			'notify_email'   => array( 'label' => 'Notificación por email', 'desc' => 'Avisar por correo al completar.', 'default' => 0 ),
			'recaptcha'      => array( 'label' => 'reCAPTCHA', 'desc' => 'Protección anti-spam.', 'default' => 0 ),
		);
		$survey_extra = array(
			'step_by_step'   => array( 'label' => 'Paso a paso (Typeform)', 'desc' => 'Muestra las preguntas una a una con animaciones.', 'default' => 1 ),
			'hero'           => array( 'label' => 'Pantalla de inicio (hero)', 'desc' => 'Título, subtítulo y CTA inicial.', 'default' => 1 ),
			'identification' => array( 'label' => 'Modo de identificación', 'desc' => 'Anónimo u opcional con identidad.', 'default' => 1 ),
			'completion'     => array( 'label' => 'Pantalla de cierre', 'desc' => 'Mensaje de agradecimiento al terminar.', 'default' => 1 ),
		);
		$postventa_extra = array(
			'incident_fields' => array( 'label' => 'Campos de incidente', 'desc' => 'Campos específicos de caso/incidente.', 'default' => 1 ),
			'attachments'     => array( 'label' => 'Adjuntos', 'desc' => 'Permite adjuntar archivos.', 'default' => 1 ),
			'follow_up'       => array( 'label' => 'Seguimiento', 'desc' => 'Seguimiento del caso de postventa.', 'default' => 1 ),
		);
		$base = $common;
		if ( $type === 'survey' ) {
			$base = array_merge( $base, $survey_extra );
		} elseif ( $type === 'incident' ) {
			$base = array_merge( $base, $postventa_extra );
		}
		$filtered = apply_filters( 'estandar_suite_capture_product_capabilities', $base, $type );
		return is_array( $filtered ) && $filtered ? $filtered : $base;
	}

	/** Enabled overrides per type (array( $type => array( cap => 0|1 ) )). */
	public static function caps_overrides(): array
	{
		$saved = get_option( self::CAP_OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	public static function is_capability_enabled( string $type, string $cap ): bool
	{
		$catalog = self::product_capabilities( $type );
		$default = isset( $catalog[ $cap ]['default'] ) ? (int) $catalog[ $cap ]['default'] : 0;
		$over    = self::caps_overrides();
		$value   = isset( $over[ $type ][ $cap ] ) ? (int) $over[ $type ][ $cap ] : $default;
		return $value === 1;
	}

	public static function set_capability( string $type, string $cap, bool $enabled ): void
	{
		$over  = self::caps_overrides();
		$over[ $type ][ $cap ] = $enabled ? 1 : 0;
		update_option( self::CAP_OPTION, $over, false );
	}

	/**
	 * Union capability catalog across catcher types, for the Settings matrix.
	 * Format: cap => array( 'label', 'desc', 'types' => array( type => true ) ).
	 */
	public static function capability_matrix(): array
	{
		$types = array( 'lead', 'survey', 'incident' );
		$union = array();
		foreach ( $types as $t ) {
			foreach ( self::product_capabilities( $t ) as $cap => $cfg ) {
				if ( ! isset( $union[ $cap ] ) ) {
					$union[ $cap ] = array( 'label' => $cfg['label'], 'desc' => $cfg['desc'], 'types' => array() );
				}
				$union[ $cap ]['types'][ $t ] = true;
			}
		}
		return $union;
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			form_type varchar(40) NOT NULL DEFAULT 'lead',
			form_context varchar(190) NOT NULL DEFAULT 'generic',
			config_json longtext,
			status varchar(40) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY form_type (form_type)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** Sanitize + default a form payload (name required). */
	public static function sanitize( array $raw ): array
	{
		$types  = self::types();
		$type   = sanitize_key( (string) ( $raw['form_type'] ?? 'lead' ) );
		$type   = isset( $types[ $type ] ) ? $type : 'lead';
		$name   = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$slug   = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $name );
		}
		$context = sanitize_key( (string) ( $raw['form_context'] ?? 'generic' ) );
		if ( ! isset( self::contexts()[ $context ] ) ) {
			$context = 'otros';
		}
		$status  = sanitize_key( (string) ( $raw['status'] ?? 'active' ) );
		$status  = in_array( $status, array( 'active', 'draft', 'archived' ), true ) ? $status : 'active';
		$config  = trim( (string) ( $raw['config_json'] ?? '' ) );
		if ( $config === '' ) {
			$config = '{}';
		}
		json_decode( $config );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$config = '{}';
		}
		return array(
			'name'         => $name,
			'slug'         => $slug,
			'form_type'    => $type,
			'form_context' => $context,
			'config_json'  => $config,
			'status'       => $status,
		);
	}

	public static function list( string $type = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		$sql = "SELECT * FROM {$table}";
		if ( $type !== '' && isset( self::types()[ $type ] ) ) {
			$sql .= $wpdb->prepare( ' WHERE form_type = %s', $type );
		}
		$sql .= ' ORDER BY id DESC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $raw ): int
	{
		global $wpdb;
		$data = self::sanitize( $raw );
		if ( $data['name'] === '' ) {
			return 0;
		}
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = $data['created_at'];
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Detecta pérdida de contenido de config (protección permanente).
	 *
	 * @param array|null $current Config actual.
	 * @param array|null $new     Config que se intenta escribir.
	 * @return string '' si es seguro; motivo si la escritura borraría contenido
	 *                sin haberlo solicitado explícitamente.
	 */
	public static function config_loss_reason( $current, $new ): string
	{
		if ( ! is_array( $new ) ) {
			return 'config_invalida';
		}
		$cur_content = is_array( $current ) && ( ! empty( $current['sections'] ) || ! empty( $current['fields_json'] ) );
		$new_content = ! empty( $new['sections'] ) || ! empty( $new['fields_json'] );
		if ( $cur_content && ! $new_content ) {
			return 'perdida_de_contenido_no_solicitada';
		}
		return '';
	}

	/**
	 * Actualiza un form. $allow_reset=false (default): NUNCA permite que una
	 * escritura degrade config_json con contenido a un documento vacío/{} —
	 * protege cualquier editor/subtab futura. Solo acciones explícitas de
	 * "Reset Form / Vaciar Formulario" llaman con $allow_reset=true.
	 */
	public static function update( int $id, array $raw, bool $allow_reset = false ): bool
	{
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return false;
		}
		$data = self::sanitize( $raw );
		$reason = self::config_loss_reason(
			json_decode( (string) $current['config_json'], true ),
			json_decode( (string) $data['config_json'], true )
		);
		if ( $reason !== '' && ! $allow_reset ) {
			if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
				\Estandar\Core\Telemetry::emit( 'error', 'SUITE_FORMS_WRITE_BLOCKED', 'Escritura bloqueada: ' . $reason . ' (form ' . $id . ').', array( 'source_plugin' => 'estandar-capture', 'form_id' => $id, 'reason' => $reason ) );
			}
			return false;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . self::TABLE, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		return true;
	}

	public static function delete( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/** Move a form to the papelera (soft delete). */
	public static function trash( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'status' => 'trashed', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		return true;
	}

	/** Restore a trashed form back to active. */
	public static function restore( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'status' => 'active', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		return true;
	}

	/** List trashed forms, optionally by product type. */
	public static function list_trashed( string $type = '' ): array
	{
		global $wpdb;
		$sql = 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE status = %s';
		$args = array( 'trashed' );
		if ( $type !== '' ) {
			$sql .= ' AND form_type = %s';
			$args[] = $type;
		}
		$sql .= ' ORDER BY updated_at DESC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Duplicate a form: new row with "(copia)", unique slug, status draft. */
	public static function duplicate( int $id ): int
	{
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f ) {
			return 0;
		}
		$name = $f['name'] . ' (copia)';
		$base = sanitize_title( $name );
		$slug = $base;
		$n = 2;
		while ( self::slug_exists( $slug ) ) {
			$slug = $base . '-' . $n;
			$n++;
		}
		$data = array(
			'name'         => $name,
			'slug'         => $slug,
			'form_type'    => $f['form_type'],
			'form_context' => $f['form_context'],
			'config_json'  => $f['config_json'],
			'status'       => 'draft',
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	private static function slug_exists( string $slug ): bool
	{
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . self::TABLE . ' WHERE slug = %s LIMIT 1', $slug ) );
	}

	/**
	 * Adopt legacy (v1) forms/surveys into the v2 table as native entries.
	 * Preserves the legacy id so historical entries (estandar_form_entries) and
	 * survey responses (estandar_survey_responses) keep linking by form_id.
	 * Idempotent by slug. Never touches the legacy table.
	 *
	 * Compat: mantiene el comportamiento original (migra TODOS los tipos). La
	 * migración controlada del micro-sprint (solo Forms) vive en migrate_legacy().
	 */
	public static function adopt_legacy( bool $dry_run = false ): array
	{
		return self::migrate_legacy( $dry_run, 0, false );
	}

	/** Marcador interno de procedencia para migración controlada. */
	const MIG_MARKER = '_migrated_legacy';

	/**
	 * Mapa form_type legacy → tipo v2. Solo Forms en este sprint
	 * (general/lead_capture → lead). Surveys/Postventa quedan fuera.
	 */
	public static function legacy_form_type_map( string $legacy_type ): string
	{
		$map = array(
			'general'      => 'lead',
			'lead_capture' => 'lead',
			'lead'         => 'lead',
		);
		return $map[ strtolower( trim( (string) $legacy_type ) ) ] ?? '';
	}

	/** ¿Ya existe un formulario nativo con este id legacy (migrado/adoptado)? */
	public static function is_migrated( int $legacy_id ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $legacy_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d LIMIT 1', $legacy_id
		) );
	}

	/** Legacy row → payload nativo v2 (puro). Incluye marcador de procedencia. */
	public static function legacy_to_native( array $lf, string $type ): array
	{
		$slug = sanitize_title( (string) ( $lf['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( (string) $lf['name'] );
		}
		$config = array();
		foreach ( array(
			'fields_json', 'survey_config', 'incidents_config', 'actions_json',
			'notify_emails', 'webhook_url', 'success_message', 'submit_label',
			'require_recaptcha',
			'after_submit_text', 'mail_subject', 'mail_body', 'mail_sender_name',
			'mail_reply_to_field', 'send_email', 'send_webhook', 'webhook_payload_mode',
			'form_country', 'store_entries', 'visual_theme', 'context_schema',
			'experience_config_json', 'request_type',
		) as $k ) {
			if ( isset( $lf[ $k ] ) && $lf[ $k ] !== '' && $lf[ $k ] !== 'null' ) {
				$config[ $k ] = $lf[ $k ];
			}
		}
		$config[ self::MIG_MARKER ] = array(
			'id'          => (int) $lf['id'],
			'legacy_type' => (string) ( $lf['form_type'] ?? '' ),
			'migrated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		return array(
			'id'           => (int) $lf['id'],
			'name'         => sanitize_text_field( (string) $lf['name'] ),
			'slug'         => $slug,
			'form_type'    => $type !== '' ? $type : 'lead',
			'form_context' => sanitize_key( (string) ( $lf['form_context'] ?? 'generic' ) ) ?: 'generic',
			'config_json'  => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'status'       => 'active',
			'created_at'   => (string) ( $lf['created_at'] ?? current_time( 'mysql' ) ),
			'updated_at'   => (string) ( $lf['created_at'] ?? current_time( 'mysql' ) ),
		);
	}

	/**
	 * Migración controlada de formularios legacy → nativos v2 (Forms).
	 *  - dry_run por defecto (0 escrituras); --commit aplica.
	 *  - form_id > 0 selecciona un formulario; 0 migra todos los elegibles.
	 *  - only_forms=true (default) filtra solo Forms (general/lead_capture).
	 *  - Idempotente por id legacy; preserva el id (entries históricas siguen
	 *    linkeando por form_id). Nunca toca la tabla legacy.
	 *
	 * @return array { migrated, skipped, existing, not_forms, total, dry_run, forms }
	 */
	public static function migrate_legacy( bool $dry_run = true, int $form_id = 0, bool $only_forms = true ): array
	{
		global $wpdb;
		$legacy = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id ASC' );
		$stats  = array(
			'migrated'  => 0,
			'skipped'   => 0,
			'existing'  => 0,
			'not_forms' => 0,
			'total'     => count( $legacy ),
			'dry_run'   => $dry_run,
			'forms'     => array(),
		);
		foreach ( $legacy as $lf ) {
			$lfid = (int) $lf['id'];
			if ( $form_id > 0 && $lfid !== $form_id ) {
				continue;
			}
			$type = self::legacy_form_type_map( (string) ( $lf['form_type'] ?? '' ) );
			if ( ! $only_forms ) {
				// Compat adopt_legacy: conserva tipos v2 válidos (survey, etc.).
				$type = (string) ( $lf['form_type'] ?? 'lead' );
				if ( ! isset( self::types()[ $type ] ) ) {
					$type = 'lead';
				}
			}
			if ( $only_forms && $type === '' ) {
				$stats['not_forms']++;
				$stats['skipped']++;
				continue;
			}
			if ( self::is_migrated( $lfid ) ) {
				$stats['existing']++;
				continue;
			}
			$native = self::legacy_to_native( $lf, $type );
			$stats['forms'][] = array(
				'id'     => $lfid,
				'name'   => $native['name'],
				'type'   => $native['form_type'],
				'parity' => self::parity_diff( $lf, $native ),
			);
			if ( ! $dry_run && isset( $wpdb ) ) {
				$wpdb->insert( $wpdb->prefix . self::TABLE, $native, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
			}
			$stats['migrated']++;
		}
		if ( ! $dry_run && isset( $wpdb ) && $stats['migrated'] > 0 ) {
			// Evitar colisión de IDs: los formularios nativos nuevos deben usar
			// ids > max(id legacy) para no pisar entradas históricas que linkean
			// por form_id (= id legacy).
			$max_legacy = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'estandar_forms' );
			$wpdb->query( 'ALTER TABLE ' . $wpdb->prefix . self::TABLE . ' AUTO_INCREMENT = ' . ( $max_legacy + 1 ) );
		}
		return $stats;
	}

	/**
	 * Reporte de paridad legacy ↔ nativo (claves de config conservadas).
	 * Pure; no accede a DB.
	 */
	public static function parity_diff( array $lf, array $native ): array
	{
		$cfg = json_decode( (string) ( $native['config_json'] ?? '{}' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();
		unset( $cfg[ self::MIG_MARKER ] );
		$missing = array();
		$mapped  = array();
		foreach ( $cfg as $k => $v ) {
			if ( array_key_exists( $k, $lf ) ) {
				if ( (string) $lf[ $k ] === (string) $v ) {
					$mapped[ $k ] = true;
				} else {
					$missing[ $k ] = 'distinto (legacy=' . substr( (string) $lf[ $k ], 0, 40 ) . ' vs native=' . substr( (string) $v, 0, 40 ) . ')';
				}
			}
		}
		return array(
			'parity'  => empty( $missing ),
			'mapped'  => array_keys( $mapped ),
			'diffs'   => $missing,
		);
	}

	/**
	 * Localiza usos de un formulario legacy en el frontend: shortcodes
	 * `[estandar_form id/slug=…]` en posts (contenido) y postmeta. Read-only.
	 */
	public static function usages( int $legacy_id ): array
	{
		global $wpdb;
		$uses = array();
		if ( ! isset( $wpdb ) || $legacy_id <= 0 ) {
			return $uses;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT slug, name FROM ' . $wpdb->prefix . 'estandar_forms WHERE id = %d LIMIT 1', $legacy_id
		), ARRAY_A );
		$needles = array( 'id="' . $legacy_id . '"', "id='" . $legacy_id . "'" );
		if ( $row && ! empty( $row['slug'] ) ) {
			$needles[] = 'slug="' . $row['slug'] . '"';
			$needles[] = "slug='" . $row['slug'] . "'";
		}
		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status FROM {$wpdb->prefix}posts WHERE post_content LIKE '%[estandar_form%'",
			ARRAY_A
		);
		foreach ( (array) $posts as $p ) {
			$content = $wpdb->get_var( $wpdb->prepare(
				'SELECT post_content FROM ' . $wpdb->prefix . 'posts WHERE ID = %d', (int) $p['ID']
			) );
			foreach ( $needles as $n ) {
				if ( $content !== null && strpos( (string) $content, $n ) !== false ) {
					$uses[] = array(
						'id'    => (int) $p['ID'],
						'title' => (string) $p['post_title'],
						'type'  => (string) $p['post_type'],
						'status'=> (string) $p['post_status'],
					);
					break;
				}
			}
		}
		return $uses;
	}

	/**
	 * Submissions nativas v2 de un formulario (registro operativo).
	 * Filtro estricto por source='form' y source_form_id = id del form.
	 * Read-only sobre estandar_capture_submissions.
	 */
	public static function submissions( int $form_id, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . Ingestion::TABLE
			. " WHERE source = 'form' AND source_form_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
			(string) $form_id, $limit, $offset
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Entradas de un formulario por `source_form_id` (relación canónica, cualquier
	 * source: form/survey/incident). Usado por el editor (subtab Entradas).
	 */
	public static function submissions_for_form( int $form_id, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . Ingestion::TABLE
			. " WHERE source_form_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
			(string) $form_id, $limit, $offset
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Lead de pipeline (estandar_flow_pipeline) asociado a una capture v2. */
	public static function pipeline_for_capture( int $capture_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, stage, entity_uuid, updated_at FROM {$wpdb->prefix}estandar_flow_pipeline
			 WHERE entity_type = 'capture' AND entity_id = %d LIMIT 1", $capture_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Configuración de correo por Form (notificación + autorespuesta). */
	public static function mail_settings( array $config ): array
	{
		$ar = is_array( $config['autoresponse'] ?? null ) ? $config['autoresponse'] : array();
		return array(
			'notify' => array(
				'enabled'  => ! empty( $config['send_email'] ) && ! empty( $config['notify_emails'] ),
				'to'       => (string) ( $config['notify_emails'] ?? '' ),
				'subject'  => (string) ( $config['mail_subject'] ?? 'Nuevo envío: {{form_name}}' ),
				'body'     => (string) ( $config['mail_body'] ?? '' ),
				'sender'   => (string) ( $config['mail_sender_name'] ?? '' ),
				'reply_to' => (string) ( $config['mail_reply_to_field'] ?? '' ),
			),
			'autoresponse' => array(
				'enabled'     => ! empty( $ar['enabled'] ),
				'email_field' => (string) ( $ar['email_field'] ?? 'mail' ),
				'subject'     => (string) ( $ar['subject'] ?? 'Gracias por contactarnos' ),
				'body'        => (string) ( $ar['body'] ?? '' ),
				'sender'      => (string) ( $ar['sender'] ?? '' ),
			),
		);
	}

	/** Variables dinámicas de una submission para plantillas de correo. */
	public static function submission_vars( int $form_id, array $config, array $capture ): array
	{
		$fields = json_decode( (string) ( $config['fields_json'] ?? '[]' ), true );
		$posted = is_array( $capture['fields'] ?? null ) ? $capture['fields'] : array();
		$lines  = array();
		$name   = '';
		foreach ( (array) $fields as $f ) {
			$fn   = (string) ( $f['name'] ?? '' );
			$lab  = (string) ( $f['label'] ?? $fn );
			$val  = (string) ( $posted[ $fn ] ?? '' );
			if ( $val !== '' ) {
				$lines[] = $lab . ': ' . $val;
			}
		}
		return array(
			'form_name'     => (string) ( $config['name'] ?? '' ),
			'name'          => (string) ( $capture['name'] ?? '' ),
			'email'         => (string) ( $capture['email_normalized'] ?? '' ),
			'phone'         => (string) ( $capture['phone_normalized'] ?? '' ),
			'message'       => (string) ( $capture['message'] ?? '' ),
			'fields'        => implode( "\n", $lines ),
			'submission_id' => (string) ( $capture['id'] ?? '' ),
			'date'          => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** Notificación interna por Form (usa el motor único Core\Mail). */
	public static function notify_submission( int $form_id, array $config, array $capture ): array
	{
		$m = self::mail_settings( $config );
		if ( empty( $m['notify']['enabled'] ) ) {
			return array( 'ok' => false, 'kind' => 'notify', 'form_id' => $form_id, 'error' => 'disabled' );
		}
		$vars = self::submission_vars( $form_id, $config, $capture );
		$body = $m['notify']['body'] !== ''
			? $m['notify']['body']
			: 'Nuevo envío del formulario «{{form_name}}».' . "\n\n" . '{{fields}}';
		$r = \Estandar\Core\Mail::send( array(
			'to'        => $m['notify']['to'],
			'subject'   => $m['notify']['subject'],
			'html'      => nl2br( $body ),
			'alt'       => $body,
			'from_name' => $m['notify']['sender'],
			'reply_to'  => $m['notify']['reply_to'],
			'vars'      => $vars,
		) );
		return array_merge( array( 'kind' => 'notify', 'form_id' => $form_id ), $r );
	}

	/** Autorespuesta al remitente por Form (independiente de la notificación). */
	public static function autoresponse_submission( int $form_id, array $config, array $capture ): array
	{
		$m = self::mail_settings( $config );
		if ( empty( $m['autoresponse']['enabled'] ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'disabled' );
		}
		$field = $m['autoresponse']['email_field'];
		$to    = isset( $capture['fields'][ $field ] ) ? (string) $capture['fields'][ $field ] : (string) ( $capture['email_normalized'] ?? '' );
		if ( ! \Estandar\Core\Mail::validate_address( $to ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'email_receptor_invalido' );
		}
		$vars = self::submission_vars( $form_id, $config, $capture );
		$body = $m['autoresponse']['body'] !== ''
			? $m['autoresponse']['body']
			: 'Hola {{name}}, recibimos tu mensaje. Te responderemos a la brevedad.';
		$r = \Estandar\Core\Mail::send( array(
			'to'        => $to,
			'subject'   => $m['autoresponse']['subject'],
			'html'      => nl2br( $body ),
			'alt'       => $body,
			'from_name' => $m['autoresponse']['sender'],
			'vars'      => $vars,
		) );
		return array_merge( array( 'kind' => 'autoresponse', 'form_id' => $form_id ), $r );
	}

	/**
	 * Rollback de migración: elimina formularios nativos migrados (marcador) y
	 * los adoptados previamente (id presente en estandar_forms). Opcionalmente
	 * scoped a un id. Nunca toca la tabla legacy.
	 */
	public static function rollback_migration( int $form_id = 0 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$table = $wpdb->prefix . self::TABLE;
		$legacy_table = $wpdb->prefix . 'estandar_forms';
		$sql = "DELETE FROM {$table} WHERE config_json LIKE '%\"" . self::MIG_MARKER . "\"%' OR id IN (SELECT id FROM {$legacy_table})";
		if ( $form_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND id = %d', $form_id );
		}
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Campos renderizables de un config (fuente canónica única).
	 *
	 * Normaliza `sections` (nativo v2) o `fields_json` (legacy plano) a una lista
	 * plana de campos. Usado por el renderer y la ingestión; independiente del id.
	 */
	public static function renderable_fields( array $config ): array
	{
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) && $config['sections'] ) {
			$fields = array();
			foreach ( $config['sections'] as $sec ) {
				if ( ! is_array( $sec ) || empty( $sec['fields'] ) || ! is_array( $sec['fields'] ) ) {
					continue;
				}
				foreach ( $sec['fields'] as $f ) {
					if ( is_array( $f ) ) {
						$fields[] = $f;
					}
				}
			}
			return $fields;
		}
		$flat = isset( $config['fields_json'] ) ? $config['fields_json'] : array();
		if ( is_string( $flat ) ) {
			$flat = json_decode( $flat, true );
		}
		return is_array( $flat ) ? $flat : array();
	}

	/**
	 * Fusión determinística y no destructiva de config_json.
	 *
	 * Protege contra guardados parciales/vacíos: NUNCA reemplaza secciones
	 * ausentes del POST por arrays vacíos ni deja que un post vacío borre un
	 * documento existente. Las claves del post prevalecen; las claves del actual
	 * ausentes en el post se conservan.
	 *
	 * @return string JSON resultante (o el actual si el post es inválido/vacío).
	 */
	public static function merge_config_json( string $current, string $posted ): string
	{
		$cur = json_decode( $current, true );
		$cur = is_array( $cur ) ? $cur : array();
		$pos = json_decode( $posted, true );
		if ( ! is_array( $pos ) || ! $pos ) {
			// Post vacío/inválido: conservar el documento actual.
			return wp_json_encode( $cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		foreach ( $pos as $key => $value ) {
			if ( $key === 'sections' ) {
				if ( is_array( $value ) && ! $value && isset( $cur['sections'] ) && ! empty( $cur['sections'] ) ) {
					// Post con sections vacías sobre un documento con contenido:
					// se conserva (no borrar por un guardado parcial).
					continue;
				}
				$cur['sections'] = is_array( $value ) ? $value : array();
			} else {
				$cur[ $key ] = $value;
			}
		}
		return wp_json_encode( $cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Política canónica de visibilidad tras una respuesta (por formulario).
	 *
	 *   repeat_visibility.mode      → always_visible | hide_temporarily
	 *   repeat_visibility.duration  → número
	 *   repeat_visibility.unit      → minutes | hours | days
	 *   repeat_visibility.behavior  → show_again (al expirar) | show_again
	 *   repeat_visibility.message   → mensaje alternativo mientras oculto
	 *
	 * Fallback seguro: si no existe o es inválido → always_visible (el formulario
	 * siempre se muestra). Nunca ocultamiento global.
	 */
	public static function repeat_visibility( array $config ): array
	{
		$rv = isset( $config['repeat_visibility'] ) && is_array( $config['repeat_visibility'] ) ? $config['repeat_visibility'] : array();
		$mode = sanitize_key( (string) ( $rv['mode'] ?? '' ) );
		$mode = $mode === 'hide_temporarily' ? 'hide_temporarily' : 'always_visible';
		$unit = sanitize_key( (string) ( $rv['unit'] ?? '' ) );
		$unit = in_array( $unit, array( 'minutes', 'hours', 'days' ), true ) ? $unit : 'minutes';
		$duration = (int) ( $rv['duration'] ?? 0 );
		if ( $duration < 0 ) {
			$duration = 0;
		}
		$behavior = sanitize_key( (string) ( $rv['behavior'] ?? '' ) );
		$behavior = in_array( $behavior, array( 'show_again' ), true ) ? $behavior : 'show_again';
		$message  = trim( (string) ( $rv['message'] ?? '' ) );
		return array(
			'mode'     => $mode,
			'duration' => $duration,
			'unit'     => $unit,
			'behavior' => $behavior,
			'message'  => $message !== '' ? $message : 'Gracias, ya recibimos tu mensaje. Vuelve pronto.',
			'enabled'  => $mode === 'hide_temporarily' && $duration > 0,
		);
	}

	/** Segundos de duración según unidad (determinístico). */
	public static function repeat_visibility_seconds( array $policy ): int
	{
		$mult = array( 'minutes' => 60, 'hours' => 3600, 'days' => 86400 );
		$u = isset( $mult[ $policy['unit'] ] ) ? $mult[ $policy['unit'] ] : 60;
		return (int) $policy['duration'] * $u;
	}

	/**
	 * Motivos de recontacto (valores canónicos estables, separados de etiquetas
	 * traducibles). Los códigos son el contrato; las etiquetas son UI.
	 */
	public static function repeat_reasons(): array
	{
		return array(
			'not_contacted_yet' => 'Aún no me contactan',
			'add_info'          => 'Quiero agregar información',
			'request_changed'   => 'Mi solicitud cambió',
			'urgent'            => 'Necesito atención urgente',
			'other'             => 'Otro',
		);
	}

	/**
	 * Prioridad/escalación determinística ante recontacto. Sin IA.
	 *
	 * @return array { score_delta, escalation, reason, notes }
	 */
	public static function repeat_priority( string $code, int $submission_count, bool $has_contact ): array
	{
		$code = sanitize_key( (string) $code );
		$escalate = false;
		$notes = '';
		$delta = 5;
		if ( $code === 'not_contacted_yet' ) {
			// Recontacto sin contacto comercial previo → escalación.
			$delta = 30;
			$escalate = ! $has_contact;
			$notes = $escalate ? 'recontacto sin contacto comercial previo' : '';
		} elseif ( $code === 'urgent' ) {
			$delta = 50;
			$escalate = true;
			$notes = 'atención urgente solicitada';
		} elseif ( $code === 'request_changed' ) {
			$delta = 20;
		} elseif ( $code === 'add_info' ) {
			$delta = 10;
		}
		return array(
			'score_delta' => $delta,
			'escalation'  => $escalate,
			'reason'      => $code,
			'notes'       => $notes,
		);
	}

	/**
	 * Registra el motivo de un envío repetido asociado a un submission.
	 *
	 * Persiste en la submission, emite evento auditable, recalcula prioridad y
	 * notifica al operador (Core\Communications) UNA sola vez por submission.
	 *
	 * @return array { ok, code, capture_id, priority, notified, error? }
	 */
	public static function record_repeat_reason( int $capture_id, string $code, string $note = '', array $config = array() ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'no_db' );
		}
		$reasons = self::repeat_reasons();
		$code = sanitize_key( (string) $code );
		if ( ! isset( $reasons[ $code ] ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'reason_invalido' );
		}
		$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE id = %d', $capture_id ), ARRAY_A );
		if ( ! is_array( $sub ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'submission_inexistente' );
		}
		if ( ! empty( $sub['repeat_reason'] ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'ya_registrado' );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );

		$wpdb->update(
			$wpdb->prefix . Ingestion::TABLE,
			array( 'repeat_reason' => $code, 'repeat_note' => $note ),
			array( 'id' => $capture_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Contexto determinístico: count de submissions por email + contacto previo.
		$count = 0;
		$has_contact = false;
		if ( $sub['email_normalized'] !== '' ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE email_normalized = %s AND source = %s',
				$sub['email_normalized'], (string) ( $sub['source'] ?? 'form' )
			) );
			$has_contact = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'estandar_flow_pipeline
				 WHERE entity_type = %s AND entity_id IN (
				   SELECT id FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE email_normalized = %s
				 ) AND stage <> %s',
				'capture', $sub['email_normalized'], 'NEW'
			) );
		}
		$priority = self::repeat_priority( $code, $count, $has_contact );

		// Evento comercial auditable.
		$payload = array(
			'event'        => 'capture_repeat_reason',
			'capture_id'   => $capture_id,
			'uuid'         => (string) $sub['capture_uuid'],
			'code'         => $code,
			'label'        => $reasons[ $code ],
			'note'         => $note,
			'email'        => (string) $sub['email_normalized'],
			'submissions'  => $count,
			'first_at'     => (string) $sub['created_at'],
			'priority'     => $priority,
		);
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( 'info', 'CAPTURE_REPEAT', 'Motivo de recontacto: ' . $reasons[ $code ], $payload );
		}
		do_action( 'estandar_suite_capture_repeat_reason', $payload );

		// Notificación contextual al operador (motor único Core\Mail), una sola vez.
		$notified = array( 'sent' => false, 'to' => array(), 'error' => 'disabled' );
		$lead = self::pipeline_for_capture( $capture_id );
		$operator = '';
		if ( $lead ) {
			$op = $wpdb->get_var( $wpdb->prepare(
				'SELECT assigned_operator FROM ' . $wpdb->prefix . 'estandar_flow_pipeline WHERE id = %d', (int) $lead['id']
			) );
			$operator = is_string( $op ) ? $op : '';
		}
		$to = array();
		if ( $operator !== '' && \Estandar\Core\Mail::validate_address( $operator ) ) {
			$to[] = $operator;
		}
		$ms_to = \Estandar\Core\Mail::normalize_addresses( self::mail_settings( $config )['notify']['to'] );
		if ( is_array( $ms_to ) ) {
			$to = array_merge( $to, $ms_to );
		}
		$to = array_values( array_unique( array_filter( $to, static function ( $a ) { return \Estandar\Core\Mail::validate_address( $a ); } ) ) );
		if ( $to ) {
			$form_name = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', (int) $sub['source_form_id'] ), ARRAY_A );
			$form_name = $row['name'] ?? '';
			$link = admin_url( 'admin.php?page=estandar-suite-flow&lead=' . ( $lead ? (int) $lead['id'] : 0 ) );
			$html = '<p><strong>Recontacto:</strong> ' . esc_html( $reasons[ $code ] ) . '</p>'
				. '<p><strong>Formulario:</strong> ' . esc_html( $form_name ) . '</p>'
				. '<p><strong>Email:</strong> ' . esc_html( (string) $sub['email_normalized'] ) . '</p>'
				. '<p><strong>Envíos de esta persona:</strong> ' . (int) $count . '</p>'
				. '<p><strong>Primer envío:</strong> ' . esc_html( (string) $sub['created_at'] ) . '</p>'
				. ( $note !== '' ? '<p><strong>Nota:</strong> ' . esc_html( $note ) . '</p>' : '' )
				. '<p><a href="' . esc_url( $link ) . '">Ver lead</a></p>';
			$r = \Estandar\Core\Mail::send( array(
				'to'      => $to,
				'subject' => '[Recontacto] ' . $reasons[ $code ] . ' — ' . ( $form_name !== '' ? $form_name : 'form #' . (int) $sub['source_form_id'] ),
				'html'    => $html,
				'alt'     => wp_strip_all_tags( $html ),
				'vars'    => array( 'form_name' => $form_name, 'email' => (string) $sub['email_normalized'] ),
			) );
			$notified = array( 'sent' => ! empty( $r['ok'] ), 'to' => $to, 'error' => $r['error'] ?? '' );
		}

		return array(
			'ok'       => true,
			'code'     => $code,
			'capture_id' => $capture_id,
			'priority' => $priority,
			'notified' => $notified,
		);
	}

	/**
	 * Recuperación idempotente y no destructiva desde legacy.
	 *
	 * Reconstruye el config nativo desde estandar_forms y lo FUSIONA con el actual
	 * (preserva claves no-legacy como sections, repeat_visibility, autoresponse).
	 * $dry_run=true (default) NO escribe: solo calcula y reporta el diff.
	 * Con $dry_run=false persiste si hay diferencia. Nunca destruye datos.
	 *
	 * @return array { ok, id, restored, merged, diff, reasons, wrote, origin }
	 */
	public static function restore_legacy_config( int $legacy_id, bool $dry_run = true ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'no_db' ) );
		}
		$legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'estandar_forms WHERE id = %d', $legacy_id ), ARRAY_A );
		if ( ! is_array( $legacy ) ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'legacy_inexistente' ) );
		}
		$current = self::get( $legacy_id );
		if ( ! $current ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'v2_inexistente' ) );
		}
		$type = self::legacy_form_type_map( (string) ( $legacy['form_type'] ?? '' ) );
		$rebuilt = self::legacy_to_native( $legacy, $type );
		$rebuilt_cfg = json_decode( (string) $rebuilt['config_json'], true );
		$rebuilt_cfg = is_array( $rebuilt_cfg ) ? $rebuilt_cfg : array();
		$current_cfg = json_decode( (string) $current['config_json'], true );
		$current_cfg = is_array( $current_cfg ) ? $current_cfg : array();

		$has_content = ! empty( $current_cfg['sections'] ) || ! empty( $current_cfg['fields_json'] );
		$merged = $current_cfg;
		foreach ( $rebuilt_cfg as $k => $v ) {
			if ( ! array_key_exists( $k, $merged ) || $merged[ $k ] === '' || $merged[ $k ] === null ) {
				$merged[ $k ] = $v;
			}
		}
		// Protección: si el actual ya tiene contenido de campos, no lo pisamos.
		if ( $has_content && isset( $merged['fields_json'] ) && ! empty( $current_cfg['fields_json'] ) ) {
			$merged['fields_json'] = $current_cfg['fields_json'];
		}
		$before = wp_json_encode( $current_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$after  = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$restored = $before !== $after;
		$wrote = false;
		if ( ! $dry_run && $restored ) {
			$wpdb->update(
				$wpdb->prefix . self::TABLE,
				array( 'config_json' => $after, 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $legacy_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$wrote = true;
		}
		$merged_fields = self::renderable_fields( $merged );
		return array(
			'ok'           => true,
			'id'           => (int) $legacy_id,
			'restored'     => $restored,
			'wrote'        => $wrote,
			'dry_run'      => $dry_run,
			'origin'       => 'estandar_forms#' . (int) $legacy['id'],
			'merged'       => $merged,
			'fields'       => $merged_fields,
			'fields_count' => count( $merged_fields ),
			'sections'     => isset( $merged['sections'] ) && is_array( $merged['sections'] ) ? count( $merged['sections'] ) : ( $merged_fields ? 1 : 0 ),
			'diff'         => array( 'before' => $before, 'after' => $after ),
			'reasons'      => array(),
		);
	}

	/**
	 * Diagnóstico del formulario (inspección completa, sin escrituras).
	 * @return array estructura legible para CLI/inspect.
	 */
	public static function inspect( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'inexistente' );
		}
		$cfg = json_decode( (string) $f['config_json'], true );
		$cfg = is_array( $cfg ) ? $cfg : array();
		$fields = self::renderable_fields( $cfg );
		return array(
			'ok'             => true,
			'id'             => (int) $f['id'],
			'name'           => $f['name'],
			'slug'           => $f['slug'],
			'form_type'      => $f['form_type'],
			'form_context'   => $f['form_context'],
			'status'         => $f['status'],
			'config_len'     => strlen( (string) $f['config_json'] ),
			'config_keys'    => array_keys( $cfg ),
			'sections'       => isset( $cfg['sections'] ) && is_array( $cfg['sections'] ) ? count( $cfg['sections'] ) : 0,
			'fields_total'   => count( $fields ),
			'fields'         => $fields,
			'has_fields'     => count( $fields ) > 0,
			'has_content'    => ! empty( $cfg['sections'] ) || ! empty( $cfg['fields_json'] ),
			'repeat_visibility' => self::repeat_visibility( $cfg ),
		);
	}

	/**
	 * Validación de integridad del documento (pure).
	 * @return array { ok, valid, errors }
	 */
	public static function validate( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'valid' => false, 'errors' => array( 'inexistente' ) );
		}
		$errors = array();
		$cfg = json_decode( (string) $f['config_json'], true );
		if ( ! is_array( $cfg ) ) {
			return array( 'ok' => false, 'valid' => false, 'errors' => array( 'config_no_es_objeto' ) );
		}
		$has_sections = isset( $cfg['sections'] );
		$has_flat     = isset( $cfg['fields_json'] );
		if ( ! $has_sections && ! $has_flat ) {
			$errors[] = 'sin_secciones_ni_campos';
		}
		if ( $has_sections ) {
			if ( ! is_array( $cfg['sections'] ) ) {
				$errors[] = 'sections_no_es_array';
			} else {
				foreach ( $cfg['sections'] as $i => $sec ) {
					if ( ! is_array( $sec ) || ! isset( $sec['fields'] ) || ! is_array( $sec['fields'] ) ) {
						$errors[] = 'seccion_' . $i . '_sin_campos';
					}
				}
			}
		}
		$rv = self::repeat_visibility( $cfg );
		if ( $rv['mode'] === 'hide_temporarily' && $rv['duration'] <= 0 ) {
			$errors[] = 'repeat_visibility_duracion_invalida';
		}
		return array(
			'ok'     => empty( $errors ),
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'id'     => $id,
		);
	}

	/** Snapshot del config (para diff/restore); sin escrituras. */
	public static function snapshot_config( int $id ): ?array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return null;
		}
		return array(
			'id'          => (int) $f['id'],
			'name'        => $f['name'],
			'snapshot_at' => gmdate( 'Y-m-d H:i:s' ),
			'config_json' => (string) $f['config_json'],
		);
	}

	/** Clave de metadata donde se preserva la config exclusiva de un tipo. */
	const TYPE_PRESERVE = '_type_preserved';

	/** Claves de config exclusivas de un tipo (se preservan al convertir). */
	public static function type_exclusive_keys( string $type ): array
	{
		if ( $type === 'survey' ) {
			return array( 'survey_config', 'step_by_step', 'hero_title', 'completion_message', 'identification_mode' );
		}
		if ( $type === 'incident' ) {
			return array( 'incidents_config', 'incident_fields', 'follow_up', 'attachments_enabled' );
		}
		return array();
	}

	/**
	 * Reporte de compatibilidad de conversión de tipo (sin escrituras).
	 * Documenta la brecha real: el renderer frontend v2 solo implementa 'lead'.
	 */
	public static function type_conversion_info( int $id, string $new_type ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'inexistente' );
		}
		$new_type = sanitize_key( (string) $new_type );
		if ( ! isset( self::types()[ $new_type ] ) ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'tipo_invalido' );
		}
		$old = (string) $f['form_type'];
		$warnings = array();
		if ( $old !== $new_type ) {
			if ( $new_type !== 'lead' ) {
				$warnings[] = 'frontend_renderer_lead_only';
			}
			if ( in_array( $old, array( 'survey', 'incident' ), true ) ) {
				$warnings[] = 'preservara_config_exclusiva';
			}
		}
		return array(
			'ok'       => true,
			'id'       => $id,
			'changed'  => $old !== $new_type,
			'old_type' => $old,
			'new_type' => $new_type,
			'warnings' => $warnings,
		);
	}

	/**
	 * Conversión NO destructiva entre tipos del mismo motor.
	 *
	 * - Conserva identidad, campos compatibles (sections/fields_json), submissions,
	 *   historial, notificaciones y repeat_visibility.
	 * - Mueve la config exclusiva del tipo anterior a `_type_preserved` (metadata
	 *   recuperable, nunca se borra).
	 * - Reactiva la config exclusiva del nuevo tipo si estaba preservada.
	 * - Valida tipo canónico, registra evento `estandar_suite_capture_form_type_changed`
	 *   y es reversible (convertir de vuelta restaura el bloque preservado).
	 *
	 * @return array { ok, changed, old_type, new_type, preserved, rollback }
	 */
	public static function convert_type( int $id, string $new_type, array $raw ): array
	{
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'error' => 'inexistente' );
		}
		$new_type = sanitize_key( (string) $new_type );
		if ( ! isset( self::types()[ $new_type ] ) ) {
			return array( 'ok' => false, 'error' => 'tipo_invalido' );
		}
		$old = (string) $f['form_type'];
		if ( $old === $new_type ) {
			$ok = self::update( $id, $raw );
			return array( 'ok' => $ok, 'changed' => false, 'old_type' => $old, 'new_type' => $new_type, 'preserved' => array(), 'rollback' => $old );
		}

		$cfg = json_decode( (string) ( $raw['config_json'] ?? '{}' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();

		// 1) Mover config exclusiva del tipo anterior a metadata preservada.
		$preserved = array();
		foreach ( self::type_exclusive_keys( $old ) as $k ) {
			if ( array_key_exists( $k, $cfg ) ) {
				$preserved[ $k ] = $cfg[ $k ];
				unset( $cfg[ $k ] );
			}
		}
		// 2) Reactivar config exclusiva del nuevo tipo si estaba preservada.
		$block = isset( $cfg[ self::TYPE_PRESERVE ] ) && is_array( $cfg[ self::TYPE_PRESERVE ] ) ? $cfg[ self::TYPE_PRESERVE ] : array();
		$restored = array();
		if ( isset( $block[ $new_type ] ) && is_array( $block[ $new_type ] ) ) {
			foreach ( self::type_exclusive_keys( $new_type ) as $k ) {
				if ( array_key_exists( $k, $block[ $new_type ] ) ) {
					$cfg[ $k ] = $block[ $new_type ][ $k ];
					unset( $block[ $new_type ][ $k ] );
					$restored[] = $k;
				}
			}
			if ( empty( $block[ $new_type ] ) ) {
				unset( $block[ $new_type ] );
			}
		}
		// 3) Guardar bloque preservado (metadata inactiva recuperable).
		if ( $preserved ) {
			$block[ $old ] = $preserved;
		}
		if ( $block ) {
			$cfg[ self::TYPE_PRESERVE ] = $block;
		} else {
			unset( $cfg[ self::TYPE_PRESERVE ] );
		}

		$raw['config_json'] = wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$raw['form_type']   = $new_type;
		$ok = self::update( $id, $raw );

		$payload = array(
			'event'      => 'capture_form_type_changed',
			'form_id'    => $id,
			'old_type'   => $old,
			'new_type'   => $new_type,
			'preserved'  => array_keys( $preserved ),
			'restored'   => $restored,
		);
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( 'info', 'CAPTURE_FORM_TYPE', 'Tipo cambiado: ' . $old . ' → ' . $new_type, $payload );
		}
		do_action( 'estandar_suite_capture_form_type_changed', $payload );

		return array(
			'ok'        => $ok,
			'changed'   => true,
			'old_type'  => $old,
			'new_type'  => $new_type,
			'preserved' => array_keys( $preserved ),
			'restored'  => $restored,
			'rollback'  => $old,
		);
	}
}
