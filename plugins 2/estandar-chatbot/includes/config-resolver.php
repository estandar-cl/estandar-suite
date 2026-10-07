<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function estandar_chatbot_default_post_lead_actions() {
	return [
		[ 'action' => 'whatsapp', 'label' => 'Hablar por WhatsApp', 'enabled' => 1 ],
		[ 'action' => 'deferred', 'label' => 'Prefiero que me contacten', 'enabled' => 1 ],
		[ 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ],
	];
}

function estandar_chatbot_profile_cta_labels( $overrides = [] ) {
	$defaults = [
		'default'   => 'Ver más',
		'pagar'     => 'Pagar ahora',
		'precio'    => 'Ver precios',
		'cotizar'   => 'Cotizar ahora',
		'contratar' => 'Contratar ahora',
	];

	return array_merge( $defaults, is_array( $overrides ) ? $overrides : [] );
}

function estandar_chatbot_profile_behavior_copy( $overrides = [] ) {
	$defaults = function_exists( 'estandar_chatbot_get_behavior_copy_defaults' )
		? estandar_chatbot_get_behavior_copy_defaults()
		: [];

	return array_merge( $defaults, is_array( $overrides ) ? $overrides : [] );
}

function estandar_chatbot_get_profile_registry() {
	return [
		'lead_real_estate' => [
			'label'       => 'Lead inmobiliario',
			'description' => 'Para proyectos o agencias inmobiliarias. Captura leads calificados con botones de proyecto, precio y asesor. Derivación rápida a WhatsApp.',
			'use_when'    => 'Vendes departamentos, casas, terrenos o proyectos en verde.',
			'project_types' => [ 'real_estate' ],
			'human_summary' => [
				'buttons'  => [ 'Ver opciones disponibles', 'Consultar precios', 'Hablar con un asesor' ],
				'captures' => [ 'Nombre', 'Teléfono' ],
				'tone'     => 'Claro, cercano y orientado a ayudar',
				'goal'     => 'Orientar interesados y derivar consultas calificadas',
			],
			'defaults' => [
					'chatbot_lead_capture_enabled'       => 1,
					'chatbot_lead_intro_text'            => 'Hola 👋 Puedo ayudarte a revisar opciones disponibles, resolver dudas o ponerte en contacto con un asesor.',
					'chatbot_lead_fields'                => [
						[ 'key' => 'name', 'label' => '¿Cuál es tu nombre?', 'required' => true ],
						[ 'key' => 'phone', 'label' => '¿Cuál es tu teléfono?', 'required' => true ],
					],
					'chatbot_lead_intents'               => [
						[ 'id' => 'opciones', 'label' => 'Ver opciones disponibles' ],
						[ 'id' => 'precios', 'label' => 'Consultar precios' ],
						[ 'id' => 'asesor', 'label' => 'Hablar con un asesor' ],
					],
					'chatbot_botones_iniciales'          => [
						[ 'texto' => 'Ver opciones disponibles', 'tipo' => 'info', 'payload' => 'Ver opciones disponibles' ],
						[ 'texto' => 'Consultar precios', 'tipo' => 'info', 'payload' => 'Consultar precios' ],
						[ 'texto' => 'Hablar con un asesor', 'tipo' => 'human_escalation', 'payload' => 'asesor' ],
					],
					'chatbot_human_escalation_enabled'   => 1,
					'chatbot_human_escalation_mode'      => 'lead_form',
					'chatbot_requires_disambiguation'    => 1,
					'chatbot_business_industry'          => 'inmobiliaria',
					'chatbot_business_goal'              => 'orientar interesados y derivar consultas calificadas',
					'chatbot_business_primary_cta'       => 'Ver opciones disponibles',
					'chatbot_business_tone'              => 'claro, cercano y orientado a ayudar',
					'chatbot_business_sensitive_topics'  => 'precio, disponibilidad, financiamiento, plazos',
					'chatbot_auto_open_enabled'          => 0,
					'chatbot_hide_menu_on_click'         => 1,
					'chatbot_cta_labels'                 => estandar_chatbot_profile_cta_labels( [
						'default' => 'Ver opciones',
						'precio'  => 'Consultar precios',
						'cotizar' => 'Hablar con un asesor',
					] ),
					'chatbot_behavior_copy'              => estandar_chatbot_profile_behavior_copy( [
						'high_intent_keywords'           => "precio\nvalor\nopciones\ndisponibilidad\nvisita\nagendar\nproyecto\ndepartamento\ncasa\nterreno\nfinanciamiento",
						'price_transition_message'       => "Te puedo orientar con precios, disponibilidad o financiamiento.\n\nSi quieres que un asesor te contacte, dime tu nombre 👇",
						'price_cta_message'              => 'Puedo ayudarte a revisar precios y opciones disponibles 👇',
						'high_intent_transition_message' => "Perfecto.\n\nPuedo ayudarte a revisar opciones o derivarte con un asesor. Para contactarte, dime tu nombre 👇",
					] ),
					'chatbot_post_lead_actions'          => estandar_chatbot_default_post_lead_actions(),
			],
			'internal' => [
				'lead_capture_mode'    => 'qualified_lead',
				'handoff_mode'         => 'fast_human',
				'disambiguation'       => 'project_interest',
				'initial_buttons_mode' => 'conversion_shortcuts',
				'cta_priority'         => 'quote_whatsapp_visit',
				'tone_mode'            => 'executive_close',
				'ai_mode'              => 'assisted_handoff',
			],
		],
		'lead_services' => [
			'label'       => 'Lead servicios',
			'description' => 'Para empresas de servicios: contabilidad, legal, consultoría, tecnología. Captura nombre, teléfono y correo. Orienta hacia cotización o contacto.',
			'use_when'    => 'Vendes servicios profesionales o B2B.',
			'project_types' => [ 'service', 'other' ],
			'human_summary' => [
				'buttons'  => [ 'Solicitar cotización', 'Tengo una consulta', 'Hablar con alguien' ],
				'captures' => [ 'Nombre', 'Teléfono', 'Correo (opcional)' ],
				'tone'     => 'Claro, profesional y consultivo',
				'goal'     => 'Captar solicitudes de servicio',
			],
			'defaults' => [
				'chatbot_lead_capture_enabled'       => 1,
				'chatbot_lead_intro_text'            => 'Cuéntame qué servicio necesitas y te pedimos los datos mínimos para contactarte.',
				'chatbot_lead_fields'                => [
					[ 'key' => 'name', 'label' => '¿Cuál es tu nombre?', 'required' => true ],
					[ 'key' => 'phone', 'label' => '¿Cuál es tu teléfono?', 'required' => true ],
					[ 'key' => 'email', 'label' => '¿Cuál es tu correo?', 'required' => false ],
				],
				'chatbot_lead_intents'               => [
					[ 'id' => 'cotizar', 'label' => 'Solicitar cotización' ],
					[ 'id' => 'consulta', 'label' => 'Tengo una consulta' ],
					[ 'id' => 'humano', 'label' => 'Hablar con alguien' ],
				],
				'chatbot_botones_iniciales'          => [
					[ 'texto' => 'Solicitar cotización', 'tipo' => 'lead_intent', 'payload' => 'cotizar' ],
					[ 'texto' => 'Tengo una consulta', 'tipo' => 'lead_intent', 'payload' => 'consulta' ],
					[ 'texto' => 'Hablar con alguien', 'tipo' => 'human_escalation', 'payload' => 'humano' ],
				],
				'chatbot_human_escalation_enabled'   => 1,
				'chatbot_human_escalation_mode'      => 'lead_form',
				'chatbot_requires_disambiguation'    => 0,
				'chatbot_business_industry'          => 'servicios',
				'chatbot_business_goal'              => 'captar solicitudes de servicio',
				'chatbot_business_primary_cta'       => 'Solicitar contacto',
				'chatbot_business_tone'              => 'claro, profesional y consultivo',
				'chatbot_business_sensitive_topics'  => 'precio, plazos, alcance, disponibilidad',
				'chatbot_auto_open_enabled'          => 0,
				'chatbot_hide_menu_on_click'         => 1,
				'chatbot_cta_labels'                 => estandar_chatbot_profile_cta_labels( [
					'default' => 'Solicitar información',
					'precio'  => 'Solicitar cotización',
					'cotizar' => 'Cotizar servicio',
				] ),
				'chatbot_behavior_copy'              => estandar_chatbot_profile_behavior_copy( [
					'high_intent_keywords'           => "precio\ncotizar\ncontratar\nservicio\nconsulta\nagendar\ncontactar\nme interesa",
					'price_transition_message'       => "Perfecto.\n\nPara orientarte según el servicio que necesitas, dime tu nombre 👇",
					'high_intent_transition_message' => "Perfecto.\n\nPara avanzar con tu solicitud, dime tu nombre 👇",
				] ),
				'chatbot_post_lead_actions'          => estandar_chatbot_default_post_lead_actions(),
			],
			'internal' => [
				'lead_capture_mode'    => 'service_request',
				'handoff_mode'         => 'qualified_contact',
				'disambiguation'       => 'need_based',
				'initial_buttons_mode' => 'service_shortcuts',
				'cta_priority'         => 'contact_request',
				'tone_mode'            => 'consultative',
				'ai_mode'              => 'guided_support',
			],
		],
		'faq_support' => [
			'label'       => 'FAQ soporte',
			'description' => 'Para sitios con base de clientes activa. Resuelve dudas frecuentes primero; solo captura datos si el usuario necesita seguimiento.',
			'use_when'    => 'Tienes clientes actuales que buscan ayuda o soporte post-venta.',
			'project_types' => [ 'service', 'other' ],
			'human_summary' => [
				'buttons'  => [ 'Tengo una duda', 'Necesito soporte', 'Contactar soporte' ],
				'captures' => [ 'Nombre', 'Correo' ],
				'tone'     => 'Claro, útil y directo',
				'goal'     => 'Resolver dudas frecuentes y derivar casos',
			],
			'defaults' => [
				'chatbot_lead_capture_enabled'       => 0,
				'chatbot_lead_intro_text'            => 'Te ayudo a resolver tu duda. Si hace falta seguimiento, te pediremos tus datos.',
				'chatbot_lead_fields'                => [
					[ 'key' => 'name', 'label' => '¿Cuál es tu nombre?', 'required' => true ],
					[ 'key' => 'email', 'label' => '¿Cuál es tu correo?', 'required' => true ],
				],
				'chatbot_lead_intents'               => [
					[ 'id' => 'faq', 'label' => 'Tengo una duda' ],
					[ 'id' => 'soporte', 'label' => 'Necesito soporte' ],
					[ 'id' => 'humano', 'label' => 'Contactar soporte' ],
				],
				'chatbot_botones_iniciales'          => [
					[ 'texto' => 'Tengo una duda', 'tipo' => 'info', 'payload' => 'Tengo una duda' ],
					[ 'texto' => 'Necesito soporte', 'tipo' => 'info', 'payload' => 'Necesito soporte' ],
					[ 'texto' => 'Contactar soporte', 'tipo' => 'human_escalation', 'payload' => 'soporte' ],
				],
				'chatbot_human_escalation_enabled'   => 1,
				'chatbot_human_escalation_mode'      => 'lead_form',
				'chatbot_requires_disambiguation'    => 1,
				'chatbot_business_industry'          => 'soporte',
				'chatbot_business_goal'              => 'resolver dudas frecuentes y derivar casos',
				'chatbot_business_primary_cta'       => 'Contactar soporte',
				'chatbot_business_tone'              => 'claro, útil y directo',
				'chatbot_business_sensitive_topics'  => 'errores, reclamos, postventa, datos personales',
				'chatbot_auto_open_enabled'          => 0,
				'chatbot_hide_menu_on_click'         => 1,
				'chatbot_cta_labels'                 => estandar_chatbot_profile_cta_labels( [
					'default' => 'Ver ayuda',
					'precio'  => 'Contactar soporte',
					'cotizar' => 'Contactar soporte',
				] ),
				'chatbot_behavior_copy'              => estandar_chatbot_profile_behavior_copy( [
					'high_intent_keywords'           => "soporte\nayuda\nproblema\nerror\ncontactar\nhumano\nreclamo\npostventa",
					'high_intent_transition_message' => "Entiendo.\n\nPara derivarte a soporte, dime tu nombre 👇",
					'objection_message'              => 'Te ayudo a revisar esto paso a paso. Si no se resuelve, te derivo con soporte.',
				] ),
				'chatbot_post_lead_actions'          => [
					[ 'action' => 'deferred', 'label' => 'Quiero que soporte me contacte', 'enabled' => 1 ],
					[ 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ],
				],
			],
			'internal' => [
				'lead_capture_mode'    => 'support_followup',
				'handoff_mode'         => 'fallback_escalation',
				'disambiguation'       => 'support_topic',
				'initial_buttons_mode' => 'faq_shortcuts',
				'cta_priority'         => 'resolve_then_escalate',
				'tone_mode'            => 'supportive_direct',
				'ai_mode'              => 'context_first',
			],
		],
		'demo_showcase' => [
			'label'       => 'Demo showcase',
			'description' => 'Modo demostración. No captura leads reales. Úsalo para presentar el chatbot a un cliente o en un entorno de prueba.',
			'use_when'    => 'Estás mostrando el producto a alguien o probando la instalación.',
			'project_types' => [],
			'human_summary' => [
				'buttons'  => [ 'Ver ejemplo de lead', 'Probar respuesta IA', 'Contactar demo' ],
				'captures' => [],
				'tone'     => 'Claro, breve y demostrativo',
				'goal'     => 'Mostrar capacidades del chatbot',
			],
			'defaults' => [
				'chatbot_lead_capture_enabled'       => 0,
				'chatbot_lead_intro_text'            => 'Este es un flujo de demostración. Prueba una opción para ver cómo responde.',
				'chatbot_lead_fields'                => [],
				'chatbot_lead_intents'               => [],
				'chatbot_botones_iniciales'          => [
					[ 'texto' => 'Ver ejemplo de lead', 'tipo' => 'info', 'payload' => 'Quiero ver un ejemplo de lead' ],
					[ 'texto' => 'Probar respuesta IA', 'tipo' => 'info', 'payload' => 'Probar respuesta IA' ],
					[ 'texto' => 'Contactar demo', 'tipo' => 'human_escalation', 'payload' => 'demo' ],
				],
				'chatbot_human_escalation_enabled'   => 0,
				'chatbot_human_escalation_mode'      => 'lead_form',
				'chatbot_requires_disambiguation'    => 0,
				'chatbot_business_industry'          => 'demo',
				'chatbot_business_goal'              => 'mostrar capacidades del chatbot',
				'chatbot_business_primary_cta'       => 'Probar demo',
				'chatbot_business_tone'              => 'claro, breve y demostrativo',
				'chatbot_business_sensitive_topics'  => 'datos reales, pagos, información sensible',
				'chatbot_auto_open_enabled'          => 0,
				'chatbot_hide_menu_on_click'         => 0,
				'chatbot_cta_labels'                 => estandar_chatbot_profile_cta_labels( [
					'default' => 'Probar demo',
					'precio'  => 'Ver ejemplo',
					'cotizar' => 'Ver ejemplo',
				] ),
				'chatbot_behavior_copy'              => estandar_chatbot_profile_behavior_copy( [
					'high_intent_keywords'           => "demo\nprobar\nejemplo\nver\nmostrar",
					'high_intent_transition_message' => 'Perfecto. Te muestro cómo se vería un flujo de conversión.',
				] ),
				'chatbot_post_lead_actions'          => [
					[ 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ],
				],
			],
			'internal' => [
				'lead_capture_mode'    => 'disabled_demo',
				'handoff_mode'         => 'none',
				'disambiguation'       => 'none',
				'initial_buttons_mode' => 'demo_shortcuts',
				'cta_priority'         => 'showcase',
				'tone_mode'            => 'demo',
				'ai_mode'              => 'optional',
			],
		],
	];
}

function estandar_chatbot_get_profile_defaults( $profile = '' ) {
	$profile  = sanitize_key( (string) $profile );
	$registry = estandar_chatbot_get_profile_registry();

	return isset( $registry[ $profile ]['defaults'] ) && is_array( $registry[ $profile ]['defaults'] )
		? $registry[ $profile ]['defaults']
		: [];
}

function estandar_chatbot_get_profile_labels() {
	$labels = [
		'' => __( 'Sin perfil / Manual legacy', 'estandar-chatbot' ),
	];

	foreach ( estandar_chatbot_get_profile_registry() as $key => $profile ) {
		$labels[ $key ] = (string) ( $profile['label'] ?? $key );
	}

	return $labels;
}

function estandar_chatbot_option_is_empty_for_profile_apply( $value ) {
	if ( $value === null ) {
		return true;
	}

	if ( is_string( $value ) ) {
		return trim( $value ) === '';
	}

	if ( is_array( $value ) ) {
		return empty( $value );
	}

	return false;
}

function estandar_chatbot_preview_profile_defaults( $profile, $overwrite = false ) {
	$profile  = sanitize_key( (string) $profile );
	$defaults = estandar_chatbot_get_profile_defaults( $profile );
	$result   = [
		'profile'         => $profile,
		'fields_to_apply' => [],
		'fields_to_skip'  => [],
		'overwrite'       => (bool) $overwrite,
	];

	if ( $profile === '' || empty( $defaults ) ) {
		return $result;
	}

	$missing = '__ecb_missing_option__';
	foreach ( $defaults as $key => $value ) {
		$current = get_option( $key, $missing );
		if ( ! $overwrite && $current !== $missing && ! estandar_chatbot_option_is_empty_for_profile_apply( $current ) ) {
			$result['fields_to_skip'][] = [
				'key'            => $key,
				'current_value'  => $current,
				'proposed_value' => $value,
			];
			continue;
		}

		$result['fields_to_apply'][] = [
			'key'            => $key,
			'current_value'  => $current === $missing ? null : $current,
			'proposed_value' => $value,
		];
	}

	return $result;
}

function estandar_chatbot_apply_profile_defaults( $profile, $overwrite = false ) {
	$profile = sanitize_key( (string) $profile );
	$preview = estandar_chatbot_preview_profile_defaults( $profile, $overwrite );
	$result  = [
		'profile'   => $profile,
		'applied'   => [],
		'skipped'   => [],
		'overwrite' => (bool) $overwrite,
		'preview'   => $preview,
	];

	update_option( 'chatbot_profile', $profile, false );

	foreach ( (array) ( $preview['fields_to_apply'] ?? [] ) as $item ) {
		$key = isset( $item['key'] ) ? sanitize_key( (string) $item['key'] ) : '';
		if ( $key === '' || ! array_key_exists( $key, estandar_chatbot_get_config_safe_defaults() ) ) {
			continue;
		}
		update_option( $key, $item['proposed_value'] ?? null, false );
		$result['applied'][] = $key;
	}

	foreach ( (array) ( $preview['fields_to_skip'] ?? [] ) as $item ) {
		if ( ! empty( $item['key'] ) ) {
			$result['skipped'][] = sanitize_key( (string) $item['key'] );
		}
	}

	return $result;
}

function estandar_chatbot_get_config_safe_defaults() {
	return [
		'chatbot_nombre'                    => 'Asistente',
		'chatbot_mostrar_nombre'            => 1,
		'chatbot_botones_iniciales'         => [],
		'chatbot_idioma'                    => 'es',
		'chatbot_lead_capture_enabled'      => 1,
		'chatbot_auto_open_enabled'         => 0,
		'chatbot_auto_open_type'            => 'time',
		'chatbot_auto_open_value'           => 6,
		'chatbot_lead_intro_text'           => 'Te ayudo rápidamente. Primero dime qué necesitas para derivarte con el equipo correcto.',
		'chatbot_lead_fields'               => [],
		'chatbot_lead_intents'              => [],
		'chatbot_hide_menu_on_click'        => 1,
		'chatbot_human_escalation_enabled'  => 1,
		'chatbot_human_escalation_text'     => 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.',
		'chatbot_human_escalation_mode'     => 'lead_form',
		'chatbot_human_whatsapp_number'     => '',
		'chatbot_human_whatsapp_message'    => 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.',
		'chatbot_enable_website_demo'       => 0,
		'chatbot_requires_disambiguation'   => 0,
		'chatbot_business_industry'         => 'servicios',
		'chatbot_business_goal'             => 'captar contactos',
		'chatbot_business_primary_cta'      => 'Quiero avanzar',
		'chatbot_business_sensitive_topics' => 'precio, plazos, disponibilidad',
		'chatbot_business_tone'             => 'claro y profesional',
		'chatbot_post_lead_message'         => "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp.",
		'chatbot_post_lead_actions'         => estandar_chatbot_default_post_lead_actions(),
		'chatbot_cta_labels'                => estandar_chatbot_profile_cta_labels(),
		'chatbot_behavior_copy'             => estandar_chatbot_profile_behavior_copy(),
		'chatbot_theme_accent'              => '#111827',
		'chatbot_visual_style'              => '',
		'chatbot_theme_visual_style'        => 'default',
		'chatbot_theme_shadow_enabled'      => 1,
		'chatbot_theme_user_bubble'         => '#111827',
		'chatbot_theme_bot_bubble'          => '#ffffff',
		'chatbot_theme_radius'              => 22,
		'chatbot_theme_bubble_shape'        => 'rounded',
		'chatbot_theme_position'            => 'right',
		'chatbot_bubble_size'               => 58,
		'chatbot_branding_config'           => [
			'bg_color'          => '#ffffff',
			'bot_bubble_color'  => '#f3f4f6',
			'user_bubble_color' => '#e8f0fe',
			'button_color'      => '#2563eb',
			'text_color'        => '#111827',
			'background_image'  => '',
			'bot_avatar'        => '',
			'shadow_enabled'    => 1,
			'shadow_intensity'  => 6,
		],
		'chatbot_position_bottom'           => 22,
		'chatbot_position_left'             => '',
		'chatbot_position_right'            => '',
		'chatbot_inline_sidebar_enabled'    => 0,
		'chatbot_inline_fixed_composer_enabled' => 0,
		'chatbot_inline_fullpage_enabled'   => 0,
		'chatbot_bubble_image_url'          => '',
		'chatbot_flow_enabled'              => 0,
		'chatbot_flow_json'                 => '',
	];
}

function estandar_chatbot_get_profile_overrides() {
	$overrides = get_option( 'chatbot_profile_overrides', [] );
	if ( ! is_array( $overrides ) ) {
		return [];
	}

	return $overrides;
}

function estandar_chatbot_get_effective_config() {
	$safe_defaults = estandar_chatbot_get_config_safe_defaults();
	$profile       = sanitize_key( (string) get_option( 'chatbot_profile', '' ) );
	$config        = array_merge( $safe_defaults, estandar_chatbot_get_profile_defaults( $profile ) );
	$missing       = '__ecb_missing_option__';

	foreach ( array_keys( $safe_defaults ) as $key ) {
		$current = get_option( $key, $missing );
		if ( $current !== $missing ) {
			$config[ $key ] = $current;
		}
	}

	foreach ( estandar_chatbot_get_profile_overrides() as $key => $value ) {
		if ( array_key_exists( $key, $safe_defaults ) ) {
			$config[ $key ] = $value;
		}
	}

	return $config;
}

function estandar_chatbot_effective_option( $key, $default = null ) {
	$config = estandar_chatbot_get_effective_config();

	return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
}

/**
 * Devuelve el human_summary de un perfil dado.
 * Usado por el preview human-readable en la UI de configuración.
 *
 * @param string $profile Clave del perfil.
 * @return array{buttons:string[],captures:string[],tone:string,goal:string}
 */
function estandar_chatbot_get_profile_human_summary( string $profile ): array {
	$registry = estandar_chatbot_get_profile_registry();
	return isset( $registry[ $profile ]['human_summary'] ) && is_array( $registry[ $profile ]['human_summary'] )
		? $registry[ $profile ]['human_summary']
		: [];
}

/**
 * Devuelve la descripción y el "use_when" de un perfil.
 *
 * @param string $profile
 * @return array{description:string,use_when:string}
 */
function estandar_chatbot_get_profile_meta( string $profile ): array {
	$registry = estandar_chatbot_get_profile_registry();
	if ( ! isset( $registry[ $profile ] ) ) {
		return [ 'description' => '', 'use_when' => '' ];
	}
	return [
		'description' => (string) ( $registry[ $profile ]['description'] ?? '' ),
		'use_when'    => (string) ( $registry[ $profile ]['use_when'] ?? '' ),
	];
}

/**
 * Sugiere el perfil más adecuado para la instalación actual.
 * Prioridad:
 *   1. Si ya hay un perfil asignado → lo respeta (no sugiere cambio).
 *   2. Lee el project_type del primer proyecto en el registry.
 *   3. Si no hay registry → devuelve 'lead_services' como fallback seguro.
 *
 * @return string Clave del perfil sugerido, o '' si ya hay uno asignado.
 */
function estandar_chatbot_suggest_profile(): string {
	$current = sanitize_key( (string) get_option( 'chatbot_profile', '' ) );
	if ( $current !== '' ) {
		return ''; // Ya tiene perfil — no sugerir.
	}

	// Detectar tipo de proyecto desde el registry.
	$project_type = '';
	if ( class_exists( 'Estandar_Core_Project_Registry' ) ) {
		$client_id = function_exists( 'estandar_core_get_client_slug' )
			? sanitize_key( estandar_core_get_client_slug() )
			: '';
		if ( $client_id !== '' ) {
			$projects = Estandar_Core_Project_Registry::get_all_for_client( $client_id );
			if ( ! empty( $projects ) ) {
				$project_type = (string) ( $projects[0]['project_type'] ?? '' );
			}
		}
	}

	// Mapear project_type → perfil.
	$registry = estandar_chatbot_get_profile_registry();
	foreach ( $registry as $profile_key => $profile_data ) {
		$types = (array) ( $profile_data['project_types'] ?? [] );
		if ( ! empty( $project_type ) && in_array( $project_type, $types, true ) ) {
			return $profile_key;
		}
	}

	// Fallback: servicio es el caso más común.
	return 'lead_services';
}
