<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable-revision delivery orchestration. It deliberately neither renders nor regenerates artifacts. */
class Estandar_Quotes_Delivery_Service {
	private static $clock = null;
	private static $uuid_factory = null;

	public static function set_runtime_for_tests( $clock = null, $uuid_factory = null ): void {
		self::$clock = $clock;
		self::$uuid_factory = $uuid_factory;
	}

	public static function create( array $revision, array $artifact, array $recipients, string $subject, string $template = 'client', string $language = 'es_CL', array $metadata = array(), string $idempotency_salt = '' ): array {
		global $wpdb;
		self::assert_source( $revision, $artifact );
		$emails = self::recipients( $recipients );
		if ( ! $emails ) { throw new InvalidArgumentException( 'Invalid delivery recipients.' ); }
		$template = sanitize_key( $template );
		if ( ! in_array( $template, array( 'client', 'operator' ), true ) ) { throw new InvalidArgumentException( 'Unknown delivery template.' ); }
		$subject = sanitize_text_field( $subject );
		if ( $subject === '' ) { throw new InvalidArgumentException( 'Invalid delivery subject.' ); }
		$language = sanitize_text_field( $language );
		$key = hash( 'sha256', implode( '|', array( $revision['revision_uuid'], $artifact['artifact_uuid'], (int) $artifact['generation_no'], implode( ',', $emails ), $subject, $template, $language, $idempotency_salt ) ) );
		$table = Estandar_Quotes_Schema::deliveries_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		if ( is_array( $existing ) ) { return $existing; }
		$now = self::now();
		$row = array(
			'delivery_uuid' => self::$uuid_factory ? call_user_func( self::$uuid_factory ) : wp_generate_uuid4(),
			'revision_uuid' => $revision['revision_uuid'], 'artifact_uuid' => $artifact['artifact_uuid'],
			'generation_no' => (int) $artifact['generation_no'], 'operator_id' => get_current_user_id() ?: null,
			'recipients_json' => wp_json_encode( $emails ), 'subject' => $subject, 'template_key' => $template,
			'language' => $language, 'metadata_json' => wp_json_encode( self::safe_metadata( $metadata ) ),
			'status' => 'pending', 'attempts' => 0, 'last_error' => '', 'idempotency_key' => $key,
			'created_at' => $now, 'updated_at' => $now,
		);
		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted || ! $wpdb->insert_id ) { throw new RuntimeException( 'Delivery persistence failed.' ); }
		$row['id'] = (int) $wpdb->insert_id;
		self::event( 'QUOTE_DELIVERY_CREATED', $row );
		return $row;
	}

	/** An explicit new delivery is intentionally distinct from a resend of an existing delivery. */
	public static function create_new( array $revision, array $artifact, array $recipients, string $subject, string $template = 'client', string $language = 'es_CL', array $metadata = array() ): array {
		return self::create( $revision, $artifact, $recipients, $subject, $template, $language, $metadata, self::$uuid_factory ? call_user_func( self::$uuid_factory ) : wp_generate_uuid4() );
	}

	public static function send( array $delivery, array $artifact, Estandar_Quotes_Mail_Transport $transport, bool $resend = false ): bool {
		global $wpdb;
		self::assert_delivery_artifact( $delivery, $artifact );
		$allowed = $resend ? array( 'failed', 'sent' ) : array( 'pending' );
		if ( ! in_array( $delivery['status'] ?? '', $allowed, true ) ) { return false; }
		$table = Estandar_Quotes_Schema::deliveries_table();
		$now = self::now();
		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$sql = "UPDATE {$table} SET status = 'sending', attempts = attempts + 1, last_attempt_at = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})";
		$params = array_merge( array( $now, $now, (int) $delivery['id'] ), $allowed );
		$claimed = $wpdb->query( $wpdb->prepare( $sql, ...$params ) );
		if ( 1 !== (int) $claimed ) { return false; }
		$attempt = (int) ( $delivery['attempts'] ?? 0 ) + 1;
		$working = $delivery;
		$working['status'] = 'sending'; $working['attempts'] = $attempt; $working['last_attempt_at'] = $now;
		$error = '';
		try {
			$ok = $transport->send( $working, self::metadata( $working, 'client' ), $artifact, self::recipients_from_delivery( $working ) );
			if ( ! $ok ) { $error = 'transport_failed'; }
		} catch ( Throwable $e ) { $ok = false; $error = 'transport_exception'; }
		$final = $ok ? 'sent' : 'failed';
		$values = array( 'status' => $final, 'last_error' => $ok ? '' : $error, 'updated_at' => self::now() );
		if ( $ok ) { $values['sent_at'] = self::now(); }
		$updated = $wpdb->update( $table, $values, array( 'id' => (int) $delivery['id'], 'status' => 'sending' ) );
		if ( false === $updated || 1 !== (int) $updated ) { throw new RuntimeException( 'Delivery finalization failed.' ); }
		if ( $ok ) { self::confirm_operator( $working, $artifact, $transport ); }
		self::event( $ok ? 'QUOTE_DELIVERY_SENT' : 'QUOTE_DELIVERY_FAILED', $working, array( 'attempt' => $attempt ) );
		return $ok;
	}

	public static function resend( array $delivery, array $artifact, Estandar_Quotes_Mail_Transport $transport ): bool { return self::send( $delivery, $artifact, $transport, true ); }

	public static function cancel( array $delivery ): bool {
		global $wpdb;
		if ( ! in_array( $delivery['status'] ?? '', array( 'pending', 'failed' ), true ) ) { return false; }
		$now = self::now(); $table = Estandar_Quotes_Schema::deliveries_table();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', cancelled_at = %s, updated_at = %s WHERE id = %d AND status IN ('pending','failed')", $now, $now, (int) $delivery['id'] ) );
		if ( 1 !== (int) $updated ) { return false; }
		self::event( 'QUOTE_DELIVERY_CANCELLED', $delivery );
		return true;
	}

	private static function assert_source( array $revision, array $artifact ): void {
		if ( empty( $revision['revision_uuid'] ) || ( $artifact['status'] ?? '' ) !== 'ready' || empty( $artifact['artifact_uuid'] ) || ( $artifact['revision_uuid'] ?? '' ) !== $revision['revision_uuid'] || empty( $artifact['generation_no'] ) ) { throw new InvalidArgumentException( 'Invalid delivery source.' ); }
	}
	private static function assert_delivery_artifact( array $delivery, array $artifact ): void {
		if ( empty( $delivery['id'] ) || ( $delivery['revision_uuid'] ?? '' ) === '' || ( $delivery['artifact_uuid'] ?? '' ) !== ( $artifact['artifact_uuid'] ?? '' ) || (int) ( $delivery['generation_no'] ?? 0 ) !== (int) ( $artifact['generation_no'] ?? -1 ) || ( $artifact['status'] ?? '' ) !== 'ready' || ( $artifact['revision_uuid'] ?? '' ) !== ( $delivery['revision_uuid'] ?? '' ) ) { throw new InvalidArgumentException( 'Invalid delivery artifact.' ); }
	}
	private static function recipients( array $recipients ): array { $emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $recipients ), 'is_email' ) ) ); sort( $emails, SORT_STRING ); return $emails; }
	private static function recipients_from_delivery( array $delivery ): array { $decoded = json_decode( (string) ( $delivery['recipients_json'] ?? '' ), true ); return is_array( $decoded ) ? self::recipients( $decoded ) : array(); }
	private static function safe_metadata( array $metadata ): array { return array_intersect_key( $metadata, array_flip( array( 'customer_name', 'summary', 'executive_name', 'expires_at', 'document_reference', 'operator_email', 'delivery_meta' ) ) ); }
	private static function metadata( array $delivery, string $kind ): array { $stored = json_decode( (string) ( $delivery['metadata_json'] ?? '' ), true ); $stored = is_array( $stored ) ? self::safe_metadata( $stored ) : array(); return array_merge( $stored, array( 'kind' => $kind, 'template' => $kind === 'operator' ? 'operator' : $delivery['template_key'], 'language' => $delivery['language'], 'timestamp' => self::now() ) ); }
	private static function confirm_operator( array $delivery, array $artifact, Estandar_Quotes_Mail_Transport $transport ): void { $metadata = self::metadata( $delivery, 'operator' ); if ( empty( $metadata['operator_email'] ) || ! is_email( $metadata['operator_email'] ) ) { return; } try { $transport->send( $delivery, $metadata, $artifact, array( sanitize_email( $metadata['operator_email'] ) ) ); } catch ( Throwable $e ) { /* The customer delivery remains sent; confirmation is best-effort. */ } }
	private static function event( string $type, array $delivery, array $extra = array() ): void { do_action( 'estandar_quote_event', $type, 0, null, array_merge( array( 'delivery_uuid' => $delivery['delivery_uuid'], 'revision_uuid' => $delivery['revision_uuid'], 'artifact_uuid' => $delivery['artifact_uuid'] ), $extra ) ); }
	private static function now(): string { return self::$clock ? call_user_func( self::$clock ) : gmdate( 'Y-m-d H:i:s' ); }
}
