<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Artifacts {
	private static $writer = null;
	private static $deleter = null;
	private static $uuid_factory = null;
	private static $clock = null;
	public static function set_io_for_tests( $writer = null, $deleter = null ): void { self::$writer = $writer; self::$deleter = $deleter; }
	public static function set_runtime_for_tests( $uuid_factory = null, $clock = null ): void { self::$uuid_factory = $uuid_factory; self::$clock = $clock; }
	/** Generates only from Preview_Service artifacts; no domain recalculation or external reads. */
	public static function generate( array $quote, array $preview, Estandar_Quotes_PDF_Adapter $renderer, string $revision_uuid = '', bool $regenerate = false ): array {
		$source = (string) ( $preview['source'] ?? '' ); if ( $source === 'revision' && ! in_array( $quote['status'] ?? '', array( 'review', 'issued' ), true ) ) { throw new InvalidArgumentException( 'Revision is not emitible.' ); } if ( ! in_array( $source, array( 'revision', 'draft' ), true ) ) { throw new InvalidArgumentException( 'Explicit source required.' ); }
		$print_html = (string) ( $preview['print_html'] ?? '' );
		if ( trim( strip_tags( $print_html ) ) === '' ) {
			throw new RuntimeException( 'Cannot generate PDF: print HTML has no content.' );
		}
		global $wpdb; $source_hash = (string) ( $preview['hashes']['source'] ?? '' ); $template_version = (string) ( $preview['definition']['id'] ?? '' ); if ( $source_hash === '' || $template_version === '' ) { throw new InvalidArgumentException( 'Invalid preview artifact.' ); }
		$table = Estandar_Quotes_Schema::artifacts_table(); $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE quote_id = %d AND revision_uuid " . ( $revision_uuid === '' ? 'IS NULL' : '= %s' ) . " AND source_hash = %s AND renderer = %s AND template_version = %s LIMIT 1", ...( $revision_uuid === '' ? array( absint( $quote['id'] ), $source_hash, $renderer->id(), $template_version ) : array( absint( $quote['id'] ), $revision_uuid, $source_hash, $renderer->id(), $template_version ) ) ), ARRAY_A );
		if ( is_array( $existing ) && ! $regenerate ) { return $existing; } $generation_no = $regenerate ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(generation_no) FROM {$table} WHERE quote_id = %d AND source_hash = %s AND renderer = %s AND template_version = %s", absint( $quote['id'] ), $source_hash, $renderer->id(), $template_version ) ) ) + 1 : 1;
		$pdf = $renderer->render( (string) $preview['print_html'], array( 'source_hash' => $source_hash ) ); if ( substr( $pdf, 0, 4 ) !== '%PDF' ) { throw new RuntimeException( 'Renderer returned invalid PDF.' ); }
		$uuid = self::$uuid_factory ? call_user_func( self::$uuid_factory ) : wp_generate_uuid4(); $uploads = wp_upload_dir(); $dir = trailingslashit( $uploads['basedir'] ) . 'estandar-quotes'; wp_mkdir_p( $dir ); $key = 'estandar-quotes/' . $uuid . '.pdf'; $path = trailingslashit( $uploads['basedir'] ) . $key; $written = self::$writer ? call_user_func( self::$writer, $path, $pdf ) : file_put_contents( $path, $pdf, LOCK_EX ); if ( false === $written ) { throw new RuntimeException( 'Artifact storage failed.' ); }
		$row = array( 'artifact_uuid' => $uuid, 'quote_id' => absint( $quote['id'] ), 'revision_id' => null, 'revision_uuid' => $revision_uuid ?: null, 'source_type' => $source, 'source_hash' => $source_hash, 'renderer' => $renderer->id(), 'renderer_version' => $renderer->version(), 'template_version' => $template_version, 'theme_version' => 'quotes-document-theme@1', 'layout_version' => 'quotes-layout@1', 'generation_no' => $generation_no, 'pdf_sha256' => hash( 'sha256', $pdf ), 'byte_size' => strlen( $pdf ), 'storage_key' => $key, 'status' => 'ready', 'generated_at' => self::$clock ? call_user_func( self::$clock ) : gmdate( 'Y-m-d H:i:s' ), 'created_by' => get_current_user_id() ?: null ); $inserted = $wpdb->insert( $table, $row ); if ( false === $inserted || ! $wpdb->insert_id ) { if ( self::$deleter ) { call_user_func( self::$deleter, $path ); } else { @unlink( $path ); } throw new RuntimeException( 'Artifact metadata storage failed.' ); } return $row + array( 'id' => absint( $wpdb->insert_id ) );
	}
}
