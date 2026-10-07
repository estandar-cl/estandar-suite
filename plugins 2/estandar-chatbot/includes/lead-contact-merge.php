<?php
/**
 * CONTACT_MERGE_PRESERVATION_V1 — pure decision for the recontact merge.
 *
 * A recontact only replaces a stored contact value when it brings a VALID
 * (normalized, non-empty) new value. An invalid attempt travels as
 * email_raw/phone_raw inside the lead data for review, but must never erase
 * a previously valid email/phone: losing a working contact channel over a
 * typo is worse than keeping a possibly stale one.
 *
 * Pure function (no WP APIs) so the regression suite can load this file
 * standalone, following the capture-attribution.php pattern in Primer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'estandar_chatbot_merge_contact_value' ) ) {
	/**
	 * @param mixed $incoming_normalized Normalized value from the current
	 *                                   submission ('' when absent/invalid).
	 * @param mixed $existing_value      Value already stored for the lead.
	 * @return string Value to persist.
	 */
	function estandar_chatbot_merge_contact_value( $incoming_normalized, $existing_value ) {
		$incoming = trim( (string) $incoming_normalized );
		return $incoming !== '' ? $incoming : trim( (string) $existing_value );
	}
}
