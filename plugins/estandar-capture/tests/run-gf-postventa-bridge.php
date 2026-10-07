<?php
/**
 * ESTANDAR SUITE v2 — Tests del puente provisional GF→Capture (postventa).
 *
 *   php tests/run-gf-postventa-bridge.php   → expect "0 failed"
 *
 * Cubre las partes PURAS de GfPostventaBridge (map_entry, field_rows,
 * all_fields_table, render_body_pure) y el contrato de correo GF (Label:Value).
 */

define( 'ABSPATH', __DIR__ );
$_SERVER['HTTP_REFERER'] = '';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return strtolower( (string) preg_replace( '/[^a-z0-9\-_]/', '-', strtolower( trim( (string) $t ) ) ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'sanitize_email' ) ) { function sanitize_email( $e ) { $e = trim( (string) $e ); return is_email( $e ) ? strtolower( $e ) : ''; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) !== false; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ), mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $t ) { return (string) $t; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return (string) strip_tags( (string) $t ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://demo.test' . $p; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'get_query_var' ) ) { function get_query_var( $v ) { return 0; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); } }
$GLOBALS['__test_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__test_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $e = 0 ) { $GLOBALS['__test_transients'][ $k ] = $v; return true; } }

require dirname( __DIR__ ) . '/includes/Ingestion.php';
require dirname( __DIR__ ) . '/includes/ConversationEngine.php';
require dirname( __DIR__ ) . '/includes/Attribution.php';
require dirname( __DIR__ ) . '/includes/Forms.php';
require dirname( __DIR__ ) . '/includes/GfPostventaBridge.php';
require dirname( __DIR__ ) . '/includes/PostventaPanel.php';

$passed = 0; $failed = 0;
function check( $label, $cond ) { global $passed, $failed; if ( $cond ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Capture as C;

// Entry GF real (form 14, similar a la entry 3220) con problemas y calle.
$entry = array(
	'id'    => '9999',
	'1.3'   => 'Bianca Amelia',
	'1.6'   => 'Escobar Correa',
	'8'     => '18516316',
	'56'    => '-4',
	'16'    => 'bianca.amelia.escobar@gmail.com',
	'3'     => '+56974126542',
	'7'     => 'LOS PARRONALES-NANCAGUA',
	'12'    => '426',
	'13'    => 'SERVICO TECNICO DE POST VENTA',
	'14'    => '',
	'18'    => '',
	'47'    => 'DORMITORIO 1',
	'27'    => 'Filtración humedad pared ventana (reiterativo)',
	'51'    => 'COCINA',
	'29'    => 'Filtración humedad pared y mueble aéreo',
	'54'    => 'CASA',
	'33'    => 'Segundo piso totalmente desnivelado',
	'64'    => 'CALLE VIÑA PUEBLO ANTIGUO',
);
$form = array( 'id' => 14, 'title' => '¿En qué podemos ayudarte?', 'notifications' => array() );

// ── map_entry ────────────────────────────────────────────────────────────
$cap = C\GfPostventaBridge::map_entry( $entry, $form );
check( 'b1 source gf_postventa', $cap['source'] === 'gf_postventa' );
check( 'b2 source_form_id gf-14', $cap['content']['source_form_id'] === 'gf-14' );
check( 'b3 nombre completo', $cap['contact']['name'] === 'Bianca Amelia Escobar Correa' );
check( 'b4 email', $cap['contact']['email'] === 'bianca.amelia.escobar@gmail.com' );
check( 'b5 proyecto', $cap['fields']['proyecto'] === 'LOS PARRONALES-NANCAGUA' );
check( 'b6 calle dependiente', $cap['fields']['calle'] === 'CALLE VIÑA PUEBLO ANTIGUO' );
check( 'b7 tipo postventa', $cap['fields']['tipo_postventa'] === 'SERVICO TECNICO DE POST VENTA' );
check( 'b8 problemas json', is_array( json_decode( (string) $cap['fields']['problemas'], true ) ) );
$probs = json_decode( (string) $cap['fields']['problemas'], true );
check( 'b9 3 problemas', count( $probs ) === 3 );
check( 'b10 problema 1 lugar', $probs[0]['lugar'] === 'DORMITORIO 1' );
check( 'b11 gf_entry_id', $cap['fields']['gf_entry_id'] === '9999' );

// ── field_rows (contrato Label:Value) ────────────────────────────────────
$rows = C\GfPostventaBridge::field_rows( $entry );
$labels = array_column( $rows, 'label' );
check( 'c1 label Nombre Propietario', in_array( 'Nombre Propietario', $labels, true ) );
check( 'c2 label RUT', in_array( 'RUT Propietario (SIN dígito verificador)', $labels, true ) );
check( 'c3 label Proyecto', in_array( 'Proyecto', $labels, true ) );
check( 'c4 label Calle', in_array( 'Calle', $labels, true ) );
check( 'c5 label Lugar del problema #1', in_array( 'Lugar del problema #1', $labels, true ) );
check( 'c6 no campos vacíos', ! array_filter( array_column( $rows, 'value' ), static function ( $v ) { return trim( (string) $v ) === ''; } ) );

// ── all_fields_table (misma estructura GF) ───────────────────────────────
$table = C\GfPostventaBridge::all_fields_table( $rows );
check( 'd1 wrapper table GF', strpos( $table, '<table width="99%"' ) === 0 );
check( 'd2 label strong', strpos( $table, '<strong>Proyecto</strong>' ) !== false );
check( 'd3 value presente', strpos( $table, 'LOS PARRONALES-NANCAGUA' ) !== false );
check( 'd4 bgcolor EAF2FA', strpos( $table, 'bgcolor="#EAF2FA"' ) !== false );

// ── render_body_pure (intro + tabla + footer, contrato) ─────────────────
$body = C\GfPostventaBridge::render_body_pure( $entry );
check( 'e1 intro con proyecto', strpos( $body, 'LOS PARRONALES-NANCAGUA' ) !== false );
check( 'e2 contiene tabla Label:Value', strpos( $body, '<strong>Proyecto</strong>' ) !== false );
check( 'e3 footer presente', trim( C\GfPostventaBridge::defaults()['footer_html'] ) !== '' );
check( 'e4 subject template', strpos( C\GfPostventaBridge::settings()['subject_template'], '{proyecto}' ) !== false );
$subject = str_replace( '{proyecto}', $entry['7'], C\GfPostventaBridge::settings()['subject_template'] );
check( 'e5 subject contratado', $subject === 'Solicitud PostVenta LOS PARRONALES-NANCAGUA desde la web Empresa Demo' );

// ── defaults / settings ──────────────────────────────────────────────────
$d = C\GfPostventaBridge::defaults();
check( 'f1 from default', $d['from_email'] === 'postventa-no-responder@cliente1.example.com' );
check( 'f2 notify default', $d['notify_to'] === 'serviciotecnico@cliente1.example.com, postventa@cliente1.example.com, cm@cliente1.example.com' );
check( 'f3 form_ids default', $d['form_ids'] === '2,14' );
check( 'f4 enabled default off', $d['enabled'] === 0 );

// ── Validación/normalización de identidad (nombre/email/tel) ─────────────
$n = C\Ingestion::normalize( array( 'contact' => array( 'name' => '  MARÍA JOSÉ  DE LA CRUZ ', 'email' => ' X@Y.CL ', 'phone' => '+56912345678' ) ) );
check( 'g1 nombre normalizado iniciales', $n['name'] === 'María José De La Cruz' );
check( 'g2 email lowercase', $n['email_normalized'] === 'x@y.cl' );
check( 'g3 phone 56', $n['phone_normalized'] === '56912345678' );
check( 'g4 phone incompleto vacío', C\Ingestion::normalize( array( 'contact' => array( 'phone' => '123' ) ) )['phone_normalized'] === '' );

// ── Fotos de la solicitud (fileupload GF → URLs) ─────────────────────────
$entry_foto = $entry;
$entry_foto['31'] = '["https:\\/\\/cliente1.example.com\\/wp-content\\/uploads\\/gravity_forms\\/14-abc\\/2026\\/08\\/img1.jpg","https:\\/\\/cliente1.example.com\\/wp-content\\/uploads\\/gravity_forms\\/14-abc\\/2026\\/08\\/img2.jpg"]';
$entry_foto['32'] = 'https://cliente1.example.com/wp-content/uploads/gravity_forms/14-abc/2026/08/img3.jpg';
$cap = C\GfPostventaBridge::map_entry( $entry_foto, $form );
$probs = json_decode( (string) $cap['fields']['problemas'], true );
check( 'h1 problema 1 con 2 fotos', is_array( $probs[0]['fotos'] ?? null ) && count( $probs[0]['fotos'] ) === 2 );
check( 'h2 fotos desescapadas (/)', strpos( $probs[0]['fotos'][0], '\\/' ) === false );
check( 'h3 problema 2 con 1 foto', is_array( $probs[1]['fotos'] ?? null ) && count( $probs[1]['fotos'] ) === 1 );

// ── RUT completo (campo nativo tipo 'rut') → contrato GF separado ────────
$cap_rut = C\GfPostventaBridge::contract_from_capture( array(
	'fields' => array( 'rut' => '18930941-4', 'proyecto' => 'ALTACRUZ' ),
	'name' => 'Test RUT',
), C\GfPostventaBridge::defaults() );
check( 'i1 subject con proyecto', strpos( $cap_rut['subject'], 'ALTACRUZ' ) !== false );
check( 'i2 RUT sin DV', strpos( $cap_rut['body'], '<strong>RUT Propietario (SIN dígito verificador)</strong>' ) !== false && strpos( $cap_rut['body'], '>18930941<' ) !== false );
check( 'i3 DV separado', strpos( $cap_rut['body'], '<strong>- Dígito Verificador</strong>' ) !== false && strpos( $cap_rut['body'], '>-4<' ) !== false );
$cap_rut2 = C\GfPostventaBridge::contract_from_capture( array(
	'fields' => array( 'rut' => '18516316', 'dv' => '-4', 'proyecto' => 'ALTACRUZ' ),
), C\GfPostventaBridge::defaults() );
check( 'i4 rut+dv separados (puente)', strpos( $cap_rut2['body'], '>18516316<' ) !== false && strpos( $cap_rut2['body'], '>-4<' ) !== false );

// Intro sin placeholders literales (form nativo: nombre completo + rut con DV)
$cap_intro = C\GfPostventaBridge::contract_from_capture( array(
	'fields' => array( 'rut' => '12874376-6', 'proyecto' => 'ALTACRUZ', 'nombre_propietario' => 'Luis Rodrigo' ),
), C\GfPostventaBridge::defaults() );
check( 'i5 intro sin {apellido} literal', strpos( $cap_intro['body'], '{apellido}' ) === false );
check( 'i6 intro sin {rut} literal', strpos( $cap_intro['body'], '{rut}' ) === false );
check( 'i7 intro con nombre completo', strpos( $cap_intro['body'], 'Luis Rodrigo, Rut: 12874376' ) !== false );

// Fotos adjuntas por problema (incidents[].fotos → fila "Fotografía problema #N").
$cap_fotos = C\GfPostventaBridge::contract_from_capture( array(
	'fields' => array(
		'proyecto'  => 'ALTACRUZ',
		'incidents' => wp_json_encode( array(
			array( 'location' => 'DORMITORIO 1', 'description' => 'Humedad', 'fotos' => array( 'https://cliente1.example.com/wp-content/uploads/estandar-capture/survey/foto1.jpg' ) ),
		) ),
	),
), C\GfPostventaBridge::defaults() );
check( 'i8 fila Fotografía problema #1', strpos( $cap_fotos['body'], '<strong>Fotografía problema #1</strong>' ) !== false );
check( 'i9 URL de la foto en el contrato', strpos( $cap_fotos['body'], 'foto1.jpg' ) !== false );
check( 'i10 sin foto: no hay fila Fotografía', strpos( $cap_rut['body'], 'Fotografía problema' ) === false );

// ── i11-i14. POSTVENTA_DEDUPE_V1: 1 correo por entry GF ───────────────────
check( 'i11 entry sin marcar → no notificada', C\GfPostventaBridge::entry_notified( '12345' ) === false );
C\GfPostventaBridge::mark_entry_notified( '12345' );
check( 'i12 tras marcar → notificada', C\GfPostventaBridge::entry_notified( '12345' ) === true );
check( 'i13 entry distinta → no notificada', C\GfPostventaBridge::entry_notified( '99999' ) === false );
check( 'i14 entry vacía → guard (false)', C\GfPostventaBridge::entry_notified( '' ) === false );

// ── i15-i16. GF_BRIDGE_VISIBILITY_V1: UI solo con GF instalado ────────────
check( 'i15 sin Gravity Forms → UI oculta', C\GfPostventaBridge::ui_visible( false ) === false );
check( 'i16 con Gravity Forms → UI visible', C\GfPostventaBridge::ui_visible( true ) === true );

// ── i17-i21. LEAD_FLAT_INCIDENTS_V1: campos planos de un form lead → incidents
// (lugar_problema_N/problema_N + foto file por bloque), igual que el repeater. ─
$flat_posted = array(
	'lugar_problema_1' => 'COCINA',
	'problema_1'       => 'Chapa mala',
	'adjunta_o_toma_una_imagen_con_tu_telefono' => 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/f1.jpg',
	'algo_mas_1'       => 'si',
	'lugar_problema_2' => 'BAÑO',
	'problema_2'       => 'Filtración',
	'adjunta_o_toma_una_imagen_con_tu_telefono_2' => 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/f2.jpg',
);
$flat_cfg = array( 'sections' => array( array( 'fields' => array(
	array( 'name' => 'lugar_problema_1', 'type' => 'select' ),
	array( 'name' => 'problema_1', 'type' => 'textarea' ),
	array( 'name' => 'adjunta_o_toma_una_imagen_con_tu_telefono', 'type' => 'file' ),
	array( 'name' => 'algo_mas_1', 'type' => 'radio' ),
	array( 'name' => 'lugar_problema_2', 'type' => 'select' ),
	array( 'name' => 'problema_2', 'type' => 'textarea' ),
	array( 'name' => 'adjunta_o_toma_una_imagen_con_tu_telefono_2', 'type' => 'file' ),
) ) ) );
$flat_incs = C\GfPostventaBridge::flat_incidents_from_fields( $flat_posted, $flat_cfg );
check( 'i17 flat agrupa 2 problemas', count( $flat_incs ) === 2 );
check( 'i18 foto bloque 1 (sin sufijo) mapea a incident 1', (string) ( $flat_incs[0]['fotos'][0] ?? '' ) === 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/f1.jpg' );
check( 'i19 foto bloque 2 (sufijo _2) mapea a incident 2', (string) ( $flat_incs[1]['fotos'][0] ?? '' ) === 'https://acacios.cl/wp-content/uploads/estandar-capture/survey/f2.jpg' );
check( 'i20 location/description del incident 1', $flat_incs[0]['location'] === 'COCINA' && $flat_incs[0]['description'] === 'Chapa mala' );
check( 'i21 sin campos planos → array vacío', C\GfPostventaBridge::flat_incidents_from_fields( array( 'email' => 'x@y.cl' ), $flat_cfg ) === array() );
check( 'i22 contrato desde flat incidents incluye Fotografía #1', strpos( C\GfPostventaBridge::contract_from_capture( array( 'fields' => $flat_posted ), $flat_cfg )['body'], 'Fotografía problema #1' ) !== false );

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
