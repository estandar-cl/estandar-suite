<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ratings {

	const OPTION_TOKEN = 'esn_rate_token';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estandar_noticias_rating';
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_shortcode( 'esn_rate', array( __CLASS__, 'shortcode' ) );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			tema varchar(64) NOT NULL DEFAULT '',
			fuente varchar(191) NOT NULL DEFAULT '',
			item varchar(64) NOT NULL DEFAULT '',
			valor varchar(8) NOT NULL DEFAULT '',
			link text NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			ua varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY tema (tema),
			KEY fuente (fuente),
			KEY item (item)
		) {$charset};";
		dbDelta( $sql );

		if ( (string) get_option( self::OPTION_TOKEN, '' ) === '' ) {
			update_option( self::OPTION_TOKEN, wp_generate_password( 32, false ), false );
		}
		update_option( 'esn_rate_db', ESTANDAR_NOTICIAS_VERSION, false );
	}

	public static function item_key( array $it ): string {
		$base = (string) ( $it['link'] ?? '' );
		if ( $base === '' ) {
			$base = (string) ( $it['titulo'] ?? '' );
		}
		return substr( md5( $base ), 0, 32 );
	}

	private static function token_ok( string $provided ): bool {
		$expected = (string) get_option( self::OPTION_TOKEN, '' );
		if ( $expected === '' ) {
			return true;
		}
		return $provided !== '' && hash_equals( $expected, $provided );
	}

	public static function maybe_handle(): void {
		if ( ! isset( $_GET['esn_rate'] ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$valor  = strtolower( sanitize_text_field( (string) $_GET['esn_rate'] ) );
		$tema   = sanitize_text_field( (string) ( $_GET['tema'] ?? '' ) );
		$fuente = sanitize_text_field( (string) ( $_GET['fuente'] ?? '' ) );
		$item   = sanitize_text_field( (string) ( $_GET['item'] ?? '' ) );
		$link   = esc_url_raw( (string) ( $_GET['link'] ?? '' ) );
		$k      = (string) ( $_GET['k'] ?? '' );

		if ( ! self::token_ok( $k ) ) {
			status_header( 403 );
			echo '<p>Token inválido.</p>';
			exit;
		}
		if ( ! in_array( $valor, array( 'up', 'down' ), true ) ) {
			status_header( 400 );
			echo '<p>Valor inválido.</p>';
			exit;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? substr( (string) $_SERVER['REMOTE_ADDR'], 0, 63 ) : '';
		$rl = 'esn_rl_' . md5( $ip );
		$n  = (int) get_transient( $rl );
		if ( $n > 240 ) {
			status_header( 429 );
			echo '<p>Demasiados votos seguidos.</p>';
			exit;
		}
		set_transient( $rl, $n + 1, 60 );

		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql' ),
				'tema'       => $tema,
				'fuente'     => $fuente,
				'item'       => $item,
				'valor'      => $valor,
				'link'       => $link,
				'ip'         => $ip,
				'ua'         => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			)
		);

		$label = $valor === 'up' ? '▲ Más de esto' : '▼ Menos de esto';
		$back  = wp_get_referer();
		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Registrado</title>'
			. '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:48px 24px;background:#faf7f2;color:#141210;text-align:center">'
			. '<h2 style="margin:0 0 6px">' . esc_html( $label ) . '</h2>'
			. '<p style="color:#6b6560;margin:0 0 24px">' . esc_html( $tema ) . ' · ' . esc_html( $fuente ) . '</p>'
			. '<p><a style="color:#7a1f1f" href="' . esc_url( $back ? $back : home_url( '/' ) ) . '">Volver al boletín</a></p>'
			. '</body>';
		exit;
	}

	public static function shortcode( $atts ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$a     = shortcode_atts( array( 'tema' => '', 'fuente' => '', 'item' => '' ), $atts );
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		$mk    = static function ( $v ) use ( $a, $token ) {
			return esc_url(
				add_query_arg(
					array(
						'esn_rate' => $v,
						'tema'     => $a['tema'],
						'fuente'   => $a['fuente'],
						'item'     => $a['item'],
						'k'        => $token,
					),
					home_url( '/' )
				)
			);
		};
		return '<span class="esn-rate" style="white-space:nowrap;margin-left:8px">'
			. '<a class="esn-up" href="' . $mk( 'up' ) . '" title="Más de esto" style="text-decoration:none;color:#1a7f37;font-weight:700">▲</a>'
			. '<a class="esn-down" href="' . $mk( 'down' ) . '" title="Menos de esto" style="text-decoration:none;color:#7a1f1f;font-weight:700;margin-left:6px">▼</a>'
			. '</span>';
	}

	public static function rest(): void {
		register_rest_route(
			Rest::NS,
			'/ratings',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					global $wpdb;
					$table = self::table();
					return rest_ensure_response(
						$wpdb->get_results(
							"SELECT tema, fuente, SUM(valor = 'up') AS up, SUM(valor = 'down') AS down, COUNT(*) AS n FROM {$table} GROUP BY tema, fuente ORDER BY n DESC",
							ARRAY_A
						)
					);
				},
			)
		);
	}

	public static function pesos(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT tema, fuente, SUM(valor = "up") AS up, SUM(valor = "down") AS down FROM ' . self::table() . ' GROUP BY tema, fuente',
			ARRAY_A
		);
		$tema   = array();
		$fuente = array();
		foreach ( (array) $rows as $r ) {
			$w = (int) $r['up'] - (int) $r['down'];
			$t = (string) $r['tema'];
			$f = (string) $r['fuente'];
			if ( $t !== '' ) {
				$tema[ $t ]   = ( $tema[ $t ] ?? 0 ) + $w;
			}
			if ( $f !== '' ) {
				$fuente[ $f ] = ( $fuente[ $f ] ?? 0 ) + $w;
			}
		}
		return array( 'tema' => self::norm( $tema ), 'fuente' => self::norm( $fuente ) );
	}

	private static function norm( array $d ): array {
		$max = 1.0;
		foreach ( $d as $v ) {
			$max = max( $max, abs( (float) $v ) );
		}
		$out = array();
		foreach ( $d as $k => $v ) {
			$out[ (string) $k ] = round( (float) $v / $max, 3 );
		}
		arsort( $out );
		return $out;
	}

	public static function pesos_prompt(): string {
		$p     = self::pesos();
		$lineas = array();
		$pref  = array();
		$evita = array();
		foreach ( (array) $p['tema'] as $k => $v ) {
			if ( $v > 0.15 ) {
				$pref[] = $k;
			} elseif ( $v < -0.15 ) {
				$evita[] = $k;
			}
		}
		$fpref  = array();
		$fevita = array();
		foreach ( (array) $p['fuente'] as $k => $v ) {
			if ( $v > 0.15 ) {
				$fpref[] = $k;
			} elseif ( $v < -0.15 ) {
				$fevita[] = $k;
			}
		}
		if ( $pref ) {
			$lineas[] = 'Temas que prefiere: ' . implode( ', ', $pref );
		}
		if ( $evita ) {
			$lineas[] = 'Temas que evita: ' . implode( ', ', $evita );
		}
		if ( $fpref ) {
			$lineas[] = 'Fuentes que prefiere: ' . implode( ', ', $fpref );
		}
		if ( $fevita ) {
			$lineas[] = 'Fuentes que evita: ' . implode( ', ', $fevita );
		}
		if ( ! $lineas ) {
			return '';
		}
		return "PREFERENCIAS APRENDIDAS DE SUS VOTOS (pondera a favor/en contra):\n" . implode( "\n", $lineas ) . "\n\n";
	}
}
