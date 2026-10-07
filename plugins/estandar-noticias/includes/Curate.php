<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Curate {

	public static function suite_active(): bool {
		return class_exists( '\\Estandar\\Core\\AiKeys' );
	}

	public static function provider(): string {
		$own = (string) Settings::get( 'ai', 'provider', 'deepseek' );
		if ( $own !== '' && $own !== 'deepseek' ) {
			return $own;
		}
		if ( $own === 'deepseek' && (string) Settings::get( 'ai', 'api_key', '' ) !== '' ) {
			return 'deepseek';
		}
		if ( self::suite_active() && method_exists( '\\Estandar\\Core\\AiKeys', 'active_provider' ) ) {
			$p = (string) \Estandar\Core\AiKeys::active_provider();
			if ( $p !== '' ) {
				return $p;
			}
		}
		return $own !== '' ? $own : 'deepseek';
	}

	public static function model(): string {
		$own = (string) Settings::get( 'ai', 'modelo', '' );
		if ( $own !== '' ) {
			return $own;
		}
		if ( self::suite_active() && method_exists( '\\Estandar\\Core\\AiKeys', 'default_model' ) ) {
			return (string) \Estandar\Core\AiKeys::default_model();
		}
		return self::provider() === 'openai' ? 'gpt-4o-mini' : 'deepseek-chat';
	}

	public static function key(): string {
		$own = (string) Settings::get( 'ai', 'api_key', '' );
		if ( $own !== '' ) {
			return $own;
		}
		if ( self::suite_active() ) {
			foreach ( array( self::provider(), null ) as $p ) {
				try {
					$k = $p === null ? \Estandar\Core\AiKeys::get() : \Estandar\Core\AiKeys::get( $p );
				} catch ( \Throwable $e ) {
					$k = '';
				}
				if ( is_string( $k ) && $k !== '' ) {
					return $k;
				}
			}
		}
		return '';
	}

	public static function endpoint(): string {
		return self::provider() === 'openai'
			? 'https://api.openai.com/v1/chat/completions'
			: 'https://api.deepseek.com/v1/chat/completions';
	}

	public static function chat( string $system, string $user, int $max_tokens = 8000, float $temperature = 0.3 ): ?string {
		$key = self::key();
		if ( $key === '' ) {
			return null;
		}
		$res = wp_remote_post(
			self::endpoint(),
			array(
				'timeout' => 180,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode(
					array(
						'model'       => self::model(),
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $user ),
						),
						'max_tokens'  => $max_tokens,
						'temperature' => $temperature,
						'stream'      => false,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return $body['choices'][0]['message']['content'] ?? null;
	}

	public static function discover_feeds( string $tema, int $limit = 8 ): array {
		$tema = trim( $tema );
		if ( $tema === '' || self::key() === '' ) {
			return array();
		}
		$system = 'Eres un curador de fuentes RSS. Devuelve EXCLUSIVAMENTE un JSON válido: '
			. '{"feeds":[{"nombre":"Medio","url":"https://.../feed"}]}. '
			. 'Da 6-10 feeds RSS/Atom reales y estables (URLs que terminen en /feed, /rss, .xml o similar), '
			. 'en español o inglés, sobre el tema pedido. No inventes URLs: si dudas de una, omítela. Sin texto adicional.';
		$resp = self::chat( $system, 'Tema: ' . $tema, 1500, 0.4 );
		$j    = self::extract_json( $resp );
		if ( ! $j || empty( $j['feeds'] ) || ! is_array( $j['feeds'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $j['feeds'] as $f ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$url = esc_url_raw( (string) ( is_array( $f ) ? ( $f['url'] ?? '' ) : $f ) );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$body = Feeds::fetch( $url );
			if ( $body === null ) {
				continue;
			}
			$items = Feeds::parse( $body );
			if ( ! $items ) {
				continue;
			}
			$nombre = is_array( $f ) ? (string) ( $f['nombre'] ?? '' ) : '';
			$out[]  = array(
				'nombre' => sanitize_text_field( $nombre !== '' ? $nombre : Feeds::host( $url ) ),
				'url'    => $url,
				'n'      => count( $items ),
			);
		}
		return $out;
	}

	public static function extract_json( ?string $text ): ?array {
		if ( ! $text ) {
			return null;
		}
		$t = trim( $text );
		$t = preg_replace( '/^```[a-zA-Z]*\n?/', '', $t );
		$t = preg_replace( '/\n?```$/', '', $t );
		$a = strpos( $t, '{' );
		$b = strrpos( $t, '}' );
		if ( $a === false || $b === false || $b < $a ) {
			return null;
		}
		$json = json_decode( substr( $t, $a, $b - $a + 1 ), true );
		return is_array( $json ) ? $json : null;
	}

	public static function curate( array $candidates, string $perfil, array $pesos = array() ): array {
		$candidates = array_values( $candidates );
		if ( ! $candidates ) {
			return array();
		}
		$cands = array();
		foreach ( $candidates as $i => $c ) {
			$cands[] = array(
				'i'      => $i,
				'tema'   => (string) ( $c['tema'] ?? '' ),
				'titulo' => (string) ( $c['titulo'] ?? '' ),
				'fuente' => (string) ( $c['fuente'] ?? '' ),
				'fecha'  => (string) ( $c['fecha'] ?? '' ),
				'texto'  => mb_substr( (string) ( $c['texto'] ?? '' ), 0, 1400 ),
			);
		}

		$system = implode(
			"\n",
			array(
				'Eres el editor de un boletín diario para un PERFIL concreto. Recibes candidatos de noticias de las últimas horas, cada uno con su fuente y texto. Reglas:',
				'1. Conserva SOLO lo que importa a ese PERFIL; descarta ruido, farándula, deportes, notas de color, PR, contenido patrocinado, opinión sin datos y titulares amarillos.',
				'2. No importa la fuente: puedes conservar un medio generalista si el hecho es relevante.',
				'3. Para cada conservado, escribe un resumen en ESPAÑOL, neutral y factual, de 100 a 140 palabras, usando SOLO la información del texto entregado; no inventes datos.',
				'4. Ordena cada tema de más a menos importante para el PERFIL.',
				'5. NO omitas un tema completo: si un tema tiene candidatos y al menos uno aporta algo al PERFIL, conserva al menos UNO de ese tema.',
				'6. Devuelve EXCLUSIVAMENTE un JSON válido: {"items":[{"i":<índice>,"tema":"<tema>","resumen":"..."}]} sin texto adicional ni cercas de código.',
			)
		);

		$user = '';
		if ( $pesos ) {
			$user .= "PESOS (feedback histórico):\n" . wp_json_encode( $pesos, JSON_UNESCAPED_UNICODE ) . "\n\n";
		}
		$user .= Ratings::pesos_prompt();
		$user .= "PERFIL:\n" . $perfil . "\n\nCANDIDATOS (JSON):\n" . wp_json_encode( $cands, JSON_UNESCAPED_UNICODE );

		$resp   = self::chat( $system, $user );
		$parsed = self::extract_json( $resp );
		if ( ! $parsed || ! isset( $parsed['items'] ) || ! is_array( $parsed['items'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $parsed['items'] as $itm ) {
			if ( ! is_array( $itm ) ) {
				continue;
			}
			$idx = isset( $itm['i'] ) ? (int) $itm['i'] : -1;
			if ( ! isset( $candidates[ $idx ] ) ) {
				continue;
			}
			$out[] = array(
				'candidato' => $idx,
				'tema'      => sanitize_key( (string) ( $itm['tema'] ?? $candidates[ $idx ]['tema'] ?? '' ) ),
				'resumen'   => sanitize_textarea_field( (string) ( $itm['resumen'] ?? '' ) ),
			);
		}
		return $out;
	}
}
