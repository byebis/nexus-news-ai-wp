<?php
/**
 * Harness di regressione standalone per Nexus_Tools::guarded_get().
 *
 * Esegue il codice REALE del plugin (includes/class-nexus-tools.php) con un
 * trasporto HTTP scriptato al posto di WordPress: nessuna rete, nessuna
 * installazione WP, nessuna dipendenza esterna. I casi coprono il contratto
 * dell'egress hardening:
 *   - i 30x vengono seguiti solo dopo la ri-validazione dell'hop successivo;
 *   - un redirect verso IP interno/metadata cloud NON viene MAI richiesto;
 *   - URL non http(s) e host interni sono rifiutati prima della richiesta;
 *   - hop esauriti e status non-2xx degradano in errore onesto;
 *   - ogni richiesta esce con 'redirection' => 0 e cap sul body.
 *
 * Uso:  php tests/guarded-get-test.php     (exit code 0 = tutti i casi verdi)
 *
 * Nota: assert_safe_url risolve davvero il DNS dell'host pubblico usato come
 * caso "lecito" (example.com). L'host interno è un IP letterale, quindi i casi
 * di blocco non dipendono dal DNS.
 *
 * @package Nexus_News_AI
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'NEXUS_VERSION', '1.1.18.1' );

/* ============ STUB MINIMI DI WORDPRESS ============ */

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

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

/**
 * Mimic ridotto di wp_http_validate_url: solo http/https con host valido.
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

function apply_filters( $tag, $value ) {
	return $value;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['nx_filters'][] = array( $tag, $priority );
	return true;
}

function remove_filter( $tag, $callback, $priority = 10 ) {
	return true;
}

function get_option( $name, $default = false ) {
	return $default;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/* ============ TRASPORTO HTTP SCRIPTATO ============ */

$GLOBALS['nx_script']   = array(); // url => array( 'code', 'headers', 'body' ).
$GLOBALS['nx_requests'] = array(); // log delle richieste realmente eseguite.

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
		if ( strtolower( (string) wp_parse_url( $req['url'], PHP_URL_HOST ) ) === strtolower( $host ) ) {
			$hits[] = $req;
		}
	}
	return $hits;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['nx_requests'][] = array(
		'url'  => (string) $url,
		'args' => is_array( $args ) ? $args : array(),
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

/* ============ CARICAMENTO DEL CODICE REALE ============ */

require_once __DIR__ . '/../includes/class-nexus-tools.php';

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

function nx_before_each() {
	$GLOBALS['nx_script']   = array();
	$GLOBALS['nx_requests'] = array();
}

function nx_body_has( $haystack, $needle ) {
	return false !== strpos( (string) $haystack, (string) $needle );
}

/* ============ CASI ============ */

$public = 'https://example.com/';

echo "1) segue un 302 verso un host lecito, con 'redirection' => 0 su ogni hop\n";
nx_before_each();
nx_script( $public . 'a', 302, '', array( 'location' => $public . 'b' ) );
nx_script( $public . 'b', 200, 'contenuto pagina' );
$res = Nexus_Tools::guarded_get( $public . 'a', array( 'timeout' => 15, 'limit_response_size' => 512000 ) );
nx_ok( ! empty( $res['ok'] ), 'ok = true' );
nx_ok( 200 === $res['code'], 'code = 200 (ricevuto ' . $res['code'] . ')' );
nx_ok( 'contenuto pagina' === $res['body'], 'body dell\'hop finale' );
nx_ok( $public . 'b' === $res['url'], 'url finale = hop validato' );
nx_ok( 2 === count( $GLOBALS['nx_requests'] ), 'esattamente 2 richieste (ricevute ' . count( $GLOBALS['nx_requests'] ) . ')' );
$ok_flags = true;
$caps     = true;
foreach ( $GLOBALS['nx_requests'] as $req ) {
	if ( ! isset( $req['args']['redirection'] ) || 0 !== (int) $req['args']['redirection'] ) {
		$ok_flags = false;
	}
	if ( empty( $req['args']['limit_response_size'] ) || 512000 !== (int) $req['args']['limit_response_size'] ) {
		$caps = false;
	}
}
nx_ok( $ok_flags, 'ogni richiesta ha redirection = 0' );
nx_ok( $caps, 'ogni richiesta rispetta limit_response_size richiesto' );

echo "2) risolve una Location relativa (rooted) e la valida\n";
nx_before_each();
nx_script( $public . 'rel', 301, '', array( 'Location' => '/c' ) );
nx_script( $public . 'c', 200, 'pagina c' );
$res = Nexus_Tools::guarded_get( $public . 'rel' );
nx_ok( ! empty( $res['ok'] ), 'ok = true' );
nx_ok( $public . 'c' === $res['url'], 'url finale risolta = https://example.com/c (ricevuto ' . $res['url'] . ')' );
nx_ok( 'pagina c' === $res['body'], 'body dalla Location relativa' );

echo "3) NON segue un redirect verso i metadata cloud (169.254.169.254)\n";
nx_before_each();
nx_script( $public . 'evil', 302, '', array( 'location' => 'http://169.254.169.254/latest/meta-data/' ) );
nx_script( 'http://169.254.169.254/latest/meta-data/', 200, 'SEGRETO-DEI-METADATA' );
$res = Nexus_Tools::guarded_get( $public . 'evil' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'non consentito' ), 'errore di URL non consentito (ricevuto: ' . $res['error'] . ')' );
nx_ok( ! nx_body_has( $res['body'], 'SEGRETO-DEI-METADATA' ), 'nessun contenuto del target interno nel body' );
nx_ok( 0 === count( nx_requests_for_host( '169.254.169.254' ) ), 'nessuna richiesta verso 169.254.169.254' );
nx_ok( 1 === count( $GLOBALS['nx_requests'] ), 'una sola richiesta (quella lecita)' );

echo "4) rifiuta un target interno diretto (192.168.1.10)\n";
nx_before_each();
nx_script( 'http://192.168.1.10/admin', 200, 'pannello interno' );
$res = Nexus_Tools::guarded_get( 'http://192.168.1.10/admin' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 0 === count( $GLOBALS['nx_requests'] ), 'nessuna richiesta partita' );

echo "5) hop esauriti: errore onesto, nessun loop infinito\n";
nx_before_each();
nx_script( $public . 'loop', 302, '', array( 'location' => $public . 'loop' ) );
$res = Nexus_Tools::guarded_get( $public . 'loop' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'troppe volte' ), 'errore di troppi redirect (ricevuto: ' . $res['error'] . ')' );
nx_ok( 3 === count( $GLOBALS['nx_requests'] ), 'richieste = 1 + 2 hop (ricevute ' . count( $GLOBALS['nx_requests'] ) . ')' );

echo "6) status non-2xx: errore con il codice, niente body\n";
nx_before_each();
nx_script( $public . 'missing', 404, 'not found' );
$res = Nexus_Tools::guarded_get( $public . 'missing' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 404 === $res['code'], 'code = 404' );
nx_ok( nx_body_has( $res['error'], '404' ), 'errore cita il codice HTTP' );
nx_ok( '' === $res['body'], 'body vuoto sul percorso di errore' );

echo "7) schema non http(s): rifiutato prima di qualunque richiesta\n";
nx_before_each();
$res = Nexus_Tools::guarded_get( 'ftp://example.com/file.txt' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 0 === count( $GLOBALS['nx_requests'] ), 'nessuna richiesta partita' );

echo "8) redirect senza Location: errore, nessuna richiesta successiva\n";
nx_before_each();
nx_script( $public . 'noloc', 302, '', array() );
$res = Nexus_Tools::guarded_get( $public . 'noloc' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'redirect non valido' ), 'errore di redirect non valido' );
nx_ok( 1 === count( $GLOBALS['nx_requests'] ), 'una sola richiesta' );

/* ============ ESITO ============ */

$failures = $GLOBALS['nx_failures'];
echo "\n" . ( $GLOBALS['nx_checks'] - count( $failures ) ) . '/' . $GLOBALS['nx_checks'] . " asserzioni verdi\n";
if ( $failures ) {
	echo "FALLITI:\n";
	foreach ( $failures as $label ) {
		echo "  - $label\n";
	}
	exit( 1 );
}
echo "OK guarded_get\n";
exit( 0 );
