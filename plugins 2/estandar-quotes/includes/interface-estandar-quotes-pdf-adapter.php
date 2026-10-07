<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
interface Estandar_Quotes_PDF_Adapter { public function id(): string; public function version(): string; public function render( string $print_html, array $options = array() ): string; }
