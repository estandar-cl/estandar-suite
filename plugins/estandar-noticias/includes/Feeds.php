<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feeds {

	const UA = 'EstandarNoticias/0.1 (+https://estandar.cl)';

	public static function fetch( string $url ): ?string {
		$url = trim( $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 3,
				'user-agent'  => self::UA,
				'headers'     => array( 'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*' ),
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	public static function parse( string $xml ): array {
		if ( trim( $xml ) === '' ) {
			return array();
		}
		$prev = libxml_use_internal_errors( true );
		$sx   = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $sx ) {
			return array();
		}

		$root  = strtolower( $sx->getName() );
		$items = array();
		if ( $root === 'feed' ) {
			foreach ( $sx->entry as $entry ) {
				$items[] = self::atom_item( $entry );
			}
		} elseif ( isset( $sx->channel->item ) ) {
			foreach ( $sx->channel->item as $item ) {
				$items[] = self::rss_item( $item, (string) $sx->channel->title );
			}
		} else {
			foreach ( $sx->item as $item ) {
				$items[] = self::rss_item( $item, '' );
			}
		}
		return array_values( array_filter( $items, static function ( $i ) {
			return ! empty( $i['titulo'] );
		} ) );
	}

	private static function rss_item( $item, string $canal ): array {
		$title = self::clean( (string) $item->title );
		$link  = trim( (string) $item->link );
		if ( $link === '' ) {
			$link = trim( (string) $item->guid );
		}
		$date = trim( (string) $item->pubDate );
		if ( $date === '' ) {
			$dc   = $item->children( 'http://purl.org/dc/elements/1.1/' );
			$date = trim( (string) $dc->date );
		}
		$text = self::node_xml( $item->description );
		$enc  = self::node_xml( $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded );
		if ( trim( $enc ) !== '' ) {
			$text = $enc;
		}
		$srcraw = trim( (string) $item->source );
		$source = self::clean( $srcraw !== '' ? $srcraw : $canal );
		if ( $source === '' ) {
			$source = self::host( $link );
		}
		return array(
			'titulo' => $title,
			'link'   => $link,
			'fuente' => $source,
			'fecha'  => self::iso_date( $date ),
			'texto'  => self::clean( $text ),
		);
	}

	private static function atom_item( $entry ): array {
		$title = self::clean( (string) $entry->title );
		$link  = '';
		foreach ( $entry->link as $l ) {
			$rel = (string) $l['rel'];
			if ( $rel === '' || $rel === 'alternate' ) {
				$link = (string) $l['href'];
				break;
			}
		}
		$date = trim( (string) $entry->updated );
		if ( $date === '' ) {
			$date = trim( (string) $entry->published );
		}
		$text = self::node_xml( $entry->content );
		if ( trim( self::clean( $text ) ) === '' ) {
			$text = self::node_xml( $entry->summary );
		}
		$source = self::clean( (string) $entry->author->name );
		if ( $source === '' ) {
			$source = self::host( $link );
		}
		return array(
			'titulo' => $title,
			'link'   => $link,
			'fuente' => $source,
			'fecha'  => self::iso_date( $date ),
			'texto'  => self::clean( $text ),
		);
	}

	private static function node_xml( $node ): string {
		if ( ! is_object( $node ) || ! method_exists( $node, 'asXML' ) ) {
			return '';
		}
		$xml = $node->asXML();
		return is_string( $xml ) ? $xml : '';
	}

	public static function clean( string $html ): string {
		$s = (string) $html;
		for ( $i = 0; $i < 2; $i++ ) {
			$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$s = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $s );
			$s = wp_strip_all_tags( $s );
		}
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	public static function iso_date( string $date ): string {
		$date = trim( $date );
		if ( $date === '' ) {
			return '';
		}
		$ts = strtotime( $date );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'c', $ts );
	}

	public static function host( string $link ): string {
		$h = wp_parse_url( $link, PHP_URL_HOST );
		return $h ? preg_replace( '/^www\./i', '', (string) $h ) : '';
	}
}
