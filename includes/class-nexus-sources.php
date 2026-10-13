<?php
/**
 * Nexus Sources — motore fonti multi-tipo (v1.1.10, Task B1).
 *
 * Dispatcher delle FONTI della redazione (term meta _nexus_sources, shape
 * {type,url,query?,full?,profile?}): per ogni fonte chiama l'adapter del tipo
 * (rss|site|youtube|telegram|reddit|search|mcp|web) e restituisce item con lo
 * STESSO shape di Nexus_Rss::fetch_for_agent (title, description, link,
 * source) piu' il campo ADDITIVO 'source_type'.
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Sources::fetch_for_agent( $agent, $max_items = 10 ) — item pronti per
 *   il collect: merge, dedup per link e cap gia' fatti qui. Fonti in errore
 *   vengono ignorate (attivita' 'source_error', level warning). Se l'agente
 *   NON ha fonti redazione si ricade sul comportamento storico
 *   Nexus_Rss::fetch_for_agent (feed globali di categoria, source_type 'rss').
 * Nexus_Sources::clear_cooldown() — azzera cooldown e contatori fallimenti
 *   (per CLI/UI).
 *
 * ECONOMIA FONTI (stato per chiave md5(type|url); i transient 'nexus_src_{md5}'
 * e 'nexus_src_cool_{md5}' sono gia' ripuliti da uninstall):
 * - early-exit (SOLO rss/site/telegram/reddit): transient 'nexus_src_{md5}'
 *   6h = md5(primo item link+title); se identico al run precedente E gli item
 *   totali sono <= 1 → skip (log 'skip' una sola volta per fonte);
 * - cooldown: contatore fallimenti consecutivi in transient
 *   'nexus_src_fail_{md5}' (1h sliding); al 3° fallimento → transient
 *   'nexus_src_cool_{md5}' 1h → skip fetch; il successo azzera.
 *
 * FULL-CONTENT (v1.1.10, WP3): le fonti rss/site con full=1 (chiave additiva
 * gia' gestita dalla sanitize delle redazioni) arricchiscono gli item con il
 * testo completo della pagina, applicato DOPO early-exit/cooldown (fonte
 * saltata = nessun fetch extra): fetch della pagina item['link'] (timeout 12,
 * SSRF guard come l'adapter web), guard anti-binario/SVG, estrazione
 * Nexus_Readability + validazione titolo→contenuto (min 300 caratteri). Se
 * tutto passa: item['description'] = testo PLAIN estratto (clip 6000, meglio
 * dell'HTML per i prompt) e item['full_content'] = 1 (campo additivo);
 * altrimenti l'estratto RSS resta intatto (log 'full_content_fallback' una
 * sola volta). Cache per link: transient 'nexus_full_{md5(link)}' 6h con il
 * testo estratto (evita il doppio fetch tra collect e fact-check); i cache
 * hit NON consumano il budget. Limite economia: max 4 fetch reali full-content
 * per run di fetch_for_agent (FULL_CONTENT_BUDGET), poi skip con log una
 * sola volta. Mai fatale: ogni item degradato torna all'estratto RSS.
 *
 * Sicurezza: le fonti 'telegram' costruiscono l'URL con host whitelist t.me e
 * canale validato [A-Za-z0-9_]; 'reddit' e 'web' validano con
 * Nexus_Tools::assert_safe_url (DNS + IP privati) o fallback http/https +
 * wp_http_validate_url; tutti gli adapter sono difensivi (try/catch Throwable
 * nel dispatcher, wp_remote_* con timeout, mai fatal).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Sources {

        /**
         * Numero massimo di item totali per agente (come Nexus_Rss::MAX_ITEMS).
         */
        const MAX_ITEMS = 10;

        /**
         * TTL early-exit (6 ore).
         */
        const EARLY_EXIT_TTL = 21600;

        /**
         * TTL contatore fallimenti e cooldown (1 ora, sliding per i fail).
         */
        const FAIL_TTL     = 3600;
        const COOLDOWN_TTL = 3600;

        /**
         * Fallimenti consecutivi prima del cooldown.
         */
        const FAIL_THRESHOLD = 3;

        /**
         * Timeout HTTP per gli adapter propri (secondi).
         */
        const HTTP_TIMEOUT = 12;

        /**
         * User-Agent onesto per le richieste dirette (t.me, reddit, youtube).
         */
        const UA = 'Mozilla/5.0 (compatible; NexusNewsAI/1.1.10)';

        /**
         * TTL della cache full-content per link (6 ore, v1.1.10 WP3).
         */
        const FULL_CONTENT_TTL = 21600;

        /**
         * Budget: max fetch reali full-content per run di fetch_for_agent
         * (cache hit esenti: la protezione e' sull'HTTP, non sulla lettura).
         */
        const FULL_CONTENT_BUDGET = 4;

        /**
         * Cap del testo estratto messo in item['description'] (testo plain,
         * migliore dell'HTML per i prompt collect/rewrite).
         */
        const FULL_CONTENT_CLIP = 6000;

        /**
         * Cap del testo conservato nella cache per link (allineato al clip
         * 20k del fact-check v1.1.9: chiusero' potra' riusarla senza refetch).
         */
        const FULL_CONTENT_CACHE_CLIP = 20000;

        /**
         * Budget full-content residuo per il run corrente (reset iniziale in
         * fetch_for_agent, decrementato a ogni fetch reale).
         *
         * @var int
         */
        private static $full_budget_left = 0;

        /**
         * Raccoglie gli item di TUTTE le fonti della redazione dell'agente.
         *
         * Flusso: fonti da Nexus_Redazioni::sources_for_term(term_id dell'agente)
         * → adapter per tipo → merge + dedup per link + cap $max_items, con
         * fair-share: l'ordine delle fonti ruota a ogni chiamata tramite
         * l'option 'nexus_source_cursor' (cursor % count), cursore+1.
         *
         * @param object|array $agent     Oggetto (o array) agente con ->id,
         *                                ->term_id (e ->category per il fallback).
         * @param int          $max_items Cap totale item.
         * @return array Array di item {title, description, link, source, source_type}.
         */
        public static function fetch_for_agent( $agent, $max_items = 10 ) {
                $max_items = max( 1, (int) $max_items );

                // v1.1.10 (WP3): budget full-content di QUESTO run (max 4 fetch reali
                // su tutte le fonti dell'agente; reset a ogni chiamata).
                self::$full_budget_left = self::FULL_CONTENT_BUDGET;

                $term_id = self::agent_term_id( $agent );

                $sources = array();
                if ( $term_id > 0 && class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'sources_for_term' ) ) {
                        $fetched = Nexus_Redazioni::sources_for_term( $term_id );
                        if ( is_array( $fetched ) ) {
                                $sources = $fetched;
                        }
                }

                // Nessuna fonte redazione → comportamento storico: feed globali di categoria.
                if ( empty( $sources ) ) {
                        return self::fallback_category_items( $agent, $max_items );
                }

                // Fair-share: ruota l'ordine fonti con il cursore persistente.
                $count  = count( $sources );
                $cursor = absint( get_option( 'nexus_source_cursor', 0 ) );
                update_option( 'nexus_source_cursor', $cursor + 1, false );
                $offset = $count > 0 ? $cursor % $count : 0;
                if ( $offset > 0 ) {
                        $sources = array_merge( array_slice( $sources, $offset ), array_slice( $sources, 0, $offset ) );
                }

                $items = array();
                $seen  = array();

                foreach ( $sources as $source ) {
                        if ( count( $items ) >= $max_items ) {
                                break;
                        }
                        if ( ! is_array( $source ) ) {
                                continue;
                        }

                        $type   = sanitize_key( (string) ( isset( $source['type'] ) ? $source['type'] : '' ) );
                        $method = 'fetch_' . $type;

                        // Tipi senza adapter possibile → skip + log una sola volta per tipo.
                        if ( '' === $type || ! method_exists( __CLASS__, $method ) ) {
                                self::log_once(
                                        'noadapter_' . $type,
                                        sprintf( 'Fonte tipo "%s" senza adapter disponibile: ignorata.', $type )
                                );
                                continue;
                        }

                        try {
                                $fetched = self::$method( $source );
                        } catch ( Throwable $e ) {
                                self::log(
                                        sprintf(
                                                'Fonte %s (%s) in errore: %s',
                                                $type,
                                                self::source_label( $source ),
                                                self::clip( $e->getMessage(), 160 )
                                        )
                                );
                                continue;
                        }

                        if ( ! is_array( $fetched ) ) {
                                continue;
                        }

                        foreach ( $fetched as $item ) {
                                if ( count( $items ) >= $max_items ) {
                                        break;
                                }
                                if ( ! is_array( $item ) ) {
                                        continue;
                                }
                                $title = trim( (string) ( isset( $item['title'] ) ? $item['title'] : '' ) );
                                if ( '' === $title ) {
                                        continue;
                                }
                                // Shape additivo: source_type garantito su ogni item.
                                if ( empty( $item['source_type'] ) ) {
                                        $item['source_type'] = $type;
                                }
                                $link = trim( (string) ( isset( $item['link'] ) ? $item['link'] : '' ) );
                                $key  = '' !== $link ? 'l:' . strtolower( $link ) : 't:' . md5( $title );
                                if ( isset( $seen[ $key ] ) ) {
                                        continue;
                                }
                                $seen[ $key ] = true;
                                $items[]      = $item;
                        }
                }

                return $items;
        }

        /**
         * Azzera cooldown e contatori fallimenti di tutte le fonti (CLI/UI).
         *
         * @return int Numero di righe transient rimosse (0 se wpdb non disponibile).
         */
        public static function clear_cooldown() {
                global $wpdb;
                if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'esc_like' ) ) {
                        return 0;
                }

                $cool = $wpdb->esc_like( 'nexus_src_cool_' ) . '%';
                $fail = $wpdb->esc_like( 'nexus_src_fail_' ) . '%';

                $deleted = $wpdb->query(
                        $wpdb->prepare(
                                "DELETE FROM {$wpdb->options}
                                 WHERE option_name LIKE %s
                                    OR option_name LIKE %s
                                    OR option_name LIKE %s
                                    OR option_name LIKE %s",
                                '_transient_' . $cool,
                                '_transient_timeout_' . $cool,
                                '_transient_' . $fail,
                                '_transient_timeout_' . $fail
                        )
                );

                return (int) $deleted;
        }

        /* ---------------------------------------------------------------------
         * ADAPTER PER TIPO
         * ------------------------------------------------------------------- */

        /**
         * Fonte 'rss': URL già un feed → Nexus_Rss::fetch_feed_items (shape identico).
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_rss( $source ) {
                $url = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                if ( '' === $url || ! class_exists( 'Nexus_Rss' ) || ! method_exists( 'Nexus_Rss', 'fetch_feed_items' ) ) {
                        return array();
                }

                $key = self::state_key( 'rss', $url );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $items = Nexus_Rss::fetch_feed_items( $url, 5 );
                if ( ! is_array( $items ) ) {
                        $items = array();
                }

                if ( empty( $items ) ) {
                        self::mark_failure( $key, 'rss ' . self::clip( $url, 80 ) );
                        return array();
                }
                if ( self::early_exit( $key, $items, 'rss ' . self::clip( $url, 80 ) ) ) {
                        return array();
                }

                self::mark_success( $key );
                // v1.1.10 (WP3): fonte rss full=1 → arricchimento col testo della pagina.
                return self::maybe_full_content( $source, $items );
        }

        /**
         * Fonte 'site': discovery feed (Nexus_Tools::discover_feeds) poi il primo feed.
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_site( $source ) {
                $url = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                if ( '' === $url ) {
                        return array();
                }

                $key = self::state_key( 'site', $url );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $feed = '';
                if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'discover_feeds' ) ) {
                        $feeds = Nexus_Tools::discover_feeds( $url );
                        if ( is_array( $feeds ) ) {
                                foreach ( $feeds as $candidate ) {
                                        if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                                                $feed = trim( $candidate );
                                                break;
                                        }
                                }
                        }
                }

                if ( '' === $feed || ! class_exists( 'Nexus_Rss' ) || ! method_exists( 'Nexus_Rss', 'fetch_feed_items' ) ) {
                        self::mark_failure( $key, 'site ' . self::clip( $url, 80 ) );
                        return array();
                }

                $items = Nexus_Rss::fetch_feed_items( $feed, 5 );
                if ( ! is_array( $items ) ) {
                        $items = array();
                }

                if ( empty( $items ) ) {
                        self::mark_failure( $key, 'site ' . self::clip( $url, 80 ) );
                        return array();
                }
                if ( self::early_exit( $key, $items, 'site ' . self::clip( $url, 80 ) ) ) {
                        return array();
                }

                self::mark_success( $key );
                // v1.1.10 (WP3): fonte site full=1 → arricchimento col testo della pagina.
                return self::maybe_full_content( $source, $items );
        }

        /**
         * Fonte 'youtube': YouTube Data API v3.
         *
         * Con source['query'] → search endpoint (order=date); altrimenti l'URL è
         * un handle canale (@nome o nome) → channels.list (forHandle) → playlist
         * uploads → playlistItems. API key dal setting youtube_api_key: manca →
         * log una sola volta + array().
         *
         * @param array $source Fonte {type,url,query?}.
         * @return array
         */
        private static function fetch_youtube( $source ) {
                $query = isset( $source['query'] ) ? trim( (string) $source['query'] ) : '';
                $url   = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';

                $key = self::state_key( 'youtube', $url . '|' . $query );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $api_key = self::setting_value( 'youtube_api_key' );
                if ( '' === $api_key ) {
                        self::log_once( 'yt_nokey', 'YouTube: API key non impostata (setting youtube_api_key): fonte ignorata.' );
                        return array();
                }

                $items = array();

                if ( '' !== $query ) {
                        // Ricerca video recenti per query.
                        $endpoint = 'https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&order=date&maxResults=8'
                                . '&q=' . rawurlencode( $query )
                                . '&key=' . rawurlencode( $api_key );
                        $data = self::http_json( $endpoint );
                        if ( ! is_array( $data ) ) {
                                self::mark_failure( $key, 'youtube query ' . self::clip( $query, 60 ) );
                                return array();
                        }
                        $videos = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
                        foreach ( $videos as $video ) {
                                if ( count( $items ) >= 8 ) {
                                        break;
                                }
                                $snippet = is_array( $video ) && isset( $video['snippet'] ) && is_array( $video['snippet'] ) ? $video['snippet'] : array();
                                $video_id = is_array( $video ) && isset( $video['id']['videoId'] ) ? (string) $video['id']['videoId'] : '';
                                $item     = self::youtube_item( $snippet, $video_id );
                                if ( null !== $item ) {
                                        $items[] = $item;
                                }
                        }
                } else {
                        // Handle canale → uploads playlist → ultimi video.
                        $handle = self::youtube_handle( $url );
                        if ( '' === $handle ) {
                                self::log( sprintf( 'YouTube: handle canale non riconosciuto da "%s".', self::clip( $url, 80 ) ) );
                                return array();
                        }

                        $channel_endpoint = 'https://www.googleapis.com/youtube/v3/channels?part=contentDetails'
                                . '&forHandle=' . rawurlencode( $handle )
                                . '&key=' . rawurlencode( $api_key );
                        $channel = self::http_json( $channel_endpoint );
                        $uploads = '';
                        if ( is_array( $channel ) && ! empty( $channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ) ) {
                                $uploads = (string) $channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'];
                        }
                        if ( '' === $uploads ) {
                                self::mark_failure( $key, 'youtube @' . self::clip( $handle, 60 ) );
                                return array();
                        }

                        $playlist_endpoint = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=8'
                                . '&playlistId=' . rawurlencode( $uploads )
                                . '&key=' . rawurlencode( $api_key );
                        $data = self::http_json( $playlist_endpoint );
                        if ( ! is_array( $data ) ) {
                                self::mark_failure( $key, 'youtube @' . self::clip( $handle, 60 ) );
                                return array();
                        }
                        $videos = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
                        foreach ( $videos as $video ) {
                                if ( count( $items ) >= 8 ) {
                                        break;
                                }
                                $snippet  = is_array( $video ) && isset( $video['snippet'] ) && is_array( $video['snippet'] ) ? $video['snippet'] : array();
                                $video_id = isset( $snippet['resourceId']['videoId'] ) ? (string) $snippet['resourceId']['videoId'] : '';
                                $item     = self::youtube_item( $snippet, $video_id );
                                if ( null !== $item ) {
                                        $items[] = $item;
                                }
                        }
                }

                if ( empty( $items ) ) {
                        self::mark_failure( $key, 'youtube ' . self::clip( '' !== $query ? $query : $url, 60 ) );
                        return array();
                }

                self::mark_success( $key );
                return $items;
        }

        /**
         * Normalizza un video YouTube in item (shape comune a search e playlistItems).
         *
         * @param array  $snippet  Snippet API.
         * @param string $video_id ID video.
         * @return array|null
         */
        private static function youtube_item( $snippet, $video_id ) {
                $video_id = trim( (string) $video_id );
                $title    = trim( (string) ( isset( $snippet['title'] ) ? $snippet['title'] : '' ) );
                if ( '' === $video_id || '' === $title ) {
                        return null;
                }

                $channel = trim( (string) ( isset( $snippet['channelTitle'] ) ? $snippet['channelTitle'] : '' ) );
                $descr   = trim( (string) ( isset( $snippet['description'] ) ? $snippet['description'] : '' ) );

                return array(
                        'title'       => self::clip( $title, 200 ),
                        'description' => self::clip( preg_replace( '/\s+/', ' ', $descr ), 400 ),
                        'link'        => 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ),
                        'source'      => 'YouTube: ' . ( '' !== $channel ? $channel : 'canale' ),
                        'source_type' => 'youtube',
                );
        }

        /**
         * Estrae l'handle canale da un URL/valore YouTube (@nome, nome, URL /@nome,
         * /c/nome, /user/nome; gli ID canale UC... non sono gestiti da forHandle).
         *
         * @param string $url Valore fonte.
         * @return string
         */
        private static function youtube_handle( $url ) {
                $value = trim( (string) $url );
                if ( '' === $value ) {
                        return '';
                }

                if ( preg_match( '#^https?://#i', $value ) ) {
                        $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
                        if ( '' === $host || false === strpos( $host, 'youtube.com' ) ) {
                                return '';
                        }
                        $path  = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
                        $parts = explode( '/', $path );
                        $first = isset( $parts[0] ) ? $parts[0] : '';
                        if ( '' === $first ) {
                                return '';
                        }
                        if ( '@' === substr( $first, 0, 1 ) ) {
                                return ltrim( $first, '@' );
                        }
                        if ( ( 'c' === $first || 'user' === $first ) && ! empty( $parts[1] ) ) {
                                return $parts[1];
                        }
                        return '';
                }

                return ltrim( $value, '@' );
        }

        /**
         * Fonte 'telegram': canale pubblico via https://t.me/s/{canale} (host
         * whitelist costruito da noi, canale validato: nessun URL arbitrario).
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_telegram( $source ) {
                $raw     = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                $channel = self::telegram_channel( $raw );
                if ( '' === $channel || ! preg_match( '/^[A-Za-z0-9_]{3,64}$/', $channel ) ) {
                        self::log( sprintf( 'Telegram: canale non valido "%s".', self::clip( $raw, 60 ) ) );
                        return array();
                }

                $key = self::state_key( 'telegram', $channel );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $endpoint = 'https://t.me/s/' . rawurlencode( $channel );
                $res      = wp_remote_get(
                        $endpoint,
                        array(
                                'timeout'    => self::HTTP_TIMEOUT,
                                'user-agent' => self::UA,
                                'headers'    => array( 'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8' ),
                        )
                );
                if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                        self::mark_failure( $key, 'telegram @' . $channel );
                        return array();
                }

                $body = (string) wp_remote_retrieve_body( $res );
                if ( '' === trim( $body ) ) {
                        self::mark_failure( $key, 'telegram @' . $channel );
                        return array();
                }

                // Blocchi messaggio: split sul div di apertura di ogni messaggio.
                $items  = array();
                $chunks = preg_split( '#<div class="tgme_widget_message\b#', $body );
                if ( is_array( $chunks ) ) {
                        foreach ( $chunks as $chunk ) {
                                if ( count( $items ) >= 8 ) {
                                        break;
                                }
                                if ( ! preg_match( '/data-post="([^"]+)"/', (string) $chunk, $m ) ) {
                                        continue;
                                }
                                $post = trim( $m[1] ); // "canale/id".
                                if ( '' === $post || false === strpos( $post, '/' ) ) {
                                        continue;
                                }
                                if ( ! preg_match( '#<div class="tgme_widget_message_text[^>]*>(.*?)</div>#s', (string) $chunk, $t ) ) {
                                        continue;
                                }
                                $text = self::html_text( $t[1] );
                                if ( '' === $text ) {
                                        continue;
                                }
                                $lines = explode( "\n", $text );
                                $first = trim( (string) $lines[0] );

                                $items[] = array(
                                        'title'       => self::clip( '' !== $first ? $first : $text, 120 ),
                                        'description' => self::clip( $text, 400 ),
                                        'link'        => 'https://t.me/' . $post,
                                        'source'      => 'Telegram: @' . $channel,
                                        'source_type' => 'telegram',
                                );
                        }
                }

                if ( empty( $items ) ) {
                        self::mark_failure( $key, 'telegram @' . $channel );
                        return array();
                }
                if ( self::early_exit( $key, $items, 'telegram @' . $channel ) ) {
                        return array();
                }

                self::mark_success( $key );
                return $items;
        }

        /**
         * Canale Telegram da "@canale", "canale" o URL t.me/telegram.me (host
         * whitelist: l'URL finale è sempre costruito da noi su t.me).
         *
         * @param string $raw Valore fonte.
         * @return string
         */
        private static function telegram_channel( $raw ) {
                $value = trim( ltrim( trim( (string) $raw ), '@' ) );
                if ( '' === $value ) {
                        return '';
                }

                if ( preg_match( '#^https?://#i', $value ) ) {
                        $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
                        if ( 't.me' !== $host && 'telegram.me' !== $host ) {
                                return '';
                        }
                        $path  = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
                        $parts = explode( '/', $path );
                        $value = isset( $parts[0] ) ? $parts[0] : '';
                        if ( 's' === strtolower( $value ) && ! empty( $parts[1] ) ) {
                                $value = $parts[1];
                        }
                }

                return trim( ltrim( $value, '@' ) );
        }

        /**
         * Fonte 'reddit': subreddit. Con reddit_client_id+secret → OAuth
         * client_credentials (token cache 23h) + oauth.reddit.com/r/{sub}/new;
         * altrimenti fallback pubblico https://www.reddit.com/r/{sub}/.rss
         * (Atom, parse con SimpleXMLElement).
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_reddit( $source ) {
                $raw = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                $sub = self::reddit_sub( $raw );
                if ( '' === $sub || ! preg_match( '/^[A-Za-z0-9_]{2,21}$/', $sub ) ) {
                        self::log( sprintf( 'Reddit: subreddit non valido "%s".', self::clip( $raw, 60 ) ) );
                        return array();
                }

                $key = self::state_key( 'reddit', $sub );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $items = array();

                $client_id = self::setting_value( 'reddit_client_id' );
                $secret    = self::setting_value( 'reddit_client_secret' );
                if ( '' !== $client_id && '' !== $secret ) {
                        $token = self::reddit_token( $client_id, $secret );
                        if ( '' !== $token ) {
                                $endpoint = 'https://oauth.reddit.com/r/' . rawurlencode( $sub ) . '/new?limit=10';
                                $res      = wp_remote_get(
                                        $endpoint,
                                        array(
                                                'timeout'    => self::HTTP_TIMEOUT,
                                                'user-agent' => self::UA,
                                                'headers'    => array( 'Authorization' => 'Bearer ' . $token ),
                                        )
                                );
                                if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                                        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
                                        if ( is_array( $data ) && ! empty( $data['data']['children'] ) && is_array( $data['data']['children'] ) ) {
                                                foreach ( $data['data']['children'] as $child ) {
                                                        if ( count( $items ) >= 10 ) {
                                                                break;
                                                        }
                                                        $post = is_array( $child ) && isset( $child['data'] ) && is_array( $child['data'] ) ? $child['data'] : array();
                                                        $title = trim( (string) ( isset( $post['title'] ) ? $post['title'] : '' ) );
                                                        if ( '' === $title ) {
                                                                continue;
                                                        }
                                                        $permalink = trim( (string) ( isset( $post['permalink'] ) ? $post['permalink'] : '' ) );
                                                        $selftext  = trim( (string) ( isset( $post['selftext'] ) ? $post['selftext'] : '' ) );
                                                        $items[]   = array(
                                                                'title'       => self::clip( $title, 200 ),
                                                                'description' => self::clip( preg_replace( '/\s+/', ' ', '' !== $selftext ? $selftext : $title ), 400 ),
                                                                'link'        => esc_url_raw( 'https://www.reddit.com' . $permalink ),
                                                                'source'      => 'Reddit: r/' . $sub,
                                                                'source_type' => 'reddit',
                                                        );
                                                }
                                        }
                                }
                        }
                }

                // Fallback pubblico .rss (senza OAuth o se la via OAuth non ha dato item).
                if ( empty( $items ) ) {
                        $home = function_exists( 'home_url' ) ? home_url() : '';
                        $res  = wp_remote_get(
                                'https://www.reddit.com/r/' . rawurlencode( $sub ) . '/.rss',
                                array(
                                        'timeout'    => self::HTTP_TIMEOUT,
                                        'user-agent' => 'Nexus News AI/1.1.10 (WordPress; ' . $home . ')',
                                )
                        );
                        if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                                $items = self::reddit_parse_atom( (string) wp_remote_retrieve_body( $res ), $sub );
                        }
                }

                if ( empty( $items ) ) {
                        self::mark_failure( $key, 'reddit r/' . $sub );
                        return array();
                }
                if ( self::early_exit( $key, $items, 'reddit r/' . $sub ) ) {
                        return array();
                }

                self::mark_success( $key );
                return $items;
        }

        /**
         * Subreddit da "r/sub", "sub" o URL reddit.
         *
         * @param string $raw Valore fonte.
         * @return string
         */
        private static function reddit_sub( $raw ) {
                $value = trim( (string) $raw );
                if ( preg_match( '#^https?://#i', $value ) ) {
                        $path  = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
                        $parts = explode( '/', $path );
                        if ( isset( $parts[0] ) && 'r' === strtolower( $parts[0] ) && ! empty( $parts[1] ) ) {
                                $value = $parts[1];
                        } elseif ( isset( $parts[0] ) ) {
                                $value = $parts[0];
                        }
                }
                $value = preg_replace( '#^r/#i', '', ltrim( $value, '/' ) );
                return trim( (string) $value );
        }

        /**
         * Token OAuth reddit (client_credentials) con cache transient 23 ore.
         *
         * @param string $client_id Client ID.
         * @param string $secret    Client Secret.
         * @return string Token ('' se fallito).
         */
        private static function reddit_token( $client_id, $secret ) {
                $cached = get_transient( 'nexus_reddit_token' );
                if ( is_string( $cached ) && '' !== $cached ) {
                        return $cached;
                }

                $res = wp_remote_post(
                        'https://www.reddit.com/api/v1/access_token',
                        array(
                                'timeout'    => self::HTTP_TIMEOUT,
                                'user-agent' => self::UA,
                                'headers'    => array(
                                        'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
                                ),
                                'body'       => array( 'grant_type' => 'client_credentials' ),
                        )
                );
                if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                        return '';
                }

                $data  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
                $token = is_array( $data ) && isset( $data['access_token'] ) ? trim( (string) $data['access_token'] ) : '';
                if ( '' !== $token ) {
                        set_transient( 'nexus_reddit_token', $token, 23 * HOUR_IN_SECONDS );
                }
                return $token;
        }

        /**
         * Parse del feed Atom pubblico reddit (.rss) con SimpleXMLElement.
         *
         * @param string $body Corpo XML.
         * @param string $sub  Subreddit (per il nome sorgente).
         * @return array
         */
        private static function reddit_parse_atom( $body, $sub ) {
                if ( '' === trim( (string) $body ) || ! class_exists( 'SimpleXMLElement' ) ) {
                        return array();
                }

                $prev = libxml_use_internal_errors( true );
                $xml  = @simplexml_load_string( $body );
                libxml_clear_errors();
                libxml_use_internal_errors( $prev );

                if ( ! $xml || ! isset( $xml->entry ) ) {
                        return array();
                }

                $items = array();
                foreach ( $xml->entry as $entry ) {
                        if ( count( $items ) >= 10 ) {
                                break;
                        }
                        $title = trim( (string) $entry->title );
                        $link  = '';
                        if ( isset( $entry->link ) ) {
                                $link = trim( (string) $entry->link['href'] );
                                if ( '' === $link ) {
                                        $link = trim( (string) $entry->link );
                                }
                        }
                        if ( '' === $title || '' === $link ) {
                                continue;
                        }
                        $content = isset( $entry->content ) ? self::html_text( (string) $entry->content ) : '';

                        $items[] = array(
                                'title'       => self::clip( $title, 200 ),
                                'description' => self::clip( '' !== $content ? $content : $title, 400 ),
                                'link'        => esc_url_raw( $link ),
                                'source'      => 'Reddit: r/' . $sub,
                                'source_type' => 'reddit',
                        );
                }

                return $items;
        }

        /**
         * Fonte 'search': provider di ricerca web configurato (tavily|serper|brave)
         * con tools_search_provider/tools_search_key. Logica MINIMA dedicata (la
         * Nexus_Tools::search_web_raw è privata e ha shape per i tool AI).
         * Nessun fetch della pagina: solo i metadati del risultato.
         *
         * @param array $source Fonte {type,url?,query?}.
         * @return array
         */
        private static function fetch_search( $source ) {
                $query = isset( $source['query'] ) ? trim( (string) $source['query'] ) : '';
                if ( '' === $query && isset( $source['url'] ) ) {
                        $query = trim( (string) $source['url'] );
                }
                if ( '' === $query ) {
                        return array();
                }

                $provider = sanitize_key( self::setting_value( 'tools_search_provider' ) );
                if ( '' === $provider ) {
                        $provider = 'tavily';
                }
                if ( 'none' === $provider ) {
                        self::log_once( 'search_none', 'Search: provider di ricerca impostato su "none": fonte ignorata.' );
                        return array();
                }

                $api_key = self::setting_value( 'tools_search_key' );
                if ( '' === $api_key ) {
                        self::log_once( 'search_nokey', 'Search: chiave API non impostata (setting tools_search_key): fonte ignorata.' );
                        return array();
                }

                $items = array();

                if ( 'tavily' === $provider ) {
                        $res = wp_remote_post(
                                'https://api.tavily.com/search',
                                array(
                                        'timeout'    => self::HTTP_TIMEOUT,
                                        'user-agent' => self::UA,
                                        'headers'    => array( 'Content-Type' => 'application/json' ),
                                        'body'       => (string) wp_json_encode(
                                                array(
                                                        'api_key'     => $api_key,
                                                        'query'       => $query,
                                                        'max_results' => 8,
                                                )
                                        ),
                                )
                        );
                        $data = self::json_body( $res );
                        if ( is_array( $data ) && ! empty( $data['results'] ) && is_array( $data['results'] ) ) {
                                foreach ( $data['results'] as $result ) {
                                        if ( count( $items ) >= 8 ) {
                                                break;
                                        }
                                        $item = self::search_item( $result, 'title', 'url', 'content', $provider );
                                        if ( null !== $item ) {
                                                $items[] = $item;
                                        }
                                }
                        }
                } elseif ( 'serper' === $provider ) {
                        $res = wp_remote_post(
                                'https://google.serper.dev/search',
                                array(
                                        'timeout'    => self::HTTP_TIMEOUT,
                                        'user-agent' => self::UA,
                                        'headers'    => array(
                                                'X-API-KEY'   => $api_key,
                                                'Content-Type' => 'application/json',
                                        ),
                                        'body'       => (string) wp_json_encode( array( 'q' => $query ) ),
                                )
                        );
                        $data = self::json_body( $res );
                        if ( is_array( $data ) && ! empty( $data['organic'] ) && is_array( $data['organic'] ) ) {
                                foreach ( $data['organic'] as $result ) {
                                        if ( count( $items ) >= 8 ) {
                                                break;
                                        }
                                        $item = self::search_item( $result, 'title', 'link', 'snippet', $provider );
                                        if ( null !== $item ) {
                                                $items[] = $item;
                                        }
                                }
                        }
                } elseif ( 'brave' === $provider ) {
                        $res = wp_remote_get(
                                'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode( $query ),
                                array(
                                        'timeout'    => self::HTTP_TIMEOUT,
                                        'user-agent' => self::UA,
                                        'headers'    => array(
                                                'X-Subscription-Token' => $api_key,
                                                'Accept'               => 'application/json',
                                        ),
                                )
                        );
                        $data = self::json_body( $res );
                        if ( is_array( $data ) && ! empty( $data['web']['results'] ) && is_array( $data['web']['results'] ) ) {
                                foreach ( $data['web']['results'] as $result ) {
                                        if ( count( $items ) >= 8 ) {
                                                break;
                                        }
                                        $item = self::search_item( $result, 'title', 'url', 'description', $provider );
                                        if ( null !== $item ) {
                                                $items[] = $item;
                                        }
                                }
                        }
                } else {
                        self::log_once( 'search_provider_' . $provider, sprintf( 'Search: provider "%s" non supportato.', $provider ) );
                        return array();
                }

                if ( empty( $items ) ) {
                        self::log( sprintf( 'Search (%s): nessun risultato per "%s".', $provider, self::clip( $query, 80 ) ) );
                }

                return $items;
        }

        /**
         * Normalizza un risultato di ricerca (chiavi diverse per provider).
         *
         * @param array  $result       Risultato provider.
         * @param string $title_key    Chiave titolo.
         * @param string $link_key     Chiave URL.
         * @param string $content_key  Chiave descrizione.
         * @param string $provider     Nome provider.
         * @return array|null
         */
        private static function search_item( $result, $title_key, $link_key, $content_key, $provider ) {
                if ( ! is_array( $result ) ) {
                        return null;
                }
                $title = trim( (string) ( isset( $result[ $title_key ] ) ? $result[ $title_key ] : '' ) );
                $link  = trim( (string) ( isset( $result[ $link_key ] ) ? $result[ $link_key ] : '' ) );
                if ( '' === $title || '' === $link ) {
                        return null;
                }
                $content = trim( (string) ( isset( $result[ $content_key ] ) ? $result[ $content_key ] : '' ) );

                return array(
                        'title'       => self::clip( $title, 200 ),
                        'description' => self::clip( preg_replace( '/\s+/', ' ', $content ), 400 ),
                        'link'        => esc_url_raw( $link ),
                        'source'      => 'Search: ' . $provider,
                        'source_type' => 'search',
                );
        }

        /**
         * Fonte 'mcp': url formato 'server|tool|args_json'. Il server viene
         * risolto da Nexus_Tools::mcp_call (PUBLIC in v1.1.10) che legge i server
         * configurati (mcp_servers_text: nome|url|token).
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_mcp( $source ) {
                $spec  = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                $parts = explode( '|', $spec, 3 );
                $server = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
                $tool   = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
                $args   = array();
                if ( isset( $parts[2] ) && '' !== trim( (string) $parts[2] ) ) {
                        $decoded = json_decode( (string) $parts[2], true );
                        if ( is_array( $decoded ) ) {
                                $args = $decoded;
                        }
                }
                if ( '' === $server || '' === $tool ) {
                        self::log( sprintf( 'MCP: specifica fonte non valida "%s".', self::clip( $spec, 80 ) ) );
                        return array();
                }

                if ( ! class_exists( 'Nexus_Tools' ) || ! method_exists( 'Nexus_Tools', 'mcp_call' ) ) {
                        self::log_once( 'mcp_notools', 'MCP: Nexus_Tools::mcp_call non disponibile: fonte MCP ignorata.' );
                        return array();
                }

                $res = Nexus_Tools::mcp_call( $server, $tool, $args );
                if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
                        $error = is_array( $res ) && isset( $res['error'] ) ? (string) $res['error'] : 'chiamata MCP fallita';
                        self::log( sprintf( 'MCP %s.%s: %s', $server, $tool, self::clip( $error, 160 ) ) );
                        return array();
                }

                $data = isset( $res['data'] ) ? $res['data'] : null;
                $text = self::mcp_text( $data );
                if ( '' === trim( $text ) ) {
                        self::log( sprintf( 'MCP %s.%s: risposta vuota.', $server, $tool ) );
                        return array();
                }

                return array(
                        array(
                                'title'       => sprintf( 'MCP: %s.%s', $server, $tool ),
                                'description' => self::clip( $text, 400 ),
                                'link'        => '',
                                'source'      => sprintf( 'MCP: %s', $server ),
                                'source_type' => 'mcp',
                        ),
                );
        }

        /**
         * Estrae testo leggibile dalla risposta MCP (content[].text, stringa o JSON).
         *
         * @param mixed $data Dati risposta.
         * @return string
         */
        private static function mcp_text( $data ) {
                if ( is_string( $data ) ) {
                        return self::clip( $data, 1000 );
                }
                if ( is_array( $data ) ) {
                        if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
                                $texts = array();
                                foreach ( $data['content'] as $chunk ) {
                                        if ( is_array( $chunk ) && isset( $chunk['text'] ) ) {
                                                $texts[] = (string) $chunk['text'];
                                        } elseif ( is_string( $chunk ) ) {
                                                $texts[] = $chunk;
                                        }
                                }
                                if ( ! empty( $texts ) ) {
                                        return self::clip( implode( "\n", $texts ), 1000 );
                                }
                        }
                        if ( isset( $data['text'] ) && is_string( $data['text'] ) ) {
                                return self::clip( $data['text'], 1000 );
                        }
                        $json = wp_json_encode( $data );
                        return is_string( $json ) ? self::clip( $json, 1000 ) : '';
                }
                return '';
        }

        /**
         * Fonte 'web': pagina singola → title + testo pulito. SSRF: preferisce
         * Nexus_Tools::assert_safe_url (DNS + IP privati), altrimenti fallback
         * http/https + wp_http_validate_url. Guardie Nexus_Readability (binario/SVG,
         * lazy/URL relativi) se la classe è presente.
         *
         * @param array $source Fonte {type,url}.
         * @return array
         */
        private static function fetch_web( $source ) {
                $url = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
                if ( '' === $url ) {
                        return array();
                }

                if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'assert_safe_url' ) ) {
                        $safe = Nexus_Tools::assert_safe_url( $url );
                        if ( is_wp_error( $safe ) ) {
                                self::log( sprintf( 'Web: URL non consentito "%s".', self::clip( $url, 80 ) ) );
                                return array();
                        }
                } else {
                        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
                        if ( ! in_array( $scheme, array( 'http', 'https' ), true )
                                || ! function_exists( 'wp_http_validate_url' )
                                || ! wp_http_validate_url( $url ) ) {
                                self::log( sprintf( 'Web: URL non valido "%s".', self::clip( $url, 80 ) ) );
                                return array();
                        }
                }

                $key = self::state_key( 'web', $url );
                if ( self::is_cooled( $key ) ) {
                        return array();
                }

                $res = wp_remote_get(
                        $url,
                        array(
                                'timeout'    => self::HTTP_TIMEOUT,
                                'user-agent' => self::UA,
                                'headers'    => array( 'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8' ),
                        )
                );
                if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                        self::mark_failure( $key, 'web ' . self::clip( $url, 80 ) );
                        return array();
                }

                $body = (string) wp_remote_retrieve_body( $res );
                if ( '' === trim( $body ) ) {
                        self::mark_failure( $key, 'web ' . self::clip( $url, 80 ) );
                        return array();
                }

                if ( class_exists( 'Nexus_Readability' ) ) {
                        if ( method_exists( 'Nexus_Readability', 'is_svg_or_binary_guard' ) && Nexus_Readability::is_svg_or_binary_guard( $body ) ) {
                                self::mark_failure( $key, 'web ' . self::clip( $url, 80 ) );
                                return array();
                        }
                        if ( method_exists( 'Nexus_Readability', 'cleanup_lazy_and_relative' ) ) {
                                $cleaned = Nexus_Readability::cleanup_lazy_and_relative( $body, $url );
                                if ( is_string( $cleaned ) && '' !== $cleaned ) {
                                        $body = $cleaned;
                                }
                        }
                }

                $title = '';
                if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ) {
                        $title = self::html_text( $m[1] );
                }
                $text = self::html_text( $body );
                if ( '' === $text ) {
                        self::mark_failure( $key, 'web ' . self::clip( $url, 80 ) );
                        return array();
                }

                $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                $host = preg_replace( '/^www\./', '', (string) $host );

                self::mark_success( $key );

                return array(
                        array(
                                // Testo estratto cap 1200; la description resta nello shape standard (clip 400).
                                'title'       => self::clip( '' !== $title ? $title : $text, 200 ),
                                'description' => self::clip( self::clip( $text, 1200 ), 400 ),
                                'link'        => esc_url_raw( $url ),
                                'source'      => '' !== $host ? $host : self::clip( $url, 60 ),
                                'source_type' => 'web',
                        ),
                );
        }

        /* ---------------------------------------------------------------------
         * FULL-CONTENT (v1.1.10, WP3) — solo per le fonti rss/site con full=1
         * ------------------------------------------------------------------- */

        /**
         * Arricchisce gli item di una fonte full=1 con il testo completo della
         * pagina, quando la classe Nexus_Readability è disponibile.
         *
         * Per ogni item con link, finché c'è budget: cache transient
         * 'nexus_full_{md5(link)}' 6h (hit → nessun fetch e nessun consumo di
         * budget), altrimenti SSRF guard (stesso pattern dell'adapter web),
         * fetch wp_remote_get (timeout 12, redirect 2, risposta cap 500KB),
         * guard anti-binario/SVG, estrazione Nexus_Readability::extract() e
         * validazione titolo→contenuto (min 300 caratteri). Se tutto passa:
         * item['description'] = testo PLAIN estratto (clip 6000) e
         * item['full_content'] = 1 (campo additivo, v1.1.10); altrimenti la
         * description RSS resta intatta (log 'full_content_fallback' una sola
         * volta ogni 12h). Ogni fetch REALE consuma 1 unità del budget per run
         * (anche se fallisce: la protezione è sull'HTTP); finito il budget si
         * salta con log una sola volta. Il chiamante (adapter rss/site) NON
         * invoca questo metodo per fonti in early-exit/cooldown: lì ritorna
         * array() prima di arrivare qui.
         *
         * Cache: viene conservato il testo estratto clip a 20000 caratteri
         * (stesso clip del fact-check v1.1.9: un'eventuale integrazione
         * futura può riusarla senza rifare il fetch della pagina).
         *
         * @param array $source Fonte {type,url,full?}.
         * @param array $items  Item della fonte (shape {title,description,link,source}).
         * @return array Item (array stesso, description eventualmente arricchita).
         */
        private static function maybe_full_content( $source, $items ) {
                if ( empty( $source['full'] ) || empty( $items ) || ! is_array( $items ) ) {
                        return $items;
                }

                if ( ! class_exists( 'Nexus_Readability' )
                        || ! method_exists( 'Nexus_Readability', 'extract' )
                        || ! method_exists( 'Nexus_Readability', 'validate' ) ) {
                        self::log_once(
                                'full_no_readability',
                                'Full-content: Nexus_Readability non disponibile: le fonti full=1 sono servite con l\'estratto RSS.'
                        );
                        return $items;
                }

                foreach ( $items as $idx => $item ) {
                        if ( ! is_array( $item ) ) {
                                continue;
                        }
                        $link = esc_url_raw( trim( (string) ( isset( $item['link'] ) ? $item['link'] : '' ) ) );
                        if ( '' === $link ) {
                                continue;
                        }

                        // Cache 6h per link: hit → descrizione full senza fetch (budget intatto).
                        $cache_key = 'nexus_full_' . md5( $link );
                        $cached    = get_transient( $cache_key );
                        if ( is_string( $cached ) && '' !== trim( $cached ) ) {
                                $items[ $idx ]['description'] = self::clip( $cached, self::FULL_CONTENT_CLIP );
                                $items[ $idx ]['full_content'] = 1;
                                continue;
                        }

                        // Economia: budget del run esaurito → skip (log una sola volta).
                        if ( self::$full_budget_left <= 0 ) {
                                self::log_once(
                                        'full_budget',
                                        sprintf( 'Full-content: budget di %d fetch per run esaurito: item restanti serviti con l\'estratto RSS.', self::FULL_CONTENT_BUDGET )
                                );
                                break;
                        }

                        // SSRF: stesso pattern dell'adapter web (assert_safe_url o fallback validatore).
                        if ( ! self::url_is_safe( $link ) ) {
                                self::log_once(
                                        'full_content_fallback',
                                        sprintf( 'Full-content: URL item non consentito (%s): estratto RSS mantenuto.', self::clip( $link, 100 ) )
                                );
                                continue;
                        }

                        // Ogni fetch reale consuma il budget, anche se poi fallisce.
                        self::$full_budget_left--;

                        $res = wp_remote_get(
                                $link,
                                array(
                                        'timeout'             => self::HTTP_TIMEOUT,
                                        'redirection'         => 2,
                                        'limit_response_size' => 524288, // 500 KB: più che sufficiente per un articolo.
                                        'user-agent'          => self::UA,
                                        'headers'             => array( 'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8' ),
                                )
                        );
                        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                                self::log_once(
                                        'full_content_fallback',
                                        sprintf( 'Full-content: pagina non raggiungibile (%s): estratto RSS mantenuto.', self::clip( $link, 100 ) )
                                );
                                continue;
                        }

                        $body = (string) wp_remote_retrieve_body( $res );
                        if ( '' === trim( $body )
                                || ( method_exists( 'Nexus_Readability', 'is_svg_or_binary_guard' )
                                        && ! Nexus_Readability::is_svg_or_binary_guard( $body ) ) ) {
                                self::log_once(
                                        'full_content_fallback',
                                        sprintf( 'Full-content: risposta non è HTML testuale (%s): estratto RSS mantenuto.', self::clip( $link, 100 ) )
                                );
                                continue;
                        }

                        $extract = Nexus_Readability::extract( $body, $link );
                        $ok      = is_array( $extract ) && ! empty( $extract['ok'] )
                                && '' !== trim( (string) ( isset( $extract['text'] ) ? $extract['text'] : '' ) );

                        if ( $ok ) {
                                // Validazione titolo→contenuto sul HTML estratto (la classe
                                // fa da sé il plain): min 300 caratteri di testo.
                                $valid = Nexus_Readability::validate(
                                        (string) ( isset( $item['title'] ) ? $item['title'] : '' ),
                                        (string) ( isset( $extract['content'] ) ? $extract['content'] : '' ),
                                        300
                                );
                                $ok = is_array( $valid ) && ! empty( $valid['ok'] );
                        }

                        if ( ! $ok ) {
                                // Description RSS intatta: la pagina non è utilizzabile
                                // (paywall, pagina vuota, contenuto non pertinente...).
                                self::log_once(
                                        'full_content_fallback',
                                        sprintf( 'Full-content: estrazione non valida da %s: estratto RSS mantenuto.', self::clip( $link, 100 ) )
                                );
                                continue;
                        }

                        $text = self::clip( trim( (string) $extract['text'] ), self::FULL_CONTENT_CACHE_CLIP );
                        set_transient( $cache_key, $text, self::FULL_CONTENT_TTL );

                        $items[ $idx ]['description']  = self::clip( $text, self::FULL_CONTENT_CLIP );
                        $items[ $idx ]['full_content'] = 1;
                }

                return $items;
        }

        /**
         * SSRF guard condivisa per i fetch di pagine esterne (stesso pattern
         * dell'adapter 'web'): preferisce Nexus_Tools::assert_safe_url (DNS +
         * blocco IP privati/riservati), altrimenti fallback http/https +
         * wp_http_validate_url.
         *
         * @param string $url URL da verificare.
         * @return bool True se il fetch è consentito.
         */
        private static function url_is_safe( $url ) {
                if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'assert_safe_url' ) ) {
                        return ! is_wp_error( Nexus_Tools::assert_safe_url( $url ) );
                }

                $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
                return in_array( $scheme, array( 'http', 'https' ), true )
                        && function_exists( 'wp_http_validate_url' )
                        && (bool) wp_http_validate_url( $url );
        }

        /* ---------------------------------------------------------------------
         * ECONOMIA FONTI (early-exit, cooldown) E HELPER
         * ------------------------------------------------------------------- */

        /**
         * Chiave stato fonte: md5(type|url).
         *
         * @param string $type Tipo fonte.
         * @param string $url  URL (o identificativo) fonte.
         * @return string
         */
        private static function state_key( $type, $url ) {
                return md5( $type . '|' . trim( (string) $url ) );
        }

        /**
         * Early-exit (SOLO rss/site/telegram/reddit): se il primo item è identico
         * al run precedente (transient 'nexus_src_{key}' 6h) E gli item totali sono
         * <= 1 → skip. L'hash nuovo viene SEMPRE salvato.
         *
         * @param string $key   Chiave stato fonte.
         * @param array  $items Item della fonte.
         * @param string $label Label per il log.
         * @return bool True se la fonte va saltata.
         */
        private static function early_exit( $key, $items, $label ) {
                if ( empty( $items ) || ! is_array( $items ) ) {
                        return false;
                }

                $first = $items[0];
                if ( ! is_array( $first ) ) {
                        return false;
                }
                $hash = md5(
                        (string) ( isset( $first['link'] ) ? $first['link'] : '' ) . '|' . (string) ( isset( $first['title'] ) ? $first['title'] : '' )
                );

                $old = get_transient( 'nexus_src_' . $key );
                set_transient( 'nexus_src_' . $key, $hash, self::EARLY_EXIT_TTL );

                if ( $hash === $old && count( $items ) <= 1 ) {
                        self::log_once(
                                'skip_' . $key,
                                sprintf( 'Fonte %s senza novita\' (early-exit 6h): skip.', $label )
                        );
                        return true;
                }

                return false;
        }

        /**
         * Fonte in cooldown (3 fallimenti consecutivi → 1h)?
         *
         * @param string $key Chiave stato fonte.
         * @return bool
         */
        private static function is_cooled( $key ) {
                return (bool) get_transient( 'nexus_src_cool_' . $key );
        }

        /**
         * Registra un successo: azzera il contatore fallimenti.
         *
         * @param string $key Chiave stato fonte.
         * @return void
         */
        private static function mark_success( $key ) {
                delete_transient( 'nexus_src_fail_' . $key );
        }

        /**
         * Registra un fallimento: contatore sliding 1h; al 3° → cooldown 1h
         * (log warning una sola volta quando entra in cooldown).
         *
         * @param string $key   Chiave stato fonte.
         * @param string $label Label per il log.
         * @return void
         */
        private static function mark_failure( $key, $label ) {
                $transient = 'nexus_src_fail_' . $key;
                $failures  = (int) get_transient( $transient ) + 1;
                set_transient( $transient, $failures, self::FAIL_TTL );

                if ( $failures >= self::FAIL_THRESHOLD && ! get_transient( 'nexus_src_cool_' . $key ) ) {
                        set_transient( 'nexus_src_cool_' . $key, 1, self::COOLDOWN_TTL );
                        self::log(
                                sprintf( 'Fonte %s in cooldown 1h dopo %d fallimenti consecutivi.', $label, $failures )
                        );
                }
        }

        /**
         * Fallback: nessuna fonte redazione → feed globali di categoria via
         * Nexus_Rss::fetch_for_agent (source_type 'rss').
         *
         * @param object|array $agent     Agente.
         * @param int          $max_items Cap item.
         * @return array
         */
        private static function fallback_category_items( $agent, $max_items ) {
                if ( ! class_exists( 'Nexus_Rss' ) || ! method_exists( 'Nexus_Rss', 'fetch_for_agent' ) ) {
                        return array();
                }

                $fetched = Nexus_Rss::fetch_for_agent( $agent );
                if ( ! is_array( $fetched ) ) {
                        return array();
                }

                $items = array();
                foreach ( $fetched as $item ) {
                        if ( count( $items ) >= $max_items ) {
                                break;
                        }
                        if ( ! is_array( $item ) ) {
                                continue;
                        }
                        if ( empty( $item['source_type'] ) ) {
                                $item['source_type'] = 'rss';
                        }
                        $items[] = $item;
                }

                return $items;
        }

        /**
         * term_id della redazione dell'agente (difensivo: oggetto/array, con
         * eventuale lookup Nexus_DB::get_agent se manca il term_id).
         *
         * @param object|array $agent Agente.
         * @return int
         */
        private static function agent_term_id( $agent ) {
                $agent_id = 0;
                $term_id  = 0;

                if ( is_object( $agent ) ) {
                        if ( isset( $agent->term_id ) ) {
                                $term_id = absint( $agent->term_id );
                        }
                        if ( isset( $agent->id ) ) {
                                $agent_id = absint( $agent->id );
                        }
                } elseif ( is_array( $agent ) ) {
                        if ( isset( $agent['term_id'] ) ) {
                                $term_id = absint( $agent['term_id'] );
                        }
                        if ( isset( $agent['id'] ) ) {
                                $agent_id = absint( $agent['id'] );
                        }
                }

                if ( $term_id < 1 && $agent_id > 0 && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        $loaded = Nexus_DB::get_agent( $agent_id );
                        if ( $loaded && ! empty( $loaded->term_id ) ) {
                                $term_id = absint( $loaded->term_id );
                        }
                }

                return $term_id;
        }

        /**
         * Valore settings plugin (via Nexus_DB::get_settings, lettura difensiva).
         *
         * @param string $key Chiave (youtube_api_key, tools_search_key, ...).
         * @return string
         */
        private static function setting_value( $key ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return '';
                }
                try {
                        $settings = Nexus_DB::get_settings();
                } catch ( Throwable $e ) {
                        return '';
                }
                if ( ! is_array( $settings ) || ! isset( $settings[ $key ] ) ) {
                        return '';
                }
                return trim( (string) $settings[ $key ] );
        }

        /**
         * GET JSON con UA/timeout del plugin; risposta non 2xx o JSON invalido → null.
         *
         * @param string $url  Endpoint.
         * @return array|null
         */
        private static function http_json( $url ) {
                $res = wp_remote_get(
                        (string) $url,
                        array(
                                'timeout'    => self::HTTP_TIMEOUT,
                                'user-agent' => self::UA,
                        )
                );
                return self::json_body( $res );
        }

        /**
         * Decodifica il body JSON di una risposta HTTP (null se assente/invalido).
         *
         * @param array|WP_Error $res Risposta wp_remote_*.
         * @return array|null
         */
        private static function json_body( $res ) {
                if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
                        return null;
                }
                $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
                return is_array( $data ) ? $data : null;
        }

        /**
         * HTML → testo leggibile (script/style via, <br> → \n, entity decode, strip).
         *
         * @param string $html Frammento HTML.
         * @return string
         */
        private static function html_text( $html ) {
                $html = (string) $html;
                $html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
                $html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
                $html = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", (string) $html );

                $text = wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
                $text = preg_replace( '/[ \t]+/', ' ', $text );
                $text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );

                return trim( (string) $text );
        }

        /**
         * Label compatta della fonte per i log.
         *
         * @param array $source Fonte.
         * @return string
         */
        private static function source_label( $source ) {
                $type = isset( $source['type'] ) ? (string) $source['type'] : '?';
                $url  = isset( $source['url'] ) ? (string) $source['url'] : '';
                return self::clip( $type . ':' . $url, 100 );
        }

        /**
         * Attività su Nexus_DB (source_error, level default warning).
         *
         * @param string $detail Dettaglio.
         * @param string $status Level.
         * @return void
         */
        private static function log( $detail, $status = 'warning' ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity( 0, 'source_error', self::clip( (string) $detail, 240 ), $status );
                }
        }

        /**
         * Log una sola volta per chiave (marcatore transient 12h).
         *
         * @param string $key    Chiave log.
         * @param string $detail Dettaglio.
         * @param string $status Level.
         * @return void
         */
        private static function log_once( $key, $detail, $status = 'warning' ) {
                $marker = 'nexus_src_log_' . md5( (string) $key );
                if ( get_transient( $marker ) ) {
                        return;
                }
                set_transient( $marker, 1, 12 * HOUR_IN_SECONDS );
                self::log( $detail, $status );
        }

        /**
         * Tronca una stringa alla lunghezza indicata (multibyte se disponibile).
         *
         * @param string $text   Testo.
         * @param int    $length Lunghezza massima.
         * @return string
         */
        private static function clip( $text, $length ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $length );
                }
                return substr( $text, 0, (int) $length );
        }
}
