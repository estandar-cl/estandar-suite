<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_access_dashboard() ) {
	wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'estandar-chatbot' ) );
}

// ── Navigation A3 (2026-06) ───────────────────────────────────────────────────
// Groups: inicio | chatbot | ia | apariencia | actividad | sistema
// Chatbot sub-tabs: configuracion (Conversación) + leads-captacion (Captura)
// Actividad sub-tabs: leads + conversaciones + analitica + logs + sugerencias
// Sistema sub-tabs: estado-operacional + herramientas + licencia + integraciones
$tabs = [
	// ── INICIO ───────────────────────────────────────────────────────────────
	'inicio' => [
		'label' => __( 'Inicio', 'estandar-chatbot' ),
		'file'  => 'inicio.php',
		'group' => 'inicio',
	],

	// ── CHATBOT ──────────────────────────────────────────────────────────────
	'configuracion' => [
		'label' => __( 'Conversación', 'estandar-chatbot' ),
		'file'  => 'configuracion.php',
		'group' => 'chatbot',
	],
	'leads-captacion' => [
		'label' => __( 'Captura', 'estandar-chatbot' ),
		'file'  => 'leads-captacion.php',
		'group' => 'chatbot',
	],

	// ── IA ───────────────────────────────────────────────────────────────────
	'ia-contexto' => [
		'label' => __( 'Conocimiento', 'estandar-chatbot' ),
		'file'  => 'ia-contexto.php',
		'group' => 'ia',
	],

	// ── APARIENCIA ───────────────────────────────────────────────────────────
	'apariencia' => [
		'label' => __( 'Apariencia', 'estandar-chatbot' ),
		'file'  => 'apariencia.php',
		'group' => 'apariencia',
	],

	// ── ACTIVIDAD ────────────────────────────────────────────────────────────
	'leads' => [
		'label' => __( 'Leads', 'estandar-chatbot' ),
		'file'  => 'leads.php',
		'group' => 'actividad',
	],
	'conversaciones' => [
		'label' => __( 'Conversaciones', 'estandar-chatbot' ),
		'file'  => 'conversaciones.php',
		'group' => 'actividad',
	],
	'analitica' => [
		'label' => __( 'Analítica', 'estandar-chatbot' ),
		'file'  => 'analitica.php',
		'group' => 'actividad',
	],
	'logs' => [
		'label' => __( 'Logs', 'estandar-chatbot' ),
		'file'  => 'logs.php',
		'group' => 'actividad',
	],
	'sugerencias' => [
		'label' => __( 'Sugerencias', 'estandar-chatbot' ),
		'file'  => 'sugerencias.php',
		'group' => 'actividad',
	],

	// ── SISTEMA ──────────────────────────────────────────────────────────────
	'estado-operacional' => [
		'label' => __( 'Estado', 'estandar-chatbot' ),
		'file'  => 'estado-operacional.php',
		'group' => 'sistema',
	],
	'herramientas' => [
		'label' => __( 'Herramientas', 'estandar-chatbot' ),
		'file'  => 'herramientas.php',
		'group' => 'sistema',
	],
	'licencia' => [
		'label' => __( 'Licencia', 'estandar-chatbot' ),
		'file'  => 'licencia.php',
		'group' => 'sistema',
	],
	'integraciones' => [
		'label' => __( 'Integraciones', 'estandar-chatbot' ),
		'file'  => 'integraciones.php',
		'group' => 'sistema',
	],
];

$tabs = apply_filters( 'estandar_chatbot_admin_tabs', $tabs );
$tabs = array_filter(
	$tabs,
	function( $tab_key ) {
		return estandar_chatbot_user_can_view_tab( $tab_key );
	},
	ARRAY_FILTER_USE_KEY
);

if ( empty( $tabs ) ) {
	wp_die( esc_html__( 'No tienes pestañas disponibles en este momento.', 'estandar-chatbot' ) );
}

$current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'inicio';
if ( ! isset( $tabs[ $current ] ) ) {
	$current = isset( $tabs['inicio'] ) ? 'inicio' : array_key_first( $tabs );
}

$tab_file = $tabs[ $current ]['file'];
$tab_path = ESTANDAR_CHATBOT_PATH . 'admin/tabs/' . $tab_file;

$leads_count = function_exists( 'estandar_chatbot_count_leads' ) ? (int) estandar_chatbot_count_leads() : 0;
$logs_count  = function_exists( 'estandar_chatbot_count_logs' ) ? (int) estandar_chatbot_count_logs() : 0;
$conversations_count = function_exists( 'estandar_chatbot_count_conversations' ) ? (int) estandar_chatbot_count_conversations() : 0;

	$tab_groups = [
		'inicio'     => __( 'Inicio',     'estandar-chatbot' ),
		'chatbot'    => __( 'Conversación', 'estandar-chatbot' ),
		'ia'         => __( 'Conocimiento', 'estandar-chatbot' ),
		'apariencia' => __( 'Apariencia', 'estandar-chatbot' ),
		'actividad'  => __( 'Actividad',  'estandar-chatbot' ),
		'sistema'    => __( 'Estado',     'estandar-chatbot' ),
	];

$current_group = $tabs[ $current ]['group'] ?? 'inicio';
$group_tab_map = [];
$group_first_tabs = [];

foreach ( $tab_groups as $group_key => $group_label ) {
	$group_tabs = array_filter(
		$tabs,
		function( $tab ) use ( $group_key ) {
			return ( $tab['group'] ?? 'configuracion' ) === $group_key;
		}
	);

	if ( empty( $group_tabs ) ) {
		continue;
	}

	$group_tab_map[ $group_key ] = $group_tabs;
	$first_group_tab = array_key_first( $group_tabs );

	if ( null !== $first_group_tab ) {
		$group_first_tabs[ $group_key ] = $first_group_tab;
	}
}

// Antes esta plantilla dibujaba su propio shell a mano (brand bar + nav +
// dos filas de tabs con clases .ecb-nav-tabs propias) en vez de colgarse del
// shell canónico de Primer — por eso el submenú de Chatbot se veía "parecido
// pero distinto" al del resto de los plugins. Ahora usa
// estandar_core_render_admin_shell() igual que Comunicaciones/Cotizaciones/
// Forms/Analytics: header_slot dibuja las dos filas (grupo + sub-tab) con
// estandar_render_tabs(), la misma función canónica en todos lados.
if ( ! function_exists( 'estandar_core_render_admin_shell' ) ) {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Estandar Chatbot requiere Estandar Primer. Actívelo para ver este módulo.', 'estandar-chatbot' )
		. '</p></div>';
	return;
}

$current_group_tabs = $group_tab_map[ $current_group ] ?? [];

estandar_core_render_admin_shell( [
	'title'       => 'Estandar Chatbot',
	'width'       => 'fluid',
	'class'       => 'estandar-chatbot-admin',
	'header_slot' => static function () use ( $tab_groups, $group_first_tabs, $current_group, $current_group_tabs, $current, $leads_count, $conversations_count, $logs_count ): void {
		if ( ! function_exists( 'estandar_render_tabs' ) ) {
			return;
		}

		$group_items = [];
		foreach ( $tab_groups as $group_key => $group_label ) {
			if ( empty( $group_first_tabs[ $group_key ] ) ) {
				continue;
			}
			$group_items[ $group_key ] = [
				'label' => $group_label,
				'url'   => admin_url( 'admin.php?page=estandar-chatbot&tab=' . $group_first_tabs[ $group_key ] ),
			];
		}
		estandar_render_tabs( $group_items, $current_group );

		if ( count( $current_group_tabs ) <= 1 ) {
			return;
		}

		$sub_items = [];
		foreach ( $current_group_tabs as $key => $tab ) {
			$label = $tab['label'];
			if ( 'leads' === $key && $leads_count > 0 ) {
				$label .= ' (' . $leads_count . ')';
			} elseif ( 'conversaciones' === $key && $conversations_count > 0 ) {
				$label .= ' (' . $conversations_count . ')';
			} elseif ( 'logs' === $key && $logs_count > 0 ) {
				$label .= ' (' . $logs_count . ')';
			}
			$sub_items[ $key ] = [
				'label' => $label,
				'url'   => admin_url( 'admin.php?page=estandar-chatbot&tab=' . $key ),
			];
		}
		estandar_render_tabs( $sub_items, $current );
	},
	'callback'    => static function () use ( $tab_path, $tab_file ): void {
		if ( file_exists( $tab_path ) ) {
			require $tab_path;
			return;
		}
		echo '<div style="padding:10px 12px;background:#fff3cd;border:1px solid #ffeeba;">';
		echo '<strong>Tab no disponible:</strong> El archivo <code>' . esc_html( $tab_file ) . '</code> no existe en <code>/admin/tabs/</code>.';
		echo '</div>';
	},
	'footer_slot' => static function (): void {
		echo '<div class="ecb-admin-footer estandar-admin-footer"><div class="ecb-admin-footer__meta">';
		echo '<strong>Versión:</strong> ' . esc_html( ESTANDAR_CHATBOT_VERSION );
		echo '&nbsp;|&nbsp;<strong>Modo:</strong> Producto';
		echo '&nbsp;|&nbsp;<strong>Autoría:</strong> Luis Valdés de Estandar Studios CL';
		echo '</div></div>';
	},
] );
