<?php
/**
 * Regression: CONTACT_MERGE_PRESERVATION_V1 — a recontact with an invalid or
 * absent email/phone must never erase a previously valid stored value.
 *
 * Pipeline context: estandar_chatbot_evaluate_contact() normalizes contact
 * input — a malformed email yields email_norm = '' (never treated as valid)
 * and travels as email_raw. estandar_chatbot_save_lead() then persists
 * estandar_chatbot_merge_contact_value( $email_norm, $existing_email ), so
 * the '' produced by a malformed attempt keeps the stored valid email.
 * (Previous behavior: an invalid email_raw ERASED the stored valid email.)
 *
 * Run: php tests/run-contact-merge-preservation.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/lead-contact-merge.php';

$fail  = 0;
$check = static function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$fail += $ok ? 0 : 1;
};

// Valid new email replaces the stored one.
$check(
	'valid incoming email replaces existing',
	estandar_chatbot_merge_contact_value( 'nuevo@estandar.cl', 'viejo@estandar.cl' ) === 'nuevo@estandar.cl'
);

// Malformed attempt (normalized to '') keeps the stored valid email — the
// exact case that previously erased it via the email_raw branch.
$check(
	'malformed/absent incoming keeps existing valid email',
	estandar_chatbot_merge_contact_value( '', 'gestion@estandar.cl' ) === 'gestion@estandar.cl'
);

// Phone-only recontact keeps the stored email untouched (fill semantics).
$check(
	'recontact without email keeps existing',
	estandar_chatbot_merge_contact_value( '', 'gestion@estandar.cl' ) === 'gestion@estandar.cl'
);

// Nothing stored, nothing incoming → stays empty (phone-only leads valid).
$check(
	'no incoming and no existing stays empty',
	estandar_chatbot_merge_contact_value( '', '' ) === ''
);

// Phone symmetric behavior.
$check(
	'valid incoming phone replaces existing',
	estandar_chatbot_merge_contact_value( '56912345678', '56987654321' ) === '56912345678'
);
$check(
	'invalid incoming phone keeps existing valid phone',
	estandar_chatbot_merge_contact_value( '', '56987654321' ) === '56987654321'
);

// Whitespace / type coercion safety.
$check(
	'whitespace-only incoming treated as absent',
	estandar_chatbot_merge_contact_value( "  \n", ' gestion@estandar.cl ' ) === 'gestion@estandar.cl'
);
$check(
	'non-string inputs coerced safely',
	estandar_chatbot_merge_contact_value( null, null ) === ''
);

echo "\n" . ( $fail === 0 ? 'ALL PASS' : $fail . ' FAILED' ) . "\n";
exit( $fail === 0 ? 0 : 1 );
