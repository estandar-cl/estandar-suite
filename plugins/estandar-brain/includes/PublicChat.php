<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PublicChat — burbuja de chat reutilizable del agente (capa de presentación).
 *
 * Un solo componente (HTML+CSS+JS) para todas las superficies, apuntando al
 * mismo motor (Agent) con distinto perfil:
 *  - flotante público: shortcode [estandar_agent] → endpoint /agent-public.
 *  - flotante interno: burbuja en el backend (admin_footer) → endpoint /agent.
 *  - inline interno:  sub-tab Agente de Brain → endpoint /agent.
 *  - inline público:  sub-tab Chat de Capture → endpoint /agent-public.
 * La inteligencia no se duplica; solo se reutiliza esta vista.
 */
class PublicChat
{
	public static function init(): void
	{
		add_shortcode( 'estandar_agent', array( __CLASS__, 'render' ) );
		add_action( 'admin_footer', array( __CLASS__, 'admin_bubble' ), 20 );
	}

	/** Shortcode público (flotante, perfil público). */
	public static function render( array $atts = array() ): string
	{
		if ( ! Agent::activation( 'agent_public' ) ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'placeholder' => 'Escribe tu pregunta…',
			'welcome'     => 'Hola, soy el asistente del sitio. ¿En qué te ayudo?',
		), $atts, 'estandar_agent' );

		return self::bubble( array(
			'mode'        => 'floating',
			'internal'    => false,
			'endpoint'    => rest_url( 'estandar-suite/brain/v1/agent-public' ),
			'welcome'     => $atts['welcome'],
			'placeholder' => $atts['placeholder'],
			'label'       => '💬',
			'id'          => 'estandar-agent-' . wp_rand( 1000, 9999 ),
		) );
	}

	/** Burbuja interna flotante en el backend (solo admins). */
	public static function admin_bubble(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Agent::activation( 'agent_internal' ) || ! Agent::activation( 'bubble_admin' ) ) {
			return;
		}
		echo self::bubble( array(
			'mode'        => 'floating',
			'internal'    => true,
			'endpoint'    => rest_url( 'estandar-suite/brain/v1/agent' ),
			'welcome'     => 'Asistente interno. Pídeme en lenguaje natural: crear formularios, configurar emails, listar plugins/leads, o ejecutar WP-CLI (las escrituras piden confirmación).',
			'placeholder' => 'Petición… p. ej. "listar plugins"',
			'label'       => '🤖',
			'id'          => 'estandar-agent-admin',
		) );
	}

	/**
	 * Componente único de la burbuja.
	 * $opts: mode (floating|inline), internal (bool), endpoint, welcome,
	 *        placeholder, label, id.
	 */
	public static function bubble( array $opts = array() ): string
	{
		$opts = array_merge( array(
			'mode'        => 'floating',
			'internal'    => false,
			'endpoint'    => '',
			'welcome'     => '',
			'placeholder' => 'Escribe aquí…',
			'label'       => '💬',
			'id'          => 'estandar-agent-' . wp_rand( 1000, 9999 ),
		), $opts );

		$id       = (string) $opts['id'];
		$internal = ! empty( $opts['internal'] ) ? '1' : '0';
		$nonce    = $internal === '1' ? wp_create_nonce( 'wp_rest' ) : '';
		$welcome  = (string) $opts['welcome'];
		$floating = ( $opts['mode'] === 'floating' );

		$html = '<div id="' . esc_attr( $id ) . '" class="estandar-agent" data-endpoint="' . esc_url( $opts['endpoint'] ) . '" data-internal="' . $internal . '" data-nonce="' . esc_attr( $nonce ) . '">';

		$style = $floating ? 'position:fixed;bottom:84px;right:18px;' : 'position:relative;width:100%;margin:0 0 12px;';
		$html .= '<div class="estandar-agent-panel" style="display:' . ( $floating ? 'none' : 'block' ) . ';' . $style . 'width:320px;max-width:calc(100vw - 36px);background:#fff;border:1px solid #e2e2e2;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.18);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;z-index:99999;">';
		$html .= '<div style="background:#111827;color:#fff;padding:10px 14px;font-size:14px;font-weight:600;">' . esc_html( $internal === '1' ? 'Asistente interno' : 'Asistente del sitio' ) . '</div>';
		$html .= '<div class="estandar-agent-lines" style="padding:12px;min-height:120px;max-height:280px;overflow:auto;font-size:13px;line-height:1.5;color:#222;"></div>';
		$html .= '<div style="display:flex;gap:8px;padding:10px;border-top:1px solid #eee;align-items:center;">';
		$html .= '<input class="estandar-agent-input" type="text" placeholder="' . esc_attr( $opts['placeholder'] ) . '" style="flex:1;border:1px solid #ccc;border-radius:8px;padding:8px;font-size:13px;" />';
		$html .= '<button class="estandar-agent-send" type="button" style="background:#111827;color:#fff;border:0;border-radius:8px;padding:8px 12px;font-size:13px;cursor:pointer;">Enviar</button>';
		$html .= '<button class="estandar-agent-confirm" type="button" style="display:none;background:#b32d2e;color:#fff;border:0;border-radius:8px;padding:8px 12px;font-size:13px;cursor:pointer;">Confirmar</button>';
		$html .= '</div></div>';

		if ( $floating ) {
			$html .= '<button class="estandar-agent-toggle" type="button" style="position:fixed;bottom:18px;right:18px;width:58px;height:58px;border-radius:50%;border:0;background:#25D366;color:#fff;font-size:26px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.2);z-index:99999;">' . esc_html( $opts['label'] ) . '</button>';
		}
		$html .= '</div>';

		$html .= '<style>
.estandar-agent a { color:#111827; font-weight:600; text-decoration:underline; }
.estandar-agent .agent-action { display:inline-block; margin:4px 6px 0 0; background:#f3f4f6; border:1px solid #d1d5db; border-radius:20px; padding:4px 12px; font-size:12px; color:#111827; text-decoration:none; }
</style>';

		$html .= '<script>
(function(){
	var root = document.getElementById(' . wp_json_encode( $id ) . ');
	if (!root) return;
	var panel = root.querySelector(".estandar-agent-panel");
	var lines = root.querySelector(".estandar-agent-lines");
	var input = root.querySelector(".estandar-agent-input");
	var send = root.querySelector(".estandar-agent-send");
	var confirmBtn = root.querySelector(".estandar-agent-confirm");
	var toggle = root.querySelector(".estandar-agent-toggle");
	var endpoint = root.dataset.endpoint;
	var internal = root.dataset.internal === "1";
	var nonce = root.dataset.nonce || "";
	var welcome = ' . wp_json_encode( $welcome ) . ';
	var lastPayload = null;
	function esc( s ) { return (s||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }
	function add( who, text, extra ) {
		var d = document.createElement("div");
		d.style.margin = "4px 0";
		var inner = "<div style=\"padding:6px 9px;border-radius:10px;display:inline-block;max-width:100%;\"><span style=\"font-size:11px;opacity:.6;display:block;margin-bottom:2px;\">" + who + "</span>" + text;
		if (extra) inner += extra;
		inner += "</div>";
		d.innerHTML = inner;
		lines.appendChild(d);
		lines.scrollTop = lines.scrollHeight;
	}
	function actionsHtml( actions ) {
		if (!actions || !actions.length) return "";
		var h = "<div style=\"margin-top:6px;\">";
		actions.forEach(function(a){ h += "<a class=\"agent-action\" href=\"" + esc(a.href) + "\" target=\"_blank\" rel=\"noopener\">" + esc(a.label) + "</a>"; });
		return h + "</div>";
	}
	function sourcesHtml( sources ) {
		if (!sources || !sources.length) return "";
		var h = "<div style=\"margin-top:6px;font-size:11px;opacity:.75;\">Fuente: ";
		sources.forEach(function(s,i){ if (i) h += " · "; h += "<a href=\"" + esc(s.url) + "\" target=\"_blank\" rel=\"noopener\">" + esc(s.title) + "</a>"; });
		return h + "</div>";
	}
	function send( payload ) {
		var headers = { "Content-Type": "application/json" };
		if (internal && nonce) headers["X-WP-Nonce"] = nonce;
		fetch(endpoint, { method:"POST", headers: headers, body: JSON.stringify(payload) })
			.then(function(r){ return r.json(); })
			.then(function(j){
				add("asistente", esc(j.reply || "Sin respuesta."), actionsHtml(j.actions) + sourcesHtml(j.sources));
				if (j.need_confirm) { lastPayload = payload; confirmBtn.style.display = "inline-block"; }
				else { confirmBtn.style.display = "none"; lastPayload = null; }
			})
			.catch(function(){ add("asistente", "No pude conectar con el agente. Intenta más tarde."); });
	}
	function fire() {
		var v = input.value.trim();
		if (!v) return;
		input.value = "";
		add("tú", esc(v));
		var payload = {};
		payload[internal ? "request" : "message"] = v;
		if (internal) { payload.confirm = false; payload.destructive = false; }
		send(payload);
	}
	confirmBtn.addEventListener("click", function(){
		confirmBtn.style.display = "none";
		if (!lastPayload) return;
		lastPayload.confirm = true;
		lastPayload.destructive = true;
		send(lastPayload);
		lastPayload = null;
	});
	if (toggle) toggle.addEventListener("click", function(){
		var open = panel.style.display === "block";
		panel.style.display = open ? "none" : "block";
		if (!open && !lines.children.length) add("asistente", esc(welcome));
	});
	send.addEventListener("click", fire);
	input.addEventListener("keydown", function(e){ if (e.key === "Enter") fire(); });
})();
</script>';

		return $html;
	}
}
