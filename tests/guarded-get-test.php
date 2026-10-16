<?php
/**
 * Harness di regressione per Nexus_Tools::guarded_get().
 *
 * Esegue il codice REALE del plugin con un trasporto HTTP scriptato (vedi
 * tests/wp-stubs.php): nessuna rete, nessuna installazione WP. I casi coprono il
 * contratto dell'egress hardening:
 *   - i 30x vengono seguiti solo dopo la ri-validazione dell'hop successivo;
 *   - un redirect verso IP interno/metadata cloud NON viene MAI richiesto;
 *   - URL non http(s) e host interni sono rifiutati prima della richiesta;
 *   - hop esauriti e status non-2xx degradano in errore onesto;
 *   - ogni richiesta esce con 'redirection' => 0 e cap sul body.
 *
 * Uso:  php tests/guarded-get-test.php     (exit code 0 = tutti i casi verdi)
 *
 * Nota: la guardia risolve davvero il DNS dell'host pubblico usato come caso
 * lecito (example.com). I casi di blocco usano IP letterali, quindi non
 * dipendono dal DNS.
 *
 * @package Nexus_News_AI
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../includes/class-nexus-tools.php';

/* ============ CASI ============ */

$public = 'https://example.com/';

echo "1) segue un 302 verso un host lecito, con 'redirection' => 0 su ogni hop\n";
nx_reset();
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
$pinned   = true;
foreach ( $GLOBALS['nx_requests'] as $req ) {
	if ( ! isset( $req['args']['redirection'] ) || 0 !== (int) $req['args']['redirection'] ) {
		$ok_flags = false;
	}
	if ( empty( $req['args']['limit_response_size'] ) || 512000 !== (int) $req['args']['limit_response_size'] ) {
		$caps = false;
	}
	if ( empty( $req['curl_hook_armed'] ) ) {
		$pinned = false;
	}
}
nx_ok( $ok_flags, 'ogni richiesta ha redirection = 0' );
nx_ok( $caps, 'ogni richiesta rispetta limit_response_size richiesto' );
nx_ok( $pinned, 'ogni hop arma il pin anti-rebinding prima della richiesta' );

echo "2) risolve una Location relativa (rooted) e la valida\n";
nx_reset();
nx_script( $public . 'rel', 301, '', array( 'Location' => '/c' ) );
nx_script( $public . 'c', 200, 'pagina c' );
$res = Nexus_Tools::guarded_get( $public . 'rel' );
nx_ok( ! empty( $res['ok'] ), 'ok = true' );
nx_ok( $public . 'c' === $res['url'], 'url finale risolta = https://example.com/c (ricevuto ' . $res['url'] . ')' );
nx_ok( 'pagina c' === $res['body'], 'body dalla Location relativa' );

echo "3) NON segue un redirect verso i metadata cloud (169.254.169.254)\n";
nx_reset();
nx_script( $public . 'evil', 302, '', array( 'location' => 'http://169.254.169.254/latest/meta-data/' ) );
nx_script( 'http://169.254.169.254/latest/meta-data/', 200, 'SEGRETO-DEI-METADATA' );
$res = Nexus_Tools::guarded_get( $public . 'evil' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'non consentito' ), 'errore di URL non consentito (ricevuto: ' . $res['error'] . ')' );
nx_ok( ! nx_body_has( $res['body'], 'SEGRETO-DEI-METADATA' ), 'nessun contenuto del target interno nel body' );
nx_ok( 0 === count( nx_requests_for_host( '169.254.169.254' ) ), 'nessuna richiesta verso 169.254.169.254' );
nx_ok( 1 === count( $GLOBALS['nx_requests'] ), 'una sola richiesta (quella lecita)' );

echo "4) rifiuta un target interno diretto (192.168.1.10)\n";
nx_reset();
nx_script( 'http://192.168.1.10/admin', 200, 'pannello interno' );
$res = Nexus_Tools::guarded_get( 'http://192.168.1.10/admin' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 0 === count( $GLOBALS['nx_requests'] ), 'nessuna richiesta partita' );

echo "5) hop esauriti: errore onesto, nessun loop infinito\n";
nx_reset();
nx_script( $public . 'loop', 302, '', array( 'location' => $public . 'loop' ) );
$res = Nexus_Tools::guarded_get( $public . 'loop' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'troppe volte' ), 'errore di troppi redirect (ricevuto: ' . $res['error'] . ')' );
nx_ok( 3 === count( $GLOBALS['nx_requests'] ), 'richieste = 1 + 2 hop (ricevute ' . count( $GLOBALS['nx_requests'] ) . ')' );

echo "6) status non-2xx: errore con il codice, niente body\n";
nx_reset();
nx_script( $public . 'missing', 404, 'not found' );
$res = Nexus_Tools::guarded_get( $public . 'missing' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 404 === $res['code'], 'code = 404' );
nx_ok( nx_body_has( $res['error'], '404' ), 'errore cita il codice HTTP' );
nx_ok( '' === $res['body'], 'body vuoto sul percorso di errore' );

echo "7) schema non http(s): rifiutato prima di qualunque richiesta\n";
nx_reset();
$res = Nexus_Tools::guarded_get( 'ftp://example.com/file.txt' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( 0 === count( $GLOBALS['nx_requests'] ), 'nessuna richiesta partita' );

echo "8) redirect senza Location: errore, nessuna richiesta successiva\n";
nx_reset();
nx_script( $public . 'noloc', 302, '', array() );
$res = Nexus_Tools::guarded_get( $public . 'noloc' );
nx_ok( empty( $res['ok'] ), 'ok = false' );
nx_ok( nx_body_has( $res['error'], 'redirect non valido' ), 'errore di redirect non valido' );
nx_ok( 1 === count( $GLOBALS['nx_requests'] ), 'una sola richiesta' );

nx_finish( 'guarded_get' );
