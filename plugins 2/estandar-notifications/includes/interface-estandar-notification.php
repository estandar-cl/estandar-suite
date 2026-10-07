<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Estandar_Notification {

	public function id(): string;

	public function label(): string;

	public function enabled(): bool;

	public function send( array $event ): bool;
}
