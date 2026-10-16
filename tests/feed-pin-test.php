<?php
/**
 * Harness di regressione per il fetch dei feed (SimplePie) e il pin DNS.
 *
 * Il transport reale di fetch_feed() è sostituito da uno stub che riproduce la
 * catena di WordPress (WP_SimplePie_File → wp_remote_request → transport cURL →
 * 'http_api_curl') applicando i filtri registrati dal plugin. Verifica quindi
 * che, PRIMA della richiesta interna:
 *   1. il plugin abbia armato il pin anti-rebinding (filtro http_api_curl);
 *   2. il pin venga accettato dal libcurl vero (curl_setopt su un handle reale,
 *      senza warning: la stringa "host:porta:ip" è quella che libcurl vuole);
 *   3. 'redirection' e 'limit_response_size' siano forzati e il timeout esplicito;
 *   4. host interno (169.254.169.254) e host non risolvibile restino fail-closed:
 *      nessun fetch e nessun pin armato.
 *
 * Uso:  php tests/feed-pin-test.php        (exit code 0 = tutti i casi verdi)
 *
 * Nota: richiede l'estensione curl (il pin è attivo solo con CURLOPT_RESOLVE) e
 * risolve davvero il DNS dell'host pubblico usato come caso lecito (example.com).
 *
 * @package Nexus_News_AI
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../includes/class-nexus-tools.php';
require_once __DIR__ . '/../includes/class-nexus-rss.php';

/* ============ STUB DI SIMPLEPIE (fetch_feed) ============ */

$GLOBALS['nx_feed_calls'] = array(); // cosa è stato osservato alla chiamata di fetch_feed.

class NX_Fake_Item {
	public function get_title() {
		return 'Titolo del feed';
	}
	public function get_permalink() {
		return 'https://example.com/post/1';
	}
	public function get_description() {
		return 'Riassunto   con   spazi';
	}
	public function get_date( $format = '' ) {
		return '01/01/2030 10:00';
	}
}

class NX_Fake_Feed {
	public function get_items( $start = 0, $count = 0 ) {
		return array( new NX_Fake_Item() );
	}
}

/**
 * Riproduce la meccanica dei filtri applicati in WordPress alla richiesta
 * interna di SimplePie, senza fare rete.
 */
function fetch_feed( $url ) {
	// 1) Args della richiesta interna (dopo il merge dei default di WP_Http).
	$args = array( 'redirection' => 5, 'timeout' => 5 );
	if ( nx_filters_for( 'http_request_args' ) ) {
		$args = nx_apply_filters_args( 'http_request_args', array( $args ) );
	}

	// 2) Timeout e conteggio redirect (filtri dedicati di WP).
	$timeout = nx_filters_for( 'http_request_timeout' )
		? nx_apply_filters_args( 'http_request_timeout', array( 5 ) )
		: 5;
	$redirs  = nx_filters_for( 'http_request_redirection_count' )
		? nx_apply_filters_args( 'http_request_redirection_count', array( 5 ) )
		: 5;

	// 3) Hook del transport cURL: qui il plugin fissa CURLOPT_RESOLVE. L'handle è
	// un handle cURL VERO, così si verifica che libcurl accetti il pin.
	$armed     = ! empty( nx_filters_for( 'http_api_curl' ) );
	$handle    = null;
	$returned  = null;
	$warnings  = array();

	if ( $armed && function_exists( 'curl_init' ) ) {
		$handle = curl_init( 'https://' . wp_parse_url( (string) $url, PHP_URL_HOST ) . '/' );
		$warnings = nx_capture_warnings(
			function () use ( &$returned, $handle, $args, $url ) {
				$returned = nx_apply_filters_args( 'http_api_curl', array( $handle, $args, (string) $url ) );
			}
		);
		if ( $handle ) {
			curl_close( $handle );
		}
	}

	$GLOBALS['nx_feed_calls'][] = array(
		'url'      => (string) $url,
		'args'     => $args,
		'timeout'  => $timeout,
		'redirs'   => $redirs,
		'pin_armed' => $armed,
		'returned_handle' => ( null !== $handle && $returned === $handle ),
		'warnings' => $warnings,
	);

	return new NX_Fake_Feed();
}

/* ============ HELPER ============ */

function nx_reset_feed() {
	nx_reset();
	$GLOBALS['nx_feed_calls'] = array();
}

/**
 * Messaggi di warning che citano CURLOPT_RESOLVE (libcurl rifiuta un pin
 * malformato con un warning dal transport).
 */
function nx_pin_warnings( $calls ) {
	$out = array();
	foreach ( $calls as $call ) {
		foreach ( $call['warnings'] as $warning ) {
			if ( nx_body_has( $warning, 'RESOLVE' ) || nx_body_has( $warning, 'curl' ) ) {
				$out[] = $warning;
			}
		}
	}
	return $out;
}

$public_feed = 'https://example.com/feed/';

echo "0) precondizioni dell'ambiente di test\n";
nx_ok( function_exists( 'curl_init' ), 'estensione curl disponibile' );
nx_ok( defined( 'CURLOPT_RESOLVE' ), 'CURLOPT_RESOLVE definita (pin attivo)' );

echo "1) read_feed(): pin armato prima della richiesta interna e accettato da libcurl\n";
nx_reset_feed();
$items = Nexus_Tools::read_feed( $public_feed, 5 );
nx_ok( 1 === count( $items ), 'un item letto (ricevuti ' . count( $items ) . ')' );
nx_ok( isset( $items[0]['title'] ) && 'Titolo del feed' === $items[0]['title'], 'titolo parsato' );
nx_ok( isset( $items[0]['link'] ) && 'https://example.com/post/1' === $items[0]['link'], 'link parsato' );
nx_ok( 1 === count( $GLOBALS['nx_feed_calls'] ), 'fetch_feed chiamato una volta (ricevute ' . count( $GLOBALS['nx_feed_calls'] ) . ')' );

if ( 1 === count( $GLOBALS['nx_feed_calls'] ) ) {
	$call = $GLOBALS['nx_feed_calls'][0];
	nx_ok( $call['pin_armed'], 'hook http_api_curl armato prima della richiesta' );
	nx_ok( $call['returned_handle'], 'il filtro ritorna l\'handle (pin applicato sulla richiesta giusta)' );
	nx_ok( array() === nx_pin_warnings( $GLOBALS['nx_feed_calls'] ), 'libcurl accetta il pin senza warning' );
	nx_ok( 0 === (int) $call['args']['redirection'], 'redirection = 0 (ricevuto ' . $call['args']['redirection'] . ')' );
	nx_ok( 0 === (int) $call['redirs'], 'http_request_redirection_count forzato a 0 (ricevuto ' . $call['redirs'] . ')' );
	nx_ok( ! empty( $call['args']['limit_response_size'] ), 'limit_response_size presente (' . $call['args']['limit_response_size'] . ')' );
	nx_ok( is_numeric( $call['timeout'] ) && (int) $call['timeout'] >= 5, 'timeout esplicito dal plugin (ricevuto ' . var_export( $call['timeout'], true ) . ')' );
}

echo "2) read_feed(): host interno → nessun fetch e nessun pin armato\n";
nx_reset_feed();
$items = Nexus_Tools::read_feed( 'http://169.254.169.254/feed/', 5 );
nx_ok( array() === $items, 'feed interno: array() vuoto' );
nx_ok( 0 === count( $GLOBALS['nx_feed_calls'] ), 'nessuna richiesta verso l\'host interno' );

echo "3) read_feed(): host non risolvibile → nessun fetch\n";
nx_reset_feed();
$items = Nexus_Tools::read_feed( 'https://host-che-non-esiste.invalid/feed/', 5 );
nx_ok( array() === $items, 'host non risolvibile: array() vuoto' );
nx_ok( 0 === count( $GLOBALS['nx_feed_calls'] ), 'nessun fetch verso host non risolvibile' );

echo "4) Nexus_Rss::fetch_feed_items(): stesso pin, item con 'source'\n";
nx_reset_feed();
$rows = Nexus_Rss::fetch_feed_items( $public_feed, 3 );
nx_ok( 1 === count( $rows ), 'un item (ricevuti ' . count( $rows ) . ')' );
nx_ok( isset( $rows[0]['link'] ) && 'https://example.com/post/1' === $rows[0]['link'], 'link dell\'item' );
nx_ok( isset( $rows[0]['source'] ), 'chiave source presente' );
nx_ok( 1 === count( $GLOBALS['nx_feed_calls'] ), 'fetch_feed chiamato una volta' );
if ( 1 === count( $GLOBALS['nx_feed_calls'] ) ) {
	nx_ok( $GLOBALS['nx_feed_calls'][0]['pin_armed'], 'pin armato anche da fetch_feed_items' );
	nx_ok( $GLOBALS['nx_feed_calls'][0]['returned_handle'], 'pin applicato all\'handle della richiesta' );
}

echo "5) Nexus_Rss::fetch_feed_items(): host interno → nessun fetch\n";
nx_reset_feed();
$rows = Nexus_Rss::fetch_feed_items( 'http://10.0.0.5/feed/', 3 );
nx_ok( array() === $rows, 'host interno: array() vuoto' );
nx_ok( 0 === count( $GLOBALS['nx_feed_calls'] ), 'nessun fetch verso 10.0.0.5' );

nx_finish( 'feed pin' );
