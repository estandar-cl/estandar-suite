<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transversal "optimal defaults" helper.
 *
 * Suite rule: every setting's default (applied on fresh install) IS the
 * optimal value. When an operator modifies a setting away from the optimal,
 * the UI must NOT block the change but MUST make it noticeable with a red
 * note next to the modified field.
 *
 * Pure helpers; the templates decide where to render the note.
 */
class Optimal
{
	/** PURE: is the current value the optimal one? (loose string compare) */
	public static function is_optimal( $current, $optimal ): bool
	{
		return (string) $current === (string) $optimal;
	}

	/** PURE: null when optimal, else {current, optimal}. */
	public static function diff( $current, $optimal ): ?array
	{
		return self::is_optimal( $current, $optimal ) ? null : array( 'current' => $current, 'optimal' => $optimal );
	}

	/**
	 * PURE→HTML: red note for a non-optimal value ('' when optimal).
	 * Never blocks — only makes the deviation visible.
	 */
	public static function note( $current, $optimal, string $optimal_label = '' ): string
	{
		$d = self::diff( $current, $optimal );
		if ( $d === null ) {
			return '';
		}
		$label = $optimal_label !== '' ? $optimal_label : (string) $d['optimal'];
		return '<span style="color:#d63638;font-weight:600;font-size:12px;margin-left:8px;" title="Modificaste un valor que era el óptimo de fábrica.">⚠ valor modificado — óptimo: ' . esc_html( $label ) . '</span>';
	}
}
