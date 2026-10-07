<?php

use PHPUnit\Framework\TestCase;

class PhoneValidationTest extends TestCase {

	/**
	 * @dataProvider valid_phone_provider
	 */
	public function test_normalizes_valid_chilean_mobile_phones( $input ) {
		$this->assertSame( '56912345678', estandar_chatbot_normalize_phone( $input ) );
	}

	public function valid_phone_provider() {
		return [
			'country plus'  => [ '+56912345678' ],
			'country bare'  => [ '56912345678' ],
			'local mobile'  => [ '912345678' ],
			'formatted'     => [ '+56 9 1234 5678' ],
		];
	}

	/**
	 * @dataProvider invalid_phone_provider
	 */
	public function test_rejects_invalid_phone_values( $input ) {
		$this->assertSame( '', estandar_chatbot_normalize_phone( $input ) );
	}

	public function invalid_phone_provider() {
		return [
			'short observed case' => [ '+5637174183' ],
			'too short'           => [ '91234567' ],
			'too long'            => [ '569123456789' ],
			'landline prefix'     => [ '56212345678' ],
			'invalid characters'  => [ '5691234ABCD' ],
			'impossible prefix'   => [ '56812345678' ],
		];
	}

	public function test_keeps_valid_email_when_phone_is_invalid() {
		$contact = estandar_chatbot_evaluate_contact( 'persona@example.com', '+5637174183' );
		$this->assertSame( CONTACT_SUSPECT, $contact['contact_quality'] );
		$this->assertSame( CONTACT_WARNING_PHONE_INVALID, $contact['contact_warning'] );
		$this->assertSame( 'persona@example.com', $contact['email'] );
		$this->assertSame( '', $contact['phone'] );
		$this->assertSame( '+5637174183', $contact['phone_raw'] );
	}

	public function test_keeps_valid_phone_when_email_is_invalid() {
		$contact = estandar_chatbot_evaluate_contact( 'correo-invalido', '912345678' );
		$this->assertSame( CONTACT_SUSPECT, $contact['contact_quality'] );
		$this->assertSame( CONTACT_WARNING_EMAIL_INVALID, $contact['contact_warning'] );
		$this->assertSame( '', $contact['email'] );
		$this->assertSame( '56912345678', $contact['phone'] );
		$this->assertSame( 'correo-invalido', $contact['email_raw'] );
	}

	public function test_rejects_when_both_channels_are_invalid() {
		$contact = estandar_chatbot_evaluate_contact( 'correo-invalido', '+5637174183' );
		$this->assertSame( CONTACT_REJECTED, $contact['contact_quality'] );
	}
}
