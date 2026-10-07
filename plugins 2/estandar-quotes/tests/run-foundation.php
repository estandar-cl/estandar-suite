<?php
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function apply_filters( $hook, $value ) { return $value; }
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-state-machine.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
$pass = 0; $fail = 0; function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }
check( 'draft can enter review', Estandar_Quotes_State_Machine::can_transition( 'draft', 'review' ) );
check( 'issued cannot return to draft', ! Estandar_Quotes_State_Machine::can_transition( 'issued', 'draft' ) );
check( 'review can return to draft', Estandar_Quotes_State_Machine::can_transition( 'review', 'draft' ) );
check( 'base profile accepts required document', Estandar_Quotes_Profiles::validate_snapshot( 'base', array( 'title' => 'x', 'currency' => 'CLP', 'items' => array() ) )['currency'] === 'CLP' );
try { Estandar_Quotes_Profiles::validate_snapshot( 'base', array() ); $missing = false; } catch ( InvalidArgumentException $e ) { $missing = true; }
check( 'profile rejects incomplete revision', $missing );
exit( $fail ? 1 : 0 );
