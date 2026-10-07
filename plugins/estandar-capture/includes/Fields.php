<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fields — render nativo + validación server-side de campos de formulario v2.
 *
 * F1 · cierra la brecha del renderer lead: los tipos que legacy renderizaba
 * de forma nativa (rut, file, heading, html, hidden, dependent_select,
 * project_select, cards, emoji, rating, matrix, nps) dejan de degradar a
 * texto. Fuente única de render y validación para el FormRenderer.
 *
 * El render usa HTML estándar/ARIA accesible sin JS obligatorio (progressive
 * enhancement): los widgets avanzados se construyen como controles nativos
 * (radios/inputs/selects) que el browser entiende siempre; el JS opcional solo
 * mejora la UX (formato RUT, token reCAPTCHA, dependent select por AJAX).
 */
class Fields
{
	/** Sufijo de instancia de render (ids de campo únicos por instancia). */
	public static $instance = '';

	/** LABELS_IN_PLACEHOLDER_V1: usar el label como placeholder (form compacto). */
	public static $compact_labels = false;

	/** id DOM del campo, único por instancia de formulario (el `name` no cambia). */
	private static function dom_id( string $name ): string {
		return self::$instance !== '' ? $name . '-' . self::$instance : $name;
	}

	/** Tipos que tienen render nativo (no degradan). */
	const NATIVE_TYPES = array(
		'text', 'email', 'tel', 'phone', 'number', 'url', 'date', 'time',
		'textarea', 'select', 'radio', 'checkbox', 'file', 'hidden',
		'rut', 'heading', 'html', 'project_select', 'dependent_select',
		'cards', 'emoji', 'rating', 'matrix', 'nps', 'routing',
	);

	const MIME_ALLOWED = array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
	const MAX_FILE_BYTES = 5242880; // 5MB

	/** Registra el AJAX de dependent_select (sobrevive al retiro del legacy). */
	public static function register(): void
	{
		if ( class_exists( 'Estandar_Forms_Dependent_Select', false ) ) {
			return; // legacy activo: su endpoint manda.
		}
		add_action( 'wp_ajax_ef_get_streets', array( __CLASS__, 'ajax_get_streets' ) );
		add_action( 'wp_ajax_nopriv_ef_get_streets', array( __CLASS__, 'ajax_get_streets' ) );
	}

	public static function ajax_get_streets(): void
	{
		// AJAX_NONCE_JSON_V1 (2026-10-02): un nonce inválido antes hacía
		// wp_die('-1') → el `JSON.parse` del cliente explotaba. Ahora responde
		// JSON de error (200) para que el frontend lo maneje con gracia.
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['nonce'] ) ) : '';
		if ( function_exists( 'wp_verify_nonce' ) && ! wp_verify_nonce( $nonce, 'ef_get_streets' ) ) {
			if ( function_exists( 'wp_send_json_error' ) ) {
				wp_send_json_error( array( 'message' => 'invalid_nonce' ) );
			}
			return;
		}
		$project_id = isset( $_GET['project_id'] )
			? sanitize_text_field( (string) wp_unslash( $_GET['project_id'] ) )
			: '';
		$streets = $project_id !== '' ? self::streets_for_project( $project_id ) : array();
		wp_send_json_success( array( 'streets' => array_values( $streets ) ) );
	}

	/** Calles de un proyecto para dependent_select (graceful sin Core). */
	public static function streets_for_project( string $project_id ): array
	{
		if ( $project_id === '' ) {
			return array();
		}
		// Resuelve el valor del select (slug "parronales" O label completo
		// "LOS PARRONALES-NANCAGUA") contra el Project Registry. Esto permite
		// que el survey postventa use las opciones de proyecto tal cual GF.
		$resolved = self::resolve_project_id( $project_id );
		if ( function_exists( 'estandar_core_get_project_streets' ) ) {
			$streets = estandar_core_get_project_streets( $resolved );
			if ( is_array( $streets ) && $streets ) {
				return array_values( $streets );
			}
		}
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}estandar_project_registry'" ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT streets_json FROM {$wpdb->prefix}estandar_project_registry WHERE project_id = %s OR label = %s LIMIT 1",
				$resolved, $project_id
			), ARRAY_A );
			if ( is_array( $row ) && ! empty( $row['streets_json'] ) ) {
				$streets = json_decode( $row['streets_json'], true );
				return is_array( $streets ) ? array_values( $streets ) : array();
			}
		}
		return array();
	}

	/** Clave normalizada (minúsculas, sin acentos ni separadores) para mapear. Puro. */
	public static function norm_key( string $s ): string
	{
		$s = trim( $s );
		if ( $s === '' ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return (string) preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/**
	 * DEPENDENT_INLINE_MAP_V1 / STREETS_MAP_CITY_V1: mapa normalizado
	 * `valor-de-proyecto → [calles]` desde el Project Registry. Se embebe inline
	 * en el form para poblar el select dependiente SIN AJAX/nonce (a prueba de
	 * caché: antes el nonce iba dentro del HTML cacheado y expiraba → el select
	 * quedaba vacío de forma intermitente).
	 *
	 * STREETS_MAP_CITY_V1 (2026-10-02): además de `project_id` y `label`, se
	 * indexa `project_id + city` y `label + city`. Esto reconcilia el value de
	 * las options del form (p. ej. `los_parronales_nancagua`) con la fila del
	 * registry (`parronales` / `Los Parronales` / `Nancagua`), que antes no
	 * coincidía y dejaba el select vacío. Solo AGREGA claves: el resto del
	 * contrato (valores posteados → webhook/Sheet) queda intacto.
	 */
	public static function streets_map(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $wpdb->prefix ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'estandar_project_registry';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT project_id, label, city, streets_json FROM {$table}", ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$streets = json_decode( (string) ( $r['streets_json'] ?? '' ), true );
			if ( ! is_array( $streets ) || ! $streets ) {
				continue;
			}
			$streets = array_values( array_filter( array_map( 'strval', $streets ) ) );
			if ( ! $streets ) {
				continue;
			}
			$pid   = (string) ( $r['project_id'] ?? '' );
			$label = (string) ( $r['label'] ?? '' );
			$city  = (string) ( $r['city'] ?? '' );
			$keys  = array( $pid, $label );
			if ( $city !== '' ) {
				$keys[] = trim( $pid . ' ' . $city );
				$keys[] = trim( $label . ' ' . $city );
			}
			foreach ( $keys as $key ) {
				$k = self::norm_key( $key );
				if ( $k === '' ) {
					continue;
				}
				// Anti-colisión (STREETS_MAP_COLLISION_V1): no sobrescribir en
				// silencio una clave ya asignada con otra lista de calles →
				// conserva la primera y avisa por hook (sin romper la salida).
				if ( ! isset( $map[ $k ] ) ) {
					$map[ $k ] = $streets;
				} elseif ( $map[ $k ] !== $streets ) {
					do_action( 'estandar_capture_streets_map_collision', $k, $streets, $map[ $k ], $r );
				}
			}
		}
		return $map;
	}

	/** Normaliza el valor del select de proyecto a un project_id del registry. */
	public static function resolve_project_id( string $value ): string
	{
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		$slug = sanitize_title( $value );
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}estandar_project_registry'" ) ) {
			// El registry puede usar guiones ("villa-el-estero-casas") mientras el
			// form usa guion bajo ("villa_el_estero_casas"). Se prueban ambas
			// variantes y la coincidencia de label sin distinguir mayúsculas.
			$candidates = array_unique( array_filter( array( $slug, str_replace( '_', '-', $slug ) ) ) );
			foreach ( $candidates as $cand ) {
				$found = $wpdb->get_var( $wpdb->prepare(
					"SELECT project_id FROM {$wpdb->prefix}estandar_project_registry WHERE project_id = %s LIMIT 1",
					$cand
				) );
				if ( $found ) {
					return (string) $found;
				}
			}
			$rows = $wpdb->get_results( "SELECT project_id, label, city FROM {$wpdb->prefix}estandar_project_registry", ARRAY_A );
			$norm = function ( $s ) {
				// RESOLVE_ACCENTS_V1: la normalización quita acentos (remove_accents)
				// para que un value con ñ ('LOS VIÑEDOS') calce con su slug ascii
				// ('los_vinedos') y viceversa.
				$s = function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s;
				$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
				return (string) preg_replace( '/[\s_\-]+/u', '', $s );
			};
			$vn = $norm( $value );
			$sn = $norm( $slug );
			foreach ( (array) $rows as $r ) {
				$pid   = (string) ( $r['project_id'] ?? '' );
				$label = (string) ( $r['label'] ?? '' );
				$city  = (string) ( $r['city'] ?? '' );
				$cands = array( $pid, $label );
				if ( $city !== '' ) {
					$cands[] = trim( $pid . ' ' . $city );
					$cands[] = trim( $label . ' ' . $city );
				}
				foreach ( $cands as $cand ) {
					$cn = $norm( $cand );
					if ( $cn !== '' && ( $cn === $vn || $cn === $sn ) ) {
						return $pid;
					}
				}
			}
			$found2 = $wpdb->get_var( $wpdb->prepare(
				"SELECT project_id FROM {$wpdb->prefix}estandar_project_registry WHERE project_id = %s OR label = %s LIMIT 1",
				$slug, $value
			) );
			if ( $found2 ) {
				return (string) $found2;
			}
		}
		return $slug;
	}

	/**
	 * Render de un campo. @return string '' si el campo es inválido.
	 */
	public static function render( array $f ): string
	{
		$name     = sanitize_key( (string) ( $f['name'] ?? '' ) );
		$label    = trim( (string) ( $f['label'] ?? '' ) );
		$type     = strtolower( (string) ( $f['type'] ?? 'text' ) );
		$required = ! empty( $f['required'] ) ? ' required' : '';
		$compact = self::$compact_labels && $label !== '';
		$aria = $compact ? ' aria-label="' . esc_attr( $label ) . '"' : '';
		$ph   = $compact ? ' placeholder="' . esc_attr( $label ) . '"' : '';

		// LABEL_NAME_FALLBACK_V1: los campos de captura SIEMPRE renderizan. Si
		// faltan `name` o `label` (form legacy/migrado, o guardado sin etiqueta),
		// se derivan de forma determinística: name desde el label, label desde el
		// name humanizado. El maquetador normaliza ambos al guardar; esto es la
		// red de seguridad para que en producción un campo nunca se oculte en
		// silencio por faltarle la etiqueta.
		$no_name_types = array( 'heading', 'html' );
		if ( ! in_array( $type, $no_name_types, true ) && $name === '' ) {
			$name = $label !== '' ? sanitize_key( $label ) : sanitize_key( $type . '_campo' );
		}
		if ( ! in_array( $type, $no_name_types, true ) && $label === '' && $name !== '' ) {
			$label = ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
		}
		// El label se resuelve DESPUÉS del fallback: así un campo sin etiqueta
		// (legacy/migrado) nunca queda sin label visible en el formulario.
		$lbl = $compact ? '' : self::label_html( $label, $name, $required );
		// Heading sin texto no aporta nada visible → no renderizar.
		if ( $type === 'heading' && $label === '' ) {
			return '';
		}
		// COLUMN_WIDTH_V2: ancho del campo. Legacy 1-4 → 25/50/75/100 %; nuevo:
		// número = porcentaje directo (ej. 33.3, 50, 66.6, 100).
		// DEFAULT_ANCHO_V1: sin ancho configurado → 100% (línea completa).
		$col_style = '';
		$cw = $f['column_width'] ?? 100;
		if ( is_numeric( $cw ) && (int) $cw >= 1 && (int) $cw <= 4 ) {
			$pct = round( 100 * ( (int) $cw / 4 ) );
		} else {
			$pct = (float) $cw;
			if ( $pct <= 0 || $pct > 100 ) {
				$pct = 100;
			}
		}
			$col_style = ' style="box-sizing:border-box;width:' . rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) . '%;float:left;padding:0 4px;margin-bottom:6px;"';
		// CONDICIONAL_V1: 'mostrar si campo = valor' (progressive disclosure,
		// mismo patrón que survey). Emite data-es-show-when para que el JS
		// del form muestre/oculte este campo según el valor del campo origen.
		$col_style .= self::show_when_attr( $f );

		// Re-poblado transversal: si el form devuelve un error de validación,
		// el valor ya enviado se conserva (value/selected/checked) para que el
		// usuario corrija solo el campo erróneo, sin reescribir el resto.
		$posted_value = self::posted_value( $name, $type );

		// DEFAULT_TODAY_V1 (2026-09-22): atributo genérico `default_today` — en un
		// campo `date`, si el usuario no ha enviado valor, se precarga la fecha
		// actual del sitio (zona horaria de WP). Genérico: aplica a cualquier form.
		if ( $type === 'date' && ! empty( $f['default_today'] ) && $posted_value === '' ) {
			$posted_value = (string) current_time( 'Y-m-d' );
		}
		// READONLY_V1 (2026-09-22): atributo genérico `readonly` — el campo se
		// renderiza no editable pero conserva `name`, así el valor SÍ se envía
		// (se usa `readonly`, nunca `disabled`, que excluiría el campo del POST).
		$ro = ! empty( $f['readonly'] ) ? ' readonly aria-readonly="true"' : '';

		// Advertencia inline por tipo de campo (nombre/email/teléfono) + patrón.
		$vattrs = self::validation_attrs( $f, $type );

		switch ( $type ) {
			case 'heading':
				$level = (string) ( $f['level'] ?? 'h3' );
				if ( ! in_array( $level, array( 'h1', 'h2', 'h3', 'h4' ), true ) ) {
					$level = 'h3';
				}
				$align = sanitize_key( (string) ( $f['align'] ?? 'left' ) );
				return '<div' . $col_style . '><' . $level . ' class="estandar-form-v2-heading" style="text-align:' . esc_attr( $align ) . ';margin:16px 0 8px;">' . esc_html( $label ) . '</' . $level . '></div>';

			case 'html':
				$content = (string) ( $f['html'] ?? ( $f['content'] ?? '' ) );
				return '<div' . $col_style . ' class="estandar-form-v2-html">' . wp_kses_post( $content ) . '</div>';

			case 'hidden':
				$value = (string) ( $f['value'] ?? ( $f['default_value'] ?? '' ) );
				return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';

			case 'textarea':
				$max = (int) ( $f['max_length'] ?? 5000 );
				return '<div' . $col_style . '>' . $lbl
					. '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" rows="4" maxlength="' . $max . '" style="width:100%;"' . $ro . $required . '>' . esc_textarea( $posted_value ) . '</textarea></div>';

			case 'select':
			case 'routing':
				return '<div' . $col_style . '>' . $lbl
					. self::select_html( $f, $name, $required, $posted_value ) . '</div>';

			case 'radio':
			case 'cards':
			case 'emoji':
				return '<div' . $col_style . '>' . $lbl
					. self::choice_group_html( $f, $name, $required, $posted_value ) . '</div>';

			case 'checkbox':
				$checked = ( $posted_value === '1' || $posted_value !== '' ) ? ' checked' : '';
				return '<div' . $col_style . '>' . $lbl
					. '<label style="display:block;"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $checked . $required . '> ' . esc_html( $label !== '' ? $label : $name ) . '</label></div>';

			case 'rating':
				return '<div' . $col_style . '>' . $lbl
					. self::rating_html( $f, $name, $required ) . '</div>';

			case 'matrix':
				return '<div' . $col_style . '>' . $lbl
					. self::matrix_html( $f, $name, $required ) . '</div>';

			case 'nps':
				return '<div' . $col_style . '>' . $lbl
					. self::nps_html( $f, $name, $required ) . '</div>';

			case 'file':
				return '<div' . $col_style . '>' . $lbl
					. '<input type="file" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" accept="' . esc_attr( implode( ',', array_map( static function ( $m ) {
						return '.' . $m;
					}, self::MIME_ALLOWED ) ) ) . '"' . $required . '>'
					. '<small>Archivos permitidos: ' . esc_html( implode( ', ', self::MIME_ALLOWED ) ) . ' · máx 5MB</small></div>';

			case 'rut':
				return '<div' . $col_style . '>' . $lbl
					. '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" class="estandar-rut-field" placeholder="12.345.678-9" maxlength="12" value="' . esc_attr( $posted_value ) . '" style="width:100%;"' . $required . '></div>';

			case 'project_select':
				return '<div' . $col_style . '>' . $lbl
					. self::project_select_html( $f, $name, $required, $posted_value ) . '</div>';

			case 'dependent_select':
				return '<div' . $col_style . '>' . $lbl
					. self::dependent_select_html( $f, $name, $required, $posted_value ) . '</div>';

			case 'url':
				return '<div' . $col_style . '>' . $lbl
					. '<input type="url" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" value="' . esc_attr( $posted_value ) . '" style="width:100%;"' . $required . '></div>';

			case 'tel':
			case 'phone':
				$country = sanitize_key( (string) ( $f['country'] ?? 'CL' ) );
				return '<div' . $col_style . '>' . $lbl
					. '<input type="tel" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" class="estandar-phone-field" autocomplete="tel"' . $aria . $ph . ' data-country="' . esc_attr( $country ) . '" value="' . esc_attr( $posted_value ) . '" style="width:100%;"' . $vattrs . $required . '></div>';

			case 'text':
			case 'email':
			case 'number':
			case 'date':
			case 'time':
			default:
				$input_type = in_array( $type, array( 'text', 'email', 'number', 'date', 'time' ), true ) ? $type : 'text';
				$max        = (int) ( $f['max_length'] ?? 255 );
				$ac = 'off';
				$ln = strtolower( (string) $name );
				if ( 'email' === $input_type || false !== strpos( $ln, 'email' ) || false !== strpos( $ln, 'correo' ) ) { $ac = 'email'; }
				elseif ( false !== strpos( $ln, 'apellido' ) ) { $ac = 'family-name'; }
				elseif ( false !== strpos( $ln, 'nombre' ) ) { $ac = 'given-name'; }
				elseif ( false !== strpos( $ln, 'fono' ) || false !== strpos( $ln, 'telef' ) ) { $ac = 'tel'; }
				return '<div' . $col_style . '>' . $lbl
					. '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" maxlength="' . $max . '" autocomplete="' . esc_attr( $ac ) . '"' . $aria . $ph . ' value="' . esc_attr( $posted_value ) . '" style="width:100%;"' . $vattrs . $ro . $required . '></div>';
		}
	}

	/** ¿El tipo tiene render nativo en capture (paridad oracle)? */
	public static function is_native( string $type ): bool
	{
		return in_array( strtolower( trim( $type ) ), self::NATIVE_TYPES, true );
	}

	/**
	 * Validación server-side por tipo (paridad con el catálogo legacy).
	 * @return array listado de mensajes de error (vacío = válido).
	 */
	public static function validate( array $f, $value ): array
	{
		$errors = array();
		$name   = sanitize_key( (string) ( $f['name'] ?? '' ) );
		$type   = strtolower( (string) ( $f['type'] ?? 'text' ) );
		$raw    = $value;
		$value  = is_scalar( $value ) ? (string) $value : '';

		if ( ! empty( $f['required'] ) && trim( $value ) === '' && ! is_array( $raw ) ) {
			$msg = (string) ( $f['error_message'] ?? '' );
			$errors[] = $msg !== '' ? $msg : ( $name !== '' ? 'El campo ' . $name . ' es obligatorio.' : 'Hay un campo obligatorio sin completar.' );
			return $errors;
		}
		if ( trim( $value ) === '' && ! is_array( $raw ) ) {
			return $errors;
		}

		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					$errors[] = 'Ingresa un correo válido.';
				}
				break;

			case 'rut':
				if ( ! self::validate_rut( $value ) ) {
					$errors[] = 'RUT inválido.';
				}
				break;

			case 'tel':
			case 'phone':
				$country = sanitize_key( (string) ( $f['country'] ?? 'CL' ) );
				if ( ! self::validate_phone( $value, $country ) ) {
					$errors[] = 'Ingresa un teléfono válido.';
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					$errors[] = 'Ingresa un número válido.';
				}
				break;

			case 'url':
				if ( filter_var( $value, FILTER_VALIDATE_URL ) === false ) {
					$errors[] = 'Ingresa una URL válida.';
				}
				break;

			case 'date':
				if ( strtotime( $value ) === false ) {
					$errors[] = 'Ingresa una fecha válida.';
				}
				break;

			case 'time':
				if ( ! self::validate_time( $value ) ) {
					$errors[] = 'Ingresa una hora válida (HH:MM).';
				}
				break;

			case 'rating':
				$max = (int) ( $f['max'] ?? 5 );
				if ( ! is_numeric( $value ) || (int) $value < 1 || (int) $value > $max ) {
					$errors[] = 'Selecciona una calificación entre 1 y ' . $max . '.';
				}
				break;

			case 'nps':
				if ( ! is_numeric( $value ) || (int) $value < 0 || (int) $value > 10 ) {
					$errors[] = 'Selecciona una puntuación entre 0 y 10.';
				}
				break;

			case 'matrix':
				if ( is_array( $raw ) ) {
					break; // MATRIX_ARRAY_V1: array de respuestas ya válido.
				}
				$decoded = json_decode( $value, true );
				if ( ! is_array( $decoded ) ) {
					$errors[] = 'Responde la matriz completa.';
				}
				break;

			case 'file':
				$file = is_array( $value ) ? $value : array();
				if ( ! empty( $file['error'] ) ) {
					$errors[] = 'No se pudo subir el archivo.';
					break;
				}
				$size = (int) ( $file['size'] ?? 0 );
				if ( $size > self::MAX_FILE_BYTES ) {
					$errors[] = 'El archivo supera los 5MB.';
				}
				$ext = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
				if ( $ext !== '' && ! in_array( $ext, self::MIME_ALLOWED, true ) ) {
					$errors[] = 'Tipo de archivo no permitido.';
				}
				break;
		}

		return $errors;
	}

	/** Validación RUT chileno (módulo 11) — puerto de estandar_validar_rut(). */
	public static function validate_rut( string $rut ): bool
	{
		$rut = strtoupper( preg_replace( '/[^0-9K]/', '', $rut ) );
		if ( $rut === '' || strlen( $rut ) < 8 ) {
			return false;
		}
		$dv  = substr( $rut, -1 );
		$num = substr( $rut, 0, -1 );
		if ( ! ctype_digit( $num ) ) {
			return false;
		}
		$suma = 0;
		$mul  = 2;
		for ( $i = strlen( $num ) - 1; $i >= 0; $i-- ) {
			$suma += $mul * (int) $num[ $i ];
			$mul   = ( $mul < 7 ) ? $mul + 1 : 2;
		}
		$res    = 11 - ( $suma % 11 );
		$dvcalc = $res === 11 ? '0' : ( $res === 10 ? 'K' : (string) $res );
		return $dv === $dvcalc;
	}

	/** Validación de teléfono por país — puerto de estandar_forms_validate_phone(). */
	public static function validate_phone( string $value, string $country = 'CL' ): bool
	{
		$patterns = array(
			'CL' => '/^(\+?56)?[2-9]\d{8}$/',
			'AR' => '/^(\+?54)?\d{10}$/',
			'PE' => '/^(\+?51)?9\d{8}$/',
			'MX' => '/^(\+?52)?\d{10}$/',
			'ES' => '/^(\+?34)?[6-9]\d{8}$/',
			'US' => '/^(\+?1)?\d{10}$/',
		);
		$normalized = preg_replace( '/[^\d+]/', '', trim( $value ) );
		if ( $normalized === '' ) {
			return false;
		}
		if ( strpos( $normalized, '00' ) === 0 ) {
			$normalized = '+' . substr( $normalized, 2 );
		}
		$pattern = $patterns[ strtoupper( $country ) ] ?? '/^\+?\d{8,15}$/';
		return (bool) preg_match( $pattern, $normalized );
	}

	/** Validación de hora 24 h — acepta "H:MM", "HH:MM" y "HH:MM:SS" (TIME_FIELD_V1). */
	public static function validate_time( string $value ): bool
	{
		return (bool) preg_match( '/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim( $value ) );
	}

	/** Proyectos del Project Registry de Core (graceful sin Core). */
	public static function projects_for_select(): array
	{
		if ( function_exists( 'estandar_core_get_projects_for_select' ) ) {
			$projects = estandar_core_get_projects_for_select();
			return is_array( $projects ) ? $projects : array();
		}
		return array();
	}

	// ── helpers internos de render ───────────────────────────────────────────

	private static function label_html( string $label, string $name, string $required ): string
	{
		if ( $label === '' ) {
			return '';
		}
		return '<label for="' . esc_attr( self::dom_id( $name ) ) . '">' . esc_html( $label ) . ( $required ? ' <span style="color:#c00;">*</span>' : '' ) . '</label>';
	}

	private static function option_pairs( array $f ): array
	{
		$opts = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
		$pairs = array();
		foreach ( $opts as $o ) {
			if ( is_array( $o ) ) {
				$val = (string) ( $o['value'] ?? ( $o['label'] ?? '' ) );
				$pairs[] = array(
					'value'       => $val,
					'label'       => (string) ( $o['label'] ?? $val ),
					'description' => (string) ( $o['description'] ?? '' ),
					'emoji'       => (string) ( $o['emoji'] ?? '' ),
					'icon'        => (string) ( $o['icon'] ?? '' ),
				);
			} elseif ( is_scalar( $o ) ) {
				$pairs[] = array( 'value' => (string) $o, 'label' => (string) $o, 'description' => '', 'emoji' => '', 'icon' => '' );
			}
		}
		return $pairs;
	}

	private static function select_html( array $f, string $name, string $required, string $value = '' ): string
	{
		$sel = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" style="width:100%;"' . $required . '><option value="">Selecciona…</option>';
		foreach ( self::option_pairs( $f ) as $p ) {
			$sel .= '<option value="' . esc_attr( $p['value'] ) . '"' . selected( (string) $value, (string) $p['value'], false ) . '>' . esc_html( $p['label'] ) . '</option>';
		}
		return $sel . '</select>';
	}

	private static function choice_group_html( array $f, string $name, string $required, string $value = '' ): string
	{
		$type = strtolower( (string) ( $f['type'] ?? 'radio' ) );
		$out  = '<div class="estandar-form-v2-choice-group">';
		foreach ( self::option_pairs( $f ) as $p ) {
			$checked = (string) $value === (string) $p['value'] ? ' checked' : '';
			if ( $type === 'emoji' ) {
				$icon = $p['emoji'] !== '' ? '<span style="font-size:22px;margin-right:6px;">' . esc_html( $p['emoji'] ) . '</span>' : '';
				$out .= '<label style="display:block;padding:4px 0;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $p['value'] ) . '"' . $checked . $required . '> ' . $icon . esc_html( $p['label'] ) . '</label>';
			} elseif ( $type === 'cards' ) {
				$out .= '<label style="display:block;border:1px solid #c3c4c7;border-radius:8px;padding:10px;margin:4px 0;cursor:pointer;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $p['value'] ) . '"' . $checked . $required . '> '
					. '<strong>' . esc_html( $p['label'] ) . '</strong>'
					. ( $p['description'] !== '' ? '<br><small>' . esc_html( $p['description'] ) . '</small>' : '' )
					. ( $p['icon'] !== '' ? ' <span aria-hidden="true">' . esc_html( $p['icon'] ) . '</span>' : '' )
					. '</label>';
			} else {
				$out .= '<label style="display:block;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $p['value'] ) . '"' . $checked . $required . '> ' . esc_html( $p['label'] ) . '</label>';
			}
		}
		return $out . '</div>';
	}

	private static function rating_html( array $f, string $name, string $required ): string
	{
		$max = (int) ( $f['max'] ?? 5 );
		if ( $max < 1 || $max > 10 ) {
			$max = 5;
		}
		$out = '<div class="estandar-form-v2-rating" data-max="' . $max . '">';
		for ( $n = 1; $n <= $max; $n++ ) {
			$out .= '<label style="display:inline-block;font-size:22px;cursor:pointer;margin-right:4px;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . $n . '"' . $required . ' style="position:absolute;opacity:0;">&#9733;</label>';
		}
		return $out . '</div>';
	}

	private static function matrix_html( array $f, string $name, string $required ): string
	{
		$scale  = (int) ( $f['scale'] ?? 7 );
		$rows   = isset( $f['rows'] ) && is_array( $f['rows'] ) ? $f['rows'] : array();
		$cols   = isset( $f['column_labels'] ) && is_array( $f['column_labels'] ) ? $f['column_labels'] : array();
		if ( ! $rows ) {
			return '<div style="color:#999;">Matriz sin filas configuradas.</div>';
		}
		$out = '<table class="estandar-form-v2-matrix" style="border-collapse:collapse;width:100%;"><thead><tr><th></th>';
		for ( $s = 1; $s <= $scale; $s++ ) {
			$label = (string) ( $cols[ $s - 1 ] ?? $s );
			$out .= '<th style="padding:4px 6px;text-align:center;font-weight:400;">' . esc_html( $label ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row_id = sanitize_key( (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : '' ) );
			$rlabel = (string) ( is_array( $row ) ? ( $row['label'] ?? '' ) : $row );
			$out .= '<tr><td style="padding:4px 6px;">' . esc_html( $rlabel ) . '</td>';
			for ( $s = 1; $s <= $scale; $s++ ) {
				$field_name = $row_id !== '' ? $name . '[' . $row_id . ']' : $name;
				$out .= '<td style="padding:4px 6px;text-align:center;"><input type="radio" name="' . esc_attr( $field_name ) . '" value="' . $s . '"' . $required . '></td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table>';
	}

	private static function nps_html( array $f, string $name, string $required ): string
	{
		$out = '<div class="estandar-form-v2-nps">';
		for ( $n = 0; $n <= 10; $n++ ) {
			$out .= '<label style="display:inline-block;margin:0 2px;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . $n . '"' . $required . '> <span style="display:inline-block;width:28px;text-align:center;padding:4px;border:1px solid #c3c4c7;border-radius:6px;">' . $n . '</span></label>';
		}
		return $out . '</div>';
	}

	private static function project_select_html( array $f, string $name, string $required, string $value = '' ): string
	{
		$sel = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" class="estandar-project-select" style="width:100%;"' . $required . '><option value="">Selecciona…</option>';
		foreach ( self::projects_for_select() as $p ) {
			$val = (string) ( is_array( $p ) ? ( $p['value'] ?? '' ) : $p );
			$lbl = (string) ( is_array( $p ) ? ( $p['label'] ?? $val ) : $p );
			$sel .= '<option value="' . esc_attr( $val ) . '"' . selected( (string) $value, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		return $sel . '</select>';
	}

	private static function dependent_select_html( array $f, string $name, string $required, string $value = '' ): string
	{
		$depends_on = sanitize_key( (string) ( $f['depends_on'] ?? '' ) );
		// GENERIC_DEPENDENT_MAP_V1 (2026-09-22): si el campo define `options_map`
		// (mapa valor-padre → [opciones]), el select dependiente se puebla con ese
		// catálogo, genérico para cualquier form. Sin `options_map` → fallback al
		// mapa de calles por proyecto (comportamiento previo intacto).
		$generic = ( isset( $f['options_map'] ) && is_array( $f['options_map'] ) ) ? $f['options_map'] : array();
		if ( $generic ) {
			$map_attr = ' data-options-map="' . esc_attr( (string) wp_json_encode( $generic ) ) . '"';
		} else {
			$map      = self::streets_map();
			$map_attr = $map ? ' data-streets="' . esc_attr( (string) wp_json_encode( $map ) ) . '"' : '';
		}
		$sel = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( self::dom_id( $name ) ) . '" class="estandar-dependent-select" data-depends-on="' . esc_attr( $depends_on ) . '"' . $map_attr . ' style="width:100%;"' . $required . '>'
			. '<option value="">— Selecciona primero el campo anterior —</option></select>';
		if ( $depends_on !== '' && function_exists( 'admin_url' ) ) {
			$sel .= '<span class="estandar-dependent-nonce" data-nonce="' . esc_attr( wp_create_nonce( 'ef_get_streets' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"></span>';
		}
		return $sel;
	}

	/**
	 * Valor enviado por el usuario para re-poblar un campo tras un error de
	 * validación (transversal a todos los forms). No aplica a file uploads.
	 */
	private static function posted_value( string $name, string $type ): string
	{
		if ( $type === 'file' || ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
			return '';
		}
		return trim( (string) wp_unslash( $_POST[ $name ] ) );
	}

	/**
	 * Atributos de validación inline para nombre/email/teléfono (frontend).
	 * Añade `pattern` + `data-es-validate` para que el JS del form muestre una
	 * advertencia antes del envío. Nombres: solo letras (incl. acentos) y
	 * espacios; email/tel con patrón HTML5.
	 */
	private static function validation_attrs( array $f, string $type ): string	{
		$type = strtolower( (string) $type );
		$name = sanitize_key( (string) ( $f['name'] ?? '' ) );

		$is_name = in_array( $name, array( 'nombre', 'nombres', 'name', 'full_name', 'nombre_propietario' ), true )
			|| strpos( $name, 'nombre' ) !== false;

		if ( $type === 'email' ) {
			return ' data-es-validate="email" pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="Ingresa un correo válido, por ejemplo nombre@dominio.cl"';
		}
		if ( in_array( $type, array( 'tel', 'phone' ), true ) ) {
			return ' data-es-validate="phone" pattern="[\d+\s\-()]{8,20}" title="Ingresa un teléfono válido (mínimo 8 dígitos)"';
		}
		if ( $is_name && ( $type === 'text' || $type === 'name' ) ) {
			return ' data-es-validate="name" pattern="[A-Za-zÁÉÍÓÚÑÜáéíóúñüäëöïàèìòù\s\.\'-]{2,120}" title="Ingresa un nombre válido (solo letras)"';
		}
		return '';
	}

	/**
	 * Atributo condicional de visibilidad ('mostrar si campo = valor').
	 * Devuelve ` data-es-show-when="<campo>==<valor>"` o, si value = '*',
	 * ` data-es-show-when="<campo>!=*"` (campo completado de cualquier forma).
	 * El JS del form lo interpreta (estilo survey / progressive disclosure).
	 */
	private static function show_when_attr( array $f ): string
	{
		$sw = $f['show_when'] ?? null;
		if ( ! is_array( $sw ) ) {
			return '';
		}
		$field = sanitize_key( (string) ( $sw['field'] ?? '' ) );
		$value = sanitize_text_field( (string) ( $sw['value'] ?? '' ) );
		if ( $field === '' ) {
			return '';
		}
		// SHOW_WHEN_ANY_V1: value '*' = "el campo se completó de cualquier forma".
		if ( $value === '*' ) {
			return ' data-es-show-when="' . esc_attr( $field . '!=*' ) . '"';
		}
		if ( $value === '' ) {
			return '';
		}
		return ' data-es-show-when="' . esc_attr( $field . '==' . $value ) . '"';
	}
}
