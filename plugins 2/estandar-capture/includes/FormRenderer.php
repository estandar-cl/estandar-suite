<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormRenderer — render frontend mínimo de formularios nativos v2 (solo Forms).
 *
 * Corrige el defecto descubierto en la validación de "Contacto General"
 * (acacios.cl): los formularios migrados no tenían shortcode de render.
 * Alcance mínimo: formularios nativos v2 tipo 'lead' (NO surveys/postventa).
 *
 * Shortcode: [estandar_form_v2 id="<v2-form-id>"]
 *   - Render de campos (text, email, textarea, select, tel, number, date,
 *     radio, checkbox); tipos avanzados se degradan a text con nota.
 *   - Envío self-POST con nonce + honeypot anti-spam.
 *   - Persistencia vía Ingestion::ingest (estandar_capture_submissions) y
 *     confirmación desde success_message del formulario.
 *   - No realiza llamadas externas (reCAPTCHA real queda en backlog).
 */
class FormRenderer
{
	const SHORTCODE = 'estandar_form_v2';

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ): string
	{
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, self::SHORTCODE );
		$id   = (int) $atts['id'];
		$form = Forms::get( $id );
		if ( ! $form || (string) $form['form_type'] !== 'lead' ) {
			return '';
		}

		$config = json_decode( (string) $form['config_json'], true );
		$config = is_array( $config ) ? $config : array();
		$fields = Forms::renderable_fields( $config );
		$policy = Forms::repeat_visibility( $config );
		$reason_enabled = ! empty( $config['repeat_reason']['enabled'] );

		// ── 1) Procesar el MOTIVO de recontacto (segunda respuesta) ─────────
		if ( isset( $_POST[ self::SHORTCODE . '_reason' ] )
			&& isset( $_POST[ self::SHORTCODE . '_submission' ] )
			&& (int) ( $_POST[ self::SHORTCODE . '_id' ] ?? 0 ) === $id
			&& wp_verify_nonce( (string) ( $_POST[ self::SHORTCODE . '_nonce' ] ?? '' ), self::SHORTCODE . '_' . $id )
		) {
			$sid  = (int) $_POST[ self::SHORTCODE . '_submission' ];
			$code = sanitize_key( (string) $_POST[ self::SHORTCODE . '_reason' ] );
			$note = sanitize_textarea_field( (string) ( $_POST[ self::SHORTCODE . '_reason_note' ] ?? '' ) );
			$rr   = Forms::record_repeat_reason( $sid, $code, $note, $config );
			$ok   = ! empty( $rr['ok'] );
			$msg  = $ok
				? 'Gracias, tu motivo fue registrado. Un responsable te atenderá pronto.'
				: ( $rr['error'] === 'ya_registrado' ? 'Tu motivo ya fue registrado.' : 'No se pudo registrar el motivo.' );
			return '<div class="estandar-form-v2 estandar-form-v2-done" style="padding:18px;border:1px solid '
				. ( $ok ? '#d6f0d2;background:#f2fbf1' : '#f0d6d6;background:#fbf1f1' ) . ';border-radius:8px;">'
				. esc_html( $msg ) . '</div>';
		}

		// ── 2) Procesar envío principal self-POST ─────────────────────────
		if ( isset( $_POST[ self::SHORTCODE . '_id' ] ) && (int) $_POST[ self::SHORTCODE . '_id' ] === $id ) {
			$sid = self::handle_submit( $id, $config, $fields, $_POST );
			if ( $sid > 0 ) {
				$msg = (string) ( $config['success_message'] ?? '' );
				if ( $msg === '' ) {
					$msg = 'Gracias, tu mensaje fue enviado correctamente.';
				}
				$html  = '<div class="estandar-form-v2 estandar-form-v2-done" style="padding:18px;border:1px solid #d6f0d2;background:#f2fbf1;border-radius:8px;">'
					. esc_html( $msg ) . '</div>';
				if ( $reason_enabled ) {
					$html .= self::reason_panel( $id, $sid );
				}
				if ( $policy['enabled'] ) {
					$html .= self::hide_timer_script( $id, $policy );
				}
				return $html;
			}
		}

		$submit = (string) ( $config['submit_label'] ?? 'Enviar' );
		if ( $submit === '' ) {
			$submit = 'Enviar';
		}

		$html  = '<form class="estandar-form-v2" method="post" action="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '" novalidate>';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_id' ) . '" value="' . (int) $id . '">';
		$html .= wp_nonce_field( self::SHORTCODE . '_' . $id, self::SHORTCODE . '_nonce', true, false );
		// Honeypot anti-spam (oculto al usuario).
		$html .= '<p style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
			. '<label>No llenar <input type="text" name="' . esc_attr( self::SHORTCODE . '_hp' ) . '" value="" tabindex="-1" autocomplete="off"></label></p>';

		foreach ( $fields as $f ) {
			$html .= self::render_field( $f );
		}

		$html .= '<div style="margin-top:14px;"><button type="submit" class="estandar-form-v2-submit" style="padding:10px 22px;cursor:pointer;">'
			. esc_html( $submit ) . '</button></div>';
		$html .= '</form>';

		if ( $policy['enabled'] ) {
			$html .= self::hide_script( $id, $policy );
		}

		return $html;
	}

	/** Script client-side de ocultamiento temporal (por navegador, no por identidad). */
	private static function hide_script( int $id, array $policy ): string
	{
		$secs = Forms::repeat_visibility_seconds( $policy );
		$msg  = $policy['message'];
		$json = wp_json_encode( array( 'id' => $id, 'secs' => $secs, 'msg' => $msg ), JSON_UNESCAPED_UNICODE );
		return '<script>(function(){var c=' . $json . ';var k="es_fv2_hidden_"+c.id;var until=0;try{until=parseInt(localStorage.getItem(k)||"0",10)||0;}catch(e){}if(until>Date.now()){var f=document.querySelector(".estandar-form-v2");if(f){var d=document.createElement("div");d.className="estandar-form-v2 estandar-form-v2-done";d.style.cssText="padding:18px;border:1px solid #d6f0d2;background:#f2fbf1;border-radius:8px;";d.textContent=c.msg;f.parentNode.replaceChild(d,f);}}})();</script>';
	}

	/** Almacena el timer de ocultamiento tras una respuesta (mismo navegador). */
	private static function hide_timer_script( int $id, array $policy ): string
	{
		$secs = $policy['enabled'] ? Forms::repeat_visibility_seconds( $policy ) : 0;
		$json = wp_json_encode( array( 'id' => $id, 'secs' => $secs ), JSON_UNESCAPED_UNICODE );
		return '<script>(function(){var c=' . $json . ';try{if(c.secs>0){localStorage.setItem("es_fv2_hidden_"+c.id, String(Date.now()+c.secs*1000));}}catch(e){}})();</script>';
	}

	/** Panel de motivos de recontacto tras un envío aceptado. */
	private static function reason_panel( int $id, int $capture_id ): string
	{
		$reasons = Forms::repeat_reasons();
		$html  = '<form class="estandar-form-v2-reason" method="post" action="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '" style="margin-top:18px;padding:14px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_id' ) . '" value="' . (int) $id . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SHORTCODE . '_submission' ) . '" value="' . (int) $capture_id . '">';
		$html .= wp_nonce_field( self::SHORTCODE . '_' . $id, self::SHORTCODE . '_nonce', true, false );
		$html .= '<h4 style="margin:0 0 8px;">¿Por qué vuelves a contactar?</h4>';
		foreach ( $reasons as $code => $label ) {
			$html .= '<label style="display:block;margin:4px 0;"><input type="radio" name="' . esc_attr( self::SHORTCODE . '_reason' ) . '" value="' . esc_attr( $code ) . '"> '
				. esc_html( $label ) . '</label>';
		}
		$html .= '<p style="margin:8px 0 0;"><label>Detalle (opcional)<br><textarea name="' . esc_attr( self::SHORTCODE . '_reason_note' ) . '" rows="2" style="width:100%;"></textarea></label></p>';
		$html .= '<p style="margin:8px 0 0;"><button type="submit" class="button estandar-form-v2-reason-submit">Enviar motivo</button></p>';
		$html .= '</form>';
		return $html;
	}

	/** Render de un campo según su tipo. */
	private static function render_field( array $f ): string
	{
		$name     = sanitize_key( (string) ( $f['name'] ?? '' ) );
		$label    = (string) ( $f['label'] ?? '' );
		$type     = strtolower( (string) ( $f['type'] ?? 'text' ) );
		$required = ! empty( $f['required'] ) ? ' required' : '';
		$width    = isset( $f['column_width'] ) ? (int) $f['column_width'] : 3;
		$col_style = $width > 0 ? ' style="box-sizing:border-box;width:' . round( 100 * min( 1, $width / 4 ) ) . '%;float:left;padding:0 6px;margin-bottom:10px;"' : '';

		if ( $name === '' ) {
			return '';
		}
		$label_html = $label !== '' ? '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . ( $required ? ' <span style="color:#c00;">*</span>' : '' ) . '</label>' : '';

		$id_attr = 'id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"';

		if ( $type === 'textarea' ) {
			return '<div' . $col_style . '>' . $label_html . '<textarea ' . $id_attr . ' rows="4" style="width:100%;"' . $required . '></textarea></div>';
		}

		if ( $type === 'select' ) {
			$opts = is_array( $f['options'] ?? null ) ? $f['options'] : array();
			$sel  = '<select ' . $id_attr . ' style="width:100%;"' . $required . '><option value="">Selecciona…</option>';
			foreach ( $opts as $o ) {
				$val  = (string) ( is_array( $o ) ? ( $o['value'] ?? '' ) : $o );
				$lbl  = (string) ( is_array( $o ) ? ( $o['label'] ?? $val ) : $o );
				$sel .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $lbl ) . '</option>';
			}
			$sel .= '</select>';
			return '<div' . $col_style . '>' . $label_html . $sel . '</div>';
		}

		if ( $type === 'radio' ) {
			$opts = is_array( $f['options'] ?? null ) ? $f['options'] : array();
			$out  = '<div' . $col_style . '>' . $label_html;
			foreach ( $opts as $o ) {
				$val = (string) ( is_array( $o ) ? ( $o['value'] ?? '' ) : $o );
				$out .= '<label style="display:block;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '"' . $required . '> ' . esc_html( $val ) . '</label>';
			}
			return $out . '</div>';
		}

		if ( $type === 'checkbox' ) {
			return '<div' . $col_style . '>' . $label_html . '<label style="display:block;"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $required . '> ' . esc_html( $label !== '' ? $label : $name ) . '</label></div>';
		}

		// Tipos avanzados/desconocidos degradan a text con nota.
		if ( in_array( $type, array( 'file', 'matrix', 'custom', 'cards', 'emoji', 'rating', 'nps' ), true ) ) {
			return '<div' . $col_style . '>' . $label_html . '<input type="text" ' . $id_attr . ' style="width:100%;"' . $required . '><small>Campo "' . esc_html( $type ) . '" (render básico)</small></div>';
		}

		if ( ! in_array( $type, array( 'text', 'email', 'tel', 'phone', 'number', 'date', 'rut' ), true ) ) {
			$type = 'text';
		}
		if ( $type === 'phone' ) {
			$type = 'tel';
		}
		return '<div' . $col_style . '>' . $label_html . '<input type="' . esc_attr( $type ) . '" ' . $id_attr . ' style="width:100%;"' . $required . '></div>';
	}

	/** Procesa el envío: nonce + honeypot + persistencia. @return int id de capture (0 si no se aceptó). */
	private static function handle_submit( int $form_id, array $config, array $fields, array $post ): int
	{
		if ( ! isset( $post[ self::SHORTCODE . '_nonce' ] ) || ! wp_verify_nonce( (string) $post[ self::SHORTCODE . '_nonce' ], self::SHORTCODE . '_' . $form_id ) ) {
			return 0;
		}
		// Honeypot: si el campo oculto viene lleno, es spam → se descarta en silencio.
		$hp = (string) ( $post[ self::SHORTCODE . '_hp' ] ?? '' );
		if ( $hp !== '' ) {
			return 0;
		}

		$posted = array();
		$contact = array( 'name' => '', 'email' => '', 'phone' => '' );
		$message = '';
		foreach ( $fields as $f ) {
			$fname = sanitize_key( (string) ( $f['name'] ?? '' ) );
			$ftype = strtolower( (string) ( $f['type'] ?? 'text' ) );
			$val   = sanitize_text_field( (string) ( $post[ $fname ] ?? '' ) );
			if ( $fname === '' ) {
				continue;
			}
			$posted[ $fname ] = $val;
			if ( $ftype === 'email' && $contact['email'] === '' ) {
				$contact['email'] = $val;
			} elseif ( in_array( $ftype, array( 'tel', 'phone' ), true ) && $contact['phone'] === '' ) {
				$contact['phone'] = $val;
			} elseif ( $contact['name'] === '' && in_array( $fname, array( 'nombre', 'name', 'full_name' ), true ) ) {
				$contact['name'] = $val;
			} elseif ( $ftype === 'textarea' && $message === '' ) {
				$message = $val;
			}
		}

		$capture = array(
			'source'   => 'form',
			'content'  => array( 'source_form_id' => (string) $form_id, 'message' => $message ),
			'contact'  => $contact,
			'fields'   => $posted,
			'attribution' => array(),
			'spam'     => array( 'honeypot_filled' => false ),
		);

		$result = Ingestion::ingest( $capture );
		if ( ! empty( $result['id'] ) ) {
			$capture['id'] = $result['id'];
			// Notificación + autorespuesta por Form (motor único Core\Mail; best-effort).
			Forms::notify_submission( $form_id, $config, $capture );
			Forms::autoresponse_submission( $form_id, $config, $capture );
		}
		return ! empty( $result['id'] ) ? (int) $result['id'] : 0;
	}
}
