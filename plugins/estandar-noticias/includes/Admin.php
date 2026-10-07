<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const SLUG = 'noticias-boletin';
	const DIR  = 'estandar-noticias';
	const CAP  = 'manage_options';

	public static function init(): void {
		if ( ! Plugin::suite_active() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		}
		add_action( 'admin_post_estandar_noticias_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_estandar_noticias_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_en_discover_feeds', array( __CLASS__, 'ajax_discover_feeds' ) );
		add_filter( 'estandar_suite_otros_content', array( __CLASS__, 'otros_content' ), 10, 2 );
	}

	public static function menu(): void {
		add_menu_page( 'Estandar Noticias', 'Estandar Noticias', self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-rss', 58 );
	}

	public static function otros_content( $content, $dir ) {
		if ( is_string( $content ) && $content !== '' ) {
			return $content;
		}
		if ( (string) $dir === self::DIR ) {
			return self::embed_html();
		}
		return $content;
	}

	public static function base_url(): string {
		if ( Plugin::suite_active() ) {
			return add_query_arg( array( 'page' => 'estandar-suite-otros', 'p' => self::DIR ), admin_url( 'admin.php' ) );
		}
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		$is_ours    = strpos( $hook, self::SLUG ) !== false;
		$is_embedded = ( ( $_GET['page'] ?? '' ) === 'estandar-suite-otros' && ( $_GET['p'] ?? '' ) === self::DIR );
		if ( ! $is_ours && ! $is_embedded ) {
			return;
		}
		wp_enqueue_style( 'estandar-noticias-admin', ESTANDAR_NOTICIAS_URL . 'assets/admin.css', array(), ESTANDAR_NOTICIAS_VERSION );
		wp_enqueue_script( 'estandar-noticias-admin', ESTANDAR_NOTICIAS_URL . 'assets/admin.js', array(), ESTANDAR_NOTICIAS_VERSION, true );
		wp_localize_script( 'estandar-noticias-admin', 'EN_NOTICIAS', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'en_discover' ),
			'feedname' => self::name( 'temas[][feeds][]' ),
		) );
	}

	public static function ajax_discover_feeds(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'sin permisos', 403 );
		}
		check_ajax_referer( 'en_discover', 'nonce' );
		$tema  = sanitize_text_field( wp_unslash( $_POST['tema'] ?? '' ) );
		$feeds = Curate::discover_feeds( $tema );
		wp_send_json_success( array( 'feeds' => $feeds ) );
	}

	public static function tabs(): array {
		return array(
			'general'   => 'General',
			'temas'     => 'Temas y fuentes',
			'ediciones' => 'Ediciones',
			'ai'        => 'IA',
			'filtros'   => 'Filtros',
		);
	}

	public static function current_tab(): string {
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'general' ) );
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_noticias_save' );
		$input = wp_unslash( $_POST[ self::SLUG ] ?? array() );
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		Settings::save( $input );
		Plugin::reschedule();
		$tab    = sanitize_key( (string) ( $_POST['tab'] ?? 'general' ) );
		$return = (string) ( $_POST['return_url'] ?? '' );
		if ( $return === '' || ( strpos( $return, 'page=' . self::SLUG ) === false && strpos( $return, 'page=estandar-suite-otros' ) === false ) ) {
			$return = self::base_url();
		}
		wp_safe_redirect( add_query_arg( array( 'tab' => $tab, 'updated' => '1' ), $return ) );
		exit;
	}

	public static function handle_run_now(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_noticias_run_now' );
		$res   = Pipeline::run( '', true );
		$total = 0;
		foreach ( (array) $res as $r ) {
			$total += (int) ( $r['total'] ?? 0 );
		}
		wp_safe_redirect( add_query_arg( array( 'tab' => 'general', 'en_ran' => (string) $total ), self::base_url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		echo '<div class="wrap en-wrap"><h1>Estandar Noticias</h1>';
		echo self::embed_html();
		echo '</div>';
	}

	public static function embed_html(): string {
		ob_start();
		self::render_body();
		return (string) ob_get_clean();
	}

	private static function render_body(): void {
		$tab = self::current_tab();
		$s   = Settings::all();
		$base = self::base_url();

		echo '<p class="description">Boletín diario automático: recopila noticias, las cura con IA y publica/envía. Configúralo con los selectores de abajo.</p>';
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
		}
		if ( isset( $_GET['en_ran'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Publicación ejecutada ahora: ' . (int) $_GET['en_ran'] . ' noticias procesadas.</p></div>';
		}
		if ( ! Plugin::suite_active() ) {
			echo '<div class="notice notice-info inline"><p>Estandar Noticias activo. Funciona de forma independiente (sin Estandar Suite).</p></div>';
		}

		echo '<nav class="nav-tab-wrapper en-tabs">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$slug === $tab ? ' nav-tab-active' : '',
				esc_url( add_query_arg( 'tab', $slug, $base ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="estandar_noticias_save">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<input type="hidden" name="return_url" value="' . esc_attr( $base ) . '">';
		wp_nonce_field( 'estandar_noticias_save' );
		echo '<div class="en-card">';
		switch ( $tab ) {
			case 'temas':
				self::tab_temas( $s );
				break;
			case 'ediciones':
				self::tab_ediciones( $s );
				break;
			case 'ai':
				self::tab_ai( $s );
				break;
			case 'filtros':
				self::tab_filtros( $s );
				break;
			default:
				self::tab_general( $s );
		}
		echo '</div>';
		submit_button( 'Guardar cambios' );
		echo '</form>';
		self::templates();
	}

	private static function name( string $path ): string {
		return self::SLUG . '[' . str_replace( '[', '][', $path ) . ']';
	}

	private static function tab_general( array $s ): void {
		$g = $s['general'];
		$run_now = wp_nonce_url( add_query_arg( array( 'action' => 'estandar_noticias_run_now' ), admin_url( 'admin-post.php' ) ), 'estandar_noticias_run_now' );
		echo '<h2>General</h2>';
		echo '<p><a class="button button-primary" href="' . esc_url( $run_now ) . '">Publicar ahora</a> <span class="description">Genera y envía el boletín de hoy con la configuración actual (sin esperar al cron).</span></p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">Publicación automática</th><td>';
		printf( '<label class="en-inline"><input type="checkbox" name="%s" value="1"%s> Publicar y enviar cada día</label>', esc_attr( self::name( 'general[activo]' ) ), checked( (int) $g['activo'], 1, false ) );
		echo '</td></tr>';

		echo '<tr><th scope="row">Hora</th><td>';
		printf( '<select name="%s">', esc_attr( self::name( 'general[hora]' ) ) );
		for ( $h = 0; $h < 24; $h++ ) {
			$val = sprintf( '%02d:00', $h );
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $g['hora'] === $val, true, false ), esc_html( $val ) );
			$val = sprintf( '%02d:30', $h );
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $g['hora'] === $val, true, false ), esc_html( $val ) );
		}
		echo '</select>';
		$run_url = add_query_arg( array( 'en_run' => Plugin::run_token() ), home_url( '/' ) );
		echo '<p class="description">WP-Cron del sitio. Para hora exacta, programa en Site Tools → Cron Jobs esta URL (una vez al día):</p><p><code>' . esc_html( $run_url ) . '</code></p></td></tr>';

		echo '<tr><th scope="row">Salida</th><td><select name="' . esc_attr( self::name( 'general[salida]' ) ) . '">';
		foreach ( array( 'ambos' => 'Publicar y enviar', 'post' => 'Solo publicar', 'correo' => 'Solo enviar' ) as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $g['salida'], $v, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">Ventana de noticias</th><td><select name="' . esc_attr( self::name( 'general[ventana_horas]' ) ) . '">';
		foreach ( array( 12 => 'Últimas 12 horas', 24 => 'Últimas 24 horas', 48 => 'Últimos 2 días', 72 => 'Últimos 3 días' ) as $v => $l ) {
			printf( '<option value="%d"%s>%s</option>', $v, selected( (int) $g['ventana_horas'], $v, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">Noticias por tema (general)</th><td><select name="' . esc_attr( self::name( 'general[max_por_tema]' ) ) . '">';
		for ( $n = 1; $n <= 20; $n++ ) {
			printf( '<option value="%d"%s>%d</option>', $n, selected( (int) $g['max_por_tema'], $n, false ), $n );
		}
		echo '</select> <span class="description">Se aplica a los temas marcados como “según general”.</span></td></tr>';

		echo '<tr><th scope="row">Remitente del correo</th><td>';
		printf( '<input type="email" name="%s" value="%s" class="regular-text" placeholder="boletin@tudominio.cl">', esc_attr( self::name( 'general[remitente]' ) ), esc_attr( $g['remitente'] ) );
		echo '<p class="description">Vacío = correo del sitio. La entrega usa el SMTP del sitio (o el de la Suite si está configurado).</p></td></tr>';

		echo '</tbody></table>';
	}

	private static function tab_temas( array $s ): void {
		echo '<h2>Temas y fuentes</h2>';
		echo '<p class="description">Un tema = un grupo de fuentes. Usa <strong>“Buscar fuentes con IA”</strong> si no sabes qué URLs usar: la IA propone feeds y se validan automáticamente.</p>';
		echo '<div id="en-temas" class="en-rows">';
		if ( ! $s['temas'] ) {
			echo '<p class="en-empty">Sin temas. Añade el primero.</p>';
		}
		foreach ( $s['temas'] as $slug => $t ) {
			self::tema_row( (string) $slug, $t );
		}
		echo '</div><button type="button" class="button" data-en-add="tema">+ Añadir tema</button>';
	}

	private static function tema_row( string $slug, array $t ): void {
		$key = $slug !== '' ? $slug : '__new__';
		$nm  = static function ( string $f ) use ( $key ) {
			return self::name( 'temas[' . $key . '][' . $f . ']' );
		};
		echo '<div class="en-row en-row-tema">';
		echo '<div class="en-row-head">';
		printf( '<input type="text" name="%s" value="%s" placeholder="Nombre del tema" class="regular-text en-nombre">', esc_attr( $nm( 'nombre' ) ), esc_attr( $t['nombre'] ) );
		printf( '<label class="en-inline"><input type="checkbox" name="%s" value="1"%s> activo</label>', esc_attr( $nm( 'activo' ) ), checked( (int) $t['activo'], 1, false ) );
		echo '<label class="en-inline">ventana <select name="' . esc_attr( $nm( 'horas' ) ) . '">';
		foreach ( array( 12 => '12 h', 24 => '24 h', 48 => '48 h', 72 => '72 h', 168 => '7 días' ) as $v => $l ) {
			printf( '<option value="%d"%s>%s</option>', $v, selected( (int) $t['horas'], $v, false ), esc_html( $l ) );
		}
		echo '</select></label>';
		$tmax = (int) ( $t['max'] ?? 5 );
		echo '<label class="en-inline">noticias por tema <select name="' . esc_attr( $nm( 'max' ) ) . '">';
		printf( '<option value="0"%s>según general</option>', selected( $tmax, 0, false ) );
		for ( $n = 1; $n <= 20; $n++ ) {
			printf( '<option value="%d"%s>%d</option>', $n, selected( $tmax, $n, false ), $n );
		}
		echo '</select></label>';
		echo '<button type="button" class="button-link en-remove">Quitar</button>';
		echo '</div>';
		echo '<div class="en-row-body">';
		echo '<div class="en-field"><span>Fuentes (RSS) — marca las que uses</span>';
		echo '<div class="en-feeds" data-en-feeds data-en-feedname="' . esc_attr( $nm( 'feeds' ) . '[]' ) . '">';
		foreach ( (array) $t['feeds'] as $url ) {
			self::feed_checkbox( $nm( 'feeds' ) . '[]', (string) $url );
		}
		echo '</div>';
		echo '<div class="en-feeds-actions">';
		echo '<button type="button" class="button" data-en-discover>🔎 Buscar fuentes con IA</button> ';
		printf( '<input type="text" name="%s" class="regular-text" placeholder="o pega una URL /feed y Enter">', esc_attr( $nm( 'feeds_new' ) ) );
		echo '<span class="en-discover-msg description"></span>';
		echo '</div></div>';
		echo '<label class="en-field"><span>Palabras clave a incluir (opcional, separadas por coma)</span>';
		printf( '<input type="text" name="%s" value="%s" class="regular-text">', esc_attr( $nm( 'incluir' ) ), esc_attr( implode( ', ', (array) $t['incluir'] ) ) );
		echo '</label>';
		echo '</div></div>';
	}

	private static function feed_checkbox( string $name, string $url ): void {
		printf(
			'<label class="en-feed"><input type="checkbox" name="%s" value="%s" checked> <span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $url ),
			esc_html( $url )
		);
	}

	private static function tab_ediciones( array $s ): void {
		echo '<h2>Ediciones</h2>';
		echo '<p class="description">Una edición = un boletín. Elige cuántas noticias quieres por tema y a quién se envía.</p>';
		echo '<div id="en-ediciones" class="en-rows">';
		if ( ! $s['ediciones'] ) {
			echo '<p class="en-empty">Sin ediciones. Añade la primera.</p>';
		}
		foreach ( $s['ediciones'] as $slug => $ed ) {
			self::edicion_row( (string) $slug, $ed, $s['temas'] );
		}
		echo '</div><button type="button" class="button" data-en-add="edicion">+ Añadir edición</button>';
	}

	private static function edicion_row( string $slug, array $ed, array $temas ): void {
		$key = $slug !== '' ? $slug : '__new__';
		$nm  = static function ( string $f ) use ( $key ) {
			return self::name( 'ediciones[' . $key . '][' . $f . ']' );
		};
		echo '<div class="en-row en-row-edicion">';
		echo '<div class="en-row-head">';
		printf( '<input type="text" name="%s" value="%s" placeholder="Título del boletín" class="regular-text">', esc_attr( $nm( 'titulo' ) ), esc_attr( $ed['titulo'] ) );
		printf( '<label class="en-inline"><input type="checkbox" name="%s" value="1"%s> activa</label>', esc_attr( $nm( 'activo' ) ), checked( (int) $ed['activo'], 1, false ) );
		echo '<button type="button" class="button-link en-remove">Quitar</button>';
		echo '</div>';
		echo '<div class="en-row-body">';
		echo '<div class="en-field"><span>Temas incluidos</span><div class="en-checks">';
		foreach ( $temas as $tslug => $t ) {
			printf(
				'<label class="en-check"><input type="checkbox" name="%s" value="%s"%s> %s</label>',
				esc_attr( $nm( 'temas' ) . '[]' ),
				esc_attr( (string) $tslug ),
				checked( in_array( (string) $tslug, (array) $ed['temas'], true ), true, false ),
				esc_html( $t['nombre'] )
			);
		}
		echo '</div></div>';
		echo '<label class="en-field"><span>Destinatarios (separados por coma)</span>';
		printf( '<input type="text" name="%s" value="%s" class="regular-text" placeholder="tu@correo.cl">', esc_attr( $nm( 'destinatarios' ) ), esc_attr( implode( ', ', (array) $ed['destinatarios'] ) ) );
		echo '</label>';
		echo '<label class="en-field"><span>Perfil para la curación (a quién le habla)</span>';
		printf( '<textarea name="%s" rows="3">%s</textarea>', esc_attr( $nm( 'perfil' ) ), esc_textarea( (string) $ed['perfil'] ) );
		echo '</label>';
		printf( '<label class="en-field"><span>Asunto (opcional)</span><input type="text" name="%s" value="%s" class="regular-text"></label>', esc_attr( $nm( 'asunto' ) ), esc_attr( (string) $ed['asunto'] ) );
		echo '</div></div>';
	}

	private static function tab_ai( array $s ): void {
		$a = $s['ai'];
		echo '<h2>IA</h2><p class="description">Se usa para curar (resumir/seleccionar) y para buscar fuentes. Si Estandar Suite tiene credenciales, se reutilizan.</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">Proveedor</th><td><select id="en-provider" name="' . esc_attr( self::name( 'ai[provider]' ) ) . '">';
		foreach ( Settings::providers() as $p ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $p ), selected( $a['provider'], $p, false ), esc_html( ucfirst( $p ) ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">Modelo</th><td><select id="en-model" name="' . esc_attr( self::name( 'ai[modelo]' ) ) . '">';
		echo '<option value="">(por defecto)</option>';
		foreach ( Settings::providers() as $p ) {
			foreach ( Settings::models( $p ) as $m ) {
				printf( '<option value="%s" data-provider="%s"%s>%s</option>', esc_attr( $m ), esc_attr( $p ), selected( $a['modelo'], $m, false ), esc_html( $m ) );
			}
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">API key</th><td>';
		$has = (string) $a['api_key'] !== '';
		printf( '<input type="password" name="%s" value="" class="regular-text" placeholder="%s">', esc_attr( self::name( 'ai[api_key]' ) ), esc_attr( $has ? '•••••••• (configurada)' : 'Pega la API key' ) );
		echo '<p class="description">Se guarda en la base de datos. Nunca se muestra en el HTML.</p></td></tr>';
		echo '</tbody></table>';
	}

	private static function tab_filtros( array $s ): void {
		echo '<h2>Filtros</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">Ruido (excluir)</th><td>';
		printf( '<textarea name="%s" rows="6" class="large-text" spellcheck="false">%s</textarea>', esc_attr( self::name( 'ruido' ) ), esc_textarea( implode( "\n", $s['ruido'] ) ) );
		echo '<p class="description">Una palabra/frase por línea; si aparece en el título, se descarta.</p></td></tr>';
		echo '<tr><th scope="row">Dominios bloqueados</th><td>';
		printf( '<textarea name="%s" rows="3" class="large-text" spellcheck="false">%s</textarea>', esc_attr( self::name( 'blacklist_dominios' ) ), esc_textarea( implode( "\n", $s['blacklist_dominios'] ) ) );
		echo '</td></tr></tbody></table>';
	}

	private static function templates(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo '<script type="text/template" id="en-tpl-tema">';
		self::tema_row( '', array( 'nombre' => '', 'activo' => 1, 'horas' => 24, 'feeds' => array(), 'incluir' => array() ) );
		echo '</script>';
		echo '<script type="text/template" id="en-tpl-edicion">';
		self::edicion_row( '', array( 'titulo' => '', 'activo' => 1, 'perfil' => '', 'temas' => array(), 'destinatarios' => array(), 'asunto' => '', 'max_por_tema' => 0 ), array() );
		echo '</script>';
	}
}
