<?php
/** Run: php tests/run-seo-intelligence-absence.php */
define( 'ABSPATH', __DIR__ . '/' );
function absint( $v ) { return abs( (int) $v ); }
require dirname(__DIR__) . '/includes/class-estandar-leads-seo-intelligence.php';
$context = Estandar_Leads_SEO_Intelligence::context_for_lead( 1 );
$pass = $context['status'] === 'seo_unavailable' && empty( $context['top_queries'] ) && $context['attribution']['exact_query_known'] === false;
echo ( $pass ? 'PASS  ' : 'FAIL  ' ) . "SEO Intelligence absence degrades safely\n";
exit( $pass ? 0 : 1 );
