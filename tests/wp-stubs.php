<?php
/**
 * Stub minimi e deterministici di WordPress per gli harness in tests/.
 *
 * Gli harness caricano i file REALI del plugin (includes/class-nexus-*.php) e
 * sostituiscono soltanto il perimetro di WordPress: nessuna installazione WP,
 * nessuna rete, nessuna dipendenza esterna. Il trasporto HTTP è scriptato
 * (nx_script) e i filtri sono registrati per davvero, così un test può far
 * scattare a mano gli hook che in WordPress scatta il transport (es.
 * http_api_curl, http_request_args).
 *
 * @package Nexus_News_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'NEXUS_VERSION' ) ) {
	define( 'NEXUS_VERSION', '1.1.18.1' );
}
// NB: CURLOPT_RESOLVE NON viene definita qui. Il pin anti-rebinding è attivo
// solo con l'estensione curl (il plugin testa defined( 'CURLOPT_RESOLVE' )): gli
// harness verificano che il pin sia armato e che libcurl accetti la stringa
// "host:porta:ip", quindi richiedono l'estensione curl nel PHP dei test.

/* ============ ERRORI ============ */

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* ============ URL ============ */

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

/**
 * Mimic ridotto di wp_http_validate_url: solo http/https, host presente,
 * porte ammesse.
 */
function wp_http_validate_url( $url ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	if ( '' === (string) $parts['host'] ) {
		return false;
	}
	if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443, 8080, 8443 ), true ) ) {
		return false;
	}
	return (string) $url;
}

/* ============ FILTRI ============ */

$GLOBALS['nx_filters'] = array();

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['nx_filters'][ (string) $tag ][] = array( $callback, (int) $priority, (int) $accepted_args );
	return true;
}

function remove_filter( $tag, $callback, $priority = 10 ) {
	$tag = (string) $tag;
	if ( empty( $GLOBALS['nx_filters'][ $tag ] ) ) {
		return false;
	}
	$kept    = array();
	$removed = false;
	foreach ( $GLOBALS['nx_filters'][ $tag ] as $entry ) {
		if ( $entry[0] === $callback && (int) $entry[1] === (int) $priority ) {
			$removed = true;
			continue;
		}
		$kept[] = $entry;
	}
	$GLOBALS['nx_filters'][ $tag ] = $kept;
	return $removed;
}

/**
 * Applica i callback registrati passando $args (helper condiviso).
 */
function nx_apply_filters_args( $tag, array $args ) {
	$tag = (string) $tag;
	if ( empty( $GLOBALS['nx_filters'][ $tag ] ) ) {
		return isset( $args[0] ) ? $args[0] : null;
	}

	$entries = $GLOBALS['nx_filters'][ $tag ];
	usort(
		$entries,
		function ( $a, $b ) {
			return $a[1] <=> $b[1];
		}
	);

	$value = isset( $args[0] ) ? $args[0] : null;
	foreach ( $entries as $entry ) {
		$count = (int) $entry[2] > 0 ? (int) $entry[2] : 1;
		$value = call_user_func_array( $entry[0], array_slice( $args, 0, $count ) );
	}
	return $value;
}

function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	return nx_apply_filters_args( $tag, $args );
}

function nx_filters_for( $tag ) {
	return isset( $GLOBALS['nx_filters'][ (string) $tag ] ) ? $GLOBALS['nx_filters'][ (string) $tag ] : array();
}

/* ============ OPZIONI E URL DI SITO ============ */

function get_option( $name, $default = false ) {
	return $default;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/* ============ SANIFICAZIONE ============ */

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

/* ============ TRASPORTO HTTP SCRIPTATO ============ */

$GLOBALS['nx_script']     = array(); // url => array( 'code', 'headers', 'body' ).
$GLOBALS['nx_requests']   = array(); // log delle richieste realmente eseguite.

function nx_script( $url, $code, $body = '', $headers = array() ) {
	$GLOBALS['nx_script'][ (string) $url ] = array(
		'code'    => (int) $code,
		'headers' => $headers,
		'body'    => (string) $body,
	);
}

function nx_requests_for_host( $host ) {
	$hits = array();
	foreach ( $GLOBALS['nx_requests'] as $req ) {
		if ( strtolower( (string) wp_parse_url( $req['url'], PHP_URL_HOST ) ) === strtolower( (string) $host ) ) {
			$hits[] = $req;
		}
	}
	return $hits;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['nx_requests'][] = array(
		'url'              => (string) $url,
		'args'             => is_array( $args ) ? $args : array(),
		// True se al momento della richiesta il plugin aveva armato il pin
		// anti-rebinding (filtro http_api_curl registrato da pin_http_resolve).
		'curl_hook_armed'  => ! empty( $GLOBALS['nx_filters']['http_api_curl'] ),
	);

	if ( ! isset( $GLOBALS['nx_script'][ (string) $url ] ) ) {
		return new WP_Error( 'http_request_failed', 'stub: URL non scriptato ' . $url );
	}

	$stub = $GLOBALS['nx_script'][ (string) $url ];
	return array(
		'response' => array( 'code' => (int) $stub['code'] ),
		'headers'  => (array) $stub['headers'],
		'body'     => (string) $stub['body'],
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_header( $response, $name ) {
	$headers = isset( $response['headers'] ) ? (array) $response['headers'] : array();
	foreach ( $headers as $key => $value ) {
		if ( strtolower( (string) $key ) === strtolower( (string) $name ) ) {
			return $value;
		}
	}
	return '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

/* ============ MINI FRAMEWORK DI ASSERZIONE ============ */

$GLOBALS['nx_failures'] = array();
$GLOBALS['nx_checks']   = 0;

function nx_ok( $condition, $label ) {
	$GLOBALS['nx_checks']++;
	if ( $condition ) {
		echo "  OK   $label\n";
		return true;
	}
	$GLOBALS['nx_failures'][] = $label;
	echo "  FAIL $label\n";
	return false;
}

function nx_reset() {
	$GLOBALS['nx_script']    = array();
	$GLOBALS['nx_requests']  = array();
	$GLOBALS['nx_filters']   = array();
}

function nx_body_has( $haystack, $needle ) {
	return false !== strpos( (string) $haystack, (string) $needle );
}

/**
 * Esegue $fn catturando i warning PHP emessi (senza sopprimerli).
 *
 * @param callable $fn Funzione da eseguire.
 * @return array Array di messaggi di warning.
 */
function nx_capture_warnings( $fn ) {
	$seen = array();
	set_error_handler(
		function ( $errno, $errstr ) use ( &$seen ) {
			$seen[] = (string) $errstr;
			return true;
		}
	);
	try {
		$fn();
	} finally {
		restore_error_handler();
	}
	return $seen;
}

/**
 * True se l'IP è privato/riservato (stessa idea della SSRF guard, qui solo per
 * asserire che il pin non punti a un indirizzo interno).
 */
function nx_is_private_ip( $ip ) {
	$ip = (string) $ip;
	if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return true;
	}
	return 1 === preg_match(
		'/^(0\.|10\.|127\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|100\.(6[4-9]|[7-9][0-9]|1[0-2][0-9])\.|198\.18\.|198\.19\.|22[4-9]\.|23[0-9]\.|24[0-9]\.|25[0-5]\.)|^(::1$|::$)|^f[cd][0-9a-f]{2}:|^fe[89ab][0-9a-f]:/i',
		$ip
	);
}

function nx_finish( $suite ) {
	$failures = $GLOBALS['nx_failures'];
	$total    = $GLOBALS['nx_checks'];
	echo "\n" . ( $total - count( $failures ) ) . "/$total asserzioni verdi\n";
	if ( $failures ) {
		echo "FALLITI:\n";
		foreach ( $failures as $label ) {
			echo "  - $label\n";
		}
		exit( 1 );
	}
	echo "OK $suite\n";
	exit( 0 );
}
