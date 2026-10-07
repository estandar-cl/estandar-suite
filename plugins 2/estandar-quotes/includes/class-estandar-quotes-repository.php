<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Repository {
	public static function create_draft( array $args ): int {
		global $wpdb;
		$profile_key = Estandar_Quotes_Profiles::key( (string) ( $args['profile_key'] ?? 'base' ) ); $profile = Estandar_Quotes_Profiles::get( $profile_key );
		$snapshot = self::canonical_snapshot( (array) ( $args['lead_snapshot'] ?? array() ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$quote_number = Estandar_Quotes_Schema::next_quote_number();
		$ok = $wpdb->insert( Estandar_Quotes_Schema::quotes_table(), array(
			'quote_uuid' => wp_generate_uuid4(), 'quote_number' => $quote_number, 'project_slug' => sanitize_title( (string) ( $args['project_slug'] ?? '' ) ),
			'lead_id' => absint( $args['lead_id'] ?? 0 ) ?: null, 'submission_id' => absint( $args['submission_id'] ?? 0 ) ?: null,
			'profile_key' => $profile_key, 'profile_version' => sanitize_text_field( (string) ( $profile['version'] ?? '1' ) ),
			'status' => 'draft', 'lead_snapshot_json' => wp_json_encode( $snapshot ), 'created_by' => absint( $args['created_by'] ?? get_current_user_id() ) ?: null, 'operator_id' => absint( $args['operator_id'] ?? 0 ) ?: null, 'created_at' => $now, 'updated_at' => $now,
		) );
		if ( ! $ok ) { return 0; } $quote_id = absint( $wpdb->insert_id ); self::event( $quote_id, 'quote.draft.created', null, array( 'source' => sanitize_key( (string) ( $args['source'] ?? 'manual' ) ) ), absint( $args['operator_id'] ?? 0 ) ); return $quote_id;
	}
	/** Rebinds a quote's owning Leads operator. Caller must have already verified the new operator's live authorization for the underlying submission. */
	public static function rebind_operator( int $quote_id, int $operator_id ): bool {
		global $wpdb; $operator_id = absint( $operator_id ); if ( $quote_id <= 0 || $operator_id <= 0 ) { return false; }
		$changed = $wpdb->update( Estandar_Quotes_Schema::quotes_table(), array( 'operator_id' => $operator_id, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $quote_id ) );
		if ( false === $changed ) { return false; } self::event( $quote_id, 'quote.operator.rebound', null, array(), $operator_id ); return true;
	}
	public static function add_revision( int $quote_id, array $document, int $actor_id = 0 ): int {
		global $wpdb; $quote = self::get( $quote_id ); if ( ! $quote || ! in_array( $quote['status'], array( 'draft', 'review' ), true ) ) { return 0; }
		$document = Estandar_Quotes_Profiles::validate_snapshot( (string) $quote['profile_key'], $document );
		$revision_no = absint( $quote['current_revision_no'] ) + 1; $json = Estandar_Quotes_Canonical_Json::encode( $document ); $now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( 'START TRANSACTION' );
		$ok = $wpdb->insert( Estandar_Quotes_Schema::revisions_table(), array( 'quote_id' => $quote_id, 'revision_no' => $revision_no, 'revision_uuid' => wp_generate_uuid4(), 'snapshot_json' => $json, 'snapshot_hash' => Estandar_Quotes_Canonical_Json::hash( $document ), 'created_by' => $actor_id ?: get_current_user_id(), 'created_at' => $now ) );
		if ( ! $ok ) { $wpdb->query( 'ROLLBACK' ); return 0; } $revision_id = absint( $wpdb->insert_id );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Estandar_Quotes_Schema::quotes_table() . ' SET current_revision_no = %d, updated_at = %s WHERE id = %d AND status IN (\'draft\',\'review\') AND current_revision_no = %d', $revision_no, $now, $quote_id, $revision_no - 1 ) );
		if ( $updated !== 1 ) { $wpdb->query( 'ROLLBACK' ); return 0; } $wpdb->query( 'COMMIT' ); self::event( $quote_id, 'quote.revision.created', $revision_id, array( 'revision_no' => $revision_no ) ); return $revision_id;
	}
	public static function transition( int $quote_id, string $to, int $actor_id = 0 ): bool {
		global $wpdb; $quote = self::get( $quote_id ); if ( ! $quote ) { return false; } Estandar_Quotes_State_Machine::assert_transition( (string) $quote['status'], $to );
		if ( $to === 'issued' && absint( $quote['current_revision_no'] ) < 1 ) { throw new LogicException( 'An issued quote requires an immutable revision.' ); }
		$now = gmdate( 'Y-m-d H:i:s' ); $changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Estandar_Quotes_Schema::quotes_table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', sanitize_key( $to ), $now, $quote_id, $quote['status'] ) ); $ok = ( $changed === 1 ); if ( $ok ) { self::event( $quote_id, 'quote.status.' . sanitize_key( $to ), null, array( 'from' => $quote['status'] ), $actor_id ); } return $ok;
	}
	public static function get( int $quote_id ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::quotes_table() . ' WHERE id = %d LIMIT 1', $quote_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	public static function get_by_uuid( string $quote_uuid ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::quotes_table() . ' WHERE quote_uuid = %s LIMIT 1', sanitize_text_field( $quote_uuid ) ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	public static function get_by_submission( int $submission_id ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::quotes_table() . ' WHERE submission_id = %d AND status IN (\'draft\', \'review\', \'issued\') ORDER BY id DESC LIMIT 1', $submission_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	public static function get_by_submission_any_status( int $submission_id ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::quotes_table() . ' WHERE submission_id = %d ORDER BY id DESC LIMIT 1', $submission_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	public static function save_draft( int $quote_id, array $document, int $actor_id = 0 ): bool {
		global $wpdb; $quote = self::get( $quote_id ); if ( ! $quote || $quote['status'] !== 'draft' ) { return false; }
		$document = Estandar_Quotes_Profiles::validate_snapshot( (string) $quote['profile_key'], $document );
		$changed = $wpdb->update( Estandar_Quotes_Schema::quotes_table(), array( 'draft_document_json' => Estandar_Quotes_Canonical_Json::encode( $document ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $quote_id, 'status' => 'draft' ) );
		if ( false === $changed ) { return false; } self::event( $quote_id, 'quote.draft.saved', null, array(), $actor_id ); return true;
	}
	/** Initial defaults may be incomplete; full profile validation happens on explicit save or issue. */
	public static function initialize_draft( int $quote_id, array $document ): bool {
		global $wpdb; $quote = self::get( $quote_id ); if ( ! $quote || $quote['status'] !== 'draft' ) { return false; }
		return false !== $wpdb->update( Estandar_Quotes_Schema::quotes_table(), array( 'draft_document_json' => Estandar_Quotes_Canonical_Json::encode( $document ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $quote_id, 'status' => 'draft' ) );
	}
	public static function revisions( int $quote_id ): array { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id = %d ORDER BY revision_no DESC', $quote_id ), ARRAY_A ); return is_array( $rows ) ? $rows : array(); }
	private static function event( int $quote_id, string $type, $revision_id = null, array $metadata = array(), int $actor_id = 0 ): void { global $wpdb; $type = preg_replace( '/[^a-z0-9._-]/', '', strtolower( $type ) ); $wpdb->insert( Estandar_Quotes_Schema::events_table(), array( 'quote_id' => $quote_id, 'revision_id' => $revision_id ?: null, 'event_type' => $type, 'actor_id' => $actor_id ?: get_current_user_id(), 'metadata_json' => wp_json_encode( $metadata ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); do_action( 'estandar_quote_event', $type, $quote_id, $revision_id, $metadata ); }
	private static function canonical_snapshot( array $snapshot ): array { return Estandar_Quotes_Canonical_Json::normalize( array( 'lead' => (array) ( $snapshot['lead'] ?? array() ), 'submission' => (array) ( $snapshot['submission'] ?? array() ), 'captured_at' => gmdate( 'Y-m-d H:i:s' ) ) ); }
}
