<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Deterministic test seam. It never contacts a mail service. */
class Estandar_Quotes_Fake_Mail_Transport implements Estandar_Quotes_Mail_Transport {
	private $mode;
	private $records = array();
	public function __construct( string $mode = 'success' ) { $this->mode = $mode; }
	public function set_mode( string $mode ): void { $this->mode = $mode; }
	public function records(): array { return $this->records; }
	public function send( array $delivery, array $metadata, array $artifact, array $recipients ): bool {
		$this->records[] = array( 'delivery_uuid' => $delivery['delivery_uuid'] ?? '', 'artifact_uuid' => $artifact['artifact_uuid'] ?? '', 'recipients' => $recipients, 'metadata' => $metadata, 'mode' => $this->mode );
		if ( $this->mode === 'exception' ) { throw new RuntimeException( 'Fake transport exception.' ); }
		return $this->mode === 'success';
	}
}
