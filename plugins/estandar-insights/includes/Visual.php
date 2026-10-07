<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visual — helpers de presentación SIN dependencias (HTML+CSS, cero JS).
 *
 * Nacen para que los embudos (comercial y web) se muestren **gráficamente**, con
 * barras proporcionales al volumen y la conversión/el cuello visibles en la misma
 * línea. Determinista (no usa librerías ni gráficos externos).
 */
class Visual {

	/**
	 * Embudo en SVG (forma real de embudo: trapecios que se angostan). Etiquetas
	 * a la izquierda y métricas a la derecha para que no se corten en barras finas.
	 * Cero JS.
	 *
	 * @param array  $rows Lista de ['label','count','rate','bottleneck'].
	 * @param string $unit Unidad opcional.
	 * @return string HTML+SVG.
	 */
	public static function funnel_svg( array $rows, string $unit = '' ): string {
		if ( ! $rows ) {
			return '<p class="description">Sin datos para la ventana.</p>';
		}

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'label' => (string) ( $r['label'] ?? '' ),
				'count' => max( 0, (int) ( $r['count'] ?? 0 ) ),
				'rate'  => array_key_exists( 'rate', $r ) ? $r['rate'] : null,
				'neck'  => ! empty( $r['bottleneck'] ),
			);
		}

		$max   = 1;
		foreach ( $items as $it ) {
			$max = max( $max, $it['count'] );
		}

		$W     = 860;
		$cx    = (int) ( $W / 2 );
		$h     = 46;
		$gap   = 10;
		$n     = count( $items );
		$pad   = 4;
		$total = $n * $h + ( $n - 1 ) * $gap + 2 * $pad;
		$full  = 400;  // ancho máximo del trapecio
		$min   = 16;

		$width = static function ( int $c ) use ( $max, $full, $min ): float {
			return max( $min, $c / $max * $full );
		};

		$svg = '<svg viewBox="0 0 ' . $W . ' ' . $total . '" width="100%" style="max-width:' . $W . 'px;display:block;" role="img" aria-label="Embudo de conversión">';

		$y = $pad;
		foreach ( $items as $i => $it ) {
			$top_w = $width( $it['count'] );
			$bot_w = ( $i < $n - 1 ) ? $width( $items[ $i + 1 ]['count'] ) : $top_w * 0.86; // cierre visual del embudo
			$color = $it['neck'] ? '#b32d2e' : '#2271b1';

			$pts = sprintf(
				'%.1f,%d %.1f,%d %.1f,%d %.1f,%d',
				$cx - $top_w / 2, $y,
				$cx + $top_w / 2, $y,
				$cx + $bot_w / 2, $y + $h,
				$cx - $bot_w / 2, $y + $h
			);
			$svg .= '<polygon points="' . $pts . '" fill="' . $color . '" opacity="0.92"></polygon>';

			$mid_y = $y + (int) ( $h / 2 ) + 4;

			// Etiqueta (izquierda) y métricas (derecha).
			$svg .= '<text x="' . $pad . '" y="' . $mid_y . '" font-size="13" font-weight="600" fill="#1d2327">' . esc_html( $it['label'] ) . '</text>';
			if ( $it['neck'] ) {
				$svg .= '<text x="' . $pad . '" y="' . ( $mid_y + 14 ) . '" font-size="11" fill="#b32d2e">⚠ cuello</text>';
			}
			$count_txt = esc_html( number_format_i18n( $it['count'] ) ) . ( $unit !== '' ? ' ' . esc_html( $unit ) : '' );
			$rate_txt  = ( null !== $it['rate'] ) ? esc_html( number_format_i18n( (float) $it['rate'], 1 ) . '%' ) : '—';
			$svg .= '<text x="' . ( $W - $pad ) . '" y="' . ( $mid_y - 4 ) . '" text-anchor="end" font-size="13" font-weight="600" fill="#1d2327">' . $count_txt . '</text>';
			$svg .= '<text x="' . ( $W - $pad ) . '" y="' . ( $mid_y + 11 ) . '" text-anchor="end" font-size="11" fill="#646970">conv. ' . $rate_txt . '</text>';

			$y += $h + $gap;
		}

		return $svg . '</svg>';
	}

	/**
	 * Adapta el funnel comercial (`Funnel::compute`) a filas de `funnel()`.
	 *
	 * @param array $stages stage => ['count'=>int,'rate_from_prev'=>?float,'bottleneck'=>?bool].
	 * @return array
	 */
	public static function rows_from_stages( array $stages ): array {
		$rows = array();
		foreach ( $stages as $stage => $info ) {
			$rows[] = array(
				'label'      => (string) ( $info['label'] ?? $stage ),
				'count'      => (int) ( $info['count'] ?? 0 ),
				'rate'       => $info['rate_from_prev'] ?? ( $info['rate'] ?? null ),
				'bottleneck' => ! empty( $info['bottleneck'] ),
			);
		}
		return $rows;
	}
}
