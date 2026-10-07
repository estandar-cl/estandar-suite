<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Popups v2 — frontend + tracking. Fuente única del sistema de popups
 * (sin CPT legacy `estandar_pop` ni módulo portado).
 *
 * La restricción "ya envió" es POR POPUP: un popup se oculta solo si el
 * navegador envió alguno de los formularios que embebe (o un formulario del
 * mismo proyecto), nunca por un envío global en otra landing.
 */
class PopupsFrontend
{
	const SHORTCODE = 'ep_open';

	/** Mismos prefijos que FormRenderer (cookie/localStorage por form). */
	const DONE_CK = 'estandar_fv2_done_';

	const ASSET_VERSION_FALLBACK = '2.6.4';

	/** Cache por request de los popups aplicables. */
	private static $cache = null;

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_ep_track', array( __CLASS__, 'track_event' ) );
		add_action( 'wp_ajax_nopriv_ep_track', array( __CLASS__, 'track_event' ) );
	}

	public static function asset_url( string $rel ): string
	{
		$base = defined( 'ESTANDAR_SUITE_CAPTURE_FILE' )
			? plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE )
			: trailingslashit( plugin_dir_url( dirname( __DIR__ ) . '/estandar-capture.php' ) );
		return $base . 'assets/' . ltrim( $rel, '/' );
	}

	public static function asset_version(): string
	{
		$ver = defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : self::ASSET_VERSION_FALLBACK;
		// ASSET_VERSION_MTIME_V1: incluir mtime de los assets para invalidar caché.
		$mt = 0;
		foreach ( array( '/../assets/js/engine.v2.js', '/../assets/css/popup.css' ) as $rel ) {
			$file = __DIR__ . $rel;
			if ( is_readable( $file ) ) {
				$m = (int) filemtime( $file );
				if ( $m > $mt ) {
					$mt = $m;
				}
			}
		}
		if ( $mt > 0 ) {
			$ver .= '.' . $mt;
		}
		return $ver;
	}

	/** Popups v2 aplicables a la página actual (segmentación + supresión por popup). */
	public static function items(): array
	{
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$out = array();
		if ( class_exists( __NAMESPACE__ . '\\Popups' ) ) {
			foreach ( Popups::frontend_items() as $it ) {
				if ( ! self::applies( $it ) ) {
					continue;
				}
				if ( self::suppressed( $it ) ) {
					continue;
				}
				$out[] = $it;
			}
		}
		self::$cache = $out;
		return $out;
	}

	/** Segmentación (fechas / post_ids / url_contains) sobre el item normalizado. */
	public static function applies( array $item ): bool
	{
		$seg = ( isset( $item['segmentation'] ) && is_array( $item['segmentation'] ) ) ? $item['segmentation'] : array();
		$now = time();
		if ( ! empty( $seg['date_start'] ) && $now < strtotime( (string) $seg['date_start'] ) ) {
			return false;
		}
		if ( ! empty( $seg['date_end'] ) && $now > strtotime( (string) $seg['date_end'] ) ) {
			return false;
		}

		$has_ids      = ! empty( $seg['post_ids'] );
		$has_patterns = ! empty( $seg['url_contains'] );

		if ( ! $has_ids && ! $has_patterns ) {
			return true; // todo el sitio
		}

		if ( $has_ids && function_exists( 'get_queried_object_id' ) ) {
			$current = (int) get_queried_object_id();
			if ( $current && in_array( $current, array_map( 'intval', (array) $seg['post_ids'] ), true ) ) {
				return true;
			}
		}

		if ( $has_patterns ) {
			$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
			foreach ( (array) $seg['url_contains'] as $pattern ) {
				if ( $pattern !== '' && str_contains( $uri, (string) $pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** IDs de forms v2 embebidos en el contenido del popup (shortcode). */
	public static function embedded_form_ids( string $content ): array
	{
		$ids = array();
		if ( preg_match_all( '/\[estandar_form_v2\b[^\]]*\bid\s*=\s*["\']?(\d+)/i', $content, $m ) ) {
			$ids = array_map( 'intval', $m[1] );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** Scopes de proyecto del popup: config.project_slug + proyecto de cada form embebido. */
	public static function project_scopes( array $item ): array
	{
		$scopes = array();
		if ( ! empty( $item['project_slug'] ) ) {
			$scopes[] = sanitize_title( (string) $item['project_slug'] );
		}
		if ( ! class_exists( __NAMESPACE__ . '\\Forms' ) ) {
			return array_values( array_unique( array_filter( $scopes ) ) );
		}
		foreach ( self::embedded_form_ids( (string) ( $item['content'] ?? '' ) ) as $fid ) {
			$form = Forms::get( (int) $fid );
			if ( ! $form ) {
				continue;
			}
			$cfg = json_decode( (string) ( $form['config_json'] ?? '' ), true );
			$cfg = is_array( $cfg ) ? $cfg : array();
			$scope = FormRenderer::unique_project_scope( $cfg, (string) ( $form['form_type'] ?? 'lead' ) );
			if ( $scope !== '' ) {
				$scopes[] = $scope;
			}
		}
		return array_values( array_unique( array_filter( $scopes ) ) );
	}

	/**
	 * ¿Ocultar el popup porque este navegador ya envió? Solo aplica cuando el
	 * popup declara `hide_if_submitted`. Con scope conocido (form embebido o
	 * proyecto) la decisión es por popup; sin scope, se conserva el criterio
	 * global histórico (cualquier envío).
	 */
	public static function suppressed( array $item ): bool
	{
		if ( empty( $item['hide_if_submitted'] ) ) {
			return false;
		}
		if ( ! class_exists( __NAMESPACE__ . '\\FormRenderer' ) || ! FormRenderer::submitted_restriction_enabled() ) {
			return false;
		}
		$form_ids = self::embedded_form_ids( (string) ( $item['content'] ?? '' ) );
		$scopes   = self::project_scopes( $item );

		if ( ! $form_ids && ! $scopes ) {
			return FormRenderer::done_cookie_set();
		}
		foreach ( $form_ids as $fid ) {
			if ( ! empty( $_COOKIE[ self::DONE_CK . $fid ] ) ) {
				return true;
			}
		}
		foreach ( $scopes as $scope ) {
			if ( FormRenderer::project_done_cookie_set( (string) $scope ) ) {
				return true;
			}
		}
		return false;
	}

	// ─── ENQUEUE ─────────────────────────────────────────────────────────────

	public static function enqueue(): void
	{
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}
		$items = self::items();
		if ( empty( $items ) ) {
			return;
		}

		wp_enqueue_script( 'ep-engine', self::asset_url( 'js/engine.v2.js' ), array(), self::asset_version(), true );
		wp_enqueue_style( 'ep-popup', self::asset_url( 'css/popup.css' ), array(), self::asset_version() );
		// POPUP_BLOCKS_V1: el contenido puede venir del editor de bloques; asegura
		// el CSS de bloques core (columnas, imagen, grupo…).
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'wp-block-library' );
		}

		$payload = array();
		foreach ( $items as $it ) {
			$payload[] = array(
				'id'              => (int) $it['id'],
				'name'            => (string) $it['title'],
				'triggers'        => $it['triggers'],
				'frequency'       => $it['frequency'],
				'design'          => $it['design'],
				'hideIfSubmitted' => ! empty( $it['hide_if_submitted'] ),
				'formIds'         => self::embedded_form_ids( (string) ( $it['content'] ?? '' ) ),
				'projectScopes'   => self::project_scopes( $it ),
			);
		}

		wp_localize_script( 'ep-engine', 'epConfig', array(
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'ep_nonce' ),
			'submittedTtlDays' => class_exists( __NAMESPACE__ . '\\FormRenderer' ) ? FormRenderer::submitted_cookie_days() : 365,
			'popups'           => array_values( $payload ),
		) );
	}

	// ─── RENDER ──────────────────────────────────────────────────────────────

	public static function render(): void
	{
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}
		foreach ( self::items() as $it ) {
			self::render_shell(
				(int) $it['id'],
				self::render_body_content( (string) ( $it['content'] ?? '' ) ),
				(array) $it['design'],
				(string) ( $it['custom_css_scoped'] ?? '' ),
				(string) $it['title'],
				! empty( $it['hide_if_submitted'] ),
				self::embedded_form_ids( (string) ( $it['content'] ?? '' ) ),
				self::project_scopes( $it )
			);
		}
	}

	/**
	 * POPUP_BODY_CONTENT_V1: renderiza el CUERPO del popup sin correr todos los
	 * filtros de `the_content`. Motivo: page-builders (Elementor) enganchan
	 * `the_content` e INYECTAN el contenido de la página actual → el popup
	 * mostraba toda la página encima del form. Solo se corre lo necesario:
	 * bloques (Gutenberg) + wptexturize + shortcodes.
	 */
	/** ORIGIN_POPUP_V1: true mientras se renderiza el contenido de un popup, para
	 *  que FormRenderer marque el form embebido con `_origin=web_popup`. */
	public static $rendering_body = false;

	public static function render_body_content( string $content ): string
	{
		if ( $content === '' ) {
			return '';
		}
		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}
		if ( function_exists( 'wptexturize' ) ) {
			$content = wptexturize( $content );
		}
		if ( function_exists( 'do_shortcode' ) ) {
			$prev = self::$rendering_body;
			self::$rendering_body = true;
			$content = do_shortcode( $content );
			self::$rendering_body = $prev;
		}
		return $content;
	}

	public static function render_shell( int $id, string $content, array $design, string $scoped_css = '', string $title = '', bool $hide_if_submitted = false, array $form_ids = array(), array $project_scopes = array() ): void
	{
		$size      = esc_attr( $design['size']      ?? 'medium' );
		$css_mode  = esc_attr( $design['css_mode']  ?? 'inherit' );
		$z_index   = intval( $design['z_index']     ?? 99999 );

		// POPUP_ANIM_LIB_V1: efecto de entrada y de salida por separado + duración.
		$anim_in  = \Estandar\Capture\Popups::normalize_animation( $design['animation'] ?? 'fade', 'fade' );
		$anim_out = \Estandar\Capture\Popups::normalize_animation( $design['exit_animation'] ?? 'same', 'same' );
		if ( $anim_out === 'same' ) {
			$anim_out = $anim_in;
		}
		$anim_dur = \Estandar\Capture\Popups::normalize_duration( $design['duration'] ?? 0.4 );

		$container_style = '';
		if ( $size === 'custom' && ! empty( $design['custom_width'] ) ) {
			$container_style = 'width:' . esc_attr( $design['custom_width'] ) . ';';
		}

		$overlay_style = '';
		if ( ! empty( $design['overlay'] ) ) {
			$color   = esc_attr( $design['overlay_color']   ?? '#000000' );
			$opacity = floatval( $design['overlay_opacity'] ?? 0.6 );
			$overlay_style = "background:{$color};opacity:{$opacity};";
		}

		$classes = implode( ' ', array(
			'ep-popup',
			"ep-popup--{$size}",
			"ep-popup--in-{$anim_in}",
			"ep-popup--out-{$anim_out}",
			"ep-popup--{$css_mode}",
		) );

		$title_id = "ep-popup-{$id}-title";
		$body_id  = "ep-popup-{$id}-body";
		?>
		<div id="ep-popup-<?php echo (int) $id; ?>"
			 class="<?php echo $classes; ?>"
			 style="z-index:<?php echo $z_index; ?>;--ep-dur:<?php echo esc_attr( $anim_dur ); ?>s;"
			 role="dialog"
			 aria-modal="true"
			 aria-labelledby="<?php echo esc_attr( $title_id ); ?>"
			 aria-describedby="<?php echo esc_attr( $body_id ); ?>">

			<?php if ( ! empty( $design['overlay'] ) ) : ?>
			<div class="ep-popup__overlay"
				 style="<?php echo $overlay_style; ?>"
				 data-ep-close="<?php echo (int) $id; ?>"
				 tabindex="-1"
				 aria-hidden="true"></div>
			<?php endif; ?>

			<div class="ep-popup__container"
				 style="<?php echo $container_style; ?>"
				 tabindex="-1">

				<h2 id="<?php echo esc_attr( $title_id ); ?>" class="ep-sr-only">
					<?php echo esc_html( $title !== '' ? $title : (string) $id ); ?>
				</h2>

				<button class="ep-popup__close"
						data-ep-close="<?php echo (int) $id; ?>"
						aria-label="<?php esc_attr_e( 'Cerrar', 'estandar-suite-capture' ); ?>">&#x2715;</button>

				<div id="<?php echo esc_attr( $body_id ); ?>" class="ep-popup__body">
					<?php echo $content; ?>
				</div>
			</div>

			<?php if ( $scoped_css !== '' ) : ?>
			<style><?php echo wp_strip_all_tags( $scoped_css ); ?></style>
			<?php endif; ?>

		</div>
		<?php
		// POPUP_SKIP_SUBMITTED_V2: elimina el popup del DOM si este navegador ya
		// envió ALGUNO de los formularios del popup (o un form del mismo
		// proyecto). SOLO cuando el popup lo declara (hide_if_submitted).
		// Es un script inline en el HTML fresco (no cacheado): funciona aunque el
		// motor JS viejo quede en caché del navegador.
		if ( $hide_if_submitted && class_exists( __NAMESPACE__ . '\\FormRenderer' ) ) {
			$ttl = FormRenderer::submitted_cookie_days();
			if ( $ttl > 0 ) {
				self::print_suppression_script( $id, $ttl, $form_ids, $project_scopes );
			}
		}
	}

	/** Script inline de supresión por popup (form ids + proyectos). */
	private static function print_suppression_script( int $id, int $ttl_days, array $form_ids, array $project_scopes ): void
	{
		$forms    = wp_json_encode( array_values( array_map( 'intval', $form_ids ) ) );
		$projects = wp_json_encode( array_values( $project_scopes ) );
		?>
		<script>(function(){try{
		  var cutoff=Date.now()-<?php echo (int) $ttl_days; ?>*86400000;
		  var forms=<?php echo $forms; ?>||[], projects=<?php echo $projects; ?>||[];
		  var done=false;
		  function ck(n){return document.cookie.indexOf(n+'=')!==-1;}
		  function ls(n){try{return localStorage.getItem(n);}catch(e){return null;}}
		  try{
		    if(forms.length||projects.length){
		      for(var i=0;i<forms.length;i++){
		        if(ls('es_fv2_done_'+forms[i])){var ts=parseInt(ls('es_fv2_done_'+forms[i]+'_t')||'0',10);if(!ts||ts>=cutoff){done=true;break;}}
		        if(ck('estandar_fv2_done_'+forms[i])){done=true;break;}
		      }
		      if(!done){for(var j=0;j<projects.length;j++){
		        if(ls('es_fv2_done_project_'+projects[j])){done=true;break;}
		        if(ck('estandar_fv2_done_project_'+projects[j])){done=true;break;}
		      }}
		    } else {
		      try{for(var m=0;m<localStorage.length;m++){var kk=localStorage.key(m);if(!kk||kk.indexOf('es_fv2_done_')!==0||/_t$/.test(kk))continue;var t2=parseInt(localStorage.getItem(kk+'_t')||'0',10);if(!t2||t2>=cutoff){done=true;break;}}}catch(e){}
		      if(!done && document.cookie.indexOf('estandar_fv2_done_')!==-1){done=true;}
		    }
		  }catch(e){}
		  if(done){var el=document.getElementById('ep-popup-<?php echo (int) $id; ?>');if(el&&el.parentNode){el.parentNode.removeChild(el);}}
		}catch(e){}})();</script>
		<?php
	}

	// ─── SHORTCODE ─────────────────────────────────────────────────────────────

	public static function shortcode( $atts, string $content = '' ): string
	{
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts );
		$id   = intval( $atts['id'] );
		if ( ! $id ) {
			return esc_html( $content );
		}
		return '<a href="#" data-ep-open="' . esc_attr( $id ) . '">' . esc_html( $content ) . '</a>';
	}

	// ─── TRACKING (admin-ajax ep_track) ────────────────────────────────────────

	public static function track_event(): void
	{
		check_ajax_referer( 'ep_nonce', 'nonce' );

		$id  = isset( $_POST['popup_id'] ) ? intval( $_POST['popup_id'] ) : 0;
		$key = isset( $_POST['counter'] ) ? sanitize_key( (string) $_POST['counter'] ) : '';

		if ( ! $id || ! in_array( $key, array( 'views', 'opens', 'clicks', 'submits' ), true ) ) {
			wp_die();
		}

		if ( class_exists( __NAMESPACE__ . '\\Popups' ) && Popups::is_frontend_id( $id ) ) {
			Popups::increment_stat( Popups::frontend_row_id( $id ), $key );
		}
		wp_die();
	}
}
