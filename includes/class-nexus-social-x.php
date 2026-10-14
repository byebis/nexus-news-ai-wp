<?php
/**
 * Nexus Social X — pubblicazione reale degli articoli su X (Twitter) tramite
 * l'API v2 (POST https://api.twitter.com/2/tweets) con OAuth 1.0a firmato A MANO.
 *
 * Nessuna libreria esterna: la firma HMAC-SHA256 è implementata con le sole
 * funzioni native PHP (hash_hmac), seguendo passo passo RFC 5849.
 *
 * COME FUNZIONA LA FIRMA OAUTH 1.0a (sintesi operativa, vedi helper sotto):
 *   1. Parameter string: SOLO i parametri oauth_* (il body JSON NON entra nella
 *      firma), ordinati alfabeticamente per chiave, ogni chiave e valore
 *      codificati RFC 3986 (rawurlencode) e uniti da '&': "k1=v1&k2=v2&...".
 *   2. Base string: 'POST' + '&' + rawurlencode(endpoint) + '&' +
 *      rawurlencode(parameter_string). È QUI che avviene la "doppia codifica":
 *      la parameter string già encoded viene encoded una seconda volta.
 *   3. Signing key: rawurlencode(x_api_secret) + '&' + rawurlencode(x_access_secret).
 *   4. oauth_signature = base64( hash_hmac( 'sha256', base_string, signing_key, true ) ).
 *   5. Header 'Authorization: OAuth ...': parametri (firma inclusa) separati da
 *      virgola, chiavi e valori percent-encoded UNA volta sola. La doppia
 *      codifica esiste SOLO nella base string (punto 2): codificare due volte
 *      nell'header corromperebbe la firma e produrrebbe un 401 dal server X.
 *
 * CONTRATTO PUBBLICO (v1.0.2, canali social):
 * Nexus_Social_X::publish( $article, $settings ) → array(
 *     'status'   => 'published'|'failed'|'skipped',
 *     'post_id'  => string,  // id del tweet remoto, '' se fallito
 *     'post_url' => string,  // https://x.com/i/status/{id}, '' se fallito
 *     'error'    => string,  // '' se ok
 *     'note'     => string,  // dettaglio onesto
 * );
 *
 * Settings usati (Nexus_DB::get_settings()): enable_x, x_api_key, x_api_secret,
 * x_access_token, x_access_secret. Le 4 credenziali NON vengono MAI loggate né
 * incluse nei messaggi d'errore (mascherate con redact()).
 *
 * @package Nexus_News_AI
 * @since   1.0.2 Distribuzione reale (canale X/Twitter).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Social_X {

        /** Endpoint API v2 per la creazione dei tweet. */
        const API_ENDPOINT = 'https://api.twitter.com/2/tweets';

        /** Base degli URL pubblici dei tweet. */
        const STATUS_URL = 'https://x.com/i/status/';

        /** Limite del tweet in PUNTI ponderati (v1.1.15 WE2: CJK pesano 2, vedi tweet_weighted_length). */
        const MAX_CHARS = 280;

        /**
         * Pubblica un articolo su X (Twitter) creando un tweet con l'API v2.
         *
         * Flusso: guard configurazione → costruzione testo (280 punti ponderati,
         * v1.1.15 WE2) → firma OAuth 1.0a HMAC-SHA256 a mano → wp_remote_post
         * (timeout 20s) con UN retry automatico sui transient (rete/429/5xx,
         * v1.1.15 WE1) → interpretazione risposta. Nessuna eccezione propaga al
         * chiamante: qualunque problema diventa uno status 'failed' con errore
         * descrittivo e segreti mascherati.
         *
         * @param array|object $article  Articolo Nexus (riga di nexus_articles).
         * @param array        $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array array( 'status', 'post_id', 'post_url', 'error', 'note' ).
         */
        public static function publish( $article, $settings ) {
                try {
                        return self::publish_safe( $article, $settings );
                } catch ( \Throwable $e ) {
                        // Contratto: nessuna eccezione non catturata verso il publisher.
                        return self::result( 'failed', '', '', 'Errore inatteso invio X: ' . self::snippet( $e->getMessage() ), '' );
                }
        }

        /**
         * Implementazione protetta di publish(): guard, testo, firma, HTTP, risposta.
         *
         * @param array|object $article  Articolo Nexus.
         * @param array        $settings Impostazioni plugin.
         * @return array Risultato normalizzato (contratto canali social).
         */
        private static function publish_safe( $article, $settings ) {
                if ( is_object( $settings ) ) {
                        $settings = (array) $settings;
                }
                if ( ! is_array( $settings ) ) {
                        $settings = array();
                }
                if ( is_array( $article ) ) {
                        $article = (object) $article;
                }

                // Guard: canale disattivo oppure anche una sola credenziale vuota → skip onesto.
                $enable_x = isset( $settings['enable_x'] ) ? (int) $settings['enable_x'] : 0;
                $creds    = self::credentials( $settings );
                if ( 1 !== $enable_x || empty( $creds ) ) {
                        return self::result( 'skipped', '', '', '', __( 'X non configurato', 'nexus-news-ai' ) );
                }

                // Testo del tweet: titolo + 2 frasi del summary + source_url, max 280 caratteri.
                $text = self::build_text( $article );
                if ( '' === trim( $text ) ) {
                        return self::result( 'failed', '', '', __( 'Testo del tweet vuoto: nessun titolo o summary valido.', 'nexus-news-ai' ), '' );
                }

                // Firma OAuth 1.0a a mano → header Authorization completo.
                $auth_header = self::build_authorization( $creds );

                // Corpo JSON: SOLO "text". Il body NON entra nella firma OAuth.
                $payload = wp_json_encode( array( 'text' => $text ), JSON_UNESCAPED_UNICODE );
                if ( false === $payload ) {
                        return self::result( 'failed', '', '', 'json_encode del testo del tweet fallito.', '' );
                }

                // v1.1.15 (WE1): POST con UN retry su errori transitori (rete/429/5xx),
                // attesa da Retry-After (cap 60s) — mai ripetuto su 2xx (niente doppi tweet).
                $response = self::post_with_retry(
                        self::API_ENDPOINT,
                        array(
                                'timeout'     => 20,
                                'data_format' => 'body',
                                'headers'     => array(
                                        'Authorization' => $auth_header,
                                        'Content-Type'  => 'application/json',
                                        'Accept'        => 'application/json',
                                        'User-Agent'    => 'nexus-news-ai/1.0.2 (WordPress)',
                                ),
                                'body'        => $payload,
                        )
                );

                return self::interpret_response( $response, $creds );
        }

        /**
         * Costruisce l'header "Authorization: OAuth ..." firmato per la richiesta.
         *
         * Sequenza completa (vedi docblock di classe): parametri oauth → parameter
         * string → base string → signing key → firma HMAC-SHA256 → header.
         *
         * @param array $creds Credenziali complete (x_api_key, x_api_secret, x_access_token, x_access_secret).
         * @return string Header Authorization pronto per wp_remote_post.
         */
        private static function build_authorization( array $creds ) {
                // 1) Parametri OAuth 1.0a richiesti (SENZA firma: si aggiunge dopo).
                $oauth = array(
                        'oauth_consumer_key'     => $creds['x_api_key'],
                        'oauth_nonce'            => self::make_nonce(),
                        'oauth_signature_method' => 'HMAC-SHA256',
                        'oauth_timestamp'        => (string) time(),
                        'oauth_token'            => $creds['x_access_token'],
                        'oauth_version'          => '1.0',
                );

                // 2) Parameter string: SOLO parametri oauth, ordinati per chiave, RFC 3986.
                //    Il body JSON non partecipa alla firma (nessun oauth_body_hash richiesto).
                $parameter_string = self::build_parameter_string( $oauth );

                // 3) Base string: metodo + endpoint encoded + parameter string encoded
                //    (la parameter string viene qui encoded per la seconda volta).
                $base_string = 'POST' . '&' . self::rfc3986_encode( self::API_ENDPOINT ) . '&' . self::rfc3986_encode( $parameter_string );

                // 4) Signing key = consumer_secret + '&' + token_secret, entrambi encoded.
                //    Firma: HMAC-SHA256 in binario, poi base64.
                $signing_key = self::rfc3986_encode( $creds['x_api_secret'] ) . '&' . self::rfc3986_encode( $creds['x_access_secret'] );

                $oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha256', $base_string, $signing_key, true ) );

                // 5) Header: valori percent-encoded una volta sola (vedi build_oauth_header).
                return self::build_oauth_header( $oauth );
        }

        /**
         * Nonce OAuth: base64 del binario di uniqid + mt_rand (passati in sha256).
         *
         * Univoco per richiesta, 44 caratteri alfanumerici/base64, sotto il limite
         * pratico di 64 caratteri accettato dal server X.
         *
         * @return string Nonce monouso.
         */
        private static function make_nonce() {
                $seed  = uniqid( '', true ) . '|' . (string) mt_rand();

                return base64_encode( hash( 'sha256', $seed, true ) );
        }

        /**
         * Encoding RFC 3986 (percent-encoding): rawurlencode() è l'implementazione
         * corretta — lo spazio diventa '%20' e non '+' come in urlencode().
         *
         * @param string $value Valore da codificare.
         * @return string Valore codificato RFC 3986.
         */
        private static function rfc3986_encode( $value ) {
                return rawurlencode( (string) $value );
        }

        /**
         * Parameter string OAuth: chiavi ordinate alfabeticamente, coppie
         * "chiave=valore" con chiave e valore encoded RFC 3986, unite da '&'.
         *
         * @param array $params Parametri oauth_* (senza oauth_signature).
         * @return string Parameter string da inserire nella base string.
         */
        private static function build_parameter_string( array $params ) {
                ksort( $params );

                $pairs = array();
                foreach ( $params as $key => $value ) {
                        $pairs[] = self::rfc3986_encode( $key ) . '=' . self::rfc3986_encode( $value );
                }

                return implode( '&', $pairs );
        }

        /**
         * Header 'Authorization: OAuth k1="v1", k2="v2", ...' (firma inclusa).
         *
         * PUNTO CRITICO: chiavi e valori — firma base64 compresa — vengono
         * percent-encoded UNA volta sola qui. La "doppia" codifica esiste solo
         * nella base string (la parameter string già encoded viene re-encoded);
         * una seconda codifica qui corromperebbe la firma (401 dal server X).
         *
         * @param array $params Parametri oauth_* con oauth_signature già presente.
         * @return string Header Authorization completo.
         */
        private static function build_oauth_header( array $params ) {
                $parts = array();
                foreach ( $params as $key => $value ) {
                        $parts[] = self::rfc3986_encode( $key ) . '="' . self::rfc3986_encode( $value ) . '"';
                }

                return 'OAuth ' . implode( ', ', $parts );
        }

        /**
         * v1.1.15 (WE1) — wp_remote_post con UN retry automatico su errori
         * transitori (rete/HTTP 429/5xx): gira tramite Nexus_Notify::http_post_with_retry(),
         * l'helper condiviso di WE1 (UNA sola implementazione del retry nel plugin).
         * Con il modulo notify assente (caricamento parziale) fallback al
         * wp_remote_post storico senza retry.
         *
         * @param string $url  Endpoint.
         * @param array  $args Argomenti di wp_remote_post.
         * @return array|WP_Error Risposta dell'ultimo tentativo.
         */
        private static function post_with_retry( $url, array $args ) {
                if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'http_post_with_retry' ) ) {
                        return Nexus_Notify::http_post_with_retry( $url, $args );
                }

                return wp_remote_post( $url, $args );
        }

        /**
         * Testo del tweet: titolo + 2 frasi dal summary + source_url, entro 280
         * PUNTI ponderati (v1.1.15 WE2).
         *
         * Composizione: "Titolo — Frase 1 Frase 2" e, se presente, l'URL della
         * fonte su una riga a parte. L'URL non viene MAI troncato: se il totale
         * sfora 280 punti si accorcia solo la parte testuale a
         * (280 - punti(url) - 4) punti + '…' (margine 4 = ritorno a capo +
         * sicurezza puntini).
         *
         * @param array|object $article Articolo Nexus.
         * @return string Testo pronto per il body JSON.
         */
        private static function build_text( $article ) {
                $title   = self::clean_text( self::field( $article, 'title' ) );
                $summary = self::first_sentences( self::clean_text( self::field( $article, 'summary' ) ), 2 );

                // URL della fonte (se presente e valido): sempre preservato, mai tagliato.
                $url        = '';
                $source_url = esc_url_raw( self::field( $article, 'source_url' ) );
                if ( '' !== $source_url ) {
                        $url = $source_url;
                }

                // Nessuna inventiva: solo testo reale dell'articolo.
                $text = $title;
                if ( '' !== $summary ) {
                        $text .= ( '' === $text ? '' : ' — ' ) . $summary;
                }

                // v1.1.15 (WE2): budget in PUNTI ponderati — X conta i caratteri
                // CJK/Hangul/fullwidth con peso 2 (limite reale: 140 caratteri per
                // un testo tutto giapponese/cinese; il plugin traduce anche in zh/ja,
                // translate:120-121). Il conteggio include il ritorno a capo prima
                // dell'URL; l'URL resta conteggiato in modo conservativo (1 punto a
                // carattere, come nella versione storica).
                $url_points   = self::tweet_weighted_length( $url );
                $text_points  = self::tweet_weighted_length( $text );
                $total_points = $text_points + ( $url_points > 0 ? 1 + $url_points : 0 );

                if ( $total_points > self::MAX_CHARS ) {
                        $budget = max( 1, self::MAX_CHARS - $url_points - 4 );
                        $text   = trim( self::clip_to_points( $text, $budget ) ) . '…';
                }

                if ( '' !== $url ) {
                        $text .= "\n" . $url;
                }

                return $text;
        }

        /**
         * v1.1.15 (WE2) — lunghezza PONDERATA alla maniera di X: i caratteri
         * CJK/Hangul/fullwidth (range in char_points()) pesano 2, tutti gli altri 1.
         * Il limite del tweet è 280 punti: un testo tutto CJK entra fino a 140
         * caratteri, esattamente come lo conteggia il server X.
         *
         * @param string $text Testo UTF-8.
         * @return int Punti totali.
         */
        private static function tweet_weighted_length( $text ) {
                $text = (string) $text;
                if ( '' === $text ) {
                        return 0;
                }

                $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $chars ) ) {
                        // UTF-8 invalido (regex /u fallita): fallback al conteggio semplice.
                        return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : (int) strlen( $text );
                }

                $points = 0;
                foreach ( $chars as $char ) {
                        $points += self::char_points( $char );
                }

                return $points;
        }

        /**
         * v1.1.15 (WE2) — peso di un SINGOLO carattere secondo il conteggio di X:
         * 2 nei range CJK/Hangul/fullwidth (Hangul Jamo U+1100-11FF, radicals e
         * punteggiatura CJK U+2E80-303F, kana/Bopomofo U+3040-318F, CJK compat/est.
         * A U+3200-4DBF, CJK unificati U+4E00-9FFF, Hangul Jamo ext. U+A960-A97F,
         * Hangul syllables U+AC00-D7FF, compat. ideografico U+F900-FAFF, forme
         * verticali U+FE10-FE19, fullwidth/halfwidth U+FF00-FFEF, estensioni CJK
         * supplementari U+20000-2FA1F), 1 per tutto il resto.
         *
         * @param string $char Un solo carattere UTF-8.
         * @return int 1|2.
         */
        private static function char_points( $char ) {
                $wide = preg_match(
                        '/[\x{1100}-\x{11FF}\x{2E80}-\x{303F}\x{3040}-\x{318F}\x{3200}-\x{4DBF}\x{4E00}-\x{9FFF}\x{A960}-\x{A97F}\x{AC00}-\x{D7FF}\x{F900}-\x{FAFF}\x{FE10}-\x{FE19}\x{FF00}-\x{FFEF}\x{20000}-\x{2FA1F}]/u',
                        (string) $char
                );

                return ( 1 === $wide ) ? 2 : 1;
        }

        /**
         * v1.1.15 (WE2) — tronca un testo a un budget di PUNTI ponderati (CJK
         * pesano 2) senza mai spezzare un carattere multibyte.
         *
         * @param string $text       Testo UTF-8.
         * @param int    $max_points Budget in punti.
         * @return string Testo entro il budget ('' se il budget è esaurito).
         */
        private static function clip_to_points( $text, $max_points ) {
                $text       = (string) $text;
                $max_points = max( 0, (int) $max_points );
                if ( '' === $text || 0 === $max_points ) {
                        return '';
                }

                $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $chars ) ) {
                        // UTF-8 invalido: fallback storico (troncamento per caratteri).
                        return function_exists( 'mb_substr' ) ? (string) mb_substr( $text, 0, $max_points, 'UTF-8' ) : (string) substr( $text, 0, $max_points );
                }

                $out    = '';
                $points = 0;
                foreach ( $chars as $char ) {
                        $weight = self::char_points( $char );
                        if ( $points + $weight > $max_points ) {
                                break;
                        }
                        $out    .= $char;
                        $points += $weight;
                }

                return $out;
        }

        /**
         * Estrae le prime $count frasi da un testo già pulito (NON inventa riassunti).
         *
         * Spezza dopo i delimitatori di frase ( . ! ? … ) seguiti da uno o più
         * spazi; se il testo contiene meno frasi del richiesto, lo restituisce
         * intero. Con UTF-8 invalido la regex /u fallisce: si torna al testo intero.
         *
         * @param string $text  Testo pulito (wp_strip_all_tags + spazi collassati).
         * @param int    $count Numero di frasi da mantenere.
         * @return string Prime $count frasi, oppure il testo intero.
         */
        private static function first_sentences( $text, $count = 2 ) {
                $text = trim( (string) $text );
                if ( '' === $text ) {
                        return '';
                }

                $parts = preg_split( '/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $parts ) || count( $parts ) <= $count ) {
                        return $text;
                }

                return trim( implode( ' ', array_slice( $parts, 0, $count ) ) );
        }

        /**
         * Pulizia del testo: niente tag HTML, entità decodificate, spazi collassati.
         *
         * @param string $value Valore grezzo dall'articolo.
         * @return string Testo monoriga pulito.
         */
        private static function clean_text( $value ) {
                $value = wp_strip_all_tags( (string) $value );
                $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                $collapsed = preg_replace( '/\s+/u', ' ', $value );
                if ( null === $collapsed ) {
                        // UTF-8 invalido: regex /u fallita → fallback senza regex.
                        return trim( $value );
                }

                return trim( $collapsed );
        }

        /**
         * Lettura difensiva di un campo dell'articolo (object o array).
         *
         * @param array|object $article Articolo Nexus.
         * @param string       $key     Nome del campo.
         * @return string Valore come stringa, '' se assente.
         */
        private static function field( $article, $key ) {
                if ( is_array( $article ) && isset( $article[ $key ] ) ) {
                        return (string) $article[ $key ];
                }
                if ( is_object( $article ) && isset( $article->{$key} ) ) {
                        return (string) $article->{$key};
                }

                return '';
        }

        /**
         * Credenziali X: le 4 chiavi richieste, o array vuoto se una sola è vuota.
         *
         * @param array $settings Impostazioni plugin.
         * @return array array( 'x_api_key', 'x_api_secret', 'x_access_token', 'x_access_secret' ) oppure array().
         */
        private static function credentials( array $settings ) {
                $creds = array();
                foreach ( array( 'x_api_key', 'x_api_secret', 'x_access_token', 'x_access_secret' ) as $key ) {
                        $value = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
                        if ( '' === $value ) {
                                return array();
                        }
                        $creds[ $key ] = $value;
                }

                return $creds;
        }

        /**
         * Interpreta la risposta HTTP di X e la traduce nel contratto dei canali.
         *
         * 201 atteso con JSON { "data": { "id": "...", "text": "..." } } → 'published'
         * con post_url https://x.com/i/status/{id}. 401/403 → credenziali rifiutate.
         * Altri codici → 'failed' con codice HTTP e body mascherato/troncato a 200.
         *
         * @param array|WP_Error $response Risposta di wp_remote_post.
         * @param array          $creds    Credenziali (per la mascheratura nei messaggi).
         * @return array Risultato normalizzato.
         */
        private static function interpret_response( $response, array $creds ) {
                if ( is_wp_error( $response ) ) {
                        return self::result( 'failed', '', '', 'Errore di rete verso X: ' . self::snippet( self::redact( $response->get_error_message(), $creds ) ), '' );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = (string) wp_remote_retrieve_body( $response );

                // 201 Created: risposta attesa dall'API v2 per un tweet creato.
                if ( 201 === $code ) {
                        $data     = json_decode( $body, true );
                        $tweet_id = ( is_array( $data ) && isset( $data['data']['id'] ) ) ? (string) $data['data']['id'] : '';

                        if ( '' === $tweet_id ) {
                                return self::result( 'failed', '', '', 'Risposta X inattesa (HTTP 201 senza data.id): ' . self::snippet( self::redact( $body, $creds ) ), '' );
                        }

                        return self::result( 'published', $tweet_id, self::STATUS_URL . $tweet_id, '', __( 'X: tweet pubblicato via API v2 (OAuth 1.0a HMAC-SHA256)', 'nexus-news-ai' ) );
                }

                // 401/403: chiavi o token rifiutati dal server X.
                if ( 401 === $code || 403 === $code ) {
                        return self::result( 'failed', '', '', sprintf( __( 'Credenziali X rifiutate (HTTP %d)', 'nexus-news-ai' ), $code ), '' );
                }

                // Altri codici: codice HTTP + body mascherato e troncato a 200 caratteri.
                return self::result( 'failed', '', '', 'X HTTP ' . $code . ': ' . self::snippet( self::redact( $body, $creds ) ), '' );
        }

        /**
         * Maschera i segreti dentro un testo (body di risposta o messaggi d'errore):
         * nessuna credenziale può finire in un log o in un messaggio visibile.
         *
         * @param string $text Testo da ripulire.
         * @param array  $creds Credenziali da sostituire con '[***]'.
         * @return string Testo senza segreti in chiaro.
         */
        private static function redact( $text, array $creds ) {
                $text = (string) $text;
                foreach ( $creds as $secret ) {
                        if ( '' !== $secret ) {
                                $text = str_replace( $secret, '[***]', $text );
                        }
                }

                return $text;
        }

        /**
         * Monoriga troncata a 200 caratteri per i messaggi d'errore (body di risposta).
         *
         * @param string $text Testo (anche HTML/multiriga).
         * @return string Estratto sicuro da mostrare nei log.
         */
        private static function snippet( $text ) {
                $text = wp_strip_all_tags( (string) $text );

                $single = preg_replace( '/\s+/', ' ', $text );
                if ( null === $single ) {
                        $single = $text;
                }

                return trim( mb_substr( trim( $single ), 0, 200 ) );
        }

        /**
         * Struttura di ritorno normalizzata (contratto canali social v1.0.2).
         *
         * @param string $status   'published'|'failed'|'skipped'.
         * @param string $post_id  Id remoto o ''.
         * @param string $post_url URL remoto o ''.
         * @param string $error    Errore o ''.
         * @param string $note     Nota onesta o ''.
         * @return array Contratto completo: status, post_id, post_url, error, note.
         */
        private static function result( $status, $post_id, $post_url, $error, $note ) {
                return array(
                        'status'   => (string) $status,
                        'post_id'  => (string) $post_id,
                        'post_url' => (string) $post_url,
                        'error'    => (string) $error,
                        'note'     => (string) $note,
                );
        }
}
