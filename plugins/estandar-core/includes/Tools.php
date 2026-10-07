<?php
/**
 * Tools — Herramientas de administración de la Suite (portado de estandar-inicio).
 *
 * Dos capacidades, ambas CONFIGURABLES por sitio (nada hardcodeado):
 *   1. Subida de SVG a la biblioteca, solo administradores, con **allowlist DOM**
 *      (no blocklist por regex): se elimina todo tag/atributo fuera de la lista.
 *   2. Generación de child theme desde un tema padre.
 *
 * @package Estandar\Core
 */

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Herramientas de admin (SVG + child theme) con switches de settings.
 */
class Tools {

	/** Opción de settings de las herramientas. */
	public const OPTION = 'estandar_suite_tools';

	/** Acción admin-post: guardar switches. */
	public const ACTION_SAVE = 'estandar_suite_tools_save';

	/** Acción admin-post: generar child theme. */
	public const ACTION_CHILD = 'estandar_suite_tools_child_theme';

	/**
	 * Tags SVG permitidos (allowlist). Fuera de esta lista, se elimina el nodo.
	 *
	 * @var array
	 */
	private const SVG_TAGS = array(
		'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textPath', 'marker',
		'linearGradient', 'radialGradient', 'stop', 'pattern',
		'clipPath', 'mask', 'filter', 'image',
		'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix', 'feComposite',
		'feFlood', 'feMerge', 'feMergeNode', 'feMorphology', 'feTile',
	);

	/**
	 * Atributos permitidos (allowlist global; se aplica a cualquier tag).
	 *
	 * @var array
	 */
	private const SVG_ATTRS = array(
		'id', 'class', 'role', 'aria-hidden', 'aria-label', 'focusable',
		'd', 'points', 'pathLength',
		'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'dx', 'dy',
		'viewBox', 'preserveAspectRatio', 'version', 'transform', 'rotate', 'transform-origin',
		'fill', 'fill-opacity', 'fill-rule', 'fill-rule', 'stroke', 'stroke-width',
		'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
		'stroke-dashoffset', 'stroke-opacity', 'opacity', 'color',
		'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
		'patternUnits', 'patternContentUnits', 'patternTransform', 'clipPathUnits', 'maskUnits',
		'clip-path', 'clip-rule', 'mask', 'overflow', 'display', 'visibility',
		'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
		'letter-spacing', 'word-spacing', 'dominant-baseline', 'alignment-baseline',
		'textLength', 'lengthAdjust', 'style',
		'href', 'xlink:href', 'xmlns', 'xmlns:xlink', 'xml:space', 'preserveAspectRatio',
		'stdDeviation', 'in', 'in2', 'result', 'mode', 'values', 'type', 'tableValues',
	);

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CHILD, array( __CLASS__, 'handle_child_theme' ) );

		if ( self::enabled( 'svg' ) ) {
			add_filter( 'upload_mimes', array( __CLASS__, 'svg_mimes' ) );
			add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'svg_fix_filetype' ), 10, 4 );
			add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'svg_prefilter' ) );
		}
	}

	// ── Settings ────────────────────────────────────────────────────────────

	/**
	 * Valores por defecto (SVG OFF: la seguridad primero; child theme ON).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'svg'         => false,
			'child_theme' => true,
		);
	}

	/**
	 * Settings efectivos (con defaults y normalización).
	 *
	 * @return array
	 */
	public static function settings(): array {
		$raw  = get_option( self::OPTION, array() );
		$raw  = is_array( $raw ) ? $raw : array();
		$def  = self::defaults();
		$out  = array();
		foreach ( $def as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $raw ) ? (bool) $raw[ $k ] : $v;
		}
		return $out;
	}

	/**
	 * ¿Está habilitada una capacidad?
	 *
	 * @param string $key svg|child_theme.
	 * @return bool
	 */
	public static function enabled( string $key ): bool {
		$s = self::settings();
		return ! empty( $s[ $key ] );
	}

	/**
	 * Guarda los switches (solo las claves conocidas).
	 *
	 * @param array $in Datos de entrada.
	 * @return array
	 */
	public static function save( array $in ): array {
		$out = array();
		foreach ( array_keys( self::defaults() ) as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] );
		}
		update_option( self::OPTION, $out );
		return $out;
	}

	// ── SVG ─────────────────────────────────────────────────────────────────

	/**
	 * Habilita el mime SVG (solo administradores).
	 *
	 * @param array $mimes Mimes.
	 * @return array
	 */
	public static function svg_mimes( $mimes ) {
		if ( current_user_can( 'manage_options' ) ) {
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Corrige el tipo detectado para SVG (WP lo bloquea por defecto).
	 *
	 * @param array  $data     Datos de tipo.
	 * @param string $file     Ruta temporal.
	 * @param string $filename Nombre original.
	 * @param array  $mimes    Mimes permitidos.
	 * @return array
	 */
	public static function svg_fix_filetype( $data, $file, $filename, $mimes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $data;
		}
		if ( 'svg' === strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Sanitiza el SVG antes de guardarlo; rechaza si no pasa la allowlist.
	 *
	 * @param array $file Datos del archivo.
	 * @return array
	 */
	public static function svg_prefilter( $file ) {
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		if ( 'svg' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return $file;
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $file;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$file['error'] = __( 'Solo administradores pueden subir SVG.', 'estandar-suite-core' );
			return $file;
		}

		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			$file['error'] = __( 'SVG ilegible.', 'estandar-suite-core' );
			return $file;
		}

		$clean = self::sanitize_svg( (string) $raw );
		if ( false === $clean ) {
			$file['error'] = __( 'SVG rechazado por seguridad (contenido no permitido).', 'estandar-suite-core' );
			return $file;
		}

		file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $file;
	}

	/**
	 * Sanitiza un SVG con **allowlist DOM** (no blocklist): parsea el XML, elimina
	 * todo nodo cuyo tag no esté permitido, y todo atributo fuera de la lista o
	 * con valor peligroso. Devuelve false si no se puede parsear.
	 *
	 * @param string $svg Contenido SVG.
	 * @return string|false
	 */
	public static function sanitize_svg( string $svg ) {
		$svg = trim( $svg );
		if ( '' === $svg || false === stripos( $svg, '<svg' ) ) {
			return false;
		}

		// Sin DTD ni entidades: corta XXE y trucos de entidad de raíz.
		if ( preg_match( '#<!DOCTYPE|<!ENTITY#i', $svg ) ) {
			return false;
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		// LIBXML_NONET: sin acceso a red (evita XXE por DTD externo).
		// NO se usa LIBXML_NOENT (no expandir entidades).
		$ok = $doc->loadXML( $svg, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $doc->documentElement ) {
			return false;
		}

		self::scrub_node( $doc->documentElement );

		$out = $doc->saveXML( $doc->documentElement );
		if ( false === $out || '' === trim( $out ) ) {
			return false;
		}

		// Red de seguridad final: no debe quedar script ni esquema peligroso.
		if ( preg_match( '#<script|javascript:|<\s*foreignObject#i', $out ) ) {
			return false;
		}

		return $out;
	}

	/**
	 * Limpia recursivamente un nodo (allowlist de tags y atributos).
	 *
	 * @param \DOMNode $node Nodo.
	 * @return void
	 */
	private static function scrub_node( \DOMNode $node ): void {
		if ( ! $node->hasChildNodes() ) {
			return;
		}

		// Copia de hijos: se modificará la lista durante el recorrido.
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$tag = $child->localName ? $child->localName : $child->nodeName;

				if ( ! in_array( $tag, self::SVG_TAGS, true ) ) {
					$node->removeChild( $child );
					continue;
				}

				self::scrub_attributes( $child );
				self::scrub_node( $child );
				continue;
			}

			if ( XML_COMMENT_NODE === $child->nodeType || XML_PI_NODE === $child->nodeType
				|| XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				$node->removeChild( $child );
			}
		}
	}

	/**
	 * Elimina atributos fuera de la allowlist o con valores peligrosos.
	 *
	 * @param \DOMElement $el Elemento.
	 * @return void
	 */
	private static function scrub_attributes( \DOMElement $el ): void {
		if ( ! $el->hasAttributes() ) {
			return;
		}

		$remove = array();
		foreach ( $el->attributes as $attr ) {
			$name = $attr->nodeName;
			$lname = strtolower( $name );
			$value = (string) $attr->nodeValue;

			// Namespaces declarados: se conservan tal cual.
			if ( 0 === strpos( $lname, 'xmlns' ) ) {
				continue;
			}

			// Atributos de evento (onload, onclick, ...) y data-* fuera.
			if ( 0 === strpos( $lname, 'on' ) ) {
				$remove[] = $name;
				continue;
			}

			// href/xlink:href: solo referencias internas (#id).
			if ( 'href' === $lname || 'xlink:href' === $lname ) {
				if ( '#' !== substr( ltrim( $value ), 0, 1 ) ) {
					$remove[] = $name;
				}
				continue;
			}

			if ( ! in_array( $name, self::SVG_ATTRS, true ) && ! in_array( $lname, array_map( 'strtolower', self::SVG_ATTRS ), true ) ) {
				$remove[] = $name;
				continue;
			}

			if ( ! self::attr_value_ok( $value ) ) {
				$remove[] = $name;
			}
		}

		foreach ( $remove as $name ) {
			$el->removeAttribute( $name );
		}
	}

	/**
	 * ¿El valor de un atributo es seguro? (bloquea esquemas y url() externos).
	 *
	 * @param string $v Valor.
	 * @return bool
	 */
	private static function attr_value_ok( string $v ): bool {
		if ( preg_match( '#javascript\s*:#i', $v ) ) {
			return false;
		}
		if ( preg_match( '#expression\s*\(#i', $v ) ) {
			return false;
		}
		if ( preg_match( '#@import#i', $v ) ) {
			return false;
		}
		if ( preg_match( '#behavior\s*:#i', $v ) ) {
			return false;
		}
		// url(...) solo si apunta a un fragmento interno (#id).
		if ( preg_match_all( '#url\s*\(\s*(["\']?)([^)"\']*)\1\s*\)#i', $v, $m ) ) {
			foreach ( $m[2] as $target ) {
				if ( '#' !== substr( ltrim( (string) $target ), 0, 1 ) ) {
					return false;
				}
			}
		}
		// Sin esquemas de datos/red en valores.
		if ( preg_match( '#\b(data|https?|ftp)\s*:#i', $v ) ) {
			return false;
		}
		return true;
	}

	// ── Child theme ─────────────────────────────────────────────────────────

	/**
	 * Crea un child theme (style.css + functions.php + screenshot) desde un padre.
	 * No sobreescribe carpetas existentes.
	 *
	 * @param string     $slug        Slug/carpeta.
	 * @param string     $name        Nombre.
	 * @param string     $parent      Slug del padre.
	 * @param string     $author      Autor.
	 * @param string     $description Descripción.
	 * @param array|null $shot        Archivo subido (o null).
	 * @return bool
	 */
	public static function create_child_theme( string $slug, string $name, string $parent, string $author, string $description, $shot = null ): bool {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || '' === $name ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}

		$themes = wp_get_themes();
		if ( ! isset( $themes[ $parent ] ) ) {
			return false;
		}

		$dir = trailingslashit( get_theme_root() ) . $slug;
		if ( is_dir( $dir ) ) {
			return false;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		$version = defined( 'ESTANDAR_SUITE_CORE_VERSION' ) ? ESTANDAR_SUITE_CORE_VERSION : '1.0.0';

		$style = "/*\n"
			. 'Theme Name: ' . $name . "\n"
			. 'Template: ' . $parent . "\n"
			. ( '' !== $author ? 'Author: ' . $author . "\n" : '' )
			. ( '' !== $description ? 'Description: ' . $description . "\n" : '' )
			. 'Version: ' . $version . "\n"
			. 'Text Domain: ' . $slug . "\n"
			. "*/\n";

		$functions = "<?php\n"
			. "// Estandar Suite — child theme de {$parent}.\n"
			. "add_action( 'wp_enqueue_scripts', function () {\n"
			. "    wp_enqueue_style( '{$slug}-parent', get_template_directory_uri() . '/style.css', array(), wp_get_theme( get_template() )->get( 'Version' ) );\n"
			. "} );\n";

		if ( ! $wp_filesystem->mkdir( $dir ) ) {
			return false;
		}

		$ok = $wp_filesystem->put_contents( $dir . '/style.css', $style )
			&& $wp_filesystem->put_contents( $dir . '/functions.php', $functions );
		if ( ! $ok ) {
			return false;
		}

		$saved = ( is_array( $shot ) && ! empty( $shot['tmp_name'] ) && empty( $shot['error'] ) && is_uploaded_file( $shot['tmp_name'] ) )
			? self::save_screenshot( $shot, $dir )
			: false;

		if ( ! $saved ) {
			$default = ESTANDAR_SUITE_CORE_PATH . 'includes/assets/screenshot-default.png';
			if ( file_exists( $default ) ) {
				$wp_filesystem->copy( $default, $dir . '/screenshot.png' );
			}
		}

		return true;
	}

	/**
	 * Guarda el screenshot subido como screenshot.<ext> (png/jpg/gif), con límite.
	 *
	 * @param array  $shot Datos del archivo.
	 * @param string $dir  Directorio del child theme.
	 * @return bool
	 */
	private static function save_screenshot( array $shot, string $dir ): bool {
		$max = 2 * 1024 * 1024; // 2 MB.
		if ( isset( $shot['size'] ) && (int) $shot['size'] > $max ) {
			return false;
		}

		$info = @getimagesize( $shot['tmp_name'] );
		if ( ! $info ) {
			return false;
		}

		$map = array(
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_GIF  => 'gif',
		);
		if ( ! isset( $map[ $info[2] ] ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->copy( $shot['tmp_name'], $dir . '/screenshot.' . $map[ $info[2] ] );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	/**
	 * Guarda los switches de herramientas.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'estandar-suite-core' ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		self::save(
			array(
				'svg'         => isset( $_POST['tools_svg'] ),
				'child_theme' => isset( $_POST['tools_child_theme'] ),
			)
		);

		wp_safe_redirect( self::url( 'saved' ) );
		exit;
	}

	/**
	 * Procesa el formulario de child theme.
	 *
	 * @return void
	 */
	public static function handle_child_theme(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'estandar-suite-core' ) );
		}
		check_admin_referer( self::ACTION_CHILD );

		if ( ! self::enabled( 'child_theme' ) ) {
			wp_safe_redirect( self::url( 'disabled' ) );
			exit;
		}

		$parent      = isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '';
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug        = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$author      = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		$shot = ( isset( $_FILES['screenshot'] ) && is_array( $_FILES['screenshot'] ) && ! empty( $_FILES['screenshot']['name'] ) )
			? $_FILES['screenshot'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: null;

		$ok = self::create_child_theme( $slug, $name, $parent, $author, $description, $shot );

		wp_safe_redirect( self::url( $ok ? 'child_ok' : 'child_err' ) );
		exit;
	}

	/**
	 * URL de la pantalla Herramientas con aviso.
	 *
	 * @param string $notice Aviso.
	 * @return string
	 */
	private static function url( string $notice ): string {
		return add_query_arg(
			array(
				'page'         => 'estandar-suite',
				'tab'          => 'avanzado',
				'sub'          => 'herramientas',
				'tools_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
	}

	// ── Render ──────────────────────────────────────────────────────────────

	/**
	 * Render de la pantalla Herramientas.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = self::settings();
		$notice = isset( $_GET['tools_notice'] ) ? sanitize_key( wp_unslash( $_GET['tools_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'saved'     => array( 'success', 'Herramientas guardadas.' ),
			'child_ok'  => array( 'success', 'Child theme generado.' ),
			'child_err' => array( 'error', 'No se pudo generar el child theme (revisa nombre/slug, permisos o si ya existe).' ),
			'disabled'  => array( 'error', 'El generador de child theme está desactivado.' ),
		);
		if ( '' !== $notice && isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
		}
		?>
		<h2 style="margin-top:6px;">Herramientas</h2>
		<p class="description" style="max-width:820px;">
			Capacidades de administración de la suite. Ambas son <strong>configurables por sitio</strong>.
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;margin:12px 0 22px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<?php wp_nonce_field( self::ACTION_SAVE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">SVG en la biblioteca</th>
					<td>
						<label><input type="checkbox" name="tools_svg" value="1" <?php checked( $s['svg'] ); ?> /> Permitir subir SVG (solo administradores)</label>
						<p class="description">Los SVG se validan en la subida con una <strong>lista blanca</strong>: se eliminan scripts, eventos <code>on*</code>, referencias externas y estilos peligrosos. Si algo no pasa, el archivo se rechaza.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Generador de child theme</th>
					<td>
						<label><input type="checkbox" name="tools_child_theme" value="1" <?php checked( $s['child_theme'] ); ?> /> Permitir generar child themes desde un tema padre</label>
						<p class="description">Crea <code>style.css</code> + <code>functions.php</code> en una carpeta nueva (nunca sobreescribe).</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar herramientas', 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $s['child_theme'] ) : ?>
			<h3>Generar child theme</h3>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHILD ); ?>" />
				<?php wp_nonce_field( self::ACTION_CHILD ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="es_parent">Tema padre</label></th>
						<td>
							<select name="parent" id="es_parent" required>
								<?php foreach ( wp_get_themes() as $slug => $theme ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $theme->get( 'Name' ) . ' (' . $slug . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="es_name">Nombre del child</label></th>
						<td><input name="name" id="es_name" type="text" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="es_slug">Slug (carpeta)</label></th>
						<td><input name="slug" id="es_slug" type="text" class="regular-text" placeholder="mi-child" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="es_author">Autor</label></th>
						<td><input name="author" id="es_author" type="text" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="es_desc">Descripción</label></th>
						<td><input name="description" id="es_desc" type="text" class="large-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="es_shot">Imagen (screenshot)</label></th>
						<td>
							<input name="screenshot" id="es_shot" type="file" accept="image/png,image/jpeg,image/gif" />
							<p class="description">Opcional (máx. 2 MB, PNG/JPG/GIF). Si no subes una, se usa una por defecto.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Generar child theme' ); ?>
			</form>
		<?php else : ?>
			<p class="description">El generador de child theme está desactivado en este sitio.</p>
		<?php endif; ?>
		<?php
	}
}
