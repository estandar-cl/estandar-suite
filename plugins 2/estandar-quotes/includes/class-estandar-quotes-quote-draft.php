<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Estandar_Quotes_Quote_Draft {

	private array $data = [];
	private array $errors = [];
	private array $warnings = [];
	private array $meta = [];

	public function __construct( array $base = [] ) {
		$this->data = $base;
	}

	public function get( ?string $path = null ): mixed {
		if ( $path === null ) { return $this->data; }
		return self::dot_get( $this->data, $path );
	}

	public function set( string $path, mixed $value ): self {
		self::dot_set( $this->data, $path, $value );
		return $this;
	}

	public function merge( array $values ): self {
		$this->data = array_replace_recursive( $this->data, $values );
		return $this;
	}

	public function merge_if_empty( string $path, mixed $value ): self {
		$current = self::dot_get( $this->data, $path );
		if ( $current === null || $current === '' || $current === [] ) {
			self::dot_set( $this->data, $path, $value );
		}
		return $this;
	}

	public function add_error( string $field, string $message ): self {
		$this->errors[] = [ 'field' => $field, 'message' => $message ];
		return $this;
	}

	public function add_warning( string $field, string $message ): self {
		$this->warnings[] = [ 'field' => $field, 'message' => $message ];
		return $this;
	}

	public function set_meta( string $key, mixed $value ): self {
		$this->meta[ $key ] = $value;
		return $this;
	}

	public function get_meta( string $key, mixed $default = null ): mixed {
		return $this->meta[ $key ] ?? $default;
	}

	public function errors(): array { return $this->errors; }
	public function warnings(): array { return $this->warnings; }
	public function has_errors(): bool { return count( $this->errors ) > 0; }

	public function to_array(): array { return $this->data; }

	public static function from_quote_row( array $quote ): self {
		$draft = json_decode( $quote['draft_document_json'] ?? '', true );
		$d = new self( is_array( $draft ) ? $draft : [] );
		$d->set_meta( 'quote_id', (int) ( $quote['id'] ?? 0 ) );
		$d->set_meta( 'quote_uuid', $quote['quote_uuid'] ?? '' );
		$d->set_meta( 'profile_key', $quote['profile_key'] ?? '' );
		$d->set_meta( 'status', $quote['status'] ?? 'draft' );
		$d->set_meta( 'lead_snapshot', json_decode( $quote['lead_snapshot_json'] ?? '{}', true ) ?: [] );
		return $d;
	}

	public function clone(): self {
		$c = new self( $this->data );
		$c->errors = $this->errors;
		$c->warnings = $this->warnings;
		$c->meta = $this->meta;
		return $c;
	}

	private static function dot_get( array $a, string $path ): mixed {
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $a ) || ! array_key_exists( $part, $a ) ) { return null; }
			$a = $a[ $part ];
		}
		return $a;
	}

	private static function dot_set( array &$a, string $path, mixed $value ): void {
		$parts = explode( '.', $path );
		$last = array_pop( $parts );
		foreach ( $parts as $part ) {
			if ( ! isset( $a[ $part ] ) || ! is_array( $a[ $part ] ) ) { $a[ $part ] = []; }
			$a =& $a[ $part ];
		}
		$a[ $last ] = $value;
	}
}
