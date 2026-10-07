<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quotes — cotizaciones comerciales auditables (v2, absorbe estandar-quotes).
 * Own table; ciclo de vida draft → sent → accepted|rejected|expired.
 * Fase 1 (QUOTE_V2): CRUD + documento HTML con marca + envío por Core\Mail.
 */
class Quotes
{
	const TABLE = 'estandar_flow_quotes';

	/** Estados del ciclo de vida de la cotización. */
	const STATUSES = array( 'draft', 'sent', 'accepted', 'rejected', 'expired' );

	private static $migrated = false;

	/** Migración idempotente de columnas nuevas (QUOTE_V2). */
	public static function ensure_columns(): void
	{
		if ( self::$migrated ) {
			return;
		}
		self::$migrated = true;
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // schema aún no creada por activate_schema()
		}
		$cols = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ) as $c ) {
			$cols[] = (string) $c['Field'];
		}
		$add = array(
			'title'         => "ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT '' AFTER customer_id",
			'items_json'    => 'ADD COLUMN items_json LONGTEXT NULL AFTER title',
			'validity_days' => 'ADD COLUMN validity_days INT NOT NULL DEFAULT 15 AFTER items_json',
			'notes'         => 'ADD COLUMN notes TEXT NULL AFTER validity_days',
			'lead_id'       => 'ADD COLUMN lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER origin_quote_id',
			'sent_at'       => 'ADD COLUMN sent_at DATETIME NULL AFTER status',
		);
		foreach ( $add as $col => $sql ) {
			if ( ! in_array( $col, $cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} {$sql}" );
			}
		}
	}

	/** Pure: deterministic quote key (stable for adoption/idempotency). */
	public static function quote_uuid( int $pipeline_id, string $nonce ): string
	{
		return md5( $pipeline_id . ':' . $nonce );
	}

	public static function create( array $data ): int
	{
		self::ensure_columns();
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$amount = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (float) $data['amount'] : null;
		$uuid   = (string) ( $data['quote_uuid'] ?? '' );
		if ( $uuid === '' ) {
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
		}
		$status = sanitize_key( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'draft';
		}
		$items = self::normalize_items( $data['items'] ?? null );
		$now   = current_time( 'mysql' );
		// M13-fix: correlativo por sitio al crear (antes solo lo asignaba duplicate()).
		$quote_number = trim( (string) ( $data['quote_number'] ?? '' ) );
		if ( '' === $quote_number && class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			$quote_number = \Estandar\Flow\Quotes\Repository::next_quote_number();
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'quote_uuid'    => $uuid,
			'quote_number'  => $quote_number,
			'pipeline_id'   => (int) ( $data['pipeline_id'] ?? 0 ),
			'customer_id'   => (int) ( $data['customer_id'] ?? 0 ),
			'lead_id'       => (int) ( $data['lead_id'] ?? 0 ),
			'profile_key'   => sanitize_key( (string) ( $data['profile_key'] ?? '' ) ),
			'title'         => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'items_json'    => $items,
			'validity_days' => (int) ( $data['validity_days'] ?? 15 ),
			'amount'        => $amount,
			'currency'      => strtoupper( substr( (string) ( $data['currency'] ?? 'CLP' ), 0, 8 ) ),
			'status'        => $status,
			'notes'         => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'origin_quote_id' => (int) ( $data['origin_quote_id'] ?? 0 ),
			'sent_at'       => null,
			'created_at'    => $now,
			'updated_at'    => $now,
			'client_tax_id' => sanitize_text_field( (string) ( $data['client_tax_id'] ?? '' ) ),
		), array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array
	{
		self::ensure_columns();
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['items'] = self::decode_items( (string) ( $row['items_json'] ?? '' ) );
		return $row;
	}

	public static function update( int $id, array $data ): bool
	{
		self::ensure_columns();
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return false;
		}
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['title'] ) ) { $update['title'] = sanitize_text_field( (string) $data['title'] ); }
		if ( array_key_exists( 'items', $data ) ) { $update['items_json'] = self::normalize_items( $data['items'] ); }
		if ( isset( $data['validity_days'] ) ) { $update['validity_days'] = (int) $data['validity_days']; }
		if ( array_key_exists( 'amount', $data ) ) {
			$update['amount'] = ( $data['amount'] !== '' && $data['amount'] !== null ) ? (float) $data['amount'] : null;
		}
		if ( isset( $data['currency'] ) ) { $update['currency'] = strtoupper( substr( (string) $data['currency'], 0, 8 ) ); }
		if ( isset( $data['status'] ) ) {
			$s = sanitize_key( (string) $data['status'] );
			if ( in_array( $s, self::STATUSES, true ) ) { $update['status'] = $s; }
		}
		if ( array_key_exists( 'notes', $data ) ) { $update['notes'] = sanitize_textarea_field( (string) $data['notes'] ); }
		if ( isset( $data['lead_id'] ) ) { $update['lead_id'] = (int) $data['lead_id']; }
		if ( isset( $data['profile_key'] ) ) { $update['profile_key'] = sanitize_key( (string) $data['profile_key'] ); }
		if ( array_key_exists( 'client_tax_id', $data ) ) { $update['client_tax_id'] = sanitize_text_field( (string) $data['client_tax_id'] ); }
		if ( ! empty( $data['mark_sent'] ) ) { $update['sent_at'] = current_time( 'mysql' ); }
		if ( isset( $data['sent_at'] ) ) { $update['sent_at'] = sanitize_text_field( (string) $data['sent_at'] ); }
		// M7: validez + timestamps de decisión del ciclo de vida.
		if ( array_key_exists( 'valid_until', $data ) ) { $update['valid_until'] = ( '' !== (string) $data['valid_until'] ) ? sanitize_text_field( (string) $data['valid_until'] ) : null; }
		if ( isset( $data['accepted_at'] ) ) { $update['accepted_at'] = sanitize_text_field( (string) $data['accepted_at'] ); }
		if ( isset( $data['rejected_at'] ) ) { $update['rejected_at'] = sanitize_text_field( (string) $data['rejected_at'] ); }
		if ( array_key_exists( 'reject_reason', $data ) ) { $update['reject_reason'] = sanitize_text_field( (string) $data['reject_reason'] ); }
		// M8: token público del link del cliente (aditivo; valida formato o vacío).
		if ( array_key_exists( 'public_token', $data ) ) {
			$pt = strtolower( trim( (string) $data['public_token'] ) );
			if ( '' === $pt || preg_match( '/^[a-f0-9]{16,64}$/', $pt ) ) {
				$update['public_token'] = $pt;
			}
		}
		return false !== $wpdb->update( $wpdb->prefix . self::TABLE, $update, array( 'id' => $id ) );
	}

	public static function list( int $limit = 100 ): array
	{
		self::ensure_columns();
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, quote_uuid, quote_number, pipeline_id, customer_id, lead_id, title, items_json, amount, currency, status, validity_days, sent_at, origin_quote_id, created_at, public_token
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['items'] = self::decode_items( (string) ( $r['items_json'] ?? '' ) );
		}
		return $rows;
	}

	/** Lista de cotizaciones de un lead (para la ficha del panel). */
	public static function for_lead( int $lead_id ): array
	{
		self::ensure_columns();
		global $wpdb;
		if ( ! isset( $wpdb ) || $lead_id <= 0 ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, quote_uuid, title, amount, currency, status, validity_days, sent_at, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . " WHERE lead_id = %d ORDER BY id DESC LIMIT 20", $lead_id
		), ARRAY_A );
		return $rows;
	}

	/** Normaliza items a JSON (array de filas {label,qty,unit,amount}). */
	public static function normalize_items( $items ): ?string
	{
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}
		$out = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$label = trim( (string) ( $it['label'] ?? '' ) );
			$qty   = isset( $it['qty'] ) && is_numeric( $it['qty'] ) ? (float) $it['qty'] : 1;
			$unit  = trim( (string) ( $it['unit'] ?? '' ) );
			$amt   = isset( $it['amount'] ) && is_numeric( $it['amount'] ) ? (float) $it['amount'] : 0;
			if ( $label === '' && $amt <= 0 ) {
				continue;
			}
			$out[] = array( 'label' => $label, 'qty' => $qty, 'unit' => $unit, 'amount' => $amt );
		}
		return $out ? json_encode( $out, JSON_UNESCAPED_UNICODE ) : null;
	}

	public static function decode_items( string $json ): array
	{
		$items = json_decode( $json, true );
		return is_array( $items ) ? $items : array();
	}

	/** Total de la cotización: suma de items si hay, si no el amount. */
	public static function total( array $quote ): float
	{
		$items = (array) ( $quote['items'] ?? self::decode_items( (string) ( $quote['items_json'] ?? '' ) ) );
		if ( $items ) {
			$sum = 0.0;
			foreach ( $items as $it ) {
				$sum += (float) ( $it['amount'] ?? 0 ) * (float) ( $it['qty'] ?? 1 );
			}
			return $sum;
		}
		return (float) ( $quote['amount'] ?? 0 );
	}

	/** Formatea un monto (CLP con separador de miles). */
	public static function format_amount( float $amount, string $currency = 'CLP' ): string
	{
		if ( $currency === 'UF' ) {
			return 'UF ' . number_format( $amount, 2, ',', '.' );
		}
		return '$ ' . number_format( $amount, 0, ',', '.' );
	}

	/**
	 * URL pública del cliente (M8): delega en `Gateway` (genera/persiste el
	 * `public_token` si falta). Sin Gateway o sin cotización → ''.
	 */
	public static function public_url( int $id ): string
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Gateway' ) ) {
			return \Estandar\Flow\Quotes\Gateway::public_url( $id );
		}
		return '';
	}

	/** PURE: documento HTML de la cotización con la marca de la Suite. */
	public static function quote_document_html( array $quote, array $lead = array(), array $identity = array() ): string
	{
		if ( empty( $identity ) && class_exists( 'Estandar\\Core\\Mail' ) ) {
			$identity = \Estandar\Core\Mail::identity();
		}
		$logo = isset( $identity['logo_url'] ) && $identity['logo_url'] !== ''
			? '<img src="' . esc_url( $identity['logo_url'] ) . '" alt="' . esc_attr( (string) ( $identity['site_name'] ?? '' ) ) . '" style="max-height:60px;margin-bottom:12px;">'
			: '';
		$customer = (string) ( $lead['name'] ?? $quote['customer_name'] ?? '' );
		$email    = (string) ( $lead['email'] ?? '' );
		$number   = 'COT-' . (int) ( $quote['id'] ?? 0 ) . '-' . substr( (string) ( $quote['quote_uuid'] ?? '' ), 0, 6 );
		$title    = (string) ( $quote['title'] ?? 'Cotización' );
		$validity = (int) ( $quote['validity_days'] ?? 15 );
		$notes    = (string) ( $quote['notes'] ?? '' );

		$rows = '';
		$items = (array) ( $quote['items'] ?? self::decode_items( (string) ( $quote['items_json'] ?? '' ) ) );
		if ( $items ) {
			foreach ( $items as $it ) {
				$qty  = (float) ( $it['qty'] ?? 1 );
				$amt  = (float) ( $it['amount'] ?? 0 );
				$unit = (string) ( $it['unit'] ?? '' );
				$rows .= '<tr><td style="border:1px solid #e2e4e7;padding:8px;">' . esc_html( (string) ( $it['label'] ?? '' ) )
					. ( $unit !== '' ? ' <span style="color:#646970;">(' . esc_html( $unit ) . ')</span>' : '' )
					. '</td><td style="border:1px solid #e2e4e7;padding:8px;text-align:center;">' . esc_html( (string) $qty ) . '</td>'
					. '<td style="border:1px solid #e2e4e7;padding:8px;text-align:right;">' . esc_html( self::format_amount( $amt, (string) ( $quote['currency'] ?? 'CLP' ) ) ) . '</td></tr>';
			}
			$rows .= '<tr><td colspan="2" style="border:1px solid #e2e4e7;padding:8px;text-align:right;font-weight:700;">Total</td>'
				. '<td style="border:1px solid #e2e4e7;padding:8px;text-align:right;font-weight:700;">' . esc_html( self::format_amount( self::total( $quote ), (string) ( $quote['currency'] ?? 'CLP' ) ) ) . '</td></tr>';
		} else {
			$rows = '<tr><td style="border:1px solid #e2e4e7;padding:8px;">' . esc_html( $title ) . '</td>'
				. '<td style="border:1px solid #e2e4e7;padding:8px;text-align:right;font-weight:700;">' . esc_html( self::format_amount( (float) ( $quote['amount'] ?? 0 ), (string) ( $quote['currency'] ?? 'CLP' ) ) ) . '</td></tr>';
		}

		$footer = isset( $identity['footer_text'] ) && $identity['footer_text'] !== ''
			? '<p style="margin-top:18px;color:#999;font-size:12px;">' . wp_kses_post( $identity['footer_text'] ) . '</p>'
			: '';

		return '<div style="max-width:640px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.5;">'
			. $logo
			. '<h2 style="margin:0 0 4px;">' . esc_html( $title ) . '</h2>'
			. '<p style="color:#646970;margin:0 0 14px;">' . esc_html( $number ) . '</p>'
			. '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e2e4e7;"><tr><td style="padding:8px;background:#f6f7f7;width:140px;"><strong>Cliente</strong></td><td style="padding:8px;">' . esc_html( $customer ) . ( $email !== '' ? ' · ' . esc_html( $email ) : '' ) . '</td></tr></table>'
			. '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:14px;"><tr><th style="border:1px solid #e2e4e7;padding:8px;text-align:left;background:#f6f7f7;">Detalle</th><th style="border:1px solid #e2e4e7;padding:8px;background:#f6f7f7;width:60px;">Cant.</th><th style="border:1px solid #e2e4e7;padding:8px;background:#f6f7f7;text-align:right;">Valor</th></tr>' . $rows . '</table>'
			. '<p style="margin:14px 0 0;color:#646970;font-size:13px;">Cotización válida por <strong>' . (int) $validity . ' días</strong> desde la fecha de emisión.</p>'
			. ( $notes !== '' ? '<p style="margin:10px 0 0;color:#555;">' . nl2br( esc_html( $notes ) ) . '</p>' : '' )
			. $footer
			. '</div>';
	}

	/**
	 * Documento canónico de una cotización (M5). Delega en `DocumentBuilder`;
	 * sin el builder (no cargado) devuelve un documento base seguro.
	 */
	public static function document( int $id, array $lead = array() ): array
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' ) ) {
			return \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id, $lead );
		}
		return array(
			'profile'    => 'generic@1',
			'meta'       => array(
				'title'          => 'Cotización',
				'document_title' => 'Cotización',
				'quote_number'   => 'COT-' . $id,
				'issued_at'      => date( 'Y-m-d' ),
				'valid_until'    => date( 'Y-m-d' ),
				'currency'       => 'CLP',
			),
			'issuer'     => array(),
			'client'     => array(),
			'project'    => array(),
			'items'      => array(),
			'totals'     => array(),
			'conditions' => array(),
			'notes'      => '',
			'property'   => array(),
			'pricing'    => array(),
			'financing'  => array(),
		);
	}

	/**
	 * Envía la cotización al cliente por el motor único (Core\Mail).
	 *
	 * M5: usa los settings del módulo (documento + correo) y adjunta el PDF si
	 * el motor está disponible. Retrocompatible: si faltan Settings/Renderer/Pdf
	 * cae al HTML legado y envía sin adjunto. Marca status=sent y sent_at.
	 * Devuelve array {ok, error, transport}.
	 */
	public static function send( int $id, string $recipient = '', array $lead = array(), array $opts = array() ): array
	{
		$quote = self::get( $id );
		if ( ! $quote ) {
			return array( 'ok' => false, 'error' => 'no_encontrada' );
		}
		$has_settings = class_exists( 'Estandar\\Flow\\Quotes\\Settings' );
		if ( $has_settings && ! \Estandar\Flow\Quotes\Settings::enabled() ) {
			return array( 'ok' => false, 'error' => 'modulo_deshabilitado' );
		}
		if ( ! class_exists( 'Estandar\\Core\\Mail' ) ) {
			return array( 'ok' => false, 'error' => 'sin_motor' );
		}
		if ( $recipient === '' ) {
			$recipient = sanitize_email( (string) ( $lead['email'] ?? '' ) );
		}
		if ( ! \Estandar\Core\Mail::validate_address( $recipient ) ) {
			return array( 'ok' => false, 'error' => 'email_invalido' );
		}

		$identity = \Estandar\Core\Mail::identity();
		$email    = $has_settings ? \Estandar\Flow\Quotes\Settings::email() : array();

		// Documento canónico (siempre: alimenta el correo y el PDF adjunto).
		$doc = array();
		if ( class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' ) ) {
			$doc = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id, $lead );
		}

		// Cuerpo del correo: SOLO el texto configurable (`email_body`) renderizado,
		// NO el documento completo (`render_print`); el PDF va como adjunto.
		// Sin el módulo Settings se conserva el HTML legado (retrocompatible).
		$body = '';
		if ( $has_settings ) {
			$body = self::email_body_html( \Estandar\Flow\Quotes\Settings::render_email_body( $doc ) );
		} elseif ( class_exists( 'Estandar\\Flow\\Quotes\\Document\\Renderer' ) ) {
			$body = (string) \Estandar\Flow\Quotes\Document\Renderer::render_print( $doc );
		}
		if ( '' === $body ) {
			$body = self::quote_document_html( $quote, $lead, $identity );
		}

		// M8: enlace público discreto en el correo (guard; no rompe el HTML actual).
		$public_url = self::public_url( $id );
		if ( '' !== $public_url ) {
			$body .= '<p style="margin:18px 0 0;font-size:12px;color:#646970;">'
				. 'Ver cotización en línea: '
				. '<a href="' . esc_url( $public_url ) . '">' . esc_html( $public_url ) . '</a>'
				. '</p>';
		}

		$number  = 'COT-' . (int) $quote['id'] . '-' . substr( (string) ( $quote['quote_uuid'] ?? '' ), 0, 6 );
		$subject = $has_settings ? (string) \Estandar\Flow\Quotes\Settings::subject_for( $doc ) : '';
		if ( '' === $subject ) {
			$subject = (string) ( (string) ( $quote['title'] ?? '' ) !== '' ? $quote['title'] : 'Cotización ' . $number ) . ' — ' . (string) ( $identity['site_name'] ?? '' );
		}

		$from_name  = (string) ( $email['sender_name'] ?? '' );
		if ( '' === $from_name ) {
			$from_name = (string) ( ( $identity['sender_name'] ?? '' ) !== '' ? $identity['sender_name'] : ( $identity['site_name'] ?? '' ) );
		}
		$from_email = (string) ( $email['sender_email'] ?? '' );
		if ( '' === $from_email ) {
			$from_email = (string) ( ( $identity['sender_email'] ?? '' ) !== '' ? $identity['sender_email'] : ( function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '' ) );
		}

		// Copias: CC/CCO de settings + copia al operador como CC.
		$cc  = trim( (string) ( $email['cc'] ?? '' ) );
		$bcc = trim( (string) ( $email['bcc'] ?? '' ) );
		$operator_copy = trim( (string) ( $email['operator_copy'] ?? '' ) );
		if ( '' !== $operator_copy ) {
			$cc = '' !== $cc ? $cc . ',' . $operator_copy : $operator_copy;
		}
		// CC adicionales del llamador (wizard/operación): se suman a los de
		// settings sin pisarlos. `$opts['cc']` acepta string o array.
		$extra_cc = self::normalize_cc( $opts['cc'] ?? array() );
		if ( $extra_cc ) {
			$cc = '' !== trim( $cc ) ? $cc . ',' . implode( ',', $extra_cc ) : implode( ',', $extra_cc );
		}

		// Adjunto PDF (best-effort): nunca rompe el envío.
		$attachments = array();
		$tmp_pdf     = '';
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Document\\Pdf' ) && \Estandar\Flow\Quotes\Document\Pdf::available() ) {
			$pdf = \Estandar\Flow\Quotes\Document\Pdf::render( $doc );
			if ( ! empty( $pdf['ok'] ) && '' !== (string) ( $pdf['bytes'] ?? '' ) ) {
				$path = \Estandar\Flow\Quotes\Document\PdfStore::path( \Estandar\Flow\Quotes\Document\Pdf::filename( $doc ) );
				if ( false !== @file_put_contents( $path, (string) $pdf['bytes'] ) ) {
					$attachments[] = $path;
					$tmp_pdf       = $path;
				}
			}
		}

		$opts = array(
			'to'         => $recipient,
			'subject'    => $subject,
			'html'       => $body,
			'alt'        => wp_strip_all_tags( $body ),
			'from_name'  => $from_name,
			'from_email' => $from_email,
		);
		if ( '' !== $cc ) {
			$opts['cc'] = $cc;
		}
		if ( '' !== $bcc ) {
			$opts['bcc'] = $bcc;
		}
		if ( $attachments ) {
			$opts['attachments'] = $attachments;
		}

		$r = \Estandar\Core\Mail::send( $opts );

		if ( '' !== $tmp_pdf ) {
			@unlink( $tmp_pdf );
		}

		if ( ! empty( $r['ok'] ) ) {
			self::update( $id, array( 'status' => 'sent', 'mark_sent' => true ) );
			// M7: fija la validez desde el momento del envío (si no estaba fijada).
			if ( class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
				\Estandar\Flow\Quotes\Lifecycle::set_valid_until_from_send( $id );
			}
			// Bus de eventos canónicos (S15): cotización emitida/enviada →
			// estandar_suite_quote_issued. Inerte si ningún destino mapea
			// `quote_issued` (dispatch_event no encola ni hace red).
			self::emit_issued( $quote );
			// M9: avanza el journey del lead a PROPOSAL (best-effort, no emite señal).
			if ( class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
				\Estandar\Flow\Quotes\Lifecycle::advance_proposal( $id );
			}
			// M13-fix: trazabilidad en el ledger (antes solo se emitía la acción).
			if ( class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
				\Estandar\Flow\Quotes\Repository::log_event( $id, 'quote.sent', array(
					'to'        => (string) $recipient,
					'transport' => (string) ( $r['transport'] ?? '' ),
				) );
			}
		}
		return array( 'ok' => ! empty( $r['ok'] ), 'error' => (string) ( $r['error'] ?? '' ), 'transport' => (string) ( $r['transport'] ?? '' ) );
	}

	/**
	 * Convierte el cuerpo configurable del correo (`email_body`) a HTML.
	 *
	 * Texto simple → `esc_html` + `nl2br`; si ya trae etiquetas se respeta
	 * saneado con `wp_kses_post`. Nunca fataliza.
	 */
	private static function email_body_html( string $text ): string
	{
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/<[a-z][\s\S]*>/i', $text ) ) {
			$html = function_exists( 'wp_kses_post' ) ? (string) wp_kses_post( $text ) : $text;
		} else {
			$html = function_exists( 'esc_html' ) ? (string) esc_html( $text ) : htmlspecialchars( $text, ENT_QUOTES );
			$html = nl2br( $html, false );
		}
		return '<div style="font-family:inherit;font-size:14px;line-height:1.5;">' . $html . '</div>';
	}

	/**
	 * Duplica una cotización existente como NUEVA cotización en borrador.
	 *
	 * Copia ítems/título/moneda/validez/notas/cliente, fija `origin_quote_id`
	 * apuntando al original (trazabilidad; NO modifica la fila origen), asigna un
	 * correlativo nuevo y copia el `document_json` si existe. Aditivo: sin fuente
	 * devuelve 0 y nunca fataliza.
	 *
	 * @return int Id de la copia (0 si no se pudo).
	 */
	public static function duplicate( int $id ): int
	{
		$src = self::get( $id );
		if ( ! is_array( $src ) ) {
			return 0;
		}
		$title = (string) ( $src['title'] ?? '' );
		$data  = array(
			'lead_id'         => (int) ( $src['lead_id'] ?? 0 ),
			'pipeline_id'     => (int) ( $src['pipeline_id'] ?? 0 ),
			'customer_id'     => (int) ( $src['customer_id'] ?? 0 ),
			'profile_key'     => (string) ( $src['profile_key'] ?? '' ),
			'title'           => '' !== $title ? $title . ' (copia)' : 'Copia',
			'items'           => (array) ( $src['items'] ?? self::decode_items( (string) ( $src['items_json'] ?? '' ) ) ),
			'currency'        => (string) ( $src['currency'] ?? 'CLP' ),
			'validity_days'   => (int) ( $src['validity_days'] ?? 15 ),
			'notes'           => (string) ( $src['notes'] ?? '' ),
			'client_tax_id'   => (string) ( $src['client_tax_id'] ?? '' ),
			'status'          => 'draft',
			'origin_quote_id' => $id,
		);
		$new_id = self::create( $data );
		if ( $new_id <= 0 ) {
			return 0;
		}

		// Correlativo nuevo + copia del documento canónico guardado (best-effort).
		global $wpdb;
		if ( isset( $wpdb ) ) {
			// Correlativo único: máximo entre el siguiente del sitio y el id nuevo.
			$number = sprintf( 'COT-%06d', $new_id );
			if ( class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
				$candidate = \Estandar\Flow\Quotes\Repository::next_quote_number();
				$cand_num  = (int) preg_replace( '/\D+/', '', (string) $candidate );
				if ( $cand_num > $new_id ) {
					$number = sprintf( 'COT-%06d', $cand_num );
				}
			}
			$upd = array( 'quote_number' => $number );
			if ( ! empty( $src['document_json'] ) ) {
				$upd['document_json'] = (string) $src['document_json'];
			}
			$wpdb->update( $wpdb->prefix . self::TABLE, $upd, array( 'id' => $new_id ) );
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			\Estandar\Flow\Quotes\Repository::log_event( $new_id, 'quote.duplicated', array( 'origin_quote_id' => $id ) );
		}

		return $new_id;
	}

	/** PURE: payload canónico mínimo de una cotización emitida (sin PII). */
	public static function issued_payload( array $quote ): array
	{
		return array(
			'id'          => (int) ( $quote['id'] ?? 0 ),
			'quote_uuid'  => (string) ( $quote['quote_uuid'] ?? '' ),
			'pipeline_id' => (int) ( $quote['pipeline_id'] ?? 0 ),
			'customer_id' => (int) ( $quote['customer_id'] ?? 0 ),
			'lead_id'     => (int) ( $quote['lead_id'] ?? 0 ),
			'title'       => (string) ( $quote['title'] ?? '' ),
			'amount'      => isset( $quote['amount'] ) && is_numeric( $quote['amount'] ) ? (float) $quote['amount'] : null,
			'currency'    => (string) ( $quote['currency'] ?? 'CLP' ),
			'status'      => 'sent',
		);
	}

	/**
	 * PURE: normaliza una lista de CC (string separado por comas/espacios o
	 * array) a direcciones válidas, únicas y saneadas. Sin WordPress usa
	 * `filter_var` como validación. Nunca fataliza.
	 *
	 * @param string|array $cc
	 * @return array<int,string>
	 */
	public static function normalize_cc( $cc ): array
	{
		if ( is_array( $cc ) ) {
			$parts = array();
			foreach ( $cc as $one ) {
				if ( is_scalar( $one ) ) {
					$parts[] = (string) $one;
				}
			}
			$cc = implode( ',', $parts );
		}
		$cc  = is_string( $cc ) ? $cc : '';
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $cc ) as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			if ( function_exists( 'sanitize_email' ) ) {
				$item = (string) sanitize_email( $item );
			}
			if ( '' === $item ) {
				continue;
			}
			if ( function_exists( 'is_email' ) ) {
				$valid = (bool) is_email( $item );
			} elseif ( class_exists( 'Estandar\\Core\\Mail' ) && method_exists( 'Estandar\\Core\\Mail', 'validate_address' ) ) {
				$valid = (bool) \Estandar\Core\Mail::validate_address( $item );
			} else {
				$valid = (bool) filter_var( $item, FILTER_VALIDATE_EMAIL );
			}
			if ( $valid && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/** Best-effort canonical quote-issued event (S15). Inerte sin mapeo. */
	public static function emit_issued( array $quote ): void
	{
		if ( ! function_exists( 'do_action' ) || empty( $quote['id'] ) ) {
			return;
		}
		do_action( 'estandar_suite_quote_issued', self::issued_payload( $quote ) );
	}
}
