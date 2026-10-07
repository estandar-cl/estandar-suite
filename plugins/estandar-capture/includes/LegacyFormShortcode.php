<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LegacyFormShortcode — compat shortcode `[estandar_form]` de estandar-forms.
 *
 * F0 · no romper el sitio al retirar estandar-forms: los embeds existentes
 * `[estandar_form id="N" slug="..."]` siguen funcionando sin el plugin legacy.
 *
 * TOMA DE POSESIÓN (takeover) en F5 — no compite con estandar-core LegacyMap:
 *  - Mientras el archivo `estandar-forms/estandar-forms.php` exista en disco,
 *    LegacyMap (estandar-core) re-incluye el main file y el shortcode lo sirve
 *    el código legacy original (cero regresión, cero reimplementación). Este
 *    compat NO se registra (guard por disco).
 *  - Cuando estandar-forms se ARCHIVA (F5, archivo fuera de disco), LegacyMap
 *    deja de poder servirlo y este compat toma posesión: resuelve el form
 *    legacy SOLO-LECTURA de `estandar_forms` y renderiza por el pipeline v2.
 *
 * Reglas:
 *  - No se registra si estandar-forms está activo (su shortcode manda).
 *  - No se registra mientras el main file exista (LegacyMap manda).
 *  - Resolución: primero form nativo v2 (por id o slug); si no existe, lee la
 *    tabla legacy `estandar_forms` SOLO-LECTURA y construye el payload nativo
 *    en memoria vía Forms::legacy_to_native() (0 escrituras).
 *  - El render delega en FormRenderer::render_form() → mismo pipeline v2
 *    (honeypot, nonce, Ingestion, notificación por Core\Mail).
 *  - Form types que capture aún no publica (survey/incident) no se renderizan
 *    todavía (oracle: missing). El id legacy se preserva → las submissions
 *    linkean por source_form_id igual que las entries históricas.
 */
class LegacyFormShortcode
{
	const SHORTCODE = 'estandar_form';
	const LEGACY_MAIN = 'estandar-forms/estandar-forms.php';

	public static function init(): void
	{
		if ( class_exists( 'Estandar_Forms_Shortcode', false ) ) {
			return; // legacy activo: su shortcode manda.
		}
		if ( self::legacy_main_on_disk() ) {
			return; // LegacyMap (estandar-core) aún lo cubre: no pisar.
		}
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/** ¿El main file legacy sigue en disco (LegacyMap puede servirlo)? */
	public static function legacy_main_on_disk(): bool
	{
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}
		return is_readable( WP_PLUGIN_DIR . '/' . self::LEGACY_MAIN );
	}

	public static function render( $atts ): string
	{
		$atts = shortcode_atts( array(
			'id'   => 0,
			'slug' => '',
		), (array) $atts, self::SHORTCODE );

		$form = self::resolve( $atts );
		if ( ! $form ) {
			return '';
		}
		// FORM_STATUS_GATE_V1: no publicar forms no-activos en el frontend.
		if ( ! FormRenderer::is_publishable( $form ) ) {
			return '';
		}
		if ( (string) $form['form_type'] === 'survey' ) {
			return SurveyRenderer::render_form( $form );
		}
		if ( (string) $form['form_type'] !== 'lead' && (string) $form['form_type'] !== 'incident' ) {
			return '';
		}
		return FormRenderer::render_form( $form );
	}

	/** Resuelve el payload nativo: v2 primero, legacy read-only después. */
	public static function resolve( array $atts ): ?array
	{
		$id   = (int) ( $atts['id'] ?? 0 );
		$slug = sanitize_title( (string) ( $atts['slug'] ?? '' ) );

		// 1) Nativo v2 (ya migrado → el id legacy coincide).
		if ( $id > 0 ) {
			$native = Forms::get( $id );
			if ( $native ) {
				return $native;
			}
		}
		if ( $slug !== '' ) {
			$native = Forms::get_by_slug( $slug );
			if ( $native ) {
				return $native;
			}
		}

		// 2) Legacy read-only (guard: la tabla legacy pudo no existir en sitios
		// ya migrados; consultarla sin guard tira DB error "table doesn't exist").
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'estandar_forms';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		$row   = null;
		if ( $id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		}
		if ( ! $row && $slug !== '' ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
		}
		if ( ! is_array( $row ) || empty( $row['fields_json'] ) ) {
			return null;
		}

		$type = Forms::legacy_form_type_map( (string) ( $row['form_type'] ?? '' ) );
		if ( $type === '' ) {
			return null; // tipos fuera de alcance (sin equivalente v2).
		}
		return Forms::legacy_to_native( $row, $type );
	}
}
