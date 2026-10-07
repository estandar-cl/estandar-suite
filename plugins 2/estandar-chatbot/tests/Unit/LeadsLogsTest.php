<?php
/**
 * Tests unitarios para leads y logs handlers.
 */

use PHPUnit\Framework\TestCase;

class LeadsLogsTest extends TestCase {

	public function test_delete_lead_by_id_returns_zero_for_invalid_id() {
		$result = estandar_chatbot_delete_lead_by_id( 0 );
		$this->assertSame( 0, $result );
	}

	public function test_delete_lead_by_id_returns_zero_for_negative_id() {
		$result = estandar_chatbot_delete_lead_by_id( -1 );
		$this->assertSame( 0, $result );
	}

	public function test_delete_leads_by_ids_returns_zero_for_empty_array() {
		$result = estandar_chatbot_delete_leads_by_ids( [] );
		$this->assertSame( 0, $result );
	}

	public function test_delete_leads_by_ids_filters_invalid_ids() {
		$result = estandar_chatbot_delete_leads_by_ids( [ 0, -5, 0 ] );
		$this->assertSame( 0, $result );
	}

	public function test_delete_log_by_id_returns_zero_for_invalid_id() {
		$result = estandar_chatbot_delete_log_by_id( 0 );
		$this->assertSame( 0, $result );
	}

	public function test_delete_log_by_id_returns_zero_for_negative_id() {
		$result = estandar_chatbot_delete_log_by_id( -1 );
		$this->assertSame( 0, $result );
	}

	public function test_delete_logs_by_ids_returns_zero_for_empty_array() {
		$result = estandar_chatbot_delete_logs_by_ids( [] );
		$this->assertSame( 0, $result );
	}
}
