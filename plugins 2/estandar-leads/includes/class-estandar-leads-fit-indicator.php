<?php
/**
 * LEAD_ASSESSMENT_V1 — Decoupled fit indicator renderer.
 *
 * Semantics stay canonical (commercial_fit + fit_reason); the local
 * representation is configurable per installation. Contract:
 *
 *   - Config:  wp_option `estandar_leads_fit_indicator_settings`
 *              = { enabled:0|1, mode:'text'|'badge'|'emoji'|'icon'|'image'|
 *                  'html'|'template', state_map:{ STATE:{label,emoji,icon,
 *                  image_url,color,html,template} } }
 *   - Renderers: extendable via filter `estandar_leads_fit_renderers`
 *                (mode name → callable(array $state_config, array $ctx): string).
 *                A installation adds modes without touching the core.
 *   - Default: mode 'text', enabled=false → render() returns '' → email is
 *              byte-identical to the pre-assessment behavior.
 *
 * Never hardcodes labels: unknown states fall back to Estandar_Leads_Fit
 * canonical labels. Output is HTML; all free-text pieces are escaped.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Fit_Indicator {

	const OPTION = 'estandar_leads_fit_indicator_settings';

	const DEFAULT_MODE = 'text';

	/** Canonical color defaults per fit state (overridable in state_map). */
	public static function state_colors(): array {
		return array(
			'MATCH'           => '#188038',
			'MATCH_PARCIAL'   => '#e37400',
			'DUDOSO'          => '#f29900',
			'FUERA_DE_PERFIL' => '#5f6368',
		);
	}

	public static function defaults(): array {
		return array(
			'enabled'   => 0,
			'mode'      => self::DEFAULT_MODE,
			'state_map' => array(),
		);
	}

	public static function settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		$s     = array_merge( self::defaults(), $saved );
		$s['enabled'] = ! empty( $s['enabled'] ) ? 1 : 0;
		$s['mode']    = trim( (string) ( $s['mode'] ?? self::DEFAULT_MODE ) );
		if ( $s['mode'] === '' ) {
			$s['mode'] = self::DEFAULT_MODE;
		}
		return $s;
	}

	public static function is_enabled(): bool {
		return ! empty( self::settings()['enabled'] );
	}

	/** Sanitize + persist settings (additive; unknown keys dropped). */
	public static function save_settings( array $raw ): array {
		$s = self::defaults();
		$s['enabled'] = ! empty( $raw['enabled'] ) ? 1 : 0;
		$mode = sanitize_key( (string) ( $raw['mode'] ?? self::DEFAULT_MODE ) );
		$s['mode'] = $mode !== '' ? $mode : self::DEFAULT_MODE;

		$map = array();
		$known = array_map( 'strtoupper', Estandar_Leads_Fit::states() );
		foreach ( (array) ( $raw['state_map'] ?? array() ) as $state => $cfg ) {
			$state_key = strtoupper( sanitize_key( (string) $state ) );
			if ( ! in_array( $state_key, $known, true ) ) {
				continue; // only canonical fit states can own a visual mapping.
			}
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$entry = array();
			if ( isset( $cfg['label'] ) ) {
				$entry['label'] = sanitize_text_field( (string) $cfg['label'] );
			}
			if ( isset( $cfg['emoji'] ) ) {
				$entry['emoji'] = sanitize_text_field( (string) $cfg['emoji'] );
			}
			if ( isset( $cfg['icon'] ) ) {
				$entry['icon'] = sanitize_html_class( (string) $cfg['icon'] );
			}
			if ( isset( $cfg['image_url'] ) ) {
				$entry['image_url'] = esc_url_raw( (string) $cfg['image_url'] );
			}
			if ( isset( $cfg['color'] ) ) {
				$entry['color'] = (string) $cfg['color'];
			}
			if ( isset( $cfg['html'] ) ) {
				$entry['html'] = wp_kses_post( (string) $cfg['html'] );
			}
			if ( isset( $cfg['template'] ) ) {
				$entry['template'] = sanitize_key( (string) $cfg['template'] );
			}
			$map[ strtoupper( sanitize_key( (string) $state ) ) ] = $entry;
		}
		$s['state_map'] = $map;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $s, false );
		}
		return $s;
	}

	/**
	 * Renderer registry. Extensible per installation via
	 * `estandar_leads_fit_renderers` (mode → callable(config, ctx): string).
	 */
	public static function renderers(): array {
		$builtin = array(
			'text'     => array( __CLASS__, 'render_text' ),
			'badge'    => array( __CLASS__, 'render_badge' ),
			'emoji'    => array( __CLASS__, 'render_emoji' ),
			'icon'     => array( __CLASS__, 'render_icon' ),
			'image'    => array( __CLASS__, 'render_image' ),
			'html'     => array( __CLASS__, 'render_html' ),
			'template' => array( __CLASS__, 'render_template' ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_leads_fit_renderers', $builtin );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$builtin = $filtered;
			}
		}
		return $builtin;
	}

	/**
	 * Canonical render entry point. Returns '' when disabled or no fit state.
	 *
	 * @param string $fit      canonical commercial_fit state.
	 * @param string $reason   canonical fit_reason.
	 * @param array  $ctx      renderer context (e.g. ['template' => 'email']).
	 * @param array|null $settings settings override (null = load from option).
	 */
	public static function render( string $fit, string $reason = '', array $ctx = array(), $settings = null ): string {
		$s = ( is_array( $settings ) && ! empty( $settings ) ) ? $settings : self::settings();
		if ( empty( $s['enabled'] ) || $fit === '' ) {
			return '';
		}
		$mode = (string) $s['mode'];
		$renderers = self::renderers();
		if ( ! isset( $renderers[ $mode ] ) || ! is_callable( $renderers[ $mode ] ) ) {
			$mode = self::DEFAULT_MODE;
		}
		$state_cfg = is_array( $s['state_map'][ $fit ] ?? null ) ? $s['state_map'][ $fit ] : array();
		$cfg       = array_merge( self::state_defaults( $fit, $reason ), $state_cfg );
		$html      = call_user_func( $renderers[ $mode ], $cfg, $ctx );
		return is_string( $html ) ? $html : '';
	}

	/** Per-state baseline config (canonical label + default color). */
	public static function state_defaults( string $fit, string $reason = '' ): array {
		$colors = self::state_colors();
		return array(
			'label' => Estandar_Leads_Fit::state_label( $fit ),
			'reason_label' => $reason !== '' ? Estandar_Leads_Fit::reason_label( $reason ) : '',
			'color' => isset( $colors[ $fit ] ) ? $colors[ $fit ] : '#5f6368',
			'emoji' => '',
			'icon'  => 'es-fit es-fit-' . strtolower( $fit ),
			'image_url' => '',
			'html'  => '',
			'template' => '',
		);
	}

	// ── Builtin renderers (all return HTML fragments) ───────────────────────

	public static function render_text( array $cfg, array $ctx ): string {
		$label = esc_html( (string) ( $cfg['label'] ?? '' ) );
		if ( $label === '' ) {
			return '';
		}
		return '<span class="es-fit-label" style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700;vertical-align:middle;color:' . esc_attr( $cfg['color'] ?? '#5f6368' ) . ';">' . $label . '</span>';
	}

	public static function render_badge( array $cfg, array $ctx ): string {
		$label = esc_html( (string) ( $cfg['label'] ?? '' ) );
		if ( $label === '' ) {
			return '';
		}
		$color = esc_attr( (string) ( $cfg['color'] ?? '#5f6368' ) );
		return '<span style="display:inline-block;margin-left:8px;padding:4px 10px;border-radius:999px;background:' . $color . '1a;border:1px solid ' . $color . ';color:' . $color . ';font-size:12px;font-weight:700;vertical-align:middle;">' . $label . '</span>';
	}

	public static function render_emoji( array $cfg, array $ctx ): string {
		$emoji = trim( (string) ( $cfg['emoji'] ?? '' ) );
		if ( $emoji === '' ) {
			return self::render_text( $cfg, $ctx );
		}
		return '<span class="es-fit-emoji" title="' . esc_attr( (string) ( $cfg['label'] ?? '' ) ) . '" style="margin-left:8px;vertical-align:middle;">' . esc_html( $emoji ) . '</span>';
	}

	public static function render_icon( array $cfg, array $ctx ): string {
		$icon = trim( (string) ( $cfg['icon'] ?? '' ) );
		if ( $icon === '' ) {
			return self::render_text( $cfg, $ctx );
		}
		return '<span class="' . esc_attr( $icon ) . '" title="' . esc_attr( (string) ( $cfg['label'] ?? '' ) ) . '" style="margin-left:8px;vertical-align:middle;display:inline-block;"></span>';
	}

	public static function render_image( array $cfg, array $ctx ): string {
		$url = esc_url( (string) ( $cfg['image_url'] ?? '' ) );
		if ( $url === '' ) {
			return self::render_text( $cfg, $ctx );
		}
		return '<img src="' . $url . '" alt="' . esc_attr( (string) ( $cfg['label'] ?? '' ) ) . '" style="margin-left:8px;vertical-align:middle;height:20px;width:auto;display:inline-block;">';
	}

	public static function render_html( array $cfg, array $ctx ): string {
		$html = (string) ( $cfg['html'] ?? '' );
		return $html !== '' ? $html : self::render_text( $cfg, $ctx );
	}

	/**
	 * Template mode: delegates to a consumer-registered renderer by name via
	 * `estandar_leads_fit_render_template`. Falls back to text when no
	 * template renderer is registered (extensible, never hardcoded).
	 */
	public static function render_template( array $cfg, array $ctx ): string {
		if ( function_exists( 'apply_filters' ) ) {
			$out = apply_filters( 'estandar_leads_fit_render_template', '', $cfg, $ctx );
			if ( is_string( $out ) && $out !== '' ) {
				return $out;
			}
		}
		return self::render_text( $cfg, $ctx );
	}
}
