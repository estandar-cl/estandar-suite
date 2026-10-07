<?php
if(!defined('ABSPATH'))exit; interface Estandar_Quotes_Mail_Transport { public function send(array $delivery,array $metadata,array $artifact,array $recipients): bool; }
