<?php
/**
 * Nexus Social Telegram — canale di distribuzione reale verso la Telegram Bot API.
 *
 * Invia l'annuncio dell'articolo alla chat/canale configurata (telegram_chat_id)
 * usando il bot token impostato nel plugin (telegram_bot_token):
 *  - sendPhoto   se l'articolo ha un image_url valido (caption max ~900 caratteri);
 *  - sendMessage altrimenti (testo max ~500 caratteri);
 *  - parse_mode HTML: titolo in grassetto <b> + fino a 2 frasi REALI prese dal
 *    summary già esistente (nessuna frase inventata) + link alla fonte
 *    (article->source_url, se presente; niente se assente);
 *  - POST JSON via wp_remote_post (timeout 20s, Content-Type application/json,
 *    body wp_json_encode con JSON_UNESCAPED_UNICODE). Nessun multipart: l'immagine
 *    viene passata come URL pubblico nel campo 'photo'.
 *
 * CONTRATTO PUBBLICO (CONTRATTO v1.0.2 — "Contratto condiviso per i 4 canali social"):
 * Nexus_Social_Telegram::publish( $article, $settings ) → array(
 *     'status'   => 'published'|'failed'|'skipped',
 *     'post_id'  => string,  // message_id Telegram dell'invio, '' se fallito
 *     'post_url' => string,  // '' (l'URL di un messaggio di chat non è ricostruibile)
 *     'error'    => string,  // '' se ok; MAI con il token completo (maschera '…' + ultime 4 cifre)
 *     'note'     => string,  // dettaglio onesto (es. 'Inviato in chat {chat_id}')
 * );
 *
 * $article è la riga (object|array) di nexus_articles (title, summary, source_url,
 * image_url, ...); $settings è l'array di Nexus_DB::get_settings().
 *
 * Guard (contratto §Telegram): enable_telegram != 1 oppure telegram_bot_token o
 * telegram_chat_id vuoti → status 'skipped' + note 'Telegram non configurato'.
 *
 * SICUREZZA: nessun segreto in log o messaggi d'errore (il token viene sempre
 * mascherato anche dentro i messaggi delle eccezioni); nessuna dipendenza esterna;
 * mai eccezioni propagate (try/catch \Throwable → status 'failed').
 * Nessuna stringa UI traducibile: i testi del messaggio sono dati d'invio (come
 * nel publisher); text domain del plugin: 'nexus-news-ai'.
 *
 * NOTA WIRING (integratore): il publisher mappa la piattaforma 'telegram' su
 * questa classe con class_exists + method_exists; serve solo il require_once di
 * questo file in nexus-news-ai.php (nessuna altra integrazione).
 *
 * @package Nexus_News_AI
 * @since   1.0.2 Distribuzione sociale reale.
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Social_Telegram {

        /**
         * Pubblica l'annuncio dell'articolo sulla chat Telegram configurata.
         *
         * Guard (contratto): canale disattivato o credenziali mancanti → 'skipped'.
         * Qualunque altro problema → 'failed' con errore descrittivo (token sempre
         * mascherato). Nessuna eccezione viene mai propagata al chiamante.
         *
         * @param object|array $article  Riga dell'articolo Nexus (nexus_articles).
         * @param array        $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Array con status, post_id, post_url, error, note.
         */
        public static function publish( $article, $settings ) {
                $skipped = self::result( 'skipped', '', '', '', 'Telegram non configurato' );

                // Normalizza gli input: l'articolo può arrivare come riga object o array.
                if ( is_array( $article ) ) {
                        $article = (object) $article;
                }
                $settings = is_array( $settings ) ? $settings : (array) $settings;

                if ( ! is_object( $article ) ) {
                        return $skipped;
                }

                // Guard 1: canale disattivato (enable_telegram deve valere esattamente 1).
                $enabled = isset( $settings['enable_telegram'] ) ? absint( $settings['enable_telegram'] ) : 0;
                if ( 1 !== $enabled ) {
                        return $skipped;
                }

                // Guard 2: credenziali obbligatorie (bot token + chat id di destinazione).
                $token   = isset( $settings['telegram_bot_token'] ) ? trim( (string) $settings['telegram_bot_token'] ) : '';
                $chat_id = isset( $settings['telegram_chat_id'] ) ? trim( (string) $settings['telegram_chat_id'] ) : '';
                if ( '' === $token || '' === $chat_id ) {
                        return $skipped;
                }

                try {
                        return self::send( $article, $token, $chat_id );
                } catch ( \Throwable $e ) {
                        // Mai eccezioni propagate: errore descrittivo con token mascherato.
                        return self::failed( 'Errore inatteso durante l\'invio a Telegram: ' . $e->getMessage(), $token );
                }
        }

        /**
         * Costruisce il payload, chiama la Bot API e interpreta la risposta.
         *
         * @param object $article Articolo Nexus normalizzato.
         * @param string $token   Bot token Telegram (mai loggato o restituito in chiaro).
         * @param string $chat_id Chat/canale di destinazione.
         * @return array Risultato secondo il contratto del canale.
         */
        private static function send( $article, $token, $chat_id ) {
                $title   = self::field( $article, 'title' );
                $summary = self::field( $article, 'summary' );
                $link    = esc_url_raw( self::field( $article, 'source_url' ) );
                $image   = esc_url_raw( self::field( $article, 'image_url' ) );

                // Nessun contenuto da annunciare: è un errore d'input, non uno skip.
                if ( '' === self::clean_text( $title ) && '' === self::clean_text( $summary ) ) {
                        return self::failed( 'Titolo e summary dell\'articolo sono vuoti: nessun messaggio da inviare.', $token );
                }

                // Immagine valida (URL http/https pubblico): caption più lunga (~900) via sendPhoto.
                $with_image = ( '' !== $image && preg_match( '#^https?://#i', $image ) );
                $max_len    = $with_image ? 900 : 500;
                $message    = self::build_message( $title, $summary, $link, $max_len );

                // Payload JSON: sendPhoto con URL dell'immagine, oppure sendMessage di solo testo.
                $body = $with_image
                        ? array(
                                'chat_id'    => $chat_id,
                                'photo'      => $image,
                                'caption'    => $message,
                                'parse_mode' => 'HTML',
                        )
                        : array(
                                'chat_id'    => $chat_id,
                                'text'       => $message,
                                'parse_mode' => 'HTML',
                        );

                $encoded = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
                if ( false === $encoded || '' === $encoded ) {
                        return self::failed( 'Impossibile preparare il messaggio Telegram (encoding JSON fallito).', $token );
                }

                $endpoint = 'https://api.telegram.org/bot' . $token . '/' . ( $with_image ? 'sendPhoto' : 'sendMessage' );

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 20,
                                'headers' => array(
                                        'Content-Type' => 'application/json',
                                ),
                                'body'    => $encoded,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return self::failed( 'Errore di connessione a Telegram: ' . $response->get_error_message(), $token );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                $data = self::decode_response( $raw );
                if ( null === $data ) {
                        return self::failed( 'Risposta di Telegram non interpretabile (HTTP ' . $code . ').', $token );
                }

                if ( empty( $data['ok'] ) ) {
                        $desc = isset( $data['description'] ) ? trim( (string) $data['description'] ) : '';
                        return self::failed( 'Telegram ha rifiutato l\'invio (HTTP ' . $code . ')' . ( '' !== $desc ? ': ' . $desc : '.' ), $token );
                }

                // Successo: message_id come post_id; l'URL di chat non è ricostruibile → ''.
                $result  = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();
                $post_id = isset( $result['message_id'] ) ? (string) $result['message_id'] : '';

                return self::result( 'published', $post_id, '', '', 'Inviato in chat ' . $chat_id );
        }

        /**
         * Costruisce il testo del messaggio in HTML Telegram.
         *
         * Struttura (contratto): titolo in grassetto + fino a 2 frasi REALI del
         * summary esistente + link alla fonte. I blocchi entrano nell'ordine finché
         * il budget di caratteri ($max) lo consente, con priorità al titolo e alla
         * fonte (lo spazio per il link viene riservato subito dopo il titolo);
         * nessuna frase viene mai inventata.
         *
         * @param string $title   Titolo dell'articolo.
         * @param string $summary Summary dell'articolo.
         * @param string $link    URL della fonte ('' se assente).
         * @param int    $max     Budget caratteri (500 sendMessage, 900 caption sendPhoto).
         * @return string Messaggio pronto per parse_mode HTML.
         */
        private static function build_message( $title, $summary, $link, $max ) {
                $max    = (int) $max;
                $blocks = array();
                $used   = 0;

                // Blocco 1 — titolo in grassetto, sempre presente quando disponibile.
                $title = self::clean_text( $title, 150 );
                if ( '' !== $title ) {
                        $title_html = '<b>' . self::esc_for_html( $title ) . '</b>';
                        if ( self::len( $title_html ) > $max ) {
                                // Budget molto stretto: tronca il titolo GREZZO e poi escapa (evita entità spezzate).
                                $keep = $max - self::len( '<b></b>' ) - 1;
                                $title_html = $keep > 0
                                        ? '<b>' . self::esc_for_html( self::clip( $title, $keep ) ) . '</b>'
                                        : '<b>…</b>';
                        }
                        $blocks[] = $title_html;
                        $used    += self::len( $title_html );
                }

                // Blocco 2 — fino a 2 frasi REALI prese dal summary esistente.
                $sentences = self::summary_sentences( $summary, 2 );

                // Blocco 3 — link alla fonte: riserva lo spazio subito dopo il titolo
                // così la fonte non salta se le frasi consumano il budget.
                $link_html = ( '' !== $link ) ? self::esc_for_html( $link ) : '';
                $reserve   = ( '' !== $link_html ) ? 2 + self::len( $link_html ) : 0;
                $link_fits = ( '' !== $link_html && ( $used + $reserve ) <= $max );
                $used     += $link_fits ? $reserve : 0;

                foreach ( $sentences as $sentence ) {
                        $sentence = self::clip( $sentence, 280 );
                        if ( '' === $sentence ) {
                                continue;
                        }
                        $need = 2 + self::len( self::esc_for_html( $sentence ) );
                        if ( $used + $need > $max ) {
                                // Le frasi stanno in ordine: se non entra una, non entrano le successive.
                                break;
                        }
                        $blocks[] = self::esc_for_html( $sentence );
                        $used    += $need;
                }

                if ( $link_fits ) {
                        $blocks[] = $link_html;
                }

                if ( empty( $blocks ) ) {
                        // Estremo difensivo: non dovrebbe accadere (titolo o summary presenti).
                        return '…';
                }

                return implode( "\n\n", $blocks );
        }

        /**
         * Estrae fino a $limit frasi REALI dal summary esistente (nessun testo generato).
         *
         * Spezza sul punto (. ! ? …) seguito da spazio; se il summary è un unico
         * blocco senza punteggiatura viene restituito intero come unica frase.
         *
         * @param string $summary Summary dell'articolo.
         * @param int    $limit   Numero massimo di frasi da restituire.
         * @return array Lista di frasi (eventualmente vuota).
         */
        private static function summary_sentences( $summary, $limit ) {
                $text = self::clean_text( $summary );
                if ( '' === $text ) {
                        return array();
                }

                $parts = preg_split( '/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $parts ) || empty( $parts ) ) {
                        $parts = array( $text );
                }

                $sentences = array();
                foreach ( $parts as $part ) {
                        $part = trim( (string) $part );
                        if ( '' === $part ) {
                                continue;
                        }
                        $sentences[] = $part;
                        if ( count( $sentences ) >= (int) $limit ) {
                                break;
                        }
                }

                return $sentences;
        }

        /**
         * Ripulisce un testo: rimuove i tag HTML, collassa gli spazi e tronca
         * (multibyte quando disponibile, in stile Nexus_AI_Engine::clip()).
         *
         * @param string $text Testo di partenza.
         * @param int    $max  Lunghezza massima in caratteri (0 = nessun troncamento).
         * @return string
         */
        private static function clean_text( $text, $max = 0 ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );
                $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

                if ( $max > 0 && '' !== $text ) {
                        $text = self::clip( $text, (int) $max );
                }

                return $text;
        }

        /**
         * Tronca un testo alla lunghezza massima aggiungendo il carattere '…'.
         *
         * @param string $text Testo di partenza.
         * @param int    $max  Lunghezza massima in caratteri.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = trim( (string) $text );
                $max  = (int) $max;
                if ( $max < 1 || '' === $text || self::len( $text ) <= $max ) {
                        return $text;
                }

                $cut = function_exists( 'mb_substr' )
                        ? mb_substr( $text, 0, $max, 'UTF-8' )
                        : substr( $text, 0, $max );

                return trim( (string) $cut ) . '…';
        }

        /**
         * Lunghezza di un testo in caratteri (multibyte quando disponibile).
         *
         * @param string $text Testo da misurare.
         * @return int
         */
        private static function len( $text ) {
                return function_exists( 'mb_strlen' )
                        ? (int) mb_strlen( (string) $text, 'UTF-8' )
                        : (int) strlen( (string) $text );
        }

        /**
         * Escapa un testo per il parse_mode HTML della Bot API: SOLO < > &
         * (i caratteri " e ' sono ammessi così come sono; le entità prodotte
         * restano corte, come richiesto da Telegram).
         *
         * @param string $text Testo grezzo da escapare.
         * @return string
         */
        private static function esc_for_html( $text ) {
                return (string) strtr(
                        (string) $text,
                        array(
                                '&' => '&amp;',
                                '<' => '&lt;',
                                '>' => '&gt;',
                        )
                );
        }

        /**
         * Legge un campo dall'articolo (object|array) come stringa.
         *
         * @param object|array $article Articolo Nexus.
         * @param string       $key     Nome del campo.
         * @return string
         */
        private static function field( $article, $key ) {
                if ( is_object( $article ) && isset( $article->{$key} ) ) {
                        return (string) $article->{$key};
                }
                if ( is_array( $article ) && isset( $article[ $key ] ) ) {
                        return (string) $article[ $key ];
                }
                return '';
        }

        /**
         * Decodifica la risposta JSON della Bot API.
         *
         * Prima un json_decode diretto; come ripiego l'estrazione JSON robusta del
         * motore AI (Nexus_AI_Engine::extract_json), se disponibile. Ritorna null
         * se la risposta non è recuperabile in nessun modo.
         *
         * @param string $raw Corpo della risposta HTTP.
         * @return array|null
         */
        private static function decode_response( $raw ) {
                $data = json_decode( (string) $raw, true );
                if ( is_array( $data ) ) {
                        return $data;
                }

                if ( class_exists( 'Nexus_AI_Engine' ) && method_exists( 'Nexus_AI_Engine', 'extract_json' ) ) {
                        $parsed = Nexus_AI_Engine::extract_json( (string) $raw );
                        if ( is_array( $parsed ) ) {
                                return $parsed;
                        }
                }

                return null;
        }

        /**
         * Maschera il token completo dentro un messaggio d'errore: nessun segreto
         * in chiaro (contratto: maschera '…' + ultime 4 cifre del token).
         *
         * @param string $error Messaggio d'errore (anche da eccezioni o WP_Error).
         * @param string $token Bot token Telegram.
         * @return string Messaggio sicuro per i log.
         */
        private static function sanitize_error( $error, $token ) {
                $error = sanitize_text_field( (string) $error );
                if ( '' === $token ) {
                        return $error;
                }

                $masked = '…' . substr( $token, -4 );
                $error  = str_replace( $token, $masked, $error );
                $error  = str_replace( rawurlencode( $token ), $masked, $error );

                return $error;
        }

        /**
         * Risultato 'failed' con errore descrittivo e token sempre mascherato.
         *
         * @param string $error Messaggio d'errore.
         * @param string $token Bot token Telegram (per la mascheratura).
         * @return array
         */
        private static function failed( $error, $token ) {
                return self::result( 'failed', '', '', self::sanitize_error( $error, $token ), '' );
        }

        /**
         * Costruisce l'array di ritorno ESATTAMENTE come da contratto del canale.
         *
         * @param string $status   'published'|'failed'|'skipped'.
         * @param string $post_id  ID remoto (message_id Telegram) o ''.
         * @param string $post_url URL remoto o ''.
         * @param string $error    Errore descrittivo o ''.
         * @param string $note     Dettaglio onesto o ''.
         * @return array
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
