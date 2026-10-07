<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embed — proveedor único de códigos de inserción de la Suite.
 *
 * Cualquier objeto embebible (formularios hoy; Surveys/Popups/Chatbots/Flows en
 * el futuro) obtiene su shortcode canónico desde aquí. La UI de administración
 * usa copy_control() para mostrar un shortcode copiable con feedback.
 *
 * No duplica lógica de render: solo construye el código de inserción y el
 * control de copia reutilizable.
 */
class Embed
{
	/** Tag canónico por tipo de objeto embebible. */
	public static function tag_for( string $object ): string
	{
		$tags = array(
			'form'   => 'estandar_form_v2',
			'survey' => 'estandar_form_v2', // reutilizable: surveys usan el renderer v2
			// 'popup'   => 'estandar_pop_v2',   // backlog
			// 'chatbot' => 'estandar_agent',    // backlog (Brain ya usa estandar_agent)
			// 'flow'    => 'estandar_flow_v2',  // backlog
		);
		return $tags[ $object ] ?? '';
	}

	/** Shortcode canónico: [<tag> id="<ID>"] (vacío si el objeto no es embebible). */
	public static function shortcode( string $object, int $id ): string
	{
		$tag = self::tag_for( $object );
		if ( $tag === '' || $id <= 0 ) {
			return '';
		}
		return sprintf( '[%s id="%d"]', $tag, $id );
	}

	/**
	 * Control de inserción reutilizable (código + botón copiar + feedback).
	 * $text vacío → mensaje "guarda para generar el shortcode".
	 */
	public static function copy_control( string $text ): string
	{
		if ( $text === '' ) {
			return '<span class="description">Guarda el formulario para generar el shortcode.</span>';
		}
		$uid = 'es-embed-' . substr( md5( $text ), 0, 10 );
		return '<span id="' . esc_attr( $uid ) . '" class="es-embed-copy" style="white-space:nowrap;">'
			. '<code class="es-embed-code">' . esc_html( $text ) . '</code> '
			. '<button type="button" class="button button-small es-embed-copy-btn" data-copy="' . esc_attr( $text ) . '" '
			. 'title="Copiar shortcode" aria-label="Copiar shortcode" style="cursor:pointer;">📋</button> '
			. '<span class="es-embed-copied" style="display:none;color:#2271b1;">Shortcode copiado</span>'
			. '</span>';
	}

	/** Script compartido de copia (Clipboard API con fallback execCommand). Imprimir una sola vez. */
	public static function copy_script(): string
	{
		static $printed = false;
		if ( $printed ) {
			return '';
		}
		$printed = true;
		return '<script>(function(){function copyText(t,btn){var done=function(){var fb=btn&&btn.parentElement?btn.parentElement.querySelector(".es-embed-copied"):null;if(fb){fb.style.display="inline";setTimeout(function(){fb.style.display="none";},1600);}};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done).catch(function(){fallback();});}else{fallback();}function fallback(){var ta=document.createElement("textarea");ta.value=t;ta.style.position="fixed";ta.style.opacity="0";document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(ta);done();}}
document.addEventListener("click",function(e){var btn=e.target.closest(".es-embed-copy-btn");if(btn){e.preventDefault();copyText(btn.getAttribute("data-copy")||"",btn);}});})();</script>';
	}
}
