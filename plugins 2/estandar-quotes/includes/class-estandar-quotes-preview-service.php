<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Read-only source resolution and deterministic preview artifact construction. */
class Estandar_Quotes_Preview_Service {
	public static function build( array $quote, string $source, array $document, string $revision_uuid = '' ): array {
		if ( ! in_array( $quote['status'] ?? '', array( 'draft', 'review', 'issued', 'superseded', 'expired' ), true ) ) { throw new InvalidArgumentException( 'Quote state is not previewable.' ); }
		if ( ! in_array( $source, array( 'revision', 'draft' ), true ) || ( $source === 'revision' && $revision_uuid === '' ) ) { throw new InvalidArgumentException( 'Explicit preview source is required.' ); }
		$profile = Estandar_Quotes_Profiles::key( (string) ( $quote['profile_key'] ?? '' ) ); $definition = Estandar_Quotes_Document_Definitions::validate( Estandar_Quotes_Document_Definitions::get( $profile ), $profile ); self::validate_assets( $document, $definition );
		$vm = Estandar_Quotes_View_Model::from_document( $profile, $document, array( 'quote_number' => $quote['quote_uuid'] ?? '' ) ); $preview = Estandar_Quotes_Document_Engine::render_preview( $vm, $definition ); $print = Estandar_Quotes_Document_Engine::render_print( $vm, $definition );
		return array( 'source' => $source, 'view_model' => $vm, 'definition' => $definition, 'preview_html' => $preview, 'print_html' => $print, 'hashes' => array( 'source' => Estandar_Quotes_Canonical_Json::hash( $document ), 'view_model' => Estandar_Quotes_Canonical_Json::hash( $vm ), 'definition' => Estandar_Quotes_Canonical_Json::hash( $definition ), 'preview_html' => hash( 'sha256', $preview ), 'print_html' => hash( 'sha256', $print ) ) );
	}
	public static function resolve( string $quote_uuid, string $source, string $revision_uuid = '' ): array {
		global $wpdb; $quote = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::quotes_table() . ' WHERE quote_uuid = %s LIMIT 1', $quote_uuid ), ARRAY_A ); if ( ! is_array( $quote ) ) { throw new RuntimeException( 'Quote not found.' ); }
		if ( $source === 'draft' ) { $document = json_decode( (string) ( $quote['draft_document_json'] ?? '' ), true ); if ( ! is_array( $document ) ) { throw new RuntimeException( 'Draft document unavailable.' ); } return self::build( $quote, 'draft', $document ); }
		if ( $source !== 'revision' || $revision_uuid === '' ) { throw new InvalidArgumentException( 'Revision UUID is required.' ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT revision_uuid, snapshot_json FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id = %d AND revision_uuid = %s LIMIT 1', absint( $quote['id'] ), $revision_uuid ), ARRAY_A ); if ( ! is_array( $row ) ) { throw new RuntimeException( 'Revision not found.' ); } $document = json_decode( (string) $row['snapshot_json'], true ); if ( ! is_array( $document ) ) { throw new RuntimeException( 'Revision snapshot invalid.' ); } return self::build( $quote, 'revision', $document, (string) $row['revision_uuid'] );
	}
	private static function validate_assets( array $document, array $definition ): void { $allowed = array_flip( (array) ( $definition['assets'] ?? array() ) ); foreach ( (array) ( $document['property']['images'] ?? array() ) as $asset ) { if ( ! is_array( $asset ) || array_diff( array_keys( $asset ), array( 'kind', 'asset_ref' ) ) || ! in_array( $asset['kind'] ?? '', array( 'floor_plan', 'project_image' ), true ) || ! isset( $allowed[ $asset['asset_ref'] ?? '' ] ) ) { throw new InvalidArgumentException( 'Unapproved document asset.' ); } } }
}
