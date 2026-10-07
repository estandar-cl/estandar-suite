<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GfPostventaBridge — puente PROVISIONAL Gravity Forms → Capture (postventa).
 *
 * Garantiza las 3 promesas mientras dura la convivencia con GF (forms 2 y 14 de
 * cliente1.example.com):
 *   1. REGISTRO: cada entry GF de postventa se ingesta en
 *      `estandar_capture_submissions` (source='gf_postventa') → aparece en el
 *      Panel Postventa web y en el pipeline v2.
 *   2. PUBLICACIÓN: la submission queda en la tabla nativa que consume el
 *      Panel Postventa (/panel-postventa/) y el admin de capture.
 *   3. CORREO: reenvía la notificación admin con el CONTRATO GF byte-idéntico
 *      (subject «Solicitud PostVenta {Proyecto} desde la web Empresa Demo», cuerpo
 *      con {all_fields} → tabla Label:Value + <br /> + wrapper <html><body>,
 *      content-type text/html) desde el remitente configurado (por defecto
 *      postventa-no-responder@cliente1.example.com) a los destinatarios configurados.
 *
 * El correo se envía con el motor Core\Mail (con DeliveryLog y transporte
 * resuelto), NO con wp_mail crudo, y respetando la estructura que la plataforma
 * externa del cliente parsea (cada línea «Label: Value» rellena un campo).
 *
 * Proceso:
 *   - `gform_after_submission` (forms configurados) → map_entry → ingest →
 *     send admin email (si `enabled`).
 *   - `disable_gf_notify`: al activarse, desactiva la notificación admin de GF
 *     (evita doble correo); al desactivarse, la restaura. El resto del pipeline
 *     GF (confirmación visual) no se toca.
 */
class GfPostventaBridge
{
	const OPTION       = 'estandar_suite_capture_gf_postventa_bridge';
	const GF_NOTIF_ID  = '6491f7fd9b043'; // «Notificación del administrador» (forms 2 y 14)
	const DEFAULT_FROM = 'postventa-no-responder@cliente1.example.com';
	const DEFAULT_TO   = 'serviciotecnico@cliente1.example.com, postventa@cliente1.example.com, cm@cliente1.example.com';

	public static function init(): void
	{
		// Solo engancha cuando Gravity Forms está activo en este WordPress.
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gf_submission' ), 10, 2 );
		// Sincroniza el estado de la notificación GF con el toggle del puente.
		add_action( 'estandar_suite_capture_gf_postventa_bridge_synced', array( __CLASS__, 'sync_gf_notification' ) );
	}

	// ── Settings ───────────────────────────────────────────────────────────

	public static function settings(): array
	{
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$d = self::defaults();
		foreach ( $d as $k => $v ) {
			if ( ! array_key_exists( $k, $s ) ) {
				$s[ $k ] = $v;
			}
		}
		return $s;
	}

	public static function defaults(): array
	{
		return array(
			'enabled'            => 0,
			'form_ids'           => '2,14',
			'notify_to'          => self::DEFAULT_TO,
			'from_email'         => self::DEFAULT_FROM,
			'from_name'          => 'Postventa Empresa Demo',
			'reply_to'           => 'postventa@cliente1.example.com',
			'subject_template'   => 'Solicitud PostVenta {proyecto} desde la web Empresa Demo',
			'intro_html'         => 'Hola!<br />Un cliente ({nombre} {apellido}, Rut: {rut}) ha solicitado atención en postventa de su vivienda en {proyecto}. Aquí está la información.<br /><strong>Recuerda siempre que el cliente desde ya está esperando una pronta solución a su requerimiento.</strong><br />(Este mail fue enviado automáticamente desde la página de cliente1.example.com/postventa).<br />Recuerda que la Información de este correo queda adicionalmente guardada en <a href="' . self::SHEET_LINK . '">este enlace</a>.',
			'footer_html'        => 'Se envía a: ' . self::DEFAULT_TO,
			'disable_gf_notify'  => 1,
			'capture_source'     => 'gf_postventa',
		);
	}

	const SHEET_LINK = 'https://docs.google.com/spreadsheets/d/12hi6RPuaxZfHUvB9k7eZRD-H2xhjCohE1PvER3_xlVw/edit?usp=sharing';

	public static function save_settings( array $raw ): array
	{
		$cur = self::settings();
		$cur['enabled']           = ! empty( $raw['enabled'] ) ? 1 : 0;
		$cur['form_ids']          = sanitize_text_field( (string) ( $raw['form_ids'] ?? $cur['form_ids'] ) );
		$cur['notify_to']         = sanitize_text_field( (string) ( $raw['notify_to'] ?? $cur['notify_to'] ) );
		$cur['from_email']        = sanitize_email( (string) ( $raw['from_email'] ?? $cur['from_email'] ) );
		$cur['from_name']         = sanitize_text_field( (string) ( $raw['from_name'] ?? $cur['from_name'] ) );
		$cur['reply_to']          = sanitize_email( (string) ( $raw['reply_to'] ?? $cur['reply_to'] ) );
		$cur['subject_template']  = sanitize_text_field( (string) ( $raw['subject_template'] ?? $cur['subject_template'] ) );
		$cur['intro_html']        = wp_kses_post( (string) ( $raw['intro_html'] ?? $cur['intro_html'] ) );
		$cur['footer_html']       = wp_kses_post( (string) ( $raw['footer_html'] ?? $cur['footer_html'] ) );
		$cur['disable_gf_notify'] = ! empty( $raw['disable_gf_notify'] ) ? 1 : 0;
		update_option( self::OPTION, $cur, false );
		do_action( 'estandar_suite_capture_gf_postventa_bridge_synced' );
		return $cur;
	}

	public static function enabled(): bool
	{
		return ! empty( self::settings()['enabled'] );
	}

	/** GF_BRIDGE_VISIBILITY_V1: PURE — ¿mostrar la UI del puente en el admin?
	 *  Solo tiene sentido si el plugin Gravity Forms está presente en esta
	 *  instalación (si no, no hay entries GF que enganchar). El render del
	 *  contrato postventa (contract_from_capture) NO depende de esto: lo usan
	 *  los forms nativos con mail_contract='gf_postventa' aunque GF no exista. */
	public static function ui_visible( bool $gf_active ): bool
	{
		return $gf_active;
	}

	public static function form_ids(): array
	{
		$ids = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( self::settings()['form_ids'] ?? '' ) ) as $part ) {
			$part = (int) $part;
			if ( $part > 0 ) {
				$ids[] = $part;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	// ── Hook GF ────────────────────────────────────────────────────────────

	/**
	 * @param array $entry Entry GF (ya persistida por GF).
	 * @param array $form  Form GF.
	 */
	public static function on_gf_submission( $entry, $form ): void
	{
		if ( ! self::enabled() ) {
			return;
		}
		$form_id = (int) ( is_array( $form ) ? ( $form['id'] ?? 0 ) : 0 );
		if ( ! in_array( $form_id, self::form_ids(), true ) ) {
			return;
		}

		$entry = is_array( $entry ) ? $entry : array();
		$gf_entry_id = (string) ( $entry['id'] ?? '' );

		// 1) Registro en estandar_capture_submissions.
		$capture = self::map_entry( $entry, $form );
		$result  = Ingestion::ingest( $capture );

		// 2) Correo admin con el contrato GF (POSTVENTA_DEDUPE_V1: 1 correo por
		// entry GF, aunque `gform_after_submission` se dispare más de una vez
		// por reintento/doble fire — la ingesta es idempotente, el correo no).
		if ( ! empty( $result['id'] ) ) {
			if ( $gf_entry_id !== '' && self::entry_notified( $gf_entry_id ) ) {
				return;
			}
			self::send_admin_email( $entry, $form, (int) $result['id'] );
			if ( $gf_entry_id !== '' ) {
				self::mark_entry_notified( $gf_entry_id );
			}
		}
	}

	/** POSTVENTA_DEDUPE_V1: ¿esta entry GF ya fue notificada? (evita doble correo). */
	public static function entry_notified( string $gf_entry_id ): bool
	{
		if ( $gf_entry_id === '' || ! function_exists( 'get_transient' ) ) {
			return false;
		}
		return (bool) get_transient( 'gf_postventa_notified_' . md5( $gf_entry_id ) );
	}

	/** POSTVENTA_DEDUPE_V1: marca la entry GF como notificada (ventana 1h). */
	public static function mark_entry_notified( string $gf_entry_id ): void
	{
		if ( $gf_entry_id === '' || ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient( 'gf_postventa_notified_' . md5( $gf_entry_id ), 1, 3600 );
	}

	/** Mapea una entry GF → payload de capture (PURE, testeable). */
	public static function map_entry( array $entry, array $form ): array
	{
		$form_id = (int) ( $form['id'] ?? 0 );
		$first   = trim( (string) ( $entry['1.3'] ?? '' ) );
		$last    = trim( (string) ( $entry['1.6'] ?? '' ) );
		$name    = trim( $first . ' ' . $last );

		// Calle dependiente del proyecto (solo se llena la del proyecto elegido).
		$calle = '';
		foreach ( array( 43, 62, 44, 57, 46, 64, 58, 63 ) as $street_field ) {
			$v = trim( (string) ( $entry[ (string) $street_field ] ?? '' ) );
			if ( $v !== '' ) {
				$calle = $v;
				break;
			}
		}

		$problemas = array();
		$foto_field = array( 1 => 31, 2 => 32, 3 => 34, 4 => 36 );
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$loc = trim( (string) ( $entry[ (string) ( 47 + ( ( $i - 1 ) * 4 ) ) ] ?? '' ) ); // 47,51,54,55
			$desc = '';
			$desc_field = array( 1 => 27, 2 => 29, 3 => 33, 4 => 35 )[ $i ];
			$desc = trim( (string) ( $entry[ (string) $desc_field ] ?? '' ) );
			$fotos = self::entry_file_urls( $entry, $foto_field[ $i ] );
			if ( $loc !== '' || $desc !== '' || $fotos ) {
				$problemas[] = array(
					'numero'      => $i,
					'lugar'       => $loc,
					'descripcion' => $desc,
					'fotos'       => $fotos,
				);
			}
		}

		$rut     = trim( (string) ( $entry['8'] ?? '' ) );
		$dv      = trim( (string) ( $entry['56'] ?? '' ) );
		$proyecto = trim( (string) ( $entry['7'] ?? '' ) );
		$tipo    = trim( (string) ( $entry['13'] ?? '' ) );
		$req     = trim( (string) ( $entry['14'] ?? '' ) );
		$solicitud = trim( (string) ( $entry['18'] ?? '' ) );
		$numero  = trim( (string) ( $entry['12'] ?? '' ) );

		$message = $solicitud;
		if ( $message === '' && $problemas ) {
			$message = (string) $problemas[0]['descripcion'];
		}

		return array(
			'source'         => (string) ( self::settings()['capture_source'] ?? 'gf_postventa' ),
			'contact'        => array(
				'name'  => $name,
				'email' => (string) ( $entry['16'] ?? '' ),
				'phone' => (string) ( $entry['3'] ?? '' ),
			),
			'content'        => array(
				'source_form_id' => 'gf-' . $form_id,
				'message'        => $message,
			),
			'fields'         => array(
				'gf_entry_id'    => (string) ( $entry['id'] ?? '' ),
				'gf_form_id'     => (string) $form_id,
				'nombre'         => $first,
				'apellido'       => $last,
				'rut'            => $rut,
				'dv'             => $dv,
				'email'          => (string) ( $entry['16'] ?? '' ),
				'telefono'       => (string) ( $entry['3'] ?? '' ),
				'proyecto'       => $proyecto,
				'calle'          => $calle,
				'numero'         => $numero,
				'tipo_postventa' => $tipo,
				'requerimiento'  => $req,
				'solicitud'      => $solicitud,
				'problemas'      => wp_json_encode( $problemas, JSON_UNESCAPED_UNICODE ),
			),
			'attribution'    => array(
				'landing_url' => home_url( '/postventa/' ),
			),
			'spam'           => array( 'honeypot_filled' => false ),
		);
	}

	// ── Correo admin (contrato GF) ─────────────────────────────────────────

	/**
	 * Envía la notificación admin con el contrato GF byte-idéntico.
	 * Render: si GFCommon está disponible, usa replace_variables sobre el
	 * template real de GF (garantiza igualdad exacta). Fallback: builder puro.
	 */
	public static function send_admin_email( array $entry, array $form, int $capture_id ): array
	{
		$s  = self::settings();
		$to = trim( (string) $s['notify_to'] );
		if ( $to === '' ) {
			return array( 'ok' => false, 'error' => 'sin_destinatarios' );
		}

		$projecto = trim( (string) ( $entry['7'] ?? '' ) );
		$subject  = str_replace( '{proyecto}', $projecto, (string) $s['subject_template'] );

		$body = '';
		if ( class_exists( 'GFCommon' ) ) {
			$body = self::render_body_with_gf( $entry, $form, $subject );
		}
		if ( $body === '' ) {
			$body = self::render_body_pure( $entry, $s );
		}
		// GF envuelve el cuerpo en <html><body> (format_html_message).
		if ( ! preg_match( '/<html/i', $body ) ) {
			$body = "<html>\n\t<head>\n\t\t<title>" . esc_html( $subject ) . "</title>\n\t</head>\n\t<body>\n\t\t" . $body . "\n\t</body>\n</html>";
		}

		$r = \Estandar\Core\Mail::send( array(
			'to'        => $to,
			'subject'   => $subject,
			'html'      => $body,
			'alt'       => wp_strip_all_tags( $body ),
			'from_name' => (string) $s['from_name'],
			'from_email' => (string) $s['from_email'],
			'reply_to'  => (string) $s['reply_to'],
		) );
		$r['capture_id'] = $capture_id;
		$r['contract']   = 'gf-postventa';
		return $r;
	}

	/**
	 * Render del cuerpo con el pipeline real de GF (byte-idéntico): aplica la
	 * plantilla de la notificación admin del form (contiene {all_fields}) con
	 * el formato que GF usa en producción (format='html', nl2br=true).
	 */
	private static function render_body_with_gf( array $entry, array $form, string $subject ): string
	{
		try {
			if ( ! class_exists( 'GFCommon' ) || ! function_exists( 'GFAPI' ) ) {
				return '';
			}
			$form_id = (int) ( $form['id'] ?? 0 );
			$live    = \GFAPI::get_form( $form_id );
			$notif   = is_array( $live ) && isset( $live['notifications'][ self::GF_NOTIF_ID ] )
				? $live['notifications'][ self::GF_NOTIF_ID ]
				: null;
			if ( ! is_array( $notif ) ) {
				return '';
			}
			$message = (string) ( $notif['message'] ?? '' );
			if ( $message === '' ) {
				return '';
			}
			return \GFCommon::replace_variables( $message, $live, $entry, false, false, true, 'html' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Render puro (sin GF): intro + tabla Label:Value + footer. */
	public static function render_body_pure( array $entry, array $s = array() ): string
	{
		$s = $s ? $s : self::settings();
		$rows = self::field_rows( $entry );

		$intro = (string) ( $s['intro_html'] ?? '' );
		foreach ( array( 'nombre', 'apellido', 'rut', 'dv', 'email', 'telefono', 'proyecto', 'numero', 'tipo', 'requerimiento' ) as $key ) {
			$val = self::entry_value( $entry, $key );
			$intro = str_replace( '{' . $key . '}', $val, $intro );
		}

		$table = self::all_fields_table( $rows );
		$footer = (string) ( $s['footer_html'] ?? '' );

		return $intro . "\r\n\r\n" . $table . "\r\n\r\n" . $footer;
	}

	/** Fila Label→Value de la entry (etiquetas GF = las que la plataforma parsea). */
	public static function field_rows( array $entry ): array
	{
		$def = array(
			'Nombre Propietario'        => array( '1.3', '1.6' ),
			'RUT Propietario (SIN dígito verificador)' => '8',
			'- Dígito Verificador'      => '56',
			'Email'                     => '16',
			'Teléfono'                  => '3',
			'Proyecto'                  => '7',
			'Número de vivienda'        => '12',
			'Tipo de postventa'         => '13',
			'Seleccione su requerimento' => '14',
			'Escriba la solicitud de documento que requiere' => '18',
		);
		$rows = array();
		foreach ( $def as $label => $keys ) {
			$keys = (array) $keys;
			$vals = array();
			foreach ( $keys as $k ) {
				$v = trim( (string) ( $entry[ $k ] ?? '' ) );
				if ( $v !== '' ) {
					$vals[] = $v;
				}
			}
			$value = implode( ' ', $vals );
			if ( $value !== '' ) {
				$rows[] = array( 'label' => $label, 'value' => $value );
			}
		}

		// Calle del proyecto (dependiente).
		$calle = '';
		foreach ( array( 43, 62, 44, 57, 46, 64, 58, 63 ) as $f ) {
			$v = trim( (string) ( $entry[ (string) $f ] ?? '' ) );
			if ( $v !== '' ) {
				$calle = $v;
				break;
			}
		}
		if ( $calle !== '' ) {
			$rows[] = array( 'label' => 'Calle', 'value' => $calle );
		}

		// Problemas #1..#4 (Lugar + descripción + fotos).
		$foto_field = array( 1 => 31, 2 => 32, 3 => 34, 4 => 36 );
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$loc_field  = array( 1 => 47, 2 => 51, 3 => 54, 4 => 55 )[ $i ];
			$desc_field = array( 1 => 27, 2 => 29, 3 => 33, 4 => 35 )[ $i ];
			$loc  = trim( (string) ( $entry[ (string) $loc_field ] ?? '' ) );
			$desc = trim( (string) ( $entry[ (string) $desc_field ] ?? '' ) );
			$fotos = self::entry_file_urls( $entry, $foto_field[ $i ] );
			if ( $loc === '' && $desc === '' && ! $fotos ) {
				continue;
			}
			if ( $loc !== '' ) {
				$rows[] = array( 'label' => 'Lugar del problema #' . $i, 'value' => $loc );
			}
			if ( $desc !== '' ) {
				$rows[] = array( 'label' => 'Problema #' . $i, 'value' => $desc );
			}
			foreach ( $fotos as $u ) {
				$rows[] = array( 'label' => 'Fotografía problema #' . $i, 'value' => $u );
			}
		}

		return $rows;
	}

	/** Tabla HTML Label:Value — misma estructura que {all_fields} de GF. */
	public static function all_fields_table( array $rows ): string
	{
		$html  = '<table width="99%" border="0" cellpadding="1" cellspacing="0" bgcolor="#EAEAEA"><tr><td>' . "\r\n";
		$html .= "\t" . '<table width="100%" border="0" cellpadding="5" cellspacing="0" bgcolor="#FFFFFF">' . "\r\n";
		foreach ( $rows as $r ) {
			$label = esc_html( (string) $r['label'] );
			$value = (string) $r['value'];
			$html .= "\t\t" . '<tr bgcolor="#EAF2FA"><td colspan="2"><font style="font-family: sans-serif; font-size:12px;"><strong>' . $label . '</strong></font></td></tr>' . "\r\n";
			$html .= "\t\t" . '<tr bgcolor="#FFFFFF"><td width="20">&nbsp;</td><td><font style="font-family: sans-serif; font-size:12px;">' . $value . '</font></td></tr>' . "\r\n";
		}
		$html .= "\t" . '</table>' . "\r\n" . '</td></tr></table>';
		return $html;
	}

	/**
	 * Render del cuerpo del CONTRATO GF desde un CAPTURE NATIVO (submission v2),
	 * no desde una entry GF. Usa el mismo subject + tabla Label:Value que GF.
	 * Lo usa el form nativo de postventa (mail_contract='gf_postventa').
	 *
	 * @return array{subject:string, body:string}
	 */
	public static function contract_from_capture( array $capture, array $config = array() ): array
	{
		// Fusiona settings del bridge (defaults) + config del form (prioridad).
		$s      = array_merge( self::settings(), $config ? $config : array() );
		$posted = is_array( $capture['fields'] ?? null ) ? $capture['fields'] : array();
		$contact = is_array( $capture['contact'] ?? null ) ? $capture['contact'] : array();

		$proyecto = (string) ( $posted['proyecto'] ?? '' );
		$subject  = str_replace( '{proyecto}', $proyecto, (string) ( $s['subject_template'] ?? 'Solicitud PostVenta {proyecto} desde la web Empresa Demo' ) );

		// Filas Label:Value en el mismo orden/estructura que GF.
		$rows = array();

		// RUT: puede venir completo ("12345678-4", campo nativo tipo 'rut')
		// o separado (rut + dv, puente GF). Ambos → mismo output que GF:
		// "RUT Propietario (SIN dígito verificador)" + "- Dígito Verificador".
		$rut_raw = trim( (string) ( $posted['rut'] ?? '' ) );
		$dv_raw  = trim( (string) ( $posted['dv'] ?? '' ) );
		if ( $rut_raw !== '' ) {
			$rut_digits = preg_replace( '/[^0-9kK]/', '', $rut_raw );
			$rut_body   = $rut_digits;
			if ( $dv_raw === '' && preg_match( '/^(\d{7,8})([0-9kK])$/', $rut_digits, $m ) ) {
				$rut_body = $m[1];
				$dv_raw   = '-' . strtolower( $m[2] );
			}
			$rows[] = array( 'label' => 'RUT Propietario (SIN dígito verificador)', 'value' => $rut_body );
			$rows[] = array( 'label' => '- Dígito Verificador', 'value' => $dv_raw );
		} elseif ( trim( (string) ( $posted['gf_rut'] ?? '' ) ) !== '' ) {
			// Puente GF: viene separado en los fields del capture.
			$rows[] = array( 'label' => 'RUT Propietario (SIN dígito verificador)', 'value' => (string) $posted['gf_rut'] );
			$rows[] = array( 'label' => '- Dígito Verificador', 'value' => (string) ( $posted['gf_dv'] ?? '' ) );
		}

		$def  = array(
			'nombre_propietario' => 'Nombre Propietario',
			'email'              => 'Email',
			'telefono'           => 'Teléfono',
			'proyecto'           => 'Proyecto',
			'calle'              => 'Calle',
			'numero'             => 'Número de vivienda',
			'tipo_postventa'     => 'Tipo de postventa',
			'requerimiento'      => 'Seleccione su requerimento',
			'solicitud'          => 'Escriba la solicitud de documento que requiere',
		);
		foreach ( $def as $key => $label ) {
			$val = trim( (string) ( $posted[ $key ] ?? '' ) );
			if ( $key === 'nombre_propietario' && $val === '' ) {
				$val = trim( (string) ( $posted['nombre'] ?? '' ) . ' ' . (string) ( $posted['apellido'] ?? '' ) );
			}
			if ( $val !== '' ) {
				$rows[] = array( 'label' => $label, 'value' => $val );
			}
		}

		// Problemas (repeater nativo: incidents) o campos planos problema_#.
		$incidents = json_decode( (string) ( $posted['incidents'] ?? '[]' ), true );
		if ( ! is_array( $incidents ) ) {
			$incidents = array();
		}
		// LEAD_FLAT_INCIDENTS_V1: un form LEAD modela los problemas como campos
		// planos lugar_problema_N / problema_N (+ una foto type=file por bloque)
		// en vez del repeater incidents[]. Aquí se agrupan por sufijo _N igual que
		// el survey (mismas filas del contrato GF), usando la config de secciones
		// para emparejar cada foto con su problema (la foto del bloque N es el
		// campo type=file siguiente a lugar/problema del mismo N).
		if ( ! $incidents ) {
			$flat = self::flat_incidents_from_fields( $posted, $config );
			if ( $flat ) {
				$incidents = $flat;
			}
		}
		foreach ( $incidents as $i => $inc ) {
			$inc = is_array( $inc ) ? $inc : array();
			$n  = (int) $i + 1;
			$loc = (string) ( $inc['location'] ?? '' );
			$desc = (string) ( $inc['description'] ?? '' );
			$fotos = isset( $inc['fotos'] ) && is_array( $inc['fotos'] ) ? array_values( array_filter( array_map( 'strval', $inc['fotos'] ) ) ) : array();
			if ( $loc !== '' ) {
				$rows[] = array( 'label' => 'Lugar del problema #' . $n, 'value' => $loc );
			}
			if ( $desc !== '' ) {
				$rows[] = array( 'label' => 'Problema #' . $n, 'value' => $desc );
			}
			foreach ( $fotos as $u ) {
				$rows[] = array( 'label' => 'Fotografía problema #' . $n, 'value' => $u );
			}
		}

		// Intro: reemplaza todos los tokens ({nombre}, {apellido}, {rut}, {dv},
		// {email}, {telefono}, {proyecto}, {numero}, {calle}) de forma robusta,
		// tanto para el puente GF (nombre/apellido separados) como para el form
		// nativo (nombre_propietario = nombre completo, rut con DV).
		$intro  = (string) ( $s['intro_html'] ?? 'Hola!' );
		$nombre = (string) ( $posted['nombre_propietario'] ?? ( $capture['name'] ?? $contact['name'] ?? '' ) );
		$apellido = (string) ( $posted['apellido'] ?? '' );
		// Si no hay apellido separado (form nativo), el nombre completo ya lo
		// contiene: eliminar el token {apellido} y el espacio que lo precede.
		if ( $apellido === '' && strpos( $intro, '{apellido}' ) !== false ) {
			$intro = str_replace( ' {apellido}', '', $intro );
			$intro = str_replace( '{apellido}', '', $intro );
		}
		$intro  = str_replace( '{proyecto}', $proyecto, $intro );
		$intro  = str_replace( '{nombre}', $nombre, $intro );
		$intro  = str_replace( '{apellido}', $apellido, $intro );
		// En el intro el RUT se muestra sin DV (el DV va en su propia fila).
		$rut_intro = $rut_body !== '' ? $rut_body : trim( (string) ( $posted['rut'] ?? '' ) );
		$intro  = str_replace( '{rut}', $rut_intro, $intro );
		$intro  = str_replace( '{dv}', $dv_raw, $intro );
		$intro  = str_replace( '{email}', trim( (string) ( $posted['email'] ?? '' ) ), $intro );
		$intro  = str_replace( '{telefono}', trim( (string) ( $posted['telefono'] ?? '' ) ), $intro );
		$intro  = str_replace( '{numero}', trim( (string) ( $posted['numero'] ?? '' ) ), $intro );
		$intro  = str_replace( '{calle}', trim( (string) ( $posted['calle'] ?? '' ) ), $intro );
		$footer = (string) ( $s['footer_html'] ?? '' );
		$table  = self::all_fields_table( $rows );

		$body = $intro . "\r\n\r\n" . $table . "\r\n\r\n" . $footer;
		if ( ! preg_match( '/<html/i', $body ) ) {
			$body = "<html>\n\t<head>\n\t\t<title>" . esc_html( $subject ) . "</title>\n\t</head>\n\t<body>\n\t\t" . $body . "\n\t</body>\n</html>";
		}
		return array( 'subject' => $subject, 'body' => $body );
	}

	/** LEAD_FLAT_INCIDENTS_V1 (PURE): agrupa los campos planos de un form LEAD
	 *  (lugar_problema_N / problema_N + foto type=file por bloque) en incidents
	 *  con la MISMA forma que el repeater del survey, para que el contrato GF
	 *  emita filas "Lugar del problema #N / Problema #N / Fotografía #N: URL".
	 *  La foto del bloque N es el campo type=file situado en la config entre el
	 *  `problema_N` y el siguiente `lugar_problema`. Devuelve array vacío si no
	 *  hay campos planos de problema (p. ej. rama DOCUMENTOS). */
	public static function flat_incidents_from_fields( array $posted, array $config ): array
	{
		// Orden de los campos del form (config.sections) para emparejar fotos.
		$ordered = array();
		foreach ( (array) ( $config['sections'] ?? array() ) as $sec ) {
			foreach ( (array) ( $sec['fields'] ?? array() ) as $f ) {
				if ( is_array( $f ) && isset( $f['name'] ) ) {
					$ordered[] = $f;
				}
			}
		}
		// ¿Hay campos planos de problema? (lugar_problema_1/problema_1)
		$has_flat = false;
		foreach ( array_keys( $posted ) as $k ) {
			if ( preg_match( '/^lugar_problema_(\d+)$/', $k ) || preg_match( '/^problema_(\d+)$/', $k ) ) {
				$has_flat = true;
				break;
			}
		}
		if ( ! $has_flat ) {
			return array();
		}

		// Para cada problema N detectado, construir location/description/fotos.
		$out = array();
		$max = 4;
		for ( $n = 1; $n <= $max; $n++ ) {
			$location = trim( (string) ( $posted[ 'lugar_problema_' . $n ] ?? '' ) );
			$desc     = trim( (string) ( $posted[ 'problema_' . $n ] ?? '' ) );
			// Buscar la foto del bloque N: primer campo type=file cuyo name NO
			// termine en _2/_3/_4 (foto del bloque 1) o que termine en _N.
			$foto_url = '';
			$file_names = array();
			foreach ( $ordered as $f ) {
				if ( strtolower( (string) ( $f['type'] ?? '' ) ) === 'file' ) {
					$file_names[] = (string) ( $f['name'] ?? '' );
				}
			}
			foreach ( $file_names as $fn ) {
				if ( (string) $posted[ $fn ] === '' ) {
					continue;
				}
				$fn_is_n = (bool) preg_match( '/_(' . $n . ')$/', $fn );
				$fn_is_1 = ( $n === 1 && ! preg_match( '/_(\d+)$/', $fn ) );
				if ( $fn_is_n || $fn_is_1 ) {
					$foto_url = (string) $posted[ $fn ];
					break;
				}
			}
			if ( $location === '' && $desc === '' && $foto_url === '' ) {
				continue;
			}
			$out[] = array(
				'location'    => $location,
				'title'       => '',
				'description' => $desc,
				'fotos'       => $foto_url !== '' ? array( $foto_url ) : array(),
			);
		}
		return $out;
	}

	private static function entry_value( array $entry, string $key ): string
	{
		$map = array(
			'nombre'     => '1.3',
			'apellido'   => '1.6',
			'rut'        => '8',
			'dv'         => '56',
			'email'      => '16',
			'telefono'   => '3',
			'proyecto'   => '7',
			'numero'     => '12',
			'tipo'       => '13',
			'requerimiento' => '14',
			'solicitud'  => '18',
		);
		$f = $map[ $key ] ?? '';
		if ( $key === 'nombre' || $key === 'apellido' ) {
			$parts = array();
			foreach ( array( $map['nombre'], $map['apellido'] ) as $k ) {
				$v = trim( (string) ( $entry[ $k ] ?? '' ) );
				if ( $v !== '' ) {
					$parts[] = $v;
				}
			}
			return $key === 'nombre' ? (string) ( $parts[0] ?? '' ) : (string) ( $parts[1] ?? '' );
		}
		return trim( (string) ( $entry[ $f ] ?? '' ) );
	}

	/**
	 * PURE: URLs de un campo fileupload de GF. Puede venir como JSON array
	 * (multipleFiles) o como URL única. Normaliza a una lista de URLs absolutas.
	 */
	private static function entry_file_urls( array $entry, $field_id ): array
	{
		$raw = (string) ( $entry[ (string) $field_id ] ?? '' );
		if ( $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$candidates = $decoded;
		} else {
			$candidates = array( $raw );
		}
		$urls = array();
		foreach ( $candidates as $c ) {
			$c = trim( (string) $c );
			if ( $c === '' ) {
				continue;
			}
			// Desescapar slashes que GF deja en el JSON de URLs (\/).
			$c = str_replace( '\\/', '/', $c );
			if ( preg_match( '#^https?://#i', $c ) ) {
				$urls[] = esc_url_raw( $c );
			}
		}
		return $urls;
	}

	// ── Backfill (retro-import de entries GF históricas) ─────────────────

	/**
	 * Retro-importa entries GF de los forms configurados hacia
	 * `estandar_capture_submissions` (misma lógica del puente, SIN correo).
	 * Útil para poblar el Panel Postventa con los datos de este mes antes de
	 * la activación del puente. Idempotente: salta entries ya registradas
	 * (mismo gf_entry_id) y entries sin form_id configurado.
	 *
	 * @return array{imported:int, skipped:int, errors:int, detail:array}
	 */
	public static function backfill( string $from, string $to = '', array $form_ids = array() ): array
	{
		$ids = $form_ids ? $form_ids : self::form_ids();
		$to  = $to !== '' ? $to : gmdate( 'Y-m-d' );
		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		if ( ! $from_ts || ! $to_ts || $to_ts < $from_ts ) {
			return array( 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'detail' => array( 'rango_invalido' => "$from → $to" ) );
		}

		$imported = 0; $skipped = 0; $errors = 0; $detail = array();
		foreach ( $ids as $form_id ) {
			$form = class_exists( 'GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
			if ( ! is_array( $form ) ) {
				$detail[] = "form {$form_id}: no existe";
				continue;
			}
			$criteria = array(
				'start_date' => gmdate( 'Y-m-d', $from_ts ),
				'end_date'   => gmdate( 'Y-m-d', $to_ts ),
				'status'     => 'active',
			);
			$total = 0;
			$entries = \GFAPI::get_entries( $form_id, $criteria, array( 'date_created' => 'DESC' ), $total );
			$form_count = 0; $form_skip = 0; $form_err = 0;
			foreach ( (array) $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				if ( self::submission_exists_for_entry( (string) ( $entry['id'] ?? '' ) ) ) {
					$skipped++; $form_skip++;
					continue;
				}
				$capture = self::map_entry( $entry, $form );
				// Preserva la fecha ORIGINAL de la entry GF (no la del import).
				$orig_date = (string) ( $entry['date_created'] ?? '' );
				if ( $orig_date !== '' && strtotime( $orig_date ) !== false ) {
					$capture['created_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $orig_date ) );
				}
				$result  = Ingestion::ingest( $capture );
				if ( ! empty( $result['id'] ) ) {
					$imported++; $form_count++;
					$detail[] = 'gf-' . $form_id . '#' . (int) $entry['id'] . ' → submission#' . (int) $result['id'];
				} else {
					$errors++; $form_err++;
					$detail[] = 'gf-' . $form_id . '#' . (int) $entry['id'] . ': error al ingestar';
				}
			}
			$detail[] = sprintf( 'form %d: %d importadas, %d omitidas, %d errores', $form_id, $form_count, $form_skip, $form_err );
		}
		return array( 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'detail' => $detail );
	}

	/** ¿Ya existe una submission con este gf_entry_id? (dedupe idempotente). */
	public static function submission_exists_for_entry( string $gf_entry_id ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $gf_entry_id === '' ) {
			return false;
		}
		$pattern = '%' . $wpdb->esc_like( '"gf_entry_id":"' . $gf_entry_id . '"' ) . '%';
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE fields_json LIKE %s',
			$pattern
		) );
	}

	// ── Sincronización de la notificación GF (evita doble correo) ─────────

	/**
	 * Aplica el toggle `disable_gf_notify`: al activarlo desactiva la
	 * notificación admin de GF en los forms configurados; al desactivarlo la
	 * restaura. Idempotente. El resto de notificaciones (autorespuesta al
	 * cliente) NO se toca.
	 */
	public static function sync_gf_notification(): void
	{
		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}
		$s  = self::settings();
		$on = ! empty( $s['disable_gf_notify'] );
		foreach ( self::form_ids() as $form_id ) {
			try {
				$form = \GFAPI::get_form( $form_id );
				if ( ! is_array( $form ) ) {
					continue;
				}
				if ( isset( $form['notifications'][ self::GF_NOTIF_ID ] ) ) {
					$notif = $form['notifications'][ self::GF_NOTIF_ID ];
					$notif['isActive'] = $on ? false : true;
					$form['notifications'][ self::GF_NOTIF_ID ] = $notif;
					\GFAPI::update_form( $form );
				}
			} catch ( \Throwable $e ) {
				// No rompe el flujo si GFAPI falla.
			}
		}
	}
}
