<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form bridges — connect real form plugins (CF7, Gravity Forms) and external
 * form webhooks (JotForm) into the canonical pipeline:
 *
 *   form submit → ingest() (persist + estandar_suite_capture_event)
 *              → do_action('estandar_suite_form_entry_created', $capture, $source, $form_id)
 *                (Core Integration engine enqueues the sheet/Excel write async)
 *
 * Also exposes form + field discovery for the "Conectar a…" wizard. Never
 * fatals when a form plugin is absent (guards everywhere).
 */
class FormBridge
{
	const WEBHOOK_KEY_OPTION = 'estandar_suite_capture_webhook_key';

	/** Register hooks (called from plugins_loaded). */
	public static function init(): void
	{
		if ( class_exists( '\WPCF7_ContactForm' ) ) {
			add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_cf7_mail_sent' ), 10, 1 );
		}
		if ( class_exists( '\GFAPI' ) || class_exists( '\GFForms' ) ) {
			add_action( 'gform_after_submission', array( __CLASS__, 'on_gf_submission' ), 10, 2 );
		}
	}

	/** CF7: after a successful mail send. @param \WPCF7_ContactForm $contact_form */
	public static function on_cf7_mail_sent( $contact_form ): void
	{
		if ( ! class_exists( '\WPCF7_Submission' ) || ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return;
		}
		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission || ! is_object( $submission ) ) {
			return;
		}
		$posted = array();
		foreach ( (array) $submission->get_posted_data() as $k => $v ) {
			if ( $k === '_wpcf7' || $k === '_wpcf7_version' || $k === '_wpcf7_locale' || $k === '_wpcf7_unit_tag' || $k === '_wpcf7_container_post' || $k === '_wpcf7_posted_data_hash' ) {
				continue;
			}
			$posted[ $k ] = $v;
		}
		$meta = array();
		if ( is_object( $submission ) && method_exists( $submission, 'get_meta' ) ) {
			$ip = $submission->get_meta( 'remote_ip' );
			if ( is_string( $ip ) && $ip !== '' ) {
				$meta['ip'] = $ip;
			}
		}
		self::ingest_form( 'cf7', (int) $contact_form->id(), $posted, $meta );
	}

	/** Gravity Forms: after a successful submission. @param array $entry @param array $form */
	public static function on_gf_submission( $entry, $form ): void
	{
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}
		$fields = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			if ( ! is_object( $f ) || empty( $f->id ) ) {
				continue;
			}
			$id = (string) $f->id;
			if ( isset( $entry[ $id ] ) ) {
				$label = (string) ( $f->adminLabel ?: $f->label );
				$fields[ $id ]     = $entry[ $id ];
				if ( $label !== '' ) {
					$fields[ $label ] = $entry[ $id ];
				}
			}
		}
		$meta = array( 'ip' => (string) ( $entry['ip'] ?? '' ) );
		self::ingest_form( 'gravity', (int) ( $entry['form_id'] ?? 0 ), $fields, $meta );
	}

	/**
	 * Ingest a form entry into the canonical pipeline and emit the form-entry
	 * contract. Returns the ingest result (id + capture).
	 */
	public static function ingest_form( string $source, int $form_id, array $fields, array $meta = array() ): array
	{
		if ( ! in_array( $source, array( 'cf7', 'gravity', 'jotform', 'facebook', 'v2' ), true ) ) {
			$source = 'v2';
		}
		$contact = array(
			'name'  => (string) ( $fields['your-name'] ?? $fields['name'] ?? $fields['nombre'] ?? '' ),
			'email' => (string) ( $fields['your-email'] ?? $fields['email'] ?? $fields['correo'] ?? '' ),
			'phone' => (string) ( $fields['your-phone'] ?? $fields['phone'] ?? $fields['tel'] ?? $fields['telefono'] ?? '' ),
		);
		$raw = array(
			'source'      => 'form',
			'contact'     => $contact,
			'content'     => array( 'source_form_id' => $form_id, 'message' => (string) ( $fields['your-message'] ?? $fields['message'] ?? '' ) ),
			'fields'      => $fields,
			'attribution' => array_merge(
				array( 'landing_url' => (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) ),
				is_array( $meta['attribution'] ?? null ) ? $meta['attribution'] : array()
			),
			'ip'          => (string) ( $meta['ip'] ?? ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'spam'        => array(),
		);
		$result = Ingestion::ingest( $raw );
		if ( ! empty( $result['id'] ) ) {
			do_action( 'estandar_suite_form_entry_created', $result['capture'], $source, $form_id );
		}
		return $result;
	}

	// ── Discovery (wizard) ─────────────────────────────────────────────────

	/** External forms for a source: [ {id, name} ]. Empty when plugin absent. */
	public static function forms( string $source ): array
	{
		$source = sanitize_key( $source );
		if ( $source === 'cf7' && class_exists( '\WPCF7_ContactForm' ) && function_exists( 'get_posts' ) ) {
			$posts = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => 200 ) );
			$out = array();
			foreach ( (array) $posts as $p ) {
				$out[] = array( 'id' => (int) $p->ID, 'name' => (string) $p->post_title );
			}
			return $out;
		}
		if ( $source === 'gravity' && class_exists( '\GFAPI' ) && method_exists( '\GFAPI', 'get_forms' ) ) {
			$out = array();
			foreach ( (array) \GFAPI::get_forms() as $f ) {
				$out[] = array( 'id' => (int) ( $f['id'] ?? 0 ), 'name' => (string) ( $f['title'] ?? '' ) );
			}
			return $out;
		}
		if ( $source === 'v2' ) {
			// Fuente "v2" = formularios de captura tipo lead (el asistente y el
			// engine operan sobre forms; surveys/incidents/chatbot no despachan
			// al motor de integraciones hoy).
			$out = array();
			foreach ( Forms::list( 'lead' ) as $f ) {
				$out[] = array( 'id' => (int) $f['id'], 'name' => (string) $f['name'] );
			}
			return $out;
		}
		return array();
	}

	/** Fields of an external form: [ key => label ]. */
	public static function fields( string $source, int $form_id ): array
	{
		$source = sanitize_key( $source );
		$out    = array();
		if ( $source === 'cf7' && class_exists( '\WPCF7_ContactForm' ) ) {
			$cf = \WPCF7_ContactForm::get_instance( $form_id );
			if ( $cf && method_exists( $cf, 'scan_form_tags' ) ) {
				foreach ( (array) $cf->scan_form_tags() as $tag ) {
					if ( ! is_object( $tag ) || empty( $tag->name ) ) {
						continue;
					}
					$type = (string) ( $tag->type ?? '' );
					if ( preg_match( '/(submit|button|hidden|recaptcha|quiz|file)/i', $type ) ) {
						continue;
					}
					$out[ $tag->name ] = (string) $tag->name;
				}
			}
		} elseif ( $source === 'gravity' && class_exists( '\GFAPI' ) && method_exists( '\GFAPI', 'get_form' ) ) {
			$form = \GFAPI::get_form( $form_id );
			if ( is_array( $form ) ) {
				foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
					if ( ! is_object( $f ) || empty( $f->id ) ) {
						continue;
					}
					$label = (string) ( $f->adminLabel ?: $f->label );
					$out[ (string) $f->id ] = $label !== '' ? $label : (string) $f->id;
				}
			}
		} elseif ( $source === 'v2' ) {
			$form = Forms::get( $form_id );
			if ( $form ) {
				$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
				$fields = is_array( $config ) && ! empty( $config['fields_json'] ) ? json_decode( (string) $config['fields_json'], true ) : array();
				foreach ( (array) $fields as $f ) {
					$key   = (string) ( $f['key'] ?? $f['slug'] ?? $f['name'] ?? '' );
					$label = (string) ( $f['label'] ?? $key );
					if ( $key !== '' ) {
						$out[ $key ] = $label !== '' ? $label : $key;
					}
				}
			}
		}
		return $out;
	}

	// ── JotForm webhook receptor ───────────────────────────────────────────

	/** Shared secret for the public JotForm endpoint (auto-generated). */
	public static function webhook_key(): string
	{
		$key = get_option( self::WEBHOOK_KEY_OPTION, '' );
		if ( $key === '' ) {
			$key = bin2hex( random_bytes( 24 ) );
			update_option( self::WEBHOOK_KEY_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * Accept a JotForm webhook POST (authenticated by shared secret) and ingest
	 * it as a jotform entry. Returns {id, capture_uuid} or 0.
	 */
	public static function handle_jotform( array $payload ): array
	{
		$fields  = array();
		$form_id = (int) ( $payload['formID'] ?? 0 );
		foreach ( (array) ( $payload['rawRequest'] ?? $payload['answer'] ?? $payload ) as $k => $v ) {
			if ( is_string( $k ) && ( $k === 'formID' || $k === 'submissionID' || $k === 'ip' || $k === 'createdAt' ) ) {
				continue;
			}
			$fields[ (string) $k ] = $v;
		}
		$meta = array( 'ip' => (string) ( $payload['ip'] ?? '' ) );
		return self::ingest_form( 'jotform', $form_id, $fields, $meta );
	}

	// ── Meta (Facebook/Instagram) lead ads webhook ─────────────────────────

	/**
	 * Ingesta un lead de un formulario instantáneo de Meta ya resuelto.
	 * @param array $fields campos normalizados (name/email/phone/…)
	 * @param array $meta   contexto (leadgen_id, form_id, form_label, ad_id, …)
	 */
	public static function handle_facebook( int $form_id, array $fields, array $meta = array() ): array
	{
		$meta = array_merge( array( 'ip' => '' ), $meta );
		// META_ATTR_V1 (2026-10-07): los Instant Forms de Meta no propagan un
		// click-id (el usuario nunca visita el sitio), pero el webhook "leadgen"
		// sí entrega ad_id/adset_id/campaign_id. Se persisten como atribución
		// para poder trazar el anuncio sin depender de gclid/fbclid.
		if ( empty( $meta['attribution'] ) || ! is_array( $meta['attribution'] ) ) {
			$meta['attribution'] = array();
		}
		foreach ( array( 'ad_id', 'campaign_id', 'adset_id', 'leadgen_id' ) as $k ) {
			if ( ! empty( $meta[ $k ] ) ) {
				$meta['attribution'][ 'meta_' . $k ] = (string) $meta[ $k ];
			}
		}
		return self::ingest_form( 'facebook', $form_id, $fields, $meta );
	}
}
