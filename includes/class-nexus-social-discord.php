<?php
/**
 * Nexus Social Discord — canale di distribuzione reale verso Discord (webhook).
 *
 * CONTRATTO v1.0.2 — "Distribuzione Reale", sezione Discord. Nessuna dipendenza
 * esterna, compatibile PHP 7.4. Text domain del plugin: 'nexus-news-ai'.
 *
 * Contratto pubblico (identico per i 4 canali social):
 *
 *     Nexus_Social_Discord::publish( $article, $settings ) → array(
 *         'status'   => 'published'|'failed'|'skipped',
 *         'post_id'  => string, // 'webhook' in caso di successo, '' altrimenti
 *         'post_url' => string, // '' (il webhook Discord non espone un URL remoto)
 *         'error'    => string, // '' se ok
 *         'note'     => string, // dettaglio onesto dell'esito
 *     );
 *
 * Comportamento:
 *  - enable_discord != 1 oppure webhook vuoto → 'skipped', note 'Discord non configurato'.
 *  - Webhook invalido (host ESATTO discord.com oppure discordapp.com e path che
 *    inizia con '/api/webhooks/', v1.1.9: validazione con parse_url, niente strpos
 *    su substring che faceva passare host arbitrari tipo evil-discord.com) →
 *    'skipped', note 'Webhook Discord non valido'.
 *  - Messaggio: titolo + 2 frasi REALI del summary esistente (wp_strip_all_tags,
 *    MAI riassunti inventati) + source_url. Se l'articolo ha image_url viene
 *    aggiunto un embed {title, url, description, thumbnail:{url}}.
 *  - Limiti Discord rispettati: content max 2000 caratteri; embed description
 *    troncata a 300 (quindi sempre entro il limite Discord di 4096).
 *  - HTTP SOLO con wp_remote_post (timeout 20s, con UN retry automatico sui
 *    transient rete/429/5xx — v1.1.15 WE1, cap attesa 60s), Content-Type
 *    application/json, body wp_json_encode con JSON_UNESCAPED_UNICODE. Discord
 *    conferma la consegna con 204 No Content (talvolta 200) → status 'published'.
 *  - Codici diversi da 200/204 → 'failed' con error descrittivo (HTTP code + body
 *    troncato a 200 caratteri). Nessuna eccezione propagata: qualunque problema
 *    → 'failed'. Il webhook è un segreto: NON viene MAI riportato completo nei
 *    messaggi di errore (mascherato con mask()).
 *
 * NOTA WIRING (integratore): il publisher chiama questa classe con
 * class_exists( 'Nexus_Social_Discord' ) + method_exists( ..., 'publish' );
 * qui non serve alcun require né hook.
 *
 * @package Nexus_News_AI
 * @since   1.0.2 Canale Discord reale (webhook).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Social_Discord {

        /**
         * Pubblica l'articolo sul canale Discord tramite webhook.
         *
         * @param object|array $article  Articolo Nexus (riga di nexus_articles).
         * @param array        $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Esito secondo contratto ('status','post_id','post_url','error','note').
         */
        public static function publish( $article, $settings ) {
                $settings = is_array( $settings ) ? $settings : ( is_object( $settings ) ? (array) $settings : array() );

                $enabled = isset( $settings['enable_discord'] ) ? absint( $settings['enable_discord'] ) : 0;
                $webhook = isset( $settings['discord_webhook_url'] ) ? trim( (string) $settings['discord_webhook_url'] ) : '';

                // Guard: canale disattivato o webhook assente → skip onesto (non è un errore).
                if ( 1 !== $enabled || '' === $webhook ) {
                        return self::result( 'skipped', '', '', '', 'Discord non configurato' );
                }

                $webhook = esc_url_raw( $webhook );
                if ( '' === $webhook || ! self::is_valid_webhook( $webhook ) ) {
                        return self::result( 'skipped', '', '', '', 'Webhook Discord non valido' );
                }

                try {
                        return self::send( $article, $webhook );
                } catch ( \Throwable $e ) {
                        // Nessuna eccezione propagata (contratto): fallimento descrittivo e mascherato.
                        return self::result( 'failed', '', '', 'Errore inatteso Discord: ' . self::mask( $e->getMessage() ), 'Pubblicazione Discord non riuscita.' );
                }
        }

        /**
         * Costruisce il payload e lo invia al webhook Discord.
         *
         * @param object|array $article Articolo Nexus.
         * @param string       $webhook URL webhook già validato.
         * @return array Esito secondo contratto.
         */
        private static function send( $article, $webhook ) {
                $payload = self::build_payload( $article );

                $encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
                if ( ! is_string( $encoded ) || '' === $encoded ) {
                        return self::result( 'failed', '', '', 'Impossibile preparare il payload JSON per Discord.', 'Pubblicazione Discord non riuscita.' );
                }

                // v1.1.15 (WE1): POST con UN retry su errori transitori (rete/429/5xx),
                // attesa da Retry-After di Discord (cap 60s) — mai ripetuto su 204/200
                // (niente messaggi doppiati sul canale).
                $response = self::post_with_retry(
                        $webhook,
                        array(
                                'timeout' => 20,
                                'headers' => array(
                                        'Content-Type' => 'application/json',
                                        'Accept'       => 'application/json',
                                ),
                                'body'    => $encoded,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return self::result( 'failed', '', '', 'Errore di connessione a Discord: ' . self::mask( $response->get_error_message() ), 'Pubblicazione Discord non riuscita.' );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = (string) wp_remote_retrieve_body( $response );

                // Discord conferma la consegna con 204 No Content (raramente 200).
                if ( 204 === $code || 200 === $code ) {
                        return self::result( 'published', 'webhook', '', '', 'Inviato al canale Discord' );
                }

                // Fallimento: HTTP code + body troncato a 200 caratteri (sempre mascherato).
                $detail = self::clip( self::mask( $body ), 200 );

                return self::result(
                        'failed',
                        '',
                        '',
                        'Discord ha risposto con errore HTTP ' . $code . ( '' !== $detail ? ': ' . $detail : '.' ),
                        'Pubblicazione Discord non riuscita.'
                );
        }

        /**
         * v1.1.15 (WE1) — wp_remote_post con UN retry automatico su errori
         * transitori (rete/HTTP 429/5xx): gira tramite Nexus_Notify::http_post_with_retry(),
         * l'helper condiviso di WE1 (UNA sola implementazione del retry nel plugin;
         * il rate limit di Discord porta l'attesa nell'header Retry-After).
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
         * Costruisce il payload Discord: content + eventuale embed con thumbnail.
         *
         * @param object|array $article Articolo Nexus.
         * @return array Payload pronto per wp_json_encode.
         */
        private static function build_payload( $article ) {
                $title = sanitize_text_field( (string) self::field( $article, 'title' ) );
                if ( '' === $title ) {
                        $article_id = absint( self::field( $article, 'id' ) );
                        $title      = $article_id > 0 ? sprintf( 'Articolo Nexus #%d', $article_id ) : 'Articolo Nexus';
                }

                // Solo testo esistente: 2 frasi prese dal summary, MAI contenuto inventato.
                $summary   = self::clean_text( self::field( $article, 'summary' ) );
                $sentences = self::first_sentences( $summary, 2 );

                $source_url = esc_url_raw( (string) self::field( $article, 'source_url' ) );

                // content: titolo + 2 frasi + link (limite Discord: 2000 caratteri).
                $lines = array( $title );
                if ( '' !== $sentences ) {
                        $lines[] = $sentences;
                }
                if ( '' !== $source_url ) {
                        $lines[] = $source_url;
                }
                $payload = array( 'content' => self::clip( implode( "\n\n", $lines ), 2000 ) );

                // Con un'immagine disponibile si arricchisce il messaggio con un embed.
                $image_url = esc_url_raw( (string) self::field( $article, 'image_url' ) );
                if ( '' !== $image_url ) {
                        $embed = array( 'title' => self::clip( $title, 256 ) );
                        if ( '' !== $source_url ) {
                                $embed['url'] = $source_url;
                        }
                        if ( '' !== $summary ) {
                                $embed['description'] = self::clip( $summary, 300 );
                        }
                        $embed['thumbnail'] = array( 'url' => $image_url );

                        $payload['embeds'] = array( $embed );
                }

                return $payload;
        }

        /**
         * Verifica che l'URL sia un webhook Discord (host ufficiali).
         *
         * v1.1.9: parse_url con host ESATTO ('discord.com' o 'discordapp.com') e
         * path che INIZIA con '/api/webhooks/': il vecchio strpos su substring
         * faceva passare host arbitrari come 'https://evil.com/discord.com/api/webhooks/'.
         *
         * @param string $url URL webhook normalizzato con esc_url_raw.
         * @return bool True se l'URL è un webhook Discord valido.
         */
        private static function is_valid_webhook( $url ) {
                $url = trim( (string) $url );
                if ( '' === $url ) {
                        return false;
                }

                $parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
                if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['path'] ) ) {
                        return false;
                }

                // Host esatto (case-insensitive): niente substring, niente user/port/zone.
                $host = strtolower( (string) $parsed['host'] );
                if ( 'discord.com' !== $host && 'discordapp.com' !== $host ) {
                        return false;
                }

                // Solo http/https e path che inizia col prefisso reale dei webhook.
                $scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
                if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
                        return false;
                }

                return 0 === strpos( (string) $parsed['path'], '/api/webhooks/' );
        }

        /**
         * Estrae le prime N frasi da un testo esistente (nessuna inventiva).
         *
         * @param string $text  Testo già ripulito dai tag.
         * @param int    $count Numero di frasi richieste.
         * @return string Frasi unite da spazio, '' se il testo è vuoto.
         */
        private static function first_sentences( $text, $count = 2 ) {
                $text = trim( (string) $text );
                if ( '' === $text ) {
                        return '';
                }

                // Spezza sui delimitatori di frase (.!?…) seguiti da uno spazio.
                $parts = preg_split( '/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $parts ) || empty( $parts ) ) {
                        return $text;
                }

                $out = array();
                foreach ( $parts as $part ) {
                        $part = trim( (string) $part );
                        if ( '' === $part ) {
                                continue;
                        }
                        $out[] = $part;
                        if ( count( $out ) >= max( 1, absint( $count ) ) ) {
                                break;
                        }
                }

                if ( empty( $out ) ) {
                        return $text;
                }

                return implode( ' ', $out );
        }

        /**
         * Ripulisce un testo: rimozione tag e collassamento degli spazi.
         *
         * @param string $text Testo grezzo (es. summary dell'articolo).
         * @return string Testo su riga singola senza tag HTML.
         */
        private static function clean_text( $text ) {
                $text = wp_strip_all_tags( (string) $text );
                $text = preg_replace( '/\s+/', ' ', $text );

                return trim( (string) $text );
        }

        /**
         * Tronca un testo al numero massimo di caratteri, aggiungendo '…' se tagliato.
         *
         * @param string $text      Testo da troncare.
         * @param int    $max_chars Limite in caratteri (multibyte se disponibile).
         * @return string Testo entro il limite richiesto.
         */
        private static function clip( $text, $max_chars ) {
                $text     = (string) $text;
                $max      = max( 1, absint( $max_chars ) );
                $ellipsis = '…';

                if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                        if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
                                return $text;
                        }
                        return rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . $ellipsis;
                }

                if ( strlen( $text ) <= $max ) {
                        return $text;
                }

                return rtrim( substr( $text, 0, $max - 1 ) ) . $ellipsis;
        }

        /**
         * Maschera i webhook Discord in un testo destinato a log/errori.
         *
         * Il webhook contiene il token: non deve MAI comparire completo.
         *
         * @param string $text Testo potenzialmente contenente l'URL webhook.
         * @return string Testo su riga singola con i webhook sostituiti.
         */
        private static function mask( $text ) {
                $masked = preg_replace(
                        '#(?:https?://)?(?:discord\.com|discordapp\.com)/api/webhooks/[^"\'\s]+#i',
                        '[webhook mascherato]',
                        (string) $text
                );

                $masked = is_string( $masked ) ? $masked : '';
                $masked = preg_replace( '/\s+/', ' ', $masked );

                return trim( (string) $masked );
        }

        /**
         * Legge un campo dall'articolo, accettando object o array.
         *
         * @param object|array $article Articolo Nexus.
         * @param string       $key     Nome del campo.
         * @return mixed Valore del campo, '' se assente.
         */
        private static function field( $article, $key ) {
                if ( is_array( $article ) ) {
                        return isset( $article[ $key ] ) ? $article[ $key ] : '';
                }
                if ( is_object( $article ) && isset( $article->{ $key } ) ) {
                        return $article->{ $key };
                }
                return '';
        }

        /**
         * Costruisce l'array di esito secondo il contratto condiviso v1.0.2.
         *
         * @param string $status   'published'|'failed'|'skipped'.
         * @param string $post_id  ID/uri del post remoto, '' se non disponibile.
         * @param string $post_url URL remoto, '' se non disponibile.
         * @param string $error    Descrizione errore, '' se ok.
         * @param string $note     Dettaglio onesto dell'esito.
         * @return array Esito normalizzato.
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
