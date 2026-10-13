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

        /** Limite totale di caratteri di un tweet. */
        const MAX_CHARS = 280;

        /**
         * Pubblica un articolo su X (Twitter) creando un tweet con l'API v2.
         *
         * Flusso: guard configurazione → costruzione testo (max 280 caratteri) →
         * firma OAuth 1.0a HMAC-SHA256 a mano → wp_remote_post (timeout 20s) →
         * interpretazione risposta. Nessuna eccezione propaga al chiamante:
         * qualunque problema diventa uno status 'failed' con errore descrittivo
         * e segreti mascherati.
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

                $response = wp_remote_post(
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
         * Testo del tweet: titolo + 2 frasi dal summary + source_url, max 280 caratteri.
         *
         * Composizione: "Titolo — Frase 1 Frase 2" e, se presente, l'URL della
         * fonte su una riga a parte. L'URL non viene MAI troncato: se il totale
         * sfora 280 si accorcia solo la parte testuale a (280 - len(url) - 4)
         * caratteri + '…' (margine 4 = ritorno a capo + sicurezza puntini).
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

                // Budget caratteri: il calcolo include il ritorno a capo prima dell'URL.
                $url_len = mb_strlen( $url );
                if ( mb_strlen( $text ) + ( $url_len > 0 ? 1 + $url_len : 0 ) > self::MAX_CHARS ) {
                        $limit = max( 1, self::MAX_CHARS - $url_len - 4 );
                        $text  = trim( mb_substr( $text, 0, $limit ) ) . '…';
                }

                if ( '' !== $url ) {
                        $text .= "\n" . $url;
                }

                return $text;
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
