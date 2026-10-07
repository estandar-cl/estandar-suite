<?php
/** Run: php tests/run-conversion-analytics-contract.php */
define('ABSPATH',__DIR__.'/'); function wp_cache_get(){return false;} function wp_cache_set(){} function wp_json_encode($v){return json_encode($v);} 
class Estandar_SEO_Service { public static function commercial_timezone(){return new DateTimeZone('America/Santiago');} public static function stability_delay_days(){return 3;} }
class Estandar_SEO_Repository { public static function conversion_rows($p,$f,$q=false){return $q?array(array('landing_url'=>'https://x.test/a/','query'=>'consulta','clicks'=>8,'impressions'=>80)):array(array('landing_url'=>'https://x.test/a/','clicks'=>10,'impressions'=>100,'ctr'=>.1,'average_position'=>3,'period_to'=>(new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d')),array('landing_url'=>'https://x.test/other/','clicks'=>20,'impressions'=>200,'ctr'=>.1,'average_position'=>4,'period_to'=>(new DateTimeImmutable('now',new DateTimeZone('America/Santiago')))->format('Y-m-d')));}}
class Estandar_Leads_SEO_Analytics { public static function get_organic_conversion_summary($p,$f){return array('status'=>'available','dimensions'=>array(array('landing_url'=>'https://x.test/a/','project_id'=>'proyecto','google_organic_leads'=>2,'classified_leads'=>2,'qualified_leads'=>1,'commercial_results_available'=>1)),'warnings'=>array(),'read_only'=>true);}}
require dirname(__DIR__).'/includes/class-estandar-seo-conversion-analytics.php';
$failed=0; function check($l,$ok){global $failed;echo($ok?'PASS  ':'FAIL  ').$l."\n";$failed+=$ok?0:1;}
$p=array('from'=>'2026-06-01','to'=>'2026-06-28'); $r=Estandar_SEO_Conversion_Analytics::get_organic_conversion_summary($p);
check('stable aggregate response contract',isset($r['status'],$r['period'],$r['timezone'],$r['dimensions'],$r['seo_metrics'],$r['commercial_metrics'],$r['conversion_rates'],$r['freshness'],$r['attribution_scope'],$r['warnings']));
check('landing combines independent aggregates and rates',$r['dimensions'][0]['clicks']===10&&$r['dimensions'][0]['google_organic_leads']===2&&$r['dimensions'][0]['lead_per_click_rate']===.2&&$r['dimensions'][0]['qualified_per_lead_rate']===.5);
check('query scope is explicitly non-personal',$r['attribution_scope']['exact_query_known']===false&&strpos($r['warnings'][0],'independent')!==false);
check('project filter retains only project-linked landings',count(Estandar_SEO_Conversion_Analytics::get_project_organic_performance('proyecto',$p)['dimensions'])===1);
$q=Estandar_SEO_Conversion_Analytics::get_query_landing_performance('https://x.test/a/',$p); check('query output remains landing aggregate',$q['dimensions'][0]['query']==='consulta'&&strpos(end($q['warnings']),'never a specific Lead')!==false);
exit($failed?1:0);
