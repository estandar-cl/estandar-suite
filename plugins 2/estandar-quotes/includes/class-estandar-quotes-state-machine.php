<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_State_Machine {
	public static function transitions(): array { return array( 'draft' => array( 'review' ), 'review' => array( 'draft', 'issued' ), 'issued' => array( 'superseded', 'expired' ), 'superseded' => array(), 'expired' => array() ); }
	public static function can_transition( string $from, string $to ): bool { return in_array( sanitize_key( $to ), self::transitions()[ sanitize_key( $from ) ] ?? array(), true ); }
	public static function assert_transition( string $from, string $to ): void { if ( ! self::can_transition( $from, $to ) ) { throw new InvalidArgumentException( 'Invalid quote state transition.' ); } }
}
