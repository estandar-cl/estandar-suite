<?php
/**
 * gate-constants-defined.php — Gate de constantes usadas sin definir.
 *
 * Detecta el uso de constantes (mayúsculas con guion bajo) en el código de los
 * 26 plugins que NUNCA se definen dentro de la Suite con define()/const, y que
 * tampoco están en la lista blanca de WordPress core / PHP / extensiones.
 *
 * Previene la clase de bug de acacios.cl (2026-08-16): uso de una constante
 * nunca definida (`ESTANDAR_SUITE_CAPTURE_URL`) que en PHP 8 era
 * `Undefined constant` fatal en el frontend.
 *
 * Salida de éxito: "CONSTANTS_OK usos=N sin_definir=0 (o solo whitelisted)"
 * Salida de fallo:  "CONSTANTS_FAIL ..." + listado + exit(1)
 *
 * Uso: php tests/gate-constants-defined.php [--ignore-suite-define] [--scope=plugin1,plugin2]
 */

require __DIR__ . '/gate-scope.php';
$plugins_root = $GATE_ROOT;

// Constantes de WordPress core (definidas por WP en runtime) — no por la Suite.
$WP_CORE_CONSTANTS = array(
	'ABSPATH', 'WPINC', 'WPLANG', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
	'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_CONTENT_DIR', 'WP_CONTENT_URL',
	'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WP_PLUGIN_PATH', 'WPMU_PLUGIN_DIR',
	'WPMU_PLUGIN_URL', 'WPMU_PLUGIN_PATH', 'WP_TEMP_DIR', 'WP_HOME', 'WP_SITEURL',
	'WP_ENVIRONMENT_TYPE', 'WP_VERSION', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
	'DB_HOST', 'DB_CHARSET', 'DB_COLLATE', 'TABLE_PREFIX', 'FS_CHMOD_DIR',
	'FS_CHMOD_FILE', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY',
	'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
	'WP_CACHE', 'FORCE_SSL_ADMIN', 'DISALLOW_FILE_EDIT', 'AUTOMATIC_UPDATER_DISABLED',
	'WP_AUTO_UPDATE_CORE', 'WP_CRON_LOCK_TIMEOUT', 'EMPTY_TRASH_DAYS',
	'KB_IN_BYTES', 'MB_IN_BYTES', 'GB_IN_BYTES', 'TB_IN_BYTES', 'PB_IN_BYTES',
	'EB_IN_BYTES', 'ZB_IN_BYTES', 'YB_IN_BYTES',
	'ARRAY_A', 'ARRAY_N', 'OBJECT', 'OBJECT_K',
	'HOUR_IN_SECONDS', 'DAY_IN_SECONDS', 'WEEK_IN_SECONDS', 'MONTH_IN_SECONDS',
	'YEAR_IN_SECONDS', 'MINUTE_IN_SECONDS',
	'DOING_AUTOSAVE', 'DOING_CRON', 'DOING_AJAX', 'DOING_REQUEST', 'DOING_REST_REQUEST',
	'WP_ADMIN', 'WP_NETWORK_ADMIN', 'WP_USER_ADMIN', 'SHORTINIT',
	'WP_USE_THEMES', 'WP_CLI', 'DAY_IN_SECONDS', 'MONTH_IN_SECONDS',
	'REST_API_VERSION', 'REST_REQUEST', 'EP_PERMALINK', 'EP_PAGES', 'EP_ALL',
	'EP_MASK', 'EP_COMMENTS', 'EP_CATEGORIES', 'EP_TAGS', 'EP_AUTHORS', 'EP_DATE',
	'EP_SEARCH', 'EP_HOME', 'EP_MATCH_PAGE', 'EP_MATCH_ARCHIVE', 'EP_MATCH_CATEGORY',
	'EP_MATCH_TAG', 'EP_MATCH_AUTHOR', 'EP_MATCH_DAY', 'EP_MATCH_MONTH',
	'EP_MATCH_YEAR', 'EP_MATCH_SEARCH', 'EP_MATCH_HOME', 'EP_MATCH_QUERY',
	'EP_MATCH_REQUEST', 'EP_MATCH', 'EP_COMMENTS_QUERY', 'EP_SEARCH_QUERY',
	'FULLTEXT_QUERY', 'FULLTEXT_SEARCH', 'OBJECT_K',
	'BP_THEME_COMPAT_ID', 'BP_THEME_COMPAT_URI',
	'WP_DEFAULT_THEME', 'WP_DEFAULT_EDITING_THEME',
	'MINUTE_IN_SECONDS', 'HOUR_IN_SECONDS',
	'OBJECT', 'ARRAY_A', 'ARRAY_N',
	'WEEK_IN_SECONDS', 'MONTH_IN_SECONDS', 'YEAR_IN_SECONDS',
	'DAY_IN_SECONDS', 'HOUR_IN_SECONDS', 'MINUTE_IN_SECONDS',
	'OBJECT_K',
	'FULLTEXT_SEARCH', 'FULLTEXT_QUERY',
	'STYLESHEET', 'TEMPLATEPATH', 'STYLESHEETPATH', 'WPMU_PLUGIN_URL',
	'COOKIE_DOMAIN', 'COOKIEPATH', 'SITECOOKIEPATH', 'ADMIN_COOKIE_PATH',
	'PLUGINS_COOKIE_PATH', 'COOKIEHASH', 'USER_COOKIE', 'PASS_COOKIE',
	'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE', 'TEST_COOKIE',
	'WP_POST_REVISIONS', 'WP_POST_TYPES', 'WP_POST_STATUSES',
	'EMPTY_TRASH_DAYS', 'WP_CRON_LOCK_TIMEOUT', 'WP_CRON_INTERVAL',
	'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_HTTP_BLOCK_EXTERNAL',
	'WP_ACCESSIBLE_HOSTS', 'FS_METHOD', 'FTP_HOST', 'FTP_USER', 'FTP_PASS',
	'FTP_PUBKEY', 'FTP_PRIKEY', 'FTP_SSL',
	'GPC', 'REQUEST_URI', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT',
	'HTTP_RAW_POST_DATA', 'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE',
	'PATH_CURRENT_SITE', 'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE',
	'SUNRISE', 'WP_ALLOW_MULTISITE', 'UPLOADS', 'BLOGUPLOADDIR',
	'FILESYSTEM_METHOD_TIMEOUT', 'FILESYSTEM_METHOD_TIMEOUT_SECONDS',
);

// Constantes de PHP y extensiones (runtime local = referencia del lint).
// Se incluyen TODOS los grupos (Core, filter, fileinfo, tokenizer, dom, libxml,
// json, curl, mbstring, ...) para que las constantes reales de cada extensión
// usadas con guard de disponibilidad no cuenten como "sin definir".
$PHP_RUNTIME = array();
foreach ( get_defined_constants( true ) as $grp => $cs ) {
	foreach ( array_keys( $cs ) as $c ) {
		if ( is_string( $c ) ) {
			$PHP_RUNTIME[ $c ] = true;
		}
	}
}

// Constantes de WordPress core / PHP / extensiones / comunes que NO define la Suite.
$WHITELIST = array(
	'ABSPATH', 'WPINC', 'WPLANG', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
	'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_CONTENT_DIR', 'WP_CONTENT_URL',
	'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WP_PLUGIN_PATH', 'WPMU_PLUGIN_DIR',
	'WPMU_PLUGIN_URL', 'WPMU_PLUGIN_PATH', 'WP_TEMP_DIR', 'WP_HOME', 'WP_SITEURL',
	'WP_ENVIRONMENT_TYPE', 'WP_VERSION', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
	'DB_HOST', 'DB_CHARSET', 'DB_COLLATE', 'TABLE_PREFIX', 'FS_CHMOD_DIR',
	'FS_CHMOD_FILE', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY',
	'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
	'WP_CACHE', 'FORCE_SSL_ADMIN', 'DISALLOW_FILE_EDIT', 'AUTOMATIC_UPDATER_DISABLED',
	'WP_AUTO_UPDATE_CORE', 'WP_CRON_LOCK_TIMEOUT', 'EMPTY_TRASH_DAYS',
	'KB_IN_BYTES', 'MB_IN_BYTES', 'GB_IN_BYTES', 'TB_IN_BYTES', 'PB_IN_BYTES',
	'EB_IN_BYTES', 'ZB_IN_BYTES', 'YB_IN_BYTES',
	'PHP_VERSION', 'PHP_VERSION_ID', 'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION',
	'PHP_RELEASE_VERSION', 'PHP_EXTRA_VERSION', 'PHP_INT_SIZE', 'PHP_INT_MAX',
	'PHP_INT_MIN', 'PHP_FLOAT_DIG', 'PHP_FLOAT_EPSILON', 'PHP_FLOAT_MIN',
	'PHP_FLOAT_MAX', 'PHP_EOL', 'PHP_OS', 'PHP_OS_FAMILY', 'PHP_SAPI',
	'PHP_BINARY', 'PHP_BINDIR', 'PHP_LIBDIR', 'PHP_DATADIR', 'PHP_SYSCONFDIR',
	'PHP_LOCALSTATEDIR', 'PHP_CONFIG_FILE_PATH', 'PHP_CONFIG_FILE_SCAN_DIR',
	'PHP_SHLIB_SUFFIX', 'PHP_EOL', 'DIRECTORY_SEPARATOR', 'PATH_SEPARATOR',
	'DEFAULT_INCLUDE_PATH', 'E_ALL', 'E_ERROR', 'E_WARNING', 'E_PARSE',
	'E_NOTICE', 'E_CORE_ERROR', 'E_CORE_WARNING', 'E_COMPILE_ERROR',
	'E_COMPILE_WARNING', 'E_USER_ERROR', 'E_USER_WARNING', 'E_USER_NOTICE',
	'E_DEPRECATED', 'E_USER_DEPRECATED', 'E_STRICT', 'E_RECOVERABLE_ERROR',
	'__COMPILER_HALT_OFFSET__', 'ENT_QUOTES', 'ENT_COMPAT', 'ENT_HTML401',
	'ENT_HTML5', 'ENT_NOQUOTES', 'ENT_SUBSTITUTE', 'ENT_DISALLOWED',
	'ENT_IGNORE', 'JSON_PRETTY_PRINT', 'JSON_UNESCAPED_SLASHES',
	'JSON_UNESCAPED_UNICODE', 'JSON_PRESERVE_ZERO_FRACTION',
	'JSON_NUMERIC_CHECK', 'JSON_THROW_ON_ERROR', 'JSON_OBJECT_AS_ARRAY',
	'JSON_FORCE_OBJECT', 'JSON_BIGINT_AS_STRING', 'JSON_INVALID_UTF8_IGNORE',
	'JSON_INVALID_UTF8_SUBSTITUTE', 'CURLOPT_URL', 'CURLOPT_RETURNTRANSFER',
	'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER',
	'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_TIMEOUT',
	'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_HEADER', 'CURLOPT_FOLLOWLOCATION',
	'CURLOPT_MAXREDIRS', 'CURLOPT_USERAGENT', 'CURLOPT_CUSTOMREQUEST',
	'CURLOPT_HTTPGET', 'CURLOPT_PUT', 'CURLOPT_FILE', 'CURLOPT_WRITEFUNCTION',
	'CURLOPT_CAINFO', 'CURLINFO_HTTP_CODE', 'CURLE_OK', 'CURLE_COULDNT_CONNECT',
	'CURLE_COULDNT_RESOLVE_HOST', 'CURLE_OPERATION_TIMEOUTED',
	'CURLE_SSL_CONNECT_ERROR', 'CURLE_HTTP_RETURNED_ERROR',
	'CURL_HTTP_VERSION_1_1', 'CURL_HTTP_VERSION_2_0',
	'DATE_ATOM', 'DATE_COOKIE', 'DATE_ISO8601', 'DATE_RFC822', 'DATE_RFC850',
	'DATE_RFC1036', 'DATE_RFC1123', 'DATE_RFC2822', 'DATE_RFC3339',
	'DATE_RFC3339_EXTENDED', 'DATE_RSS', 'DATE_W3C',
	'PREG_PATTERN_ORDER', 'PREG_SET_ORDER', 'PREG_OFFSET_CAPTURE',
	'PREG_UNMATCHED_AS_NULL', 'PREG_SPLIT_NO_EMPTY', 'PREG_SPLIT_DELIM_CAPTURE',
	'PREG_SPLIT_OFFSET_CAPTURE', 'PREG_SPLIT_ORDER',
	'SORT_ASC', 'SORT_DESC', 'SORT_REGULAR', 'SORT_NUMERIC', 'SORT_STRING',
	'SORT_LOCALE_STRING', 'SORT_NATURAL', 'SORT_FLAG_CASE',
	'ARRAY_FILTER_USE_KEY', 'ARRAY_FILTER_USE_BOTH',
	'FILE_USE_INCLUDE_PATH', 'FILE_IGNORE_NEW_LINES', 'FILE_SKIP_EMPTY_LINES',
	'FILE_APPEND', 'LOCK_EX', 'LOCK_SH', 'LOCK_NB', 'LOCK_UN',
	'SEEK_SET', 'SEEK_CUR', 'SEEK_END',
	'GLOB_BRACE', 'GLOB_MARK', 'GLOB_NOSORT', 'GLOB_NOCHECK', 'GLOB_ONLYDIR',
	'PATHINFO_DIRNAME', 'PATHINFO_BASENAME', 'PATHINFO_EXTENSION',
	'PATHINFO_FILENAME', 'PATHINFO_ALL',
	'INF', 'NAN', 'M_E', 'M_PI', 'M_SQRT2',
	'PHP_FD_SETSIZE', 'STDIN', 'STDOUT', 'STDERR',
	'MYSQLI_ASSOC', 'MYSQLI_NUM', 'MYSQLI_BOTH', 'MYSQLI_STORE_RESULT',
	'MYSQLI_USE_RESULT', 'MYSQLI_REPORT_OFF', 'MYSQLI_REPORT_ERROR',
	'MYSQLI_REPORT_STRICT', 'MYSQLI_REPORT_ALL',
	'PDO::ATTR_ERRMODE', 'PDO::ERRMODE_EXCEPTION',
	'PDO::PARAM_STR', 'PDO::PARAM_INT', 'PDO::PARAM_BOOL',
	'PASSWORD_DEFAULT', 'PASSWORD_BCRYPT', 'PASSWORD_ARGON2I', 'PASSWORD_ARGON2ID',
	'HTML_ENTITIES', 'ENT_QUOTES',
	'UPLOAD_ERR_OK', 'UPLOAD_ERR_INI_SIZE', 'UPLOAD_ERR_FORM_SIZE',
	'UPLOAD_ERR_PARTIAL', 'UPLOAD_ERR_NO_FILE', 'UPLOAD_ERR_NO_TMP_DIR',
	'UPLOAD_ERR_CANT_WRITE', 'UPLOAD_ERR_EXTENSION',
	'PREG_NO_ERROR', 'PREG_INTERNAL_ERROR', 'PREG_BACKTRACK_LIMIT_ERROR',
	'PREG_RECURSION_LIMIT_ERROR', 'PREG_BAD_UTF8_ERROR', 'PREG_BAD_UTF8_OFFSET_ERROR',
	'PHP_SESSION_ACTIVE', 'PHP_SESSION_DISABLED', 'PHP_SESSION_NONE',
	'FILTER_VALIDATE_EMAIL', 'FILTER_VALIDATE_URL', 'FILTER_VALIDATE_INT',
	'FILTER_VALIDATE_IP', 'FILTER_SANITIZE_STRING', 'FILTER_SANITIZE_EMAIL',
	'FILTER_SANITIZE_URL', 'FILTER_SANITIZE_NUMBER_INT', 'FILTER_SANITIZE_NUMBER_FLOAT',
	'FILTER_FLAG_IPV4', 'FILTER_FLAG_IPV6', 'FILTER_FLAG_HOST_REQUIRED',
	'FILTER_FLAG_PATH_REQUIRED', 'FILTER_FLAG_QUERY_REQUIRED',
	'FILTER_FLAG_STRIP_HIGH', 'FILTER_FLAG_STRIP_LOW', 'FILTER_FLAG_ENCODE_HIGH',
	'FILTER_FLAG_ENCODE_LOW', 'FILTER_FLAG_NONE',
	'IMG_JPEG', 'IMG_PNG', 'IMG_GIF', 'IMG_WEBP',
	'IMAGETYPE_JPEG', 'IMAGETYPE_PNG', 'IMAGETYPE_GIF', 'IMAGETYPE_WEBP',
	'OPENSSL_ALGO_SHA256', 'OPENSSL_ALGO_SHA1', 'OPENSSL_ALGO_SHA512',
	'OPENSSL_CIPHER_AES_256_CBC', 'OPENSSL_CIPHER_AES_128_CBC',
	'OPENSSL_RAW_DATA', 'OPENSSL_ZERO_PADDING', 'OPENSSL_NO_PADDING',
	'SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES', 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES',
	'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES',
	'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES',
	'MB_CASE_UPPER', 'MB_CASE_LOWER', 'MB_CASE_TITLE', 'MB_CASE_FOLD',
	'LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_DTDATTR', 'LIBXML_NONET',
	'LIBXML_NOERROR', 'LIBXML_NOWARNING', 'LIBXML_COMPACT', 'LIBXML_PARSEHUGE',
	'LIBXML_NOBLANKS', 'LIBXML_NOCDATA', 'LIBXML_XINCLUDE',
	'LIBXML_HTML_NOIMPLIED', 'LIBXML_HTML_NODEFDTD',
	'XML_USE_INTERNAL_ERRORS', 'XML_ERROR_NONE', 'XML_ERROR_SYNTAX',
	'XML_OPTION_CASE_FOLDING', 'XML_OPTION_SKIP_TAG_START', 'XML_OPTION_SKIP_TAG_END',
	'SCANDIR_SORT_ASCENDING', 'SCANDIR_SORT_DESCENDING', 'SCANDIR_SORT_NONE',
	'CRYPT_SALT_LENGTH', 'CRYPT_BLOWFISH', 'CRYPT_SHA256', 'CRYPT_SHA512',
	'LOG_EMERG', 'LOG_ALERT', 'LOG_CRIT', 'LOG_ERR', 'LOG_WARNING',
	'LOG_NOTICE', 'LOG_INFO', 'LOG_DEBUG',
	'STREAM_CLIENT_CONNECT', 'STREAM_CLIENT_PERSISTENT', 'STREAM_CLIENT_ASYNC_CONNECT',
	'STREAM_SERVER_BIND', 'STREAM_SERVER_LISTEN',
	'GZIP_ENCODING_DEFLATE', 'GZIP_ENCODING_GZIP',
	'MB_OVERLOAD_MAIL', 'PHP_ROUND_HALF_UP', 'PHP_ROUND_HALF_DOWN',
	'PHP_ROUND_HALF_EVEN', 'PHP_ROUND_HALF_ODD',
	'CONNECTION_ABORTED', 'CONNECTION_NORMAL', 'CONNECTION_TIMEOUT',
	'JSON_ERROR_NONE', 'JSON_ERROR_DEPTH', 'JSON_ERROR_STATE_MISMATCH',
	'JSON_ERROR_CTRL_CHAR', 'JSON_ERROR_SYNTAX', 'JSON_ERROR_UTF8',
	'JSON_ERROR_RECURSION', 'JSON_ERROR_INF_OR_NAN', 'JSON_ERROR_UNSUPPORTED_TYPE',
	'JSON_ERROR_INVALID_PROPERTY_NAME', 'JSON_ERROR_UTF16',
	'ENT_SUBSTITUTE', 'ENT_IGNORE',
	// Librerías externas que la Suite consume con guard de disponibilidad
	// (class_exists/defined). PclZip se empaqueta con WordPress core
	// (wp-admin/includes/class-pclzip.php) y define sus propias constantes.
	'PCLZIP_OPT_REMOVE_PATH', 'PCLZIP_OPT_ADD_PATH', 'PCLZIP_OPT_TEMP_FILE_THRESHOLD',
	'PCLZIP_OPT_TEMP_FILE_ON', 'PCLZIP_OPT_TEMP_FILE_OFF', 'PCLZIP_OPT_NO_COMPRESSION',
);

// Recolectar constantes definidas dentro de la Suite.
function gate_collect_defines( string $dir ): array {
	$defined = array();
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
			continue;
		}
		$path = $file->getPathname();
		$rel  = str_replace( '\\', '/', $path );
		if ( ! gate_in_scope( substr( $rel, strlen( rtrim( $dir, '/' ) ) + 1 ) ) ) {
			continue;
		}
		if ( preg_match( '#(^|/)(\.[^/]*/|tests?/|vendor/|node_modules/|fixtures?/|acceptance/)#i', '/' . $rel ) ) {
			continue;
		}
		// Código de terceros vendido (dompdf de S16) no pasa por gates propios.
		if ( strpos( '/' . $rel, '/lib/dompdf/' ) !== false ) {
			continue;
		}
		$src = @file_get_contents( $path );
		if ( $src === false ) {
			continue;
		}
		// define( 'NAME', ... )  o  define( "NAME", ... )
		if ( preg_match_all( '/\bdefine\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*,/', $src, $m ) ) {
			foreach ( $m[1] as $name ) {
				$defined[ $name ] = true;
			}
		}
		// const NAME = ...  (nivel global o dentro de clase: Class::CONST no cuenta)
		if ( preg_match_all( '/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $src, $m ) ) {
			foreach ( $m[1] as $name ) {
				$defined[ $name ] = true;
			}
		}
	}
	return $defined;
}

// Escanear usos de constantes con token_get_all (preciso: evita ->CONST, ::CONST, define('X')).
function gate_scan_uses( string $dir, array $defined, array $whitelist ): array {
	$issues = array();
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
			continue;
		}
		$path = $file->getPathname();
		$rel  = str_replace( '\\', '/', $path );
		if ( ! gate_in_scope( substr( $rel, strlen( rtrim( $dir, '/' ) ) + 1 ) ) ) {
			continue;
		}
		if ( preg_match( '#(^|/)(\.[^/]*/|tests?/|vendor/|node_modules/|fixtures?/|acceptance/)#i', '/' . $rel ) ) {
			continue;
		}
		if ( strpos( '/' . $rel, '/lib/dompdf/' ) !== false ) {
			continue;
		}
		$src = @file_get_contents( $path );
		if ( $src === false ) {
			continue;
		}
		// Constantes protegidas con el patrón guard defined('X') en este archivo:
		// wp-config/opciones/features pueden definirse en runtime sin que la Suite
		// las declare; el uso está protegido por defined() → no es un bug.
		$guarded = array();
		if ( preg_match_all( '/\bdefined\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)/', $src, $gm ) ) {
			foreach ( $gm[1] as $gn ) {
				$guarded[ $gn ] = true;
			}
		}
		$tokens = @token_get_all( $src );
		if ( ! is_array( $tokens ) ) {
			continue;
		}
		$n = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$tok = $tokens[ $i ];
			if ( ! is_array( $tok ) || $tok[0] !== T_STRING ) {
				continue;
			}
			$name = $tok[1];
			if ( ! preg_match( '/^[A-Z][A-Z0-9_]{3,}$/', $name ) ) {
				continue;
			}
			// ¿Está definida en la Suite?
			if ( isset( $defined[ $name ] ) ) {
				continue;
			}
			// ¿Whitelist (WP core + PHP + lista estática)?
			if ( isset( $whitelist[ $name ] ) ) {
				continue;
			}
			// ¿Guard con defined('X') en el mismo archivo? (config externa legítima)
			if ( isset( $guarded[ $name ] ) ) {
				continue;
			}
			// Excluir contextos:
			//  - ::CONST  (acceso a constante de clase)
			//  - ->CONST  (propiedad / método)
			//  - CONST::  (nombre de clase seguido de ::)
			//  - define( 'NAME'  (definición, ya cubierta)
			//  - defined( 'NAME'  (chequeo — permite existir en runtime)
			//  - constant( 'NAME'  (lectura dinámica — no sabemos)
			$prev = ( $i > 0 ) ? $tokens[ $i - 1 ] : null;
			$next = ( $i + 1 < $n ) ? $tokens[ $i + 1 ] : null;
			// Declaración class/interface/trait NAME, o instanciación new NAME →
			// es referencia a clase, no constante. Se mira hacia atrás saltando
			// espacios/comentarios (class EA_REST = T_CLASS ws T_STRING).
			$back = $i - 1;
			while ( $back >= 0 && is_array( $tokens[ $back ] ) && in_array( $tokens[ $back ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$back--;
			}
			if ( $back >= 0 && is_array( $tokens[ $back ] ) && in_array( $tokens[ $back ][0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_NEW, T_FUNCTION ), true ) ) {
				continue;
			}
			if ( is_array( $prev ) && $prev[0] === T_DOUBLE_COLON ) {
				continue;
			}
			if ( is_array( $next ) && $next[0] === T_DOUBLE_COLON ) {
				continue; // Nombre de clase antes de :: (p.ej. EA_REST::init)
			}
			if ( is_array( $prev ) && $prev[0] === T_OBJECT_OPERATOR ) {
				continue;
			}
			if ( $prev === '->' || $prev === '::' ) {
				continue;
			}
			if ( is_array( $next ) && $next[0] === T_STRING && in_array( $next[1], array( 'define', 'defined', 'constant', 'class_alias' ), true ) ) {
				continue;
			}
			// "function NAME(" — no es constante
			$issue = sprintf( '%s:%d  %s', $rel, $tok[2], $name );
			$issues[ $issue ] = true;
		}
	}
	ksort( $issues );
	return array_keys( $issues );
}

// Combinar whitelists: estática + WP core + PHP runtime (como map para O(1)).
$WHITELIST_FULL = array();
foreach ( array_merge( $WHITELIST, $WP_CORE_CONSTANTS ) as $c ) {
	$WHITELIST_FULL[ $c ] = true;
}
foreach ( array_keys( $PHP_RUNTIME ) as $c ) {
	$WHITELIST_FULL[ $c ] = true;
}

$defined = gate_collect_defines( $plugins_root );
$issues  = gate_scan_uses( $plugins_root, $defined, $WHITELIST_FULL );

if ( count( $issues ) > 0 ) {
	echo "CONSTANTS_FAIL posibles_constant_usadas_sin_definir=" . count( $issues ) . "\n";
	foreach ( array_slice( $issues, 0, 60 ) as $line ) {
		echo "  ✗ {$line}\n";
	}
	exit( 1 );
}

echo "CONSTANTS_OK usos_sin_definir=0\n";
exit( 0 );
