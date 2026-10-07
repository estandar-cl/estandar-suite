<?php
/**
 * URL safety helpers to mitigate SSRF-like issues.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate and restrict outbound URLs.
 *
 * This plugin receives URLs from admins and uses wp_remote_* and DOMPDF.
 * We must prevent connections to private/loopback/link-local addresses.
 */
class EWS_Url_Safety {

	/**
	 * Cache (host => bool).
	 *
	 * @var array<string, bool>
	 */
	private static array $host_safety_cache = array();

	/**
	 * Cache (url => bool).
	 *
	 * @var array<string, bool>
	 */
	private static array $url_safety_cache = array();

	/**
	 * Check if a URL is safe to fetch.
	 *
	 * @param string $url URL provided by the user (or derived).
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}

		if ( isset( self::$url_safety_cache[ $url ] ) ) {
			return self::$url_safety_cache[ $url ];
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			self::$url_safety_cache[ $url ] = false;
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			self::$url_safety_cache[ $url ] = false;
			return false;
		}

		// Block credentials in URL like http://user:pass@host/...
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			self::$url_safety_cache[ $url ] = false;
			return false;
		}

		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host ) {
			self::$url_safety_cache[ $url ] = false;
			return false;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( 0 === $port ) {
			$port = ( 'https' === $scheme ) ? 443 : 80;
		}

		// Restrict to common web ports to reduce SSRF blast radius.
		if ( ! in_array( $port, array( 80, 443 ), true ) ) {
			self::$url_safety_cache[ $url ] = false;
			return false;
		}

		$unsafe = ! self::is_safe_host( $host );
		self::$url_safety_cache[ $url ] = ! $unsafe;
		return ! $unsafe;
	}

	/**
	 * Assert the URL is safe (throw on failure).
	 *
	 * @param string $url URL to validate.
	 * @param string $context Human-readable context for error messages.
	 * @throws RuntimeException
	 * @return void
	 */
	public static function assert_safe_url( string $url, string $context = '' ): void {
		$url = trim( (string) $url );
		if ( '' === $url || ! self::is_safe_url( $url ) ) {
			$msg = 'Unsafe URL';
			if ( '' !== trim( $context ) ) {
				$msg .= ' (' . (string) $context . ')';
			}
			throw new RuntimeException( $msg );
		}
	}

	/**
	 * Check if hostname resolves to safe/public IPs only.
	 *
	 * @param string $host Hostname or IP literal.
	 * @return bool
	 */
	private static function is_safe_host( string $host ): bool {
		$host = trim( (string) $host );
		if ( '' === $host ) {
			return false;
		}

		$host_norm = strtolower( $host );
		if ( isset( self::$host_safety_cache[ $host_norm ] ) ) {
			return self::$host_safety_cache[ $host_norm ];
		}

		// Common localnames.
		if ( in_array( $host_norm, array( 'localhost', 'localdomain', 'local' ), true ) ) {
			self::$host_safety_cache[ $host_norm ] = false;
			return false;
		}

		// Block hosts ending with ".local" (mDNS).
		if ( str_ends_with( $host_norm, '.local' ) ) {
			self::$host_safety_cache[ $host_norm ] = false;
			return false;
		}

		// IPv4 / IPv6 literal handling.
		$is_ip = filter_var( $host_norm, FILTER_VALIDATE_IP );
		if ( false !== $is_ip ) {
			$safe = ! self::is_private_ip( $is_ip );
			self::$host_safety_cache[ $host_norm ] = $safe;
			return $safe;
		}

		// Resolve host and ensure all A/AAAA results are public.
		$ips = array();
		// Prefer dns_get_record for both A and AAAA.
		$records = dns_get_record( $host_norm, DNS_A + DNS_AAAA );
		if ( is_array( $records ) ) {
			foreach ( $records as $rec ) {
				if ( ! empty( $rec['ip'] ) ) {
					$ips[] = (string) $rec['ip'];
				} elseif ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = (string) $rec['ipv6'];
				} elseif ( ! empty( $rec['ipv4'] ) ) {
					$ips[] = (string) $rec['ipv4'];
				}
			}
		}

		// Fallback: A record.
		if ( empty( $ips ) ) {
			$a = gethostbynamel( $host_norm );
			if ( is_array( $a ) ) {
				$ips = array_values( array_filter( array_map( 'trim', $a ) ) );
			}
		}

		// Fail closed if we can't resolve.
		if ( empty( $ips ) ) {
			self::$host_safety_cache[ $host_norm ] = false;
			return false;
		}

		$safe = true;
		foreach ( $ips as $ip ) {
			$ip = trim( (string) $ip );
			if ( '' === $ip ) {
				continue;
			}
			if ( self::is_private_ip( $ip ) ) {
				$safe = false;
				break;
			}
		}

		self::$host_safety_cache[ $host_norm ] = $safe;
		return $safe;
	}

	/**
	 * Determine whether an IP is private/loopback/link-local/reserved.
	 *
	 * @param string $ip IP literal.
	 * @return bool
	 */
	private static function is_private_ip( string $ip ): bool {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return true;
		}

		// IPv4 ranges.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = (int) ip2long( $ip );
			if ( false === $long ) {
				return true;
			}

			$check = static function ( string $range, int $mask_bits ) use ( $long ): bool {
				$range_long = (int) ip2long( $range );
				if ( false === $range_long ) {
					return false;
				}
				$mask = ( -1 << ( 32 - $mask_bits ) );
				return ( ( $long & $mask ) === ( $range_long & $mask ) );
			};

			return $check( '127.0.0.0', 8 ) // loopback
				|| $check( '10.0.0.0', 8 ) // private
				|| $check( '172.16.0.0', 12 ) // private
				|| $check( '192.168.0.0', 16 ) // private
				|| $check( '169.254.0.0', 16 ) // link-local
				|| $check( '0.0.0.0', 8 ) // "this network"
				|| $check( '100.64.0.0', 10 ) // CGNAT
				|| $check( '192.0.2.0', 24 ) // TEST-NET-1
				|| $check( '198.51.100.0', 24 ) // TEST-NET-2
				|| $check( '203.0.113.0', 24 ); // TEST-NET-3
		}

		// IPv6 ranges.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = @inet_pton( $ip );
			if ( false === $bin ) {
				return true;
			}
			$bytes = array_values( array_map( 'ord', str_split( (string) $bin ) ) );

			// ::1 (loopback)
			if ( $bytes[0] === 0 && $bytes[1] === 0 && $bytes[2] === 0 && $bytes[3] === 0 && $bytes[4] === 0 && $bytes[5] === 0 && $bytes[6] === 0 && $bytes[7] === 0 && $bytes[8] === 0 && $bytes[9] === 0 && $bytes[10] === 0 && $bytes[11] === 0 && $bytes[12] === 0 && $bytes[13] === 0 && $bytes[14] === 0 && $bytes[15] === 1 ) {
				return true;
			}

			// fc00::/7 (ULA)
			if ( ( $bytes[0] & 0xFE ) === 0xFC ) {
				return true;
			}

			// fe80::/10 (link-local)
			if ( ( $bytes[0] === 0xFE ) && ( ( $bytes[1] & 0xC0 ) === 0x80 ) ) {
				return true;
			}

			// fec0::/10 (site-local)
			if ( ( $bytes[0] === 0xFE ) && ( ( $bytes[1] & 0xC0 ) === 0xC0 ) ) {
				return true;
			}

			// ff00::/8 (multicast)
			if ( $bytes[0] === 0xFF ) {
				return true;
			}

			// ::/128 (unspecified)
			$all_zero = true;
			foreach ( $bytes as $b ) {
				if ( 0 !== $b ) {
					$all_zero = false;
					break;
				}
			}
			if ( $all_zero ) {
				return true;
			}

			return false;
		}

		return true;
	}
}

