<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OperatorGate — gate de operador para forms v2 (GATE_V1, 2026-09-01).
 *
 * Protege un form v2 detrás del MISMO motor de operadores que el panel de leads
 * (PIN 4 letras + magic link por correo), pero apuntando al destino propio de la
 * página que lo contiene. Dos accesos independientes e intercambiables:
 *   - Panel de Leads  → /panel-leads/ (LeadsPanel).
 *   - Form de cámara  → la página con [estandar_form_gate form="ID"].
 * Ambos comparten cookies/autorización de Estandar\Core\LeadsPanel: un operador
 * autenticado entra a cualquiera "indistintamente".
 *
 * Uso en una página:
 *   [estandar_form_gate form="12" title="Registro de Cámara"]
 * Atributos:
 *   form        (obligatorio) ID del form v2 a proteger.
 *   title       Título del login (default "Acceso de operador").
 *   mail_label  Etiqueta del destino en el correo magic (default "Registro de Cámara").
 *   submit_text Texto del botón (default "Registrar día", se usa el del form si vacío).
 */
class OperatorGate
{
	const SHORTCODE = 'estandar_form_gate';

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array  $atts    Atributos del shortcode.
	 * @param string $content Contenido interno (ignorado; el form se renderiza por id).
	 */
	public static function render( array $atts = array(), string $content = '' ): string
	{
		if ( ! class_exists( '\Estandar\Core\LeadsPanel' ) ) {
			return '<div style="color:#b32d2e;">El gate de operador requiere estandar-core.</div>';
		}
		$atts = shortcode_atts( array(
			'form'        => 0,
			'title'       => 'Acceso de operador',
			'mail_label'  => 'Registro de Cámara',
			'submit_text' => '',
			// GATE_PER_FORM_V1: 'inherit' (ajuste general) | 'both' | 'pin' | 'magic'.
			'methods'     => 'inherit',
		), (array) $atts, self::SHORTCODE );

		$form_id = absint( (int) $atts['form'] );
		if ( $form_id <= 0 ) {
			return '<div style="color:#b32d2e;">OperatorGate: falta el atributo form="ID".</div>';
		}
		$form = Forms::get( $form_id );
		if ( ! is_array( $form ) || (string) ( $form['status'] ?? '' ) !== 'active' ) {
			return '<div style="color:#b32d2e;">El form protegido no está disponible.</div>';
		}

		$LP = '\Estandar\Core\LeadsPanel';

		// URL de la página actual (sin magic/params que ensucien el redirect).
		$return_url = self::current_page_url();
		$action_url = $return_url;

		// Procesar POST de login / magic_request + ?magic= (sin emitir salida).
		$gate_key = 'es_og';
		$result   = $LP::process_gate_post( $action_url, $return_url, $gate_key, array(
			'label'  => (string) $atts['mail_label'],
			'subject' => 'Acceso al ' . (string) $atts['mail_label'] . ' — ' . get_bloginfo( 'name' ),
			'button'  => 'Abrir ' . (string) $atts['mail_label'],
			'methods' => (string) $atts['methods'],
		) );
		if ( ! empty( $result['redirect'] ) ) {
			nocache_headers();
			wp_safe_redirect( $result['redirect'] );
			exit;
		}

		// Autorizado → renderizar el form v2.
		if ( $LP::authorized() ) {
			$submit = (string) $atts['submit_text'];
			if ( $submit !== '' ) {
				$cfg = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
				if ( is_array( $cfg ) ) {
					$cfg['submit_label'] = $submit;
					$form['config_json'] = wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			}
			return FormRenderer::render_form( $form )
				. self::weekday_script();
		}

		// No autorizado → login embebido (PIN + magic link).
		$notice      = (string) ( $result['notice'] ?? '' );
		$notice_type = (string) ( $result['notice_type'] ?? 'info' );
		return $LP::render_gate_login( $action_url, $return_url, (string) $atts['title'], $notice, $notice_type, $gate_key, (string) $atts['methods'] );
	}

	/**
	 * Script de "día de la semana" (opcional, aditivo): si un campo html del
	 * form declara `data-es-weekday-source="<nombre_campo_fecha>"`, al cambiar
	 * ese input de fecha se escribe el día de la semana en el elemento. No
	 * depende de jQuery y no pisa otros JS del form.
	 */
	private static function weekday_script(): string
	{
		return '<script>'
			. '(function(){'
			. 'var dias=["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"];'
			. 'document.querySelectorAll("form.estandar-form-v2").forEach(function(form){'
			. 'var out=form.querySelector("[data-es-weekday-source]");'
			. 'if(!out){return;}'
			. 'var f=form.querySelector("input[name=\'"+out.getAttribute("data-es-weekday-source")+"\']");'
			. 'if(!f){return;}'
			. 'function upd(){var v=f.value;if(!v){out.textContent="—";return;}var d=new Date(v+"T12:00:00");out.textContent=isNaN(d.getTime())?"—":(dias[d.getDay()]||"");}'
			. 'f.addEventListener("change",upd);f.addEventListener("input",upd);upd();'
			. '});'
			. '})();'
			. '</script>';
	}

	/** URL canónica de la página actual (home + path), sin query. */
	public static function current_page_url(): string
	{
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( $path === '' ) {
			$path = '/';
		}
		$url = home_url( $path );
		return esc_url_raw( $url );
	}
}
