<?php
define('ABSPATH', __DIR__);
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',''.strtolower($v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9-]+/','-',strtolower((string)$v)),'-');}
function wp_parse_args($a,$b){return array_merge($b,$a);}
function wp_generate_password(){return 'salt';}
function get_option($k,$d=array()){return $GLOBALS['opts'][$k]??$d;}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
function rest_url($p=''){return 'https://example.test/wp-json/'.$p;}
function home_url(){return 'https://example.test';}
function remove_accents($v){return $v;}
function sanitize_text_field($v){return trim((string)$v);}
class EA_Utils { public static function sanitize_text($v,$n){return substr((string)$v,0,$n);} public static function extract_domain(){return 'example.test';} }
require dirname(__DIR__).'/includes/class-settings.php';
$GLOBALS['opts']['ea_settings']=EA_Settings::defaults();
$input=$GLOBALS['opts']['ea_settings']; $input['thank_you_paths']=implode("\n",$input['thank_you_paths']); $input['whatsapp_bridge_slug']='contactar';
$saved=EA_Settings::sanitize($input);
$ok=0;$fail=0;function ck($l,$c){global $ok,$fail;if($c){$ok++;echo "PASS  $l\n";}else{$fail++;echo "FAIL  $l\n";}}
ck('slug has one canonical owner',$saved['whatsapp_bridge_slug']==='contactar');
ck('previous slug becomes alias',in_array('run',$saved['whatsapp_bridge_aliases'],true));
$GLOBALS['opts']['ea_settings']=$saved;$input=$saved;$input['thank_you_paths']=implode("\n",$input['thank_you_paths']);$input['whatsapp_bridge_slug']='ventas';$saved2=EA_Settings::sanitize($input);
ck('alias history survives second change',in_array('run',$saved2['whatsapp_bridge_aliases'],true)&&in_array('contactar',$saved2['whatsapp_bridge_aliases'],true));
$cfg=(new ReflectionClass('EA_Settings'))->getMethod('get_tracker_config');$GLOBALS['opts']['ea_settings']=$saved2;$config=EA_Settings::get_tracker_config();
ck('tracker receives canonical and aliases',$config['whatsappBridgeSlugs']===array('ventas','run','contactar'));
echo "\n$ok passed, $fail failed\n";exit($fail?1:0);
