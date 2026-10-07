<?php

namespace Estandar\Flow\Quotes;

use Estandar\Flow\Quotes\Document\Calculator;
use Estandar\Flow\Quotes\Document\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor — editor WYSIWYG tipo SII para cotizaciones (M6).
 *
 * Envuelve el HTML de `Renderer::render()` (mismos slots `data-qfield`) y añade
 * JS vanilla namespaceado que convierte cada slot en un control editable EN SU
 * MISMA POSICIÓN DE FLUJO (sin absolutos, sin drag&drop), mantiene un modelo
 * plano `window.esqModel` y lo serializa en `document_json` al enviar.
 *
 * PURE en `normalize_post()` (sin BD): parsea el `document_json` (o el formato
 * legado) y devuelve el documento canónico listo para `Renderer`/persistencia.
 * Tolerante a basura: nunca fataliza.
 */
class Editor
{
	/** Campos calculados que NO se convierten en control (solo lectura). */
	const READONLY_KEYS = array( 'totals.subtotal', 'totals.tax_amount', 'totals.total' );

	/** Campos que SIEMPRE se renderizan como selector (con opción "Otro proyecto…"). */
	const PROJECT_FIELD = 'project.name';

	/** Valor especial del selector de proyecto que dispara la navegación a la lista. */
	const OTHER_PROJECT_VALUE = '__otro__';

	/** Claves canónicas de grupos de texto (una por grupo). */
	const TEXT_GROUPS = array(
		'meta'      => true,
		'issuer'    => true,
		'client'    => true,
		'project'   => true,
		'property'  => true,
		'pricing'   => true,
		'financing' => true,
		'operator'  => true,
	);

	// ── Canvas ──────────────────────────────────────────────────────────────

	/**
	 * Documento editable: HTML de `Renderer` + JS de edición in-place.
	 *
	 * @param array $doc  Documento canónico.
	 * @param array $opts Opciones: `model` (mapa plano), `currency`.
	 */
	public static function canvas( array $doc, array $opts = array() ): string
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Document\\Renderer' ) ) {
			return '<div class="esq-canvas" data-pdf-width="8.5in"></div>';
		}

		// Aseguramos al menos una fila de ítem para que el operador pueda editar
		// en el lugar (el documento base de una cotización nueva no trae líneas).
		$editable_doc = $doc;
		if ( empty( $editable_doc['items'] ) || ! is_array( $editable_doc['items'] ) ) {
			$editable_doc['items'] = array(
				array( 'description' => '', 'detail' => '', 'unit' => '', 'qty' => 1, 'unit_price' => 0, 'line_total' => 0 ),
			);
		}

		$html  = Renderer::render( $editable_doc, array( 'editable' => true ) );
		$model = isset( $opts['model'] ) && is_array( $opts['model'] ) ? $opts['model'] : self::model_from_doc( $doc );
		$cur   = esc_attr( (string) ( $doc['meta']['currency'] ?? ( $opts['currency'] ?? 'CLP' ) ) );

		// Opciones de campos (menús desplegables): del proyecto o del propio doc.
		$field_options = self::field_options_from( $doc, $opts );
		$html          = self::apply_selects( $html, $field_options );

		// El control de galería (biblioteca de medios) es opt-in: solo el wizard
		// de Proyectos engancha `wp.media` (ver `Admin::enqueue_projects_assets`).
		$gallery = ! empty( $opts['gallery'] ) ? self::gallery_control( $doc ) : '';

		return '<div class="esq-canvas" data-pdf-width="8.5in" data-currency="' . $cur . '">' . $html . $gallery . '</div>'
			. self::style()
			. self::script( $model, $field_options, self::projects_list_url( $opts ) );
	}

	/**
	 * Opciones de campos efectivas del canvas: `opts['field_options']` >
	 * `doc['field_options']`. Saneadas con `Settings` cuando está disponible.
	 *
	 * @param array $doc  Documento canónico.
	 * @param array $opts Opciones del canvas.
	 * @return array<string,string[]>
	 */
	private static function field_options_from( array $doc, array $opts ): array
	{
		$raw = array();
		if ( isset( $opts['field_options'] ) && is_array( $opts['field_options'] ) ) {
			$raw = $opts['field_options'];
		} elseif ( isset( $doc['field_options'] ) && is_array( $doc['field_options'] ) ) {
			$raw = $doc['field_options'];
		}
		if ( empty( $raw ) ) {
			return array();
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) && method_exists( 'Estandar\\Flow\\Quotes\\Settings', 'sanitize_field_options' ) ) {
			return \Estandar\Flow\Quotes\Settings::sanitize_field_options( $raw );
		}

		$out = array();
		foreach ( $raw as $key => $list ) {
			if ( ! is_scalar( $key ) || ! is_array( $list ) ) {
				continue;
			}
			$items = array();
			foreach ( $list as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$items[] = trim( (string) $value );
				}
			}
			$items = array_values( array_unique( $items ) );
			if ( ! empty( $items ) ) {
				$out[ (string) $key ] = $items;
			}
		}
		return $out;
	}

	/**
	 * Reemplaza los slots `data-qfield` con opciones configuradas por un
	 * `<select data-qfield="…">` (server-side). `project.name` siempre es select.
	 *
	 * @param string                   $html          HTML del Renderer.
	 * @param array<string,string[]>   $field_options Opciones efectivas.
	 */
	private static function apply_selects( string $html, array $field_options ): string
	{
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$keys = array_keys( $field_options );
		if ( ! in_array( self::PROJECT_FIELD, $keys, true ) ) {
			$keys[] = self::PROJECT_FIELD;
		}

		foreach ( $keys as $key ) {
			$key   = (string) $key;
			$list  = isset( $field_options[ $key ] ) && is_array( $field_options[ $key ] ) ? $field_options[ $key ] : array();
			$html  = self::replace_slot_with_select( $html, $key, $list );
		}
		return $html;
	}

	/** Sustituye UN slot (`<span data-qfield=…>`) por el par span oculto + select. */
	private static function replace_slot_with_select( string $html, string $key, array $options ): string
	{
		$pattern = '#<span data-qfield="' . preg_quote( $key, '#' ) . '" data-qtype="[^"]*">(.*?)</span>#s';
		$result  = preg_replace_callback( $pattern, function ( $m ) use ( $key, $options ) {
			$current = self::clean_select_value( self::html_to_text( (string) $m[1] ) );
			return self::select_markup( $key, $options, $current );
		}, $html );
		return is_string( $result ) ? $result : $html;
	}

	/** HTML del par span oculto + `<select>` para un campo con opciones. */
	private static function select_markup( string $key, array $options, string $current ): string
	{
		$is_project = ( self::PROJECT_FIELD === $key );

		$opts = array();
		foreach ( $options as $option ) {
			if ( ! is_scalar( $option ) ) {
				continue;
			}
			$option = trim( (string) $option );
			if ( '' !== $option && ! in_array( $option, $opts, true ) ) {
				$opts[] = $option;
			}
		}
		// Prefiere/preserva el valor actual aunque no esté en la lista.
		if ( '' !== $current && ! in_array( $current, $opts, true ) ) {
			array_unshift( $opts, $current );
		}

		$html = '';
		if ( ! $is_project ) {
			$selected = ( '' === $current ) ? ' selected' : '';
			$html    .= '<option value=""' . $selected . '>—</option>';
		}
		foreach ( $opts as $option ) {
			$selected = ( $option === $current ) ? ' selected' : '';
			$html    .= '<option value="' . esc_attr( $option ) . '"' . $selected . '>' . esc_html( $option ) . '</option>';
		}
		if ( $is_project ) {
			$html .= '<option value="' . esc_attr( self::OTHER_PROJECT_VALUE ) . '">Otro proyecto…</option>';
		}

		$span = '<span data-qfield="' . esc_attr( $key ) . '" data-qtype="select" data-esq-preloaded="1" style="display:none">'
			. esc_html( $current ) . '</span>';

		$select = '<select class="esq-input esq-input-select" data-esq-key="' . esc_attr( $key )
			. '" data-qfield="' . esc_attr( $key ) . '" data-esq-select="1">' . $html . '</select>';

		return $span . $select;
	}

	/** Valor de un select: trim y placeholder de guiones → ''. */
	private static function clean_select_value( string $value ): string
	{
		$value = trim( $value );
		if ( in_array( $value, array( '-', '--', '---', '—', '–', 'n/a', 'N/A' ), true ) ) {
			return '';
		}
		return $value;
	}

	/** Texto plano desde el HTML interno de un slot (para precargar el select). */
	private static function html_to_text( string $html ): string
	{
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		return trim( $text );
	}

	/** URL de la lista de proyectos (para la opción "Otro proyecto…"). */
	private static function projects_list_url( array $opts ): string
	{
		$url = isset( $opts['projects_url'] ) ? trim( (string) $opts['projects_url'] ) : '';
		if ( '' === $url && class_exists( 'Estandar\\Flow\\Admin' ) && function_exists( 'admin_url' )
			&& method_exists( 'Estandar\\Flow\\Admin', 'projects_url' ) ) {
			$url = trim( (string) \Estandar\Flow\Admin::projects_url() );
		}
		return $url;
	}

	/**
	 * Control de galería del documento (hasta 6 imágenes). Reusa el patrón de
	 * biblioteca de medios del wizard (`.esfp-media-gallery-pick` +
	 * `.esfp-gallery-preview`, ver `Admin::enqueue_projects_assets`) y envía el
	 * resultado en el campo oculto JSON `images_json`.
	 *
	 * @param array $doc Documento canónico (usa `doc['images']` como prefijo).
	 */
	private static function gallery_control( array $doc ): string
	{
		$images = $doc['images'] ?? array();
		if ( is_string( $images ) ) {
			$decoded = json_decode( $images, true );
			$images  = is_array( $decoded ) ? $decoded : array();
		}
		$images = is_array( $images ) ? array_values( $images ) : array();

		$thumbs = '';
		foreach ( $images as $i => $img ) {
			$img = is_array( $img ) ? $img : array();
			$url = (string) ( $img['url'] ?? '' );
			if ( '' === trim( $url ) ) {
				continue;
			}
			$thumbs .= '<span style="display:inline-block;margin:0 6px 6px 0;text-align:center;">'
				. '<img src="' . esc_url( $url ) . '" style="width:90px;height:70px;object-fit:cover;display:block;border:1px solid #dcdcde;" alt="">'
				. '<button type="button" class="button-link esfp-gallery-remove" data-index="' . esc_attr( (string) $i ) . '" style="color:#b32d2e;cursor:pointer;">Quitar</button>'
				. '</span>';
		}

		return '<div class="esq-gallery-control esq-no-print" style="margin:14px 0 0;">'
			. '<input type="hidden" name="images_json" id="esq-images-json" value="' . esc_attr( self::json_encode( $images ) ) . '">'
			. '<div class="esfp-gallery-preview" id="esq-images-preview" data-json="esq-images-json" style="margin-bottom:8px;">' . $thumbs . '</div>'
			. '<button type="button" class="button esfp-media-gallery-pick" data-json="esq-images-json" data-preview="esq-images-preview">Agregar imágenes</button>'
			. '<p class="description" style="margin:6px 0 0;">Imágenes del proyecto (hasta 6). Se muestran en el documento tras el financiamiento; si no hay imágenes, la sección no aparece.</p>'
			. '</div>';
	}

	/**
	 * Página autocontenida del editor (mismo lenguaje visual que LeadsPanel).
	 *
	 * @param int    $lead_id      Lead asociado.
	 * @param int    $quote_id      Cotización (0 → nueva).
	 * @param string $notice        Aviso a mostrar.
	 * @param string $notice_type   info|ok|error.
	 * @param string $action        URL del formulario.
	 * @param string $nonce         Nonce del formulario.
	 * @param array  $doc           Documento canónico.
	 * @param array  $opts          `quotes` (lista del lead), `back` (URL volver), `lead`.
	 */
	public static function page( int $lead_id, int $quote_id, string $notice, string $notice_type, string $action, string $nonce, array $doc, array $opts = array() ): string
	{
		$lead        = isset( $opts['lead'] ) && is_array( $opts['lead'] ) ? $opts['lead'] : array();
		$quotes      = isset( $opts['quotes'] ) && is_array( $opts['quotes'] ) ? $opts['quotes'] : array();
		$back        = (string) ( $opts['back'] ?? '' );
		$action      = (string) $action;
		$notice_html = self::notice( $notice, $notice_type );

		// Acciones admin-post opcionales (Suite): inyectan el campo oculto
		// `action` para que el POST llegue a admin_post_* sin tocar el editor de
		// LeadsPanel (que no las usa: vacías por defecto).
		$save_action = (string) ( $opts['save_action'] ?? '' );
		$send_action = (string) ( $opts['send_action'] ?? '' );
		$save_hidden = '' !== $save_action ? '<input type="hidden" name="action" value="' . esc_attr( $save_action ) . '">' : '';
		$send_hidden = '' !== $send_action ? '<input type="hidden" name="action" value="' . esc_attr( $send_action ) . '">' : '';

		$client_name = (string) ( $lead['name'] ?? ( $doc['client']['name'] ?? '' ) );
		$title       = 'Cotización — ' . ( '' !== $client_name ? $client_name : 'lead #' . $lead_id );

		$quotes_html = self::quotes_list( $quotes, $action, $nonce, $lead_id );

		$send_html = '';
		if ( $quote_id > 0 ) {
			$send_html = '<form method="post" action="' . esc_url( $action ) . '" class="esq-send esq-no-print">'
				. $send_hidden
				. '<input type="hidden" name="lp_action" value="quote_send">'
				. '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">'
				. '<input type="hidden" name="quote_id" value="' . (int) $quote_id . '">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
				. '<button class="btn sub" type="submit">Enviar al cliente</button>'
				. '</form>';
		}

		$form = '<form method="post" action="' . esc_url( $action ) . '" id="esq-form">'
			. $save_hidden
			. '<input type="hidden" name="lp_action" value="quote_save">'
			. '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">'
			. '<input type="hidden" name="quote_id" value="' . (int) $quote_id . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
			. '<input type="hidden" name="document_json" value="">'
			. '<div class="card esq-doc-card esq-no-print-border">' . self::canvas( $doc ) . '</div>'
			. '<div class="card esq-no-print"><h3>Notas internas</h3>'
			. '<textarea name="notes" rows="2" placeholder="Condiciones, forma de pago…">' . self::esc_textarea( (string) ( $doc['notes'] ?? '' ) ) . '</textarea></div>'
			. '<div class="actions esq-no-print">'
			. '<button class="btn" type="submit">Guardar</button>'
			. '<button class="btn sub" type="button" id="esq-preview">Vista previa</button>'
			. '<button class="btn sub" type="button" id="esq-print">Generar PDF</button>'
			. '</div>'
			. '</form>';

		$back_html = '' !== $back ? '<p><a href="' . esc_url( $back ) . '">← Volver al lead</a></p>' : '';
		$send_hint = $quote_id > 0 ? '' : '<p class="muted esq-no-print">Guarda la cotización para poder enviarla.</p>';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title ) . '</title><style>' . self::page_css() . self::print_css() . '</style></head><body>'
			. '<div class="wrap">' . $back_html
			. '<h2 class="esq-title esq-no-print">' . esc_html( $title ) . '</h2>'
			. $notice_html . $quotes_html
			. $form . $send_html . $send_hint
			. '</div></body></html>';
	}

	// ── Normalización (PURE) ────────────────────────────────────────────────

	/**
	 * Documento canónico desde el POST. Si `document_json` trae un modelo plano
	 * válido se usa; si no, cae al formato legado (`items`/`title`/`currency`/
	 * `validity_days`/`notes`). Saneado y tolerante a basura (nunca fatal).
	 *
	 * @return array Documento canónico (meta/issuer/client/project/property/
	 *               pricing/financing/items/totals/conditions/notes).
	 */
	public static function normalize_post( array $post, int $lead_id ): array
	{
		$raw     = (string) ( $post['document_json'] ?? '' );
		$decoded = null;

		if ( '' !== trim( $raw ) ) {
			// En WordPress `$_POST` llega con slashes; primero se intenta el JSON
			// tal cual (llamadas programáticas) y, si falla, se des-esca.
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				$un = self::unslash( $raw );
				if ( $un !== $raw ) {
					$decoded = json_decode( $un, true );
				}
			}
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return self::with_post_images( self::canonical_from_data( $decoded, $post, $lead_id ), $post );
			}
		}

		return self::with_post_images( self::from_legacy( $post, $lead_id ), $post );
	}

	/**
	 * Incorpora la galería del editor (`images_json`, campo oculto del control)
	 * al documento canónico. Solo actúa si el campo vino en el POST (no borra la
	 * galería cuando el formulario no la incluye). Máx. 6 (saneo de Settings).
	 */
	private static function with_post_images( array $doc, array $post ): array
	{
		if ( array_key_exists( 'images_json', $post ) ) {
			$doc['images'] = self::images_from_post( $post );
		}
		return $doc;
	}

	/** Galería saneada desde `images_json` del POST (JSON de `[{id,url,caption}]`). */
	private static function images_from_post( array $post ): array
	{
		$raw = $post['images_json'] ?? '';
		if ( is_array( $raw ) ) {
			$items = $raw;
		} else {
			$json  = self::unslash( (string) $raw );
			$items = json_decode( $json, true );
			if ( ! is_array( $items ) ) {
				$items = json_decode( (string) $raw, true );
			}
		}
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			return \Estandar\Flow\Quotes\Settings::sanitize_project_images( $items );
		}

		// Fallback sin el módulo: saneo mínimo equivalente (máx. 6).
		$out = array();
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) && function_exists( 'esc_url_raw' )
				? esc_url_raw( (string) $item['url'] )
				: (string) ( $item['url'] ?? '' );
			if ( '' === trim( $url ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) ( $item['id'] ?? 0 ),
				'url'     => $url,
				'caption' => self::text( $item['caption'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Persiste la cotización a partir del POST. Usa `Quotes::create/update`; el
	 * `document_json` (columna nueva) se guarda aparte. Nunca fatal.
	 *
	 * @return array{ok:bool,quote_id:int,error:string}
	 */
	public static function save_from_post( int $lead_id, array $post, string $operator = '' ): array
	{
		$fail = array( 'ok' => false, 'quote_id' => 0, 'error' => '' );

		if ( $lead_id <= 0 ) {
			$fail['error'] = 'lead_invalido';
			return $fail;
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$fail['error'] = 'sin_modulo';
			return $fail;
		}

		$doc      = self::normalize_post( $post, $lead_id );
		$quote_id = self::absint( $post['quote_id'] ?? 0 );

		$title    = (string) ( $doc['meta']['title'] ?? '' );
		$currency = (string) ( $doc['meta']['currency'] ?? 'CLP' );
		if ( '' === $currency ) {
			$currency = 'CLP';
		}
		$notes = (string) ( $doc['notes'] ?? '' );

		$items = self::items_for_quotes( (array) ( $doc['items'] ?? array() ) );

		// Si el documento no trae líneas (p. ej. cotización nueva base), no
		// borramos las existentes al actualizar.
		if ( empty( $items ) && $quote_id > 0 ) {
			$existing = \Estandar\Flow\Quotes::get( $quote_id );
			if ( is_array( $existing ) && ! empty( $existing['items'] ) && is_array( $existing['items'] ) ) {
				$items = $existing['items'];
			}
		}

		$validity = self::absint( $post['validity_days'] ?? 0 );
		if ( $validity <= 0 && $quote_id > 0 ) {
			$existing = is_array( $existing ?? null ) ? $existing : \Estandar\Flow\Quotes::get( $quote_id );
			$validity = is_array( $existing ) ? (int) ( $existing['validity_days'] ?? 0 ) : 0;
		}
		if ( $validity <= 0 ) {
			$validity = 15;
		}

		$data = array(
			'lead_id'       => $lead_id,
			'title'         => $title,
			'items'         => $items,
			'currency'      => $currency,
			'validity_days' => $validity,
			'notes'         => $notes,
			'client_tax_id' => (string) ( $doc['client']['tax_id'] ?? '' ),
			'profile_key'   => (string) ( $doc['profile'] ?? '' ),
		);

		if ( $quote_id > 0 ) {
			if ( ! \Estandar\Flow\Quotes::update( $quote_id, $data ) ) {
				$fail['error'] = 'no_actualizada';
				return $fail;
			}
		} else {
			$data['status'] = 'draft';
			$quote_id       = \Estandar\Flow\Quotes::create( $data );
			if ( $quote_id <= 0 ) {
				$fail['error'] = 'no_creada';
				return $fail;
			}
		}

		self::persist_document_json( $quote_id, $doc );

		return array( 'ok' => true, 'quote_id' => $quote_id, 'error' => '' );
	}

	// ── Persistencia / carga (aditivo) ──────────────────────────────────────

	/** Guarda `document_json` (columna nueva) de forma segura. */
	public static function persist_document_json( int $quote_id, array $doc ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return false;
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Schema' ) ) {
			\Estandar\Flow\Quotes\Schema::ensure_columns();
		}
		$table = $wpdb->prefix . \Estandar\Flow\Quotes::TABLE;
		$json  = self::json_encode( $doc );
		return false !== $wpdb->update( $table, array( 'document_json' => $json ), array( 'id' => $quote_id ) );
	}

	/** Documento guardado en `document_json` (o array vacío). */
	public static function load_saved( int $quote_id ): array
	{
		if ( $quote_id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return array();
		}
		$row = \Estandar\Flow\Quotes::get( $quote_id );
		if ( ! is_array( $row ) ) {
			return array();
		}
		$json = (string) ( $row['document_json'] ?? '' );
		if ( '' === trim( $json ) ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Superpone un documento guardado sobre la base (DocumentBuilder/settings).
	 * Solo pisa valores NO vacíos; recalcula totales desde los ítems.
	 */
	public static function merge_saved( array $base, array $saved ): array
	{
		if ( empty( $saved ) ) {
			return $base;
		}
		$out = $base;

		foreach ( self::TEXT_GROUPS as $group => $unused ) {
			if ( ! isset( $saved[ $group ] ) || ! is_array( $saved[ $group ] ) ) {
				continue;
			}
			$merged = isset( $base[ $group ] ) && is_array( $base[ $group ] ) ? $base[ $group ] : array();
			foreach ( $saved[ $group ] as $k => $v ) {
				if ( null === $v || '' === $v || ( is_array( $v ) && empty( $v ) ) ) {
					continue;
				}
				$merged[ $k ] = $v;
			}
			$out[ $group ] = $merged;
		}

		if ( isset( $saved['items'] ) && is_array( $saved['items'] ) && ! empty( $saved['items'] ) ) {
			$out['items'] = $saved['items'];
		}
		// Galería editada: manda aunque quede vacía (el operador pudo quitarlas).
		if ( array_key_exists( 'images', $saved ) && is_array( $saved['images'] ) ) {
			$out['images'] = $saved['images'];
		}
		if ( isset( $saved['conditions'] ) && is_array( $saved['conditions'] ) && ! empty( $saved['conditions'] ) ) {
			$out['conditions'] = $saved['conditions'];
		}
		if ( ! empty( $saved['notes'] ) ) {
			$out['notes'] = $saved['notes'];
		}

		if ( ! empty( $out['items'] ) && is_array( $out['items'] ) ) {
			$t        = isset( $out['totals'] ) && is_array( $out['totals'] ) ? $out['totals'] : array();
			$currency = (string) ( $out['meta']['currency'] ?? 'CLP' );
			$out['totals'] = Calculator::compose_totals( $out['items'], array(
				'discount'      => $t['discount'] ?? 0,
				'discount_mode' => $t['discount_mode'] ?? 'amount',
				'tax_rate'      => $t['tax_rate'] ?? 0,
				'tax_included'  => ! empty( $t['tax_included'] ),
				'currency'      => $currency,
			) );
		}

		return $out;
	}

	// ── Construcción del documento canónico ─────────────────────────────────

	/** Construye el documento canónico desde el modelo (plano o anidado). */
	private static function canonical_from_data( array $data, array $post, int $lead_id ): array
	{
		$flat = self::is_nested( $data ) ? self::flatten_generic( $data ) : self::flatten_shallow( $data );

		$currency = strtoupper( self::text( $flat['meta.currency'] ?? ( $post['currency'] ?? 'CLP' ) ) );
		if ( '' === $currency ) {
			$currency = 'CLP';
		}

		$items = self::items_from_flat( $flat );
		if ( empty( $items ) ) {
			$items = self::parse_legacy_items( (string) ( $post['items'] ?? '' ) );
		}
		$items = self::drop_empty_items( $items );

		$tax_rate = self::number( $flat['totals.tax_rate'] ?? ( $post['tax_rate'] ?? self::settings_vat() ) );

		$profile = self::profile_key( $flat['profile'] ?? '' );
		if ( '' === $profile ) {
			$profile = self::active_profile();
		}

		$doc = array(
			'profile' => $profile,
			'meta'    => array(
				'title'          => self::text( $flat['meta.title'] ?? ( $post['title'] ?? '' ) ),
				'document_title' => self::text( $flat['meta.document_title'] ?? '' ),
				'quote_number'   => self::text( $flat['meta.quote_number'] ?? '' ),
				'issued_at'      => self::text( $flat['meta.issued_at'] ?? '' ),
				'valid_until'    => self::text( $flat['meta.valid_until'] ?? '' ),
				'currency'       => $currency,
			),
			'issuer'     => self::group_from_flat( $flat, 'issuer' ),
			'client'     => self::group_from_flat( $flat, 'client' ),
			'project'    => self::group_from_flat( $flat, 'project' ),
			'property'   => self::group_from_flat( $flat, 'property' ),
			'pricing'    => self::group_from_flat( $flat, 'pricing' ),
			'financing'  => self::group_from_flat( $flat, 'financing' ),
			'operator'   => self::group_from_flat( $flat, 'operator' ),
			'items'      => $items,
			'totals'     => Calculator::compose_totals( $items, array(
				'tax_rate' => $tax_rate,
				'currency' => $currency,
			) ),
			'conditions' => self::conditions_from( $data, $flat ),
			'notes'      => self::textarea( $flat['notes'] ?? ( $post['notes'] ?? '' ) ),
			'lead_id'    => $lead_id,
		);

		return $doc;
	}

	/** Documento canónico desde el formato legado del panel. */
	private static function from_legacy( array $post, int $lead_id ): array
	{
		$currency = strtoupper( self::text( $post['currency'] ?? 'CLP' ) );
		if ( '' === $currency ) {
			$currency = 'CLP';
		}
		$items = self::drop_empty_items( self::parse_legacy_items( (string) ( $post['items'] ?? '' ) ) );

		return array(
			'profile'    => self::active_profile(),
			'meta'       => array(
				'title'          => self::text( $post['title'] ?? '' ),
				'document_title' => '',
				'quote_number'   => '',
				'issued_at'      => '',
				'valid_until'    => '',
				'currency'       => $currency,
			),
			'issuer'     => array(),
			'client'     => array( 'name' => self::text( $post['client_name'] ?? '' ) ),
			'project'    => array(),
			'property'   => array(),
			'pricing'    => array(),
			'financing'  => array(),
			'items'      => $items,
			'totals'     => Calculator::compose_totals( $items, array(
				'tax_rate' => self::settings_vat(),
				'currency' => $currency,
			) ),
			'conditions' => array(),
			'notes'      => self::textarea( $post['notes'] ?? '' ),
			'lead_id'    => $lead_id,
		);
	}

	// ── Ítems ───────────────────────────────────────────────────────────────

	/** Agrupa `items.N.campo` del modelo plano en filas canónicas. */
	private static function items_from_flat( array $flat ): array
	{
		$rows = array();
		foreach ( $flat as $key => $value ) {
			if ( ! preg_match( '/^items\.(\d+)\.(.+)$/', (string) $key, $m ) ) {
				continue;
			}
			$i = (int) $m[1];
			$f = (string) $m[2];
			if ( ! isset( $rows[ $i ] ) ) {
				$rows[ $i ] = array();
			}
			$rows[ $i ][ $f ] = $value;
		}
		ksort( $rows );
		return self::normalize_item_rows( $rows );
	}

	/** Normaliza filas crudas a description/detail/unit/qty/unit_price/line_total. */
	private static function normalize_item_rows( array $rows ): array
	{
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$qty   = self::number( $row['qty'] ?? 1 );
			$price = self::number( $row['unit_price'] ?? ( $row['amount'] ?? 0 ) );
			if ( $qty <= 0 ) {
				$qty = 1.0;
			}
			$out[] = array(
				'description' => self::text( $row['description'] ?? ( $row['label'] ?? '' ) ),
				'detail'      => self::textarea( $row['detail'] ?? '' ),
				'unit'        => self::text( $row['unit'] ?? '' ),
				'qty'         => $qty,
				'unit_price'  => $price,
				'line_total'  => Calculator::line_total( array( 'qty' => $qty, 'unit_price' => $price ) ),
			);
		}
		return $out;
	}

	/** Leyenda legada `descripción|monto[|qty]` por línea. */
	private static function parse_legacy_items( string $text ): array
	{
		if ( '' === trim( $text ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) >= 2 && '' !== $parts[0] ) {
				$out[] = array(
					'description' => self::text( $parts[0] ),
					'amount'      => self::number( $parts[1] ),
					'qty'         => isset( $parts[2] ) ? self::number( $parts[2] ) : 1,
					'unit'        => '',
				);
			}
		}
		return self::normalize_item_rows( $out );
	}

	/** Elimina filas de ítem totalmente vacías (p. ej. la fila plantilla). */
	private static function drop_empty_items( array $items ): array
	{
		$out = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$has_text  = '' !== trim( (string) ( $it['description'] ?? '' ) )
				|| '' !== trim( (string) ( $it['detail'] ?? '' ) )
				|| '' !== trim( (string) ( $it['unit'] ?? '' ) );
			$has_price = self::number( $it['unit_price'] ?? 0 ) > 0;
			if ( $has_text || $has_price ) {
				$out[] = $it;
			}
		}
		return $out;
	}

	/** Ítems canónicos → formato `Quotes::normalize_items` (label/qty/unit/amount). */
	private static function items_for_quotes( array $items ): array
	{
		$out = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$out[] = array(
				'label'  => (string) ( $it['description'] ?? '' ),
				'qty'    => self::number( $it['qty'] ?? 1 ),
				'unit'   => (string) ( $it['unit'] ?? '' ),
				'amount' => self::number( $it['unit_price'] ?? 0 ),
			);
		}
		return $out;
	}

	// ── Modelo plano y soporte JS ───────────────────────────────────────────

	/** Modelo plano key→value del documento (para pre-cargar `window.esqModel`). */
	public static function model_from_doc( array $doc ): array
	{
		$model = array();
		if ( isset( $doc['profile'] ) && is_scalar( $doc['profile'] ) ) {
			$model['profile'] = (string) $doc['profile'];
		}
		foreach ( array_keys( self::TEXT_GROUPS ) as $group ) {
			if ( isset( $doc[ $group ] ) && is_array( $doc[ $group ] ) ) {
				foreach ( $doc[ $group ] as $k => $v ) {
					if ( is_scalar( $v ) || null === $v ) {
						$model[ $group . '.' . $k ] = (string) $v;
					}
				}
			}
		}
		if ( isset( $doc['totals'] ) && is_array( $doc['totals'] ) ) {
			foreach ( $doc['totals'] as $k => $v ) {
				if ( is_scalar( $v ) || null === $v ) {
					$model[ 'totals.' . $k ] = (string) $v;
				}
			}
		}
		if ( isset( $doc['items'] ) && is_array( $doc['items'] ) ) {
			foreach ( $doc['items'] as $i => $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				foreach ( $it as $k => $v ) {
					if ( is_scalar( $v ) || null === $v ) {
						$model[ 'items.' . (int) $i . '.' . $k ] = (string) $v;
					}
				}
			}
		}

		$conditions = $doc['conditions'] ?? array();
		if ( is_array( $conditions ) ) {
			$model['conditions.list'] = implode( "\n", array_map( 'strval', $conditions ) );
		} elseif ( is_string( $conditions ) ) {
			$model['conditions.list'] = $conditions;
		}
		$model['notes'] = isset( $doc['notes'] ) ? (string) $doc['notes'] : '';

		return $model;
	}

	/** Opciones de `select` por campo (deriva de la forma del catálogo). */
	private static function select_options(): array
	{
		return array( 'meta.currency' => array( 'CLP', 'UF' ) );
	}

	/** True si el array es un documento anidado (grupos como arrays). */
	private static function is_nested( array $data ): bool
	{
		foreach ( array( 'meta', 'client', 'items', 'totals' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Aplana un documento anidado a key→value (dotted). */
	private static function flatten_generic( array $data, string $prefix = '' ): array
	{
		$out = array();
		foreach ( $data as $k => $v ) {
			$key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) ) {
				$out = array_merge( $out, self::flatten_generic( $v, $key ) );
			} elseif ( is_scalar( $v ) || null === $v ) {
				$out[ $key ] = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
			}
		}
		return $out;
	}

	/** Normaliza un modelo plano recibido (solo escalares). */
	private static function flatten_shallow( array $data ): array
	{
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$out[ (string) $k ] = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
			}
		}
		return $out;
	}

	/** Grupo canónico desde claves `grupo.campo` del modelo plano. */
	private static function group_from_flat( array $flat, string $group ): array
	{
		$out    = array();
		$prefix = $group . '.';
		foreach ( $flat as $key => $value ) {
			if ( 0 !== strpos( (string) $key, $prefix ) ) {
				continue;
			}
			$field = substr( (string) $key, strlen( $prefix ) );
			if ( '' !== $field && false === strpos( $field, '.' ) ) {
				$out[ $field ] = self::val( $group, $field, $value );
			}
		}
		return $out;
	}

	/** Valor saneado según el tipo declarado del campo (si el catálogo existe). */
	private static function val( string $group, string $field, $value )
	{
		$type = 'text';
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Document\\Fields' ) ) {
			$all = \Estandar\Flow\Quotes\Document\Fields::all();
			$key = $group . '.' . $field;
			$type = (string) ( $all[ $key ]['type'] ?? 'text' );
		}
		if ( 'number' === $type ) {
			return self::number( $value );
		}
		if ( 'textarea' === $type ) {
			return self::textarea( $value );
		}
		return self::text( $value );
	}

	/** Condiciones: desde `conditions.list`, `conditions.N` o lista anidada. */
	private static function conditions_from( array $data, array $flat ): array
	{
		$raw = $flat['conditions.list'] ?? null;
		if ( null === $raw && isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
			$raw = $data['conditions'];
		}
		if ( null === $raw ) {
			$collected = array();
			foreach ( $flat as $key => $value ) {
				if ( preg_match( '/^conditions\.(\d+)$/', (string) $key, $m ) ) {
					$collected[ (int) $m[1] ] = $value;
				}
			}
			ksort( $collected );
			$raw = array_values( $collected );
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = preg_split( "/\r\n|\n|\r/", $raw );
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $c ) {
			if ( is_array( $c ) ) {
				$c = implode( ' ', array_map( 'strval', $c ) );
			}
			$c = trim( (string) $c );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	// ── Saneado y helpers PURE ──────────────────────────────────────────────

	private static function text( $v ): string
	{
		if ( is_array( $v ) || is_object( $v ) ) {
			return '';
		}
		$v = self::unslash( (string) $v );
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( strip_tags( $v ) );
	}

	private static function textarea( $v ): string
	{
		if ( is_array( $v ) || is_object( $v ) ) {
			return '';
		}
		$v = self::unslash( (string) $v );
		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $v ) : trim( $v );
	}

	private static function key( $v ): string
	{
		$v = self::text( $v );
		return function_exists( 'sanitize_key' ) ? sanitize_key( $v ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) );
	}

	private static function unslash( string $v ): string
	{
		return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $v ) : $v;
	}

	/** Clave de perfil tolerante: conserva `@` (p. ej. `real_estate@1`). */
	private static function profile_key( $v ): string
	{
		if ( is_array( $v ) || is_object( $v ) ) {
			return '';
		}
		$v = self::unslash( (string) $v );
		$v = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( strip_tags( $v ) );
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9@._\-]/', '', $v ) );
	}

	/** Perfil activo de Settings (o `generic@1` sin el módulo cargado). */
	private static function active_profile(): string
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			$profile = (string) \Estandar\Flow\Quotes\Settings::active_profile();
			if ( '' !== $profile ) {
				return $profile;
			}
		}
		return 'generic@1';
	}

	/** Número tolerante: acepta 1.234,56 / $ 1.234.567 / UF 3,50 / 1234.56. */
	public static function to_number( $v ): float
	{
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$s = trim( (string) $v );
		if ( '' === $s ) {
			return 0.0;
		}
		$s = (string) preg_replace( '/[^0-9,.\-]/', '', $s );
		if ( '' === $s || '-' === $s ) {
			return 0.0;
		}

		$dot   = strrpos( $s, '.' );
		$comma = strrpos( $s, ',' );
		if ( false !== $dot && false !== $comma ) {
			if ( $comma > $dot ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $comma ) {
			$s = str_replace( ',', '.', $s );
		} elseif ( false !== $dot ) {
			$parts = explode( '.', $s );
			if ( count( $parts ) > 2 || ( 2 === count( $parts ) && strlen( $parts[1] ) > 2 ) ) {
				$s = str_replace( '.', '', $s );
			}
		}
		return (float) $s;
	}

	private static function number( $v ): float
	{
		return self::to_number( $v );
	}

	/** IVA por defecto desde Settings (o 19). */
	private static function settings_vat(): float
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			$all = \Estandar\Flow\Quotes\Settings::all();
			if ( isset( $all['vat'] ) ) {
				return self::to_number( $all['vat'] );
			}
		}
		return 19.0;
	}

	/** `absint` con fallback (entornos sin WP). */
	private static function absint( $v ): int
	{
		return function_exists( 'absint' ) ? absint( $v ) : abs( (int) $v );
	}

	/** `esc_textarea` con fallback (entornos sin WP). */
	private static function esc_textarea( string $v ): string
	{
		return function_exists( 'esc_textarea' ) ? esc_textarea( $v ) : esc_html( $v );
	}

	private static function json_encode( array $data ): string
	{
		$flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $data, $flags );
			return is_string( $json ) ? $json : '';
		}
		$json = json_encode( $data, $flags );
		return is_string( $json ) ? $json : '';
	}

	// ── Presentación ────────────────────────────────────────────────────────

	private static function notice( string $notice, string $type ): string
	{
		if ( '' === $notice ) {
			return '';
		}
		$bg    = 'ok' === $type ? '#e7f6ec' : ( 'error' === $type ? '#fcf0f1' : '#f0f6fc' );
		$color = 'ok' === $type ? '#01651f' : ( 'error' === $type ? '#b32d2e' : '#2271b1' );
		return '<div class="notice esq-no-print" style="background:' . $bg . ';color:' . $color . ';">' . esc_html( $notice ) . '</div>';
	}

	private static function quotes_list( array $quotes, string $action, string $nonce, int $lead_id ): string
	{
		if ( empty( $quotes ) ) {
			return '';
		}
		$html = '<div class="card esq-no-print"><h3>Cotizaciones de este lead</h3>';
		foreach ( $quotes as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$total = class_exists( 'Estandar\\Flow\\Quotes' )
				? \Estandar\Flow\Quotes::format_amount( \Estandar\Flow\Quotes::total( $q ), (string) ( $q['currency'] ?? 'CLP' ) )
				: '';
			$label = (string) ( ( $q['title'] ?? '' ) ?: ( 'COT-' . (int) ( $q['id'] ?? 0 ) ) );
			$html .= '<div class="row"><div><strong>' . esc_html( $label ) . '</strong><br>'
				. '<span class="muted">' . esc_html( $total ) . ' · ' . esc_html( (string) ( $q['status'] ?? '' ) ) . '</span></div>'
				. '<form method="post" action="' . esc_url( $action ) . '">'
				. '<input type="hidden" name="lp_action" value="quote_send">'
				. '<input type="hidden" name="quote_id" value="' . (int) ( $q['id'] ?? 0 ) . '">'
				. '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
				. '<button class="btn sm" type="submit">' . ( (string) ( $q['status'] ?? '' ) === 'sent' ? 'Reenviar' : 'Enviar' ) . '</button>'
				. '</form></div>';
		}
		return $html . '</div>';
	}

	/** CSS de la página del editor (mismo estilo de tarjetas que LeadsPanel). */
	private static function page_css(): string
	{
		return 'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;padding:16px;}'
			. '.wrap{max-width:960px;margin:0 auto;}'
			. '.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:14px;}'
			. '.card h3{margin:0 0 10px;font-size:14px;}'
			. '.esq-title{font-size:18px;margin:6px 0 12px;}'
			. 'label{display:block;font-size:12px;color:#646970;margin:12px 0 4px;}'
			. 'input,select,textarea{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;}'
			. 'textarea{min-height:64px;}'
			. '.btn{margin-top:14px;background:#f95b1f;color:#fff;border:none;padding:11px 18px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;}'
			. '.btn.sm{width:auto;padding:6px 14px;font-size:13px;margin:0;background:#2271b1;}'
			. '.btn.sub{background:#fff;color:#2271b1;border:1px solid #c5d9ed;}'
			. '.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}'
			. '.row{display:flex;justify-content:space-between;gap:10px;align-items:center;border-bottom:1px dashed #f0f0f1;padding:8px 0;}'
			. '.muted{color:#8c8f94;font-size:12px;}'
			. '.notice{border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;}'
			. '.esq-canvas{width:100%;overflow-x:auto;background:#fff;padding:6px;border:1px dashed #dcdcde;border-radius:8px;}'
			. '.esq-input{margin:1px 0;}'
			. '.esq-input-number{max-width:160px;}'
			. '.esq-add-line{margin:8px 0 0 2px;font-size:12px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;padding:5px 10px;cursor:pointer;}'
			. '.esq-remove-line{margin-left:6px;font-size:11px;line-height:1;background:#fcf0f1;border:1px solid #f0c8c8;color:#b32d2e;border-radius:4px;padding:2px 6px;cursor:pointer;}';
	}

	/** CSS de impresión: solo el documento. */
	private static function print_css(): string
	{
		return '@media print{body{background:#fff;padding:0;}.esq-no-print,.esq-no-print-border{border:none!important;}'
			. '.esq-no-print{display:none!important;}.card{border:none;padding:0;margin:0;}'
			. '.esq-canvas{border:none;padding:0;overflow:visible;}}';
	}

	/** CSS del canvas editable (dentro del documento). */
	private static function style(): string
	{
		return '<style>.esq-canvas .esq-input{font:inherit;color:inherit;padding:1px 4px;border:1px solid #c3c4c7;border-radius:4px;background:#fffdf5;}'
			. '.esq-canvas textarea.esq-input{min-height:auto;width:100%;}</style>';
	}

	/** JS vanilla namespaceado (sin librerías, sin eval, sin fetch externos). */
	private static function script( array $model, array $field_options = array(), string $other_project_url = '' ): string
	{
		$data = self::json_encode( array(
			'model'           => $model,
			'selects'         => array_merge( self::select_options(), $field_options ),
			'fieldOptions'    => $field_options,
			'exact'           => self::READONLY_KEYS,
			'otherProjectUrl' => $other_project_url,
			'otherProjectKey' => self::PROJECT_FIELD,
			'otherProjectVal' => self::OTHER_PROJECT_VALUE,
		) );

		return '<script>window.esqData=' . $data . ';' . self::js() . '</script>';
	}

	private static function js(): string
	{
		return <<<'JS'
(function(){
"use strict";
if(window.__esqCanvasReady){return;}
window.__esqCanvasReady=true;

var data=window.esqData||{};
var model=data.model||{};
window.esqModel=model;
var selects=data.selects||{};
var fieldOptions=data.fieldOptions||{};
var readonlyExact=data.exact||[];
var pairs=[];
function goOtherProject(){
	var url=data.otherProjectUrl||'';
	if(url&&url!=='#'){window.location.href=url;}
}
function canvasEl(){return document.querySelector('.esq-canvas');}
function isReadonly(key){
	if(readonlyExact.indexOf(key)!==-1){return true;}
	return /\.line_total$/.test(key);
}
function valOf(key,span){
	if(Object.prototype.hasOwnProperty.call(model,key)){return model[key];}
	var t=(span.textContent||'').replace(/^\s+|\s+$/g,'');
	model[key]=t;
	return t;
}
function normDate(v){
	var m=/^(\d{4})-(\d{2})-(\d{2})/.exec(String(v||''));
	return m?m[1]+'-'+m[2]+'-'+m[3]:'';
}
function hasOptions(key){return !!fieldOptions[key]&&fieldOptions[key].length>0;}
function makeControl(key,type,span){
	var v=valOf(key,span),el,i,o;
	if(type==='textarea'){
		el=document.createElement('textarea');el.rows=2;el.value=String(v==null?'':v);
	}else if(type==='select'||hasOptions(key)){
		el=document.createElement('select');
		var isProject=(key===data.otherProjectKey);
		if(!isProject){
			o=document.createElement('option');o.value='';o.textContent='—';
			if(String(v)==null||String(v)===''){o.selected=true;}
			el.appendChild(o);
		}
		var opts=selects[key]||[];
		if(!opts.length&&v!==''){opts=[String(v)];}
		for(i=0;i<opts.length;i++){
			o=document.createElement('option');o.value=opts[i];o.textContent=opts[i];
			if(String(v)===String(opts[i])){o.selected=true;}
			el.appendChild(o);
		}
		if(String(v)!=='' && !inArray(opts,String(v))){
			o=document.createElement('option');o.value=String(v);o.textContent=String(v);o.selected=true;el.appendChild(o);
		}
		if(isProject){
			o=document.createElement('option');o.value=data.otherProjectVal;o.textContent='Otro proyecto…';
			el.appendChild(o);
		}
	}else if(type==='number'){
		el=document.createElement('input');el.type='number';el.step='any';el.value=String(v==null?'':v);
	}else if(type==='date'){
		el=document.createElement('input');el.type='date';el.value=normDate(v);
	}else{
		el=document.createElement('input');el.type='text';el.value=String(v==null?'':v);
	}
	el.className='esq-input esq-input-'+type;
	el.setAttribute('data-esq-key',key);
	bindControl(el);
	return el;
}
function inArray(arr,value){
	for(var i=0;i<arr.length;i++){if(String(arr[i])===String(value)){return true;}}
	return false;
}
function bindControl(el){
	if(el.getAttribute('data-esq-bound')==='1'){return;}
	el.setAttribute('data-esq-bound','1');
	var sync=function(){
		var key=el.getAttribute('data-esq-key');
		model[key]=el.value;
		if(el.tagName==='SELECT'&&key===data.otherProjectKey&&el.value===data.otherProjectVal){goOtherProject();}
	};
	el.addEventListener('input',sync);
	el.addEventListener('change',sync);
}
function initCanvas(){
	var canvas=canvasEl();
	if(!canvas){return;}
	var spans=canvas.querySelectorAll('[data-qfield]');
	for(var i=0;i<spans.length;i++){
		var span=spans[i];
		if(span.tagName!=='SPAN'){continue;}
		var key=span.getAttribute('data-qfield');
		if(!key||span.getAttribute('data-esq-done')==='1'){continue;}
		span.setAttribute('data-esq-done','1');
		if(isReadonly(key)){continue;}
		if(span.getAttribute('data-esq-preloaded')==='1'){
			var pre=span.nextElementSibling;
			if(pre&&(pre.tagName==='SELECT'||pre.tagName==='INPUT'||pre.tagName==='TEXTAREA')){
				bindControl(pre);
				pre.style.display='';
				span.style.display='none';
				pairs.push({span:span,ctrl:pre});
			}
			continue;
		}
		var type=span.getAttribute('data-qtype')||'text';
		var ctrl=makeControl(key,type,span);
		span.parentNode.insertBefore(ctrl,span.nextSibling);
		span.style.display='none';
		pairs.push({span:span,ctrl:ctrl});
	}
	setupItems(canvas);
}
function setPreview(on){
	for(var i=0;i<pairs.length;i++){
		var p=pairs[i];
		if(on){
			p.span.textContent=p.ctrl.value;
			p.span.style.display='';
			p.ctrl.style.display='none';
		}else{
			p.ctrl.value=model[p.ctrl.getAttribute('data-esq-key')]!=null?model[p.ctrl.getAttribute('data-esq-key')]:p.span.textContent;
			p.span.style.display='none';
			p.ctrl.style.display='';
		}
	}
}
function hiddenInput(){
	var canvas=canvasEl();
	var form=canvas?canvas.closest('form'):null;
	if(!form){return null;}
	var input=form.querySelector('input[name="document_json"]');
	if(!input){
		input=document.createElement('input');input.type='hidden';input.name='document_json';form.appendChild(input);
	}
	return input;
}
function serialize(){
	var input=hiddenInput();
	if(input){input.value=JSON.stringify(model);}
}
function itemRows(canvas){
	var trs=canvas.querySelectorAll('tr'),out=[];
	for(var i=0;i<trs.length;i++){
		if(trs[i].querySelector('[data-esq-key^="items."],[data-qfield^="items."]')){out.push(trs[i]);}
	}
	return out;
}
function renumber(canvas){
	var rows=itemRows(canvas),fresh={},k;
	for(k in model){if(k.indexOf('items.')!==0){fresh[k]=model[k];}}
	for(var r=0;r<rows.length;r++){
		var ctrls=rows[r].querySelectorAll('[data-esq-key^="items."]');
		for(var c=0;c<ctrls.length;c++){
			var oldKey=ctrls[c].getAttribute('data-esq-key');
			var field=oldKey.split('.').pop();
			var newKey='items.'+r+'.'+field;
			ctrls[c].setAttribute('data-esq-key',newKey);
			fresh[newKey]=ctrls[c].value;
		}
		var spans=rows[r].querySelectorAll('[data-qfield^="items."]');
		for(var s=0;s<spans.length;s++){
			var sf=(spans[s].getAttribute('data-qfield')||'').split('.').pop();
			spans[s].setAttribute('data-qfield','items.'+r+'.'+sf);
		}
	}
	model=window.esqModel=fresh;
}
function clearRow(row){
	var ctrls=row.querySelectorAll('[data-esq-key]');
	for(var i=0;i<ctrls.length;i++){
		if(ctrls[i].tagName==='SELECT'){ctrls[i].selectedIndex=0;}else{ctrls[i].value='';}
	}
	var spans=row.querySelectorAll('[data-qfield]');
	for(var j=0;j<spans.length;j++){spans[j].textContent='';}
}
function addRemove(row){
	var cell=row.querySelector('td');
	if(!cell||cell.querySelector('.esq-remove-line')){return;}
	var btn=document.createElement('button');
	btn.type='button';btn.className='esq-remove-line esq-no-print';btn.textContent='×';
	btn.addEventListener('click',function(){
		var canvas=canvasEl();
		var rows=itemRows(canvas);
		if(rows.length<=1){clearRow(row);renumber(canvas);return;}
		if(row.parentNode){row.parentNode.removeChild(row);}
		renumber(canvas);
	});
	cell.appendChild(btn);
}
function setupItems(canvas){
	var rows=itemRows(canvas);
	if(!rows.length){return;}
	var table=rows[0].closest('table');
	if(!table){return;}
	var tbody=rows[0].parentNode;
	for(var r=0;r<rows.length;r++){addRemove(rows[r]);}
	if(table.getAttribute('data-esq-addbound')==='1'){return;}
	table.setAttribute('data-esq-addbound','1');
	var addBtn=document.createElement('button');
	addBtn.type='button';addBtn.className='esq-add-line esq-no-print';addBtn.textContent='+ Añadir ítem';
	addBtn.addEventListener('click',function(){
		var current=itemRows(canvas);
		var base=current[current.length-1];
		if(!base){return;}
		var clone=base.cloneNode(true);
		clearRow(clone);
		var rb=clone.querySelector('.esq-remove-line');
		if(rb&&rb.parentNode){rb.parentNode.removeChild(rb);}
		tbody.appendChild(clone);
		renumber(canvas);
		addRemove(clone);
	});
	if(table.parentNode){table.parentNode.insertBefore(addBtn,table.nextSibling);}
}
function bind(){
	var canvas=canvasEl();
	if(canvas){
		var form=canvas.closest('form');
		if(form&&form.getAttribute('data-esq-bound')!=='1'){
			form.setAttribute('data-esq-bound','1');
			form.addEventListener('submit',serialize);
		}
	}
	var prev=document.getElementById('esq-preview');
	if(prev&&prev.getAttribute('data-esq-bound')!=='1'){
		prev.setAttribute('data-esq-bound','1');
		prev.addEventListener('click',function(){
			var on=prev.getAttribute('data-on')!=='1';
			setPreview(on);
			prev.setAttribute('data-on',on?'1':'0');
		});
	}
	var pr=document.getElementById('esq-print');
	if(pr&&pr.getAttribute('data-esq-bound')!=='1'){
		pr.setAttribute('data-esq-bound','1');
		pr.addEventListener('click',function(){setPreview(true);window.print();});
	}
}
function boot(){initCanvas();bind();serialize();}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot);}else{boot();}
window.esqCanvas={serialize:serialize,setPreview:setPreview,model:function(){return model;}};
})();
JS;
	}
}
