<?php
/**
 * Nexus Social LinkedIn — pubblicazione reale dei post LinkedIn (API UGC v2)
 * per il plugin Nexus News AI.
 *
 * Port del canale social 'linkedin' da simulato a reale (Contratto v1.0.2
 * "Distribuzione Reale", sezione "Contratto condiviso per i 4 canali social").
 *
 * CONTRATTO PUBBLICO (usato dal publisher, class-nexus-publisher.php):
 * Nexus_Social_LinkedIn::publish( $article, $settings ) → array(
 *     'status'    => 'published'|'failed'|'skipped',
 *     'post_id'   => string,   // id/urn del post remoto, '' se fallito
 *     'post_url'  => string,   // URL remoto del feed LinkedIn, '' se non ricostruibile
 *     'error'     => string,   // '' se ok
 *     'note'      => string,   // dettaglio onesto (es. 'LinkedIn: post pubblicato')
 * );
 *
 * Comportamento:
 * - $article è una riga di nexus_articles (object|array: title, summary,
 *   source_url, ...). $settings = Nexus_DB::get_settings().
 * - Guard: enable_linkedin != 1 oppure linkedin_access_token/linkedin_author_urn
 *   vuoti → status 'skipped' con note 'LinkedIn non configurato'.
 * - linkedin_author_urn valido solo se inizia con 'urn:li:person:' oppure
 *   'urn:li:organization:' → altrimenti 'skipped' con note 'URN LinkedIn non valido'.
 * - POST https://api.linkedin.com/v2/ugcPosts con header Authorization Bearer,
 *   X-Restli-Protocol-Version: 2.0.0 e Content-Type: application/json.
 * - Testo del post in ITALIANO: titolo + prime 2 frasi del summary
 *   (wp_strip_all_tags, nessun testo inventato), massimo 900 caratteri (mb_substr).
 * - source_url presente → shareMediaCategory 'ARTICLE' con media card
 *   (status READY, originalUrl, title); source_url assente → shareMediaCategory
 *   'NONE' con solo testo.
 * - Risposta: id dal body JSON {id} oppure dall'header X-RestLi-Id → 'published';
 *   post_url = https://www.linkedin.com/feed/update/{id} solo se l'id inizia con
 *   'urn:li:share' o 'urn:li:ugcPost', altrimenti ''.
 * - HTTP 401 → 'failed' con 'Token LinkedIn scaduto o non valido (HTTP 401)';
 *   altri codici → 'failed' con codice HTTP.
 * - SEGRETI: il token non viene mai scritto nei log né negli errori (eventuale
 *   occorrenza nel corpo di risposta viene mascherata).
 * - Nessuna eccezione propagata: qualunque errore → 'failed' + descrizione.
 * - HTTP solo con wp_remote_post, timeout 20s (con UN retry automatico sui
 *   transient rete/429/5xx, v1.1.15 WE1, cap attesa 60s), body json con
 *   JSON_UNESCAPED_UNICODE.
 *
 * Text domain: 'nexus-news-ai'.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Social_LinkedIn {

        /**
         * Endpoint UGC API di LinkedIn per la creazione dei post.
         */
        const API_ENDPOINT = 'https://api.linkedin.com/v2/ugcPosts';

        /**
         * Lunghezza massima del testo del post (shareCommentary.text).
         */
        const MAX_TEXT_LENGTH = 900;

        /**
         * Pubblica l'articolo su LinkedIn tramite l'API UGC v2.
         *
         * Ritorna SEMPRE l'array con le chiavi 'status', 'post_id', 'post_url',
         * 'error' e 'note' (vedi docblock della classe). Non lancia mai eccezioni.
         *
         * @param object|array $article  Riga della tabella nexus_articles.
         * @param array        $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array
         */
        public static function publish( $article, $settings ) {
                $result = array(
                        'status'    => 'skipped',
                        'post_id'   => '',
                        'post_url'  => '',
                        'error'     => '',
                        'note'      => '',
                );

                try {
                        $settings = is_array( $settings ) ? $settings : array();

                        $enabled = isset( $settings['enable_linkedin'] ) ? absint( $settings['enable_linkedin'] ) : 0;
                        $token   = isset( $settings['linkedin_access_token'] ) ? trim( (string) $settings['linkedin_access_token'] ) : '';
                        $urn     = isset( $settings['linkedin_author_urn'] ) ? trim( (string) $settings['linkedin_author_urn'] ) : '';

                        // Guard di configurazione: canale disattivato o credenziali mancanti.
                        if ( 1 !== $enabled || '' === $token || '' === $urn ) {
                                $result['note'] = 'LinkedIn non configurato';
                                return $result;
                        }

                        // Guard sull'author urn: deve identificare una persona o un'organizzazione.
                        if ( 0 !== strpos( $urn, 'urn:li:person:' ) && 0 !== strpos( $urn, 'urn:li:organization:' ) ) {
                                $result['note'] = 'URN LinkedIn non valido';
                                return $result;
                        }

                        $title  = self::clean_text( self::field( $article, 'title' ) );
                        $source = esc_url_raw( self::field( $article, 'source_url' ) );

                        // Testo del post: titolo + 2 frasi reali dal summary, mai contenuto inventato.
                        $text = self::build_text( $title, self::field( $article, 'summary' ) );

                        $share = array(
                                'shareCommentary'    => array(
                                        'text' => $text,
                                ),
                                'shareMediaCategory' => '' !== $source ? 'ARTICLE' : 'NONE',
                        );

                        // Link card dell'articolo solo quando esiste una source_url valida.
                        if ( '' !== $source ) {
                                $share['media'] = array(
                                        array(
                                                'status'      => 'READY',
                                                'originalUrl' => $source,
                                                'title'       => array(
                                                        'text' => self::clip( $title, 400 ),
                                                ),
                                        ),
                                );
                        }

                        $body = array(
                                'author'          => $urn,
                                'lifecycleState'  => 'PUBLISHED',
                                'specificContent' => array(
                                        'com.linkedin.ugc.ShareContent' => $share,
                                ),
                                'visibility'      => array(
                                        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                                ),
                        );

                        $encoded_body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
                        if ( false === $encoded_body || '' === $encoded_body ) {
                                $result['status'] = 'failed';
                                $result['error']  = 'Impossibile preparare il payload JSON per LinkedIn (encoding fallito).';
                                return $result;
                        }

                        // v1.1.15 (WE1): POST con UN retry su errori transitori
                        // (rete/429/5xx), attesa da Retry-After di LinkedIn (cap 60s) —
                        // mai ripetuto su 2xx con id remoto (niente post doppiati).
                        $response = self::post_with_retry(
                                self::API_ENDPOINT,
                                array(
                                        'timeout' => 20,
                                        'headers' => array(
                                                'Authorization'              => 'Bearer ' . $token,
                                                'X-Restli-Protocol-Version'  => '2.0.0',
                                                'Content-Type'               => 'application/json',
                                                'Accept'                     => 'application/json',
                                        ),
                                        'body'    => $encoded_body,
                                )
                        );

                        if ( is_wp_error( $response ) ) {
                                $result['status'] = 'failed';
                                $result['error']  = 'Errore di connessione a LinkedIn: ' . self::redact_secret( $response->get_error_message(), $token );
                                return $result;
                        }

                        $code = (int) wp_remote_retrieve_response_code( $response );
                        $raw  = (string) wp_remote_retrieve_body( $response );

                        if ( 401 === $code ) {
                                $result['status'] = 'failed';
                                $result['error']  = 'Token LinkedIn scaduto o non valido (HTTP 401)';
                                return $result;
                        }

                        if ( $code < 200 || $code > 299 ) {
                                $detail = self::redact_secret( self::clip( trim( wp_strip_all_tags( $raw ) ), 200 ), $token );
                                $result['status'] = 'failed';
                                $result['error']  = 'Richiesta LinkedIn fallita (HTTP ' . $code . ')' . ( '' !== $detail ? ': ' . $detail : '.' );
                                return $result;
                        }

                        // Id del post: prima dal body JSON {id}, poi dall'header X-RestLi-Id.
                        $post_id = '';
                        $data    = json_decode( $raw, true );
                        if ( is_array( $data ) && isset( $data['id'] ) && is_scalar( $data['id'] ) ) {
                                $post_id = trim( (string) $data['id'] );
                        }
                        if ( '' === $post_id ) {
                                $header_id = wp_remote_retrieve_header( $response, 'x-restli-id' );
                                if ( is_array( $header_id ) ) {
                                        $header_id = isset( $header_id[0] ) ? $header_id[0] : '';
                                }
                                $post_id = trim( (string) $header_id );
                        }

                        if ( '' === $post_id ) {
                                $result['status'] = 'failed';
                                $result['error']  = 'LinkedIn non ha restituito l\'identificativo del post (HTTP ' . $code . ').';
                                return $result;
                        }

                        // URL pubblico ricostruibile solo per gli urn di share/ugcPost.
                        $post_url = '';
                        if ( 0 === strpos( $post_id, 'urn:li:share' ) || 0 === strpos( $post_id, 'urn:li:ugcPost' ) ) {
                                $post_url = 'https://www.linkedin.com/feed/update/' . $post_id;
                        }

                        $result['status']   = 'published';
                        $result['post_id']  = $post_id;
                        $result['post_url'] = $post_url;
                        $result['note']     = 'LinkedIn: post pubblicato (HTTP ' . $code . ')' . ( 'ARTICLE' === $share['shareMediaCategory'] ? ' con link card' : ' (solo testo)' );
                        return $result;
                } catch ( \Throwable $e ) {
                        // Mai propagare eccezioni al chiamante (publisher/cron): log onesto e uscita pulita.
                        $result['status']   = 'failed';
                        $result['post_id']  = '';
                        $result['post_url'] = '';
                        $result['error']    = 'Errore inatteso durante la pubblicazione su LinkedIn: ' . $e->getMessage();
                        return $result;
                }
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
         * Legge un campo da $article gestendo sia object sia array.
         *
         * @param object|array $article Riga articolo.
         * @param string       $key     Nome del campo.
         * @return string Valore scalare come stringa, '' se assente.
         */
        private static function field( $article, $key ) {
                $value = '';
                if ( is_object( $article ) && isset( $article->{$key} ) ) {
                        $value = $article->{$key};
                } elseif ( is_array( $article ) && isset( $article[ $key ] ) ) {
                        $value = $article[ $key ];
                }
                return is_scalar( $value ) ? (string) $value : '';
        }

        /**
         * Pulisce un testo: rimuove i tag HTML e gli spazi in eccesso.
         *
         * @param string $text Testo grezzo.
         * @return string
         */
        private static function clean_text( $text ) {
                $text = trim( (string) wp_strip_all_tags( (string) $text ) );
                return (string) preg_replace( '/\s+/u', ' ', $text );
        }

        /**
         * Estrae le prime $count frasi da un testo pulito (nessuna riscrittura).
         *
         * @param string $summary Summary dell'articolo già privo di tag.
         * @param int    $count   Numero massimo di frasi da restituire.
         * @return array Lista di frasi (array vuoto se il testo è vuoto).
         */
        private static function first_sentences( $summary, $count ) {
                $summary = self::clean_text( $summary );
                if ( '' === $summary || $count < 1 ) {
                        return array();
                }

                $parts = preg_split( '/(?<=[.!?…])\s+/u', $summary, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $parts ) || empty( $parts ) ) {
                        return array( $summary );
                }

                $sentences = array();
                foreach ( $parts as $part ) {
                        $part = trim( (string) $part );
                        if ( '' === $part ) {
                                continue;
                        }
                        $sentences[] = $part;
                        if ( count( $sentences ) >= $count ) {
                                break;
                        }
                }
                return $sentences;
        }

        /**
         * Compone il testo del post: titolo + prime 2 frasi del summary,
         * troncato al massimo consentito (900 caratteri, mb_substr).
         *
         * @param string $title   Titolo dell'articolo (già pulito).
         * @param string $summary Summary dell'articolo (grezzo).
         * @return string
         */
        private static function build_text( $title, $summary ) {
                $parts = array();
                if ( '' !== $title ) {
                        $parts[] = $title;
                }

                $sentences = self::first_sentences( $summary, 2 );
                if ( ! empty( $sentences ) ) {
                        $parts[] = implode( ' ', $sentences );
                }

                return self::clip( implode( "\n\n", $parts ), self::MAX_TEXT_LENGTH );
        }

        /**
         * Tronca una stringa multibyte alla lunghezza massima (fallback substr).
         *
         * @param string $text   Testo da troncare.
         * @param int    $length Lunghezza massima.
         * @return string
         */
        private static function clip( $text, $length ) {
                $text = (string) $text;
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $length );
                }
                return substr( $text, 0, (int) $length );
        }

        /**
         * Maschera un eventuale segreto presente in un testo destinato a log
         * o messaggi d'errore (il token non deve mai comparire in chiaro).
         *
         * @param string $text   Testo da ripulire.
         * @param string $secret Segreto da mascherare ('' = nessuno).
         * @return string
         */
        private static function redact_secret( $text, $secret ) {
                if ( '' === $secret ) {
                        return (string) $text;
                }
                return str_replace( $secret, '[mascherato]', (string) $text );
        }
}
